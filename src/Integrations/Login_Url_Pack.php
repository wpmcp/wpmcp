<?php

namespace WPMCP\Integrations;

use WPMCP\Governance\Governance_Audit_Log;
use WPMCP\Maintenance\Site_Gate;
use WPMCP\Safety\Snapshot;
use WPMCP\Safety\Snapshot_Store;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * WPS Hide Login's custom login URL (issue #896) as free ops on the theme
 * dispatcher pair, so they add no top-level tool.
 *
 * WPS Hide Login keeps two options: whl_page, the login slug (missing or
 * empty means login), and whl_redirect_admin, where wp-admin and
 * wp-login.php send a visitor who is not logged in (missing means 404). On a
 * network where the plugin is network activated, a site without its own
 * whl_page uses the network default in the site option of the same name.
 * While the plugin is active wp-login.php is always blocked; it has no
 * separate switch.
 *
 * get-login-url reads both slugs and the URLs they give. set-login-url,
 * off until the site opts in through the wpmcp_enable_login_url_write
 * filter, changes either or both. Changing the login URL is the change most
 * likely to lock the owner out, so it:
 *  - refuses a slug that is not already a clean slug (the plugin would
 *    sanitize it into something else), one that collides with a post or
 *    page, a WordPress query var (the plugin's own forbidden list), or a
 *    reserved path: the REST prefix the MCP connection uses, wp-admin,
 *    wp-content, wp-includes and anything holding wp-login;
 *  - needs confirm:true, with the new login URL in the question;
 *  - probes the new login URL and the REST root after the write and puts
 *    the old values back at once, voiding the undo point, when the login URL
 *    answers 404 or an error, or the REST root answers 404 or an error;
 *  - returns the new URL and records it in the governance audit log.
 * Every write is one option_set snapshot of both options, so
 * rollback-operation restores the old URL exactly.
 */
final class Login_Url_Pack
{
    public const ENABLE_FILTER = 'wpmcp_enable_login_url_write';

    public const ACTIVE_FILTER = 'wpmcp_wps_hide_login_active';

    private const LOGIN_OPTION = 'whl_page';

    private const REDIRECT_OPTION = 'whl_redirect_admin';

    private const PLUGIN_FILE = 'wps-hide-login/wps-hide-login.php';

    /** Paths a login slug must never take, besides the REST prefix and the query vars. */
    private const RESERVED = [ 'wp-admin', 'wp-content', 'wp-includes', 'wp-json', 'wp-cron', 'wp-sitemap', 'xmlrpc', 'feed', 'embed', 'admin', 'dashboard', 'rest_route' ];

    /** @return array<string, array<string, mixed>> op name => definition for the theme pair. */
    public static function operations(): array
    {
        $enabled  = (bool) apply_filters('wpmcp_enable_login_url_write', false);
        $requires = static fn () => Ops_Status_Packs::presence(self::is_active(), 'wps_hide_login_inactive', 'WPS Hide Login');

        return [
            'get-login-url' => [
                'mode'         => 'read',
                'capability'   => 'manage_options',
                'description'  => 'WPS Hide Login: the custom login slug and URL, and the slug and URL that wp-admin and wp-login.php send logged-out visitors to (wp-login.php is always blocked while the plugin is active). source says whether the slug is this site\'s own, the network default or the plugin default (login)',
                'input_schema' => [ 'type' => 'object', 'properties' => [] ],
                'requires'     => $requires,
                'handler'      => static fn (): array => self::status(),
            ],
            'set-login-url' => [
                'mode'               => 'destructive',
                'capability'         => 'manage_options',
                'description'        => 'WPS Hide Login: change the login slug and/or the redirect slug. Refuses a slug that collides with a post or page, a query var or a reserved path (the REST prefix included). Needs confirm:true; the preview gives the new login URL. Probes the new login URL and the REST root after the write and puts the old values back if either fails. Snapshotted; undo: rollback-operation. Disabled by default (wpmcp_enable_login_url_write filter)',
                'enabled_by_default' => $enabled,
                'opt_in_filter'      => self::ENABLE_FILTER,
                'input_schema'       => [
                    'type'       => 'object',
                    'properties' => [
                        'login_slug'    => [ 'type' => 'string' ],
                        'redirect_slug' => [ 'type' => 'string' ],
                    ],
                ],
                'requires'           => $requires,
                'validate'           => static fn (array $args): ?array => self::refusal_for($args),
                'confirm_preview'    => static fn (array $args): array => self::preview($args),
                'snapshot'           => static fn (): array => [ 'object_type' => 'option_set', 'object_id' => Snapshot::option_set_id([ self::LOGIN_OPTION, self::REDIRECT_OPTION ]) ],
                'handler'            => static fn (array $args, array $context = []): array => self::set($args, $context),
            ],
        ];
    }

    /** Whether WPS Hide Login is loaded, filterable through wpmcp_wps_hide_login_active. */
    public static function is_active(): bool
    {
        return (bool) apply_filters('wpmcp_wps_hide_login_active', defined('WPS_HIDE_LOGIN_VERSION'));
    }

    // ---------------------------------------------------------------
    // Reads
    // ---------------------------------------------------------------

    /** @return array<string, mixed> */
    private static function status(): array
    {
        [ $login, $source ] = self::login_slug();
        $redirect           = self::redirect_slug();

        return [
            'plugin'               => 'wps-hide-login',
            'login_slug'           => $login,
            'login_url'            => self::url_for($login),
            'redirect_slug'        => $redirect,
            'redirect_url'         => self::url_for($redirect),
            'wp_login_php_blocked' => true,
            'source'               => $source,
        ];
    }

    /**
     * The login slug the plugin uses and where it comes from, the way its
     * new_login_slug() resolves it.
     *
     * @return array{0: string, 1: string}
     */
    private static function login_slug(): array
    {
        $own = (string) get_option(self::LOGIN_OPTION, '');
        if ('' !== $own) {
            return [ $own, 'site' ];
        }
        if (self::network_active()) {
            $network = (string) get_site_option(self::LOGIN_OPTION, '');
            if ('' !== $network) {
                return [ $network, 'network_default' ];
            }
        }
        return [ 'login', 'default' ];
    }

    private static function redirect_slug(): string
    {
        $own = (string) get_option(self::REDIRECT_OPTION, '');
        if ('' !== $own) {
            return $own;
        }
        if (self::network_active()) {
            $network = (string) get_site_option(self::REDIRECT_OPTION, '');
            if ('' !== $network) {
                return $network;
            }
        }
        return '404';
    }

    private static function network_active(): bool
    {
        if (! is_multisite()) {
            return false;
        }
        if (! function_exists('is_plugin_active_for_network')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        return is_plugin_active_for_network(self::PLUGIN_FILE);
    }

    /** The URL a slug gives, the way the plugin builds it (new_login_url()). */
    private static function url_for(string $slug): string
    {
        $home = (string) apply_filters('wps_hide_login_home_url', home_url('/')); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPS Hide Login's own filter, so the URL matches the one it serves.
        if ('' === (string) get_option('permalink_structure')) {
            return $home . '?' . $slug;
        }
        return user_trailingslashit($home . $slug);
    }

    // ---------------------------------------------------------------
    // Write checks
    // ---------------------------------------------------------------

    /** @return array{code: string, message: string, data: array}|null */
    private static function refusal_for(array $args): ?array
    {
        $login    = array_key_exists('login_slug', $args) ? (string) $args['login_slug'] : null;
        $redirect = array_key_exists('redirect_slug', $args) ? (string) $args['redirect_slug'] : null;
        if (null === $login && null === $redirect) {
            return self::refusal('nothing_to_change', 'Give login_slug, redirect_slug or both.');
        }

        if (null !== $login) {
            $refusal = self::login_slug_refusal($login);
            if (null !== $refusal) {
                return $refusal;
            }
        }
        if (null !== $redirect) {
            $clean = sanitize_title_with_dashes($redirect);
            if ('' === $redirect || $clean !== $redirect) {
                return self::refusal('redirect_slug_invalid', 'redirect_slug must be a lowercase slug of letters, digits and dashes.', [ 'suggestion' => $clean ]);
            }
        }

        [ $new_login, $new_redirect ] = self::target($args);
        if ($new_login === $new_redirect) {
            return self::refusal('redirect_slug_invalid', 'The redirect slug cannot be the login slug: logged-out visitors would be sent to the login screen they are blocked from.');
        }
        if ($new_login === self::login_slug()[0] && $new_redirect === self::redirect_slug()) {
            return self::refusal('no_change', 'The login and redirect slugs already have these values. Nothing was written and no undo point was taken.', [ 'login_url' => self::url_for($new_login) ]);
        }
        return null;
    }

    /** @return array{code: string, message: string, data: array}|null */
    private static function login_slug_refusal(string $slug): ?array
    {
        $clean = sanitize_title_with_dashes($slug);
        if ('' === $slug || $clean !== $slug) {
            return self::refusal('invalid_login_slug', 'login_slug must be a lowercase slug of letters, digits and dashes (WPS Hide Login would store it sanitized).', [ 'suggestion' => $clean ]);
        }

        $wp       = new \WP();
        $reserved = array_merge(self::RESERVED, [ rest_get_url_prefix() ], $wp->public_query_vars, $wp->private_query_vars);
        if (false !== strpos($slug, 'wp-login') || in_array($slug, $reserved, true)) {
            return self::refusal('login_slug_reserved', sprintf('"%s" is a reserved WordPress path or query var (the REST prefix the MCP connection uses is one of them), so the login screen would shadow it or never load. Choose another slug.', $slug));
        }

        $taken = get_posts([
            'name'             => $slug,
            'post_type'        => 'any',
            'post_status'      => 'any',
            'posts_per_page'   => 1,
            'fields'           => 'ids',
        ]);
        if ([] !== $taken) {
            return self::refusal('login_slug_collision', sprintf('"%s" is already the slug of post %d, which the login screen would hide. Choose another slug.', $slug, (int) $taken[0]), [ 'post_id' => (int) $taken[0] ]);
        }
        return null;
    }

    /** @return array{0: string, 1: string} the login and redirect slugs after the write. */
    private static function target(array $args): array
    {
        return [
            isset($args['login_slug']) ? (string) $args['login_slug'] : self::login_slug()[0],
            isset($args['redirect_slug']) ? (string) $args['redirect_slug'] : self::redirect_slug(),
        ];
    }

    private static function preview(array $args): array
    {
        [ $login, $redirect ] = self::target($args);

        return [
            'message' => sprintf(
                'The login screen will move from %1$s to %2$s; wp-login.php and wp-admin will send logged-out visitors to %3$s. Bookmark the new URL. The new URL and the REST API are checked right after the write and the old values come back if either fails.',
                self::url_for(self::login_slug()[0]),
                self::url_for($login),
                self::url_for($redirect)
            ),
            'data'    => [ 'login_url' => self::url_for($login), 'redirect_url' => self::url_for($redirect) ],
        ];
    }

    // ---------------------------------------------------------------
    // Write
    // ---------------------------------------------------------------

    private static function set(array $args, array $context): array
    {
        $before             = self::status();
        [ $login, $redirect ] = self::target($args);
        $none               = new \stdClass();
        $prior_login        = get_option(self::LOGIN_OPTION, $none);
        $prior_redirect     = get_option(self::REDIRECT_OPTION, $none);

        $restore = static function () use ($prior_login, $prior_redirect, $none): void {
            if ($prior_login === $none) {
                delete_option('whl_page');
            } else {
                update_option('whl_page', $prior_login);
            }
            if ($prior_redirect === $none) {
                delete_option('whl_redirect_admin');
            } else {
                update_option('whl_redirect_admin', $prior_redirect);
            }
        };
        // Anything that stops this request before the checks finish must not
        // leave the login screen at a URL nobody was told about.
        $committed = false;
        register_shutdown_function(static function () use (&$committed, $restore): void {
            if (! $committed) {
                $restore();
            }
        });

        $failed = null;
        $probes = [];
        try {
            if (isset($args['login_slug'])) {
                update_option('whl_page', $login);
            }
            if (isset($args['redirect_slug'])) {
                update_option('whl_redirect_admin', $redirect);
            }

            $probes = [
                'login'     => Site_Gate::probe(self::url_for($login), 3),
                'rest_root' => Site_Gate::probe(rest_url(), 3),
            ];
            if (self::failed($probes['login'])) {
                $failed = 'login_url_unreachable';
            } elseif (self::failed($probes['rest_root'])) {
                $failed = 'login_url_blocked_rest';
            }
            $committed = null === $failed;
        } finally {
            if (! $committed) {
                $restore();
            }
        }
        if (null !== $failed) {
            if (! empty($context['operation_id'])) {
                Snapshot_Store::delete_operation((string) $context['operation_id']);
            }
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- surfaced as a JSON tool error by Integration_Dispatcher, never rendered as HTML.
            throw new Operation_Refused($failed, sprintf('After the change, %s did not answer, so the old values were put back and nothing changed.', 'login_url_unreachable' === $failed ? 'the new login URL' : 'the REST API'), [ 'probes' => $probes ]);
        }

        $after = self::status();
        Governance_Audit_Log::record_quietly('wpmcp/theme-write', true, 'set-login-url: ' . $before['login_url'] . ' -> ' . $after['login_url']);

        return $after + [
            'previous' => [
                'login_slug'    => $before['login_slug'],
                'login_url'     => $before['login_url'],
                'redirect_slug' => $before['redirect_slug'],
            ],
            'probes'   => $probes,
            'note'     => 'Bookmark the new login URL: wp-login.php no longer opens the login screen. Purge page caches if a cached page links to the old one.',
            'undo'     => 'rollback-operation restores the previous login and redirect slugs exactly.',
        ];
    }

    /** Whether a probe that reached the site got a missing page or an error. */
    private static function failed(array $probe): bool
    {
        if (empty($probe['reachable'])) {
            return false;
        }
        $status = (int) ($probe['status'] ?? 0);
        return 404 === $status || $status >= 500;
    }

    /** @return array{code: string, message: string, data: array} */
    private static function refusal(string $code, string $message, array $data = []): array
    {
        return [ 'code' => $code, 'message' => $message, 'data' => $data ];
    }
}
