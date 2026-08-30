<?php

namespace WPMCP\Tests\Pro\CustomCode;

use WPMCP\Safety\Rollback_Service;
use WPMCP\Tools\CustomCode\Add_Scoped_Css;
use WPMCP\Tools\CustomCode\Custom_Code_Renderer;
use WPMCP\Tools\CustomCode\Custom_Code_Store;

/**
 * add-scoped-css (issue #63): the write path, the append/replace contract,
 * the capability bar, and - the reason the store is shaped the way it is -
 * rollback isolation between pages.
 */
class AddScopedCssTest extends \WP_UnitTestCase
{
    private Add_Scoped_Css $tool;

    public function set_up(): void
    {
        parent::set_up();
        $this->tool = new Add_Scoped_Css();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    private function store(int $post_id, string $css, array $extra = []): array
    {
        return $this->tool->handle(array_merge(
            ['css' => $css, 'post_id' => $post_id, 'session_id' => 'sess'],
            $extra
        ));
    }

    public function test_stores_sanitized_css_for_the_post(): void
    {
        $post = self::factory()->post->create();

        $out = $this->store($post, '.hero { color: red; }');

        $this->assertSame('post', $out['scope']);
        $this->assertSame($post, $out['post_id']);
        $this->assertTrue($out['recoverable']);
        $this->assertSame('.hero { color: red; }', Custom_Code_Store::read_css($post));
    }

    public function test_selector_and_declarations_are_wrapped(): void
    {
        $post = self::factory()->post->create();

        $this->store($post, 'color: red;', ['selector' => '.hero > h1']);

        $this->assertSame('.hero > h1 { color: red; }', Custom_Code_Store::read_css($post));
    }

    /**
     * The ability is named "add-", and its sibling wpmcp/add-custom-css
     * appends by default. A second call that silently discarded the first
     * block would give the agent no way to know it had destroyed work.
     */
    public function test_second_call_appends_by_default(): void
    {
        $post = self::factory()->post->create();

        $this->store($post, '.a { color: red; }');
        $out = $this->store($post, '.b { color: blue; }');

        $this->assertStringContainsString('.a { color: red; }', $out['css']);
        $this->assertStringContainsString('.b { color: blue; }', $out['css']);
        $this->assertSame($out['css'], Custom_Code_Store::read_css($post));
    }

    public function test_replace_flag_overwrites_the_block(): void
    {
        $post = self::factory()->post->create();

        $this->store($post, '.a { color: red; }');
        $out = $this->store($post, '.b { color: blue; }', ['replace' => true]);

        $this->assertSame('.b { color: blue; }', $out['css']);
        $this->assertStringNotContainsString('.a', Custom_Code_Store::read_css($post));
    }

    /**
     * The blocker this store's shape exists to prevent. With every block in
     * one shared option, the snapshot for write #1 is a before-image of the
     * WHOLE store, so rolling it back would delete post B's CSS (and the JS
     * snippet) as collateral while the tool response for #1 promised a
     * recoverable, post-scoped change.
     */
    public function test_rollback_of_one_page_leaves_other_pages_untouched(): void
    {
        $post_a = self::factory()->post->create();
        $post_b = self::factory()->post->create();

        $first = $this->store($post_a, '.a { color: red; }');
        $this->store($post_b, '.b { color: blue; }');

        $this->assertTrue(Rollback_Service::restore_operation($first['operation_id']));

        $this->assertSame('', Custom_Code_Store::read_css($post_a));
        $this->assertSame('.b { color: blue; }', Custom_Code_Store::read_css($post_b));
    }

    public function test_rollback_restores_the_previous_block_not_an_empty_one(): void
    {
        $post = self::factory()->post->create();

        $this->store($post, '.a { color: red; }');
        $second = $this->store($post, '.b { color: blue; }');

        $this->assertTrue(Rollback_Service::restore_operation($second['operation_id']));
        $this->assertSame('.a { color: red; }', Custom_Code_Store::read_css($post));
    }

    public function test_rejects_a_script_capable_payload_before_writing(): void
    {
        $post = self::factory()->post->create();

        try {
            $this->store($post, '@im\\port url("//evil.example/x.css");');
            $this->fail('An escape-obfuscated @import should have been rejected.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('', Custom_Code_Store::read_css($post));
        }
    }

    public function test_requires_an_existing_post(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->store(999999, '.a { color: red; }');
    }

    /**
     * WordPress treats authoring a stylesheet as an unfiltered_html-class
     * action (core maps edit_css onto it, and multisite strips it from
     * everyone but super admins), so manage_options alone is not the bar.
     */
    public function test_refuses_a_caller_without_edit_css(): void
    {
        $post = self::factory()->post->create();
        $deny = function ($caps, $cap) {
            return 'edit_css' === $cap ? ['do_not_allow'] : $caps;
        };
        add_filter('map_meta_cap', $deny, 10, 2);

        try {
            $this->expectException(\RuntimeException::class);
            $this->store($post, '.a { color: red; }');
        } finally {
            remove_filter('map_meta_cap', $deny, 10);
        }
    }

    /**
     * get_queried_object_id() returns a term_id on a taxonomy archive and a
     * user ID on an author archive, and those ids share an integer space with
     * post ids, so scoping on it alone printed post N's CSS on unrelated
     * archives that happened to have id N.
     */
    public function test_css_prints_on_the_post_and_not_on_a_same_id_archive(): void
    {
        $post = self::factory()->post->create();
        $this->store($post, '.only-here { color: red; }');

        $this->go_to(get_permalink($post));
        ob_start();
        Custom_Code_Renderer::print_css();
        $singular = (string) ob_get_clean();
        $this->assertStringContainsString('.only-here', $singular);

        // Force a non-singular request whose queried object id collides with
        // the post id, which is exactly the archive case.
        $term = self::factory()->category->create();
        $this->go_to(get_term_link($term, 'category'));
        $GLOBALS['wp_query']->queried_object    = get_term($term);
        $GLOBALS['wp_query']->queried_object_id = $post;

        ob_start();
        Custom_Code_Renderer::print_css();
        $archive = (string) ob_get_clean();
        $this->assertSame('', $archive, 'Page-scoped CSS leaked onto an archive with a colliding object id.');
    }
}
