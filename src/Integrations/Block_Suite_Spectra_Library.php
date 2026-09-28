<?php

namespace WPMCP\Integrations;

use WPMCP\Tools\Media\Remote_Image_Guard;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Spectra's remote pattern library as a source for the block-suites
 * list-patterns and import-pattern ops (issue #364).
 *
 * Spectra's own editor fills its pattern library, with no key or account,
 * from its vendor's template site: the public WordPress REST API on
 * websitedemos.net, where each pattern is an astra-blocks post and its kind
 * is recorded in public taxonomies (page builder, block or page, free or
 * premium, Spectra 2 or 3). This client reads the same collection through
 * core's standard wp/v2 routes, so it relies on the REST contract every
 * WordPress site keeps, not on a private endpoint. It offers only what
 * Spectra itself hands a site without a license:
 *  - Gutenberg single block patterns (not Elementor items or full pages);
 *  - never an item tagged premium, which the vendor sells behind a key;
 *  - never a Spectra 3 item (spectra/* blocks), which the Spectra release on
 *    wordpress.org does not register and the block suite pack does not know.
 * The filters are sent with every listing and checked again on the item
 * itself before an import, so a changed catalog fails closed.
 *
 * Nothing is sent unless an agent asks for this source, and only while
 * Spectra is active: list-patterns with source "spectra", or import-pattern
 * with a "spectra:<id>" name. Every request:
 *  - is checked by Remote_Image_Guard::validate_url() against the one
 *    library host before it leaves the site;
 *  - goes through wp_safe_remote_get with redirects off, a timeout, a byte
 *    cap (wpmcp_spectra_library_max_bytes) and a pinned user agent. Unlike
 *    Spectra's own importer it sends no site_url and no purchase key;
 *  - is cached in a transient (wpmcp_spectra_library_cache_ttl, a day by
 *    default). Failures are not cached.
 *
 * Library images are sideloaded through Remote_Image_Guard::sideload() with
 * the remote media hosts plus the library's own upload host
 * (wpmcp_spectra_library_image_hosts); anything else is kept and reported.
 *
 * The other suites' remote libraries are deliberately not used:
 *  - Kadence Blocks reads an undocumented vendor cloud endpoint and sends the
 *    site's license key and email to it when the site has them.
 *  - GenerateBlocks reaches its pattern libraries with a public key that is
 *    hardcoded in the plugin; reusing a vendor key is not acceptable.
 *  - Otter Blocks ships its free patterns inside the plugin (they are in the
 *    block pattern registry already); its remote feed only previews Pro
 *    patterns and is keyed on the Otter license.
 *  - Blocksy's remote service installs whole starter sites, not patterns.
 */
final class Block_Suite_Spectra_Library
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.

    public const SOURCE = 'spectra';

    public const PREFIX = 'spectra:';

    public const BASE = 'https://websitedemos.net/wp-json/wp/v2/';

    public const USER_AGENT = 'WPMCP-Spectra-Library/1.0';

    public const DEFAULT_IMAGE_HOSTS = [ 'websitedemos.net' ];

    public const DEFAULT_MAX_BYTES = 4 * 1024 * 1024;

    /** Core's largest REST page. */
    public const MAX_PAGE = 100;

    private const HOSTS = [ 'websitedemos.net' ];

    private const TIMEOUT = 15;

    /** taxonomy => the term slug the filters need. */
    private const TERMS = [
        'astra-blocks-page-builder' => 'gutenberg',
        'block-type'                => 'block',
        'block-access-type'         => 'premium',
        'spectra-blocks-ver'        => 'v3',
    ];

    private const ITEM_FIELDS = 'id,original_content,astra-blocks-page-builder,block-type,block-access-type,spectra-blocks-ver';

    /** Whether a pattern name addresses this source. */
    public static function owns(string $name): bool
    {
        return str_starts_with($name, self::PREFIX);
    }

    /** Refuse, before any request, a filter for another suite or a site without Spectra. */
    public static function refuse(array $args): ?array
    {
        if (self::SOURCE !== ($args['source'] ?? '')) {
            return null;
        }
        if (isset($args['suite']) && Block_Suite::SPECTRA !== $args['suite']) {
            return [
                'code'    => 'invalid_args',
                'message' => 'The Spectra library holds Spectra patterns only. Drop suite, or browse the registry.',
                'data'    => [ 'source' => self::SOURCE ],
            ];
        }
        if (! Block_Suite::is_active(Block_Suite::SPECTRA)) {
            return self::inactive();
        }
        return null;
    }

    public static function list_patterns(array $args): array
    {
        $terms      = self::terms();
        $categories = self::categories();
        $query      = [
            'per_page'                          => max(1, min(self::MAX_PAGE, (int) ($args['limit'] ?? 50))),
            'offset'                            => max(0, (int) ($args['offset'] ?? 0)),
            '_fields'                           => 'id,title,link,blocks-category',
            'astra-blocks-page-builder'         => $terms['astra-blocks-page-builder'],
            'block-type'                        => $terms['block-type'],
            'block-access-type_exclude'         => $terms['block-access-type'],
            'spectra-blocks-ver_exclude'        => $terms['spectra-blocks-ver'],
        ];
        $search = trim((string) ($args['search'] ?? ''));
        if ('' !== $search) {
            $query['search'] = $search;
        }
        $category = trim((string) ($args['category'] ?? ''));
        if ('' !== $category) {
            if (! isset($categories[ $category ])) {
                throw new Operation_Error('unknown_category', sprintf('"%s" is not a Spectra library category.', $category), [ 'categories' => array_keys($categories) ]);
            }
            $query['blocks-category'] = $categories[ $category ];
        }

        $answer   = self::get(add_query_arg($query, self::BASE . 'astra-blocks'));
        $slugs    = array_flip($categories);
        $patterns = [];
        foreach ((array) $answer['body'] as $raw) {
            if (! is_array($raw) || ! isset($raw['id'])) {
                continue;
            }
            $patterns[] = [
                'name'       => self::PREFIX . (int) $raw['id'],
                'title'      => html_entity_decode((string) ($raw['title']['rendered'] ?? ''), ENT_QUOTES, 'UTF-8'),
                'categories' => array_values(array_filter(array_map(
                    static fn ($id) => $slugs[ (int) $id ] ?? null,
                    (array) ($raw['blocks-category'] ?? [])
                ))),
                'suites'     => [ Block_Suite::SPECTRA ],
                'preview'    => esc_url_raw((string) ($raw['link'] ?? '')),
            ];
        }

        return [
            'source'   => self::SOURCE,
            'patterns' => $patterns,
            'total'    => $answer['total'] ?? count($patterns),
        ];
    }

    /** A free Spectra 2 block pattern's markup, or a refusal. */
    public static function content(string $name): string
    {
        if (! Block_Suite::is_active(Block_Suite::SPECTRA)) {
            $refusal = self::inactive();
            throw new Operation_Error($refusal['code'], $refusal['message'], $refusal['data']);
        }
        $id = substr($name, strlen(self::PREFIX));
        if (1 !== preg_match('/^[1-9]\d{0,11}$/', $id)) {
            throw new Operation_Error('unknown_pattern', sprintf('"%s" is not a Spectra library name: use spectra:<id> from list-patterns with source "spectra".', $name), [ 'name' => $name ]);
        }

        $answer = self::get(add_query_arg([ '_fields' => self::ITEM_FIELDS ], self::BASE . 'astra-blocks/' . $id), true);
        $raw    = null === $answer ? null : $answer['body'];
        if (! is_array($raw) || (string) ($raw['id'] ?? '') !== $id) {
            throw new Operation_Error('unknown_pattern', sprintf('Spectra library pattern %s was not found.', $id), [ 'name' => $name ]);
        }

        $terms   = self::terms();
        $has     = static fn (string $taxonomy): bool => in_array($terms[ $taxonomy ], array_map('intval', (array) ($raw[ $taxonomy ] ?? [])), true);
        $content = (string) ($raw['original_content'] ?? '');
        if (! $has('astra-blocks-page-builder') || ! $has('block-type') || $has('block-access-type') || $has('spectra-blocks-ver') || str_contains($content, '<!-- wp:spectra/')) {
            throw new Operation_Error('unknown_pattern', sprintf('Spectra library pattern %s is not a free Gutenberg block pattern for this Spectra release, so it is not offered.', $id), [ 'name' => $name ]);
        }
        return $content;
    }

    /**
     * The hosts a library import may sideload images from: the remote media
     * list plus the library's own upload host.
     *
     * @return string[]
     */
    public static function image_hosts(): array
    {
        $own = (array) apply_filters('wpmcp_spectra_library_image_hosts', self::DEFAULT_IMAGE_HOSTS);
        return array_values(array_unique(array_map('strval', array_merge(Remote_Image_Guard::allowed_hosts(), $own))));
    }

    public static function max_bytes(): int
    {
        return max(1, (int) apply_filters('wpmcp_spectra_library_max_bytes', self::DEFAULT_MAX_BYTES));
    }

    /** @return array{code:string,message:string,data:array<string,string>} */
    private static function inactive(): array
    {
        return [
            'code'    => 'suite_unavailable',
            'message' => 'Spectra is not active on this site, so its pattern library is not offered.',
            'data'    => [ 'suite' => Block_Suite::SPECTRA ],
        ];
    }

    /**
     * The term ids the library filters need. A missing term fails closed:
     * without the premium term, premium items could not be kept out.
     *
     * @return array<string,int> taxonomy => term id
     */
    private static function terms(): array
    {
        $ids = [];
        foreach (self::TERMS as $taxonomy => $slug) {
            $found = self::slugs($taxonomy)[ $slug ] ?? null;
            if (null === $found) {
                throw new Operation_Error('spectra_library_unavailable', sprintf('The Spectra library no longer has the "%s" term in %s, so it cannot be filtered safely.', $slug, $taxonomy));
            }
            $ids[ $taxonomy ] = $found;
        }
        return $ids;
    }

    /** @return array<string,int> category slug => term id */
    private static function categories(): array
    {
        return self::slugs('blocks-category');
    }

    /** @return array<string,int> slug => term id */
    private static function slugs(string $taxonomy): array
    {
        $slugs = [];
        $url   = add_query_arg([ 'per_page' => 100, '_fields' => 'id,slug' ], self::BASE . $taxonomy);
        foreach ((array) self::get($url)['body'] as $term) {
            if (is_array($term) && isset($term['id'], $term['slug'])) {
                $slugs[ (string) $term['slug'] ] = (int) $term['id'];
            }
        }
        return $slugs;
    }

    /**
     * The decoded JSON at a library URL with its x-wp-total count, from the
     * cache or one guarded request. With $missing_ok a 404 is null.
     *
     * @return array{body:mixed,total:int|null}|null
     */
    private static function get(string $url, bool $missing_ok = false): ?array
    {
        $key    = 'wpmcp_splib_' . md5($url);
        $cached = get_transient($key);
        if (is_array($cached) && array_key_exists('body', $cached)) {
            return $cached;
        }

        try {
            Remote_Image_Guard::validate_url($url, self::HOSTS);
        } catch (\InvalidArgumentException $e) {
            throw new Operation_Error('spectra_library_unavailable', 'The Spectra library URL was refused by the remote request guard.');
        }

        $max      = self::max_bytes();
        $response = wp_safe_remote_get($url, [
            'timeout'             => self::TIMEOUT,
            'redirection'         => 0,
            'limit_response_size' => $max + 1,
            'user-agent'          => self::USER_AGENT,
            'headers'             => [ 'Accept' => 'application/json' ],
        ]);
        if (is_wp_error($response)) {
            throw new Operation_Error('spectra_library_unavailable', 'The Spectra library could not be reached: ' . $response->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($missing_ok && 404 === $code) {
            return null;
        }
        if (200 !== $code) {
            throw new Operation_Error('spectra_library_unavailable', sprintf('The Spectra library answered HTTP %d.', $code));
        }

        $declared = wp_remote_retrieve_header($response, 'content-length');
        $body     = (string) wp_remote_retrieve_body($response);
        if (('' !== (string) $declared && (int) $declared > $max) || strlen($body) > $max) {
            throw new Operation_Error('spectra_library_unavailable', sprintf('The Spectra library answer is above the %d byte limit.', $max));
        }
        $decoded = json_decode($body, true, 64);
        if (! is_array($decoded)) {
            throw new Operation_Error('spectra_library_unavailable', 'The Spectra library answer is not JSON.');
        }

        $total  = wp_remote_retrieve_header($response, 'x-wp-total');
        $answer = [ 'body' => $decoded, 'total' => '' === (string) $total ? null : (int) $total ];
        $ttl    = (int) apply_filters('wpmcp_spectra_library_cache_ttl', DAY_IN_SECONDS);
        if ($ttl > 0) {
            set_transient($key, $answer, $ttl);
        }
        return $answer;
    }

    // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
