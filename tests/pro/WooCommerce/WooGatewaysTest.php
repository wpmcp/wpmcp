<?php

namespace WPMCP\Tests\Pro\WooCommerce;

use WPMCP\Governance\Governance;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Rollback_Operation;
use WPMCP\Tools\Rollback_Session;
use WPMCP\Tools\WooCommerce\Catalog\Op_Catalog;
use WPMCP\Tools\WooCommerce\Catalog\Woo_Read;
use WPMCP\Tools\WooCommerce\Catalog\Woo_Write;

/**
 * Payment gateway settings (issue #292, fourth slice) as ops on the existing
 * woo-read and woo-write dispatchers. Reads show each gateway's enabled
 * state, title, order and settings, with every secret-like field (keys,
 * secrets, passwords, tokens, and fields of type password) masked. Writes
 * change the enabled flag, title, description, order and non-secret
 * settings, refuse secret fields outright, and snapshot the option they
 * change (the gateway's settings option, or the gateway order option for an
 * order change) so rollback restores it exactly. Restoring one of those
 * snapshots takes manage_woocommerce.
 */
class WooGatewaysTest extends \WP_UnitTestCase
{
    private const FAKE_ID     = 'wpmcp_test_pay';
    private const FAKE_OPTION = 'woocommerce_wpmcp_test_pay_settings';
    private const ORDER       = 'woocommerce_gateway_order';

    /** Every secret value seeded into the fake gateway; none may ever appear in a response. */
    private const SECRETS = ['sk_live_SECRET123', 'pk_live_PUB456', 'whsec_SIGN789', 'tok_ACCESS000'];

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

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        update_option(self::FAKE_OPTION, [
            'enabled'              => 'yes',
            'title'                => 'Test Pay',
            'description'          => 'Pay with the test gateway.',
            'secret_key'           => 'sk_live_SECRET123',
            'publishable_key'      => 'pk_live_PUB456',
            'webhook_signing'      => 'whsec_SIGN789',
            'access_token'         => 'tok_ACCESS000',
            'testmode'             => 'no',
            'statement_descriptor' => 'SHOP',
            'capture'              => 'yes',
        ]);
        delete_option(self::ORDER);

        $fake           = $this->fake_gateway();
        $this->register = static function ($gateways) use ($fake) {
            $gateways[] = $fake;
            return $gateways;
        };
        add_filter('woocommerce_payment_gateways', $this->register);
        $this->reload_gateways();

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
            $this->reload_gateways();
        }
        remove_all_filters('wpmcp_woo_op_enabled');
        Governance::reset_for_tests();
        wp_set_current_user(0);
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    // ------------------------------------------------------------ fixtures

    private function reload_gateways(): void
    {
        $gateways                   = WC()->payment_gateways();
        $gateways->payment_gateways = [];
        $gateways->init();
    }

    /** A third-party style gateway with secret and non-secret settings. */
    private function fake_gateway(): \WC_Payment_Gateway
    {
        return new class extends \WC_Payment_Gateway {
            public function __construct()
            {
                $this->id                 = 'wpmcp_test_pay';
                $this->method_title       = 'Test Pay';
                $this->method_description = 'A <strong>test</strong> gateway.';
                $this->has_fields         = false;
                $this->init_form_fields();
                $this->init_settings();
                $this->title       = $this->get_option('title');
                $this->description = $this->get_option('description');
            }

            public function init_form_fields()
            {
                $this->form_fields = [
                    'enabled'              => [ 'title' => 'Enable', 'type' => 'checkbox', 'default' => 'no' ],
                    'title'                => [ 'title' => 'Title', 'type' => 'text', 'default' => 'Test Pay' ],
                    'description'          => [ 'title' => 'Description', 'type' => 'textarea', 'default' => '' ],
                    'api_details'          => [ 'title' => 'API credentials', 'type' => 'title' ],
                    'secret_key'           => [ 'title' => 'Secret key', 'type' => 'text', 'default' => '' ],
                    'publishable_key'      => [ 'title' => 'Publishable key', 'type' => 'text', 'default' => '' ],
                    'webhook_signing'      => [ 'title' => 'Webhook signing', 'type' => 'password', 'default' => '' ],
                    'access_token'         => [ 'title' => 'Access token', 'type' => 'text', 'default' => '' ],
                    'refresh_token'        => [ 'title' => 'Refresh token', 'type' => 'text', 'default' => '' ],
                    'testmode'             => [ 'title' => 'Test mode', 'type' => 'checkbox', 'default' => 'yes' ],
                    'statement_descriptor' => [ 'title' => 'Statement descriptor', 'type' => 'text', 'default' => '' ],
                    'capture'              => [ 'title' => 'Capture', 'type' => 'select', 'default' => 'yes', 'options' => [ 'yes' => 'Immediately', 'no' => 'Later' ] ],
                ];
            }
        };
    }

    private function read(string $op, array $params = []): array
    {
        return (new Woo_Read())->handle(['op' => $op, 'params' => $params]);
    }

    private function write(string $op, array $params, array $extra = []): array
    {
        return (new Woo_Write())->handle(['op' => $op, 'params' => $params] + $extra);
    }

    /** The raw option rows a gateway write can touch, existence included. */
    private function state(string $option = self::FAKE_OPTION): array
    {
        $missing = '__missing__';
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete($option, 'options');
        wp_cache_delete(self::ORDER, 'options');
        return [
            'settings' => get_option($option, $missing),
            'order'    => get_option(self::ORDER, $missing),
        ];
    }

    private function snapshot_count(): int
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- counts ledger rows to prove a refusal wrote nothing.
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wpmcp_snapshots");
    }

    private function assertNoSecrets(array $out): void
    {
        $json = (string) wp_json_encode($out);
        foreach (self::SECRETS as $secret) {
            $this->assertStringNotContainsString($secret, $json, 'A gateway secret leaked into a response');
        }
    }

    // ------------------------------------------------------------- catalog

    public function test_the_catalog_carries_gateway_ops_on_the_existing_dispatchers(): void
    {
        $ops    = Op_Catalog::ops();
        $expect = [
            'gateways.list'   => ['read', 'GET', null],
            'gateways.get'    => ['read', 'GET', null],
            'gateways.update' => ['write', 'PUT', 'wc_gateway'],
        ];
        foreach ($expect as $op => [$mode, $method, $snapshot]) {
            $this->assertArrayHasKey($op, $ops);
            $this->assertSame('gateways', $ops[ $op ]['domain']);
            $this->assertSame($mode, $ops[ $op ]['mode'], $op);
            $this->assertSame($method, $ops[ $op ]['method'], $op);
            $this->assertSame($snapshot, $ops[ $op ]['snapshot']['type'] ?? null, $op);
            $this->assertSame('manage_woocommerce', $ops[ $op ]['capability'], $op);
        }
        $this->assertTrue($ops['gateways.update']['recoverable']);
    }

    // --------------------------------------------------------------- reads

    public function test_list_shows_state_title_order_and_settings_with_secrets_masked(): void
    {
        update_option(self::ORDER, ['cheque' => 0, self::FAKE_ID => 1]);

        $out = $this->read('gateways.list');
        $this->assertSame(200, $out['status'], wp_json_encode($out));
        $this->assertNoSecrets($out);

        $rows = [];
        foreach ($out['body']['gateways'] as $row) {
            $rows[ $row['id'] ] = $row;
        }
        foreach (['bacs', 'cheque', 'cod', self::FAKE_ID] as $id) {
            $this->assertArrayHasKey($id, $rows, "Gateway {$id} is listed");
        }

        $fake = $rows[ self::FAKE_ID ];
        $this->assertTrue($fake['enabled']);
        $this->assertSame('Test Pay', $fake['title']);
        $this->assertSame('Pay with the test gateway.', $fake['description']);
        $this->assertSame(1, $fake['order']);
        $this->assertSame(0, $rows['cheque']['order']);
        $this->assertNull($rows['cod']['order'], 'A gateway with no stored position has no order');
        $this->assertSame('SHOP', $fake['settings']['statement_descriptor']['value']);
        $this->assertSame('[redacted]', $fake['settings']['secret_key']['value']);
        $this->assertArrayNotHasKey('api_details', $fake['settings'], 'Section headings are not settings');
    }

    public function test_get_masks_every_secret_like_field_and_marks_it(): void
    {
        $out = $this->read('gateways.get', ['id' => self::FAKE_ID]);
        $this->assertSame(200, $out['status'], wp_json_encode($out));
        $this->assertNoSecrets($out);

        $settings = $out['body']['settings'];
        foreach (['secret_key', 'publishable_key', 'webhook_signing', 'access_token'] as $key) {
            $this->assertSame('[redacted]', $settings[ $key ]['value'], $key);
            $this->assertTrue($settings[ $key ]['secret'], $key);
        }
        $this->assertSame('', $settings['refresh_token']['value'], 'An empty secret is shown as empty, not as redacted');
        $this->assertTrue($settings['refresh_token']['secret']);
        $this->assertSame('password', $settings['webhook_signing']['type']);

        foreach (['statement_descriptor' => 'SHOP', 'testmode' => 'no', 'capture' => 'yes'] as $key => $value) {
            $this->assertSame($value, $settings[ $key ]['value'], $key);
            $this->assertFalse($settings[ $key ]['secret'], $key);
        }
        $this->assertSame('Statement descriptor', $settings['statement_descriptor']['label']);

        $missing = $this->read('gateways.get', ['id' => 'no_such_gateway']);
        $this->assertSame('unknown_gateway', $missing['error']['code'] ?? null, wp_json_encode($missing));
    }

    // -------------------------------------------------------------- writes

    public function test_update_changes_state_title_description_and_settings_and_rolls_back_exactly(): void
    {
        $before = $this->state();

        $out = $this->write('gateways.update', [
            'id'          => self::FAKE_ID,
            'enabled'     => false,
            'title'       => 'Card payments',
            'description' => 'Pay by card.',
            'settings'    => [ 'statement_descriptor' => 'MYSHOP', 'testmode' => 'yes', 'capture' => 'no' ],
        ]);
        $this->assertSame(200, $out['status'], wp_json_encode($out));
        $this->assertTrue($out['applied']);
        $this->assertTrue($out['recoverable']);
        $this->assertNotEmpty($out['operation_id']);
        $this->assertNoSecrets($out);

        $this->assertFalse($out['body']['enabled']);
        $this->assertSame('Card payments', $out['body']['title']);
        $this->assertSame('[redacted]', $out['body']['settings']['secret_key']['value']);

        $stored = get_option(self::FAKE_OPTION);
        $this->assertSame('no', $stored['enabled']);
        $this->assertSame('Card payments', $stored['title']);
        $this->assertSame('Pay by card.', $stored['description']);
        $this->assertSame('MYSHOP', $stored['statement_descriptor']);
        $this->assertSame('yes', $stored['testmode']);
        $this->assertSame('no', $stored['capture']);
        $this->assertSame('sk_live_SECRET123', $stored['secret_key'], 'Secrets are left exactly as they were');
        $this->assertSame($before['order'], $this->state()['order'], 'A settings write leaves the gateway order alone');

        $rolled = (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertTrue($rolled['restored'], wp_json_encode($rolled));
        $this->assertSame($before, $this->state(), 'Rollback restores the settings option exactly');
    }

    public function test_update_of_a_never_configured_gateway_rolls_back_to_no_option(): void
    {
        delete_option('woocommerce_cheque_settings');
        $this->reload_gateways();
        $before = $this->state('woocommerce_cheque_settings');
        $this->assertSame('__missing__', $before['settings']);

        $out = $this->write('gateways.update', ['id' => 'cheque', 'enabled' => true]);
        $this->assertSame(200, $out['status'], wp_json_encode($out));
        $this->assertTrue($out['body']['enabled']);
        $this->assertSame('yes', get_option('woocommerce_cheque_settings')['enabled']);

        (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertSame($before, $this->state('woocommerce_cheque_settings'), 'The option the write created is removed again');
    }

    public function test_order_change_snapshots_the_order_option_and_rolls_back_exactly(): void
    {
        update_option(self::ORDER, ['bacs' => 0, 'cheque' => 1]);
        $before = $this->state();

        $out = $this->write('gateways.update', ['id' => self::FAKE_ID, 'order' => 0]);
        $this->assertSame(200, $out['status'], wp_json_encode($out));
        $this->assertSame(0, $out['body']['order']);
        $this->assertSame(['bacs' => 0, 'cheque' => 1, self::FAKE_ID => 0], get_option(self::ORDER));
        $this->assertSame($before['settings'], $this->state()['settings'], 'An order change leaves the settings option alone');

        (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertSame($before, $this->state());

        // With no stored order at all, rollback removes the option again.
        delete_option(self::ORDER);
        $before = $this->state();
        $out    = $this->write('gateways.update', ['id' => 'cod', 'order' => '3']);
        $this->assertSame(200, $out['status'], wp_json_encode($out));
        $this->assertSame(['cod' => 3], get_option(self::ORDER));
        (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertSame($before, $this->state());
    }

    public function test_a_session_of_settings_and_order_changes_unwinds_together(): void
    {
        update_option(self::ORDER, ['cheque' => 0]);
        $before  = $this->state();
        $session = wp_generate_uuid4();

        $first  = $this->write('gateways.update', ['id' => self::FAKE_ID, 'title' => 'One'], ['session_id' => $session]);
        $second = $this->write('gateways.update', ['id' => self::FAKE_ID, 'order' => 2], ['session_id' => $session]);
        $third  = $this->write('gateways.update', ['id' => self::FAKE_ID, 'settings' => ['statement_descriptor' => 'TWO']], ['session_id' => $session]);
        foreach ([$first, $second, $third] as $out) {
            $this->assertSame(200, $out['status'], wp_json_encode($out));
        }

        (new Rollback_Session())->handle(['session_id' => $session]);
        $this->assertSame($before, $this->state());
    }

    // ------------------------------------------------------------ refusals

    public function test_refusals_write_nothing(): void
    {
        $before = $this->state();
        $ledger = $this->snapshot_count();

        $cases = [
            [['id' => 'no_such_gateway', 'enabled' => true], 'unknown_gateway'],
            [['id' => self::FAKE_ID, 'settings' => ['secret_key' => 'sk_live_NEW']], 'secret_field'],
            [['id' => self::FAKE_ID, 'settings' => ['webhook_signing' => 'whsec_NEW']], 'secret_field'],
            [['id' => self::FAKE_ID, 'settings' => ['access_token' => 'tok_NEW']], 'secret_field'],
            [['id' => self::FAKE_ID, 'settings' => ['nope' => 'x']], 'invalid_params'],
            [['id' => self::FAKE_ID, 'settings' => ['api_details' => 'x']], 'invalid_params'],
            [['id' => self::FAKE_ID, 'settings' => ['capture' => 'sometimes']], 'invalid_params'],
            [['id' => self::FAKE_ID, 'settings' => ['testmode' => 'maybe']], 'invalid_params'],
            [['id' => self::FAKE_ID, 'settings' => 'x'], 'invalid_params'],
            [['id' => self::FAKE_ID, 'enabled' => 'sure'], 'invalid_params'],
            [['id' => self::FAKE_ID, 'title' => ['x']], 'invalid_params'],
            [['id' => self::FAKE_ID, 'order' => -1], 'invalid_params'],
            [['id' => self::FAKE_ID, 'order' => 1, 'title' => 'x'], 'invalid_params'],
            [['id' => self::FAKE_ID, 'color' => 'red'], 'invalid_params'],
            [['id' => self::FAKE_ID], 'invalid_params'],
        ];
        foreach ($cases as [$params, $code]) {
            $out = $this->write('gateways.update', $params);
            $this->assertSame($code, $out['error']['code'] ?? null, wp_json_encode($params) . ' => ' . wp_json_encode($out));
            $this->assertNoSecrets($out);
        }

        $this->assertSame($before, $this->state());
        $this->assertSame($ledger, $this->snapshot_count(), 'A refusal writes no snapshot');
    }

    public function test_gateway_ops_and_their_rollback_need_manage_woocommerce(): void
    {
        $before = $this->state();

        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        foreach (['gateways.list' => [], 'gateways.get' => ['id' => self::FAKE_ID]] as $op => $params) {
            $out = $this->read($op, $params);
            $this->assertSame('operation_denied', $out['error']['code'] ?? null, $op);
        }
        $out = $this->write('gateways.update', ['id' => self::FAKE_ID, 'title' => 'x']);
        $this->assertSame('operation_denied', $out['error']['code'] ?? null);
        $this->assertSame($before, $this->state());

        wp_set_current_user(self::factory()->user->create(['role' => 'shop_manager']));
        $out = $this->write('gateways.update', ['id' => self::FAKE_ID, 'title' => 'Shop managed']);
        $this->assertSame(200, $out['status'], wp_json_encode($out));
        $after = $this->state();

        // The snapshot holds the gateway's secrets, so an editor, who may
        // run rollback-operation, may not restore it.
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        $rolled = (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertFalse($rolled['restored'] ?? false, wp_json_encode($rolled));
        $this->assertSame($after, $this->state());

        wp_set_current_user(self::factory()->user->create(['role' => 'shop_manager']));
        $rolled = (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertTrue($rolled['restored'], wp_json_encode($rolled));
        $this->assertSame($before, $this->state());
    }
}
