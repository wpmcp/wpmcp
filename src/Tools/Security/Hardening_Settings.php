<?php

namespace WPMCP\Tools\Security;

use WPMCP\MCP\Transport_Guard;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The hardening settings WP MCP applies itself (issue #847): the fixes for
 * what the hardening audit reports, each on or off on its own, stored in ONE
 * option and applied by WP MCP's own hooks on every request. Nothing is
 * written to wp-config.php, .htaccess or any other file, so turning a
 * setting off (or rolling back the write that turned it on) takes effect on
 * the next request.
 *
 *  - disable_xmlrpc: core's xmlrpc_enabled answers false, every XML-RPC
 *    method is removed (pingbacks and system.multicall included, which
 *    xmlrpc_enabled alone leaves on) and the X-Pingback header goes.
 *  - hide_wp_version: the generator tag is emptied in markup and feeds.
 *    readme.html is a file and stays; the status says so.
 *  - block_user_enumeration: for visitors who are not logged in, the
 *    /wp/v2/users routes answer 401, ?author=N stops resolving (404 instead
 *    of the redirect that names the user) and the users sitemap is off.
 *    Logged-in users keep all of it, so the block editor's author list
 *    works.
 *  - hide_php_errors: display_errors off for the request (logging stays).
 *  - disable_file_editor: edit_plugins, edit_themes and edit_files are
 *    denied, which is what DISALLOW_FILE_EDIT does, through map_meta_cap.
 *  - security_headers: X-Content-Type-Options, X-Frame-Options and
 *    Referrer-Policy on front-end responses, never overriding a value the
 *    site already sends. HSTS and a Content-Security-Policy are left to the
 *    site: both can lock visitors out when set without knowing the site.
 *
 * None of this touches the MCP routes or any REST route but the users
 * route for logged-out visitors. The writes live on the theme pair
 * (Hardening_Pack); this class is the store and the runtime, and ships in
 * every build.
 */
final class Hardening_Settings
{
    public const OPTION = 'wpmcp_hardening';

    public const SETTINGS = [
        'disable_xmlrpc',
        'hide_wp_version',
        'block_user_enumeration',
        'hide_php_errors',
        'disable_file_editor',
        'security_headers',
    ];

    /** Headers security_headers adds when the response does not already carry them. */
    public const HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options'        => 'SAMEORIGIN',
        'Referrer-Policy'        => 'strict-origin-when-cross-origin',
    ];

    /** While true every hook passes its value through, so a probe sees what the site does without WP MCP. */
    private static bool $standing_down = false;

    private static bool $registered = false;

    /** display_errors as it was before WP MCP touched it. */
    private static ?string $display_errors_at_boot = null;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        // phpcs:ignore WordPress.PHP.IniSet.display_errors_Disallowed -- reading only.
        $display                      = ini_get('display_errors');
        self::$display_errors_at_boot = false === $display ? null : (string) $display;

        // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own hooks.
        add_filter('xmlrpc_enabled', [self::class, 'filter_xmlrpc_enabled'], PHP_INT_MAX);
        add_filter('xmlrpc_methods', [self::class, 'filter_xmlrpc_methods'], PHP_INT_MAX);
        add_filter('wp_headers', [self::class, 'filter_headers'], PHP_INT_MAX);
        add_filter('the_generator', [self::class, 'filter_generator'], PHP_INT_MAX);
        add_filter('rest_pre_dispatch', [self::class, 'filter_rest_users'], 5, 3);
        add_filter('request', [self::class, 'filter_query_vars'], 1);
        add_filter('wp_sitemaps_add_provider', [self::class, 'filter_sitemap_provider'], 10, 2);
        add_filter('map_meta_cap', [self::class, 'filter_caps'], 10, 2);
        // phpcs:enable
        add_action('init', [self::class, 'apply_error_display'], 1);
    }

    /** @return array<string,bool> every setting => on. */
    public static function all(): array
    {
        // The audit's pure checks also run outside WordPress (tests), where
        // nothing can be stored, so every setting reads as off there.
        $stored = function_exists('get_option') ? get_option(self::OPTION, []) : [];
        $stored = is_array($stored) ? $stored : [];
        $out    = [];
        foreach (self::SETTINGS as $setting) {
            $out[ $setting ] = ! empty($stored[ $setting ]);
        }
        return $out;
    }

    public static function enabled(string $setting): bool
    {
        return ! self::$standing_down && (self::all()[ $setting ] ?? false);
    }

    /**
     * Run $probe with every hardening hook passing its value through, so a
     * caller can tell what the site does on its own (another plugin, the
     * theme, the server) from what WP MCP adds.
     *
     * @template T
     * @param callable():T $probe
     * @return T
     */
    public static function without_hardening(callable $probe)
    {
        $previous             = self::$standing_down;
        self::$standing_down  = true;
        try {
            return $probe();
        } finally {
            self::$standing_down = $previous;
        }
    }

    /** display_errors before WP MCP changed it, null when PHP did not say. */
    public static function display_errors_at_boot(): ?string
    {
        return self::$display_errors_at_boot;
    }

    // ---------------------------------------------------------------
    // Hooks
    // ---------------------------------------------------------------

    /** @param mixed $enabled */
    public static function filter_xmlrpc_enabled($enabled)
    {
        return self::enabled('disable_xmlrpc') ? false : $enabled;
    }

    /** @param mixed $methods */
    public static function filter_xmlrpc_methods($methods)
    {
        return self::enabled('disable_xmlrpc') ? [] : $methods;
    }

    /** @param mixed $headers */
    public static function filter_headers($headers)
    {
        if (! is_array($headers)) {
            return $headers;
        }
        if (self::enabled('disable_xmlrpc')) {
            unset($headers['X-Pingback']);
        }
        if (self::enabled('security_headers')) {
            $present = array_change_key_case($headers, CASE_LOWER);
            foreach (self::HEADERS as $name => $value) {
                if (! isset($present[ strtolower($name) ])) {
                    $headers[ $name ] = $value;
                }
            }
        }
        return $headers;
    }

    /** @param mixed $generator */
    public static function filter_generator($generator)
    {
        return self::enabled('hide_wp_version') ? '' : $generator;
    }

    /**
     * rest_pre_dispatch: the users routes answer 401 to a visitor who is not
     * logged in. Logged-in users (the block editor) are untouched.
     *
     * @param mixed $result
     * @param mixed $server
     * @param mixed $request
     * @return mixed
     */
    public static function filter_rest_users($result, $server, $request)
    {
        if (null !== $result || ! $request instanceof \WP_REST_Request || ! self::enabled('block_user_enumeration')) {
            return $result;
        }
        $route = strtolower(untrailingslashit($request->get_route()));
        if (! preg_match('#^/wp/v2/users(/|$)#', $route . '/') || is_user_logged_in()) {
            return $result;
        }
        return new \WP_Error('rest_user_cannot_view', __('Sorry, you are not allowed to list users.', 'wpmcp'), [ 'status' => 401 ]);
    }

    /**
     * request: drop a numeric ?author= a logged-out visitor sent, so it
     * resolves to a 404 instead of redirecting to the author's archive,
     * whose URL names the user.
     *
     * @param mixed $query_vars
     * @return mixed
     */
    public static function filter_query_vars($query_vars)
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a public front-end query var, read only.
        $get = isset($_GET['author']) ? [ 'author' => sanitize_text_field(wp_unslash((string) $_GET['author'])) ] : [];
        return is_array($query_vars) ? self::filter_request($query_vars, $get) : $query_vars;
    }

    /**
     * The decision behind filter_query_vars(), with the query string passed
     * in so it can be tested.
     *
     * @param array<string,mixed> $query_vars
     * @param array<string,mixed> $get
     * @return array<string,mixed>
     */
    public static function filter_request(array $query_vars, array $get): array
    {
        if (is_admin() || is_user_logged_in() || ! self::enabled('block_user_enumeration')) {
            return $query_vars;
        }
        if (! isset($get['author'], $query_vars['author'])) {
            return $query_vars;
        }
        unset($query_vars['author']);
        $query_vars['error'] = '404';
        return $query_vars;
    }

    /**
     * @param mixed  $provider
     * @return mixed
     */
    public static function filter_sitemap_provider($provider, string $name)
    {
        return 'users' === $name && self::enabled('block_user_enumeration') ? false : $provider;
    }

    /**
     * @param string[] $caps
     * @return string[]
     */
    public static function filter_caps($caps, string $cap)
    {
        if (in_array($cap, [ 'edit_plugins', 'edit_themes', 'edit_files' ], true) && self::enabled('disable_file_editor')) {
            return [ 'do_not_allow' ];
        }
        return $caps;
    }

    /** init: display_errors off for the rest of the request. */
    public static function apply_error_display(): void
    {
        if (self::enabled('hide_php_errors')) {
            Transport_Guard::suppress_error_display();
        }
    }
}
