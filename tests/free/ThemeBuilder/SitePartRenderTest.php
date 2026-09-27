<?php

namespace WPMCP\Tests\Free\ThemeBuilder;

use WPMCP\Tools\ThemeBuilder\Render\Block_Adapter;
use WPMCP\Tools\ThemeBuilder\Render\Classic_Adapter;
use WPMCP\Tools\ThemeBuilder\Render\Template_Renderer;
use WPMCP\Tools\ThemeBuilder\Template_Store;

/**
 * Header and footer rendering for both theme types (issue #70 acceptance
 * criterion 3), and the block-theme 404 composition.
 *
 * The adapters' hook callbacks are exercised directly rather than through
 * supports(), so both adapters are covered whichever theme the test install
 * happens to run: supports() choosing exactly one is asserted separately in
 * SitePartEngineTest.
 */
class SitePartRenderTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Template_Store::ensure_post_type();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    private function seed(string $part_type, string $content, array $conditions = ['include' => [['type' => 'entire_site']]]): int
    {
        $id = self::factory()->post->create([
            'post_type'    => Template_Store::POST_TYPE,
            'post_status'  => 'publish',
            'post_title'   => $part_type,
            'post_content' => $content,
        ]);
        update_post_meta($id, '_wpmcp_template_type', $part_type);
        update_post_meta($id, '_wpmcp_template_conditions', $conditions);
        update_post_meta($id, '_wpmcp_template_priority', 0);

        return (int) $id;
    }

    // ---- block themes: header / footer --------------------------------------

    public function test_block_adapter_hooks_template_parts_for_header_and_footer(): void
    {
        $adapter = new Block_Adapter();
        $adapter->register('header');

        $this->assertSame(10, has_filter('pre_render_block', [$adapter, 'replace_template_part']));
    }

    public function test_block_adapter_replaces_a_header_template_part_with_the_winner(): void
    {
        $this->seed('header', '<!-- wp:paragraph --><p>Custom header 70</p><!-- /wp:paragraph -->');
        $this->go_to(home_url('/'));

        $block = [
            'blockName' => 'core/template-part',
            'attrs'     => ['slug' => 'header', 'area' => 'header', 'tagName' => 'header'],
        ];
        $out = (new Block_Adapter())->replace_template_part(null, $block);

        $this->assertIsString($out);
        $this->assertStringContainsString('Custom header 70', $out);
        $this->assertStringStartsWith('<header', $out);
        $this->assertStringContainsString('wp-block-template-part', $out);
    }

    public function test_block_adapter_infers_the_area_from_the_part_slug(): void
    {
        $this->seed('footer', '<!-- wp:paragraph --><p>Custom footer 70</p><!-- /wp:paragraph -->');
        $this->go_to(home_url('/'));

        $out = (new Block_Adapter())->replace_template_part(
            null,
            ['blockName' => 'core/template-part', 'attrs' => ['slug' => 'footer']]
        );

        $this->assertStringContainsString('Custom footer 70', (string) $out);
        $this->assertStringStartsWith('<footer', (string) $out);
    }

    public function test_block_adapter_leaves_other_blocks_and_unmatched_parts_alone(): void
    {
        $this->seed('header', '<p>Custom header 70</p>', ['include' => [['type' => 'front_page']]]);
        $post = self::factory()->post->create();
        $this->go_to(get_permalink($post));

        $adapter = new Block_Adapter();
        // Not a template part at all.
        $this->assertNull($adapter->replace_template_part(null, ['blockName' => 'core/paragraph', 'attrs' => []]));
        // A header part, but no header template wins on a single post.
        $this->assertNull($adapter->replace_template_part(
            null,
            ['blockName' => 'core/template-part', 'attrs' => ['slug' => 'header', 'area' => 'header']]
        ));
        // A template part in an area this subsystem does not own.
        $this->assertNull($adapter->replace_template_part(
            null,
            ['blockName' => 'core/template-part', 'attrs' => ['slug' => 'sidebar', 'area' => 'uncategorized']]
        ));
    }

    public function test_block_adapter_respects_an_earlier_short_circuit(): void
    {
        $this->seed('header', '<p>Custom header 70</p>');
        $this->go_to(home_url('/'));

        $out = (new Block_Adapter())->replace_template_part(
            'already rendered',
            ['blockName' => 'core/template-part', 'attrs' => ['slug' => 'header', 'area' => 'header']]
        );

        $this->assertSame('already rendered', $out);
    }

    public function test_block_adapter_does_not_recurse_into_a_part_that_embeds_a_template_part(): void
    {
        // A header template that itself contains a header template part must
        // render once, not loop until the stack runs out.
        $this->seed(
            'header',
            '<!-- wp:paragraph --><p>Outer 70</p><!-- /wp:paragraph -->'
                . '<!-- wp:template-part {"slug":"header","area":"header"} /-->'
        );
        $this->go_to(home_url('/'));

        $adapter = new Block_Adapter();
        add_filter('pre_render_block', [$adapter, 'replace_template_part'], 10, 2);

        $out = (string) $adapter->replace_template_part(
            null,
            ['blockName' => 'core/template-part', 'attrs' => ['slug' => 'header', 'area' => 'header']]
        );

        $this->assertSame(1, substr_count($out, 'Outer 70'));
    }

    public function test_block_adapter_never_emits_an_unsafe_wrapper_tag(): void
    {
        $this->seed('header', '<p>Custom header 70</p>');
        $this->go_to(home_url('/'));

        $out = (string) (new Block_Adapter())->replace_template_part(
            null,
            ['blockName' => 'core/template-part', 'attrs' => ['slug' => 'header', 'area' => 'header', 'tagName' => 'script']]
        );

        $this->assertStringNotContainsString('<script', $out);
        $this->assertStringStartsWith('<header', $out);
    }

    // ---- block themes: 404 --------------------------------------------------

    public function test_block_adapter_composes_the_404_into_the_block_template_canvas(): void
    {
        global $_wp_current_template_content;
        $previous = $_wp_current_template_content;

        $this->seed('404', '<!-- wp:paragraph --><p>Lost 70</p><!-- /wp:paragraph -->', ['include' => [['type' => 'error_404']]]);
        $this->go_to(home_url('/definitely-not-a-real-url-70/'));
        $GLOBALS['wp_query']->set_404();

        $adapter = new Block_Adapter();
        $adapter->register('404');
        $out = apply_filters('template_include', ABSPATH . WPINC . '/template-canvas.php');

        $this->assertSame(ABSPATH . WPINC . '/template-canvas.php', $out);
        $this->assertStringContainsString('Lost 70', (string) $_wp_current_template_content);
        // The theme's own header and footer template parts frame the page, so
        // a header or footer site part (or the theme's own) still renders.
        $this->assertStringContainsString('"slug":"header"', (string) $_wp_current_template_content);
        $this->assertStringContainsString('"slug":"footer"', (string) $_wp_current_template_content);

        $_wp_current_template_content = $previous;
    }

    public function test_block_adapter_leaves_the_404_canvas_alone_without_a_winner(): void
    {
        global $_wp_current_template_content;
        $_wp_current_template_content = '<!-- wp:paragraph --><p>Theme 404</p><!-- /wp:paragraph -->';

        $this->go_to(home_url('/definitely-not-a-real-url-70/'));
        $GLOBALS['wp_query']->set_404();

        $adapter = new Block_Adapter();
        $adapter->register('404');
        $canvas = ABSPATH . WPINC . '/template-canvas.php';

        $this->assertSame($canvas, apply_filters('template_include', $canvas));
        $this->assertStringContainsString('Theme 404', $_wp_current_template_content);
    }

    // ---- classic themes: header / footer ------------------------------------

    public function test_classic_adapter_hooks_get_header_and_get_footer(): void
    {
        $adapter = new Classic_Adapter();
        $adapter->register('header');
        $adapter->register('footer');

        $this->assertSame(10, has_action('get_header', [$adapter, 'replace_header']));
        $this->assertSame(10, has_action('get_footer', [$adapter, 'replace_footer']));
    }

    public function test_classic_adapter_prints_a_document_head_and_the_winning_header(): void
    {
        $this->seed('header', '<!-- wp:paragraph --><p>Classic header 70</p><!-- /wp:paragraph -->');
        $this->go_to(home_url('/'));
        // Other plugins' wp_head callbacks (the suite loads Elementor) assume
        // a full front-end bootstrap; this asserts the adapter, not them.
        remove_all_actions('wp_head');
        remove_all_actions('wp_body_open');
        $head_runs = 0;
        $body_runs = 0;
        add_action('wp_head', static function () use (&$head_runs) {
            ++$head_runs;
        });
        add_action('wp_body_open', static function () use (&$body_runs) {
            ++$body_runs;
        });

        ob_start();
        (new Classic_Adapter())->replace_header(null);
        $out = (string) ob_get_clean();

        $this->assertStringContainsString('<!DOCTYPE html>', $out);
        $this->assertStringContainsString('<body', $out);
        $this->assertStringContainsString('Classic header 70', $out);
        // The callbacks ran once, for the document this adapter opened; the
        // theme's header.php (if any) runs into a discarded buffer with them
        // removed, so they cannot run a second time.
        $this->assertSame(1, $head_runs);
        $this->assertSame(1, $body_runs);
        $this->assertFalse(has_action('wp_head'));
    }

    public function test_classic_adapter_prints_the_winning_footer_and_closes_the_document(): void
    {
        $this->seed('footer', '<!-- wp:paragraph --><p>Classic footer 70</p><!-- /wp:paragraph -->');
        $this->go_to(home_url('/'));
        // Core's own wp_footer callbacks include deprecated block-theme shims
        // that the suite reports as unexpected notices; not under test here.
        remove_all_actions('wp_footer');
        $footer_runs = 0;
        add_action('wp_footer', static function () use (&$footer_runs) {
            ++$footer_runs;
        });

        ob_start();
        (new Classic_Adapter())->replace_footer(null);
        $out = (string) ob_get_clean();

        $this->assertStringContainsString('Classic footer 70', $out);
        $this->assertStringContainsString('</body>', $out);
        $this->assertStringContainsString('</html>', $out);
        $this->assertSame(1, $footer_runs);
        $this->assertFalse(has_action('wp_footer'));
    }

    public function test_classic_adapter_prints_nothing_when_no_header_wins(): void
    {
        $this->seed('header', '<p>Front only 70</p>', ['include' => [['type' => 'front_page']]]);
        $post = self::factory()->post->create();
        $this->go_to(get_permalink($post));

        ob_start();
        (new Classic_Adapter())->replace_header(null);
        $out = (string) ob_get_clean();

        $this->assertSame('', $out);
        $this->assertSame(0, did_action('wp_head'));
    }

    // ---- shared -------------------------------------------------------------

    public function test_winner_reports_the_live_request_winner_or_null(): void
    {
        $id = $this->seed('header', '<p>h</p>', ['include' => [['type' => 'front_page']]]);

        $this->go_to(home_url('/'));
        $this->assertSame($id, Template_Renderer::winner('header')['template_id'] ?? null);

        $post = self::factory()->post->create();
        $this->go_to(get_permalink($post));
        $this->assertNull(Template_Renderer::winner('header'));
    }
}
