<?php

namespace WPMCP\Tests\Free\Connect;

use WPMCP\Connect\Connection_Tester;
use WPMCP\MCP\Transport_Guard;

/**
 * Issue #76: the server-side connection self-test. It POSTs an MCP
 * initialize request to this site's own MCP endpoint and classifies the
 * outcome. Any HTTP answer other than 404 proves the endpoint is mounted
 * and answering (401/403 simply mean "bring credentials"); a 404 means the
 * adapter route is missing; a transport error means the site cannot reach
 * itself (loopback blocked).
 *
 * Issue #133 extends it past the status line to the transport itself: the
 * no-store headers, response buffering, and JSON framing that
 * Transport_Guard exists to protect, each surfaced as a named check with
 * its own remediation text, plus the 421 site-URL mismatch classification.
 */
class ConnectionTesterTest extends \WP_UnitTestCase
{
    /** @return array<string, string> The headers a correctly-behaving host returns. */
    private function good_headers(): array
    {
        return [
            'cache-control'     => 'no-store, no-cache, must-revalidate, max-age=0',
            'x-accel-buffering' => 'no',
        ];
    }

    /** @param array<string, string> $headers */
    private function stub_response(int $code, array $headers = [], string $body = ''): void
    {
        add_filter('pre_http_request', static fn () => [
            'response' => ['code' => $code, 'message' => ''],
            'headers'  => $headers,
            'body'     => $body,
        ]);
    }

    /** @return array{id: string, label: string, ok: bool, detail: string} */
    private function check(array $result, string $id): array
    {
        foreach ($result['checks'] as $check) {
            if ($id === $check['id']) {
                return $check;
            }
        }

        $this->fail("The self-test did not report a '{$id}' check.");
    }

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
        // Nothing answered, so there is no transport to report on.
        $this->assertSame([], $result['checks']);
    }

    public function test_a_well_behaved_host_passes_every_transport_check(): void
    {
        $this->stub_response(401, $this->good_headers(), '{"error":"unauthorized"}');
        // The test site is plain HTTP, where Application Passwords are off by design.
        add_filter('wp_is_application_passwords_available', '__return_true');

        $result = (new Connection_Tester())->test();
        remove_all_filters('wp_is_application_passwords_available');

        $this->assertTrue($result['ok']);
        foreach ($result['checks'] as $check) {
            $this->assertTrue($check['ok'], "Check '{$check['id']}' unexpectedly failed.");
            $this->assertNotEmpty($check['label']);
            $this->assertNotEmpty($check['detail']);
        }
    }

    public function test_a_cache_layer_stripping_no_store_is_reported(): void
    {
        // The symptom a site owner sees is an MCP client replaying a stale
        // response; the cause is invisible without this check.
        $this->stub_response(401, ['cache-control' => 'public, max-age=600', 'x-accel-buffering' => 'no']);

        $result = (new Connection_Tester())->test();

        $this->assertFalse($this->check($result, 'no_store')['ok']);
        $this->assertStringContainsString('caching layer', $this->check($result, 'no_store')['detail']);
        // Advisory only: the endpoint is still up.
        $this->assertTrue($result['ok']);
    }

    public function test_a_proxy_buffering_the_response_is_reported(): void
    {
        $this->stub_response(401, ['cache-control' => 'no-store', 'x-accel-buffering' => 'yes']);

        $result = (new Connection_Tester())->test();

        $this->assertFalse($this->check($result, 'buffering')['ok']);
        $this->assertTrue($this->check($result, 'no_store')['ok']);
    }

    public function test_stray_php_output_in_the_body_is_reported_as_broken_framing(): void
    {
        // A notice printed ahead of the JSON is exactly what destroys
        // JSON-RPC framing and makes a client drop the connection.
        $this->stub_response(
            200,
            $this->good_headers(),
            "<br /><b>Notice</b>: Undefined index in /wp-content/plugins/x.php on line 3\n{\"jsonrpc\":\"2.0\"}"
        );

        $result = (new Connection_Tester())->test();

        $framing = $this->check($result, 'json_framing');
        $this->assertFalse($framing['ok']);
        $this->assertStringContainsString('not valid JSON', $framing['detail']);
    }

    public function test_an_empty_body_is_not_treated_as_broken_framing(): void
    {
        $this->stub_response(401, $this->good_headers(), '');

        $this->assertTrue($this->check((new Connection_Tester())->test(), 'json_framing')['ok']);
    }

    public function test_a_421_is_classified_as_the_site_url_mismatch_it_is(): void
    {
        $this->stub_response(421, $this->good_headers(), '{"code":"wpmcp_site_url_mismatch"}');

        $result = (new Connection_Tester())->test();

        $this->assertFalse($result['ok']);
        $this->assertSame(421, $result['status']);
        $this->assertStringContainsString('site-URL mismatch', $result['message']);
        $this->assertStringContainsString('wpmcp_host_guard_enabled', $result['message']);
    }

    public function test_a_404_still_reports_the_transport_checks_it_could_run(): void
    {
        $this->stub_response(404, $this->good_headers());

        $result = (new Connection_Tester())->test();

        $this->assertFalse($result['ok']);
        $this->assertNotEmpty($result['checks']);
    }

    public function test_the_expected_header_set_is_the_guards_own(): void
    {
        $this->assertSame(Transport_Guard::no_store_headers(), Connection_Tester::expected_headers());
    }
}
