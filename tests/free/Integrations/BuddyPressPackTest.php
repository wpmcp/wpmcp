<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Safety\BuddyPress_Rows_Snapshot;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Safe_Mutation;
use WPMCP\Safety\Snapshot_Store;

require_once __DIR__ . '/../../support/buddypress-tables.php';

/**
 * BuddyPress groups, activity and extended profile fields (issue #354), as
 * free ops on the plugin-data dispatcher pair, which registers only while one
 * of its plugins is loaded.
 *
 * Presence is driven through the wpmcp_buddypress_active filter and the
 * BuddyPress tables are created from its own schema
 * (tests/support/buddypress-tables.php), so the plugin never has to be
 * installed into the shared test core.
 *
 * Every write is snapshotted as a 'buddypress_rows' image of the rows it
 * touches (the row, its meta, and for a create the membership it adds), so
 * rollback-operation puts every BuddyPress table back exactly. Member email
 * never reaches a caller without list_users.
 */
class BuddyPressPackTest extends \WP_UnitTestCase
{
    private const OTHER_FILTERS = [ 'wpmcp_jetengine_active', 'wpmcp_pods_active', 'wpmcp_translatepress_active' ];

    private const OPS = [
        'buddypress-list-groups'          => 'read',
        'buddypress-get-group'            => 'read',
        'buddypress-list-group-members'   => 'read',
        'buddypress-list-activity'        => 'read',
        'buddypress-list-profile-fields'  => 'read',
        'buddypress-create-group'         => 'write',
        'buddypress-update-group'         => 'write',
        'buddypress-update-profile-field' => 'write',
        'buddypress-hide-activity'        => 'write',
        'buddypress-delete-activity'      => 'destructive',
    ];

    private int $admin;

    public static function wpSetUpBeforeClass(): void
    {
        wpmcp_test_create_buddypress_tables();
    }

    public static function wpTearDownAfterClass(): void
    {
        wpmcp_test_drop_buddypress_tables();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        $this->admin = self::factory()->user->create([ 'role' => 'administrator', 'display_name' => 'Ada Admin', 'user_email' => 'ada@example.com' ]);
        wp_set_current_user($this->admin);
        foreach (self::OTHER_FILTERS as $filter) {
            add_filter($filter, '__return_false');
        }
        add_filter('wpmcp_buddypress_active', '__return_true');
    }

    protected function tearDown(): void
    {
        foreach (self::OTHER_FILTERS as $filter) {
            remove_all_filters($filter);
        }
        remove_all_filters('wpmcp_buddypress_active');
        parent::tearDown();
    }

    private function read(string $op, array $args = []): array
    {
        return (new Plugin_Data_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
    }

    private function write(string $op, array $args, bool $confirm = false, ?string $session = null): array
    {
        $call = [ 'operation' => $op, 'args' => $args ];
        if ($confirm) {
            $call['confirm'] = true;
        }
        if (null !== $session) {
            $call['session_id'] = $session;
        }
        return (new Plugin_Data_Integration())->handle_write($call);
    }

    private function error_code(array $out): string
    {
        return (string) ($out['error']['code'] ?? '');
    }

    private function editor(): int
    {
        $editor = self::factory()->user->create([ 'role' => 'editor' ]);
        wp_set_current_user($editor);
        return $editor;
    }

    // ---------------------------------------------------------------
    // Registration, catalog and presence
    // ---------------------------------------------------------------

    public function test_pair_registers_while_only_buddypress_is_loaded_and_is_absent_without_it(): void
    {
        $integration = new Plugin_Data_Integration();
        $this->assertTrue($integration->is_available());
        $this->assertTrue($integration->should_register());

        remove_all_filters('wpmcp_buddypress_active');
        add_filter('wpmcp_buddypress_active', '__return_false');
        $this->assertFalse($integration->is_available());
        $this->assertFalse($integration->should_register(), 'with no plugin of the pair loaded the tools are absent');
    }

    public function test_presence_defaults_to_the_buddypress_function(): void
    {
        remove_all_filters('wpmcp_buddypress_active');
        remove_all_filters('wpmcp_translatepress_active');
        add_filter('wpmcp_translatepress_active', '__return_true');

        $catalog = array_column((new Plugin_Data_Integration())->catalog()['operations'], null, 'name');
        $this->assertSame(function_exists('buddypress'), $catalog['buddypress-list-groups']['dependency_met']);
    }

    public function test_catalog_lists_every_op_with_its_mode_and_capability(): void
    {
        $catalog = array_column((new Plugin_Data_Integration())->catalog()['operations'], null, 'name');
        foreach (self::OPS as $op => $mode) {
            $this->assertArrayHasKey($op, $catalog);
            $this->assertSame($mode, $catalog[ $op ]['mode'], $op);
            $this->assertTrue($catalog[ $op ]['enabled'], $op);
            $this->assertTrue($catalog[ $op ]['dependency_met'], $op);
            if ('read' !== $mode) {
                $this->assertSame('manage_options', $catalog[ $op ]['capability'], "{$op} runs at BuddyPress's own moderation capability");
            }
        }
    }

    public function test_ops_are_skipped_cleanly_while_buddypress_is_inactive(): void
    {
        remove_all_filters('wpmcp_buddypress_active');
        add_filter('wpmcp_buddypress_active', '__return_false');
        remove_all_filters('wpmcp_pods_active');
        add_filter('wpmcp_pods_active', '__return_true');

        $before = wpmcp_test_bp_dump();
        $snaps  = Snapshot_Store::row_count();

        $this->assertSame('buddypress_inactive', $this->error_code($this->read('buddypress-list-groups')));
        $this->assertSame('buddypress_inactive', $this->error_code($this->write('buddypress-create-group', [ 'name' => 'Nope' ])));

        $this->assertSame($before, wpmcp_test_bp_dump());
        $this->assertSame($snaps, Snapshot_Store::row_count());
    }

    // ---------------------------------------------------------------
    // Group reads
    // ---------------------------------------------------------------

    public function test_list_groups_filters_pages_and_counts_members(): void
    {
        $hikers = wpmcp_test_bp_group('Hikers');
        wpmcp_test_bp_group('Book Club', 'private');
        wpmcp_test_bp_group('Board', 'hidden');
        wpmcp_test_bp_member($hikers, $this->admin, [ 'is_admin' => 1 ]);
        wpmcp_test_bp_member($hikers, self::factory()->user->create());
        wpmcp_test_bp_member($hikers, self::factory()->user->create(), [ 'is_confirmed' => 0 ]);
        wpmcp_test_bp_member($hikers, self::factory()->user->create(), [ 'is_banned' => 1 ]);

        $all = $this->read('buddypress-list-groups');
        $this->assertArrayNotHasKey('error', $all, (string) wp_json_encode($all));
        $this->assertSame(3, $all['result']['total']);
        $group = array_column($all['result']['groups'], null, 'id')[ $hikers ];
        $this->assertSame('Hikers', $group['name']);
        $this->assertSame('hikers', $group['slug']);
        $this->assertSame('public', $group['status']);
        $this->assertSame(2, $group['member_count'], 'confirmed, unbanned members only');

        $private = $this->read('buddypress-list-groups', [ 'status' => 'private' ]);
        $this->assertSame([ 'Book Club' ], array_column($private['result']['groups'], 'name'));

        $search = $this->read('buddypress-list-groups', [ 'search' => 'book' ]);
        $this->assertSame([ 'Book Club' ], array_column($search['result']['groups'], 'name'));

        $page = $this->read('buddypress-list-groups', [ 'per_page' => 2, 'page' => 2 ]);
        $this->assertSame(3, $page['result']['total']);
        $this->assertCount(1, $page['result']['groups']);
    }

    public function test_hidden_groups_are_only_visible_to_moderators(): void
    {
        wpmcp_test_bp_group('Open');
        $board = wpmcp_test_bp_group('Board', 'hidden');
        $this->editor();

        $names = array_column($this->read('buddypress-list-groups')['result']['groups'], 'name');
        $this->assertSame([ 'Open' ], $names);
        $this->assertSame('group_not_found', $this->error_code($this->read('buddypress-get-group', [ 'id' => $board ])));
        $this->assertSame('group_not_found', $this->error_code($this->read('buddypress-list-group-members', [ 'group_id' => $board ])));
    }

    public function test_get_group_returns_the_group_and_allowlisted_meta_only(): void
    {
        $id = wpmcp_test_bp_group('Hikers', 'public', $this->admin);
        wpmcp_test_bp_insert('bp_groups_groupmeta', [ 'group_id' => $id, 'meta_key' => 'last_activity', 'meta_value' => '2026-02-03 04:05:06' ]);
        wpmcp_test_bp_insert('bp_groups_groupmeta', [ 'group_id' => $id, 'meta_key' => 'invite_secret', 'meta_value' => 'hunter2' ]);

        $out = $this->read('buddypress-get-group', [ 'id' => $id ]);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $group = $out['result']['group'];
        $this->assertSame($id, $group['id']);
        $this->assertSame('About Hikers', $group['description']);
        $this->assertSame($this->admin, $group['creator_id']);
        $this->assertSame('2026-02-03 04:05:06', $group['last_activity']);
        $this->assertStringNotContainsString('hunter2', (string) wp_json_encode($out));

        $this->assertSame('group_not_found', $this->error_code($this->read('buddypress-get-group', [ 'id' => 999999 ])));
    }

    // ---------------------------------------------------------------
    // Members and privacy
    // ---------------------------------------------------------------

    public function test_group_members_carry_email_only_for_list_users_callers(): void
    {
        $group  = wpmcp_test_bp_group('Hikers');
        $member = self::factory()->user->create([ 'display_name' => 'Mia Member', 'user_email' => 'mia@example.com' ]);
        wpmcp_test_bp_member($group, $this->admin, [ 'is_admin' => 1, 'user_title' => 'Group Admin' ]);
        wpmcp_test_bp_member($group, $member);
        wpmcp_test_bp_member($group, self::factory()->user->create(), [ 'is_banned' => 1 ]);

        $out = $this->read('buddypress-list-group-members', [ 'group_id' => $group ]);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertSame(3, $out['result']['total']);
        $rows = array_column($out['result']['members'], null, 'user_id');
        $this->assertSame('admin', $rows[ $this->admin ]['role']);
        $this->assertSame('Mia Member', $rows[ $member ]['name']);
        $this->assertSame('member', $rows[ $member ]['role']);
        $this->assertSame('mia@example.com', $rows[ $member ]['email'], 'an administrator has list_users');

        $banned = $this->read('buddypress-list-group-members', [ 'group_id' => $group, 'role' => 'banned' ]);
        $this->assertCount(1, $banned['result']['members']);

        $this->editor();
        $this->assertFalse(current_user_can('list_users'));
        $out  = $this->read('buddypress-list-group-members', [ 'group_id' => $group ]);
        $json = (string) wp_json_encode($out);
        $this->assertArrayNotHasKey('error', $out, $json);
        $this->assertStringNotContainsString('mia@example.com', $json);
        $this->assertStringNotContainsString('ada@example.com', $json);
        $this->assertStringNotContainsString('private@example.com', $json, 'the membership request text is never returned');
        foreach ($out['result']['members'] as $row) {
            $this->assertArrayNotHasKey('email', $row);
        }
    }

    // ---------------------------------------------------------------
    // Activity
    // ---------------------------------------------------------------

    public function test_activity_keeps_content_but_never_leaks_email_ip_or_meta(): void
    {
        $author = self::factory()->user->create([ 'display_name' => 'Pat Poster', 'user_email' => 'pat@example.com' ]);
        $id     = wpmcp_test_bp_activity($author, 'Trail was muddy today');
        wpmcp_test_bp_insert('bp_activity_meta', [ 'activity_id' => $id, 'meta_key' => 'ip_address', 'meta_value' => '203.0.113.9' ]);

        $out = $this->read('buddypress-list-activity');
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $item = array_column($out['result']['activity'], null, 'id')[ $id ];
        $this->assertSame('Trail was muddy today', $item['content']);
        $this->assertSame('activity_update', $item['type']);
        $this->assertSame($author, $item['user']['id']);
        $this->assertSame('Pat Poster', $item['user']['name']);
        $this->assertSame('pat@example.com', $item['user']['email']);
        $this->assertStringNotContainsString('203.0.113.9', (string) wp_json_encode($out));

        $this->editor();
        $json = (string) wp_json_encode($this->read('buddypress-list-activity'));
        $this->assertStringContainsString('Trail was muddy today', $json);
        $this->assertStringNotContainsString('pat@example.com', $json);
        $this->assertStringNotContainsString('203.0.113.9', $json);
    }

    public function test_activity_filters_and_hides_moderated_items_from_non_moderators(): void
    {
        $user = self::factory()->user->create();
        wpmcp_test_bp_activity($user, 'Public post');
        wpmcp_test_bp_activity($user, 'Joined a group', [ 'component' => 'groups', 'type' => 'joined_group' ]);
        wpmcp_test_bp_activity($user, 'Hidden post', [ 'hide_sitewide' => 1 ]);
        wpmcp_test_bp_activity($user, 'Spam post', [ 'is_spam' => 1 ]);
        wpmcp_test_bp_activity($this->admin, 'Admin post');

        $this->assertSame(5, $this->read('buddypress-list-activity')['result']['total']);
        $groups = $this->read('buddypress-list-activity', [ 'component' => 'groups' ]);
        $this->assertSame([ 'Joined a group' ], array_column($groups['result']['activity'], 'content'));
        $mine = $this->read('buddypress-list-activity', [ 'user_id' => $this->admin ]);
        $this->assertSame([ 'Admin post' ], array_column($mine['result']['activity'], 'content'));
        $page = $this->read('buddypress-list-activity', [ 'per_page' => 2, 'page' => 3 ]);
        $this->assertCount(1, $page['result']['activity']);

        $this->editor();
        $contents = array_column($this->read('buddypress-list-activity')['result']['activity'], 'content');
        $this->assertNotContains('Hidden post', $contents);
        $this->assertNotContains('Spam post', $contents);
        $this->assertCount(3, $contents);
    }

    // ---------------------------------------------------------------
    // Extended profile fields
    // ---------------------------------------------------------------

    private function profile_fixture(): array
    {
        $base  = wpmcp_test_bp_insert('bp_xprofile_groups', [ 'name' => 'Base', 'description' => '', 'group_order' => 0, 'can_delete' => 0 ]);
        $extra = wpmcp_test_bp_insert('bp_xprofile_groups', [ 'name' => 'Extra', 'description' => 'More about you', 'group_order' => 1, 'can_delete' => 1 ]);
        $name  = wpmcp_test_bp_insert('bp_xprofile_fields', [ 'group_id' => $base, 'parent_id' => 0, 'type' => 'textbox', 'name' => 'Name', 'description' => '', 'is_required' => 1, 'field_order' => 0, 'can_delete' => 0 ]);
        $color = wpmcp_test_bp_insert('bp_xprofile_fields', [ 'group_id' => $extra, 'parent_id' => 0, 'type' => 'selectbox', 'name' => 'Color', 'description' => 'Pick one', 'is_required' => 0, 'field_order' => 1, 'can_delete' => 1 ]);
        $red   = wpmcp_test_bp_insert('bp_xprofile_fields', [ 'group_id' => $extra, 'parent_id' => $color, 'type' => 'option', 'name' => 'Red', 'description' => '', 'is_default_option' => 1, 'option_order' => 1 ]);
        wpmcp_test_bp_insert('bp_xprofile_fields', [ 'group_id' => $extra, 'parent_id' => $color, 'type' => 'option', 'name' => 'Blue', 'description' => '', 'option_order' => 2 ]);
        wpmcp_test_bp_insert('bp_xprofile_meta', [ 'object_id' => $color, 'object_type' => 'field', 'meta_key' => 'default_visibility', 'meta_value' => 'loggedin' ]);

        return [ 'base' => $base, 'extra' => $extra, 'name' => $name, 'color' => $color, 'red' => $red ];
    }

    public function test_profile_fields_list_groups_fields_and_options(): void
    {
        $ids = $this->profile_fixture();

        $out = $this->read('buddypress-list-profile-fields');
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $groups = array_column($out['result']['groups'], null, 'id');
        $this->assertSame([ $ids['base'], $ids['extra'] ], array_keys($groups));
        $this->assertSame('More about you', $groups[ $ids['extra'] ]['description']);

        $fields = array_column($groups[ $ids['extra'] ]['fields'], null, 'id');
        $this->assertSame([ $ids['color'] ], array_keys($fields), 'options are nested under their field, not listed as fields');
        $color = $fields[ $ids['color'] ];
        $this->assertSame('selectbox', $color['type']);
        $this->assertSame('Color', $color['name']);
        $this->assertFalse($color['is_required']);
        $this->assertSame('loggedin', $color['default_visibility']);
        $this->assertSame([ 'Red', 'Blue' ], array_column($color['options'], 'name'));
        $this->assertTrue($color['options'][0]['is_default']);

        $name = array_column($groups[ $ids['base'] ]['fields'], null, 'id')[ $ids['name'] ];
        $this->assertTrue($name['is_required']);
        $this->assertSame('public', $name['default_visibility']);
    }

    public function test_update_profile_field_and_rollback_restores_row_and_meta_exactly(): void
    {
        $ids    = $this->profile_fixture();
        $before = wpmcp_test_bp_dump();

        $out = $this->write('buddypress-update-profile-field', [
            'id'                 => $ids['color'],
            'name'               => 'Favourite color',
            'is_required'        => true,
            'default_visibility' => 'adminsonly',
        ]);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertTrue($out['recoverable']);
        $this->assertSame('Favourite color', $out['result']['field']['name']);
        $this->assertTrue($out['result']['field']['is_required']);
        $this->assertSame('adminsonly', $out['result']['field']['default_visibility']);
        $this->assertSame('Pick one', $out['result']['field']['description'], 'fields not passed are kept');
        $this->assertNotSame($before, wpmcp_test_bp_dump());

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame($before, wpmcp_test_bp_dump());
    }

    public function test_update_profile_field_refuses_options_unknown_ids_and_empty_names(): void
    {
        $ids    = $this->profile_fixture();
        $before = wpmcp_test_bp_dump();
        $snaps  = Snapshot_Store::row_count();

        $this->assertSame('field_not_found', $this->error_code($this->write('buddypress-update-profile-field', [ 'id' => $ids['red'], 'name' => 'Crimson' ])));
        $this->assertSame('field_not_found', $this->error_code($this->write('buddypress-update-profile-field', [ 'id' => 999999, 'name' => 'X' ])));
        $this->assertSame('invalid_args', $this->error_code($this->write('buddypress-update-profile-field', [ 'id' => $ids['name'], 'name' => '' ])));

        $this->assertSame($before, wpmcp_test_bp_dump());
        $this->assertSame($snaps, Snapshot_Store::row_count());
    }

    // ---------------------------------------------------------------
    // Group writes
    // ---------------------------------------------------------------

    public function test_create_group_adds_the_creator_as_admin_and_rollback_removes_every_row(): void
    {
        wpmcp_test_bp_group('Hikers');
        $before = wpmcp_test_bp_dump();

        $out = $this->write('buddypress-create-group', [ 'name' => 'Hikers', 'description' => 'Second <script>x</script>hiking group', 'status' => 'private' ]);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertTrue($out['recoverable']);
        $group = $out['result']['group'];
        $this->assertSame('Hikers', $group['name']);
        $this->assertNotSame('hikers', $group['slug'], 'slugs stay unique');
        $this->assertSame('private', $group['status']);
        $this->assertSame($this->admin, $group['creator_id']);
        $this->assertStringNotContainsString('<script>', $group['description']);
        $this->assertSame(1, $group['member_count']);

        $members = $this->read('buddypress-list-group-members', [ 'group_id' => $group['id'] ]);
        $this->assertSame([ 'admin' ], array_column($members['result']['members'], 'role'));

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame($before, wpmcp_test_bp_dump());
    }

    public function test_create_group_refuses_an_unknown_creator(): void
    {
        $before = wpmcp_test_bp_dump();
        $out    = $this->write('buddypress-create-group', [ 'name' => 'Ghosts', 'creator_id' => 999999 ]);
        $this->assertSame('user_not_found', $this->error_code($out));
        $this->assertSame($before, wpmcp_test_bp_dump());
    }

    public function test_update_group_changes_only_passed_fields_and_rolls_back_exactly(): void
    {
        $id = wpmcp_test_bp_group('Hikers');
        wpmcp_test_bp_insert('bp_groups_groupmeta', [ 'group_id' => $id, 'meta_key' => 'total_member_count', 'meta_value' => '4' ]);
        $before = wpmcp_test_bp_dump();

        $out = $this->write('buddypress-update-group', [ 'id' => $id, 'status' => 'hidden', 'name' => 'Trail Hikers' ]);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertSame('hidden', $out['result']['group']['status']);
        $this->assertSame('Trail Hikers', $out['result']['group']['name']);
        $this->assertSame('About Hikers', $out['result']['group']['description']);
        $this->assertSame('hikers', $out['result']['group']['slug'], 'renaming keeps the slug and so the URL');

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame($before, wpmcp_test_bp_dump());

        $this->assertSame('group_not_found', $this->error_code($this->write('buddypress-update-group', [ 'id' => 999999, 'name' => 'X' ])));
        $this->assertSame('invalid_args', $this->error_code($this->write('buddypress-update-group', [ 'id' => $id, 'status' => 'secret' ])));
    }

    public function test_session_rollback_of_a_create_then_update_removes_the_group(): void
    {
        $before  = wpmcp_test_bp_dump();
        $session = wp_generate_uuid4();

        $created = $this->write('buddypress-create-group', [ 'name' => 'Session Group' ], false, $session);
        $this->assertArrayNotHasKey('error', $created, (string) wp_json_encode($created));
        $updated = $this->write('buddypress-update-group', [ 'id' => $created['result']['group']['id'], 'description' => 'Edited' ], false, $session);
        $this->assertArrayNotHasKey('error', $updated, (string) wp_json_encode($updated));

        Rollback_Service::restore_session($session);
        $this->assertSame($before, wpmcp_test_bp_dump());
    }

    public function test_rollback_of_a_create_leaves_a_group_that_is_no_longer_the_created_one(): void
    {
        global $wpdb;
        $out = $this->write('buddypress-create-group', [ 'name' => 'Mine' ]);
        $id  = (int) $out['result']['group']['id'];
        $wpdb->update($wpdb->base_prefix . 'bp_groups', [ 'slug' => 'someone-elses' ], [ 'id' => $id ]);

        Rollback_Service::restore_operation((string) $out['operation_id']);
        $this->assertNotEmpty(Rollback_Service::take_warnings());
        $this->assertSame('someone-elses', $wpdb->get_var($wpdb->prepare('SELECT slug FROM %i WHERE id = %d', $wpdb->base_prefix . 'bp_groups', $id)));
    }

    // ---------------------------------------------------------------
    // Activity moderation
    // ---------------------------------------------------------------

    public function test_hide_activity_flips_hide_sitewide_and_rolls_back(): void
    {
        $id     = wpmcp_test_bp_activity($this->admin, 'Noisy post');
        $before = wpmcp_test_bp_dump();

        $out = $this->write('buddypress-hide-activity', [ 'id' => $id ]);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertTrue($out['result']['activity']['hidden']);

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame($before, wpmcp_test_bp_dump());

        $shown = $this->write('buddypress-hide-activity', [ 'id' => $id, 'hidden' => false ]);
        $this->assertFalse($shown['result']['activity']['hidden']);
        $this->assertSame('activity_not_found', $this->error_code($this->write('buddypress-hide-activity', [ 'id' => 999999 ])));
    }

    public function test_delete_activity_needs_confirm_removes_the_thread_and_rollback_restores_it_exactly(): void
    {
        $user   = self::factory()->user->create();
        $root   = wpmcp_test_bp_activity($user, 'Root post', [ 'mptt_left' => 1, 'mptt_right' => 6 ]);
        $reply  = wpmcp_test_bp_activity($user, 'Reply', [ 'type' => 'activity_comment', 'item_id' => $root, 'secondary_item_id' => $root, 'mptt_left' => 2, 'mptt_right' => 5 ]);
        $nested = wpmcp_test_bp_activity($user, 'Nested reply', [ 'type' => 'activity_comment', 'item_id' => $root, 'secondary_item_id' => $reply, 'mptt_left' => 3, 'mptt_right' => 4 ]);
        $other  = wpmcp_test_bp_activity($user, 'Unrelated');
        wpmcp_test_bp_insert('bp_activity_meta', [ 'activity_id' => $root, 'meta_key' => 'favorite_count', 'meta_value' => '3' ]);
        wpmcp_test_bp_insert('bp_activity_meta', [ 'activity_id' => $nested, 'meta_key' => 'note', 'meta_value' => 'x' ]);
        $before = wpmcp_test_bp_dump();

        $this->assertSame('confirmation_required', $this->error_code($this->write('buddypress-delete-activity', [ 'id' => $root ])));
        $this->assertSame($before, wpmcp_test_bp_dump());

        $out = $this->write('buddypress-delete-activity', [ 'id' => $root ], true);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertTrue($out['recoverable']);
        $this->assertSame([ $root, $reply, $nested ], $out['result']['deleted']);
        $left = wpmcp_test_bp_dump();
        $this->assertSame([ (string) $other ], array_column($left['bp_activity'], 'id'));
        $this->assertSame([], $left['bp_activity_meta']);

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame($before, wpmcp_test_bp_dump());
    }

    public function test_deleting_a_reply_takes_only_its_own_subthread(): void
    {
        $user   = self::factory()->user->create();
        $root   = wpmcp_test_bp_activity($user, 'Root');
        $reply  = wpmcp_test_bp_activity($user, 'Reply', [ 'type' => 'activity_comment', 'item_id' => $root, 'secondary_item_id' => $root ]);
        $nested = wpmcp_test_bp_activity($user, 'Nested', [ 'type' => 'activity_comment', 'item_id' => $root, 'secondary_item_id' => $reply ]);
        $sister = wpmcp_test_bp_activity($user, 'Sister', [ 'type' => 'activity_comment', 'item_id' => $root, 'secondary_item_id' => $root ]);

        $out = $this->write('buddypress-delete-activity', [ 'id' => $reply ], true);
        $this->assertSame([ $reply, $nested ], $out['result']['deleted']);
        $this->assertSame([ (string) $root, (string) $sister ], array_column(wpmcp_test_bp_dump()['bp_activity'], 'id'));
    }

    // ---------------------------------------------------------------
    // Capabilities and snapshot type
    // ---------------------------------------------------------------

    public function test_writes_need_manage_options(): void
    {
        $group    = wpmcp_test_bp_group('Hikers');
        $activity = wpmcp_test_bp_activity($this->admin, 'Post');
        $this->editor();
        $before = wpmcp_test_bp_dump();

        $this->assertSame('operation_denied', $this->error_code($this->write('buddypress-create-group', [ 'name' => 'X' ])));
        $this->assertSame('operation_denied', $this->error_code($this->write('buddypress-update-group', [ 'id' => $group, 'name' => 'X' ])));
        $this->assertSame('operation_denied', $this->error_code($this->write('buddypress-hide-activity', [ 'id' => $activity ])));
        $this->assertSame('operation_denied', $this->error_code($this->write('buddypress-delete-activity', [ 'id' => $activity ], true)));
        $this->assertSame($before, wpmcp_test_bp_dump());
    }

    public function test_rollback_of_a_buddypress_write_needs_manage_options(): void
    {
        $id  = wpmcp_test_bp_group('Hikers');
        $out = $this->write('buddypress-update-group', [ 'id' => $id, 'name' => 'Renamed' ]);
        $this->editor();

        $this->assertFalse(Rollback_Service::restore_operation((string) $out['operation_id']));
        global $wpdb;
        $this->assertSame('Renamed', $wpdb->get_var($wpdb->prepare('SELECT name FROM %i WHERE id = %d', $wpdb->base_prefix . 'bp_groups', $id)));
    }

    // ---------------------------------------------------------------
    // Rows a write's hooks create (issue #363)
    // ---------------------------------------------------------------

    public function test_rollback_also_removes_rows_the_write_and_its_hooks_created(): void
    {
        $id     = wpmcp_test_bp_group('Hikers');
        $before = wpmcp_test_bp_dump();
        $marks  = BuddyPress_Rows_Snapshot::watermarks();

        $out = $this->write('buddypress-update-group', [ 'id' => $id, 'name' => 'Trail Hikers' ]);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));

        // What BuddyPress's hooks add around a write: an activity item with
        // its meta, and a notification.
        $activity = wpmcp_test_bp_activity($this->admin, '', [ 'component' => 'groups', 'type' => 'group_details_updated', 'item_id' => $id ]);
        $meta     = wpmcp_test_bp_insert('bp_activity_meta', [ 'activity_id' => $activity, 'meta_key' => 'note', 'meta_value' => 'x' ]);
        $notice   = wpmcp_test_bp_insert('bp_notifications', [ 'user_id' => $this->admin, 'item_id' => $id, 'secondary_item_id' => 0, 'component_name' => 'groups', 'component_action' => 'group_details_updated', 'date_notified' => '2026-01-02 03:04:05', 'is_new' => 1 ]);

        $created = BuddyPress_Rows_Snapshot::created_since($marks);
        $this->assertSame([ 'bp_activity' => [ $activity ], 'bp_activity_meta' => [ $meta ], 'bp_notifications' => [ $notice ] ], $created);
        BuddyPress_Rows_Snapshot::record_created((string) $out['operation_id'], $created);

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame($before, wpmcp_test_bp_dump(), 'the edit is undone and every row created with it is gone');
    }

    public function test_a_group_snapshot_covers_its_membership_requests_and_nothing_of_another_component(): void
    {
        global $wpdb;
        $id      = wpmcp_test_bp_group('Quiet Club', 'private');
        $asker   = self::factory()->user->create();
        $request = wpmcp_test_bp_insert('bp_invitations', [ 'user_id' => $asker, 'inviter_id' => 0, 'class' => 'BP_Groups_Invitation_Manager', 'item_id' => $id, 'type' => 'request', 'content' => '', 'date_modified' => '2026-01-02 03:04:05' ]);
        $other   = wpmcp_test_bp_insert('bp_invitations', [ 'user_id' => $asker, 'inviter_id' => 0, 'class' => 'Some_Other_Manager', 'item_id' => $id, 'type' => 'invite', 'content' => '', 'date_modified' => '2026-01-02 03:04:05' ]);
        $before  = wpmcp_test_bp_dump();
        $marks   = BuddyPress_Rows_Snapshot::watermarks();

        $out = $this->write('buddypress-update-group', [ 'id' => $id, 'status' => 'public' ]);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));

        // BuddyPress accepts pending requests when a private group goes public.
        $table = $wpdb->base_prefix . 'bp_invitations';
        $wpdb->update($table, [ 'accepted' => 1 ], [ 'id' => $request ]);
        wpmcp_test_bp_member($id, $asker);
        BuddyPress_Rows_Snapshot::record_created((string) $out['operation_id'], BuddyPress_Rows_Snapshot::created_since($marks));
        $wpdb->update($table, [ 'accepted' => 1 ], [ 'id' => $other ]);

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $after = wpmcp_test_bp_dump();
        $this->assertSame($before['bp_groups'], $after['bp_groups']);
        $this->assertSame($before['bp_groups_members'], $after['bp_groups_members'], 'the accepted membership is removed');
        $rows = array_column($after['bp_invitations'], null, 'id');
        $this->assertSame('0', $rows[ $request ]['accepted'], 'the request is pending again');
        $this->assertSame('1', $rows[ $other ]['accepted'], "another component's invitation is not the group's to restore");
    }

    public function test_an_activity_snapshot_covers_its_whole_thread_and_its_notifications(): void
    {
        global $wpdb;
        $user   = self::factory()->user->create();
        $root   = wpmcp_test_bp_activity($user, 'Root', [ 'mptt_left' => 1, 'mptt_right' => 8 ]);
        $reply  = wpmcp_test_bp_activity($user, 'Reply', [ 'type' => 'activity_comment', 'item_id' => $root, 'secondary_item_id' => $root, 'mptt_left' => 2, 'mptt_right' => 5 ]);
        $nested = wpmcp_test_bp_activity($user, 'Nested', [ 'type' => 'activity_comment', 'item_id' => $root, 'secondary_item_id' => $reply, 'mptt_left' => 3, 'mptt_right' => 4 ]);
        $sister = wpmcp_test_bp_activity($user, 'Sister', [ 'type' => 'activity_comment', 'item_id' => $root, 'secondary_item_id' => $root, 'mptt_left' => 6, 'mptt_right' => 7 ]);
        $notice = wpmcp_test_bp_insert('bp_notifications', [ 'user_id' => $user, 'item_id' => $reply, 'secondary_item_id' => $this->admin, 'component_name' => 'activity', 'component_action' => 'update_reply', 'date_notified' => '2026-01-02 03:04:05', 'is_new' => 1 ]);
        $before = wpmcp_test_bp_dump();

        $out = $this->write('buddypress-delete-activity', [ 'id' => $reply ], true);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertSame([ $reply, $nested ], $out['result']['deleted']);

        // BuddyPress renumbers the rest of the thread and drops the reply's
        // notification when it deletes a reply.
        $wpdb->update($wpdb->base_prefix . 'bp_activity', [ 'mptt_right' => 4 ], [ 'id' => $root ]);
        $wpdb->update($wpdb->base_prefix . 'bp_activity', [ 'mptt_left' => 2, 'mptt_right' => 3 ], [ 'id' => $sister ]);
        $wpdb->delete($wpdb->base_prefix . 'bp_notifications', [ 'id' => $notice ]);

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame($before, wpmcp_test_bp_dump());
    }

    public function test_a_create_is_undone_on_the_group_the_write_actually_produced(): void
    {
        $before   = wpmcp_test_bp_dump();
        $reserved = 900001;
        $op       = wp_generate_uuid4();
        $actual   = 0;

        // BuddyPress assigns a new group's id itself, so the group it writes
        // can sit at another id than the one reserved for the snapshot, and
        // another group can take the reserved one.
        Safe_Mutation::run([
            'operation_id'        => $op,
            'object_type'         => BuddyPress_Rows_Snapshot::TYPE,
            'object_id'           => BuddyPress_Rows_Snapshot::key('group_created', $reserved),
            'session_id'          => 'default',
            'tool_name'           => 'plugin-data-write',
            'extra_snapshot_data' => [ 'created' => [ 'slug' => 'actual' ] ],
        ], function () use ($op, &$actual): void {
            $marks  = BuddyPress_Rows_Snapshot::watermarks();
            $actual = wpmcp_test_bp_group('Actual', 'public', $this->admin);
            wpmcp_test_bp_member($actual, $this->admin, [ 'is_admin' => 1 ]);
            BuddyPress_Rows_Snapshot::record_created($op, BuddyPress_Rows_Snapshot::created_since($marks), [ 'id' => $actual, 'slug' => 'actual' ]);
        });
        $this->assertNotSame($reserved, $actual);
        $foreign = wpmcp_test_bp_group('Foreign', 'public', $this->admin, [ 'id' => $reserved ]);

        $this->assertTrue(Rollback_Service::restore_operation($op));
        $after = wpmcp_test_bp_dump();
        $this->assertSame([ (string) $foreign ], array_column($after['bp_groups'], 'id'), 'the created group is removed and the group at the reserved id is left');
        $this->assertSame($before['bp_groups_members'], $after['bp_groups_members']);
    }

    public function test_buddypress_rows_is_a_restorable_type(): void
    {
        $this->assertContains('buddypress_rows', Rollback_Service::restorable_object_types());
    }
}
