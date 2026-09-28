<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Block_Suites_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * Issue #364: the WordPress.org Pattern Directory as a remote source on the
 * block-suites list-patterns and import-pattern ops.
 *
 * The suites' own cloud libraries are not used: each is an undocumented
 * vendor endpoint, and one needs a key shipped inside its plugin. The
 * Pattern Directory is public, documented and the service core itself reads.
 *
 * There is no network here: every request is answered by a pre_http_request
 * stub that also records what the site tried to send.
 */
class BlockSuitePatternDirectoryTest extends \WP_UnitTestCase
{
    private const API        = 'https://api.wordpress.org/patterns/1.0/';
    private const CATEGORIES = 'https://wordpress.org/patterns/wp-json/wp/v2/pattern-categories';
    private const DIR_IMAGE  = 'https://pd.w.org/2024/01/hero.jpg';
    private const OFFLIST    = 'https://images.rawpixel.com/image_1300/abc.jpg';

    /** @var callable */
    private $presence;

    /** @var array<int,array{url:string,args:array}> */
    private array $requests = [];

    /** @var array<string,mixed> url prefix => canned answer */
    private array $answers = [];

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        $this->presence = static fn ($default, string $suite): bool => 'kadence-blocks' === $suite;
        add_filter('wpmcp_block_suite_active', $this->presence, 10, 2);
        add_filter('pre_http_request', [ $this, 'serve' ], 10, 3);
    }

    protected function tearDown(): void
    {
        remove_filter('wpmcp_block_suite_active', $this->presence, 10);
        remove_filter('pre_http_request', [ $this, 'serve' ], 10);
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    public function serve($preempt, $parsed_args, $url)
    {
        $url              = (string) $url;
        $this->requests[] = [ 'url' => $url, 'args' => (array) $parsed_args ];

        if (str_starts_with($url, 'https://pd.w.org/') || str_starts_with($url, 'https://images.rawpixel.com/')) {
            $source = DIR_TESTDATA . '/images/canola.jpg';
            file_put_contents($parsed_args['filename'], (string) file_get_contents($source));
            return [
                'headers'  => [ 'content-type' => 'image/jpeg', 'content-length' => (string) filesize($source) ],
                'body'     => '',
                'response' => [ 'code' => 200, 'message' => 'OK' ],
                'cookies'  => [],
                'filename' => $parsed_args['filename'],
            ];
        }

        foreach ($this->answers as $prefix => $answer) {
            if (str_starts_with($url, $prefix)) {
                return $answer;
            }
        }
        return new \WP_Error('http_request_failed', 'unexpected request to ' . $url);
    }

    private function answer(string $prefix, $body, int $code = 200, array $headers = []): void
    {
        $this->answers[ $prefix ] = [
            'headers'  => $headers + [ 'content-type' => 'application/json' ],
            'body'     => is_string($body) ? $body : (string) wp_json_encode($body),
            'response' => [ 'code' => $code, 'message' => 'OK' ],
            'cookies'  => [],
            'filename' => null,
        ];
    }

    private static function pattern(int $id, string $title, string $content, array $categories = [ 'header' ]): array
    {
        return [
            'id'              => $id,
            'title'           => [ 'rendered' => $title ],
            'content'         => [ 'rendered' => '<p>rendered</p>' ],
            'meta'            => [ 'wpop_description' => $title . ' description', 'wpop_viewport_width' => 1200 ],
            'category_slugs'  => $categories,
            'keyword_slugs'   => [],
            'pattern_content' => $content,
        ];
    }

    private static function hero_content(): string
    {
        return '<!-- wp:cover {"url":"' . self::DIR_IMAGE . '","id":12} --><div class="wp-block-cover"><img class="wp-block-cover__image-background wp-image-12" src="' . self::DIR_IMAGE . '"/><div class="wp-block-cover__inner-container">'
            . '<!-- wp:heading --><h2 class="wp-block-heading">Hello</h2><!-- /wp:heading -->'
            . '</div></div><!-- /wp:cover -->'
            . '<!-- wp:image --><figure class="wp-block-image"><img src="' . self::OFFLIST . '" alt=""/></figure><!-- /wp:image -->';
    }

    private function read(string $op, array $args = []): array
    {
        return ( new Block_Suites_Integration() )->handle_read([ 'operation' => $op, 'args' => $args ]);
    }

    private function write(string $op, array $args): array
    {
        return ( new Block_Suites_Integration() )->handle_write([ 'operation' => $op, 'args' => $args ]);
    }

    /** @return array{0:int,1:string} */
    private function post(string $content = ''): array
    {
        $id = self::factory()->post->create([ 'post_type' => 'page', 'post_content' => $content ]);
        return [ $id, hash('sha256', (string) get_post($id)->post_content) ];
    }

    private function content(int $id): string
    {
        clean_post_cache($id);
        return (string) get_post($id)->post_content;
    }

    /** @return array<string,string> */
    private static function query(string $url): array
    {
        parse_str((string) wp_parse_url($url, PHP_URL_QUERY), $query);
        return $query;
    }

    // ------------------------------------------------------------ browse

    public function test_list_patterns_browses_the_directory_through_the_guarded_transport_and_caches_it(): void
    {
        $this->answer(self::API, [
            self::pattern(101, 'Big hero', self::hero_content()),
            self::pattern(102, 'Plain text', '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->', [ 'text' ]),
        ], 200, [ 'x-wp-total' => '42' ]);

        $out = $this->read('list-patterns', [ 'source' => 'directory', 'search' => 'hero', 'limit' => 2, 'offset' => 4 ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $result = $out['result'];
        $this->assertSame('directory', $result['source']);
        $this->assertSame(42, $result['total']);
        $this->assertSame([ 'directory:101', 'directory:102' ], array_column($result['patterns'], 'name'));

        $hero = $result['patterns'][0];
        $this->assertSame('Big hero', $hero['title']);
        $this->assertSame([ 'header' ], $hero['categories']);
        $this->assertSame('Big hero description', $hero['description']);
        $this->assertSame([], $hero['suites']);
        $this->assertSame(2, $hero['remote_images']);
        $this->assertArrayNotHasKey('content', $hero);

        $this->assertCount(1, $this->requests);
        $request = $this->requests[0];
        $this->assertStringStartsWith(self::API . '?', $request['url']);
        $query = self::query($request['url']);
        $this->assertSame('hero', $query['search']);
        $this->assertSame('2', $query['per_page']);
        $this->assertSame('4', $query['offset']);
        $this->assertSame(get_user_locale(), $query['locale']);
        $this->assertSame(0, $request['args']['redirection'], 'redirects are never followed');
        $this->assertTrue($request['args']['reject_unsafe_urls'], 'the request goes through wp_safe_remote_get');
        $this->assertSame('WPMCP-Pattern-Directory/1.0', $request['args']['user-agent'], 'the pinned user agent does not carry the site address');

        $again = $this->read('list-patterns', [ 'source' => 'directory', 'search' => 'hero', 'limit' => 2, 'offset' => 4 ]);
        $this->assertSame($result, $again['result']);
        $this->assertCount(1, $this->requests, 'a repeat browse is served from the cache');
    }

    public function test_directory_page_size_is_capped_at_the_api_maximum(): void
    {
        $this->answer(self::API, [], 200, [ 'x-wp-total' => '0' ]);
        $out = $this->read('list-patterns', [ 'source' => 'directory', 'limit' => 200 ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $this->assertSame('100', self::query($this->requests[0]['url'])['per_page']);
    }

    public function test_a_category_slug_is_resolved_to_the_directory_term_id(): void
    {
        $this->answer(self::CATEGORIES, [ [ 'id' => 5, 'slug' => 'header' ], [ 'id' => 37, 'slug' => 'footer' ] ]);
        $this->answer(self::API, [ self::pattern(101, 'Big hero', self::hero_content()) ], 200, [ 'x-wp-total' => '1' ]);

        $out = $this->read('list-patterns', [ 'source' => 'directory', 'category' => 'footer' ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $api = array_values(array_filter($this->requests, static fn ($r) => str_starts_with($r['url'], self::API)));
        $this->assertCount(1, $api);
        $this->assertSame('37', self::query($api[0]['url'])['pattern-categories']);

        $unknown = $this->read('list-patterns', [ 'source' => 'directory', 'category' => 'nope' ]);
        $this->assertSame('unknown_category', $unknown['error']['code'] ?? null);
        $this->assertContains('footer', $unknown['error']['data']['categories'] ?? []);
        $this->assertCount(2, $this->requests, 'the category list is cached and an unknown slug never reaches the pattern API');
    }

    public function test_a_suite_filter_on_the_directory_is_refused_before_any_request(): void
    {
        $out = $this->read('list-patterns', [ 'source' => 'directory', 'suite' => 'kadence-blocks' ]);
        $this->assertSame('invalid_args', $out['error']['code'] ?? null);
        $this->assertSame([], $this->requests);
    }

    public function test_an_unreachable_or_oversized_directory_answer_is_an_error_and_is_not_cached(): void
    {
        $this->answer(self::API, [ 'code' => 'oops' ], 500);
        $out = $this->read('list-patterns', [ 'source' => 'directory' ]);
        $this->assertSame('pattern_directory_unavailable', $out['error']['code'] ?? null);

        add_filter('wpmcp_pattern_directory_max_bytes', $cap = static fn () => 64);
        $this->answer(self::API, [ self::pattern(101, 'Big hero', self::hero_content()) ], 200, [ 'x-wp-total' => '1' ]);
        $out = $this->read('list-patterns', [ 'source' => 'directory' ]);
        remove_filter('wpmcp_pattern_directory_max_bytes', $cap);
        $this->assertSame('pattern_directory_unavailable', $out['error']['code'] ?? null);

        $out = $this->read('list-patterns', [ 'source' => 'directory' ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $this->assertCount(3, $this->requests, 'failures are not cached');
    }

    public function test_the_registry_stays_the_default_source_and_sends_nothing(): void
    {
        $out = $this->read('list-patterns', [ 'search' => 'no-such-pattern-anywhere' ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $this->assertSame(0, $out['result']['total']);
        $this->assertSame([], $this->requests);
    }

    // ------------------------------------------------------------ import

    public function test_import_of_a_directory_pattern_is_one_undoable_write_with_images_through_the_guard(): void
    {
        $this->answer(self::API, [ self::pattern(101, 'Big hero', self::hero_content()) ], 200, [ 'x-wp-total' => '1' ]);
        [ $id, ] = $this->post('');
        wp_update_post([ 'ID' => $id, 'post_content' => wp_slash('<!-- wp:paragraph --><p>a</p><!-- /wp:paragraph -->') ]);
        $original = $this->content($id);
        $before   = Snapshot_Store::row_count();

        $out = $this->write('import-pattern', [ 'name' => 'directory:101', 'id' => $id, 'expected_hash' => hash('sha256', $original), 'path' => [ 1 ] ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $this->assertCount(1, $out['operation_ids']);
        $this->assertSame($before + 1, Snapshot_Store::row_count(), 'one snapshot for the whole import');
        $this->assertSame(2, $out['result']['inserted']);

        $api = array_values(array_filter($this->requests, static fn ($r) => str_starts_with($r['url'], self::API)));
        $this->assertCount(1, $api);
        $this->assertSame('101', self::query($api[0]['url'])['include']);

        $images = array_column($out['result']['images'], null, 'url');
        $this->assertSame('sideloaded', $images[ self::DIR_IMAGE ]['status'], 'the directory image host is allowed for directory imports');
        $media = (int) $images[ self::DIR_IMAGE ]['media_id'];
        $this->assertSame('attachment', get_post_type($media));
        $this->assertSame('kept', $images[ self::OFFLIST ]['status']);
        $this->assertStringContainsString('wpmcp_pattern_directory_image_hosts', $images[ self::OFFLIST ]['reason']);
        $this->assertNotContains(self::OFFLIST, array_column($this->requests, 'url'), 'a host off the list is never fetched');

        $content = $this->content($id);
        $local   = (string) wp_get_attachment_url($media);
        $this->assertStringNotContainsString(self::DIR_IMAGE, $content);
        $this->assertStringContainsString($local, $content);
        $this->assertStringContainsString(self::OFFLIST, $content);
        $blocks = array_values(array_filter(parse_blocks($content), static fn ($b) => null !== $b['blockName']));
        $this->assertSame('core/cover', $blocks[1]['blockName']);
        $this->assertSame($media, $blocks[1]['attrs']['id'], 'the attachment id follows the sideloaded url');

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_ids'][0]));
        $this->assertSame($original, $this->content($id), 'rollback restores the exact bytes');
    }

    public function test_a_directory_pattern_is_fetched_once_and_then_served_from_the_cache(): void
    {
        $this->answer(self::API, [ self::pattern(102, 'Plain', '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->') ], 200, [ 'x-wp-total' => '1' ]);
        [ $id, $hash ] = $this->post('');
        $out = $this->write('import-pattern', [ 'name' => 'directory:102', 'id' => $id, 'expected_hash' => $hash, 'path' => [ 0 ] ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $out = $this->write('import-pattern', [ 'name' => 'directory:102', 'id' => $id, 'expected_hash' => $out['result']['content_hash'], 'path' => [ 1 ] ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $this->assertCount(1, $this->requests);
    }

    public function test_import_refuses_a_stale_hash_before_any_request_and_an_unknown_id_before_any_snapshot(): void
    {
        [ $id, $hash ] = $this->post('');
        $before        = Snapshot_Store::row_count();

        $out = $this->write('import-pattern', [ 'name' => 'directory:101', 'id' => $id, 'expected_hash' => str_repeat('0', 64), 'path' => [ 0 ] ]);
        $this->assertSame('invalid_block_edit', $out['error']['code'] ?? null);
        $this->assertSame([], $this->requests, 'a stale hash costs no request');

        $out = $this->write('import-pattern', [ 'name' => 'directory:abc', 'id' => $id, 'expected_hash' => $hash, 'path' => [ 0 ] ]);
        $this->assertSame('unknown_pattern', $out['error']['code'] ?? null);
        $this->assertSame([], $this->requests, 'a malformed directory id costs no request');

        $this->answer(self::API, [], 200, [ 'x-wp-total' => '0' ]);
        $out = $this->write('import-pattern', [ 'name' => 'directory:999', 'id' => $id, 'expected_hash' => $hash, 'path' => [ 0 ] ]);
        $this->assertSame('unknown_pattern', $out['error']['code'] ?? null);

        $this->assertSame($before, Snapshot_Store::row_count());
        $this->assertSame('', $this->content($id));
    }
}
