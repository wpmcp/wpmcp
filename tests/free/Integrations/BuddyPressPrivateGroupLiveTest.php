<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Safety\Snapshot_Store;

/**
 * Issue #503 on the real BuddyPress: the group member and activity reads
 * agree with BuddyPress's own visibility rules for a private group, with the
 * group, its memberships, the membership request, the ban and the activity
 * all made through BuddyPress's API.
 *
 * Runs only in the local gate's live BuddyPress leg (bin/test-local.sh, or
 * bin/test-local.sh --live-buddypress alone); see BuddyPressLiveTest.
 *
 * @group buddypress-live
 */
class BuddyPressPrivateGroupLiveTest extends \WP_UnitTestCase
{
    /** @var array<string, int> */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (! function_exists('buddypress') || ! bp_is_active('groups') || ! bp_is_active('activity')) {
            $this->markTestSkipped('Needs the real BuddyPress (bin/test-local.sh --live-buddypress, WPMCP_LIVE_BUDDYPRESS=1).');
        }
        Snapshot_Store::install();

        foreach ([ 'owner' => 'Group Owner', 'mod' => 'Group Mod', 'member' => 'Secret Member', 'pending' => 'Pending Applicant', 'banned' => 'Banned Person', 'outsider' => 'Outsider' ] as $key => $name) {
            $this->ids[ $key ] = self::factory()->user->create([ 'role' => 'contributor', 'display_name' => $name ]);
        }
        $this->ids['admin'] = self::factory()->user->create([ 'role' => 'administrator' ]);

        // Built the way BuddyPress's screens build it: the creator becomes
        // the group's admin, members join, the group admin promotes and bans
        // (passing the admin's id, as BuddyPress's REST API does).
        $this->as_user($this->ids['owner']);
        $group = (int) groups_create_group([ 'creator_id' => $this->ids['owner'], 'name' => 'Recovery Support', 'description' => 'Members only', 'status' => 'public' ]);
        $this->assertGreaterThan(0, $group);
        $this->ids['group'] = $group;
        foreach ([ 'mod', 'member', 'banned' ] as $key) {
            $this->assertTrue(groups_join_group($group, $this->ids[ $key ]));
        }
        $this->assertTrue(groups_promote_member($this->ids['mod'], $group, 'mod', $this->ids['owner']));
        $this->assertTrue(groups_ban_member($this->ids['banned'], $group, $this->ids['owner']));

        // Posted while the group is public; BuddyPress leaves it visible
        // site-wide when the group goes private below.
        $this->ids['early'] = (int) groups_post_update([ 'content' => 'I relapsed last week', 'group_id' => $group, 'user_id' => $this->ids['member'] ]);
        $this->assertGreaterThan(0, $this->ids['early']);
        $this->ids['reply'] = (int) bp_activity_new_comment([ 'content' => 'We are here for you', 'activity_id' => $this->ids['early'], 'user_id' => $this->ids['mod'], 'skip_notification' => true ]);
        $this->assertGreaterThan(0, $this->ids['reply']);

        $this->assertTrue(groups_edit_group_settings($group, false, 'private'));
        $this->assertSame('private', groups_get_group($group)->status);
        $this->assertNotEmpty(groups_send_membership_request([ 'user_id' => $this->ids['pending'], 'group_id' => $group ]));
        $this->ids['public_post'] = (int) bp_activity_post_update([ 'content' => 'A public update', 'user_id' => $this->ids['member'] ]);
        $this->assertGreaterThan(0, $this->ids['public_post']);
        $this->as_user(0);
    }

    /**
     * Switch the current user for WordPress and for BuddyPress, which keeps
     * its own logged-in user id from page load (bp_loggedin_user_id()).
     */
    private function as_user(int $user): void
    {
        wp_set_current_user($user);
        buddypress()->loggedin_user->id = $user;
    }

    protected function tearDown(): void
    {
        $this->as_user(0);
        parent::tearDown();
    }

    private function read(int $user, string $op, array $args = []): array
    {
        $this->as_user($user);
        return (new Plugin_Data_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
    }

    /** @return array<string, string> name => role */
    private function members_as(int $user, array $args = []): array
    {
        $out = $this->read($user, 'buddypress-list-group-members', [ 'group_id' => $this->ids['group'] ] + $args);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $rows = array_column($out['result']['members'], 'role', 'name');
        ksort($rows);
        $this->assertSame(count($rows), $out['result']['total']);
        return $rows;
    }

    public function test_buddypress_itself_agrees_on_who_may_see_the_private_group(): void
    {
        foreach ([ 'outsider' => false, 'pending' => false, 'banned' => false, 'member' => true, 'mod' => true, 'owner' => true, 'admin' => true ] as $key => $access) {
            $this->as_user($this->ids[ $key ]);
            $this->assertSame($access, bp_group_is_visible(groups_get_group($this->ids['group'])), "BuddyPress access for {$key}");

            $out = $this->read($this->ids[ $key ], 'buddypress-list-group-members', [ 'group_id' => $this->ids['group'] ]);
            if ($access) {
                $this->assertArrayNotHasKey('error', $out, "{$key}: " . wp_json_encode($out));
            } else {
                $this->assertSame('group_not_found', $out['error']['code'] ?? '', "{$key} read the private group's members: " . wp_json_encode($out));
                $this->assertStringNotContainsString('Secret Member', (string) wp_json_encode($out));
            }
        }
    }

    public function test_a_member_sees_confirmed_members_only_and_admins_mods_and_moderators_see_every_row(): void
    {
        $confirmed = [ 'Group Mod' => 'mod', 'Group Owner' => 'admin', 'Secret Member' => 'member' ];
        // BuddyPress keeps a membership request in its invitations table, not
        // as a member row, so the applicant is not listed to anyone here; the
        // harness test covers legacy pending member rows.
        $all       = [ 'Banned Person' => 'banned', 'Group Mod' => 'mod', 'Group Owner' => 'admin', 'Secret Member' => 'member' ];

        // The same confirmed list BuddyPress's own member loop shows.
        $theirs = array_map(static fn ($u): string => (string) $u->display_name, groups_get_group_members([ 'group_id' => $this->ids['group'], 'group_role' => [ 'admin', 'mod', 'member' ] ])['members']);
        sort($theirs);
        $this->assertSame(array_keys($confirmed), $theirs);

        $this->assertSame($confirmed, $this->members_as($this->ids['member']));
        $this->assertSame([], $this->members_as($this->ids['member'], [ 'role' => 'pending' ]));
        $this->assertSame([], $this->members_as($this->ids['member'], [ 'role' => 'banned' ]));
        $this->assertSame($all, $this->members_as($this->ids['owner']));
        $this->assertSame([ 'Banned Person' => 'banned' ], $this->members_as($this->ids['mod'], [ 'role' => 'banned' ]));
        $this->assertSame($all, $this->members_as($this->ids['mod']));
        $this->assertSame($all, $this->members_as($this->ids['admin']));
    }

    public function test_group_activity_is_listed_only_where_buddypress_lets_the_caller_read_it(): void
    {
        $group_items = [ $this->ids['early'], $this->ids['reply'] ];
        foreach ([ 'outsider' => false, 'pending' => false, 'banned' => false, 'member' => true, 'admin' => true ] as $key => $access) {
            $out = $this->read($this->ids[ $key ], 'buddypress-list-activity', [ 'per_page' => 100 ]);
            $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
            $listed = array_column($out['result']['activity'], 'id');
            $this->assertContains($this->ids['public_post'], $listed, $key);

            foreach ($group_items as $id) {
                $this->assertSame($access, bp_activity_user_can_read(new \BP_Activity_Activity($id), $this->ids[ $key ]), "BuddyPress read access to {$id} for {$key}");
                $this->assertSame($access, in_array($id, $listed, true), "{$key} and activity {$id}");
            }
            foreach ($out['result']['activity'] as $item) {
                $this->assertTrue(bp_activity_user_can_read(new \BP_Activity_Activity($item['id']), $this->ids[ $key ]), "{$key} was listed activity {$item['id']} BuddyPress hides from them");
            }
        }
    }
}
