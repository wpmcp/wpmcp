<?php

namespace WPMCP\Tests\Free\Backup;

use WPMCP\Tools\Backup\Backup_Job_Store;
use WPMCP\Tools\Backup\Restore_Files;
use WPMCP\Tools\Backup\Restore_Site_Backup;
use WPMCP\Tools\Backup\Site_Backup_Dir;

/**
 * The execution path of restore-site-backup (issue #190, phase 1), against
 * real archives of real tables. See RestoreArchiveFixtures for how a test
 * can restore wp_posts without replacing the shared test database.
 *
 * Covers the definition of done: a real restore round-trips content; a
 * truncated archive is refused before maintenance mode; a mid-import
 * failure leaves maintenance off, names the failing statement, and rolls
 * back; the pre-restore safety archive exists and is reported even when
 * the restore fails.
 */
class RestoreSiteBackupExecuteTest extends \WP_UnitTestCase
{
    use RestoreArchiveFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        delete_option(Backup_Job_Store::OPTION);
        delete_option('wpmcp_maintenance');
    }

    protected function tearDown(): void
    {
        $this->clean_restore_fixtures();
        foreach (Backup_Job_Store::list() as $job) {
            $file = (string) ($job['result']['file'] ?? '');
            if ('' !== $file && is_file($file)) {
                unlink($file);
            }
        }
        delete_option(Backup_Job_Store::OPTION);
        delete_option('wpmcp_maintenance');
        remove_all_actions('wpmcp_restore_statement_executed');
        parent::tearDown();
    }

    private function posts_tables(): array
    {
        global $wpdb;
        return [$wpdb->posts, $wpdb->postmeta];
    }

    private function restore(string $path, array $args = [], ?array $safety_tables = null): array
    {
        $tool = new Restore_Site_Backup($this->safety_producer($safety_tables ?? $this->posts_tables()));
        return $tool->handle(array_merge(['path' => $path, 'dry_run' => false], $args));
    }

    private function title(int $post_id): string
    {
        clean_post_cache($post_id);
        return (string) get_post($post_id)->post_title;
    }

    public function test_a_real_restore_round_trips_content(): void
    {
        $post = self::factory()->post->create(['post_title' => 'As backed up']);
        add_post_meta($post, 'colour', 'blue');
        $archive = $this->archive_of($this->posts_tables());

        wp_update_post(['ID' => $post, 'post_title' => 'Changed after the backup']);
        update_post_meta($post, 'colour', 'red');
        $later = self::factory()->post->create(['post_title' => 'Created after the backup']);

        $out = $this->restore($archive);

        $this->assertSame('restored', $out['status']);
        $this->assertTrue($out['restored']);
        $this->assertNull($out['failure']);
        $this->assertSame('As backed up', $this->title($post));
        wp_cache_flush();
        $this->assertSame('blue', get_post_meta($post, 'colour', true));
        $this->assertNull(get_post($later), 'A post created after the backup is gone once the backup is restored.');
        $this->assertSame($out['import']['statements_total'], $out['import']['statements_executed']);
        $this->assertSame(2, $out['import']['tables']);
    }

    public function test_the_safety_archive_is_taken_first_and_reported(): void
    {
        self::factory()->post->create(['post_title' => 'Before']);
        $archive = $this->archive_of($this->posts_tables());

        $out = $this->restore($archive);

        $job = Backup_Job_Store::get($out['safety_archive']['job_id']);
        $this->assertNotNull($job, 'The safety job must survive the restore of the options table it lives in.');
        $this->assertSame('completed', $job['status']);
        $this->assertSame('pre-restore safety archive', $job['purpose']);
        $this->assertFileExists($out['safety_archive']['file']);
    }

    public function test_the_default_safety_archive_is_a_real_database_archive(): void
    {
        $archive = $this->archive_of($this->posts_tables());

        $out = (new Restore_Site_Backup())->handle(['path' => $archive, 'dry_run' => false]);

        $this->assertSame('restored', $out['status']);
        $file = $out['safety_archive']['file'];
        $this->fixture_files[] = $file;
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($file));
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        $this->assertNotFalse($zip->locateName('db.sql'));
        $zip->close();
        $this->assertSame('database', $manifest['scope']);
    }

    public function test_a_failed_safety_archive_means_the_restore_never_starts(): void
    {
        $post    = self::factory()->post->create(['post_title' => 'Untouched']);
        $archive = $this->archive_of($this->posts_tables());
        wp_update_post(['ID' => $post, 'post_title' => 'Live value']);

        $tool = new Restore_Site_Backup(static function (): array {
            throw new \RuntimeException('disk full');
        });

        try {
            $tool->handle(['path' => $archive, 'dry_run' => false]);
            $this->fail('A restore without a safety archive must not start.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('pre-restore safety archive', $e->getMessage());
            $this->assertStringContainsString('disk full', $e->getMessage());
        }

        $this->assertSame('Live value', $this->title($post));
        $this->assertFalse(get_option('wpmcp_maintenance'), 'Maintenance mode must never have been entered.');
    }

    public function test_maintenance_mode_is_on_for_every_statement_and_off_afterwards(): void
    {
        $archive = $this->archive_of($this->posts_tables());
        $seen    = [];
        add_action('wpmcp_restore_statement_executed', static function () use (&$seen): void {
            $option = get_option('wpmcp_maintenance');
            $seen[] = is_array($option) && ! empty($option['enabled']);
        });

        $out = $this->restore($archive);

        $this->assertNotEmpty($seen);
        $this->assertNotContains(false, $seen, 'Maintenance mode must hold for the whole import.');
        $this->assertFalse(get_option('wpmcp_maintenance'), 'The option did not exist before, so it must not exist after.');
        $this->assertTrue($out['maintenance']['released']);
    }

    public function test_maintenance_is_reasserted_after_the_options_table_is_replaced(): void
    {
        global $wpdb;
        // The backup was taken with maintenance off (no row at all). Once
        // the dump replaces wp_options, the guard would read "off" for every
        // table that sorts after options (posts, users, ...).
        $tables  = [$wpdb->options, $wpdb->posts];
        $archive = $this->archive_of($tables);
        $after_options = [];
        add_action('wpmcp_restore_statement_executed', static function (array $statement, array $class) use (&$after_options, $wpdb): void {
            if ($class['table'] === $wpdb->posts) {
                wp_cache_delete('alloptions', 'options');
                $option          = get_option('wpmcp_maintenance');
                $after_options[] = is_array($option) && ! empty($option['enabled']);
            }
        }, 10, 2);

        $out = $this->restore($archive, [], $tables);

        $this->assertSame('restored', $out['status']);
        $this->assertNotEmpty($after_options);
        $this->assertNotContains(false, $after_options);
        $this->assertFalse(get_option('wpmcp_maintenance'), 'The restored options table had no maintenance row, so none is left behind.');
    }

    public function test_a_maintenance_value_from_before_the_restore_is_put_back(): void
    {
        $archive = $this->archive_of($this->posts_tables());
        $before  = ['enabled' => false, 'message' => 'Planned work', 'retry_after' => 60];
        update_option('wpmcp_maintenance', $before);

        $this->restore($archive);

        $this->assertSame($before, get_option('wpmcp_maintenance'));
    }

    public function test_a_mid_import_failure_reports_the_statement_rolls_back_and_releases_maintenance(): void
    {
        global $wpdb;
        $post = self::factory()->post->create(['post_title' => 'As backed up']);
        [$sql, $result] = $this->dump_tables($this->posts_tables());

        // A duplicate primary key passes the policy (it is a literal-only
        // INSERT) but the database rejects it half-way through the import.
        $dup  = "INSERT INTO `{$wpdb->posts}` (`ID`, `post_title`) VALUES\n('{$post}', 'duplicate');\n";
        $at   = strrpos($sql, "\nSET FOREIGN_KEY_CHECKS = 1;");
        $sql  = substr($sql, 0, $at) . "\n" . $dup . substr($sql, $at);
        $path = $this->archive_from_sql($sql, $result['tables']);

        wp_update_post(['ID' => $post, 'post_title' => 'Live value']);

        $out = $this->restore($path);

        $this->assertFalse($out['restored']);
        $this->assertSame('failed_rolled_back', $out['status']);
        $this->assertSame($wpdb->posts, $out['failure']['table']);
        $this->assertSame('insert', $out['failure']['kind']);
        $this->assertStringContainsString('Duplicate', $out['failure']['error']);
        $this->assertGreaterThan(0, $out['failure']['statement']);
        $this->assertLessThan($out['import']['statements_total'], $out['import']['statements_executed']);

        $this->assertTrue($out['rollback']['restored']);
        $this->assertSame('Live value', $this->title($post), 'The rollback must put the pre-restore content back.');

        $this->assertFalse(get_option('wpmcp_maintenance'), 'A failed restore must not leave the site in maintenance mode.');
        $this->assertNotNull(Backup_Job_Store::get($out['safety_archive']['job_id']));
        $this->assertFileExists($out['safety_archive']['file']);
    }

    public function test_the_failure_leaves_foreign_key_checks_and_sql_mode_as_they_were(): void
    {
        global $wpdb;
        $before = $wpdb->get_row('SELECT @@SESSION.sql_mode AS m, @@SESSION.foreign_key_checks AS f', ARRAY_A);
        [$sql, $result] = $this->dump_tables($this->posts_tables());
        $sql  = str_replace("SET FOREIGN_KEY_CHECKS = 1;\n", "INSERT INTO `{$wpdb->posts}` (`no_such_column`) VALUES ('x');\n", $sql);
        $path = $this->archive_from_sql($sql, $result['tables']);

        $out = $this->restore($path);

        $this->assertFalse($out['restored']);
        $after = $wpdb->get_row('SELECT @@SESSION.sql_mode AS m, @@SESSION.foreign_key_checks AS f', ARRAY_A);
        $this->assertSame($before, $after);
    }

    public function test_a_truncated_zip_is_refused_before_maintenance_mode_or_any_backup(): void
    {
        $archive = $this->archive_of($this->posts_tables());
        $bytes   = (string) file_get_contents($archive);
        file_put_contents($archive, substr($bytes, 0, (int) (strlen($bytes) / 2)));

        try {
            $this->restore($archive);
            $this->fail('A truncated archive must be refused.');
        } catch (\RuntimeException $e) {
            $this->assertNotSame('', $e->getMessage());
        }

        $this->assertFalse(get_option('wpmcp_maintenance'));
        $this->assertSame([], Backup_Job_Store::list(), 'No safety archive is taken for an archive that was refused.');
    }

    public function test_a_truncated_dump_is_refused_by_its_manifest_byte_count(): void
    {
        [$sql, $result] = $this->dump_tables($this->posts_tables());
        $path = $this->archive_from_sql(substr($sql, 0, (int) (strlen($sql) / 2)), $result['tables'], ['database.bytes' => strlen($sql)]);

        $out = (new Restore_Site_Backup())->handle(['path' => $path]);

        $this->assertFalse($out['compatible']);
        $this->assertStringContainsString('truncated or was edited', implode(' ', $out['refusals']));
    }

    public function test_a_truncated_dump_without_a_byte_count_is_refused_by_the_parser(): void
    {
        [$sql, $result] = $this->dump_tables($this->posts_tables());
        $cut  = substr($sql, 0, (int) strrpos($sql, 'INSERT INTO') + 40);
        $path = $this->archive_from_sql($cut, $result['tables'], ['database.bytes' => null]);

        try {
            $this->restore($path);
            $this->fail('A dump that ends mid-statement must be refused.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('truncated', $e->getMessage());
        }

        $this->assertFalse(get_option('wpmcp_maintenance'));
        $this->assertSame([], Backup_Job_Store::list());
    }

    public function test_a_dump_that_writes_to_another_prefix_is_refused_with_the_table_named(): void
    {
        global $wpdb;
        [$sql, $result] = $this->dump_tables($this->posts_tables());
        $sql .= "DROP TABLE IF EXISTS `other_posts`;\n";
        $path = $this->archive_from_sql($sql, $result['tables']);

        $out = (new Restore_Site_Backup())->handle(['path' => $path]);

        $this->assertFalse($out['compatible']);
        $refusals = implode(' ', $out['refusals']);
        $this->assertStringContainsString('other_posts', $refusals);
        $this->assertStringContainsString('"' . $wpdb->prefix . '"', $refusals);
    }

    public function test_a_dump_with_statements_outside_the_allowlist_is_refused(): void
    {
        [$sql, $result] = $this->dump_tables($this->posts_tables());
        $path = $this->archive_from_sql($sql . "GRANT ALL ON *.* TO 'x'@'%';\n", $result['tables']);

        $out = (new Restore_Site_Backup())->handle(['path' => $path]);

        $this->assertFalse($out['compatible']);
        $this->assertStringContainsString('not allowed', implode(' ', $out['refusals']));
    }

    public function test_a_wordpress_downgrade_still_restores_and_carries_the_warning(): void
    {
        $post    = self::factory()->post->create(['post_title' => 'From a newer WordPress']);
        $archive = $this->archive_of($this->posts_tables(), ['versions.wordpress' => '99.0']);
        wp_update_post(['ID' => $post, 'post_title' => 'Changed']);

        $out = $this->restore($archive);

        $this->assertSame('restored', $out['status']);
        $this->assertStringContainsString('database downgrade', implode(' ', $out['warnings']));
        $this->assertSame('From a newer WordPress', $this->title($post));
    }

    public function test_blob_tables_are_reported_in_a_real_restore(): void
    {
        global $wpdb;
        $archive = $this->archive_of($this->posts_tables(), ['database.blob_tables' => [$wpdb->postmeta]]);

        $out = $this->restore($archive);

        $this->assertStringContainsString('BLOB columns', implode(' ', $out['warnings']));
    }

    public function test_the_dry_run_lists_tables_the_archive_does_not_cover(): void
    {
        global $wpdb;
        $archive = $this->archive_of($this->posts_tables());

        $out = (new Restore_Site_Backup())->handle(['path' => $archive]);

        $this->assertTrue($out['compatible']);
        $this->assertGreaterThan(0, $out['statements']);
        $this->assertSame(2, $out['tables']);
        $this->assertStringContainsString($wpdb->options, implode(' ', $out['warnings']));
    }

    public function test_a_legacy_placeholder_token_is_turned_back_into_a_percent_sign(): void
    {
        $post = self::factory()->post->create(['post_title' => 'Save 100% today']);
        [$sql, $result] = $this->dump_tables($this->posts_tables());
        $token = '{' . str_repeat('ab12', 16) . '}';
        $sql   = str_replace('Save 100% today', 'Save 100' . $token . ' today', $sql);
        $path  = $this->archive_from_sql($sql, $result['tables']);
        wp_update_post(['ID' => $post, 'post_title' => 'Changed']);

        $out = $this->restore($path);

        $this->assertSame('Save 100% today', $this->title($post));
        $this->assertStringContainsString('placeholder token', implode(' ', $out['warnings']));
    }

    public function test_an_interrupted_restore_is_reported_by_the_next_call(): void
    {
        $archive = $this->archive_of($this->posts_tables());
        Site_Backup_Dir::protect(Site_Backup_Dir::path());
        $state = Site_Backup_Dir::path() . '/restore-in-progress.json';
        file_put_contents($state, (string) wp_json_encode([
            'started_at'    => '2026-01-01T00:00:00+00:00',
            'archive'       => 'wpmcp-database-old.zip',
            'safety_job_id' => 41,
            'statement'     => 17,
            'table'         => 'wp_postmeta',
        ]));
        $this->fixture_files[] = $state;

        $out = (new Restore_Site_Backup())->handle(['path' => $archive]);

        $warning = implode(' ', $out['warnings']);
        $this->assertStringContainsString('stopped without finishing', $warning);
        $this->assertStringContainsString('backup job 41', $warning);
        $this->assertStringContainsString('statement 17', $warning);
    }

    public function test_a_second_restore_is_refused_while_one_holds_the_lock(): void
    {
        global $wpdb;
        $archive = $this->archive_of($this->posts_tables());

        // Another connection holding the lock is what a concurrent restore
        // looks like; GET_LOCK is per connection, so a second mysqli
        // connection stands in for the other request.
        $other = new \wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
        $name  = 'wpmcp_restore_' . substr(md5(DB_NAME . '|' . $wpdb->base_prefix), 0, 16);
        $this->assertSame('1', (string) $other->get_var($other->prepare('SELECT GET_LOCK(%s, 0)', $name)));

        try {
            $this->restore($archive);
            $this->fail('A concurrent restore must be refused.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('already running', $e->getMessage());
        } finally {
            $other->close();
        }

        $this->assertSame([], Backup_Job_Store::list());
    }

    public function test_the_acting_user_stays_signed_in(): void
    {
        global $wpdb;
        $admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin);
        $archive = $this->archive_of($this->posts_tables());

        $out = $this->restore($archive);

        $this->assertTrue($out['session']['preserved']);
        $this->assertFalse($out['session']['relogin_required']);
        $this->assertSame($admin, get_current_user_id());
    }

    public function test_preserve_session_false_reports_that_a_relogin_is_needed(): void
    {
        $archive = $this->archive_of($this->posts_tables());

        $out = $this->restore($archive, ['preserve_session' => false]);

        $this->assertFalse($out['session']['preserved']);
        $this->assertTrue($out['session']['relogin_required']);
    }

    public function test_include_files_swaps_a_staged_wp_content_in_after_the_database(): void
    {
        $content = get_temp_dir() . 'wpmcp-restore-content-' . wp_generate_password(6, false);
        wp_mkdir_p($content . '/themes/old-theme');
        file_put_contents($content . '/themes/old-theme/style.css', 'old');
        wp_mkdir_p($content . '/uploads/' . Site_Backup_Dir::DIR_NAME);

        $uploads = static function (array $dirs) use ($content): array {
            $dirs['basedir'] = $content . '/uploads';
            $dirs['path']    = $content . '/uploads';
            return $dirs;
        };
        add_filter('upload_dir', $uploads);

        try {
            [$sql, $result] = $this->dump_tables($this->posts_tables());
            $archive = $this->archive_from_sql($sql, $result['tables'], ['scope' => 'all'], [
                'wp-content/themes/new-theme/style.css' => 'new',
                'wp-content/uploads/2020/01/photo.txt'  => 'photo',
            ]);
            $this->assertStringStartsWith($content, $archive);

            $tool = new Restore_Site_Backup($this->safety_producer($this->posts_tables()), new Restore_Files($content));
            $out  = $tool->handle(['path' => $archive, 'dry_run' => false, 'include_files' => true]);

            $this->assertSame('restored', $out['status']);
            $this->assertTrue($out['files']['restored']);
            $this->assertSame('new', file_get_contents($content . '/themes/new-theme/style.css'));
            $this->assertFileDoesNotExist($content . '/themes/old-theme/style.css');
            $this->assertSame('photo', file_get_contents($content . '/uploads/2020/01/photo.txt'));
            $this->assertFileExists($archive, 'The backup directory is carried across the uploads swap.');
            $this->assertFileExists($out['safety_archive']['file']);
            $this->assertSame('old', file_get_contents($out['files']['previous_tree'] . '/themes/old-theme/style.css'));
            $this->assertContains('uploads/' . Site_Backup_Dir::DIR_NAME, $out['files']['kept_live']);
            $this->assertSame([], glob($content . '/' . Restore_Files::DIR_NAME . '/staging-*'), 'The staging directory is removed.');
        } finally {
            remove_filter('upload_dir', $uploads);
            require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
            require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
            (new \WP_Filesystem_Direct(null))->delete($content, true);
        }
    }

    public function test_include_files_is_not_swapped_when_the_database_import_fails(): void
    {
        global $wpdb;
        $content = get_temp_dir() . 'wpmcp-restore-content-' . wp_generate_password(6, false);
        wp_mkdir_p($content . '/themes/live-theme');
        file_put_contents($content . '/themes/live-theme/style.css', 'live');

        try {
            [$sql, $result] = $this->dump_tables($this->posts_tables());
            $sql     = str_replace("SET FOREIGN_KEY_CHECKS = 1;\n", "INSERT INTO `{$wpdb->posts}` (`no_such_column`) VALUES ('x');\n", $sql);
            $archive = $this->archive_from_sql($sql, $result['tables'], ['scope' => 'all'], [
                'wp-content/themes/new-theme/style.css' => 'new',
            ]);

            $tool = new Restore_Site_Backup($this->safety_producer($this->posts_tables()), new Restore_Files($content));
            $out  = $tool->handle(['path' => $archive, 'dry_run' => false, 'include_files' => true]);

            $this->assertFalse($out['restored']);
            $this->assertFalse($out['files']['restored']);
            $this->assertSame('live', file_get_contents($content . '/themes/live-theme/style.css'));
            $this->assertFileDoesNotExist($content . '/themes/new-theme/style.css');
        } finally {
            require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
            require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
            (new \WP_Filesystem_Direct(null))->delete($content, true);
        }
    }

    public function test_include_files_with_an_unsafe_entry_name_is_refused(): void
    {
        [$sql, $result] = $this->dump_tables($this->posts_tables());
        $archive = $this->archive_from_sql($sql, $result['tables'], ['scope' => 'all'], [
            'wp-content/../../evil.php' => '<?php',
        ]);

        $out = (new Restore_Site_Backup())->handle(['path' => $archive, 'include_files' => true]);

        $this->assertFalse($out['compatible']);
        $this->assertStringContainsString('outside wp-content', implode(' ', $out['refusals']));
    }
}
