<?php

namespace WPMCP\Tests\Free\Context;

use WPMCP\Tools\Context\Get_Rendered_Html;

/**
 * wpmcp/get-rendered-html: fetch this site's own rendered front end, and
 * nothing else. Every HTTP request is served by a pre_http_request stub, so
 * the suite never touches the network and can assert exactly which URLs were
 * dispatched (or that none were).
 */
class GetRenderedHtmlTest extends \WP_UnitTestCase
{
    private const NAME = 'wpmcp/get-rendered-html';

    /** @var array<int,string> URLs the stub was asked for, in order. */
    private array $requested = [];

    /** @var array<int,array> Parsed args of each dispatched request. */
    private array $request_args = [];

    /** @var array<string,array{status:int,body:string,headers?:array}> url => canned response. */
    private array $routes = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->requested    = [];
        $this->request_args = [];
        $this->routes       = [];
        add_filter('pre_http_request', [$this, 'serve'], 10, 3);
    }

    protected function tearDown(): void
    {
        remove_filter('pre_http_request', [$this, 'serve'], 10);
        parent::tearDown();
    }

    public function serve($preempt, $args, $url)
    {
        $this->requested[]    = $url;
        $this->request_args[] = $args;
        if (! isset($this->routes[ $url ])) {
            return new \WP_Error('unexpected_dispatch', 'No stub route for ' . $url);
        }
        $route = $this->routes[ $url ];
        return [
            'headers'  => $route['headers'] ?? ['content-type' => 'text/html; charset=UTF-8'],
            'body'     => $route['body'],
            'response' => ['code' => $route['status'], 'message' => 'Stub'],
            'cookies'  => [],
            'filename' => null,
        ];
    }

    private function route(string $url, string $body, int $status = 200, array $headers = []): void
    {
        $this->routes[ $url ] = ['status' => $status, 'body' => $body] + ($headers ? ['headers' => $headers] : []);
    }

    private function tool(): Get_Rendered_Html
    {
        return new Get_Rendered_Html();
    }

    private function assert_refused(array $args, string $needle): void
    {
        try {
            $this->tool()->handle($args);
            $this->fail('Expected the target to be refused: ' . wp_json_encode($args));
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString($needle, $e->getMessage());
        }
        $this->assertSame([], $this->requested, 'A refused target must never dispatch an HTTP request.');
    }

    // ------------------------------------------------------------------
    // Same-host fetch and chunking.
    // ------------------------------------------------------------------

    public function test_fetches_a_published_post_by_id(): void
    {
        $post_id = self::factory()->post->create(['post_status' => 'publish']);
        $url     = get_permalink($post_id);
        $this->route($url, '<html><body><h1>Hello</h1></body></html>');

        $out = $this->tool()->handle(['post_id' => $post_id]);

        $this->assertSame([$url], $this->requested);
        $this->assertSame(200, $out['status_code']);
        $this->assertSame($url, $out['final_url']);
        $this->assertSame('<html><body><h1>Hello</h1></body></html>', $out['content']);
        $this->assertSame(strlen('<html><body><h1>Hello</h1></body></html>'), $out['total_bytes']);
        $this->assertSame(1, $out['chunk_count']);
        $this->assertSame(0, $out['chunk_index']);
        $this->assertFalse($out['truncated']);
    }

    public function test_request_is_bounded_and_does_not_follow_redirects_itself(): void
    {
        $this->route(home_url('/'), 'ok');

        $this->tool()->handle([]);

        $args = $this->request_args[0];
        $this->assertSame(0, $args['redirection'], 'Redirects are followed by the tool, one validated hop at a time.');
        $this->assertLessThanOrEqual(15, $args['timeout']);
        $this->assertGreaterThan(0, (int) $args['limit_response_size']);
        $this->assertTrue($args['reject_unsafe_urls'], 'Must go through wp_safe_remote_get().');
    }

    public function test_accepts_a_same_site_path_and_a_same_site_absolute_url(): void
    {
        $this->route(home_url('/about/'), 'about page');

        $by_path = $this->tool()->handle(['url' => '/about/']);
        $by_url  = $this->tool()->handle(['url' => home_url('/about/')]);

        $this->assertSame('about page', $by_path['content']);
        $this->assertSame('about page', $by_url['content']);
        $this->assertSame(home_url('/about/'), $by_path['final_url']);
    }

    public function test_defaults_to_the_front_page(): void
    {
        $this->route(home_url('/'), 'front');

        $out = $this->tool()->handle([]);

        $this->assertSame('front', $out['content']);
    }

    public function test_splits_long_output_into_chunks(): void
    {
        $body = str_repeat('a', 1000) . str_repeat('b', 1000) . str_repeat('c', 500);
        $this->route(home_url('/'), $body);

        $first = $this->tool()->handle(['chunk_size' => 1000]);
        $last  = $this->tool()->handle(['chunk_size' => 1000, 'chunk' => 2]);

        $this->assertSame(2500, $first['total_bytes']);
        $this->assertSame(3, $first['chunk_count']);
        $this->assertSame(str_repeat('a', 1000), $first['content']);
        $this->assertSame(str_repeat('c', 500), $last['content']);
        $this->assertSame(2, $last['chunk_index']);
    }

    public function test_chunks_never_split_a_multibyte_character_and_reassemble_exactly(): void
    {
        $body = str_repeat('é', 1500); // 3000 bytes, two bytes per character.
        $this->route(home_url('/'), $body);

        $first = $this->tool()->handle(['chunk_size' => 1001]);
        $whole = '';
        for ($i = 0; $i < $first['chunk_count']; $i++) {
            $part = $this->tool()->handle(['chunk_size' => 1001, 'chunk' => $i])['content'];
            $this->assertTrue(mb_check_encoding($part, 'UTF-8'), "Chunk $i must be valid UTF-8.");
            $whole .= $part;
        }

        $this->assertSame($body, $whole);
    }

    public function test_chunk_size_is_clamped_to_its_bounds(): void
    {
        $this->route(home_url('/'), str_repeat('x', 300000));

        $tiny = $this->tool()->handle(['chunk_size' => 1]);
        $huge = $this->tool()->handle(['chunk_size' => 10000000]);

        $this->assertSame(Get_Rendered_Html::MIN_CHUNK, $tiny['chunk_size']);
        $this->assertSame(Get_Rendered_Html::MAX_CHUNK, $huge['chunk_size']);
        $this->assertSame(Get_Rendered_Html::MAX_CHUNK, strlen($huge['content']));
    }

    public function test_out_of_range_chunk_is_rejected_with_the_chunk_count(): void
    {
        $this->route(home_url('/'), 'short');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('chunk_count 1');
        $this->tool()->handle(['chunk' => 5]);
    }

    public function test_non_200_status_is_returned_not_thrown(): void
    {
        $this->route(home_url('/missing/'), 'Not found page', 404);

        $out = $this->tool()->handle(['url' => '/missing/']);

        $this->assertSame(404, $out['status_code']);
        $this->assertSame('Not found page', $out['content']);
    }

    // ------------------------------------------------------------------
    // Size cap.
    // ------------------------------------------------------------------

    public function test_body_over_the_size_cap_is_truncated_and_flagged(): void
    {
        $this->route(home_url('/'), str_repeat('z', Get_Rendered_Html::MAX_BYTES + 5000));

        $out = $this->tool()->handle([]);

        $this->assertTrue($out['truncated']);
        $this->assertSame(Get_Rendered_Html::MAX_BYTES, $out['total_bytes']);
        $this->assertSame(Get_Rendered_Html::MAX_BYTES, $this->request_args[0]['limit_response_size']);
    }

    // ------------------------------------------------------------------
    // Filters.
    // ------------------------------------------------------------------

    public function test_strip_scripts_removes_script_style_and_noscript_blocks(): void
    {
        $this->route(
            home_url('/'),
            '<html><head><style>.a{color:red}</style><script src="x.js"></script></head>'
            . '<body><p>Keep</p><SCRIPT type="text/javascript">alert(1)</SCRIPT><noscript>nojs</noscript></body></html>'
        );

        $out = $this->tool()->handle(['strip_scripts' => true]);

        $this->assertSame('<html><head></head><body><p>Keep</p></body></html>', $out['content']);
    }

    public function test_text_only_returns_visible_text_without_markup_or_script_bodies(): void
    {
        $this->route(
            home_url('/'),
            '<html><head><title>T</title><style>p{}</style><script>var secret=1;</script></head>'
            . '<body><h1>Welcome &amp; hello</h1><p>First   para.</p><div>Second<br>line</div></body></html>'
        );

        $out = $this->tool()->handle(['text_only' => true]);

        $this->assertStringNotContainsString('<', $out['content']);
        $this->assertStringNotContainsString('secret', $out['content']);
        $this->assertStringNotContainsString('p{}', $out['content']);
        $this->assertStringContainsString('Welcome & hello', $out['content']);
        $this->assertStringContainsString('First para.', $out['content']);
        $this->assertMatchesRegularExpression('/Second\s*\n\s*line/', $out['content']);
        $this->assertSame(strlen($out['content']), $out['total_bytes']);
    }

    // ------------------------------------------------------------------
    // Refusals: none of these may dispatch a request.
    // ------------------------------------------------------------------

    public function test_refuses_an_off_site_host(): void
    {
        $this->assert_refused(['url' => 'https://evil.test/'], 'this site');
    }

    public function test_refuses_a_look_alike_subdomain_of_the_site(): void
    {
        $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
        $this->assert_refused(['url' => 'http://' . $host . '.evil.test/'], 'this site');
    }

    public function test_refuses_a_protocol_relative_off_site_url(): void
    {
        $this->assert_refused(['url' => '//evil.test/x'], 'this site');
    }

    public function test_refuses_ip_literals(): void
    {
        $this->assert_refused(['url' => 'http://127.0.0.1/'], 'IP');
        $this->assert_refused(['url' => 'http://169.254.169.254/latest/meta-data/'], 'IP');
        $this->assert_refused(['url' => 'http://[::1]/'], 'IP');
    }

    public function test_refuses_non_http_schemes(): void
    {
        $this->assert_refused(['url' => 'file:///etc/passwd'], 'http');
        $this->assert_refused(['url' => 'gopher://' . wp_parse_url(home_url(), PHP_URL_HOST) . '/'], 'http');
    }

    public function test_refuses_credentials_and_a_foreign_port_on_the_site_host(): void
    {
        $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
        $this->assert_refused(['url' => 'http://user:pass@' . $host . '/'], 'credentials');
        $this->assert_refused(['url' => 'http://' . $host . ':6379/'], 'port');
    }

    public function test_refuses_a_bare_relative_path_without_a_leading_slash(): void
    {
        $this->assert_refused(['url' => 'evil.test/x'], 'path');
    }

    public function test_refuses_a_redirect_that_leaves_the_site(): void
    {
        $this->route(home_url('/go/'), '', 302, ['location' => 'http://169.254.169.254/latest/meta-data/']);

        try {
            $this->tool()->handle(['url' => '/go/']);
            $this->fail('An off-site redirect must be refused.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('redirect', $e->getMessage());
        }
        $this->assertSame([home_url('/go/')], $this->requested, 'The off-site Location must never be requested.');
    }

    public function test_follows_a_same_site_redirect_and_reports_the_final_url(): void
    {
        $this->route(home_url('/old/'), '', 301, ['location' => '/new/']);
        $this->route(home_url('/new/'), 'new page');

        $out = $this->tool()->handle(['url' => '/old/']);

        $this->assertSame(home_url('/new/'), $out['final_url']);
        $this->assertSame('new page', $out['content']);
        $this->assertSame(200, $out['status_code']);
    }

    public function test_redirect_loops_are_bounded(): void
    {
        $this->route(home_url('/a/'), '', 302, ['location' => home_url('/b/')]);
        $this->route(home_url('/b/'), '', 302, ['location' => home_url('/a/')]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('redirects');
        $this->tool()->handle(['url' => '/a/']);
    }

    // ------------------------------------------------------------------
    // Non-public posts: refused, never fetched with elevated auth.
    // ------------------------------------------------------------------

    public function test_refuses_draft_private_and_password_protected_posts(): void
    {
        $draft     = self::factory()->post->create(['post_status' => 'draft']);
        $private   = self::factory()->post->create(['post_status' => 'private']);
        $protected = self::factory()->post->create(['post_status' => 'publish', 'post_password' => 'pw']);

        $this->assert_refused(['post_id' => $draft], 'not publicly viewable');
        $this->assert_refused(['post_id' => $private], 'not publicly viewable');
        $this->assert_refused(['post_id' => $protected], 'password');
    }

    public function test_refuses_a_missing_post(): void
    {
        $this->assert_refused(['post_id' => 999999], 'not found');
    }

    public function test_the_request_carries_no_cookies_or_authorization(): void
    {
        $this->route(home_url('/'), 'ok');

        $this->tool()->handle([]);

        $args = $this->request_args[0];
        $this->assertEmpty($args['cookies']);
        $this->assertArrayNotHasKey('authorization', array_change_key_case((array) $args['headers']));
    }

    // ------------------------------------------------------------------
    // Registration.
    // ------------------------------------------------------------------

    public function test_is_registered_as_a_free_read_ability_in_the_wpmcp_category(): void
    {
        $abilities = wp_get_abilities();
        $this->assertArrayHasKey(self::NAME, $abilities);
        $this->assertSame('wpmcp', $abilities[ self::NAME ]->get_category());

        $manifest = require dirname(__DIR__, 2) . '/support/ability-manifest.php';
        $this->assertSame('free', $manifest['abilities'][ self::NAME ] ?? null);
    }

    public function test_denies_a_visitor_and_allows_a_contributor(): void
    {
        $ability = wp_get_abilities()[ self::NAME ];

        wp_set_current_user(self::factory()->user->create(['role' => 'contributor']));
        $this->assertTrue($ability->check_permissions());

        wp_set_current_user(0);
        $this->assertFalse($ability->check_permissions());
    }
}
