<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\BackWPup_Status;
use WPMCP\Integrations\Theme_Integration;
use WPMCP\Safety\Snapshot_Store;

/**
 * BackWPup ops (issue #694): list-backwpup-backups (jobs and their archives
 * from each destination's own file list, no paths or links) and
 * run-backwpup-backup (opt-in, confirm, rate limited, starts the job through
 * BackWPup's own run-now request). BackWPup is not installed in the test
 * core: presence and the start request come through the adapter's filters
 * and state is seeded the way BackWPup 5 stores it (the backwpup_jobs site
 * option, backwpup_<jobid>_<dest> site transients for remote destinations,
 * archives in the job's backupdir for the folder destination).
 */
class BackWPupOpsTest extends \WP_UnitTestCase
{
    private const FILTERS = [
        'wpmcp_backwpup_active', BackWPup_Status::RUN_FILTER, 'wpmcp_backwpup_free_bytes',
        'wpmcp_backwpup_backup_running', 'wpmcp_backwpup_start_job', 'wpmcp_backwpup_backup_dir',
    ];

    private string $dir = '';

    /** @var array<int,int> */
    private array $started = [];

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        if (is_multisite()) {
            grant_super_admin(get_current_user_id());
        }
        add_filter('wpmcp_backwpup_active', '__return_true');
        add_filter('wpmcp_backwpup_free_bytes', static fn () => 10 * 1024 * 1024 * 1024);
        $this->started = [];
        add_filter('wpmcp_backwpup_start_job', function ($result, int $job_id) {
            $this->started[] = $job_id;
            return true;
        }, 10, 2);
        delete_site_transient('wpmcp_backwpup_run_lock');

        $this->dir = get_temp_dir() . 'wpmcp-backwpup-' . wp_generate_password(6, false);
        mkdir($this->dir);
        $dir = $this->dir;
        add_filter('wpmcp_backwpup_backup_dir', static fn () => $dir);

        update_site_option('backwpup_jobs', [
            1 => [
                'jobid'        => 1,
                'name'         => 'Nightly files',
                'type'         => [ 'FILE', 'DBDUMP' ],
                'destinations' => [ 'FOLDER', 'S3' ],
                'activetype'   => 'wpcron',
                'cron'         => '0 3 * * *',
                'lastrun'      => 1790000000,
                'lastruntime'  => 42,
                'logfile'      => '/var/www/wp-content/uploads/backwpup/logs/backwpup_log_x.html',
                'backupdir'    => 'uploads/backwpup-abc123-backups/',
                'maxbackups'   => 3,
                's3maxbackups' => 5,
                's3secretkey'  => 'S3SECRETVALUE',
                'lastbackupdownloadurl' => 'https://example.org/wp-admin/admin.php?page=backwpupbackups&file=x',
            ],
            2 => [
                'jobid'        => 2,
                'name'         => 'Manual db',
                'type'         => [ 'DBDUMP' ],
                'destinations' => [ 'DROPBOX' ],
                'activetype'   => '',
                'cron'         => '0 0 1 * *',
                'dropboxtoken' => 'DROPBOXTOKENVALUE',
            ],
            3 => [
                'jobid'        => 3,
                'name'         => 'Temp backup now',
                'type'         => [ 'DBDUMP' ],
                'destinations' => [ 'FOLDER' ],
                'activetype'   => '',
                'tempjob'      => true,
            ],
        ]);
    }

    protected function tearDown(): void
    {
        foreach (self::FILTERS as $filter) {
            remove_all_filters($filter);
        }
        delete_site_option('backwpup_jobs');
        delete_site_transient('backwpup_1_s3');
        delete_site_transient('backwpup_2_dropbox');
        delete_site_transient('wpmcp_backwpup_run_lock');
        if ('' !== $this->dir && is_dir($this->dir)) {
            array_map('unlink', (array) glob($this->dir . '/{,.}[!.,!..]*', GLOB_BRACE));
            rmdir($this->dir);
        }
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
        add_filter(BackWPup_Status::RUN_FILTER, '__return_true');
    }

    private function seed_archives(): void
    {
        file_put_contents($this->dir . '/2026-09-01_03-00-00_AAAAAA01.zip', str_repeat('a', 10));
        file_put_contents($this->dir . '/2026-09-15_03-00-00_AAAAAA02.tar.gz', str_repeat('b', 20));
        file_put_contents($this->dir . '/index.php', '<?php');
        file_put_contents($this->dir . '/notes.txt', 'x');
        touch($this->dir . '/2026-09-01_03-00-00_AAAAAA01.zip', 1788000000);
        touch($this->dir . '/2026-09-15_03-00-00_AAAAAA02.tar.gz', 1789000000);

        set_site_transient('backwpup_1_s3', [
            [
                'folder'      => 's3://bucket/site/',
                'file'        => 'site/2026-09-20_03-00-00_AAAAAA03.zip',
                'filename'    => '2026-09-20_03-00-00_AAAAAA03.zip',
                'downloadurl' => 'https://example.org/wp-admin/admin.php?page=backwpupbackups&action=downloads3&file=site%2Fx',
                'filesize'    => 300,
                'time'        => 1789500000,
            ],
        ], YEAR_IN_SECONDS);
        set_site_transient('backwpup_2_dropbox', [
            [
                'file'     => '/Apps/BackWPup/2026-08-01_00-00-00_AAAAAA00.tar',
                'filesize' => 5,
                'time'     => 1787000000,
            ],
        ], YEAR_IN_SECONDS);
    }

    public function test_list_reports_jobs_and_archives_newest_first_without_paths_links_or_secrets(): void
    {
        $this->seed_archives();

        $out = $this->call('read', 'list-backwpup-backups');
        $this->assertArrayNotHasKey('error', $out);
        $list = $out['result'];

        $this->assertSame([ 1, 2 ], array_column($list['jobs'], 'id'), 'temporary backup-now jobs are not listed');
        $job = $list['jobs'][0];
        $this->assertSame('Nightly files', $job['name']);
        $this->assertTrue($job['enabled']);
        $this->assertSame([ 'FILE', 'DBDUMP' ], $job['types']);
        $this->assertSame([ 'FOLDER', 'S3' ], $job['destinations']);
        $this->assertSame('0 3 * * *', $job['cron']);
        $this->assertSame(gmdate('Y-m-d\TH:i:s\Z', 1790000000), $job['last_run']);
        $this->assertFalse($list['jobs'][1]['enabled']);

        $this->assertSame(4, $list['total']);
        $this->assertSame(
            [ '2026-09-20_03-00-00_AAAAAA03.zip', '2026-09-15_03-00-00_AAAAAA02.tar.gz', '2026-09-01_03-00-00_AAAAAA01.zip', '2026-08-01_00-00-00_AAAAAA00.tar' ],
            array_column($list['backups'], 'name')
        );
        $first = $list['backups'][0];
        $this->assertSame(1, $first['job_id']);
        $this->assertSame('S3', $first['destination']);
        $this->assertSame(300, $first['size']);
        $this->assertSame(gmdate('Y-m-d\TH:i:s\Z', 1789500000), $first['date']);
        $this->assertSame('FOLDER', $list['backups'][1]['destination']);
        $this->assertSame(20, $list['backups'][1]['size']);
        $this->assertSame(2, $list['backups'][3]['job_id']);

        $json = (string) wp_json_encode($out);
        foreach ([ $this->dir, 's3://', 'site/2026', 'downloads3', 'Apps/BackWPup', 'backwpup_log', 'S3SECRETVALUE', 'DROPBOXTOKENVALUE', 'backwpupbackups', 'backwpup-abc123' ] as $needle) {
            $this->assertStringNotContainsString($needle, $json);
        }

        $this->assertCount(1, $this->call('read', 'list-backwpup-backups', [ 'limit' => 1 ])['result']['backups']);
        $only = $this->call('read', 'list-backwpup-backups', [ 'job_id' => 2 ])['result'];
        $this->assertSame([ '2026-08-01_00-00-00_AAAAAA00.tar' ], array_column($only['backups'], 'name'));
    }

    public function test_run_is_refused_without_opt_in(): void
    {
        $out = $this->call('write', 'run-backwpup-backup', [ 'job_id' => 1 ], true);
        $this->assertSame('opt_in_required', $out['error']['code'] ?? null);
        $this->assertSame([], $this->started);
    }

    public function test_run_without_confirm_previews_destinations_and_retention(): void
    {
        $this->opt_in();

        $out = $this->call('write', 'run-backwpup-backup', [ 'job_id' => 1 ]);
        $this->assertSame('confirmation_required', $out['error']['code'] ?? null);
        $this->assertStringContainsString('Nightly files', $out['error']['message']);
        $this->assertStringContainsString('S3', $out['error']['message']);
        $this->assertStringContainsString('delete older', $out['error']['message']);
        $this->assertSame([ 'S3' ], $out['error']['data']['remote_storage']);
        $this->assertSame([ 'FOLDER' => 3, 'S3' => 5 ], $out['error']['data']['retain']);
        $this->assertFalse($out['error']['data']['recoverable']);
        $this->assertSame([], $this->started);
    }

    public function test_confirmed_run_starts_the_job_once_and_rate_limits(): void
    {
        $this->opt_in();

        $out = $this->call('write', 'run-backwpup-backup', [ 'job_id' => 1 ], true);
        $this->assertArrayNotHasKey('error', $out);
        $this->assertTrue($out['result']['started']);
        $this->assertSame(1, $out['result']['job_id']);
        $this->assertFalse($out['recoverable'] ?? true);
        $this->assertSame([ 1 ], $this->started);

        $again = $this->call('write', 'run-backwpup-backup', [ 'job_id' => 2 ], true);
        $this->assertSame('backup_rate_limited', $again['error']['code'] ?? null);
        $this->assertSame([ 1 ], $this->started);
    }

    public function test_failed_start_is_reported_and_does_not_take_the_rate_slot(): void
    {
        $this->opt_in();
        remove_all_filters('wpmcp_backwpup_start_job');
        add_filter('wpmcp_backwpup_start_job', static fn () => new \WP_Error('http_request_failed', 'loopback refused'));

        $out = $this->call('write', 'run-backwpup-backup', [ 'job_id' => 1 ], true);
        $this->assertSame('backup_start_failed', $out['error']['code'] ?? null);
        $this->assertFalse(get_site_transient('wpmcp_backwpup_run_lock'));
    }

    public function test_run_refused_for_unknown_or_temporary_job_while_running_or_low_disk(): void
    {
        $this->opt_in();

        $this->assertSame('invalid_args', $this->call('write', 'run-backwpup-backup', [ 'job_id' => 99 ], true)['error']['code'] ?? null);
        $this->assertSame('invalid_args', $this->call('write', 'run-backwpup-backup', [ 'job_id' => 3 ], true)['error']['code'] ?? null);

        add_filter('wpmcp_backwpup_backup_running', '__return_true');
        $this->assertSame('backup_running', $this->call('write', 'run-backwpup-backup', [ 'job_id' => 1 ], true)['error']['code'] ?? null);

        remove_all_filters('wpmcp_backwpup_backup_running');
        remove_all_filters('wpmcp_backwpup_free_bytes');
        add_filter('wpmcp_backwpup_free_bytes', static fn () => 1024);
        $this->assertSame('backup_low_disk', $this->call('write', 'run-backwpup-backup', [ 'job_id' => 1 ], true)['error']['code'] ?? null);
        $this->assertSame([], $this->started);
    }

    public function test_inactive_plugin_is_refused_by_name(): void
    {
        remove_all_filters('wpmcp_backwpup_active');
        add_filter('wpmcp_backwpup_active', '__return_false');

        $this->assertSame('backwpup_inactive', $this->call('read', 'list-backwpup-backups')['error']['code'] ?? null);
        $this->opt_in();
        $this->assertSame('backwpup_inactive', $this->call('write', 'run-backwpup-backup', [ 'job_id' => 1 ], true)['error']['code'] ?? null);
    }

    public function test_sub_site_admin_cannot_reach_network_wide_backups(): void
    {
        if (! is_multisite()) {
            $this->markTestSkipped('multisite only');
        }
        revoke_super_admin(get_current_user_id());
        $this->opt_in();

        $this->assertSame('operation_denied', $this->call('read', 'list-backwpup-backups')['error']['code'] ?? null);
        $this->assertSame('operation_denied', $this->call('write', 'run-backwpup-backup', [ 'job_id' => 1 ], true)['error']['code'] ?? null);
        $this->assertSame([], $this->started);
    }
}
