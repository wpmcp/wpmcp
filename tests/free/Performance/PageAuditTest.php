<?php

namespace WPMCP\Tests\Free\Performance;

use WPMCP\Tools\Performance\Page_Audit;

class PageAuditTest extends \WP_UnitTestCase
{
    private Page_Audit $audit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->audit = new Page_Audit();
    }

    private function fetched(string $body, array $headers = [], int $status = 200): array
    {
        return [
            'ok'          => true,
            'status_code' => $status,
            'response_ms' => 120,
            'total_bytes' => strlen($body),
            'headers'     => $headers,
            'body'        => $body,
            'error'       => null,
            'host'        => 'example.com',
        ];
    }

    private function status_of(array $result, string $id): string
    {
        foreach ($result['findings'] as $finding) {
            if ($finding['id'] === $id) {
                return $finding['status'];
            }
        }
        return 'MISSING';
    }

    public function test_failed_fetch_degrades_gracefully(): void
    {
        $result = $this->audit->analyze([
            'ok' => false, 'status_code' => 0, 'response_ms' => 0, 'total_bytes' => 0,
            'headers' => [], 'body' => '', 'error' => 'timeout', 'host' => 'example.com',
        ], false);

        $this->assertFalse($result['page_fetch']['ok']);
        $this->assertSame('timeout', $result['page_fetch']['error']);
        $this->assertSame('warning', $this->status_of($result, 'page_fetch'));
        $this->assertCount(1, $result['findings']);
    }

    public function test_http_status_pass_on_200_warning_otherwise(): void
    {
        $pass = $this->audit->analyze($this->fetched('<html></html>', [], 200), false);
        $this->assertSame('pass', $this->status_of($pass, 'http_status'));

        $warn = $this->audit->analyze($this->fetched('<html></html>', [], 404), false);
        $this->assertSame('warning', $this->status_of($warn, 'http_status'));
    }

    private function fetched_with_ms(string $body, int $ms): array
    {
        $fetched               = $this->fetched($body);
        $fetched['response_ms'] = $ms;
        return $fetched;
    }

    public function test_response_time_pass_at_or_under_800ms_warning_above(): void
    {
        $this->assertSame('pass', $this->status_of($this->audit->analyze($this->fetched_with_ms('<html></html>', 800), false), 'response_time'));
        $this->assertSame('warning', $this->status_of($this->audit->analyze($this->fetched_with_ms('<html></html>', 801), false), 'response_time'));
    }

    public function test_html_size_pass_at_or_under_threshold_warning_above(): void
    {
        // Reference threshold: 512000 bytes (500 KB).
        $pass = $this->audit->analyze($this->fetched(str_repeat('a', 512000)), false);
        $this->assertSame('pass', $this->status_of($pass, 'html_size'));

        $warn = $this->audit->analyze($this->fetched(str_repeat('a', 512001)), false);
        $this->assertSame('warning', $this->status_of($warn, 'html_size'));
    }

    public function test_compression_detected_from_content_encoding_header(): void
    {
        $gzip = $this->audit->analyze($this->fetched('<html></html>', ['content-encoding' => 'gzip']), false);
        $this->assertSame('pass', $this->status_of($gzip, 'compression'));

        $brotli = $this->audit->analyze($this->fetched('<html></html>', ['content-encoding' => 'br']), false);
        $this->assertSame('pass', $this->status_of($brotli, 'compression'));

        $none = $this->audit->analyze($this->fetched('<html></html>', []), false);
        $this->assertSame('warning', $this->status_of($none, 'compression'));
    }

    public function test_cache_headers_detected_from_any_of_three_headers(): void
    {
        $cache_control = $this->audit->analyze($this->fetched('<html></html>', ['cache-control' => 'max-age=600']), false);
        $this->assertSame('pass', $this->status_of($cache_control, 'cache_headers'));

        $expires = $this->audit->analyze($this->fetched('<html></html>', ['expires' => 'Wed, 21 Oct 2026 07:28:00 GMT']), false);
        $this->assertSame('pass', $this->status_of($expires, 'cache_headers'));

        $x_cache = $this->audit->analyze($this->fetched('<html></html>', ['x-cache' => 'HIT']), false);
        $this->assertSame('pass', $this->status_of($x_cache, 'cache_headers'));

        $none = $this->audit->analyze($this->fetched('<html></html>', []), false);
        $this->assertSame('warning', $this->status_of($none, 'cache_headers'));
    }
}
