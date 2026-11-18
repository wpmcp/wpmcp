<?php

namespace WPMCP\Tests\Free\MCP;

use WPMCP\Governance\Governance;
use WPMCP\MCP\Stdio_Transport;
use WPMCP\MCP\Tool_Exposure;
use WPMCP\Pro\Gate;

/**
 * notifications/tools/list_changed on stdio (issue #632). A stdio session
 * is long-lived, so when the set of tools this site can offer changes (a
 * plugin activated, a license added, a governance or exposure toggle), the
 * client is told once. HTTP is stateless and keeps listChanged:false.
 */
class StdioListChangedTest extends \WP_UnitTestCase
{
    public static function wpSetUpBeforeClass(): void
    {
        if (0 === did_action('wp_abilities_api_init')) {
            do_action('wp_abilities_api_init');
        }
    }

    protected function tearDown(): void
    {
        Gate::set_pro_for_tests(null);
        delete_option(Tool_Exposure::OPTION);
        parent::tearDown();
    }

    private static function initialize(Stdio_Transport $transport): array
    {
        $response = $transport->handle_request([
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'initialize',
            'params'  => [ 'protocolVersion' => '2025-11-25' ],
        ]);

        return $response['result']['capabilities'];
    }

    private static function watched(): Stdio_Transport
    {
        $transport = new Stdio_Transport();
        $transport->watch_tool_list();
        return $transport;
    }

    public function test_a_watched_stdio_session_advertises_list_changed(): void
    {
        $this->assertSame([ 'listChanged' => true ], self::initialize(self::watched())['tools']);
    }

    public function test_an_unwatched_transport_keeps_list_changed_false(): void
    {
        // The HTTP 2026 bridge and tests drive the transport unwatched.
        $this->assertSame([ 'listChanged' => false ], self::initialize(new Stdio_Transport())['tools']);
        $this->assertSame([ 'listChanged' => false ], Stdio_Transport::capabilities()['tools']);
    }

    public function test_activating_a_stub_plugin_mid_session_emits_one_notification(): void
    {
        $transport = self::watched();
        self::initialize($transport);

        $this->assertNull($transport->tool_list_notification(), 'nothing changed yet');

        $active   = (array) get_option('active_plugins', []);
        $active[] = 'wpmcp-stub-plugin/wpmcp-stub-plugin.php';
        update_option('active_plugins', $active);

        $this->assertSame(
            [ 'jsonrpc' => '2.0', 'method' => 'notifications/tools/list_changed' ],
            $transport->tool_list_notification()
        );
        $this->assertNull($transport->tool_list_notification(), 'one notification per change');
    }

    public function test_license_governance_and_exposure_changes_each_notify(): void
    {
        $transport = self::watched();
        self::initialize($transport);

        Gate::set_pro_for_tests(! Gate::is_pro());
        $this->assertNotNull($transport->tool_list_notification(), 'license change');

        $settings                    = (array) get_option(Governance::OPTION, []);
        $settings['wpmcp_test_flip'] = true;
        update_option(Governance::OPTION, $settings);
        $this->assertNotNull($transport->tool_list_notification(), 'governance change');

        update_option(Tool_Exposure::OPTION, Tool_Exposure::MODE_COMPACT);
        $this->assertNotNull($transport->tool_list_notification(), 'exposure change');

        $this->assertNull($transport->tool_list_notification());
    }

    public function test_no_notification_before_initialize_or_when_unwatched(): void
    {
        $before = self::watched();
        update_option('active_plugins', array_merge((array) get_option('active_plugins', []), [ 'wpmcp-stub-two/x.php' ]));
        $this->assertNull($before->tool_list_notification(), 'not before initialize');

        $unwatched = new Stdio_Transport();
        self::initialize($unwatched);
        update_option('active_plugins', array_merge((array) get_option('active_plugins', []), [ 'wpmcp-stub-three/x.php' ]));
        $this->assertNull($unwatched->tool_list_notification());
    }
}
