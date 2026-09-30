<?php

namespace WPMCP\Tests\Pro\Builders;

use WPMCP\Pro\Gate;
use WPMCP\Safety\{Rollback_Service, Snapshot_Store};
use WPMCP\Tools\Builders\{Detect_Builder, Get_Builder_Content, Update_Builder_Content};

/**
 * Issue #457: the Breakdance adapter against a real Breakdance 1.x.
 *
 * It runs only in the local gate's live Breakdance leg
 * (bin/test-local.sh --live-breakdance), which sets WPMCP_LIVE_BREAKDANCE=1,
 * copies the Breakdance plugin from WPMCP_BREAKDANCE_SRC onto a separate
 * WordPress install (Breakdance is not on wordpress.org) and loads it in
 * tests/bootstrap.php.
 *
 * A page saved through Breakdance's own storage API must be detected, read
 * and edited in the row Breakdance wrote, must still load through the
 * builder's own document loader and renderer after the edit, and rollback
 * must put back the exact row Breakdance wrote. A page wpmcp creates must be
 * one Breakdance loads too.
 *
 * Everywhere else Breakdance is absent and the test is skipped; the live leg
 * runs with --fail-on-skipped so it cannot pass vacuously.
 *
 * @group breakdance-live
 */
class BreakdanceLiveTest extends \WP_UnitTestCase
{
    private const TREE = '{"root":{"id":1,"data":{"type":"root","properties":null},"children":['
        . '{"id":100,"data":{"type":"EssentialElements\\\\Section","properties":null},"children":['
        . '{"id":101,"data":{"type":"EssentialElements\\\\Heading","properties":{"content":{"content":{"text":"Saved by Breakdance","tags":"h1"}}}},"children":[],"_parentId":100},'
        . '{"id":102,"data":{"type":"EssentialElements\\\\Text","properties":{"content":{"content":{"text":"C:\\\\Sites and \\"quotes\\""}}}},"children":[],"_parentId":100}'
        . '],"_parentId":1}]},"_nextNodeId":103,"status":"exported"}';

    protected function setUp(): void
    {
        parent::setUp();
        if (! defined('__BREAKDANCE_VERSION') || ! function_exists('Breakdance\\Data\\set_meta')) {
            $this->markTestSkipped('Needs the real Breakdance (bin/test-local.sh --live-breakdance, WPMCP_BREAKDANCE_SRC).');
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

    /** A page saved the way Breakdance's own save_document() stores it. */
    private function breakdance_page(): int
    {
        $id = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'publish']);
        \Breakdance\Data\set_meta($id, 'breakdance_data', ['tree_json_string' => self::TREE]);
        \Breakdance\Render\generateCacheForPost($id);

        return $id;
    }

    private function raw(int $post_id, string $key): ?string
    {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $post_id, $key));
    }

    /** The document as Breakdance's builder loads it (load_document() reads it through get_tree()). */
    private function loaded(int $post_id): array
    {
        $tree = \Breakdance\Data\get_tree($post_id);
        $this->assertIsArray($tree, 'Breakdance could not load the document');

        return $tree;
    }

    public function test_a_page_saved_by_breakdance_is_detected_read_edited_and_still_loads(): void
    {
        $id  = $this->breakdance_page();
        $raw = $this->raw($id, 'breakdance_data');
        $this->assertNotNull($raw, 'Breakdance ' . __BREAKDANCE_VERSION . ' did not store breakdance_data');

        $this->assertSame('breakdance', (new Detect_Builder())->handle(['post_id' => $id])['builder']);

        $read = (new Get_Builder_Content())->handle(['post_id' => $id]);
        $this->assertSame('breakdance', $read['builder']);
        $this->assertTrue($read['plugin_active']);
        $this->assertSame('Saved by Breakdance', $read['tree'][0]->children[0]->data->properties->content->content->text);

        $session = wp_generate_uuid4();
        $out     = (new Update_Builder_Content())->handle([
            'post_id'    => $id,
            'builder'    => 'breakdance',
            'operation'  => 'update',
            'path'       => '101',
            'attrs'      => ['content' => ['content' => ['text' => 'Edited through wpmcp']]],
            'session_id' => $session,
        ]);
        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');

        // Written to the row Breakdance uses, never to a second one.
        $this->assertFalse(metadata_exists('post', $id, '_breakdance_data'));
        $tree = $this->loaded($id);
        $this->assertSame('Edited through wpmcp', $tree['root']['children'][0]['children'][0]['data']['properties']['content']['content']['text']);
        $this->assertSame('C:\\Sites and "quotes"', $tree['root']['children'][0]['children'][1]['data']['properties']['content']['content']['text']);
        // Breakdance rebuilt its own CSS cache row for the page.
        $this->assertNotNull($this->raw($id, 'breakdance_css_file_paths_cache'));
        $this->assertFalse(metadata_exists('post', $id, '_breakdance_css_file_paths_cache'));

        $html = (string) \Breakdance\Render\render($id);
        $this->assertStringContainsString('Edited through wpmcp', $html);
        $this->assertStringNotContainsString('Saved by Breakdance', $html);

        Rollback_Service::restore_session($session);

        $this->assertSame($raw, $this->raw($id, 'breakdance_data'));
        $this->assertFalse(metadata_exists('post', $id, '_breakdance_data'));
        $this->assertSame('Saved by Breakdance', $this->loaded($id)['root']['children'][0]['children'][0]['data']['properties']['content']['content']['text']);
    }

    public function test_a_page_wpmcp_builds_is_one_breakdance_loads(): void
    {
        $id = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'publish', 'post_content' => '']);

        $out = (new Update_Builder_Content())->handle([
            'post_id' => $id,
            'builder' => 'breakdance',
            'content' => '[{"type":"EssentialElements\\\\Section","children":[{"type":"EssentialElements\\\\Heading","properties":{"content":{"content":{"text":"Built by wpmcp","tags":"h2"}}}}]}]',
        ]);
        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');

        $this->assertTrue(metadata_exists('post', $id, 'breakdance_data'));
        $this->assertFalse(metadata_exists('post', $id, '_breakdance_data'));
        $this->assertSame('Built by wpmcp', $this->loaded($id)['root']['children'][0]['children'][0]['data']['properties']['content']['content']['text']);
        $this->assertStringContainsString('Built by wpmcp', (string) \Breakdance\Render\render($id));
    }

    public function test_template_conditions_breakdance_stores_are_read(): void
    {
        $id = self::factory()->post->create(['post_type' => BREAKDANCE_HEADER_POST_TYPE, 'post_title' => 'Header', 'post_status' => 'publish']);
        \Breakdance\Data\set_meta($id, 'breakdance_data', ['tree_json_string' => self::TREE]);
        \Breakdance\Data\set_meta($id, 'breakdance_template_settings', ['type' => 'all', 'ruleGroups' => [], 'priority' => 1]);

        $out = (new Get_Builder_Content())->handle(['builder' => 'breakdance', 'scope' => 'templates']);

        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
        $by_id = array_column($out['templates'], null, 'id');
        $this->assertSame('header', $by_id[$id]['type']);
        $this->assertSame(['type' => 'all', 'ruleGroups' => [], 'priority' => 1], $by_id[$id]['settings']);
        $this->assertSame('Saved by Breakdance', $by_id[$id]['tree'][0]->children[0]->data->properties->content->content->text);
    }
}
