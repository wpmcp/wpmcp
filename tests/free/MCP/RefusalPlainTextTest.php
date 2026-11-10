<?php

namespace WPMCP\Tests\Free\MCP;

use WPMCP\Governance\Governance;
use WPMCP\Identity\Identity_Context;
use WPMCP\Identity\Identity_Store;
use WPMCP\MCP\Ability;
use WPMCP\MCP\Opt_In_Required;
use WPMCP\MCP\Stdio_Transport;
use WPMCP\MCP\Tool_Exposure;
use WPMCP\Plugin;
use WPMCP\RateLimit\Rate_Limiter;

/**
 * Refusals reach MCP clients as plain text (issue #724), without the
 * "callback threw an exception" prefix that made an expected refusal read
 * like a crash (issue #631), on the abilities API, on stdio and on HTTP.
 */
class RefusalPlainTextTest extends \WP_UnitTestCase
{
    private const ROUTE = '/mcp/wpmcp-server';

    public static function wpSetUpBeforeClass(): void
    {
        if (0 === did_action('wp_abilities_api_init')) {
            do_action('wp_abilities_api_init');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Governance::reset_for_tests();
        Identity_Context::set_current_for_tests(null);
        delete_option(Identity_Store::OPTION);
        delete_option(Tool_Exposure::OPTION);
        Rate_Limiter::set_clock_override(fn() => 1_790_000_724);
        add_filter('wpmcp_rate_limit', fn() => 100000);
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        if (is_multisite()) {
            grant_super_admin(get_current_user_id());
        }
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_rate_limit');
        Rate_Limiter::set_clock_override(null);
        Identity_Context::set_current_for_tests(null);
        Governance::reset_for_tests();
        delete_option(Identity_Store::OPTION);
        delete_option(Tool_Exposure::OPTION);
        global $wp_rest_server;
        $wp_rest_server = null;
        parent::tearDown();
    }

    private const SENSITIVE = 'Refusing to read sensitive option "wpmcp_db_write_audit_log".';

    private static function assert_no_entities(string $text): void
    {
        foreach (['&quot;', '&#039;', '&lt;', '&gt;', '&amp;'] as $entity) {
            self::assertStringNotContainsString($entity, $text);
        }
        self::assertStringNotContainsString('callback threw an exception', $text);
    }

    public function test_an_ability_refusal_is_plain_text_with_no_prefix(): void
    {
        $result = wp_get_ability('wpmcp/get-option')->execute(['name' => 'wpmcp_db_write_audit_log']);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame(self::SENSITIVE, $result->get_error_message());
    }

    /** The issue's third case: quotes, an apostrophe and angle brackets in one refusal. */
    public function test_a_refusal_with_quotes_and_angle_brackets_reads_as_written(): void
    {
        global $wpdb;

        $result = wp_get_ability('wpmcp/query')->execute([
            'sql' => "SELECT REVERSE(option_value) AS v FROM {$wpdb->options} WHERE option_name = 'woocommerce_stripe_settings'",
        ]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $message = $result->get_error_message();
        self::assert_no_entities($message);
        $this->assertStringContainsString("WHERE option_name = '<name>'", $message);
        $this->assertStringContainsString('<options table>', $message);
    }

    public function test_stdio_sends_the_refusal_as_plain_text(): void
    {
        $response = (new Stdio_Transport())->handle_request([
            'jsonrpc' => '2.0',
            'id'      => 7,
            'method'  => 'tools/call',
            'params'  => [ 'name' => 'wpmcp-get-option', 'arguments' => [ 'name' => 'wpmcp_db_write_audit_log' ] ],
        ]);

        $this->assertTrue($response['result']['isError']);
        $this->assertSame(self::SENSITIVE, $response['result']['content'][0]['text']);
    }

    public function test_http_sends_the_refusal_as_plain_text(): void
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

        $params = [
            'name'      => 'wpmcp-get-option',
            'arguments' => [ 'name' => 'wpmcp_db_write_audit_log' ],
            '_meta'     => [
                'io.modelcontextprotocol/protocolVersion'    => '2026-07-28',
                'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
            ],
        ];
        $request = new \WP_REST_Request('POST', self::ROUTE);
        $request->set_header('Content-Type', 'application/json');
        $request->set_header('Accept', 'application/json, text/event-stream');
        $request->set_header('MCP-Protocol-Version', '2026-07-28');
        $request->set_header('Mcp-Method', 'tools/call');
        $request->set_header('Mcp-Name', 'wpmcp-get-option');
        $request->set_body((string) wp_json_encode([ 'jsonrpc' => '2.0', 'id' => 8, 'method' => 'tools/call', 'params' => $params ]));

        $response = rest_do_request($request);
        $response = apply_filters('rest_post_dispatch', rest_ensure_response($response), $wp_rest_server, $request);
        $body     = json_decode((string) wp_json_encode($response->get_data()), true);

        $this->assertTrue($body['result']['isError'] ?? null, (string) wp_json_encode($body));
        $this->assertSame(self::SENSITIVE, $body['result']['content'][0]['text']);
    }

    /**
     * A PHP engine error is a real crash and keeps the prefix; an opt-in
     * refusal gets its own code and names the filter.
     */
    public function test_an_engine_error_keeps_the_prefix_and_an_opt_in_refusal_has_its_code(): void
    {
        $plugin   = Plugin::instance();
        $prop     = new \ReflectionProperty(Plugin::class, 'registrar');
        $original = $prop->getValue($plugin);
        $fresh    = new \WPMCP\MCP\Registrar();
        $prop->setValue($plugin, $fresh);

        try {
            remove_all_actions('wp_abilities_api_init');
            add_action('wp_abilities_api_init', function () use ($fresh) {
                $fresh->register(new Ability(
                    'wpmcp/test-type-error',
                    'free',
                    'Throws a TypeError',
                    ['type' => 'object', 'properties' => []],
                    function (array $args = []) {
                        throw new \TypeError('bad <type>');
                    }
                ));
                $fresh->register(new Ability(
                    'wpmcp/test-opt-in',
                    'free',
                    'Throws an opt-in refusal',
                    ['type' => 'object', 'properties' => []],
                    function (array $args = []) {
                        throw new Opt_In_Required('The test tool is disabled.', 'wpmcp_enable_test_tool');
                    }
                ));
            });
            do_action('wp_abilities_api_init');

            $crash = wp_get_ability('wpmcp/test-type-error')->execute([]);
            $this->assertSame('ability_callback_exception', $crash->get_error_code());
            $this->assertSame('Ability "wpmcp/test-type-error" callback threw an exception: bad <type>', $crash->get_error_message());

            $opt_in = wp_get_ability('wpmcp/test-opt-in')->execute([]);
            $this->assertSame('opt_in_required', $opt_in->get_error_code());
            $this->assertStringStartsWith('The test tool is disabled. It is off by default', $opt_in->get_error_message());
            $this->assertStringContainsString("add_filter('wpmcp_enable_test_tool', '__return_true');", $opt_in->get_error_message());
            $this->assertStringContainsString('no wp-admin setting', $opt_in->get_error_message());
        } finally {
            wp_unregister_ability('wpmcp/test-type-error');
            wp_unregister_ability('wpmcp/test-opt-in');
            $prop->setValue($plugin, $original);
        }
    }
}
