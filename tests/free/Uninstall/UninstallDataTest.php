<?php

namespace WPMCP\Tests\Free\Uninstall;

use WPMCP\Admin\Connection_Page;
use WPMCP\Data_Registry;
use WPMCP\Uninstaller;

/**
 * Opt-in removal of every WP MCP store when the plugin is deleted (issue
 * #480).
 *
 * Off by default, so deleting the plugin keeps its data as #468 decided.
 * When the setting (or WPMCP_UNINSTALL_DELETE_DATA, which overrides it) is
 * on, uninstall drops the plugin's tables and deletes its options,
 * transients, user meta, site meta, Application Passwords it issued, and
 * its own directories under uploads and wp-content, on every site of a
 * network. Directory removal never leaves the registered directory: a
 * symlink is removed as a link and its target is left alone.
 *
 * Uploads and wp-content are redirected to a private temporary directory
 * for every test: the WordPress install under test is shared with other
 * checkouts running the suite at the same time, and their backup
 * directories must not disappear under them.
 */
class UninstallDataTest extends \WP_UnitTestCase
{
    private string $root;
    private string $uploads;
    private string $content;
    private string $outside;
    private int $user_id;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root    = rtrim(get_temp_dir(), '/') . '/wpmcp-uninstall-' . wp_generate_password(8, false, false);
        $this->uploads = $this->root . '/uploads';
        $this->content = $this->root . '/content';
        $this->outside = $this->root . '/outside';
        foreach ([ $this->uploads, $this->content, $this->outside ] as $dir) {
            wp_mkdir_p($dir);
        }
        add_filter('upload_dir', [ $this, 'redirect_uploads' ]);
        Uninstaller::set_content_dir_for_tests($this->content);

        $this->user_id = self::factory()->user->create([ 'role' => 'administrator' ]);
        delete_site_option(Data_Registry::SETTING);
        Data_Registry::reset_for_tests();
    }

    protected function tearDown(): void
    {
        remove_filter('upload_dir', [ $this, 'redirect_uploads' ]);
        Uninstaller::set_content_dir_for_tests(null);
        Data_Registry::reset_for_tests();
        delete_site_option(Data_Registry::SETTING);
        remove_all_filters('query', 100);
        $this->remove_tree($this->root);
        parent::tearDown();
    }

    public function redirect_uploads(array $dirs): array
    {
        $dirs['basedir'] = $this->site_uploads();
        $dirs['path']    = $dirs['basedir'];
        return $dirs;
    }

    /** Each site of a network gets its own uploads directory, as in core. */
    private function site_uploads(): string
    {
        return is_main_site() ? $this->uploads : $this->uploads . '/sites/' . get_current_blog_id();
    }

    // ------------------------------------------------------------ setting

    public function test_the_setting_is_off_by_default(): void
    {
        $this->assertFalse(Data_Registry::delete_on_uninstall());
        $this->assertFalse(Data_Registry::is_forced());
    }

    public function test_the_stored_setting_turns_it_on(): void
    {
        Data_Registry::set_delete_on_uninstall(true);
        $this->assertTrue(Data_Registry::delete_on_uninstall());

        Data_Registry::set_delete_on_uninstall(false);
        $this->assertFalse(Data_Registry::delete_on_uninstall());
    }

    public function test_the_constant_overrides_the_stored_setting_both_ways(): void
    {
        Data_Registry::set_delete_on_uninstall(false);
        Data_Registry::set_constant_for_tests(true, true);
        $this->assertTrue(Data_Registry::delete_on_uninstall());
        $this->assertTrue(Data_Registry::is_forced());

        Data_Registry::set_delete_on_uninstall(true);
        Data_Registry::set_constant_for_tests(true, false);
        $this->assertFalse(Data_Registry::delete_on_uninstall());
    }

    public function test_constant_values_read_as_booleans(): void
    {
        foreach ([ [ true, true ], [ 1, true ], [ '1', true ], [ 'true', true ], [ 'yes', true ], [ false, false ], [ 0, false ], [ '0', false ], [ 'false', false ], [ '', false ], [ [ 1 ], false ] ] as [ $value, $expected ]) {
            Data_Registry::set_constant_for_tests(true, $value);
            $this->assertSame($expected, Data_Registry::delete_on_uninstall(), var_export($value, true));
        }
    }

    public function test_the_setting_is_one_network_wide_value(): void
    {
        Data_Registry::set_delete_on_uninstall(true);

        $this->assertTrue((bool) get_site_option(Data_Registry::SETTING));
    }

    // ------------------------------------------------------------ off

    public function test_with_the_setting_off_uninstall_keeps_everything(): void
    {
        $this->seed();
        $queries = $this->capture_queries();

        Uninstaller::uninstall();

        $this->assertSame('kept', get_option('wpmcp_test_store'));
        $this->assertSame('kept', get_transient('wpmcp_test_transient'));
        $this->assertSame('kept', get_user_meta($this->user_id, '_wpmcp_test_meta', true));
        $this->assertFileExists($this->uploads . '/.wpmcp-backups/op-1/file.txt');
        $this->assertFileExists($this->uploads . '/wpmcp-site-backups/archive.zip');
        $this->assertFileExists($this->content . '/wpmcp-restore/staged.txt');
        $this->assertSame([], preg_grep('/DROP/i', $queries->list));
    }

    // ------------------------------------------------------------ on

    public function test_with_the_setting_on_uninstall_removes_every_store(): void
    {
        $this->seed();
        Data_Registry::set_delete_on_uninstall(true);
        $queries = $this->capture_queries();

        Uninstaller::uninstall();

        $this->assertSame([], $this->owned_options(), 'No wpmcp option or transient may survive.');
        $this->assertFalse(get_transient('wpmcp_test_transient'));
        $this->assertFalse(get_site_transient('wpmcp_test_site_transient'));
        $this->assertFalse(get_site_option(Data_Registry::SETTING, false), 'The setting itself goes too.');
        $this->assertSame([], $this->owned_user_meta(), 'No wpmcp user meta may survive.');

        global $wpdb;
        foreach (Data_Registry::TABLES as $table) {
            $this->assertNotEmpty(
                preg_grep('/^DROP (TEMPORARY )?TABLE IF EXISTS `' . preg_quote($wpdb->prefix . $table, '/') . '`$/', $queries->list),
                "Expected $table to be dropped."
            );
        }

        foreach (Data_Registry::UPLOADS_DIRS as $dir) {
            $this->assertFileDoesNotExist($this->uploads . '/' . $dir);
        }
        foreach (Data_Registry::CONTENT_DIRS as $dir) {
            $this->assertFileDoesNotExist($this->content . '/' . $dir);
        }
    }

    public function test_uninstall_leaves_everything_else_alone(): void
    {
        $this->seed();
        Data_Registry::set_delete_on_uninstall(true);

        Uninstaller::uninstall();

        $this->assertSame('theirs', get_option('other_plugin_store'));
        $this->assertSame('theirs', get_option('xwpmcp_lookalike'));
        $this->assertSame('theirs', get_user_meta($this->user_id, 'other_meta', true));
        $this->assertNotEmpty(get_option('siteurl'));
        $this->assertFileExists($this->uploads . '/2026/10/photo.jpg');
        $this->assertFileExists($this->uploads . '/wpmcp-not-registered/file.txt');
        $this->assertFileExists($this->content . '/wpmcp-skills/mine.md', 'Hand-written skills are the owner\'s.');
    }

    public function test_application_passwords_issued_by_wp_mcp_are_revoked(): void
    {
        add_filter('wp_is_application_passwords_available', '__return_true');
        wp_set_current_user($this->user_id);
        $issued = (new Connection_Page())->handle_request([
            'wpmcp_connection_action' => 'provision',
            '_wpnonce'                => wp_create_nonce(Connection_Page::NONCE_ACTION),
            'user_id'                 => $this->user_id,
            'name'                    => 'Agent',
        ]);
        [ , $own ] = \WP_Application_Passwords::create_new_application_password($this->user_id, [ 'name' => 'Not from WP MCP' ]);
        remove_filter('wp_is_application_passwords_available', '__return_true');
        $this->assertArrayHasKey('uuid', $issued);

        Data_Registry::set_delete_on_uninstall(true);
        Uninstaller::uninstall();

        $this->assertNull(\WP_Application_Passwords::get_user_application_password($this->user_id, $issued['uuid']));
        $this->assertNotNull(\WP_Application_Passwords::get_user_application_password($this->user_id, $own['uuid']), 'Passwords the owner created elsewhere stay.');
    }

    // ------------------------------------------------------------ directory confinement

    public function test_a_symlinked_registered_directory_is_unlinked_not_followed(): void
    {
        file_put_contents($this->outside . '/precious.txt', 'x');
        symlink($this->outside, $this->uploads . '/wpmcp-exports');

        Data_Registry::set_delete_on_uninstall(true);
        Uninstaller::uninstall();

        $this->assertFalse(is_link($this->uploads . '/wpmcp-exports'));
        $this->assertFileExists($this->outside . '/precious.txt');
    }

    public function test_symlinks_inside_a_registered_directory_are_unlinked_not_followed(): void
    {
        wp_mkdir_p($this->uploads . '/.wpmcp-backups/op-1');
        file_put_contents($this->outside . '/precious.txt', 'x');
        symlink($this->outside, $this->uploads . '/.wpmcp-backups/op-1/escape-dir');
        symlink($this->outside . '/precious.txt', $this->uploads . '/.wpmcp-backups/escape-file');

        Data_Registry::set_delete_on_uninstall(true);
        Uninstaller::uninstall();

        $this->assertFileDoesNotExist($this->uploads . '/.wpmcp-backups');
        $this->assertFileExists($this->outside . '/precious.txt');
    }

    public function test_directory_removal_refuses_names_outside_the_registry(): void
    {
        wp_mkdir_p($this->uploads . '/keep');
        file_put_contents($this->uploads . '/keep/file.txt', 'x');

        foreach ([ 'keep', '../outside', '.', '..', '', 'keep/..', '/etc' ] as $name) {
            $this->assertFalse(Uninstaller::delete_owned_dir($this->uploads, $name), $name);
        }

        $this->assertFileExists($this->uploads . '/keep/file.txt');
        $this->assertDirectoryExists($this->outside);
    }

    public function test_directory_removal_refuses_a_base_that_does_not_resolve(): void
    {
        $this->assertFalse(Uninstaller::delete_owned_dir($this->root . '/missing', '.wpmcp-backups'));
    }

    // ------------------------------------------------------------ multisite

    public function test_every_site_of_a_network_is_cleaned(): void
    {
        if (! is_multisite()) {
            $this->markTestSkipped('Runs under WP_MULTISITE=1.');
        }

        $site = self::factory()->blog->create();
        $this->seed();
        switch_to_blog($site);
        $this->seed();
        restore_current_blog();
        update_site_option('wpmcp_test_network_store', 'kept');
        set_site_transient('wpmcp_test_network_transient', 'kept');

        Data_Registry::set_delete_on_uninstall(true);
        Uninstaller::uninstall();

        foreach ([ get_main_site_id(), $site ] as $id) {
            switch_to_blog($id);
            $this->assertSame([], $this->owned_options(), "Site $id kept wpmcp options.");
            foreach (Data_Registry::UPLOADS_DIRS as $dir) {
                $this->assertFileDoesNotExist($this->site_uploads() . '/' . $dir, "Site $id kept $dir.");
            }
            $this->assertSame('theirs', get_option('other_plugin_store'));
            restore_current_blog();
        }

        global $wpdb;
        $left = $wpdb->get_col($wpdb->prepare("SELECT meta_key FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s OR meta_key LIKE %s", 'wpmcp\_%', '\_site\_transient\_%wpmcp\_%'));
        $this->assertSame([], $left, 'No wpmcp network option may survive.');
    }

    // ------------------------------------------------------------ uninstall.php

    public function test_uninstall_php_loads_the_registry_without_an_autoloader(): void
    {
        $root = dirname(__DIR__, 3);
        $file = (string) file_get_contents($root . '/uninstall.php');

        $this->assertStringContainsString("require_once __DIR__ . '/src/Data_Registry.php';", $file);

        // Uninstaller and Data_Registry run from uninstall.php with no
        // autoloader, so they may only reach the classes it requires.
        foreach ([ 'src/Uninstaller.php', 'src/Data_Registry.php' ] as $relative) {
            preg_match_all('/\b(?:new\s+\\\\?|\\\\?)(WPMCP\\\\[A-Za-z_\\\\]+|[A-Z][A-Za-z]+_[A-Za-z_]+)::|\bnew\s+\\\\?(WPMCP\\\\[A-Za-z_\\\\]+)/', (string) file_get_contents("$root/$relative"), $m);
            $used = array_filter(array_merge($m[1], $m[2]));
            foreach ($used as $class) {
                $this->assertContains(ltrim(str_replace('WPMCP\\', '', $class), '\\'), [ 'Cron_Registry', 'Data_Registry', 'Uninstaller', 'WP_Application_Passwords', 'WP_Filesystem_Direct' ], "$relative uses $class");
            }
        }
    }

    // ------------------------------------------------------------ helpers

    private function seed(): void
    {
        update_option('wpmcp_test_store', 'kept');
        update_option('wpmcp_oauth_tokens', [ 'x' => [ 'client_id' => 'c' ] ]);
        set_transient('wpmcp_test_transient', 'kept', HOUR_IN_SECONDS);
        set_site_transient('wpmcp_test_site_transient', 'kept', HOUR_IN_SECONDS);
        update_option('other_plugin_store', 'theirs');
        update_option('xwpmcp_lookalike', 'theirs');
        update_user_meta($this->user_id, '_wpmcp_test_meta', 'kept');
        update_user_meta($this->user_id, 'wpmcp_dismissed_announcements', [ 'a' ]);
        update_user_meta($this->user_id, 'other_meta', 'theirs');

        $uploads = $this->site_uploads();
        $files   = [
            $uploads . '/.wpmcp-backups/op-1/file.txt',
            $uploads . '/wpmcp-site-backups/archive.zip',
            $uploads . '/wpmcp-site-backups/incoming/upload.part',
            $uploads . '/wpmcp-exports/export.xml',
            $uploads . '/wpmcp-mirror/post-1.json',
            $uploads . '/wpmcp-fs-backups/file.bak',
            $uploads . '/wpmcp-not-registered/file.txt',
            $uploads . '/2026/10/photo.jpg',
            $this->content . '/wpmcp-restore/staged.txt',
            $this->content . '/wpmcp-widgets/widget.php',
            $this->content . '/wpmcp-skills/mine.md',
        ];
        foreach ($files as $file) {
            wp_mkdir_p(dirname($file));
            file_put_contents($file, 'x');
        }
        file_put_contents($uploads . '/wpmcp-site-backups/.htaccess', "Require all denied\n");
    }

    /** Every option on the current site a wpmcp prefix owns. */
    private function owned_options(): array
    {
        global $wpdb;
        wp_cache_flush();
        return $wpdb->get_col($wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            'wpmcp\_%',
            '\_%transient\_%wpmcp\_%'
        ));
    }

    private function owned_user_meta(): array
    {
        global $wpdb;
        return $wpdb->get_col($wpdb->prepare(
            "SELECT meta_key FROM {$wpdb->usermeta} WHERE meta_key LIKE %s OR meta_key LIKE %s",
            'wpmcp\_%',
            '\_wpmcp\_%'
        ));
    }

    /** Record every query from here on; the object's list fills as they run. */
    private function capture_queries(): \stdClass
    {
        $log       = new \stdClass();
        $log->list = [];
        add_filter('query', static function ($query) use ($log) {
            $log->list[] = trim((string) $query);
            return $query;
        }, 100);
        return $log;
    }

    private function remove_tree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
            return;
        }
        if (! is_dir($path)) {
            return;
        }
        foreach (array_diff((array) scandir($path), [ '.', '..' ]) as $entry) {
            $this->remove_tree($path . '/' . $entry);
        }
        rmdir($path);
    }
}
