<?php

namespace WPMCP\Tests\Free\Diagnostics;

use WPMCP\MCP\Opt_In_Required;
use WPMCP\Tools\Diagnostics\Access_Log_Search;
use WPMCP\Tools\Diagnostics\Get_Debug_Log;

/**
 * get-debug-log source=access (issue #848): search the web server access
 * log, opt-in only, inside an allowlist of log folders, with IPs masked by
 * default and secrets in query strings always masked.
 */
class AccessLogSearchTest extends \WP_UnitTestCase
{
    private string $dir;

    /** @var string[] */
    private array $paths = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/wpmcp-access-log-' . wp_generate_password(8, false);
        mkdir($this->dir);
        $this->dir = (string) realpath($this->dir);
        $this->paths = [ $this->dir . '/access.log' ];
        add_filter('wpmcp_access_log_paths', fn (): array => $this->paths);
        add_filter(Access_Log_Search::ENABLE_FILTER, '__return_true');
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->dir . '/*') as $file) {
            unlink((string) $file);
        }
        rmdir($this->dir);
        remove_all_filters('wpmcp_access_log_paths');
        remove_all_filters(Access_Log_Search::ENABLE_FILTER);
        remove_all_filters(Access_Log_Search::UNMASK_FILTER);
        parent::tearDown();
    }

    private static function line(string $ip, string $time, string $method, string $path, int $status, string $agent = 'Mozilla/5.0'): string
    {
        return sprintf('%s - - [%s] "%s %s HTTP/1.1" %d 512 "-" "%s"', $ip, $time, $method, $path, $status, $agent);
    }

    private function write(string $name, array $lines): string
    {
        $abs = $this->dir . '/' . $name;
        file_put_contents($abs, implode("\n", $lines) . "\n");
        return $abs;
    }

    private function search(array $args): array
    {
        return (new Get_Debug_Log())->handle([ 'source' => 'access' ] + $args);
    }

    public function test_refuses_with_opt_in_required_until_the_site_opts_in(): void
    {
        remove_all_filters(Access_Log_Search::ENABLE_FILTER);
        $this->write('access.log', [ self::line('203.0.113.7', '10/Oct/2026:13:55:36 +0000', 'GET', '/', 200) ]);

        $this->expectException(Opt_In_Required::class);
        $this->expectExceptionMessage(Access_Log_Search::ENABLE_FILTER);
        $this->search([]);
    }

    public function test_a_missing_log_is_refused_naming_every_path_tried(): void
    {
        $this->paths = [ $this->dir . '/nope.log', $this->dir . '/also-missing.log' ];

        try {
            $this->search([]);
            $this->fail('Expected a refusal');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('nope.log', $e->getMessage());
            $this->assertStringContainsString('also-missing.log', $e->getMessage());
            $this->assertStringContainsString('WPMCP_ACCESS_LOG', $e->getMessage());
        }
    }

    public function test_apache_combined_search_is_newest_first_and_masks_ips_by_default(): void
    {
        $this->write('access.log', [
            self::line('203.0.113.7', '10/Oct/2026:13:55:36 +0000', 'GET', '/', 200),
            self::line('198.51.100.4', '10/Oct/2026:13:56:00 +0000', 'POST', '/xmlrpc.php', 200),
            self::line('203.0.113.7', '10/Oct/2026:13:57:00 +0000', 'GET', '/wp-content/plugins/fake/x.php', 404),
            self::line('203.0.113.7', '10/Oct/2026:13:58:00 +0000', 'GET', '/about/', 200),
        ]);

        $out = $this->search([ 'filter' => [ 'ip' => '203.0.113.7' ] ]);

        $this->assertSame('access', $out['source']);
        $this->assertTrue($out['masked']);
        $this->assertCount(3, $out['entries']);
        $this->assertSame('/about/', $out['entries'][0]['path']);
        $this->assertSame('/', $out['entries'][2]['path']);
        $this->assertSame('203.0.*.*', $out['entries'][0]['ip']);
        $this->assertSame('M***', $out['entries'][0]['agent']);
        $this->assertSame('2026-10-10T13:58:00+00:00', $out['entries'][0]['time']);
        $this->assertNull($out['next_cursor']);

        $out = $this->search([ 'filter' => [ 'status' => '4xx' ] ]);
        $this->assertCount(1, $out['entries']);
        $this->assertSame(404, $out['entries'][0]['status']);

        $out = $this->search([ 'filter' => [ 'method' => 'post', 'path' => 'xmlrpc' ] ]);
        $this->assertCount(1, $out['entries']);
        $this->assertSame('198.51.*.*', $out['entries'][0]['ip']);
    }

    public function test_unmask_filter_returns_full_ips_and_agents(): void
    {
        add_filter(Access_Log_Search::UNMASK_FILTER, '__return_true');
        $this->write('access.log', [ self::line('203.0.113.7', '10/Oct/2026:13:55:36 +0000', 'GET', '/', 200, 'curl/8.0') ]);

        $out = $this->search([]);

        $this->assertFalse($out['masked']);
        $this->assertSame('203.0.113.7', $out['entries'][0]['ip']);
        $this->assertSame('curl/8.0', $out['entries'][0]['agent']);
    }

    public function test_nginx_and_vhost_prefixed_lines_parse(): void
    {
        $this->write('access.log', [
            // nginx default "combined".
            '192.0.2.10 - - [10/Oct/2026:10:00:00 +0500] "GET /wp-login.php HTTP/2.0" 200 1043 "-" "Go-http-client/1.1"',
            // Apache vhost_combined.
            'example.com:443 192.0.2.11 - - [10/Oct/2026:10:00:01 +0500] "POST /wp-login.php HTTP/1.1" 302 0 "https://example.com/" "Mozilla/5.0"',
            'not a log line',
        ]);

        $out = $this->search([ 'filter' => [ 'path' => 'wp-login' ] ]);

        $this->assertCount(2, $out['entries']);
        $this->assertSame('POST', $out['entries'][0]['method']);
        $this->assertSame(302, $out['entries'][0]['status']);
        $this->assertSame('192.0.*.*', $out['entries'][0]['ip']);
        $this->assertSame('2026-10-10T05:00:00+00:00', $out['entries'][1]['time']);
    }

    public function test_secrets_in_query_strings_are_masked(): void
    {
        $this->write('access.log', [
            self::line('203.0.113.7', '10/Oct/2026:13:55:36 +0000', 'GET', '/wp-login.php?action=rp&key=abc123&login=admin&page=2', 200),
        ]);

        $out = $this->search([]);

        $this->assertSame('/wp-login.php?action=rp&key=***&login=***&page=2', $out['entries'][0]['path']);
    }

    public function test_rotated_and_gzip_logs_are_read_in_order_with_a_cursor(): void
    {
        $this->write('access.log', [ self::line('203.0.113.1', '12/Oct/2026:00:00:00 +0000', 'GET', '/newest', 200) ]);
        $this->write('access.log.1', [ self::line('203.0.113.2', '11/Oct/2026:00:00:00 +0000', 'GET', '/middle', 200) ]);
        file_put_contents(
            $this->dir . '/access.log.2.gz',
            gzencode(self::line('203.0.113.3', '10/Oct/2026:00:00:00 +0000', 'GET', '/oldest', 200) . "\n")
        );

        $first = $this->search([ 'lines' => 2 ]);
        $this->assertSame([ '/newest', '/middle' ], array_column($first['entries'], 'path'));
        $this->assertNotNull($first['next_cursor']);

        $second = $this->search([ 'lines' => 2, 'cursor' => $first['next_cursor'] ]);
        $this->assertSame([ '/oldest' ], array_column($second['entries'], 'path'));
        $this->assertNull($second['next_cursor']);
        $this->assertContains($this->dir . '/access.log.2.gz', $second['files']);
    }

    public function test_time_window_around_a_files_mtime(): void
    {
        $file = WP_CONTENT_DIR . '/uploads/wpmcp-848-shell.php';
        wp_mkdir_p(dirname($file));
        file_put_contents($file, '<?php');
        touch($file, (int) strtotime('2026-10-10T12:00:00+00:00'));

        $this->write('access.log', [
            self::line('203.0.113.1', '10/Oct/2026:11:00:00 +0000', 'GET', '/early', 200),
            self::line('203.0.113.2', '10/Oct/2026:11:59:30 +0000', 'POST', '/wp-admin/admin-ajax.php', 200),
            self::line('203.0.113.3', '10/Oct/2026:12:04:00 +0000', 'GET', '/wp-content/uploads/wpmcp-848-shell.php', 200),
            self::line('203.0.113.4', '10/Oct/2026:13:00:00 +0000', 'GET', '/late', 200),
        ]);

        try {
            $out = $this->search([ 'filter' => [ 'around_file' => 'wp-content/uploads/wpmcp-848-shell.php', 'window' => 5 ] ]);
        } finally {
            unlink($file);
        }

        $this->assertSame(
            [ '/wp-content/uploads/wpmcp-848-shell.php', '/wp-admin/admin-ajax.php' ],
            array_column($out['entries'], 'path')
        );

        $out = $this->search([ 'filter' => [ 'since' => '2026-10-10T12:30:00Z' ] ]);
        $this->assertSame([ '/late' ], array_column($out['entries'], 'path'));
    }

    public function test_summary_counts_top_ips_paths_and_php_posts_under_wp_content(): void
    {
        $this->write('access.log', [
            self::line('203.0.113.7', '10/Oct/2026:13:55:36 +0000', 'POST', '/xmlrpc.php', 200),
            self::line('203.0.113.7', '10/Oct/2026:13:55:37 +0000', 'POST', '/xmlrpc.php', 200),
            self::line('198.51.100.4', '10/Oct/2026:13:55:38 +0000', 'POST', '/wp-content/uploads/2026/10/x.php?cmd=id', 200),
            self::line('192.0.2.1', '10/Oct/2026:13:55:39 +0000', 'GET', '/', 200),
        ]);

        $out = $this->search([ 'summary' => true ]);

        $this->assertSame(4, $out['summary']['requests']);
        $this->assertSame([ 'ip' => '203.0.*.*', 'count' => 2 ], $out['summary']['top_ips'][0]);
        $this->assertSame([ 'path' => '/xmlrpc.php', 'count' => 2 ], $out['summary']['top_paths'][0]);
        $this->assertSame([ '2xx' => 4 ], $out['summary']['status']);
        $this->assertCount(1, $out['summary']['php_posts']);
        $this->assertSame('/wp-content/uploads/2026/10/x.php?cmd=id', $out['summary']['php_posts'][0]['path']);
        $this->assertTrue($out['summary']['complete']);
    }

    public function test_a_path_outside_the_log_folders_is_refused(): void
    {
        $this->write('access.log', [ self::line('203.0.113.7', '10/Oct/2026:13:55:36 +0000', 'GET', '/', 200) ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not inside');
        $this->search([ 'path' => ABSPATH . 'wp-load.php' ]);
    }

    public function test_a_rotated_log_in_the_folder_can_be_chosen_by_path(): void
    {
        $this->write('access.log', [ self::line('203.0.113.1', '12/Oct/2026:00:00:00 +0000', 'GET', '/newest', 200) ]);
        $other = $this->write('other_access.log', [ self::line('203.0.113.2', '11/Oct/2026:00:00:00 +0000', 'GET', '/other', 200) ]);

        $out = $this->search([ 'path' => $other ]);

        $this->assertSame([ '/other' ], array_column($out['entries'], 'path'));
    }

    public function test_an_unknown_source_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Get_Debug_Log())->handle([ 'source' => 'mail' ]);
    }
}
