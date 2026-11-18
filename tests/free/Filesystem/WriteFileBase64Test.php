<?php

namespace WPMCP\Tests\Free\Filesystem;

use WPMCP\Tools\Filesystem\Write_File;

/**
 * write-file with encoding: base64 writes binary files (issue #851): fonts,
 * images and small archives for a theme or plugin folder.
 *
 * The bytes go through the same path, protected-file, opt-in and undo rules
 * as a text write. base64 mode refuses executable and server-config types
 * (a .php file, or a name such as shell.php.png that a server may still run
 * as PHP), refuses more than Write_File::BASE64_MAX_BYTES decoded, and
 * refuses input that is not strict base64. Every call goes through the
 * registered ability, the way the MCP adapter runs it.
 */
class WriteFileBase64Test extends \WP_UnitTestCase
{
    private string $rel_dir;

    public function setUp(): void
    {
        parent::setUp();
        $this->rel_dir = 'wp-content/wpmcp-fs-b64-' . getmypid();
        wp_mkdir_p(ABSPATH . $this->rel_dir);
        add_filter('wpmcp_enable_fs_writes', '__return_true');
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        wpmcp_test_grant_network_admin(get_current_user_id());
    }

    public function tearDown(): void
    {
        foreach ((array) glob(ABSPATH . $this->rel_dir . '/{,.}*', GLOB_BRACE) as $file) {
            if (is_file((string) $file)) {
                @unlink((string) $file);
            }
        }
        @rmdir(ABSPATH . $this->rel_dir);
        parent::tearDown();
    }

    /** @return array|\WP_Error */
    private function run_ability(string $name, array $args)
    {
        $ability = wp_get_ability('wpmcp/' . $name);
        $this->assertNotNull($ability, "wpmcp/$name is not registered");
        return $ability->execute($args);
    }

    private function call(string $name, array $args): array
    {
        $result = $this->run_ability($name, $args);
        $this->assertNotWPError($result, $name . ': ' . (is_wp_error($result) ? $result->get_error_message() : ''));
        return (array) $result;
    }

    private function refused(array $args, string $needle): void
    {
        $result = $this->run_ability('write-file', $args);
        $this->assertWPError($result, wp_json_encode($result));
        $this->assertStringContainsString($needle, $result->get_error_message());
        $this->assertFileDoesNotExist(ABSPATH . (string) $args['path']);
    }

    private function path(string $name): string
    {
        return $this->rel_dir . '/' . $name;
    }

    private function bytes(string $name): ?string
    {
        $abs = ABSPATH . $this->path($name);
        return is_file($abs) ? (string) file_get_contents($abs) : null;
    }

    /** A WOFF2 header followed by bytes that are not valid UTF-8. */
    private function woff2(): string
    {
        return "wOF2\x00\x01\x00\x00" . str_repeat("\x00\xff\xfe\x80\xc3\x28", 64);
    }

    /** A 1x1 transparent PNG. */
    private function png(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true);
    }

    public function test_the_schema_advertises_the_encoding_enum(): void
    {
        $schema = wp_get_ability('wpmcp/write-file')->get_input_schema();
        $this->assertSame(['utf8', 'base64'], $schema['properties']['encoding']['enum'] ?? null);
    }

    public function test_a_woff2_font_is_written_byte_for_byte_with_its_size_and_sha256(): void
    {
        $bytes = $this->woff2();
        $out   = $this->call('write-file', [
            'path'     => $this->path('font.woff2'),
            'content'  => base64_encode($bytes),
            'encoding' => 'base64',
        ]);

        $this->assertSame('created', $out['action']);
        $this->assertSame($bytes, $this->bytes('font.woff2'));
        $this->assertSame(strlen($bytes), $out['bytes']);
        $this->assertSame(hash('sha256', $bytes), $out['sha256']);
        $this->assertTrue($out['recoverable']);
    }

    public function test_a_png_is_written_byte_for_byte_and_line_breaks_in_the_base64_are_ignored(): void
    {
        $bytes = $this->png();
        $out   = $this->call('write-file', [
            'path'     => $this->path('icon.png'),
            'content'  => chunk_split(base64_encode($bytes), 20, "\r\n"),
            'encoding' => 'base64',
        ]);

        $this->assertSame($bytes, $this->bytes('icon.png'));
        $this->assertSame(hash('sha256', $bytes), $out['sha256']);
    }

    public function test_rollback_operation_restores_the_previous_binary_bytes(): void
    {
        $before = $this->png();
        file_put_contents(ABSPATH . $this->path('logo.png'), $before);

        $out = $this->call('write-file', [
            'path'     => $this->path('logo.png'),
            'content'  => base64_encode($this->woff2()),
            'encoding' => 'base64',
        ]);
        $this->assertSame('overwritten', $out['action']);
        $this->assertSame($this->woff2(), $this->bytes('logo.png'));

        $rolled = $this->call('rollback-operation', ['operation_id' => $out['operation_id']]);
        $this->assertTrue($rolled['restored'], wp_json_encode($rolled));
        $this->assertSame($before, $this->bytes('logo.png'));
    }

    public function test_rollback_operation_removes_a_binary_file_the_call_created(): void
    {
        $out = $this->call('write-file', [
            'path'     => $this->path('new.woff2'),
            'content'  => base64_encode($this->woff2()),
            'encoding' => 'base64',
        ]);
        $this->call('rollback-operation', ['operation_id' => $out['operation_id']]);
        $this->assertNull($this->bytes('new.woff2'));
    }

    public function test_more_than_the_decoded_size_cap_is_refused(): void
    {
        $this->refused([
            'path'     => $this->path('big.bin'),
            'content'  => base64_encode(str_repeat("\x00", Write_File::BASE64_MAX_BYTES + 1)),
            'encoding' => 'base64',
        ], 'limit');
    }

    public function test_exactly_the_size_cap_is_written(): void
    {
        $out = $this->call('write-file', [
            'path'     => $this->path('edge.bin'),
            'content'  => base64_encode(str_repeat("\x01", Write_File::BASE64_MAX_BYTES)),
            'encoding' => 'base64',
        ]);
        $this->assertSame(Write_File::BASE64_MAX_BYTES, $out['bytes']);
    }

    /** @return array<string, array{0: string}> */
    public function executable_names(): array
    {
        return [
            'php'            => ['shell.php'],
            'upper PHP'      => ['shell.PHP'],
            'phtml'          => ['shell.phtml'],
            'phar'           => ['shell.phar'],
            'php7'           => ['shell.php7'],
            'pht'            => ['shell.pht'],
            'double ext'     => ['shell.php.png'],
            'user ini'       => ['.user.ini'],
            'php.ini'        => ['php.ini'],
            'htaccess clone' => ['.htpasswd'],
            'cgi'            => ['run.cgi'],
            'shell script'   => ['run.sh'],
        ];
    }

    /** @dataProvider executable_names */
    public function test_executable_and_server_config_types_are_refused(string $name): void
    {
        $this->refused([
            'path'     => $this->path($name),
            'content'  => base64_encode('<?php echo 1;'),
            'encoding' => 'base64',
        ], 'base64');
    }

    public function test_input_that_is_not_strict_base64_is_refused(): void
    {
        $this->refused([
            'path'     => $this->path('bad.png'),
            'content'  => 'not*base64!',
            'encoding' => 'base64',
        ], 'base64');
    }

    public function test_an_unknown_encoding_is_refused(): void
    {
        $this->refused([
            'path'     => $this->path('x.txt'),
            'content'  => 'hello',
            'encoding' => 'hex',
        ], 'encoding');
    }

    public function test_a_protected_file_is_still_refused_in_base64_mode(): void
    {
        $result = $this->run_ability('write-file', [
            'path'     => 'wp-config.php',
            'content'  => base64_encode('x'),
            'encoding' => 'base64',
        ]);
        $this->assertWPError($result);
    }

    public function test_text_writes_are_unchanged_by_default_and_with_utf8(): void
    {
        $this->call('write-file', ['path' => $this->path('a.txt'), 'content' => 'aGk=']);
        $this->assertSame('aGk=', $this->bytes('a.txt'));
        $this->call('write-file', ['path' => $this->path('b.txt'), 'content' => 'aGk=', 'encoding' => 'utf8']);
        $this->assertSame('aGk=', $this->bytes('b.txt'));
    }

    public function test_base64_mode_is_still_off_by_default(): void
    {
        remove_all_filters('wpmcp_enable_fs_writes');
        $this->assertFalse(Write_File::is_enabled());
        $result = $this->run_ability('write-file', [
            'path'     => $this->path('off.png'),
            'content'  => base64_encode($this->png()),
            'encoding' => 'base64',
        ]);
        $this->assertWPError($result);
        $this->assertNull($this->bytes('off.png'));
    }
}
