<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Safety\BuddyPress_Rows_Snapshot;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * Issue #363: the BuddyPress writes run through BuddyPress itself, so its
 * hooks fire, and rollback-operation still puts every BuddyPress table back,
 * including the rows those hooks added.
 *
 * It runs only in the local gate's live BuddyPress leg (bin/test-local.sh,
 * or bin/test-local.sh --live-buddypress alone), which sets
 * WPMCP_LIVE_BUDDYPRESS=1, installs BuddyPress from wordpress.org on a
 * separate WordPress install, loads it in tests/bootstrap.php with the
 * groups, activity, extended profile and notifications components on, and
 * builds its tables from its own schema.
 *
 * A group created through the tool is compared with one created the way
 * BuddyPress's own group creation screen does it: the same hooks fire, as
 * often, and the same activity is recorded. Every write is then rolled back
 * and every BuddyPress table must match what it held before, row for row.
 *
 * Everywhere else BuddyPress is absent and the test is skipped; the live leg
 * runs with --fail-on-skipped so it cannot pass vacuously.
 *
 * @group buddypress-live
 */
class BuddyPressLiveTest extends \WP_UnitTestCase
{
    /** BuddyPress hooks whose firing is counted. */
    private const HOOKS = [
        'groups_group_after_save',
        'groups_member_after_save',
        'groups_create_group',
        'groups_created_group',
        'groups_group_create_complete',
        'groups_details_updated',
        'groups_settings_updated',
        'bp_activity_after_save',
        'bp_activity_deleted_activities',
        'bp_activity_delete_comment',
        'xprofile_field_after_save',
        'xprofile_fields_saved_field',
    ];

    private int $admin;

    /** @var array<string, int> hook => times fired */
    private array $fired = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (! function_exists('buddypress') || ! bp_is_active('groups') || ! bp_is_active('activity') || ! bp_is_active('xprofile') || ! bp_is_active('notifications')) {
            $this->markTestSkipped('Needs the real BuddyPress (bin/test-local.sh --live-buddypress, WPMCP_LIVE_BUDDYPRESS=1).');
        }
        Snapshot_Store::install();
        $this->admin = self::factory()->user->create([ 'role' => 'administrator', 'user_login' => 'ada', 'display_name' => 'Ada Admin' ]);
        wp_set_current_user($this->admin);
        foreach (self::HOOKS as $hook) {
            add_action($hook, function () use ($hook): void {
                $this->fired[ $hook ] = ($this->fired[ $hook ] ?? 0) + 1;
            }, 10, 0);
        }
    }

    private function write(string $op, array $args, bool $confirm = false): array
    {
        $call = [ 'operation' => $op, 'args' => $args ];
        if ($confirm) {
            $call['confirm'] = true;
        }
        $out = (new Plugin_Data_Integration())->handle_write($call);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertTrue($out['recoverable']);
        return $out;
    }

    private function table(string $name): string
    {
        return bp_core_get_table_prefix() . $name;
    }

    /**
     * Every row of every BuddyPress table, id order: a rollback is exact when
     * this matches.
     *
     * @return array<string, array<int, array<string, string|null>>>
     */
    private function dump(): array
    {
        global $wpdb;
        $tables = (array) $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like(bp_core_get_table_prefix() . 'bp_') . '%'));
        sort($tables);
        $this->assertContains($this->table('bp_groups'), $tables);
        $out = [];
        foreach ($tables as $table) {
            $out[ $table ] = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i ORDER BY id ASC', $table), ARRAY_A);
        }
        return $out;
    }

    private function max_id(string $table): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COALESCE(MAX(id), 0) FROM %i', $this->table($table)));
    }

    /** Activity items recorded after $mark, leaving out members' last_activity rows. */
    private function activity_since(int $mark): array
    {
        global $wpdb;
        return (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM %i WHERE id > %d AND type <> 'last_activity' ORDER BY id ASC", $this->table('bp_activity'), $mark), ARRAY_A);
    }

    /** An activity item with the group it is about abstracted away. */
    private function shape(array $row, int $group): array
    {
        return [
            'component'         => $row['component'],
            'type'              => $row['type'],
            'user_id'           => (int) $row['user_id'],
            'item_is_the_group' => (int) $row['item_id'] === $group,
            'secondary_item_id' => (int) $row['secondary_item_id'],
            'content'           => $row['content'],
            'hide_sitewide'     => (int) $row['hide_sitewide'],
            'is_spam'           => (int) $row['is_spam'],
        ];
    }

    /** A group's memberships, without ids and dates. */
    private function members(int $group): array
    {
        global $wpdb;
        return (array) $wpdb->get_results($wpdb->prepare('SELECT user_id, inviter_id, is_admin, is_mod, user_title, is_confirmed, is_banned FROM %i WHERE group_id = %d ORDER BY id ASC', $this->table('bp_groups_members'), $group), ARRAY_A);
    }

    /** A group's meta keys, sorted. */
    private function meta_keys(int $group): array
    {
        global $wpdb;
        $keys = (array) $wpdb->get_col($wpdb->prepare('SELECT meta_key FROM %i WHERE group_id = %d', $this->table('bp_groups_groupmeta'), $group));
        sort($keys);
        return $keys;
    }

    public function test_a_group_created_through_the_tool_matches_one_buddypress_creates_and_rollback_removes_all_of_it(): void
    {
        // BuddyPress itself: what its group creation screen runs
        // (bp-groups/actions/create.php): groups_create_group() on the
        // details step, then, once the last step is saved, a created_group
        // activity item and groups_group_create_complete.
        $mark        = $this->max_id('bp_activity');
        $this->fired = [];
        $reference   = groups_create_group([
            'creator_id'   => $this->admin,
            'name'         => 'Reference Club',
            'description'  => 'Made by BuddyPress',
            'slug'         => groups_check_slug(sanitize_title('Reference Club')),
            'status'       => 'public',
            'date_created' => bp_core_current_time(),
        ]);
        $this->assertIsInt($reference);
        groups_record_activity([ 'type' => 'created_group', 'item_id' => $reference, 'user_id' => $this->admin ]);
        do_action('groups_group_create_complete', $reference);
        $hooks    = $this->fired;
        $activity = array_map(fn (array $row): array => $this->shape($row, $reference), $this->activity_since($mark));
        $this->assertContains('created_group', array_column($activity, 'type'));

        $before = $this->dump();
        $count  = (int) bp_get_user_meta($this->admin, 'total_group_count', true);
        $mark   = $this->max_id('bp_activity');

        $this->fired = [];
        $out         = $this->write('buddypress-create-group', [ 'name' => 'Tool Club', 'description' => 'Made by the tool' ]);
        $id          = (int) $out['result']['group']['id'];

        $this->assertSame($hooks, $this->fired, 'the same BuddyPress hooks fire, as often');
        $items = $this->activity_since($mark);
        $this->assertSame($activity, array_map(fn (array $row): array => $this->shape($row, $id), $items), 'the same activity is recorded');
        $this->assertStringContainsString('Tool Club', (string) $items[ array_search('created_group', array_column($items, 'type'), true) ]['action']);
        $this->assertSame($this->members($reference), $this->members($id));
        $this->assertSame($this->meta_keys($reference), $this->meta_keys($id));
        $this->assertSame($count + 1, (int) bp_get_user_meta($this->admin, 'total_group_count', true));
        $this->assertSame('Tool Club', groups_get_group($id)->name);

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame($before, $this->dump(), 'rollback removes the group and every row its hooks added');
        $this->assertSame(0, (int) groups_get_group($id)->id, "BuddyPress's caches no longer hold the group");
        $this->assertSame($count, (int) bp_get_user_meta($this->admin, 'total_group_count', true), "the creator's group count is recounted");
    }

    public function test_updating_a_group_runs_through_buddypress_and_rollback_undoes_what_its_hooks_did(): void
    {
        $group = groups_create_group([ 'creator_id' => $this->admin, 'name' => 'Quiet Club', 'description' => 'Members only', 'status' => 'private' ]);
        $asker = self::factory()->user->create();
        $this->assertNotEmpty(groups_send_membership_request([ 'user_id' => $asker, 'group_id' => $group ]));
        $before = $this->dump();

        $this->fired = [];
        $out         = $this->write('buddypress-update-group', [ 'id' => $group, 'name' => 'Open Club', 'status' => 'public' ]);
        $this->assertSame(1, $this->fired['groups_details_updated'] ?? 0);
        $this->assertSame(1, $this->fired['groups_settings_updated'] ?? 0);
        $this->assertSame('Open Club', $out['result']['group']['name']);
        $this->assertSame('quiet-club', $out['result']['group']['slug'], 'renaming keeps the slug');
        $this->assertSame('public', groups_get_group($group)->status);
        $this->assertNotEmpty(groups_is_user_member($asker, $group), 'BuddyPress accepts pending requests when a private group goes public');

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame($before, $this->dump());
        $this->assertSame('Quiet Club', groups_get_group($group)->name);
        $this->assertEmpty(groups_is_user_member($asker, $group));
        $this->assertNotEmpty(groups_check_for_membership_request($asker, $group), 'the request is pending again');
    }

    /**
     * Issue #372: a row another request adds while the write runs is not
     * the write's, so its rollback leaves it. The other request is played
     * from inside one of BuddyPress's own hooks during the write, writing
     * straight to the tables as a separate request would, so nothing in this
     * request reports those rows.
     */
    public function test_rollback_keeps_rows_another_request_added_while_the_write_ran(): void
    {
        global $wpdb;
        $group     = groups_create_group([ 'creator_id' => $this->admin, 'name' => 'Busy Club', 'description' => 'Lively', 'status' => 'public' ]);
        $member    = self::factory()->user->create();
        $elsewhere = groups_create_group([ 'creator_id' => $member, 'name' => 'Elsewhere', 'description' => 'Another group', 'status' => 'public' ]);
        $before    = $this->dump();

        $foreign = [];
        $during  = function () use ($member, $elsewhere, &$foreign): void {
            global $wpdb;
            if ([] !== $foreign) {
                return;
            }
            $now = bp_core_current_time();
            $wpdb->insert($this->table('bp_activity'), [ 'user_id' => $member, 'component' => 'groups', 'type' => 'activity_update', 'action' => 'A member posted in Elsewhere', 'content' => 'Posted meanwhile', 'primary_link' => '', 'item_id' => $elsewhere, 'secondary_item_id' => 0, 'date_recorded' => $now, 'hide_sitewide' => 0, 'mptt_left' => 0, 'mptt_right' => 0, 'is_spam' => 0 ]);
            $foreign['bp_activity'] = (int) $wpdb->insert_id;
            $wpdb->insert($this->table('bp_activity_meta'), [ 'activity_id' => $foreign['bp_activity'], 'meta_key' => 'note', 'meta_value' => 'theirs' ]);
            $foreign['bp_activity_meta'] = (int) $wpdb->insert_id;
            $wpdb->insert($this->table('bp_notifications'), [ 'user_id' => $member, 'item_id' => $elsewhere, 'secondary_item_id' => 0, 'component_name' => 'groups', 'component_action' => 'membership_request_accepted', 'date_notified' => $now, 'is_new' => 1 ]);
            $foreign['bp_notifications'] = (int) $wpdb->insert_id;
        };
        add_action('groups_details_updated', $during);
        try {
            $out = $this->write('buddypress-update-group', [ 'id' => $group, 'name' => 'Busier Club' ]);
        } finally {
            remove_action('groups_details_updated', $during);
        }
        $this->assertCount(3, $foreign, 'the other request ran during the write');

        // What rollback must leave: the tables as before, plus the other
        // request's rows exactly as it wrote them.
        $expected = $before;
        foreach ($foreign as $name => $id) {
            $table              = $this->table($name);
            $expected[ $table ][] = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', $table, $id), ARRAY_A);
        }

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame($expected, $this->dump(), "the edit is undone and the other request's rows survive");
        $this->assertSame('Busy Club', groups_get_group($group)->name);
        $this->assertSame([], Rollback_Service::take_warnings());
    }

    /**
     * The rows of $now that $then does not hold, per table, id order.
     *
     * @param array<string, array<int, array<string, string|null>>> $then
     * @param array<string, array<int, array<string, string|null>>> $now
     * @return array<string, array<int, array<string, string|null>>>
     */
    private function added(array $then, array $now): array
    {
        $out = [];
        foreach ($now as $table => $rows) {
            $old = array_column($then[ $table ] ?? [], 'id');
            $new = array_values(array_filter($rows, static fn (array $row): bool => ! in_array($row['id'], $old, true)));
            if ([] !== $new) {
                $out[ $table ] = $new;
            }
        }
        return $out;
    }

    /**
     * Issue #375: a membership request another member sends after a status
     * change is not the write's, so rolling the change back keeps it, and
     * the notification BuddyPress sent the admin about it.
     */
    public function test_a_membership_request_sent_after_a_status_change_survives_its_rollback(): void
    {
        $group  = groups_create_group([ 'creator_id' => $this->admin, 'name' => 'Open Club', 'description' => 'Anyone can join', 'status' => 'public' ]);
        $member = self::factory()->user->create();
        $before = $this->dump();

        $out = $this->write('buddypress-update-group', [ 'id' => $group, 'status' => 'private' ]);
        $this->assertSame('private', groups_get_group($group)->status);

        // Now that the group is private, another member asks to join.
        $then = $this->dump();
        $this->assertNotEmpty(groups_send_membership_request([ 'user_id' => $member, 'group_id' => $group ]));
        $later = $this->added($then, $this->dump());
        $this->assertNotEmpty($later[ $this->table('bp_invitations') ] ?? [], 'BuddyPress recorded the request');
        $this->assertNotEmpty($later[ $this->table('bp_notifications') ] ?? [], 'BuddyPress notified the admin');
        $expected = $before;
        foreach ($later as $table => $rows) {
            $expected[ $table ] = array_merge($expected[ $table ], $rows);
        }

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame($expected, $this->dump(), 'the group is public again and the later request and its notification are kept');
        $this->assertSame('public', groups_get_group($group)->status);
        $this->assertNotEmpty(groups_check_for_membership_request($member, $group));
        $this->assertSame([], Rollback_Service::take_warnings());
    }

    /**
     * Issue #375: a reply posted after the write is kept by its rollback, and
     * the thread's nested-set numbering around it stays the one BuddyPress
     * gives it.
     */
    public function test_a_reply_posted_after_hiding_survives_rollback_and_the_thread_stays_numbered(): void
    {
        $other = self::factory()->user->create();
        $late  = self::factory()->user->create();
        $root  = bp_activity_add([ 'user_id' => $this->admin, 'component' => 'activity', 'type' => 'activity_update', 'content' => 'Root post' ]);
        $this->assertIsInt(bp_activity_new_comment([ 'activity_id' => $root, 'parent_id' => $root, 'content' => 'A reply', 'user_id' => $other ]));
        $before = $this->dump();

        $out = $this->write('buddypress-hide-activity', [ 'id' => $root ]);

        // After the write, another member replies to the post: BuddyPress
        // renumbers the thread and notifies the author.
        $answer = bp_activity_new_comment([ 'activity_id' => $root, 'parent_id' => $root, 'content' => 'A later reply', 'user_id' => $late ]);
        $this->assertIsInt($answer);

        // What rollback must leave: everything as it is now, less the rows
        // the write created, with the post shown again.
        $expected = $this->dump();
        $snapshot = Snapshot_Store::get_by_operation((string) $out['operation_id'])['snapshot'];
        foreach ((array) ($snapshot['data']['created_rows'] ?? []) as $name => $entries) {
            $ids                = array_map('strval', array_column((array) $entries, 'id'));
            $table              = BuddyPress_Rows_Snapshot::table((string) $name);
            $expected[ $table ] = array_values(array_filter($expected[ $table ], static fn (array $row): bool => ! in_array($row['id'], $ids, true)));
        }
        $activity = $this->table('bp_activity');
        $shown    = array_column($before[ $activity ], 'hide_sitewide', 'id')[ (string) $root ];
        foreach ($expected[ $activity ] as $i => $row) {
            if ((int) $row['id'] === $root) {
                $expected[ $activity ][ $i ]['hide_sitewide'] = $shown;
            }
        }
        $this->assertContains((string) $answer, array_column($expected[ $activity ], 'id'));

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $after = $this->dump();
        $this->assertSame($expected, $after, 'the post is shown again and the later reply, its notification and its numbering are kept');
        \BP_Activity_Activity::rebuild_activity_comment_tree($root);
        $this->assertSame($after, $this->dump(), 'the thread is numbered exactly as BuddyPress numbers it');
    }

    public function test_updating_a_profile_field_saves_it_through_buddypress_and_keeps_its_options(): void
    {
        $filter = static fn (): array => [ 1 => 'Red', 2 => 'Blue' ];
        add_filter('xprofile_field_options_before_save', $filter);
        $field = xprofile_insert_field([ 'field_group_id' => 1, 'name' => 'Color', 'type' => 'selectbox', 'description' => 'Pick one' ]);
        remove_filter('xprofile_field_options_before_save', $filter);
        $this->assertIsInt($field);
        $before = $this->dump();

        $this->fired = [];
        $out         = $this->write('buddypress-update-profile-field', [ 'id' => $field, 'name' => 'Favourite color', 'is_required' => true, 'default_visibility' => 'loggedin' ]);
        $this->assertSame(1, $this->fired['xprofile_field_after_save'] ?? 0);
        $this->assertSame(1, $this->fired['xprofile_fields_saved_field'] ?? 0);
        $saved = $out['result']['field'];
        $this->assertSame('Favourite color', $saved['name']);
        $this->assertTrue($saved['is_required']);
        $this->assertSame('Pick one', $saved['description']);
        $this->assertSame('loggedin', $saved['default_visibility']);
        $this->assertSame([ 'Red', 'Blue' ], array_column($saved['options'], 'name'), 'saving through BuddyPress keeps the options');
        $this->assertSame('Favourite color', xprofile_get_field($field, null, false)->name);

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame($before, $this->dump());
        $this->assertSame('Color', xprofile_get_field($field, null, false)->name, "BuddyPress's caches no longer hold the edit");
    }

    public function test_hiding_activity_saves_it_through_buddypress_and_rolls_back(): void
    {
        $id = bp_activity_add([ 'user_id' => $this->admin, 'component' => 'activity', 'type' => 'activity_update', 'content' => 'Noisy post' ]);
        $this->assertIsInt($id);
        $before = $this->dump();

        $this->fired = [];
        $out         = $this->write('buddypress-hide-activity', [ 'id' => $id ]);
        $this->assertSame(1, $this->fired['bp_activity_after_save'] ?? 0);
        $this->assertTrue($out['result']['activity']['hidden']);
        $this->assertSame(1, (int) (new \BP_Activity_Activity($id))->hide_sitewide);

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame($before, $this->dump());
        $this->assertSame(0, (int) (new \BP_Activity_Activity($id))->hide_sitewide);
    }

    public function test_deleting_a_reply_goes_through_buddypress_and_rollback_restores_the_thread(): void
    {
        $other  = self::factory()->user->create();
        $root   = bp_activity_add([ 'user_id' => $this->admin, 'component' => 'activity', 'type' => 'activity_update', 'content' => 'Root post' ]);
        $reply  = bp_activity_new_comment([ 'activity_id' => $root, 'parent_id' => $root, 'content' => 'A reply', 'user_id' => $other ]);
        $nested = bp_activity_new_comment([ 'activity_id' => $root, 'parent_id' => $reply, 'content' => 'A nested reply', 'user_id' => $this->admin ]);
        $sister = bp_activity_new_comment([ 'activity_id' => $root, 'parent_id' => $root, 'content' => 'Another reply', 'user_id' => $other ]);
        $this->assertIsInt($sister);
        $before = $this->dump();

        $this->fired = [];
        $out         = $this->write('buddypress-delete-activity', [ 'id' => $reply ], true);
        $this->assertSame(1, $this->fired['bp_activity_delete_comment'] ?? 0);
        $this->assertSame([ $reply, $nested ], $out['result']['deleted']);
        $left = array_map('intval', array_column($this->dump()[ $this->table('bp_activity') ], 'id'));
        $this->assertNotContains($reply, $left);
        $this->assertNotContains($nested, $left);
        $this->assertContains($sister, $left);

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame($before, $this->dump(), 'the thread comes back as it was, numbering and notifications included');
    }

    public function test_deleting_an_item_goes_through_buddypress_and_rollback_restores_it(): void
    {
        $other = self::factory()->user->create();
        $root  = bp_activity_add([ 'user_id' => $this->admin, 'component' => 'activity', 'type' => 'activity_update', 'content' => 'Root post' ]);
        bp_activity_new_comment([ 'activity_id' => $root, 'parent_id' => $root, 'content' => 'A reply', 'user_id' => $other ]);
        bp_activity_update_meta($root, 'note', 'kept');
        $before = $this->dump();

        $this->fired = [];
        $out         = $this->write('buddypress-delete-activity', [ 'id' => $root ], true);
        $this->assertSame(1, $this->fired['bp_activity_deleted_activities'] ?? 0);
        $this->assertFalse((bool) bp_activity_get_specific([ 'activity_ids' => [ $root ] ])['activities']);

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame($before, $this->dump());
        $this->assertSame('kept', bp_activity_get_meta($root, 'note'));
    }
}
