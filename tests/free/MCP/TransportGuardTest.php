<?php

namespace WPMCP\Tests\Free\MCP;

use WPMCP\MCP\Transport_Guard;

/**
 * Transport hardening for the MCP + OAuth request paths (issue #133):
 * no-store cache headers, display_errors suppression so a stray notice
 * cannot corrupt JSON-RPC framing, and the HTTP 421 site-URL mismatch
 * guard for connectors left pointing at an old domain.
 */
class TransportGuardTest extends \WP_UnitTestCase
{
    private ?string $display_errors = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->display_errors = ini_get('display_errors');
    }

    protected function tearDown(): void
    {
        if (null !== $this->display_errors && false !== $this->display_errors) {
            @ini_set('display_errors', $this->display_errors);
        }
        parent::tearDown();
    }

    private function request(string $route, ?string $host = null): \WP_REST_Request
    {
        $request = new \WP_REST_Request('POST', $route);
        if (null !== $host) {
            $request->set_header('host', $host);
        }
        return $request;
    }

    private function home_host(): string
    {
        return (string) wp_parse_url(home_url(), PHP_URL_HOST);
    }

    public function test_route_classification_covers_mcp_and_oauth_only(): void
    {
        $this->assertTrue(Transport_Guard::is_mcp_route('/mcp/wpmcp-server'));
        $this->assertTrue(Transport_Guard::is_oauth_route('/wpmcp/v1/oauth/token'));
        $this->assertTrue(Transport_Guard::is_guarded_route('/mcp/wpmcp-server'));
        $this->assertTrue(Transport_Guard::is_guarded_route('/wpmcp/v1/oauth/register'));

        $this->assertTrue(Transport_Guard::is_guarded_route('/wpmcp/v1/chat/key'));
        $this->assertTrue(Transport_Guard::is_guarded_route('/wpmcp/v1/chat/message'));
        $this->assertFalse(Transport_Guard::is_guarded_route('/wp/v2/posts'));
        $this->assertFalse(Transport_Guard::is_guarded_route('/wpmcp/v1/something-else'));
        $this->assertFalse(Transport_Guard::is_mcp_route('/wp/v2/mcp/nope'));
    }

    public function test_host_matching_ignores_case_port_and_www(): void
    {
        $this->assertTrue(Transport_Guard::host_matches('Example.COM', 'example.com'));
        $this->assertTrue(Transport_Guard::host_matches('example.com:443', 'example.com'));
        $this->assertTrue(Transport_Guard::host_matches('www.example.com', 'example.com'));
        $this->assertTrue(Transport_Guard::host_matches('example.com', 'www.example.com:8080'));

        $this->assertFalse(Transport_Guard::host_matches('old.example.com', 'example.com'));
    }

    public function test_a_missing_host_header_fails_open(): void
    {
        // A request with no Host at all must never be the thing that takes
        // the endpoint down.
        $this->assertTrue(Transport_Guard::host_matches('', 'example.com'));
    }

    public function test_matching_host_passes_dispatch_through_untouched(): void
    {
        $guard  = new Transport_Guard();
        $result = $guard->filter_pre_dispatch(null, null, $this->request('/mcp/wpmcp-server', $this->home_host()));

        $this->assertNull($result);
    }

    public function test_mismatched_host_is_rejected_with_a_structured_421(): void
    {
        $guard  = new Transport_Guard();
        $result = $guard->filter_pre_dispatch(null, null, $this->request('/mcp/wpmcp-server', 'stale-domain.example'));

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame(Transport_Guard::MISMATCH_CODE, $result->get_error_code());

        $data = $result->get_error_data();
        $this->assertSame(421, $data['status']);
        $this->assertSame($this->home_host(), $data['expected_host']);
        $this->assertStringContainsString('/wp-json/mcp/', $data['endpoint']);
    }

    public function test_the_host_guard_can_be_disabled_by_filter_for_reverse_proxies(): void
    {
        add_filter('wpmcp_host_guard_enabled', '__return_false');

        $guard  = new Transport_Guard();
        $result = $guard->filter_pre_dispatch(null, null, $this->request('/mcp/wpmcp-server', 'stale-domain.example'));

        $this->assertNull($result);
    }

    public function test_the_host_guard_does_not_touch_non_mcp_routes(): void
    {
        $guard = new Transport_Guard();

        $this->assertNull($guard->filter_pre_dispatch(null, null, $this->request('/wp/v2/posts', 'stale-domain.example')));
        // OAuth routes are hardened but not host-guarded: an authorization
        // flow can legitimately be started against an alternate host.
        $this->assertNull($guard->filter_pre_dispatch(null, null, $this->request('/wpmcp/v1/oauth/token', 'stale-domain.example')));
    }

    public function test_the_host_falls_back_to_the_server_global_when_the_request_carries_no_header(): void
    {
        $original = $_SERVER['HTTP_HOST'] ?? null;

        try {
            $_SERVER['HTTP_HOST'] = 'stale-domain.example';
            $guard                = new Transport_Guard();

            $result = $guard->filter_pre_dispatch(null, null, $this->request('/mcp/wpmcp-server'));

            $this->assertInstanceOf(\WP_Error::class, $result);
            $this->assertSame(Transport_Guard::MISMATCH_CODE, $result->get_error_code());

            $_SERVER['HTTP_HOST'] = $this->home_host();
            $this->assertNull($guard->filter_pre_dispatch(null, null, $this->request('/mcp/wpmcp-server')));
        } finally {
            if (null === $original) {
                unset($_SERVER['HTTP_HOST']);
            } else {
                $_SERVER['HTTP_HOST'] = $original;
            }
        }
    }

    public function test_display_errors_is_forced_off_on_guarded_routes(): void
    {
        @ini_set('display_errors', '1');

        $guard = new Transport_Guard();
        $guard->filter_pre_dispatch(null, null, $this->request('/wpmcp/v1/oauth/token', $this->home_host()));

        $this->assertSame('0', (string) ini_get('display_errors'));
    }

    public function test_display_errors_is_left_alone_on_other_routes(): void
    {
        @ini_set('display_errors', '1');

        $guard = new Transport_Guard();
        $guard->filter_pre_dispatch(null, null, $this->request('/wp/v2/posts', $this->home_host()));

        $this->assertSame('1', (string) ini_get('display_errors'));
    }

    public function test_the_no_store_header_set_covers_caches_and_buffering(): void
    {
        $headers = Transport_Guard::no_store_headers();

        $this->assertStringContainsString('no-store', $headers['Cache-Control']);
        $this->assertStringContainsString('must-revalidate', $headers['Cache-Control']);
        $this->assertSame('no-cache', $headers['Pragma']);
        // The vendor-specific opt-outs the shared-hosting stacks honour.
        $this->assertSame('no-cache', $headers['X-LiteSpeed-Cache-Control']);
        $this->assertSame('no', $headers['X-Accel-Buffering']);
    }

    public function test_pre_serve_passes_the_served_flag_through_unchanged(): void
    {
        $guard = new Transport_Guard();

        // Headers are already sent under PHPUnit, so send_no_store_headers()
        // no-ops; what must hold either way is that the filter is
        // transparent and never claims to have served the request itself.
        $this->assertTrue($guard->filter_pre_serve(true, null, $this->request('/mcp/wpmcp-server')));
        $this->assertFalse($guard->filter_pre_serve(false, null, $this->request('/mcp/wpmcp-server')));
        $this->assertFalse($guard->filter_pre_serve(false, null, $this->request('/wp/v2/posts')));
    }

    public function test_pre_dispatch_tolerates_a_request_that_is_not_a_rest_request(): void
    {
        $guard = new Transport_Guard();

        $this->assertSame('untouched', $guard->filter_pre_dispatch('untouched', null, null));
        $this->assertSame('untouched', $guard->filter_pre_dispatch('untouched', null, new \stdClass()));
    }

    public function test_register_attaches_both_transport_filters(): void
    {
        $guard = new Transport_Guard();
        $guard->register();

        $this->assertNotFalse(has_filter('rest_pre_dispatch', [$guard, 'filter_pre_dispatch']));
        $this->assertNotFalse(has_filter('rest_pre_serve_request', [$guard, 'filter_pre_serve']));

        remove_filter('rest_pre_dispatch', [$guard, 'filter_pre_dispatch'], 10);
        remove_filter('rest_pre_serve_request', [$guard, 'filter_pre_serve'], 10);
    }
}
