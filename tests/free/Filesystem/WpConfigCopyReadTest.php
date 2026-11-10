<?php

namespace WPMCP\Tests\Free\Filesystem;

use WPMCP\Tools\Filesystem\Edit_File;
use WPMCP\Tools\Filesystem\Filesystem_Guard;
use WPMCP\Tools\Filesystem\Read_File;
use WPMCP\Tools\Filesystem\Search_Files;

/**
 * Issue #606. read-file refused wp-config.php but returned renamed copies of
 * it (wp-config.php.bak, wp-config-backup.php, ...) in full, secrets and
 * all, and search-files returned their lines. The guard now protects every
 * wp-config* name except wp-config-sample.php, and refuses any file whose
 * PHP code defines DB_PASSWORD or a key/salt as a real literal, whatever it
 * is called. wp-config-sample.php (placeholder values) and core files that
 * only mention the constants in comments stay readable.
 */
class WpConfigCopyReadTest extends \WP_UnitTestCase
{
    private const SECRET = 'h606-fake-db-password-not-real';
    private const SALT   = 'h606-fake-salt-Xakm<o xQy rw4EMsLKM';

    private string $rel_dir = 'wp-content/wpmcp-fs-606';

    /** @var string[] */
    private array $created = [];

    public function setUp(): void
    {
        parent::setUp();
        wp_mkdir_p(ABSPATH . $this->rel_dir);
    }

    public function tearDown(): void
    {
        foreach ($this->created as $path) {
            @unlink($path);
        }
        @rmdir(ABSPATH . $this->rel_dir);
        remove_all_filters('wpmcp_enable_fs_writes');
        parent::tearDown();
    }

    private static function config_body(): string
    {
        return "<?php\n// a copy left behind by a migration tool\n"
            . "define( 'DB_NAME', 'wp' );\n"
            . "define( \"DB_PASSWORD\", \"" . self::SECRET . "\" );\n"
            . "define( 'AUTH_KEY', '" . self::SALT . "' );\n";
    }

    private function put(string $name, string $body): string
    {
        $rel = $this->rel_dir . '/' . $name;
        file_put_contents(ABSPATH . $rel, $body);
        $this->created[] = ABSPATH . $rel;

        return $rel;
    }

    /** @return array<string, array{0: string}> */
    public static function copy_names(): array
    {
        $names = [
            'wp-config.php.bak', 'wp-config.bak', 'wp-config.old', 'wp-config.txt',
            'wp-config-backup.php', 'wp-config-h4-copy.php', 'wp-config.php.h4bak',
            'wp-config.php~', 'wp-config.php.save', 'WP-CONFIG.PHP.BAK', 'wp-config.php.orig',
        ];

        return array_combine($names, array_map(static fn ($n) => [$n], $names));
    }

    /** @dataProvider copy_names */
    public function test_read_file_refuses_a_wp_config_copy(string $name): void
    {
        $rel = $this->put($name, self::config_body());

        try {
            $out = (new Read_File())->handle(['path' => $rel]);
            $this->fail("read-file returned {$name}: " . wp_json_encode($out));
        } catch (\RuntimeException $e) {
            $this->assertSame('This file is protected from reads.', $e->getMessage());
        }
    }

    /** @dataProvider copy_names */
    public function test_search_files_does_not_return_a_wp_config_copy(string $name): void
    {
        $this->put($name, self::config_body());

        $out = (new Search_Files())->handle(['query' => 'DB_PASSWORD', 'path' => $this->rel_dir]);

        $this->assertSame([], $out['matches'], "search-files returned lines of {$name}.");
        $this->assertStringNotContainsString(self::SECRET, wp_json_encode($out));
    }

    /** A copy under a name no list would guess is caught by what it holds. */
    public function test_read_file_refuses_any_file_that_defines_real_secrets(): void
    {
        $rel = $this->put('old-settings-2024.php', self::config_body());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('This file is protected from reads.');
        (new Read_File())->handle(['path' => $rel]);
    }

    public function test_read_file_refuses_a_copy_without_an_open_tag(): void
    {
        $rel = $this->put('notes.txt', "define('DB_PASSWORD', '" . self::SECRET . "');\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('This file is protected from reads.');
        (new Read_File())->handle(['path' => $rel]);
    }

    public function test_search_files_skips_any_file_that_defines_real_secrets(): void
    {
        $this->put('old-settings-2024.php', self::config_body());
        $this->put('ordinary.php', "<?php\n// DB_PASSWORD is read from the environment\n");

        $out = (new Search_Files())->handle(['query' => 'DB_PASSWORD', 'path' => $this->rel_dir]);

        $files = array_column($out['matches'], 'file');
        $this->assertSame([$this->rel_dir . '/ordinary.php'], $files);
        $this->assertStringNotContainsString(self::SECRET, wp_json_encode($out));
    }

    /** edit-file's found / not found answers would be an oracle for the secret. */
    public function test_edit_file_refuses_a_file_that_defines_real_secrets(): void
    {
        add_filter('wpmcp_enable_fs_writes', '__return_true');
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        wpmcp_test_grant_network_admin(get_current_user_id());
        $rel = $this->put('old-settings-2024.txt', self::config_body());

        try {
            (new Edit_File())->handle(['path' => $rel, 'old_string' => self::SECRET, 'new_string' => 'x']);
            $this->fail('edit-file accepted a file holding real secrets.');
        } catch (\RuntimeException $e) {
            $this->assertSame('This file is protected from writes.', $e->getMessage());
        }
        $this->assertSame(self::config_body(), file_get_contents(ABSPATH . $rel));
    }

    public function test_wp_config_sample_is_still_readable(): void
    {
        $this->assertFileExists(ABSPATH . 'wp-config-sample.php', 'Precondition: core ships the sample.');

        $out = (new Read_File())->handle(['path' => 'wp-config-sample.php']);

        $this->assertStringContainsString("'password_here'", $out['content']);
        $this->assertFalse(Filesystem_Guard::is_protected(ABSPATH . 'wp-config-sample.php'));
    }

    public function test_core_files_that_only_mention_the_constants_stay_readable(): void
    {
        // pluggable.php carries example salts in a docblock; setup-config.php
        // defines DB_PASSWORD from a variable. Neither holds a secret.
        foreach (['wp-includes/pluggable.php', 'wp-admin/setup-config.php'] as $rel) {
            $out = (new Read_File())->handle(['path' => $rel]);
            $this->assertArrayHasKey('content', $out, $rel);
        }
    }

    /** @dataProvider secret_detection_cases */
    public function test_holds_wp_secrets(string $content, bool $expected): void
    {
        $this->assertSame($expected, Filesystem_Guard::holds_wp_secrets($content));
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function secret_detection_cases(): array
    {
        return [
            'real password'           => ["<?php define('DB_PASSWORD', 's3cret');", true],
            'real salt, double quote' => ["<?php define( \"NONCE_SALT\" , \"abc\" );", true],
            'lower-case define'       => ["<?php DEFINE('db_password', 's3cret');", true],
            'const syntax'            => ["<?php const DB_PASSWORD = 's3cret';", true],
            'sample placeholder'      => ["<?php define('DB_PASSWORD', 'password_here');", false],
            'sample salt placeholder' => ["<?php define('AUTH_KEY', 'put your unique phrase here');", false],
            'empty password'          => ["<?php define('DB_PASSWORD', '');", false],
            'from the environment'    => ["<?php define('DB_PASSWORD', getenv('DB_PASSWORD'));", false],
            'from a variable'         => ["<?php define('DB_PASSWORD', \$pwd);", false],
            'only in a comment'       => ["<?php\n/* define('AUTH_KEY', 'abc'); */\n// define('DB_PASSWORD', 'x');", false],
            'only in a string'        => ["<?php echo \"define('DB_PASSWORD', 'x')\";", false],
            'no open tag'             => ["define('DB_PASSWORD', 's3cret');", true],
            'unrelated constant'      => ["<?php define('DB_HOST', 'localhost');", false],
        ];
    }
}
