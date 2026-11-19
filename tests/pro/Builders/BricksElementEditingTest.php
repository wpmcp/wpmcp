<?php

namespace WPMCP\Tests\Pro\Builders;

use WPMCP\Pro\Gate;
use WPMCP\Safety\{Rollback_Service, Snapshot_Store};
use WPMCP\Tools\Builders\{Bricks_Page, Bricks_Tree, Get_Builder_Content, Update_Builder_Content};

/**
 * Issue #633: Bricks element-level editing on the builder pair. The read
 * gains an outline by element id, the hash writes are pinned to, the Bricks
 * version and the revisions that hold a layout; the write gains update, add,
 * remove and move by element id, add from a Bricks template, save_template,
 * restore and publish. Every write is undone.
 *
 * Bricks is not installed here: element rows are stored the way Bricks'
 * ajax.php save stores them (a flat native array of {id, name, parent,
 * children, settings}), its presence is toggled through wpmcp_bricks_active
 * and the bricks_template post type is registered when a test needs it.
 */
class BricksElementEditingTest extends \WP_UnitTestCase
{
    /** A section holding a container with a heading and a text, then a second section. */
    private const PAGE = [
        ['id' => 'sec001', 'name' => 'section', 'parent' => 0, 'children' => ['con001'], 'settings' => []],
        ['id' => 'con001', 'name' => 'container', 'parent' => 'sec001', 'children' => ['hea001', 'txt001'], 'settings' => []],
        ['id' => 'hea001', 'name' => 'heading', 'parent' => 'con001', 'children' => [], 'settings' => ['text' => 'Hello', 'tag' => 'h2']],
        ['id' => 'txt001', 'name' => 'text-basic', 'parent' => 'con001', 'children' => [], 'settings' => ['text' => 'Body']],
        ['id' => 'sec002', 'name' => 'section', 'parent' => 0, 'children' => [], 'settings' => [], 'label' => 'Footer band'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
        Snapshot_Store::install();
        add_filter('wpmcp_bricks_active', '__return_true');
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    protected function tearDown(): void
    {
        Gate::set_pro_for_tests(null);
        remove_all_filters('wpmcp_bricks_active');
        if (post_type_exists('bricks_template')) {
            unregister_post_type('bricks_template');
        }
        parent::tearDown();
    }

    private function bricks_page(array $elements = self::PAGE, string $status = 'publish'): int
    {
        $id = self::factory()->post->create(['post_type' => 'page', 'post_status' => $status]);
        update_post_meta($id, '_bricks_page_content_2', $elements);
        update_post_meta($id, '_bricks_editor_mode', 'bricks');

        return $id;
    }

    private function state(int $post_id): array
    {
        $post = get_post($post_id);
        return [$post->post_content, $post->post_status, get_post_meta($post_id)];
    }

    private function read(int $post_id)
    {
        return (new Get_Builder_Content())->handle(['post_id' => $post_id]);
    }

    private function update(int $post_id, array $args)
    {
        return (new Update_Builder_Content())->handle(['post_id' => $post_id, 'builder' => 'bricks'] + $args);
    }

    private function hashed(int $post_id, array $args)
    {
        return $this->update($post_id, $args + ['expected_hash' => $this->read($post_id)['hash']]);
    }

    /** @return array<string,array<string,mixed>> the stored elements by id */
    private function by_id(int $post_id): array
    {
        $out = [];
        foreach ((array) get_post_meta($post_id, '_bricks_page_content_2', true) as $element) {
            $out[$element['id']] = $element;
        }

        return $out;
    }

    private function register_template_type(): void
    {
        register_post_type('bricks_template', ['public' => false, 'capability_type' => 'post']);
    }

    // ------------------------------------------------------------------ read

    public function test_read_keeps_content_and_adds_outline_hash_version_and_revisions(): void
    {
        $post_id = $this->bricks_page();

        $out = $this->read($post_id);

        $this->assertSame(self::PAGE, $out['content']);
        $this->assertSame(Bricks_Page::hash($post_id), $out['hash']);
        $this->assertSame('content', $out['area']);
        $this->assertArrayHasKey('plugin_version', $out);
        $this->assertNull($out['plugin_version'], 'No BRICKS_VERSION is defined here.');
        $this->assertSame([], $out['revisions']);
        $this->assertSame('sec001', $out['tree'][0]['id']);
        $this->assertSame('hea001', $out['tree'][0]['children'][0]['children'][0]['id']);
        $this->assertSame('heading', $out['tree'][0]['children'][0]['children'][0]['name']);
        $this->assertSame('Footer band', $out['tree'][1]['label']);
    }

    public function test_version_note_flags_an_untested_major_version(): void
    {
        $this->assertNull(Bricks_Page::version_note(null));
        $this->assertNull(Bricks_Page::version_note('2.3.9'));
        $this->assertNull(Bricks_Page::version_note('1.12.4'));
        $this->assertStringContainsString('Bricks 3.0', (string) Bricks_Page::version_note('3.0'));
    }

    public function test_read_lists_only_revisions_that_hold_a_bricks_layout(): void
    {
        $post_id = $this->bricks_page();
        $with    = _wp_put_post_revision(get_post($post_id));
        add_metadata('post', $with, '_bricks_page_content_2', wp_slash([self::PAGE[0]]));
        _wp_put_post_revision(get_post($post_id));

        $revisions = $this->read($post_id)['revisions'];

        $this->assertCount(1, $revisions);
        $this->assertSame($with, $revisions[0]['id']);
    }

    // --------------------------------------------------------- hash and plugin

    public function test_element_writes_to_a_page_with_a_layout_need_the_current_hash(): void
    {
        $post_id = $this->bricks_page();
        $before  = $this->state($post_id);

        $missing = $this->update($post_id, ['operation' => 'remove', 'path' => 'txt001']);
        $stale   = $this->update($post_id, ['operation' => 'remove', 'path' => 'txt001', 'expected_hash' => str_repeat('0', 64)]);

        $this->assertWPError($missing);
        $this->assertSame('missing_expected_hash', $missing->get_error_code());
        $this->assertWPError($stale);
        $this->assertSame('stale_expected_hash', $stale->get_error_code());
        $this->assertSame($before, $this->state($post_id));
    }

    public function test_a_blank_page_needs_no_hash(): void
    {
        $post_id = self::factory()->post->create(['post_type' => 'page']);

        $out = $this->update($post_id, ['operation' => 'add', 'to' => '', 'element' => ['name' => 'section']]);

        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
        $this->assertCount(1, $this->by_id($post_id));
        $this->assertSame(Bricks_Page::hash($post_id), $out['hash']);
    }

    public function test_whole_content_writes_check_a_hash_when_one_is_given(): void
    {
        $post_id = $this->bricks_page();

        $stale = $this->update($post_id, ['content' => wp_json_encode([]), 'expected_hash' => 'nope']);

        $this->assertWPError($stale);
        $this->assertSame('stale_expected_hash', $stale->get_error_code());
        $this->assertCount(5, $this->by_id($post_id));
    }

    public function test_element_writes_are_refused_with_plugin_missing_when_bricks_is_not_loaded(): void
    {
        remove_all_filters('wpmcp_bricks_active');
        add_filter('wpmcp_bricks_active', '__return_false');
        $post_id = $this->bricks_page();
        $before  = $this->state($post_id);

        $out = $this->hashed($post_id, ['operation' => 'remove', 'path' => 'txt001']);

        $this->assertWPError($out);
        $this->assertSame('plugin_missing', $out->get_error_code());
        $this->assertStringContainsString('plugins.php', $out->get_error_message());
        $this->assertSame($before, $this->state($post_id));
    }

    public function test_a_non_bricks_post_is_refused(): void
    {
        $post_id = self::factory()->post->create(['post_type' => 'post']);

        $out = $this->update($post_id, ['operation' => 'add', 'to' => '', 'element' => ['name' => 'section']]);

        $this->assertWPError($out);
        $this->assertSame('not_a_bricks_page', $out->get_error_code());
    }

    // ------------------------------------------------------------- operations

    public function test_update_merges_settings_and_null_removes_one_then_rolls_back(): void
    {
        $post_id = $this->bricks_page();
        $before  = $this->state($post_id);

        $out = $this->hashed($post_id, ['operation' => 'update', 'path' => 'hea001', 'attrs' => ['text' => 'Hi there', 'tag' => null, '_cssId' => 'intro']]);

        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
        $this->assertSame('hea001', $out['path']);
        $this->assertSame(['text' => 'Hi there', '_cssId' => 'intro'], $this->by_id($post_id)['hea001']['settings']);
        $this->assertSame(Bricks_Page::hash($post_id), $out['hash']);

        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertSame($before, $this->state($post_id));
    }

    public function test_update_can_set_the_label(): void
    {
        $post_id = $this->bricks_page();

        $out = $this->hashed($post_id, ['operation' => 'update', 'path' => 'sec001', 'element' => ['label' => 'Hero']]);

        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
        $this->assertSame('Hero', $this->by_id($post_id)['sec001']['label']);
    }

    public function test_add_places_a_nested_element_with_new_ids_at_the_index_then_rolls_back(): void
    {
        $post_id = $this->bricks_page();
        $before  = $this->state($post_id);

        $out = $this->hashed($post_id, [
            'operation' => 'add',
            'to'        => 'con001',
            'index'     => 1,
            'element'   => ['name' => 'block', 'label' => 'Card', 'children' => [['name' => 'button', 'settings' => ['text' => 'Go']]]],
        ]);

        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
        $elements = $this->by_id($post_id);
        $new      = $elements[$out['path']];
        $this->assertMatchesRegularExpression('/^[a-z0-9]{6}$/', $out['path']);
        $this->assertSame('block', $new['name']);
        $this->assertSame('con001', $new['parent']);
        $this->assertSame('Card', $new['label']);
        $this->assertSame(['hea001', $out['path'], 'txt001'], $elements['con001']['children']);
        $this->assertCount(1, $new['children']);
        $button = $elements[$new['children'][0]];
        $this->assertSame('button', $button['name']);
        $this->assertSame($out['path'], $button['parent']);
        $this->assertSame(['text' => 'Go'], $button['settings']);

        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertSame($before, $this->state($post_id));
    }

    public function test_add_at_the_top_level_keeps_the_root_order(): void
    {
        $post_id = $this->bricks_page();

        $out = $this->hashed($post_id, ['operation' => 'add', 'to' => '', 'index' => 0, 'element' => ['name' => 'section']]);

        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
        $roots = array_values(array_filter((array) get_post_meta($post_id, '_bricks_page_content_2', true), static fn ($e) => empty($e['parent'])));
        $this->assertSame([$out['path'], 'sec001', 'sec002'], array_column($roots, 'id'));
    }

    public function test_remove_drops_the_element_and_its_descendants_then_rolls_back(): void
    {
        $post_id = $this->bricks_page();
        $before  = $this->state($post_id);

        $out = $this->hashed($post_id, ['operation' => 'remove', 'path' => 'con001']);

        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
        $elements = $this->by_id($post_id);
        $this->assertSame(['sec001', 'sec002'], array_keys($elements));
        $this->assertSame([], $elements['sec001']['children']);

        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertSame($before, $this->state($post_id));
    }

    public function test_move_reparents_and_reorders_then_rolls_back(): void
    {
        $post_id = $this->bricks_page();
        $before  = $this->state($post_id);

        $out = $this->hashed($post_id, ['operation' => 'move', 'path' => 'txt001', 'to' => 'sec002', 'index' => 0]);

        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
        $elements = $this->by_id($post_id);
        $this->assertSame('sec002', $elements['txt001']['parent']);
        $this->assertSame(['txt001'], $elements['sec002']['children']);
        $this->assertSame(['hea001'], $elements['con001']['children']);

        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertSame($before, $this->state($post_id));
    }

    public function test_bad_operations_are_refused_and_write_nothing(): void
    {
        $post_id = $this->bricks_page();
        $before  = $this->state($post_id);

        $cases = [
            ['operation' => 'update', 'path' => 'nope00', 'attrs' => ['a' => 1]],
            ['operation' => 'update', 'path' => 'hea001'],
            ['operation' => 'add', 'to' => 'nope00', 'element' => ['name' => 'section']],
            ['operation' => 'add', 'to' => '', 'element' => ['settings' => []]],
            ['operation' => 'remove', 'path' => 'nope00'],
            ['operation' => 'move', 'path' => 'sec001', 'to' => 'hea001'],
            ['operation' => 'move', 'path' => 'sec001', 'to' => 'sec001'],
            ['operation' => 'explode'],
        ];
        foreach ($cases as $case) {
            $out = $this->hashed($post_id, $case);
            $this->assertWPError($out, wp_json_encode($case));
            $this->assertSame('invalid_bricks_request', $out->get_error_code(), wp_json_encode($case));
        }
        $this->assertSame($before, $this->state($post_id));
    }

    public function test_tree_refuses_a_stored_list_that_is_not_a_tree(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Bricks_Tree::index([['id' => 'a', 'name' => 'section', 'parent' => 'zzz', 'children' => []]]);
    }

    // ------------------------------------------------------------- templates

    public function test_add_from_a_template_copies_its_elements_with_new_ids_and_rolls_back(): void
    {
        $this->register_template_type();
        $post_id     = $this->bricks_page();
        $template_id = self::factory()->post->create(['post_type' => 'bricks_template', 'post_status' => 'publish']);
        update_post_meta($template_id, '_bricks_template_type', 'section');
        update_post_meta($template_id, '_bricks_page_content_2', [
            ['id' => 'tsec01', 'name' => 'section', 'parent' => 0, 'children' => ['thea01'], 'settings' => []],
            ['id' => 'thea01', 'name' => 'heading', 'parent' => 'tsec01', 'children' => [], 'settings' => ['text' => 'From template']],
        ]);
        $before = $this->state($post_id);

        $out = $this->hashed($post_id, ['operation' => 'add', 'to' => '', 'index' => 1, 'element' => ['template' => $template_id]]);

        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
        $elements = $this->by_id($post_id);
        $this->assertCount(7, $elements);
        $this->assertArrayNotHasKey('tsec01', $elements, 'Template elements get new ids.');
        $copy = $elements[$out['path']];
        $this->assertSame('section', $copy['name']);
        $this->assertSame('From template', $elements[$copy['children'][0]]['settings']['text']);

        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertSame($before, $this->state($post_id));
    }

    public function test_add_from_something_that_is_not_a_template_is_refused(): void
    {
        $post_id = $this->bricks_page();
        $other   = self::factory()->post->create(['post_type' => 'post']);

        $out = $this->hashed($post_id, ['operation' => 'add', 'to' => '', 'element' => ['template' => $other]]);

        $this->assertWPError($out);
        $this->assertSame('invalid_bricks_request', $out->get_error_code());
    }

    public function test_save_template_saves_an_element_as_a_section_template_and_rollback_trashes_it(): void
    {
        $this->register_template_type();
        $post_id = $this->bricks_page();
        $before  = $this->state($post_id);

        $out = $this->update($post_id, ['operation' => 'save_template', 'path' => 'con001', 'element' => ['title' => 'Card row']]);

        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
        $template = get_post($out['template_id']);
        $this->assertSame('bricks_template', $template->post_type);
        $this->assertSame('Card row', $template->post_title);
        $this->assertSame('section', get_post_meta($template->ID, '_bricks_template_type', true));
        $saved = (array) get_post_meta($template->ID, '_bricks_page_content_2', true);
        $this->assertCount(3, $saved);
        $this->assertSame('container', $saved[0]['name']);
        $this->assertSame(0, $saved[0]['parent']);
        $this->assertNotSame('con001', $saved[0]['id']);
        $this->assertSame($before, $this->state($post_id), 'The page is left as it is.');

        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertNotSame('publish', get_post_status($template->ID));
    }

    public function test_save_template_refusals(): void
    {
        $post_id = $this->bricks_page();

        $no_type = $this->update($post_id, ['operation' => 'save_template', 'element' => ['title' => 'X']]);
        $this->register_template_type();
        $no_title = $this->update($post_id, ['operation' => 'save_template', 'element' => []]);
        $no_node  = $this->update($post_id, ['operation' => 'save_template', 'path' => 'nope00', 'element' => ['title' => 'X']]);

        $this->assertSame('builder_not_loaded', $no_type->get_error_code());
        $this->assertSame('invalid_bricks_request', $no_title->get_error_code());
        $this->assertSame('invalid_bricks_request', $no_node->get_error_code());
    }

    // ------------------------------------------------- revisions and publish

    public function test_restore_puts_back_a_revisions_layout_and_rolls_back(): void
    {
        $post_id  = $this->bricks_page();
        $revision = _wp_put_post_revision(get_post($post_id));
        add_metadata('post', $revision, '_bricks_page_content_2', wp_slash([self::PAGE[4]]));
        $before = $this->state($post_id);

        $out = $this->hashed($post_id, ['operation' => 'restore', 'path' => (string) $revision]);

        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
        $this->assertSame(['sec002'], array_keys($this->by_id($post_id)));

        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertSame($before, $this->state($post_id));
    }

    public function test_restore_refuses_what_is_not_a_revision_of_this_page(): void
    {
        $post_id = $this->bricks_page();
        $other   = $this->bricks_page();
        $foreign = _wp_put_post_revision(get_post($other));
        add_metadata('post', $foreign, '_bricks_page_content_2', wp_slash(self::PAGE));
        $empty = _wp_put_post_revision(get_post($post_id));

        foreach (['', 'abc', (string) $other, (string) $foreign, (string) $empty] as $path) {
            $out = $this->hashed($post_id, ['operation' => 'restore', 'path' => $path]);
            $this->assertWPError($out, $path);
            $this->assertSame('invalid_bricks_request', $out->get_error_code(), $path);
        }
    }

    public function test_publish_sets_a_draft_live_and_rolls_back(): void
    {
        $post_id = $this->bricks_page(self::PAGE, 'draft');

        $out = $this->hashed($post_id, ['operation' => 'publish']);

        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
        $this->assertSame('publish', get_post_status($post_id));

        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertSame('draft', get_post_status($post_id));
    }

    public function test_publish_refuses_a_published_page(): void
    {
        $post_id = $this->bricks_page();

        $out = $this->hashed($post_id, ['operation' => 'publish']);

        $this->assertWPError($out);
        $this->assertSame('already_published', $out->get_error_code());
    }
}
