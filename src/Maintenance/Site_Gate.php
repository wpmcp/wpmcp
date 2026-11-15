<?php

namespace WPMCP\Maintenance;

use WPMCP\Tools\Backup\Restore_Site_Backup;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The real state of the site's front door (issue #903): which mechanism, if
 * any, is turning visitors away, what they get, and who walks past it.
 *
 * A site can be gated by WP MCP's own maintenance option, by WordPress core's
 * .maintenance upgrade flag, or by one of the coming soon and maintenance
 * plugins (SeedProd, WP Maintenance Mode, Under Construction, Maintenance,
 * CMP). Reading only the first, as get-maintenance-status did, answers "off"
 * on a site that a plugin has closed. This class reads all of them, each from
 * the plugin's own stored settings (formats verified against the free
 * plugins from wordpress.org), and can ask the site itself what an anonymous
 * visitor receives.
 *
 * Reads only, and allowlisted: a bypass phrase, an IP address, a password, a
 * licence key or a subscriber list is never returned, only a count or a
 * boolean saying one is set. Kept outside src/Integrations so the core
 * maintenance tool can use it on every build.
 */
final class Site_Gate
{
    /** Plugin slug => main file, for the plugins this class reads. */
    public const PLUGINS = [
        'coming-soon'                   => 'coming-soon/coming-soon.php',
        'wp-maintenance-mode'           => 'wp-maintenance-mode/wp-maintenance-mode.php',
        'under-construction-page'       => 'under-construction-page/under-construction.php',
        'maintenance'                   => 'maintenance/maintenance.php',
        'cmp-coming-soon-maintenance'   => 'cmp-coming-soon-maintenance/niteo-cmp.php',
    ];

    /** Source name each plugin answers with. */
    private const SOURCES = [
        'coming-soon'                 => 'seedprod',
        'wp-maintenance-mode'         => 'wpmm',
        'under-construction-page'     => 'ucp',
        'maintenance'                 => 'maintenance',
        'cmp-coming-soon-maintenance' => 'cmp',
    ];

    /** The core flag goes stale after ten minutes (wp_is_maintenance_mode). */
    private const CORE_STALE_AFTER = 600;

    /**
     * @return array<string,mixed>
     */
    public static function status(bool $probe = true): array
    {
        $gates = [ self::wpmcp_gate(), self::core_gate() ];
        foreach (array_keys(self::PLUGINS) as $slug) {
            if (self::plugin_active($slug)) {
                $gates[] = self::plugin_gate($slug);
            }
        }

        $active  = array_values(array_filter($gates, static fn (array $g): bool => true === $g['active']));
        $result  = [
            'public_site_gated' => [] !== $active,
            'basis'             => [] !== $active ? 'active_gate' : 'none',
            'active_gates'      => array_column($active, 'source'),
            'gates'             => $gates,
        ];
        if ([] !== $active && count($active) > 1) {
            $result['warning'] = 'More than one gate is active; turn off the ones you do not want.';
        }
        if (! $probe) {
            return $result;
        }

        $seen            = self::probe(home_url('/'));
        $result['probe'] = $seen;
        if (! $seen['reachable']) {
            $result['basis'] = [] !== $active ? 'active_gate_unverified' : 'probe_failed';
            return $result;
        }
        $code = (int) $seen['status'];
        if (503 === $code) {
            $result['public_site_gated'] = true;
            $result['basis']             = 'probe_503';
        } elseif (in_array($code, [ 401, 403 ], true)) {
            $result['public_site_gated'] = true;
            $result['basis']             = 'probe_' . $code;
        } elseif ([] !== $active) {
            $result['basis'] = 'active_gate';
        } else {
            $result['public_site_gated'] = false;
            $result['basis']             = 'probe_' . $code;
        }
        $result['cache_hint'] = 'A page cache or CDN may still serve the previous state; purge it after changing a gate.';

        return $result;
    }

    /**
     * What an anonymous visitor gets for a URL: one request, no cookies, no
     * redirects followed.
     *
     * @return array{reachable: bool, status?: int, retry_after?: string|null, location?: string|null, error?: string}
     */
    public static function probe(string $url): array
    {
        $response = wp_remote_get($url, [
            'timeout'     => 8,
            'redirection' => 0,
            'cookies'     => [],
            'sslverify'   => (bool) apply_filters('https_local_ssl_verify', false), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own loopback filter, the one wp_remote_get() loopbacks use.
            'headers'     => [ 'Cache-Control' => 'no-cache' ],
        ]);
        if (is_wp_error($response)) {
            return [ 'reachable' => false, 'error' => $response->get_error_code() ];
        }
        $retry    = wp_remote_retrieve_header($response, 'retry-after');
        $location = wp_remote_retrieve_header($response, 'location');

        return [
            'reachable'   => true,
            'status'      => (int) wp_remote_retrieve_response_code($response),
            'retry_after' => '' !== (string) $retry ? (string) $retry : null,
            'location'    => '' !== (string) $location ? (string) $location : null,
        ];
    }

    public static function plugin_active(string $slug): bool
    {
        if (! isset(self::PLUGINS[ $slug ])) {
            return false;
        }
        if (! function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $active = is_plugin_active(self::PLUGINS[ $slug ]);

        return (bool) apply_filters('wpmcp_site_gate_plugin_active', $active, $slug);
    }

    /** One plugin's gate, or null for a slug this class does not read. */
    public static function plugin_gate(string $slug): ?array
    {
        switch ($slug) {
            case 'coming-soon':
                return self::seedprod_gate();
            case 'wp-maintenance-mode':
                return self::wpmm_gate();
            case 'under-construction-page':
                return self::ucp_gate();
            case 'maintenance':
                return self::maintenance_gate();
            case 'cmp-coming-soon-maintenance':
                return self::cmp_gate();
        }
        return null;
    }

    public static function source_for(string $slug): string
    {
        return self::SOURCES[ $slug ] ?? $slug;
    }

    // ---------------------------------------------------------------
    // WP MCP and WordPress core
    // ---------------------------------------------------------------

    private static function wpmcp_gate(): array
    {
        $option  = get_option('wpmcp_maintenance');
        $own     = is_array($option) && ! empty($option['enabled']);
        $restore = Restore_Site_Backup::is_running();

        return self::gate('wpmcp', $own || $restore, 'maintenance', [
            'http_status'  => 503,
            'retry_after'  => $own ? (int) ($option['retry_after'] ?? 0) : null,
            'headline'     => $own ? (string) ($option['message'] ?? '') : null,
            'bypass'       => [ 'roles' => [], 'capability' => 'manage_options', 'logged_in_users' => 'only users with manage_options' ],
            'visitors_see' => 'A 503 with a plain text message (WP MCP maintenance mode' . ($restore && ! $own ? ', a database restore is running' : '') . ').',
        ]);
    }

    private static function core_gate(): array
    {
        $file = ABSPATH . '.maintenance';
        if (! file_exists($file)) {
            return self::gate('wp_core', false, 'maintenance', []);
        }
        // Read as text, never included: the file is code WordPress runs, not data.
        $raw       = (string) file_get_contents($file);
        $upgrading = 1 === preg_match('/\$upgrading\s*=\s*(\d+)/', $raw, $m) ? (int) $m[1] : 0;
        $fresh     = (time() - $upgrading) < self::CORE_STALE_AFTER;
        $active    = (bool) apply_filters('enable_maintenance_mode', $fresh, $upgrading); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own filter, applied by wp_is_maintenance_mode() to the same flag.

        return self::gate('wp_core', $active, 'maintenance', [
            'http_status'  => 503,
            'stale'        => ! $fresh,
            'since'        => $upgrading > 0 ? gmdate('Y-m-d\TH:i:s\Z', $upgrading) : null,
            'bypass'       => [ 'roles' => [], 'logged_in_users' => 'nobody: the flag stops every request, administrators and REST included' ],
            'visitors_see' => 'WordPress core "Briefly unavailable for scheduled maintenance" (503) until the .maintenance file is removed or ten minutes pass.',
        ]);
    }

    // ---------------------------------------------------------------
    // Plugins
    // ---------------------------------------------------------------

    /** SeedProd keeps its flags in a JSON string option; the page JSON holds who bypasses. */
    private static function seedprod_gate(): array
    {
        $settings = self::seedprod_settings();
        $mode     = ! empty($settings['enable_coming_soon_mode']) ? 'coming_soon' : (! empty($settings['enable_maintenance_mode']) ? 'maintenance' : 'coming_soon');
        $active   = ! empty($settings['enable_coming_soon_mode']) || ! empty($settings['enable_maintenance_mode']);
        $page_id  = (int) get_option('coming_soon' === $mode ? 'seedprod_coming_soon_page_id' : 'seedprod_maintenance_mode_page_id');
        $page     = $page_id > 0 ? get_post($page_id) : null;
        $json     = $page instanceof \WP_Post ? json_decode((string) $page->post_content_filtered, true) : null;
        $json     = is_array($json) ? $json : [];
        $roles    = array_values(array_map('strval', (array) ($json['access_by_role'] ?? [])));
        $redirect = ! empty($json['redirect_mode']) && ! empty($json['redirect_url']);

        return self::gate('seedprod', $active, $mode, [
            'page_id'      => $page_id > 0 ? $page_id : null,
            'page_status'  => $page instanceof \WP_Post ? $page->post_status : null,
            'http_status'  => 'maintenance' === $mode ? 503 : ($redirect ? 302 : 200),
            'retry_after'  => 'maintenance' === $mode ? 86400 : null,
            'headline'     => $page instanceof \WP_Post ? get_the_title($page) : null,
            'bypass'       => [
                'roles'           => $roles,
                'logged_in_users' => [] === $roles ? 'every logged-in user' : 'only the listed roles',
                'bypass_link_set' => ! empty($json['bypass_phrase']),
            ],
            'visitors_see' => $redirect ? 'A redirect to the address set on the SeedProd page.' : 'The SeedProd landing page' . ('maintenance' === $mode ? ' with a 503.' : ' with a 200.'),
        ]);
    }

    /** @return array<string,mixed> */
    public static function seedprod_settings(): array
    {
        $raw = get_option('seedprod_settings');
        if (is_array($raw)) {
            return $raw;
        }
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    private static function wpmm_gate(): array
    {
        $settings = get_option('wpmm_settings');
        $settings = is_array($settings) ? $settings : [];
        $general  = (array) ($settings['general'] ?? []);
        $design   = (array) ($settings['design'] ?? []);
        $page_id  = (int) ($design['page_id'] ?? 0);

        return self::gate('wpmm', 1 === (int) ($general['status'] ?? 0), 'maintenance', [
            'page_id'      => $page_id > 0 ? $page_id : null,
            'http_status'  => 503,
            'headline'     => isset($design['heading']) ? wp_strip_all_tags((string) $design['heading']) : null,
            'bypass'       => [
                'roles'           => array_values(array_map('strval', (array) ($general['frontend_role'] ?? []))),
                'backend_roles'   => array_values(array_map('strval', (array) ($general['backend_role'] ?? []))),
                'logged_in_users' => 'super administrators and the listed roles',
                'excluded_urls'   => count((array) ($general['exclude'] ?? [])),
                'search_bots'     => ! empty($general['bypass_bots']),
            ],
            'visitors_see' => 'The WP Maintenance Mode page with a 503 and a Retry-After. Enabling it with a design page also publishes that page and sets the front page.',
        ]);
    }

    private static function ucp_gate(): array
    {
        $options  = get_option('ucp_options');
        $options  = is_array($options) ? $options : [];
        $end      = (string) ($options['end_date'] ?? '');
        $ended    = 16 === strlen($end) && '0000-00-00 00:00' !== $end && $end < current_time('mysql');
        $roles    = isset($options['whitelisted_roles']) ? (array) $options['whitelisted_roles'] : [ 'administrator' ];
        $on       = ! empty($options['status']) && '0' !== (string) $options['status'];

        return self::gate('ucp', $on && ! $ended, 'coming_soon', [
            'http_status'  => 200,
            'headline'     => isset($options['heading1']) ? wp_strip_all_tags((string) $options['heading1']) : null,
            'ended'        => $on && $ended,
            'bypass'       => [
                'roles'           => array_values(array_map('strval', $roles)),
                'users'           => count((array) ($options['whitelisted_users'] ?? [])),
                'logged_in_users' => 'only users whose first role is listed, and listed users',
            ],
            'visitors_see' => 'The Under Construction page with a 200 status.',
        ]);
    }

    private static function maintenance_gate(): array
    {
        $stored  = get_option('mtnc-options');
        $options = is_array($stored) && isset($stored['options']) && is_array($stored['options']) ? $stored['options'] : [];
        $ips     = array_filter(array_map('trim', explode("\n", (string) ($options['ip_whitelist'] ?? ''))));

        return self::gate('maintenance', '1' === (string) ($options['status'] ?? '0'), 'maintenance', [
            'http_status'  => ! empty($options['blockse']) ? 503 : 200,
            'headline'     => isset($options['header_text']) ? wp_strip_all_tags((string) $options['header_text']) : null,
            'bypass'       => [
                'roles'           => [],
                'logged_in_users' => '1' === (string) ($options['show_logged_in'] ?? '') ? 'every logged-in user' : 'nobody: logged-in users see the page too',
                'ip_count'        => count($ips),
                'bypass_link_set' => ! empty($options['direct_access_link']),
                'password_set'    => ! empty($options['site_password']),
            ],
            'visitors_see' => 'The Maintenance plugin page' . (! empty($options['blockse']) ? ' with a 503.' : ' with a 200 (its 503 option is off). The free plugin does not exempt the REST API.'),
        ]);
    }

    private static function cmp_gate(): array
    {
        $on   = '0' !== (string) get_option('niteoCS_status', '0');
        $mode = (string) get_option('niteoCS_activation', '2');
        $roles = json_decode((string) get_option('niteoCS_roles', '[]'), true);
        $roles = is_array($roles) ? $roles : [];

        return self::gate('cmp', $on, '1' === $mode ? 'maintenance' : 'coming_soon', [
            'http_status'  => '1' === $mode ? 503 : ('3' === $mode ? 302 : 200),
            'retry_after'  => '1' === $mode ? 86400 : null,
            'bypass'       => [
                'roles'           => array_values(array_unique(array_merge(array_map('strval', $roles), [ 'administrator' ]))),
                'logged_in_users' => 'only users whose role is listed (administrators always)',
            ],
            'rest_api'     => '1' === (string) get_option('niteoCS_rest_api_status', '1') ? 'open' : 'closed to anonymous requests',
            'visitors_see' => '1' === $mode ? 'The CMP page with a 503.' : ('3' === $mode ? 'A CMP redirect.' : 'The CMP coming soon page with a 200.'),
        ]);
    }

    /**
     * @param array<string,mixed> $fields
     * @return array<string,mixed>
     */
    private static function gate(string $source, bool $active, string $mode, array $fields): array
    {
        return array_merge([ 'source' => $source, 'active' => $active, 'mode' => $mode ], $fields);
    }
}
