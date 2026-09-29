<?php

namespace WPMCP\Tests\Free\Packages;

use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Backup\Backup_Job_Store;
use WPMCP\Tools\Backup\Site_Backup_Dir;
use WPMCP\Tools\Packages\Manage_Updates;
use WPMCP\Tools\Rollback_Operation;

/**
 * manage-updates (issue #389): the core update is gated behind confirm, a
 * fresh full-site backup and the optional expected-version guard, and the
 * plugin/theme auto-update toggles are snapshotted and undoable.
 *
 * No test here performs a real core update or any network call: the core
 * and database upgraders are injected spies, and the offered update is a
 * seeded update_core transient.
 */
class ManageUpdatesTest extends \WP_UnitTestCase
{
    /** @var array<int, mixed> */
    private array $core_calls = [];

    private int $db_calls = 0;

    /** @var string[] */
    private array $archives = [];

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        delete_option(Backup_Job_Store::OPTION);
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->core_calls = [];
        $this->db_calls   = 0;
    }

    protected function tearDown(): void
    {
        Backup_Job_Store::set_clock_for_tests(null);
        foreach ($this->archives as $file) {
            if (is_file($file)) {
                wp_delete_file($file);
            }
        }
        delete_site_transient('update_core');
        wp_set_current_user(0);
        parent::tearDown();
    }

    private function tool(): Manage_Updates
    {
        return new Manage_Updates(
            function ($offer) {
                $this->core_calls[] = $offer;
                return $offer->current;
            },
            function () {
                $this->db_calls++;
            }
        );
    }

    private function offer(string $version): void
    {
        set_site_transient('update_core', (object) [
            'updates'         => [
                (object) [
                    'response' => 'upgrade',
                    'current'  => $version,
                    'version'  => $version,
                    'locale'   => get_locale(),
                    'download' => 'https://example.invalid/wordpress-' . $version . '.zip',
                    'packages' => (object) [],
                ],
            ],
            'version_checked' => get_bloginfo('version'),
            'last_checked'    => time(),
        ]);
    }

    /** A completed backup job whose archive exists in the site-backup dir. */
    private function backup(string $type = 'full', int $age = 60, string $scope = 'all'): int
    {
        $dir = Site_Backup_Dir::path();
        Site_Backup_Dir::protect($dir);
        $file = $dir . '/test-' . wp_generate_password(8, false) . '.zip';
        file_put_contents($file, 'zip');
        $this->archives[] = $file;

        Backup_Job_Store::set_clock_for_tests(time() - $age);
        $job = Backup_Job_Store::create($type, 'all');
        Backup_Job_Store::update($job['id'], [
            'status' => 'completed',
            'result' => ['file' => $file, 'scope' => $scope],
        ]);
        Backup_Job_Store::set_clock_for_tests(null);

        return (int) $job['id'];
    }

    public function test_core_update_refuses_without_confirm(): void
    {
        $this->offer('99.0');
        $this->backup();

        try {
            $this->tool()->handle(['type' => 'core']);
            $this->fail('Expected a refusal without confirm.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('confirm', $e->getMessage());
        }
        $this->assertSame([], $this->core_calls);
    }

    public function test_core_update_refuses_without_any_backup(): void
    {
        $this->offer('99.0');

        try {
            $this->tool()->handle(['type' => 'core', 'confirm' => true]);
            $this->fail('Expected a refusal without a backup.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('backup', $e->getMessage());
        }
        $this->assertSame([], $this->core_calls);
    }

    public function test_core_update_refuses_a_stale_backup(): void
    {
        $this->offer('99.0');
        $this->backup('full', 2 * HOUR_IN_SECONDS);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/backup/');
        $this->tool()->handle(['type' => 'core', 'confirm' => true]);
    }

    public function test_core_update_refuses_a_partial_scope_backup(): void
    {
        $this->offer('99.0');
        $this->backup('database', 60, 'database');

        $this->expectException(\RuntimeException::class);
        $this->tool()->handle(['type' => 'core', 'confirm' => true]);
    }

    public function test_core_update_refuses_a_backup_whose_archive_is_gone(): void
    {
        $this->offer('99.0');
        $this->backup();
        foreach ($this->archives as $file) {
            wp_delete_file($file);
        }

        $this->expectException(\RuntimeException::class);
        $this->tool()->handle(['type' => 'core', 'confirm' => true]);
    }

    public function test_backup_freshness_window_is_filterable(): void
    {
        $this->offer('99.0');
        $this->backup('full', 2 * HOUR_IN_SECONDS);
        add_filter('wpmcp_core_update_backup_max_age', static fn() => 3 * HOUR_IN_SECONDS);

        $out = $this->tool()->handle(['type' => 'core', 'confirm' => true]);

        $this->assertTrue($out['updated']);
    }

    public function test_expected_version_guard_refuses_a_different_offer(): void
    {
        $this->offer('99.1');
        $this->backup();

        try {
            $this->tool()->handle(['type' => 'core', 'confirm' => true, 'expected_version' => '99.0']);
            $this->fail('Expected the version guard to refuse.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('99.1', $e->getMessage());
        }
        $this->assertSame([], $this->core_calls);
    }

    public function test_core_update_runs_with_confirm_fresh_backup_and_matching_version(): void
    {
        $this->offer('99.0');
        $job_id = $this->backup();

        $out = $this->tool()->handle(['type' => 'core', 'confirm' => true, 'expected_version' => '99.0']);

        $this->assertCount(1, $this->core_calls);
        $this->assertSame('99.0', $this->core_calls[0]->current);
        $this->assertTrue($out['updated']);
        $this->assertSame('99.0', $out['new_version']);
        $this->assertSame($job_id, $out['backup_job_id']);
        $this->assertFalse($out['rollbackable']);
    }

    public function test_core_update_is_a_no_op_when_up_to_date(): void
    {
        set_site_transient('update_core', (object) ['updates' => []]);
        $this->backup();

        $out = $this->tool()->handle(['type' => 'core', 'confirm' => true]);

        $this->assertFalse($out['updated']);
        $this->assertTrue($out['up_to_date']);
        $this->assertSame([], $this->core_calls);
        $this->assertSame(0, $this->db_calls);
    }

    public function test_pending_database_upgrade_runs_behind_the_same_gates(): void
    {
        set_site_transient('update_core', (object) ['updates' => []]);
        update_option('db_version', 1);

        try {
            $this->tool()->handle(['type' => 'core', 'confirm' => true]);
            $this->fail('Expected a refusal without a backup.');
        } catch (\RuntimeException $e) {
            $this->assertSame(0, $this->db_calls);
        }

        $this->backup();
        $out = $this->tool()->handle(['type' => 'core', 'confirm' => true]);

        $this->assertSame(1, $this->db_calls);
        $this->assertTrue($out['db_upgraded']);
        update_option('db_version', $GLOBALS['wp_db_version']);
    }

    public function test_plugin_auto_update_toggle_is_snapshotted_and_undoable(): void
    {
        update_option('auto_update_plugins', []);

        $out = $this->tool()->handle(['type' => 'plugin', 'item' => 'akismet/akismet.php', 'enabled' => true]);

        $this->assertArrayHasKey('operation_id', $out);
        $this->assertTrue($out['auto_update']);
        $this->assertContains('akismet/akismet.php', (array) get_option('auto_update_plugins'));

        (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);

        $this->assertNotContains('akismet/akismet.php', (array) get_option('auto_update_plugins'));
    }

    public function test_theme_auto_update_toggle_is_snapshotted_and_undoable(): void
    {
        $theme = get_stylesheet();
        update_option('auto_update_themes', [ $theme ]);

        $out = $this->tool()->handle(['type' => 'theme', 'item' => $theme, 'enabled' => false]);

        $this->assertFalse($out['auto_update']);
        $this->assertNotContains($theme, (array) get_option('auto_update_themes'));

        (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);

        $this->assertContains($theme, (array) get_option('auto_update_themes'));
    }

    public function test_auto_update_toggle_refuses_unknown_items_and_missing_enabled(): void
    {
        try {
            $this->tool()->handle(['type' => 'plugin', 'item' => 'ghost/ghost.php', 'enabled' => true]);
            $this->fail('Expected unknown plugin refusal.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('ghost', $e->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->tool()->handle(['type' => 'theme', 'item' => get_stylesheet()]);
    }

    public function test_unknown_type_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->tool()->handle(['type' => 'language']);
    }

    public function test_registered_description_says_core_update_cannot_be_rolled_back(): void
    {
        $abilities = wp_get_abilities();
        $this->assertArrayHasKey('wpmcp/manage-updates', $abilities);
        $this->assertStringContainsString('cannot be snapshot-rolled-back', $abilities['wpmcp/manage-updates']->get_description());
    }
}
