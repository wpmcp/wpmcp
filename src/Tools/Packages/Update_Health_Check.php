<?php

namespace WPMCP\Tools\Packages;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The site check a safe plugin or theme update runs before and after the
 * package is swapped (issue #843): a handful of this site's own pages,
 * fetched over loopback in a fresh request, so a new version that fatals
 * on load breaks that request and not the one running the update.
 *
 * A page fails when it cannot be fetched, answers with a 5xx status, or its
 * body carries a PHP fatal or core's "critical error" screen. Only the
 * status and a short reason are reported; the page body never leaves here.
 *
 * Every URL must be on this site's own host (home or site URL): the list is
 * filterable, and a filter must not turn the check into a request to
 * anywhere else.
 */
class Update_Health_Check
{
    /** Recent published posts added to the home page and wp-admin. */
    public const SAMPLE_POSTS = 2;

    public const TIMEOUT = 15;

    /** Body fragments that mean PHP died while rendering the page. */
    private const FATAL_MARKERS = [
        'There has been a critical error on this website',
        '<b>Fatal error</b>',
        '<b>Parse error</b>',
        'PHP Fatal error',
    ];

    /**
     * The pages to check: the home page, wp-admin (which loads every active
     * plugin before it redirects a visitor to the login screen) and a sample
     * of recent posts.
     *
     * @return string[]
     */
    public function urls(): array
    {
        $urls = [ home_url('/'), admin_url('index.php') ];

        $posts = get_posts([
            'post_type'        => 'post',
            'post_status'      => 'publish',
            'numberposts'      => self::SAMPLE_POSTS,
            'orderby'          => 'date',
            'order'            => 'DESC',
            'fields'           => 'ids',
        ]);
        foreach ((array) $posts as $post_id) {
            $link = get_permalink((int) $post_id);
            if (is_string($link) && '' !== $link) {
                $urls[] = $link;
            }
        }

        $urls = (array) apply_filters('wpmcp_safe_update_check_urls', $urls);
        $urls = array_values(array_unique(array_filter(array_map('strval', $urls), [ $this, 'is_own_url' ])));

        return $urls;
    }

    /**
     * Fetch one page and judge it.
     *
     * @return array{url: string, ok: bool, status: int, reason?: string}
     */
    public function probe(string $url): array
    {
        if (! $this->is_own_url($url)) {
            return [ 'url' => $url, 'ok' => false, 'status' => 0, 'reason' => 'not a page on this site' ];
        }

        $response = wp_remote_get(add_query_arg('wpmcp_update_check', (string) wp_rand(), $url), [
            'timeout'     => self::TIMEOUT,
            'redirection' => 0,
            // Core's own loopback requests (Site Health, the file editor)
            // skip certificate checks against the local site the same way.
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own filter, read as core reads it.
            'sslverify'   => (bool) apply_filters('https_local_ssl_verify', false),
            'headers'     => [ 'Cache-Control' => 'no-cache' ],
        ]);

        if (is_wp_error($response)) {
            return [ 'url' => $url, 'ok' => false, 'status' => 0, 'reason' => 'request failed: ' . $response->get_error_message() ];
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status >= 500) {
            return [ 'url' => $url, 'ok' => false, 'status' => $status, 'reason' => sprintf('HTTP %d', $status) ];
        }

        $body = (string) wp_remote_retrieve_body($response);
        foreach (self::FATAL_MARKERS as $marker) {
            if (false !== stripos($body, $marker)) {
                return [ 'url' => $url, 'ok' => false, 'status' => $status, 'reason' => 'PHP fatal error on the page' ];
            }
        }

        return [ 'url' => $url, 'ok' => true, 'status' => $status ];
    }

    /** Whether $url is http(s) on this site's home or site URL host. */
    public function is_own_url(string $url): bool
    {
        $host   = wp_parse_url($url, PHP_URL_HOST);
        $scheme = wp_parse_url($url, PHP_URL_SCHEME);
        if (! is_string($host) || '' === $host || ! in_array($scheme, [ 'http', 'https' ], true)) {
            return false;
        }

        $own = array_filter([
            wp_parse_url(home_url('/'), PHP_URL_HOST),
            wp_parse_url(site_url('/'), PHP_URL_HOST),
            wp_parse_url(admin_url(), PHP_URL_HOST),
        ], 'is_string');

        return in_array(strtolower($host), array_map('strtolower', $own), true);
    }
}
