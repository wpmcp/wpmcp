<?php

namespace WPMCP\Tests\Free\Harness;

use WPMCP\Tests\Support\Network_Guard;

/**
 * The test network guard (issue #323) itself: unmocked requests are blocked
 * and recorded, a test's own mock wins, and only loopback URLs can be allowed.
 */
class NetworkGuardTest extends \WP_UnitTestCase
{
    protected function tearDown(): void
    {
        // Violations this class provokes on purpose must not fail it.
        Network_Guard::take_violations();
        parent::tearDown();
    }

    public function test_an_unmocked_request_is_blocked_and_recorded(): void
    {
        Network_Guard::take_violations();

        $response = wp_remote_get('https://example.org/wpmcp-network-guard-probe?x=1');

        $this->assertWPError($response);
        $this->assertSame('wpmcp_test_network_blocked', $response->get_error_code());
        $violations = Network_Guard::take_violations();
        $this->assertCount(1, $violations);
        $this->assertSame('https://example.org/wpmcp-network-guard-probe?...', $violations[0]['url']);
        $this->assertSame([], Network_Guard::take_violations(), 'Taking violations resets them.');
    }

    public function test_a_tests_own_mock_answers_first(): void
    {
        $mock = static fn () => ['headers' => [], 'body' => 'mocked', 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => []];
        add_filter('pre_http_request', $mock);

        $response = wp_remote_get('https://example.org/mocked');

        $this->assertSame('mocked', wp_remote_retrieve_body($response));
        $this->assertSame([], Network_Guard::take_violations());
    }

    public function test_an_allowed_loopback_prefix_passes_through(): void
    {
        Network_Guard::allow('http://127.0.0.1:9/');

        $this->assertFalse(Network_Guard::filter(false, [], 'http://127.0.0.1:9/ping'));
        $this->assertSame([], Network_Guard::take_violations());
        $this->assertWPError(Network_Guard::filter(false, [], 'http://127.0.0.2:9/ping'));
    }

    public function test_public_hosts_cannot_be_allowed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Network_Guard::allow('https://api.wordpress.org/');
    }
}
