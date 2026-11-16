<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Theme_Integration;
use WPMCP\Integrations\UpdraftPlus_Status;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Snapshot_Store;

/**
 * Backup plugin ops (issue #694): list-updraftplus-backups,
 * run-updraftplus-backup (opt-in, confirm, rate limited, queues
 * UpdraftPlus's own backup-now action) and list-aio-wp-migration-backups.
 * Neither plugin is installed in the test core: presence comes through the
 * adapters' filters and state is seeded the way each plugin stores it.
 */
class BackupPluginOpsTest extends \WP_UnitTestCase
{
    private const FILTERS = [
        'wpmcp_updraftplus_active', 'wpmcp_aio_wp_migration_active', 'wpmcp_aio_wp_migration_backups_dir',
        UpdraftPlus_Status::RUN_FILTER, 'wpmcp_updraftplus_free_bytes', 'wpmcp_updraftplus_backup_running',
    ];

    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        add_filter('wpmcp_updraftplus_active', '__return_true');
        add_filter('wpmcp_aio_wp_migration_active', '__return_true');
        add_filter('wpmcp_updraftplus_free_bytes', static fn () => 10 * 1024 * 1024 * 1024);
        delete_transient('wpmcp_updraftplus_run_lock');
        wp_clear_scheduled_hook(UpdraftPlus_Status::RUN_HOOK);
    }

    protected function tearDown(): void
    {
        foreach (self::FILTERS as $filter) {
            remove_all_filters($filter);
        }
        wp_unschedule_hook(UpdraftPlus_Status::RUN_HOOK);
        delete_transient('wpmcp_updraftplus_run_lock');
        if ('' !== $this->dir && is_dir($this->dir)) {
            array_map('unlink', (array) glob($this->dir . '/*'));
            rmdir($this->dir);
        }
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function call(string $channel, string $op, array $args = [], bool $confirm = false): array
    {
        $call = [ 'operation' => $op, 'args' => $args ];
        if ($confirm) {
            $call['confirm'] = true;
        }
        $theme = new Theme_Integration();
        return 'read' === $channel ? $theme->handle_read($call) : $theme->handle_write($call);
    }

    private function opt_in(): void
    {
        add_filter(UpdraftPlus_Status::RUN_FILTER, '__return_true');
    }

    private function queued(): array
    {
        $out = [];
        foreach ((array) _get_cron_array() as $events) {
            foreach ((array) ($events[ UpdraftPlus_Status::RUN_HOOK ] ?? []) as $event) {
                $out[] = $event['args'];
            }
        }
        return $out;
    }

    public function test_list_updraftplus_backups_newest_first_without_file_names(): void
    {
        $t = 1790500000;
        update_option('updraft_backup_history', [
            $t - 3600 => [ 'db' => 'backup_b-db.gz', 'db-size' => 100, 'nonce' => '9a8b7c6d5e4f', 'service' => [ 'none' ] ],
            $t        => [
                'db'            => 'backup_a-db.gz',
                'db-size'       => 500,
                'plugins'       => [ 'backup_a-plugins.zip', 'backup_a-plugins2.zip' ],
                'plugins0-size' => 1000,
                'plugins1-size' => 24,
                'nonce'         => '4f1a2b3c4d5e',
                'service'       => [ 's3' ],
                'native'        => true,
            ],
        ]);

        $out = $this->call('read', 'list-updraftplus-backups');
        $this->assertArrayNotHasKey('error', $out);
        $list = $out['result'];

        $this->assertSame(2, $list['total']);
        $this->assertSame($t, $list['backups'][0]['id']);
        $this->assertSame(gmdate('Y-m-d\TH:i:s\Z', $t), $list['backups'][0]['date']);
        $this->assertSame([ 'db' => 500, 'plugins' => 1024 ], $list['backups'][0]['components']);
        $this->assertSame(1524, $list['backups'][0]['size']);
        $this->assertSame([ 's3' ], $list['backups'][0]['storage']);
        $this->assertSame([], $list['backups'][1]['storage']);

        $json = (string) wp_json_encode($out);
        foreach ([ 'backup_a', 'backup_b', '4f1a2b3c4d5e', '9a8b7c6d5e4f' ] as $needle) {
            $this->assertStringNotContainsString($needle, $json);
        }

        $this->assertCount(1, $this->call('read', 'list-updraftplus-backups', [ 'limit' => 1 ])['result']['backups']);
    }

    public function test_run_is_refused_without_opt_in(): void
    {
        $out = $this->call('write', 'run-updraftplus-backup', [], true);
        $this->assertSame('opt_in_required', $out['error']['code'] ?? null);
        $this->assertSame([], $this->queued());
    }

    public function test_run_without_confirm_previews_and_queues_nothing(): void
    {
        $this->opt_in();
        update_option('updraft_service', [ 's3' ]);
        update_option('updraft_retain', 3);

        $out = $this->call('write', 'run-updraftplus-backup', [ 'include_remote' => true ]);
        $this->assertSame('confirmation_required', $out['error']['code'] ?? null);
        $this->assertStringContainsString('s3', $out['error']['message']);
        $this->assertStringContainsString('delete older', $out['error']['message']);
        $this->assertSame([ 's3' ], $out['error']['data']['remote_storage']);
        $this->assertSame(3, $out['error']['data']['retain']);
        $this->assertSame([], $this->queued());
    }

    public function test_confirmed_run_queues_one_local_backup_and_rate_limits(): void
    {
        $this->opt_in();

        $out = $this->call('write', 'run-updraftplus-backup', [], true);
        $this->assertArrayNotHasKey('error', $out);
        $this->assertTrue($out['result']['queued']);
        $this->assertFalse($out['recoverable'] ?? true);

        $queued = $this->queued();
        $this->assertCount(1, $queued);
        $this->assertSame(1, $queued[0][0]['nocloud'], 'local only unless include_remote:true');

        wp_unschedule_hook(UpdraftPlus_Status::RUN_HOOK);
        $again = $this->call('write', 'run-updraftplus-backup', [], true);
        $this->assertSame('backup_rate_limited', $again['error']['code'] ?? null);
    }

    public function test_run_refused_while_queued_running_or_low_disk(): void
    {
        $this->opt_in();
        wp_schedule_single_event(time() + 60, UpdraftPlus_Status::RUN_HOOK, [ [ 'nocloud' => 1 ] ]);
        $this->assertSame('backup_running', $this->call('write', 'run-updraftplus-backup', [], true)['error']['code'] ?? null);

        wp_unschedule_hook(UpdraftPlus_Status::RUN_HOOK);
        add_filter('wpmcp_updraftplus_backup_running', '__return_true');
        $this->assertSame('backup_running', $this->call('write', 'run-updraftplus-backup', [], true)['error']['code'] ?? null);

        remove_all_filters('wpmcp_updraftplus_backup_running');
        remove_all_filters('wpmcp_updraftplus_free_bytes');
        add_filter('wpmcp_updraftplus_free_bytes', static fn () => 1024);
        $this->assertSame('backup_low_disk', $this->call('write', 'run-updraftplus-backup', [], true)['error']['code'] ?? null);
        $this->assertSame([], $this->queued());
    }

    public function test_inactive_plugins_are_refused_by_name(): void
    {
        remove_all_filters('wpmcp_updraftplus_active');
        add_filter('wpmcp_updraftplus_active', '__return_false');
        remove_all_filters('wpmcp_aio_wp_migration_active');
        add_filter('wpmcp_aio_wp_migration_active', '__return_false');

        $this->assertSame('updraftplus_inactive', $this->call('read', 'list-updraftplus-backups')['error']['code'] ?? null);
        $this->assertSame('aio_wp_migration_inactive', $this->call('read', 'list-aio-wp-migration-backups')['error']['code'] ?? null);
    }

    public function test_list_aio_wp_migration_backups_without_paths(): void
    {
        $this->dir = get_temp_dir() . 'wpmcp-ai1wm-' . wp_generate_password(6, false);
        mkdir($this->dir);
        file_put_contents($this->dir . '/site-20260901-old.wpress', str_repeat('a', 10));
        file_put_contents($this->dir . '/site-20260915-new.wpress', str_repeat('b', 20));
        file_put_contents($this->dir . '/notes.txt', 'x');
        touch($this->dir . '/site-20260901-old.wpress', 1788000000);
        touch($this->dir . '/site-20260915-new.wpress', 1789000000);
        $dir = $this->dir;
        add_filter('wpmcp_aio_wp_migration_backups_dir', static fn () => $dir);

        $out = $this->call('read', 'list-aio-wp-migration-backups');
        $this->assertArrayNotHasKey('error', $out);
        $this->assertSame(2, $out['result']['total']);
        $this->assertSame('site-20260915-new.wpress', $out['result']['backups'][0]['name']);
        $this->assertSame(20, $out['result']['backups'][0]['size']);
        $this->assertSame(gmdate('Y-m-d\TH:i:s\Z', 1789000000), $out['result']['backups'][0]['date']);
        $this->assertStringNotContainsString($this->dir, (string) wp_json_encode($out));
    }
}
