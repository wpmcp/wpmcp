<?php

namespace WPMCP\Tests\Pro\WooCommerce;

use WPMCP\Governance\Governance;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Rollback_Operation;
use WPMCP\Tools\Rollback_Session;
use WPMCP\Tools\WooCommerce\Catalog\Op_Catalog;
use WPMCP\Tools\WooCommerce\Catalog\Woo_Ops;
use WPMCP\Tools\WooCommerce\Catalog\Woo_Read;
use WPMCP\Tools\WooCommerce\Catalog\Woo_Write;

/**
 * WooCommerce brands workflows (issue #293): brand create, update, delete and
 * product assignment as ops on the existing woo-read / woo-write catalog,
 * with brand images validated as image attachments (or fetched through the
 * remote media guard), term snapshots for brand writes and post snapshots for
 * assignment, so rollback restores exactly.
 */
class WooBrandsTest extends \WP_UnitTestCase
{
    private const TAX = 'product_brand';

    /** @var int */
    private $outbound = 0;

    protected function setUp(): void
    {
        parent::setUp();
        if (! class_exists('WooCommerce')) {
            $this->markTestSkipped('WooCommerce is not active in this environment.');
        }
        if (! taxonomy_exists(self::TAX)) {
            $this->markTestSkipped('This WooCommerce does not register the product_brand taxonomy.');
        }

        Gate::set_pro_for_tests(true);
        Snapshot_Store::install();
        Governance::reset_for_tests();

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
        remove_all_filters('pre_http_request');
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

    private function product(string $name = 'Mug'): int
    {
        $product = new \WC_Product_Simple();
        $product->set_name($name);
        $product->set_regular_price('10.00');
        return (int) $product->save();
    }

    private function brand(string $name, array $args = []): int
    {
        $made = wp_insert_term($name, self::TAX, $args);
        $this->assertIsArray($made);
        return (int) $made['term_id'];
    }

    private function image(): int
    {
        return (int) self::factory()->attachment->create_upload_object(DIR_TESTDATA . '/images/canola.jpg');
    }

    /** @return int[] */
    private function brands_of(int $product_id): array
    {
        clean_object_term_cache($product_id, 'product');
        $ids = wp_get_object_terms($product_id, self::TAX, ['fields' => 'ids']);
        sort($ids);
        return array_map('intval', $ids);
    }

    public function count_outbound($preempt)
    {
        $this->outbound++;
        return $preempt;
    }

    public function serve_image($preempt, $parsed_args, $url)
    {
        $body = (string) file_get_contents(DIR_TESTDATA . '/images/canola.jpg');
        if (! empty($parsed_args['filename'])) {
            file_put_contents($parsed_args['filename'], $body);
            $body = '';
        }
        return [
            'headers'  => ['content-type' => 'image/jpeg', 'content-length' => (string) filesize(DIR_TESTDATA . '/images/canola.jpg')],
            'body'     => $body,
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies'  => [],
            'filename' => $parsed_args['filename'] ?? null,
        ];
    }

    // ------------------------------------------------------------ catalog

    public function test_the_catalog_carries_brand_ops_on_the_existing_dispatchers(): void
    {
        $ops = Op_Catalog::ops();

        $expected = [
            'brands.list'     => ['read', null],
            'brands.get'      => ['read', null],
            'brands.create'   => ['write', 'term'],
            'brands.update'   => ['write', 'term'],
            'brands.delete'   => ['destructive', 'term'],
            'brands.assign'   => ['write', 'post'],
            'brands.unassign' => ['write', 'post'],
        ];

        foreach ($expected as $op => [$mode, $snapshot]) {
            $this->assertArrayHasKey($op, $ops, "Missing op {$op}");
            $this->assertSame('brands', $ops[ $op ]['domain']);
            $this->assertSame($mode, $ops[ $op ]['mode'], "Wrong mode for {$op}");
            $this->assertSame($snapshot, $ops[ $op ]['snapshot']['type'] ?? null, "Wrong snapshot for {$op}");
            if ('read' !== $mode) {
                $this->assertTrue($ops[ $op ]['recoverable'], "{$op} must be recoverable");
            }
        }
    }

    public function test_woo_ops_lists_the_brands_domain(): void
    {
        $out = (new Woo_Ops())->handle(['domain' => 'brands']);

        $this->assertSame(['brands'], array_keys($out['domains']));
        $this->assertSame(7, $out['total']);
    }

    // -------------------------------------------------------------- reads

    public function test_brands_list_and_get_read_through_woo_read(): void
    {
        $id = $this->brand('Acme');

        $list = (new Woo_Read())->handle(['op' => 'brands.list']);
        $get  = (new Woo_Read())->handle(['op' => 'brands.get', 'params' => ['id' => $id]]);

        $this->assertSame(200, $list['status']);
        $this->assertContains('Acme', array_column($list['body'], 'name'));
        $this->assertSame(200, $get['status']);
        $this->assertSame('acme', $get['body']['slug']);
    }

    // ------------------------------------------------------------- create

    public function test_brands_create_is_term_snapshotted_and_rollback_removes_it(): void
    {
        $out = (new Woo_Write())->handle([
            'op'     => 'brands.create',
            'params' => ['name' => 'Northwind', 'description' => 'Outdoor gear'],
        ]);

        $this->assertSame(201, $out['status']);
        $this->assertTrue($out['applied']);
        $this->assertTrue($out['recoverable']);
        $this->assertArrayHasKey('operation_id', $out);

        $term = get_term_by('slug', 'northwind', self::TAX);
        $this->assertInstanceOf(\WP_Term::class, $term);
        $this->assertSame((int) $term->term_id, (int) $out['body']['id']);

        (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertFalse(get_term_by('slug', 'northwind', self::TAX));
    }

    public function test_brands_create_refuses_a_slug_already_taken_and_writes_nothing(): void
    {
        $this->brand('Acme');
        $before = (int) wp_count_terms(['taxonomy' => self::TAX, 'hide_empty' => false]);

        $out = (new Woo_Write())->handle(['op' => 'brands.create', 'params' => ['name' => 'Acme']]);

        $this->assertSame('brand_exists', $out['error']['code']);
        $this->assertSame($before, (int) wp_count_terms(['taxonomy' => self::TAX, 'hide_empty' => false]));
    }

    public function test_brands_create_requires_a_name(): void
    {
        $out = (new Woo_Write())->handle(['op' => 'brands.create', 'params' => ['description' => 'x']]);
        $this->assertSame('invalid_params', $out['error']['code']);
    }

    public function test_brands_create_with_an_image_sets_the_brand_thumbnail(): void
    {
        $image = $this->image();
        $out   = (new Woo_Write())->handle([
            'op'     => 'brands.create',
            'params' => ['name' => 'Contoso', 'image' => ['id' => $image]],
        ]);

        $this->assertSame(201, $out['status']);
        $this->assertSame($image, (int) get_term_meta((int) $out['body']['id'], 'thumbnail_id', true));
    }

    // ------------------------------------------------------------- images

    public function test_a_brand_image_that_is_not_an_image_attachment_is_refused(): void
    {
        $id  = $this->brand('Acme');
        $doc = self::factory()->post->create(['post_type' => 'attachment', 'post_mime_type' => 'application/pdf']);

        foreach ([['id' => $doc], ['id' => 999999], 'not-an-object'] as $image) {
            $out = (new Woo_Write())->handle([
                'op'     => 'brands.update',
                'params' => ['id' => $id, 'image' => $image],
            ]);
            $this->assertSame('invalid_image', $out['error']['code']);
        }
        $this->assertSame('', get_term_meta($id, 'thumbnail_id', true));
    }

    public function test_brand_image_alt_and_name_are_refused_since_they_edit_the_attachment(): void
    {
        $id    = $this->brand('Acme');
        $image = $this->image();

        foreach (['alt', 'name'] as $key) {
            $out = (new Woo_Write())->handle([
                'op'     => 'brands.update',
                'params' => ['id' => $id, 'image' => ['id' => $image, $key => 'x']],
            ]);
            $this->assertSame('invalid_image', $out['error']['code']);
        }
    }

    public function test_a_remote_brand_image_off_the_media_allowlist_is_refused_before_any_request(): void
    {
        add_filter('pre_http_request', [$this, 'count_outbound']);
        $id = $this->brand('Acme');

        $out = (new Woo_Write())->handle([
            'op'     => 'brands.update',
            'params' => ['id' => $id, 'image' => ['src' => 'http://169.254.169.254/logo.png']],
        ]);

        $this->assertSame('invalid_image', $out['error']['code']);
        $this->assertSame(0, $this->outbound);
    }

    public function test_an_allowlisted_remote_brand_image_is_sideloaded_through_the_media_guard(): void
    {
        add_filter('pre_http_request', [$this, 'serve_image'], 10, 3);
        $id = $this->brand('Acme');

        $out = (new Woo_Write())->handle([
            'op'         => 'brands.update',
            'params'     => ['id' => $id, 'image' => ['src' => 'https://images.pexels.com/photos/1/logo.jpg']],
            'session_id' => 'brand-image',
        ]);

        $this->assertSame(200, $out['status']);
        $media = (int) get_term_meta($id, 'thumbnail_id', true);
        $this->assertGreaterThan(0, $media);
        $this->assertTrue(wp_attachment_is_image($media));
        $this->assertArrayHasKey('media_operation_id', $out);

        // Rolling back the session removes the thumbnail and the imported file.
        (new Rollback_Session())->handle(['session_id' => 'brand-image']);
        $this->assertSame('', get_term_meta($id, 'thumbnail_id', true));
        $this->assertNull(get_post($media));
    }

    // ------------------------------------------------------------- update

    public function test_brands_update_rolls_back_name_slug_description_parent_and_image_exactly(): void
    {
        $parent = $this->brand('Umbrella');
        $old    = $this->image();
        $new    = $this->image();
        $id     = $this->brand('Acme', ['description' => 'Original']);
        update_term_meta($id, 'thumbnail_id', $old);

        $out = (new Woo_Write())->handle([
            'op'     => 'brands.update',
            'params' => [
                'id'          => $id,
                'name'        => 'Acme Corp',
                'slug'        => 'acme-corp',
                'description' => 'Changed',
                'parent'      => $parent,
                'image'       => ['id' => $new],
            ],
        ]);

        $this->assertSame(200, $out['status']);
        $this->assertTrue($out['recoverable']);
        $this->assertSame($new, (int) get_term_meta($id, 'thumbnail_id', true));
        $this->assertSame('acme-corp', get_term($id, self::TAX)->slug);

        (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);

        clean_term_cache($id, self::TAX);
        $term = get_term($id, self::TAX);
        $this->assertSame('Acme', $term->name);
        $this->assertSame('acme', $term->slug);
        $this->assertSame('Original', $term->description);
        $this->assertSame(0, (int) $term->parent);
        $this->assertSame($old, (int) get_term_meta($id, 'thumbnail_id', true));
    }

    public function test_brands_update_rollback_removes_an_image_the_brand_did_not_have(): void
    {
        $id  = $this->brand('Acme');
        $out = (new Woo_Write())->handle([
            'op'     => 'brands.update',
            'params' => ['id' => $id, 'image' => ['id' => $this->image()]],
        ]);
        $this->assertSame(200, $out['status']);

        (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertSame('', get_term_meta($id, 'thumbnail_id', true));
    }

    public function test_brands_update_of_an_unknown_brand_is_refused(): void
    {
        $category = wp_insert_term('Not a brand', 'product_cat');

        foreach ([999999, (int) $category['term_id']] as $id) {
            $out = (new Woo_Write())->handle(['op' => 'brands.update', 'params' => ['id' => $id, 'name' => 'x']]);
            $this->assertSame('unknown_brand', $out['error']['code']);
        }
        $this->assertSame('Not a brand', get_term((int) $category['term_id'])->name);
    }

    // ------------------------------------------------------------- delete

    public function test_brands_delete_without_confirm_reports_how_many_products_use_it(): void
    {
        $this->enable_op('brands.delete');
        $id = $this->brand('Acme');
        foreach (['A', 'B', 'C'] as $name) {
            wp_set_object_terms($this->product($name), [$id], self::TAX);
        }

        $out = (new Woo_Write())->handle(['op' => 'brands.delete', 'params' => ['id' => $id]]);

        $this->assertSame('confirmation_required', $out['error']['code']);
        $this->assertSame(3, $out['error']['data']['products_using']);
        $this->assertStringContainsString('3 products', $out['error']['message']);
        $this->assertInstanceOf(\WP_Term::class, get_term($id, self::TAX));
    }

    public function test_brands_delete_is_off_by_default(): void
    {
        $id  = $this->brand('Acme');
        $out = (new Woo_Write())->handle(['op' => 'brands.delete', 'params' => ['id' => $id], 'confirm' => true]);

        $this->assertSame('operation_disabled', $out['error']['code']);
        $this->assertInstanceOf(\WP_Term::class, get_term($id, self::TAX));
    }

    public function test_brands_delete_with_confirm_rolls_back_the_term_its_image_and_its_products(): void
    {
        $this->enable_op('brands.delete');
        $image = $this->image();
        $id    = $this->brand('Acme', ['description' => 'Kept']);
        update_term_meta($id, 'thumbnail_id', $image);
        $a = $this->product('A');
        $b = $this->product('B');
        wp_set_object_terms($a, [$id], self::TAX);
        wp_set_object_terms($b, [$id], self::TAX);

        $out = (new Woo_Write())->handle(['op' => 'brands.delete', 'params' => ['id' => $id], 'confirm' => true]);

        $this->assertSame(200, $out['status']);
        $this->assertTrue($out['recoverable']);
        $this->assertSame(2, $out['products_using']);
        $this->assertNull(get_term($id, self::TAX));
        $this->assertSame([], $this->brands_of($a));

        (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);

        clean_term_cache($id, self::TAX);
        $term = get_term($id, self::TAX);
        $this->assertInstanceOf(\WP_Term::class, $term);
        $this->assertSame('Acme', $term->name);
        $this->assertSame('Kept', $term->description);
        $this->assertSame($image, (int) get_term_meta($id, 'thumbnail_id', true));
        $this->assertSame([$id], $this->brands_of($a));
        $this->assertSame([$id], $this->brands_of($b));
    }

    // --------------------------------------------------------- assignment

    public function test_brands_assign_appends_and_rolls_back_to_the_exact_previous_set(): void
    {
        $acme   = $this->brand('Acme');
        $globex = $this->brand('Globex');
        $other  = $this->brand('Initech');
        $id     = $this->product();
        wp_set_object_terms($id, [$other], self::TAX);

        $out = (new Woo_Write())->handle([
            'op'     => 'brands.assign',
            'params' => ['product_id' => $id, 'brands' => [$acme, $globex]],
        ]);

        $this->assertSame(200, $out['status']);
        $this->assertTrue($out['applied']);
        $this->assertTrue($out['recoverable']);
        $expected = [$acme, $globex, $other];
        sort($expected);
        $this->assertSame($expected, $this->brands_of($id));
        $this->assertSame($expected, $out['body']['brands']);

        (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertSame([$other], $this->brands_of($id));
    }

    public function test_brands_assign_on_a_product_with_no_brands_rolls_back_to_none(): void
    {
        $acme = $this->brand('Acme');
        $id   = $this->product();

        $out = (new Woo_Write())->handle(['op' => 'brands.assign', 'params' => ['product_id' => $id, 'brands' => [$acme]]]);
        $this->assertSame([$acme], $this->brands_of($id));

        (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertSame([], $this->brands_of($id));
    }

    public function test_brands_unassign_can_remove_the_last_brand_and_rolls_back(): void
    {
        $acme = $this->brand('Acme');
        $id   = $this->product();
        wp_set_object_terms($id, [$acme], self::TAX);

        $out = (new Woo_Write())->handle(['op' => 'brands.unassign', 'params' => ['product_id' => $id, 'brands' => [$acme]]]);

        $this->assertSame(200, $out['status']);
        $this->assertSame([], $this->brands_of($id));
        $this->assertSame([], $out['body']['brands']);

        (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertSame([$acme], $this->brands_of($id));
    }

    public function test_assignment_refuses_bad_targets_before_any_write(): void
    {
        $acme     = $this->brand('Acme');
        $product  = $this->product();
        $page     = self::factory()->post->create(['post_type' => 'page']);
        $category = wp_insert_term('Mugs', 'product_cat');

        $cases = [
            'not a product'   => [['product_id' => $page, 'brands' => [$acme]], 'invalid_params'],
            'unknown brand'   => [['product_id' => $product, 'brands' => [999999]], 'unknown_brand'],
            'category id'     => [['product_id' => $product, 'brands' => [(int) $category['term_id']]], 'unknown_brand'],
            'empty list'      => [['product_id' => $product, 'brands' => []], 'invalid_params'],
            'not a list'      => [['product_id' => $product, 'brands' => 'acme'], 'invalid_params'],
            'missing product' => [['brands' => [$acme]], 'invalid_params'],
        ];

        foreach ($cases as $label => [$params, $code]) {
            $out = (new Woo_Write())->handle(['op' => 'brands.assign', 'params' => $params]);
            $this->assertSame($code, $out['error']['code'] ?? null, $label);
        }
        $this->assertSame([], $this->brands_of($product));
        $this->assertSame([], $this->brands_of($page));
    }

    public function test_assignment_requires_the_brand_assign_capability(): void
    {
        $acme = $this->brand('Acme');
        $id   = $this->product();

        $user = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($user);
        wp_get_current_user()->add_cap('assign_product_terms', false);

        $out = (new Woo_Write())->handle(['op' => 'brands.assign', 'params' => ['product_id' => $id, 'brands' => [$acme]]]);

        $this->assertSame('operation_denied', $out['error']['code']);
        $this->assertSame([], $this->brands_of($id));
    }

    public function test_a_batch_of_brand_ops_rolls_back_as_one_session(): void
    {
        $acme = $this->brand('Acme');
        $a    = $this->product('A');
        $b    = $this->product('B');

        $out = (new Woo_Write())->handle([
            'batch' => [
                ['op' => 'brands.assign', 'params' => ['product_id' => $a, 'brands' => [$acme]]],
                ['op' => 'brands.assign', 'params' => ['product_id' => $b, 'brands' => [$acme]]],
                ['op' => 'brands.update', 'params' => ['id' => $acme, 'name' => 'Acme Two']],
            ],
        ]);

        $this->assertSame(3, $out['applied']);
        (new Rollback_Session())->handle(['session_id' => $out['session_id']]);

        $this->assertSame([], $this->brands_of($a));
        $this->assertSame([], $this->brands_of($b));
        clean_term_cache($acme, self::TAX);
        $this->assertSame('Acme', get_term($acme, self::TAX)->name);
    }

    // ------------------------------------------------ taxonomy is missing

    public function test_brand_ops_skip_cleanly_when_the_brands_taxonomy_is_absent(): void
    {
        $product = $this->product();
        global $wp_taxonomies;
        $saved = $wp_taxonomies[ self::TAX ];
        unset($wp_taxonomies[ self::TAX ]);

        try {
            $write = (new Woo_Write())->handle(['op' => 'brands.assign', 'params' => ['product_id' => $product, 'brands' => [1]]]);
            $read  = (new Woo_Read())->handle(['op' => 'brands.list']);
            $ops   = (new Woo_Ops())->handle(['domain' => 'brands']);
        } finally {
            $wp_taxonomies[ self::TAX ] = $saved;
        }

        $this->assertSame('integration_unavailable', $write['error']['code']);
        $this->assertSame('integration_unavailable', $read['error']['code']);
        foreach ($ops['domains']['brands'] as $row) {
            $this->assertFalse($row['enabled'], "{$row['op']} should report disabled without the taxonomy");
        }
        // The rest of the catalog is unaffected.
        $this->assertSame(200, (new Woo_Read())->handle(['op' => 'coupons.list'])['status']);
    }
}
