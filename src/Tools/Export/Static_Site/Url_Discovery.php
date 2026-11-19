<?php

namespace WPMCP\Tools\Export\Static_Site;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Which URLs a static HTML export starts from (issue #854).
 *
 * The default set is the one the core XML sitemap is built from, read
 * directly rather than by fetching the sitemap (which a site can switch
 * off): the home page, every published, non-password-protected entry of a
 * public post type (attachments excluded), and the archive of every
 * non-empty term of a public taxonomy; plus the internal links of every
 * nav menu. During the build the export also follows the internal links of
 * each page it renders (pagination, author and date archives), up to the
 * page limit.
 *
 * Two narrowing inputs: post_types (one or more, comma separated) keeps the
 * home page and that type's entries only, and urls exports exactly those
 * internal URLs. Either one also stops the build from following links, so
 * a narrowed export holds what was asked for and nothing else.
 */
class Url_Discovery
{
    /** Default page limit. Filter: wpmcp_static_export_max_pages. */
    public const MAX_PAGES = 500;

    /** URLs a plan lists in full; the counts cover the rest. */
    public const PLAN_LIST_LIMIT = 200;

    /** Rough average bytes per exported page, its share of assets included. */
    public const AVERAGE_PAGE_BYTES = 60000;

    public static function max_pages(): int
    {
        return max(1, (int) apply_filters('wpmcp_static_export_max_pages', self::MAX_PAGES));
    }

    /**
     * The narrowing options from a trigger-backup call, normalized.
     *
     * @return array{post_types: string[], urls: string[], follow: bool}|\WP_Error
     */
    public static function options(array $args)
    {
        $post_types = [];
        if (isset($args['post_types']) && '' !== (string) $args['post_types']) {
            foreach (array_filter(array_map('trim', explode(',', (string) $args['post_types']))) as $type) {
                $type = sanitize_key($type);
                if (! post_type_exists($type) || ! is_post_type_viewable($type) || 'attachment' === $type) {
                    return new \WP_Error('wpmcp_static_export_invalid', sprintf('post_types: "%s" is not a public post type.', $type));
                }
                $post_types[] = $type;
            }
        }

        $urls = [];
        if (isset($args['urls'])) {
            if (! is_array($args['urls'])) {
                return new \WP_Error('wpmcp_static_export_invalid', 'urls must be a list of URLs on this site.');
            }
            foreach ($args['urls'] as $url) {
                $urls[] = (string) $url;
            }
        }

        return [
            'post_types' => $post_types,
            'urls'       => $urls,
            'follow'     => [] === $post_types && [] === $urls,
        ];
    }

    /**
     * The start URLs with where each came from, and the URLs refused.
     *
     * @param array{post_types: string[], urls: string[], follow: bool} $options
     * @return array{urls: array<string, string>, skipped: array<int, array{url: string, reason: string}>}
     */
    public static function discover(array $options): array
    {
        $home    = home_url('/');
        $urls    = [];
        $skipped = [];
        $limit   = self::max_pages();

        $add = static function (string $url, string $source) use (&$urls, &$skipped, $home, $limit): void {
            $url = (string) strtok($url, '#');
            if ('' === $url || isset($urls[ $url ])) {
                return;
            }
            $reason = self::refusal($url, $home);
            if (null !== $reason) {
                $skipped[] = ['url' => $url, 'reason' => $reason];
                return;
            }
            if (count($urls) >= $limit) {
                $skipped[] = ['url' => $url, 'reason' => sprintf('over the %d page limit (filter wpmcp_static_export_max_pages)', $limit)];
                return;
            }
            $urls[ $url ] = $source;
        };

        if ([] !== $options['urls']) {
            foreach ($options['urls'] as $url) {
                $add($url, 'list');
            }
            return ['urls' => $urls, 'skipped' => $skipped];
        }

        $add($home, 'home');

        $types = [] !== $options['post_types'] ? $options['post_types'] : self::public_post_types();
        foreach (self::post_urls($types, $limit) as $url) {
            $add($url, 'post');
        }

        if ($options['follow']) {
            foreach (self::menu_urls() as $url) {
                if (Link_Rewriter::is_internal($url, $home)) {
                    $add($url, 'menu');
                }
            }
            foreach (self::term_urls($limit) as $url) {
                $add($url, 'term');
            }
        }

        return ['urls' => $urls, 'skipped' => $skipped];
    }

    /**
     * Why a URL cannot be exported as a page, or null when it can.
     */
    public static function refusal(string $url, string $home): ?string
    {
        if (! preg_match('#^https?://#i', $url)) {
            return 'not an http(s) URL';
        }
        if (! Link_Rewriter::is_internal($url, $home)) {
            return 'off-site URL';
        }
        if (Link_Rewriter::is_dynamic($url)) {
            return 'not a public HTML page (admin, login, REST, feed or comment endpoint)';
        }
        if ('page' !== Link_Rewriter::kind($url)) {
            return 'not a page URL';
        }
        if (null === Link_Rewriter::page_file($url)) {
            return 'a query string or path that cannot be a static file';
        }

        return null;
    }

    /**
     * The plan a trigger-backup type=static plan=true call returns.
     *
     * @param array{post_types: string[], urls: string[], follow: bool} $options
     */
    public static function plan(array $options): array
    {
        $found  = self::discover($options);
        $counts = array_count_values($found['urls']);
        $total  = count($found['urls']);

        return [
            'plan'            => true,
            'page_count'      => $total,
            'by_source'       => $counts,
            'urls'            => array_slice(array_keys($found['urls']), 0, self::PLAN_LIST_LIMIT),
            'urls_truncated'  => $total > self::PLAN_LIST_LIMIT,
            'follows_links'   => $options['follow'],
            'max_pages'       => self::max_pages(),
            'estimated_bytes' => $total * self::AVERAGE_PAGE_BYTES,
            'skipped'         => array_slice($found['skipped'], 0, self::PLAN_LIST_LIMIT),
            'note'            => 'The estimate is rough (pages times an average with assets). Forms, search and comments do not work in a static copy; the finished job lists the pages that have them.',
        ];
    }

    /** @return string[] */
    private static function public_post_types(): array
    {
        $types = [];
        foreach (get_post_types(['public' => true], 'names') as $type) {
            if ('attachment' !== $type && is_post_type_viewable($type)) {
                $types[] = $type;
            }
        }

        return $types;
    }

    /**
     * @param string[] $types
     * @return string[]
     */
    private static function post_urls(array $types, int $limit): array
    {
        if ([] === $types) {
            return [];
        }

        $ids = get_posts([
            'post_type'        => $types,
            'post_status'      => 'publish',
            'has_password'     => false,
            'posts_per_page'   => $limit,
            'orderby'          => 'ID',
            'order'            => 'ASC',
            'fields'           => 'ids',
            'no_found_rows'    => true,
            'suppress_filters' => false,
        ]);

        $urls = [];
        foreach ($ids as $id) {
            $link = get_permalink((int) $id);
            if (is_string($link) && '' !== $link) {
                $urls[] = $link;
            }
        }

        return $urls;
    }

    /** @return string[] */
    private static function term_urls(int $limit): array
    {
        $taxonomies = array_diff(get_taxonomies(['public' => true], 'names'), ['post_format']);
        if ([] === $taxonomies) {
            return [];
        }

        $terms = get_terms([
            'taxonomy'   => array_values($taxonomies),
            'hide_empty' => true,
            'number'     => $limit,
        ]);
        if (! is_array($terms)) {
            return [];
        }

        $urls = [];
        foreach ($terms as $term) {
            $link = get_term_link($term);
            if (is_string($link)) {
                $urls[] = $link;
            }
        }

        return $urls;
    }

    /** @return string[] */
    private static function menu_urls(): array
    {
        $urls = [];
        foreach ((array) wp_get_nav_menus() as $menu) {
            $items = wp_get_nav_menu_items($menu);
            if (! is_array($items)) {
                continue;
            }
            foreach ($items as $item) {
                if (isset($item->url) && is_string($item->url) && '' !== $item->url) {
                    $urls[] = $item->url;
                }
            }
        }

        return $urls;
    }
}
