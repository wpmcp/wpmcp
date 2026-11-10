<?php

namespace WPMCP\Governance;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Who may execute code on a multisite network (issue #562).
 *
 * run-php-snippet, activate-php-snippet, run-wp-cli and dispatch-cli-job are
 * gated by manage_options plus one opt-in (WPMCP_ALLOW_PHP_EXEC /
 * WPMCP_ALLOW_WP_CLI or their filters). On a single site that is the site
 * owner. On a network the opt-in is set once for every site, and every
 * sub-site administrator holds manage_options on its own blog, so opening
 * the opt-in would hand each of them code execution on the shared codebase
 * and database: network takeover.
 *
 * So on multisite these surfaces also require a network administrator
 * (manage_network_options, which core gives super admins), whatever the
 * opt-in says. A network that really wants its sub-site administrators to
 * run code says so separately and explicitly with the
 * wpmcp_allow_subsite_code_exec filter, which receives the surface ('php' or
 * 'wp-cli'), the user id and the blog id, and defaults to false. Single-site
 * installs are unaffected.
 *
 * The check takes a user id rather than reading the current user so the
 * WP-Cron runner of a queued CLI job can ask about the user who queued it:
 * cron runs with nobody signed in.
 */
final class Code_Exec_Network_Gate
{
    public const FILTER = 'wpmcp_allow_subsite_code_exec';

    public const CAPABILITY = 'manage_network_options';

    /** Whether $user_id may use the $surface execution tools on this site. */
    public static function allows(string $surface, int $user_id): bool
    {
        if (! is_multisite()) {
            return true;
        }

        // Nobody signed in cannot be a network administrator, and a grant
        // to sub-site administrators is a grant to administrators, not to
        // anonymous callers, so the filter is not consulted for user 0.
        if ($user_id <= 0) {
            return false;
        }

        if (user_can($user_id, self::CAPABILITY)) {
            return true;
        }

        return (bool) apply_filters('wpmcp_allow_subsite_code_exec', false, $surface, $user_id, get_current_blog_id());
    }

    /** Whether the signed-in user may use the $surface execution tools. */
    public static function allows_current_user(string $surface): bool
    {
        return self::allows($surface, get_current_user_id());
    }

    /**
     * Refuse when $user_id (the current user when null) may not use the
     * $surface execution tools here.
     *
     * @throws \RuntimeException
     */
    public static function assert_allowed(string $surface, string $label, ?int $user_id = null): void
    {
        $user_id = null === $user_id ? get_current_user_id() : $user_id;
        if (self::allows($surface, $user_id)) {
            return;
        }

        throw new \RuntimeException(
            sprintf(
                '%s on a multisite network needs a network administrator (manage_network_options), whatever the opt-in: a sub-site administrator could otherwise run code across every site. A network can grant sub-site administrators access with the %s filter.',
                esc_html($label),
                esc_html(self::FILTER)
            )
        );
    }
}
