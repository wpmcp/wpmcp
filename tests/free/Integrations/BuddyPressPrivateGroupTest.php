<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Safety\Snapshot_Store;

require_once __DIR__ . '/../../support/buddypress-tables.php';

/**
 * Issue #503: the BuddyPress group reads follow BuddyPress's own visibility
 * rules for private groups.
 *
 * BuddyPress shows a private group's member list only to its confirmed,
 * unbanned members and to community moderators (BP_Groups_Group's
 * user_has_access, and the group members REST endpoint's permission check),
 * and it shows pending membership requests and banned members only on the
 * group's management screens. Activity posted in a private group is readable
 * only by those who can access the group (bp_activity_user_can_read()).
 *
 * The read capability is edit_posts, so a Contributor who is not in the group
 * must get the same group_not_found a hidden group answers.
 */
class BuddyPressPrivateGroupTest extends \WP_UnitTestCase
{
    private const OTHER_FILTERS = [ 'wpmcp_jetengine_active', 'wpmcp_pods_active', 'wpmcp_translatepress_active' ];

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
        wp_set_current_user(0);
        parent::tearDown();
    }

    private function read(string $op, array $args = []): array
    {
        return (new Plugin_Data_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
    }

    private function contributor(string $name): int
    {
        return self::factory()->user->create([ 'role' => 'contributor', 'display_name' => $name ]);
    }

    /**
     * A group with an admin, a mod, a member, a pending applicant and a
     * banned user, all Contributors (so all pass the edit_posts read gate).
     *
     * @return array<string, int>
     */
    private function group(string $status): array
    {
        $ids          = [
            'owner'   => $this->contributor('Group Owner'),
            'mod'     => $this->contributor('Group Mod'),
            'member'  => $this->contributor('Secret Member'),
            'pending' => $this->contributor('Pending Applicant'),
            'banned'  => $this->contributor('Banned Person'),
        ];
        $ids['group'] = wpmcp_test_bp_group('Recovery Support ' . $status, $status, $ids['owner']);
        wpmcp_test_bp_member($ids['group'], $ids['owner'], [ 'is_admin' => 1 ]);
        wpmcp_test_bp_member($ids['group'], $ids['mod'], [ 'is_mod' => 1 ]);
        wpmcp_test_bp_member($ids['group'], $ids['member']);
        wpmcp_test_bp_member($ids['group'], $ids['pending'], [ 'is_confirmed' => 0 ]);
        wpmcp_test_bp_member($ids['group'], $ids['banned'], [ 'is_banned' => 1 ]);
        return $ids;
    }

    /** @return array<string, string> name => role, as the caller sees them */
    private function members_as(int $user, int $group, array $args = []): array
    {
        wp_set_current_user($user);
        $out = $this->read('buddypress-list-group-members', [ 'group_id' => $group ] + $args);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $rows = array_column($out['result']['members'], 'role', 'name');
        ksort($rows);
        $this->assertSame(count($rows), $out['result']['total'], 'total counts only the rows the caller may see');
        return $rows;
    }

    public function test_a_non_member_contributor_is_refused_on_a_private_group(): void
    {
        $ids = $this->group('private');
        wp_set_current_user($this->contributor('Outsider'));

        $out  = $this->read('buddypress-list-group-members', [ 'group_id' => $ids['group'] ]);
        $json = (string) wp_json_encode($out);

        $this->assertSame('group_not_found', $out['error']['code'] ?? '', 'a non-member read the member list of a private group: ' . $json);
        $this->assertStringNotContainsString('Secret Member', $json);
        $this->assertStringNotContainsString('Pending Applicant', $json);
    }

    public function test_pending_and_banned_users_are_not_members_of_a_private_group(): void
    {
        $ids = $this->group('private');
        foreach ([ 'pending', 'banned' ] as $who) {
            wp_set_current_user($ids[ $who ]);
            $out = $this->read('buddypress-list-group-members', [ 'group_id' => $ids['group'] ]);
            $this->assertSame('group_not_found', $out['error']['code'] ?? '', "a {$who} user read a private group's members");
        }
    }

    public function test_a_confirmed_member_sees_confirmed_members_only(): void
    {
        $ids       = $this->group('private');
        $confirmed = [ 'Group Mod' => 'mod', 'Group Owner' => 'admin', 'Secret Member' => 'member' ];

        $this->assertSame($confirmed, $this->members_as($ids['member'], $ids['group']));
        $this->assertSame([], $this->members_as($ids['member'], $ids['group'], [ 'role' => 'pending' ]));
        $this->assertSame([], $this->members_as($ids['member'], $ids['group'], [ 'role' => 'banned' ]));
    }

    public function test_group_admins_and_mods_see_pending_and_banned_rows(): void
    {
        $ids = $this->group('private');
        $all = [ 'Banned Person' => 'banned', 'Group Mod' => 'mod', 'Group Owner' => 'admin', 'Pending Applicant' => 'pending', 'Secret Member' => 'member' ];

        $this->assertSame($all, $this->members_as($ids['owner'], $ids['group']));
        $this->assertSame($all, $this->members_as($ids['mod'], $ids['group']));
        $this->assertSame([ 'Pending Applicant' => 'pending' ], $this->members_as($ids['mod'], $ids['group'], [ 'role' => 'pending' ]));
    }

    public function test_moderators_keep_full_access(): void
    {
        $ids   = $this->group('private');
        $admin = self::factory()->user->create([ 'role' => 'administrator' ]);
        $all   = [ 'Banned Person' => 'banned', 'Group Mod' => 'mod', 'Group Owner' => 'admin', 'Pending Applicant' => 'pending', 'Secret Member' => 'member' ];

        $this->assertSame($all, $this->members_as($admin, $ids['group']));

        $hidden = $this->group('hidden');
        $this->assertCount(5, $this->members_as($admin, $hidden['group']));
    }

    public function test_a_public_group_lists_confirmed_members_to_anyone_and_the_rest_to_its_admins_and_mods(): void
    {
        $ids       = $this->group('public');
        $confirmed = [ 'Group Mod' => 'mod', 'Group Owner' => 'admin', 'Secret Member' => 'member' ];
        $outsider  = $this->contributor('Outsider');

        $this->assertSame($confirmed, $this->members_as($outsider, $ids['group']));
        $this->assertSame($confirmed, $this->members_as($ids['member'], $ids['group']));
        $this->assertSame($confirmed, $this->members_as($ids['pending'], $ids['group']));
        $this->assertSame([], $this->members_as($outsider, $ids['group'], [ 'role' => 'banned' ]));
        $this->assertCount(5, $this->members_as($ids['owner'], $ids['group']));
    }

    public function test_activity_in_a_private_group_is_listed_only_to_those_who_can_access_it(): void
    {
        $ids = $this->group('private');
        // Posted while the group was public: BuddyPress does not re-hide old
        // items when a group goes private, so hide_sitewide is still 0.
        $post = wpmcp_test_bp_activity($ids['member'], 'I relapsed last week', [ 'component' => 'groups', 'type' => 'activity_update', 'item_id' => $ids['group'] ]);
        wpmcp_test_bp_activity($ids['mod'], 'We are here for you', [ 'type' => 'activity_comment', 'item_id' => $post, 'secondary_item_id' => $post ]);
        wpmcp_test_bp_activity($ids['member'], 'A public update');

        $contents = function (int $user): array {
            wp_set_current_user($user);
            $out = $this->read('buddypress-list-activity');
            $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
            $this->assertSame(count($out['result']['activity']), $out['result']['total']);
            $list = array_column($out['result']['activity'], 'content');
            sort($list);
            return $list;
        };

        $this->assertSame([ 'A public update' ], $contents($this->contributor('Outsider')));
        $this->assertSame([ 'A public update' ], $contents($ids['pending']));
        $this->assertSame([ 'A public update' ], $contents($ids['banned']));
        $everything = [ 'A public update', 'I relapsed last week', 'We are here for you' ];
        $this->assertSame($everything, $contents($ids['member']));
        $this->assertSame($everything, $contents(self::factory()->user->create([ 'role' => 'administrator' ])));
    }
}
