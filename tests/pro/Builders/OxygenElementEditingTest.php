<?php

namespace WPMCP\Tests\Pro\Builders;

use WPMCP\Pro\Gate;
use WPMCP\Safety\{Rollback_Service, Snapshot_Store};
use WPMCP\Tools\Builders\{Builder_Design, Get_Builder_Content, Oxygen_Page, Update_Builder_Content};

/**
 * Issue #635: Oxygen 6 element-level editing on the builder pair, beyond
 * the node operations OxygenAdapterTest covers: the content hash every write
 * to a page with a layout is pinned to, the plugin_missing refusal, the
 * version and revisions on the read, Oxygen templates (Components and parts
 * included) in scope templates, adding from a template or a Component,
 * saving a node or page as a Component, restoring a revision, and publish.
 * Every write is undone.
 *
 * Oxygen is not installed here: its rows are stored raw in the shape the
 * Oxygen 6.1 source writes (see OxygenAdapterTest::fixture_json()), its
 * presence is toggled through wpmcp_oxygen_active, and the Component post
 * type is registered the way Oxygen registers it when a test needs it. The
 * cache generator stand-in lives in OxygenAdapterTest.
 */
class OxygenElementEditingTest extends \WP_UnitTestCase
{
    /** A Component document, the way Oxygen's saveGlobalBlock() stores one. */
    private const COMPONENT_TREE = '{"root":{"id":1,"data":{"type":"root","properties":{}},"children":['
        . '{"id":100,"data":{"type":"OxygenElements\\\\Container","properties":null},"children":['
        . '{"id":101,"data":{"type":"OxygenElements\\\\Text","properties":{"content":{"content":{"text":"From the Component"}}}},"children":[],"_parentId":100}'
        . '],"_parentId":1},'
        . '{"id":102,"data":{"type":"OxygenElements\\\\Text","properties":{"content":{"content":{"text":"Second top node"}}}},"children":[],"_parentId":1}'
        . ']},"_nextNodeId":103,"status":"exported"}';

    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
        Snapshot_Store::install();
        $GLOBALS['wpmcp_breakdance_cache_calls'] = [];
        add_filter('wpmcp_oxygen_active', '__return_true');
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    protected function tearDown(): void
    {
        Gate::set_pro_for_tests(null);
        remove_all_filters('wpmcp_oxygen_active');
        if (post_type_exists('oxygen_block')) {
            unregister_post_type('oxygen_block');
        }
        parent::tearDown();
    }

    private function raw_meta(int $post_id, string $key, string $value): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->postmeta, ['post_id' => $post_id, 'meta_key' => $key, 'meta_value' => $value]);
        wp_cache_delete($post_id, 'post_meta');
    }

    private function raw(int $post_id, string $key): ?string
    {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $post_id, $key));
    }

    private function oxygen_post(string $type = 'page', string $status = 'publish', string $tree = ''): int
    {
        $id = self::factory()->post->create(['post_type' => $type, 'post_status' => $status]);
        $this->raw_meta($id, '_oxygen_data', '' === $tree ? OxygenAdapterTest::fixture_meta() : (string) wp_json_encode(['tree_json_string' => $tree]));

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
        return (new Update_Builder_Content())->handle(['post_id' => $post_id, 'builder' => 'oxygen'] + $args);
    }

    private function hashed(int $post_id, array $args)
    {
        return $this->update($post_id, $args + ['expected_hash' => $this->read($post_id)['hash']]);
    }

    private function register_component_type(): void
    {
        register_post_type('oxygen_block', ['public' => false, 'capability_type' => 'post']);
    }

    // ------------------------------------------------------------------ read

    public function test_read_returns_hash_of_the_stored_row_version_and_revisions(): void
    {
        $post_id = $this->oxygen_post();

        $out = $this->read($post_id);

        $this->assertSame(hash('sha256', OxygenAdapterTest::fixture_meta()), $out['hash']);
        $this->assertArrayHasKey('plugin_version', $out);
        $this->assertNull($out['plugin_version'], 'No Oxygen constant is defined here.');
        $this->assertSame([], $out['revisions']);
        $this->assertArrayNotHasKey('version_note', $out);
    }

    public function test_version_note_flags_a_major_version_other_than_six(): void
    {
        $this->assertNull(Oxygen_Page::version_note(null));
        $this->assertNull(Oxygen_Page::version_note('6.1.0-beta.4'));
        $this->assertStringContainsString('Oxygen 7.0', (string) Oxygen_Page::version_note('7.0'));
    }

    public function test_read_lists_only_revisions_that_hold_an_oxygen_layout(): void
    {
        $post_id = $this->oxygen_post();
        $with    = _wp_put_post_revision(get_post($post_id));
        add_metadata('post', $with, '_oxygen_data', wp_slash(OxygenAdapterTest::fixture_meta()));
        $without = _wp_put_post_revision(get_post($post_id));

        $revisions = $this->read($post_id)['revisions'];

        $this->assertSame([$with], array_column($revisions, 'id'));
        $this->assertSame(hash('sha256', OxygenAdapterTest::fixture_meta()), $revisions[0]['hash']);
        $this->assertNotSame($with, $without);
    }

    // ----------------------------------------------------------------- hash

    public function test_writes_to_a_page_with_a_layout_need_the_current_hash(): void
    {
        $post_id = $this->oxygen_post();
        $before  = $this->state($post_id);
        $args    = ['operation' => 'remove', 'path' => '7'];

        $missing = $this->update($post_id, $args);
        $stale   = $this->update($post_id, $args + ['expected_hash' => hash('sha256', 'old')]);

        $this->assertSame('missing_expected_hash', $missing->get_error_code());
        $this->assertSame('stale_expected_hash', $stale->get_error_code());
        $this->assertSame($before, $this->state($post_id));

        $out = $this->hashed($post_id, $args);
        $this->assertIsArray($out);
        $this->assertSame($this->read($post_id)['hash'], $out['hash'], 'The result hash chains into the next write.');
        $next = $this->update($post_id, ['operation' => 'remove', 'path' => '4', 'expected_hash' => $out['hash']]);
        $this->assertIsArray($next);

        Rollback_Service::restore_operation($next['operation_id']);
        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertSame($before, $this->state($post_id));
    }

    public function test_a_blank_page_needs_no_hash(): void
    {
        $post_id = self::factory()->post->create(['post_type' => 'page', 'post_content' => '']);

        $out = $this->update($post_id, ['operation' => 'add', 'to' => '', 'element' => ['type' => 'OxygenElements\\Text']]);

        $this->assertIsArray($out);
        $this->assertSame(Oxygen_Page::hash($post_id), $out['hash']);
    }

    // ----------------------------------------------------------- availability

    public function test_writes_are_refused_with_plugin_missing_when_oxygen_is_not_active(): void
    {
        $post_id = $this->oxygen_post();
        $before  = $this->state($post_id);
        remove_all_filters('wpmcp_oxygen_active');
        add_filter('wpmcp_oxygen_active', '__return_false');

        foreach ([['operation' => 'remove', 'path' => '7'], ['operation' => 'save_template', 'element' => ['title' => 'X']], ['operation' => 'publish']] as $args) {
            $out = $this->hashed($post_id, $args);
            $this->assertInstanceOf(\WP_Error::class, $out);
            $this->assertSame('plugin_missing', $out->get_error_code());
            $this->assertStringContainsString('Install and activate Oxygen 6', $out->get_error_message());
            $this->assertSame(admin_url('plugins.php'), $out->get_error_data()['url']);
        }
        $this->assertSame($before, $this->state($post_id));

        // Reads still work.
        $this->assertFalse($this->read($post_id)['plugin_active']);
    }

    // ------------------------------------------------------------ templates

    public function test_templates_scope_lists_oxygen_kinds_with_hashes(): void
    {
        $template  = $this->oxygen_post('oxygen_template');
        $component = $this->oxygen_post('oxygen_block', 'publish', self::COMPONENT_TREE);
        $part      = $this->oxygen_post('oxygen_part');

        $out   = Builder_Design::read(['scope' => 'templates', 'builder' => 'oxygen']);
        $by_id = array_column($out['templates'], null, 'id');

        $this->assertSame('template', $by_id[ $template ]['type']);
        $this->assertSame('block', $by_id[ $component ]['type']);
        $this->assertSame('part', $by_id[ $part ]['type']);
        $this->assertSame(Oxygen_Page::hash($component), $by_id[ $component ]['hash']);
        $this->assertSame(100, $by_id[ $component ]['tree'][0]->id);
    }

    public function test_add_from_a_template_copies_its_nodes_with_new_ids_and_rolls_back(): void
    {
        $post_id   = $this->oxygen_post();
        $component = $this->oxygen_post('oxygen_block', 'publish', self::COMPONENT_TREE);
        $before    = $this->state($post_id);

        $out = $this->hashed($post_id, ['operation' => 'add', 'to' => '4', 'index' => 1, 'element' => ['template' => $component]]);

        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
        $this->assertSame('8', $out['path']);
        $children = $this->read($post_id)['tree'][1]->children;
        $this->assertSame([5, 8, 10, 6], array_map(static fn ($n) => $n->id, $children));
        $this->assertSame('From the Component', $children[1]->children[0]->data->properties->content->content->text);
        $this->assertSame(9, $children[1]->children[0]->id);
        $this->assertSame(8, $children[1]->children[0]->_parentId);
        $this->assertSame(4, $children[2]->_parentId);
        $this->assertSame(11, $this->read($post_id)['next_node_id']);

        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertSame($before, $this->state($post_id));
    }

    public function test_add_a_component_places_one_component_node_and_rolls_back(): void
    {
        $post_id   = $this->oxygen_post();
        $component = $this->oxygen_post('oxygen_block', 'publish', self::COMPONENT_TREE);
        $before    = $this->state($post_id);

        $out = $this->hashed($post_id, ['operation' => 'add', 'to' => '', 'element' => ['component' => (string) $component]]);

        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
        $node = $this->read($post_id)['tree'][3];
        $this->assertSame('OxygenElements\\Component', $node->data->type);
        $this->assertSame($component, $node->data->properties->content->content->block->componentId);
        $this->assertStringContainsString('"block":{"componentId":' . $component . ',"targets":[],"properties":{}}', (string) json_decode((string) $this->raw($post_id, '_oxygen_data'))->tree_json_string);

        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertSame($before, $this->state($post_id));
    }

    public function test_bad_inserts_are_refused_and_write_nothing(): void
    {
        $post_id  = $this->oxygen_post();
        $ordinary = self::factory()->post->create(['post_type' => 'page']);
        $template = $this->oxygen_post('oxygen_template');
        $before   = $this->state($post_id);

        foreach ([
            ['template' => $ordinary],
            ['template' => 999999],
            ['component' => $template],
            ['component' => 'abc'],
            ['template' => $template, 'type' => 'x'],
        ] as $element) {
            $out = $this->hashed($post_id, ['operation' => 'add', 'to' => '', 'element' => $element]);
            $this->assertInstanceOf(\WP_Error::class, $out, (string) wp_json_encode($element));
            $this->assertSame('invalid_oxygen_request', $out->get_error_code());
        }
        $this->assertSame($before, $this->state($post_id));
    }

    // -------------------------------------------------------- save_template

    public function test_save_template_saves_a_node_as_a_component_and_rollback_trashes_it(): void
    {
        $this->register_component_type();
        $post_id = $this->oxygen_post();
        $before  = $this->state($post_id);

        $out = $this->update($post_id, ['operation' => 'save_template', 'path' => '1', 'element' => ['title' => 'Hero']]);

        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
        $component = get_post($out['template_id']);
        $this->assertSame('oxygen_block', $component->post_type);
        $this->assertSame('publish', $component->post_status);
        $this->assertSame('Hero', $component->post_title);
        $doc = json_decode((string) json_decode((string) $this->raw($component->ID, '_oxygen_data'))->tree_json_string);
        $this->assertSame(1, $doc->root->id);
        $this->assertSame([100], array_map(static fn ($n) => $n->id, $doc->root->children));
        $this->assertSame([101, 102], array_map(static fn ($n) => $n->id, $doc->root->children[0]->children));
        $this->assertSame('selector-hero', $doc->root->children[0]->data->properties->meta->classes[0]);
        $this->assertSame(103, $doc->_nextNodeId);
        $this->assertSame($before, $this->state($post_id), 'The page is left as it is.');

        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertSame('trash', get_post_status($component->ID));
    }

    public function test_save_template_without_a_path_saves_the_whole_page(): void
    {
        $this->register_component_type();
        $post_id = $this->oxygen_post();

        $out = $this->update($post_id, ['operation' => 'save_template', 'element' => ['title' => 'Whole page']]);

        $this->assertIsArray($out);
        $outer = json_decode((string) $this->raw($out['template_id'], '_oxygen_data'));
        $this->assertSame(OxygenAdapterTest::fixture_json(), $outer->tree_json_string);
    }

    public function test_save_template_refusals(): void
    {
        $post_id = $this->oxygen_post();

        $this->assertSame('builder_not_loaded', $this->update($post_id, ['operation' => 'save_template', 'element' => ['title' => 'X']])->get_error_code());

        $this->register_component_type();
        $this->assertSame('invalid_oxygen_request', $this->update($post_id, ['operation' => 'save_template'])->get_error_code());
        $this->assertSame('invalid_oxygen_request', $this->update($post_id, ['operation' => 'save_template', 'path' => '99', 'element' => ['title' => 'X']])->get_error_code());
        $this->assertSame([], get_posts(['post_type' => 'oxygen_block', 'post_status' => 'any']));
    }

    // -------------------------------------------------------------- restore

    public function test_restore_puts_back_a_revisions_layout_and_rolls_back(): void
    {
        $post_id  = $this->oxygen_post();
        $revision = _wp_put_post_revision(get_post($post_id));
        add_metadata('post', $revision, '_oxygen_data', wp_slash((string) wp_json_encode(['tree_json_string' => self::COMPONENT_TREE])));
        $before = $this->state($post_id);

        $out = $this->hashed($post_id, ['operation' => 'restore', 'path' => (string) $revision]);

        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
        $outer = json_decode((string) $this->raw($post_id, '_oxygen_data'));
        $this->assertSame(self::COMPONENT_TREE, $outer->tree_json_string);

        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertSame($before, $this->state($post_id));
    }

    public function test_restore_refuses_what_is_not_a_revision_of_this_page(): void
    {
        $post_id = $this->oxygen_post();
        $other   = $this->oxygen_post();
        $foreign = _wp_put_post_revision(get_post($other));
        add_metadata('post', $foreign, '_oxygen_data', wp_slash(OxygenAdapterTest::fixture_meta()));
        $empty = _wp_put_post_revision(get_post($post_id));
        $before = $this->state($post_id);

        foreach (['', 'abc', (string) $foreign, (string) $empty, (string) $other] as $path) {
            $out = $this->hashed($post_id, ['operation' => 'restore', 'path' => $path]);
            $this->assertSame('invalid_oxygen_request', $out->get_error_code(), $path);
        }
        $this->assertSame($before, $this->state($post_id));
    }

    // -------------------------------------------------------------- publish

    public function test_publish_sets_a_draft_live_and_rolls_back(): void
    {
        $post_id = $this->oxygen_post('page', 'draft');
        $before  = $this->state($post_id);

        $out = $this->hashed($post_id, ['operation' => 'publish']);

        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
        $this->assertSame('publish', get_post_status($post_id));
        $this->assertSame(OxygenAdapterTest::fixture_meta(), $this->raw($post_id, '_oxygen_data'));

        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertSame($before, $this->state($post_id));

        $published = $this->oxygen_post();
        $this->assertSame('already_published', $this->hashed($published, ['operation' => 'publish'])->get_error_code());
    }

    public function test_unknown_operation_names_the_oxygen_operations(): void
    {
        $out = $this->hashed($this->oxygen_post(), ['operation' => 'frobnicate']);

        $this->assertSame('invalid_oxygen_request', $out->get_error_code());
        $this->assertStringContainsString('save_template', $out->get_error_message());
    }
}
