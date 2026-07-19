<?php

namespace WPMCP\Tests\Free\Connect;

use WPMCP\Connect\Connection_Tester;

/**
 * Issue #76: the server-side connection self-test. It POSTs an MCP
 * initialize request to this site's own MCP endpoint and classifies the
 * outcome. Any HTTP answer other than 404 proves the endpoint is mounted
 * and answering (401/403 simply mean "bring credentials"); a 404 means the
 * adapter route is missing; a transport error means the site cannot reach
 * itself (loopback blocked).
 */
class ConnectionTesterTest extends \WP_UnitTestCase
{
    public function test_an_unauthorized_answer_still_counts_as_reachable(): void
    {
        add_filter('pre_http_request', static fn () => [
            'response' => ['code' => 401, 'message' => 'Unauthorized'],
            'headers'  => [],
            'body'     => '',
        ]);

        $result = (new Connection_Tester())->test();

        $this->assertTrue($result['ok']);
        $this->assertSame(401, $result['status']);
        $this->assertNotEmpty($result['message']);
    }

    public function test_a_404_means_the_adapter_route_is_missing(): void
    {
        add_filter('pre_http_request', static fn () => [
            'response' => ['code' => 404, 'message' => 'Not Found'],
            'headers'  => [],
            'body'     => '',
        ]);

        $result = (new Connection_Tester())->test();

        $this->assertFalse($result['ok']);
        $this->assertSame(404, $result['status']);
    }

    public function test_a_transport_error_reports_the_failure_message(): void
    {
        add_filter('pre_http_request', static fn () => new \WP_Error(
            'http_request_failed',
            'cURL error 7: could not connect'
        ));

        $result = (new Connection_Tester())->test();

        $this->assertFalse($result['ok']);
        $this->assertNull($result['status']);
        $this->assertStringContainsString('could not connect', $result['message']);
    }
}
