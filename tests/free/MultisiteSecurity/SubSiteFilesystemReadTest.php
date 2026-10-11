<?php

namespace WPMCP\Tests\Free\MultisiteSecurity;

use WPMCP\Tools\Filesystem\List_Directory;
use WPMCP\Tools\Filesystem\Read_File;
use WPMCP\Tools\Filesystem\Search_Files;

/**
 * Issue #559: read-file, list-directory and search-files are confined to
 * ABSPATH, and on multisite ABSPATH holds every site's files: other sites'
 * uploads, the main site's private wpmcp-exports WXR files, the
 * wpmcp-fs-backups copies, debug.log. Core gives a sub-site administrator
 * no file access at all, so on multisite these reads belong to the network
 * administrator (manage_network_options), like the write tools already do.
 *
 * Runs under WP_MULTISITE=1 and skips on single site, where the read tools
 * stay at manage_options (FilesystemCapabilityTest covers that).
 */
class SubSiteFilesystemReadTest extends \WP_UnitTestCase
{
    private const READ_TOOLS = [
        'wpmcp/read-file',
        'wpmcp/list-directory',
        'wpmcp/search-files',
    ];

    private int $blog = 0;
    private int $other = 0;
    private int $sub_admin = 0;
    private int $super_admin = 0;
    /** @var string[] */
    private array $planted = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (! is_multisite()) {
            $this->markTestSkipped('Multisite only: run with WP_MULTISITE=1.');
        }
        $this->setExpectedDeprecated('wpmu_new_blog');

        $this->blog        = (int) self::factory()->blog->create();
        $this->other       = (int) self::factory()->blog->create();
        $this->super_admin = (int) self::factory()->user->create(['role' => 'administrator']);
        grant_super_admin($this->super_admin);
        $this->sub_admin = (int) self::factory()->user->create(['role' => 'subscriber']);
        add_user_to_blog($this->blog, $this->sub_admin, 'administrator');

        switch_to_blog($this->blog);
        wp_set_current_user($this->sub_admin);
        $this->assertFalse(is_super_admin($this->sub_admin));
        $this->assertTrue(current_user_can('manage_options'), 'Fixture: the sub-site admin holds manage_options on its blog');
    }

    protected function tearDown(): void
    {
        foreach ($this->planted as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_multisite()) {
            if (ms_is_switched()) {
                restore_current_blog();
            }
            revoke_super_admin($this->super_admin);
        }
        parent::tearDown();
    }

    /** Plant a file in the main site's private export directory; returns its ABSPATH-relative path. */
    private function plant_main_site_export(): string
    {
        switch_to_blog(get_main_site_id());
        $dir = trailingslashit(wp_upload_dir()['basedir']) . 'wpmcp-exports';
        restore_current_blog();
        wp_mkdir_p($dir);
        $file            = $dir . '/issue-559-main-site-export.xml';
        $this->planted[] = $file;
        file_put_contents($file, '<wp:author_email>owner-559@main.example</wp:author_email>');
        return ltrim(str_replace(ABSPATH, '', $file), '/');
    }

    /** Plant a file in another sub-site's uploads; returns the uploads dir, ABSPATH-relative. */
    private function plant_other_site_upload(): string
    {
        switch_to_blog($this->other);
        $dir = wp_upload_dir()['basedir'];
        restore_current_blog();
        wp_mkdir_p($dir);
        $file            = $dir . '/issue-559-other-site.txt';
        $this->planted[] = $file;
        file_put_contents($file, 'other-site-secret-559');
        return ltrim(str_replace(ABSPATH, '', $dir), '/');
    }

    public function test_read_tools_deny_a_sub_site_admin_at_the_permission_layer(): void
    {
        $rel       = $this->plant_main_site_export();
        $abilities = wp_get_abilities();
        $allowed   = [];
        foreach (self::READ_TOOLS as $name) {
            $this->assertArrayHasKey($name, $abilities);
            if (true === $abilities[ $name ]->check_permissions(['path' => $rel, 'query' => 'owner'])) {
                $allowed[] = $name;
            }
        }

        $this->assertSame([], $allowed, 'Filesystem read tools must not be open to a sub-site admin on multisite');
    }

    public function test_read_file_refuses_a_sub_site_admin_reading_the_main_site_export(): void
    {
        $rel = $this->plant_main_site_export();

        $content = null;
        $refused = null;
        try {
            $content = (new Read_File())->handle(['path' => $rel])['content'] ?? null;
        } catch (\RuntimeException $e) {
            $refused = $e->getMessage();
        }

        $this->assertNull($content, 'A sub-site admin read the main site\'s private export');
        $this->assertNotNull($refused);
    }

    public function test_list_directory_refuses_a_sub_site_admin_listing_another_sites_uploads(): void
    {
        $rel = $this->plant_other_site_upload();

        $listed  = null;
        $refused = null;
        try {
            $listed = (new List_Directory())->handle(['path' => $rel]);
        } catch (\RuntimeException $e) {
            $refused = $e->getMessage();
        }

        $this->assertNull($listed, 'A sub-site admin listed another sub-site\'s uploads');
        $this->assertNotNull($refused);
    }

    public function test_search_files_refuses_a_sub_site_admin(): void
    {
        $rel = $this->plant_other_site_upload();

        $found   = null;
        $refused = null;
        try {
            $found = (new Search_Files())->handle(['query' => 'other-site-secret-559', 'path' => $rel]);
        } catch (\RuntimeException $e) {
            $refused = $e->getMessage();
        }

        $this->assertNull($found, 'A sub-site admin searched another sub-site\'s uploads');
        $this->assertNotNull($refused);
    }

    public function test_a_super_admin_keeps_every_read_tool(): void
    {
        $export = $this->plant_main_site_export();
        $other  = $this->plant_other_site_upload();
        wp_set_current_user($this->super_admin);

        $abilities = wp_get_abilities();
        $denied    = [];
        foreach (self::READ_TOOLS as $name) {
            if (true !== $abilities[ $name ]->check_permissions(['path' => $export, 'query' => 'owner'])) {
                $denied[] = $name;
            }
        }
        $this->assertSame([], $denied, 'A super admin must keep the filesystem read tools');

        $read = (new Read_File())->handle(['path' => $export]);
        $this->assertStringContainsString('owner-559@main.example', (string) $read['content']);

        $listed = (new List_Directory())->handle(['path' => $other]);
        $names  = array_map(static fn (array $e): string => basename((string) $e['path']), $listed['entries']);
        $this->assertContains('issue-559-other-site.txt', $names);

        $found = (new Search_Files())->handle(['query' => 'other-site-secret-559', 'path' => $other]);
        $this->assertNotEmpty($found['matches']);
    }
}
