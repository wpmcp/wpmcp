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
 * The remaining wc/v3 gaps of the woo-read / woo-write catalog (issue #657):
 * customer downloads, the data endpoints (countries, continents,
 * currencies, the store currency), product and review totals, one tax rate,
 * and tax rate writes (create, update, delete) on the catalog, so taxes
 * batch through woo-write like every other domain. Every write is
 * snapshotted and undone here; batches of orders, settings and taxes run
 * one snapshotted op per item in one session.
 */
class WooCatalogGapsTest extends \WP_UnitTestCase
{
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
        wpmcp_test_grant_network_admin(get_current_user_id());

        global $wp_rest_server;
        $wp_rest_server = null;
        rest_get_server();
    }

    protected function tearDown(): void
    {
        global $wp_rest_server;
        $wp_rest_server = null;

        remove_all_filters('wpmcp_woo_op_enabled');
        Governance::reset_for_tests();
        wp_set_current_user(0);
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function enable_op(string $op): void
    {
        add_filter('wpmcp_woo_op_enabled', static function ($enabled, $name) use ($op) {
            return $name === $op ? true : $enabled;
        }, 10, 2);
    }

    private function tax_rate(string $rate = '10.0000', string $country = 'US'): int
    {
        return (int) \WC_Tax::_insert_tax_rate([
            'tax_rate_country'  => $country,
            'tax_rate_state'    => '',
            'tax_rate'          => $rate,
            'tax_rate_name'     => 'Sales tax',
            'tax_rate_priority' => 1,
            'tax_rate_compound' => 0,
            'tax_rate_shipping' => 1,
            'tax_rate_order'    => 0,
            'tax_rate_class'    => '',
        ]);
    }

    private function rate_of(int $id): ?string
    {
        $row = \WC_Tax::_get_tax_rate($id, ARRAY_A);
        return is_array($row) && ! empty($row) ? (string) $row['tax_rate'] : null;
    }

    public function test_the_new_rows_are_in_the_catalog_with_their_modes(): void
    {
        $ops = Op_Catalog::ops();
        foreach ([
            'customers.downloads'   => 'read',
            'data.countries'        => 'read',
            'data.country'          => 'read',
            'data.continents'       => 'read',
            'data.currencies'       => 'read',
            'data.currency-current' => 'read',
            'reports.products'      => 'read',
            'reports.reviews'       => 'read',
            'taxes.rate'            => 'read',
            'taxes.create'          => 'write',
            'taxes.update'          => 'write',
            'taxes.delete'          => 'destructive',
        ] as $op => $mode) {
            $this->assertArrayHasKey($op, $ops, "{$op} is missing");
            $this->assertSame($mode, $ops[ $op ]['mode'], "{$op} mode");
        }
        $this->assertTrue($ops['taxes.update']['recoverable']);
        $this->assertTrue($ops['taxes.delete']['recoverable']);
        $this->assertTrue($ops['taxes.create']['recoverable']);
        $this->assertArrayHasKey('customer_id', $ops['customers.downloads']['objects']);
    }

    // ---------------------------------------------------------------- reads

    public function test_data_reads_list_countries_currencies_and_the_store_currency(): void
    {
        update_option('woocommerce_currency', 'EUR');
        $read = new Woo_Read();

        $countries = $read->handle(['op' => 'data.countries']);
        $this->assertSame(200, $countries['status']);
        $this->assertContains('US', array_column($countries['body'], 'code'));

        $country = $read->handle(['op' => 'data.country', 'params' => ['location' => 'us']]);
        $this->assertSame(200, $country['status']);
        $this->assertSame('US', $country['body']['code']);

        $continents = $read->handle(['op' => 'data.continents']);
        $this->assertSame(200, $continents['status']);
        $this->assertNotEmpty($continents['body']);

        $currencies = $read->handle(['op' => 'data.currencies']);
        $this->assertSame(200, $currencies['status']);
        $this->assertContains('USD', array_column($currencies['body'], 'code'));

        $current = $read->handle(['op' => 'data.currency-current']);
        $this->assertSame(200, $current['status']);
        $this->assertSame('EUR', $current['body']['code']);
    }

    public function test_product_and_review_totals(): void
    {
        $product = new \WC_Product_Simple();
        $product->set_name('Mug');
        $product->set_regular_price('5');
        $pid = (int) $product->save();
        $cid = self::factory()->comment->create(['comment_post_ID' => $pid, 'comment_type' => 'review', 'comment_approved' => '1']);
        update_comment_meta($cid, 'rating', 4);

        $read = new Woo_Read();

        $products = $read->handle(['op' => 'reports.products']);
        $this->assertSame(200, $products['status']);
        $by_type = array_column($products['body'], 'total', 'slug');
        $this->assertGreaterThanOrEqual(1, (int) $by_type['simple']);

        $reviews = $read->handle(['op' => 'reports.reviews']);
        $this->assertSame(200, $reviews['status']);
        $by_rating = array_column($reviews['body'], 'total', 'slug');
        $this->assertGreaterThanOrEqual(1, (int) $by_rating['rated_4_out_of_5']);
    }

    public function test_one_tax_rate_reads_through_woo_read(): void
    {
        $id  = $this->tax_rate('7.5000', 'CA');
        $out = (new Woo_Read())->handle(['op' => 'taxes.rate', 'params' => ['id' => $id]]);

        $this->assertSame(200, $out['status']);
        $this->assertSame($id, (int) $out['body']['id']);
        $this->assertSame('CA', $out['body']['country']);
    }

    public function test_customer_downloads_hide_the_download_links_and_order_key(): void
    {
        $customer = self::factory()->user->create(['role' => 'customer', 'user_email' => 'reader@example.com']);

        $product = new \WC_Product_Simple();
        $product->set_name('Ebook');
        $product->set_regular_price('5');
        $product->set_virtual(true);
        $product->set_downloadable(true);
        $file = new \WC_Product_Download();
        $file->set_id('ebook-pdf');
        $file->set_name('Ebook PDF');
        $file->set_file(content_url('uploads/woocommerce_uploads/ebook.pdf'));
        $product->set_downloads([$file]);
        $pid = (int) $product->save();

        $order = wc_create_order(['customer_id' => $customer]);
        $order->add_product(wc_get_product($pid), 1);
        $order->set_billing_email('reader@example.com');
        $order->calculate_totals();
        $order->set_status('completed');
        // Completing the order grants the download permission.
        $order->save();

        $out = (new Woo_Read())->handle(['op' => 'customers.downloads', 'params' => ['customer_id' => $customer]]);

        $this->assertSame(200, $out['status']);
        $this->assertCount(1, $out['body']);
        $row = $out['body'][0];
        $this->assertSame($pid, (int) $row['product_id']);
        $this->assertSame('Ebook PDF', $row['download_name']);
        $this->assertSame('[redacted]', $row['download_url']);
        $this->assertSame('[redacted]', $row['order_key']);
        $this->assertSame('[redacted]', $row['file']);
        $this->assertStringNotContainsString('reader@example.com', (string) wp_json_encode($out));
        $this->assertStringNotContainsString($order->get_order_key(), (string) wp_json_encode($out));
    }

    // --------------------------------------------------------------- writes

    public function test_taxes_create_records_a_creation_row_that_rollback_deletes(): void
    {
        $out = (new Woo_Write())->handle([
            'op'     => 'taxes.create',
            'params' => ['country' => 'DE', 'rate' => '19.0000', 'name' => 'MwSt', 'postcodes' => ['10115']],
        ]);

        $this->assertSame(201, $out['status'], (string) wp_json_encode($out));
        $this->assertTrue($out['applied']);
        $this->assertTrue($out['recoverable']);
        $this->assertNotEmpty($out['operation_id']);
        $id = (int) $out['body']['id'];
        $this->assertSame('19.0000', $this->rate_of($id));

        (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertNull($this->rate_of($id));
    }

    public function test_taxes_update_is_snapshotted_and_rolls_back_rate_and_postcodes(): void
    {
        $id = $this->tax_rate('10.0000');
        \WC_Tax::_update_tax_rate_postcodes($id, '90210');

        $out = (new Woo_Write())->handle([
            'op'     => 'taxes.update',
            'params' => ['id' => $id, 'rate' => '12.5000', 'postcodes' => ['10001', '10002']],
        ]);
        $this->assertSame(200, $out['status'], (string) wp_json_encode($out));
        $this->assertSame('12.5000', $this->rate_of($id));

        (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertSame('10.0000', $this->rate_of($id));
        $rate = (new Woo_Read())->handle(['op' => 'taxes.rate', 'params' => ['id' => $id]]);
        $this->assertSame(['90210'], array_values($rate['body']['postcodes']));
    }

    public function test_taxes_delete_is_opt_in_needs_confirm_and_rolls_back(): void
    {
        $id    = $this->tax_rate('8.0000');
        $write = new Woo_Write();

        $off = $write->handle(['op' => 'taxes.delete', 'params' => ['id' => $id], 'confirm' => true]);
        $this->assertSame('opt_in_required', $off['error']['code']);
        $this->assertSame('8.0000', $this->rate_of($id));

        $this->enable_op('taxes.delete');
        $unconfirmed = $write->handle(['op' => 'taxes.delete', 'params' => ['id' => $id]]);
        $this->assertSame('confirmation_required', $unconfirmed['error']['code']);
        $this->assertSame('8.0000', $this->rate_of($id));

        $out = $write->handle(['op' => 'taxes.delete', 'params' => ['id' => $id], 'confirm' => true]);
        $this->assertSame(200, $out['status'], (string) wp_json_encode($out));
        $this->assertNull($this->rate_of($id));

        (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertSame('8.0000', $this->rate_of($id));
    }

    public function test_a_tax_batch_applies_each_item_and_rolls_back_in_one_session(): void
    {
        $a = $this->tax_rate('5.0000');
        $b = $this->tax_rate('6.0000');

        $out = (new Woo_Write())->handle([
            'batch' => [
                ['op' => 'taxes.update', 'params' => ['id' => $a, 'rate' => '5.5000']],
                ['op' => 'taxes.update', 'params' => ['id' => $b, 'rate' => '6.5000']],
                ['op' => 'taxes.create', 'params' => ['country' => 'FR', 'rate' => '20.0000', 'name' => 'TVA']],
            ],
        ]);

        $this->assertSame(3, $out['applied'], (string) wp_json_encode($out));
        $made = (int) $out['results'][2]['body']['id'];
        $this->assertSame('5.5000', $this->rate_of($a));
        $this->assertSame('6.5000', $this->rate_of($b));
        $this->assertSame('20.0000', $this->rate_of($made));

        (new Rollback_Session())->handle(['session_id' => $out['session_id']]);
        $this->assertSame('5.0000', $this->rate_of($a));
        $this->assertSame('6.0000', $this->rate_of($b));
        $this->assertNull($this->rate_of($made));
    }

    public function test_a_settings_batch_rolls_back_in_one_session(): void
    {
        update_option('woocommerce_currency', 'USD');
        update_option('woocommerce_price_num_decimals', '2');

        $out = (new Woo_Write())->handle([
            'batch' => [
                ['op' => 'settings.update', 'params' => ['group_id' => 'general', 'id' => 'woocommerce_currency', 'value' => 'GBP']],
                ['op' => 'settings.update', 'params' => ['group_id' => 'general', 'id' => 'woocommerce_price_num_decimals', 'value' => '3']],
            ],
        ]);

        $this->assertSame(2, $out['applied'], (string) wp_json_encode($out));
        $this->assertSame('GBP', get_option('woocommerce_currency'));
        $this->assertSame('3', (string) get_option('woocommerce_price_num_decimals'));

        (new Rollback_Session())->handle(['session_id' => $out['session_id']]);
        $this->assertSame('USD', get_option('woocommerce_currency'));
        $this->assertSame('2', (string) get_option('woocommerce_price_num_decimals'));
    }

    public function test_an_orders_batch_rolls_back_in_one_session(): void
    {
        $product = new \WC_Product_Simple();
        $product->set_name('Cap');
        $product->set_regular_price('10');
        $pid = (int) $product->save();

        $first  = wc_create_order();
        $second = wc_create_order();
        foreach ([$first, $second] as $order) {
            $order->add_product(wc_get_product($pid), 1);
            $order->set_customer_note('before');
            $order->calculate_totals();
            $order->save();
        }

        $out = (new Woo_Write())->handle([
            'batch' => [
                ['op' => 'orders.update', 'params' => ['id' => $first->get_id(), 'customer_note' => 'after one']],
                ['op' => 'orders.update', 'params' => ['id' => $second->get_id(), 'customer_note' => 'after two']],
            ],
        ]);

        $this->assertSame(2, $out['applied'], (string) wp_json_encode($out));
        $this->assertSame('after one', wc_get_order($first->get_id())->get_customer_note());

        (new Rollback_Session())->handle(['session_id' => $out['session_id']]);
        $this->assertSame('before', wc_get_order($first->get_id())->get_customer_note());
        $this->assertSame('before', wc_get_order($second->get_id())->get_customer_note());
    }
}
