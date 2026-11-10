<?php

namespace WPMCP\Tests\Free\MultisiteSecurity;

use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Users\Get_User;
use WPMCP\Tools\Users\List_Users;
use WPMCP\Tools\Users\Update_User;

/**
 * Issue #560: on multisite the users table is shared by every site. A
 * sub-site administrator holds list_users on its own blog, and get-user
 * looked an id up with get_userdata() alone, so it returned the profile and
 * email of any network account, including other sites' admins and the
 * super admins. Core REST answers rest_user_invalid_id for a user who is
 * not a member of the current site unless the caller can
 * manage_network_users; get-user now does the same.
 *
 * The other user tools are pinned here too: list-users only lists members
 * (WP_User_Query scopes to the current blog), and update-user needs
 * edit_users, which core maps to manage_network_users on multisite, so a
 * sub-site admin cannot reach it; its handler also refuses non-members.
 * No tool changes roles or writes user meta.
 *
 * Runs under WP_MULTISITE=1 and skips on single site.
 */
class SubSiteUserMembershipTest extends \WP_UnitTestCase
{
    private int $blog = 0;
    private int $other = 0;
    private int $sub_admin = 0;
    private int $super_admin = 0;
    private int $outsider = 0;
    private int $member = 0;
    /** Unique per test: the snapshot table install commits, so users can outlive a test. */
    private string $tag = '';

    protected function setUp(): void
    {
        parent::setUp();
        if (! is_multisite()) {
            $this->markTestSkipped('Multisite only: run with WP_MULTISITE=1.');
        }
        $this->setExpectedDeprecated('wpmu_new_blog');
        $this->tag = strtolower(wp_generate_password(8, false));

        $this->blog  = (int) self::factory()->blog->create();
        $this->other = (int) self::factory()->blog->create();

        $this->super_admin = (int) self::factory()->user->create(['user_email' => "super-560-{$this->tag}@example.test"]);
        grant_super_admin($this->super_admin);
        remove_user_from_blog($this->super_admin, get_main_site_id());

        $this->sub_admin = (int) self::factory()->user->create(['role' => 'subscriber']);
        add_user_to_blog($this->blog, $this->sub_admin, 'administrator');

        // An administrator of another site only.
        $this->outsider = (int) self::factory()->user->create(['role' => 'subscriber', 'user_email' => "outsider-560-{$this->tag}@example.test"]);
        add_user_to_blog($this->other, $this->outsider, 'administrator');
        remove_user_from_blog($this->outsider, get_main_site_id());

        // A subscriber of the sub-site.
        $this->member = (int) self::factory()->user->create(['role' => 'subscriber', 'user_email' => "member-560-{$this->tag}@example.test"]);
        add_user_to_blog($this->blog, $this->member, 'subscriber');

        switch_to_blog($this->blog);
        wp_set_current_user($this->sub_admin);
        $this->assertFalse(is_super_admin($this->sub_admin));
        $this->assertTrue(current_user_can('list_users'), 'Fixture: the sub-site admin holds list_users on its blog');
        $this->assertFalse(is_user_member_of_blog($this->outsider, $this->blog));
        $this->assertFalse(is_user_member_of_blog($this->super_admin, $this->blog));
    }

    protected function tearDown(): void
    {
        if (is_multisite()) {
            if (ms_is_switched()) {
                restore_current_blog();
            }
            revoke_super_admin($this->super_admin);
        }
        parent::tearDown();
    }

    /** @return array|null The get-user row, or null when refused. */
    private function get_user(int $id): ?array
    {
        try {
            return (new Get_User())->handle(['id' => $id]);
        } catch (\RuntimeException $e) {
            $this->assertSame('User not found.', $e->getMessage(), 'A non-member must look exactly like a missing user');
            return null;
        }
    }

    public function test_get_user_hides_an_admin_of_another_site_from_a_sub_site_admin(): void
    {
        $this->assertNull($this->get_user($this->outsider), 'get-user returned a non-member\'s profile to a sub-site admin');
    }

    public function test_get_user_hides_a_non_member_super_admin_from_a_sub_site_admin(): void
    {
        $this->assertNull($this->get_user($this->super_admin), 'get-user returned a super admin\'s email to a sub-site admin');
    }

    public function test_get_user_still_returns_a_member_to_a_sub_site_admin(): void
    {
        $row = $this->get_user($this->member);
        $this->assertNotNull($row);
        $this->assertSame("member-560-{$this->tag}@example.test", $row['email']);
    }

    public function test_get_user_returns_a_non_member_to_a_network_user_manager(): void
    {
        wp_set_current_user($this->super_admin);
        $this->assertTrue(current_user_can('manage_network_users'));

        $row = $this->get_user($this->outsider);
        $this->assertNotNull($row, 'A super admin must still look up any network account');
        $this->assertSame("outsider-560-{$this->tag}@example.test", $row['email']);
    }

    public function test_list_users_lists_members_only(): void
    {
        $out = (new List_Users())->handle(['per_page' => 100]);
        $ids = array_map(static fn (array $row): int => $row['id'], $out['users']);

        $this->assertContains($this->member, $ids);
        $this->assertNotContains($this->outsider, $ids, 'list-users listed another site\'s admin');
        $this->assertNotContains($this->super_admin, $ids, 'list-users listed a non-member super admin');

        $search = (new List_Users())->handle(['search' => 'outsider-560-' . $this->tag]);
        $this->assertSame([], $search['users'], 'list-users search reached a non-member');
    }

    public function test_update_user_is_closed_to_a_sub_site_admin(): void
    {
        $this->assertFalse(
            wp_get_abilities()['wpmcp/update-user']->check_permissions(['id' => $this->outsider]),
            'update-user must not be open to a sub-site admin on multisite (edit_users is network-level)'
        );
    }

    public function test_update_user_handler_refuses_a_non_member_without_network_user_management(): void
    {
        // The sub-site needs its own snapshot table, so update-user can only
        // be refused for the reason under test.
        Snapshot_Store::install();
        $before  = get_userdata($this->outsider)->display_name;
        $refused = null;
        try {
            (new Update_User())->handle(['id' => $this->outsider, 'display_name' => 'Renamed by sub-site admin']);
        } catch (\RuntimeException $e) {
            $refused = $e->getMessage();
        }

        clean_user_cache($this->outsider);
        $this->assertSame($before, get_userdata($this->outsider)->display_name, 'update-user changed a non-member\'s profile');
        $this->assertSame('User not found.', $refused, 'A non-member must look exactly like a missing user');
    }

    public function test_update_user_handler_still_lets_a_network_user_manager_edit_a_non_member(): void
    {
        // The sub-site needs its own snapshot table, so update-user can only
        // be refused for the reason under test.
        Snapshot_Store::install();
        wp_set_current_user($this->super_admin);

        $out = (new Update_User())->handle(['id' => $this->outsider, 'display_name' => 'Renamed by super admin']);

        clean_user_cache($this->outsider);
        $this->assertSame(['display_name'], $out['updated']);
        $this->assertSame('Renamed by super admin', get_userdata($this->outsider)->display_name);
    }
}
