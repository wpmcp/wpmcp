<?php

namespace WPMCP\Tests\Pro\SEO;

use WPMCP\Pro\Gate;
use WPMCP\Tools\SEO\SEO_Adapter;
use WPMCP\Tools\SEO\Term_SEO;
use WPMCP\Tools\SEO\Update_Term_SEO_Meta;

require_once __DIR__ . '/../../support/aioseo-tables.php';

/**
 * The free All in One SEO creates only `aioseo_posts`; term SEO lives in
 * `aioseo_terms`, which ships with the paid plugin. Without that table term
 * SEO answers "unsupported" and writes nothing, rather than failing.
 */
class AioseoWithoutTermsTableTest extends \WP_UnitTestCase
{
    public static function wpSetUpBeforeClass(): void
    {
        wpmcp_test_create_aioseo_tables(false);
    }

    public static function wpTearDownAfterClass(): void
    {
        wpmcp_test_drop_aioseo_tables();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
        SEO_Adapter::set_active_plugin_for_tests('aioseo');
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    protected function tearDown(): void
    {
        SEO_Adapter::set_active_plugin_for_tests(null);
        Gate::set_pro_for_tests(null);
        wp_set_current_user(0);
        parent::tearDown();
    }

    public function test_terms_answer_unsupported_and_write_nothing(): void
    {
        $term = get_term(self::factory()->category->create(), 'category');

        $this->assertSame([], Term_SEO::supported_fields());
        $this->assertFalse(Term_SEO::get($term)['supported']);

        $out = (new Update_Term_SEO_Meta())->handle(['taxonomy' => 'category', 'term_id' => $term->term_id, 'title' => 'x']);
        $this->assertFalse($out['supported']);
        $this->assertSame(['title'], $out['skipped_fields']);
        $this->assertArrayNotHasKey('operation_id', $out);
    }

    public function test_posts_still_work(): void
    {
        $post_id = self::factory()->post->create();

        SEO_Adapter::update_meta($post_id, ['title' => 'Post side']);

        $this->assertSame('Post side', SEO_Adapter::get_meta($post_id)['title']);
    }
}
