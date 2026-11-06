<?php

namespace WPMCP\Tests\Free\Packages;

use WPMCP\Safety\Operation_Context;
use WPMCP\Safety\Safe_Mutation;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\List_Operations;
use WPMCP\Tools\Packages\Activate_Plugin;
use WPMCP\Tools\Packages\Install_Plugin;
use WPMCP\Tools\Packages\Plugin_State;

/**
 * Issue #728: a plugin activation that failed was still recorded as a
 * mutation, so the Audit Log and list-operations listed a change the site
 * never had. A failed activation now leaves no row, the reply says what the
 * site's state is, and activations are serialised.
 */
class FailedPluginWriteLedgerTest extends \WP_UnitTestCase
{
    /** @var string[] Plugin files this test wrote, relative to WP_PLUGIN_DIR. */
    private array $written = [];

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        Operation_Context::reset();
        $user_id = self::factory()->user->create([ 'role' => 'administrator' ]);
        wp_set_current_user($user_id);
        wpmcp_test_grant_network_admin($user_id);
    }

    protected function tearDown(): void
    {
        foreach ($this->written as $file) {
            deactivate_plugins([ $file ], true);
            @unlink(WP_PLUGIN_DIR . '/' . $file);
            @rmdir(WP_PLUGIN_DIR . '/' . dirname($file));
        }
        wp_clean_plugins_cache(false);
        deactivate_plugins([ 'hello.php' ], true);
        remove_all_filters('wpmcp_install_plugin_time_left');
        remove_all_filters('wpmcp_plugin_lock_wait');
        wp_set_current_user(0);
        parent::tearDown();
    }

    /** A real plugin (header and all) whose main file runs $body when loaded. */
    private function plugin(string $body): string
    {
        $file = 'wpmcp-728-' . wp_generate_password(6, false, false) . '/plugin.php';
        wp_mkdir_p(WP_PLUGIN_DIR . '/' . dirname($file));
        file_put_contents(WP_PLUGIN_DIR . '/' . $file, "<?php\n/*\nPlugin Name: WPMCP 728 test\n*/\n" . $body . "\n");
        $this->written[] = $file;
        wp_clean_plugins_cache(false);
        return $file;
    }

    private function rows_for(string $session): array
    {
        return (new List_Operations())->handle([ 'session_id' => $session ])['operations'];
    }

    public function test_an_activation_that_fails_leaves_no_ledger_row(): void
    {
        // Output on activation: core writes active_plugins, then reports
        // unexpected_output, so the write is rolled back by the verify step.
        $plugin = $this->plugin('echo "noise";');
        $before = (array) get_option('active_plugins', []);

        try {
            (new Activate_Plugin())->handle([ 'plugin' => $plugin, 'session_id' => 'sess-728-a' ]);
            $this->fail('A failed activation must not report success.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('was not activated', $e->getMessage());
        }

        $this->assertSame($before, (array) get_option('active_plugins', []));
        $this->assertSame([], $this->rows_for('sess-728-a'));
        $this->assertSame([], Operation_Context::all(), 'No request row may link to the voided undo point.');
    }

    public function test_an_activation_that_throws_leaves_no_ledger_row(): void
    {
        $plugin = $this->plugin('throw new \RuntimeException("boom on load");');
        $before = (array) get_option('active_plugins', []);
        $level  = ob_get_level();

        try {
            (new Activate_Plugin())->handle([ 'plugin' => $plugin, 'session_id' => 'sess-728-b' ]);
            $this->fail('An activation that throws must not report success.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('boom on load', $e->getMessage());
        } finally {
            // Core's activation sandbox opened a buffer the throw skipped closing.
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }

        $this->assertSame($before, (array) get_option('active_plugins', []));
        $this->assertSame([], $this->rows_for('sess-728-b'));
    }

    public function test_an_install_whose_activation_fails_reports_the_state_and_records_nothing(): void
    {
        $plugin = $this->plugin('echo "noise";');

        $out = (new Install_Plugin())->activate_installed($plugin, [ 'session_id' => 'sess-728-c' ]);

        $this->assertFalse($out['activated']);
        $this->assertArrayNotHasKey('operation_id', $out);
        $this->assertNotContains($plugin, (array) get_option('active_plugins', []));
        $this->assertSame([], $this->rows_for('sess-728-c'));
    }

    public function test_activation_is_skipped_when_the_time_limit_is_nearly_spent(): void
    {
        add_filter('wpmcp_install_plugin_time_left', static fn () => 5.0);
        $this->assertFalse(Install_Plugin::time_left_for_activation());

        remove_all_filters('wpmcp_install_plugin_time_left');
        add_filter('wpmcp_install_plugin_time_left', static fn () => null);
        $this->assertTrue(Install_Plugin::time_left_for_activation());
    }

    public function test_a_successful_activation_is_still_recorded(): void
    {
        $out = (new Install_Plugin())->activate_installed('hello.php', [ 'session_id' => 'sess-728-d' ]);

        $this->assertTrue($out['activated']);
        $rows = $this->rows_for('sess-728-d');
        $this->assertCount(1, $rows);
        $this->assertSame($out['operation_id'], $rows[0]['operation_id']);
        $this->assertSame('hello.php', $rows[0]['object_ref']);
    }

    public function test_a_parallel_activation_is_refused_while_another_holds_the_lock(): void
    {
        $other = new \wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
        $this->assertSame('1', (string) $other->get_var($other->prepare('SELECT GET_LOCK(%s, 0)', Plugin_State::lock_name())));
        add_filter('wpmcp_plugin_lock_wait', static fn () => 0);

        try {
            (new Activate_Plugin())->handle([ 'plugin' => 'hello.php', 'session_id' => 'sess-728-e' ]);
            $this->fail('An activation must wait for, or refuse during, another one.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Another plugin activation', $e->getMessage());
        } finally {
            $other->query($other->prepare('SELECT RELEASE_LOCK(%s)', Plugin_State::lock_name()));
            $other->close();
        }

        $this->assertNotContains('hello.php', (array) get_option('active_plugins', []));
        $this->assertSame([], $this->rows_for('sess-728-e'));
    }

    public function test_a_failed_write_that_changed_the_probed_state_keeps_its_undo_point(): void
    {
        update_option('wpmcp_728_probe', 'before');
        try {
            Safe_Mutation::run(
                [
                    'object_type' => 'option',
                    'object_id'   => 'wpmcp_728_probe',
                    'session_id'  => 'sess-728-f',
                    'tool_name'   => 'update-option',
                    'args'        => [],
                    'state_probe' => static fn () => get_option('wpmcp_728_probe'),
                ],
                static function (): void {
                    update_option('wpmcp_728_probe', 'half-written');
                    throw new \RuntimeException('failed after the write');
                }
            );
            $this->fail('The failure must propagate.');
        } catch (\RuntimeException $e) {
            $this->assertSame('failed after the write', $e->getMessage());
        }

        $this->assertCount(1, $this->rows_for('sess-728-f'), 'A write that did change something keeps its undo point.');
    }
}
