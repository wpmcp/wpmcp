<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Block_Suites_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * Issue #364: Spectra's remote pattern library as a source on the
 * block-suites list-patterns and import-pattern ops.
 *
 * Spectra's editor reads its library, without any key, from the public
 * WordPress REST API of its vendor's template site (the astra-blocks post type
 * and its taxonomies). Only free Gutenberg block patterns built on the
 * uagb/* blocks are offered: items tagged premium and Spectra 3 items
 * (spectra/* blocks) are filtered out when listing and refused on import.
 *
 * There is no network here: every request is answered by a pre_http_request
 * stub that also records what the site tried to send.
 */
class BlockSuiteSpectraLibraryTest extends \WP_UnitTestCase
{
    private const BASE     = 'https://websitedemos.net/wp-json/wp/v2/';
    private const ITEMS    = self::BASE . 'astra-blocks';
    private const IMAGE    = 'https://websitedemos.net/wp-content/uploads/2024/03/hero.jpg';
    private const OFFLIST  = 'https://images.rawpixel.com/image_1300/abc.jpg';

    private const GUTENBERG = 1623;
    private const ELEMENTOR = 1622;
    private const BLOCK     = 3377;
    private const PAGE      = 3379;
    private const FREE      = 3694;
    private const PREMIUM   = 3695;
    private const V2        = 3731;
    private const V3        = 3732;

    /** @var callable */
    private $presence;

    /** @var callable */
    private $defaults;

    private bool $spectra_active = true;

    /** @var array<int,array{url:string,args:array}> */
    private array $requests = [];

    /** @var array<string,mixed> exact endpoint (no query) => canned answer */
    private array $answers = [];

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        // Kadence stays active so the block-suites integration itself is
        // available when a test turns Spectra off.
        $this->presence = fn ($default, string $suite): bool => 'kadence-blocks' === $suite || ('spectra' === $suite && $this->spectra_active);
        add_filter('wpmcp_block_suite_active', $this->presence, 10, 2);
        $this->defaults = static function (array $defaults, string $suite): array {
            if ('spectra' !== $suite) {
                return $defaults;
            }
            return $defaults + [
                'uagb/container' => [
                    'title'      => 'Container',
                    'attributes' => [ 'block_id' => '', 'directionDesktop' => 'column' ],
                ],
            ];
        };
        add_filter('wpmcp_block_suite_default_attributes', $this->defaults, 10, 2);
        add_filter('pre_http_request', [ $this, 'serve' ], 10, 3);
        $this->terms();
    }

    protected function tearDown(): void
    {
        remove_filter('wpmcp_block_suite_active', $this->presence, 10);
        remove_filter('wpmcp_block_suite_default_attributes', $this->defaults, 10);
        remove_filter('pre_http_request', [ $this, 'serve' ], 10);
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    public function serve($preempt, $parsed_args, $url)
    {
        $url              = (string) $url;
        $this->requests[] = [ 'url' => $url, 'args' => (array) $parsed_args ];

        if (str_starts_with($url, 'https://websitedemos.net/wp-content/') || str_starts_with($url, 'https://images.rawpixel.com/')) {
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

        $endpoint = strtok($url, '?');
        return $this->answers[ $endpoint ] ?? new \WP_Error('http_request_failed', 'unexpected request to ' . $url);
    }

    private function answer(string $endpoint, $body, int $code = 200, array $headers = []): void
    {
        $this->answers[ $endpoint ] = [
            'headers'  => $headers + [ 'content-type' => 'application/json' ],
            'body'     => is_string($body) ? $body : (string) wp_json_encode($body),
            'response' => [ 'code' => $code, 'message' => 'OK' ],
            'cookies'  => [],
            'filename' => null,
        ];
    }

    /** The library's own taxonomies, looked up by slug. */
    private function terms(): void
    {
        $this->answer(self::BASE . 'astra-blocks-page-builder', [ [ 'id' => self::ELEMENTOR, 'slug' => 'elementor' ], [ 'id' => self::GUTENBERG, 'slug' => 'gutenberg' ] ]);
        $this->answer(self::BASE . 'block-type', [ [ 'id' => self::BLOCK, 'slug' => 'block' ], [ 'id' => self::PAGE, 'slug' => 'page' ], [ 'id' => 3378, 'slug' => 'wireframe' ] ]);
        $this->answer(self::BASE . 'block-access-type', [ [ 'id' => self::FREE, 'slug' => 'free' ], [ 'id' => self::PREMIUM, 'slug' => 'premium' ] ]);
        $this->answer(self::BASE . 'spectra-blocks-ver', [ [ 'id' => self::V2, 'slug' => 'v2' ], [ 'id' => self::V3, 'slug' => 'v3' ] ]);
        $this->answer(self::BASE . 'blocks-category', [ [ 'id' => 831, 'slug' => 'hero' ], [ 'id' => 1425, 'slug' => 'portfolio' ] ]);
    }

    private static function summary(int $id, string $title, array $categories = [ 831 ]): array
    {
        return [
            'id'              => $id,
            'link'            => 'https://websitedemos.net/astra-blocks/item-' . $id . '/',
            'title'           => [ 'rendered' => $title ],
            'blocks-category' => $categories,
        ];
    }

    private static function item(int $id, string $content, array $terms = []): array
    {
        return $terms + [
            'id'                        => $id,
            'title'                     => [ 'rendered' => 'Item ' . $id ],
            'original_content'          => $content,
            'astra-blocks-page-builder' => [ self::GUTENBERG ],
            'block-type'                => [ self::BLOCK ],
            'block-access-type'         => [],
            'spectra-blocks-ver'        => [],
        ];
    }

    private static function hero_content(): string
    {
        return '<!-- wp:uagb/container {"block_id":"28bd0455","backgroundImageDesktop":{"url":"' . self::IMAGE . '","id":261}} -->'
            . '<div class="wp-block-uagb-container uagb-block-28bd0455"><img src="' . self::IMAGE . '" alt=""/><img src="' . self::OFFLIST . '" alt=""/></div>'
            . '<!-- /wp:uagb/container -->';
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

    /** @return array<int,array{url:string,args:array}> */
    private function requests_to(string $endpoint): array
    {
        return array_values(array_filter($this->requests, static fn ($r) => strtok($r['url'], '?') === $endpoint));
    }

    // ------------------------------------------------------------ browse

    public function test_list_patterns_browses_free_spectra_block_patterns_through_the_guarded_transport_and_caches_it(): void
    {
        $this->answer(self::ITEMS, [
            self::summary(83549, 'Portfolio 10', [ 1425 ]),
            self::summary(83247, 'Hero &#8211; 16'),
        ], 200, [ 'x-wp-total' => '187' ]);

        $out = $this->read('list-patterns', [ 'source' => 'spectra', 'search' => 'hero', 'limit' => 2, 'offset' => 4 ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $result = $out['result'];
        $this->assertSame('spectra', $result['source']);
        $this->assertSame(187, $result['total']);
        $this->assertSame([ 'spectra:83549', 'spectra:83247' ], array_column($result['patterns'], 'name'));
        $this->assertSame('Hero - 16', str_replace("\u{2013}", '-', $result['patterns'][1]['title']));
        $this->assertSame([ 'portfolio' ], $result['patterns'][0]['categories'], 'category ids come back as slugs');
        $this->assertSame([ 'spectra' ], $result['patterns'][0]['suites']);
        $this->assertSame('https://websitedemos.net/astra-blocks/item-83549/', $result['patterns'][0]['preview']);

        $items = $this->requests_to(self::ITEMS);
        $this->assertCount(1, $items);
        $query = self::query($items[0]['url']);
        $this->assertSame('hero', $query['search']);
        $this->assertSame('2', $query['per_page']);
        $this->assertSame('4', $query['offset']);
        $this->assertSame((string) self::GUTENBERG, $query['astra-blocks-page-builder'], 'Gutenberg patterns only');
        $this->assertSame((string) self::BLOCK, $query['block-type'], 'single block patterns only');
        $this->assertSame((string) self::PREMIUM, $query['block-access-type_exclude'], 'premium items are never listed');
        $this->assertSame((string) self::V3, $query['spectra-blocks-ver_exclude'], 'Spectra 3 items are never listed');
        $this->assertStringNotContainsString('original_content', $query['_fields'], 'a browse does not download pattern bodies');

        foreach ($this->requests as $request) {
            $this->assertStringStartsWith(self::BASE, $request['url']);
            $this->assertSame(0, $request['args']['redirection'], 'redirects are never followed');
            $this->assertTrue($request['args']['reject_unsafe_urls'], 'the request goes through wp_safe_remote_get');
            $this->assertSame('WPMCP-Spectra-Library/1.0', $request['args']['user-agent'], 'the pinned user agent does not carry the site address');
            $this->assertStringNotContainsString('site_url', $request['url']);
            $this->assertStringNotContainsString((string) wp_parse_url(home_url(), PHP_URL_HOST), $request['url']);
            $this->assertStringNotContainsString('purchase_key', $request['url'], 'no license key is ever sent');
        }

        $count = count($this->requests);
        $again = $this->read('list-patterns', [ 'source' => 'spectra', 'search' => 'hero', 'limit' => 2, 'offset' => 4 ]);
        $this->assertSame($result, $again['result']);
        $this->assertCount($count, $this->requests, 'a repeat browse is served from the cache');
    }

    public function test_page_size_is_capped_at_the_rest_maximum(): void
    {
        $this->answer(self::ITEMS, [], 200, [ 'x-wp-total' => '0' ]);
        $out = $this->read('list-patterns', [ 'source' => 'spectra', 'limit' => 200 ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $this->assertSame('100', self::query($this->requests_to(self::ITEMS)[0]['url'])['per_page']);
    }

    public function test_a_category_slug_is_resolved_to_the_library_term_id(): void
    {
        $this->answer(self::ITEMS, [ self::summary(83549, 'Portfolio 10', [ 1425 ]) ], 200, [ 'x-wp-total' => '1' ]);

        $out = $this->read('list-patterns', [ 'source' => 'spectra', 'category' => 'portfolio' ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $this->assertSame('1425', self::query($this->requests_to(self::ITEMS)[0]['url'])['blocks-category']);

        $unknown = $this->read('list-patterns', [ 'source' => 'spectra', 'category' => 'nope' ]);
        $this->assertSame('unknown_category', $unknown['error']['code'] ?? null);
        $this->assertContains('hero', $unknown['error']['data']['categories'] ?? []);
        $this->assertCount(1, $this->requests_to(self::ITEMS), 'an unknown slug never reaches the item list');
    }

    public function test_the_library_is_refused_before_any_request_when_spectra_is_not_active(): void
    {
        $this->spectra_active = false;
        $out = $this->read('list-patterns', [ 'source' => 'spectra' ]);
        $this->assertSame('suite_unavailable', $out['error']['code'] ?? null);

        [ $id, $hash ] = $this->post('');
        $out = $this->write('import-pattern', [ 'name' => 'spectra:83549', 'id' => $id, 'expected_hash' => $hash, 'path' => [ 0 ] ]);
        $this->assertSame('suite_unavailable', $out['error']['code'] ?? null);
        $this->assertSame([], $this->requests);
    }

    public function test_a_filter_for_another_suite_is_refused_before_any_request(): void
    {
        $out = $this->read('list-patterns', [ 'source' => 'spectra', 'suite' => 'kadence-blocks' ]);
        $this->assertSame('invalid_args', $out['error']['code'] ?? null);
        $this->assertSame([], $this->requests);

        $this->answer(self::ITEMS, [], 200, [ 'x-wp-total' => '0' ]);
        $out = $this->read('list-patterns', [ 'source' => 'spectra', 'suite' => 'spectra' ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
    }

    public function test_an_unreachable_or_oversized_answer_is_an_error_and_is_not_cached(): void
    {
        $this->answer(self::ITEMS, [ 'code' => 'oops' ], 500);
        $out = $this->read('list-patterns', [ 'source' => 'spectra' ]);
        $this->assertSame('spectra_library_unavailable', $out['error']['code'] ?? null);

        add_filter('wpmcp_spectra_library_max_bytes', $cap = static fn () => 16);
        $this->answer(self::ITEMS, [ self::summary(83549, 'Portfolio 10') ], 200, [ 'x-wp-total' => '1' ]);
        $out = $this->read('list-patterns', [ 'source' => 'spectra' ]);
        remove_filter('wpmcp_spectra_library_max_bytes', $cap);
        $this->assertSame('spectra_library_unavailable', $out['error']['code'] ?? null);

        $out = $this->read('list-patterns', [ 'source' => 'spectra' ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $this->assertCount(3, $this->requests_to(self::ITEMS), 'failures are not cached');
    }

    // ------------------------------------------------------------ import

    public function test_import_of_a_library_pattern_is_one_undoable_write_with_images_through_the_guard(): void
    {
        $this->answer(self::ITEMS . '/83549', self::item(83549, self::hero_content()));
        [ $id, ] = $this->post('');
        wp_update_post([ 'ID' => $id, 'post_content' => wp_slash('<!-- wp:paragraph --><p>a</p><!-- /wp:paragraph -->') ]);
        $original = $this->content($id);
        $before   = Snapshot_Store::row_count();

        $out = $this->write('import-pattern', [ 'name' => 'spectra:83549', 'id' => $id, 'expected_hash' => hash('sha256', $original), 'path' => [ 1 ] ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $this->assertCount(1, $out['operation_ids']);
        $this->assertSame($before + 1, Snapshot_Store::row_count(), 'one snapshot for the whole import');
        $this->assertSame(1, $out['result']['inserted']);

        $item = $this->requests_to(self::ITEMS . '/83549');
        $this->assertCount(1, $item);
        $this->assertStringContainsString('original_content', self::query($item[0]['url'])['_fields']);
        $this->assertSame('WPMCP-Spectra-Library/1.0', $item[0]['args']['user-agent']);
        $this->assertStringNotContainsString('site_url', $item[0]['url'], 'the site address is not sent, unlike the vendor editor');

        $images = array_column($out['result']['images'], null, 'url');
        $this->assertSame('sideloaded', $images[ self::IMAGE ]['status'], 'the library image host is allowed for library imports');
        $media = (int) $images[ self::IMAGE ]['media_id'];
        $this->assertSame('attachment', get_post_type($media));
        $this->assertSame('kept', $images[ self::OFFLIST ]['status']);
        $this->assertStringContainsString('wpmcp_spectra_library_image_hosts', $images[ self::OFFLIST ]['reason']);
        $this->assertNotContains(self::OFFLIST, array_column($this->requests, 'url'), 'a host off the list is never fetched');

        $content = $this->content($id);
        $local   = (string) wp_get_attachment_url($media);
        $this->assertStringNotContainsString(self::IMAGE, $content);
        $this->assertStringContainsString($local, $content);
        $blocks = array_values(array_filter(parse_blocks($content), static fn ($b) => null !== $b['blockName']));
        $this->assertSame('uagb/container', $blocks[1]['blockName']);
        $this->assertSame($media, $blocks[1]['attrs']['backgroundImageDesktop']['id'], 'the attachment id follows the sideloaded url');
        $this->assertNotEmpty($out['result']['unique_ids'], 'Spectra block ids are made unique in the post');

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_ids'][0]));
        $this->assertSame($original, $this->content($id), 'rollback restores the exact bytes');
    }

    public function test_a_library_pattern_is_fetched_once_and_then_served_from_the_cache(): void
    {
        $this->answer(self::ITEMS . '/83650', self::item(83650, '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->'));
        [ $id, $hash ] = $this->post('');
        $out = $this->write('import-pattern', [ 'name' => 'spectra:83650', 'id' => $id, 'expected_hash' => $hash, 'path' => [ 0 ] ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $count = count($this->requests);
        $out   = $this->write('import-pattern', [ 'name' => 'spectra:83650', 'id' => $id, 'expected_hash' => $out['result']['content_hash'], 'path' => [ 1 ] ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $this->assertCount($count, $this->requests);
        $this->assertCount(1, $this->requests_to(self::ITEMS . '/83650'));
    }

    public function test_premium_spectra_3_and_non_gutenberg_items_are_refused_before_any_write(): void
    {
        $paragraph = '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->';
        $this->answer(self::ITEMS . '/1', self::item(1, $paragraph, [ 'block-access-type' => [ self::PREMIUM ] ]));
        $this->answer(self::ITEMS . '/2', self::item(2, '<!-- wp:spectra/container --><div></div><!-- /wp:spectra/container -->', [ 'spectra-blocks-ver' => [ self::V3 ] ]));
        $this->answer(self::ITEMS . '/3', self::item(3, $paragraph, [ 'astra-blocks-page-builder' => [ self::ELEMENTOR ] ]));
        $this->answer(self::ITEMS . '/4', self::item(4, $paragraph, [ 'block-type' => [ self::PAGE ] ]));
        [ $id, $hash ] = $this->post('');
        $before        = Snapshot_Store::row_count();

        foreach ([ 1, 2, 3, 4 ] as $item) {
            $out = $this->write('import-pattern', [ 'name' => 'spectra:' . $item, 'id' => $id, 'expected_hash' => $hash, 'path' => [ 0 ] ]);
            $this->assertSame('unknown_pattern', $out['error']['code'] ?? null, 'item ' . $item);
        }
        $this->assertSame($before, Snapshot_Store::row_count());
        $this->assertSame('', $this->content($id));
    }

    public function test_import_refuses_a_stale_hash_or_malformed_id_before_any_request_and_a_missing_id_before_any_snapshot(): void
    {
        [ $id, $hash ] = $this->post('');
        $before        = Snapshot_Store::row_count();

        $out = $this->write('import-pattern', [ 'name' => 'spectra:83549', 'id' => $id, 'expected_hash' => str_repeat('0', 64), 'path' => [ 0 ] ]);
        $this->assertSame('invalid_block_edit', $out['error']['code'] ?? null);
        $this->assertSame([], $this->requests, 'a stale hash costs no request');

        $out = $this->write('import-pattern', [ 'name' => 'spectra:../users', 'id' => $id, 'expected_hash' => $hash, 'path' => [ 0 ] ]);
        $this->assertSame('unknown_pattern', $out['error']['code'] ?? null);
        $this->assertSame([], $this->requests, 'a malformed library id costs no request');

        $this->answer(self::ITEMS . '/999', [ 'code' => 'rest_post_invalid_id' ], 404);
        $out = $this->write('import-pattern', [ 'name' => 'spectra:999', 'id' => $id, 'expected_hash' => $hash, 'path' => [ 0 ] ]);
        $this->assertSame('unknown_pattern', $out['error']['code'] ?? null);

        $this->assertSame($before, Snapshot_Store::row_count());
        $this->assertSame('', $this->content($id));
    }
}
