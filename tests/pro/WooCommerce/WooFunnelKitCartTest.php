<?php

namespace WPMCP\Tests\Pro\WooCommerce;

use WPMCP\Governance\Governance;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Rollback_Operation;
use WPMCP\Tools\WooCommerce\Catalog\Cart_Ops;
use WPMCP\Tools\WooCommerce\Catalog\Op_Catalog;
use WPMCP\Tools\WooCommerce\Catalog\Woo_Ops;
use WPMCP\Tools\WooCommerce\Catalog\Woo_Read;
use WPMCP\Tools\WooCommerce\Catalog\Woo_Write;

/**
 * FunnelKit Cart for WooCommerce (issue #832) as the cart domain of the
 * existing woo-read and woo-write dispatchers.
 *
 * The plugin keeps every drawer, coupon, upsell, reward and style setting in
 * one option, fkcart_settings, read over its built-in defaults, and shows a
 * cart item's own WooCommerce upsells and cross-sells as the drawer's
 * upsells, editing them on the product (_upsell_ids, _crosssell_ids). The
 * fixture below is recorded from the free plugin's storage (1.9.1.3): the
 * stored option holds only what the site changed, rewards are a list of
 * rows. Detection is stubbed with Cart_Ops::set_active_for_tests().
 *
 * Writes are off until the site opts in through wpmcp_woo_op_enabled,
 * every write is snapshotted and undone here exactly.
 */
class WooFunnelKitCartTest extends \WP_UnitTestCase
{
    private const WRITES = [ 'cart.update-settings', 'cart.add-upsell', 'cart.remove-upsell' ];

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
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        Cart_Ops::set_active_for_tests(true);
        delete_option('fkcart_settings');
    }

    protected function tearDown(): void
    {
        Cart_Ops::set_active_for_tests(null);
        remove_all_filters('wpmcp_woo_op_enabled');
        remove_all_actions('fkcart_settings_saved');
        delete_option('fkcart_settings');
        Governance::reset_for_tests();
        wp_set_current_user(0);
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    // ------------------------------------------------------------ fixtures

    private function read(string $op, array $params = []): array
    {
        return (new Woo_Read())->handle([ 'op' => $op, 'params' => $params ]);
    }

    private function write(string $op, array $params): array
    {
        return (new Woo_Write())->handle([ 'op' => $op, 'params' => $params ]);
    }

    private function opt_in(): void
    {
        add_filter('wpmcp_woo_op_enabled', static fn ($on, $op) => str_starts_with((string) $op, 'cart.') ? true : $on, 10, 2);
    }

    /** fkcart_settings as the plugin's settings screen saves a changed site. */
    private function stored(): array
    {
        return [
            'enable_cart'          => 1,
            'cart_display'         => 'entire',
            'cart_heading'         => 'Your bag',
            'enable_upsells'       => true,
            'upsell_type'          => 'cross_sells',
            'upsell_max_count'     => 3,
            'css_accent_color'     => '#112233',
            'reward_title'         => 'All rewards unlocked',
            'reward'               => [
                [ 'type' => 'freeshipping', 'amount' => '50', 'title' => 'Free shipping', 'icon_title' => 'Shipping' ],
                [ 'type' => 'discount', 'amount' => '100', 'discount' => '10', 'title' => '10% off', 'icon_title' => 'Discount' ],
            ],
        ];
    }

    private function product(string $name = 'Mug'): int
    {
        $product = new \WC_Product_Simple();
        $product->set_name($name);
        $product->set_regular_price('10');
        $product->set_status('publish');
        return $product->save();
    }

    private function rollback(array $out): void
    {
        $this->assertArrayHasKey('operation_id', $out, wp_json_encode($out));
        $rolled = (new Rollback_Operation())->handle([ 'operation_id' => $out['operation_id'] ]);
        $this->assertTrue($rolled['restored'], wp_json_encode($rolled));
    }

    /** @return int[] */
    private function upsells(int $product, string $meta = '_upsell_ids'): array
    {
        clean_post_cache($product);
        return array_values(array_filter(array_map('intval', (array) get_post_meta($product, $meta, true))));
    }

    // ------------------------------------------------------------- catalog

    public function test_the_catalog_carries_cart_ops_on_the_existing_pair(): void
    {
        $ops = Op_Catalog::ops();

        foreach ([ 'cart.settings', 'cart.upsells' ] as $op) {
            $this->assertSame('read', $ops[ $op ]['mode'], $op);
            $this->assertSame('cart-for-woocommerce', $ops[ $op ]['plugin'], $op);
            $this->assertSame('cart', $ops[ $op ]['domain'], $op);
        }
        $this->assertSame('option', $ops['cart.update-settings']['snapshot']['type']);
        $this->assertSame('fkcart_settings', $ops['cart.update-settings']['snapshot']['option']);
        $this->assertSame('post', $ops['cart.add-upsell']['snapshot']['type']);
        $this->assertSame('post', $ops['cart.remove-upsell']['snapshot']['type']);
        foreach (self::WRITES as $op) {
            $this->assertSame('write', $ops[ $op ]['mode'], $op);
            $this->assertTrue($ops[ $op ]['recoverable'], $op);
            $this->assertTrue($ops[ $op ]['opt_in'], $op);
            $this->assertSame('manage_woocommerce', $ops[ $op ]['capability'], $op);
            $this->assertSame('cart-for-woocommerce', $ops[ $op ]['plugin'], $op);
        }
    }

    public function test_without_the_plugin_the_domain_is_hidden_and_calls_are_refused(): void
    {
        Cart_Ops::set_active_for_tests(false);

        $listed = (new Woo_Ops())->handle([]);
        $this->assertArrayNotHasKey('cart', $listed['domains']);

        $out = $this->read('cart.settings');
        $this->assertSame('plugin_missing', $out['error']['code'] ?? null, wp_json_encode($out));
        $this->assertStringContainsString('Install and activate FunnelKit Cart for WooCommerce', $out['error']['message']);
        $this->assertSame(admin_url('plugins.php'), $out['error']['data']['plugins_url']);

        $this->opt_in();
        $out = $this->write('cart.update-settings', [ 'settings' => [ 'cart_heading' => 'x' ] ]);
        $this->assertSame('plugin_missing', $out['error']['code'] ?? null);
        $this->assertFalse(get_option('fkcart_settings'));
    }

    public function test_writes_are_off_until_the_site_opts_in(): void
    {
        $listed = (new Woo_Ops())->handle([ 'domain' => 'cart' ]);
        $rows   = array_column($listed['domains']['cart'], null, 'op');
        $this->assertTrue($rows['cart.settings']['enabled']);
        foreach (self::WRITES as $op) {
            $this->assertFalse($rows[ $op ]['enabled'], $op);
        }

        $product = $this->product();
        $calls   = [
            'cart.update-settings' => [ 'settings' => [ 'cart_heading' => 'x' ] ],
            'cart.add-upsell'      => [ 'product_id' => $product, 'product_ids' => [ $this->product('Lid') ] ],
            'cart.remove-upsell'   => [ 'product_id' => $product, 'product_ids' => [ 1 ] ],
        ];
        foreach ($calls as $op => $params) {
            $out = $this->write($op, $params);
            $this->assertSame('opt_in_required', $out['error']['code'] ?? null, $op . ' ' . wp_json_encode($out));
            $this->assertSame('wpmcp_woo_op_enabled', $out['error']['data']['filter'], $op);
        }
        $this->assertFalse(get_option('fkcart_settings'));
        $this->assertSame([], $this->upsells($product));
    }

    // --------------------------------------------------------------- reads

    public function test_settings_read_groups_stored_values_over_the_defaults(): void
    {
        update_option('fkcart_settings', $this->stored(), false);

        $out = $this->read('cart.settings');
        $this->assertSame(200, $out['status'], wp_json_encode($out));
        $body = $out['body'];

        $this->assertTrue($body['enabled']);
        $this->assertSame('Your bag', $body['drawer']['cart_heading']);
        $this->assertSame('bottom-right', $body['drawer']['cart_icon_position'], 'a default fills a key the site never saved');
        $this->assertSame('cross_sells', $body['upsells']['upsell_type']);
        $this->assertSame(3, $body['upsells']['upsell_max_count']);
        $this->assertSame('#112233', $body['style']['css_accent_color']);
        $this->assertTrue($body['coupon']['enable_coupon_box']);
        $this->assertCount(2, $body['rewards']['reward']);
        $this->assertSame('freeshipping', $body['rewards']['reward'][0]['type']);
        $this->assertSame('All rewards unlocked', $body['rewards']['reward_title']);
        $this->assertContains('cart_heading', $body['customized']);
        $this->assertNotContains('cart_icon_position', $body['customized']);
    }

    public function test_settings_read_on_a_fresh_site_reports_the_cart_off(): void
    {
        $body = $this->read('cart.settings')['body'];
        $this->assertFalse($body['enabled']);
        $this->assertSame([], $body['customized']);
        $this->assertSame([], $body['rewards']['reward']);
    }

    public function test_upsells_read_lists_products_with_their_upsells_and_cross_sells(): void
    {
        update_option('fkcart_settings', $this->stored(), false);
        $mug  = $this->product('Mug');
        $lid  = $this->product('Lid');
        $cup  = $this->product('Cup');
        $this->product('Plain');
        $product = wc_get_product($mug);
        $product->set_upsell_ids([ $lid ]);
        $product->set_cross_sell_ids([ $cup ]);
        $product->save();

        $out = $this->read('cart.upsells');
        $this->assertSame(200, $out['status'], wp_json_encode($out));
        $body = $out['body'];
        $this->assertSame('cross_sells', $body['upsell_type']);
        $this->assertCount(1, $body['products'], 'only products with upsells by default');
        $row = $body['products'][0];
        $this->assertSame($mug, $row['id']);
        $this->assertSame([ [ 'id' => $lid, 'name' => 'Lid' ] ], $row['upsells']);
        $this->assertSame([ [ 'id' => $cup, 'name' => 'Cup' ] ], $row['cross_sells']);

        $all = $this->read('cart.upsells', [ 'all' => true ])['body'];
        $this->assertSame(4, $all['total']);

        $bad = $this->read('cart.upsells', [ 'colour' => 'red' ]);
        $this->assertSame('invalid_params', $bad['error']['code'] ?? null);
    }

    // -------------------------------------------------------------- writes

    public function test_update_settings_changes_only_the_named_keys_and_rolls_back(): void
    {
        $this->opt_in();
        update_option('fkcart_settings', $this->stored(), false);
        $before = get_option('fkcart_settings');
        $saved  = [];
        add_action('fkcart_settings_saved', static function ($data) use (&$saved) {
            $saved[] = $data;
        });

        $out = $this->write('cart.update-settings', [ 'settings' => [
            'cart_heading'     => 'Review your basket',
            'upsell_max_count' => '4',
            'hide_empty_cart'  => true,
            'css_accent_color' => '#AABBCC',
            'reward'           => [ [ 'type' => 'freeshipping', 'amount' => '75', 'title' => 'Free shipping over 75', 'icon_title' => 'Ship' ] ],
        ] ]);
        $this->assertTrue($out['applied'], wp_json_encode($out));
        $this->assertTrue($out['recoverable']);
        $this->assertEqualsCanonicalizing([ 'cart_heading', 'upsell_max_count', 'hide_empty_cart', 'css_accent_color', 'reward' ], $out['body']['changed']);

        $after = get_option('fkcart_settings');
        $this->assertSame('Review your basket', $after['cart_heading']);
        $this->assertSame(4, $after['upsell_max_count']);
        $this->assertTrue($after['hide_empty_cart']);
        $this->assertSame('#aabbcc', strtolower($after['css_accent_color']));
        $this->assertCount(1, $after['reward']);
        $this->assertSame('cross_sells', $after['upsell_type'], 'an unnamed key is kept');
        $this->assertCount(1, $saved, 'the plugin hears its own settings-saved action');

        $this->rollback($out);
        $this->assertSame($before, get_option('fkcart_settings'));
    }

    public function test_update_settings_on_a_fresh_site_rolls_back_to_no_option(): void
    {
        $this->opt_in();
        $out = $this->write('cart.update-settings', [ 'settings' => [ 'enable_cart' => true ] ]);
        $this->assertTrue($out['applied'], wp_json_encode($out));
        $this->assertSame([ 'enable_cart' => 1 ], get_option('fkcart_settings'));

        $this->rollback($out);
        $this->assertFalse(get_option('fkcart_settings'));
    }

    public function test_update_settings_refuses_bad_input_and_writes_nothing(): void
    {
        $this->opt_in();
        update_option('fkcart_settings', $this->stored(), false);
        $before = get_option('fkcart_settings');

        foreach ([
            'empty'          => [],
            'not a map'      => [ 'settings' => 'enable_cart=1' ],
            'unknown key'    => [ 'settings' => [ 'evil_key' => 1 ] ],
            'bad colour'     => [ 'settings' => [ 'css_accent_color' => 'red; background:url(x)' ] ],
            'array for text' => [ 'settings' => [ 'cart_heading' => [ 'a' ] ] ],
            'text for bool'  => [ 'settings' => [ 'hide_empty_cart' => 'maybe' ] ],
            'text for int'   => [ 'settings' => [ 'upsell_max_count' => 'lots' ] ],
            'bad reward'     => [ 'settings' => [ 'reward' => 'free' ] ],
        ] as $label => $params) {
            $out = $this->write('cart.update-settings', $params);
            $this->assertSame('invalid_params', $out['error']['code'] ?? null, $label . ' ' . wp_json_encode($out));
        }
        $this->assertSame($before, get_option('fkcart_settings'));
    }

    public function test_add_upsell_appends_to_the_product_and_rolls_back(): void
    {
        $this->opt_in();
        $mug = $this->product('Mug');
        $lid = $this->product('Lid');
        $cup = $this->product('Cup');
        $product = wc_get_product($mug);
        $product->set_upsell_ids([ $lid ]);
        $product->save();

        $out = $this->write('cart.add-upsell', [ 'product_id' => $mug, 'product_ids' => [ $cup, $lid ] ]);
        $this->assertTrue($out['applied'], wp_json_encode($out));
        $this->assertSame([ $lid, $cup ], $this->upsells($mug));
        $this->assertSame([ $cup ], $out['body']['added']);

        $this->rollback($out);
        $this->assertSame([ $lid ], $this->upsells($mug));

        $cross = $this->write('cart.add-upsell', [ 'product_id' => $mug, 'product_ids' => [ $cup ], 'type' => 'cross_sells' ]);
        $this->assertTrue($cross['applied'], wp_json_encode($cross));
        $this->assertSame([ $cup ], $this->upsells($mug, '_crosssell_ids'));
        $this->rollback($cross);
        $this->assertSame([], $this->upsells($mug, '_crosssell_ids'));
    }

    public function test_add_upsell_refuses_what_is_not_a_product(): void
    {
        $this->opt_in();
        $mug  = $this->product('Mug');
        $page = self::factory()->post->create([ 'post_type' => 'page' ]);

        foreach ([
            'page as upsell'  => [ 'product_id' => $mug, 'product_ids' => [ $page ] ],
            'self'            => [ 'product_id' => $mug, 'product_ids' => [ $mug ] ],
            'page as target'  => [ 'product_id' => $page, 'product_ids' => [ $mug ] ],
            'no ids'          => [ 'product_id' => $mug, 'product_ids' => [] ],
            'bad type'        => [ 'product_id' => $mug, 'product_ids' => [ $this->product('Lid') ], 'type' => 'related' ],
        ] as $label => $params) {
            $out = $this->write('cart.add-upsell', $params);
            $this->assertSame('invalid_params', $out['error']['code'] ?? null, $label . ' ' . wp_json_encode($out));
        }
        $this->assertSame([], $this->upsells($mug));
    }

    public function test_remove_upsell_drops_ids_and_rolls_back(): void
    {
        $this->opt_in();
        $mug = $this->product('Mug');
        $lid = $this->product('Lid');
        $cup = $this->product('Cup');
        $product = wc_get_product($mug);
        $product->set_cross_sell_ids([ $lid, $cup ]);
        $product->save();

        $out = $this->write('cart.remove-upsell', [ 'product_id' => $mug, 'product_ids' => [ $lid ], 'type' => 'cross_sells' ]);
        $this->assertTrue($out['applied'], wp_json_encode($out));
        $this->assertSame([ $cup ], $this->upsells($mug, '_crosssell_ids'));
        $this->assertSame([ $lid ], $out['body']['removed']);

        $this->rollback($out);
        $this->assertSame([ $lid, $cup ], $this->upsells($mug, '_crosssell_ids'));

        $none = $this->write('cart.remove-upsell', [ 'product_id' => $mug, 'product_ids' => [ 999999 ] ]);
        $this->assertSame('invalid_params', $none['error']['code'] ?? null, 'removing what is not there is refused');
    }
}
