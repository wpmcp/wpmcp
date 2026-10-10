<?php

namespace WPMCP\Auth;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Finds the multisite site an OAuth path-insertion URL names, and builds a
 * document as that site (issue #473).
 *
 * On a subdirectory network the issuer of a subsite is
 * https://example.com/shop, so RFC 8414 and RFC 9728 have clients ask the
 * domain root: /.well-known/oauth-authorization-server/shop. WordPress
 * hands that request to the main site, which uses this class to resolve
 * /shop/ on the same host to the subsite and answer with the subsite's own
 * metadata. Only a site on the same host, with this plugin active, is ever
 * resolved, so the main site never vouches for another host or for a site
 * that cannot complete the flow.
 */
class Network_Sites
{
    /**
     * Test seam: the harness boots WordPress single-site, so tests describe a
     * network as ['host/path/' => home URL]. Running as one of those sites
     * filters home and siteurl to its home URL instead of switch_to_blog().
     *
     * @var array<string, string>|null
     */
    private static ?array $test_sites = null;

    /** @param array<string, string>|null $sites */
    public static function set_test_sites(?array $sites): void
    {
        self::$test_sites = $sites;
    }

    /**
     * Run $build as the site at $host + $path, when that is another site of
     * this network. Returns null when no such site exists, when it is the
     * current site (which answers its own forms), or when this plugin is not
     * active there.
     *
     * $build runs under switch_to_blog(), so it sees that site's options but
     * not filters added only by code loaded on that site; callers re-check
     * that the site's home is the issuer the URL names, so the worst a
     * site-specific filter can cause is a document the client then fails
     * to use, never another site's issuer.
     *
     * @template T
     * @param callable(): T $build
     * @return T|null
     */
    public static function run_as(string $host, string $path, callable $build)
    {
        $host = strtolower($host);
        $path = '/' . trim($path, '/') . '/';
        if ('//' === $path || '' === $host) {
            return null;
        }

        if (null !== self::$test_sites) {
            return self::run_as_test_site($host, $path, $build);
        }

        if (! is_multisite()) {
            return null;
        }

        $ids = get_sites([
            'domain'   => $host,
            'path'     => $path,
            'number'   => 1,
            'fields'   => 'ids',
            'archived' => 0,
            'deleted'  => 0,
            'spam'     => 0,
        ]);
        if (! $ids) {
            return null;
        }

        $site_id = (int) $ids[0];
        if ($site_id === get_current_blog_id()) {
            return null;
        }

        switch_to_blog($site_id);
        try {
            return self::plugin_active_here() ? $build() : null;
        } finally {
            restore_current_blog();
        }
    }

    /** The current site's host, as multisite stores it, for matching against $host. */
    public static function current_host(): string
    {
        return strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
    }

    /**
     * Whether the main site of this network loads this plugin, and so can
     * answer the path-insertion URLs at the domain root for its subsites.
     */
    public static function main_site_answers(): bool
    {
        if (! is_multisite()) {
            return false;
        }
        if (is_main_site()) {
            return self::plugin_active_here();
        }

        switch_to_blog(get_main_site_id());
        try {
            return self::plugin_active_here();
        } finally {
            restore_current_blog();
        }
    }

    private static function plugin_active_here(): bool
    {
        if (! defined('WPMCP_FILE')) {
            return false;
        }
        $basename = plugin_basename(WPMCP_FILE);

        if (in_array($basename, (array) get_option('active_plugins', []), true)) {
            return true;
        }

        return isset(((array) get_site_option('active_sitewide_plugins', []))[ $basename ]);
    }

    /**
     * @template T
     * @param callable(): T $build
     * @return T|null
     */
    private static function run_as_test_site(string $host, string $path, callable $build)
    {
        $home = self::$test_sites[ $host . $path ] ?? null;
        if (null === $home || untrailingslashit($home) === untrailingslashit(home_url())) {
            return null;
        }

        $filter = static fn () => $home;
        add_filter('pre_option_home', $filter, 1000);
        add_filter('pre_option_siteurl', $filter, 1000);
        try {
            return $build();
        } finally {
            remove_filter('pre_option_home', $filter, 1000);
            remove_filter('pre_option_siteurl', $filter, 1000);
        }
    }
}
