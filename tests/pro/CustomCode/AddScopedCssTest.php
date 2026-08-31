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

    /**
     * The wrap is "selector { declarations }", so a declarations string that
     * closes the block itself escapes the scope the ability advertises. A
     * stray '}' already failed the sanitizer's brace COUNT, but as
     * "unbalanced braces" - an error about the CSS rather than about the
     * contract the caller broke, which is the one thing it needed to say.
     * Both brace characters are now named by the same guard.
     */
    public function test_rejects_a_brace_of_either_kind_in_declarations(): void
    {
        $post = self::factory()->post->create();

        foreach (
            [
                'color: red; } .evil { position: fixed; top: 0; left: 0 ',
                'color: red; } .evil',
            ] as $declarations
        ) {
            try {
                $this->store($post, $declarations, ['selector' => '.a']);
                $this->fail('Declarations that escape the selector scope should have been rejected.');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('bare declarations without braces', $e->getMessage());
            }
        }

        $this->assertSame('', Custom_Code_Store::read_css($post));
    }

    /**
     * The end-to-end shape of the stored-XSS threat model: whatever the write
     * path accepted, the bytes the renderer prints inside <style> must not
     * contain a </style> the HTML tokenizer would honour. A payload parked
     * inside a CSS comment is invisible to the CSS parser and to a canonical
     * form built by stripping comments, but not to the tokenizer.
     */
    public function test_no_accepted_css_can_close_the_style_element(): void
    {
        $post = self::factory()->post->create();

        foreach (
            [
                '.a { color: red; } /* </style><script>alert(1)</script> */',
                '.a{content:"/*"}</style><script>alert(1)</script>.b{content:"*/"}',
            ] as $payload
        ) {
            $rejected = false;
            try {
                $this->store($post, $payload);
            } catch (\InvalidArgumentException $e) {
                $rejected = true;
            }

            $this->go_to(get_permalink($post));
            ob_start();
            Custom_Code_Renderer::print_css();
            $printed = (string) ob_get_clean();

            // Rejecting on write is the expected outcome, but the assertion
            // that matters is about the bytes: however the payload was
            // handled, the printed element must close exactly once, at its
            // own </style>.
            $this->assertTrue($rejected, 'The payload reached storage: ' . $payload);
            $this->assertLessThanOrEqual(
                1,
                preg_match_all('#</\s*style#i', $printed),
                'A stored block closed the <style> element early: ' . $printed
            );
        }
    }

    /**
     * The issue's first acceptance criterion asks for ELEMENT-level CSS as
     * well as page-level. An element_id scopes the declarations to one
     * Elementor element on that page by prefixing the Elementor class the
     * builder already renders on it, so the block is still a plain stylesheet
     * in this plugin's own store: no builder settings are written, which
     * keeps the write inside the one-option-per-rolled-back-block model and
     * out of a builder's postmeta that another tool may be mid-edit on.
     */
    public function test_element_scope_wraps_the_declarations_in_the_element_selector(): void
    {
        $post = self::factory()->post->create();

        $out = $this->store($post, 'color: red;', ['element_id' => 'a1b2c3d']);

        $this->assertSame('element', $out['scope']);
        $this->assertSame('a1b2c3d', $out['element_id']);
        $this->assertSame('.elementor-element-a1b2c3d { color: red; }', Custom_Code_Store::read_css($post));
    }

    /** An element_id plus a selector reads as a descendant of the element. */
    public function test_element_scope_composes_with_a_selector(): void
    {
        $post = self::factory()->post->create();

        $this->store($post, 'color: red;', ['element_id' => 'a1b2c3d', 'selector' => 'h2']);

        $this->assertSame('.elementor-element-a1b2c3d h2 { color: red; }', Custom_Code_Store::read_css($post));
    }

    /**
     * Element-scoped CSS has to RENDER, not just persist; and it renders on
     * the page it was scoped to, through the same wp_head path page-scoped
     * CSS uses.
     */
    public function test_element_scoped_css_renders_on_that_page(): void
    {
        $post = self::factory()->post->create();
        $this->store($post, 'color: red;', ['element_id' => 'a1b2c3d']);

        $this->go_to(get_permalink($post));
        ob_start();
        Custom_Code_Renderer::print_css();
        $printed = (string) ob_get_clean();

        $this->assertStringContainsString('.elementor-element-a1b2c3d { color: red; }', $printed);
    }

    /**
     * The element id goes into a selector, so it is an injection point: it is
     * allowlisted to the alphabet Elementor actually issues rather than being
     * escaped after the fact.
     */
    public function test_rejects_an_element_id_outside_the_allowed_alphabet(): void
    {
        $post = self::factory()->post->create();

        foreach (['a1b2c3d { } .evil', 'a1b2c3d"', '../x', ''] as $element_id) {
            try {
                $this->store($post, 'color: red;', ['element_id' => $element_id]);
                $this->fail('Expected a refusal for element_id ' . var_export($element_id, true));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('element_id', $e->getMessage());
            }
        }

        $this->assertSame('', Custom_Code_Store::read_css($post));
    }

    /**
     * With an element_id the css is bare declarations, exactly as it is with
     * a selector: a '}' in it would close the element block and let the rest
     * apply to the whole page.
     */
    public function test_element_scope_refuses_declarations_carrying_a_brace(): void
    {
        $post = self::factory()->post->create();

        $this->expectException(\InvalidArgumentException::class);
        $this->store($post, 'color: red; } .evil { position: fixed', ['element_id' => 'a1b2c3d']);
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
