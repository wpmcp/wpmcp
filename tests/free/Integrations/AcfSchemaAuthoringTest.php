<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\ACF_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Safe_Mutation;
use WPMCP\Safety\Snapshot;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Rollback_Operation;

/**
 * ACF schema authoring through the acf dispatcher pair (issue #291): field
 * groups, ACF post types and taxonomies, options pages, field type
 * discovery, validation and the batch value write. Every write must route
 * through Safe_Mutation and roll back to exactly the rows it started from.
 */
class AcfSchemaAuthoringTest extends \WP_UnitTestCase
{
    private ACF_Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();
        if (! wpmcp_acf_active()) {
            $this->markTestSkipped('ACF not active');
        }
        Snapshot_Store::install();
        $this->integration = new ACF_Integration();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        add_filter('wpmcp_enable_acf_write', '__return_true');
        $this->resetAcf();
    }

    protected function tearDown(): void
    {
        remove_filter('wpmcp_enable_acf_write', '__return_true');
        Gate::set_pro_for_tests(null);
        $this->resetAcf();
        parent::tearDown();
    }

    private function resetAcf(): void
    {
        if (! function_exists('acf_get_store')) {
            return;
        }
        foreach ([ 'fields', 'field-groups', 'post-types', 'taxonomies', 'values' ] as $name) {
            $store = acf_get_store($name);
            if ($store) {
                $store->reset();
            }
        }
        wp_cache_flush();
    }

    private function write(string $op, array $args, array $extra = []): array
    {
        return (new ACF_Integration())->handle_write([ 'operation' => $op, 'args' => $args ] + $extra);
    }

    private function read(string $op, array $args = []): array
    {
        return (new ACF_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
    }

    /** Every database row of a structure tree, keyed by ID, for exact before/after comparison. */
    private function treeRows(string $key): array
    {
        $root = Snapshot::acf_structure_root_id($key);
        if (null === $root) {
            return [];
        }
        $rows = [];
        foreach (Snapshot::acf_structure_tree($root) as $id) {
            clean_post_cache($id);
            $post = get_post($id, ARRAY_A);
            unset($post['filter']);
            $rows[ $id ] = $post;
        }
        ksort($rows);
        return $rows;
    }

    private function rollback(array $out): void
    {
        $this->assertTrue($out['recoverable'], 'write must be recoverable');
        (new Rollback_Operation())->handle([ 'operation_id' => $out['operation_id'] ]);
        $this->resetAcf();
    }

    private function newGroup(): array
    {
        $out = $this->write('save-field-group', [
            'title'    => 'Book Details',
            'location' => [ [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ] ] ],
            'fields'   => [
                [ 'label' => 'Subtitle', 'name' => 'wpmcp_subtitle', 'type' => 'text' ],
                [ 'label' => 'Pages', 'name' => 'wpmcp_pages', 'type' => 'number', 'min' => 1, 'max' => 5000 ],
            ],
        ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $this->resetAcf();
        return $out;
    }

    // -------------------------------------------------------- registration

    public function test_pair_stays_free_and_catalogs_the_schema_ops(): void
    {
        foreach ($this->integration->abilities() as $ability) {
            $this->assertSame('free', $ability->tier);
        }

        $ops = array_column($this->read('list-operations')['result']['operations'], null, 'name');
        foreach ([ 'get-field-group', 'list-field-types', 'list-post-types', 'get-post-type', 'list-taxonomies', 'get-taxonomy', 'validate-fields', 'get-options' ] as $read) {
            $this->assertSame('read', $ops[ $read ]['mode'], $read);
        }
        foreach ([ 'save-field-group', 'save-post-type', 'save-taxonomy', 'update-options' ] as $write) {
            $this->assertSame('write', $ops[ $write ]['mode'], $write);
            $this->assertSame('manage_options', $ops[ $write ]['capability'], $write);
        }
    }

    public function test_structure_writes_follow_the_acf_write_opt_in(): void
    {
        remove_filter('wpmcp_enable_acf_write', '__return_true');

        $out = $this->write('save-field-group', [ 'title' => 'Off' ]);

        $this->assertSame('operation_disabled', $out['error']['code']);
    }

    public function test_structure_writes_need_acf_capability(): void
    {
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));

        $out = $this->write('save-field-group', [ 'title' => 'Nope' ]);

        $this->assertSame('operation_denied', $out['error']['code']);
        $this->assertSame('capability', $out['error']['data']['reason']);
    }

    public function test_every_op_refuses_cleanly_without_acf(): void
    {
        $absent = new class () extends ACF_Integration {
            public function is_available(): bool
            {
                return false;
            }
        };

        $this->assertSame('integration_unavailable', $absent->handle_write([ 'operation' => 'save-field-group', 'args' => [ 'title' => 'x' ] ])['error']['code']);
        $this->assertSame('integration_unavailable', $absent->handle_read([ 'operation' => 'list-field-types' ])['error']['code']);
        $this->assertFalse($absent->handle_read([ 'operation' => 'list-operations' ])['result']['available']);
    }

    // -------------------------------------------------------- field groups

    public function test_field_group_create_and_update_round_trip(): void
    {
        $created = $this->newGroup();
        $key     = $created['result']['key'];
        $this->assertStringStartsWith('group_', $key);

        $read = $this->read('get-field-group', [ 'key' => $key ])['result'];
        $this->assertSame('Book Details', $read['title']);
        $this->assertSame([ 'wpmcp_subtitle', 'wpmcp_pages' ], array_column($read['fields'], 'name'));
        $this->assertSame('number', $read['fields'][1]['type']);
        $this->assertEquals(5000, $read['fields'][1]['max']);
        $this->assertSame('post', $read['location'][0][0]['value']);

        $subtitle = $read['fields'][0];
        $updated  = $this->write('save-field-group', [
            'key'    => $key,
            'title'  => 'Book Facts',
            'fields' => [
                [ 'key' => $subtitle['key'], 'label' => 'Tagline', 'name' => 'wpmcp_subtitle', 'type' => 'text' ],
                [ 'label' => 'Contact', 'name' => 'wpmcp_contact', 'type' => 'email' ],
            ],
        ]);
        $this->assertArrayNotHasKey('error', $updated, wp_json_encode($updated));
        $this->resetAcf();

        $read = $this->read('get-field-group', [ 'key' => $key ])['result'];
        $this->assertSame('Book Facts', $read['title']);
        $this->assertSame([ 'wpmcp_subtitle', 'wpmcp_contact' ], array_column($read['fields'], 'name'));
        $this->assertSame($subtitle['key'], $read['fields'][0]['key'], 'a kept field keeps its key');
        $this->assertSame('Tagline', $read['fields'][0]['label']);
        $this->assertSame('post', $read['location'][0][0]['value'], 'omitted settings are kept');
    }

    public function test_settings_only_update_leaves_fields_untouched(): void
    {
        $key    = $this->newGroup()['result']['key'];
        $before = $this->read('get-field-group', [ 'key' => $key ])['result']['fields'];

        $this->write('save-field-group', [ 'key' => $key, 'title' => 'Renamed Only' ]);
        $this->resetAcf();

        $after = $this->read('get-field-group', [ 'key' => $key ])['result'];
        $this->assertSame('Renamed Only', $after['title']);
        $this->assertSame(array_column($before, 'key'), array_column($after['fields'], 'key'));
    }

    public function test_field_group_create_rolls_back_to_nothing(): void
    {
        $out = $this->newGroup();
        $key = $out['result']['key'];
        $this->assertCount(3, $this->treeRows($key));

        $this->rollback($out);

        $this->assertSame([], $this->treeRows($key));
        $this->assertSame('not_found', $this->read('get-field-group', [ 'key' => $key ])['error']['code']);
    }

    public function test_field_group_update_rolls_back_exactly(): void
    {
        $key    = $this->newGroup()['result']['key'];
        $fields = $this->read('get-field-group', [ 'key' => $key ])['result']['fields'];
        $before = $this->treeRows($key);

        $out = $this->write('save-field-group', [
            'key'    => $key,
            'title'  => 'Changed',
            'fields' => [
                [ 'key' => $fields[1]['key'], 'label' => 'Page Count', 'name' => 'wpmcp_pages', 'type' => 'number' ],
                [ 'label' => 'Added', 'name' => 'wpmcp_added', 'type' => 'text' ],
            ],
        ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $this->resetAcf();
        $this->assertNotEquals($before, $this->treeRows($key));

        $this->rollback($out);

        $this->assertEquals($before, $this->treeRows($key), 'deleted field resurrected, added field removed, changes undone');
        $read = $this->read('get-field-group', [ 'key' => $key ])['result'];
        $this->assertSame('Book Details', $read['title']);
        $this->assertSame([ 'wpmcp_subtitle', 'wpmcp_pages' ], array_column($read['fields'], 'name'));
    }

    public function test_nested_sub_fields_get_keys_and_round_trip(): void
    {
        $out = $this->write('save-field-group', [
            'title'  => 'Nested',
            'fields' => [
                [
                    'label'      => 'Author',
                    'name'       => 'wpmcp_author',
                    'type'       => 'group',
                    'sub_fields' => [ [ 'label' => 'Name', 'name' => 'name', 'type' => 'text' ] ],
                ],
            ],
        ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $this->resetAcf();

        $group = $this->read('get-field-group', [ 'key' => $out['result']['key'] ])['result'];
        $this->assertSame('name', $group['fields'][0]['sub_fields'][0]['name']);
        $this->assertStringStartsWith('field_', $group['fields'][0]['sub_fields'][0]['key']);
        $this->assertCount(3, $this->treeRows($out['result']['key']));
    }

    public function test_unknown_field_type_is_refused_before_any_write(): void
    {
        $before = Snapshot_Store::row_count();

        $out = $this->write('save-field-group', [
            'title'  => 'Bad',
            'fields' => [ [ 'label' => 'X', 'name' => 'x', 'type' => 'no_such_type' ] ],
        ]);

        $this->assertSame('invalid_field_type', $out['error']['code']);
        $this->assertSame($before, Snapshot_Store::row_count(), 'no snapshot written');
    }

    public function test_field_group_refusals(): void
    {
        $this->assertSame('invalid_key', $this->write('save-field-group', [ 'key' => 'nope', 'title' => 'x' ])['error']['code']);
        $this->assertSame('invalid_field_group', $this->write('save-field-group', [ 'fields' => [] ])['error']['code']);
        $this->assertSame('invalid_field', $this->write('save-field-group', [ 'title' => 't', 'fields' => [ [ 'type' => 'text', 'name' => 'n' ] ] ])['error']['code']);
        $this->assertSame('invalid_field', $this->write('save-field-group', [ 'title' => 't', 'fields' => [ [ 'type' => 'text', 'name' => 'n', 'label' => 'l', 'key' => 'bad' ] ] ])['error']['code']);
        $this->assertSame('not_found', $this->read('get-field-group', [ 'key' => 'group_missing' ])['error']['code']);

        acf_add_local_field_group([ 'key' => 'group_wpmcp_local_only', 'title' => 'Local', 'fields' => [] ]);
        $this->assertSame('local_definition', $this->write('save-field-group', [ 'key' => 'group_wpmcp_local_only', 'title' => 'x' ])['error']['code']);
    }

    public function test_list_field_types_includes_core_types(): void
    {
        $types = array_column($this->read('list-field-types')['result']['field_types'], 'name');

        foreach ([ 'text', 'number', 'email', 'select' ] as $type) {
            $this->assertContains($type, $types);
        }
    }

    // ---------------------------------------------- post types, taxonomies

    public function test_post_type_registration_round_trip_and_rollback(): void
    {
        $created = $this->write('save-post-type', [
            'post_type' => 'wpmcp_book',
            'title'     => 'Books',
            'labels'    => [ 'singular_name' => 'Book' ],
        ]);
        $this->assertArrayNotHasKey('error', $created, wp_json_encode($created));
        $key = $created['result']['key'];
        $this->assertStringStartsWith('post_type_', $key);
        $this->resetAcf();

        $listed = array_column($this->read('list-post-types')['result']['items'], null, 'key');
        $this->assertSame('wpmcp_book', $listed[ $key ]['post_type']);
        $read = $this->read('get-post-type', [ 'key' => $key ])['result'];
        $this->assertSame('Books', $read['title']);
        $this->assertSame('Book', $read['labels']['singular_name']);

        $before  = $this->treeRows($key);
        $updated = $this->write('save-post-type', [ 'key' => $key, 'title' => 'Novels', 'hierarchical' => true ]);
        $this->assertArrayNotHasKey('error', $updated, wp_json_encode($updated));
        $this->resetAcf();
        $read = $this->read('get-post-type', [ 'key' => $key ])['result'];
        $this->assertSame('Novels', $read['title']);
        $this->assertTrue((bool) $read['hierarchical']);
        $this->assertSame('Book', $read['labels']['singular_name'], 'labels merge, not replace');

        $this->rollback($updated);
        $this->assertEquals($before, $this->treeRows($key));
        $this->assertSame('Books', $this->read('get-post-type', [ 'key' => $key ])['result']['title']);

        $this->rollback($created);
        $this->assertSame([], $this->treeRows($key));
    }

    public function test_taxonomy_registration_round_trip_and_rollback(): void
    {
        $created = $this->write('save-taxonomy', [
            'taxonomy'    => 'wpmcp_genre',
            'title'       => 'Genres',
            'labels'      => [ 'singular_name' => 'Genre' ],
            'object_type' => [ 'post' ],
        ]);
        $this->assertArrayNotHasKey('error', $created, wp_json_encode($created));
        $key = $created['result']['key'];
        $this->assertStringStartsWith('taxonomy_', $key);
        $this->resetAcf();

        $listed = array_column($this->read('list-taxonomies')['result']['items'], null, 'key');
        $this->assertSame('wpmcp_genre', $listed[ $key ]['taxonomy']);
        $read = $this->read('get-taxonomy', [ 'key' => $key ])['result'];
        $this->assertSame([ 'post' ], $read['object_type']);

        $before  = $this->treeRows($key);
        $updated = $this->write('save-taxonomy', [ 'key' => $key, 'title' => 'Kinds' ]);
        $this->assertArrayNotHasKey('error', $updated, wp_json_encode($updated));
        $this->resetAcf();
        $this->assertSame('Kinds', $this->read('get-taxonomy', [ 'key' => $key ])['result']['title']);

        $this->rollback($updated);
        $this->assertEquals($before, $this->treeRows($key));

        $this->rollback($created);
        $this->assertSame([], $this->treeRows($key));
        $this->assertSame('not_found', $this->read('get-taxonomy', [ 'key' => $key ])['error']['code']);
    }

    public function test_registration_refusals(): void
    {
        $this->assertSame('invalid_post_type', $this->write('save-post-type', [ 'post_type' => 'Bad Slug!', 'title' => 'x' ])['error']['code']);
        $this->assertSame('invalid_post_type', $this->write('save-post-type', [ 'post_type' => str_repeat('a', 21), 'title' => 'x' ])['error']['code']);
        $this->assertSame('invalid_post_type', $this->write('save-post-type', [ 'post_type' => 'wpmcp_ok' ])['error']['code'], 'title required on create');
        register_post_type('wpmcp_outside');
        $this->assertSame('post_type_exists', $this->write('save-post-type', [ 'post_type' => 'wpmcp_outside', 'title' => 'x' ])['error']['code']);
        unregister_post_type('wpmcp_outside');
        $this->assertSame('invalid_post_type', $this->write('save-post-type', [ 'post_type' => 'page', 'title' => 'x' ])['error']['code'], 'reserved term');
        $this->assertSame('invalid_taxonomy', $this->write('save-taxonomy', [ 'taxonomy' => 'category', 'title' => 'x' ])['error']['code'], 'reserved term');
        $this->assertSame('invalid_key', $this->write('save-taxonomy', [ 'key' => 'group_x', 'taxonomy' => 'wpmcp_t', 'title' => 'x' ])['error']['code']);

        $first = $this->write('save-post-type', [ 'post_type' => 'wpmcp_dup', 'title' => 'Dups' ]);
        $this->assertArrayNotHasKey('error', $first);
        $this->resetAcf();
        $this->assertSame('post_type_exists', $this->write('save-post-type', [ 'post_type' => 'wpmcp_dup', 'title' => 'Again' ])['error']['code']);
    }

    // ------------------------------------------------------- validation

    private function localGroup(): void
    {
        acf_add_local_field_group([
            'key'      => 'group_wpmcp_validate',
            'title'    => 'Validate',
            'fields'   => [
                [ 'key' => 'field_wpmcp_v_num', 'label' => 'Num', 'name' => 'wpmcp_v_num', 'type' => 'number', 'min' => 1, 'max' => 10 ],
                [ 'key' => 'field_wpmcp_v_mail', 'label' => 'Mail', 'name' => 'wpmcp_v_mail', 'type' => 'email' ],
                [ 'key' => 'field_wpmcp_v_req', 'label' => 'Req', 'name' => 'wpmcp_v_req', 'type' => 'text', 'required' => 1 ],
            ],
            'location' => [ [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ] ] ],
        ]);
    }

    public function test_validate_fields_rejects_bad_values(): void
    {
        $this->localGroup();
        $post = self::factory()->post->create();

        $bad = $this->read('validate-fields', [
            'post_id' => $post,
            'fields'  => [ 'wpmcp_v_num' => 'abc', 'wpmcp_v_mail' => 'not-an-email', 'wpmcp_v_req' => '', 'wpmcp_nope' => 1 ],
        ])['result'];
        $this->assertFalse($bad['valid']);
        $this->assertEqualsCanonicalizing(
            [ 'wpmcp_v_num', 'wpmcp_v_mail', 'wpmcp_v_req', 'wpmcp_nope' ],
            array_unique(array_column($bad['errors'], 'field'))
        );

        $range = $this->read('validate-fields', [ 'fields' => [ 'field_wpmcp_v_num' => 50 ] ])['result'];
        $this->assertFalse($range['valid'], 'out of range by key');

        $good = $this->read('validate-fields', [
            'post_id' => $post,
            'fields'  => [ 'wpmcp_v_num' => 5, 'wpmcp_v_mail' => 'a@example.com', 'wpmcp_v_req' => 'x' ],
        ])['result'];
        $this->assertTrue($good['valid']);
        $this->assertSame([], $good['errors']);
    }

    // ------------------------------------------------------- batch update

    public function test_batch_update_is_hidden_without_pro(): void
    {
        Gate::set_pro_for_tests(false);

        $names = array_column($this->read('list-operations')['result']['operations'], 'name');
        $this->assertNotContains('batch-update-fields', $names);
        $out = $this->write('batch-update-fields', [ 'updates' => [ [ 'post_id' => 1, 'fields' => [ 'a' => 1 ] ] ] ]);
        $this->assertSame('unknown_operation', $out['error']['code']);
    }

    public function test_batch_update_is_one_session_and_rolls_back_exactly(): void
    {
        Gate::set_pro_for_tests(true);
        $this->localGroup();
        $a = self::factory()->post->create();
        $b = self::factory()->post->create();
        update_field('wpmcp_v_num', 2, $a);
        $meta_a = get_post_meta($a);
        $meta_b = get_post_meta($b);

        $out = $this->write('batch-update-fields', [
            'updates' => [
                [ 'post_id' => $a, 'fields' => [ 'wpmcp_v_num' => 7, 'wpmcp_v_req' => 'yes' ] ],
                [ 'post_id' => $b, 'fields' => [ 'wpmcp_v_mail' => 'b@example.com', 'wpmcp_v_req' => 'ok' ] ],
            ],
        ]);

        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $this->assertTrue($out['recoverable']);
        $this->assertCount(2, $out['operation_ids']);
        $session = $out['session_id'];
        $this->assertNotSame('default', $session);
        $this->assertCount(2, Snapshot_Store::list_by_session($session));
        $this->resetAcf();
        $this->assertEquals(7, get_field('wpmcp_v_num', $a));
        $this->assertSame('b@example.com', get_field('wpmcp_v_mail', $b));

        Rollback_Service::restore_session($session);
        $this->resetAcf();

        $this->assertEquals($meta_a, get_post_meta($a));
        $this->assertEquals($meta_b, get_post_meta($b));
        $this->assertEquals(2, get_field('wpmcp_v_num', $a));
    }

    public function test_batch_update_uses_the_callers_session(): void
    {
        Gate::set_pro_for_tests(true);
        $this->localGroup();
        $a = self::factory()->post->create();

        $out = $this->write('batch-update-fields', [ 'updates' => [ [ 'post_id' => $a, 'fields' => [ 'wpmcp_v_num' => 3 ] ] ] ], [ 'session_id' => 'wpmcp-batch-test' ]);

        $this->assertSame('wpmcp-batch-test', $out['session_id']);
        $this->assertCount(1, Snapshot_Store::list_by_session('wpmcp-batch-test'));
    }

    public function test_batch_update_refuses_the_whole_batch_on_one_bad_value(): void
    {
        Gate::set_pro_for_tests(true);
        $this->localGroup();
        $a      = self::factory()->post->create();
        $b      = self::factory()->post->create();
        $before = Snapshot_Store::row_count();

        $out = $this->write('batch-update-fields', [
            'updates' => [
                [ 'post_id' => $a, 'fields' => [ 'wpmcp_v_num' => 4 ] ],
                [ 'post_id' => $b, 'fields' => [ 'wpmcp_v_num' => 99 ] ],
                [ 'post_id' => 999999, 'fields' => [ 'wpmcp_v_num' => 4 ] ],
            ],
        ]);

        $this->assertSame('invalid_field_values', $out['error']['code']);
        $this->assertSame([ 1, 2 ], array_column($out['error']['data']['errors'], 'index'));
        $this->assertSame('', (string) get_post_meta($a, 'wpmcp_v_num', true), 'nothing written');
        $this->assertSame($before, Snapshot_Store::row_count(), 'no snapshot written');
    }

    // ------------------------------------------------------- options pages

    public function test_options_ops_refuse_cleanly_without_acf_pro(): void
    {
        if (function_exists('acf_get_options_pages')) {
            $this->markTestSkipped('ACF Pro is active; covered by the round trip test');
        }

        $ops = array_column($this->read('list-operations')['result']['operations'], null, 'name');
        $this->assertFalse($ops['get-options']['dependency_met']);
        $this->assertFalse($ops['update-options']['dependency_met']);
        $this->assertSame('acf_options_unavailable', $this->read('get-options')['error']['code']);
        $this->assertSame('acf_options_unavailable', $this->write('update-options', [ 'page' => 'x', 'fields' => [ 'a' => 1 ] ])['error']['code']);
    }

    public function test_options_page_read_and_write_round_trip(): void
    {
        if (! function_exists('acf_add_options_page')) {
            $this->markTestSkipped('Options pages need ACF Pro');
        }
        acf_add_options_page([ 'menu_slug' => 'wpmcp-settings', 'page_title' => 'WPMCP Settings', 'post_id' => 'options' ]);
        acf_add_local_field_group([
            'key'      => 'group_wpmcp_options',
            'title'    => 'Options',
            'fields'   => [ [ 'key' => 'field_wpmcp_opt_hero', 'label' => 'Hero', 'name' => 'wpmcp_hero', 'type' => 'text' ] ],
            'location' => [ [ [ 'param' => 'options_page', 'operator' => '==', 'value' => 'wpmcp-settings' ] ] ],
        ]);
        update_field('wpmcp_hero', 'before', 'options');

        $pages = array_column($this->read('get-options')['result']['pages'], 'page');
        $this->assertContains('wpmcp-settings', $pages);

        $out = $this->write('update-options', [ 'page' => 'wpmcp-settings', 'fields' => [ 'wpmcp_hero' => 'after' ] ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $this->resetAcf();
        $this->assertSame('after', $this->read('get-options', [ 'page' => 'wpmcp-settings' ])['result']['fields']['wpmcp_hero']);

        $this->rollback($out);
        $this->assertSame('before', get_field('wpmcp_hero', 'options'));
        $this->assertSame('not_found', $this->write('update-options', [ 'page' => 'no-such-page', 'fields' => [ 'a' => 1 ] ])['error']['code']);
    }

    /**
     * The acf_options snapshot does not depend on ACF Pro: it covers option
     * rows under field-name prefixes, which free ACF also writes for any
     * "options" post_id. Proven here so the restore is exercised on every run.
     */
    public function test_acf_options_snapshot_restores_rows_exactly(): void
    {
        update_option('options_wpmcp_hero', 'before');
        update_option('_options_wpmcp_hero', 'field_wpmcp_opt_hero');
        update_option('options_wpmcp_list_0_item', 'one');
        $id = Snapshot::acf_options_id('options', [ 'wpmcp_list', 'wpmcp_hero' ]);
        $this->assertSame('options|wpmcp_hero,wpmcp_list', $id);

        $out = Safe_Mutation::run(
            [ 'object_type' => 'acf_options', 'object_id' => $id, 'session_id' => 's-opt', 'tool_name' => 'test' ],
            static function (): bool {
                update_option('options_wpmcp_hero', 'after');
                update_option('options_wpmcp_list_0_item', 'changed');
                update_option('options_wpmcp_list_1_item', 'added');
                return true;
            }
        );

        Rollback_Service::restore_session('s-opt');

        $this->assertSame('before', get_option('options_wpmcp_hero'));
        $this->assertSame('field_wpmcp_opt_hero', get_option('_options_wpmcp_hero'));
        $this->assertSame('one', get_option('options_wpmcp_list_0_item'));
        $this->assertFalse(get_option('options_wpmcp_list_1_item'), 'row added by the write is removed');
        $this->assertNotEmpty($out['operation_id']);
        $this->assertContains('acf_options', Rollback_Service::restorable_object_types());
        $this->assertContains('acf_structure', Rollback_Service::restorable_object_types());
    }

    public function test_structure_rollback_needs_acf_capability(): void
    {
        $out = $this->newGroup();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));

        $this->expectException(\WPMCP\Safety\Mutation_Failed::class);
        Rollback_Service::apply_snapshot(Snapshot_Store::get_by_operation($out['operation_id'])['snapshot']);
    }
}
