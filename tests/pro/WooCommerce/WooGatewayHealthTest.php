<?php

namespace WPMCP\Tests\Pro\WooCommerce;

use WPMCP\Governance\Governance;
use WPMCP\Pro\Gate;
use WPMCP\Tools\WooCommerce\Catalog\Op_Catalog;
use WPMCP\Tools\WooCommerce\Catalog\Woo_Ops;
use WPMCP\Tools\WooCommerce\Catalog\Woo_Read;

/**
 * gateways.health (issue #661): the connection health of the Stripe,
 * WooPayments and PayPal Payments gateways on the existing woo-read
 * dispatcher. Each row says whether the gateway is connected, whether it is
 * in test mode, how its webhooks are doing and the last error the plugin
 * recorded. It is a read only, and no key, secret, token or merchant
 * identity ever appears in it.
 *
 * The three plugins are stood in for by gateways registered under their real
 * ids, with the options each plugin really stores (read from WooCommerce
 * Stripe 11.2, WooPayments 11.3 and PayPal Payments 4.2):
 *
 *  - Stripe: woocommerce_stripe_settings (testmode, publishable_key,
 *    secret_key, webhook_secret and their test_ twins) and the webhook
 *    monitor options wc_stripe_wh_{,test_}last_success_at,
 *    wc_stripe_wh_{,test_}last_failure_at and wc_stripe_wh_{,test_}last_error.
 *  - WooPayments: woocommerce_woocommerce_payments_settings (test_mode) and
 *    the account cache wcpay_account_data ({data, fetched, errored}).
 *  - PayPal Payments: woocommerce-ppcp-data-common (use_sandbox, merchant
 *    and client credentials), ppcp-webhook (the registered webhook) and
 *    ppcp-last-webhook ({id, received_time}).
 */
class WooGatewayHealthTest extends \WP_UnitTestCase
{
    /** Every credential or identity seeded below; none may appear in a response. */
    private const SECRETS = [
        'sk_live_HEALTH1', 'pk_live_HEALTH2', 'whsec_HEALTH3', 'sk_test_HEALTH4', 'pk_test_HEALTH5',
        'acct_HEALTH6', 'owner@shop.example', 'PPCLIENTHEALTH7', 'PPSECRETHEALTH8', 'PPMERCHANTHEALTH9', 'WH-HEALTH10',
    ];

    private const OPTIONS = [
        'woocommerce_stripe_settings', 'wc_stripe_wh_last_success_at', 'wc_stripe_wh_last_failure_at', 'wc_stripe_wh_last_error',
        'wc_stripe_wh_test_last_success_at', 'wc_stripe_wh_test_last_failure_at', 'wc_stripe_wh_test_last_error',
        'woocommerce_woocommerce_payments_settings', 'wcpay_account_data',
        'woocommerce-ppcp-data-common', 'woocommerce-ppcp-settings', 'ppcp-webhook', 'ppcp-last-webhook',
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
        Governance::reset_for_tests();

        \WC_Install::create_roles();
        $GLOBALS['wp_roles'] = null;
        wp_roles();

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        foreach (self::OPTIONS as $option) {
            delete_option($option);
        }
    }

    protected function tearDown(): void
    {
        $this->unregister();
        foreach (self::OPTIONS as $option) {
            delete_option($option);
        }
        Governance::reset_for_tests();
        wp_set_current_user(0);
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    // ------------------------------------------------------------ fixtures

    /** Register stand-in gateways under the plugins' real ids. */
    private function register(string ...$ids): void
    {
        $this->unregister();
        $fakes = array_map(fn ($id) => $this->fake_gateway($id), $ids);
        $this->register = static function ($gateways) use ($fakes) {
            return array_merge($gateways, $fakes);
        };
        add_filter('woocommerce_payment_gateways', $this->register);
        $this->reload_gateways();
    }

    private function unregister(): void
    {
        if (null !== $this->register) {
            remove_filter('woocommerce_payment_gateways', $this->register);
            $this->register = null;
        }
        if (function_exists('WC')) {
            $this->reload_gateways();
        }
    }

    private function reload_gateways(): void
    {
        $gateways                   = WC()->payment_gateways();
        $gateways->payment_gateways = [];
        $gateways->init();
    }

    private function fake_gateway(string $id): \WC_Payment_Gateway
    {
        return new class ($id) extends \WC_Payment_Gateway {
            public function __construct(string $id)
            {
                $this->id           = $id;
                $this->method_title = 'Stand-in ' . $id;
                $this->form_fields  = [ 'enabled' => [ 'title' => 'Enable', 'type' => 'checkbox', 'default' => 'no' ] ];
                $this->init_settings();
            }
        };
    }

    private function read(array $params = []): array
    {
        return (new Woo_Read())->handle(['op' => 'gateways.health', 'params' => $params]);
    }

    /** @return array<string, array<string, mixed>> rows by gateway id */
    private function rows(array $params = []): array
    {
        $out = $this->read($params);
        $this->assertSame(200, $out['status'] ?? null, (string) wp_json_encode($out));
        $this->assertNoSecrets($out);
        $rows = [];
        foreach ($out['body']['gateways'] as $row) {
            $rows[ $row['id'] ] = $row;
        }
        return $rows;
    }

    private function assertNoSecrets(array $out): void
    {
        $json = (string) wp_json_encode($out);
        foreach (self::SECRETS as $secret) {
            $this->assertStringNotContainsString($secret, $json, 'A credential or merchant identity leaked into a health response');
        }
    }

    private function seed_stripe(bool $test_mode): void
    {
        update_option('woocommerce_stripe_settings', [
            'enabled'              => 'yes',
            'testmode'             => $test_mode ? 'yes' : 'no',
            'publishable_key'      => 'pk_live_HEALTH2',
            'secret_key'           => 'sk_live_HEALTH1',
            'webhook_secret'       => 'whsec_HEALTH3',
            'test_publishable_key' => 'pk_test_HEALTH5',
            'test_secret_key'      => '',
            'test_webhook_secret'  => '',
        ]);
    }

    // ------------------------------------------------------------- catalog

    public function test_the_catalog_carries_a_read_only_health_op_on_woo_read(): void
    {
        $ops = Op_Catalog::ops();
        $this->assertArrayHasKey('gateways.health', $ops);
        $def = $ops['gateways.health'];
        $this->assertSame('gateways', $def['domain']);
        $this->assertSame('read', $def['mode']);
        $this->assertSame('GET', $def['method']);
        $this->assertSame('manage_woocommerce', $def['capability']);
        $this->assertNull($def['snapshot']);
        $this->assertSame('gateway_health', $def['handler']);
        $this->assertNotNull($def['plugin'], 'The op needs one of the three gateway plugins');
    }

    public function test_without_any_of_the_gateways_the_op_is_hidden_and_refused_with_plugin_missing(): void
    {
        $listed = (new Woo_Ops())->handle(['domain' => 'gateways']);
        $names  = array_column($listed['domains']['gateways'] ?? [], 'op');
        $this->assertNotContains('gateways.health', $names);
        $this->assertContains('gateways.list', $names, 'The other gateway ops stay listed');

        $out = $this->read();
        $this->assertSame('plugin_missing', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertStringContainsString('Install and activate WooCommerce Stripe, WooPayments or WooCommerce PayPal Payments', $out['error']['message']);
        $this->assertSame(admin_url('plugins.php'), $out['error']['data']['plugins_url']);

        $this->register('stripe');
        $listed = (new Woo_Ops())->handle(['domain' => 'gateways']);
        $row    = array_values(array_filter($listed['domains']['gateways'], static fn ($r) => 'gateways.health' === $r['op']));
        $this->assertCount(1, $row, 'Listed once a supported gateway is registered');
        $this->assertSame('WooCommerce Stripe, WooPayments or WooCommerce PayPal Payments', $row[0]['requires']);
    }

    // -------------------------------------------------------------- stripe

    public function test_stripe_live_mode_with_keys_and_a_recent_successful_webhook_is_healthy(): void
    {
        $this->register('stripe');
        $this->seed_stripe(false);
        update_option('wc_stripe_wh_last_success_at', 1763400000);
        update_option('wc_stripe_wh_last_failure_at', 1763300000);
        update_option('wc_stripe_wh_last_error', 'signature_invalid');

        $row = $this->rows()['stripe'];
        $this->assertSame('stripe', $row['provider']);
        $this->assertTrue($row['enabled']);
        $this->assertFalse($row['test_mode']);
        $this->assertTrue($row['connected']);
        $this->assertSame('ok', $row['webhook']['status']);
        $this->assertTrue($row['webhook']['signing_secret_set']);
        $this->assertSame(gmdate('Y-m-d H:i:s', 1763400000), $row['webhook']['last_success_at']);
        $this->assertSame(gmdate('Y-m-d H:i:s', 1763300000), $row['webhook']['last_failure_at']);
        $this->assertNull($row['last_error'], 'An error older than the last success is not current');
    }

    public function test_stripe_test_mode_reads_the_test_keys_and_the_test_webhook_monitor(): void
    {
        $this->register('stripe');
        $this->seed_stripe(true);
        // The live monitor is healthy, the test one is failing: only the test one counts.
        update_option('wc_stripe_wh_last_success_at', 1763400000);
        update_option('wc_stripe_wh_test_last_success_at', 1763300000);
        update_option('wc_stripe_wh_test_last_failure_at', 1763400000);
        update_option('wc_stripe_wh_test_last_error', 'signature_invalid');

        $row = $this->rows()['stripe'];
        $this->assertTrue($row['test_mode']);
        $this->assertFalse($row['connected'], 'The test secret key is empty');
        $this->assertSame('failing', $row['webhook']['status']);
        $this->assertFalse($row['webhook']['signing_secret_set']);
        $this->assertSame('signature_invalid', $row['last_error']);
    }

    public function test_stripe_with_no_webhook_ever_received(): void
    {
        $this->register('stripe');
        $this->seed_stripe(false);

        $row = $this->rows()['stripe'];
        $this->assertSame('none_received', $row['webhook']['status']);
        $this->assertNull($row['webhook']['last_success_at']);
        $this->assertNull($row['last_error']);
    }

    // --------------------------------------------------------- woopayments

    public function test_woopayments_reports_the_cached_account_state_and_test_mode(): void
    {
        $this->register('woocommerce_payments');
        update_option('woocommerce_woocommerce_payments_settings', ['enabled' => 'yes', 'test_mode' => 'yes']);
        update_option('wcpay_account_data', [
            'data'    => [
                'account_id'        => 'acct_HEALTH6',
                'email'             => 'owner@shop.example',
                'status'            => 'complete',
                'payments_enabled'  => true,
                'details_submitted' => true,
                'is_live'           => true,
                'has_overdue_requirements' => false,
            ],
            'fetched' => 1763400000,
            'errored' => false,
        ]);

        $row = $this->rows()['woocommerce_payments'];
        $this->assertSame('woopayments', $row['provider']);
        $this->assertTrue($row['enabled']);
        $this->assertTrue($row['test_mode']);
        $this->assertTrue($row['connected']);
        $this->assertSame('complete', $row['account']['status']);
        $this->assertTrue($row['account']['payments_enabled']);
        $this->assertTrue($row['account']['details_submitted']);
        $this->assertFalse($row['account']['past_due']);
        $this->assertSame(gmdate('Y-m-d H:i:s', 1763400000), $row['account']['checked_at']);
        $this->assertSame('managed', $row['webhook']['status']);
        $this->assertNull($row['last_error']);
    }

    public function test_woopayments_not_connected_and_a_failed_account_fetch(): void
    {
        $this->register('woocommerce_payments');
        update_option('wcpay_account_data', ['data' => [], 'fetched' => 1763400000, 'errored' => false]);
        $row = $this->rows()['woocommerce_payments'];
        $this->assertFalse($row['connected']);
        $this->assertFalse($row['test_mode'], 'No stored setting means live mode, as WooPayments reads it');

        update_option('wcpay_account_data', ['data' => false, 'fetched' => 1763400000, 'errored' => true]);
        $row = $this->rows()['woocommerce_payments'];
        $this->assertNull($row['connected'], 'Unknown when the last account fetch failed');
        $this->assertSame('account_fetch_failed', $row['last_error']);
    }

    // -------------------------------------------------------------- paypal

    public function test_paypal_payments_reports_connection_sandbox_and_webhook_registration(): void
    {
        $this->register('ppcp-gateway');
        update_option('woocommerce-ppcp-data-common', [
            'use_sandbox'        => true,
            'merchant_connected' => true,
            'merchant_id'        => 'PPMERCHANTHEALTH9',
            'merchant_email'     => 'owner@shop.example',
            'client_id'          => 'PPCLIENTHEALTH7',
            'client_secret'      => 'PPSECRETHEALTH8',
        ]);
        update_option('ppcp-webhook', ['url' => 'https://shop.example/wp-json/paypal/v1/incoming', 'event_types' => [], 'id' => 'WH-HEALTH10']);
        update_option('ppcp-last-webhook', ['id' => 'WH-EVENT-1', 'received_time' => 1763400000]);

        $row = $this->rows()['ppcp-gateway'];
        $this->assertSame('paypal', $row['provider']);
        $this->assertTrue($row['test_mode']);
        $this->assertTrue($row['connected']);
        $this->assertSame('registered', $row['webhook']['status']);
        $this->assertSame(gmdate('Y-m-d H:i:s', 1763400000), $row['webhook']['last_received_at']);
        $this->assertNull($row['last_error']);
    }

    public function test_paypal_payments_falls_back_to_the_legacy_settings_and_flags_a_missing_webhook(): void
    {
        $this->register('ppcp-gateway');
        update_option('woocommerce-ppcp-settings', [
            'sandbox_on'    => false,
            'merchant_id'   => 'PPMERCHANTHEALTH9',
            'merchant_email' => 'owner@shop.example',
            'client_id'     => 'PPCLIENTHEALTH7',
            'client_secret' => '',
        ]);

        $row = $this->rows()['ppcp-gateway'];
        $this->assertFalse($row['test_mode']);
        $this->assertFalse($row['connected'], 'The client secret is missing');
        $this->assertSame('not_registered', $row['webhook']['status']);
        $this->assertNull($row['webhook']['last_received_at']);
    }

    // -------------------------------------------------------------- params

    public function test_one_gateway_by_id_and_refusals(): void
    {
        $this->register('stripe', 'ppcp-gateway', 'woocommerce_payments');

        $rows = $this->rows();
        $this->assertSame(['stripe', 'ppcp-gateway', 'woocommerce_payments'], array_keys($rows));
        $this->assertArrayNotHasKey('bacs', $rows, 'Only the supported gateways are reported');

        $one = $this->rows(['gateway' => 'ppcp-gateway']);
        $this->assertSame(['ppcp-gateway'], array_keys($one));

        $out = $this->read(['gateway' => 'bacs']);
        $this->assertSame('unknown_gateway', $out['error']['code'] ?? null, (string) wp_json_encode($out));

        $out = $this->read(['id' => 'stripe']);
        $this->assertSame('invalid_params', $out['error']['code'] ?? null, (string) wp_json_encode($out));
    }

    public function test_a_shop_manager_can_read_it_and_a_customer_cannot(): void
    {
        $this->register('stripe');
        $this->seed_stripe(false);

        wp_set_current_user(self::factory()->user->create(['role' => 'shop_manager']));
        $this->assertSame(200, $this->read()['status'] ?? null);

        wp_set_current_user(self::factory()->user->create(['role' => 'customer']));
        $out = $this->read();
        $this->assertSame('operation_denied', $out['error']['code'] ?? null);
    }
}
