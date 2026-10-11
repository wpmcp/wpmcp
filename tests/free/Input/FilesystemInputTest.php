<?php

namespace WPMCP\Tests\Free\Input;

use WPMCP\Tools\Filesystem\Read_File;
use WPMCP\Tools\Filesystem\Write_File;
use WPMCP\Tools\Filesystem\Edit_File;
use WPMCP\Tools\Filesystem\Delete_File;
use WPMCP\Tools\Filesystem\Search_Files;

/**
 * Input-boundary tests for the Filesystem domain: missing/empty paths,
 * path traversal, missing confirm, and no-match edits must all fail
 * cleanly (RuntimeException/InvalidArgumentException), never a fatal or
 * an out-of-sandbox read/write.
 */
class FilesystemInputTest extends \WP_UnitTestCase
{
    private string $rel_dir = 'wp-content/wpmcp-fs-input-test';

    public function setUp(): void
    {
        parent::setUp();
        mkdir(ABSPATH . $this->rel_dir, 0777, true);
        file_put_contents(ABSPATH . $this->rel_dir . '/existing.txt', 'aaa bbb aaa');
    }

    public function tearDown(): void
    {
        @unlink(ABSPATH . $this->rel_dir . '/existing.txt');
        @rmdir(ABSPATH . $this->rel_dir);
        parent::tearDown();
    }

    public function test_read_file_rejects_missing_path(): void
    {
        $this->expectException(\RuntimeException::class);
        (new Read_File())->handle([]);
    }

    public function test_read_file_rejects_empty_path(): void
    {
        $this->expectException(\RuntimeException::class);
        (new Read_File())->handle(['path' => '']);
    }

    public function test_write_file_disabled_by_default(): void
    {
        $this->expectException(\RuntimeException::class);
        (new Write_File())->handle(['path' => $this->rel_dir . '/new.txt', 'content' => 'x']);
    }

    public function test_write_file_rejects_traversal_when_enabled(): void
    {
        add_filter('wpmcp_enable_fs_writes', '__return_true');
        $admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin);
        wpmcp_test_grant_network_admin(get_current_user_id());

        $this->expectException(\RuntimeException::class);
        (new Write_File())->handle(['path' => '../../../../etc/passwd', 'content' => 'x']);
    }

    public function test_edit_file_disabled_by_default(): void
    {
        $this->expectException(\RuntimeException::class);
        (new Edit_File())->handle([
            'path'       => $this->rel_dir . '/existing.txt',
            'old_string' => 'aaa',
            'new_string' => 'zzz',
        ]);
    }

    public function test_edit_file_rejects_empty_old_string_when_enabled(): void
    {
        add_filter('wpmcp_enable_fs_writes', '__return_true');
        $admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin);
        wpmcp_test_grant_network_admin(get_current_user_id());

        $this->expectException(\InvalidArgumentException::class);
        (new Edit_File())->handle([
            'path'       => $this->rel_dir . '/existing.txt',
            'old_string' => '',
            'new_string' => 'zzz',
        ]);
    }

    public function test_edit_file_rejects_a_non_matching_old_string_when_enabled(): void
    {
        add_filter('wpmcp_enable_fs_writes', '__return_true');
        $admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin);
        wpmcp_test_grant_network_admin(get_current_user_id());

        $this->expectException(\RuntimeException::class);
        (new Edit_File())->handle([
            'path'       => $this->rel_dir . '/existing.txt',
            'old_string' => 'this-string-is-not-in-the-file',
            'new_string' => 'zzz',
        ]);
    }

    public function test_edit_file_rejects_ambiguous_multiple_matches_without_replace_all(): void
    {
        add_filter('wpmcp_enable_fs_writes', '__return_true');
        $admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin);
        wpmcp_test_grant_network_admin(get_current_user_id());

        $this->expectException(\RuntimeException::class);
        (new Edit_File())->handle([
            'path'       => $this->rel_dir . '/existing.txt',
            'old_string' => 'aaa',
            'new_string' => 'zzz',
        ]);
    }

    public function test_delete_file_disabled_by_default(): void
    {
        $this->expectException(\RuntimeException::class);
        (new Delete_File())->handle(['path' => $this->rel_dir . '/existing.txt', 'confirm' => true]);
    }

    public function test_delete_file_requires_confirm_when_enabled(): void
    {
        add_filter('wpmcp_enable_fs_writes', '__return_true');
        $admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin);
        wpmcp_test_grant_network_admin(get_current_user_id());

        $this->expectException(\InvalidArgumentException::class);
        (new Delete_File())->handle(['path' => $this->rel_dir . '/existing.txt']);
    }

    public function test_delete_file_rejects_nonexistent_file_when_enabled(): void
    {
        add_filter('wpmcp_enable_fs_writes', '__return_true');
        $admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin);
        wpmcp_test_grant_network_admin(get_current_user_id());

        $this->expectException(\RuntimeException::class);
        (new Delete_File())->handle(['path' => $this->rel_dir . '/does-not-exist.txt', 'confirm' => true]);
    }

    public function test_search_files_rejects_empty_query(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Search_Files())->handle(['query' => '']);
    }

    public function test_search_files_rejects_a_path_that_is_not_a_directory(): void
    {
        $this->expectException(\RuntimeException::class);
        (new Search_Files())->handle(['query' => 'aaa', 'path' => $this->rel_dir . '/existing.txt']);
    }
}
