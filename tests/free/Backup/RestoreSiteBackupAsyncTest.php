<?php

namespace WPMCP\Tests\Free\Backup;

use WPMCP\Tools\Backup\Backup_Job_Store;
use WPMCP\Tools\Backup\Cancel_Backup_Job;
use WPMCP\Tools\Backup\Get_Backup_Status;
use WPMCP\Tools\Backup\Restore_Site_Backup;
use WPMCP\Tools\Backup\Run_Restore_Job;
use WPMCP\Tools\Backup\Site_Backup_Dir;

/**
 * restore-site-backup as a background job (issue #727). A real restore
 * through the ability used to run inside the MCP request: a client that gave
 * up waiting saw only "The operation timed out" while the import carried on
 * and visitors got a 503. Now dry_run:false queues a 'restore' job, returns
 * its id at once, and Run_Restore_Job runs it under WP-Cron; get-backup-status
 * reports queued, running, completed or failed, with the safety archive and
 * the maintenance window.
 */
class RestoreSiteBackupAsyncTest extends \WP_UnitTestCase
{
    use RestoreArchiveFixtures;

    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        delete_option(Backup_Job_Store::OPTION);
        delete_option('wpmcp_maintenance');
        _set_cron_array([]);

        $this->admin = (int) self::factory()->user->create(['role' => 'administrator']);
        if (is_multisite()) {
            grant_super_admin($this->admin);
        }
        wp_set_current_user($this->admin);
    }

    protected function tearDown(): void
    {
        $this->clean_restore_fixtures();
        foreach (Backup_Job_Store::list() as $job) {
            $file = (string) ($job['result']['file'] ?? '');
            if ('' !== $file && is_file($file)) {
                unlink($file);
            }
            $safety = (string) ($job['result']['safety_archive']['file'] ?? '');
            if ('' !== $safety && is_file($safety)) {
                unlink($safety);
            }
        }
        $state = Site_Backup_Dir::path() . '/restore-in-progress.json';
        if (is_file($state)) {
            unlink($state);
        }
        delete_option(Backup_Job_Store::OPTION);
        delete_option('wpmcp_maintenance');
        remove_all_actions('wpmcp_restore_statement_executed');
        _set_cron_array([]);
        parent::tearDown();
    }

    private function posts_tables(): array
    {
        global $wpdb;
        return [$wpdb->posts, $wpdb->postmeta];
    }

    private function tool(): Restore_Site_Backup
    {
        return new Restore_Site_Backup($this->safety_producer($this->posts_tables()));
    }

    private function status(int $job_id): array
    {
        $out = (new Get_Backup_Status())->handle(['job_id' => $job_id]);
        $this->assertIsArray($out);
        return $out;
    }

    private function title(int $post_id): string
    {
        clean_post_cache($post_id);
        return (string) get_post($post_id)->post_title;
    }

    public function test_a_real_restore_returns_a_job_id_at_once_and_restores_nothing_yet(): void
    {
        $post    = self::factory()->post->create(['post_title' => 'As backed up']);
        $archive = $this->archive_of($this->posts_tables());
        wp_update_post(['ID' => $post, 'post_title' => 'Live value']);

        $imported = 0;
        add_action('wpmcp_restore_statement_executed', static function () use (&$imported): void {
            $imported++;
        });

        $out = $this->tool()->handle_request(['path' => $archive, 'dry_run' => false]);

        $this->assertSame('async', $out['mode']);
        $this->assertSame('queued', $out['status']);
        $this->assertIsInt($out['job_id']);
        $this->assertStringContainsString('get-backup-status', $out['message']);
        $this->assertNotFalse(wp_next_scheduled(Run_Restore_Job::HOOK, [$out['job_id']]));

        $this->assertSame(0, $imported, 'The request that queues the restore must not import anything.');
        $this->assertSame('Live value', $this->title($post));
        $this->assertFalse(get_option('wpmcp_maintenance'), 'Maintenance mode goes on in the job, not in the queueing request.');
        $this->assertCount(1, Backup_Job_Store::list(), 'Only the restore job exists: the safety archive is taken by the job.');

        $job = $this->status($out['job_id']);
        $this->assertSame('restore', $job['type']);
        $this->assertSame('queued', $job['status']);
        $this->assertSame($this->admin, $job['user_id']);
    }

    public function test_a_long_restore_moves_from_queued_to_running_to_completed_with_its_maintenance_window(): void
    {
        $post    = self::factory()->post->create(['post_title' => 'As backed up']);
        $archive = $this->archive_of($this->posts_tables());
        wp_update_post(['ID' => $post, 'post_title' => 'Changed after the backup']);

        $tool   = $this->tool();
        $queued = $tool->handle_request(['path' => $archive, 'dry_run' => false]);
        $id     = $queued['job_id'];
        $this->assertSame('queued', $this->status($id)['status']);

        // The client polls while the import runs (and "the request timed
        // out" on its side does not matter): what it sees mid-import.
        $seen = null;
        add_action('wpmcp_restore_statement_executed', function () use (&$seen, $id): void {
            if (null === $seen) {
                $seen = $this->status($id);
            }
        });

        wp_set_current_user(0); // WP-Cron has nobody signed in.
        (new Run_Restore_Job($tool))->handle($id);

        $this->assertNotNull($seen);
        $this->assertSame('running', $seen['status']);
        $this->assertNotEmpty($seen['maintenance']['started_at'], 'A running restore reports when maintenance mode went on.');
        $this->assertNull($seen['maintenance']['ended_at']);
        $this->assertGreaterThan(0, $seen['safety_archive']['job_id']);

        $done = $this->status($id);
        $this->assertSame('completed', $done['status']);
        $this->assertNull($done['error']);
        $this->assertSame('restored', $done['result']['status']);
        $this->assertSame('As backed up', $this->title($post));
        $this->assertNotEmpty($done['maintenance']['started_at']);
        $this->assertNotEmpty($done['maintenance']['ended_at']);
        $this->assertIsInt($done['maintenance']['seconds']);
        $this->assertTrue($done['maintenance']['released']);
        $this->assertSame('completed', Backup_Job_Store::get($done['safety_archive']['job_id'])['status']);
        $this->assertFalse(get_option('wpmcp_maintenance'));
        $this->assertSame(0, get_current_user_id(), 'The runner puts the cron request\'s user back.');
    }

    public function test_a_failed_import_is_reported_as_a_failed_job_with_its_safety_archive(): void
    {
        global $wpdb;
        $post = self::factory()->post->create(['post_title' => 'As backed up']);
        [$sql, $result] = $this->dump_tables($this->posts_tables());
        // An unknown column: the policy admits it, the database rejects it.
        $dup  = "INSERT INTO `{$wpdb->posts}` (`ID`, `no_such_column`) VALUES\n('{$post}', 'x');\n";
        $at   = strrpos($sql, "\nSET FOREIGN_KEY_CHECKS = 1;");
        $sql  = substr($sql, 0, $at) . "\n" . $dup . substr($sql, $at);
        $path = $this->archive_from_sql($sql, $result['tables']);
        wp_update_post(['ID' => $post, 'post_title' => 'Live value']);

        $tool = $this->tool();
        $id   = $tool->handle_request(['path' => $path, 'dry_run' => false])['job_id'];
        (new Run_Restore_Job($tool))->handle($id);

        $job = $this->status($id);
        $this->assertSame('failed', $job['status']);
        $this->assertStringContainsString('rolled back', $job['error']);
        $this->assertStringContainsString('backup job ' . $job['safety_archive']['job_id'], $job['error']);
        $this->assertSame('failed_rolled_back', $job['result']['status']);
        $this->assertNotEmpty($job['maintenance']['ended_at']);
        $this->assertSame('Live value', $this->title($post));
        $this->assertFalse(get_option('wpmcp_maintenance'));
    }

    public function test_a_failed_safety_archive_fails_the_job_and_changes_nothing(): void
    {
        $post    = self::factory()->post->create(['post_title' => 'Untouched']);
        $archive = $this->archive_of($this->posts_tables());
        wp_update_post(['ID' => $post, 'post_title' => 'Live value']);

        $tool = new Restore_Site_Backup(static function (): array {
            throw new \RuntimeException('disk full');
        });
        $id = $tool->handle_request(['path' => $archive, 'dry_run' => false])['job_id'];
        (new Run_Restore_Job($tool))->handle($id);

        $job = $this->status($id);
        $this->assertSame('failed', $job['status']);
        $this->assertStringContainsString('pre-restore safety archive', $job['error']);
        $this->assertStringContainsString('disk full', $job['error']);
        $this->assertNull($job['maintenance']['started_at'], 'Maintenance mode was never entered.');
        $this->assertFalse(get_option('wpmcp_maintenance'));
        $this->assertSame('Live value', $this->title($post));
    }

    public function test_a_second_restore_is_refused_while_one_is_queued(): void
    {
        $archive = $this->archive_of($this->posts_tables());
        $first   = $this->tool()->handle_request(['path' => $archive, 'dry_run' => false]);

        try {
            $this->tool()->handle_request(['path' => $archive, 'dry_run' => false]);
            $this->fail('A second restore must be refused while one is queued.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('restore job ' . $first['job_id'] . ' is already queued', $e->getMessage());
        }

        $this->assertCount(1, Backup_Job_Store::list());
    }

    public function test_a_restore_is_refused_while_another_holds_the_restore_lock(): void
    {
        global $wpdb;
        $archive = $this->archive_of($this->posts_tables());

        $other = new \wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
        $name  = 'wpmcp_restore_' . substr(md5(DB_NAME . '|' . $wpdb->base_prefix), 0, 16);
        $this->assertSame('1', (string) $other->get_var($other->prepare('SELECT GET_LOCK(%s, 0)', $name)));

        try {
            $this->tool()->handle_request(['path' => $archive, 'dry_run' => false]);
            $this->fail('A restore must be refused while another one runs.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('already running', $e->getMessage());
        } finally {
            $other->close();
        }

        $this->assertSame([], Backup_Job_Store::list());
    }

    public function test_a_refused_archive_is_refused_before_any_job_exists(): void
    {
        $path = $this->archive_of($this->posts_tables(), ['site.table_prefix' => 'other_']);

        try {
            $this->tool()->handle_request(['path' => $path, 'dry_run' => false]);
            $this->fail('An incompatible archive must be refused.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Restore refused', $e->getMessage());
        }

        $this->assertSame([], Backup_Job_Store::list());
    }

    public function test_a_second_delivery_of_the_event_is_refused(): void
    {
        $archive = $this->archive_of($this->posts_tables());
        $tool    = $this->tool();
        $id      = $tool->handle_request(['path' => $archive, 'dry_run' => false])['job_id'];

        (new Run_Restore_Job($tool))->handle($id);
        (new Run_Restore_Job($tool))->handle($id);

        $job = $this->status($id);
        $this->assertSame('completed', $job['status']);
        $this->assertStringContainsString('not queued', $job['last_refusal']);
        $this->assertCount(2, Backup_Job_Store::list(), 'One restore job and one safety archive: the duplicate ran nothing.');
    }

    public function test_a_job_whose_owner_lost_the_capability_is_canceled(): void
    {
        $archive = $this->archive_of($this->posts_tables());
        $tool    = $this->tool();
        $id      = $tool->handle_request(['path' => $archive, 'dry_run' => false])['job_id'];

        if (is_multisite()) {
            revoke_super_admin($this->admin);
        }
        get_userdata($this->admin)->set_role('subscriber');

        (new Run_Restore_Job($tool))->handle($id);

        $job = $this->status($id);
        $this->assertSame('canceled', $job['status']);
        $this->assertStringContainsString('no longer has', $job['error']);
        $this->assertCount(1, Backup_Job_Store::list(), 'No safety archive was taken.');
    }

    public function test_a_queued_restore_can_be_canceled(): void
    {
        $archive = $this->archive_of($this->posts_tables());
        $id      = $this->tool()->handle_request(['path' => $archive, 'dry_run' => false])['job_id'];

        $out = (new Cancel_Backup_Job())->handle(['job_id' => $id]);

        $this->assertSame('canceled', $out['status']);
        $this->assertFalse(wp_next_scheduled(Run_Restore_Job::HOOK, [$id]));
    }

    public function test_a_restore_that_died_mid_import_is_reported_failed(): void
    {
        Site_Backup_Dir::protect(Site_Backup_Dir::path());
        $job = Backup_Job_Store::create('restore', 'database', ['user_id' => $this->admin]);
        Backup_Job_Store::transition($job['id'], 'queued', ['status' => 'running']);
        file_put_contents(Site_Backup_Dir::path() . '/restore-in-progress.json', (string) wp_json_encode([
            'started_at'             => '2026-01-01T00:00:00+00:00',
            'archive'                => 'wpmcp-database-old.zip',
            'safety_job_id'          => 41,
            'restore_job_id'         => $job['id'],
            'maintenance_started_at' => '2026-01-01T00:00:05+00:00',
            'statement'              => 17,
            'table'                  => 'wp_postmeta',
        ]));

        $out = $this->status($job['id']);

        $this->assertSame('failed', $out['status']);
        $this->assertStringContainsString('backup job 41', $out['error']);
        $this->assertSame('2026-01-01T00:00:05+00:00', $out['maintenance']['started_at']);
        $this->assertSame(17, $out['progress']['statement']);
    }

    /**
     * Issue #814: the interrupted-restore warning comes back when the next
     * restore is queued, before it runs, and the earlier safety archive is
     * put back in the job store so it can be found and restored.
     */
    public function test_queueing_after_an_interrupted_restore_warns_and_lists_the_earlier_safety_archive(): void
    {
        $archive = $this->archive_of($this->posts_tables());
        $safety  = $this->archive_of($this->posts_tables());
        Site_Backup_Dir::protect(Site_Backup_Dir::path());
        file_put_contents(Site_Backup_Dir::path() . '/restore-in-progress.json', (string) wp_json_encode([
            'started_at'    => '2026-01-01T00:00:00+00:00',
            'archive'       => basename($archive),
            'safety_job_id' => 7,
            'safety_file'   => basename($safety),
            'statement'     => 601,
            'table'         => 'wp_options',
        ]));

        $out = $this->tool()->handle_request(['path' => $archive, 'dry_run' => false]);

        $this->assertSame('async', $out['mode']);
        $warning = implode(' ', $out['warnings']);
        $this->assertStringContainsString('stopped without finishing', $warning);
        $this->assertStringContainsString(basename($safety), $warning);

        $adopted = Backup_Job_Store::get(7);
        $this->assertNotNull($adopted, 'The earlier safety archive is listed again under its own id when that id is free.');
        $this->assertSame('completed', $adopted['status']);
        $this->assertSame(realpath($safety), realpath((string) $adopted['result']['file']));
        $this->assertGreaterThan(7, $out['job_id'], 'The queued restore does not reuse an id the interrupted restore handed out.');
    }

    public function test_a_restore_that_died_mid_import_names_its_safety_archive_file(): void
    {
        $safety = $this->archive_of($this->posts_tables());
        $job    = Backup_Job_Store::create('restore', 'database', ['user_id' => $this->admin]);
        Backup_Job_Store::transition($job['id'], 'queued', ['status' => 'running']);
        file_put_contents(Site_Backup_Dir::path() . '/restore-in-progress.json', (string) wp_json_encode([
            'started_at'     => '2026-01-01T00:00:00+00:00',
            'archive'        => 'wpmcp-database-old.zip',
            'safety_job_id'  => 41,
            'safety_file'    => basename($safety),
            'restore_job_id' => $job['id'],
            'statement'      => 601,
            'table'          => 'wp_options',
        ]));

        $out = $this->status($job['id']);

        $this->assertSame('failed', $out['status']);
        $this->assertStringContainsString(basename($safety), $out['error']);
        $this->assertStringNotContainsString('backup job 41', $out['error']);
        $this->assertSame(basename($safety), $out['safety_archive']['file']);
    }

    public function test_wait_true_keeps_the_synchronous_restore(): void
    {
        $post    = self::factory()->post->create(['post_title' => 'As backed up']);
        $archive = $this->archive_of($this->posts_tables());
        wp_update_post(['ID' => $post, 'post_title' => 'Changed']);

        $out = $this->tool()->handle_request(['path' => $archive, 'dry_run' => false, 'wait' => true]);

        $this->assertSame('restored', $out['status']);
        $this->assertSame('As backed up', $this->title($post));
        $this->assertNotEmpty($out['maintenance']['started_at']);
        $this->assertNotEmpty($out['maintenance']['ended_at']);
    }

    public function test_a_dry_run_is_still_answered_in_the_request(): void
    {
        $archive = $this->archive_of($this->posts_tables());

        $out = $this->tool()->handle_request(['path' => $archive]);

        $this->assertTrue($out['dry_run']);
        $this->assertTrue($out['compatible']);
        $this->assertSame([], Backup_Job_Store::list());
    }

    public function test_the_ability_queues_by_default(): void
    {
        $archive = $this->archive_of($this->posts_tables());

        $out = wp_get_ability('wpmcp/restore-site-backup')->execute(['path' => $archive, 'dry_run' => false]);

        $this->assertIsArray($out);
        $this->assertSame('queued', $out['status']);
        $this->assertNotFalse(wp_next_scheduled(Run_Restore_Job::HOOK, [$out['job_id']]));
    }
}
