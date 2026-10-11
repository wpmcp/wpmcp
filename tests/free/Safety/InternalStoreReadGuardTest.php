<?php

namespace WPMCP\Tests\Free\Safety;

use WPMCP\MCP\Request_Log;
use WPMCP\Tools\Database\Database_Guard;
use WPMCP\Tools\Filesystem\Filesystem_Guard;
use WPMCP\Tools\Filesystem\List_Directory;
use WPMCP\Tools\Filesystem\Read_File;
use WPMCP\Tools\Filesystem\Search_Files;
use WPMCP\Tools\Meta\Get_Option;

/**
 * Issue #550: WP MCP's own stores that keep raw copies of options, rows or
 * tool arguments are not readable through the generic tools. The request
 * log (which, with argument capture on, keeps the first characters of
 * every argument whose key does not look secret) is refused to get-option,
 * and the site backup directory, where full database dumps live, is out of
 * reach of the generic file tools. The payment gateway cases are in
 * tests/pro/WooCommerce/InternalStoreGatewaySecretReadTest.php.
 */
class InternalStoreReadGuardTest extends \WP_UnitTestCase
{
    private const MARKER = 'sk_live_DUMP_MARKER_550';

    private string $dir = '';

    public function setUp(): void
    {
        parent::setUp();
        $uploads   = wp_upload_dir();
        $this->dir = trailingslashit($uploads['basedir']) . 'wpmcp-site-backups';
        wp_mkdir_p($this->dir . '/restore-test550');
        file_put_contents($this->dir . '/db-test550.sql', "INSERT INTO wp_options VALUES ('" . self::MARKER . "');\n");
        file_put_contents($this->dir . '/restore-test550/db.sql', "INSERT INTO wp_options VALUES ('" . self::MARKER . "');\n");
        if (0 !== strpos(wp_normalize_path($this->dir), wp_normalize_path(ABSPATH))) {
            $this->markTestSkipped('The uploads directory is outside ABSPATH here, so the file tools cannot reach it anyway.');
        }
    }

    public function tearDown(): void
    {
        @unlink($this->dir . '/db-test550.sql');
        @unlink($this->dir . '/restore-test550/db.sql');
        @rmdir($this->dir . '/restore-test550');
        delete_option(Request_Log::OPTION);
        delete_option(Request_Log::CAPTURE_OPTION);
        parent::tearDown();
    }

    private function rel(string $abs): string
    {
        return ltrim(substr(wp_normalize_path($abs), strlen(wp_normalize_path(ABSPATH))), '/');
    }

    public function test_get_option_refuses_the_request_log_and_its_capture_switch(): void
    {
        update_option(Request_Log::OPTION, [ [ 'tool' => 'update-rows', 'args' => [ 'data' => [ 'option_value' => self::MARKER ] ] ] ], false);
        foreach ([ Request_Log::OPTION, Request_Log::CAPTURE_OPTION, strtoupper(Request_Log::OPTION) ] as $name) {
            try {
                $out = (new Get_Option())->handle([ 'name' => $name ]);
                $this->fail("get-option {$name} was not refused: " . wp_json_encode($out));
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('Refusing to read', $e->getMessage());
            }
        }
    }

    public function test_read_file_refuses_database_dumps_in_the_site_backup_dir(): void
    {
        foreach ([ $this->dir . '/db-test550.sql', $this->dir . '/restore-test550/db.sql' ] as $abs) {
            try {
                $out = (new Read_File())->handle([ 'path' => $this->rel($abs) ]);
                $this->fail('read-file returned a database dump: ' . wp_json_encode($out));
            } catch (\RuntimeException $e) {
                $this->assertStringNotContainsString(self::MARKER, $e->getMessage());
            }
        }
    }

    public function test_search_and_list_do_not_reach_into_the_site_backup_dir(): void
    {
        $uploads = $this->rel(dirname($this->dir));
        $found   = [];
        try {
            $found = (new Search_Files())->handle([ 'query' => self::MARKER, 'path' => $uploads ]);
        } catch (\RuntimeException $e) {
            $found = [ 'refused' => $e->getMessage() ];
        }
        $this->assertStringNotContainsString(self::MARKER, (string) wp_json_encode($found));
        $this->assertStringNotContainsString('db-test550.sql', (string) wp_json_encode($found));

        $listed = [];
        try {
            $listed = (new List_Directory())->handle([ 'path' => $this->rel($this->dir), 'recursive' => true ]);
        } catch (\RuntimeException $e) {
            $listed = [ 'refused' => $e->getMessage() ];
        }
        $this->assertStringNotContainsString('db-test550.sql', (string) wp_json_encode($listed));
        $this->assertStringNotContainsString('restore-test550', (string) wp_json_encode($listed));
    }

    public function test_the_backup_dir_cannot_be_reopened_by_the_protected_paths_filter(): void
    {
        add_filter('wpmcp_fs_protected_paths', '__return_empty_array');
        try {
            $this->assertTrue(Filesystem_Guard::is_protected($this->dir . '/db-test550.sql'));
            $this->assertTrue(Filesystem_Guard::is_protected(strtoupper($this->dir) . '/DB-TEST550.SQL'));
            $this->assertFalse(Filesystem_Guard::is_protected(dirname($this->dir) . '/wpmcp-site-backups-not/x.sql'));
        } finally {
            remove_all_filters('wpmcp_fs_protected_paths');
        }
    }

    public function test_the_snapshot_ledger_is_an_always_protected_table(): void
    {
        global $wpdb;
        add_filter('wpmcp_db_protected_tables', '__return_empty_array');
        try {
            foreach ([ $wpdb->prefix . 'wpmcp_snapshots', strtoupper($wpdb->prefix . 'wpmcp_snapshots'), $wpdb->base_prefix . '2_wpmcp_snapshots' ] as $table) {
                $this->assertTrue(Database_Guard::is_protected($table), $table);
            }
            $this->assertFalse(Database_Guard::is_protected($wpdb->options));
        } finally {
            remove_all_filters('wpmcp_db_protected_tables');
        }
    }
}
