<?php

namespace WPMCP\Tests\Free\MultisiteSecurity;

use WPMCP\Tests\Free\Backup\RestoreArchiveFixtures;
use WPMCP\Tools\Backup\Backup_Job_Store;
use WPMCP\Tools\Backup\Db_Dumper;
use WPMCP\Tools\Backup\Restore_Site_Backup;
use WPMCP\Tools\Migration\Rewrite_Site_Urls;

/**
 * Issue #525: on multisite the backup, restore and migration tools work on
 * the whole network database (Db_Dumper and the restore prefix check use
 * $wpdb->base_prefix), so they belong to whoever runs the network, not to a
 * sub-site administrator. A sub-site admin holds manage_options on its own
 * blog and nothing more; before the fix that was enough to dump every
 * user's password hash and to restore wp_sitemeta with its own login added
 * to site_admins.
 *
 * Runs under WP_MULTISITE=1 and skips on single site, where none of this
 * changes.
 */
class SubSiteAdminBackupReachTest extends \WP_UnitTestCase
{
    use RestoreArchiveFixtures;

    /** Every whole-database tool: the backup group and the migration trio. */
    private const NETWORK_TOOLS = [
        'wpmcp/trigger-backup',
        'wpmcp/get-backup-status',
        'wpmcp/list-backup-jobs',
        'wpmcp/cancel-backup-job',
        'wpmcp/get-backup-manifest',
        'wpmcp/delete-backup-archive',
        'wpmcp/restore-site-backup',
        'wpmcp/rewrite-site-urls',
        'wpmcp/push-site-archive',
        'wpmcp/receive-site-archive',
    ];

    private int $blog;
    private int $sub_admin;
    private int $super_admin;

    protected function setUp(): void
    {
        parent::setUp();
        if (! is_multisite()) {
            $this->markTestSkipped('Multisite only: run with WP_MULTISITE=1.');
        }
        $this->setExpectedDeprecated('wpmu_new_blog');

        $this->blog        = (int) self::factory()->blog->create();
        $this->super_admin = (int) self::factory()->user->create(['role' => 'administrator']);
        grant_super_admin($this->super_admin);
        $this->sub_admin = (int) self::factory()->user->create([
            'role'       => 'subscriber',
            'user_login' => 'subadmin' . wp_generate_password(6, false),
        ]);
        add_user_to_blog($this->blog, $this->sub_admin, 'administrator');

        switch_to_blog($this->blog);
        wp_set_current_user($this->sub_admin);
        $this->assertFalse(is_super_admin($this->sub_admin));
        $this->assertTrue(current_user_can('manage_options'), 'Fixture: the sub-site admin holds manage_options on its blog');
    }

    protected function tearDown(): void
    {
        $this->clean_restore_fixtures();
        remove_all_filters('wpmcp_accept_incoming_migrations');
        remove_all_filters('wpmcp_allow_outgoing_migrations');
        if (is_multisite()) {
            restore_current_blog();
            revoke_super_admin($this->super_admin);
        }
        parent::tearDown();
    }

    public function test_whole_database_tools_deny_a_sub_site_admin(): void
    {
        $abilities = wp_get_abilities();
        $allowed   = [];
        foreach (self::NETWORK_TOOLS as $name) {
            $this->assertArrayHasKey($name, $abilities);
            if (true === $abilities[ $name ]->check_permissions([])) {
                $allowed[] = $name;
            }
        }

        $this->assertSame([], $allowed, 'Whole-database tools must not be open to a sub-site admin');
    }

    public function test_a_super_admin_keeps_every_whole_database_tool(): void
    {
        add_filter('wpmcp_accept_incoming_migrations', '__return_true');
        add_filter('wpmcp_allow_outgoing_migrations', '__return_true');
        wp_set_current_user($this->super_admin);

        $abilities = wp_get_abilities();
        $denied    = [];
        foreach (self::NETWORK_TOOLS as $name) {
            if (true !== $abilities[ $name ]->check_permissions([])) {
                $denied[] = $name;
            }
        }

        $this->assertSame([], $denied, 'A super admin must keep network backup, restore and migration');
    }

    public function test_a_dump_started_by_a_sub_site_admin_holds_no_network_data(): void
    {
        global $wpdb;
        $hash = (string) $wpdb->get_var($wpdb->prepare("SELECT user_pass FROM {$wpdb->users} WHERE ID = %d", $this->super_admin));
        $this->assertNotSame('', $hash);

        $sql     = '';
        $refused = null;
        try {
            (new Db_Dumper())->dump(static function (string $chunk) use (&$sql): void {
                $sql .= $chunk;
            });
        } catch (\RuntimeException $e) {
            $refused = $e->getMessage();
        }

        // assertFalse(str_contains()) rather than assertStringNotContains:
        // a failure must not print the network dump into the test log.
        $this->assertFalse(str_contains($sql, $hash), 'A super admin password hash ended up in a sub-site admin dump');
        foreach ([$wpdb->users, $wpdb->usermeta, $wpdb->sitemeta, $wpdb->blogs, $wpdb->base_prefix . 'options'] as $table) {
            $this->assertFalse(str_contains($sql, "`{$table}`"), "{$table} ended up in a sub-site admin dump");
        }
        $this->assertNotNull($refused, 'The dump must be refused outright, not silently emptied');
    }

    public function test_a_super_admin_dump_from_a_sub_site_still_covers_the_network(): void
    {
        global $wpdb;
        wp_set_current_user($this->super_admin);

        $sql    = '';
        $result = (new Db_Dumper())->dump(static function (string $chunk) use (&$sql): void {
            $sql .= $chunk;
        }, [$wpdb->sitemeta, $wpdb->users]);

        $this->assertArrayHasKey($wpdb->sitemeta, $result['tables']);
        $this->assertArrayHasKey($wpdb->users, $result['tables']);
        $this->assertTrue(str_contains($sql, 'site_admins'), 'A super admin dump keeps the network tables');
    }

    public function test_a_sub_site_admin_cannot_restore_network_tables_into_super_admin(): void
    {
        global $wpdb;
        $login = get_userdata($this->sub_admin)->user_login;

        // The attacker's archive: a genuine sitemeta dump (taken by a super
        // admin, as a sub-site admin can no longer take one) with the
        // sub-site admin's login added to site_admins.
        wp_set_current_user($this->super_admin);
        [$sql, $result] = $this->dump_tables([$wpdb->sitemeta]);
        $old = addslashes(serialize(get_super_admins()));
        $new = addslashes(serialize(array_merge(get_super_admins(), [$login])));
        $this->assertStringContainsString($old, $sql);
        $archive = $this->archive_from_sql(str_replace($old, $new, $sql), $result['tables'], ['database.bytes' => strlen(str_replace($old, $new, $sql))]);
        $jobs_before = count(Backup_Job_Store::list());

        wp_set_current_user($this->sub_admin);
        $refused = null;
        try {
            (new Restore_Site_Backup($this->safety_producer([$wpdb->sitemeta])))->handle(['path' => $archive, 'dry_run' => false, 'confirm' => true]);
        } catch (\RuntimeException $e) {
            $refused = $e->getMessage();
        }

        wp_cache_flush();
        $this->assertFalse(is_super_admin($this->sub_admin), 'A sub-site admin restored network sitemeta and became a super admin');
        $this->assertNotNull($refused, 'The restore must be refused for a sub-site admin');
        $this->assertCount($jobs_before, Backup_Job_Store::list(), 'A refused restore must not take a safety archive first');
    }

    public function test_a_sub_site_admin_cannot_take_a_url_rewrite_safety_archive(): void
    {
        $produced = false;
        $producer = static function (array $job) use (&$produced): array {
            $produced = true;
            return ['file' => '', 'size' => 0, 'scope' => 'database'];
        };
        $home = home_url();

        $refused = null;
        try {
            $out = (new Rewrite_Site_Urls($producer))->handle([
                'from_url' => $home,
                'to_url'   => 'https://elsewhere.example',
                'dry_run'  => false,
                'confirm'  => true,
            ]);
            if (is_wp_error($out)) {
                $refused = $out->get_error_message();
            }
        } catch (\RuntimeException $e) {
            $refused = $e->getMessage();
        }

        $this->assertNotNull($refused, 'A sub-site admin must not run the rewrite and its whole-network safety archive');
        $this->assertFalse($produced, 'No network safety archive may be produced for a sub-site admin');
        $this->assertSame($home, home_url());
    }
}
