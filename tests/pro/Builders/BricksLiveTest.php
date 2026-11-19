<?php

namespace WPMCP\Tests\Pro\Builders;

use WPMCP\Pro\Gate;
use WPMCP\Safety\{Rollback_Service, Snapshot_Store};
use WPMCP\Tools\Builders\{Bricks_Cache, Get_Builder_Content, Update_Builder_Content};

/**
 * Issue #914: the Bricks page write against the real Bricks theme.
 *
 * It runs only in the local gate's live Bricks leg (bin/test-local.sh
 * --live-bricks), which sets WPMCP_LIVE_BRICKS=1, copies Bricks from
 * WPMCP_BRICKS_SRC onto a separate WordPress install (Bricks is a commercial
 * theme, not on wordpress.org) and makes it the active theme in
 * tests/bootstrap.php.
 *
 * A page last saved in WordPress mode must be switched to Bricks mode by the
 * write so Bricks renders it, its external CSS file must be rebuilt by Bricks'
 * own generator, and undo must put back the exact layout, the editor mode and
 * the CSS file.
 *
 * Issue #633: element operations against the real theme. An element added
 * by id is rendered by Bricks and styled in its rebuilt CSS file, a section
 * template saved from a page is a real Bricks template, and undo restores
 * both.
 *
 * Everywhere else Bricks is absent and the test is skipped; the live leg runs
 * with --fail-on-skipped so it cannot pass vacuously.
 *
 * @group bricks-live
 */
class BricksLiveTest extends \WP_UnitTestCase
{
    private const OLD = [
        ['id' => 'aaaaaa', 'name' => 'section', 'parent' => 0, 'children' => ['bbbbbb'], 'settings' => []],
        ['id' => 'bbbbbb', 'name' => 'heading', 'parent' => 'aaaaaa', 'children' => [], 'settings' => ['text' => 'Old heading', '_cssId' => 'old-heading']],
    ];

    private const NEW = [
        ['id' => 'cccccc', 'name' => 'section', 'parent' => 0, 'children' => ['dddddd'], 'settings' => []],
        ['id' => 'dddddd', 'name' => 'heading', 'parent' => 'cccccc', 'children' => [], 'settings' => ['text' => 'New heading', '_cssId' => 'new-heading', '_typography' => ['color' => ['hex' => '#ff0000']]]],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        if (! defined('BRICKS_VERSION') || ! class_exists('Bricks\\Assets_Files') || ! Bricks_Cache::plugin_active()) {
            $this->markTestSkipped('Needs the real Bricks theme (bin/test-local.sh --live-bricks, WPMCP_BRICKS_SRC).');
        }
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        // External CSS files, the mode whose files go stale.
        \Bricks\Database::$global_settings['cssLoading'] = 'file';
    }

    protected function tearDown(): void
    {
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function css_file(int $post_id): string
    {
        $path = \Bricks\Assets::$css_dir . '/post-' . $post_id . '.min.css';

        return is_readable($path) ? (string) file_get_contents($path) : '';
    }

    public function test_a_written_page_is_rendered_by_bricks_with_fresh_css_and_undo_restores_it(): void
    {
        $id = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'publish']);
        update_post_meta($id, '_bricks_page_content_2', self::OLD);
        update_post_meta($id, '_bricks_editor_mode', 'wordpress');
        $this->assertFalse(\Bricks\Helpers::render_with_bricks($id), 'precondition: a WordPress mode page is not rendered by Bricks');
        $before_css = $this->css_file($id);

        $out = (new Update_Builder_Content())->handle([
            'post_id'    => $id,
            'builder'    => 'bricks',
            'content'    => wp_json_encode(self::NEW),
            'session_id' => $session = wp_generate_uuid4(),
        ]);
        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_code() . ': ' . $out->get_error_message() : '');

        $this->assertSame('bricks', \Bricks\Helpers::get_editor_mode($id));
        $this->assertTrue(\Bricks\Helpers::render_with_bricks($id));
        $css = $this->css_file($id);
        $this->assertNotSame('', $css, 'Bricks rebuilt the page CSS file');
        $this->assertStringContainsString('new-heading', $css);
        $this->assertStringNotContainsString('old-heading', $css);

        Rollback_Service::restore_session($session);

        $this->assertSame('wordpress', \Bricks\Helpers::get_editor_mode($id));
        $this->assertSame(self::OLD, get_post_meta($id, '_bricks_page_content_2', true));
        $this->assertStringNotContainsString('new-heading', $this->css_file($id));
        unset($before_css);
    }

    public function test_inline_css_loading_writes_the_page_without_a_css_file(): void
    {
        \Bricks\Database::$global_settings['cssLoading'] = 'inline';
        $id = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'publish']);

        $out = (new Update_Builder_Content())->handle(['post_id' => $id, 'builder' => 'bricks', 'content' => wp_json_encode(self::NEW)]);
        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_code() . ': ' . $out->get_error_message() : '');

        $this->assertTrue(\Bricks\Helpers::render_with_bricks($id));
        $this->assertSame('', $this->css_file($id), 'inline CSS loading keeps no page CSS file');
    }

    public function test_an_added_element_is_rendered_and_styled_by_bricks_and_undo_removes_it(): void
    {
        $id = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'publish']);
        update_post_meta($id, '_bricks_page_content_2', self::OLD);
        update_post_meta($id, '_bricks_editor_mode', 'bricks');
        $read = (new Get_Builder_Content())->handle(['post_id' => $id]);
        $this->assertSame(BRICKS_VERSION, $read['plugin_version']);
        $this->assertArrayNotHasKey('version_note', $read);

        $out = (new Update_Builder_Content())->handle([
            'post_id'       => $id,
            'builder'       => 'bricks',
            'operation'     => 'add',
            'to'            => 'aaaaaa',
            'element'       => ['name' => 'heading', 'settings' => ['text' => 'Added by id', '_cssId' => 'added-heading', '_typography' => ['color' => ['hex' => '#00ff00']]]],
            'expected_hash' => $read['hash'],
        ]);
        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_code() . ': ' . $out->get_error_message() : '');

        $html = (string) \Bricks\Frontend::render_data(get_post_meta($id, '_bricks_page_content_2', true));
        $this->assertStringContainsString('Added by id', $html);
        $this->assertStringContainsString('Old heading', $html);
        $this->assertStringContainsString('added-heading', $this->css_file($id));

        $saved = (new Update_Builder_Content())->handle(['post_id' => $id, 'builder' => 'bricks', 'operation' => 'save_template', 'path' => $out['path'], 'element' => ['title' => 'Live template']]);
        $this->assertIsArray($saved, is_wp_error($saved) ? $saved->get_error_code() . ': ' . $saved->get_error_message() : '');
        $this->assertContains($saved['template_id'], array_map('intval', (array) \Bricks\Templates::get_templates_by_type('section')));

        Rollback_Service::restore_operation($saved['operation_id']);
        Rollback_Service::restore_operation($out['operation_id']);

        $this->assertNotSame('publish', get_post_status($saved['template_id']));
        $this->assertSame(self::OLD, get_post_meta($id, '_bricks_page_content_2', true));
        $this->assertStringNotContainsString('added-heading', $this->css_file($id));
    }

    public function test_a_post_type_bricks_does_not_edit_is_refused(): void
    {
        $id  = self::factory()->post->create(['post_type' => 'post']);
        $out = (new Update_Builder_Content())->handle(['post_id' => $id, 'builder' => 'bricks', 'content' => wp_json_encode(self::NEW)]);

        $this->assertWPError($out);
        $this->assertSame('not_a_bricks_page', $out->get_error_code());
    }
}
