<?php

namespace WPMCP\Tests\Pro\Builders;

use WPMCP\Pro\Gate;
use WPMCP\Safety\{Rollback_Service, Snapshot_Store};
use WPMCP\Tools\Builders\{Cornerstone_Content, Cornerstone_Tree, Detect_Builder, Get_Builder_Content, Update_Builder_Content};

/**
 * Issue #646: the Cornerstone (Themeco) adapter against a real Cornerstone.
 *
 * It runs only in the local gate's live Cornerstone leg
 * (bin/test-local.sh --live-cornerstone), which sets
 * WPMCP_LIVE_CORNERSTONE=1, copies the plugin from WPMCP_CORNERSTONE_SRC
 * onto a separate WordPress install (it is not on wordpress.org) and loads
 * it in tests/bootstrap.php.
 *
 * Pages are first saved through Cornerstone's own document save
 * (Document::locate()->update()->save(), what its builder's Save calls), so
 * the rows, revisions and rendered post_content are Cornerstone's. Every
 * write here must go through the same pipeline: the placement rules come
 * from its element definitions, the page must be rendered by it again
 * (twice in one request too, past its document cache), a revision it made
 * must restore, a template it saved must insert, and every write must roll
 * back to the exact rows before it.
 *
 * Everywhere else Cornerstone is absent and the test is skipped; the live
 * leg runs with --fail-on-skipped so it cannot pass vacuously.
 *
 * @group cornerstone-live
 */
class CornerstoneLiveTest extends \WP_UnitTestCase
{
    private const ELEMENTS = [[
        '_type'    => 'section',
        '_modules' => [[
            '_type'    => 'layout-row',
            '_modules' => [[
                '_type'    => 'layout-column',
                '_modules' => [
                    ['_type' => 'headline', 'text_content' => 'Live headline'],
                    ['_type' => 'text', 'text_content' => '<p>Live body</p>'],
                ],
            ]],
        ]],
    ]];

    protected function setUp(): void
    {
        parent::setUp();
        if (! defined('CS_VERSION') || ! function_exists('cornerstone') || ! class_exists('Themeco\\Cornerstone\\Documents\\Document')) {
            $this->markTestSkipped('Cornerstone is not loaded; run bin/test-local.sh --live-cornerstone with WPMCP_CORNERSTONE_SRC set.');
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

    /** A page saved the way Cornerstone's builder saves one. */
    private function cornerstone_page(array $elements = self::ELEMENTS, string $status = 'publish'): int
    {
        $post_id = self::factory()->post->create(['post_type' => 'page', 'post_status' => $status, 'post_title' => 'Live page']);
        \Themeco\Cornerstone\Documents\Document::locate($post_id)->update(['elements' => $elements])->save();
        clean_post_cache($post_id);

        return $post_id;
    }

    private function update(int $post_id, array $args)
    {
        $read = (new Get_Builder_Content())->handle(['post_id' => $post_id]);
        $args += ['expected_hash' => $read['hash'] ?? ''];

        return (new Update_Builder_Content())->handle(['post_id' => $post_id, 'builder' => 'cornerstone'] + $args);
    }

    private function state(int $post_id): array
    {
        clean_post_cache($post_id);
        $meta = get_post_meta($post_id);
        // Cornerstone regenerates its style cache whenever it renders.
        unset($meta['_cs_generated_tss'], $meta['_edit_lock']);

        return [get_post($post_id)->post_status, get_post($post_id)->post_content, $meta];
    }

    private function content(int $post_id): string
    {
        clean_post_cache($post_id);

        return (string) get_post($post_id)->post_content;
    }

    public function test_a_page_cornerstone_saved_is_detected_and_read(): void
    {
        $post_id = $this->cornerstone_page();

        $this->assertSame('cornerstone', (new Detect_Builder())->handle(['post_id' => $post_id])['builder']);
        $read = (new Get_Builder_Content())->handle(['post_id' => $post_id]);

        $this->assertTrue($read['plugin_active']);
        $this->assertSame(CS_VERSION, $read['plugin_version']);
        $this->assertSame(self::ELEMENTS, $read['content']);
        $this->assertSame('headline', $read['tree'][0]['children'][0]['children'][0]['children'][0]['type']);
        $this->assertSame(hash('sha256', (string) get_post_meta($post_id, '_cornerstone_data', true)), $read['hash']);
        $this->assertNotEmpty($read['revisions'], 'Cornerstone copies its rows onto the revision the save made');
    }

    public function test_updates_render_through_cornerstone_twice_in_one_request_and_roll_back(): void
    {
        $post_id = $this->cornerstone_page();
        $before  = $this->state($post_id);

        $first = $this->update($post_id, ['operation' => 'update', 'path' => '0.0.0.0', 'attrs' => ['text_content' => 'First edit']]);
        $this->assertIsArray($first, is_wp_error($first) ? $first->get_error_message() : '');
        $this->assertStringContainsString('<!-- cs-content -->', $this->content($post_id));
        $this->assertStringContainsString('First edit', $this->content($post_id));

        $second = $this->update($post_id, ['operation' => 'update', 'path' => '0.0.0.0', 'attrs' => ['text_content' => 'Second edit']]);
        $this->assertIsArray($second, is_wp_error($second) ? $second->get_error_message() : '');
        $this->assertStringContainsString('Second edit', $this->content($post_id));
        $this->assertStringNotContainsString('First edit', $this->content($post_id));
        $this->assertSame('Second edit', Cornerstone_Tree::node(Cornerstone_Content::elements($post_id), '0.0.0.0')['text_content']);

        Rollback_Service::restore_operation($second['operation_id']);
        Rollback_Service::restore_operation($first['operation_id']);
        $this->assertSame($before, $this->state($post_id));
    }

    public function test_placement_follows_cornerstones_own_definitions(): void
    {
        $post_id = $this->cornerstone_page();
        $before  = $this->state($post_id);

        $refused = $this->update($post_id, ['operation' => 'add', 'to' => '0', 'element' => ['type' => 'layout-column']]);
        $this->assertInstanceOf(\WP_Error::class, $refused);
        $this->assertStringContainsString('goes only in a layout-row', $refused->get_error_message());

        $out = $this->update($post_id, ['operation' => 'add', 'to' => '0.0.0', 'element' => ['type' => 'button', 'attrs' => ['anchor_text_primary_content' => 'Live button']]]);
        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
        $this->assertSame('0.0.0.2', $out['path']);
        $this->assertStringContainsString('Live button', $this->content($post_id));

        $moved = $this->update($post_id, ['operation' => 'move', 'path' => '0.0.0.2', 'to' => '0.0.0', 'index' => 0]);
        $this->assertSame(['button', 'headline', 'text'], array_column(Cornerstone_Tree::node(Cornerstone_Content::elements($post_id), '0.0.0')['_modules'], '_type'));
        $removed = $this->update($post_id, ['operation' => 'remove', 'path' => '0.0.0.0']);
        $this->assertStringNotContainsString('Live button', $this->content($post_id));

        Rollback_Service::restore_operation($removed['operation_id']);
        Rollback_Service::restore_operation($moved['operation_id']);
        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertSame($before, $this->state($post_id));
    }

    public function test_a_blank_page_takes_a_whole_layout_and_publishes(): void
    {
        $post_id = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'draft', 'post_content' => '']);
        $before  = $this->state($post_id);

        $out = (new Update_Builder_Content())->handle(['post_id' => $post_id, 'builder' => 'cornerstone', 'content' => (string) wp_json_encode(self::ELEMENTS)]);
        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
        $this->assertSame('cornerstone', (new Detect_Builder())->handle(['post_id' => $post_id])['builder']);
        $this->assertStringContainsString('Live headline', $this->content($post_id));
        $this->assertSame('draft', get_post_status($post_id), 'Cornerstone keeps the status it read');

        $published = $this->update($post_id, ['operation' => 'publish']);
        $this->assertSame('publish', get_post_status($post_id));

        Rollback_Service::restore_operation($published['operation_id']);
        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertSame($before, $this->state($post_id));
    }

    public function test_restore_a_revision_cornerstone_made(): void
    {
        $post_id = $this->cornerstone_page();
        $first   = (new Get_Builder_Content())->handle(['post_id' => $post_id])['revisions'][0]['id'];
        $changed = $this->update($post_id, ['operation' => 'update', 'path' => '0.0.0.1', 'attrs' => ['text_content' => '<p>Changed</p>']]);
        $this->assertIsArray($changed, is_wp_error($changed) ? $changed->get_error_message() : '');
        $before = $this->state($post_id);

        $out = $this->update($post_id, ['operation' => 'restore', 'path' => (string) $first]);
        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
        $this->assertSame(self::ELEMENTS, Cornerstone_Content::elements($post_id));
        $this->assertStringContainsString('Live body', $this->content($post_id));

        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertSame($before, $this->state($post_id));
    }

    public function test_templates_cornerstone_saved_are_listed_and_inserted(): void
    {
        // Cornerstone's own template save still calls these WordPress
        // functions, deprecated since WordPress 6.7.
        $this->setExpectedDeprecated('wp_remove_targeted_link_rel_filters');
        $this->setExpectedDeprecated('wp_init_targeted_link_rel_filters');
        $template = \Themeco\Cornerstone\Templating\Template::create('element', '__multi__');
        $template->setTitle('Live section');
        $template->setMeta(['elements' => [[
            '_type'    => 'section',
            '_modules' => [['_type' => 'layout-row', '_modules' => [['_type' => 'layout-column', '_modules' => [['_type' => 'headline', 'text_content' => 'From a template']]]]]],
        ]]]);
        $saved = $template->save();
        $id    = (int) $saved['id'];

        $list = (new Get_Builder_Content())->handle(['builder' => 'cornerstone', 'scope' => 'templates']);
        $item = array_values(array_filter($list['templates'], static fn ($t) => $t['id'] === $id))[0] ?? null;
        $this->assertNotNull($item);
        $this->assertTrue($item['insertable']);
        $this->assertSame('element|__multi__', $item['identifier']);

        $post_id = $this->cornerstone_page();
        $before  = $this->state($post_id);
        $out     = $this->update($post_id, ['operation' => 'add', 'to' => '', 'element' => ['template' => $id]]);
        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
        $this->assertSame('1', $out['path']);
        $this->assertStringContainsString('From a template', $this->content($post_id));

        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertSame($before, $this->state($post_id));
    }

    public function test_catalog_comes_from_the_real_element_registry(): void
    {
        $list = (new Get_Builder_Content())->handle(['builder' => 'cornerstone', 'scope' => 'catalog']);
        $this->assertContains('headline', array_column($list['elements'], 'type'));
        $this->assertContains('section', array_column($list['elements'], 'type'));

        $one = (new Get_Builder_Content())->handle(['builder' => 'cornerstone', 'scope' => 'catalog', 'element' => 'headline']);
        $this->assertSame('markup:seo', $one['element']['designations']['text_content']);
        $this->assertContains('text_content', $one['element']['inline']);
        $this->assertArrayHasKey('text_content', $one['element']['defaults']);

        $design = (new Get_Builder_Content())->handle(['builder' => 'cornerstone', 'scope' => 'design_system']);
        $this->assertIsArray($design['colors']);
    }
}
