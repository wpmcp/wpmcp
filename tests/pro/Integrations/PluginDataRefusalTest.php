<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\MCP\Hidden_Tool_Refusal;
use WPMCP\MCP\Registrar;
use WPMCP\MCP\Stdio_Transport;
use WPMCP\Plugin;
use WPMCP\Pro\Gate;
use WPMCP\Tools\Dispatch\Call_Tool;
use WPMCP\Tools\Dispatch\Get_Tool_Schema;

/**
 * Issue #815: with none of the plugin-data plugins active the pair is not
 * registered, and a call to one of its ops names the plugin that op works
 * with (MemberPress for a memberpress-* op), the way an op guard names
 * WooCommerce Subscriptions. Without a known op the refusal lists every
 * plugin the pair works with, Amelia, Polylang, WPML and MemberPress
 * included.
 */
class PluginDataRefusalTest extends \WP_UnitTestCase
{
    /** Every plugin-data presence filter, all forced off so the pair is held, not registered. */
    private const PRESENCE = [
        'wpmcp_amelia_active', 'wpmcp_awesome_support_active', 'wpmcp_bbpress_active', 'wpmcp_betterdocs_active', 'wpmcp_buddypress_active', 'wpmcp_code_snippets_active',
        'wpmcp_elementor_pro_active', 'wpmcp_fluent_support_active', 'wpmcp_fluentcrm_active', 'wpmcp_jetengine_active', 'wpmcp_learndash_active',
        'wpmcp_lifterlms_active', 'wpmcp_mailpoet_active', 'wpmcp_memberpress_active', 'wpmcp_multilingual_active',
        'wpmcp_pods_active', 'wpmcp_tablepress_active', 'wpmcp_translatepress_active', 'wpmcp_tutor_active',
        'wpmcp_ws_form_active',
        'wpmcp_wp_activity_log_active',
        'wpmcp_simple_history_active',
        'wpmcp_action_scheduler_active',
        'wpmcp_wp_mail_logging_active',
        'wpmcp_check_email_active',
        'wpmcp_email_log_active',
        'wpmcp_weglot_active',
        'wpmcp_gtranslate_active',
        'wpmcp_loco_active',
        'wpmcp_surecart_active',
        'wpmcp_wpgraphql_active',
        'wpmcp_checkout_field_editor_active',
    ];

    private Registrar $original;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        foreach (self::PRESENCE as $filter) {
            add_filter($filter, '__return_false', 999);
        }
        $this->original = Plugin::instance()->registrar();
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(Plugin::class, 'registrar'))->setValue(Plugin::instance(), $this->original);
        foreach (self::PRESENCE as $filter) {
            remove_filter($filter, '__return_false', 999);
        }
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    /** Runs Plugin's own registration loop over the real pair into a fresh Registrar, made the live one. */
    private static function register(Plugin_Data_Integration $integration): Registrar
    {
        $fresh = new Registrar();
        (new \ReflectionProperty(Plugin::class, 'registrar'))->setValue(Plugin::instance(), $fresh);
        (new \ReflectionMethod(Plugin::class, 'register_integrations'))->invoke(Plugin::instance(), $fresh, [ $integration ]);
        return $fresh;
    }

    private static function call(string $tool, array $arguments): \WP_Error
    {
        $result = (new Call_Tool())->handle(['name' => $tool, 'arguments' => $arguments]);
        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('plugin_missing', $result->get_error_code());
        return $result;
    }

    public function test_a_memberpress_op_names_memberpress(): void
    {
        $integration = new Plugin_Data_Integration();
        $this->assertFalse($integration->is_available());
        $registrar = self::register($integration);
        $this->assertNull($registrar->get('wpmcp/plugin-data-read'));

        $result = self::call('wpmcp/plugin-data-read', ['operation' => 'memberpress-list-memberships', 'args' => []]);
        $this->assertSame(
            'MemberPress is not active on this site, so op "memberpress-list-memberships" of wpmcp/plugin-data-read cannot run. Install and activate MemberPress (' . admin_url('plugins.php') . '), then reconnect the MCP client so it reloads its tool list.',
            $result->get_error_message()
        );
        $this->assertSame(
            [ 'plugin' => 'MemberPress', 'operation' => 'memberpress-list-memberships', 'plugins_url' => admin_url('plugins.php') ],
            $result->get_error_data()
        );
    }

    public function test_each_op_names_its_own_plugin(): void
    {
        self::register(new Plugin_Data_Integration());

        $amelia = self::call('wpmcp/plugin-data-read', ['operation' => 'amelia-list-services']);
        $this->assertStringStartsWith('Amelia is not active on this site', $amelia->get_error_message());

        $tutor = self::call('wpmcp/plugin-data-read', ['operation' => 'tutor-list-courses']);
        $this->assertStringStartsWith('Tutor LMS is not active on this site', $tutor->get_error_message());

        $i18n = self::call('wpmcp/plugin-data-write', ['operation' => 'create-translation']);
        $this->assertSame(
            'Op "create-translation" of wpmcp/plugin-data-write works with Polylang, WPML, and none of them is active on this site, so it cannot run. Install and activate one of them (' . admin_url('plugins.php') . '), then reconnect the MCP client so it reloads its tool list.',
            $i18n->get_error_message()
        );
    }

    public function test_no_op_lists_every_plugin(): void
    {
        self::register(new Plugin_Data_Integration());

        $message = self::call('wpmcp/plugin-data-read', [])->get_error_message();
        $this->assertStringStartsWith('None of the plugins wpmcp/plugin-data-read works with is active on this site (', $message);
        foreach ([ 'MemberPress', 'Amelia', 'Polylang', 'WPML', 'Elementor Pro', 'WS Form', 'WP Activity Log', 'WP Mail Logging' ] as $plugin) {
            $this->assertStringContainsString($plugin, $message);
        }

        $schema = (new Get_Tool_Schema())->handle(['name' => 'wpmcp/plugin-data-read']);
        $this->assertSame('plugin_missing', $schema->get_error_code());
        $this->assertStringContainsString('MemberPress', $schema->get_error_message());
    }

    /**
     * Issue #1036: an op no pack defines answers unknown_operation with the
     * valid ops, as it does while a covered plugin is active, not "install
     * a plugin".
     */
    public function test_an_unknown_op_answers_unknown_operation_with_no_covered_plugin_active(): void
    {
        self::register(new Plugin_Data_Integration());

        foreach ([ 'read' => 'jetengine-list-fields', 'write' => 'create-translation' ] as $channel => $valid) {
            $tool = "wpmcp/plugin-data-{$channel}";
            foreach ([ 'nosuch-operation', 'list-memberships' ] as $op) {
                $result = (new Call_Tool())->handle(['name' => $tool, 'arguments' => ['operation' => $op]]);
                $this->assertInstanceOf(\WP_Error::class, $result);
                $this->assertSame('unknown_operation', $result->get_error_code(), "{$tool} {$op}");
                $this->assertSame(sprintf('Unknown %s operation "%s" for the plugin-data integration.', $channel, $op), $result->get_error_message());
                $this->assertContains($valid, $result->get_error_data()['operations'] ?? []);
                $this->assertStringNotContainsString('Install and activate', $result->get_error_message());
            }

            // The same answer on the transports that see only the tool name.
            $direct = Plugin::instance()->registrar()->plugin_inactive_for_tool(Stdio_Transport::tool_name($tool), [ 'operation' => 'nosuch-operation' ]);
            $this->assertSame('unknown_operation', $direct->get_error_code());
        }

        // An op of the other half is unknown there, as it is while the pair is registered.
        $this->assertSame(
            'unknown_operation',
            (new Call_Tool())->handle(['name' => 'wpmcp/plugin-data-write', 'arguments' => ['operation' => 'jetengine-list-fields']])->get_error_code()
        );
        // list-operations is the read half's own op, never unknown.
        $this->assertSame('plugin_missing', self::call('wpmcp/plugin-data-read', ['operation' => 'list-operations'])->get_error_code());
        // A known op still names its own plugin.
        $this->assertStringStartsWith('MemberPress is not active', self::call('wpmcp/plugin-data-read', ['operation' => 'memberpress-list-memberships'])->get_error_message());
    }

    /** Issue #1036: the capability check comes first, so a caller who could not run the pair learns no op list. */
    public function test_a_caller_without_the_capability_gets_no_op_list(): void
    {
        self::register(new Plugin_Data_Integration());
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));

        $result = (new Call_Tool())->handle(['name' => 'wpmcp/plugin-data-read', 'arguments' => ['operation' => 'nosuch-operation']]);
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertNotSame('unknown_operation', $result->get_error_code());
        $this->assertArrayNotHasKey('operations', (array) $result->get_error_data());
    }

    /** Issue #876: a direct tools/call for the unregistered pair names the plugin over stdio. */
    public function test_a_direct_stdio_call_names_the_plugin_not_unknown_tool(): void
    {
        self::register(new Plugin_Data_Integration());

        // Stdio calls this from call_tool() when no registered ability has the
        // tool name (the shared test ability registry still holds the real pair).
        $error = Plugin::instance()->registrar()->plugin_inactive_for_tool('wpmcp-plugin-data-write', [ 'operation' => 'wsform-update-entry-status' ]);
        $this->assertInstanceOf(\WP_Error::class, $error);
        $result = Hidden_Tool_Refusal::result($error);
        $this->assertTrue($result['isError']);
        $this->assertStringContainsString('WS Form is not active on this site', $result['content'][0]['text']);
        $this->assertStringContainsString(admin_url('plugins.php'), $result['content'][0]['text']);
        $this->assertSame('plugin_missing', $result['structuredContent']['error']['code']);
        $this->assertSame('WS Form', $result['structuredContent']['error']['data']['plugin']);
        $this->assertNull(Plugin::instance()->registrar()->plugin_inactive_for_tool('wpmcp-no-such-tool'));

        // A name that is no tool at all is still unknown.
        $unknown = (new Stdio_Transport())->handle_request([ 'jsonrpc' => '2.0', 'id' => 6, 'method' => 'tools/call', 'params' => [ 'name' => 'wpmcp-no-such-tool', 'arguments' => [] ] ]);
        $this->assertSame('Unknown tool: wpmcp-no-such-tool', $unknown['error']['message']);
    }

    /** Issue #876: the HTTP adapter's "Tool not found" is replaced the same way. */
    public function test_the_http_tool_not_found_answer_is_replaced(): void
    {
        self::register(new Plugin_Data_Integration());
        $filter = new Hidden_Tool_Refusal();

        $call = [ 'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => [ 'name' => 'wpmcp-plugin-data-read', 'arguments' => [ 'operation' => 'betterdocs-list-docs' ] ] ];
        $miss = [ 'jsonrpc' => '2.0', 'id' => 2, 'error' => [ 'code' => -32003, 'message' => 'Tool not found: wpmcp-plugin-data-read' ] ];

        $request = new \WP_REST_Request('POST', '/mcp/wpmcp-server');
        $request->set_header('content-type', 'application/json');
        $request->set_body((string) wp_json_encode($call));
        $out = $filter->filter_response(new \WP_REST_Response($miss, 200), null, $request)->get_data();

        $this->assertArrayNotHasKey('error', $out);
        $this->assertSame(2, $out['id']);
        $this->assertTrue($out['result']['isError']);
        $this->assertStringContainsString('BetterDocs is not active on this site', $out['result']['content'][0]['text']);
        $this->assertSame(admin_url('plugins.php'), $out['result']['structuredContent']['error']['data']['plugins_url']);

        // A tool that is genuinely unknown keeps the adapter's answer.
        $other = $call;
        $other['params']['name'] = 'wpmcp-nope';
        $request->set_body((string) wp_json_encode($other));
        $this->assertSame($miss, $filter->filter_response(new \WP_REST_Response($miss, 200), null, $request)->get_data());
    }

    /** Issue #876: a batch with a notification in the middle is matched by id, not position. */
    public function test_a_batch_with_a_notification_is_matched_by_id(): void
    {
        self::register(new Plugin_Data_Integration());
        $filter = new Hidden_Tool_Refusal();

        $batch = [
            [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => [ 'name' => 'wpmcp-nope', 'arguments' => [] ] ],
            [ 'jsonrpc' => '2.0', 'method' => 'notifications/initialized' ],
            [ 'jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => [ 'name' => 'wpmcp-plugin-data-read', 'arguments' => [ 'operation' => 'betterdocs-list-docs' ] ] ],
        ];
        $answers = [
            [ 'jsonrpc' => '2.0', 'id' => 1, 'error' => [ 'code' => -32003, 'message' => 'Tool not found: wpmcp-nope' ] ],
            [ 'jsonrpc' => '2.0', 'id' => 3, 'error' => [ 'code' => -32003, 'message' => 'Tool not found: wpmcp-plugin-data-read' ] ],
        ];
        $request = new \WP_REST_Request('POST', '/mcp/wpmcp-server');
        $request->set_header('content-type', 'application/json');
        $request->set_body((string) wp_json_encode($batch));

        $out = $filter->filter_response(new \WP_REST_Response($answers, 200), null, $request)->get_data();

        $this->assertSame($answers[0], $out[0]);
        $this->assertSame(3, $out[1]['id']);
        $this->assertTrue($out[1]['result']['isError']);
        $this->assertStringContainsString('BetterDocs', $out[1]['result']['content'][0]['text']);
    }

    /** Every op the pair merges has a plugin, and that plugin is named in the pair's own refusal list. */
    public function test_every_op_has_a_host_named_in_the_pair_summary(): void
    {
        $integration = new Plugin_Data_Integration();
        $hosts       = $integration->operation_hosts();
        $summary     = $integration->host_plugin()['name'];

        $ops = array_column($integration->catalog()['operations'], 'name');
        $this->assertNotEmpty($ops);
        $this->assertEqualsCanonicalizing($ops, array_keys($hosts));

        foreach ($hosts as $op => $host) {
            foreach (explode(', ', $host['name']) as $plugin) {
                $this->assertStringContainsString($plugin, $summary, "{$op}: {$plugin} missing from the pair summary");
            }
        }
    }

    public function test_an_unlicensed_install_does_not_name_a_plugin_for_a_paid_op(): void
    {
        Gate::set_pro_for_tests(false);

        $hosts = (new Plugin_Data_Integration())->operation_hosts();

        $this->assertArrayNotHasKey('memberpress-list-memberships', $hosts);
        $this->assertArrayHasKey('jetengine-list-fields', $hosts);
    }
}
