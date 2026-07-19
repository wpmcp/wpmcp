<?php

namespace WPMCP\Connect;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The master MCP exposure switch (issue #76): one option that, when off,
 * kills the entire MCP surface instantly.
 *
 * Enforcement rides the EXISTING governance layer rather than adding a new
 * one: register() hooks the wpmcp_ability_enabled filter (layer 2 of
 * Governance's AND-of-narrowing chain), so a disabled switch makes
 * Governance::is_ability_enabled() return false for every ability. Because
 * Registrar's permission_callback re-evaluates that check on every request,
 * already-registered abilities start denying on the very next call — no
 * cache flush or re-registration needed — and every denial lands in the
 * governance audit log exactly like any other governance decision. Being a
 * narrowing layer, the switch can only take away: turning it ON never
 * re-enables an ability some other governance layer disabled.
 *
 * The switch's state is surfaced in the admin bar (admins only) so an
 * operator always knows whether the site is agent-reachable.
 */
class Exposure
{
    public const OPTION = 'wpmcp_mcp_exposure';

    public static function is_enabled(): bool
    {
        return '0' !== (string) get_option(self::OPTION, '1');
    }

    public static function set_enabled(bool $enabled): void
    {
        update_option(self::OPTION, $enabled ? '1' : '0');
    }

    /** Hooked from Plugin::boot(). */
    public static function register(): void
    {
        add_filter('wpmcp_ability_enabled', [self::class, 'filter_ability_enabled']);
        add_action('admin_bar_menu', [self::class, 'admin_bar'], 100);
    }

    /**
     * Governance layer-2 narrowing: pass the incoming decision through
     * unchanged when exposure is on, force false when it is off.
     *
     * @param mixed $enabled The decision so far.
     */
    public static function filter_ability_enabled($enabled): bool
    {
        return (bool) $enabled && self::is_enabled();
    }

    /**
     * Admin-bar indicator, linked to the Connection screen. Shown only to
     * users who could act on it (manage_options, matching the screen).
     *
     * @param \WP_Admin_Bar $wp_admin_bar
     */
    public static function admin_bar($wp_admin_bar): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $on = self::is_enabled();
        $wp_admin_bar->add_node([
            'id'    => 'wpmcp-exposure',
            'title' => $on
                ? esc_html__('MCP: On', 'wpmcp')
                : esc_html__('MCP: Off', 'wpmcp'),
            'href'  => admin_url('admin.php?page=wpmcp-connection'),
            'meta'  => [
                'title' => $on
                    ? __('wpmcp: the MCP surface is exposed. Click to manage connections.', 'wpmcp')
                    : __('wpmcp: the MCP surface is disabled. Click to manage connections.', 'wpmcp'),
            ],
        ]);
    }
}
