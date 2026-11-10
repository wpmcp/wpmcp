<?php

namespace WPMCP\Tests\Pro\WooCommerce;

use WPMCP\Governance\Governance;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Database\Query;
use WPMCP\Tools\WooCommerce\Catalog\Woo_Read;

/**
 * Issue #621: query masked a gateway settings row only when option_value
 * came back intact, so REVERSE(), TO_BASE64(), HEX() or a LIKE oracle in the
 * WHERE clause returned or leaked the live credentials. The live row cannot
 * be masked at rest, so query refuses any SQL that can read option_value
 * from an options table unless it is a plain lookup of named options that
 * get-option would also return. Fixture shared with the other gateway
 * masking tests.
 */
class QueryLiveGatewaySecretTest extends \WP_UnitTestCase
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


    /** Whatever query returns (or refuses), no credential, in any encoding the SQL could apply. */
    private function assertQueryLeaksNothing(string $sql): void
    {
        try {
            $out = (new Query())->handle([ 'sql' => $sql ]);
        } catch (\RuntimeException $e) {
            $this->assertNoCredential($e->getMessage(), "query refusal for {$sql}");
            return;
        }
        $text = '';
        foreach ((array) $out['rows'] as $row) {
            foreach ((array) $row as $cell) {
                $cell  = (string) $cell;
                $text .= "\n" . $cell . "\n" . strrev($cell) . "\n" . (string) base64_decode($cell, false)
                    . "\n" . (ctype_xdigit($cell) && 0 === strlen($cell) % 2 ? (string) hex2bin($cell) : '');
            }
        }
        foreach (self::SECRETS as $key => $value) {
            $this->assertStringNotContainsString(strtolower($value), strtolower($text), "{$key} leaked through {$sql}");
        }
        // A LIKE oracle: the row count must not depend on the credential.
        $this->assertNotSame(1, $out['row_count'] ?? null, "query answered an oracle on the credential: {$sql}");
    }

    /** @return array<string, array{0: string}> */
    public static function live_row_reads(): array
    {
        $w = "WHERE option_name = '" . self::OPTION . "'";
        return [
            'REVERSE'            => [ "SELECT REVERSE(option_value) AS v FROM {o} {$w}" ],
            'TO_BASE64'          => [ "SELECT TO_BASE64(option_value) AS v FROM {o} {$w}" ],
            'HEX'                => [ "SELECT HEX(option_value) AS v FROM {o} {$w}" ],
            'LOWER'              => [ "SELECT LOWER(option_value) AS v FROM {o} {$w}" ],
            'CONCAT'             => [ "SELECT CONCAT(option_value, '') AS v FROM {o} {$w}" ],
            'SUBSTRING'          => [ "SELECT SUBSTRING(option_value, 2) AS v FROM {o} {$w}" ],
            'pattern name'       => [ "SELECT REVERSE(option_value) AS v FROM {o} WHERE option_name LIKE 'woocommerce%mask_pay%'" ],
            'no where'           => [ 'SELECT REVERSE(option_value) AS v FROM {o}' ],
            'subquery'           => [ "SELECT (SELECT REVERSE(option_value) FROM {o} {$w}) AS v FROM {o} WHERE option_name = 'blogname'" ],
            'derived table'      => [ "SELECT REVERSE(x) AS v FROM (SELECT option_value AS x FROM {o} {$w}) t" ],
            'union'              => [ "SELECT option_name FROM {o} WHERE option_name = 'blogname' UNION SELECT REVERSE(option_value) FROM {o} {$w}" ],
            'star in a subquery' => [ "SELECT REVERSE(t.option_value) AS v FROM (SELECT * FROM {o}) t WHERE t.option_name = '" . self::OPTION . "'" ],
            'LIKE oracle'        => [ "SELECT option_name FROM {o} WHERE option_name = '" . self::OPTION . "' AND option_value LIKE '%sk_live_MASK1%'" ],
            'HAVING oracle'      => [ "SELECT option_name, option_value AS v FROM {o} {$w} HAVING v LIKE '%sk_live_MASK1%'" ],
            'OR widening'        => [ "SELECT REVERSE(option_value) AS v FROM {o} WHERE option_name = 'blogname' OR option_name = '" . self::OPTION . "'" ],
            'upper-cased name'   => [ "SELECT REVERSE(option_value) AS v FROM {o} WHERE option_name = '" . strtoupper(self::OPTION) . "'" ],
            'backticked'         => [ "SELECT REVERSE(`option_value`) AS v FROM `{o}` {$w}" ],
            'aliased table'      => [ "SELECT REVERSE(o.option_value) AS v FROM {o} o WHERE o.option_name = '" . self::OPTION . "'" ],
            'denylisted option'  => [ "SELECT REVERSE(option_value) AS v FROM {o} WHERE option_name = 'secure_auth_key'" ],
        ];
    }

    /** @dataProvider live_row_reads */
    public function test_query_never_leaks_a_live_gateway_credential(string $template): void
    {
        global $wpdb;
        $this->as('administrator');
        $this->assertQueryLeaksNothing(str_replace('{o}', $wpdb->options, $template));
    }

    public function test_a_transform_on_the_live_row_is_refused(): void
    {
        global $wpdb;
        $this->as('administrator');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('option_value');
        (new Query())->handle([ 'sql' => "SELECT REVERSE(option_value) AS v FROM {$wpdb->options} WHERE option_name = '" . self::OPTION . "'" ]);
    }

    /** @return array<string, array{0: string, 1: int}> */
    public static function ordinary_reads(): array
    {
        return [
            'one option'           => [ "SELECT option_value FROM {o} WHERE option_name = 'blogname'", 1 ],
            'transform, one option' => [ "SELECT UPPER(option_value) AS v FROM {o} WHERE option_name = 'blogname'", 1 ],
            'IN list'              => [ "SELECT option_name, option_value FROM {o} WHERE option_name IN ('blogname', 'blogdescription')", 2 ],
            'star, one option'     => [ "SELECT * FROM {o} WHERE option_name = 'blogname' LIMIT 1", 1 ],
            'names only'           => [ "SELECT option_name FROM {o} WHERE option_name LIKE 'blogdesc%' ORDER BY option_name", 1 ],
            'count'                => [ 'SELECT COUNT(*) AS n FROM {o}', 1 ],
            'other table'          => [ 'SELECT ID FROM {p} LIMIT 1', -1 ],
        ];
    }

    /** @dataProvider ordinary_reads */
    public function test_ordinary_reads_still_work(string $template, int $rows): void
    {
        global $wpdb;
        $this->as('administrator');
        $out = (new Query())->handle([ 'sql' => str_replace([ '{o}', '{p}' ], [ $wpdb->options, $wpdb->posts ], $template) ]);
        $this->assertIsArray($out['rows']);
        if ($rows >= 0) {
            $this->assertSame($rows, $out['row_count']);
        }
    }
}
