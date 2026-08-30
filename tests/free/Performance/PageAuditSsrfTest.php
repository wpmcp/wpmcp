<?php

namespace WPMCP\Tests\Free\Performance;

use WPMCP\Tools\Performance\Page_Audit;

class PageAuditSsrfTest extends \WP_UnitTestCase
{
    private Page_Audit $audit;
    private bool $http_request_fired = false;
    private int $http_request_count  = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->audit               = new Page_Audit();
        $this->http_request_fired  = false;
        $this->http_request_count  = 0;
        add_filter('pre_http_request', [$this, 'record_and_fail'], 10, 3);
    }

    protected function tearDown(): void
    {
        remove_filter('pre_http_request', [$this, 'record_and_fail'], 10);
        parent::tearDown();
    }

    /**
     * If fetch() ever dispatches an HTTP request for a refused target, this
     * records that fact and fails the request, so a test can assert dispatch
     * never happened rather than merely getting a WP HTTP error back.
     */
    public function record_and_fail($preempt, $parsed_args, $url)
    {
        $this->http_request_fired = true;
        return new \WP_Error('unexpected_dispatch', 'fetch() should not have dispatched an HTTP request');
    }

    public function test_fetch_refuses_a_literal_loopback_url(): void
    {
        $result = $this->audit->fetch('http://127.0.0.1/');

        $this->assertFalse($this->http_request_fired, 'fetch() must refuse before dispatching HTTP');
        $this->assertFalse($result['ok']);
        $this->assertSame('refused_private_target', $result['error']);
    }

    public function test_fetch_refuses_a_private_range_ip_url(): void
    {
        $result = $this->audit->fetch('http://192.168.1.1/');

        $this->assertFalse($this->http_request_fired, 'fetch() must refuse before dispatching HTTP');
        $this->assertFalse($result['ok']);
        $this->assertSame('refused_private_target', $result['error']);
    }

    public function test_fetch_refuses_ipv6_loopback(): void
    {
        $result = $this->audit->fetch('http://[::1]/');

        $this->assertFalse($this->http_request_fired, 'fetch() must refuse before dispatching HTTP');
        $this->assertFalse($result['ok']);
        $this->assertSame('refused_private_target', $result['error']);
    }

    public function test_fetch_allows_a_public_ip_and_dispatches_via_wp_safe_remote_get(): void
    {
        // A literal public IP needs no DNS lookup, keeping this test off the
        // real network entirely while still proving the guard does not
        // block a legitimate public target and that dispatch happens.
        remove_filter('pre_http_request', [$this, 'record_and_fail'], 10);
        add_filter('pre_http_request', [$this, 'serve_canned_response'], 10, 3);

        $result = $this->audit->fetch('http://93.184.216.34/');

        remove_filter('pre_http_request', [$this, 'serve_canned_response'], 10);

        $this->assertTrue($result['ok']);
        $this->assertSame(200, $result['status_code']);
        $this->assertSame('<html>ok</html>', $result['body']);
    }

    /**
     * Count how many callbacks are currently registered on $hook, across all
     * priorities. Used instead of has_filter($hook) (no callback argument),
     * which only reports whether the hook is non-empty at all and would be
     * fooled by unrelated callbacks other code may already have registered.
     */
    private function count_filters(string $hook): int
    {
        global $wp_filter;

        if (! isset($wp_filter[ $hook ])) {
            return 0;
        }

        $count = 0;
        foreach ($wp_filter[ $hook ]->callbacks as $callbacks) {
            $count += count($callbacks);
        }
        return $count;
    }

    public function serve_canned_response($preempt, $parsed_args, $url)
    {
        return [
            'headers'  => [],
            'body'     => '<html>ok</html>',
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies'  => [],
        ];
    }

    public function test_fetch_does_not_follow_a_redirect_and_surfaces_it_as_a_finding(): void
    {
        // The target answers with a 3xx pointing at an internal host. We must
        // not chase it: exactly one request fires (no off-target second hop),
        // and analyze() surfaces the redirect as its own finding.
        remove_filter('pre_http_request', [$this, 'record_and_fail'], 10);
        add_filter('pre_http_request', [$this, 'serve_redirect'], 10, 3);

        $result   = $this->audit->fetch('http://93.184.216.34/');
        $analysis = $this->audit->analyze($result, false);

        remove_filter('pre_http_request', [$this, 'serve_redirect'], 10);

        $this->assertSame(1, $this->http_request_count, 'fetch() must not follow the redirect to a second target');
        $this->assertSame(302, $result['status_code']);

        $redirect = null;
        foreach ($analysis['findings'] as $finding) {
            if ('redirect' === $finding['id']) {
                $redirect = $finding;
                break;
            }
        }
        $this->assertNotNull($redirect, 'a redirect finding must be present');
        $this->assertSame('warning', $redirect['status']);
    }

    public function test_fetch_refuses_an_ipv4_mapped_ipv6_loopback_resolved_for_the_host(): void
    {
        // The host resolves (via the injected seam) to an IPv4-mapped IPv6
        // loopback. The naked FILTER_FLAG_NO_PRIV_RANGE|NO_RES_RANGE check
        // does not catch ::ffff:127.0.0.1, so the guard must normalize the
        // embedded IPv4 and reject it.
        $audit = new Page_Audit(static fn (string $host): array => ['::ffff:127.0.0.1']);

        $result = $audit->fetch('http://mapped-loopback.test/');

        $this->assertFalse($this->http_request_fired, 'fetch() must refuse before dispatching HTTP');
        $this->assertFalse($result['ok']);
        $this->assertSame('refused_private_target', $result['error']);
    }

    /**
     * Prove the embedded-IPv4 normalization is load-bearing, independent of
     * whatever the platform filter_var() happens to do with mapped forms: the
     * private resolution seam feeds a hex-form mapped loopback and the guard
     * must reject it.
     */
    public function test_resolves_to_private_ip_rejects_hex_form_mapped_loopback(): void
    {
        $audit  = new Page_Audit(static fn (string $host): array => ['::ffff:7f00:1']);
        $method = new \ReflectionMethod($audit, 'resolves_to_private_ip');

        $this->assertTrue($method->invoke($audit, 'mapped-loopback.test'));
    }

    public function test_mapped_ipv4_extracts_embedded_address_and_ignores_plain_ipv6(): void
    {
        $audit  = new Page_Audit();
        $method = new \ReflectionMethod($audit, 'mapped_ipv4');

        $this->assertSame('127.0.0.1', $method->invoke($audit, '::ffff:127.0.0.1'));
        $this->assertSame('127.0.0.1', $method->invoke($audit, '::ffff:7f00:1'));
        $this->assertSame('192.168.1.1', $method->invoke($audit, '::ffff:192.168.1.1'));
        $this->assertNull($method->invoke($audit, '2606:2800:220:1:248:1893:25c8:1946'));
        $this->assertNull($method->invoke($audit, '93.184.216.34'));
    }

    public function serve_redirect($preempt, $parsed_args, $url)
    {
        $this->http_request_count++;
        $this->assertSame(0, $parsed_args['redirection'], 'fetch() must request no redirect following');
        return [
            'headers'  => ['location' => 'http://127.0.0.1/internal'],
            'body'     => '',
            'response' => ['code' => 302, 'message' => 'Found'],
            'cookies'  => [],
        ];
    }

    public function test_pick_pinned_ip_returns_the_literal_ip_for_an_ip_host(): void
    {
        $audit  = new Page_Audit();
        $method = new \ReflectionMethod($audit, 'pick_pinned_ip');

        $this->assertSame('93.184.216.34', $method->invoke($audit, '93.184.216.34'));
    }

    public function test_pick_pinned_ip_returns_the_first_public_candidate_from_the_resolver(): void
    {
        $audit  = new Page_Audit(static fn (string $host): array => ['93.184.216.34', '203.0.113.9']);
        $method = new \ReflectionMethod($audit, 'pick_pinned_ip');

        $this->assertSame('93.184.216.34', $method->invoke($audit, 'example.test'));
    }

    public function test_pick_pinned_ip_returns_null_when_the_resolver_yields_no_candidates(): void
    {
        $audit  = new Page_Audit(static fn (string $host): array => []);
        $method = new \ReflectionMethod($audit, 'pick_pinned_ip');

        $this->assertNull($method->invoke($audit, 'unresolvable.test'));
    }

    public function test_fetch_pins_the_validated_ip_via_curlopt_resolve_and_removes_the_filter_after(): void
    {
        if (! function_exists('curl_init')) {
            $this->markTestSkipped('curl is not available in this environment');
        }

        // pre_http_request fires before the curl transport is selected, so
        // this short-circuits the request (no real network call) while still
        // letting us inspect, at that exact moment, whether fetch() has wired
        // up the scoped http_api_curl pin for this request. We count
        // http_api_curl callbacks before/after rather than asserting the bare
        // hook is empty, because unrelated code (e.g. WooCommerce's
        // WC_HTTPS::http_api_curl) may already be registered on it globally.
        $count_before             = $this->count_filters('http_api_curl');
        $seen_pin_during_request  = null;
        $count_during             = null;
        $capture                  = function ($preempt, $parsed_args, $url) use (&$seen_pin_during_request, &$count_during) {
            $count_during            = $this->count_filters('http_api_curl');
            $seen_pin_during_request = $this->audit->get_last_pinned_ip();
            return new \WP_Error('short_circuited', 'test short-circuit before transport dispatch');
        };
        add_filter('pre_http_request', $capture, 20, 3);
        remove_filter('pre_http_request', [$this, 'record_and_fail'], 10);

        $audit        = new Page_Audit(static fn (string $host): array => ['93.184.216.34']);
        $this->audit  = $audit;
        $result       = $audit->fetch('http://example-pin.test/');

        remove_filter('pre_http_request', $capture, 20);
        add_filter('pre_http_request', [$this, 'record_and_fail'], 10, 3);

        $this->assertSame($count_before + 1, $count_during, 'fetch() must register exactly one additional http_api_curl callback for the duration of the request');
        $this->assertSame('93.184.216.34', $seen_pin_during_request, 'fetch() must pin the validated resolved IP');
        $this->assertSame('93.184.216.34', $audit->get_last_pinned_ip());
        $this->assertSame($count_before, $this->count_filters('http_api_curl'), 'the http_api_curl pin filter must be removed once the request completes, leaving no net change');
    }

    /**
     * The pin is scoped to one request by construction: it is added before
     * wp_safe_remote_get() and removed after. A throw from anywhere inside
     * the HTTP stack (a third-party pre_http_request or http_api_curl hook
     * is arbitrary site code) must not be able to skip the removal, or every
     * later WP curl request to the same host:port in this PHP process would
     * silently inherit a stale IP pin.
     */
    public function test_the_pin_filter_is_removed_even_when_the_http_stack_throws(): void
    {
        if (! function_exists('curl_init')) {
            $this->markTestSkipped('curl is not available in this environment');
        }

        $count_before = $this->count_filters('http_api_curl');
        $thrower      = static function ($preempt, $parsed_args, $url) {
            throw new \RuntimeException('third-party hook exploded');
        };
        add_filter('pre_http_request', $thrower, 20, 3);
        remove_filter('pre_http_request', [$this, 'record_and_fail'], 10);

        $audit  = new Page_Audit(static fn (string $host): array => ['93.184.216.34']);
        $caught = null;
        try {
            $audit->fetch('http://example-pin.test/');
        } catch (\RuntimeException $e) {
            $caught = $e;
        } finally {
            remove_filter('pre_http_request', $thrower, 20);
            add_filter('pre_http_request', [$this, 'record_and_fail'], 10, 3);
        }

        $this->assertInstanceOf(\RuntimeException::class, $caught, 'the throw must propagate rather than be swallowed');
        $this->assertSame(
            $count_before,
            $this->count_filters('http_api_curl'),
            'the http_api_curl pin must be removed even when the request throws, leaving no net change'
        );
    }

    public function test_fetch_builds_the_curl_resolve_entry_for_the_pinned_host_and_port(): void
    {
        $audit  = new Page_Audit();
        $method = new \ReflectionMethod($audit, 'build_curl_resolve_entry');

        $this->assertSame('example.test:80:93.184.216.34', $method->invoke($audit, 'example.test', 80, '93.184.216.34'));
        $this->assertSame('example.test:443:93.184.216.34', $method->invoke($audit, 'example.test', 443, '93.184.216.34'));
    }
}
