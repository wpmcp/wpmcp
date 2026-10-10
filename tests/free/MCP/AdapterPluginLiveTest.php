<?php

namespace WPMCP\Tests\Free\MCP;

/**
 * Issue #501: WP MCP on the released MCP Adapter plugin.
 *
 * The adapter is a plugin in the WordPress.org directory, and when a site has
 * it active WP MCP runs on its classes instead of the bundled copy
 * (src/adapter-guard.php, issue #386). That plugin updates on its own
 * schedule, so the local gate's adapter leg (bin/test-local.sh --live-adapter,
 * also part of --all) downloads the latest release, points
 * WPMCP_TEST_ADAPTER_PLUGIN at it and runs the whole suite on it. This test is
 * the part of that leg that checks the coexistence itself: the classes in the
 * run come from the plugin, and with both copies present the plugin neither
 * reports a duplicate copy in wp-admin nor fires a _doing_it_wrong notice
 * while WordPress boots (tests/bootstrap.php records those, since they fire
 * before any test case is listening).
 *
 * Everywhere else no adapter plugin is loaded and the test is skipped; the
 * adapter leg runs this group with --fail-on-skipped so it cannot pass
 * vacuously.
 *
 * @group adapter-live
 */
class AdapterPluginLiveTest extends \WP_UnitTestCase
{
    private string $plugin = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->plugin = (string) getenv('WPMCP_TEST_ADAPTER_PLUGIN');
        if ('' === $this->plugin) {
            $this->markTestSkipped('Needs the released MCP Adapter plugin (bin/test-local.sh --live-adapter, WPMCP_TEST_ADAPTER_PLUGIN).');
        }
    }

    public function test_the_adapter_classes_come_from_the_plugin_not_the_bundle(): void
    {
        $this->assertTrue(class_exists(\WP\MCP\Core\McpAdapter::class));
        $file = (string) (new \ReflectionClass(\WP\MCP\Core\McpAdapter::class))->getFileName();

        $this->assertStringStartsWith(
            dirname((string) realpath($this->plugin)) . DIRECTORY_SEPARATOR,
            (string) realpath($file),
            'McpAdapter was loaded from ' . $file . ', not from the adapter plugin under test.'
        );
        $this->assertStringNotContainsString(dirname(__DIR__, 3) . '/vendor/', $file, 'The bundled copy shadowed the plugin.');
    }

    public function test_the_plugin_version_under_test_is_the_one_that_booted(): void
    {
        $expected = (string) getenv('WPMCP_TEST_ADAPTER_VERSION');
        if ('' === $expected) {
            $this->markTestSkipped('WPMCP_TEST_ADAPTER_VERSION is set by the adapter leg only.');
        }

        $this->assertTrue(defined('WP_MCP_VERSION'), 'The adapter plugin did not boot (WP_MCP_VERSION is undefined).');
        $this->assertSame($expected, \WP_MCP_VERSION);
    }

    public function test_no_doing_it_wrong_fired_while_both_copies_booted(): void
    {
        $this->assertIsArray($GLOBALS['wpmcp_adapter_boot_doing_it_wrong'] ?? null, 'tests/bootstrap.php must record boot-time _doing_it_wrong calls when an adapter plugin is loaded.');

        $this->assertSame(
            [],
            $GLOBALS['wpmcp_adapter_boot_doing_it_wrong'],
            "_doing_it_wrong fired while WordPress booted with the adapter plugin and the bundled copy:\n"
            . implode("\n", (array) $GLOBALS['wpmcp_adapter_boot_doing_it_wrong'])
        );
    }

    public function test_wp_admin_shows_no_duplicate_copy_notice(): void
    {
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        set_current_screen('dashboard');

        ob_start();
        do_action('admin_notices');
        do_action('network_admin_notices');
        $notices = (string) ob_get_clean();

        $this->assertStringNotContainsString('Another version of MCP Adapter', $notices);
        $this->assertStringNotContainsString('MCP Adapter: The Composer autoloader was not found', $notices);
    }
}
