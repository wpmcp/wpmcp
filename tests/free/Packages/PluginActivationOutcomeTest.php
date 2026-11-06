<?php

namespace WPMCP\Tests\Free\Packages;

use WPMCP\MCP\Registrar;
use WPMCP\Safety\Operation_Context;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Packages\Activate_Plugin;
use WPMCP\Tools\Packages\Activation_Guard;
use WPMCP\Tools\Packages\Install_Plugin;

/**
 * What an activation tells the client, whatever the plugin does:
 *
 * - issue #728 (reopened): a plugin that redirects while it is activated
 *   (wp_redirect() then exit, or a raw Location header then exit, as a
 *   bundled SDK does when it finds itself newer than one already loaded)
 *   ended the MCP request with a bare 302 to the endpoint. The redirect
 *   must not escape, and a request that is ended anyway answers JSON.
 * - issue #746: WordPress's activation and install errors are HTML with an
 *   internal link; the client gets plain text.
 * - issue #747: activating an active plugin changes nothing, so it reports
 *   already_active and records no operation.
 */
class PluginActivationOutcomeTest extends \WP_UnitTestCase
{
    /** @var string[] Plugin files this test wrote, relative to WP_PLUGIN_DIR. */
    private array $written = [];

    private const HTML_ERROR = '<p><strong>Error:</strong> CF7 Apps requires 1 plugin to be installed &amp; activated: contact-form-7. <a href="http://localhost:8080/wp-admin/plugins.php">Manage plugins</a>.</p>';

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
        if (is_multisite()) {
            deactivate_plugins([ 'hello.php' ], true, true);
        }
        remove_all_filters('plugins_api');
        remove_all_filters('upgrader_pre_download');
        self::reset_plugin_dependencies();
        wp_set_current_user(0);
        parent::tearDown();
    }

    /** A real plugin (header and all) whose main file runs $body when loaded. */
    private function plugin(string $body): string
    {
        $file = 'wpmcp-act-' . wp_generate_password(6, false, false) . '/plugin.php';
        wp_mkdir_p(WP_PLUGIN_DIR . '/' . dirname($file));
        file_put_contents(WP_PLUGIN_DIR . '/' . $file, "<?php\n/*\nPlugin Name: WPMCP activation test\n*/\n" . $body . "\n");
        $this->written[] = $file;
        wp_clean_plugins_cache(false);
        return $file;
    }

    private function assert_plain(string $message): void
    {
        $this->assertStringNotContainsString('<', $message);
        $this->assertStringNotContainsString('&amp;', $message);
        $this->assertStringNotContainsString('localhost:8080', $message);
        $this->assertStringNotContainsString('Manage plugins', $message);
        $this->assertStringContainsString('Error: CF7 Apps requires 1 plugin to be installed & activated: contact-form-7.', $message);
    }

    // ---- #728: a redirect during activation does not escape -----------------

    public function test_a_redirect_while_the_plugin_loads_does_not_end_the_request(): void
    {
        // If the guard failed, exit would end the PHPUnit run here.
        $plugin = $this->plugin('wp_redirect("/wp-json/mcp/wpmcp-server"); exit;');
        $rows   = Snapshot_Store::row_count();
        $level  = ob_get_level();

        try {
            (new Activate_Plugin())->handle([ 'plugin' => $plugin, 'session_id' => 'sess-act-a' ]);
            $this->fail('An activation that never reached the active list must not report success.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('was not activated', $e->getMessage());
            $this->assertStringContainsString('redirect', $e->getMessage());
        }

        $this->assertSame($level, ob_get_level(), 'The activation sandbox buffer is closed again.');
        $this->assertNotContains($plugin, (array) get_option('active_plugins', []));
        $this->assertSame($rows, Snapshot_Store::row_count(), 'No operation row for an activation that did not happen.');
        $this->assertFalse(Activation_Guard::in_progress());
    }

    public function test_a_redirect_after_the_activation_landed_keeps_the_activation(): void
    {
        // The welcome-page pattern: redirect from activated_plugin, after
        // core has written active_plugins.
        $plugin = $this->plugin('add_action("activated_plugin", static function () { wp_safe_redirect(admin_url("index.php?welcome=1")); exit; });');

        $out = (new Activate_Plugin())->handle([ 'plugin' => $plugin, 'session_id' => 'sess-act-b' ]);

        $this->assertTrue($out['activated']);
        $this->assertArrayHasKey('operation_id', $out);
        $this->assertContains($plugin, (array) get_option('active_plugins', []));
        $this->assertNotNull(Snapshot_Store::get_by_operation($out['operation_id']));
    }

    public function test_install_activate_step_survives_a_redirect(): void
    {
        $plugin = $this->plugin('wp_redirect("/wp-json/mcp/wpmcp-server"); exit;');

        $out = (new Install_Plugin())->activate_installed($plugin, [ 'session_id' => 'sess-act-c' ]);

        $this->assertFalse($out['activated']);
        $this->assertArrayNotHasKey('operation_id', $out);
        $this->assertStringContainsString('redirect', $out['error']);
    }

    public function test_a_request_ended_during_activation_answers_json_rpc(): void
    {
        $raw  = (string) wp_json_encode([ 'jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call', 'params' => [ 'name' => 'wpmcp-activate-plugin' ] ]);
        $body = Activation_Guard::interrupted_response('tablepress/tablepress.php', false, $raw);

        $this->assertIsString($body);
        $decoded = json_decode($body, true);
        $this->assertSame('2.0', $decoded['jsonrpc']);
        $this->assertSame(7, $decoded['id']);
        $this->assertTrue($decoded['result']['isError']);
        $text = $decoded['result']['content'][0]['text'];
        $this->assertStringContainsString('tablepress/tablepress.php', $text);
        $this->assertStringContainsString('was not activated', $text);

        $active = json_decode((string) Activation_Guard::interrupted_response('hello.php', true, $raw), true);
        $this->assertStringContainsString('is active', $active['result']['content'][0]['text']);

        $this->assertNull(Activation_Guard::interrupted_response('hello.php', false, '{"jsonrpc":"2.0","method":"notifications/x"}'), 'A notification has no id to answer.');
        $this->assertNull(Activation_Guard::interrupted_response('hello.php', false, 'not json'));
    }

    // ---- #746: plain-text errors -------------------------------------------

    /** A plugin whose Requires Plugins header names a plugin that is not installed. */
    private function dependent_plugin(): string
    {
        $file = 'wpmcp-act-' . wp_generate_password(6, false, false) . '/plugin.php';
        wp_mkdir_p(WP_PLUGIN_DIR . '/' . dirname($file));
        file_put_contents(WP_PLUGIN_DIR . '/' . $file, "<?php\n/*\nPlugin Name: WPMCP Dependent & Co\nRequires Plugins: wpmcp-missing-dependency\n*/\n");
        $this->written[] = $file;
        wp_clean_plugins_cache(false);
        // Core reads dependency headers once per request; read them again,
        // and answer its wordpress.org lookup offline.
        add_filter('plugins_api', static fn () => new \WP_Error('offline', 'offline'));
        self::reset_plugin_dependencies();
        return $file;
    }

    private static function reset_plugin_dependencies(): void
    {
        if (! class_exists('WP_Plugin_Dependencies')) {
            return;
        }
        foreach ((new \ReflectionClass(\WP_Plugin_Dependencies::class))->getProperties(\ReflectionProperty::IS_STATIC) as $property) {
            $property->setValue(null, 'initialized' === $property->getName() ? false : null);
        }
    }

    public function test_activation_error_is_plain_text_in_both_tools(): void
    {
        // Core refuses with an HTML WP_Error ("<p><strong>Error:</strong>
        // ... <a href=...>Manage plugins</a>.</p>"), the message the issue saw.
        $plugin = $this->dependent_plugin();
        $raw    = activate_plugin($plugin);
        $this->assertWPError($raw);
        $this->assertStringContainsString('<a ', $raw->get_error_message(), 'Precondition: core still sends HTML.');

        try {
            (new Activate_Plugin())->handle([ 'plugin' => $plugin, 'session_id' => 'sess-act-e' ]);
            $this->fail('A refused activation must not report success.');
        } catch (\RuntimeException $e) {
            $message = Registrar::plain_text($e->getMessage());
            $this->assertStringNotContainsString('<', $message);
            $this->assertStringNotContainsString('&amp;', $message);
            $this->assertStringNotContainsString('Manage plugins', $message);
            $this->assertStringNotContainsString(admin_url(), $message);
            $this->assertStringContainsString('Error: WPMCP Dependent & Co requires', $message);
        }

        $out = (new Install_Plugin())->activate_installed($plugin, [ 'session_id' => 'sess-act-e' ]);
        $this->assertFalse($out['activated']);
        $this->assertStringNotContainsString('<', $out['error']);
        $this->assertStringNotContainsString('Manage plugins', $out['error']);
        $this->assertStringContainsString('Error: WPMCP Dependent & Co requires', $out['error']);
    }

    public function test_install_step_error_is_plain_text(): void
    {
        $html = self::HTML_ERROR;
        add_filter('plugins_api', static fn () => (object) [ 'download_link' => 'https://downloads.example.invalid/x.zip' ]);
        add_filter('upgrader_pre_download', static fn () => new \WP_Error('download_failed', $html));

        try {
            (new Install_Plugin())->handle([ 'slug' => 'contact-form-7-honeypot' ]);
            $this->fail('A failed install must not report success.');
        } catch (\RuntimeException $e) {
            $message = Registrar::plain_text($e->getMessage());
            $this->assertStringStartsWith('Plugin install failed: ', $message);
            $this->assert_plain($message);
        }
    }

    public function test_plain_message_strips_markup_links_and_entities(): void
    {
        $this->assertSame(
            'Error: CF7 Apps requires 1 plugin to be installed & activated: contact-form-7.',
            Activation_Guard::plain_message(self::HTML_ERROR)
        );
        $this->assertSame('Plain already.', Activation_Guard::plain_message('Plain already.'));
    }

    // ---- #747: already active ----------------------------------------------

    public function test_activating_an_active_plugin_records_nothing(): void
    {
        $first = (new Activate_Plugin())->handle([ 'plugin' => 'hello.php', 'session_id' => 'sess-act-f' ]);
        $this->assertTrue($first['activated']);
        $rows = Snapshot_Store::row_count();

        $again = (new Activate_Plugin())->handle([ 'plugin' => 'hello.php', 'session_id' => 'sess-act-f' ]);

        $this->assertFalse($again['activated']);
        $this->assertTrue($again['already_active']);
        $this->assertArrayNotHasKey('operation_id', $again);
        $this->assertSame($rows, Snapshot_Store::row_count());
    }

    public function test_activating_a_network_active_plugin_network_wide_records_nothing(): void
    {
        if (! is_multisite()) {
            $this->markTestSkipped('network_wide needs a multisite network.');
        }
        activate_plugin('hello.php', '', true);
        $rows = Snapshot_Store::row_count();

        $again = (new Activate_Plugin())->handle([ 'plugin' => 'hello.php', 'network_wide' => true, 'session_id' => 'sess-act-g' ]);

        $this->assertFalse($again['activated']);
        $this->assertTrue($again['already_active']);
        $this->assertTrue($again['network_wide']);
        $this->assertArrayNotHasKey('operation_id', $again);
        $this->assertSame($rows, Snapshot_Store::row_count());
    }

    public function test_install_activate_step_on_an_active_plugin_records_nothing(): void
    {
        activate_plugin('hello.php');
        $rows = Snapshot_Store::row_count();

        $out = (new Install_Plugin())->activate_installed('hello.php', [ 'session_id' => 'sess-act-h' ]);

        $this->assertFalse($out['activated']);
        $this->assertTrue($out['already_active']);
        $this->assertArrayNotHasKey('operation_id', $out);
        $this->assertSame($rows, Snapshot_Store::row_count());
    }
}
