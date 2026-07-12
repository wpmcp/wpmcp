<?php

namespace WPMCP\Tests\Free\Filesystem;

use WPMCP\Tools\Filesystem\Filesystem_Guard;

class FilesystemGuardTest extends \WP_UnitTestCase
{
    private string $root;

    public function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/wpmcp-fs-' . uniqid();
        mkdir($this->root . '/wp-content/themes/x', 0777, true);
        file_put_contents($this->root . '/wp-content/themes/x/style.css', "a\nb\nc\n");
        file_put_contents($this->root . '/wp-config.php', '<?php // secrets');
        file_put_contents(dirname($this->root) . '/wpmcp-outside.txt', 'nope');
    }

    public function tearDown(): void
    {
        @unlink(dirname($this->root) . '/wpmcp-outside.txt');
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->root);
        parent::tearDown();
    }

    public function test_resolves_an_in_tree_path(): void
    {
        $out = Filesystem_Guard::resolve_path('wp-content/themes/x/style.css', $this->root);
        $this->assertSame(realpath($this->root . '/wp-content/themes/x/style.css'), $out);
    }

    public function test_rejects_parent_traversal(): void
    {
        $out = Filesystem_Guard::resolve_path('wp-content/../../wpmcp-outside.txt', $this->root);
        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('outside_root', $out->get_error_code());
    }

    public function test_rejects_absolute_path_outside_root(): void
    {
        $out = Filesystem_Guard::resolve_path(dirname($this->root) . '/wpmcp-outside.txt', $this->root);
        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('outside_root', $out->get_error_code());
    }

    public function test_rejects_null_byte(): void
    {
        $out = Filesystem_Guard::resolve_path("wp-config.php\0.txt", $this->root);
        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('invalid_path', $out->get_error_code());
    }

    public function test_rejects_empty_path(): void
    {
        $out = Filesystem_Guard::resolve_path('', $this->root);
        $this->assertInstanceOf(\WP_Error::class, $out);
    }

    public function test_resolves_a_new_file_when_parent_exists(): void
    {
        $out = Filesystem_Guard::resolve_path('wp-content/themes/x/new.txt', $this->root);
        $this->assertSame(
            realpath($this->root . '/wp-content/themes/x') . DIRECTORY_SEPARATOR . 'new.txt',
            $out
        );
    }

    public function test_rejects_symlink_escaping_the_root(): void
    {
        $link = $this->root . '/escape';
        if (! @symlink(dirname($this->root), $link)) {
            $this->markTestSkipped('symlink() unavailable in this environment');
        }

        $out = Filesystem_Guard::resolve_path('escape/wpmcp-outside.txt', $this->root);
        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('outside_root', $out->get_error_code());

        @unlink($link);
    }

    public function test_is_protected_flags_config_and_htaccess_case_insensitively(): void
    {
        $this->assertTrue(Filesystem_Guard::is_protected('/srv/site/wp-config.php'));
        $this->assertTrue(Filesystem_Guard::is_protected('/srv/site/WP-CONFIG.PHP'));
        $this->assertTrue(Filesystem_Guard::is_protected('/srv/site/.htaccess'));
        $this->assertTrue(Filesystem_Guard::is_protected('/srv/site/.HTACCESS'));
        $this->assertFalse(Filesystem_Guard::is_protected('/srv/site/wp-content/themes/x/style.css'));
    }

    public function test_backup_name_is_timestamped_and_sanitized(): void
    {
        $name = Filesystem_Guard::backup_name('wp-content/themes/x/style.css', '20260627-120000');
        $this->assertSame('20260627-120000-wp-content-themes-x-style.css', $name);
    }

    public function test_is_utf8_detects_text_vs_binary(): void
    {
        $this->assertTrue(Filesystem_Guard::is_utf8("plain text\nok"));
        $this->assertFalse(Filesystem_Guard::is_utf8("\xff\xfe\x00\x01binary"));
    }

    public function test_check_writes_gates_on_capability_and_disallow_file_edit(): void
    {
        $this->assertTrue(Filesystem_Guard::check_writes(true, false));
        $this->assertInstanceOf(\WP_Error::class, Filesystem_Guard::check_writes(true, true));
        $this->assertInstanceOf(\WP_Error::class, Filesystem_Guard::check_writes(false, false));
    }

    public function test_to_relative_strips_the_root_prefix(): void
    {
        $abs = realpath($this->root . '/wp-content/themes/x/style.css');
        $this->assertSame(
            'wp-content/themes/x/style.css',
            Filesystem_Guard::to_relative($abs, $this->root)
        );
    }

    public function test_backup_to_dir_copies_the_file_and_returns_the_backup_path(): void
    {
        $target     = realpath($this->root . '/wp-content/themes/x/style.css');
        $backup_dir = $this->root . '/backups';
        mkdir($backup_dir, 0777, true);

        $backup = Filesystem_Guard::backup_to_dir($target, $backup_dir, $this->root, '20260627-120000');

        $this->assertIsString($backup);
        $this->assertFileExists($backup);
        $this->assertSame(file_get_contents($target), file_get_contents($backup));
        $this->assertSame(
            '20260627-120000-wp-content-themes-x-style.css',
            basename($backup)
        );
    }

    public function test_backup_to_dir_returns_empty_string_for_a_not_yet_existing_file(): void
    {
        $target     = $this->root . '/wp-content/themes/x/does-not-exist-yet.txt';
        $backup_dir = $this->root . '/backups';
        mkdir($backup_dir, 0777, true);

        $backup = Filesystem_Guard::backup_to_dir($target, $backup_dir, $this->root, '20260627-120000');

        $this->assertSame('', $backup);
    }

    public function test_restore_copies_the_backup_back_over_the_target(): void
    {
        $target     = realpath($this->root . '/wp-content/themes/x/style.css');
        $backup_dir = $this->root . '/backups';
        mkdir($backup_dir, 0777, true);

        $backup = Filesystem_Guard::backup_to_dir($target, $backup_dir, $this->root, '20260627-120000');
        file_put_contents($target, 'mutated content');
        $this->assertSame('mutated content', file_get_contents($target));

        $restored = Filesystem_Guard::restore($backup, $target);

        $this->assertTrue($restored);
        $this->assertSame("a\nb\nc\n", file_get_contents($target));
    }

    public function test_log_appends_a_capped_entry_to_the_audit_option(): void
    {
        delete_option(Filesystem_Guard::AUDIT_OPTION);

        Filesystem_Guard::log('write', 'wp-content/themes/x/style.css');

        $log = get_option(Filesystem_Guard::AUDIT_OPTION);
        $this->assertIsArray($log);
        $this->assertCount(1, $log);
        $this->assertSame('write', $log[0]['op']);
        $this->assertSame('wp-content/themes/x/style.css', $log[0]['path']);
        $this->assertArrayHasKey('user', $log[0]);
        $this->assertArrayHasKey('time', $log[0]);
    }

    public function test_log_caps_the_audit_option_at_the_configured_max(): void
    {
        delete_option(Filesystem_Guard::AUDIT_OPTION);

        for ($i = 0; $i < Filesystem_Guard::AUDIT_MAX + 5; $i++) {
            Filesystem_Guard::log('write', "file-{$i}.txt");
        }

        $log = get_option(Filesystem_Guard::AUDIT_OPTION);
        $this->assertCount(Filesystem_Guard::AUDIT_MAX, $log);
        $this->assertSame('file-' . (Filesystem_Guard::AUDIT_MAX + 4) . '.txt', $log[ count($log) - 1 ]['path']);
    }
}
