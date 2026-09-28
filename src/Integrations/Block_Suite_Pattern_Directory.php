<?php

namespace WPMCP\Integrations;

use WPMCP\Tools\Media\Remote_Image_Guard;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The WordPress.org Pattern Directory as a remote source for the block-suites
 * list-patterns and import-pattern ops (issue #364).
 *
 * The block suites' own cloud libraries are not used. Each is an
 * undocumented endpoint run by its vendor for its own editor, and one of
 * them is reached with a key shipped inside the plugin, which this plugin
 * must never reuse. The Pattern Directory is public, needs no key, and is
 * the service core's own pattern-directory REST route proxies.
 *
 * Nothing is sent unless an agent asks for this source: list-patterns with
 * source "directory", or import-pattern with a "directory:<id>" name. Every
 * request:
 *  - is checked by Remote_Image_Guard::validate_url() against the two
 *    WordPress.org hosts below before it leaves the site;
 *  - goes through wp_safe_remote_get with redirects off, a timeout, a byte
 *    cap (filterable, wpmcp_pattern_directory_max_bytes) and a pinned user
 *    agent, so the site's address is not sent;
 *  - is cached in a transient (wpmcp_pattern_directory_cache_ttl, an hour by
 *    default), so a repeat browse or import sends nothing. Failures are not
 *    cached.
 *
 * Directory images are sideloaded through Remote_Image_Guard::sideload() with
 * the remote media hosts plus the directory's own image hosts
 * (wpmcp_pattern_directory_image_hosts); anything else is kept and reported.
 */
final class Block_Suite_Pattern_Directory
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.

    public const SOURCE = 'directory';

    public const PREFIX = 'directory:';

    public const API = 'https://api.wordpress.org/patterns/1.0/';

    public const CATEGORIES = 'https://wordpress.org/patterns/wp-json/wp/v2/pattern-categories';

    public const USER_AGENT = 'WPMCP-Pattern-Directory/1.0';

    public const DEFAULT_IMAGE_HOSTS = [ 'pd.w.org', 's.w.org' ];

    public const DEFAULT_MAX_BYTES = 4 * 1024 * 1024;

    /** The directory API's largest page. */
    public const MAX_PAGE = 100;

    private const HOSTS = [ 'api.wordpress.org', 'wordpress.org' ];

    private const TIMEOUT = 15;

    /** Whether a pattern name addresses this source. */
    public static function owns(string $name): bool
    {
        return str_starts_with($name, self::PREFIX);
    }

    /** Refuse, before any request, a suite filter: directory patterns use core blocks only. */
    public static function refuse_suite_filter(array $args): ?array
    {
        if (self::SOURCE !== ($args['source'] ?? '') || ! isset($args['suite'])) {
            return null;
        }
        return [
            'code'    => 'invalid_args',
            'message' => 'The Pattern Directory holds core block patterns only, so it cannot be filtered by suite. Drop suite, or browse the registry.',
            'data'    => [ 'source' => self::SOURCE ],
        ];
    }

    public static function list_patterns(array $args): array
    {
        $query = [
            'per_page' => max(1, min(self::MAX_PAGE, (int) ($args['limit'] ?? 50))),
            'offset'   => max(0, (int) ($args['offset'] ?? 0)),
            'locale'   => get_user_locale(),
        ];
        $search = trim((string) ($args['search'] ?? ''));
        if ('' !== $search) {
            $query['search'] = $search;
        }
        $category = trim((string) ($args['category'] ?? ''));
        if ('' !== $category) {
            $query['pattern-categories'] = self::category_id($category);
        }

        $answer   = self::get(add_query_arg($query, self::API));
        $patterns = [];
        foreach ((array) $answer['body'] as $raw) {
            if (! is_array($raw) || ! isset($raw['id'])) {
                continue;
            }
            $content = (string) ($raw['pattern_content'] ?? '');
            $suites  = array_keys(Block_Suite_Patterns::suites_in($content));
            sort($suites, SORT_STRING);
            $patterns[] = [
                'name'          => self::PREFIX . (int) $raw['id'],
                'title'         => html_entity_decode((string) ($raw['title']['rendered'] ?? ''), ENT_QUOTES, 'UTF-8'),
                'categories'    => array_values(array_map('strval', (array) ($raw['category_slugs'] ?? []))),
                'description'   => (string) ($raw['meta']['wpop_description'] ?? ''),
                'suites'        => $suites,
                'remote_images' => count(Block_Suite_Patterns::remote_images($content)),
            ];
        }

        return [
            'source'   => self::SOURCE,
            'patterns' => $patterns,
            'total'    => $answer['total'] ?? count($patterns),
        ];
    }

    /** A directory pattern's block markup, or an unknown_pattern refusal. */
    public static function content(string $name): string
    {
        $id = substr($name, strlen(self::PREFIX));
        if (1 !== preg_match('/^[1-9]\d{0,11}$/', $id)) {
            throw new Operation_Error('unknown_pattern', sprintf('"%s" is not a Pattern Directory name: use directory:<id> from list-patterns with source "directory".', $name), [ 'name' => $name ]);
        }

        $answer = self::get(add_query_arg([ 'include' => $id ], self::API));
        foreach ((array) $answer['body'] as $raw) {
            if (is_array($raw) && (string) ($raw['id'] ?? '') === $id) {
                return (string) ($raw['pattern_content'] ?? '');
            }
        }
        throw new Operation_Error('unknown_pattern', sprintf('Pattern Directory pattern %s was not found.', $id), [ 'name' => $name ]);
    }

    /**
     * The hosts a directory import may sideload images from: the remote media
     * list plus the directory's own image hosts.
     *
     * @return string[]
     */
    public static function image_hosts(): array
    {
        $own = (array) apply_filters('wpmcp_pattern_directory_image_hosts', self::DEFAULT_IMAGE_HOSTS);
        return array_values(array_unique(array_map('strval', array_merge(Remote_Image_Guard::allowed_hosts(), $own))));
    }

    public static function max_bytes(): int
    {
        return max(1, (int) apply_filters('wpmcp_pattern_directory_max_bytes', self::DEFAULT_MAX_BYTES));
    }

    /** The directory term id for a category slug (digits pass through). */
    private static function category_id(string $category): int
    {
        if (1 === preg_match('/^\d+$/', $category)) {
            return (int) $category;
        }
        $slugs = [];
        foreach ((array) self::get(add_query_arg([ 'per_page' => 100, '_fields' => 'id,slug' ], self::CATEGORIES))['body'] as $term) {
            if (is_array($term) && isset($term['id'], $term['slug'])) {
                $slugs[ (string) $term['slug'] ] = (int) $term['id'];
            }
        }
        if (! isset($slugs[ $category ])) {
            throw new Operation_Error('unknown_category', sprintf('"%s" is not a Pattern Directory category.', $category), [ 'categories' => array_keys($slugs) ]);
        }
        return $slugs[ $category ];
    }

    /**
     * The decoded JSON at a directory URL with its x-wp-total count, from the
     * cache or one guarded request.
     *
     * @return array{body:mixed,total:int|null}
     */
    private static function get(string $url): array
    {
        $key    = 'wpmcp_pdir_' . md5($url);
        $cached = get_transient($key);
        if (is_array($cached) && array_key_exists('body', $cached)) {
            return $cached;
        }

        try {
            Remote_Image_Guard::validate_url($url, self::HOSTS);
        } catch (\InvalidArgumentException $e) {
            throw new Operation_Error('pattern_directory_unavailable', 'The Pattern Directory URL was refused by the remote request guard.');
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
            throw new Operation_Error('pattern_directory_unavailable', 'The Pattern Directory could not be reached: ' . $response->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if (200 !== $code) {
            throw new Operation_Error('pattern_directory_unavailable', sprintf('The Pattern Directory answered HTTP %d.', $code));
        }

        $declared = wp_remote_retrieve_header($response, 'content-length');
        $body     = (string) wp_remote_retrieve_body($response);
        if (('' !== (string) $declared && (int) $declared > $max) || strlen($body) > $max) {
            throw new Operation_Error('pattern_directory_unavailable', sprintf('The Pattern Directory answer is above the %d byte limit.', $max));
        }
        $decoded = json_decode($body, true, 64);
        if (! is_array($decoded)) {
            throw new Operation_Error('pattern_directory_unavailable', 'The Pattern Directory answer is not a JSON list.');
        }

        $total  = wp_remote_retrieve_header($response, 'x-wp-total');
        $answer = [ 'body' => $decoded, 'total' => '' === (string) $total ? null : (int) $total ];
        $ttl    = (int) apply_filters('wpmcp_pattern_directory_cache_ttl', HOUR_IN_SECONDS);
        if ($ttl > 0) {
            set_transient($key, $answer, $ttl);
        }
        return $answer;
    }

    // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
