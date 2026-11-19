<?php

namespace WPMCP\Tests\Pro\Builders;

use WPMCP\Pro\Gate;
use WPMCP\Safety\{Rollback_Service, Snapshot_Store};
use WPMCP\Tools\Builders\{Breakdance_Page, Builder_Design, Get_Builder_Content, Update_Builder_Content};

/**
 * Issue #634: Breakdance element-level editing on the builder pair, beyond
 * the node operations BreakdanceAdapterTest covers: the content hash every
 * write to a page with a layout is pinned to, the plugin_missing refusal,
 * the version and revisions on the read, template hashes in scope
 * templates, adding from a template, saving a node or page as a Global
 * Block, restoring a revision, and publish, on both the Breakdance 2 row
 * (`_breakdance_data`) and the 1.x row (`breakdance_data`). Every write is
 * undone.
 *
 * Breakdance is not installed here: its rows are stored raw in the shape
 * its editor writes (see BreakdanceTreeTest::fixture_json()), its presence
 * is toggled through wpmcp_breakdance_active, and the Global Block post type
 * is registered the way Breakdance registers it when a test needs it.
 */
class BreakdanceElementEditingTest extends \WP_UnitTestCase
{
    /** A Global Block document, the way Breakdance's saveGlobalBlock() stores one. */
    private const BLOCK_TREE = '{"root":{"id":1,"data":{"type":"root","properties":[]},"children":['
        . '{"id":100,"data":{"type":"EssentialElements\\\\Section","properties":null},"children":['
        . '{"id":101,"data":{"type":"EssentialElements\\\\Text","properties":{"content":{"content":{"text":"From the block"}}}},"children":[],"_parentId":100}'
        . '],"_parentId":1},'
        . '{"id":102,"data":{"type":"EssentialElements\\\\Text","properties":{"content":{"content":{"text":"Second top node"}}}},"children":[],"_parentId":1}'
        . ']},"_nextNodeId":103,"status":"exported"}';

    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
        Snapshot_Store::install();
        $GLOBALS['wpmcp_breakdance_cache_calls'] = [];
        add_filter('wpmcp_breakdance_active', '__return_true');
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    protected function tearDown(): void
    {
        Gate::set_pro_for_tests(null);
        remove_all_filters('wpmcp_breakdance_active');
        if (post_type_exists('breakdance_block')) {
            unregister_post_type('breakdance_block');
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

    private function breakdance_post(string $type = 'page', string $status = 'publish', string $tree = '', string $key = '_breakdance_data'): int
    {
        $id = self::factory()->post->create(['post_type' => $type, 'post_status' => $status]);
        $this->raw_meta($id, $key, '' === $tree ? BreakdanceTreeTest::fixture_meta() : (string) wp_json_encode(['tree_json_string' => $tree]));

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
        return (new Update_Builder_Content())->handle(['post_id' => $post_id, 'builder' => 'breakdance'] + $args);
    }

    private function hashed(int $post_id, array $args)
    {
        return $this->update($post_id, $args + ['expected_hash' => $this->read($post_id)['hash']]);
    }

    private function register_block_type(): void
    {
        register_post_type('breakdance_block', ['public' => false, 'capability_type' => 'post']);
    }

    // ------------------------------------------------------------------ read

    public function test_read_returns_hash_of_the_stored_row_version_and_revisions(): void
    {
        $post_id = $this->breakdance_post();

        $out = $this->read($post_id);

        $this->assertSame('breakdance', $out['builder']);
        $this->assertSame(hash('sha256', BreakdanceTreeTest::fixture_meta()), $out['hash']);
        $this->assertArrayHasKey('plugin_version', $out);
        $this->assertNull($out['plugin_version'], 'No Breakdance constant is defined here.');
        $this->assertSame([], $out['revisions']);
        $this->assertArrayNotHasKey('version_note', $out);
    }

    public function test_read_hashes_the_unprefixed_breakdance_1_row(): void
    {
        $post_id = $this->breakdance_post('page', 'publish', '', 'breakdance_data');

        $this->assertSame(hash('sha256', BreakdanceTreeTest::fixture_meta()), $this->read($post_id)['hash']);
    }

    public function test_version_note_flags_a_major_version_other_than_one_or_two(): void
    {
        $this->assertNull(Breakdance_Page::version_note('breakdance', null));
        $this->assertNull(Breakdance_Page::version_note('breakdance', '1.7.2'));
        $this->assertNull(Breakdance_Page::version_note('breakdance', '2.5.0'));
        $this->assertStringContainsString('Breakdance 3.0', (string) Breakdance_Page::version_note('breakdance', '3.0'));
    }

    public function test_read_lists_only_revisions_that_hold_a_breakdance_layout(): void
    {
        $post_id = $this->breakdance_post();
        $with    = _wp_put_post_revision(get_post($post_id));
        add_metadata('post', $with, '_breakdance_data', wp_slash(BreakdanceTreeTest::fixture_meta()));
        $without = _wp_put_post_revision(get_post($post_id));

        $revisions = $this->read($post_id)['revisions'];

        $this->assertSame([$with], array_column($revisions, 'id'));
        $this->assertSame(hash('sha256', BreakdanceTreeTest::fixture_meta()), $revisions[0]['hash']);
        $this->assertNotSame($with, $without);
    }

    // ----------------------------------------------------------------- hash

    public function test_writes_to_a_page_with_a_layout_need_the_current_hash(): void
    {
        $post_id = $this->breakdance_post();
        $before  = $this->state($post_id);
        $args    = ['operation' => 'remove', 'path' => '111'];

        $missing = $this->update($post_id, $args);
        $stale   = $this->update($post_id, $args + ['expected_hash' => hash('sha256', 'old')]);
        $whole   = $this->update($post_id, ['content' => '[]']);

        $this->assertSame('missing_expected_hash', $missing->get_error_code());
        $this->assertStringContainsString('a Breakdance page', $missing->get_error_message());
        $this->assertSame('stale_expected_hash', $stale->get_error_code());
        $this->assertSame('missing_expected_hash', $whole->get_error_code(), 'A whole-tree write is pinned too.');
        $this->assertSame($before, $this->state($post_id));

        $out = $this->hashed($post_id, $args);
        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
        $this->assertSame($this->read($post_id)['hash'], $out['hash'], 'The result hash chains into the next write.');
        $next = $this->update($post_id, ['operation' => 'remove', 'path' => '103', 'expected_hash' => $out['hash']]);
        $this->assertIsArray($next);

        Rollback_Service::restore_operation($next['operation_id']);
        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertSame($before, $this->state($post_id));
    }

    public function test_a_blank_page_needs_no_hash(): void
    {
        $post_id = self::factory()->post->create(['post_type' => 'page', 'post_content' => '']);

        $out = $this->update($post_id, ['operation' => 'add', 'to' => '', 'element' => ['type' => 'EssentialElements\\Text']]);

        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
        $this->assertSame(Breakdance_Page::hash($post_id, 'breakdance'), $out['hash']);
    }

    public function test_a_breakdance_1_page_is_written_against_its_own_row(): void
    {
        $post_id = $this->breakdance_post('page', 'publish', '', 'breakdance_data');
        $before  = $this->state($post_id);

        $out = $this->hashed($post_id, ['operation' => 'remove', 'path' => '111']);

        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
        $this->assertNull($this->raw($post_id, '_breakdance_data'), 'No second row is created.');
        $this->assertSame(hash('sha256', (string) $this->raw($post_id, 'breakdance_data')), $out['hash']);

        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertSame($before, $this->state($post_id));
    }

    // ----------------------------------------------------------- availability

    public function test_writes_are_refused_with_plugin_missing_when_breakdance_is_not_active(): void
    {
        $post_id = $this->breakdance_post();
        $before  = $this->state($post_id);
        remove_all_filters('wpmcp_breakdance_active');
        add_filter('wpmcp_breakdance_active', '__return_false');

        foreach ([['operation' => 'remove', 'path' => '111'], ['operation' => 'save_template', 'element' => ['title' => 'X']], ['operation' => 'publish']] as $args) {
            $out = $this->hashed($post_id, $args);
            $this->assertInstanceOf(\WP_Error::class, $out);
            $this->assertSame('plugin_missing', $out->get_error_code());
            $this->assertStringContainsString('Install and activate Breakdance', $out->get_error_message());
            $this->assertSame('breakdance', $out->get_error_data()['plugin']);
            $this->assertSame(admin_url('plugins.php'), $out->get_error_data()['url']);
        }
        $this->assertSame($before, $this->state($post_id));

        // Reads still work.
        $this->assertFalse($this->read($post_id)['plugin_active']);
    }

    // ------------------------------------------------------------ templates

    public function test_templates_scope_lists_breakdance_kinds_with_hashes(): void
    {
        $template = $this->breakdance_post('breakdance_template');
        $block    = $this->breakdance_post('breakdance_block', 'publish', self::BLOCK_TREE);
        $popup    = $this->breakdance_post('breakdance_popup');
        $part     = $this->breakdance_post('breakdance_part');

        $out   = Builder_Design::read(['scope' => 'templates', 'builder' => 'breakdance']);
        $by_id = array_column($out['templates'], null, 'id');

        $this->assertSame('template', $by_id[ $template ]['type']);
        $this->assertSame('block', $by_id[ $block ]['type']);
        $this->assertSame('popup', $by_id[ $popup ]['type']);
        $this->assertSame('part', $by_id[ $part ]['type']);
        $this->assertSame(Breakdance_Page::hash($block, 'breakdance'), $by_id[ $block ]['hash']);
        $this->assertSame(100, $by_id[ $block ]['tree'][0]->id);
    }

    public function test_add_from_a_template_copies_its_nodes_with_new_ids_and_rolls_back(): void
    {
        $post_id = $this->breakdance_post();
        $block   = $this->breakdance_post('breakdance_block', 'publish', self::BLOCK_TREE);
        $before  = $this->state($post_id);

        $out = $this->hashed($post_id, ['operation' => 'add', 'to' => '', 'index' => 1, 'element' => ['template' => $block]]);

        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
        $this->assertSame('112', $out['path']);
        $tree = $this->read($post_id)['tree'];
        $this->assertSame([100, 112, 114, 103, 111], array_map(static fn ($n) => $n->id, $tree));
        $this->assertSame('From the block', $tree[1]->children[0]->data->properties->content->content->text);
        $this->assertSame(113, $tree[1]->children[0]->id);
        $this->assertSame(112, $tree[1]->children[0]->_parentId);
        $this->assertSame(1, $tree[2]->_parentId);
        $this->assertSame(115, $this->read($post_id)['next_node_id']);

        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertSame($before, $this->state($post_id));
    }

    public function test_bad_inserts_are_refused_and_write_nothing(): void
    {
        $post_id  = $this->breakdance_post();
        $ordinary = self::factory()->post->create(['post_type' => 'page']);
        $template = $this->breakdance_post('breakdance_template');
        $oxygen   = $this->breakdance_post('oxygen_block', 'publish', self::BLOCK_TREE);
        $before   = $this->state($post_id);

        foreach ([
            ['template' => $ordinary],
            ['template' => 999999],
            ['template' => $oxygen],
            ['template' => 'abc'],
            ['template' => $template, 'type' => 'x'],
        ] as $element) {
            $out = $this->hashed($post_id, ['operation' => 'add', 'to' => '', 'element' => $element]);
            $this->assertInstanceOf(\WP_Error::class, $out, (string) wp_json_encode($element));
            $this->assertSame('invalid_breakdance_request', $out->get_error_code());
        }
        $this->assertSame($before, $this->state($post_id));
    }

    // -------------------------------------------------------- save_template

    public function test_save_template_saves_a_node_as_a_global_block_and_rollback_trashes_it(): void
    {
        $this->register_block_type();
        $post_id = $this->breakdance_post();
        $before  = $this->state($post_id);

        $out = $this->update($post_id, ['operation' => 'save_template', 'path' => '100', 'element' => ['title' => 'Hero']]);

        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
        $this->assertSame('breakdance', $out['builder']);
        $block = get_post($out['template_id']);
        $this->assertSame('breakdance_block', $block->post_type);
        $this->assertSame('publish', $block->post_status);
        $this->assertSame('Hero', $block->post_title);
        $doc = json_decode((string) json_decode((string) $this->raw($block->ID, '_breakdance_data'))->tree_json_string);
        $this->assertSame(1, $doc->root->id);
        $this->assertSame([100], array_map(static fn ($n) => $n->id, $doc->root->children));
        $this->assertSame([101, 102], array_map(static fn ($n) => $n->id, $doc->root->children[0]->children));
        $this->assertSame('About the studio', $doc->root->children[0]->children[0]->data->properties->content->content->text);
        $this->assertSame(103, $doc->_nextNodeId);
        $this->assertSame(Breakdance_Page::hash($block->ID, 'breakdance'), $out['hash']);
        $this->assertSame($before, $this->state($post_id), 'The page is left as it is.');

        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertSame('trash', get_post_status($block->ID));
    }

    public function test_save_template_without_a_path_saves_the_whole_page(): void
    {
        $this->register_block_type();
        $post_id = $this->breakdance_post();

        $out = $this->update($post_id, ['operation' => 'save_template', 'element' => ['title' => 'Whole page']]);

        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
        $outer = json_decode((string) $this->raw($out['template_id'], '_breakdance_data'));
        $this->assertSame(BreakdanceTreeTest::fixture_json(), $outer->tree_json_string);
    }

    public function test_save_template_refusals(): void
    {
        $post_id = $this->breakdance_post();

        $this->assertSame('builder_not_loaded', $this->update($post_id, ['operation' => 'save_template', 'element' => ['title' => 'X']])->get_error_code());

        $this->register_block_type();
        $this->assertSame('invalid_breakdance_request', $this->update($post_id, ['operation' => 'save_template'])->get_error_code());
        $this->assertSame('invalid_breakdance_request', $this->update($post_id, ['operation' => 'save_template', 'path' => '999', 'element' => ['title' => 'X']])->get_error_code());
        $this->assertSame([], get_posts(['post_type' => 'breakdance_block', 'post_status' => 'any']));
    }

    public function test_save_template_needs_publish_posts_for_the_block_type(): void
    {
        $this->register_block_type();
        $contributor = self::factory()->user->create(['role' => 'contributor']);
        wp_set_current_user($contributor);
        $post_id = self::factory()->post->create(['post_type' => 'post', 'post_status' => 'draft', 'post_author' => $contributor]);
        $this->raw_meta($post_id, '_breakdance_data', BreakdanceTreeTest::fixture_meta());
        $before    = $this->state($post_id);
        $snapshots = Snapshot_Store::row_count();

        $out = $this->update($post_id, ['operation' => 'save_template', 'element' => ['title' => 'Site-wide']]);

        $this->assertWPError($out);
        $this->assertSame('forbidden', $out->get_error_code());
        $this->assertStringContainsString('publish_posts', $out->get_error_message());
        $this->assertSame([], get_posts(['post_type' => 'breakdance_block', 'post_status' => 'any']));
        $this->assertSame($before, $this->state($post_id));
        $this->assertSame($snapshots, Snapshot_Store::row_count(), 'No snapshot is taken for a refused save.');
    }

    // -------------------------------------------------------------- restore

    public function test_restore_puts_back_a_revisions_layout_and_rolls_back(): void
    {
        $post_id  = $this->breakdance_post();
        $revision = _wp_put_post_revision(get_post($post_id));
        add_metadata('post', $revision, '_breakdance_data', wp_slash((string) wp_json_encode(['tree_json_string' => self::BLOCK_TREE])));
        $before = $this->state($post_id);

        $out = $this->hashed($post_id, ['operation' => 'restore', 'path' => (string) $revision]);

        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
        $outer = json_decode((string) $this->raw($post_id, '_breakdance_data'));
        $this->assertSame(self::BLOCK_TREE, $outer->tree_json_string);

        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertSame($before, $this->state($post_id));
    }

    public function test_restore_refuses_what_is_not_a_revision_of_this_page(): void
    {
        $post_id = $this->breakdance_post();
        $other   = $this->breakdance_post();
        $foreign = _wp_put_post_revision(get_post($other));
        add_metadata('post', $foreign, '_breakdance_data', wp_slash(BreakdanceTreeTest::fixture_meta()));
        $empty  = _wp_put_post_revision(get_post($post_id));
        $before = $this->state($post_id);

        foreach (['', 'abc', (string) $foreign, (string) $empty, (string) $other] as $path) {
            $out = $this->hashed($post_id, ['operation' => 'restore', 'path' => $path]);
            $this->assertSame('invalid_breakdance_request', $out->get_error_code(), $path);
        }
        $this->assertSame($before, $this->state($post_id));
    }

    // -------------------------------------------------------------- publish

    public function test_publish_sets_a_draft_live_and_rolls_back(): void
    {
        $post_id = $this->breakdance_post('page', 'draft');
        $before  = $this->state($post_id);

        $out = $this->hashed($post_id, ['operation' => 'publish']);

        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
        $this->assertSame('publish', get_post_status($post_id));
        $this->assertSame(BreakdanceTreeTest::fixture_meta(), $this->raw($post_id, '_breakdance_data'));

        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertSame($before, $this->state($post_id));

        $published = $this->breakdance_post();
        $this->assertSame('already_published', $this->hashed($published, ['operation' => 'publish'])->get_error_code());
    }

    public function test_unknown_operation_names_the_page_operations(): void
    {
        $out = $this->hashed($this->breakdance_post(), ['operation' => 'frobnicate']);

        $this->assertSame('invalid_breakdance_request', $out->get_error_code());
        $this->assertStringContainsString('save_template', $out->get_error_message());
    }
}
