<?php

namespace WPMCP\Tests\Free\Packages;

use WPMCP\MCP\Confirmation_Required;
use WPMCP\MCP\Opt_In_Required;
use WPMCP\Safety\File_Backup;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Packages\Manage_Updates;
use WPMCP\Tools\Packages\Safe_Package_Update;
use WPMCP\Tools\Packages\Update_Health_Check;

/**
 * Safe plugin and theme updates through manage-updates (issue #843):
 * type=plugins|themes with action=plan|run|status|restore.
 *
 * No test downloads anything. The upgrader is an injected stand-in that
 * replaces the stub plugin's folder the way Plugin_Upgrader would (and,
 * like it, deactivates the plugin and runs the new version's "migration"
 * on the stub's options and table), and the site check loads the installed
 * plugin file in a separate PHP process, so a version that fatals on load
 * really fails the check.
 */
class SafePackageUpdateTest extends \WP_UnitTestCase
{
    private const SLUG = 'stub-safe-update';
    private const FILE = 'stub-safe-update/stub-safe-update.php';

    private string $dir = '';
    private string $table = '';
    private string $extra_table = '';

    /** The body of the "new version" the stand-in upgrader installs. */
    private string $next_body = '';

    /** @var string[] */
    private array $probed = [];

    protected function setUp(): void
    {
        parent::setUp();
        global $wpdb;

        Snapshot_Store::install();
        delete_option(Safe_Package_Update::RUNS_OPTION);
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        wpmcp_test_grant_network_admin(get_current_user_id());

        $this->dir = WP_PLUGIN_DIR . '/' . self::SLUG;
        $this->remove_dir($this->dir);
        wp_mkdir_p($this->dir);
        file_put_contents($this->dir . '/stub-safe-update.php', $this->plugin_source('1.0', 'function stub_safe_update_ok() { return true; }'));
        file_put_contents($this->dir . '/readme.txt', "old readme\n");
        wp_clean_plugins_cache(false);

        update_option('active_plugins', array_values(array_unique(array_merge((array) get_option('active_plugins', []), [self::FILE]))));
        update_option('stub_safe_update_db', '1');
        delete_option('stub_safe_update_added');

        // The stub's own table, named after its folder, which the snapshot
        // must cover. A real table: the dump and import need SHOW TABLES.
        remove_filter('query', [$this, '_create_temporary_tables']);
        remove_filter('query', [$this, '_drop_temporary_tables']);
        $this->table       = $wpdb->prefix . 'stub_safe_update_log';
        $this->extra_table = $wpdb->prefix . 'stub_safe_update_extra';
        $wpdb->query("DROP TABLE IF EXISTS {$this->table}");
        $wpdb->query("DROP TABLE IF EXISTS {$this->extra_table}");
        $wpdb->query("CREATE TABLE {$this->table} (id INT NOT NULL PRIMARY KEY, note VARCHAR(40) NOT NULL) {$wpdb->get_charset_collate()}");
        $wpdb->query("INSERT INTO {$this->table} (id, note) VALUES (1, 'before')");

        set_site_transient('update_plugins', (object) [
            'response' => [
                self::FILE => (object) ['slug' => self::SLUG, 'plugin' => self::FILE, 'new_version' => '2.0', 'package' => 'https://example.invalid/stub.zip'],
            ],
        ]);

        $this->next_body = 'function stub_safe_update_ok() { return true; }';
        $this->probed    = [];
    }

    protected function tearDown(): void
    {
        global $wpdb;

        Safe_Package_Update::set_clock_for_tests(null);
        foreach ((array) get_option(Safe_Package_Update::RUNS_OPTION, []) as $run) {
            foreach ((array) ($run['packages'] ?? []) as $package) {
                if (! empty($package['operation_id'])) {
                    File_Backup::delete_backup_dir((string) $package['operation_id']);
                }
            }
        }
        delete_option(Safe_Package_Update::RUNS_OPTION);
        delete_option('stub_safe_update_db');
        delete_option('stub_safe_update_added');
        update_option('active_plugins', array_values(array_diff((array) get_option('active_plugins', []), [self::FILE])));
        delete_site_transient('update_plugins');
        $this->remove_dir($this->dir);
        $wpdb->query("DROP TABLE IF EXISTS {$this->table}");
        $wpdb->query("DROP TABLE IF EXISTS {$this->extra_table}");
        add_filter('query', [$this, '_create_temporary_tables']);
        add_filter('query', [$this, '_drop_temporary_tables']);
        wp_clean_plugins_cache(false);
        wp_set_current_user(0);
        parent::tearDown();
    }

    private function plugin_source(string $version, string $body): string
    {
        return "<?php\n/**\n * Plugin Name: Stub Safe Update\n * Version: {$version}\n */\n{$body}\n";
    }

    private function remove_dir(string $dir): void
    {
        if (! is_dir($dir)) {
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

    /** Replaces the folder like Plugin_Upgrader, deactivates, and migrates. */
    private function upgrader(): callable
    {
        return function (string $kind, string $item) {
            global $wpdb;
            $this->assertSame('plugin', $kind);
            $this->assertSame(self::FILE, $item);
            update_option('active_plugins', array_values(array_diff((array) get_option('active_plugins', []), [self::FILE])));
            $this->remove_dir($this->dir);
            wp_mkdir_p($this->dir);
            file_put_contents($this->dir . '/stub-safe-update.php', $this->plugin_source('2.0', $this->next_body));
            file_put_contents($this->dir . '/new-file.php', "<?php\n");
            update_option('stub_safe_update_db', '2');
            add_option('stub_safe_update_added', 'yes');
            $wpdb->query("UPDATE {$this->table} SET note = 'after' WHERE id = 1");
            $wpdb->query("CREATE TABLE {$this->extra_table} (id INT NOT NULL) {$wpdb->get_charset_collate()}");
            return true;
        };
    }

    /** A site check that loads the installed plugin file in its own PHP process. */
    private function probe(): callable
    {
        return function (string $url): array {
            $this->probed[] = $url;
            $file           = $this->dir . '/stub-safe-update.php';
            $command        = escapeshellarg(PHP_BINARY) . ' -n -r ' . escapeshellarg('require $argv[1];') . ' ' . escapeshellarg($file) . ' 2>&1';
            $output         = [];
            $code           = 0;
            exec($command, $output, $code);
            return 0 === $code
                ? ['url' => $url, 'ok' => true, 'status' => 200]
                : ['url' => $url, 'ok' => false, 'status' => 500, 'reason' => 'PHP fatal error'];
        };
    }

    private function tool(): Manage_Updates
    {
        return new Manage_Updates(null, null, new Safe_Package_Update($this->upgrader(), $this->probe()));
    }

    private function run_update(array $extra = []): array
    {
        add_filter(Safe_Package_Update::OPT_IN_FILTER, '__return_true');
        return $this->tool()->handle(['type' => 'plugins', 'action' => 'run', 'items' => [self::FILE], 'confirm' => true] + $extra);
    }

    public function test_plan_lists_the_pending_update_and_what_the_snapshot_covers(): void
    {
        $out = $this->tool()->handle(['type' => 'plugins', 'action' => 'plan']);

        $this->assertFalse($out['enabled']);
        $rows = array_column($out['packages'], null, 'item');
        $this->assertArrayHasKey(self::FILE, $rows);
        $row = $rows[ self::FILE ];
        $this->assertSame('1.0', $row['version']);
        $this->assertSame('2.0', $row['new_version']);
        $this->assertTrue($row['snapshot']['possible'], wp_json_encode($row));
        $this->assertContains($this->table, $row['snapshot']['tables']);
        $this->assertGreaterThan(0, $row['size_bytes']);
    }

    public function test_plan_marks_a_single_file_plugin_as_not_snapshottable(): void
    {
        $transient = get_site_transient('update_plugins');
        $transient->response['hello.php'] = (object) ['slug' => 'hello-dolly', 'new_version' => '9.9'];
        set_site_transient('update_plugins', $transient);
        file_put_contents(WP_PLUGIN_DIR . '/hello.php', "<?php\n/**\n * Plugin Name: Hello Stub\n * Version: 1.0\n */\n");
        wp_clean_plugins_cache(false);

        try {
            $out  = $this->tool()->handle(['type' => 'plugins', 'action' => 'plan']);
            $rows = array_column($out['packages'], null, 'item');
            $this->assertFalse($rows['hello.php']['snapshot']['possible']);
            $this->assertStringContainsString('folder', $rows['hello.php']['snapshot']['reason']);
        } finally {
            wp_delete_file(WP_PLUGIN_DIR . '/hello.php');
        }
    }

    public function test_run_is_off_until_the_site_opts_in(): void
    {
        try {
            $this->tool()->handle(['type' => 'plugins', 'action' => 'run', 'items' => [self::FILE], 'confirm' => true]);
            $this->fail('Expected the opt-in refusal.');
        } catch (Opt_In_Required $e) {
            $this->assertStringContainsString(Safe_Package_Update::OPT_IN_FILTER, $e->getMessage());
        }
        $this->assertStringContainsString('Version: 1.0', (string) file_get_contents($this->dir . '/stub-safe-update.php'));
    }

    public function test_run_needs_confirm(): void
    {
        add_filter(Safe_Package_Update::OPT_IN_FILTER, '__return_true');

        $this->expectException(Confirmation_Required::class);
        $this->tool()->handle(['type' => 'plugins', 'action' => 'run', 'items' => [self::FILE]]);
    }

    public function test_run_refuses_when_file_changes_are_not_allowed(): void
    {
        add_filter('file_mod_allowed', '__return_false');

        try {
            $this->run_update();
            $this->fail('Expected a refusal.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('DISALLOW_FILE_MODS', $e->getMessage());
        } finally {
            remove_filter('file_mod_allowed', '__return_false');
        }
        $this->assertStringContainsString('Version: 1.0', (string) file_get_contents($this->dir . '/stub-safe-update.php'));
    }

    public function test_an_update_that_fatals_on_load_is_rolled_back_with_its_files_options_and_tables(): void
    {
        global $wpdb;
        $this->next_body = 'stub_safe_update_missing_function();';

        $out = $this->run_update();

        $this->assertSame('done', $out['status']);
        $package = $out['packages'][0];
        $this->assertSame(self::FILE, $package['item']);
        $this->assertSame('rolled_back', $package['status'], wp_json_encode($package));
        $this->assertSame('check', $package['failed_step']);
        $this->assertStringContainsString(self::FILE, $package['message']);
        $this->assertNotEmpty($package['checks']);
        $this->assertFalse($package['checks'][0]['ok']);

        $source = (string) file_get_contents($this->dir . '/stub-safe-update.php');
        $this->assertStringContainsString('Version: 1.0', $source);
        $this->assertFileDoesNotExist($this->dir . '/new-file.php');
        $this->assertSame("old readme\n", file_get_contents($this->dir . '/readme.txt'));

        $this->assertSame('1', get_option('stub_safe_update_db'));
        $this->assertFalse(get_option('stub_safe_update_added'));
        $this->assertContains(self::FILE, (array) get_option('active_plugins'));

        $this->assertSame('before', $wpdb->get_var("SELECT note FROM {$this->table} WHERE id = 1"));
        $this->assertNull($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $this->extra_table)));

        $status = $this->tool()->handle(['type' => 'plugins', 'action' => 'status', 'run_id' => $out['run_id']]);
        $this->assertSame('rolled_back', $status['packages'][0]['status']);
    }

    public function test_a_good_update_is_kept_reported_and_its_snapshot_removed_after_the_retention_window(): void
    {
        $out     = $this->run_update();
        $package = $out['packages'][0];

        $this->assertSame('updated', $package['status'], wp_json_encode($package));
        $this->assertSame('1.0', $package['from_version']);
        $this->assertSame('2.0', $package['to_version']);
        $this->assertNotEmpty($package['operation_id']);
        $this->assertTrue($package['restorable']);
        $this->assertStringContainsString('Version: 2.0', (string) file_get_contents($this->dir . '/stub-safe-update.php'));
        $this->assertContains(self::FILE, (array) get_option('active_plugins'), 'the plugin is active again after the update');
        $this->assertTrue(File_Backup::has_directory_backup($package['operation_id']));
        $this->assertNotEmpty($this->probed);

        Safe_Package_Update::set_clock_for_tests(time() + Safe_Package_Update::DEFAULT_RETENTION + 60);
        $status = $this->tool()->handle(['type' => 'plugins', 'action' => 'status', 'run_id' => $out['run_id']]);

        $this->assertFalse(File_Backup::has_directory_backup($package['operation_id']));
        $this->assertDirectoryDoesNotExist(File_Backup::operation_dir($package['operation_id']));
        $this->assertFalse($status['packages'][0]['restorable']);
        $this->assertSame('updated', $status['packages'][0]['status']);
    }

    public function test_restore_puts_a_finished_update_back_while_its_snapshot_is_kept(): void
    {
        global $wpdb;
        $out = $this->run_update();
        $this->assertSame('updated', $out['packages'][0]['status']);

        $restored = $this->tool()->handle(['type' => 'plugins', 'action' => 'restore', 'run_id' => $out['run_id'], 'items' => [self::FILE], 'confirm' => true]);

        $this->assertSame('restored', $restored['packages'][0]['status'], wp_json_encode($restored));
        $this->assertStringContainsString('Version: 1.0', (string) file_get_contents($this->dir . '/stub-safe-update.php'));
        $this->assertFileDoesNotExist($this->dir . '/new-file.php');
        $this->assertSame('1', get_option('stub_safe_update_db'));
        $this->assertFalse(get_option('stub_safe_update_added'));
        $this->assertSame('before', $wpdb->get_var("SELECT note FROM {$this->table} WHERE id = 1"));
        $this->assertContains(self::FILE, (array) get_option('active_plugins'));

        try {
            $this->tool()->handle(['type' => 'plugins', 'action' => 'restore', 'run_id' => $out['run_id'], 'items' => [self::FILE], 'confirm' => true]);
            $this->fail('A restored package cannot be restored twice.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('restored', $e->getMessage());
        }
    }

    public function test_restore_needs_the_opt_in_and_confirm(): void
    {
        $out = $this->run_update();
        remove_all_filters(Safe_Package_Update::OPT_IN_FILTER);

        try {
            $this->tool()->handle(['type' => 'plugins', 'action' => 'restore', 'run_id' => $out['run_id'], 'items' => [self::FILE], 'confirm' => true]);
            $this->fail('Expected the opt-in refusal.');
        } catch (Opt_In_Required $e) {
            $this->assertStringContainsString(Safe_Package_Update::OPT_IN_FILTER, $e->getMessage());
        }

        add_filter(Safe_Package_Update::OPT_IN_FILTER, '__return_true');
        $this->expectException(Confirmation_Required::class);
        $this->tool()->handle(['type' => 'plugins', 'action' => 'restore', 'run_id' => $out['run_id'], 'items' => [self::FILE]]);
    }

    public function test_status_without_a_run_id_lists_recent_runs(): void
    {
        $out    = $this->run_update();
        $status = $this->tool()->handle(['type' => 'plugins', 'action' => 'status']);

        $this->assertSame($out['run_id'], $status['runs'][0]['run_id']);
        $this->assertSame(['updated' => 1], $status['runs'][0]['counts']);
    }

    public function test_the_protected_plugin_is_never_updated(): void
    {
        add_filter(Safe_Package_Update::OPT_IN_FILTER, '__return_true');
        $out = $this->tool()->handle(['type' => 'plugins', 'action' => 'run', 'items' => ['wpmcp/wpmcp.php'], 'confirm' => true]);

        $this->assertSame('skipped', $out['packages'][0]['status']);
    }

    public function test_the_site_check_fails_on_server_errors_and_fatal_pages_and_stays_on_this_site(): void
    {
        $responses = [
            'https://example.org/?a=1'  => ['code' => 200, 'body' => '<html>fine</html>'],
            'https://example.org/?a=2'  => ['code' => 500, 'body' => ''],
            'https://example.org/?a=3'  => ['code' => 200, 'body' => '<p>There has been a critical error on this website.</p>'],
        ];
        $filter = static function ($pre, $args, $url) use ($responses) {
            foreach ($responses as $prefix => $response) {
                if (0 === strpos($url, $prefix)) {
                    return ['headers' => [], 'body' => $response['body'], 'response' => ['code' => $response['code'], 'message' => ''], 'cookies' => [], 'filename' => null];
                }
            }
            return new \WP_Error('unexpected', $url);
        };
        add_filter('pre_http_request', $filter, 10, 3);
        add_filter('home_url', static fn () => 'https://example.org/');

        try {
            $check = new Update_Health_Check();
            $this->assertTrue($check->probe('https://example.org/?a=1')['ok']);
            $this->assertFalse($check->probe('https://example.org/?a=2')['ok']);
            $this->assertFalse($check->probe('https://example.org/?a=3')['ok']);
            $this->assertFalse($check->probe('https://elsewhere.example/')['ok']);
            $this->assertNotEmpty($check->urls());
        } finally {
            remove_filter('pre_http_request', $filter, 10);
            remove_all_filters('home_url');
        }
    }
}
