<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Integrations\SureCart_Pack;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Safety\SureCart_Api;
use WPMCP\Safety\SureCart_Snapshot;

/**
 * Issue #665: SureCart products, orders, customers and coupons on the
 * plugin-data pair, paid tier.
 *
 * SureCart keeps its store in its own cloud and its models call that API
 * with the site's connection token, so the shared test core never loads it.
 * Presence is driven through wpmcp_surecart_active, and SureCart_Api's test
 * seam serves an in-memory store in the shapes SureCart 4.13.1's API returns
 * (ids are UUIDs, amounts in the smallest currency unit, times as Unix
 * timestamps, lists as { data, pagination }).
 */
class SureCartPackTest extends \WP_UnitTestCase
{
    private const OPS = [
        'surecart-list-products'  => 'read',
        'surecart-get-product'    => 'read',
        'surecart-list-orders'    => 'read',
        'surecart-list-customers' => 'read',
        'surecart-list-coupons'   => 'read',
        'surecart-update-product' => 'write',
        'surecart-create-coupon'  => 'write',
    ];

    private const PRODUCT = '7d8e9c1a-0b2c-4d3e-8f4a-5b6c7d8e9f01';

    /** @var array<string, array<string, array<string, mixed>>> resource => id => record */
    private array $store = [];

    /** @var array<int, array{0: string, 1: string, 2: array}> */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        add_filter('wpmcp_surecart_active', '__return_true');
        add_filter(SureCart_Pack::ENABLE_FILTER, '__return_true');

        $this->store = [
            'products'  => [
                self::PRODUCT => [
                    'id' => self::PRODUCT, 'object' => 'product', 'name' => 'Pro Course', 'slug' => 'pro-course',
                    'description' => '<p>Learn it all.</p>', 'status' => 'published', 'archived' => false,
                    'sku' => 'PC-1', 'recurring' => false, 'stock_enabled' => true, 'stock' => 40, 'available_stock' => 38,
                    'tax_enabled' => true, 'shipping_enabled' => false, 'metadata' => [ 'wp_created_by' => 1 ],
                    'created_at' => 1760000000, 'updated_at' => 1760000500,
                    'prices' => [ 'object' => 'list', 'data' => [
                        [ 'id' => 'price-1', 'name' => 'One time', 'amount' => 4900, 'currency' => 'usd', 'recurring_interval' => null, 'recurring_interval_count' => null, 'archived' => false, 'ad_hoc' => false ],
                    ] ],
                ],
                'b1c2d3e4-0000-4000-8000-000000000002' => [
                    'id' => 'b1c2d3e4-0000-4000-8000-000000000002', 'object' => 'product', 'name' => 'Old Ebook', 'slug' => 'old-ebook',
                    'description' => '', 'status' => 'draft', 'archived' => true, 'sku' => null, 'recurring' => false,
                    'stock_enabled' => false, 'stock' => 0, 'available_stock' => 0, 'tax_enabled' => false, 'shipping_enabled' => false,
                    'created_at' => 1750000000, 'updated_at' => 1750000000,
                ],
            ],
            'customers' => [
                'cus-1' => [ 'id' => 'cus-1', 'object' => 'customer', 'name' => 'Jane Buyer', 'email' => 'jane@example.com', 'phone' => '+15551234567', 'live_mode' => true, 'created_at' => 1760001000 ],
            ],
            'orders'    => [
                'ord-1' => [
                    'id' => 'ord-1', 'object' => 'order', 'number' => '0001', 'status' => 'paid', 'fulfillment_status' => 'unfulfilled',
                    'shipment_status' => 'unshipped', 'live_mode' => true, 'created_at' => 1760002000,
                    'checkout' => [ 'id' => 'chk-1', 'email' => 'jane@example.com', 'name' => 'Jane Buyer', 'total_amount' => 4900, 'currency' => 'usd', 'customer' => [ 'id' => 'cus-1', 'email' => 'jane@example.com', 'name' => 'Jane Buyer' ] ],
                ],
            ],
            'coupons'   => [
                'cpn-1' => [
                    'id' => 'cpn-1', 'object' => 'coupon', 'name' => 'Spring', 'percent_off' => 20, 'amount_off' => null, 'currency' => null,
                    'duration' => 'once', 'duration_in_months' => null, 'max_redemptions' => 100, 'times_redeemed' => 3,
                    'redeem_by' => null, 'archived' => false, 'valid' => true, 'created_at' => 1760003000,
                    'promotions' => [ 'object' => 'list', 'data' => [ [ 'id' => 'promo-1', 'code' => 'SPRING20', 'archived' => false ] ] ],
                ],
            ],
        ];
        $this->calls = [];
        SureCart_Api::use_for_tests(fn (string $resource, string $action, array $params) => $this->fake($resource, $action, $params));
    }

    protected function tearDown(): void
    {
        SureCart_Api::use_for_tests(null);
        remove_all_filters('wpmcp_surecart_active');
        remove_all_filters(SureCart_Pack::ENABLE_FILTER);
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    /** The stand-in for SureCart's API, as SureCart_Api's models would answer. */
    private function fake(string $resource, string $action, array $params)
    {
        $this->calls[] = [ $resource, $action, $params ];
        $rows          = &$this->store[ $resource ];
        $id            = (string) ($params['id'] ?? '');
        switch ($action) {
            case 'list':
                $query = $params['query'] ?? [];
                $items = array_values(array_filter($rows, static function (array $row) use ($query): bool {
                    if (isset($query['archived']) && (bool) $row['archived'] !== (bool) $query['archived']) {
                        return false;
                    }
                    if (! empty($query['status']) && ! in_array($row['status'] ?? '', (array) $query['status'], true)) {
                        return false;
                    }
                    return empty($query['query']) || false !== stripos((string) wp_json_encode($row), (string) $query['query']);
                }));
                return [ 'object' => 'list', 'data' => $items, 'pagination' => [ 'count' => count($items), 'page' => (int) ($params['page'] ?? 1), 'limit' => (int) ($params['per_page'] ?? 20) ] ];
            case 'find':
                return $rows[ $id ] ?? new \WP_Error('not_found', 'Not found.', [ 'status' => 404 ]);
            case 'update':
                if (! isset($rows[ $id ])) {
                    return new \WP_Error('not_found', 'Not found.', [ 'status' => 404 ]);
                }
                $rows[ $id ] = array_merge($rows[ $id ], (array) $params['attributes'], [ 'updated_at' => time() ]);
                return $rows[ $id ];
            case 'create':
                $attributes = (array) $params['attributes'];
                $new        = 'cpn-' . (count($rows) + 1);
                $codes      = [];
                foreach ((array) ($attributes['promotions'] ?? []) as $i => $promotion) {
                    $codes[] = [ 'id' => 'promo-' . $new . '-' . $i, 'code' => (string) ($promotion['code'] ?? 'AUTO' . strtoupper(substr(md5($new), 0, 6))), 'archived' => false ];
                }
                unset($attributes['promotions']);
                $rows[ $new ] = array_merge([ 'amount_off' => null, 'percent_off' => null, 'currency' => null, 'duration_in_months' => null, 'max_redemptions' => null, 'redeem_by' => null ], $attributes, [
                    'id' => $new, 'object' => 'coupon', 'times_redeemed' => 0, 'archived' => false, 'valid' => true, 'created_at' => time(),
                    'promotions' => [ 'object' => 'list', 'data' => $codes ],
                ]);
                return $rows[ $new ];
            case 'delete':
                if (! isset($rows[ $id ])) {
                    return new \WP_Error('not_found', 'Not found.', [ 'status' => 404 ]);
                }
                unset($rows[ $id ]);
                return [ 'id' => $id, 'deleted' => true ];
        }
        return new \WP_Error('bad_action', $action);
    }

    private function read(string $op, array $args = []): array
    {
        return (new Plugin_Data_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
    }

    private function write(string $op, array $args = []): array
    {
        return (new Plugin_Data_Integration())->handle_write([ 'operation' => $op, 'args' => $args ]);
    }

    private function ok(array $out): array
    {
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out;
    }

    public function test_the_catalog_lists_every_op_at_manage_options(): void
    {
        $ops = array_column((new Plugin_Data_Integration())->catalog()['operations'], null, 'name');
        foreach (self::OPS as $name => $mode) {
            $this->assertArrayHasKey($name, $ops);
            $this->assertSame($mode, $ops[ $name ]['mode'], $name);
            $this->assertTrue($ops[ $name ]['dependency_met'], $name);
            $this->assertSame('manage_options', $ops[ $name ]['capability'], $name);
        }
    }

    public function test_without_pro_the_ops_are_not_in_the_catalog(): void
    {
        Gate::set_pro_for_tests(false);
        $ops = array_column((new Plugin_Data_Integration())->catalog()['operations'], 'name');
        foreach (array_keys(self::OPS) as $name) {
            $this->assertNotContains($name, $ops);
        }
    }

    public function test_a_missing_plugin_answers_plugin_missing_naming_it(): void
    {
        remove_all_filters('wpmcp_surecart_active');
        add_filter('wpmcp_surecart_active', '__return_false');
        $out = $this->read('surecart-list-products');
        $this->assertSame('plugin_missing', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertStringContainsString('Install and activate SureCart', $out['error']['message']);
        $this->assertSame(admin_url('plugins.php'), $out['error']['data']['plugins_url'] ?? null);
        $this->assertSame([], $this->calls);
    }

    public function test_list_products_summarises_each_product_and_its_prices(): void
    {
        $out = $this->ok($this->read('surecart-list-products'))['result'];
        $this->assertSame(2, $out['total']);
        $this->assertCount(2, $out['products']);
        $first = $out['products'][0];
        $this->assertSame(self::PRODUCT, $first['id']);
        $this->assertSame('Pro Course', $first['name']);
        $this->assertSame('published', $first['status']);
        $this->assertFalse($first['archived']);
        $this->assertSame(38, $first['available_stock']);
        $this->assertSame([ [ 'id' => 'price-1', 'name' => 'One time', 'amount' => 4900, 'currency' => 'usd', 'recurring_interval' => null, 'recurring_interval_count' => null, 'archived' => false, 'ad_hoc' => false ] ], $first['prices']);
        $this->assertArrayNotHasKey('description', $first, 'lists stay short');
        $this->assertArrayNotHasKey('metadata', $first);

        $out = $this->ok($this->read('surecart-list-products', [ 'archived' => true ]))['result'];
        $this->assertSame([ 'Old Ebook' ], array_column($out['products'], 'name'));

        [ , $action, $params ] = end($this->calls);
        $this->assertSame('list', $action);
        $this->assertContains('prices', $params['expand']);
    }

    public function test_get_product_returns_the_description_and_refuses_an_unknown_id(): void
    {
        $out = $this->ok($this->read('surecart-get-product', [ 'product_id' => self::PRODUCT ]))['result'];
        $this->assertSame('<p>Learn it all.</p>', $out['description']);
        $this->assertSame('pro-course', $out['slug']);
        $this->assertSame('PC-1', $out['sku']);

        $out = $this->read('surecart-get-product', [ 'product_id' => 'nope' ]);
        $this->assertSame('not_found', $out['error']['code'] ?? null, (string) wp_json_encode($out));
    }

    public function test_customer_and_order_lists_mask_personal_data(): void
    {
        $customers = $this->ok($this->read('surecart-list-customers'))['result'];
        $this->assertSame('cus-1', $customers['customers'][0]['id']);
        $this->assertSame('j***@example.com', $customers['customers'][0]['email']);
        $this->assertSame('J***', $customers['customers'][0]['name']);
        $this->assertSame('+***', $customers['customers'][0]['phone']);

        $orders = $this->ok($this->read('surecart-list-orders', [ 'status' => 'paid' ]))['result'];
        $order  = $orders['orders'][0];
        $this->assertSame('0001', $order['number']);
        $this->assertSame('paid', $order['status']);
        $this->assertSame(4900, $order['total_amount']);
        $this->assertSame('usd', $order['currency']);
        $this->assertSame('cus-1', $order['customer_id']);
        $this->assertSame('j***@example.com', $order['email']);
        $this->assertSame('J***', $order['name']);

        $json = (string) wp_json_encode([ $customers, $orders ]);
        $this->assertStringNotContainsString('jane@example.com', $json);
        $this->assertStringNotContainsString('Jane Buyer', $json);
        $this->assertStringNotContainsString('5551234567', $json);
    }

    public function test_list_coupons_reports_the_discount_and_its_codes(): void
    {
        $out    = $this->ok($this->read('surecart-list-coupons'))['result'];
        $coupon = $out['coupons'][0];
        $this->assertSame('Spring', $coupon['name']);
        $this->assertSame(20, $coupon['percent_off']);
        $this->assertSame(3, $coupon['times_redeemed']);
        $this->assertSame([ 'SPRING20' ], $coupon['codes']);
    }

    public function test_a_connection_error_is_a_refusal_naming_the_connection(): void
    {
        SureCart_Api::use_for_tests(static fn () => new \WP_Error('missing_token', 'Please connect your site to SureCart.'));
        $out = $this->read('surecart-list-products');
        $this->assertSame('surecart_not_connected', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertStringContainsString('connect', $out['error']['message']);
    }

    public function test_writes_are_off_until_the_site_opts_in(): void
    {
        remove_all_filters(SureCart_Pack::ENABLE_FILTER);
        $out = $this->write('surecart-update-product', [ 'product_id' => self::PRODUCT, 'name' => 'New' ]);
        $this->assertSame('opt_in_required', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertStringContainsString(SureCart_Pack::ENABLE_FILTER, $out['error']['message']);
        $out = $this->write('surecart-create-coupon', [ 'name' => 'X', 'percent_off' => 10 ]);
        $this->assertSame('opt_in_required', $out['error']['code'] ?? null);
        $this->assertSame('Pro Course', $this->store['products'][ self::PRODUCT ]['name']);
        $this->assertCount(1, $this->store['coupons']);
        $this->assertSame([], Snapshot_Store::list_by_session('default'));
    }

    public function test_update_product_changes_only_the_named_fields_and_rolls_back(): void
    {
        $out = $this->ok($this->write('surecart-update-product', [ 'product_id' => self::PRODUCT, 'name' => 'Pro Course 2', 'status' => 'draft' ]));
        $this->assertTrue($out['recoverable']);
        $this->assertSame('Pro Course 2', $out['result']['name']);
        $this->assertSame('draft', $out['result']['status']);
        $this->assertSame([ 'name', 'status' ], $out['result']['changed']);

        $update = array_values(array_filter($this->calls, static fn (array $c): bool => 'update' === $c[1]));
        $this->assertCount(1, $update);
        $this->assertSame([ 'name' => 'Pro Course 2', 'status' => 'draft' ], $update[0][2]['attributes'], 'only the changed fields are sent');
        $this->assertSame('<p>Learn it all.</p>', $this->store['products'][ self::PRODUCT ]['description']);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame('Pro Course', $this->store['products'][ self::PRODUCT ]['name']);
        $this->assertSame('published', $this->store['products'][ self::PRODUCT ]['status']);
    }

    public function test_undoing_a_product_edit_keeps_a_field_changed_since(): void
    {
        $out = $this->ok($this->write('surecart-update-product', [ 'product_id' => self::PRODUCT, 'name' => 'Edited', 'archived' => true ]));
        $this->store['products'][ self::PRODUCT ]['name'] = 'Changed in the dashboard';

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame('Changed in the dashboard', $this->store['products'][ self::PRODUCT ]['name'], 'a later edit is not overwritten');
        $this->assertFalse($this->store['products'][ self::PRODUCT ]['archived'], 'the untouched field is put back');
    }

    public function test_update_product_refuses_unknown_ids_no_change_and_no_fields(): void
    {
        $cases = [
            'not_found'    => [ 'product_id' => 'nope', 'name' => 'X' ],
            'no_change'    => [ 'product_id' => self::PRODUCT, 'name' => 'Pro Course' ],
            'invalid_args' => [ 'product_id' => self::PRODUCT ],
        ];
        foreach ($cases as $code => $args) {
            $out = $this->write('surecart-update-product', $args);
            $this->assertSame($code, $out['error']['code'] ?? null, $code . ': ' . wp_json_encode($out));
        }
        $this->assertSame([], Snapshot_Store::list_by_session('default'));
    }

    public function test_a_failed_update_leaves_no_undo_point(): void
    {
        $fake = fn (string $resource, string $action, array $params) => 'update' === $action
            ? new \WP_Error('invalid', 'Slug is taken.', [ 'status' => 422 ])
            : $this->fake($resource, $action, $params);
        SureCart_Api::use_for_tests($fake);
        $out = $this->write('surecart-update-product', [ 'product_id' => self::PRODUCT, 'slug' => 'taken' ]);
        $this->assertSame('surecart_refused', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertStringContainsString('Slug is taken.', $out['error']['message']);
        $this->assertSame([], Snapshot_Store::list_by_session('default'));
    }

    public function test_create_coupon_makes_a_coupon_with_its_code_and_undo_deletes_it(): void
    {
        $out = $this->ok($this->write('surecart-create-coupon', [ 'name' => 'Launch', 'percent_off' => 15, 'code' => 'LAUNCH15', 'max_redemptions' => 50 ]));
        $this->assertTrue($out['recoverable']);
        $id = $out['result']['id'];
        $this->assertSame('Launch', $out['result']['name']);
        $this->assertSame(15, $out['result']['percent_off']);
        $this->assertSame('once', $out['result']['duration']);
        $this->assertSame([ 'LAUNCH15' ], $out['result']['codes']);
        $this->assertArrayHasKey($id, $this->store['coupons']);

        $create = array_values(array_filter($this->calls, static fn (array $c): bool => 'create' === $c[1]));
        $this->assertSame([ [ 'code' => 'LAUNCH15' ] ], $create[0][2]['attributes']['promotions']);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertArrayNotHasKey($id, $this->store['coupons']);
        $this->assertArrayHasKey('cpn-1', $this->store['coupons']);
    }

    public function test_undoing_a_redeemed_coupon_archives_it_instead(): void
    {
        $out = $this->ok($this->write('surecart-create-coupon', [ 'name' => 'Ten off', 'amount_off' => 1000, 'currency' => 'usd', 'duration' => 'repeating', 'duration_in_months' => 3 ]));
        $id  = $out['result']['id'];
        $this->assertSame(1000, $out['result']['amount_off']);
        $this->assertCount(1, $out['result']['codes'], 'SureCart makes a code when none is given');
        $this->store['coupons'][ $id ]['times_redeemed'] = 1;

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertArrayHasKey($id, $this->store['coupons'], 'an order used it, so it is kept');
        $this->assertTrue($this->store['coupons'][ $id ]['archived']);
    }

    public function test_create_coupon_validates_the_discount(): void
    {
        $cases = [
            [ 'name' => 'A' ],
            [ 'name' => 'A', 'percent_off' => 10, 'amount_off' => 100, 'currency' => 'usd' ],
            [ 'name' => 'A', 'amount_off' => 100 ],
            [ 'name' => 'A', 'percent_off' => 10, 'duration' => 'repeating' ],
        ];
        foreach ($cases as $args) {
            $out = $this->write('surecart-create-coupon', $args);
            $this->assertSame('invalid_args', $out['error']['code'] ?? null, wp_json_encode($args) . ': ' . wp_json_encode($out));
        }
        $this->assertCount(1, $this->store['coupons']);
        $this->assertSame([], Snapshot_Store::list_by_session('default'));
    }

    public function test_an_editor_is_refused(): void
    {
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        $this->assertArrayHasKey('error', $this->read('surecart-list-customers'));
        $this->assertArrayHasKey('error', $this->write('surecart-update-product', [ 'product_id' => self::PRODUCT, 'name' => 'X' ]));
        $this->assertSame('Pro Course', $this->store['products'][ self::PRODUCT ]['name']);
    }

    public function test_the_snapshot_key_round_trips(): void
    {
        $this->assertSame([ 'kind' => 'product', 'id' => 'abc' ], SureCart_Snapshot::parse(SureCart_Snapshot::key('product', 'abc')));
        $this->assertSame([ 'kind' => '', 'id' => 'x' ], SureCart_Snapshot::parse('order:x'));
        $this->assertContains(SureCart_Snapshot::TYPE, Rollback_Service::restorable_object_types());
    }
}
