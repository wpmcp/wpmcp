<?php

namespace WPMCP\Tests\Pro\WooCommerce;

use WPMCP\Governance\Governance;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Database\Database_Guard;
use WPMCP\Tools\Database\Delete_Rows;
use WPMCP\Tools\Database\Query;
use WPMCP\Tools\Database\Update_Rows;
use WPMCP\Tools\Filesystem\Filesystem_Guard;
use WPMCP\Tools\Meta\Get_Option;
use WPMCP\Tools\Meta\Update_Option;
use WPMCP\Tools\WooCommerce\Catalog\Woo_Read;

/**
 * Issue #550: WP MCP's own stores keep raw copies of what a write replaced,
 * so a rollback is exact (#528). Those copies must not become a side door
 * around the gateway masking of #507 and #528: get-option refuses both
 * write audit logs, query masks a gateway settings row inside the database
 * audit log (even after the credential was rotated), and the snapshot
 * ledger, whose payload is base64 gzip the value scrub cannot see into, is
 * refused to query and to the row-write tools. Rollback stays exact.
 * Fixture shared with DatabaseToolsGatewaySecretMaskingTest.
 */
class InternalStoreGatewaySecretReadTest extends \WP_UnitTestCase
{
    private const GATEWAY = 'wpmcp_mask_pay';
    private const OPTION  = 'woocommerce_wpmcp_mask_pay_settings';
    private const MASK    = '[redacted]';

    /** Every credential seeded into the gateway, keyed by setting. */
    private const SECRETS = [
        'secret_key'      => 'sk_live_MASK123',
        'publishable_key' => 'pk_live_MASK456',
        'webhook_signing' => 'whsec_MASK789',
        'access_token'    => 'tok_MASK000',
    ];

    /** @var callable|null */
    private $register = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (! class_exists('WooCommerce')) {
            $this->markTestSkipped('WooCommerce is not active in this environment.');
        }
        Gate::set_pro_for_tests(true);
        Snapshot_Store::install();
        Governance::reset_for_tests();
        \WC_Install::create_roles();
        $GLOBALS['wp_roles'] = null;
        wp_roles();

        update_option(self::OPTION, self::SECRETS + [
            'enabled'  => 'yes',
            'title'    => 'Mask Pay',
            'testmode' => 'no',
        ]);

        $fake = new class extends \WC_Payment_Gateway {
            public function __construct()
            {
                $this->id                 = 'wpmcp_mask_pay';
                $this->method_title       = 'Mask Pay';
                $this->method_description = 'Masking fixture.';
                $this->init_form_fields();
                $this->init_settings();
                $this->title = $this->get_option('title');
            }

            public function init_form_fields()
            {
                $this->form_fields = [
                    'enabled'         => [ 'title' => 'Enable', 'type' => 'checkbox', 'default' => 'no' ],
                    'title'           => [ 'title' => 'Title', 'type' => 'text', 'default' => 'Mask Pay' ],
                    'secret_key'      => [ 'title' => 'Secret key', 'type' => 'text', 'default' => '' ],
                    'publishable_key' => [ 'title' => 'Publishable key', 'type' => 'text', 'default' => '' ],
                    // A password field whose name does not look like a credential.
                    'webhook_signing' => [ 'title' => 'Webhook signing', 'type' => 'password', 'default' => '' ],
                    'access_token'    => [ 'title' => 'Access token', 'type' => 'text', 'default' => '' ],
                    'testmode'        => [ 'title' => 'Test mode', 'type' => 'checkbox', 'default' => 'no' ],
                ];
            }
        };
        $this->register = static function ($gateways) use ($fake) {
            $gateways[] = $fake;
            return $gateways;
        };
        add_filter('woocommerce_payment_gateways', $this->register);
        $this->reload();

        global $wp_rest_server;
        $wp_rest_server = null;
        rest_get_server();
    }

    protected function tearDown(): void
    {
        global $wp_rest_server;
        $wp_rest_server = null;
        if (null !== $this->register) {
            remove_filter('woocommerce_payment_gateways', $this->register);
            $this->reload();
        }
        remove_all_filters('wpmcp_enable_db_writes');
        Governance::reset_for_tests();
        wp_set_current_user(0);
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function reload(): void
    {
        $gateways                   = WC()->payment_gateways();
        $gateways->payment_gateways = [];
        $gateways->init();
    }

    private function as(string $role): void
    {
        wp_set_current_user(self::factory()->user->create([ 'role' => $role ]));
    }

    private function stored(): array
    {
        wp_cache_delete(self::OPTION, 'options');
        wp_cache_delete('alloptions', 'options');
        return (array) get_option(self::OPTION);
    }

    /**
     * The fields gateways.get reports as secret: the set every other read
     * path must mask too.
     *
     * @return array<int, string>
     */
    private function masked_by_gateway_ops(): array
    {
        $out = (new Woo_Read())->handle([ 'op' => 'gateways.get', 'params' => [ 'id' => self::GATEWAY ] ]);
        $this->assertSame(200, $out['status'] ?? null, (string) wp_json_encode($out));
        $secret = array_keys(array_filter((array) $out['body']['settings'], static fn (array $s): bool => true === $s['secret']));
        sort($secret);
        $expected = array_keys(self::SECRETS);
        sort($expected);
        $this->assertSame($expected, $secret, 'The fixture no longer exercises every kind of secret field');
        return $secret;
    }

    private function assertNoCredential($out, string $where): void
    {
        $json = (string) wp_json_encode($out);
        foreach (self::SECRETS as $key => $value) {
            $this->assertStringNotContainsString($value, $json, "{$key} leaked through {$where}");
        }
    }


    /** The settings option's stored value, raw from the table. */
    private function raw_row(): ?string
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the test compares the exact stored bytes.
        $value = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPTION));
        return null === $value ? null : (string) $value;
    }

    /** Every secret field in a serialized settings value reads as the mask; the rest is intact. */
    private function assertMaskedSettings(string $serialized, string $where): void
    {
        $settings = unserialize($serialized, [ 'allowed_classes' => false ]);
        $this->assertIsArray($settings, "{$where} did not return a valid serialized settings array");
        foreach ($this->masked_by_gateway_ops() as $key) {
            $this->assertSame(self::MASK, $settings[ $key ] ?? null, "{$key} not masked through {$where}");
        }
        $this->assertSame('Mask Pay', $settings['title'] ?? null, "{$where} masked a non-secret field");
        $this->assertSame('yes', $settings['enabled'] ?? null);
    }

    /** Change only the title through update-rows: the credentials stay live and land raw in the audit log and the snapshot. */
    private function rename_through_update_rows(): array
    {
        global $wpdb;
        $settings          = $this->stored();
        $settings['title'] = 'Renamed';
        $out = (new Update_Rows())->handle([
            'table'   => $wpdb->options,
            'data'    => [ 'option_value' => serialize($settings) ],
            'where'   => [ 'option_name' => self::OPTION ],
            'confirm' => true,
        ]);
        $this->assertSame(1, $out['affected']);
        $this->assertTrue($out['recoverable']);
        $this->assertNoCredential($out, 'update-rows');
        return $out;
    }

    /** Run a tool call that must be refused and return the refusal message. */
    private function refused(callable $call, string $where): string
    {
        try {
            $out = $call();
        } catch (\RuntimeException $e) {
            $this->assertNoCredential($e->getMessage(), $where . ' refusal');
            return $e->getMessage();
        }
        $this->assertNoCredential($out, $where);
        $this->fail("{$where} was not refused");
    }

    public function test_get_option_refuses_both_write_audit_logs(): void
    {
        $this->as('administrator');
        add_filter('wpmcp_enable_db_writes', '__return_true');
        $this->rename_through_update_rows();
        Filesystem_Guard::log('write', 'wp-content/uploads/example.txt');

        foreach ([ Database_Guard::AUDIT_OPTION, Filesystem_Guard::AUDIT_OPTION ] as $option) {
            foreach ([ $option, strtoupper($option), " {$option} " ] as $spelling) {
                $message = $this->refused(static fn () => (new Get_Option())->handle([ 'name' => $spelling ]), "get-option {$spelling}");
                $this->assertStringContainsString('Refusing to read', $message);
            }
        }
    }

    public function test_the_audit_logs_cannot_be_unlocked_by_trimming_the_denylist(): void
    {
        $this->as('administrator');
        add_filter('wpmcp_enable_db_writes', '__return_true');
        $this->rename_through_update_rows();
        add_filter('wpmcp_option_denylist', '__return_empty_array');
        add_filter('wpmcp_option_denylist_patterns', '__return_empty_array');
        try {
            $this->refused(static fn () => (new Get_Option())->handle([ 'name' => Database_Guard::AUDIT_OPTION ]), 'get-option with a trimmed denylist');
        } finally {
            remove_all_filters('wpmcp_option_denylist');
            remove_all_filters('wpmcp_option_denylist_patterns');
        }
    }

    public function test_update_option_refuses_both_write_audit_logs(): void
    {
        $this->as('administrator');
        add_filter('wpmcp_enable_option_write', '__return_true');
        foreach ([ Database_Guard::AUDIT_OPTION, Filesystem_Guard::AUDIT_OPTION ] as $option) {
            try {
                (new Update_Option())->handle([ 'name' => $option, 'value' => [] ]);
                $this->fail("update-option {$option} was not refused");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('sensitive option', $e->getMessage());
            }
        }
        remove_all_filters('wpmcp_enable_option_write');
    }

    public function test_query_masks_the_audit_log_row_even_after_the_credential_was_rotated(): void
    {
        global $wpdb;
        $this->as('administrator');
        add_filter('wpmcp_enable_db_writes', '__return_true');

        // Rotate every credential: the old values live on only in the
        // audit log and the snapshot, where the current-value scrub cannot
        // recognize them.
        $original = $this->stored();
        $rotated  = $original;
        foreach (array_keys(self::SECRETS) as $key) {
            $rotated[ $key ] = 'rotated_' . $key . '_NEW999';
        }
        $out = (new Update_Rows())->handle([
            'table'   => $wpdb->options,
            'data'    => [ 'option_value' => serialize($rotated) ],
            'where'   => [ 'option_name' => self::OPTION ],
            'confirm' => true,
        ]);
        $this->assertSame(1, $out['affected']);

        $sql  = $wpdb->prepare("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name = %s", Database_Guard::AUDIT_OPTION);
        $read = (new Query())->handle([ 'sql' => $sql ]);
        $this->assertSame(1, $read['row_count']);
        $this->assertNoCredential($read, 'query on the audit log row');
        $this->assertStringNotContainsString('_NEW999', (string) wp_json_encode($read), 'the live credential leaked through query on the audit log row');

        // The masked copy is still a readable audit log: the entry is there
        // with every non-secret field intact.
        $log  = unserialize($read['rows'][0]['option_value'], [ 'allowed_classes' => false ]);
        $this->assertIsArray($log);
        $last = end($log);
        $this->assertSame('update', $last['op']);
        $this->assertSame($wpdb->options, $last['table']);
        $this->assertMaskedSettings($last['before'][0]['option_value'], 'query on the audit log row');

        // SELECT * reaches the same row.
        $all = (new Query())->handle([ 'sql' => "SELECT * FROM {$wpdb->options} WHERE option_name LIKE 'wpmcp\\_db\\_write\\_audit%'" ]);
        $this->assertGreaterThan(0, $all['row_count']);
        $this->assertNoCredential($all, 'SELECT * on the audit log row');

        // The stored audit log stays the raw manual-recovery trail.
        wp_cache_delete(Database_Guard::AUDIT_OPTION, 'options');
        $audit = (array) get_option(Database_Guard::AUDIT_OPTION, []);
        $raw   = end($audit);
        $this->assertSame(self::SECRETS['secret_key'], unserialize($raw['before'][0]['option_value'], [ 'allowed_classes' => false ])['secret_key']);

        // Rollback restores the exact credentials that were rotated away.
        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame(serialize($original), $this->raw_row());
    }

    /** @return array<string, array{0: string}> */
    public static function snapshot_ledger_reads(): array
    {
        return [
            'payload column'       => [ 'SELECT before_blob FROM {t} ORDER BY id DESC LIMIT 5' ],
            'every column'         => [ 'SELECT * FROM {t}' ],
            'qualified star'       => [ 'SELECT s.* FROM {t} s' ],
            'transformed payload'  => [ 'SELECT HEX(before_blob) AS h FROM {t}' ],
            'decoded payload'      => [ 'SELECT FROM_BASE64(before_blob) AS b FROM {t}' ],
            'union under an alias' => [ 'SELECT 1,2,3,4,5,6,7,8,9,10 UNION SELECT * FROM {t}' ],
            'subquery'             => [ 'SELECT x.* FROM (SELECT * FROM {t}) x' ],
            'backticked'           => [ 'SELECT `before_blob` FROM `{t}`' ],
            'upper-cased'          => [ 'SELECT BEFORE_BLOB FROM {T}' ],
            'metadata only'        => [ 'SELECT id, tool_name FROM {t}' ],
        ];
    }

    /** @dataProvider snapshot_ledger_reads */
    public function test_query_refuses_the_snapshot_ledger(string $template): void
    {
        $this->as('administrator');
        add_filter('wpmcp_enable_db_writes', '__return_true');
        $this->rename_through_update_rows();

        $table = Snapshot_Store::table_name();
        $sql   = str_replace([ '{t}', '{T}' ], [ $table, strtoupper($table) ], $template);
        $message = $this->refused(static fn () => (new Query())->handle([ 'sql' => $sql ]), "query {$sql}");
        $this->assertStringContainsString('snapshot', strtolower($message));
    }

    public function test_query_still_answers_ordinary_reads(): void
    {
        global $wpdb;
        $this->as('administrator');
        $read = (new Query())->handle([ 'sql' => "SELECT option_name FROM {$wpdb->options} WHERE option_name = 'blogname'" ]);
        $this->assertSame(1, $read['row_count']);
        // A literal that merely names the ledger is not a read of it.
        $read = (new Query())->handle([ 'sql' => "SELECT 'wpmcp_snapshots' AS name" ]);
        $this->assertSame(1, $read['row_count']);
    }

    public function test_row_write_tools_refuse_the_snapshot_ledger(): void
    {
        $this->as('administrator');
        add_filter('wpmcp_enable_db_writes', '__return_true');
        $this->rename_through_update_rows();
        $table = Snapshot_Store::table_name();

        // update-rows and delete-rows would echo the payload in before_image.
        $this->refused(static fn () => (new Update_Rows())->handle([
            'table'   => $table,
            'data'    => [ 'user_id' => 0 ],
            'where'   => [ 'tool_name' => 'update-rows' ],
            'confirm' => true,
        ]), 'update-rows on the snapshot ledger');
        $this->refused(static fn () => (new Delete_Rows())->handle([
            'table'   => $table,
            'where'   => [ 'tool_name' => 'update-rows' ],
            'confirm' => true,
        ]), 'delete-rows on the snapshot ledger');
    }

    public function test_rollback_through_the_ledger_stays_exact(): void
    {
        $this->as('administrator');
        add_filter('wpmcp_enable_db_writes', '__return_true');
        $original = $this->raw_row();
        $out      = $this->rename_through_update_rows();

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame($original, $this->raw_row(), 'Rollback did not restore the exact stored credentials');
        $this->assertSame(self::SECRETS['secret_key'], $this->stored()['secret_key']);
    }
}
