<?php

namespace WPMCP\Tests\Pro\Packages;

use WPMCP\Governance\Governance_Audit_Log;
use WPMCP\Governance\Opt_In_Gates;
use WPMCP\Safety\File_Backup;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Packages\Install_Package_From_Zip;

/**
 * install-package-from-zip (issue #282): installs a plugin or theme from a
 * ZIP the site owner uploaded to the Media Library.
 *
 * Every refusal is checked for "nothing written": no package directory, no
 * snapshot row and no backup directory. The install paths run core's real
 * Plugin_Upgrader / Theme_Upgrader against a local package, and rollback is
 * exercised through Rollback_Service, the same entry rollback-operation uses.
 */
class InstallPackageFromZipTest extends \WP_UnitTestCase
{
    /** @var string[] absolute directories this test created and must remove. */
    private array $cleanup = [];

    private string $slug;

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        delete_option(Governance_Audit_Log::OPTION);
        add_filter('filesystem_method', [$this, 'direct']);

        // Random per test: the WordPress core under test is shared between
        // concurrent runs, so a fixed slug could collide with another run.
        $this->slug = 'wpmcp-ziptest-' . strtolower(wp_generate_password(8, false));

        $admin = self::factory()->user->create(['role' => 'administrator']);
        $user  = new \WP_User($admin);
        foreach (['install_plugins', 'update_plugins', 'install_themes', 'update_themes'] as $cap) {
            $user->add_cap($cap);
        }
        wp_set_current_user($admin);
    }

    protected function tearDown(): void
    {
        remove_filter('filesystem_method', [$this, 'direct']);
        remove_all_filters('wpmcp_enable_zip_install');
        foreach ([WP_PLUGIN_DIR . '/' . $this->slug, get_theme_root() . '/' . $this->slug] as $dir) {
            $this->cleanup[] = $dir;
        }
        foreach ($this->cleanup as $path) {
            $this->remove_path($path);
        }
        wp_clean_plugins_cache(false);
        wp_clean_themes_cache();
        wp_set_current_user(0);
        parent::tearDown();
    }

    public function direct(): string
    {
        return 'direct';
    }

    // ------------------------------------------------------------- gate

    public function test_gate_is_default_off_and_the_refusal_is_audited(): void
    {
        [$id, $hash] = $this->upload_zip($this->plugin_entries('1.0.0'));

        $this->assertFalse(Opt_In_Gates::is_open('wpmcp/install-package-from-zip'));
        $this->assertSame('wpmcp_enable_zip_install', Opt_In_Gates::filter_for('wpmcp/install-package-from-zip'));

        $this->assertRefusedWithNothingWritten(
            fn () => (new Install_Package_From_Zip())->handle($this->args($id, $hash)),
            'disabled'
        );

        $entry = Governance_Audit_Log::list(1)[0] ?? [];
        $this->assertSame('wpmcp/install-package-from-zip', $entry['ability'] ?? null);
        $this->assertFalse($entry['allowed']);
        $this->assertSame(Install_Package_From_Zip::REASON_GATE_CLOSED, $entry['reason']);
    }

    public function test_confirm_true_is_required(): void
    {
        $this->open_gate();
        [$id, $hash] = $this->upload_zip($this->plugin_entries('1.0.0'));

        foreach ([null, false, 'true', 1] as $confirm) {
            $args = $this->args($id, $hash);
            if (null === $confirm) {
                unset($args['confirm']);
            } else {
                $args['confirm'] = $confirm;
            }
            $this->assertRefusedWithNothingWritten(
                fn () => (new Install_Package_From_Zip())->handle($args),
                'confirm'
            );
        }
    }

    // ------------------------------------------------------- validation

    public function test_hash_mismatch_is_refused(): void
    {
        $this->open_gate();
        [$id] = $this->upload_zip($this->plugin_entries('1.0.0'));

        $this->assertRefusedWithNothingWritten(
            fn () => (new Install_Package_From_Zip())->handle($this->args($id, str_repeat('a', 64))),
            'SHA-256'
        );
        $this->assertSame(Install_Package_From_Zip::REASON_HASH_MISMATCH, Governance_Audit_Log::list(1)[0]['reason']);
    }

    public function test_malformed_hash_is_refused(): void
    {
        $this->open_gate();
        [$id] = $this->upload_zip($this->plugin_entries('1.0.0'));

        $this->assertRefusedWithNothingWritten(
            fn () => (new Install_Package_From_Zip())->handle($this->args($id, 'not-a-hash')),
            'sha256'
        );
    }

    public function test_path_traversal_is_refused(): void
    {
        $this->open_gate();
        foreach ([
            $this->slug . '/../../evil.php',
            $this->slug . '/sub/..\\..\\evil.php',
        ] as $bad) {
            $entries         = $this->plugin_entries('1.0.0');
            $entries[ $bad ] = '<?php // escaped';
            [$id, $hash]     = $this->upload_zip($entries);

            $this->assertRefusedWithNothingWritten(
                fn () => (new Install_Package_From_Zip())->handle($this->args($id, $hash)),
                'traversal'
            );
        }
        $this->assertFileDoesNotExist(WP_CONTENT_DIR . '/evil.php');
    }

    public function test_absolute_path_is_refused(): void
    {
        $this->open_gate();
        foreach (['/tmp/' . $this->slug . '/evil.php', 'C:/' . $this->slug . '/evil.php'] as $bad) {
            $entries         = $this->plugin_entries('1.0.0');
            $entries[ $bad ] = '<?php // absolute';
            [$id, $hash]     = $this->upload_zip($entries);

            $this->assertRefusedWithNothingWritten(
                fn () => (new Install_Package_From_Zip())->handle($this->args($id, $hash)),
                'absolute'
            );
        }
    }

    public function test_symlink_entry_is_refused(): void
    {
        $this->open_gate();
        [$id, $hash] = $this->upload_zip(
            $this->plugin_entries('1.0.0'),
            [$this->slug . '/link-to-config' => '../../wp-config.php']
        );

        $this->assertRefusedWithNothingWritten(
            fn () => (new Install_Package_From_Zip())->handle($this->args($id, $hash)),
            'symlink'
        );
        $this->assertSame(Install_Package_From_Zip::REASON_ARCHIVE_REJECTED . ':symlink', Governance_Audit_Log::list(1)[0]['reason']);
    }

    public function test_multiple_top_level_roots_are_refused(): void
    {
        $this->open_gate();

        $two_dirs                         = $this->plugin_entries('1.0.0');
        $two_dirs['other-root/other.php'] = '<?php';
        [$id, $hash]                      = $this->upload_zip($two_dirs);
        $this->assertRefusedWithNothingWritten(
            fn () => (new Install_Package_From_Zip())->handle($this->args($id, $hash)),
            'one top-level directory'
        );

        $loose_file               = $this->plugin_entries('1.0.0');
        $loose_file['loose.php'] = '<?php';
        [$id, $hash]              = $this->upload_zip($loose_file);
        $this->assertRefusedWithNothingWritten(
            fn () => (new Install_Package_From_Zip())->handle($this->args($id, $hash)),
            'one top-level directory'
        );
        $this->assertDirectoryDoesNotExist(WP_PLUGIN_DIR . '/other-root');
    }

    public function test_archive_without_a_plugin_header_is_refused(): void
    {
        $this->open_gate();
        [$id, $hash] = $this->upload_zip([
            $this->slug . '/readme.txt' => 'Plugin Name: not in a PHP file',
            $this->slug . '/lib.php'    => '<?php function helper() {}',
        ]);

        $this->assertRefusedWithNothingWritten(
            fn () => (new Install_Package_From_Zip())->handle($this->args($id, $hash)),
            'plugin header'
        );
    }

    public function test_archive_without_a_theme_stylesheet_header_is_refused(): void
    {
        $this->open_gate();
        [$id, $hash] = $this->upload_zip([
            $this->slug . '/style.css' => "/*\nDescription: no name here\n*/",
            $this->slug . '/index.php' => '<?php',
        ]);

        $this->assertRefusedWithNothingWritten(
            fn () => (new Install_Package_From_Zip())->handle($this->args($id, $hash, 'theme')),
            'Theme Name'
        );
    }

    public function test_a_plugin_archive_is_not_accepted_as_a_theme(): void
    {
        $this->open_gate();
        [$id, $hash] = $this->upload_zip($this->plugin_entries('1.0.0'));

        $this->assertRefusedWithNothingWritten(
            fn () => (new Install_Package_From_Zip())->handle($this->args($id, $hash, 'theme')),
            'Theme Name'
        );
    }

    public function test_protected_plugin_directory_is_refused(): void
    {
        $this->open_gate();
        [$id, $hash] = $this->upload_zip([
            'elementor/elementor.php' => "<?php\n/*\nPlugin Name: Fake Elementor\nVersion: 99.0.0\n*/",
        ]);

        $before = $this->snapshot_count();
        try {
            (new Install_Package_From_Zip())->handle($this->args($id, $hash));
            $this->fail('Expected the protected plugin directory to be refused.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('protected', $e->getMessage());
        }
        $this->assertSame($before, $this->snapshot_count());
    }

    public function test_non_zip_attachment_is_refused(): void
    {
        $this->open_gate();
        $post_id = self::factory()->post->create();

        $this->assertRefusedWithNothingWritten(
            fn () => (new Install_Package_From_Zip())->handle($this->args($post_id, str_repeat('a', 64))),
            'attachment'
        );
    }

    public function test_invalid_type_is_refused(): void
    {
        $this->open_gate();
        [$id, $hash] = $this->upload_zip($this->plugin_entries('1.0.0'));

        $this->assertRefusedWithNothingWritten(
            fn () => (new Install_Package_From_Zip())->handle($this->args($id, $hash, 'mu-plugin')),
            'type'
        );
    }

    public function test_theme_install_requires_install_themes(): void
    {
        $this->open_gate();
        $user = wp_get_current_user();
        $user->add_cap('install_themes', false);
        [$id, $hash] = $this->upload_zip($this->theme_entries('1.0.0'));

        $this->assertRefusedWithNothingWritten(
            fn () => (new Install_Package_From_Zip())->handle($this->args($id, $hash, 'theme')),
            'install_themes'
        );
    }

    public function test_replacing_an_installed_plugin_requires_update_plugins(): void
    {
        $this->open_gate();
        [$id, $hash] = $this->upload_zip($this->plugin_entries('1.0.0'));
        (new Install_Package_From_Zip())->handle($this->args($id, $hash));

        wp_get_current_user()->add_cap('update_plugins', false);
        [$id2, $hash2] = $this->upload_zip($this->plugin_entries('2.0.0'));

        $before = $this->snapshot_count();
        try {
            (new Install_Package_From_Zip())->handle($this->args($id2, $hash2));
            $this->fail('Expected a refusal without update_plugins.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('update_plugins', $e->getMessage());
        }
        $this->assertSame($before, $this->snapshot_count());
        $this->assertStringContainsString('Version: 1.0.0', (string) file_get_contents($this->main_file()));
    }

    // ------------------------------------------------ install / rollback

    public function test_install_then_rollback_removes_the_plugin(): void
    {
        $this->open_gate();
        [$id, $hash] = $this->upload_zip($this->plugin_entries('1.0.0'));

        $out = (new Install_Package_From_Zip())->handle($this->args($id, $hash));

        $this->assertTrue($out['installed']);
        $this->assertSame('plugin', $out['type']);
        $this->assertSame($this->slug, $out['slug']);
        $this->assertSame($this->slug . '/' . $this->slug . '.php', $out['plugin_file']);
        $this->assertFalse($out['replaced']);
        $this->assertNull($out['previous_version']);
        $this->assertSame('1.0.0', $out['version']);
        $this->assertTrue($out['recoverable']);
        $this->assertFileExists($this->main_file());
        $this->assertNotNull(Snapshot_Store::get_by_operation($out['operation_id']));
        $this->assertFalse(is_plugin_active($out['plugin_file']), 'Install must not activate.');

        $success = Governance_Audit_Log::list(1)[0];
        $this->assertTrue($success['allowed']);
        $this->assertSame(Install_Package_From_Zip::REASON_INSTALLED, $success['reason']);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));

        $this->assertDirectoryDoesNotExist(WP_PLUGIN_DIR . '/' . $this->slug);
        wp_clean_plugins_cache(false);
        $this->assertArrayNotHasKey($out['plugin_file'], get_plugins());
    }

    public function test_upgrade_then_rollback_restores_the_prior_version(): void
    {
        $this->open_gate();
        [$id1, $hash1] = $this->upload_zip($this->plugin_entries('1.0.0') + [
            $this->slug . '/includes/only-in-v1.php' => '<?php // v1 helper',
        ]);
        (new Install_Package_From_Zip())->handle($this->args($id1, $hash1));

        [$id2, $hash2] = $this->upload_zip($this->plugin_entries('2.0.0') + [
            $this->slug . '/includes/only-in-v2.php' => '<?php // v2 helper',
        ]);
        $out = (new Install_Package_From_Zip())->handle($this->args($id2, $hash2));

        $this->assertTrue($out['replaced']);
        $this->assertSame('1.0.0', $out['previous_version']);
        $this->assertSame('2.0.0', $out['version']);
        $this->assertStringContainsString('Version: 2.0.0', (string) file_get_contents($this->main_file()));
        $this->assertFileDoesNotExist(WP_PLUGIN_DIR . '/' . $this->slug . '/includes/only-in-v1.php');

        // The prior directory is held as one archive, never as loose PHP
        // under uploads where a web server could execute it.
        $backup_dir = File_Backup::operation_dir($out['operation_id']);
        $this->assertFileExists($backup_dir . '/' . File_Backup::PACKAGE_ARCHIVE);
        // index.php is the backup dir's own "silence is golden" guard.
        $php = array_diff(array_map('basename', glob($backup_dir . '/*.php') ?: []), ['index.php']);
        $this->assertSame([], array_values($php));

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));

        $this->assertStringContainsString('Version: 1.0.0', (string) file_get_contents($this->main_file()));
        $this->assertFileExists(WP_PLUGIN_DIR . '/' . $this->slug . '/includes/only-in-v1.php');
        $this->assertFileDoesNotExist(WP_PLUGIN_DIR . '/' . $this->slug . '/includes/only-in-v2.php');
    }

    public function test_theme_install_then_rollback_removes_the_theme(): void
    {
        $this->open_gate();
        [$id, $hash] = $this->upload_zip($this->theme_entries('1.0.0'));

        $out = (new Install_Package_From_Zip())->handle($this->args($id, $hash, 'theme'));

        $this->assertTrue($out['installed']);
        $this->assertSame('theme', $out['type']);
        $this->assertSame('1.0.0', $out['version']);
        $this->assertArrayNotHasKey('plugin_file', $out);
        $this->assertFileExists(get_theme_root() . '/' . $this->slug . '/style.css');

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));

        $this->assertDirectoryDoesNotExist(get_theme_root() . '/' . $this->slug);
    }

    public function test_theme_upgrade_then_rollback_restores_the_prior_version(): void
    {
        $this->open_gate();
        [$id1, $hash1] = $this->upload_zip($this->theme_entries('1.0.0'));
        (new Install_Package_From_Zip())->handle($this->args($id1, $hash1, 'theme'));

        [$id2, $hash2] = $this->upload_zip($this->theme_entries('2.0.0'));
        $out = (new Install_Package_From_Zip())->handle($this->args($id2, $hash2, 'theme'));
        $this->assertSame('1.0.0', $out['previous_version']);
        $this->assertSame('2.0.0', $out['version']);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));

        $this->assertStringContainsString('Version: 1.0.0', (string) file_get_contents(get_theme_root() . '/' . $this->slug . '/style.css'));
    }

    public function test_rollback_leaves_an_active_fresh_plugin_in_place_with_a_warning(): void
    {
        $this->open_gate();
        [$id, $hash] = $this->upload_zip($this->plugin_entries('1.0.0'));
        $out = (new Install_Package_From_Zip())->handle($this->args($id, $hash));

        update_option('active_plugins', array_merge((array) get_option('active_plugins', []), [$out['plugin_file']]));

        Rollback_Service::restore_operation($out['operation_id']);
        $warnings = Rollback_Service::take_warnings();

        $this->assertFileExists($this->main_file());
        $this->assertNotEmpty($warnings);
        $this->assertStringContainsString('active', implode(' ', $warnings));
    }

    public function test_package_install_is_a_restorable_object_type(): void
    {
        $this->assertContains('package_install', Rollback_Service::restorable_object_types());
    }

    // ---------------------------------------------------------- helpers

    private function open_gate(): void
    {
        add_filter('wpmcp_enable_zip_install', '__return_true');
    }

    private function args(int $id, string $hash, string $type = 'plugin'): array
    {
        return ['attachment_id' => $id, 'sha256' => $hash, 'type' => $type, 'confirm' => true];
    }

    private function main_file(): string
    {
        return WP_PLUGIN_DIR . '/' . $this->slug . '/' . $this->slug . '.php';
    }

    /** @return array<string, string> */
    private function plugin_entries(string $version): array
    {
        return [
            $this->slug . '/' . $this->slug . '.php' => "<?php\n/**\n * Plugin Name: Zip Test Plugin\n * Version: {$version}\n */\n",
            $this->slug . '/readme.txt'               => "=== Zip Test Plugin ===\nStable tag: {$version}\n",
        ];
    }

    /** @return array<string, string> */
    private function theme_entries(string $version): array
    {
        return [
            $this->slug . '/style.css' => "/*\nTheme Name: Zip Test Theme\nVersion: {$version}\n*/\n",
            $this->slug . '/index.php' => "<?php\n// Silence.\n",
        ];
    }

    /**
     * Build a zip from name => contents (plus optional unix symlink entries,
     * name => target), copy it into uploads and attach it.
     *
     * @return array{0: int, 1: string} attachment id and the file's sha256
     */
    private function upload_zip(array $entries, array $symlinks = []): array
    {
        $uploads = wp_upload_dir();
        wp_mkdir_p($uploads['path']);
        $path = $uploads['path'] . '/' . wp_unique_filename($uploads['path'], $this->slug . '.zip');

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        foreach ($entries as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        foreach ($symlinks as $name => $target) {
            $zip->addFromString($name, $target);
            $zip->setExternalAttributesName($name, \ZipArchive::OPSYS_UNIX, (0120777 << 16));
        }
        $zip->close();
        $this->cleanup[] = $path;

        $id = self::factory()->attachment->create_object(
            $path,
            0,
            ['post_mime_type' => 'application/zip', 'post_title' => $this->slug]
        );

        return [(int) $id, hash_file('sha256', $path)];
    }

    private function snapshot_count(): int
    {
        return Snapshot_Store::row_count();
    }

    private function assertRefusedWithNothingWritten(callable $call, string $message_fragment): void
    {
        $before       = $this->snapshot_count();
        $backup_root  = dirname(File_Backup::operation_dir('x'));
        $backups_then = is_dir($backup_root) ? count(scandir($backup_root)) : 0;

        try {
            $call();
            $this->fail('Expected a refusal mentioning "' . $message_fragment . '".');
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            $this->assertStringContainsStringIgnoringCase($message_fragment, $e->getMessage());
        }

        $this->assertDirectoryDoesNotExist(WP_PLUGIN_DIR . '/' . $this->slug);
        $this->assertDirectoryDoesNotExist(get_theme_root() . '/' . $this->slug);
        $this->assertSame($before, $this->snapshot_count(), 'A refusal must not record a snapshot.');
        $this->assertSame($backups_then, is_dir($backup_root) ? count(scandir($backup_root)) : 0, 'A refusal must not leave a backup.');
    }

    private function remove_path(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (! is_dir($path)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() && ! $item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($path);
    }
}
