<?php

namespace WPMCP\Tests\Free\Sync;

use WPMCP\Safety\Safe_Mutation;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Sync\Build_Change_Set;
use WPMCP\Tools\Sync\Change_Set_Builder;
use WPMCP\Tools\Sync\Change_Set_Format;

/**
 * Phase 1 of local-live sync (issue #192), the parts that make a change set
 * safe to push: explicit selection, the base revision conflict detection
 * needs, a deterministic artifact, and dependency resolution beyond media
 * (terms, synced patterns, template parts, Elementor templates and global
 * classes, theme mods).
 */
class ChangeSetDependenciesTest extends \WP_UnitTestCase
{
    private const PNG_B64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    private function session_edit(int $post_id, array $fields, string $session = 'build'): void
    {
        Safe_Mutation::run(
            ['object_type' => 'post', 'object_id' => $post_id, 'session_id' => $session, 'tool_name' => 'update-post', 'args' => $fields],
            static fn () => wp_update_post(wp_slash(array_merge(['ID' => $post_id], $fields)))
        );
    }

    private function object(array $set, string $key): array
    {
        foreach ($set['objects'] as $object) {
            if ($object['key'] === $key) {
                return $object;
            }
        }
        $this->fail("{$key} is not in the change set");
    }

    public function test_building_the_same_state_twice_yields_the_same_checksum(): void
    {
        $a = self::factory()->post->create(['post_content' => 'a']);
        $b = self::factory()->post->create(['post_content' => 'b']);
        $this->session_edit($b, ['post_content' => 'b2']);
        $this->session_edit($a, ['post_content' => 'a2']);

        $first  = (new Change_Set_Builder())->build(['session_id' => 'build']);
        $second = (new Change_Set_Builder())->build(['session_id' => 'build']);

        $this->assertSame($first['checksum'], $second['checksum']);
        $this->assertSame(Change_Set_Format::checksum($first), $first['checksum']);
        $this->assertSame(['post:' . min($a, $b), 'post:' . max($a, $b)], wp_list_pluck($first['objects'], 'key'), 'Objects are in a stable order, not ledger order');
        Change_Set_Format::validate($first);
    }

    public function test_the_base_revision_is_the_state_before_the_session_touched_the_object(): void
    {
        $post = self::factory()->post->create(['post_content' => 'before']);
        $this->session_edit($post, ['post_content' => 'middle']);
        $this->session_edit($post, ['post_content' => 'after']);

        $object = (new Change_Set_Builder())->build(['session_id' => 'build'])['objects'][0];

        $this->assertSame('present', $object['base']['state']);
        $this->assertNotSame($object['base']['hash'], $object['hash']);
        $this->assertFalse($object['unchanged']);
        $this->assertSame('after', $object['data']['post_content']);
    }

    public function test_explicit_selection_exports_only_the_selected_objects_with_an_unknown_base(): void
    {
        $picked = self::factory()->post->create(['post_title' => 'Picked']);
        $other  = self::factory()->post->create(['post_title' => 'Other']);
        $this->session_edit($other, ['post_content' => 'touched but not selected'], 'unrelated');

        $set = (new Change_Set_Builder())->build(['objects' => ['post:' . $picked]]);

        $this->assertSame(['post:' . $picked], wp_list_pluck($set['objects'], 'key'));
        $this->assertSame('unknown', $set['objects'][0]['base']['state'], 'No ledger row means no base: the apply side must not assume the target is unmodified');
    }

    public function test_an_unreadable_selection_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Change_Set_Builder())->build(['objects' => ['users:1']]);
    }

    public function test_a_live_side_post_type_is_never_exported(): void
    {
        // A store-specific live-side type, declared the way an integration
        // would (the built-in list already covers WooCommerce's own types).
        register_post_type('sync_test_entry');
        $filter = static fn (array $types) => array_merge($types, ['sync_test_entry']);
        add_filter('wpmcp_sync_non_syncable_post_types', $filter);

        $entry = self::factory()->post->create(['post_type' => 'sync_test_entry']);
        $this->session_edit($entry, ['post_content' => 'entry edit']);

        $set = (new Change_Set_Builder())->build(['session_id' => 'build']);

        remove_filter('wpmcp_sync_non_syncable_post_types', $filter);
        unregister_post_type('sync_test_entry');

        $this->assertSame([], $set['objects']);
        $this->assertStringContainsString('by design', $set['excluded'][0]['reason']);
    }

    public function test_attachments_travel_as_bytes_with_their_path_and_checksum(): void
    {
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- test fixture bytes.
        $bytes  = base64_decode(self::PNG_B64);
        $upload = wp_upload_bits('dep-pixel.png', null, $bytes);
        $image  = (int) wp_insert_attachment(['post_mime_type' => 'image/png', 'post_title' => 'Pixel', 'post_status' => 'inherit'], $upload['file']);
        $page   = self::factory()->post->create(['post_content' => 'x']);
        $this->session_edit($page, ['post_content' => '<img class="wp-image-' . $image . '">']);

        $set = (new Change_Set_Builder())->build(['session_id' => 'build']);
        $dep = $set['dependencies']['attachments'][0];

        $this->assertSame($image, $dep['object_id']);
        $this->assertSame(md5($bytes), $dep['checksum']);
        $this->assertSame(get_post_meta($image, '_wp_attached_file', true), $dep['relative_path']);
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- comparing against the artifact encoding.
        $this->assertSame(base64_encode($bytes), $dep['bytes']);
        $this->assertContains('attachment:' . $image, $this->object($set, 'post:' . $page)['requires']);
    }

    public function test_media_over_the_inline_cap_is_listed_without_bytes(): void
    {
        $upload = wp_upload_bits('big-pixel.png', null, str_repeat('x', 64));
        $image  = (int) wp_insert_attachment(['post_mime_type' => 'image/png', 'post_status' => 'inherit'], $upload['file']);
        $page   = self::factory()->post->create();
        $this->session_edit($page, ['post_content' => '<img class="wp-image-' . $image . '">']);

        $builder = new class extends Change_Set_Builder {
            protected function inline_media_max_bytes(): int
            {
                return 10;
            }
        };
        $dep = $builder->build(['session_id' => 'build'])['dependencies']['attachments'][0];

        $this->assertNull($dep['bytes']);
        $this->assertStringContainsString('cap', (string) $dep['bytes_omitted']);
    }

    public function test_terms_resolve_as_dependencies_with_their_parent_chain(): void
    {
        $parent = self::factory()->category->create(['slug' => 'dep-parent', 'name' => 'Parent']);
        $child  = self::factory()->category->create(['slug' => 'dep-child', 'name' => 'Child', 'parent' => $parent]);
        $post   = self::factory()->post->create();
        $this->session_edit($post, ['post_content' => 'filed']);
        wp_set_object_terms($post, [$child], 'category');

        $set   = (new Change_Set_Builder())->build(['session_id' => 'build']);
        $terms = [];
        foreach ($set['dependencies']['terms'] as $term) {
            $terms[ $term['slug'] ] = $term;
        }

        $this->assertArrayHasKey('dep-child', $terms);
        $this->assertArrayHasKey('dep-parent', $terms, 'A child term without its parent lands in the wrong place');
        $this->assertSame('dep-parent', $terms['dep-child']['parent']);
    }

    public function test_synced_patterns_and_database_template_parts_resolve_and_theme_patterns_are_listed(): void
    {
        $pattern = self::factory()->post->create(['post_type' => 'wp_block', 'post_title' => 'Promo', 'post_content' => 'promo body', 'post_status' => 'publish']);
        $page    = self::factory()->post->create(['post_type' => 'page']);
        $content = '<!-- wp:block {"ref":' . $pattern . '} /-->'
            . '<!-- wp:pattern {"slug":"theme/hero"} /-->';
        $this->session_edit($page, ['post_content' => $content]);

        $set = (new Change_Set_Builder())->build(['session_id' => 'build']);

        $this->assertSame(['post:' . $pattern], wp_list_pluck($set['dependencies']['posts'], 'key'));
        $this->assertSame('promo body', $set['dependencies']['posts'][0]['data']['post_content']);
        $this->assertContains('post:' . $pattern, $this->object($set, 'post:' . $page)['requires']);
        $this->assertContains(['kind' => 'pattern', 'ref' => 'theme/hero'], $set['dependencies']['external']);
    }

    public function test_elementor_templates_and_global_classes_resolve_from_the_element_tree(): void
    {
        $template = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'Saved section', 'post_status' => 'publish']);
        $page     = self::factory()->post->create(['post_type' => 'page']);
        $tree     = [[
            'id'       => 'a1',
            'elType'   => 'widget',
            'widgetType' => 'template',
            'settings' => [
                'template_id' => (string) $template,
                'classes'     => ['$$type' => 'classes', 'value' => ['g-brand', 'e-local']],
            ],
            'elements' => [],
        ]];
        update_post_meta($page, '_elementor_data', wp_slash(wp_json_encode($tree)));
        $this->session_edit($page, ['post_content' => 'elementor page']);

        $builder = new class extends Change_Set_Builder {
            protected function global_class_items(): ?array
            {
                return ['g-brand' => ['id' => 'g-brand', 'label' => 'brand', 'type' => 'class', 'variants' => []]];
            }
        };
        $set = $builder->build(['session_id' => 'build']);

        $this->assertSame(['post:' . $template], wp_list_pluck($set['dependencies']['posts'], 'key'));
        $this->assertSame(['g-brand'], array_keys($set['dependencies']['elementor_global_classes']));
        $requires = $this->object($set, 'post:' . $page)['requires'];
        $this->assertContains('global_class:g-brand', $requires);
        $this->assertNotContains('global_class:e-local', $requires, 'A local style id is not a global class');
    }

    public function test_theme_mods_export_the_keys_the_session_changed(): void
    {
        $option = 'theme_mods_' . get_stylesheet();
        update_option($option, ['accent' => 'blue', 'layout' => 'wide']);
        Safe_Mutation::run(
            ['object_type' => 'option', 'object_id' => $option, 'session_id' => 'build', 'tool_name' => 'update-option', 'args' => []],
            static fn () => update_option($option, ['accent' => 'red', 'layout' => 'wide'])
        );
        Safe_Mutation::run(
            ['object_type' => 'option', 'object_id' => 'blogname', 'session_id' => 'build', 'tool_name' => 'update-option', 'args' => []],
            static fn () => update_option('blogname', 'Local name')
        );

        $set    = (new Change_Set_Builder())->build(['session_id' => 'build']);
        $object = $this->object($set, 'option:' . $option);

        $this->assertSame(['accent'], $object['changed_keys']);
        $this->assertSame('red', $object['value']['accent']);
        $this->assertSame('blue', $object['base']['value']['accent']);

        $excluded = wp_list_pluck($set['excluded'], 'reason');
        $this->assertCount(1, $excluded, 'blogname is site configuration, never synced');
        $this->assertStringContainsString('by design', $excluded[0]);
    }

    public function test_dry_run_lists_what_would_be_pushed_without_writing_an_artifact(): void
    {
        $post = self::factory()->post->create(['post_title' => 'Preview me']);
        $this->session_edit($post, ['post_content' => 'changed']);

        $out = (new Build_Change_Set())->handle(['session_id' => 'build', 'dry_run' => true]);

        $this->assertTrue($out['dry_run']);
        $this->assertArrayNotHasKey('file', $out);
        $this->assertSame('post:' . $post, $out['objects'][0]['key']);
        $this->assertSame('Preview me', $out['objects'][0]['title']);
        $this->assertNotEmpty($out['checksum']);
    }
}
