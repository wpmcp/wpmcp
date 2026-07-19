<?php

namespace WPMCP\Tests\Pro\Freemius;

use WPMCP\Freemius\Bootstrap;

class BootstrapTest extends \WP_UnitTestCase
{
    protected function tearDown(): void
    {
        Bootstrap::set_sdk_path_for_tests(null);
        Bootstrap::set_fs_for_tests(null);
        parent::tearDown();
    }

    public function test_config_returns_expected_static_shape(): void
    {
        $config = Bootstrap::config();

        $this->assertIsArray($config);
        $this->assertSame('wpmcp', $config['slug']);
        $this->assertSame('plugin', $config['type']);
        $this->assertFalse($config['is_premium']);
        $this->assertTrue($config['has_premium_version']);
        $this->assertSame('wpmcp-pro', $config['premium_slug']);
        $this->assertFalse($config['has_addons']);
        $this->assertTrue($config['has_paid_plans']);
        $this->assertTrue($config['anonymous_mode']);
        $this->assertSame('wpmcp', $config['menu']['slug']);
    }

    public function test_config_id_and_public_key_come_from_constants(): void
    {
        $config = Bootstrap::config();

        $this->assertSame(WPMCP_FS_ID, $config['id']);
        $this->assertSame(WPMCP_FS_PUBLIC_KEY, $config['public_key']);
    }

    public function test_config_carries_live_registered_credentials(): void
    {
        // Registered on freemius.com; the public key is public by design.
        $config = Bootstrap::config();

        $this->assertSame(34955, $config['id']);
        $this->assertSame('pk_198c5294157bf7068fd2ffd493957', $config['public_key']);
    }

    public function test_locate_sdk_finds_composer_vendored_sdk(): void
    {
        $expected = WPMCP_DIR . 'vendor/freemius/wordpress-sdk/start.php';

        $this->assertFileExists($expected);
        $this->assertSame($expected, Bootstrap::locate_sdk());
    }

    public function test_locate_sdk_returns_null_when_sdk_missing(): void
    {
        Bootstrap::set_sdk_path_for_tests('/nonexistent/freemius/start.php');

        $this->assertNull(Bootstrap::locate_sdk());
    }

    public function test_should_load_true_with_credentials_and_sdk_present(): void
    {
        $this->assertTrue(Bootstrap::credentials_present());
        $this->assertTrue(Bootstrap::should_load());
    }

    public function test_should_load_false_when_sdk_absent(): void
    {
        // CI-safety guard: with the SDK directory missing, init() must be a
        // no-op (no require, no fatal), which should_load() gates.
        Bootstrap::set_sdk_path_for_tests('/nonexistent/freemius/start.php');

        $this->assertFalse(Bootstrap::should_load());
    }

    public function test_init_is_safe_noop_when_sdk_absent(): void
    {
        Bootstrap::set_sdk_path_for_tests('/nonexistent/freemius/start.php');

        Bootstrap::init();

        // No fatal, and the guard prevented any SDK load attempt.
        $this->assertFalse(Bootstrap::should_load());
    }

    public function test_init_boots_freemius_exactly_once_and_exposes_instance(): void
    {
        // wpmcp.php already ran Bootstrap::init() at plugin load with the SDK
        // vendored, so the singleton must exist and be stable across re-init.
        $this->assertTrue(function_exists('wpmcp_fs'));

        $fs = Bootstrap::fs();
        $this->assertInstanceOf(\Freemius::class, $fs);

        Bootstrap::init();

        $this->assertSame($fs, Bootstrap::fs());
        $this->assertSame($fs, wpmcp_fs());
    }

    public function test_fs_returns_null_when_sdk_forced_absent(): void
    {
        Bootstrap::set_fs_for_tests(false);

        $this->assertNull(Bootstrap::fs());
    }

    public function test_uninstall_and_deactivation_paths_do_not_fatal_without_sdk(): void
    {
        Bootstrap::set_sdk_path_for_tests('/nonexistent/freemius/start.php');
        Bootstrap::set_fs_for_tests(false);

        // Re-running init and firing the deactivation hook with the SDK gone
        // must not fatal (mirrors an SDK-less install being deactivated).
        Bootstrap::init();
        do_action('deactivate_' . plugin_basename(WPMCP_FILE));

        $this->assertNull(Bootstrap::fs());
    }
}
