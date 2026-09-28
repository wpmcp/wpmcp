<?php

namespace WPMCP\Tests\Pro\WooCommerce;

use WPMCP\Governance\Governance;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tests\Free\Platform\RegisteredAbilities;
use WPMCP\Tests\Free\WooCommerce\VariableProductFixture;
use WPMCP\Tools\Rollback_Operation;
use WPMCP\Tools\Rollback_Session;
use WPMCP\Tools\WooCommerce\Catalog\Op_Catalog;
use WPMCP\Tools\WooCommerce\Catalog\Wc_Rest_Write_Dispatch;
use WPMCP\Tools\WooCommerce\Catalog\Woo_Ops;
use WPMCP\Tools\WooCommerce\Catalog\Woo_Read;
use WPMCP\Tools\WooCommerce\Catalog\Woo_Write;

/**
 * The write half of the deep WooCommerce operations catalog (issue #68).
 *
 * Acceptance criterion 2: destructive ops (delete, refund) require confirm
 * and batch ops are supported. On top of that, every write that touches
 * existing state must be snapshotted through Safe_Mutation first and be
 * restorable with rollback-operation, every op is governed individually, and
 * every gate refusal leaves the store untouched.
 */
class WooWriteTest extends \WP_UnitTestCase
{
    use VariableProductFixture;

    protected function setUp(): void
    {
        parent::setUp();
        if (! class_exists('WooCommerce')) {
            $this->markTestSkipped('WooCommerce is not active in this environment.');
        }

        Gate::set_pro_for_tests(true);
        Snapshot_Store::install();
        Governance::reset_for_tests();

        // Same role and REST server re-seeding as WooCatalogTest: WooCommerce's
        // store capabilities and its wc/v3 routes must be present regardless
        // of test order.
        \WC_Install::create_roles();
        $GLOBALS['wp_roles'] = null;
        wp_roles();

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        global $wp_rest_server;
        $wp_rest_server = null;
        rest_get_server();
    }

    protected function tearDown(): void
    {
        global $wp_rest_server;
        $wp_rest_server = null;

        remove_all_filters('wpmcp_woo_op_enabled');
        remove_all_filters('wpmcp_ability_enabled');
        Governance::reset_for_tests();
        wp_set_current_user(0);
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function simple_product(string $price = '10.00'): int
    {
        $product = new \WC_Product_Simple();
        $product->set_name('Mug');
        $product->set_regular_price($price);
        return (int) $product->save();
    }

    private function enable_op(string $op): void
    {
        add_filter('wpmcp_woo_op_enabled', static function ($enabled, $name) use ($op) {
            return $name === $op ? true : $enabled;
        }, 10, 2);
    }

    private function paid_order(): \WC_Order
    {
        $order = wc_create_order();
        $order->add_product(wc_get_product($this->simple_product('20.00')), 1);
        $order->calculate_totals();
        $order->set_status('processing');
        $order->save();
        return $order;
    }

    // ------------------------------------------------------------ catalog

    /** Acceptance criterion 4: every domain the issue names has ops. */
    public function test_the_catalog_covers_all_ten_domains_the_issue_names(): void
    {
        $covered = [];
        foreach (Op_Catalog::ops() as $def) {
            $covered[ $def['domain'] ] = true;
        }
        ksort($covered);

        $this->assertSame(
            ['coupons', 'customers', 'orders', 'products', 'refunds', 'settings', 'shipping', 'taxes', 'variations', 'webhooks'],
            array_keys($covered)
        );
    }

    public function test_every_write_row_names_a_mode_and_every_existing_state_write_names_a_snapshot(): void
    {
        foreach (Op_Catalog::ops() as $name => $def) {
            $this->assertContains($def['mode'], ['read', 'write', 'destructive'], "Op {$name} has no valid mode");

            if ('read' === $def['mode']) {
                $this->assertSame('GET', $def['method'], "Read op {$name} must be a GET");
                continue;
            }

            $this->assertNotSame('GET', $def['method'], "Write op {$name} must not be a GET");

            // Only a POST that creates a brand new object may run without a
            // snapshot target: there is no prior state to capture. Anything
            // that changes or removes existing state must name one.
            if (null === $def['snapshot']) {
                $this->assertSame('POST', $def['method'], "Op {$name} changes existing state without a snapshot");
            }
        }
    }

    /** Destructive ops: delete of any kind, and refund creation. */
    public function test_every_delete_and_the_refund_op_are_destructive(): void
    {
        foreach (Op_Catalog::ops() as $name => $def) {
            if ('DELETE' === $def['method']) {
                $this->assertSame('destructive', $def['mode'], "Op {$name} deletes but is not destructive");
            }
        }
        $this->assertSame('destructive', Op_Catalog::get('refunds.create')['mode']);
    }

    public function test_woo_ops_reports_mode_confirm_and_enabled_state(): void
    {
        $out = (new Woo_Ops())->handle(['domain' => 'products']);
        $rows = [];
        foreach ($out['domains']['products'] as $row) {
            $rows[ $row['op'] ] = $row;
        }

        $this->assertSame('destructive', $rows['products.delete']['mode']);
        $this->assertTrue($rows['products.delete']['requires_confirm']);
        $this->assertFalse($rows['products.delete']['enabled']);
        $this->assertSame('write', $rows['products.update']['mode']);
        $this->assertFalse($rows['products.update']['requires_confirm']);
        $this->assertTrue($rows['products.update']['enabled']);
        $this->assertSame('post', $rows['products.update']['snapshot']);
        $this->assertNull($rows['products.create']['snapshot']);
    }

    public function test_woo_write_is_registered_pro_in_the_woocommerce_domain_with_a_destructive_hint(): void
    {
        $found = null;
        foreach (RegisteredAbilities::all() as $ability) {
            if ('wpmcp/woo-write' === $ability->name) {
                $found = $ability;
            }
        }

        $this->assertNotNull($found, 'wpmcp/woo-write is not registered');
        $this->assertSame('pro', $found->tier);
        $this->assertSame('woocommerce', $found->domain);
        $this->assertSame('manage_woocommerce', $found->capability);
        $this->assertTrue($found->destructive_hint);
        $this->assertFalse($found->read_only_hint);
    }

    // ------------------------------------------------------- channel split

    public function test_woo_write_refuses_a_read_op(): void
    {
        $out = (new Woo_Write())->handle(['op' => 'products.list']);
        $this->assertSame('not_a_write_op', $out['error']['code']);
    }

    public function test_woo_read_refuses_a_write_op(): void
    {
        $this->expectException(\RuntimeException::class);
        (new Woo_Read())->handle(['op' => 'products.update', 'params' => ['id' => 1]]);
    }

    public function test_the_write_dispatch_refuses_get(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Wc_Rest_Write_Dispatch())->send('GET', '/wc/v3/products', []);
    }

    // ----------------------------------------------------- update + undo

    public function test_products_update_is_snapshotted_and_rolls_back(): void
    {
        $id  = $this->simple_product('10.00');
        $out = (new Woo_Write())->handle([
            'op'     => 'products.update',
            'params' => ['id' => $id, 'regular_price' => '42.00'],
        ]);

        $this->assertSame(200, $out['status']);
        $this->assertTrue($out['applied']);
        $this->assertTrue($out['recoverable']);
        $this->assertArrayHasKey('operation_id', $out);
        $this->assertNotNull(Snapshot_Store::get_by_operation($out['operation_id']));
        $this->assertSame('42.00', wc_get_product($id)->get_regular_price());

        $rolled = (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertTrue($rolled['restored']);
        $this->assertSame('10.00', wc_get_product($id)->get_regular_price());
    }

    public function test_variations_update_is_snapshotted_and_rolls_back(): void
    {
        $ids = $this->variable_product();
        $out = (new Woo_Write())->handle([
            'op'     => 'variations.update',
            'params' => ['product_id' => $ids['parent'], 'id' => $ids['small'], 'regular_price' => '77.00'],
        ]);

        $this->assertSame(200, $out['status']);
        $this->assertSame('77.00', wc_get_product($ids['small'])->get_regular_price());

        (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertSame('10.00', wc_get_product($ids['small'])->get_regular_price());
    }

    public function test_variations_list_reads_through_woo_read(): void
    {
        $ids = $this->variable_product();
        $out = (new Woo_Read())->handle(['op' => 'variations.list', 'params' => ['product_id' => $ids['parent']]]);

        $this->assertSame(200, $out['status']);
        $this->assertCount(2, $out['body']);
    }

    public function test_coupons_update_is_snapshotted_and_rolls_back(): void
    {
        $coupon = new \WC_Coupon();
        $coupon->set_code('spring');
        $coupon->set_amount('5');
        $id = (int) $coupon->save();

        $out = (new Woo_Write())->handle([
            'op'     => 'coupons.update',
            'params' => ['id' => $id, 'amount' => '15'],
        ]);
        $this->assertSame(200, $out['status']);
        $this->assertSame(15.0, (float) (new \WC_Coupon($id))->get_amount());

        (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertSame(5.0, (float) (new \WC_Coupon($id))->get_amount());
    }

    public function test_customers_update_is_snapshotted_and_rolls_back(): void
    {
        $user = self::factory()->user->create(['role' => 'customer', 'first_name' => 'Ada']);

        $out = (new Woo_Write())->handle([
            'op'     => 'customers.update',
            'params' => ['id' => $user, 'first_name' => 'Grace'],
        ]);
        $this->assertSame(200, $out['status']);
        $this->assertSame('Grace', get_userdata($user)->first_name);

        (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        clean_user_cache($user);
        $this->assertSame('Ada', get_userdata($user)->first_name);
    }

    /** A password is not in the user snapshot, so changing it could never be undone. */
    public function test_customers_update_refuses_a_password_change(): void
    {
        $user   = self::factory()->user->create(['role' => 'customer']);
        $before = get_userdata($user)->user_pass;

        $out = (new Woo_Write())->handle([
            'op'     => 'customers.update',
            'params' => ['id' => $user, 'password' => 'hunter2hunter2'],
        ]);

        $this->assertSame('forbidden_param', $out['error']['code']);
        clean_user_cache($user);
        $this->assertSame($before, get_userdata($user)->user_pass);
    }

    /** WooCommerce only strips underscore-prefixed meta, so capability keys must be refused here. */
    public function test_customer_writes_refuse_capability_meta(): void
    {
        global $wpdb;
        $user = self::factory()->user->create(['role' => 'customer']);
        $key  = $wpdb->get_blog_prefix() . 'capabilities';

        $update = (new Woo_Write())->handle([
            'op'     => 'customers.update',
            'params' => ['id' => $user, 'meta_data' => [['key' => $key, 'value' => ['administrator' => true]]]],
        ]);
        $create = (new Woo_Write())->handle([
            'op'     => 'customers.create',
            'params' => ['email' => 'mallory@example.com', 'meta_data' => [['key' => 'WP_User_Level', 'value' => 10]]],
        ]);

        $this->assertSame('forbidden_param', $update['error']['code']);
        $this->assertSame('forbidden_param', $create['error']['code']);
        clean_user_cache($user);
        $this->assertSame(['customer'], get_userdata($user)->roles);
        $this->assertFalse(get_user_by('email', 'mallory@example.com'));
    }

    public function test_settings_update_is_snapshotted_as_the_backing_option_and_rolls_back(): void
    {
        update_option('woocommerce_currency', 'USD');

        $out = (new Woo_Write())->handle([
            'op'     => 'settings.update',
            'params' => ['group_id' => 'general', 'id' => 'woocommerce_currency', 'value' => 'EUR'],
        ]);

        $this->assertSame(200, $out['status']);
        $this->assertSame('EUR', get_option('woocommerce_currency'));

        (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertSame('USD', get_option('woocommerce_currency'));
    }

    /** Email settings live as keys inside one array option; the whole option is snapshotted. */
    public function test_an_email_setting_is_snapshotted_as_its_array_option_and_rolls_back(): void
    {
        update_option('woocommerce_new_order_settings', ['enabled' => 'yes', 'recipient' => 'old@example.com']);

        $out = (new Woo_Write())->handle([
            'op'     => 'settings.update',
            'params' => ['group_id' => 'email_new_order', 'id' => 'recipient', 'value' => 'new@example.com'],
        ]);

        $this->assertSame(200, $out['status']);
        $this->assertSame('new@example.com', get_option('woocommerce_new_order_settings')['recipient']);

        (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertSame('old@example.com', get_option('woocommerce_new_order_settings')['recipient']);
    }

    public function test_settings_update_of_an_unknown_setting_is_refused_before_any_snapshot(): void
    {
        $out = (new Woo_Write())->handle([
            'op'     => 'settings.update',
            'params' => ['group_id' => 'general', 'id' => 'not_a_real_setting', 'value' => 'x'],
        ]);

        $this->assertSame('unknown_setting', $out['error']['code']);
    }

    // ------------------------------------------------------------ creates

    public function test_products_create_reports_honestly_that_there_is_no_snapshot(): void
    {
        $out = (new Woo_Write())->handle([
            'op'     => 'products.create',
            'params' => ['name' => 'Teapot', 'regular_price' => '30.00'],
        ]);

        $this->assertSame(201, $out['status']);
        $this->assertFalse($out['recoverable']);
        $this->assertArrayNotHasKey('operation_id', $out);
        $this->assertSame('products.delete', $out['undo_op']);
        $this->assertSame('Teapot', wc_get_product((int) $out['body']['id'])->get_name());
    }

    // --------------------------------------------------------- destructive

    public function test_a_destructive_op_is_disabled_by_default(): void
    {
        $id  = $this->simple_product();
        $out = (new Woo_Write())->handle(['op' => 'products.delete', 'params' => ['id' => $id], 'confirm' => true]);

        $this->assertSame('operation_disabled', $out['error']['code']);
        $this->assertSame('publish', get_post_status($id));
    }

    public function test_a_destructive_op_requires_confirm_even_when_enabled(): void
    {
        $this->enable_op('products.delete');
        $id = $this->simple_product();

        foreach ([[], ['confirm' => false], ['confirm' => 'true'], ['confirm' => 1]] as $extra) {
            $out = (new Woo_Write())->handle(['op' => 'products.delete', 'params' => ['id' => $id]] + $extra);
            $this->assertSame('confirmation_required', $out['error']['code']);
        }
        $this->assertSame('publish', get_post_status($id));
    }

    public function test_products_delete_with_confirm_is_snapshotted_and_rolls_back(): void
    {
        $this->enable_op('products.delete');
        $id = $this->simple_product();

        $out = (new Woo_Write())->handle([
            'op'      => 'products.delete',
            'params'  => ['id' => $id],
            'confirm' => true,
        ]);

        $this->assertSame(200, $out['status']);
        $this->assertSame('trash', get_post_status($id));
        $this->assertTrue($out['recoverable']);

        (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertSame('publish', get_post_status($id));
    }

    public function test_products_force_delete_resurrects_on_rollback(): void
    {
        $this->enable_op('products.delete');
        $id = $this->simple_product('12.00');

        $out = (new Woo_Write())->handle([
            'op'      => 'products.delete',
            'params'  => ['id' => $id, 'force' => true],
            'confirm' => true,
        ]);
        $this->assertSame(200, $out['status']);
        $this->assertNull(get_post($id));

        (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertSame('12.00', wc_get_product($id)->get_regular_price());
    }

    /** The product snapshot does not cover variations, which WooCommerce removes with the parent. */
    public function test_deleting_or_retyping_a_product_with_variations_is_refused(): void
    {
        $this->enable_op('products.delete');
        $ids = $this->variable_product();

        $delete = (new Woo_Write())->handle(['op' => 'products.delete', 'params' => ['id' => $ids['parent']], 'confirm' => true]);
        $retype = (new Woo_Write())->handle(['op' => 'products.update', 'params' => ['id' => $ids['parent'], 'type' => 'simple']]);
        $rename = (new Woo_Write())->handle(['op' => 'products.update', 'params' => ['id' => $ids['parent'], 'name' => 'Linen Shirt II']]);

        $this->assertSame('has_variations', $delete['error']['code']);
        $this->assertSame('has_variations', $retype['error']['code']);
        $this->assertSame(200, $rename['status']);
        $this->assertSame('publish', get_post_status($ids['parent']));
        $this->assertSame('publish', get_post_status($ids['small']));
        $this->assertTrue(wc_get_product($ids['parent'])->is_type('variable'));
    }

    public function test_refunds_create_needs_confirm_never_triggers_a_gateway_refund_by_default_and_is_flagged_unrecoverable(): void
    {
        $this->enable_op('refunds.create');
        $order = $this->paid_order();

        $refused = (new Woo_Write())->handle([
            'op'     => 'refunds.create',
            'params' => ['order_id' => $order->get_id(), 'amount' => '5.00'],
        ]);
        $this->assertSame('confirmation_required', $refused['error']['code']);
        $this->assertCount(0, wc_get_order($order->get_id())->get_refunds());

        $spy = new class extends Wc_Rest_Write_Dispatch {
            /** @var array<string, mixed> */
            public $last_params = [];
            public function send(string $method, string $route, array $params): array
            {
                $this->last_params = $params;
                return parent::send($method, $route, $params);
            }
        };

        $out = (new Woo_Write($spy))->handle([
            'op'      => 'refunds.create',
            'params'  => ['order_id' => $order->get_id(), 'amount' => '5.00'],
            'confirm' => true,
        ]);

        $this->assertSame(201, $out['status']);
        $this->assertFalse($spy->last_params['api_refund']);
        $this->assertFalse($out['recoverable']);
        $this->assertArrayHasKey('operation_id', $out);
        $this->assertCount(1, wc_get_order($order->get_id())->get_refunds());
    }

    // ---------------------------------------------- capability + governance

    public function test_a_write_op_is_denied_without_its_capability(): void
    {
        $id = $this->simple_product('10.00');
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));

        $out = (new Woo_Write())->handle(['op' => 'products.update', 'params' => ['id' => $id, 'regular_price' => '1.00']]);

        $this->assertSame('operation_denied', $out['error']['code']);
        $this->assertSame('capability', $out['error']['data']['reason']);
        $this->assertSame('10.00', wc_get_product($id)->get_regular_price());
    }

    public function test_customers_update_needs_edit_users_on_top_of_the_store_capability(): void
    {
        $this->assertSame('edit_users', Op_Catalog::get('customers.update')['capability']);
        $this->assertSame('create_users', Op_Catalog::get('customers.create')['capability']);
        $this->assertSame('edit_shop_orders', Op_Catalog::get('refunds.create')['capability']);
    }

    public function test_a_single_write_op_can_be_disabled_by_governance(): void
    {
        $id = $this->simple_product('10.00');
        add_filter('wpmcp_ability_enabled', static function ($enabled, $name) {
            return 'wpmcp/woo-products-update' === $name ? false : $enabled;
        }, 10, 2);

        $out = (new Woo_Write())->handle(['op' => 'products.update', 'params' => ['id' => $id, 'regular_price' => '1.00']]);

        $this->assertSame('operation_denied', $out['error']['code']);
        $this->assertSame('governance', $out['error']['data']['reason']);
        $this->assertSame('10.00', wc_get_product($id)->get_regular_price());
    }

    public function test_the_delete_operation_toggle_disables_destructive_ops_only(): void
    {
        $this->enable_op('products.delete');
        Governance::set_operation_toggle('delete', false);
        $id = $this->simple_product('10.00');

        $out = (new Woo_Write())->handle(['op' => 'products.delete', 'params' => ['id' => $id], 'confirm' => true]);
        $this->assertSame('governance', $out['error']['data']['reason']);
        $this->assertSame('publish', get_post_status($id));

        $ok = (new Woo_Write())->handle(['op' => 'products.update', 'params' => ['id' => $id, 'regular_price' => '11.00']]);
        $this->assertSame(200, $ok['status']);
    }

    public function test_a_single_read_op_can_be_disabled_by_governance(): void
    {
        add_filter('wpmcp_ability_enabled', static function ($enabled, $name) {
            return 'wpmcp/woo-customers-list' === $name ? false : $enabled;
        }, 10, 2);

        $out = (new Woo_Read())->handle(['op' => 'customers.list']);
        $this->assertSame('governance', $out['error']['data']['reason']);
    }

    /** A webhook's signing secret never reaches the model context. */
    public function test_webhook_reads_redact_the_signing_secret(): void
    {
        $webhook = new \WC_Webhook();
        $webhook->set_name('Order hook');
        $webhook->set_topic('order.created');
        $webhook->set_delivery_url('https://example.com/hook');
        $webhook->set_secret('s3cr3t-signing-key');
        $webhook->set_status('paused');
        $webhook->set_user_id(get_current_user_id());
        $id = $webhook->save();

        $one  = (new Woo_Read())->handle(['op' => 'webhooks.get', 'params' => ['id' => $id]]);
        $list = (new Woo_Read())->handle(['op' => 'webhooks.list']);

        $this->assertSame(200, $one['status']);
        $this->assertStringNotContainsString('s3cr3t-signing-key', (string) wp_json_encode($one));
        $this->assertStringNotContainsString('s3cr3t-signing-key', (string) wp_json_encode($list));
    }

    // ---------------------------------------------------------------- batch

    public function test_a_batch_applies_each_item_with_its_own_snapshot_in_one_session(): void
    {
        $a = $this->simple_product('10.00');
        $b = $this->simple_product('20.00');

        $out = (new Woo_Write())->handle([
            'batch' => [
                ['op' => 'products.update', 'params' => ['id' => $a, 'regular_price' => '11.00']],
                ['op' => 'products.update', 'params' => ['id' => $b, 'regular_price' => '21.00']],
            ],
        ]);

        $this->assertTrue($out['batch']);
        $this->assertSame(2, $out['applied']);
        $this->assertSame(0, $out['failed']);
        $this->assertCount(2, $out['results']);
        $this->assertNotSame($out['results'][0]['operation_id'], $out['results'][1]['operation_id']);
        $this->assertSame('11.00', wc_get_product($a)->get_regular_price());
        $this->assertSame('21.00', wc_get_product($b)->get_regular_price());

        // One session covers the whole batch, so it undoes in one call.
        (new Rollback_Session())->handle(['session_id' => $out['session_id']]);
        $this->assertSame('10.00', wc_get_product($a)->get_regular_price());
        $this->assertSame('20.00', wc_get_product($b)->get_regular_price());
    }

    public function test_a_batch_is_rejected_whole_when_any_item_fails_a_gate(): void
    {
        $this->enable_op('products.delete');
        $a = $this->simple_product('10.00');
        $b = $this->simple_product('20.00');

        $out = (new Woo_Write())->handle([
            'batch' => [
                ['op' => 'products.update', 'params' => ['id' => $a, 'regular_price' => '11.00']],
                ['op' => 'products.delete', 'params' => ['id' => $b]],
            ],
        ]);

        $this->assertSame('batch_rejected', $out['error']['code']);
        $this->assertSame(1, $out['error']['data']['index']);
        $this->assertSame('confirmation_required', $out['error']['data']['error']['code']);
        $this->assertSame('10.00', wc_get_product($a)->get_regular_price());
        $this->assertSame('publish', get_post_status($b));
    }

    public function test_a_batch_rejects_an_unknown_op_without_side_effects(): void
    {
        $a = $this->simple_product('10.00');

        $out = (new Woo_Write())->handle([
            'batch' => [
                ['op' => 'products.update', 'params' => ['id' => $a, 'regular_price' => '11.00']],
                ['op' => 'products.nope'],
            ],
        ]);

        $this->assertSame('batch_rejected', $out['error']['code']);
        $this->assertSame('10.00', wc_get_product($a)->get_regular_price());
    }

    public function test_a_batch_reports_a_per_item_failure_and_keeps_the_others(): void
    {
        $a = $this->simple_product('10.00');

        $out = (new Woo_Write())->handle([
            'batch' => [
                ['op' => 'products.update', 'params' => ['id' => 999999, 'regular_price' => '1.00']],
                ['op' => 'products.update', 'params' => ['id' => $a, 'regular_price' => '11.00']],
            ],
        ]);

        $this->assertSame(1, $out['applied']);
        $this->assertSame(1, $out['failed']);
        $this->assertFalse($out['results'][0]['applied']);
        $this->assertGreaterThanOrEqual(400, $out['results'][0]['status']);
        $this->assertTrue($out['results'][1]['applied']);
        $this->assertSame('11.00', wc_get_product($a)->get_regular_price());
    }

    public function test_a_batch_over_the_item_limit_is_refused(): void
    {
        $items = array_fill(0, Woo_Write::MAX_BATCH + 1, ['op' => 'products.create', 'params' => ['name' => 'x']]);
        $out   = (new Woo_Write())->handle(['batch' => $items]);

        $this->assertSame('batch_too_large', $out['error']['code']);
    }

    public function test_op_and_batch_together_are_refused(): void
    {
        $out = (new Woo_Write())->handle(['op' => 'products.create', 'batch' => []]);
        $this->assertSame('invalid_request', $out['error']['code']);
    }

    // ------------------------------------------------------- no loopback

    public function test_writes_dispatch_in_process_with_no_http_loopback(): void
    {
        $id       = $this->simple_product('10.00');
        $outbound = 0;
        $spy      = static function ($preempt) use (&$outbound) {
            $outbound++;
            return $preempt;
        };
        add_filter('pre_http_request', $spy);

        try {
            $out = (new Woo_Write())->handle(['op' => 'products.update', 'params' => ['id' => $id, 'regular_price' => '9.00']]);
        } finally {
            remove_filter('pre_http_request', $spy);
        }

        $this->assertSame(200, $out['status']);
        $this->assertSame(0, $outbound);
    }
}
