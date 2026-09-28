<?php

namespace WPMCP\Tests\Free\WooCommerce;

use WPMCP\Governance\Governance;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Rollback_Session;
use WPMCP\Tools\WooCommerce\Apply_Product_Import;
use WPMCP\Tools\WooCommerce\Plan_Product_Import;

/**
 * plan-product-import and apply-product-import: a read-only per-row plan
 * with a hash of the rows and the matched products, and an apply that
 * refuses a stale hash, writes through the existing product and variation
 * paths under one session, and is undone in full by rollback-session.
 */
class ProductImportTest extends \WP_UnitTestCase
{
    use VariableProductFixture;

    private int $http_calls = 0;

    public static function wpSetUpBeforeClass(): void
    {
        if (0 === did_action('wp_abilities_api_init')) {
            do_action('wp_abilities_api_init');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (! wpmcp_woocommerce_active()) {
            $this->markTestSkipped('WooCommerce not active');
        }
        Snapshot_Store::install();
        Governance::reset_for_tests();
        $user = self::factory()->user->create_and_get(['role' => 'administrator']);
        $user->add_cap('manage_woocommerce');
        wp_set_current_user($user->ID);
        $this->http_calls = 0;
        add_filter('pre_http_request', [$this, 'serve_image'], 10, 3);
    }

    protected function tearDown(): void
    {
        remove_filter('pre_http_request', [$this, 'serve_image'], 10);
        Governance::reset_for_tests();
        parent::tearDown();
    }

    public function serve_image($preempt, $parsed_args, $url)
    {
        $this->http_calls++;
        $body = (string) file_get_contents(DIR_TESTDATA . '/images/canola.jpg');
        if (! empty($parsed_args['filename'])) {
            file_put_contents($parsed_args['filename'], $body);
            $body = '';
        }
        return [
            'headers'  => ['content-type' => 'image/jpeg'],
            'body'     => $body,
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies'  => [],
            'filename' => $parsed_args['filename'] ?? null,
        ];
    }

    private function simple(string $sku, string $price, int $stock, string $name = 'Mug'): int
    {
        $product = new \WC_Product_Simple();
        $product->set_name($name);
        $product->set_sku($sku);
        $product->set_regular_price($price);
        $product->set_manage_stock(true);
        $product->set_stock_quantity($stock);
        return $product->save();
    }

    private function category(string $name): int
    {
        return (int) self::factory()->term->create(['taxonomy' => 'product_cat', 'name' => $name]);
    }

    private function plan(array $rows, array $extra = []): array
    {
        return (new Plan_Product_Import())->handle(array_merge(['rows' => $rows], $extra));
    }

    private function apply(array $rows, string $hash, array $extra = []): array
    {
        return (new Apply_Product_Import())->handle(array_merge(['rows' => $rows, 'plan_hash' => $hash], $extra));
    }

    /** Everything an import can touch, for "nothing was written" checks. */
    private function store_fingerprint(): string
    {
        global $wpdb;
        return md5((string) wp_json_encode([
            $wpdb->get_results("SELECT ID, post_type, post_status, post_modified_gmt, post_title FROM {$wpdb->posts} ORDER BY ID", ARRAY_A),
            $wpdb->get_results("SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} ORDER BY meta_id", ARRAY_A),
            $wpdb->get_results("SELECT object_id, term_taxonomy_id FROM {$wpdb->term_relationships} ORDER BY object_id, term_taxonomy_id", ARRAY_A),
            Snapshot_Store::row_count(),
        ]));
    }

    private function row_by_sku(array $plan, string $sku): array
    {
        foreach ($plan['rows'] as $row) {
            if ($row['sku'] === $sku) {
                return $row;
            }
        }
        $this->fail("No plan row for {$sku}");
    }

    public function test_plan_reports_create_update_skip_and_error_per_row(): void
    {
        $this->category('Kitchen');
        $this->simple('MUG-1', '9.00', 3);
        $this->simple('MUG-2', '5.00', 1, 'Same');

        $plan = $this->plan([
            ['sku' => 'NEW-1', 'name' => 'New Bowl', 'regular_price' => '12.50', 'stock' => 4, 'categories' => ['kitchen']],
            ['sku' => 'MUG-1', 'regular_price' => '11.00', 'stock' => 3, 'name' => 'Mug'],
            ['sku' => 'MUG-2', 'regular_price' => '5', 'name' => 'Same'],
            ['sku' => 'NEW-2', 'regular_price' => '1.00'],
            ['sku' => 'NEW-3', 'name' => 'X', 'categories' => ['No Such Category']],
            ['sku' => 'NEW-4', 'name' => 'Y', 'regular_price' => '5.00', 'sale_price' => '6.00'],
            ['sku' => 'NEW-1', 'name' => 'Duplicate'],
            ['sku' => 'NEW-5', 'name' => 'Z', 'images' => ['ftp://images.pexels.com/a.jpg', 'file:///etc/passwd']],
            ['sku' => 'NEW-6', 'name' => 'Q', 'colour' => 'red'],
        ]);

        $this->assertSame(['create' => 1, 'update' => 1, 'skip' => 1, 'error' => 6], $plan['summary']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $plan['plan_hash']);

        $this->assertSame('create', $plan['rows'][0]['action']);
        $this->assertSame('simple', $plan['rows'][0]['type']);

        $update = $plan['rows'][1];
        $this->assertSame('update', $update['action']);
        $this->assertSame(['regular_price' => ['from' => '9.00', 'to' => '11.00']], $update['changes']);

        $this->assertSame('skip', $plan['rows'][2]['action']);
        $this->assertStringContainsString('no changes', $plan['rows'][2]['reason']);

        $errors = array_map(fn ($r) => implode(' ', $r['errors'] ?? []), array_slice($plan['rows'], 3));
        $this->assertStringContainsString('name', $errors[0]);
        $this->assertStringContainsString('No Such Category', $errors[1]);
        $this->assertStringContainsString('sale_price', $errors[2]);
        $this->assertStringContainsString('more than once', $errors[3]);
        $this->assertStringContainsString('https', $errors[4]);
        $this->assertStringContainsString('file:///etc/passwd', $errors[4]);
        $this->assertStringContainsString('colour', $errors[5]);
    }

    public function test_mode_create_and_update_skip_rows_they_do_not_cover(): void
    {
        $this->simple('MUG-1', '9.00', 3);

        $create_only = $this->plan([['sku' => 'MUG-1', 'regular_price' => '1.00']], ['mode' => 'create']);
        $this->assertSame('skip', $create_only['rows'][0]['action']);

        $update_only = $this->plan([['sku' => 'NOPE', 'name' => 'x']], ['mode' => 'update']);
        $this->assertSame('skip', $update_only['rows'][0]['action']);
    }

    public function test_plan_writes_nothing_and_its_hash_is_stable(): void
    {
        $this->category('Kitchen');
        $ids  = $this->variable_product();
        $this->simple('MUG-1', '9.00', 3);
        $rows = [
            ['sku' => 'NEW-1', 'name' => 'Bowl', 'regular_price' => '3.00', 'images' => ['https://images.pexels.com/photos/1/bowl.jpg']],
            ['sku' => 'MUG-1', 'regular_price' => '11.00', 'categories' => ['Kitchen']],
            [
                'sku'        => 'TEE-1',
                'name'       => 'Tee',
                'type'       => 'variable',
                'attributes' => [['name' => 'Size', 'options' => ['S', 'M']]],
                'variations' => [['attributes' => ['Size' => 'S'], 'regular_price' => '10.00']],
            ],
        ];

        $before = $this->store_fingerprint();
        $first  = $this->plan($rows);
        $second = $this->plan($rows);
        $this->assertSame($before, $this->store_fingerprint(), 'plan-product-import wrote to the store');
        $this->assertSame(0, $this->http_calls, 'planning must never fetch an image');
        $this->assertSame($first['plan_hash'], $second['plan_hash']);
        $this->assertSame(0, $first['summary']['error'], (string) wp_json_encode($first['rows']));

        $rows[1]['regular_price'] = '12.00';
        $this->assertNotSame($first['plan_hash'], $this->plan($rows)['plan_hash'], 'changed rows must change the hash');
        unset($ids);
    }

    public function test_apply_refuses_a_stale_hash_and_writes_nothing(): void
    {
        $mug  = $this->simple('MUG-1', '9.00', 3);
        $rows = [
            ['sku' => 'MUG-1', 'regular_price' => '11.00'],
            ['sku' => 'NEW-1', 'name' => 'Bowl', 'regular_price' => '3.00'],
        ];
        $plan = $this->plan($rows);

        // The store changes after approval.
        $product = wc_get_product($mug);
        $product->set_stock_quantity(7);
        $product->save();

        $before = $this->store_fingerprint();
        try {
            $this->apply($rows, $plan['plan_hash']);
            $this->fail('A stale plan hash must be refused.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('plan-product-import', $e->getMessage());
        }
        $this->assertSame($before, $this->store_fingerprint());

        // The rows change after approval.
        $plan = $this->plan($rows);
        $rows[1]['regular_price'] = '4.00';
        try {
            $this->apply($rows, $plan['plan_hash']);
            $this->fail('Changed rows must be refused.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('changed', $e->getMessage());
        }
        $this->assertSame($before, $this->store_fingerprint());
        $this->assertSame(0, wc_get_product_id_by_sku('NEW-1'));
    }

    public function test_apply_refuses_a_plan_with_error_rows(): void
    {
        $rows = [
            ['sku' => 'NEW-1', 'name' => 'Bowl'],
            ['sku' => 'NEW-2'],
        ];
        $plan   = $this->plan($rows);
        $before = $this->store_fingerprint();

        $this->expectException(\InvalidArgumentException::class);
        try {
            $this->apply($rows, $plan['plan_hash']);
        } finally {
            $this->assertSame($before, $this->store_fingerprint());
        }
    }

    public function test_apply_creates_and_updates_including_a_variable_product(): void
    {
        $kitchen = $this->category('Kitchen');
        $media   = self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');
        $mug     = $this->simple('MUG-1', '9.00', 3);
        $rows    = [
            ['sku' => 'MUG-1', 'regular_price' => '11.00', 'sale_price' => '8.00', 'stock' => 30, 'categories' => ['kitchen'], 'images' => [$media]],
            ['sku' => 'BOWL-1', 'name' => 'Bowl', 'regular_price' => '3.00', 'status' => 'draft', 'images' => ['https://images.pexels.com/photos/2/bowl.jpg']],
            [
                'sku'        => 'TEE-1',
                'name'       => 'Tee',
                'type'       => 'variable',
                'categories' => ['Kitchen'],
                'attributes' => [
                    ['name' => 'Size', 'options' => ['S', 'M']],
                    ['name' => 'Material', 'options' => ['Cotton'], 'variation' => false],
                ],
                'variations' => [
                    ['sku' => 'TEE-1-S', 'attributes' => ['Size' => 'S'], 'regular_price' => '10.00', 'stock' => 2],
                    ['sku' => 'TEE-1-M', 'attributes' => ['size' => 'm'], 'regular_price' => '12.00', 'sale_price' => '11.00'],
                ],
            ],
        ];

        $plan = $this->plan($rows);
        $this->assertSame(0, $plan['summary']['error'], (string) wp_json_encode($plan['rows']));
        $out = $this->apply($rows, $plan['plan_hash']);

        $this->assertTrue(wp_is_uuid($out['session_id']));
        $this->assertSame(2, $out['created']);
        $this->assertSame(1, $out['updated']);
        $this->assertSame(1, $this->http_calls);

        $mug_product = wc_get_product($mug);
        $this->assertSame('11.00', $mug_product->get_regular_price());
        $this->assertSame('8.00', $mug_product->get_sale_price());
        $this->assertSame(30, $mug_product->get_stock_quantity());
        $this->assertSame([$kitchen], $mug_product->get_category_ids());
        $this->assertSame($media, $mug_product->get_image_id() ? (int) $mug_product->get_image_id() : 0);

        $bowl = wc_get_product(wc_get_product_id_by_sku('BOWL-1'));
        $this->assertSame('draft', $bowl->get_status());
        $this->assertGreaterThan(0, (int) $bowl->get_image_id());
        $this->assertTrue(wp_attachment_is_image((int) $bowl->get_image_id()));

        $tee = wc_get_product(wc_get_product_id_by_sku('TEE-1'));
        $this->assertTrue($tee->is_type('variable'));
        $this->assertSame([$kitchen], $tee->get_category_ids());
        $this->assertCount(2, $tee->get_children());
        $small = wc_get_product(wc_get_product_id_by_sku('TEE-1-S'));
        $this->assertSame('10.00', $small->get_regular_price());
        $this->assertSame(2, $small->get_stock_quantity());
        $this->assertSame(['size' => 'S'], $small->get_attributes());
        $medium = wc_get_product(wc_get_product_id_by_sku('TEE-1-M'));
        $this->assertSame('11.00', $medium->get_sale_price());
        $this->assertSame(['size' => 'M'], $medium->get_attributes());
        $this->assertSame('10.00', $tee->get_variation_price('min'));

        // Re-planning the same rows now finds nothing to do.
        $again = $this->plan($rows);
        $this->assertSame(['create' => 0, 'update' => 0, 'skip' => 3, 'error' => 0], $again['summary'], (string) wp_json_encode($again['rows']));
    }

    public function test_apply_updates_and_adds_variations_on_an_existing_variable_product(): void
    {
        $ids = $this->variable_product();
        $parent = wc_get_product($ids['parent']);
        $parent->set_sku('SHIRT');
        $parent->set_attributes([$this->size_attribute(['small', 'large', 'huge'])]);
        $parent->save();

        $rows = [[
            'sku'        => 'SHIRT',
            'variations' => [
                ['attributes' => ['size' => 'large'], 'regular_price' => '25.00'],
                ['attributes' => ['size' => 'huge'], 'regular_price' => '30.00', 'sku' => 'SHIRT-H'],
            ],
        ]];
        $plan = $this->plan($rows);
        $row  = $plan['rows'][0];
        $this->assertSame('update', $row['action'], (string) wp_json_encode($row));
        $this->assertSame('update', $row['variations'][0]['action']);
        $this->assertSame($ids['large'], $row['variations'][0]['id']);
        $this->assertSame(['regular_price' => ['from' => '20.00', 'to' => '25.00']], $row['variations'][0]['changes']);
        $this->assertSame('create', $row['variations'][1]['action']);

        $out = $this->apply($rows, $plan['plan_hash']);
        $this->assertSame(1, $out['updated']);
        $this->assertSame('25.00', wc_get_product($ids['large'])->get_regular_price());
        $this->assertSame('30.00', wc_get_product(wc_get_product_id_by_sku('SHIRT-H'))->get_regular_price());
        $this->assertSame('10.00', wc_get_product($ids['small'])->get_regular_price());
    }

    private function size_attribute(array $options): \WC_Product_Attribute
    {
        $attribute = new \WC_Product_Attribute();
        $attribute->set_name('size');
        $attribute->set_options($options);
        $attribute->set_visible(true);
        $attribute->set_variation(true);
        return $attribute;
    }

    /** @return array<string, mixed> the parts of a product an import can change. */
    private function state(int $id): array
    {
        clean_post_cache($id);
        $product = wc_get_product($id);
        $state   = [
            'name'       => $product->get_name(),
            'status'     => $product->get_status(),
            'regular'    => $product->get_regular_price(),
            'sale'       => $product->get_sale_price(),
            'price'      => $product->get_price(),
            'stock'      => $product->get_stock_quantity(),
            'manage'     => $product->get_manage_stock(),
            'categories' => $product->get_category_ids(),
            'image'      => (int) $product->get_image_id(),
            'gallery'    => $product->get_gallery_image_ids(),
            'attributes' => get_post_meta($id, '_product_attributes', true),
            'children'   => [],
        ];
        if ($product->is_type('variable')) {
            $children = $product->get_children();
            sort($children);
            foreach ($children as $child) {
                $state['children'][ $child ] = $this->state($child);
            }
            $state['range'] = [$product->get_variation_price('min'), $product->get_variation_price('max')];
        }
        return $state;
    }

    public function test_rollback_session_restores_every_product_and_removes_created_ones(): void
    {
        $this->category('Kitchen');
        $ids = $this->variable_product();
        $parent = wc_get_product($ids['parent']);
        $parent->set_sku('SHIRT');
        $parent->set_attributes([$this->size_attribute(['small', 'large', 'huge'])]);
        $parent->save();
        $mug = $this->simple('MUG-1', '9.00', 3);

        $before_mug    = $this->state($mug);
        $before_shirt  = $this->state($ids['parent']);
        $before_meta   = get_post_meta($mug);
        $product_count = count(get_posts(['post_type' => ['product', 'product_variation', 'attachment'], 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids']));

        $rows = [
            ['sku' => 'MUG-1', 'name' => 'Big Mug', 'regular_price' => '11.00', 'stock' => null, 'categories' => ['Kitchen'], 'images' => ['https://images.pexels.com/photos/3/mug.jpg']],
            ['sku' => 'SHIRT', 'variations' => [
                ['attributes' => ['size' => 'small'], 'regular_price' => '1.00', 'stock' => 99],
                ['attributes' => ['size' => 'huge'], 'regular_price' => '50.00'],
            ]],
            ['sku' => 'NEW-1', 'name' => 'Bowl', 'regular_price' => '3.00'],
            ['sku' => 'TEE-1', 'name' => 'Tee', 'type' => 'variable', 'attributes' => [['name' => 'Size', 'options' => ['S']]], 'variations' => [['attributes' => ['Size' => 'S'], 'regular_price' => '10.00']]],
        ];
        $plan = $this->plan($rows);
        $this->assertSame(0, $plan['summary']['error'], (string) wp_json_encode($plan['rows']));
        $out = $this->apply($rows, $plan['plan_hash']);
        $this->assertSame('11.00', wc_get_product($mug)->get_regular_price());

        $rolled = (new Rollback_Session())->handle(['session_id' => $out['session_id']]);
        $this->assertSame([], $rolled['warnings']);

        $this->assertSame($before_mug, $this->state($mug));
        $this->assertSame($before_shirt, $this->state($ids['parent']));
        $after_meta = get_post_meta($mug);
        unset($before_meta['_edit_lock'], $after_meta['_edit_lock']);
        $this->assertEquals($before_meta, $after_meta);
        $this->assertSame(0, wc_get_product_id_by_sku('NEW-1'));
        $this->assertSame(0, wc_get_product_id_by_sku('TEE-1'));
        $this->assertSame($product_count, count(get_posts(['post_type' => ['product', 'product_variation', 'attachment'], 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids'])));
    }

    public function test_the_whole_import_stays_undoable_past_the_snapshot_history_limit(): void
    {
        $limit = static fn () => 2;
        add_filter('wpmcp_snapshot_history_limit', $limit);
        try {
            $rows = [];
            for ($i = 1; $i <= 5; $i++) {
                $this->simple("S-{$i}", '1.00', 1);
                $rows[] = ['sku' => "S-{$i}", 'regular_price' => '2.00'];
            }
            $plan = $this->plan($rows);
            $out  = $this->apply($rows, $plan['plan_hash']);
            $this->assertSame(5, $out['undo_points']);
            $this->assertNotEmpty($out['warnings']);

            (new Rollback_Session())->handle(['session_id' => $out['session_id']]);
            for ($i = 1; $i <= 5; $i++) {
                $this->assertSame('1.00', wc_get_product(wc_get_product_id_by_sku("S-{$i}"))->get_regular_price());
            }
        } finally {
            remove_filter('wpmcp_snapshot_history_limit', $limit);
        }
    }

    public function test_row_cap_and_confirm_threshold(): void
    {
        $max = static fn () => 2;
        add_filter('wpmcp_product_import_max_rows', $max);
        try {
            $rows = [['sku' => 'A', 'name' => 'A'], ['sku' => 'B', 'name' => 'B'], ['sku' => 'C', 'name' => 'C']];
            try {
                $this->plan($rows);
                $this->fail('Rows over the cap must be refused.');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('2', $e->getMessage());
            }
            try {
                $this->apply($rows, str_repeat('0', 64));
                $this->fail('Rows over the cap must be refused.');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('2', $e->getMessage());
            }
        } finally {
            remove_filter('wpmcp_product_import_max_rows', $max);
        }

        $threshold = static fn () => 1;
        add_filter('wpmcp_product_import_confirm_threshold', $threshold);
        try {
            $rows = [['sku' => 'A', 'name' => 'A'], ['sku' => 'B', 'name' => 'B']];
            $plan = $this->plan($rows);
            $this->assertTrue($plan['confirm_required']);
            $before = $this->store_fingerprint();
            try {
                $this->apply($rows, $plan['plan_hash']);
                $this->fail('Writes above the threshold need confirm:true.');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('confirm', $e->getMessage());
            }
            $this->assertSame($before, $this->store_fingerprint());

            $out = $this->apply($rows, $plan['plan_hash'], ['confirm' => true]);
            $this->assertSame(2, $out['created']);
        } finally {
            remove_filter('wpmcp_product_import_confirm_threshold', $threshold);
        }
    }

    public function test_a_failing_row_rolls_back_what_the_call_already_wrote(): void
    {
        $mug  = $this->simple('MUG-1', '9.00', 3);
        $rows = [
            ['sku' => 'MUG-1', 'regular_price' => '11.00'],
            ['sku' => 'NEW-1', 'name' => 'Bowl', 'images' => ['https://images.pexels.com/photos/4/bowl.jpg']],
        ];
        $plan = $this->plan($rows);

        $fail = static fn () => ['response' => ['code' => 500, 'message' => 'err'], 'headers' => [], 'body' => '', 'cookies' => []];
        remove_filter('pre_http_request', [$this, 'serve_image'], 10);
        add_filter('pre_http_request', $fail);
        try {
            $this->apply($rows, $plan['plan_hash']);
            $this->fail('A failed image download must fail the import.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('rolled back', $e->getMessage());
        } finally {
            remove_filter('pre_http_request', $fail);
        }

        $this->assertSame('9.00', wc_get_product($mug)->get_regular_price());
        $this->assertSame(0, wc_get_product_id_by_sku('NEW-1'));
    }

    public function test_global_attribute_terms_must_exist_and_variations_use_their_slugs(): void
    {
        $attribute_id = wc_create_attribute(['name' => 'Color', 'slug' => 'color']);
        $this->assertIsInt($attribute_id);
        register_taxonomy('pa_color', ['product']);
        wp_insert_term('Red', 'pa_color', ['slug' => 'red']);
        wp_insert_term('Blue', 'pa_color', ['slug' => 'blue']);

        try {
            $rows = [[
                'sku'        => 'CAP-1',
                'name'       => 'Cap',
                'type'       => 'variable',
                'attributes' => [['name' => 'Color', 'options' => ['Red', 'blue']]],
                'variations' => [
                    ['attributes' => ['color' => 'Red'], 'regular_price' => '5.00'],
                    ['attributes' => ['pa_color' => 'blue'], 'regular_price' => '6.00', 'status' => 'private'],
                ],
            ]];
            $plan = $this->plan($rows);
            $this->assertSame('create', $plan['rows'][0]['action'], (string) wp_json_encode($plan['rows']));
            $out = $this->apply($rows, $plan['plan_hash']);

            $cap        = wc_get_product($out['rows'][0]['id']);
            $attributes = $cap->get_attributes();
            $this->assertArrayHasKey('pa_color', $attributes);
            $this->assertTrue($attributes['pa_color']->is_taxonomy());
            $values = [];
            foreach ($cap->get_children() as $child) {
                $values[] = wc_get_product($child)->get_attributes()['pa_color'];
            }
            sort($values);
            $this->assertSame(['blue', 'red'], $values);

            $again = $this->plan($rows)['rows'][0];
            $this->assertSame('skip', $again['action'], (string) wp_json_encode($again));

            $bad = $this->plan([['sku' => 'CAP-2', 'name' => 'Cap', 'attributes' => [['name' => 'Color', 'options' => ['Mauve']]]]]);
            $this->assertStringContainsString('Mauve', implode(' ', $bad['rows'][0]['errors']));
        } finally {
            wc_delete_attribute($attribute_id);
        }
    }

    public function test_plan_explains_each_invalid_row(): void
    {
        $this->simple('MUG-1', '9.00', 3);
        $ids    = $this->variable_product();
        $parent = wc_get_product($ids['parent']);
        $parent->set_sku('SHIRT');
        $parent->save();
        $small = wc_get_product($ids['small']);
        $small->set_sku('SHIRT-S');
        $small->save();

        $cases = [
            'changing a product'   => ['sku' => 'MUG-1', 'type' => 'variable'],
            'set on its variations' => ['sku' => 'SHIRT', 'regular_price' => '5.00'],
            'only accepted on a variable' => ['sku' => 'MUG-1', 'variations' => []],
            'unknown variation attribute' => ['sku' => 'SHIRT', 'variations' => [['attributes' => ['colour' => 'red']]]],
            'no option'            => ['sku' => 'SHIRT', 'variations' => [['attributes' => ['size' => 'tiny']]]],
            'combination'          => ['sku' => 'SHIRT', 'variations' => [['attributes' => ['size' => 'small']], ['attributes' => ['size' => 'Small']]]],
            'already used by product' => ['sku' => 'SHIRT', 'variations' => [['sku' => 'MUG-1', 'attributes' => ['size' => 'large']]]],
            'belongs to a variation' => ['sku' => 'SHIRT-S', 'regular_price' => '1.00'],
            'integer quantity'     => ['sku' => 'NEW-1', 'name' => 'A', 'stock' => 'lots'],
            'status must be'       => ['sku' => 'NEW-2', 'name' => 'A', 'status' => 'live'],
            'variation:true'       => ['sku' => 'NEW-3', 'name' => 'A', 'type' => 'variable', 'attributes' => [['name' => 'Size', 'options' => ['S'], 'variation' => false]]],
            'type must be'         => ['sku' => 'NEW-4', 'name' => 'A', 'type' => 'grouped'],
            'sku is required'      => ['name' => 'No sku'],
            'not an image'         => ['sku' => 'NEW-5', 'name' => 'A', 'images' => [999999]],
            'must be an object'    => ['sku' => 'NEW-6', 'name' => 'A', 'type' => 'variable', 'attributes' => [['name' => 'Size', 'options' => ['S']]], 'variations' => [['attributes' => ['S']]]],
        ];

        foreach ($cases as $needle => $row) {
            $plan = $this->plan([$row]);
            $this->assertSame('error', $plan['rows'][0]['action'], $needle . ': ' . wp_json_encode($plan['rows'][0]));
            $this->assertStringContainsString($needle, implode(' ', $plan['rows'][0]['errors']));
        }

        foreach ([['rows' => []], ['rows' => [['sku' => 'A']], 'mode' => 'merge'], ['rows' => ['sku' => 'A']]] as $bad) {
            try {
                (new Plan_Product_Import())->handle($bad);
                $this->fail('Expected a refusal.');
            } catch (\InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
        try {
            (new Apply_Product_Import())->handle(['rows' => [['sku' => 'A', 'name' => 'A']]]);
            $this->fail('A missing plan_hash must be refused.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('plan_hash', $e->getMessage());
        }
    }

    public function test_an_all_skip_plan_applies_as_a_no_op(): void
    {
        $this->simple('MUG-1', '9.00', 3);
        $rows = [['sku' => 'MUG-1', 'regular_price' => '9.00']];
        $plan = $this->plan($rows);
        $out  = $this->apply($rows, $plan['plan_hash']);
        $this->assertNull($out['session_id']);
        $this->assertSame(1, $out['skipped']);
    }

    public function test_abilities_are_registered_free_with_the_store_capability(): void
    {
        $abilities = wp_get_abilities();
        foreach (['wpmcp/plan-product-import', 'wpmcp/apply-product-import'] as $name) {
            $this->assertArrayHasKey($name, $abilities);
        }

        $registrar = \WPMCP\Plugin::instance()->registrar();
        $plan      = $registrar->get('wpmcp/plan-product-import');
        $apply     = $registrar->get('wpmcp/apply-product-import');
        $this->assertSame('free', $plan->tier);
        $this->assertSame('free', $apply->tier);
        $this->assertSame('manage_woocommerce', $plan->capability);
        $this->assertSame('manage_woocommerce', $apply->capability);
        $this->assertTrue($plan->read_only_hint);
        $this->assertFalse($apply->read_only_hint);
        $this->assertFalse($apply->destructive_hint);
    }
}
