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

    /** @var array<int,int> http_api_curl callback count seen by each dispatched request. */
    private array $curl_filters = [];

    /** @var array<string,array{status:int,body:string,headers?:array}> url => canned response. */
    private array $routes = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->requested    = [];
        $this->request_args = [];
        $this->routes       = [];
        $this->curl_filters = [];
        add_filter('pre_http_request', [$this, 'serve'], 10, 3);
        // The tool is gated at edit_posts, and a post_id target must also
        // pass read_post for the caller; an administrator can read anything.
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
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
        $this->curl_filters[] = self::curl_filter_count();
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

    private static function curl_filter_count(): int
    {
        global $wp_filter;
        if (! isset($wp_filter['http_api_curl'])) {
            return 0;
        }
        $n = 0;
        foreach ($wp_filter['http_api_curl']->callbacks as $callbacks) {
            $n += count($callbacks);
        }
        return $n;
    }

    /**
     * The DNS resolver is injected so no test ever performs a real lookup
     * (network-dependent tests were a source of flakes, #323). By default it
     * resolves nothing, which means no pin is applied.
     */
    private function tool(?callable $resolver = null): Get_Rendered_Html
    {
        return new Get_Rendered_Html($resolver ?? static fn(string $host): array => []);
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
    // Offset continuation and content hash (#353).
    // ------------------------------------------------------------------

    public function test_reports_a_sha256_content_hash_that_is_stable_across_chunks(): void
    {
        $body = str_repeat('a', 1000) . str_repeat('b', 1000) . str_repeat('c', 500);
        $this->route(home_url('/'), $body);

        $first  = $this->tool()->handle(['chunk_size' => 1000]);
        $second = $this->tool()->handle(['chunk_size' => 1000, 'offset' => $first['next_offset']]);

        $this->assertSame(hash('sha256', $body), $first['content_hash']);
        $this->assertSame($first['content_hash'], $second['content_hash']);
    }

    public function test_content_hash_changes_when_the_page_changes_between_reads(): void
    {
        $this->route(home_url('/'), str_repeat('a', 2500));
        $before = $this->tool()->handle(['chunk_size' => 1000]);

        $this->route(home_url('/'), str_repeat('a', 2400) . 'edited');
        $after = $this->tool()->handle(['chunk_size' => 1000, 'offset' => $before['next_offset']]);

        $this->assertNotSame($before['content_hash'], $after['content_hash']);
    }

    public function test_content_hash_covers_the_reduced_document_that_is_served(): void
    {
        $raw = '<p>Keep</p><script>x()</script>';
        $this->route(home_url('/'), $raw);

        $out = $this->tool()->handle(['strip_scripts' => true]);

        $this->assertSame('<p>Keep</p>', $out['content']);
        $this->assertSame(hash('sha256', '<p>Keep</p>'), $out['content_hash']);
    }

    public function test_offset_reads_follow_next_offset_and_reassemble_exactly(): void
    {
        $body = str_repeat('0123456789', 250); // 2500 bytes.
        $this->route(home_url('/'), $body);

        $whole  = '';
        $offset = 0;
        $calls  = 0;
        do {
            $out = $this->tool()->handle(['offset' => $offset, 'chunk_size' => 1000]);
            $this->assertSame($offset, $out['offset']);
            $whole  .= $out['content'];
            $offset  = $out['next_offset'];
            $calls++;
        } while (null !== $offset && $calls < 10);

        $this->assertSame(3, $calls);
        $this->assertSame($body, $whole);
        $this->assertNull($out['next_offset'], 'The last read has no continuation.');
    }

    public function test_offset_reads_never_split_a_multibyte_character(): void
    {
        $body = str_repeat('é', 1500); // 3000 bytes, two bytes per character.
        $this->route(home_url('/'), $body);

        $whole  = '';
        $offset = 0;
        $calls  = 0;
        do {
            $out = $this->tool()->handle(['offset' => $offset, 'chunk_size' => 1001]);
            $this->assertTrue(mb_check_encoding($out['content'], 'UTF-8'), "Read at $offset must be valid UTF-8.");
            $whole  .= $out['content'];
            $offset  = $out['next_offset'];
            $calls++;
        } while (null !== $offset && $calls < 10);

        $this->assertSame($body, $whole);
    }

    public function test_chunk_reads_also_report_next_offset(): void
    {
        $this->route(home_url('/'), str_repeat('x', 2500));

        $first = $this->tool()->handle(['chunk_size' => 1000]);
        $last  = $this->tool()->handle(['chunk_size' => 1000, 'chunk' => 2]);

        $this->assertSame(0, $first['offset']);
        $this->assertSame(1000, $first['next_offset']);
        $this->assertSame(2000, $last['offset']);
        $this->assertNull($last['next_offset']);
    }

    public function test_offset_past_the_end_or_negative_is_rejected(): void
    {
        $this->route(home_url('/'), 'short');

        foreach ([6, 99999, -1] as $offset) {
            try {
                $this->tool()->handle(['offset' => $offset]);
                $this->fail("offset $offset must be rejected.");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('total_bytes 5', $e->getMessage());
            }
        }
    }

    public function test_chunk_and_offset_together_are_rejected_before_any_request(): void
    {
        $this->assert_refused(['chunk' => 1, 'offset' => 10], 'not both');
    }

    // ------------------------------------------------------------------
    // DNS pin: every hop connects to the address resolved for this site.
    // ------------------------------------------------------------------

    public function test_pins_every_hop_to_the_resolved_site_address_and_unpins_after(): void
    {
        if (! function_exists('curl_init')) {
            $this->markTestSkipped('The pin needs the curl extension.');
        }
        $before = self::curl_filter_count();
        $home   = (string) wp_parse_url(home_url(), PHP_URL_HOST);
        $asked  = [];
        $tool   = $this->tool(static function (string $host) use (&$asked): array {
            $asked[] = $host;
            return ['203.0.113.10'];
        });
        $this->route(home_url('/old/'), '', 301, ['location' => '/new/']);
        $this->route(home_url('/new/'), 'new page');

        $out = $tool->handle(['url' => '/old/']);

        $this->assertSame('new page', $out['content']);
        $this->assertSame([$home], $asked, 'The site host is resolved once and reused for every hop.');
        $this->assertSame('203.0.113.10', $tool->get_last_pinned_ip());
        $this->assertSame([$before + 1, $before + 1], $this->curl_filters, 'Each hop runs with the pin in place.');
        $this->assertSame($before, self::curl_filter_count(), 'The pin is removed after the fetch.');
    }

    public function test_the_pin_is_removed_even_when_the_fetch_fails(): void
    {
        if (! function_exists('curl_init')) {
            $this->markTestSkipped('The pin needs the curl extension.');
        }
        $before = self::curl_filter_count();
        $tool   = $this->tool(static fn(string $host): array => ['203.0.113.10']);

        try {
            $tool->handle(['url' => '/no-stub/']);
            $this->fail('A failed fetch must throw.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Fetch failed', $e->getMessage());
        }

        $this->assertSame($before, self::curl_filter_count());
    }

    public function test_no_pin_when_the_site_host_does_not_resolve(): void
    {
        $before = self::curl_filter_count();
        $this->route(home_url('/'), 'ok');
        $tool = $this->tool();

        $out = $tool->handle([]);

        $this->assertSame('ok', $out['content']);
        $this->assertNull($tool->get_last_pinned_ip());
        $this->assertSame([$before], $this->curl_filters);
    }

    // ------------------------------------------------------------------
    // Caller must be able to read the post (#353).
    // ------------------------------------------------------------------

    public function test_refuses_a_post_the_caller_cannot_read(): void
    {
        $admin   = get_current_user_id();
        $private = self::factory()->post->create(['post_status' => 'private', 'post_author' => $admin]);
        $draft   = self::factory()->post->create(['post_status' => 'draft', 'post_author' => $admin]);
        wp_set_current_user(self::factory()->user->create(['role' => 'contributor']));

        $this->assert_refused(['post_id' => $private], 'cannot read');
        $this->assert_refused(['post_id' => $draft], 'cannot read');
    }

    public function test_a_readable_draft_is_refused_with_a_credential_free_explanation(): void
    {
        $draft = self::factory()->post->create(['post_status' => 'draft']);

        $this->assert_refused(['post_id' => $draft], 'logged-out');
    }

    public function test_no_cookies_or_auth_are_forwarded_for_a_logged_in_caller(): void
    {
        $post_id = self::factory()->post->create(['post_status' => 'publish']);
        $this->route(get_permalink($post_id), 'ok');
        $_COOKIE[ LOGGED_IN_COOKIE ] = 'caller-session';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic Y2FsbGVyOnNlY3JldA==';

        try {
            $this->tool()->handle(['post_id' => $post_id]);
        } finally {
            unset($_COOKIE[ LOGGED_IN_COOKIE ], $_SERVER['HTTP_AUTHORIZATION']);
        }

        $args    = $this->request_args[0];
        $headers = array_change_key_case((array) $args['headers']);
        $this->assertEmpty($args['cookies']);
        $this->assertArrayNotHasKey('cookie', $headers);
        $this->assertArrayNotHasKey('authorization', $headers);
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

        $props = $abilities[ self::NAME ]->get_input_schema()['properties'] ?? [];
        $this->assertSame('integer', $props['offset']['type'] ?? null, 'offset must be advertised for continuation reads.');
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
