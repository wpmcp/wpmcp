<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Limit Login Attempts Reloaded status (issue #695): local or cloud mode,
 * the lockout policy, and counts of active lockouts, IPs with recent failed
 * attempts, lockouts so far and allow and deny list entries, read from the
 * plugin's limit_login_* options (site options when it runs network wide,
 * the way its own Config::get() reads them), falling back to its defaults.
 *
 * The lockout, retry, log and list options are keyed by IP address and user
 * name, so they are only ever counted. The cloud app config (its key and
 * endpoint) and the notification addresses are never read.
 */
final class Limit_Login_Attempts_Status
{
    /** The plugin's own defaults (core/Config.php) for the options read here. */
    private const DEFAULTS = [
        'allowed_retries'  => 4,
        'lockout_duration' => 1200,
        'allowed_lockouts' => 4,
        'long_duration'    => 86400,
        'valid_duration'   => 86400,
    ];

    /** @return array<string,array<string,mixed>> */
    public static function operations(): array
    {
        return [
            'get-limit-login-attempts-status' => Ops_Status_Packs::read_op(
                'Limit Login Attempts Reloaded status: local or cloud mode, lockout policy, counts of active lockouts, IPs with recent failures, total lockouts and allow and deny list entries. No IPs or user names',
                static fn () => Ops_Status_Packs::presence(self::is_active(), 'limit_login_attempts_inactive', 'Limit Login Attempts Reloaded'),
                static fn (): array => self::status()
            ),
        ];
    }

    /** Whether Limit Login Attempts Reloaded is loaded, filterable through wpmcp_limit_login_attempts_active. */
    public static function is_active(): bool
    {
        return (bool) apply_filters('wpmcp_limit_login_attempts_active', defined('LLA_PLUGIN_DIR'));
    }

    /** @return array<string,mixed> */
    private static function status(): array
    {
        $now = time();

        return [
            'mode'                     => 'custom' === self::option('active_app', 'local') ? 'cloud' : 'local',
            'policy'                   => [
                'allowed_retries'      => self::int_option('allowed_retries'),
                'lockout_minutes'      => (int) round(self::int_option('lockout_duration') / 60),
                'lockouts_before_long' => self::int_option('allowed_lockouts'),
                'long_lockout_hours'   => (int) round(self::int_option('long_duration') / 3600),
                'retries_reset_hours'  => (int) round(self::int_option('valid_duration') / 3600),
            ],
            'active_lockouts'          => self::count_future(self::option('lockouts', []), $now),
            'ips_with_recent_failures' => self::count_future(self::option('retries_valid', []), $now),
            'lockouts_total'           => (int) self::option('lockouts_total', 0),
            'allowlist'                => [
                'ips'       => self::count_list(self::option('whitelist', [])),
                'usernames' => self::count_list(self::option('whitelist_usernames', [])),
            ],
            'denylist'                 => [
                'ips'       => self::count_list(self::option('blacklist', [])),
                'usernames' => self::count_list(self::option('blacklist_usernames', [])),
            ],
            'gdpr'                     => ! empty(self::option('gdpr', 0)),
            'mfa_enabled'              => ! empty(self::option('mfa_enabled', 0)),
        ];
    }

    /**
     * One limit_login_* option, from the site options when the plugin runs
     * network wide without local options (its Helpers::use_local_options()).
     *
     * @param mixed $fallback
     * @return mixed
     */
    private static function option(string $name, $fallback)
    {
        $helpers = '\\LLAR\\Core\\Helpers';
        $local   = ! class_exists($helpers) || ! is_callable([ $helpers, 'use_local_options' ]) || (bool) call_user_func([ $helpers, 'use_local_options' ]);
        $value   = $local ? get_option('limit_login_' . $name, null) : get_site_option('limit_login_' . $name, null);

        return null === $value ? $fallback : $value;
    }

    private static function int_option(string $name): int
    {
        $value = self::option($name, self::DEFAULTS[ $name ]);
        return is_numeric($value) ? (int) $value : self::DEFAULTS[ $name ];
    }

    /** Entries of an IP => expiry map that have not expired yet. */
    private static function count_future($map, int $now): int
    {
        $count = 0;
        foreach (is_array($map) ? $map : [] as $until) {
            if (is_numeric($until) && (int) $until > $now) {
                ++$count;
            }
        }
        return $count;
    }

    private static function count_list($list): int
    {
        return is_array($list) ? count(array_filter($list, static fn ($item): bool => '' !== trim((string) (is_scalar($item) ? $item : '')))) : 0;
    }
}
