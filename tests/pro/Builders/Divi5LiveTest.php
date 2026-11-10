<?php

namespace WPMCP\Tests\Pro\Builders;

use WPMCP\Pro\Gate;
use WPMCP\Safety\{Rollback_Service, Snapshot_Store};
use WPMCP\Tools\Builders\{Detect_Builder, Divi_Content, Get_Builder_Content, Update_Builder_Content};

/**
 * Issue #636: the Divi 5 adapter against the real Divi 5 theme.
 *
 * It runs only in the local gate's live Divi leg (bin/test-local.sh
 * --live-divi), which sets WPMCP_LIVE_DIVI=1, copies the Divi theme from
 * WPMCP_DIVI_SRC onto a separate WordPress install (Divi is a commercial
 * theme, not on wordpress.org) and makes it the active theme in
 * tests/bootstrap.php.
 *
 * A page stored the way Divi 5's own save stores it must be detected, read
 * and edited element by element, must still parse and render through Divi
 * after the edit, and rollback must put back the exact layout. A page wpmcp
 * builds from nothing must be one Divi renders too, and the catalog must
 * come from Divi's own module metadata.
 *
 * Everywhere else Divi is absent and the test is skipped; the live leg runs
 * with --fail-on-skipped so it cannot pass vacuously.
 *
 * @group divi-live
 */
class Divi5LiveTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! function_exists('et_builder_d5_enabled') || ! defined('ET_BUILDER_VERSION') || ! Divi_Content::divi5_active()) {
            $this->markTestSkipped('Needs the real Divi 5 theme (bin/test-local.sh --live-divi, WPMCP_DIVI_SRC).');
        }
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    protected function tearDown(): void
    {
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    /** A page stored the way Divi 5's own save (SyncToServerController::update) stores it. */
    private function divi_page(string $layout): int
    {
        $id = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'publish']);
        wp_update_post(['ID' => $id, 'post_content' => wp_slash($layout)]);
        update_post_meta($id, '_et_pb_use_divi_5', 'on');
        update_post_meta($id, '_et_pb_use_builder', 'on');

        return $id;
    }

    /** The page as Divi renders it on the front end. */
    private function rendered(int $post_id): string
    {
        $post            = get_post($post_id);
        $GLOBALS['post'] = $post;
        setup_postdata($post);

        return (string) apply_filters('the_content', $post->post_content);
    }

    public function test_a_page_saved_by_divi_is_detected_read_edited_and_still_renders(): void
    {
        $layout = str_replace('5.18.0', (string) ET_BUILDER_VERSION, Divi5BlocksTest::FIXTURE);
        $id     = $this->divi_page($layout);
        $stored = get_post($id)->post_content;
        $this->assertStringContainsString('Welcome', $this->rendered($id));

        $this->assertSame('divi', (new Detect_Builder())->handle(['post_id' => $id])['builder']);

        $read = (new Get_Builder_Content())->handle(['post_id' => $id]);
        $this->assertSame('divi5', $read['format']);
        $this->assertTrue($read['plugin_active']);
        $this->assertSame((string) ET_BUILDER_VERSION, $read['plugin_version']);
        $this->assertSame('divi/heading', $read['tree'][0]['children'][0]['children'][0]['children'][0]['name']);

        $session = wp_generate_uuid4();
        $out     = (new Update_Builder_Content())->handle([
            'post_id'       => $id,
            'builder'       => 'divi',
            'operation'     => 'update',
            'path'          => '0.0.0.0',
            'text'          => 'Edited through wpmcp',
            'expected_hash' => $read['hash'],
            'session_id'    => $session,
        ]);
        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');

        $out = (new Update_Builder_Content())->handle([
            'post_id'       => $id,
            'builder'       => 'divi',
            'operation'     => 'add',
            'to'            => '0.0.1',
            'element'       => ['name' => 'text', 'text' => '<p>Added by wpmcp</p>'],
            'expected_hash' => $out['hash'],
            'session_id'    => $session,
        ]);
        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');

        // Divi's own block parser reads the edited layout.
        $blocks = parse_blocks(get_post($id)->post_content);
        $this->assertSame('divi/placeholder', $blocks[0]['blockName']);
        $html = $this->rendered($id);
        // Rendered by Divi's modules, not passed through as block comments.
        $this->assertStringNotContainsString('wp:divi/', $html);
        $this->assertStringContainsString('et_pb_text', $html);
        $this->assertStringContainsString('Edited through wpmcp', $html);
        $this->assertStringContainsString('Added by wpmcp', $html);
        $this->assertStringContainsString('C:\\Sites', $html);
        $this->assertStringNotContainsString('Welcome', $html);

        Rollback_Service::restore_session($session);

        $this->assertSame($stored, get_post($id)->post_content);
        $html = $this->rendered($id);
        $this->assertStringContainsString('Welcome', $html);
        $this->assertStringNotContainsString('Added by wpmcp', $html);
    }

    public function test_a_page_wpmcp_builds_is_one_divi_renders(): void
    {
        $id = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'publish', 'post_content' => '']);

        $out = (new Update_Builder_Content())->handle([
            'post_id'   => $id,
            'builder'   => 'divi',
            'operation' => 'add',
            'to'        => '',
            'element'   => ['name' => 'section', 'children' => [['name' => 'row', 'children' => [['name' => 'column', 'children' => [
                ['name' => 'heading', 'text' => 'Built by wpmcp'],
                ['name' => 'button', 'attrs' => ['button' => ['innerContent' => ['desktop' => ['value' => ['text' => 'Go', 'linkUrl' => 'https://example.com/']]]]]],
            ]]]]]],
        ]);
        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');

        $this->assertSame('divi', (new Detect_Builder())->handle(['post_id' => $id])['builder']);
        $this->assertSame('on', get_post_meta($id, '_et_pb_use_divi_5', true));
        $html = $this->rendered($id);
        $this->assertStringNotContainsString('wp:divi/', $html);
        $this->assertStringContainsString('et_pb_button', $html);
        $this->assertStringContainsString('Built by wpmcp', $html);
        $this->assertStringContainsString('https://example.com/', $html);
    }

    public function test_the_catalog_comes_from_divis_module_metadata(): void
    {
        $list = (new Get_Builder_Content())->handle(['builder' => 'divi', 'scope' => 'catalog']);
        $this->assertIsArray($list, is_wp_error($list) ? $list->get_error_message() : '');
        $names = array_column($list['elements'], 'name');
        $this->assertContains('divi/text', $names);
        $this->assertContains('divi/section', $names);

        $text = (new Get_Builder_Content())->handle(['builder' => 'divi', 'scope' => 'catalog', 'element' => 'text']);
        $this->assertArrayHasKey('innerContent', $text['element']['attributes']['content']);
        $this->assertIsArray($text['element']['defaults']);
    }
}
