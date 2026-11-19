<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Integrations\Ultimate_Member_Pack;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * Ultimate Member (issue #669): roles, members, forms and member directories
 * on the plugin-data dispatcher pair, free tier, so they add no top-level
 * tools.
 *
 * The plugin is not loaded in the shared test core. Presence is driven
 * through wpmcp_ultimate_member_active, the um_form and um_directory post
 * types are registered here, and the data is stored the way Ultimate Member
 * 2.14.2 keeps it: custom role keys in the um_roles option (each a WordPress
 * role named um_<key>, its settings in um_role_<key>_meta), a member's
 * status in the account_status user meta, and form and directory settings
 * in _um_* post meta.
 */
class UltimateMemberPackTest extends \WP_UnitTestCase
{
    private const OPS = [
        'um-list-roles'              => [ 'read', 'manage_options' ],
        'um-list-members'            => [ 'read', 'manage_options' ],
        'um-list-forms'              => [ 'read', 'manage_options' ],
        'um-list-member-directories' => [ 'read', 'manage_options' ],
        'um-get-member-directory'    => [ 'read', 'manage_options' ],
        'um-update-member-role'      => [ 'destructive', 'promote_users' ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        register_post_type('um_form', [ 'public' => false, 'show_ui' => true ]);
        register_post_type('um_directory', [ 'public' => false, 'show_ui' => true ]);
        add_role('um_vip', 'VIP', [ 'read' => true ]);
        add_role('um_member', 'Member', [ 'read' => true ]);
        add_role('um_staff', 'Staff', [ 'read' => true, 'list_users' => true ]);
        update_option('um_roles', [ 'vip', 'member', 'staff' ]);
        update_option('um_role_vip_meta', [ '_um_priority' => 5, '_um_status' => 'approved', '_um_can_access_wpadmin' => 0 ]);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        add_filter('wpmcp_ultimate_member_active', '__return_true');
        add_filter('wpmcp_enable_ultimate_member_write', '__return_true');
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_ultimate_member_active');
        remove_all_filters('wpmcp_enable_ultimate_member_write');
        foreach ([ 'um_vip', 'um_member', 'um_staff' ] as $role) {
            remove_role($role);
        }
        unregister_post_type('um_form');
        unregister_post_type('um_directory');
        parent::tearDown();
    }

    private function read(string $op, array $args = []): array
    {
        $out = (new Plugin_Data_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
        return isset($out['result']) && ! isset($out['error']) ? $out['result'] : $out;
    }

    private function write(string $op, array $args, bool $confirm = false): array
    {
        $call = [ 'operation' => $op, 'args' => $args ];
        if ($confirm) {
            $call['confirm'] = true;
        }
        return (new Plugin_Data_Integration())->handle_write($call);
    }

    private function ok(array $out): array
    {
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out;
    }

    private function member(string $role = 'um_member', string $status = 'approved', array $o = []): int
    {
        $id = self::factory()->user->create($o + [ 'role' => 'subscriber' ]);
        $user = new \WP_User($id);
        $user->add_role($role);
        update_user_meta($id, 'account_status', $status);
        return $id;
    }

    private function roles(int $id): array
    {
        clean_user_cache($id);
        $roles = (new \WP_User($id))->roles;
        sort($roles);
        return array_values($roles);
    }

    // ---------------------------------------------------------------
    // Catalog and availability
    // ---------------------------------------------------------------

    public function test_the_catalog_lists_every_op_with_its_mode_and_capability(): void
    {
        $ops = array_column((new Plugin_Data_Integration())->catalog()['operations'], null, 'name');
        foreach (self::OPS as $name => [ $mode, $cap ]) {
            $this->assertArrayHasKey($name, $ops);
            $this->assertSame($mode, $ops[ $name ]['mode'], $name);
            $this->assertTrue($ops[ $name ]['dependency_met'], $name);
            $this->assertSame($cap, $ops[ $name ]['capability'], $name);
        }
    }

    public function test_presence_defaults_to_the_plugin_constant(): void
    {
        remove_all_filters('wpmcp_ultimate_member_active');
        $this->assertSame(defined('UM_VERSION'), Ultimate_Member_Pack::active());
    }

    public function test_while_the_plugin_is_missing_a_call_answers_plugin_missing_with_the_plugins_link(): void
    {
        remove_all_filters('wpmcp_ultimate_member_active');
        add_filter('wpmcp_ultimate_member_active', '__return_false');
        $out = $this->read('um-list-roles');
        $this->assertSame('plugin_missing', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertStringContainsString('Install and activate Ultimate Member', $out['error']['message']);
        $this->assertSame(admin_url('plugins.php'), $out['error']['data']['plugins_url'] ?? null);
    }

    public function test_the_role_write_is_off_until_the_site_opts_in(): void
    {
        $id = $this->member();
        remove_all_filters('wpmcp_enable_ultimate_member_write');
        $out = $this->write('um-update-member-role', [ 'user_id' => $id, 'role' => 'um_vip' ], true);
        $this->assertSame('opt_in_required', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertStringContainsString('wpmcp_enable_ultimate_member_write', $out['error']['message']);
        $this->assertSame([ 'subscriber', 'um_member' ], $this->roles($id));
    }

    public function test_reads_take_manage_options(): void
    {
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        foreach ([ 'um-list-roles', 'um-list-members', 'um-list-forms', 'um-list-member-directories' ] as $op) {
            $this->assertSame('operation_denied', $this->read($op)['error']['code'] ?? null, $op);
        }
    }

    // ---------------------------------------------------------------
    // Reads
    // ---------------------------------------------------------------

    public function test_list_roles_marks_the_custom_roles_their_settings_and_which_are_assignable(): void
    {
        $this->member('um_vip');
        $roles = array_column($this->read('um-list-roles')['roles'], null, 'role');
        $this->assertTrue($roles['um_vip']['custom']);
        $this->assertSame('VIP', $roles['um_vip']['name']);
        $this->assertSame(5, $roles['um_vip']['priority']);
        $this->assertSame('approved', $roles['um_vip']['registration_status']);
        $this->assertSame(1, $roles['um_vip']['users']);
        $this->assertTrue($roles['um_vip']['assignable']);
        $this->assertTrue($roles['subscriber']['assignable']);
        $this->assertFalse($roles['subscriber']['custom']);
        $this->assertFalse($roles['administrator']['assignable']);
        $this->assertFalse($roles['um_staff']['assignable'], 'a role holding list_users is not assignable');
    }

    public function test_list_members_masks_names_and_emails_and_filters(): void
    {
        $jane = $this->member('um_vip', 'approved', [ 'display_name' => 'Jane Doe', 'user_email' => 'jane@example.com' ]);
        $this->member('um_member', 'awaiting_admin_review');

        $out = $this->read('um-list-members', [ 'role' => 'um_vip' ]);
        $this->assertTrue($out['masked']);
        $this->assertSame(1, $out['total']);
        $row = $out['members'][0];
        $this->assertSame($jane, $row['user_id']);
        $this->assertSame('J***', $row['name']);
        $this->assertSame('j***@example.com', $row['email']);
        $this->assertSame('approved', $row['account_status']);
        $this->assertContains('um_vip', $row['roles']);
        $this->assertStringNotContainsString('jane@example.com', (string) wp_json_encode($out));

        $pending = $this->read('um-list-members', [ 'account_status' => 'awaiting_admin_review' ]);
        $this->assertSame(1, $pending['total']);
    }

    public function test_list_forms_returns_mode_field_count_role_and_shortcode(): void
    {
        $reg = self::factory()->post->create([ 'post_type' => 'um_form', 'post_title' => 'Join', 'post_status' => 'publish' ]);
        update_post_meta($reg, '_um_mode', 'register');
        update_post_meta($reg, '_um_core', 'register');
        update_post_meta($reg, '_um_custom_fields', [ 'user_login' => [ 'type' => 'text' ], 'user_email' => [ 'type' => 'text' ] ]);
        update_post_meta($reg, '_um_register_use_custom_settings', 1);
        update_post_meta($reg, '_um_register_role', 'um_vip');
        $login = self::factory()->post->create([ 'post_type' => 'um_form', 'post_title' => 'Sign in', 'post_status' => 'publish' ]);
        update_post_meta($login, '_um_mode', 'login');

        $forms = array_column($this->read('um-list-forms')['forms'], null, 'id');
        $this->assertSame('register', $forms[ $reg ]['mode']);
        $this->assertSame(2, $forms[ $reg ]['field_count']);
        $this->assertSame('um_vip', $forms[ $reg ]['role']);
        $this->assertTrue($forms[ $reg ]['core']);
        $this->assertSame('[ultimatemember form_id="' . $reg . '"]', $forms[ $reg ]['shortcode']);
        $this->assertNull($forms[ $login ]['role']);

        $this->assertSame([ $login ], array_column($this->read('um-list-forms', [ 'mode' => 'login' ])['forms'], 'id'));
    }

    public function test_member_directories_are_listed_and_read_in_full(): void
    {
        $dir = self::factory()->post->create([ 'post_type' => 'um_directory', 'post_title' => 'Members', 'post_status' => 'publish' ]);
        update_post_meta($dir, '_um_mode', 'directory');
        update_post_meta($dir, '_um_roles', [ 'um_vip' ]);
        update_post_meta($dir, '_um_profiles_per_page', 12);
        update_post_meta($dir, '_um_search', 1);
        update_post_meta($dir, '_um_search_fields', [ 'first_name' ]);
        update_post_meta($dir, '_um_directory_token', 'tok');
        update_post_meta($dir, '_edit_lock', 'x');

        $list = $this->read('um-list-member-directories');
        $this->assertSame([ $dir ], array_column($list['directories'], 'id'));
        $this->assertSame([ 'um_vip' ], $list['directories'][0]['roles']);

        $out = $this->read('um-get-member-directory', [ 'directory_id' => $dir ]);
        $this->assertSame('Members', $out['directory']['title']);
        $this->assertSame([ 'um_vip' ], $out['directory']['settings']['roles']);
        $this->assertSame([ 'first_name' ], $out['directory']['settings']['search_fields']);
        $this->assertArrayNotHasKey('_edit_lock', $out['directory']['settings']);
        $this->assertSame('[ultimatemember form_id="' . $dir . '"]', $out['directory']['shortcode']);

        $form = self::factory()->post->create([ 'post_type' => 'um_form', 'post_status' => 'publish' ]);
        $this->assertSame('directory_not_found', $this->read('um-get-member-directory', [ 'directory_id' => $form ])['error']['code'] ?? null);
    }

    // ---------------------------------------------------------------
    // Role write
    // ---------------------------------------------------------------

    public function test_update_member_role_asks_for_confirmation_with_a_preview_and_changes_nothing(): void
    {
        $id  = $this->member();
        $out = $this->write('um-update-member-role', [ 'user_id' => $id, 'role' => 'um_vip' ]);
        $this->assertSame('confirmation_required', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertSame([ 'um_member' ], $out['error']['data']['current_um_roles']);
        $this->assertSame('um_vip', $out['error']['data']['role']);
        $this->assertSame([ 'subscriber', 'um_member' ], $this->roles($id));
    }

    public function test_update_member_role_swaps_the_um_role_and_rolls_back_exactly(): void
    {
        $id = $this->member();
        $out = $this->ok($this->write('um-update-member-role', [ 'user_id' => $id, 'role' => 'um_vip' ], true));
        $this->assertTrue($out['recoverable']);
        $this->assertSame([ 'subscriber', 'um_vip' ], $out['result']['roles']);
        $this->assertSame([ 'um_member' ], $out['result']['previous_um_roles']);
        $this->assertSame([ 'subscriber', 'um_vip' ], $this->roles($id));

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame([ 'subscriber', 'um_member' ], $this->roles($id));
    }

    public function test_update_member_role_refuses_privileged_roles_and_protected_users(): void
    {
        $id    = $this->member();
        $admin = self::factory()->user->create([ 'role' => 'administrator' ]);
        foreach ([
            [ [ 'user_id' => $id, 'role' => 'administrator' ], 'role_not_assignable' ],
            [ [ 'user_id' => $id, 'role' => 'editor' ], 'role_not_assignable' ],
            [ [ 'user_id' => $id, 'role' => 'um_staff' ], 'role_not_assignable' ],
            [ [ 'user_id' => $id, 'role' => 'um_nope' ], 'role_not_assignable' ],
            [ [ 'user_id' => $admin, 'role' => 'um_vip' ], 'user_protected' ],
            [ [ 'user_id' => get_current_user_id(), 'role' => 'um_vip' ], 'user_protected' ],
            [ [ 'user_id' => $id, 'role' => 'um_member' ], 'role_unchanged' ],
        ] as [ $args, $code ]) {
            $out = $this->write('um-update-member-role', $args, true);
            $this->assertSame($code, $out['error']['code'] ?? null, (string) wp_json_encode($out));
        }
        $this->assertSame([ 'subscriber', 'um_member' ], $this->roles($id));
        $this->assertSame([ 'administrator' ], $this->roles($admin));
        $this->assertSame([], Snapshot_Store::list_by_session('default'));
    }

    public function test_update_member_role_needs_promote_users(): void
    {
        $id = $this->member();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        $out = $this->write('um-update-member-role', [ 'user_id' => $id, 'role' => 'um_vip' ], true);
        $this->assertSame('operation_denied', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertSame([ 'subscriber', 'um_member' ], $this->roles($id));
    }
}
