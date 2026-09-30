<?php

namespace WPMCP\Tests\Free\Governance;

use WPMCP\Governance\Governance;
use WPMCP\Governance\Governance_Audit_Log;
use WPMCP\Governance\Site_Wide_Governance;
use WPMCP\Identity\Identity_Context;
use WPMCP\MCP\Stdio_Transport;
use WPMCP\Tools\Governance\Get_Governance_Settings;
use WPMCP\Tools\Governance\List_Governance_Audit_Log;
use WPMCP\Tools\Governance\Update_Governance_Settings;

/**
 * Site-wide governance and audit of ability calls that do not come through
 * the MCP endpoint (issue #412).
 *
 * Core runs every ability through WP_Ability::execute(), whichever door the
 * call came in by: its own REST run route, another MCP server on the site,
 * or plain PHP from another plugin. Core 7.1 fires wp_pre_execute_ability,
 * wp_before_execute_ability and wp_after_execute_ability around that, so the
 * audit log can cover calls wpmcp does not transport. The setting is off by
 * default; "audit" logs, "enforce" also refuses abilities an admin disabled
 * by name. Calls through wpmcp's own endpoint are logged exactly once.
 */
class SiteWideGovernanceTest extends \WP_Test_REST_TestCase
{
    private const ECHO    = 'wpmcptest/echo';
    private const DENIED  = 'wpmcptest/denied';
    private const FAILING = 'wpmcptest/failing';

    private const FIXTURES = [self::ECHO, self::DENIED, self::FAILING];

    private const RUN_ROUTE = '/wp-abilities/v1/abilities/%s/run';
    private const MCP_ROUTE = '/mcp/wpmcp-server';

    /** A value that must never reach the audit log (redaction parity). */
    private const SECRET = 'hunter2-never-logged';

    /** @var int How many times a fixture handler ran. */
    private static $handler_runs = 0;

    private ?string $session  = null;
    private ?string $protocol = null;

    public static function wpSetUpBeforeClass(): void
    {
        if (0 === did_action('wp_abilities_api_init')) {
            do_action('wp_abilities_api_init');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists('WP_Filter_Sentinel')) {
            $this->markTestSkipped('wp_pre_execute_ability needs WordPress 7.1.');
        }

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        delete_option(Governance_Audit_Log::OPTION);
        delete_option(Site_Wide_Governance::OPTION);
        Governance::reset_for_tests();
        Identity_Context::set_current_for_tests(null);
        self::$handler_runs = 0;
        add_filter('wpmcp_rate_limit', static fn () => 100000);

        wp_get_abilities();
        remove_all_actions('wp_abilities_api_init');
        add_action('wp_abilities_api_init', [$this, 'register_fixtures']);
        do_action('wp_abilities_api_init');
    }

    protected function tearDown(): void
    {
        foreach (self::FIXTURES as $name) {
            if (function_exists('wp_has_ability') && wp_has_ability($name)) {
                wp_unregister_ability($name);
            }
        }
        remove_all_actions('wp_abilities_api_init');
        remove_all_filters('wpmcp_rate_limit');
        remove_all_filters('wpmcp_enable_ability_bridge');
        delete_option(Governance_Audit_Log::OPTION);
        delete_option(Site_Wide_Governance::OPTION);
        Governance::reset_for_tests();
        Identity_Context::set_current_for_tests(null);
        $this->session  = null;
        $this->protocol = null;
        global $wp_rest_server;
        $wp_rest_server = null;

        parent::tearDown();
    }

    public function register_fixtures(): void
    {
        $fixture = static function (string $label) {
            return [
                'label'               => $label,
                'description'         => $label . ' fixture standing in for a third-party ability.',
                'category'            => 'wpmcp',
                'input_schema'        => [
                    'type'       => 'object',
                    'properties' => ['id' => ['type' => 'integer'], 'token' => ['type' => 'string']],
                ],
                'execute_callback'    => static function ($input = null) use ($label) {
                    ++self::$handler_runs;
                    return ['ran' => $label];
                },
                'permission_callback' => '__return_true',
                'meta'                => ['show_in_rest' => true],
            ];
        };

        wp_register_ability(self::ECHO, $fixture('Echo'));

        $denied                        = $fixture('Denied');
        $denied['permission_callback'] = '__return_false';
        wp_register_ability(self::DENIED, $denied);

        $failing                     = $fixture('Failing');
        $failing['execute_callback'] = static function () {
            ++self::$handler_runs;
            return new \WP_Error('fixture_broke', 'The fixture failed.');
        };
        wp_register_ability(self::FAILING, $failing);
    }

    // ---------------------------------------------------------------
    // Helpers

    private function set_mode(string $mode): void
    {
        $out = (new Update_Governance_Settings())->handle(['site_wide' => $mode]);
        $this->assertSame($mode, $out['updated']['site_wide'] ?? null);
    }

    /** @return array<int, array<string, mixed>> audit rows for one ability, newest first. */
    private function rows(string $ability): array
    {
        return array_values(array_filter(
            Governance_Audit_Log::list(),
            static fn ($row) => $ability === $row['ability']
        ));
    }

    private function run_php(string $name)
    {
        return wp_get_ability($name)->execute(['id' => 7, 'token' => self::SECRET]);
    }

    private function run_rest(string $name): \WP_REST_Response
    {
        $request = new \WP_REST_Request('POST', sprintf(self::RUN_ROUTE, $name));
        $request->set_header('Content-Type', 'application/json');
        $request->set_body((string) wp_json_encode(['input' => ['id' => 7, 'token' => self::SECRET]]));

        return rest_do_request($request);
    }

    /** Mount the MCP endpoint fresh, as PromptsResourcesHttpTest documents. */
    private function mount(): void
    {
        global $wp_rest_server;

        $adapter    = \WP\MCP\Core\McpAdapter::instance();
        $reflection = new \ReflectionClass($adapter);
        if ($reflection->hasProperty('servers')) {
            $reflection->getProperty('servers')->setValue($adapter, []);
        }
        if ($reflection->hasProperty('initialized')) {
            $reflection->getProperty('initialized')->setValue(null, false);
        }
        add_action('rest_api_init', [$adapter, 'init'], 15);

        $wp_rest_server = new \WP_REST_Server();
        do_action('rest_api_init', $wp_rest_server);
    }

    /** @param array<string, mixed> $params */
    private function rpc(string $method, array $params = [], int $id = 11): array
    {
        global $wp_rest_server;

        $message = ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method];
        if ([] !== $params) {
            $message['params'] = $params;
        }

        $request = new \WP_REST_Request('POST', self::MCP_ROUTE);
        $request->set_header('Content-Type', 'application/json');
        $request->set_header('Accept', 'application/json, text/event-stream');
        if (null !== $this->session) {
            $request->set_header('Mcp-Session-Id', $this->session);
        }
        if (null !== $this->protocol) {
            $request->set_header('MCP-Protocol-Version', $this->protocol);
        }
        $request->set_body((string) wp_json_encode($message));

        $response = rest_do_request($request);
        $response = apply_filters('rest_post_dispatch', rest_ensure_response($response), $wp_rest_server, $request);

        $headers = $response->get_headers();
        if (isset($headers['Mcp-Session-Id'])) {
            $this->session = (string) $headers['Mcp-Session-Id'];
        }

        $data = json_decode((string) wp_json_encode($response->get_data()), true);
        $this->assertIsArray($data, "No JSON-RPC body for $method");
        return $data;
    }

    private function connect(): void
    {
        $this->mount();
        $init = $this->rpc('initialize', [
            'protocolVersion' => '2025-06-18',
            'capabilities'    => new \stdClass(),
            'clientInfo'      => ['name' => 'site-wide', 'version' => '0.0.0'],
        ], 1);
        $this->protocol = $init['result']['protocolVersion'] ?? null;
    }

    // ---------------------------------------------------------------
    // Settings surface

    public function test_site_wide_is_off_by_default_and_reported_by_get_governance_settings(): void
    {
        $this->assertSame('off', Site_Wide_Governance::mode());
        $this->assertSame('off', (new Get_Governance_Settings())->handle([])['site_wide']);

        $this->set_mode('enforce');

        $this->assertSame('enforce', (new Get_Governance_Settings())->handle([])['site_wide']);
    }

    public function test_update_skips_an_unknown_site_wide_value_and_keeps_the_stored_one(): void
    {
        $this->set_mode('audit');

        $out = (new Update_Governance_Settings())->handle(['site_wide' => 'everything']);

        $this->assertArrayNotHasKey('site_wide', $out['updated']);
        $this->assertSame([['key' => 'site_wide', 'reason' => 'expected off, audit or enforce']], $out['skipped']);
        $this->assertSame('audit', Site_Wide_Governance::mode());
    }

    // ---------------------------------------------------------------
    // Off: nothing changes for anyone

    public function test_off_logs_nothing_and_never_refuses_a_third_party_call(): void
    {
        Governance::set_ability_toggle(self::ECHO, false);

        $result = $this->run_php(self::ECHO);

        $this->assertSame(['ran' => 'Echo'], $result);
        $this->assertSame(1, self::$handler_runs);
        $this->assertSame([], $this->rows(self::ECHO));
    }

    // ---------------------------------------------------------------
    // Audit

    public function test_audit_logs_a_rest_run_with_its_entry_point_owner_outcome_and_duration(): void
    {
        $this->set_mode('audit');

        $response = $this->run_rest(self::ECHO);

        $this->assertSame(200, $response->get_status());
        $rows = $this->rows(self::ECHO);
        $this->assertCount(1, $rows, 'One REST run is one audit row.');
        $this->assertSame('rest', $rows[0]['source']);
        $this->assertTrue($rows[0]['allowed']);
        $this->assertSame('site:wpmcptest', $rows[0]['reason']);
        $this->assertIsInt($rows[0]['duration_ms']);
        $this->assertSame('none', $rows[0]['identity']);
    }

    public function test_audit_never_stores_inputs_or_outputs(): void
    {
        $this->set_mode('audit');

        $this->run_rest(self::ECHO);
        $this->run_php(self::ECHO);

        $log = (string) wp_json_encode(Governance_Audit_Log::list());
        $this->assertStringNotContainsString(self::SECRET, $log);
        $this->assertStringNotContainsString('"ran"', $log);
        foreach ($this->rows(self::ECHO) as $row) {
            $this->assertSame(
                ['ability', 'identity', 'allowed', 'timestamp', 'reason', 'source', 'duration_ms'],
                array_keys($row)
            );
        }
    }

    public function test_audit_logs_a_php_call_and_does_not_refuse_a_disabled_ability(): void
    {
        $this->set_mode('audit');
        Governance::set_ability_toggle(self::ECHO, false);

        $result = $this->run_php(self::ECHO);

        $this->assertSame(['ran' => 'Echo'], $result, 'Audit only: a toggle is logged, never enforced.');
        $rows = $this->rows(self::ECHO);
        $this->assertCount(1, $rows);
        $this->assertSame('php', $rows[0]['source']);
        $this->assertTrue($rows[0]['allowed']);
    }

    public function test_audit_logs_the_owners_own_permission_denial_and_a_failing_callback(): void
    {
        $this->set_mode('audit');

        $this->assertWPError($this->run_php(self::DENIED));
        $this->assertWPError($this->run_php(self::FAILING));

        $denied = $this->rows(self::DENIED);
        $this->assertCount(1, $denied);
        $this->assertFalse($denied[0]['allowed']);
        $this->assertSame('site:wpmcptest:ability_invalid_permissions', $denied[0]['reason']);

        // Over the core run route the owner's own check refuses before
        // execute(); that refusal is logged once too.
        $this->assertSame(403, $this->run_rest(self::DENIED)->get_status());
        $denied = $this->rows(self::DENIED);
        $this->assertCount(2, $denied);
        $this->assertSame('rest', $denied[0]['source']);
        $this->assertFalse($denied[0]['allowed']);

        $failing = $this->rows(self::FAILING);
        $this->assertCount(1, $failing);
        $this->assertFalse($failing[0]['allowed']);
        $this->assertSame('site:wpmcptest:fixture_broke', $failing[0]['reason']);
    }

    // ---------------------------------------------------------------
    // Enforce

    public function test_enforce_refuses_a_disabled_ability_on_every_entry_point(): void
    {
        $this->set_mode('enforce');
        Governance::set_ability_toggle(self::ECHO, false);

        $php = $this->run_php(self::ECHO);
        $this->assertWPError($php);
        $this->assertSame('wpmcp_governance_denied', $php->get_error_code());

        $rest = $this->run_rest(self::ECHO);
        $this->assertSame(403, $rest->get_status());
        $this->assertSame('wpmcp_governance_denied', $rest->get_data()['code'] ?? null);

        $this->assertSame(0, self::$handler_runs, 'A refused ability never reaches its handler.');

        $rows = $this->rows(self::ECHO);
        $this->assertCount(2, $rows);
        $this->assertSame(['rest', 'php'], array_column($rows, 'source'));
        foreach ($rows as $row) {
            $this->assertFalse($row['allowed']);
            $this->assertSame('site:wpmcptest:governance:ability_toggle', $row['reason']);
        }
    }

    public function test_enforce_fails_closed_only_for_abilities_disabled_by_name(): void
    {
        $this->set_mode('enforce');
        // Broad toggles written for MCP agents must not take down another
        // plugin's own abilities site-wide.
        Governance::set_domain_toggle('bridge', false);
        Governance::set_operation_toggle('update', false);
        Governance::set_ability_toggle(self::ECHO, true);
        add_filter('wpmcp_ability_enabled', '__return_false');

        $this->assertSame(['ran' => 'Echo'], $this->run_php(self::ECHO));
        $this->assertSame(200, $this->run_rest(self::ECHO)->get_status());

        remove_all_filters('wpmcp_ability_enabled');
    }

    // ---------------------------------------------------------------
    // wpmcp's own abilities and its own endpoint

    public function test_a_wpmcp_ability_run_over_the_core_rest_route_is_logged_once_with_its_entry_point(): void
    {
        $response = $this->run_rest_wpmcp('wpmcp/get-governance-settings');

        $this->assertSame(200, $response->get_status());
        $rows = $this->rows('wpmcp/get-governance-settings');
        $this->assertCount(1, $rows, 'The permission check before execute() and the one inside it are one call.');
        $this->assertSame('rest', $rows[0]['source']);
        $this->assertTrue($rows[0]['allowed']);
    }

    public function test_calls_through_the_mcp_endpoint_are_logged_once(): void
    {
        $this->set_mode('enforce');
        add_filter('wpmcp_enable_ability_bridge', '__return_true');
        $this->connect();

        $own = $this->rpc('tools/call', ['name' => 'wpmcp-get-governance-settings', 'arguments' => new \stdClass()], 2);
        $this->assertFalse($own['result']['isError'] ?? true, (string) wp_json_encode($own));

        $bridged = $this->rpc('tools/call', [
            'name'      => 'wpmcp-execute-site-ability',
            'arguments' => ['name' => self::ECHO, 'arguments' => ['id' => 7]],
        ], 3);
        $this->assertFalse($bridged['result']['isError'] ?? true, (string) wp_json_encode($bridged));

        $own_rows = $this->rows('wpmcp/get-governance-settings');
        $this->assertCount(1, $own_rows);
        $this->assertSame('mcp', $own_rows[0]['source']);

        $echo_rows = $this->rows(self::ECHO);
        $this->assertCount(1, $echo_rows, 'The bridge row is the only row: the site-wide hook stands down on the endpoint.');
        $this->assertSame('mcp', $echo_rows[0]['source']);
        $this->assertSame('bridge:wpmcptest', $echo_rows[0]['reason']);
    }

    public function test_calls_through_the_stdio_transport_are_attributed_to_the_endpoint(): void
    {
        $this->set_mode('audit');

        $response = (new Stdio_Transport())->handle_request([
            'jsonrpc' => '2.0',
            'id'      => 5,
            'method'  => 'tools/call',
            'params'  => ['name' => 'wpmcp-get-governance-settings', 'arguments' => []],
        ]);

        $this->assertFalse($response['result']['isError'] ?? true, (string) wp_json_encode($response));
        $rows = $this->rows('wpmcp/get-governance-settings');
        $this->assertCount(1, $rows);
        $this->assertSame('mcp', $rows[0]['source']);
    }

    // ---------------------------------------------------------------
    // list-governance-audit-log

    public function test_the_audit_log_tool_filters_by_source(): void
    {
        $this->set_mode('audit');
        $this->run_rest(self::ECHO);
        $this->run_php(self::ECHO);

        $all  = (new List_Governance_Audit_Log())->handle([]);
        $rest = (new List_Governance_Audit_Log())->handle(['source' => 'rest']);
        $php  = (new List_Governance_Audit_Log())->handle(['source' => 'php', 'limit' => 1]);

        $this->assertCount(2, array_filter($all['entries'], static fn ($row) => self::ECHO === $row['ability']));
        $this->assertSame(['rest'], array_values(array_unique(array_column($rest['entries'], 'source'))));
        $this->assertCount(1, $php['entries']);
        $this->assertSame('php', $php['entries'][0]['source']);
    }

    private function run_rest_wpmcp(string $name): \WP_REST_Response
    {
        $request = new \WP_REST_Request('POST', sprintf(self::RUN_ROUTE, $name));
        $request->set_header('Content-Type', 'application/json');
        $request->set_body('{"input":{}}');

        return rest_do_request($request);
    }
}
