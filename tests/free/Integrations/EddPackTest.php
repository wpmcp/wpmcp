<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\EDD_Pack;
use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

require_once __DIR__ . '/../../support/edd-tables.php';

/**
 * Easy Digital Downloads (issue #664): free-tier ops on the plugin-data
 * dispatcher pair, so they add no top-level tools.
 *
 * EDD is not loaded in the shared test core. Presence is driven through
 * wpmcp_edd_active, and the storage is built the way EDD 3.7.3 keeps it
 * (tests/support/edd-tables.php). The writes are off until the site opts in
 * (wpmcp_enable_edd_write) and each is undone in a test.
 */
class EddPackTest extends \WP_UnitTestCase
{
    private const OPS = [
        'edd-list-downloads'   => 'read',
        'edd-get-download'     => 'read',
        'edd-list-orders'      => 'read',
        'edd-get-order'        => 'read',
        'edd-list-customers'   => 'read',
        'edd-list-discounts'   => 'read',
        'edd-sales-summary'    => 'read',
        'edd-create-download'  => 'write',
        'edd-update-download'  => 'write',
        'edd-create-discount'  => 'write',
        'edd-update-discount'  => 'write',
    ];

    private int $admin;

    public static function wpSetUpBeforeClass(): void
    {
        wpmcp_test_create_edd_tables();
    }

    public static function wpTearDownAfterClass(): void
    {
        wpmcp_test_drop_edd_tables();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        wpmcp_test_register_edd_download_type();
        $this->admin = self::factory()->user->create([ 'role' => 'administrator' ]);
        $user        = get_user_by('id', $this->admin);
        foreach (wpmcp_test_edd_product_caps() as $cap) {
            $user->add_cap($cap);
        }
        wp_set_current_user($this->admin);
        add_filter('wpmcp_edd_active', '__return_true');
        add_filter('wpmcp_enable_edd_write', '__return_true');
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_edd_active');
        remove_all_filters('wpmcp_enable_edd_write');
        global $wpdb;
        foreach (wpmcp_test_edd_table_names() as $name) {
            $wpdb->query($wpdb->prepare('DELETE FROM %i', $wpdb->prefix . $name));
        }
        parent::tearDown();
    }

    private function read(string $op, array $args = []): array
    {
        $out = (new Plugin_Data_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
        return isset($out['result']) && ! isset($out['error']) ? $out['result'] : $out;
    }

    private function write(string $op, array $args): array
    {
        return (new Plugin_Data_Integration())->handle_write([ 'operation' => $op, 'args' => $args ]);
    }

    private function ok(array $out): array
    {
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out;
    }

    private function insert(string $table, array $row): int
    {
        global $wpdb;
        $this->assertNotFalse($wpdb->insert($wpdb->prefix . $table, $row), $wpdb->last_error);
        return (int) $wpdb->insert_id;
    }

    private function adjustment(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', $wpdb->prefix . 'edd_adjustments', $id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    private function download(array $meta = [], array $post = []): int
    {
        $id = self::factory()->post->create($post + [ 'post_type' => 'download', 'post_status' => 'publish', 'post_title' => 'Ebook', 'post_author' => $this->admin ]);
        foreach ($meta + [ 'edd_price' => '9.00' ] as $key => $value) {
            update_post_meta($id, $key, $value);
        }
        return $id;
    }

    /** One customer with one complete sale of $download and a refund of part of it. */
    private function shop(int $download): array
    {
        $customer = $this->insert('edd_customers', [ 'email' => 'jane.buyer@example.com', 'name' => 'Jane Buyer', 'status' => 'active', 'purchase_value' => 30, 'purchase_count' => 2, 'date_created' => '2026-01-02 10:00:00' ]);
        $sale     = $this->insert('edd_orders', [ 'order_number' => 'EDD-1', 'status' => 'complete', 'type' => 'sale', 'customer_id' => $customer, 'email' => 'jane.buyer@example.com', 'ip' => '203.0.113.7', 'payment_key' => 'secretkey', 'gateway' => 'stripe', 'mode' => 'live', 'currency' => 'USD', 'subtotal' => 20, 'total' => 20, 'date_created' => '2026-01-03 10:00:00', 'date_completed' => '2026-01-03 10:00:05' ]);
        $this->insert('edd_order_items', [ 'order_id' => $sale, 'product_id' => $download, 'product_name' => 'Ebook', 'status' => 'complete', 'quantity' => 2, 'amount' => 10, 'subtotal' => 20, 'total' => 20 ]);
        $other    = $this->insert('edd_orders', [ 'status' => 'pending', 'type' => 'sale', 'customer_id' => $customer, 'email' => 'jane.buyer@example.com', 'mode' => 'live', 'currency' => 'USD', 'total' => 10, 'date_created' => '2026-02-01 09:00:00' ]);
        $refund   = $this->insert('edd_orders', [ 'parent' => $sale, 'order_number' => 'EDD-1-R-1', 'status' => 'complete', 'type' => 'refund', 'customer_id' => $customer, 'email' => 'jane.buyer@example.com', 'mode' => 'live', 'currency' => 'USD', 'total' => -5, 'date_created' => '2026-01-05 10:00:00' ]);
        $this->insert('edd_orders', [ 'status' => 'complete', 'type' => 'sale', 'customer_id' => $customer, 'email' => 'jane.buyer@example.com', 'mode' => 'test', 'currency' => 'USD', 'total' => 99, 'date_created' => '2026-01-04 10:00:00' ]);
        return [ $customer, $sale, $other, $refund ];
    }

    // ---------------------------------------------------------------
    // Catalog and availability
    // ---------------------------------------------------------------

    public function test_the_catalog_lists_every_op_with_its_mode(): void
    {
        $ops = array_column((new Plugin_Data_Integration())->catalog()['operations'], null, 'name');
        foreach (self::OPS as $name => $mode) {
            $this->assertArrayHasKey($name, $ops);
            $this->assertSame($mode, $ops[ $name ]['mode'], $name);
            $this->assertTrue($ops[ $name ]['dependency_met'], $name);
            $this->assertSame('manage_options', $ops[ $name ]['capability'], $name);
        }
        $this->assertArrayNotHasKey('edd-update-order-status', $ops, 'Order status writes move money and send mail; not in this version');
    }

    public function test_presence_defaults_to_the_plugin_constant_or_class(): void
    {
        remove_all_filters('wpmcp_edd_active');
        $this->assertSame(defined('EDD_VERSION') || class_exists('Easy_Digital_Downloads', false), EDD_Pack::active());
    }

    public function test_while_edd_is_missing_a_call_answers_plugin_missing_with_the_plugins_link(): void
    {
        remove_all_filters('wpmcp_edd_active');
        add_filter('wpmcp_edd_active', '__return_false');
        $out = $this->read('edd-list-orders');
        $this->assertSame('plugin_missing', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertStringContainsString('Install and activate Easy Digital Downloads', $out['error']['message']);
        $this->assertSame(admin_url('plugins.php'), $out['error']['data']['plugins_url'] ?? null);
    }

    public function test_writes_are_off_until_the_site_opts_in(): void
    {
        remove_all_filters('wpmcp_enable_edd_write');
        $out = $this->write('edd-create-discount', [ 'code' => 'SAVE10', 'amount' => 10 ]);
        $this->assertSame('opt_in_required', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertStringContainsString('wpmcp_enable_edd_write', (string) wp_json_encode($out));
        $this->assertNull($this->adjustment(1));
    }

    // ---------------------------------------------------------------
    // Reads
    // ---------------------------------------------------------------

    public function test_list_and_get_download_read_prices_sales_and_file_names_only(): void
    {
        $id = $this->download([
            'edd_sku'                => 'EB-1',
            '_edd_download_sales'    => '3',
            '_edd_download_earnings' => '27.00',
            'edd_download_files'     => [ [ 'index' => 0, 'name' => 'book.pdf', 'file' => 'https://example.com/private/book.pdf' ] ],
        ]);
        $this->download([ '_variable_pricing' => '1', 'edd_variable_prices' => [ 1 => [ 'index' => 1, 'name' => 'Basic', 'amount' => '5.00' ], 2 => [ 'index' => 2, 'name' => 'Pro', 'amount' => '15.00' ] ] ], [ 'post_title' => 'Course' ]);

        $list = $this->read('edd-list-downloads');
        $this->assertSame(2, $list['total'], (string) wp_json_encode($list));
        $rows = array_column($list['downloads'], null, 'id');
        $this->assertSame(9.0, $rows[ $id ]['price']);
        $this->assertSame('EB-1', $rows[ $id ]['sku']);
        $this->assertSame(3, $rows[ $id ]['sales']);

        $one = $this->read('edd-get-download', [ 'download_id' => $id ]);
        $this->assertSame([ 'book.pdf' ], $one['download']['files'], 'file names only');
        $this->assertStringNotContainsString('private/book.pdf', (string) wp_json_encode($one));

        $search = $this->read('edd-list-downloads', [ 'search' => 'Course' ]);
        $this->assertSame(1, $search['total']);
        $this->assertTrue($search['downloads'][0]['variable_pricing']);
        $this->assertSame([ [ 'index' => 1, 'name' => 'Basic', 'amount' => 5.0 ], [ 'index' => 2, 'name' => 'Pro', 'amount' => 15.0 ] ], $search['downloads'][0]['prices']);
    }

    public function test_get_download_refuses_another_post_type(): void
    {
        $post = self::factory()->post->create();
        $out  = $this->read('edd-get-download', [ 'download_id' => $post ]);
        $this->assertArrayHasKey('error', $out);
    }

    public function test_list_orders_masks_the_email_filters_and_leaves_secrets_out(): void
    {
        $download = $this->download();
        [ $customer, $sale ] = $this->shop($download);

        $list = $this->read('edd-list-orders');
        $this->assertTrue($list['masked']);
        $this->assertSame(3, $list['total'], 'sales in every mode, refunds left out by default: ' . wp_json_encode($list));
        $json = (string) wp_json_encode($list);
        $this->assertStringNotContainsString('jane.buyer@example.com', $json);
        $this->assertStringContainsString('j***@example.com', $json);
        $this->assertStringNotContainsString('secretkey', $json);
        $this->assertStringNotContainsString('203.0.113.7', $json);

        $complete = $this->read('edd-list-orders', [ 'status' => 'complete', 'mode' => 'live' ]);
        $this->assertSame(1, $complete['total']);
        $this->assertSame($sale, $complete['orders'][0]['id']);
        $this->assertSame('EDD-1', $complete['orders'][0]['order_number']);
        $this->assertSame(20.0, $complete['orders'][0]['total']);

        $refunds = $this->read('edd-list-orders', [ 'type' => 'refund' ]);
        $this->assertSame(1, $refunds['total']);

        $by_customer = $this->read('edd-list-orders', [ 'customer_id' => $customer + 1 ]);
        $this->assertSame(0, $by_customer['total']);
    }

    public function test_get_order_returns_items_refunds_and_the_full_email(): void
    {
        $download = $this->download();
        [ , $sale, , $refund ] = $this->shop($download);

        $one = $this->read('edd-get-order', [ 'order_id' => $sale ]);
        $order = $one['order'];
        $this->assertSame('jane.buyer@example.com', $order['email'], 'one order in full at manage_options');
        $this->assertSame('Jane Buyer', $order['customer_name']);
        $this->assertSame([ $download ], array_column($order['items'], 'product_id'));
        $this->assertSame(2, $order['items'][0]['quantity']);
        $this->assertSame([ $refund ], array_column($order['refunds'], 'id'));
        $this->assertArrayNotHasKey('payment_key', $order);
        $this->assertArrayNotHasKey('ip', $order);

        $missing = $this->read('edd-get-order', [ 'order_id' => $sale + 100 ]);
        $this->assertSame('order_not_found', $missing['error']['code'] ?? null);
    }

    public function test_list_customers_masks_name_and_email(): void
    {
        $this->shop($this->download());
        $list = $this->read('edd-list-customers', [ 'search' => 'jane' ]);
        $this->assertSame(1, $list['total']);
        $this->assertSame('J***', $list['customers'][0]['name']);
        $this->assertSame('j***@example.com', $list['customers'][0]['email']);
        $this->assertSame(2, $list['customers'][0]['purchase_count']);
        $this->assertSame(0, $this->read('edd-list-customers', [ 'search' => 'nobody' ])['total']);
    }

    public function test_sales_summary_counts_live_sales_and_refunds_per_currency(): void
    {
        $this->shop($this->download());
        $sum = $this->read('edd-sales-summary', [ 'from' => '2026-01-01', 'to' => '2026-01-31' ]);
        $usd = $sum['currencies']['USD'] ?? null;
        $this->assertNotNull($usd, (string) wp_json_encode($sum));
        $this->assertSame(1, $usd['orders'], 'the complete live sale; the pending one is out of range, the test one is test mode');
        $this->assertSame(20.0, $usd['gross']);
        $this->assertSame(5.0, $usd['refunds']);
        $this->assertSame(15.0, $usd['net']);
        $this->assertSame('live', $sum['mode']);

        $test = $this->read('edd-sales-summary', [ 'mode' => 'test' ]);
        $this->assertSame(99.0, $test['currencies']['USD']['gross']);
    }

    public function test_list_discounts_reads_codes_and_product_requirements(): void
    {
        $id = $this->insert('edd_adjustments', [ 'name' => 'Ten off', 'code' => 'TEN', 'status' => 'active', 'type' => 'discount', 'scope' => 'global', 'amount_type' => 'percent', 'amount' => 10, 'description' => '', 'use_count' => 4 ]);
        $this->insert('edd_adjustments', [ 'name' => 'Old', 'code' => 'OLD', 'status' => 'expired', 'type' => 'discount', 'amount_type' => 'flat', 'amount' => 2, 'description' => '' ]);
        $this->insert('edd_adjustmentmeta', [ 'edd_adjustment_id' => $id, 'meta_key' => 'product_requirement', 'meta_value' => '42' ]);

        $all = $this->read('edd-list-discounts');
        $this->assertSame(2, $all['total']);
        $active = $this->read('edd-list-discounts', [ 'status' => 'active' ]);
        $this->assertSame(1, $active['total']);
        $this->assertSame('TEN', $active['discounts'][0]['code']);
        $this->assertSame(10.0, $active['discounts'][0]['amount']);
        $this->assertSame(4, $active['discounts'][0]['use_count']);
        $this->assertSame([ 42 ], $active['discounts'][0]['product_requirements']);
    }

    // ---------------------------------------------------------------
    // Writes
    // ---------------------------------------------------------------

    public function test_create_discount_writes_the_row_edd_would_and_rollback_removes_it(): void
    {
        $out = $this->ok($this->write('edd-create-discount', [ 'code' => 'SAVE10', 'amount' => 10, 'end_date' => '2026-12-31' ]));
        $this->assertNotEmpty($out['operation_id']);
        $id  = (int) $out['result']['discount']['id'];
        $row = $this->adjustment($id);
        $this->assertSame('SAVE10', $row['code']);
        $this->assertSame('SAVE10', $row['name'], 'the name defaults to the code');
        $this->assertSame('discount', $row['type']);
        $this->assertSame('active', $row['status']);
        $this->assertSame('percent', $row['amount_type']);
        $this->assertSame('global', $row['scope']);
        $this->assertSame('2026-12-31 23:59:59', $row['end_date']);
        $this->assertStringStartsWith('urn:uuid:', $row['uuid']);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertNull($this->adjustment($id));
    }

    public function test_create_discount_refuses_a_taken_code_and_a_percent_over_100(): void
    {
        $this->ok($this->write('edd-create-discount', [ 'code' => 'TAKEN', 'amount' => 5 ]));
        $taken = $this->write('edd-create-discount', [ 'code' => 'taken', 'amount' => 5 ]);
        $this->assertSame('discount_code_taken', $taken['error']['code'] ?? null, (string) wp_json_encode($taken));
        $big = $this->write('edd-create-discount', [ 'code' => 'BIG', 'amount' => 150 ]);
        $this->assertSame('invalid_discount', $big['error']['code'] ?? null);
        $dates = $this->write('edd-create-discount', [ 'code' => 'BACK', 'amount' => 5, 'start_date' => '2026-05-01', 'end_date' => '2026-04-01' ]);
        $this->assertSame('invalid_discount', $dates['error']['code'] ?? null);
    }

    public function test_update_discount_changes_only_the_args_given_and_rolls_back_exactly(): void
    {
        $id     = $this->insert('edd_adjustments', [ 'name' => 'Ten off', 'code' => 'TEN', 'status' => 'active', 'type' => 'discount', 'scope' => 'global', 'amount_type' => 'percent', 'amount' => 10, 'description' => 'keep', 'use_count' => 4 ]);
        $before = $this->adjustment($id);

        $out = $this->ok($this->write('edd-update-discount', [ 'discount_id' => $id, 'status' => 'inactive', 'amount' => 15 ]));
        $row = $this->adjustment($id);
        $this->assertSame('inactive', $row['status']);
        $this->assertSame(15.0, (float) $row['amount']);
        $this->assertSame('keep', $row['description']);
        $this->assertSame('4', $row['use_count']);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame($before, $this->adjustment($id));
    }

    public function test_update_discount_refuses_another_discounts_code_and_an_unknown_id(): void
    {
        $this->insert('edd_adjustments', [ 'name' => 'A', 'code' => 'AAA', 'status' => 'active', 'type' => 'discount', 'amount_type' => 'flat', 'amount' => 1, 'description' => '' ]);
        $b    = $this->insert('edd_adjustments', [ 'name' => 'B', 'code' => 'BBB', 'status' => 'active', 'type' => 'discount', 'amount_type' => 'flat', 'amount' => 1, 'description' => '' ]);
        $out  = $this->write('edd-update-discount', [ 'discount_id' => $b, 'code' => 'AAA' ]);
        $this->assertSame('discount_code_taken', $out['error']['code'] ?? null);
        $this->ok($this->write('edd-update-discount', [ 'discount_id' => $b, 'code' => 'BBB' ]));
        $none = $this->write('edd-update-discount', [ 'discount_id' => $b + 50, 'status' => 'inactive' ]);
        $this->assertSame('discount_not_found', $none['error']['code'] ?? null);
    }

    public function test_create_download_makes_a_draft_with_its_price_and_rollback_trashes_it(): void
    {
        $out = $this->ok($this->write('edd-create-download', [ 'title' => 'New ebook', 'price' => 12.5, 'sku' => 'NE-1' ]));
        $id  = (int) $out['result']['download']['id'];
        $this->assertSame('download', get_post_type($id));
        $this->assertSame('draft', get_post_status($id));
        $this->assertSame('12.50', get_post_meta($id, 'edd_price', true));
        $this->assertSame('NE-1', get_post_meta($id, 'edd_sku', true));
        $this->assertNotEmpty($out['session_id'] ?? '', (string) wp_json_encode($out));

        $this->assertGreaterThan(0, Rollback_Service::restore_session((string) $out['session_id']));
        $this->assertContains(get_post_status($id), [ 'trash', false ]);
    }

    public function test_update_download_changes_title_and_price_and_rolls_back(): void
    {
        $id  = $this->download();
        $out = $this->ok($this->write('edd-update-download', [ 'download_id' => $id, 'title' => 'Renamed', 'price' => 19 ]));
        $this->assertSame('Renamed', get_the_title($id));
        $this->assertSame('19.00', get_post_meta($id, 'edd_price', true));

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame('Ebook', get_the_title($id));
        $this->assertSame('9.00', get_post_meta($id, 'edd_price', true));
    }

    public function test_update_download_refuses_a_price_on_variable_pricing(): void
    {
        $id  = $this->download([ '_variable_pricing' => '1' ]);
        $out = $this->write('edd-update-download', [ 'download_id' => $id, 'price' => 5 ]);
        $this->assertSame('variable_pricing', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertSame('9.00', get_post_meta($id, 'edd_price', true));
    }
}
