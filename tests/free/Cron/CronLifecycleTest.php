<?php

namespace WPMCP\Tests\Free\Cron;

use WPMCP\Activator;
use WPMCP\Auth\Oauth_Gc;
use WPMCP\Cron_Registry;
use WPMCP\Deactivator;
use WPMCP\Tools\Backup\Backup_Job_Store;
use WPMCP\Tools\Backup\Backup_Schedule;
use WPMCP\Tools\Backup\Run_Backup_Job;
use WPMCP\Tools\Cli\Cli_Job_Store;
use WPMCP\Tools\Cli\Run_Cli_Job;
use WPMCP\Tools\Media\Optimize_Media_Job;
use WPMCP\Tools\Redirects\Broken_Link_Scan_Store;
use WPMCP\Tools\Redirects\Run_Broken_Link_Scan;
use WPMCP\Uninstaller;

/**
 * The plugin's cron events across deactivation, reactivation and uninstall
 * (issue #468).
 *
 * Deactivation clears every event on a registered hook. The recurring ones
 * (the OAuth sweep, backup schedules) come back on activation only when
 * their settings still ask for them, at the configured time. A background
 * job still waiting on its next event when the plugin goes away is marked
 * canceled rather than left "queued" or "running" with nothing to move it.
 */
class CronLifecycleTest extends \WP_UnitTestCase
{
    /** Tue 14 Nov 2023 22:13:20 UTC. */
    private const NOW = 1700000000;

    private const OPTIONS = [
        Backup_Job_Store::OPTION,
        Backup_Schedule::OPTION,
        Cli_Job_Store::OPTION,
        Broken_Link_Scan_Store::OPTION,
        Optimize_Media_Job::OPTION,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::OPTIONS as $option) {
            delete_option($option);
        }
        update_option('timezone_string', 'UTC');
        update_option('gmt_offset', 0);
        Backup_Job_Store::set_clock_for_tests(self::NOW);
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->clear_plugin_events();
    }

    protected function tearDown(): void
    {
        $this->clear_plugin_events();
        wp_clear_scheduled_hook('wpmcp_test_site_hook');
        Backup_Job_Store::set_clock_for_tests(null);
        foreach (self::OPTIONS as $option) {
            delete_option($option);
        }
        parent::tearDown();
    }

    private function clear_plugin_events(): void
    {
        foreach ($this->plugin_events() as [$hook]) {
            wp_unschedule_hook($hook);
        }
    }

    /** Every scheduled event whose hook starts with wpmcp_, as [hook, args]. */
    private function plugin_events(): array
    {
        $events = [];
        foreach ((array) _get_cron_array() as $hooks) {
            foreach ((array) $hooks as $hook => $instances) {
                if (0 !== strpos((string) $hook, 'wpmcp_') || 'wpmcp_test_site_hook' === $hook) {
                    continue;
                }
                foreach ((array) $instances as $event) {
                    $events[] = [$hook, $event['args']];
                }
            }
        }
        return $events;
    }

    /** Schedule one event on every registered hook, the way the plugin does. */
    private function schedule_everything(): array
    {
        add_filter('wpmcp_oauth_enabled', '__return_true');
        Oauth_Gc::ensure_scheduled();
        $this->assertIsArray(Backup_Schedule::set('database', 'all', 'daily 04:30', 5));

        $backup = Backup_Job_Store::create('database', 'all');
        wp_schedule_single_event(time(), Run_Backup_Job::HOOK, [$backup['id']]);

        $cli = Cli_Job_Store::create(['option', 'get', 'home'], 60);
        wp_schedule_single_event(time(), Run_Cli_Job::HOOK, [$cli['id']]);

        $scan = Broken_Link_Scan_Store::create(['post'], 100, 10, 50);
        Broken_Link_Scan_Store::update($scan['id'], ['status' => 'running', 'offset' => 10]);
        wp_schedule_single_event(time(), Run_Broken_Link_Scan::HOOK, [$scan['id']]);

        $optimize = Optimize_Media_Job::create(['background' => true], get_current_user_id(), 20);

        return ['backup' => $backup['id'], 'cli' => $cli['id'], 'scan' => $scan['id'], 'optimize' => $optimize['id']];
    }

    public function test_deactivation_leaves_no_plugin_events(): void
    {
        $this->schedule_everything();
        $scheduled = array_unique(array_column($this->plugin_events(), 0));
        sort($scheduled);
        $expected = Cron_Registry::hooks();
        sort($expected);
        $this->assertSame($expected, $scheduled, 'The fixture should put an event on every registered hook.');

        Deactivator::deactivate(false);

        $this->assertSame([], $this->plugin_events());
    }

    public function test_deactivation_leaves_other_events_alone(): void
    {
        wp_schedule_event(time() + 60, 'hourly', 'wpmcp_test_site_hook');
        wp_schedule_single_event(time() + 60, 'some_other_plugin_hook');

        Deactivator::deactivate(false);

        $this->assertIsInt(wp_next_scheduled('wpmcp_test_site_hook'));
        $this->assertIsInt(wp_next_scheduled('some_other_plugin_hook'));
        wp_clear_scheduled_hook('some_other_plugin_hook');
    }

    public function test_in_flight_jobs_are_marked_canceled_on_deactivation(): void
    {
        $ids = $this->schedule_everything();

        Deactivator::deactivate(false);

        $jobs = [
            'backup'   => Backup_Job_Store::get($ids['backup']),
            'cli'      => Cli_Job_Store::get($ids['cli']),
            'scan'     => Broken_Link_Scan_Store::get($ids['scan']),
            'optimize' => Optimize_Media_Job::get($ids['optimize']),
        ];
        foreach ($jobs as $kind => $job) {
            $this->assertSame('canceled', $job['status'], "$kind job");
            $this->assertStringContainsString('deactivated', (string) $job['error'], "$kind job");
        }
        $this->assertSame(10, Broken_Link_Scan_Store::get($ids['scan'])['offset'], 'Progress so far is kept.');
    }

    public function test_a_finished_job_with_a_stray_event_keeps_its_status(): void
    {
        $job = Backup_Job_Store::create('database', 'all');
        Backup_Job_Store::update($job['id'], ['status' => 'completed']);
        wp_schedule_single_event(time(), Run_Backup_Job::HOOK, [$job['id']]);

        Deactivator::deactivate(false);

        $this->assertSame('completed', Backup_Job_Store::get($job['id'])['status']);
        $this->assertNull(Backup_Job_Store::get($job['id'])['error']);
        $this->assertFalse(wp_next_scheduled(Run_Backup_Job::HOOK, [$job['id']]));
    }

    public function test_reactivation_restores_the_configured_schedules_only(): void
    {
        $this->schedule_everything();
        $configured = wp_get_scheduled_event(Backup_Schedule::HOOK, ['database']);
        $this->assertNotFalse($configured);

        Deactivator::deactivate(false);
        Activator::activate();

        $restored = wp_get_scheduled_event(Backup_Schedule::HOOK, ['database']);
        $this->assertNotFalse($restored, 'The backup schedule that is still on comes back.');
        $this->assertSame($configured->timestamp, $restored->timestamp, 'At the configured time of day.');
        $this->assertSame('daily', $restored->schedule);
        $this->assertIsInt(wp_next_scheduled(Oauth_Gc::HOOK), 'The OAuth sweep comes back while OAuth is on.');

        $hooks = array_unique(array_column($this->plugin_events(), 0));
        sort($hooks);
        $this->assertSame([Oauth_Gc::HOOK, Backup_Schedule::HOOK], $hooks, 'No canceled job is put back on the schedule.');
    }

    public function test_reactivation_does_not_restore_what_settings_no_longer_ask_for(): void
    {
        $this->schedule_everything();
        Deactivator::deactivate(false);

        remove_filter('wpmcp_oauth_enabled', '__return_true');
        $this->assertIsArray(Backup_Schedule::set('database', 'all', 'off', null));
        Activator::activate();

        $this->assertSame([], $this->plugin_events());
    }

    public function test_reactivation_does_not_duplicate_a_schedule_that_survived(): void
    {
        $this->assertIsArray(Backup_Schedule::set('full', 'all', 'weekly mon 02:00', 3));
        $before = wp_get_scheduled_event(Backup_Schedule::HOOK, ['full']);

        Activator::activate();

        $count = 0;
        foreach ($this->plugin_events() as [$hook]) {
            $count += Backup_Schedule::HOOK === $hook ? 1 : 0;
        }
        $this->assertSame(1, $count);
        $this->assertSame($before->timestamp, wp_get_scheduled_event(Backup_Schedule::HOOK, ['full'])->timestamp);
    }

    public function test_uninstall_leaves_no_plugin_events(): void
    {
        $this->schedule_everything();

        Uninstaller::uninstall();

        $this->assertSame([], $this->plugin_events());
    }

    public function test_boot_wires_the_deactivation_hook(): void
    {
        $this->assertNotFalse(
            has_action('deactivate_' . plugin_basename(WPMCP_FILE), [Deactivator::class, 'deactivate']),
            'Plugin::boot() runs in every build (full, wp.org, WooCommerce), so wiring it there covers all three.'
        );
    }

    public function test_uninstall_php_guards_and_clears(): void
    {
        $root = dirname(__DIR__, 3);
        $file = (string) file_get_contents($root . '/uninstall.php');

        $this->assertMatchesRegularExpression("/defined\\(\\s*'WP_UNINSTALL_PLUGIN'\\s*\\)/", $file);
        $this->assertStringContainsString('Uninstaller::uninstall()', $file);
    }

    /**
     * The directory builds have no licensing SDK, so they need uninstall.php.
     * The self-hosted build must not ship it: WordPress runs uninstall.php
     * INSTEAD of a registered uninstall hook, which would silently skip the
     * licensing SDK's uninstall event; that build clears its events through
     * the SDK's after_uninstall action (Freemius\Bootstrap) instead.
     */
    public function test_each_build_has_exactly_one_uninstall_path(): void
    {
        $root = dirname(__DIR__, 3);

        foreach (['build-wporg-release.sh', 'build-woo-release.sh'] as $script) {
            $this->assertStringContainsString('"$ROOT/uninstall.php"', (string) file_get_contents("$root/scripts/$script"), $script);
        }
        $this->assertDoesNotMatchRegularExpression('/^\s*cp\b[^\n]*uninstall\.php/m', (string) file_get_contents("$root/scripts/build-release.sh"));
        $this->assertStringContainsString('Uninstaller::class', (string) file_get_contents("$root/src/Freemius/Bootstrap.php"));
        $this->assertTrue(method_exists(Uninstaller::class, 'uninstall'));
    }
}
