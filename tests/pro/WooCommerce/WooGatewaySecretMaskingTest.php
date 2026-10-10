<?php

namespace WPMCP\Tests\Pro\WooCommerce;

use WPMCP\Governance\Governance;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Meta\Get_Option;
use WPMCP\Tools\Meta\Update_Option;
use WPMCP\Tools\Rest\Call_Rest;
use WPMCP\Tools\WooCommerce\Catalog\Woo_Read;
use WPMCP\Tools\WooCommerce\Catalog\Woo_Write;

/**
 * Payment gateway credentials are masked on every read path, not only the
 * gateways.* ops (issue #507): get-option on a gateway's settings option and
 * call-rest on WooCommerce's payment gateway routes mask the same fields,
 * with the same rule. A masked value written back unchanged keeps the stored
 * credential, through the gateway ops, update-option and call-rest alike.
 */
class WooGatewaySecretMaskingTest extends \WP_UnitTestCase
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
        remove_all_filters('wpmcp_enable_option_write');
        remove_all_filters('wpmcp_enable_rest_writes');
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

    public function test_get_option_masks_every_secret_the_gateway_ops_mask(): void
    {
        $this->as('administrator');
        $secret = $this->masked_by_gateway_ops();

        $out = (new Get_Option())->handle([ 'name' => self::OPTION ]);

        $this->assertNoCredential($out, 'get-option');
        foreach ($secret as $key) {
            $this->assertSame(self::MASK, $out['value'][ $key ], "get-option did not mask {$key}");
        }
        $this->assertSame('Mask Pay', $out['value']['title']);
        $this->assertSame('no', $out['value']['testmode']);
    }

    public function test_get_option_leaves_other_woocommerce_settings_options_alone(): void
    {
        $this->as('administrator');
        update_option('woocommerce_new_order_settings', [ 'enabled' => 'yes', 'recipient' => 'owner@example.com' ]);

        $out = (new Get_Option())->handle([ 'name' => 'woocommerce_new_order_settings' ]);

        $this->assertSame('owner@example.com', $out['value']['recipient']);
    }

    public function test_call_rest_masks_a_gateway_for_a_shop_manager(): void
    {
        $this->as('shop_manager');
        $secret = $this->masked_by_gateway_ops();

        $out = (new Call_Rest())->handle([ 'method' => 'GET', 'route' => '/wc/v3/payment_gateways/' . self::GATEWAY ]);

        $this->assertSame(200, $out['status'], (string) wp_json_encode($out));
        $this->assertNoCredential($out, 'call-rest GET /wc/v3/payment_gateways/<id>');
        foreach ($secret as $key) {
            $this->assertSame(self::MASK, $out['body']['settings'][ $key ]['value'], "call-rest did not mask {$key}");
        }
        $this->assertSame('Mask Pay', $out['body']['settings']['title']['value']);
    }

    public function test_call_rest_masks_the_gateway_collection(): void
    {
        $this->as('shop_manager');
        $secret = $this->masked_by_gateway_ops();

        $out = (new Call_Rest())->handle([ 'method' => 'GET', 'route' => '/wc/v3/payment_gateways' ]);

        $this->assertSame(200, $out['status'], (string) wp_json_encode($out));
        $this->assertNoCredential($out, 'call-rest GET /wc/v3/payment_gateways');
        $mine = array_values(array_filter((array) $out['body'], static fn ($g): bool => is_array($g) && self::GATEWAY === ($g['id'] ?? null)));
        $this->assertCount(1, $mine);
        foreach ($secret as $key) {
            $this->assertSame(self::MASK, $mine[0]['settings'][ $key ]['value'], "call-rest collection did not mask {$key}");
        }
    }

    public function test_call_rest_masks_whatever_spelling_of_the_route_matches(): void
    {
        $this->as('shop_manager');

        foreach ([ '/wc/v2/payment_gateways/' . self::GATEWAY, '/WC/V3/Payment_Gateways/' . self::GATEWAY, '/wc/v3/payment_gateways/' . self::GATEWAY . '/' ] as $route) {
            $out = (new Call_Rest())->handle([ 'method' => 'GET', 'route' => $route ]);
            $this->assertNoCredential($out, "call-rest GET {$route}");
        }
    }

    public function test_a_masked_round_trip_through_gateway_ops_keeps_the_credentials(): void
    {
        $this->as('shop_manager');

        $out = (new Woo_Write())->handle([
            'op'     => 'gateways.update',
            'params' => [
                'id'       => self::GATEWAY,
                'settings' => [ 'testmode' => 'yes', 'secret_key' => self::MASK, 'webhook_signing' => self::MASK ],
            ],
        ]);

        $this->assertSame(200, $out['status'] ?? null, (string) wp_json_encode($out));
        $this->assertNoCredential($out, 'woo-write gateways.update');
        $stored = $this->stored();
        $this->assertSame('yes', $stored['testmode']);
        foreach (self::SECRETS as $key => $value) {
            $this->assertSame($value, $stored[ $key ], "gateways.update overwrote {$key}");
        }
    }

    public function test_gateway_ops_still_refuse_a_new_credential(): void
    {
        $this->as('shop_manager');

        $out = (new Woo_Write())->handle([
            'op'     => 'gateways.update',
            'params' => [ 'id' => self::GATEWAY, 'settings' => [ 'secret_key' => 'sk_live_NEW' ] ],
        ]);

        $this->assertSame('secret_field', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertSame(self::SECRETS['secret_key'], $this->stored()['secret_key']);
    }

    public function test_a_masked_round_trip_through_update_option_keeps_the_credentials(): void
    {
        $this->as('administrator');
        add_filter('wpmcp_enable_option_write', '__return_true');

        $read          = (new Get_Option())->handle([ 'name' => self::OPTION ])['value'];
        $read['title'] = 'Renamed Pay';
        $out           = (new Update_Option())->handle([ 'name' => self::OPTION, 'value' => $read ]);

        $this->assertNoCredential($out, 'update-option');
        $stored = $this->stored();
        $this->assertSame('Renamed Pay', $stored['title']);
        foreach (self::SECRETS as $key => $value) {
            $this->assertSame($value, $stored[ $key ], "update-option overwrote {$key} with the mask");
        }
    }

    public function test_a_masked_round_trip_through_call_rest_keeps_the_credentials(): void
    {
        $this->as('shop_manager');
        add_filter('wpmcp_enable_rest_writes', '__return_true');

        $out = (new Call_Rest())->handle([
            'method'  => 'PUT',
            'route'   => '/wc/v3/payment_gateways/' . self::GATEWAY,
            'params'  => [ 'settings' => [ 'title' => 'Rest Pay', 'secret_key' => self::MASK, 'webhook_signing' => self::MASK ] ],
            'confirm' => true,
        ]);

        $this->assertSame(200, $out['status'], (string) wp_json_encode($out));
        $this->assertNoCredential($out, 'call-rest PUT /wc/v3/payment_gateways/<id>');
        $stored = $this->stored();
        $this->assertSame('Rest Pay', $stored['title']);
        foreach (self::SECRETS as $key => $value) {
            $this->assertSame($value, $stored[ $key ], "call-rest overwrote {$key} with the mask");
        }
    }
}
