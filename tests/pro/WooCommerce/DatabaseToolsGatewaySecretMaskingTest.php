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
use WPMCP\Tools\WooCommerce\Catalog\Woo_Read;

/**
 * Issue #528: the database tools mask payment gateway credentials the way
 * get-option and call-rest do since #507, with the shared Gateway_Secrets
 * rule. query masks a gateway settings row, update-rows and delete-rows
 * mask their before_image, and the snapshot and audit copies stay raw so a
 * rollback restores the exact stored credentials. Fixture shared with
 * WooGatewaySecretMaskingTest.
 */
class DatabaseToolsGatewaySecretMaskingTest extends \WP_UnitTestCase
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

    public function test_query_masks_every_secret_in_the_gateway_settings_row(): void
    {
        global $wpdb;
        $this->as('administrator');

        $out = (new Query())->handle([ 'sql' => "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'woocommerce\\_%\\_settings'" ]);

        $this->assertGreaterThan(0, $out['row_count']);
        $this->assertNoCredential($out, 'query');
        $row = array_values(array_filter($out['rows'], static fn (array $r): bool => self::OPTION === $r['option_name']))[0] ?? null;
        $this->assertNotNull($row, 'The gateway settings row is missing from the result');
        $this->assertMaskedSettings($row['option_value'], 'query');
    }

    public function test_query_masks_the_settings_value_without_its_option_name_column(): void
    {
        global $wpdb;
        $this->as('administrator');

        $queries = [
            $wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPTION),
            $wpdb->prepare("SELECT option_name AS n, option_value AS v FROM {$wpdb->options} WHERE option_name = %s", self::OPTION),
            // Not valid serialized data any more: scrubbed as plain text.
            $wpdb->prepare("SELECT SUBSTRING(option_value, 2) AS s FROM {$wpdb->options} WHERE option_name = %s", self::OPTION),
        ];
        foreach ($queries as $sql) {
            $out = (new Query())->handle([ 'sql' => $sql ]);
            $this->assertSame(1, $out['row_count'], $sql);
            $this->assertNoCredential($out, $sql);
        }
    }

    public function test_query_leaves_other_options_and_the_stored_value_untouched(): void
    {
        global $wpdb;
        $this->as('administrator');
        $before = $this->raw_row();

        $out = (new Query())->handle([ 'sql' => "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name = 'blogname'" ]);
        $this->assertSame(get_option('blogname'), $out['rows'][0]['option_value']);

        (new Query())->handle([ 'sql' => "SELECT option_value FROM {$wpdb->options} WHERE option_name = '" . self::OPTION . "'" ]);
        $this->assertSame($before, $this->raw_row(), 'Masking must never touch the stored value');
        $this->assertSame(self::SECRETS['secret_key'], $this->stored()['secret_key']);
    }

    public function test_update_rows_masks_its_before_image_and_rollback_restores_the_exact_credentials(): void
    {
        global $wpdb;
        $this->as('administrator');
        add_filter('wpmcp_enable_db_writes', '__return_true');
        $original = $this->raw_row();

        $out = (new Update_Rows())->handle([
            'table'   => $wpdb->options,
            'data'    => [ 'option_value' => serialize([ 'enabled' => 'no' ]) ],
            'where'   => [ 'option_name' => self::OPTION ],
            'confirm' => true,
        ]);

        $this->assertSame(1, $out['affected']);
        $this->assertTrue($out['recoverable']);
        $this->assertNoCredential($out, 'update-rows before_image');
        $this->assertMaskedSettings($out['before_image'][0]['option_value'], 'update-rows before_image');

        // The audit copy stays raw: it is the manual-recovery trail.
        $audit = (array) get_option(Database_Guard::AUDIT_OPTION, []);
        $last  = end($audit);
        $this->assertSame($original, $last['before'][0]['option_value'] ?? null);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame($original, $this->raw_row(), 'Rollback did not restore the exact stored credentials');
        $this->assertSame(self::SECRETS['secret_key'], $this->stored()['secret_key']);
    }

    public function test_delete_rows_masks_its_before_image_and_rollback_reinserts_the_exact_credentials(): void
    {
        global $wpdb;
        $this->as('administrator');
        add_filter('wpmcp_enable_db_writes', '__return_true');
        $original = $this->raw_row();

        $out = (new Delete_Rows())->handle([
            'table'   => $wpdb->options,
            'where'   => [ 'option_name' => self::OPTION ],
            'confirm' => true,
        ]);

        $this->assertSame(1, $out['affected']);
        $this->assertNoCredential($out, 'delete-rows before_image');
        $this->assertMaskedSettings($out['before_image'][0]['option_value'], 'delete-rows before_image');
        $this->assertNull($this->raw_row());

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame($original, $this->raw_row(), 'Rollback did not reinsert the exact stored credentials');
    }
}
