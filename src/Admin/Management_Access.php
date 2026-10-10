<?php

namespace WPMCP\Admin;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Who may manage WP MCP (issue #479): its screens, its admin-post and AJAX
 * handlers, its admin REST routes and its options.php groups.
 *
 * Every one of those entry points checks self::CAPABILITY, a capability no
 * role stores. It is computed per request: a user holds it when they hold
 * manage_options AND the management policy admits their id. The policy is
 *  - every administrator (the default, and what an empty stored list means);
 *  - an allowlist of administrator ids saved on the Connection screen; or
 *  - the WPMCP_MANAGEMENT_ADMINS constant ('all', '1,5' or [1, 5]), which
 *    overrides the stored list without erasing it. A constant that does not
 *    parse closes management entirely until it is fixed.
 *
 * Agent execution is deliberately outside this policy: abilities keep
 * their own capability checks, identities, scopes and governance, and the
 * MCP endpoint and the OAuth flow are never gated here. This decides only
 * who manages the plugin.
 */
final class Management_Access
{
    public const CAPABILITY = 'wpmcp_manage';
    public const OPTION     = 'wpmcp_management_admins';
    public const CONSTANT   = 'WPMCP_MANAGEMENT_ADMINS';

    /**
     * Admin-post hooks WP MCP registers that are not plugin management: the
     * OAuth consent screen, where any user approves a connection to their
     * own account. The entry point test fails on any other unguarded one.
     */
    public const UNGUARDED_HOOKS = [
        'admin_post_' . OAuth_Consent_Page::ACTION,
        'admin_post_nopriv_' . OAuth_Consent_Page::ACTION,
    ];

    /** options.php groups whose saves need the management capability. */
    private const SETTINGS_GROUPS = [
        Handshake_Settings_Page::GROUP,
        Skills_Settings_Page::GROUP,
    ];

    private static bool $constant_overridden = false;
    private static bool $constant_defined_for_tests = false;
    /** @var mixed */
    private static $constant_for_tests = null;

    public static function register(): void
    {
        add_filter('user_has_cap', [self::class, 'filter_user_has_cap'], 10, 4);
        add_filter('map_meta_cap', [self::class, 'filter_map_meta_cap'], 10, 4);
        foreach (self::SETTINGS_GROUPS as $group) {
            add_filter("option_page_capability_{$group}", [self::class, 'capability']);
        }
        if (defined('WP_CLI') && WP_CLI && class_exists('\WP_CLI')) {
            \WP_CLI::add_command('wpmcp management-access reset', [self::class, 'cli_reset']);
        }
    }

    /** The capability every management entry point checks. */
    public static function capability(): string
    {
        return self::CAPABILITY;
    }

    /**
     * Register an admin-post or AJAX handler behind the management check.
     * Every WP MCP admin handler goes through here; the entry point test
     * fails on one that does not.
     */
    public static function add_handler(string $hook, callable $handler): void
    {
        add_action($hook, new Guarded_Handler($handler));
    }

    public static function refusal_message(): string
    {
        return __('Sorry, you are not allowed to manage WP MCP on this site.', 'wpmcp');
    }

    /**
     * user_has_cap: grant the management capability to a manage_options
     * holder the policy admits, and strip it from everyone else, so a role
     * editor storing it on a role or user changes nothing.
     *
     * @param array<string, bool> $allcaps
     * @param mixed               $caps
     * @param mixed               $args
     * @param \WP_User|null       $user
     * @return array<string, bool>
     */
    public static function filter_user_has_cap($allcaps, $caps = [], $args = [], $user = null): array
    {
        $allcaps = is_array($allcaps) ? $allcaps : [];
        // Resolved only when it is being checked: this filter runs on every
        // capability check on the site.
        $checked = is_array($caps) && in_array(self::CAPABILITY, $caps, true);
        $user_id = $user instanceof \WP_User ? (int) $user->ID : 0;

        $allcaps[ self::CAPABILITY ] = $checked && ! empty($allcaps['manage_options']) && self::allows($user_id);

        return $allcaps;
    }

    /**
     * map_meta_cap: a super admin passes every primitive check without
     * user_has_cap being consulted, so a refusal has to be do_not_allow.
     *
     * @param mixed $caps
     * @param mixed $cap
     * @param mixed $user_id
     * @param mixed $args
     * @return array<int, string>
     */
    public static function filter_map_meta_cap($caps, $cap = '', $user_id = 0, $args = []): array
    {
        $caps = is_array($caps) ? $caps : [];
        if (in_array(self::CAPABILITY, $caps, true) && ! self::allows((int) $user_id)) {
            return ['do_not_allow'];
        }

        return $caps;
    }

    /** Whether $user_id may manage WP MCP. Null means the current user. */
    public static function can_manage(?int $user_id = null): bool
    {
        $user_id = null === $user_id ? get_current_user_id() : $user_id;

        return $user_id > 0 && user_can($user_id, self::CAPABILITY);
    }

    /** Whether the policy admits $user_id. Says nothing about their role. */
    public static function allows(int $user_id): bool
    {
        if ($user_id <= 0) {
            return false;
        }
        $policy = self::policy();

        return 'all' === $policy['mode']
            || ('list' === $policy['mode'] && in_array($user_id, $policy['ids'], true));
    }

    /**
     * The policy in force.
     *
     * @return array{source: string, mode: string, ids: int[]}
     */
    public static function policy(): array
    {
        if (self::constant_defined()) {
            $parsed = self::parse_constant(self::constant_value());
            return ['source' => 'constant'] + ($parsed ?? ['mode' => 'closed', 'ids' => []]);
        }

        $stored = get_option(self::OPTION, []);
        if (false === $stored || '' === $stored || [] === $stored) {
            return ['source' => 'default', 'mode' => 'all', 'ids' => []];
        }
        $ids = is_array($stored) ? self::parse_ids($stored) : null;
        if (null === $ids) {
            return ['source' => 'option', 'mode' => 'closed', 'ids' => []];
        }

        return ['source' => 'option', 'mode' => 'list', 'ids' => $ids];
    }

    /**
     * Parse the constant. 'all' (any case), a comma-separated list of
     * positive ids, a positive int, or a non-empty array of positive ids.
     * Anything else returns null, which closes management.
     *
     * @param mixed $value
     * @return array{mode: string, ids: int[]}|null
     */
    public static function parse_constant($value): ?array
    {
        if (is_string($value) && 'all' === strtolower(trim($value))) {
            return ['mode' => 'all', 'ids' => []];
        }
        if (is_string($value)) {
            $value = explode(',', $value);
        } elseif (is_int($value)) {
            $value = [$value];
        }
        if (! is_array($value)) {
            return null;
        }
        $ids = self::parse_ids($value);

        return null === $ids ? null : ['mode' => 'list', 'ids' => $ids];
    }

    /**
     * Strict id list: every entry a positive int or a string of digits, at
     * least one entry. Null when any entry is anything else.
     *
     * @param array<mixed> $values
     * @return int[]|null
     */
    private static function parse_ids(array $values): ?array
    {
        $ids = [];
        foreach ($values as $value) {
            if (is_string($value)) {
                $value = trim($value);
                if (! preg_match('/^[0-9]+$/', $value)) {
                    return null;
                }
                $value = (int) $value;
            }
            if (! is_int($value) || $value <= 0) {
                return null;
            }
            $ids[] = $value;
        }

        return [] === $ids ? null : array_values(array_unique($ids));
    }

    /** Token for the stored value, so a form rendered before a change is refused. */
    public static function version(): string
    {
        return md5((string) wp_json_encode(get_option(self::OPTION, [])));
    }

    /**
     * Save the stored policy. $mode 'all' clears the allowlist; 'list'
     * stores $ids, which must all be administrators and include $actor_id.
     *
     * @param array<mixed> $ids
     * @return true|\WP_Error
     */
    public static function save(string $mode, array $ids, int $actor_id, string $version)
    {
        if (self::constant_defined()) {
            return self::constant_error();
        }
        if (! hash_equals(self::version(), $version)) {
            return new \WP_Error(
                'wpmcp_management_stale',
                __('The management access policy changed since this page was loaded. Reload the page and try again.', 'wpmcp')
            );
        }

        if ('all' === $mode) {
            delete_option(self::OPTION);
            return true;
        }
        if ('list' !== $mode) {
            return new \WP_Error('wpmcp_management_invalid', __('Unknown management access mode.', 'wpmcp'));
        }

        $parsed = self::parse_ids($ids);
        if (null === $parsed) {
            return new \WP_Error('wpmcp_management_invalid', __('Choose at least one administrator who can manage WP MCP.', 'wpmcp'));
        }
        foreach ($parsed as $id) {
            if (! self::is_administrator($id)) {
                return new \WP_Error(
                    'wpmcp_management_not_admin',
                    /* translators: %d: user id. */
                    sprintf(__('User %d is not an administrator of this site.', 'wpmcp'), $id)
                );
            }
        }
        if (! in_array($actor_id, $parsed, true)) {
            return new \WP_Error(
                'wpmcp_management_self_lockout',
                __('You cannot remove yourself from the administrators who manage WP MCP. Ask another listed administrator to do it.', 'wpmcp')
            );
        }

        update_option(self::OPTION, $parsed, false);

        return true;
    }

    /**
     * Recovery: back to every administrator. Refused while the constant is
     * defined, because the constant would still win and wp-config.php is
     * where that policy has to be changed.
     *
     * @return true|\WP_Error
     */
    public static function reset()
    {
        if (self::constant_defined()) {
            return self::constant_error();
        }
        delete_option(self::OPTION);

        return true;
    }

    /** `wp wpmcp management-access reset`: let every administrator manage WP MCP again. */
    public static function cli_reset(array $args = [], array $assoc = []): void
    {
        $result = self::reset();
        if (is_wp_error($result)) {
            \WP_CLI::error($result->get_error_message());
            return;
        }
        \WP_CLI::success('Every administrator can manage WP MCP again.');
    }

    /**
     * Listed ids that are not administrators of this site, for the
     * read-only view of the constant.
     *
     * @return int[]
     */
    public static function non_admin_ids(): array
    {
        return array_values(array_filter(
            self::policy()['ids'],
            static fn (int $id): bool => ! self::is_administrator($id)
        ));
    }

    /** Administrators of this site, the people an allowlist can name. */
    public static function administrators(): array
    {
        return get_users(['capability' => 'manage_options', 'orderby' => 'display_name']);
    }

    private static function is_administrator(int $id): bool
    {
        return false !== get_userdata($id) && user_can($id, 'manage_options');
    }

    private static function constant_error(): \WP_Error
    {
        return new \WP_Error(
            'wpmcp_management_constant',
            /* translators: %s: constant name. */
            sprintf(__('Management access is set by %s in wp-config.php. Change or remove it there.', 'wpmcp'), self::CONSTANT)
        );
    }

    public static function constant_defined(): bool
    {
        if (self::$constant_overridden) {
            return self::$constant_defined_for_tests;
        }

        return defined(self::CONSTANT);
    }

    /** @return mixed */
    private static function constant_value()
    {
        if (self::$constant_overridden) {
            return self::$constant_for_tests;
        }

        return constant(self::CONSTANT);
    }

    /**
     * Test-only: act as if the constant were (or were not) defined with
     * $value. A PHP constant cannot be undefined once set, so tests that
     * need several values use this instead. Ignored outside WPMCP_TESTING.
     *
     * @param mixed $value
     */
    public static function set_constant_for_tests(bool $defined, $value = null): void
    {
        if (! defined('WPMCP_TESTING') || ! WPMCP_TESTING) {
            return;
        }
        self::$constant_overridden        = true;
        self::$constant_defined_for_tests = $defined;
        self::$constant_for_tests         = $value;
    }
}
