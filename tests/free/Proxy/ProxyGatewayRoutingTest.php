<?php

namespace WPMCP\Tests\Free\Proxy;

use PHPUnit\Framework\TestCase;
use WPMCP\Proxy\Router;

use function WPMCP\Proxy\pump_router;

/**
 * Per-call site routing and the `all` broadcast in the stdio proxy
 * (issue #130, on top of the #77 proxy).
 *
 * Driven through a fake transport so every assertion can see exactly which
 * site received which message with which credential and which session
 * header. That is the property routing has to get right: a call aimed at
 * one site must reach that site, under that site's own application password
 * and session, and never borrow another site's. The target site then applies
 * its own governance and identity to the call, because the proxy never
 * forwards anything but the site's own credential.
 */
class ProxyGatewayRoutingTest extends TestCase
{
    /** @var array<int,array{site:string,auth:string,session:string,message:array}> */
    private array $sent = [];

    /** @var array<string,array<string,array>> Per-site tool catalogs the fake answers tools/list with. */
    private array $catalogs = [];

    /** @var array<string,bool> Sites the fake treats as down. */
    private array $down = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (! defined('WPMCP_PROXY_NO_RUN')) {
            define('WPMCP_PROXY_NO_RUN', true);
        }
        require_once dirname(__DIR__, 3) . '/bin/wpmcp-proxy.php';
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->sent     = [];
        $this->down     = [];
        $this->catalogs = [
            'prod'    => [
                'get-post'    => [ 'readOnlyHint' => true ],
                'update-post' => [ 'readOnlyHint' => false ],
                'delete-post' => [ 'readOnlyHint' => false, 'confirm' => true ],
            ],
            'staging' => [
                'get-post'    => [ 'readOnlyHint' => true ],
                'update-post' => [ 'readOnlyHint' => false ],
                'delete-post' => [ 'readOnlyHint' => false, 'confirm' => true ],
            ],
            'shop'    => [
                // The shop site's governance hides update-post entirely.
                'get-post' => [ 'readOnlyHint' => true ],
            ],
        ];
    }

    private function sites(): array
    {
        return [
            'prod'    => [ 'url' => 'https://prod.example', 'user' => 'admin-a', 'app_password' => 'pw-a' ],
            'staging' => [ 'url' => 'https://staging.example', 'user' => 'admin-b', 'app_password' => 'pw-b' ],
            'shop'    => [ 'url' => 'https://shop.example', 'user' => 'admin-c', 'app_password' => 'pw-c' ],
        ];
    }

    private function router(array $env = [], ?array $sites = null): Router
    {
        return new Router($sites ?? $this->sites(), 'prod', $env, [$this, 'fake_transport']);
    }

    /**
     * Fake MCP endpoint. Issues a per-site session id on initialize, demands
     * it afterwards, answers tools/list from the site's catalog and echoes
     * tools/call so the test can see what arrived.
     *
     * @return array{body:string,headers:array<int,string>}
     */
    public function fake_transport(array $site, string $body, array $extra = []): array
    {
        $host    = (string) parse_url($site['url'], PHP_URL_HOST);
        $name    = explode('.', $host)[0];
        $message = json_decode($body, true);
        $session = '';
        foreach ($extra as $h) {
            if (0 === stripos($h, 'Mcp-Session-Id:')) {
                $session = trim(substr($h, strlen('Mcp-Session-Id:')));
            }
        }

        $this->sent[] = [
            'site'    => $name,
            'auth'    => $site['user'] . ':' . $site['app_password'],
            'session' => $session,
            'message' => $message,
        ];

        if (isset($this->down[ $name ])) {
            throw new \RuntimeException(sprintf('Could not reach %s.', $site['url']));
        }

        $id     = $message['id'] ?? null;
        $method = $message['method'] ?? '';

        if ('initialize' === $method) {
            return [
                'headers' => [ 'HTTP/1.1 200 OK', 'Mcp-Session-Id: sess-' . $name ],
                'body'    => json_encode([ 'jsonrpc' => '2.0', 'id' => $id, 'result' => [ 'protocolVersion' => '2025-11-25' ] ]),
            ];
        }
        if (str_starts_with($method, 'notifications/')) {
            return [ 'headers' => [ 'HTTP/1.1 202 Accepted' ], 'body' => 'null' ];
        }
        if ('sess-' . $name !== $session) {
            return [
                'headers' => [ 'HTTP/1.1 400 Bad Request' ],
                'body'    => json_encode([ 'jsonrpc' => '2.0', 'id' => $id, 'error' => [ 'code' => -32600, 'message' => 'Missing or foreign Mcp-Session-Id' ] ]),
            ];
        }
        if ('tools/list' === $method) {
            $tools = [];
            foreach ($this->catalogs[ $name ] as $tool => $spec) {
                $props = [ 'id' => [ 'type' => 'integer' ] ];
                if (! empty($spec['confirm'])) {
                    $props['confirm'] = [ 'type' => 'boolean' ];
                }
                $tools[] = [
                    'name'        => $tool,
                    'inputSchema' => [ 'type' => 'object', 'properties' => $props ],
                    'annotations' => [ 'readOnlyHint' => $spec['readOnlyHint'] ],
                ];
            }
            return [ 'headers' => [ 'HTTP/1.1 200 OK' ], 'body' => json_encode([ 'jsonrpc' => '2.0', 'id' => $id, 'result' => [ 'tools' => $tools ] ]) ];
        }
        if ('tools/call' === $method) {
            $tool = (string) ($message['params']['name'] ?? '');
            if (! isset($this->catalogs[ $name ][ $tool ])) {
                return [
                    'headers' => [ 'HTTP/1.1 404 Not Found' ],
                    'body'    => json_encode([ 'jsonrpc' => '2.0', 'id' => $id, 'error' => [ 'code' => -32602, 'message' => 'Tool not found' ] ]),
                ];
            }
            return [
                'headers' => [ 'HTTP/1.1 200 OK' ],
                'body'    => json_encode([
                    'jsonrpc' => '2.0',
                    'id'      => $id,
                    'result'  => [
                        'content' => [ [ 'type' => 'text', 'text' => $name . ':' . $tool ] ],
                        'echo'    => $message['params']['arguments'] ?? null,
                    ],
                ]),
            ];
        }

        return [ 'headers' => [ 'HTTP/1.1 200 OK' ], 'body' => json_encode([ 'jsonrpc' => '2.0', 'id' => $id, 'result' => [] ]) ];
    }

    /** @return array<int,array> decoded response lines */
    private function run_lines(Router $router, array $requests): array
    {
        $in = fopen('php://memory', 'r+');
        fwrite($in, implode("\n", array_map('json_encode', $requests)) . "\n");
        rewind($in);
        $out = fopen('php://memory', 'r+');

        pump_router($in, $out, $router, []);

        rewind($out);
        $written = (string) stream_get_contents($out);
        fclose($in);
        fclose($out);

        $lines = array_values(array_filter(explode("\n", $written), static fn($l) => '' !== trim($l)));

        return array_map(static fn($l) => json_decode($l, true), $lines);
    }

    private function handshake(): array
    {
        return [
            [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [ 'protocolVersion' => '2025-11-25', 'clientInfo' => [ 'name' => 'test' ] ] ],
            [ 'jsonrpc' => '2.0', 'method' => 'notifications/initialized' ],
        ];
    }

    private function call(int $id, string $tool, array $args): array
    {
        return [ 'jsonrpc' => '2.0', 'id' => $id, 'method' => 'tools/call', 'params' => [ 'name' => $tool, 'arguments' => $args ] ];
    }

    /** @return array<int,array> messages the fake received for one site */
    private function sent_to(string $site): array
    {
        return array_values(array_filter($this->sent, static fn($s) => $site === $s['site']));
    }

    public function test_a_call_without_a_site_goes_to_the_default_site(): void
    {
        $out = $this->run_lines($this->router(), array_merge($this->handshake(), [ $this->call(2, 'get-post', [ 'id' => 5 ]) ]));

        $this->assertSame('prod:get-post', $out[1]['result']['content'][0]['text']);
        $this->assertSame([], $this->sent_to('staging'));
    }

    public function test_a_site_alias_routes_the_call_to_that_site_and_is_stripped(): void
    {
        $out = $this->run_lines($this->router(), array_merge($this->handshake(), [ $this->call(2, 'get-post', [ 'id' => 5, 'site' => 'Staging' ]) ]));

        $this->assertSame(2, $out[1]['id']);
        $this->assertSame('staging:get-post', $out[1]['result']['content'][0]['text']);
        $this->assertSame([ 'id' => 5 ], $out[1]['result']['echo'], 'The routing argument must never reach the site.');
    }

    /**
     * The credential-scope property: everything sent to staging carries
     * staging's own application password and staging's own session, and
     * nothing sent to staging carries prod's.
     */
    public function test_a_routed_call_uses_only_the_target_sites_credential_and_session(): void
    {
        $this->run_lines($this->router(), array_merge($this->handshake(), [ $this->call(2, 'get-post', [ 'site' => 'staging' ]) ]));

        $staging = $this->sent_to('staging');
        $this->assertNotEmpty($staging);
        foreach ($staging as $s) {
            $this->assertSame('admin-b:pw-b', $s['auth']);
            $this->assertNotSame('sess-prod', $s['session']);
        }

        $methods = array_map(static fn($s) => $s['message']['method'], $staging);
        $this->assertSame([ 'initialize', 'notifications/initialized', 'tools/call' ], $methods, 'A new site gets its own handshake before the first call.');
        $this->assertSame('sess-staging', $staging[2]['session']);
        $this->assertSame([ 'name' => 'test' ], $staging[0]['message']['params']['clientInfo'], 'The client\'s own initialize params are replayed.');

        foreach ($this->sent_to('prod') as $s) {
            $this->assertSame('admin-a:pw-a', $s['auth']);
        }
    }

    public function test_an_unknown_alias_is_refused_and_forwarded_nowhere(): void
    {
        $out = $this->run_lines($this->router(), array_merge($this->handshake(), [ $this->call(2, 'update-post', [ 'site' => 'prdo' ]) ]));

        $this->assertSame(2, $out[1]['id']);
        $this->assertStringContainsString('Unknown site "prdo"', $out[1]['error']['message']);
        $calls = array_filter($this->sent, static fn($s) => 'tools/call' === ($s['message']['method'] ?? ''));
        $this->assertSame([], array_values($calls), 'A typo must never fall back to the default site.');
    }

    public function test_a_non_string_site_is_refused(): void
    {
        $out = $this->run_lines($this->router(), array_merge($this->handshake(), [ $this->call(2, 'get-post', [ 'site' => [ 'prod' ] ]) ]));

        $this->assertArrayHasKey('error', $out[1]);
        $calls = array_filter($this->sent, static fn($s) => 'tools/call' === ($s['message']['method'] ?? ''));
        $this->assertSame([], array_values($calls));
    }

    public function test_a_misconfigured_target_site_is_an_error_for_that_call_only(): void
    {
        $sites                            = $this->sites();
        $sites['staging']['app_password'] = '';

        $out = $this->run_lines($this->router([], $sites), array_merge($this->handshake(), [
            $this->call(2, 'get-post', [ 'site' => 'staging' ]),
            $this->call(3, 'get-post', []),
        ]));

        $this->assertStringContainsString('missing a user or application password', $out[1]['error']['message']);
        $this->assertSame('prod:get-post', $out[2]['result']['content'][0]['text']);
        $this->assertSame([], $this->sent_to('staging'));
    }

    public function test_broadcast_read_returns_a_per_site_status_array(): void
    {
        $this->down['staging'] = true;

        $out = $this->run_lines($this->router(), array_merge($this->handshake(), [ $this->call(2, 'get-post', [ 'id' => 1, 'site' => 'all' ]) ]));

        $result = $out[1]['result'];
        $this->assertFalse($result['isError']);
        $by_site = array_column($result['structuredContent']['results'], null, 'site');

        $this->assertSame([ 'prod', 'staging', 'shop' ], array_keys($by_site), 'One status per configured site, in config order.');
        $this->assertSame('ok', $by_site['prod']['status']);
        $this->assertSame('ok', $by_site['shop']['status']);
        $this->assertSame('site_unavailable', $by_site['staging']['status']);
        $this->assertSame('prod:get-post', $by_site['prod']['result']['content'][0]['text']);
        $this->assertSame([ 'id' => 1 ], $by_site['shop']['result']['echo']);
        $this->assertArrayNotHasKey('result', $by_site['staging']);
    }

    public function test_broadcast_reports_tool_unavailable_where_a_site_does_not_expose_the_tool(): void
    {
        $out = $this->run_lines(
            $this->router([ 'WPMCP_BROADCAST_WRITES' => '1' ]),
            array_merge($this->handshake(), [ $this->call(2, 'update-post', [ 'id' => 1, 'site' => 'all', 'confirm' => true ]) ])
        );

        $by_site = array_column($out[1]['result']['structuredContent']['results'], null, 'site');
        $this->assertSame('tool_unavailable', $by_site['shop']['status']);
        $this->assertSame('ok', $by_site['prod']['status']);
        $shop_calls = array_filter($this->sent_to('shop'), static fn($s) => 'tools/call' === $s['message']['method']);
        $this->assertSame([], array_values($shop_calls), 'A site whose governance hides the tool is never called.');
    }

    public function test_broadcast_write_is_refused_without_the_workspace_opt_in(): void
    {
        $out = $this->run_lines($this->router(), array_merge($this->handshake(), [ $this->call(2, 'update-post', [ 'id' => 1, 'site' => 'all', 'confirm' => true ]) ]));

        $this->assertStringContainsString('WPMCP_BROADCAST_WRITES', $out[1]['error']['message']);
        $calls = array_filter($this->sent, static fn($s) => 'tools/call' === ($s['message']['method'] ?? ''));
        $this->assertSame([], array_values($calls), 'A refused broadcast write reaches no site at all.');
    }

    public function test_broadcast_write_is_refused_without_per_call_confirm(): void
    {
        $out = $this->run_lines(
            $this->router([ 'WPMCP_BROADCAST_WRITES' => '1' ]),
            array_merge($this->handshake(), [ $this->call(2, 'update-post', [ 'id' => 1, 'site' => 'all' ]) ])
        );

        $this->assertStringContainsString('confirm', $out[1]['error']['message']);
        $calls = array_filter($this->sent, static fn($s) => 'tools/call' === ($s['message']['method'] ?? ''));
        $this->assertSame([], array_values($calls));
    }

    /** confirm is a proxy gate, stripped unless the tool itself declares it. */
    public function test_broadcast_confirm_is_stripped_unless_the_tool_declares_it(): void
    {
        $out = $this->run_lines(
            $this->router([ 'WPMCP_BROADCAST_WRITES' => '1' ]),
            array_merge($this->handshake(), [
                $this->call(2, 'update-post', [ 'id' => 1, 'site' => 'all', 'confirm' => true ]),
                $this->call(3, 'delete-post', [ 'id' => 1, 'site' => 'all', 'confirm' => true ]),
            ])
        );

        $update = array_column($out[1]['result']['structuredContent']['results'], null, 'site');
        $this->assertSame([ 'id' => 1 ], $update['prod']['result']['echo']);

        $delete = array_column($out[2]['result']['structuredContent']['results'], null, 'site');
        $this->assertSame([ 'id' => 1, 'confirm' => true ], $delete['prod']['result']['echo']);
    }

    /** A tool with no readOnlyHint is treated as a write: the gate fails closed. */
    public function test_a_tool_without_a_read_only_hint_is_gated_as_a_write(): void
    {
        $this->catalogs['prod']['mystery']    = [ 'readOnlyHint' => null ];
        $this->catalogs['staging']['mystery'] = [ 'readOnlyHint' => true ];

        $out = $this->run_lines($this->router(), array_merge($this->handshake(), [ $this->call(2, 'mystery', [ 'site' => 'all' ]) ]));

        $this->assertArrayHasKey('error', $out[1]);
    }

    public function test_every_broadcast_leg_uses_its_own_sites_credential(): void
    {
        $this->run_lines($this->router(), array_merge($this->handshake(), [ $this->call(2, 'get-post', [ 'site' => 'all' ]) ]));

        $expected = [ 'prod' => 'admin-a:pw-a', 'staging' => 'admin-b:pw-b', 'shop' => 'admin-c:pw-c' ];
        foreach ($this->sent as $s) {
            $this->assertSame($expected[ $s['site'] ], $s['auth']);
            if ('' !== $s['session']) {
                $this->assertSame('sess-' . $s['site'], $s['session'], 'A session id is never replayed to another site.');
            }
        }
    }

    public function test_tools_list_advertises_the_site_argument_and_the_list_sites_tool(): void
    {
        $out = $this->run_lines($this->router(), array_merge($this->handshake(), [
            [ 'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list' ],
            $this->call(3, Router::LIST_SITES_TOOL, []),
        ]));

        $tools = array_column($out[1]['result']['tools'], null, 'name');
        $this->assertArrayHasKey(Router::LIST_SITES_TOOL, $tools);
        $this->assertSame('string', $tools['get-post']['inputSchema']['properties']['site']['type']);

        $listed = $out[2]['result']['structuredContent'];
        $this->assertSame('prod', $listed['default']);
        $this->assertSame([ 'prod', 'staging', 'shop' ], array_column($listed['sites'], 'name'));
        $encoded = json_encode($out[2]);
        $this->assertStringNotContainsString('pw-a', $encoded, 'Listing sites must never expose a credential.');
        $this->assertStringNotContainsString('admin-b', $encoded);
    }

    public function test_a_single_site_proxy_does_not_rewrite_tools_list(): void
    {
        $sites = [ 'prod' => $this->sites()['prod'] ];
        $out   = $this->run_lines($this->router([], $sites), array_merge($this->handshake(), [ [ 'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list' ] ]));

        $tools = array_column($out[1]['result']['tools'], null, 'name');
        $this->assertArrayNotHasKey(Router::LIST_SITES_TOOL, $tools);
        $this->assertArrayNotHasKey('site', $tools['get-post']['inputSchema']['properties']);
    }
}
