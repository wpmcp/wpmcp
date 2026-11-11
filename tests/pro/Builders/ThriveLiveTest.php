<?php

namespace WPMCP\Tests\Pro\Builders;

use WPMCP\Pro\Gate;
use WPMCP\Safety\{Rollback_Service, Snapshot_Store};
use WPMCP\Tools\Builders\{Detect_Builder, Get_Builder_Content, Thrive_Content, Update_Builder_Content};

/**
 * Issue #640: the Thrive Architect adapter against a real Thrive Architect.
 *
 * It runs only in the local gate's live Thrive leg
 * (bin/test-local.sh --live-thrive), which sets WPMCP_LIVE_THRIVE=1,
 * copies the plugin from WPMCP_THRIVE_ARCHITECT_SRC onto a separate
 * WordPress install (it is not on wordpress.org) and loads it in
 * tests/bootstrap.php.
 *
 * Pages are saved through Thrive's own save pipeline
 * (TCB_Editor_Ajax::save_post_content(), what the editor's Save calls), so
 * the rows, revisions and plain-text copy are Thrive's. The catalog must
 * come from the real element registry; an edited page must still render
 * through Thrive; a revision Thrive made must restore; a template saved here
 * must be one Thrive's own model loads; and every write must roll back.
 *
 * Everywhere else Thrive Architect is absent and the test is skipped; the
 * live leg runs with --fail-on-skipped so it cannot pass vacuously.
 *
 * @group thrive-live
 */
class ThriveLiveTest extends \WP_UnitTestCase
{
    private const TEXT = '<div class="thrv_wrapper thrv_text_element" data-css="tve-u-1800000000a"><p>Live text</p></div>';

    private const BUTTON = '<div class="thrv_wrapper thrv-button" data-css="tve-u-1800000000b"><a href="#" class="tcb-button-link"><span class="tcb-button-texts"><span class="tcb-button-text thrv-inline-text">Go</span></span></a></div>';

    private const CSS = '@media (min-width: 300px){[data-css="tve-u-1800000000b"] .tcb-button-link{background-color:rgb(7, 7, 7) !important;}}';

    protected function setUp(): void
    {
        parent::setUp();
        if (! defined('TVE_VERSION') || ! function_exists('tcb_elements') || ! class_exists('TCB_Editor_Ajax')) {
            $this->markTestSkipped('Thrive Architect is not loaded; run bin/test-local.sh --live-thrive with WPMCP_THRIVE_ARCHITECT_SRC set.');
        }
        Gate::set_pro_for_tests(true);
        Snapshot_Store::install();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    protected function tearDown(): void
    {
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    /** Save a layout the way the editor's Save button does. */
    private function thrive_save(int $post_id, string $layout, string $css = ''): void
    {
        $result = \TCB_Editor_Ajax::save_post_content($post_id, wp_slash([
            'update'               => 'true',
            'tve_content'          => $layout,
            'tve_stripped_content' => wp_strip_all_tags($layout),
            'inline_rules'         => $css,
            'tve_custom_css'       => '',
            'tve_landing_page'     => '',
            'custom_font_classes'  => [],
            'tve_globals'          => [],
        ]));
        $this->assertTrue($result['success'], wp_json_encode($result));
    }

    private function thrive_page(string $layout = self::TEXT . self::BUTTON, string $css = self::CSS): int
    {
        $post_id = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'publish', 'post_content' => '']);
        // Opening a page without Thrive content in the editor marks it ready
        // (tve_editor_content()); the first save then enables the editor.
        tcb_post($post_id)->meta('tcb2_ready', 1);
        $this->thrive_save($post_id, $layout, $css);

        return $post_id;
    }

    private function update(int $post_id, array $args)
    {
        if (! array_key_exists('expected_hash', $args)) {
            $args['expected_hash'] = hash('sha256', Thrive_Content::get_content($post_id));
        }

        return (new Update_Builder_Content())->handle(['post_id' => $post_id, 'builder' => 'thrive'] + $args);
    }

    private function state(int $post_id): array
    {
        clean_post_cache($post_id);
        return [get_post($post_id)->post_status, get_post($post_id)->post_content, get_post_meta($post_id)];
    }

    /** What Thrive renders for the page's content area. */
    private function render(int $post_id): string
    {
        $GLOBALS['post'] = get_post($post_id);
        setup_postdata($GLOBALS['post']);
        $html = (string) tve_editor_content('');
        wp_reset_postdata();

        return $html;
    }

    public function test_catalog_comes_from_the_real_element_registry(): void
    {
        $catalog = (new Get_Builder_Content())->handle(['scope' => 'catalog', 'builder' => 'thrive']);

        $this->assertSame(TVE_VERSION, $catalog['plugin_version']);
        $tags = array_column($catalog['elements'], 'tag');
        foreach (['text', 'image', 'button', 'section', 'contentbox', 'columns'] as $tag) {
            $this->assertContains($tag, $tags);
        }

        $text = (new Get_Builder_Content())->handle(['scope' => 'catalog', 'builder' => 'thrive', 'element' => 'text']);
        $this->assertStringContainsString('thrv_text_element', $text['element']['layout']);
        $this->assertNotEmpty($text['element']['components']);
        $this->assertSame('element_not_found', (new Get_Builder_Content())->handle(['scope' => 'catalog', 'builder' => 'thrive', 'element' => 'nope'])->get_error_code());
    }

    public function test_a_page_thrive_saved_is_edited_and_still_renders_through_thrive(): void
    {
        $post_id = $this->thrive_page();
        $this->assertSame('thrive', (new Detect_Builder())->handle(['post_id' => $post_id])['builder']);
        $read = (new Get_Builder_Content())->handle(['post_id' => $post_id]);
        $this->assertSame(TVE_VERSION, $read['plugin_version']);
        $this->assertSame('text', $read['tree'][0]['type']);
        $before = $this->state($post_id);

        $out = $this->update($post_id, ['operation' => 'update', 'path' => '0', 'text' => '<p>Edited here</p>', 'expected_hash' => $read['hash']]);

        $this->assertStringContainsString('Edited here', $this->render($post_id));
        $this->assertStringContainsString('Edited here', get_post($post_id)->post_content, 'Thrive refreshed its plain-text copy');

        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertSame($before, $this->state($post_id));
        $this->assertStringContainsString('Live text', $this->render($post_id));
    }

    public function test_a_revision_thrive_made_restores_and_rolls_back(): void
    {
        $post_id = $this->thrive_page(self::TEXT, '');
        $this->thrive_save($post_id, self::TEXT . self::BUTTON, self::CSS);

        $revisions = (new Get_Builder_Content())->handle(['post_id' => $post_id])['revisions'];
        $first     = array_values(array_filter($revisions, static fn ($r) => hash('sha256', self::TEXT) === $r['hash']));
        $this->assertNotEmpty($first, 'Thrive copied the first layout onto a revision');
        $before = $this->state($post_id);

        $out = $this->update($post_id, ['operation' => 'restore', 'path' => (string) $first[0]['id']]);

        $this->assertSame(self::TEXT, Thrive_Content::get_content($post_id));
        $this->assertStringNotContainsString('Go', $this->render($post_id));

        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertSame($before, $this->state($post_id));
    }

    public function test_a_saved_template_loads_through_thrive_and_inserts_into_a_page(): void
    {
        $post_id = $this->thrive_page();

        $saved = $this->update($post_id, ['operation' => 'save_template', 'path' => '1', 'element' => ['title' => 'Live button']]);
        $this->assertIsArray($saved, is_wp_error($saved) ? $saved->get_error_message() : '');

        $loaded = \TCB\UserTemplates\Template::get_instance_with_id($saved['template_id'])->load();
        $this->assertSame(self::BUTTON, $loaded['html_code']);
        $this->assertStringContainsString('rgb(7, 7, 7)', $loaded['media_css']['(min-width: 300px)']);
        $this->assertContains((string) $saved['template_id'], array_map('strval', array_column(\TCB\UserTemplates\Template::get_all(), 'id')));

        $before = $this->state($post_id);
        $add    = $this->update($post_id, ['operation' => 'add', 'to' => '', 'index' => 0, 'element' => ['template' => (string) $saved['template_id']]]);
        $this->assertSame('0', $add['path']);
        $html = $this->render($post_id);
        $this->assertSame(2, substr_count($html, 'thrv-button'));

        Rollback_Service::restore_operation($add['operation_id']);
        $this->assertSame($before, $this->state($post_id));
        Rollback_Service::restore_operation($saved['operation_id']);
        $this->assertNotSame('publish', get_post_status($saved['template_id']));
    }
}
