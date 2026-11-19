<?php

namespace WPMCP\Tests\Pro\Builders;

use WPMCP\Pro\Gate;
use WPMCP\Safety\{Rollback_Service, Snapshot_Store};
use WPMCP\Tools\Builders\{Detect_Builder, Get_Builder_Content, Update_Builder_Content, Visual_Composer_Content, Visual_Composer_Html};

/**
 * Issue #642: Visual Composer Website Builder through the builder pair.
 *
 * Visual Composer is not loaded here, so a page is stored the way its
 * editor's save stores one (Visual Composer 45.16, DataAjax Controller and
 * its translation integration): `vcv-pageContent` holding
 * rawurlencode(JSON) with a flat `elements` map of {id, tag, parent, order,
 * ...attributes}, and post_content holding the markup the browser editor
 * rendered, where each element's main node has id="el-<id>" (row:
 * vce-row-container > vce-row#el-id > vce-row-content; column:
 * vce-col#el-id > vce-col-inner > vce-col-content; Text Block:
 * vce-text-block > vce-text-block-wrapper#el-id > its output). The plugin's
 * presence is toggled through wpmcp_visual_composer_active, and the element
 * catalog reads the recorded bundles under tests/support/visual-composer.
 */
class VisualComposerAdapterTest extends \WP_UnitTestCase
{
    public const TEXT = "<h2>Welcome</h2>\n<p>Visual Composer &amp; friends.<br /></p>";

    /** @return array<string,mixed> */
    public static function document(): array
    {
        return [
            'elements' => [
                'a1b2c3d' => ['tag' => 'row', 'parent' => false, 'id' => 'a1b2c3d', 'order' => 0, 'customHeaderTitle' => '', 'metaElementAssets' => new \stdClass(), 'designOptionsAdvanced' => [], 'layout' => ['layoutData' => ['auto']]],
                'b2c3d4e' => ['tag' => 'column', 'parent' => 'a1b2c3d', 'id' => 'b2c3d4e', 'order' => 0, 'size' => ['all' => 'auto'], 'metaElementAssets' => new \stdClass()],
                'c3d4e5f' => ['tag' => 'textBlock', 'parent' => 'b2c3d4e', 'id' => 'c3d4e5f', 'order' => 1, 'output' => self::TEXT, 'darkTextSkin' => false, 'customClass' => '', 'metaElementAssets' => new \stdClass()],
                'd4e5f6a' => ['tag' => 'basicButton', 'parent' => 'b2c3d4e', 'id' => 'd4e5f6a', 'order' => 0, 'buttonText' => 'Apply now', 'shape' => 'square', 'metaElementAssets' => new \stdClass()],
            ],
        ];
    }

    public static function html(): string
    {
        return '<!--vcv no format--><div class="vce-row-container"><div class="vce-row vce-row--col-gap-30 vce-row-columns--top" id="el-a1b2c3d" data-vce-do-apply="all el-a1b2c3d"><div class="vce-row-content" data-vce-element-content="true">'
            . '<div class="vce-col vce-col--md-auto vce-col--xs-1" id="el-b2c3d4e"><div class="vce-col-inner" data-vce-do-apply="border margin background el-b2c3d4e"><div class="vce-col-content" data-vce-element-content="true" data-vce-do-apply="padding el-b2c3d4e">'
            . '<div class="vce-button--style-basic-container"><span class="vce-button--style-basic-wrapper vce" id="el-d4e5f6a" data-vce-do-apply="margin el-d4e5f6a"><a class="vce-button vce-button--style-basic" href="#">Apply now</a></span></div>'
            . '<div class="vce-text-block"><div class="vce-text-block-wrapper vce" id="el-c3d4e5f" data-vce-do-apply="all el-c3d4e5f">'
            . "<h2>Welcome</h2>\n<p>Visual Composer &amp; friends.<br></p>"
            . '</div></div></div></div></div></div></div></div><!--vcv no format-->';
    }

    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
        Snapshot_Store::install();
        $this->admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($this->admin);
        add_filter('wpmcp_visual_composer_active', '__return_true');
        add_filter('wpmcp_visual_composer_element_dirs', static fn () => [dirname(__DIR__, 2) . '/support/visual-composer/elements']);
        if (! post_type_exists('vcv_templates')) {
            register_post_type('vcv_templates', ['public' => false]);
        }
    }

    protected function tearDown(): void
    {
        Gate::set_pro_for_tests(null);
        remove_all_filters('wpmcp_visual_composer_active');
        remove_all_filters('wpmcp_visual_composer_element_dirs');
        parent::tearDown();
    }

    private function page(array $args = [], ?array $doc = null, ?string $html = null): int
    {
        $id = self::factory()->post->create($args + ['post_type' => 'page', 'post_status' => 'publish', 'post_content' => wp_slash($html ?? self::html())]);
        update_post_meta($id, 'vcv-pageContent', rawurlencode((string) wp_json_encode($doc ?? self::document())));
        update_post_meta($id, 'vcv-be-editor', 'fe');

        return $id;
    }

    private function read(int $id): array
    {
        $out = (new Get_Builder_Content())->handle(['post_id' => $id]);
        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');

        return $out;
    }

    private function write(int $id, array $args)
    {
        return (new Update_Builder_Content())->handle($args + ['post_id' => $id, 'builder' => 'visual-composer', 'expected_hash' => $this->read($id)['hash']]);
    }

    private function ok($out): array
    {
        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_code() . ': ' . $out->get_error_message() : '');

        return $out;
    }

    private function stored(int $id): array
    {
        return json_decode(rawurldecode((string) get_post_meta($id, 'vcv-pageContent', true)), true);
    }

    public function test_detects_a_page_and_not_one_switched_to_another_editor(): void
    {
        $id = $this->page();
        $this->assertSame('visual-composer', (new Detect_Builder())->handle(['post_id' => $id])['builder']);

        update_post_meta($id, 'vcv-be-editor', 'gutenberg');
        $this->assertNotSame('visual-composer', (new Detect_Builder())->handle(['post_id' => $id])['builder']);

        $empty = $this->page([], ['elements' => new \stdClass()], '<p>Plain</p>');
        $this->assertSame('classic', (new Detect_Builder())->handle(['post_id' => $empty])['builder']);
    }

    public function test_read_returns_the_tree_in_order_with_labels_and_the_elements(): void
    {
        $read = $this->read($this->page());
        $this->assertSame('visual-composer', $read['builder']);
        $this->assertTrue($read['plugin_active']);
        $this->assertSame(Visual_Composer_Content::hash((int) $read['post_id']), $read['hash']);
        $this->assertSame('row', $read['tree'][0]['tag']);
        $column = $read['tree'][0]['children'][0];
        $this->assertSame('b2c3d4e', $column['id']);
        $this->assertSame(['id' => 'd4e5f6a', 'tag' => 'basicButton', 'label' => 'Apply now'], $column['children'][0]);
        $this->assertSame(['id' => 'c3d4e5f', 'tag' => 'textBlock', 'label' => 'Welcome Visual Composer & friends.'], $column['children'][1]);
        $this->assertSame(self::TEXT, $read['elements']['c3d4e5f']['output']);
        $this->assertStringContainsString('vcv-source-id=' . $read['post_id'], $read['editor_url']);
    }

    public function test_read_refuses_a_private_page_to_a_reader_who_cannot_see_it(): void
    {
        $id = $this->page(['post_status' => 'private']);
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        $out = (new Get_Builder_Content())->handle(['post_id' => $id]);
        $this->assertWPError($out);
        $this->assertSame('forbidden', $out->get_error_code());
    }

    public function test_update_text_changes_both_stores_and_rolls_back_exactly(): void
    {
        $id      = $this->page();
        $before  = get_post_meta($id);
        $content = get_post($id)->post_content;
        $session = wp_generate_uuid4();

        $out = $this->ok($this->write($id, [
            'operation'  => 'update',
            'path'       => 'c3d4e5f',
            'text'       => '<h2>Hello</h2><p>New copy</p>',
            'session_id' => $session,
        ]));
        $this->assertSame('c3d4e5f', $out['path']);
        $this->assertSame($this->read($id)['hash'], $out['hash']);

        $stored = $this->stored($id);
        $this->assertSame('<h2>Hello</h2><p>New copy</p>', $stored['elements']['c3d4e5f']['output']);
        $this->assertSame([], $stored['elements']['a1b2c3d']['designOptionsAdvanced']);
        $this->assertStringContainsString('"metaElementAssets":{}', rawurldecode((string) get_post_meta($id, 'vcv-pageContent', true)));
        $html = get_post($id)->post_content;
        $this->assertStringContainsString('id="el-c3d4e5f" data-vce-do-apply="all el-c3d4e5f"><h2>Hello</h2><p>New copy</p></div></div>', $html);
        $this->assertStringContainsString('<a class="vce-button vce-button--style-basic" href="#">Apply now</a>', $html);

        Rollback_Service::restore_session($session);
        $this->assertSame($content, get_post($id)->post_content);
        $this->assertSame($before, get_post_meta($id));
    }

    public function test_update_layer_name_is_editor_only_and_leaves_the_markup(): void
    {
        $id   = $this->page();
        $html = get_post($id)->post_content;
        $this->ok($this->write($id, ['operation' => 'update', 'path' => 'a1b2c3d', 'attrs' => ['customHeaderTitle' => 'Hero row']]));
        $this->assertSame('Hero row', $this->stored($id)['elements']['a1b2c3d']['customHeaderTitle']);
        $this->assertSame($html, get_post($id)->post_content);
    }

    public function test_attributes_the_browser_renders_are_refused_with_nothing_written(): void
    {
        $id     = $this->page();
        $before = get_post_meta($id);

        // The button text is not the whole inner HTML of its el- node.
        foreach ([['buttonText' => 'Go'], ['shape' => 'round'], ['darkTextSkin' => true]] as $attrs) {
            $path = isset($attrs['darkTextSkin']) ? 'c3d4e5f' : 'd4e5f6a';
            $out  = $this->write($id, ['operation' => 'update', 'path' => $path, 'attrs' => $attrs]);
            $this->assertWPError($out);
            $this->assertSame('editor_render_required', $out->get_error_code(), (string) key($attrs));
        }

        // The rendered text no longer matches the document (edited outside).
        $doc = self::document();
        $doc['elements']['c3d4e5f']['output'] = '<p>Something else</p>';
        $drift = $this->page([], $doc);
        $out   = $this->write($drift, ['operation' => 'update', 'path' => 'c3d4e5f', 'text' => '<p>x</p>']);
        $this->assertSame('editor_render_required', $out->get_error_code());

        foreach (['add', 'remove', 'move'] as $operation) {
            $out = $this->write($id, ['operation' => $operation, 'path' => 'c3d4e5f', 'to' => 'b2c3d4e', 'element' => ['tag' => 'textBlock']]);
            $this->assertWPError($out);
            $this->assertSame('editor_render_required', $out->get_error_code(), $operation);
        }
        $out = $this->write($id, ['content' => '{"elements":{}}']);
        $this->assertSame('editor_render_required', $out->get_error_code());

        $this->assertSame($before, get_post_meta($id));
    }

    public function test_write_needs_the_plugin_the_hash_and_a_known_element(): void
    {
        $id = $this->page();

        $out = (new Update_Builder_Content())->handle(['post_id' => $id, 'builder' => 'visual-composer', 'operation' => 'update', 'path' => 'c3d4e5f', 'text' => 'x']);
        $this->assertSame('missing_expected_hash', $out->get_error_code());
        $out = (new Update_Builder_Content())->handle(['post_id' => $id, 'builder' => 'visual-composer', 'operation' => 'update', 'path' => 'c3d4e5f', 'text' => 'x', 'expected_hash' => str_repeat('0', 64)]);
        $this->assertSame('stale_expected_hash', $out->get_error_code());

        $out = $this->write($id, ['operation' => 'update', 'path' => 'nope123', 'text' => 'x']);
        $this->assertSame('invalid_visual_composer_request', $out->get_error_code());
        $out = $this->write($id, ['operation' => 'update', 'path' => 'c3d4e5f', 'attrs' => ['parent' => false]]);
        $this->assertSame('invalid_visual_composer_request', $out->get_error_code());

        $other = self::factory()->post->create(['post_content' => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->']);
        $out   = (new Update_Builder_Content())->handle(['post_id' => $other, 'builder' => 'visual-composer', 'operation' => 'publish', 'expected_hash' => 'x']);
        $this->assertSame('unsupported_builder', $out->get_error_code());

        $hash = $this->read($id)['hash'];
        remove_all_filters('wpmcp_visual_composer_active');
        add_filter('wpmcp_visual_composer_active', '__return_false');
        $out = (new Update_Builder_Content())->handle(['post_id' => $id, 'builder' => 'visual-composer', 'operation' => 'update', 'path' => 'c3d4e5f', 'text' => 'x', 'expected_hash' => $hash]);
        $this->assertSame('plugin_missing', $out->get_error_code());
        $this->assertStringContainsString('Install and activate Visual Composer', $out->get_error_message());
        $this->assertIsArray($this->read($id), 'reads work without the plugin');
    }

    public function test_publish_sets_a_draft_live_and_rolls_back(): void
    {
        $id      = $this->page(['post_status' => 'draft']);
        $session = wp_generate_uuid4();
        $this->ok($this->write($id, ['operation' => 'publish', 'session_id' => $session]));
        $this->assertSame('publish', get_post_status($id));
        $this->assertSame('already_published', $this->write($id, ['operation' => 'publish'])->get_error_code());

        Rollback_Service::restore_session($session);
        $this->assertSame('draft', get_post_status($id));
    }

    public function test_scope_pages_and_templates_list_what_the_reader_can_see(): void
    {
        $page    = $this->page(['post_title' => 'VC landing']);
        $hidden  = $this->page(['post_title' => 'Someone private', 'post_status' => 'private', 'post_author' => self::factory()->user->create(['role' => 'editor'])]);
        $tpl     = self::factory()->post->create(['post_type' => 'vcv_templates', 'post_title' => 'Hero', 'post_status' => 'publish']);
        update_post_meta($tpl, 'vcv-pageContent', rawurlencode((string) wp_json_encode(self::document())));
        update_post_meta($tpl, '_vcv-type', 'customBlock');
        self::factory()->post->create(['post_content' => 'plain']);

        wp_set_current_user(self::factory()->user->create(['role' => 'author']));
        $pages = (new Get_Builder_Content())->handle(['builder' => 'visual-composer', 'scope' => 'pages']);
        $this->assertIsArray($pages);
        $ids = array_column($pages['pages'], 'id');
        $this->assertContains($page, $ids);
        $this->assertNotContains($hidden, $ids);
        $this->assertNotContains($tpl, $ids);
        $row = $pages['pages'][array_search($page, $ids, true)];
        $this->assertSame(['id' => $page, 'title' => 'VC landing', 'type' => 'page', 'status' => 'publish', 'elements' => 4], $row);

        wp_set_current_user($this->admin);
        $templates = (new Get_Builder_Content())->handle(['builder' => 'visual-composer', 'scope' => 'templates']);
        $this->assertSame([['id' => $tpl, 'title' => 'Hero', 'status' => 'publish', 'type' => 'customBlock', 'elements' => 4]], $templates['templates']);
    }

    public function test_scope_catalog_lists_elements_and_reads_attributes_from_either_bundle_form(): void
    {
        $catalog = (new Get_Builder_Content())->handle(['builder' => 'visual-composer', 'scope' => 'catalog']);
        $this->assertIsArray($catalog, is_wp_error($catalog) ? $catalog->get_error_message() : '');
        $this->assertSame(['headingMinified', 'textBlock'], array_column($catalog['elements'], 'tag'));
        $this->assertSame('Text Block', $catalog['elements'][1]['name']);

        $text = (new Get_Builder_Content())->handle(['builder' => 'visual-composer', 'scope' => 'catalog', 'element' => 'textBlock']);
        $this->assertIsArray($text, is_wp_error($text) ? $text->get_error_message() : '');
        $byName = array_column($text['element']['attributes'], null, 'name');
        $this->assertSame('htmleditor', $byName['output']['type']);
        $this->assertSame('Content', $byName['output']['label']);
        $this->assertStringContainsString("</h2>\n<p>Typography", $byName['output']['default']);
        $this->assertArrayNotHasKey('tag', $byName, 'protected attributes are left out');

        $heading = (new Get_Builder_Content())->handle(['builder' => 'visual-composer', 'scope' => 'catalog', 'element' => 'headingMinified']);
        $byName  = array_column($heading['element']['attributes'], null, 'name');
        $this->assertSame('It\'s a "title"', $byName['text']['default']);
        $this->assertSame(['left', 'right'], $byName['align']['values']);

        $this->assertSame('element_not_found', (new Get_Builder_Content())->handle(['builder' => 'visual-composer', 'scope' => 'catalog', 'element' => 'nope'])->get_error_code());

        remove_all_filters('wpmcp_visual_composer_active');
        add_filter('wpmcp_visual_composer_active', '__return_false');
        $this->assertSame('builder_not_loaded', (new Get_Builder_Content())->handle(['builder' => 'visual-composer', 'scope' => 'catalog'])->get_error_code());
    }

    public function test_inner_range_needs_exactly_one_balanced_node(): void
    {
        $html = '<div id="el-x1"><div>a</div><div>b</div></div><p>after</p>';
        $this->assertSame('<div>a</div><div>b</div>', substr($html, ...Visual_Composer_Html::inner_range($html, 'x1')));
        $this->assertNull(Visual_Composer_Html::inner_range('<div id="el-x1">a</div><div id="el-x1">b</div>', 'x1'));
        $this->assertNull(Visual_Composer_Html::inner_range('<div id="el-x1"><div>open', 'x1'));
        $this->assertNull(Visual_Composer_Html::inner_range($html, 'x1"]'));
    }
}
