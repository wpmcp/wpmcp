<?php

namespace WPMCP\Integrations;

use WPMCP\Maintenance\Site_Gate;
use WPMCP\Safety\Snapshot;
use WPMCP\Safety\Snapshot_Store;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Coming soon and maintenance gates (issue #903) as free ops on the theme
 * dispatcher pair, so they add no top-level tool.
 *
 * Reads: get-site-gate-status answers the question get-maintenance-status
 * could not, from every mechanism at once (WP MCP's own flag, WordPress
 * core's .maintenance, SeedProd, WP Maintenance Mode, Under Construction,
 * Maintenance and CMP): which one is closing the site, what a visitor gets
 * (the live probe asks the site itself), and who bypasses it. The reading
 * itself lives in WPMCP\Maintenance\Site_Gate.
 *
 * Writes, off until the site opts in through the wpmcp_enable_site_gate_write
 * filter: enable-site-gate and disable-site-gate for SeedProd (its
 * seedprod_settings JSON flag), Under Construction (its ucp_options status),
 * WP Maintenance Mode, also sold as LightStart (the integer
 * wpmm_settings.general.status, the same write its own lightstart/set-mode
 * ability makes; from 2.6.23 the plugin itself publishes its page and swaps
 * the front page while on, and puts both back when off), Maintenance
 * (mtnc-options.options.status) and CMP (niteoCS_status, plus
 * niteoCS_activation when a mode is asked for). None of the five gates the
 * REST API: they hook template_redirect or template_include, which a REST
 * request never reaches (CMP can close anonymous REST, never a logged-in
 * client), and all five leave wp-login.php alone; the probe below checks it.
 *
 * The risk of a toggle is the agent closing the site on itself, so enabling:
 *  - refuses unless the acting user is bypassed by the plugin's own setting
 *    (SeedProd access by role on the page, Under Construction whitelisted
 *    roles and users, WP Maintenance Mode super admins and listed roles,
 *    Maintenance's logged-in bypass, CMP roles plus administrator), naming
 *    what to change;
 *  - refuses while another gate is already active;
 *  - needs confirm:true, with a preview of what visitors will get;
 *  - probes the site after the write (the front page, wp-login.php and the
 *    REST root) and puts the settings back at once, voiding the undo point,
 *    when the login screen or the REST API answers 503.
 * Every write is one option_set snapshot of the plugin's settings option, so
 * rollback-operation restores it. Disabling never needs confirm. Licence
 * keys, bypass phrases, passwords and IP lists are neither read out nor
 * written.
 */
final class Site_Gate_Pack
{
    public const ENABLE_FILTER = 'wpmcp_enable_site_gate_write';

    /** Plugin slug => the options its gate lives in; the plugins this pack can toggle. */
    private const WRITABLE = [
        'coming-soon'                 => [ 'seedprod_settings' ],
        'under-construction-page'     => [ 'ucp_options' ],
        'wp-maintenance-mode'         => [ 'wpmm_settings' ],
        'maintenance'                 => [ 'mtnc-options' ],
        'cmp-coming-soon-maintenance' => [ 'niteoCS_status', 'niteoCS_activation' ],
    ];

    private const LABELS = [
        'coming-soon'                 => 'SeedProd',
        'under-construction-page'     => 'Under Construction',
        'wp-maintenance-mode'         => 'WP Maintenance Mode (LightStart)',
        'maintenance'                 => 'Maintenance',
        'cmp-coming-soon-maintenance' => 'CMP',
    ];

    /** WP Maintenance Mode restores its page and front page itself from this version on. */
    private const WPMM_MIN_VERSION = '2.6.23';

    /** CMP's activation option: mode => stored value. */
    private const CMP_MODES = [ 'maintenance' => '1', 'coming_soon' => '2' ];

    /** @return array<string, array<string, mixed>> op name => definition for the theme pair. */
    public static function operations(): array
    {
        $enabled = (bool) apply_filters('wpmcp_enable_site_gate_write', false);
        $plugin  = [ 'type' => 'string', 'enum' => array_merge([ 'auto' ], array_keys(self::WRITABLE)) ];

        return [
            'get-site-gate-status'  => [
                'mode'         => 'read',
                'capability'   => 'manage_options',
                'description'  => 'Whether the public site is gated and by what: WP MCP maintenance mode, WordPress core .maintenance, and the SeedProd, WP Maintenance Mode, Under Construction, Maintenance and CMP plugins. Per gate: active, mode (coming soon or maintenance), the HTTP status and message visitors get, and who bypasses it (roles, counts, whether a bypass link or password is set; the secrets themselves are never returned). public_site_gated comes from a live request to the home page as an anonymous visitor, with the evidence in basis and probe',
                'input_schema' => [ 'type' => 'object', 'properties' => [] ],
                'handler'      => static fn (): array => Site_Gate::status(true),
            ],
            'enable-site-gate'      => [
                'mode'               => 'destructive',
                'capability'         => 'manage_options',
                'description'        => 'Close the public site with SeedProd or CMP (mode coming_soon or maintenance), Under Construction, WP Maintenance Mode (LightStart) or Maintenance. Refuses when another gate is already active, when the plugin would also lock out you (your role is not bypassed) for SeedProd without its page set up, and for a countdown that already ended. Needs confirm:true; the preview says what visitors will get and who bypasses it. After the write it probes wp-login.php and the REST root and restores the settings itself if either answers 503. Snapshotted; undo: rollback-operation. Disabled by default (wpmcp_enable_site_gate_write filter)',
                'enabled_by_default' => $enabled,
                'opt_in_filter'      => self::ENABLE_FILTER,
                'input_schema'       => [
                    'type'       => 'object',
                    'properties' => [
                        'plugin' => $plugin,
                        'mode'   => [ 'type' => 'string', 'enum' => [ 'coming_soon', 'maintenance' ] ],
                    ],
                ],
                'validate'           => static fn (array $args): ?array => self::enable_refusal($args),
                'confirm_preview'    => static fn (array $args): array => self::preview($args),
                'snapshot'           => static fn (array $args): ?array => self::target($args),
                'handler'            => static fn (array $args, array $context = []): array => self::enable($args, $context),
            ],
            'disable-site-gate'     => [
                'mode'               => 'write',
                'capability'         => 'manage_options',
                'description'        => 'Open the public site again by turning off the SeedProd, Under Construction, WP Maintenance Mode, Maintenance or CMP gate (auto picks the one that is active). Refuses when none is active. Snapshotted; undo: rollback-operation. Disabled by default (wpmcp_enable_site_gate_write filter)',
                'enabled_by_default' => $enabled,
                'opt_in_filter'      => self::ENABLE_FILTER,
                'input_schema'       => [ 'type' => 'object', 'properties' => [ 'plugin' => $plugin ] ],
                'validate'           => static fn (array $args): ?array => self::disable_refusal($args),
                'snapshot'           => static fn (array $args): ?array => self::target($args, true),
                'handler'            => static fn (array $args): array => self::disable($args),
            ],
        ];
    }

    // ---------------------------------------------------------------
    // Which plugin
    // ---------------------------------------------------------------

    /**
     * @return array{0: ?string, 1: ?array} the plugin slug, or a refusal.
     */
    private static function resolve(array $args, bool $disabling): array
    {
        $named = (string) ($args['plugin'] ?? 'auto');
        if ('auto' !== $named) {
            if (! Site_Gate::plugin_active($named)) {
                return [ null, Plugin_Refusal::make('site_gate_plugin_inactive', self::LABELS[ $named ] ?? $named) ];
            }
            return [ $named, null ];
        }

        $active = array_values(array_filter(array_keys(self::WRITABLE), static fn (string $slug): bool => Site_Gate::plugin_active($slug)));
        if ($disabling) {
            $on = array_values(array_filter($active, static fn (string $slug): bool => true === (Site_Gate::plugin_gate($slug)['active'] ?? false)));
            $active = [] !== $on ? $on : $active;
        }
        if ([] === $active) {
            return [ null, Plugin_Refusal::any_of('site_gate_plugin_inactive', 'None of ' . implode(', ', self::LABELS) . ' is active on this site. Other gates (WP MCP, WordPress core) can be read with get-site-gate-status but not toggled here.') ];
        }
        if (count($active) > 1) {
            return [ null, self::refusal('plugin_required', 'More than one gate plugin is active; name one with plugin.', [ 'plugins' => $active ]) ];
        }
        return [ $active[0], null ];
    }

    private static function enable_refusal(array $args): ?array
    {
        [ $slug, $refusal ] = self::resolve($args, false);
        if (null !== $refusal) {
            return $refusal;
        }
        $gate = Site_Gate::plugin_gate($slug);
        if (true === ($gate['active'] ?? false)) {
            return self::refusal('no_change', sprintf('%s is already closing the site (%s). Nothing was written and no undo point was taken.', self::LABELS[ $slug ], $gate['mode']));
        }
        $others = array_values(array_diff(Site_Gate::status(false)['active_gates'], [ Site_Gate::source_for($slug) ]));
        if ([] !== $others) {
            return self::refusal('gate_already_active', 'Another gate is already closing the site: ' . implode(', ', $others) . '. Turn it off first (disable-maintenance for wpmcp, the plugin\'s own settings for the others).', [ 'active_gates' => $others ]);
        }

        switch ($slug) {
            case 'coming-soon':
                return self::seedprod_enable_refusal(self::mode($args));
            case 'wp-maintenance-mode':
                return self::wpmm_enable_refusal();
            case 'maintenance':
                return self::maintenance_enable_refusal();
            case 'cmp-coming-soon-maintenance':
                return self::cmp_enable_refusal();
        }
        return self::ucp_enable_refusal();
    }

    /** @return array<string,mixed> */
    private static function wpmm_settings(): array
    {
        $settings = get_option('wpmm_settings');
        return is_array($settings) ? $settings : [];
    }

    private static function wpmm_enable_refusal(): ?array
    {
        $version = (string) get_option('wpmm_version', '0');
        if (version_compare($version, self::WPMM_MIN_VERSION, '<')) {
            return self::refusal('plugin_version_unsupported', sprintf('WP Maintenance Mode %1$s cannot be toggled here: before %2$s it left its page published and the front page changed after maintenance mode was turned off. Update the plugin first.', $version, self::WPMM_MIN_VERSION), [ 'version' => $version, 'minimum' => self::WPMM_MIN_VERSION ]);
        }
        // The plugin lets super admins through, then the listed roles: the
        // front end checks frontend_role, wp-admin checks backend_role.
        if (is_super_admin()) {
            return null;
        }
        $general = (array) (self::wpmm_settings()['general'] ?? []);
        $roles   = (array) wp_get_current_user()->roles;
        $extra   = is_multisite() ? [ 'administrator' ] : [];
        $front   = array_merge(array_map('strval', (array) ($general['frontend_role'] ?? [])), $extra);
        $back    = array_merge(array_map('strval', (array) ($general['backend_role'] ?? [])), $extra);
        if ([] !== array_intersect($roles, $front) && [] !== array_intersect($roles, $back)) {
            return null;
        }
        return self::refusal('admin_not_bypassed', 'WP Maintenance Mode would lock you out: your role is not in both its frontend and backend roles. Add it to both in its settings first.', [ 'roles' => array_values($roles) ]);
    }

    /** @return array<string,mixed> the plugin's settings under its "options" key. */
    private static function maintenance_options(): array
    {
        $stored = get_option('mtnc-options');
        return is_array($stored) && isset($stored['options']) && is_array($stored['options']) ? $stored['options'] : [];
    }

    private static function maintenance_enable_refusal(): ?array
    {
        if ('1' === (string) (self::maintenance_options()['show_logged_in'] ?? '')) {
            return null;
        }
        return self::refusal('admin_not_bypassed', 'Maintenance would show its page to logged-in users too, you included. Turn on its "Logged in users see the normal website" setting first.');
    }

    private static function cmp_enable_refusal(): ?array
    {
        if ('1' === (string) get_option('niteoCS_counter', '1') && 'disable-cmp' === (string) get_option('niteoCS_countdown_action', 'no-action')) {
            $date = absint(get_option('niteoCS_counter_date'));
            if ($date > 0 && $date <= time()) {
                return self::refusal('end_date_passed', 'CMP\'s countdown has already ended and is set to turn CMP off, so enabling it would close nothing. Change the countdown in its settings first.', [ 'counter_date' => gmdate('Y-m-d\TH:i:s\Z', $date) ]);
            }
        }
        $roles = json_decode((string) get_option('niteoCS_roles', '[]'), true);
        $roles = array_merge(is_array($roles) ? array_map('strval', $roles) : [], [ 'administrator' ]);
        if ([] !== array_intersect((array) wp_get_current_user()->roles, $roles)) {
            return null;
        }
        return self::refusal('admin_not_bypassed', 'CMP would lock you out: your role is not in its bypass roles (' . implode(', ', array_unique($roles)) . '). Add it in CMP\'s settings first.', [ 'roles' => array_values(array_unique($roles)) ]);
    }

    private static function seedprod_enable_refusal(string $mode): ?array
    {
        $page_id = (int) get_option('coming_soon' === $mode ? 'seedprod_coming_soon_page_id' : 'seedprod_maintenance_mode_page_id');
        $page    = $page_id > 0 ? get_post($page_id) : null;
        if (! $page instanceof \WP_Post) {
            return self::refusal('seedprod_page_missing', sprintf('SeedProd has no %s page set up, so visitors would get an error screen. Create and choose it in SeedProd first.', str_replace('_', ' ', $mode)), [ 'mode' => $mode ]);
        }
        $json  = json_decode((string) $page->post_content_filtered, true);
        $roles = is_array($json) ? array_values(array_map('strval', (array) ($json['access_by_role'] ?? []))) : [];
        if ([] === $roles) {
            return null; // Every logged-in user bypasses.
        }
        foreach ($roles as $role) {
            $role = str_replace(' ', '', strtolower($role));
            if ('anyoneloggedin' === $role || current_user_can($role)) {
                return null;
            }
        }
        return self::refusal('admin_not_bypassed', 'SeedProd would lock you out: the page lets only these roles through: ' . implode(', ', $roles) . '. Add administrator to its Access by role in SeedProd first.', [ 'roles' => $roles ]);
    }

    private static function ucp_enable_refusal(): ?array
    {
        $options = get_option('ucp_options');
        $options = is_array($options) ? $options : [];
        $end     = (string) ($options['end_date'] ?? '');
        if (16 === strlen($end) && '0000-00-00 00:00' !== $end && $end < current_time('mysql')) {
            return self::refusal('end_date_passed', 'Under Construction has an end date in the past, so enabling it would close nothing. Change the date in its settings first.', [ 'end_date' => $end ]);
        }
        $roles = isset($options['whitelisted_roles']) ? (array) $options['whitelisted_roles'] : [ 'administrator' ];
        $user  = wp_get_current_user();
        $first = ! empty($user->roles) ? (string) $user->roles[0] : 'guest';
        if (in_array($first, $roles, true) || in_array((int) $user->ID, array_map('intval', (array) ($options['whitelisted_users'] ?? [])), true)) {
            return null;
        }
        return self::refusal('admin_not_bypassed', sprintf('Under Construction would lock you out: your role (%s) is not whitelisted. Add it to its whitelisted roles first.', $first), [ 'roles' => array_values(array_map('strval', $roles)) ]);
    }

    private static function disable_refusal(array $args): ?array
    {
        [ $slug, $refusal ] = self::resolve($args, true);
        if (null !== $refusal) {
            return $refusal;
        }
        if (true !== (Site_Gate::plugin_gate($slug)['active'] ?? false)) {
            return self::refusal('no_change', sprintf('%s is not closing the site. Nothing was written and no undo point was taken.', self::LABELS[ $slug ]));
        }
        return null;
    }

    private static function mode(array $args): string
    {
        return 'maintenance' === ($args['mode'] ?? '') ? 'maintenance' : 'coming_soon';
    }

    /** The mode the gate will be in once enabled: asked for where the plugin has two, else its own. */
    private static function target_mode(string $slug, array $args): string
    {
        if ('coming-soon' === $slug) {
            return self::mode($args);
        }
        if ('cmp-coming-soon-maintenance' === $slug && isset($args['mode'])) {
            return self::mode($args);
        }
        return (string) (Site_Gate::plugin_gate($slug)['mode'] ?? 'coming_soon');
    }

    private static function preview(array $args): array
    {
        [ $slug ] = self::resolve($args, false);
        $mode     = self::target_mode($slug, $args);
        $bypass   = [
            'coming-soon'                 => 'every logged-in user, or the roles on the page',
            'under-construction-page'     => 'the whitelisted roles and users',
            'wp-maintenance-mode'         => 'super administrators and the roles listed in its settings',
            'maintenance'                 => 'every logged-in user',
            'cmp-coming-soon-maintenance' => 'administrators and the roles listed in its settings',
        ][ $slug ];
        $status   = 'maintenance' === $mode ? '503' : '200';
        if ('maintenance' === $slug && empty(self::maintenance_options()['blockse'])) {
            $status = '200';
        }

        return [
            'message' => sprintf(
                'The public site will answer with the %1$s %2$s page (%3$s); %4$s keep seeing the normal site, and wp-login.php and the REST API stay reachable, checked right after the write.',
                self::LABELS[ $slug ],
                str_replace('_', ' ', $mode),
                $status,
                $bypass
            ),
            'data'    => [ 'plugin' => $slug, 'mode' => $mode, 'bypass' => $bypass ],
        ];
    }

    private static function target(array $args, bool $disabling = false): ?array
    {
        [ $slug ] = self::resolve($args, $disabling);
        if (null === $slug) {
            return null;
        }
        return [ 'object_type' => 'option_set', 'object_id' => Snapshot::option_set_id(self::WRITABLE[ $slug ]) ];
    }

    // ---------------------------------------------------------------
    // Writes
    // ---------------------------------------------------------------

    private static function enable(array $args, array $context): array
    {
        [ $slug ] = self::resolve($args, false);
        $mode     = self::target_mode($slug, $args);
        $none     = new \stdClass();
        $prior    = [];
        foreach (self::WRITABLE[ $slug ] as $option) {
            $prior[ $option ] = get_option($option, $none);
        }

        $restore = static function () use ($prior, $none): void {
            foreach ($prior as $option => $value) {
                if ($value === $none) {
                    delete_option($option);
                } else {
                    update_option($option, $value);
                }
            }
        };
        // Anything that stops this request before the checks finish (an error,
        // a fatal, the time limit) must not leave the site closed.
        $committed = false;
        $shutdown  = static function () use (&$committed, $restore): void {
            if (! $committed) {
                $restore();
            }
        };
        register_shutdown_function($shutdown);

        $blocked = null;
        $probes  = [];
        try {
            self::write($slug, true, $mode, isset($args['mode']));

            // Short timeouts: the three requests together stay under ten seconds.
            $probes = [
                'front_page' => Site_Gate::probe(home_url('/'), 3),
                'login'      => Site_Gate::probe(wp_login_url(), 3),
                'rest_root'  => Site_Gate::probe(rest_url(), 3),
            ];
            if (! empty($probes['login']['reachable']) && in_array((int) $probes['login']['status'], [ 401, 403, 503 ], true)) {
                $blocked = 'gate_blocked_login';
            } elseif (! empty($probes['rest_root']['reachable']) && 503 === (int) $probes['rest_root']['status']) {
                $blocked = 'gate_blocked_rest';
            }
            $committed = null === $blocked;
        } finally {
            if (! $committed) {
                $restore();
            }
        }
        if (null !== $blocked) {
            if (! empty($context['operation_id'])) {
                Snapshot_Store::delete_operation((string) $context['operation_id']);
            }
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- surfaced as a JSON tool error by Integration_Dispatcher, never rendered as HTML.
            throw new Operation_Refused($blocked, sprintf('After closing the site, %s answered 503, so the settings were put back and nothing changed.', 'gate_blocked_login' === $blocked ? 'wp-login.php' : 'the REST API'), [ 'probes' => $probes ]);
        }

        $out = [
            'plugin'     => $slug,
            'enabled'    => true,
            'gate'       => Site_Gate::plugin_gate($slug),
            'probes'     => $probes,
            'cache_hint' => 'Purge page caches so visitors see the change (the cache settings ops on this pair).',
            'undo'       => 'rollback-operation restores the plugin settings exactly; disable-site-gate opens the site again.',
        ];
        if ('wp-maintenance-mode' === $slug) {
            $out['note'] = 'WP Maintenance Mode publishes its selected page and points the front page at it on its next page load, and puts both back when it is turned off.';
        }
        if ('cmp-coming-soon-maintenance' === $slug && '1' !== (string) get_option('niteoCS_rest_api_status', '1')) {
            $out['note'] = 'CMP closes the REST API to anonymous requests while it is on (its REST API setting); signed-in clients, this one included, are not affected.';
        }
        if ('coming-soon' === $slug) {
            $out['note'] = 'SeedProd gates on its settings flag alone (it reads the landing page row whatever its status) and re-syncs the page to published or draft on its own next settings save; the page status is not changed here.';
        }
        if (! empty($probes['front_page']['reachable']) && 'maintenance' === $mode && 503 !== (int) $probes['front_page']['status']) {
            $out['warning'] = 'The home page did not answer 503 to an anonymous request; a page cache or CDN may still be serving the old page.';
        }

        return $out;
    }

    private static function disable(array $args): array
    {
        [ $slug ] = self::resolve($args, true);
        self::write($slug, false, 'coming_soon', false);

        return [
            'plugin'     => $slug,
            'enabled'    => false,
            'gate'       => Site_Gate::plugin_gate($slug),
            'cache_hint' => 'Purge page caches so visitors see the open site.',
            'undo'       => 'rollback-operation closes the site again exactly as it was.',
        ];
    }

    /** Store the flag in the plugin's own format, keeping every other setting. */
    private static function write(string $slug, bool $on, string $mode, bool $mode_asked): void
    {
        switch ($slug) {
            case 'coming-soon':
                $settings = Site_Gate::seedprod_settings();
                $settings['enable_coming_soon_mode'] = $on && 'coming_soon' === $mode;
                $settings['enable_maintenance_mode'] = $on && 'maintenance' === $mode;
                update_option('seedprod_settings', wp_json_encode($settings));
                return;
            case 'wp-maintenance-mode':
                // The plugin tests the status with === 1, so it stays an integer.
                $settings            = self::wpmm_settings();
                $general             = (array) ($settings['general'] ?? []);
                $general['status']   = $on ? 1 : 0;
                if ($on) {
                    $general['status_date'] = gmdate('Y-m-d H:i:s');
                }
                $settings['general'] = $general;
                update_option('wpmm_settings', $settings);
                return;
            case 'maintenance':
                $stored = get_option('mtnc-options');
                $stored = is_array($stored) ? $stored : [];
                $stored['options'] = self::maintenance_options();
                $stored['options']['status'] = $on ? '1' : '0';
                update_option('mtnc-options', $stored);
                return;
            case 'cmp-coming-soon-maintenance':
                update_option('niteoCS_status', $on ? '1' : '0');
                if ($on && $mode_asked) {
                    update_option('niteoCS_activation', self::CMP_MODES[ $mode ]);
                }
                return;
        }
        $options = get_option('ucp_options');
        $options = is_array($options) ? $options : [];
        $options['status'] = $on ? '1' : '0';
        update_option('ucp_options', $options);
    }

    /** @return array{code: string, message: string, data: array} */
    private static function refusal(string $code, string $message, array $data = []): array
    {
        return [ 'code' => $code, 'message' => $message, 'data' => $data ];
    }
}
