<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * MonsterInsights status (issue #300): whether the site is connected to
 * Google Analytics, and how tracking is configured, read from the plugin's
 * own monsterinsights_site_profile and monsterinsights_settings options.
 *
 * The connection is reported as a mode (authenticated, manual or none), the
 * GA4 measurement id (already public in every tracked page) and the view
 * name. The profile's site key, token, site hash, account ids and
 * measurement protocol secret are never returned. Tracking settings come
 * from an allowlist, so license keys and report email addresses stay out.
 */
final class MonsterInsights_Status
{
    /** Tracking settings reported as booleans. */
    private const FLAGS = [
        'demographics', 'anonymize_ips', 'link_attribution', 'enhanced_link_attribution',
        'userid', 'allow_anchor', 'add_allow_linker', 'tag_links_in_rss', 'enable_affiliate_links',
    ];

    /** Tracking settings reported as strings. */
    private const STRINGS = [ 'tracking_mode', 'events_mode', 'extensions_of_files', 'subdomain_tracking' ];

    /** @return array<string,array<string,mixed>> */
    public static function operations(): array
    {
        return [
            'get-monsterinsights-status' => Ops_Status_Packs::read_op(
                'MonsterInsights status: Google Analytics connection (mode, measurement id, view name) and tracking settings. No tokens',
                static fn () => Ops_Status_Packs::presence(self::is_active(), 'monsterinsights_inactive', 'MonsterInsights'),
                static fn (): array => self::status()
            ),
        ];
    }

    /** Whether MonsterInsights is loaded, filterable through wpmcp_monsterinsights_active. */
    public static function is_active(): bool
    {
        return (bool) apply_filters('wpmcp_monsterinsights_active', defined('MONSTERINSIGHTS_VERSION'));
    }

    /** @return array<string,mixed> */
    private static function status(): array
    {
        return [
            'connection'        => self::connection(),
            'tracking'          => self::tracking(),
            'tracking_disabled' => defined('MONSTERINSIGHTS_DISABLE_TRACKING') && (bool) constant('MONSTERINSIGHTS_DISABLE_TRACKING'),
        ];
    }

    /** @return array{connected:bool,mode:string,measurement_id:string|null,view_name:string|null} */
    private static function connection(): array
    {
        $profile = get_option('monsterinsights_site_profile', []);
        $profile = is_array($profile) ? $profile : [];

        // MonsterInsights_Auth::is_authed(): a site key and a GA4 id.
        $authed = ! empty($profile['key']) && null !== self::measurement_id($profile['v4'] ?? null);
        $manual = self::measurement_id($profile['manual_v4'] ?? null);

        if ($authed) {
            $mode = 'authenticated';
            $id   = self::measurement_id($profile['v4']);
        } else {
            $mode = null === $manual ? 'none' : 'manual';
            $id   = $manual;
        }

        $view = isset($profile['viewname']) && is_string($profile['viewname']) ? sanitize_text_field($profile['viewname']) : '';

        return [
            'connected'      => $authed,
            'mode'           => $mode,
            'measurement_id' => $id,
            'view_name'      => '' === $view ? null : $view,
        ];
    }

    /** A GA4 measurement id (G-XXXXXXXX), or null for anything else. */
    private static function measurement_id($value): ?string
    {
        return is_string($value) && 1 === preg_match('/^G-[A-Z0-9]{4,20}$/i', $value) ? strtoupper($value) : null;
    }

    /** @return array<string,mixed> */
    private static function tracking(): array
    {
        $settings = get_option('monsterinsights_settings', []);
        if (! is_array($settings)) {
            return [];
        }

        $out = [];
        foreach (self::STRINGS as $key) {
            if (isset($settings[ $key ]) && is_scalar($settings[ $key ])) {
                $out[ $key ] = sanitize_text_field((string) $settings[ $key ]);
            }
        }
        foreach (self::FLAGS as $key) {
            if (array_key_exists($key, $settings)) {
                $out[ $key ] = ! empty($settings[ $key ]) && 'false' !== $settings[ $key ];
            }
        }
        if (isset($settings['ignore_users']) && is_array($settings['ignore_users'])) {
            $out['ignore_users'] = array_values(array_filter(array_map(
                static fn ($role): string => is_string($role) ? sanitize_key($role) : '',
                $settings['ignore_users']
            )));
        }
        if (isset($settings['cross_domains']) && is_array($settings['cross_domains'])) {
            $out['cross_domains'] = array_values(array_filter(array_map(
                static fn ($row): string => is_array($row) && is_string($row['domain'] ?? null) ? sanitize_text_field($row['domain']) : '',
                $settings['cross_domains']
            )));
        }

        return $out;
    }
}
