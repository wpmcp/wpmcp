<?php

namespace WPMCP\Tools\SEO;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Site-level crawler files (issue #384): extra robots.txt rules, a generated
 * llms.txt, and the core XML sitemap's inclusion lists.
 *
 * Everything lives in ONE option, so a write is one 'option' snapshot and
 * rollback-operation puts the whole state back (or deletes the option when
 * the write created it). Nothing here ever writes a file: robots.txt rules
 * reach visitors through core's robots_txt filter, llms.txt is answered from
 * template_redirect, and the sitemap lists are applied through core's
 * wp_sitemaps_* filters, all read from the option at request time.
 *
 * Ownership is the other half. A physical robots.txt or llms.txt at the site
 * root is served by the web server before WordPress runs, and an SEO plugin
 * may replace the core sitemap or serve its own llms.txt. Those cases are
 * reported by owner() style helpers so the write tool can refuse instead of
 * storing settings no visitor would ever see.
 */
class Crawler_Files
{
    public const OPTION = 'wpmcp_crawler_files';

    /**
     * Priority on robots_txt: after the SEO plugins that rebuild the whole
     * output at the default priority, so the managed rules are appended to
     * whatever they produce instead of being replaced by it.
     */
    public const ROBOTS_PRIORITY = 100;

    private const MAX_RULES_BYTES = 8000;
    private const MAX_PAGES       = 200;
    private const MAX_POST_IDS    = 500;

    /** SEO plugin slug => [display name, sitemap index path]. */
    private const SEO_SITEMAPS = [
        'yoast'        => [ 'Yoast SEO', '/sitemap_index.xml' ],
        'rankmath'     => [ 'Rank Math', '/sitemap_index.xml' ],
        'seopress'     => [ 'SEOPress', '/sitemaps.xml' ],
        'seoframework' => [ 'The SEO Framework', '/sitemap.xml' ],
        'surerank'     => [ 'SureRank', '/sitemap_index.xml' ],
        'aioseo'       => [ 'All in One SEO', '/sitemap.xml' ],
        'slimseo'      => [ 'Slim SEO', '/sitemap.xml' ],
    ];

    /** Test seam: the directory treated as the site root. Guarded by WPMCP_TESTING. */
    private static ?string $root_override = null;

    public static function set_root_for_tests(?string $root): void
    {
        if (defined('WPMCP_TESTING') && WPMCP_TESTING) {
            self::$root_override = $root;
        }
    }

    public static function register_runtime_hooks(): void
    {
        add_filter('robots_txt', [ self::class, 'filter_robots' ], self::ROBOTS_PRIORITY, 2);
        add_filter('wp_sitemaps_post_types', [ self::class, 'filter_sitemap_post_types' ]);
        add_filter('wp_sitemaps_taxonomies', [ self::class, 'filter_sitemap_taxonomies' ]);
        add_filter('wp_sitemaps_posts_query_args', [ self::class, 'filter_sitemap_posts_query' ]);
        // Priority 0: ahead of the managed redirects (1) and core's
        // redirect_canonical (10), so /llms.txt is never redirected away.
        // Wrapped because template_redirect passes an empty string argument.
        add_action('template_redirect', static function (): void {
            self::maybe_serve_llms_txt();
        }, 0);
    }

    // -----------------------------------------------------------------
    // stored settings
    // -----------------------------------------------------------------

    /** @return array{robots_rules:string,llms:array,sitemap:array} */
    public static function defaults(): array
    {
        return [
            'robots_rules' => '',
            'llms'         => [ 'enabled' => false, 'title' => '', 'summary' => '', 'pages' => [] ],
            'sitemap'      => [ 'exclude_post_types' => [], 'exclude_taxonomies' => [], 'exclude_post_ids' => [] ],
        ];
    }

    /** @return array{robots_rules:string,llms:array,sitemap:array} */
    public static function settings(): array
    {
        $stored   = get_option(self::OPTION, []);
        $stored   = is_array($stored) ? $stored : [];
        $defaults = self::defaults();

        return [
            'robots_rules' => (string) ($stored['robots_rules'] ?? ''),
            'llms'         => array_merge($defaults['llms'], is_array($stored['llms'] ?? null) ? $stored['llms'] : []),
            'sitemap'      => array_merge($defaults['sitemap'], is_array($stored['sitemap'] ?? null) ? $stored['sitemap'] : []),
        ];
    }

    // -----------------------------------------------------------------
    // ownership
    // -----------------------------------------------------------------

    public static function root(): string
    {
        if (null !== self::$root_override) {
            return untrailingslashit(self::$root_override);
        }
        if (! function_exists('get_home_path')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        return untrailingslashit(get_home_path());
    }

    /** True when a real file of this name sits at the site root, where the web server serves it before WordPress. */
    public static function has_physical_file(string $name): bool
    {
        return is_file(self::root() . '/' . $name);
    }

    public static function read_physical_file(string $name): string
    {
        $contents = file_get_contents(self::root() . '/' . $name, false, null, 0, 65536); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        return false === $contents ? '' : $contents;
    }

    public static function plugin_name(string $slug): string
    {
        return self::SEO_SITEMAPS[ $slug ][0] ?? $slug;
    }

    /**
     * Which SEO plugin serves its own llms.txt, or '' when none does. Only
     * plugins with a known on/off switch are detected; the
     * wpmcp_llms_txt_owner filter covers the rest.
     */
    public static function llms_owner(): string
    {
        $owner = '';
        if (class_exists('WPSEO_Options') && method_exists('WPSEO_Options', 'get') && \WPSEO_Options::get('enable_llms_txt', false)) {
            $owner = 'yoast';
        } elseif (class_exists('\RankMath\Helper') && method_exists('\RankMath\Helper', 'is_module_active') && \RankMath\Helper::is_module_active('llms-txt')) {
            $owner = 'rankmath';
        }

        return (string) apply_filters('wpmcp_llms_txt_owner', $owner);
    }

    public static function core_sitemaps_enabled(): bool
    {
        return function_exists('wp_sitemaps_get_server') && wp_sitemaps_get_server()->sitemaps_enabled();
    }

    /**
     * 'core' when core sitemaps are on; the SEO plugin's slug when core is
     * off and an SEO plugin is active (they switch core off to serve their
     * own); 'disabled' when core is off and nothing replaces it.
     */
    public static function sitemap_owner(): string
    {
        if (self::core_sitemaps_enabled()) {
            return 'core';
        }
        $seo = SEO_Adapter::active_plugin();
        return '' !== $seo ? $seo : 'disabled';
    }

    public static function sitemap_url(string $owner): string
    {
        if ('core' === $owner) {
            return (string) get_sitemap_url('index');
        }
        if (isset(self::SEO_SITEMAPS[ $owner ])) {
            return home_url(self::SEO_SITEMAPS[ $owner ][1]);
        }
        return '';
    }

    // -----------------------------------------------------------------
    // robots.txt
    // -----------------------------------------------------------------

    /** Hooked to robots_txt: append the managed rules after everything else. */
    public static function filter_robots($output, $public = true): string
    {
        $output = (string) $output;
        $rules  = self::settings()['robots_rules'];
        if ('' === $rules) {
            return $output;
        }
        return rtrim($output, "\n") . "\n\n" . $rules . "\n";
    }

    /**
     * What /robots.txt returns: the physical file when one exists, otherwise
     * core's virtual output with every robots_txt filter applied, built the
     * way do_robots() builds it but without sending headers.
     */
    public static function effective_robots(): string
    {
        if (self::has_physical_file('robots.txt')) {
            return self::read_physical_file('robots.txt');
        }

        ob_start();
        do_action('do_robotstxt'); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook, fired as do_robots() fires it.
        $prefix = (string) ob_get_clean();

        $output  = "User-agent: *\n";
        $output .= 'Disallow: ' . wp_parse_url(admin_url(), PHP_URL_PATH) . "\n";
        $output .= 'Allow: ' . wp_parse_url(admin_url('admin-ajax.php'), PHP_URL_PATH) . "\n";

        return $prefix . (string) apply_filters('robots_txt', $output, (bool) get_option('blog_public')); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook, applied as do_robots() applies it.
    }

    /**
     * Validate robots.txt rules: every non-blank line must be a comment or a
     * "Field: value" directive, so nothing but crawler directives can reach
     * the served file.
     */
    public static function sanitize_robots_rules(string $rules): string
    {
        if (strlen($rules) > self::MAX_RULES_BYTES) {
            throw new \InvalidArgumentException(sprintf('robots_rules is limited to %d bytes.', (int) self::MAX_RULES_BYTES));
        }

        $lines = preg_split('/\r\n|\r|\n/', $rules) ?: [];
        $clean = [];
        foreach ($lines as $i => $line) {
            $line = trim($line);
            if ('' !== $line && '#' !== $line[0] && ! preg_match('/^[A-Za-z][A-Za-z-]*\s*:[^<>\x00-\x1f\x7f]*$/', $line)) {
                throw new \InvalidArgumentException(sprintf(
                    'robots_rules line %d is not a robots.txt directive ("Field: value") or a # comment.',
                    (int) $i + 1
                ));
            }
            $clean[] = $line;
        }

        return trim(implode("\n", $clean));
    }

    // -----------------------------------------------------------------
    // llms.txt
    // -----------------------------------------------------------------

    /** The public URL path llms.txt is answered on. */
    public static function llms_path(): string
    {
        $home = (string) wp_parse_url(home_url('/'), PHP_URL_PATH);
        return rtrim($home, '/') . '/llms.txt';
    }

    /**
     * The llms.txt body for a request URI, or null when this request is not
     * for llms.txt or there is nothing to serve (disabled, no public pages,
     * or an SEO plugin serves its own).
     */
    public static function llms_response(string $request_uri): ?string
    {
        $path = (string) wp_parse_url($request_uri, PHP_URL_PATH);
        if ($path !== self::llms_path()) {
            return null;
        }

        $llms = self::settings()['llms'];
        if (empty($llms['enabled']) || '' !== self::llms_owner()) {
            return null;
        }

        return self::render_llms($llms);
    }

    /** Hooked (via a closure) to template_redirect: answer /llms.txt and end the request. */
    public static function maybe_serve_llms_txt(?callable $emit = null): void
    {
        if (is_admin() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return;
        }

        $uri  = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $body = self::llms_response($uri);
        if (null === $body) {
            return;
        }

        $emit = $emit ?? static function (string $text): void {
            status_header(200);
            header('Content-Type: text/plain; charset=utf-8');
            header('X-Robots-Tag: noindex');
            echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text built from sanitized titles and URLs.
            exit;
        };
        $emit($body);
    }

    /** Render llms.txt from stored settings; null when no chosen page is still public. */
    public static function render_llms(array $llms): ?string
    {
        $items = [];
        foreach ((array) ($llms['pages'] ?? []) as $page) {
            $post_id = (int) ($page['post_id'] ?? 0);
            if (! self::is_public_post($post_id)) {
                continue;
            }
            $title   = self::one_line(wp_strip_all_tags(get_the_title($post_id)));
            $summary = self::one_line((string) ($page['summary'] ?? ''));
            if ('' === $summary) {
                $summary = self::one_line(wp_strip_all_tags((string) get_post_field('post_excerpt', $post_id)));
            }
            $item    = '- [' . str_replace([ '[', ']' ], [ '(', ')' ], $title) . '](' . esc_url_raw((string) get_permalink($post_id)) . ')';
            $items[] = '' !== $summary ? $item . ': ' . $summary : $item;
        }
        if ([] === $items) {
            return null;
        }

        $title   = self::one_line((string) ($llms['title'] ?? '')) ?: self::one_line(wp_strip_all_tags(get_bloginfo('name')));
        $summary = self::one_line((string) ($llms['summary'] ?? '')) ?: self::one_line(wp_strip_all_tags(get_bloginfo('description')));

        $out = '# ' . $title . "\n\n";
        if ('' !== $summary) {
            $out .= '> ' . $summary . "\n\n";
        }
        return $out . "## Pages\n\n" . implode("\n", $items) . "\n";
    }

    public static function is_public_post(int $post_id): bool
    {
        $post = $post_id > 0 ? get_post($post_id) : null;
        return $post instanceof \WP_Post
            && 'publish' === $post->post_status
            && '' === $post->post_password
            && is_post_type_viewable($post->post_type);
    }

    /**
     * Validate an llms page list: [{post_id, summary?}], published and public.
     *
     * @return array<int, array{post_id:int,summary:string}>
     */
    public static function sanitize_llms_pages($pages): array
    {
        if (! is_array($pages)) {
            throw new \InvalidArgumentException('llms.pages must be an array of {post_id, summary}.');
        }
        if (count($pages) > self::MAX_PAGES) {
            throw new \InvalidArgumentException(sprintf('llms.pages is limited to %d pages.', (int) self::MAX_PAGES));
        }

        $out = [];
        foreach ($pages as $page) {
            $post_id = (int) (is_array($page) ? ($page['post_id'] ?? 0) : $page);
            if (! self::is_public_post($post_id)) {
                throw new \InvalidArgumentException(sprintf('Post %d is not a published, public, unprotected post.', (int) $post_id));
            }
            $out[ $post_id ] = [
                'post_id' => $post_id,
                'summary' => is_array($page) ? sanitize_text_field((string) ($page['summary'] ?? '')) : '',
            ];
        }
        return array_values($out);
    }

    private static function one_line(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', html_entity_decode($text, ENT_QUOTES, 'UTF-8')));
    }

    // -----------------------------------------------------------------
    // XML sitemap
    // -----------------------------------------------------------------

    public static function filter_sitemap_post_types($post_types): array
    {
        $post_types = (array) $post_types;
        foreach (self::settings()['sitemap']['exclude_post_types'] as $name) {
            unset($post_types[ $name ]);
        }
        return $post_types;
    }

    public static function filter_sitemap_taxonomies($taxonomies): array
    {
        $taxonomies = (array) $taxonomies;
        foreach (self::settings()['sitemap']['exclude_taxonomies'] as $name) {
            unset($taxonomies[ $name ]);
        }
        return $taxonomies;
    }

    public static function filter_sitemap_posts_query($args): array
    {
        $args = (array) $args;
        $ids  = array_map('intval', (array) self::settings()['sitemap']['exclude_post_ids']);
        if ([] !== $ids) {
            $args['post__not_in'] = array_values(array_unique(array_merge(array_map('intval', (array) ($args['post__not_in'] ?? [])), $ids)));
        }
        return $args;
    }

    /** @return string[] */
    public static function sanitize_names($names, array $known, string $label): array
    {
        if (! is_array($names)) {
            throw new \InvalidArgumentException(sprintf('sitemap.%s must be an array of names.', esc_html($label)));
        }
        $out = [];
        foreach ($names as $name) {
            $name = sanitize_key((string) $name);
            if (! in_array($name, $known, true)) {
                throw new \InvalidArgumentException(sprintf('sitemap.%s: "%s" is not a public one on this site.', esc_html($label), esc_html($name)));
            }
            $out[] = $name;
        }
        $out = array_values(array_unique($out));
        sort($out);
        return $out;
    }

    /** @return int[] */
    public static function sanitize_post_ids($ids): array
    {
        if (! is_array($ids)) {
            throw new \InvalidArgumentException('sitemap.exclude_post_ids must be an array of post ids.');
        }
        if (count($ids) > self::MAX_POST_IDS) {
            throw new \InvalidArgumentException(sprintf('sitemap.exclude_post_ids is limited to %d ids.', (int) self::MAX_POST_IDS));
        }
        $out = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id <= 0 || ! get_post($id)) {
                throw new \InvalidArgumentException(sprintf('sitemap.exclude_post_ids: post %d does not exist.', (int) $id));
            }
            $out[] = $id;
        }
        $out = array_values(array_unique($out));
        sort($out);
        return $out;
    }
}
