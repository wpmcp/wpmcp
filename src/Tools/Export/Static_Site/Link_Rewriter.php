<?php

namespace WPMCP\Tools\Export\Static_Site;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The URL arithmetic of the static HTML export (issue #854): where a page or
 * an asset lands inside the ZIP, and how a reference in a page or a
 * stylesheet is rewritten to a relative path that still resolves when the
 * copy is opened from disk (file://) or served from any folder of a CDN.
 *
 * Layout. Every file is keyed by its URL path from the host root, so the
 * ZIP mirrors the live site's URL space: /about/ is about/index.html, the
 * home page is index.html, /wp-content/themes/x/style.css?ver=2 is
 * wp-content/themes/x/style.css. Page links point at the explicit
 * index.html, because a file:// browser does not resolve a folder to its
 * index.
 *
 * What is never rewritten: references this export cannot make work
 * statically (wp-admin, wp-login.php, wp-json, xmlrpc.php, feeds, comment
 * posting, and any page URL with a query string such as ?s= search) stay
 * pointing at the live site, as do off-site URLs, data: and javascript:
 * URIs and in-page fragments; a relative reference to such a URL is made
 * absolute so it still reaches the live site. The caller's resolver decides the rest: it
 * returns the ZIP path a URL is exported to, or null to leave it alone.
 *
 * Pure string work with no WordPress state, so it is unit tested directly.
 */
class Link_Rewriter
{
    /** Attributes that carry one URL. */
    private const URL_ATTRIBUTES = 'href|src|poster|data-src|data-bg|data-background|data-lazy-src';

    /** Attributes that carry a srcset list. */
    private const SRCSET_ATTRIBUTES = 'srcset|data-srcset|data-lazy-srcset';

    /** Path prefixes and files that only work against the live site. */
    private const DYNAMIC_PATHS = [
        '/wp-admin',
        '/wp-login.php',
        '/wp-json',
        '/xmlrpc.php',
        '/wp-comments-post.php',
        '/wp-cron.php',
        '/wp-signup.php',
        '/wp-activate.php',
        '/wp-trackback.php',
    ];

    /** Extensions an exported page may have. Anything else is an asset. */
    private const PAGE_EXTENSIONS = ['', 'html', 'htm'];

    /**
     * Resolve a reference against the URL of the document it appears in.
     * Null for anything that is not an http(s) URL once resolved: empty,
     * fragment-only, mailto:, tel:, data:, javascript: and the like.
     */
    public static function resolve(string $ref, string $base): ?string
    {
        $ref = trim(html_entity_decode($ref, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ('' === $ref || '#' === $ref[0]) {
            return null;
        }

        if (preg_match('#^([a-z][a-z0-9+.-]*):#i', $ref, $m)) {
            return in_array(strtolower($m[1]), ['http', 'https'], true) ? $ref : null;
        }

        $parts = wp_parse_url($base);
        if (! is_array($parts) || empty($parts['host'])) {
            return null;
        }
        $scheme = $parts['scheme'] ?? 'http';
        $origin = $scheme . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');

        if (str_starts_with($ref, '//')) {
            return $scheme . ':' . $ref;
        }
        if ('/' === $ref[0]) {
            return $origin . self::normalize_path($ref);
        }

        $base_path = (string) ($parts['path'] ?? '/');
        if ('?' === $ref[0]) {
            return $origin . $base_path . $ref;
        }

        $dir = str_ends_with($base_path, '/') ? $base_path : rtrim(str_replace('\\', '/', dirname($base_path)), '/.') . '/';

        return $origin . self::normalize_path($dir . $ref);
    }

    /** Collapse ./ and ../ segments of a path (with any query and fragment). */
    private static function normalize_path(string $ref): string
    {
        $suffix = '';
        $cut    = strcspn($ref, '?#');
        if ($cut < strlen($ref)) {
            $suffix = substr($ref, $cut);
            $ref    = substr($ref, 0, $cut);
        }

        $out = [];
        foreach (explode('/', $ref) as $segment) {
            if ('.' === $segment) {
                continue;
            }
            if ('..' === $segment) {
                if (count($out) > 1) {
                    array_pop($out);
                }
                continue;
            }
            $out[] = $segment;
        }
        $path = implode('/', $out);
        if ('' === $path || '/' !== $path[0]) {
            $path = '/' . $path;
        }
        if (preg_match('#/\.\.?$#', $ref)) {
            $path = rtrim($path, '/') . '/';
        }

        return $path . $suffix;
    }

    /** Whether a URL is on the same host as the site's home URL. */
    public static function is_internal(string $url, string $home): bool
    {
        $host = wp_parse_url($url, PHP_URL_HOST);
        $own  = wp_parse_url($home, PHP_URL_HOST);

        return is_string($host) && is_string($own) && strtolower($host) === strtolower($own);
    }

    /**
     * Whether an internal URL is one only the live site can answer: an
     * admin, login, REST, XML-RPC, cron or comment endpoint, or a feed.
     */
    public static function is_dynamic(string $url): bool
    {
        $path = '/' . ltrim((string) wp_parse_url($url, PHP_URL_PATH), '/');
        foreach (self::DYNAMIC_PATHS as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/') || (str_ends_with($prefix, '.php') && str_starts_with($path, $prefix))) {
                return true;
            }
        }

        return (bool) preg_match('#/(feed|comments/feed)(/|$)|/embed/?$#', $path);
    }

    /** 'page' or 'asset', from the URL path's extension. */
    public static function kind(string $url): string
    {
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        $ext  = strtolower((string) pathinfo(rtrim($path, '/'), PATHINFO_EXTENSION));
        if (str_ends_with($path, '/') || in_array($ext, self::PAGE_EXTENSIONS, true)) {
            return 'page';
        }

        return 'php' === $ext ? 'dynamic' : 'asset';
    }

    /**
     * The ZIP path of a page URL, or null when the URL cannot be a static
     * file (a query string, or a path segment that would escape the root).
     */
    public static function page_file(string $url): ?string
    {
        if (null !== wp_parse_url($url, PHP_URL_QUERY)) {
            return null;
        }
        $path = self::safe_path($url);
        if (null === $path) {
            return null;
        }
        if ('' === $path) {
            return 'index.html';
        }
        $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($ext, ['html', 'htm'], true) && ! str_ends_with($path, '/')) {
            return $path;
        }

        return rtrim($path, '/') . '/index.html';
    }

    /** The ZIP path of an asset URL (its query string dropped), or null. */
    public static function asset_file(string $url): ?string
    {
        $path = self::safe_path($url);
        if (null === $path || '' === $path || str_ends_with($path, '/')) {
            return null;
        }

        return $path;
    }

    /**
     * The URL path from the host root, decoded and without the leading
     * slash, or null when a segment is empty-dot, a parent reference or
     * holds a control character.
     */
    private static function safe_path(string $url): ?string
    {
        $path = rawurldecode((string) wp_parse_url($url, PHP_URL_PATH));
        $path = ltrim($path, '/');
        if ('' === $path) {
            return '';
        }
        foreach (explode('/', rtrim($path, '/')) as $segment) {
            if ('' === $segment || '.' === $segment || '..' === $segment || preg_match('/[\x00-\x1f\\\\]/', $segment)) {
                return null;
            }
        }

        return $path;
    }

    /**
     * The relative reference from the ZIP file $from to the ZIP file $to,
     * each path segment percent-encoded.
     */
    public static function relative(string $from, string $to): string
    {
        $from_dir = explode('/', $from);
        array_pop($from_dir);
        $to_parts = explode('/', $to);

        while ([] !== $from_dir && [] !== $to_parts && count($to_parts) > 1 && $from_dir[0] === $to_parts[0]) {
            array_shift($from_dir);
            array_shift($to_parts);
        }

        $ups = str_repeat('../', count($from_dir));

        return $ups . implode('/', array_map('rawurlencode', $to_parts));
    }

    /**
     * Rewrite the URL references in a page.
     *
     * @param string                          $html     The page's markup.
     * @param string                          $page_url The page's own URL, the base for relative references.
     * @param string                          $page_file The page's ZIP path.
     * @param callable(string, string): ?string $resolver Given an absolute URL and its kind ('page' or
     *                                                    'asset'), the ZIP path it is exported to, or null.
     */
    public static function rewrite_html(string $html, string $page_url, string $page_file, callable $resolver): string
    {
        $rewrite_one = static function (string $value) use ($page_url, $page_file, $resolver): string {
            return self::rewrite_reference($value, $page_url, $page_file, $resolver, null);
        };

        $html = (string) preg_replace_callback(
            '/(\s(?:' . self::URL_ATTRIBUTES . ')\s*=\s*)(["\'])(.*?)\2/is',
            static fn(array $m): string => $m[1] . $m[2] . $rewrite_one($m[3]) . $m[2],
            $html
        );

        $html = (string) preg_replace_callback(
            '/(\s(?:' . self::SRCSET_ATTRIBUTES . ')\s*=\s*)(["\'])(.*?)\2/is',
            static function (array $m) use ($rewrite_one): string {
                $candidates = array_map('trim', explode(',', $m[3]));
                $out        = [];
                foreach ($candidates as $candidate) {
                    if ('' === $candidate) {
                        continue;
                    }
                    $bits    = preg_split('/\s+/', $candidate, 2);
                    $bits[0] = $rewrite_one((string) $bits[0]);
                    $out[]   = implode(' ', $bits);
                }
                return $m[1] . $m[2] . implode(', ', $out) . $m[2];
            },
            $html
        );

        // Inline <style> blocks and style="" attributes carry url() too.
        $css = static fn(string $text): string => self::rewrite_css($text, $page_url, $page_file, $resolver);
        $html = (string) preg_replace_callback(
            '#(<style\b[^>]*>)(.*?)(</style>)#is',
            static fn(array $m): string => $m[1] . $css($m[2]) . $m[3],
            $html
        );

        return (string) preg_replace_callback(
            '/(\sstyle\s*=\s*)(["\'])(.*?)\2/is',
            static fn(array $m): string => $m[1] . $m[2] . $css($m[3]) . $m[2],
            $html
        );
    }

    /**
     * Rewrite the url() and @import references in a stylesheet whose own URL
     * is $css_url and ZIP path $css_file. Everything a stylesheet points at
     * is an asset (fonts, images, other stylesheets).
     *
     * @param callable(string, string): ?string $resolver See rewrite_html().
     */
    public static function rewrite_css(string $css, string $css_url, string $css_file, callable $resolver): string
    {
        $css = (string) preg_replace_callback(
            '/url\(\s*(["\']?)([^"\')]*)\1\s*\)/i',
            static fn(array $m): string => 'url(' . $m[1] . self::rewrite_reference($m[2], $css_url, $css_file, $resolver, 'asset') . $m[1] . ')',
            $css
        );

        return (string) preg_replace_callback(
            '/(@import\s+)(["\'])([^"\']+)\2/i',
            static fn(array $m): string => $m[1] . $m[2] . self::rewrite_reference($m[3], $css_url, $css_file, $resolver, 'asset') . $m[2],
            $css
        );
    }

    /**
     * One reference: resolved, handed to the resolver, and written back
     * relative to $from_file with its fragment kept. Unchanged when the
     * resolver declines it.
     *
     * @param callable(string, string): ?string $resolver
     */
    private static function rewrite_reference(string $value, string $base, string $from_file, callable $resolver, ?string $kind): string
    {
        $absolute = self::resolve($value, $base);
        if (null === $absolute) {
            return $value;
        }

        $fragment = '';
        $hash     = strpos($absolute, '#');
        if (false !== $hash) {
            $fragment = substr($absolute, $hash);
            $absolute = substr($absolute, 0, $hash);
        }

        $target = $resolver($absolute, $kind ?? self::kind($absolute));
        if (null === $target) {
            // Left for the live site. An absolute reference already points
            // there; a relative or root-relative one would resolve inside
            // the copy (or at a CDN's root), so it is made absolute.
            if (preg_match('#^\s*(https?:)?//#i', $value)) {
                return $value;
            }
            $live = $absolute . $fragment;
            return null === $kind ? str_replace('&', '&amp;', $live) : $live;
        }

        return self::relative($from_file, $target) . $fragment;
    }
}
