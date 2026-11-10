<?php

namespace WPMCP\Tests\Free\Packages;

use WPMCP\Tools\Packages\Manage_Updates;

/**
 * Issue #600: manage-updates type=plugin|theme saved the auto-update change
 * and then threw "Call to undefined function wp_is_auto_update_enabled_for_type()"
 * over MCP, so the caller got an error and no operation_id for a change that
 * had been made. The function lives in wp-admin/includes/update.php, which a
 * REST request does not load; the core path required it, the auto-update
 * path did not.
 *
 * In this suite other plugins load update.php while WordPress boots, which is
 * why ManageUpdatesTest never saw the error. The check here runs in a fresh
 * PHP process that boots WordPress with no plugins and no wp-admin includes.
 */
class ManageUpdatesFreshRequestTest extends \WP_UnitTestCase
{
    public function test_effective_setting_resolves_without_wp_admin_update_php_loaded(): void
    {
        if (is_multisite()) {
            $this->markTestSkipped('The auto-update toggle refuses on a network before it reads the effective setting.');
        }
        $config = getenv('WP_TESTS_CONFIG_FILE_PATH');
        if (! $config) {
            $config = rtrim((string) getenv('WP_TESTS_DIR'), '/') . '/wp-tests-config.php';
        }
        if (! is_readable($config)) {
            $this->markTestSkipped('No wp-tests-config.php to boot a fresh WordPress from.');
        }

        $root  = dirname(__DIR__, 3);
        $probe = $root . '/tests/support/probes/auto-update-effective.php';
        $cmd   = sprintf('%s %s %s %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($probe), escapeshellarg($config), escapeshellarg($root));

        $output = (string) shell_exec($cmd);
        $json   = substr($output, (int) strrpos($output, '{"preloaded"'));
        $result = json_decode($json, true);

        $this->assertIsArray($result, 'The probe must print its result: ' . $output);
        $this->assertFalse($result['preloaded'], 'The probe must start without wp-admin/includes/update.php, as a REST request does');
        $this->assertNull($result['error'], 'Reading the effective auto-update setting must not fail: ' . (string) $result['error']);
        $this->assertIsBool($result['plugin']);
        $this->assertIsBool($result['theme']);
    }

    public function test_the_toggle_reports_the_effective_setting(): void
    {
        if (is_multisite()) {
            $this->markTestSkipped('The auto-update toggle refuses on a network.');
        }
        \WPMCP\Safety\Snapshot_Store::install();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        update_option('auto_update_plugins', []);

        add_filter('plugins_auto_update_enabled', '__return_false');
        try {
            $out = (new Manage_Updates())->handle(['type' => 'plugin', 'item' => 'akismet/akismet.php', 'enabled' => true]);
        } finally {
            remove_filter('plugins_auto_update_enabled', '__return_false');
        }

        $this->assertArrayHasKey('operation_id', $out);
        $this->assertTrue($out['auto_update']);
        $this->assertFalse($out['effective'], 'effective reports the site-wide plugins_auto_update_enabled setting');
    }
}
