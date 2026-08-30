<?php

namespace WPMCP\Tests\Free\Proxy;

use WPMCP\Proxy\Session;

use function WPMCP\Proxy\error_line;
use function WPMCP\Proxy\forward;
use function WPMCP\Proxy\header_value;
use function WPMCP\Proxy\one_line_response;
use function WPMCP\Proxy\resolve_sites;
use function WPMCP\Proxy\select_site;
use function WPMCP\Proxy\status_code;

/**
 * Protocol-level behavior of the stdio-to-HTTP proxy (issue #77).
 *
 * The MCP Adapter's HTTP transport issues an Mcp-Session-Id on the
 * initialize response and rejects every later method without it
 * (HttpSessionValidator), so a proxy that does not capture and replay that
 * header cannot complete a single tools/list. The end-to-end test below
 * runs against a stubbed endpoint enforcing exactly that rule, which is the
 * only way this class of bug is visible from a unit test.
 */
class ProxyProtocolTest extends \WP_UnitTestCase
{
    /** @var resource|null */
    private static $server;

    private static string $base = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (! defined('WPMCP_PROXY_NO_RUN')) {
            define('WPMCP_PROXY_NO_RUN', true);
        }
        require_once dirname(__DIR__, 3) . '/bin/wpmcp-proxy.php';
    }

    /** Boots a stub MCP endpoint on a free localhost port, or skips. */
    private function stub_endpoint(): string
    {
        if ('' !== self::$base) {
            return self::$base;
        }

        $router = tempnam(sys_get_temp_dir(), 'wpmcp-stub') . '.php';
        file_put_contents($router, <<<'PHP'
<?php
$body    = file_get_contents('php://input');
$message = json_decode($body, true);
$method  = $message['method'] ?? '';
header('Content-Type: application/json');

if ('initialize' === $method) {
    header('Mcp-Session-Id: session-abc');
    // Deliberately pretty-printed: a body with internal newlines must not
    // desynchronize the proxy's newline-delimited framing.
    echo json_encode([
        'jsonrpc' => '2.0',
        'id'      => $message['id'] ?? null,
        'result'  => [ 'protocolVersion' => '2025-11-25' ],
    ], JSON_PRETTY_PRINT);
    return;
}

if (! isset($_SERVER['HTTP_MCP_SESSION_ID'])) {
    echo json_encode([
        'jsonrpc' => '2.0',
        'id'      => $message['id'] ?? null,
        'error'   => [ 'code' => -32600, 'message' => 'Missing Mcp-Session-Id header' ],
    ]);
    return;
}

echo json_encode([
    'jsonrpc' => '2.0',
    'id'      => $message['id'] ?? null,
    'result'  => [
        'session'  => $_SERVER['HTTP_MCP_SESSION_ID'],
        'protocol' => $_SERVER['HTTP_MCP_PROTOCOL_VERSION'] ?? '',
    ],
]);
PHP);

        $port = 8000 + (getmypid() % 1000);
        $cmd  = sprintf(
            '%s -S 127.0.0.1:%d %s',
            escapeshellarg(PHP_BINARY),
            $port,
            escapeshellarg($router)
        );

        $pipes  = [];
        $server = proc_open($cmd, [ 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w'] ], $pipes);
        if (! is_resource($server)) {
            $this->markTestSkipped('Could not start the stub HTTP endpoint.');
        }
        self::$server = $server;

        $base = 'http://127.0.0.1:' . $port;
        for ($i = 0; $i < 50; $i++) {
            $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if (is_resource($probe)) {
                fclose($probe);
                self::$base = $base;
                return $base;
            }
            usleep(100000);
        }

        $this->markTestSkipped('The stub HTTP endpoint did not come up.');
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
            self::$server = null;
        }
        self::$base = '';
        parent::tearDownAfterClass();
    }

    /**
     * The acceptance criterion the proxy exists for: initialize, then a
     * second method that the endpoint only answers when the session header
     * from the first exchange is replayed.
     */
    public function test_session_id_is_captured_on_initialize_and_replayed(): void
    {
        $base = $this->stub_endpoint();
        $site = [ 'url' => $base, 'user' => 'u', 'app_password' => 'p' ];

        $session = new Session();
        $init    = forward($site, '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}');
        $session->learn($init['headers'], json_decode($init['body'], true));

        $this->assertSame('session-abc', $session->id);
        $this->assertSame('2025-11-25', $session->protocol_version);
        $this->assertSame(
            [ 'Mcp-Session-Id: session-abc', 'MCP-Protocol-Version: 2025-11-25' ],
            $session->headers()
        );

        $listed = forward($site, '{"jsonrpc":"2.0","id":2,"method":"tools/list"}', $session->headers());
        $decoded = json_decode($listed['body'], true);

        $this->assertArrayNotHasKey('error', $decoded, 'The replayed session header must satisfy the endpoint.');
        $this->assertSame('session-abc', $decoded['result']['session']);
        $this->assertSame('2025-11-25', $decoded['result']['protocol']);
    }

    /** Without the replay the stub rejects the call, as the real adapter does. */
    public function test_a_second_message_without_the_session_header_is_rejected(): void
    {
        $base = $this->stub_endpoint();
        $site = [ 'url' => $base, 'user' => 'u', 'app_password' => 'p' ];

        $decoded = json_decode(forward($site, '{"jsonrpc":"2.0","id":2,"method":"tools/list"}')['body'], true);

        $this->assertSame('Missing Mcp-Session-Id header', $decoded['error']['message']);
    }

    /** A multi-line body must be re-serialized onto one line before stdout. */
    public function test_response_body_is_reserialized_onto_one_line(): void
    {
        $line = one_line_response("{\n  \"jsonrpc\": \"2.0\",\n  \"id\": 1\n}");

        $this->assertNotNull($line);
        $this->assertStringNotContainsString("\n", $line);
        $this->assertSame(['jsonrpc' => '2.0', 'id' => 1], json_decode($line, true));
    }

    public function test_non_json_body_is_not_forwarded(): void
    {
        $this->assertNull(one_line_response("<b>Warning</b>: something\n{\"jsonrpc\":\"2.0\"}"));
    }

    /** A notification must never receive a response, not even an error one. */
    public function test_no_error_envelope_is_written_for_a_notification(): void
    {
        $this->assertNull(error_line(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], 'boom'));
        $this->assertNull(error_line(null, 'boom'));
    }

    /** A request with an explicit null id is still a request, not a notification. */
    public function test_error_envelope_preserves_an_explicit_null_id(): void
    {
        $line = error_line(['jsonrpc' => '2.0', 'id' => null, 'method' => 'ping'], 'boom');

        $this->assertNotNull($line);
        $decoded = json_decode($line, true);
        $this->assertArrayHasKey('id', $decoded);
        $this->assertNull($decoded['id']);
        $this->assertSame(-32000, $decoded['error']['code']);
    }

    public function test_header_and_status_parsing_is_case_insensitive(): void
    {
        $raw = [ 'HTTP/1.1 200 OK', 'Content-Type: application/json', 'mcp-session-id: abc' ];

        $this->assertSame('abc', header_value($raw, 'Mcp-Session-Id'));
        $this->assertNull(header_value($raw, 'X-Nope'));
        $this->assertSame(200, status_code($raw));
    }

    /** A malformed WPMCP_SITES must name the parse error, not read as "no sites". */
    public function test_malformed_sites_json_is_a_named_error(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('WPMCP_SITES is not valid JSON');
        resolve_sites([ 'WPMCP_SITES' => '{"prod": {' ]);
    }

    /**
     * The credential guard: an http:// site would put the application
     * password on the wire in the clear (and WordPress refuses
     * application-password auth over non-SSL anyway).
     */
    public function test_plain_http_site_is_refused_without_an_explicit_opt_in(): void
    {
        $sites = [ 'local' => [ 'url' => 'http://a.example', 'user' => 'u', 'app_password' => 'p' ] ];

        try {
            select_site($sites, []);
            $this->fail('Expected an http:// site to be refused.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('WPMCP_ALLOW_INSECURE', $e->getMessage());
        }

        $this->assertSame(
            'http://a.example',
            select_site($sites, [ 'WPMCP_ALLOW_INSECURE' => '1' ])['url']
        );
    }

    public function test_missing_credentials_are_reported_before_the_first_request(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('missing a user or application password');
        select_site([ 'prod' => [ 'url' => 'https://a.example', 'user' => '', 'app_password' => '' ] ], []);
    }
}
