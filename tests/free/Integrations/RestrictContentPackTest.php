<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Integrations\Restrict_Content_Pack;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * Restrict Content and Restrict Content Pro (issue #669): membership level
 * and member ops on the plugin-data dispatcher pair, free tier, so they add
 * no top-level tools.
 *
 * The plugin is not loaded in the shared test core. Presence is driven
 * through wpmcp_restrict_content_active, and the three tables are built from
 * the plugin's own DDL (Restrict Content 4.0.6: Membership_Levels,
 * Memberships and Customers table schemas).
 */
class RestrictContentPackTest extends \WP_UnitTestCase
{
    private const OPS = [
        'rcp-list-membership-levels'  => 'read',
        'rcp-list-members'            => 'read',
        'rcp-update-membership-level' => 'write',
    ];

    private static function table(string $name): string
    {
        global $wpdb;
        return $wpdb->prefix . $name;
    }

    public static function wpSetUpBeforeClass(): void
    {
        global $wpdb;
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL copied from the plugin.
        $wpdb->query('CREATE TABLE IF NOT EXISTS ' . self::table('restrict_content_pro') . " (
            id bigint(9) UNSIGNED NOT NULL AUTO_INCREMENT,
            name varchar(200) NOT NULL,
            description longtext NOT NULL,
            duration smallint UNSIGNED NOT NULL DEFAULT 0,
            duration_unit tinytext NOT NULL,
            trial_duration smallint(6) UNSIGNED NOT NULL DEFAULT 0,
            trial_duration_unit tinytext NOT NULL,
            price tinytext NOT NULL,
            fee tinytext NOT NULL,
            maximum_renewals smallint UNSIGNED NOT NULL DEFAULT 0,
            after_final_payment tinytext NOT NULL,
            level mediumint UNSIGNED NOT NULL DEFAULT 0,
            role tinytext NOT NULL,
            status varchar(12) NOT NULL DEFAULT 'active',
            list_order mediumint UNSIGNED NOT NULL DEFAULT 0,
            date_created datetime NOT NULL,
            date_modified datetime NOT NULL,
            uuid varchar(100) NOT NULL DEFAULT '',
            PRIMARY KEY (id),
            KEY name (name(191)),
            KEY status (status)
        )");
        $wpdb->query('CREATE TABLE IF NOT EXISTS ' . self::table('rcp_memberships') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            customer_id bigint(20) unsigned NOT NULL default '0',
            user_id bigint(20) unsigned DEFAULT NULL,
            object_id bigint(9) NOT NULL default '0',
            object_type varchar(20) DEFAULT NULL,
            currency varchar(20) NOT NULL DEFAULT 'USD',
            initial_amount mediumtext NOT NULL,
            recurring_amount mediumtext NOT NULL,
            created_date datetime NOT NULL,
            activated_date datetime DEFAULT NULL,
            trial_end_date datetime DEFAULT NULL,
            renewed_date datetime DEFAULT NULL,
            cancellation_date datetime DEFAULT NULL,
            expiration_date datetime DEFAULT NULL,
            payment_plan_completed_date datetime DEFAULT NULL,
            auto_renew smallint unsigned NOT NULL DEFAULT '0',
            times_billed smallint unsigned NOT NULL DEFAULT '0',
            maximum_renewals smallint unsigned NOT NULL DEFAULT '0',
            status varchar(12) NOT NULL DEFAULT 'pending',
            gateway_customer_id tinytext DEFAULT NULL,
            gateway_subscription_id tinytext DEFAULT NULL,
            gateway tinytext NOT NULL,
            signup_method tinytext NOT NULL,
            subscription_key varchar(32) NOT NULL default '',
            notes longtext NOT NULL,
            upgraded_from bigint(20) unsigned DEFAULT NULL,
            date_modified datetime NOT NULL,
            disabled smallint unsigned DEFAULT NULL,
            uuid varchar(100) NOT NULL default '',
            PRIMARY KEY (id),
            KEY customer_id (customer_id),
            KEY object_id (object_id),
            KEY status (status)
        )");
        $wpdb->query('CREATE TABLE IF NOT EXISTS ' . self::table('rcp_customers') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL DEFAULT '0',
            date_registered datetime NOT NULL,
            email_verification enum('verified', 'pending', 'none') DEFAULT 'none',
            last_login datetime DEFAULT NULL,
            has_trialed smallint unsigned DEFAULT NULL,
            ips longtext NOT NULL,
            notes longtext NOT NULL,
            uuid varchar(100) NOT NULL default '',
            PRIMARY KEY (id),
            KEY user_id (user_id)
        )");
        // phpcs:enable
    }

    public static function wpTearDownAfterClass(): void
    {
        global $wpdb;
        foreach ([ 'restrict_content_pro', 'rcp_memberships', 'rcp_customers' ] as $name) {
            $wpdb->query('DROP TABLE IF EXISTS ' . self::table($name));
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        foreach ([ 'restrict_content_pro', 'rcp_memberships', 'rcp_customers' ] as $name) {
            $wpdb->query('DELETE FROM ' . self::table($name));
        }
        Snapshot_Store::install();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        add_filter('wpmcp_restrict_content_active', '__return_true');
        add_filter('wpmcp_enable_restrict_content_write', '__return_true');
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_restrict_content_active');
        remove_all_filters('wpmcp_enable_restrict_content_write');
        remove_all_actions('rcp_edit_subscription_level');
        parent::tearDown();
    }

    private function read(string $op, array $args = []): array
    {
        $out = (new Plugin_Data_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
        return isset($out['result']) && ! isset($out['error']) ? $out['result'] : $out;
    }

    private function write(string $op, array $args): array
    {
        return (new Plugin_Data_Integration())->handle_write([ 'operation' => $op, 'args' => $args ]);
    }

    private function ok(array $out): array
    {
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out;
    }

    private function level(array $o = []): int
    {
        global $wpdb;
        $wpdb->insert(self::table('restrict_content_pro'), $o + [
            'name' => 'Gold', 'description' => 'All access', 'duration' => 1, 'duration_unit' => 'month',
            'trial_duration' => 0, 'trial_duration_unit' => 'day', 'price' => '29.99', 'fee' => '0',
            'maximum_renewals' => 0, 'after_final_payment' => '', 'level' => 2, 'role' => 'subscriber',
            'status' => 'active', 'list_order' => 1, 'date_created' => '2026-01-01 00:00:00',
            'date_modified' => '2026-01-01 00:00:00', 'uuid' => 'urn:uuid:1',
        ]);
        return (int) $wpdb->insert_id;
    }

    private function membership(int $level, int $user, array $o = []): int
    {
        global $wpdb;
        $wpdb->insert(self::table('rcp_customers'), [ 'user_id' => $user, 'date_registered' => '2026-01-01 00:00:00', 'ips' => '10.0.0.1', 'notes' => '' ]);
        $customer = (int) $wpdb->insert_id;
        $wpdb->insert(self::table('rcp_memberships'), $o + [
            'customer_id' => $customer, 'user_id' => $user, 'object_id' => $level, 'object_type' => 'membership',
            'initial_amount' => '29.99', 'recurring_amount' => '29.99', 'created_date' => '2026-02-01 00:00:00',
            'expiration_date' => '2026-03-01 23:59:59', 'auto_renew' => 1, 'times_billed' => 1, 'status' => 'active',
            'gateway' => 'stripe', 'gateway_customer_id' => 'cus_secret', 'gateway_subscription_id' => 'sub_secret',
            'signup_method' => 'live', 'subscription_key' => 'abc123', 'notes' => 'private note', 'date_modified' => '2026-02-01 00:00:00',
        ]);
        return (int) $wpdb->insert_id;
    }

    private function row(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', self::table('restrict_content_pro'), $id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    // ---------------------------------------------------------------
    // Catalog and availability
    // ---------------------------------------------------------------

    public function test_the_catalog_lists_every_op_with_its_mode_and_manage_options(): void
    {
        $ops = array_column((new Plugin_Data_Integration())->catalog()['operations'], null, 'name');
        foreach (self::OPS as $name => $mode) {
            $this->assertArrayHasKey($name, $ops);
            $this->assertSame($mode, $ops[ $name ]['mode'], $name);
            $this->assertTrue($ops[ $name ]['dependency_met'], $name);
            $this->assertSame('manage_options', $ops[ $name ]['capability'], $name);
        }
    }

    public function test_presence_defaults_to_the_plugin_constant(): void
    {
        remove_all_filters('wpmcp_restrict_content_active');
        $this->assertSame(defined('RCP_PLUGIN_VERSION'), Restrict_Content_Pack::active());
    }

    public function test_while_the_plugin_is_missing_a_call_answers_plugin_missing_with_the_plugins_link(): void
    {
        remove_all_filters('wpmcp_restrict_content_active');
        add_filter('wpmcp_restrict_content_active', '__return_false');
        $out = $this->read('rcp-list-membership-levels');
        $this->assertSame('plugin_missing', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertStringContainsString('Install and activate Restrict Content', $out['error']['message']);
        $this->assertSame(admin_url('plugins.php'), $out['error']['data']['plugins_url'] ?? null);
    }

    public function test_writes_are_off_until_the_site_opts_in(): void
    {
        $id = $this->level();
        remove_all_filters('wpmcp_enable_restrict_content_write');
        $out = $this->write('rcp-update-membership-level', [ 'level_id' => $id, 'name' => 'Platinum' ]);
        $this->assertSame('opt_in_required', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertStringContainsString('wpmcp_enable_restrict_content_write', $out['error']['message']);
        $this->assertSame('Gold', $this->row($id)['name']);
    }

    public function test_every_op_takes_manage_options(): void
    {
        $id = $this->level();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        $this->assertSame('operation_denied', $this->read('rcp-list-membership-levels')['error']['code'] ?? null);
        $this->assertSame('operation_denied', $this->read('rcp-list-members')['error']['code'] ?? null);
        $this->assertSame('operation_denied', $this->write('rcp-update-membership-level', [ 'level_id' => $id, 'name' => 'x' ])['error']['code'] ?? null);
        $this->assertSame('Gold', $this->row($id)['name']);
    }

    // ---------------------------------------------------------------
    // Reads
    // ---------------------------------------------------------------

    public function test_list_membership_levels_returns_pricing_and_active_member_counts(): void
    {
        $gold = $this->level();
        $free = $this->level([ 'name' => 'Free', 'price' => '0', 'duration' => 0, 'status' => 'inactive', 'list_order' => 2 ]);
        $this->membership($gold, self::factory()->user->create());
        $this->membership($gold, self::factory()->user->create(), [ 'status' => 'expired' ]);

        $out    = $this->read('rcp-list-membership-levels');
        $levels = array_column($out['levels'], null, 'id');
        $this->assertSame(2, $out['total']);
        $this->assertSame('Gold', $levels[ $gold ]['name']);
        $this->assertSame(29.99, $levels[ $gold ]['price']);
        $this->assertSame('1 month', $levels[ $gold ]['duration']);
        $this->assertSame(1, $levels[ $gold ]['active_members']);
        $this->assertSame(2, $levels[ $gold ]['access_level']);
        $this->assertSame('unlimited', $levels[ $free ]['duration']);
        $this->assertSame(0, $levels[ $free ]['active_members']);

        $only = $this->read('rcp-list-membership-levels', [ 'status' => 'inactive' ]);
        $this->assertSame([ $free ], array_column($only['levels'], 'id'));
    }

    public function test_list_members_masks_names_and_emails_and_leaves_gateway_secrets_out(): void
    {
        $gold = $this->level();
        $user = self::factory()->user->create([ 'display_name' => 'Jane Doe', 'user_email' => 'jane@example.com' ]);
        $this->membership($gold, $user);
        $this->membership($gold, self::factory()->user->create(), [ 'status' => 'cancelled' ]);

        $out = $this->read('rcp-list-members', [ 'status' => 'active' ]);
        $this->assertTrue($out['masked']);
        $this->assertSame(1, $out['total']);
        $row = $out['members'][0];
        $this->assertSame($user, $row['user_id']);
        $this->assertSame('J***', $row['name']);
        $this->assertSame('j***@example.com', $row['email']);
        $this->assertSame('Gold', $row['level_name']);
        $this->assertSame('active', $row['status']);
        $json = (string) wp_json_encode($out);
        foreach ([ 'cus_secret', 'sub_secret', 'abc123', 'private note', '10.0.0.1', 'jane@example.com' ] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }

        $this->assertSame(2, $this->read('rcp-list-members', [ 'level_id' => $gold ])['total']);
        $this->assertSame(0, $this->read('rcp-list-members', [ 'level_id' => $gold + 50 ])['total']);
    }

    // ---------------------------------------------------------------
    // Writes
    // ---------------------------------------------------------------

    public function test_update_membership_level_changes_only_the_args_given_and_rolls_back_exactly(): void
    {
        $id     = $this->level();
        $before = $this->row($id);
        $seen   = [];
        add_action('rcp_edit_subscription_level', static function ($level_id, $args) use (&$seen): void {
            $seen[] = [ $level_id, $args['name'] ];
        }, 10, 2);

        $out = $this->ok($this->write('rcp-update-membership-level', [ 'level_id' => $id, 'name' => 'Platinum', 'price' => 49, 'status' => 'inactive' ]));
        $this->assertTrue($out['recoverable']);
        $this->assertSame('Platinum', $out['result']['level']['name']);
        $this->assertSame(49.0, $out['result']['level']['price']);
        $this->assertSame('inactive', $out['result']['level']['status']);
        $this->assertSame('1 month', $out['result']['level']['duration']);
        $row = $this->row($id);
        $this->assertSame('All access', $row['description']);
        $this->assertSame('subscriber', $row['role']);
        $this->assertSame([ [ $id, 'Platinum' ] ], $seen, 'the plugin\'s own edit action fires');

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame($before, $this->row($id));
    }

    public function test_update_membership_level_refuses_what_the_plugin_refuses(): void
    {
        $id = $this->level();
        $free = $this->level([ 'price' => '0', 'duration' => 0 ]);
        foreach ([
            [ [ 'level_id' => $id + 100, 'name' => 'x' ], 'level_not_found' ],
            [ [ 'level_id' => $id, 'name' => '  ' ], 'invalid_level_name' ],
            [ [ 'level_id' => $free, 'trial_duration' => 7 ], 'invalid_level_trial' ],
        ] as [ $args, $code ]) {
            $out = $this->write('rcp-update-membership-level', $args);
            $this->assertSame($code, $out['error']['code'] ?? null, (string) wp_json_encode($out));
        }
        $out = $this->write('rcp-update-membership-level', [ 'level_id' => $id, 'price' => -1 ]);
        $this->assertSame('invalid_args', $out['error']['code'] ?? null);
        $out = $this->write('rcp-update-membership-level', [ 'level_id' => $id, 'role' => 'administrator' ]);
        $this->assertArrayHasKey('error', $out, 'the level role is not writable');
        $this->assertSame('subscriber', $this->row($id)['role']);
        $this->assertSame('Gold', $this->row($id)['name']);
        $this->assertSame([], Snapshot_Store::list_by_session('default'));
    }
}
