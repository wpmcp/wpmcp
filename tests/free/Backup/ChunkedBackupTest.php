<?php

namespace WPMCP\Tests\Free\Backup;

use WPMCP\Cron_Registry;
use WPMCP\Tools\Backup\Backup_Job_Store;
use WPMCP\Tools\Backup\Cancel_Backup_Job;
use WPMCP\Tools\Backup\Chunked_Archive_Build;
use WPMCP\Tools\Backup\Db_Dumper;
use WPMCP\Tools\Backup\Get_Backup_Status;
use WPMCP\Tools\Backup\Restore_Site_Backup;
use WPMCP\Tools\Backup\Run_Backup_Job;
use WPMCP\Tools\Backup\Site_Backup_Dir;

/**
 * Issue #783: a full backup of a large site could not finish inside the
 * usual PHP time limit, because the whole archive was built in one cron
 * request. The chunked build does the dump, the file listing and the
 * archive in steps, each one ended by a time budget, saved on the job and
 * continued by the next cron event.
 *
 * Every test forces the budget low (seconds 0: one unit of work per
 * request) so each delivery of the cron event is one "request", and the
 * test plays WP-Cron by delivering the event until the job is final.
 * Uploads are pointed at a private fixture directory so the file phase
 * walks a known tree, not whatever the shared test install holds.
 */
class ChunkedBackupTest extends \WP_UnitTestCase
{
    private string $root = '';

    /** @var callable|null */
    private $uploads = null;

    private array $limits = [];

    protected function setUp(): void
    {
        parent::setUp();
        delete_option(Backup_Job_Store::OPTION);
        Backup_Job_Store::set_clock_for_tests(null);

        $this->root = rtrim(get_temp_dir(), '/') . '/wpmcp-chunk-' . wp_generate_password(8, false);
        wp_mkdir_p($this->root . '/2026/11');
        wp_mkdir_p($this->root . '/nested/deeper');
        wp_mkdir_p($this->root . '/cache');
        file_put_contents($this->root . '/2026/11/photo.jpg', str_repeat('j', 3000));
        file_put_contents($this->root . '/2026/11/doc.pdf', str_repeat('p', 2000));
        file_put_contents($this->root . '/nested/a.txt', 'alpha');
        file_put_contents($this->root . '/nested/deeper/b.txt', 'bravo');
        file_put_contents($this->root . '/nested/deeper/c.txt', "line one\nline two");
        file_put_contents($this->root . '/top.txt', 'top');
        file_put_contents($this->root . '/debug.log', 'excluded inside uploads too');
        file_put_contents($this->root . '/cache/skip.txt', 'excluded directory');

        $root          = $this->root;
        $this->uploads = static function (array $dirs) use ($root): array {
            $dirs['basedir'] = $root;
            $dirs['path']    = $root;
            return $dirs;
        };
        add_filter('upload_dir', $this->uploads);

        $this->limits = ['seconds' => 0, 'hard_seconds' => null, 'batch_files' => 2, 'batch_bytes' => 64 * MB_IN_BYTES];
        add_filter('wpmcp_backup_chunked', '__return_true');
        add_filter('wpmcp_backup_step_limits', [$this, 'limits']);
    }

    protected function tearDown(): void
    {
        remove_filter('upload_dir', $this->uploads);
        remove_filter('wpmcp_backup_chunked', '__return_true');
        remove_filter('wpmcp_backup_step_limits', [$this, 'limits']);
        $this->remove_tree($this->root);
        wp_clear_scheduled_hook(Run_Backup_Job::HOOK);
        Backup_Job_Store::set_clock_for_tests(null);
        delete_option(Backup_Job_Store::OPTION);
        parent::tearDown();
    }

    public function limits(array $limits): array
    {
        return array_merge($limits, $this->limits);
    }

    private function remove_tree(string $dir): void
    {
        if ('' === $dir || ! is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    private function queue(string $type): int
    {
        $job = Backup_Job_Store::create($type, 'all');
        wp_schedule_single_event(time(), Run_Backup_Job::HOOK, [$job['id']]);
        return (int) $job['id'];
    }

    /**
     * Deliver the job's cron event until it is final, as WP-Cron does: the
     * event is removed before its callback runs. Returns how many requests
     * it took and every record get-backup-status showed between them.
     *
     * @return array{0:int,1:array<int,array>}
     */
    private function drive(int $id, int $max = 400): array
    {
        $runs    = 0;
        $records = [];
        while ($runs < $max) {
            wp_clear_scheduled_hook(Run_Backup_Job::HOOK, [$id]);
            (new Run_Backup_Job())->handle_scheduled($id);
            $runs++;
            $records[] = (new Get_Backup_Status())->handle(['job_id' => $id]);
            $job = Backup_Job_Store::get($id);
            if (! in_array($job['status'], ['queued', 'running'], true)) {
                break;
            }
            $this->assertNotFalse(
                wp_next_scheduled(Run_Backup_Job::HOOK, [$id]),
                'An unfinished step must leave the next step scheduled.'
            );
        }
        return [$runs, $records];
    }

    private function leftovers(): array
    {
        $dir = Site_Backup_Dir::path();
        return array_values(array_filter(
            array_map('basename', glob($dir . '/*') ?: []),
            static fn(string $name): bool => ! in_array($name, ['index.php', 'README.txt'], true) && ! str_ends_with($name, '.zip')
        ));
    }

    public function test_an_uploads_backup_is_built_over_many_requests_into_the_usual_archive(): void
    {
        $id = $this->queue('uploads');

        [$runs, $records] = $this->drive($id);

        $job = Backup_Job_Store::get($id);
        $this->assertSame('completed', $job['status'], (string) $job['error']);
        $this->assertGreaterThan(4, $runs, 'Seven files two at a time cannot be one request.');
        $this->assertArrayNotHasKey('build', $job, 'The step cursor goes when the job is final.');

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($job['result']['file']));
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        $nested   = (string) $zip->getFromName('wp-content/' . basename($this->root) . '/nested/deeper/c.txt');
        $zip->close();
        sort($names);

        $prefix = 'wp-content/' . basename($this->root) . '/';
        $this->assertSame([
            'manifest.json',
            $prefix . '2026/11/doc.pdf',
            $prefix . '2026/11/photo.jpg',
            $prefix . 'nested/a.txt',
            $prefix . 'nested/deeper/b.txt',
            $prefix . 'nested/deeper/c.txt',
            $prefix . 'top.txt',
        ], $names);
        $this->assertSame("line one\nline two", $nested);
        $this->assertSame('wpmcp-site-backup', $manifest['format']);
        $this->assertSame(1, $manifest['format_version'], 'Restore reads the same format as before.');
        $this->assertSame('uploads', $manifest['scope']);
        $this->assertSame(6, $manifest['files']['count']);
        $this->assertSame(6, $job['result']['file_count']);
        $this->assertSame([], $this->leftovers(), 'No scratch file outlives a finished build.');

        // get-backup-status showed the build moving, step by step, and
        // never the internal cursor.
        $running = array_values(array_filter($records, static fn(array $r): bool => 'running' === $r['status']));
        $this->assertNotEmpty($running);
        $steps = array_map(static fn(array $r): int => (int) $r['progress']['steps'], $running);
        $this->assertSame($steps, array_values(array_unique($steps)), 'Each request is one more step.');
        $this->assertContains('files', array_map(static fn(array $r): string => $r['progress']['phase'], $running));
        foreach ($running as $record) {
            $this->assertArrayNotHasKey('build', $record);
            $this->assertArrayHasKey('summary', $record['progress']);
        }
    }

    public function test_a_database_backup_dumps_table_by_table_and_imports(): void
    {
        $this->limits['dump_rows'] = 3;
        $id = $this->queue('database');

        [$runs] = $this->drive($id, 2000);

        $job = Backup_Job_Store::get($id);
        $this->assertSame('completed', $job['status'], (string) $job['error']);
        $this->assertGreaterThan(3, $runs);

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($job['result']['file']));
        $sql = (string) $zip->getFromName('db.sql');
        $zip->close();

        global $wpdb;
        $this->assertStringContainsString('SET FOREIGN_KEY_CHECKS = 0;', $sql);
        $this->assertStringEndsWith("SET UNIQUE_CHECKS = 1;\n", $sql);
        $this->assertSame(
            (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}"),
            $job['result']['tables'][ $wpdb->users ]
        );
        $this->assertArrayHasKey($wpdb->options, $job['result']['manifest']['database']['tables']);
        $this->assertSame(1, substr_count($sql, 'CREATE TABLE `' . $wpdb->users . '`'));

        // The restore reads it like any archive: its dry run parses every
        // statement of db.sql and refuses a truncated dump.
        $report = (new Restore_Site_Backup())->handle(['job_id' => $id]);
        $this->assertNotWPError($report);
        $this->assertTrue($report['compatible']);
        $this->assertSame([], $report['refusals']);
    }

    public function test_the_resumable_dump_writes_every_row_once_across_steps(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wpmcp_chunk_probe';
        remove_filter('query', [$this, '_create_temporary_tables']);
        remove_filter('query', [$this, '_drop_temporary_tables']);
        try {
            $wpdb->query("DROP TABLE IF EXISTS {$table}");
            $wpdb->query("CREATE TABLE {$table} (a INT NOT NULL, b VARCHAR(20) NOT NULL) {$wpdb->get_charset_collate()}");
            for ($i = 1; $i <= 23; $i++) {
                $wpdb->insert($table, ['a' => $i, 'b' => 'r-' . $i]);
            }

            $dumper = new Db_Dumper();
            $state  = $dumper->begin([$table]);
            $sql    = '';
            $steps  = 0;
            while (! $state['done']) {
                $state = $dumper->step($state, static function (string $chunk) use (&$sql): void {
                    $sql .= $chunk;
                }, static fn(): bool => true, 4);
                $steps++;
                // The state is what is saved on the job between requests.
                $state = json_decode((string) wp_json_encode($state), true);
            }

            $this->assertGreaterThan(5, $steps, 'Four rows a step over a 23 row table without a primary key.');
            for ($i = 1; $i <= 23; $i++) {
                $this->assertSame(1, substr_count($sql, "'r-{$i}'"), "Row {$i} is written exactly once.");
            }
            $this->assertSame([$table => 23], $state['counts']);
        } finally {
            $wpdb->query("DROP TABLE IF EXISTS {$table}");
            add_filter('query', [$this, '_create_temporary_tables']);
            add_filter('query', [$this, '_drop_temporary_tables']);
        }
    }

    public function test_a_close_that_would_cross_the_hard_limit_is_canceled_and_retried_smaller(): void
    {
        $id = $this->queue('uploads');
        // Two requests in: the listing is done, the first batch is next.
        $this->drive_until($id, 'files');

        // This request's close is canceled at once (the time limit would
        // end the request); the archive keeps what it had.
        $this->limits['hard_seconds'] = 0;
        $before = Backup_Job_Store::get($id)['build'];
        wp_clear_scheduled_hook(Run_Backup_Job::HOOK, [$id]);
        (new Run_Backup_Job())->handle_scheduled($id);
        $after = Backup_Job_Store::get($id);

        $this->assertSame('running', $after['status']);
        $this->assertSame($before['files']['offset'], $after['build']['files']['offset'], 'A canceled batch is not counted.');
        $this->assertSame(1, $after['build']['files']['batch_files'], 'The next batch is smaller.');

        $this->limits['hard_seconds'] = null;
        $this->drive($id);
        $job = Backup_Job_Store::get($id);
        $this->assertSame('completed', $job['status'], (string) $job['error']);
        $this->assertSame(6, $job['result']['file_count']);
    }

    public function test_a_single_file_that_cannot_fit_in_a_request_fails_the_job_clearly(): void
    {
        $this->limits['hard_seconds'] = 0;
        $id = $this->queue('uploads');

        $this->drive($id);

        $job = Backup_Job_Store::get($id);
        $this->assertSame('failed', $job['status']);
        $this->assertStringContainsString('time limit', (string) $job['error']);
        $this->assertSame([], $this->leftovers(), 'A failed build leaves no partial file.');
    }

    public function test_a_step_killed_mid_request_is_resumed_by_its_watchdog(): void
    {
        Backup_Job_Store::set_clock_for_tests(1800000000);
        $id = $this->queue('uploads');
        $this->drive_until($id, 'files');
        // One batch in, so the partial archive exists.
        wp_clear_scheduled_hook(Run_Backup_Job::HOOK, [$id]);
        (new Run_Backup_Job())->handle_scheduled($id);

        $job     = Backup_Job_Store::get($id);
        $partial = $job['progress']['partial_file'];
        $this->assertFileExists($partial);

        // The next request starts a step and PHP ends it inside close():
        // the step is marked running, libzip's temp file is left behind,
        // and the list file grew past what was committed.
        Backup_Job_Store::update($id, ['build' => array_merge($job['build'], ['step_running' => true])]);
        $temp = $partial . '.Qw3rTy.part';
        file_put_contents($temp, 'half of a libzip rewrite');
        file_put_contents(
            $job['progress']['list_file'],
            wp_json_encode([$this->root . '/top.txt', 'wp-content/' . basename($this->root) . '/extra.txt']) . "\n",
            FILE_APPEND
        );

        // A duplicate delivery while that step still looks alive is refused.
        wp_clear_scheduled_hook(Run_Backup_Job::HOOK, [$id]);
        (new Run_Backup_Job())->handle_scheduled($id);
        $this->assertStringContainsString('another step', (string) Backup_Job_Store::get($id)['last_refusal']);
        $this->assertNotFalse(wp_next_scheduled(Run_Backup_Job::HOOK, [$id]), 'The watchdog stays armed.');
        $this->assertFileExists($temp);

        // Past the idle window the watchdog takes over: it removes the
        // temp file and carries on from the last saved step.
        Backup_Job_Store::set_clock_for_tests(1800000000 + Run_Backup_Job::STEP_IDLE_SECONDS + 5);
        $this->drive($id);

        $done = Backup_Job_Store::get($id);
        $this->assertSame('completed', $done['status'], (string) $done['error']);
        $this->assertSame(6, $done['result']['file_count'], 'Bytes written past the last saved step are dropped.');
        $this->assertFileDoesNotExist($temp);
    }

    public function test_a_step_killed_again_and_again_fails_the_job_and_cleans_up(): void
    {
        $now = 1800000000;
        Backup_Job_Store::set_clock_for_tests($now);
        $id = $this->queue('uploads');
        $this->drive_until($id, 'files');

        // The steps before this one all died where this one is about to.
        $job = Backup_Job_Store::get($id);
        Backup_Job_Store::update($id, ['build' => array_merge($job['build'], [
            'step_running' => true,
            'attempts'     => Run_Backup_Job::MAX_STEP_ATTEMPTS,
        ])]);
        Backup_Job_Store::set_clock_for_tests($now + Run_Backup_Job::STEP_IDLE_SECONDS + 5);

        wp_clear_scheduled_hook(Run_Backup_Job::HOOK, [$id]);
        (new Run_Backup_Job())->handle_scheduled($id);

        $job = Backup_Job_Store::get($id);
        $this->assertSame('failed', $job['status']);
        $this->assertStringContainsString('ended', (string) $job['error']);
        $this->assertArrayNotHasKey('build', $job);
        $this->assertSame([], $this->leftovers());
        $this->assertFalse(wp_next_scheduled(Run_Backup_Job::HOOK, [$id]));
    }

    public function test_cancel_between_steps_removes_every_partial_file_even_libzip_temp_files(): void
    {
        $id = $this->queue('uploads');
        $this->drive_until($id, 'files');
        wp_clear_scheduled_hook(Run_Backup_Job::HOOK, [$id]);
        (new Run_Backup_Job())->handle_scheduled($id);

        $partial = Backup_Job_Store::get($id)['progress']['partial_file'];
        // The temp name libzip 1.10 and later writes beside the archive.
        $temp = $partial . '.a1B2c3.part';
        file_put_contents($temp, 'libzip output of a killed close');
        $this->assertNotSame([], $this->leftovers());

        $out = (new Cancel_Backup_Job())->handle(['job_id' => $id]);
        $this->assertSame('canceled', $out['status']);
        $this->assertSame([], $this->leftovers());

        // A step left armed runs into the canceled job and does nothing but
        // tidy up.
        (new Run_Backup_Job())->handle_scheduled($id);
        $job = Backup_Job_Store::get($id);
        $this->assertSame('canceled', $job['status']);
        $this->assertArrayNotHasKey('build', $job);
        $this->assertFalse(wp_next_scheduled(Run_Backup_Job::HOOK, [$id]));
    }

    public function test_deactivating_the_plugin_between_steps_cancels_the_build_and_removes_its_files(): void
    {
        $id = $this->queue('uploads');
        $this->drive_until($id, 'files');
        $this->assertNotSame([], $this->leftovers());

        Cron_Registry::clear_all();

        $job = Backup_Job_Store::get($id);
        $this->assertSame('canceled', $job['status']);
        $this->assertArrayNotHasKey('build', $job);
        $this->assertSame([], $this->leftovers());
    }

    public function test_a_job_waiting_for_its_next_step_is_not_swept_as_dead_after_ten_minutes(): void
    {
        Backup_Job_Store::set_clock_for_tests(1800000000);
        $id = $this->queue('uploads');
        $this->drive_until($id, 'files');

        // A quiet site: no request comes for half an hour, so WP-Cron does
        // not run. The saved step is still good.
        Backup_Job_Store::set_clock_for_tests(1800000000 + 30 * MINUTE_IN_SECONDS);
        $this->assertSame('running', Backup_Job_Store::get($id)['status']);

        $this->drive($id);
        $this->assertSame('completed', Backup_Job_Store::get($id)['status']);
    }

    public function test_without_a_time_limit_the_backup_still_runs_in_one_request(): void
    {
        remove_filter('wpmcp_backup_chunked', '__return_true');
        // CLI: max_execution_time is 0, so nothing forces chunking.
        $this->assertSame('0', (string) ini_get('max_execution_time'));

        $id = $this->queue('uploads');
        (new Run_Backup_Job())->handle_scheduled($id);

        $job = Backup_Job_Store::get($id);
        $this->assertSame('completed', $job['status'], (string) $job['error']);
        $this->assertSame(6, $job['result']['file_count']);
        $this->assertArrayNotHasKey('build', $job);
    }

    public function test_a_chunked_full_backup_is_the_default_under_a_time_limit(): void
    {
        remove_filter('wpmcp_backup_chunked', '__return_true');
        $job = ['type' => 'full', 'scope' => 'all'];

        $limit = ini_get('max_execution_time');
        ini_set('max_execution_time', '30');
        try {
            $this->assertTrue(Run_Backup_Job::chunked($job));
            $limits = Run_Backup_Job::step_limits($job);
            $this->assertLessThan(30, $limits['seconds']);
            $this->assertLessThan(30, $limits['hard_seconds']);
        } finally {
            ini_set('max_execution_time', (string) $limit);
        }
        $this->assertFalse(Run_Backup_Job::chunked(['type' => 'content']), 'The WXR export is not an archive.');
    }

    /** Deliver steps until the build reaches $phase. */
    private function drive_until(int $id, string $phase): void
    {
        for ($i = 0; $i < 50; $i++) {
            wp_clear_scheduled_hook(Run_Backup_Job::HOOK, [$id]);
            (new Run_Backup_Job())->handle_scheduled($id);
            $job = Backup_Job_Store::get($id);
            if ($phase === ($job['build']['phase'] ?? null)) {
                return;
            }
        }
        $this->fail('The build never reached ' . $phase);
    }
}
