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
 * group's management screens, which are for the group's admins and
 * moderators, not its mods (issue #518). Since BuddyPress 5.0 a membership
 * request is a row in its invitations table. Activity posted in a private group is readable
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

    public function test_group_admins_see_pending_and_banned_rows_and_group_mods_do_not(): void
    {
        $ids       = $this->group('private');
        $all       = [ 'Banned Person' => 'banned', 'Group Mod' => 'mod', 'Group Owner' => 'admin', 'Pending Applicant' => 'pending', 'Secret Member' => 'member' ];
        $confirmed = [ 'Group Mod' => 'mod', 'Group Owner' => 'admin', 'Secret Member' => 'member' ];

        $this->assertSame($all, $this->members_as($ids['owner'], $ids['group']));
        $this->assertSame([ 'Pending Applicant' => 'pending' ], $this->members_as($ids['owner'], $ids['group'], [ 'role' => 'pending' ]));
        $this->assertSame([ 'Banned Person' => 'banned' ], $this->members_as($ids['owner'], $ids['group'], [ 'role' => 'banned' ]));

        // Issue #518: BuddyPress's management screens are for group admins only.
        $this->assertSame($confirmed, $this->members_as($ids['mod'], $ids['group']));
        $this->assertSame([], $this->members_as($ids['mod'], $ids['group'], [ 'role' => 'pending' ]));
        $this->assertSame([], $this->members_as($ids['mod'], $ids['group'], [ 'role' => 'banned' ]));
    }

    /**
     * A membership request as BuddyPress 5.0+ stores it: a 'request' row of
     * the group invitations class in its invitations table.
     */
    private function request(int $group, int $user, array $columns = []): int
    {
        return wpmcp_test_bp_insert('bp_invitations', $columns + [
            'user_id'       => $user,
            'inviter_id'    => 0,
            'class'         => 'BP_Groups_Invitation_Manager',
            'item_id'       => $group,
            'type'          => 'request',
            'content'       => 'Please let me in, I need this group',
            'date_modified' => '2026-10-01 10:00:00',
            'invite_sent'   => 1,
            'accepted'      => 0,
        ]);
    }

    public function test_current_membership_requests_are_pending_rows_for_group_admins_and_moderators_only(): void
    {
        $ids       = $this->group('private');
        $requester = $this->contributor('Current Requester');
        $this->request($ids['group'], $requester);
        // Not pending requests of this group: an accepted request, an
        // invitation, and a request to another group.
        $this->request($ids['group'], $this->contributor('Accepted Requester'), [ 'accepted' => 1 ]);
        $this->request($ids['group'], $this->contributor('Invited Person'), [ 'type' => 'invite', 'inviter_id' => $ids['owner'] ]);
        $other = $this->group('private');
        $this->request($other['group'], $this->contributor('Elsewhere Requester'));

        $pending = [ 'Current Requester' => 'pending', 'Pending Applicant' => 'pending' ];
        $admin   = self::factory()->user->create([ 'role' => 'administrator' ]);
        $this->assertSame($pending, $this->members_as($ids['owner'], $ids['group'], [ 'role' => 'pending' ]));
        $this->assertSame($pending, $this->members_as($admin, $ids['group'], [ 'role' => 'pending' ]));
        $this->assertSame('pending', $this->members_as($ids['owner'], $ids['group'])['Current Requester'] ?? null);

        foreach ([ 'mod', 'member' ] as $who) {
            $this->assertSame([], $this->members_as($ids[ $who ], $ids['group'], [ 'role' => 'pending' ]), $who);
            $this->assertArrayNotHasKey('Current Requester', $this->members_as($ids[ $who ], $ids['group']), $who);
        }

        // The request's message is never returned.
        wp_set_current_user($ids['owner']);
        $this->assertStringNotContainsString('Please let me in', (string) wp_json_encode($this->read('buddypress-list-group-members', [ 'group_id' => $ids['group'] ])));
    }

    public function test_paging_runs_through_member_rows_and_then_membership_requests(): void
    {
        $ids = $this->group('private');
        foreach ([ 'First Requester', 'Second Requester' ] as $name) {
            $this->request($ids['group'], $this->contributor($name));
        }
        wp_set_current_user($ids['owner']);

        $seen = [];
        foreach ([ 1, 2, 3, 4 ] as $page) {
            $out = $this->read('buddypress-list-group-members', [ 'group_id' => $ids['group'], 'per_page' => 2, 'page' => $page ]);
            $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
            $this->assertSame(7, $out['result']['total']);
            $this->assertCount(4 === $page ? 1 : 2, $out['result']['members'], "page {$page}");
            $seen = array_merge($seen, array_column($out['result']['members'], 'name'));
        }
        $this->assertSame([ 'Group Owner', 'Group Mod', 'Secret Member', 'Pending Applicant', 'Banned Person', 'First Requester', 'Second Requester' ], $seen);
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

    public function test_a_public_group_lists_confirmed_members_to_anyone_and_the_rest_to_its_admins(): void
    {
        $ids       = $this->group('public');
        $confirmed = [ 'Group Mod' => 'mod', 'Group Owner' => 'admin', 'Secret Member' => 'member' ];
        $outsider  = $this->contributor('Outsider');

        $this->assertSame($confirmed, $this->members_as($outsider, $ids['group']));
        $this->assertSame($confirmed, $this->members_as($ids['member'], $ids['group']));
        $this->assertSame($confirmed, $this->members_as($ids['pending'], $ids['group']));
        $this->assertSame([], $this->members_as($outsider, $ids['group'], [ 'role' => 'banned' ]));
        $this->assertCount(5, $this->members_as($ids['owner'], $ids['group']));
        $this->assertSame($confirmed, $this->members_as($ids['mod'], $ids['group']));
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
