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
}
