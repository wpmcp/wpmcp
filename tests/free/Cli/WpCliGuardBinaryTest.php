<?php

namespace WPMCP\Tests\Free\Cli;

use WPMCP\Tools\Cli\Wp_Cli_Guard;

/**
 * The wp binary location is resolved via an explicit seam
 * (WPMCP_WP_CLI_BINARY constant or wpmcp_wp_cli_binary filter), never a
 * shell PATH search: if the resolved path does not exist or is not
 * executable, resolve_binary() returns a WP_Error rather than falling back
 * to searching for one.
 */
class WpCliGuardBinaryTest extends \WP_UnitTestCase
{
    private string $fixture_dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture_dir = sys_get_temp_dir() . '/wpmcp-cli-binary-test-' . getmypid();
        mkdir($this->fixture_dir);
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_wp_cli_binary');
        array_map('unlink', glob($this->fixture_dir . '/*'));
        rmdir($this->fixture_dir);
        parent::tearDown();
    }

    public function test_resolves_an_executable_binary_via_filter(): void
    {
        $bin = $this->fixture_dir . '/wp';
        file_put_contents($bin, "#!/bin/sh\necho ok\n");
        chmod($bin, 0755);

        add_filter('wpmcp_wp_cli_binary', function () use ($bin) {
            return $bin;
        });

        $resolved = Wp_Cli_Guard::resolve_binary();
        $this->assertSame($bin, $resolved);
    }

    public function test_returns_error_when_binary_does_not_exist(): void
    {
        add_filter('wpmcp_wp_cli_binary', function () {
            return $this->fixture_dir . '/does-not-exist';
        });

        $resolved = Wp_Cli_Guard::resolve_binary();
        $this->assertInstanceOf(\WP_Error::class, $resolved);
        $this->assertSame('wp_cli_binary_not_found', $resolved->get_error_code());
    }

    public function test_returns_error_when_binary_is_not_executable(): void
    {
        $bin = $this->fixture_dir . '/wp-not-executable';
        file_put_contents($bin, "#!/bin/sh\necho ok\n");
        chmod($bin, 0644);

        add_filter('wpmcp_wp_cli_binary', function () use ($bin) {
            return $bin;
        });

        $resolved = Wp_Cli_Guard::resolve_binary();
        $this->assertInstanceOf(\WP_Error::class, $resolved);
        $this->assertSame('wp_cli_binary_not_executable', $resolved->get_error_code());
    }

    public function test_returns_error_when_no_binary_configured_and_default_lookup_fails(): void
    {
        // No filter/constant set, and the conservative default candidate
        // paths are extremely unlikely to exist in the test environment;
        // must fail closed (WP_Error), never fall back to a shell search.
        $resolved = Wp_Cli_Guard::resolve_binary();
        if (! ($resolved instanceof \WP_Error)) {
            $this->markTestSkipped('A real wp binary was found on this machine at a default candidate path.');
        }
        $this->assertSame('wp_cli_binary_not_found', $resolved->get_error_code());
    }
}
