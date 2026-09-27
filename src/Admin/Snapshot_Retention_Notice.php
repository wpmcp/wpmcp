<?php

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols -- ABSPATH guard is an intentional side effect.
// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps -- WP-style snake_case class name is intentional.

namespace WPMCP\Admin;

use WPMCP\Safety\Snapshot_Store;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The one-time notice behind the flat snapshot cap (issue #158).
 *
 * An install that arrives with more snapshots than the cap keeps them: the
 * retention floor in Snapshot_Store holds the existing depth until somebody
 * decides otherwise. This notice is how the site owner is told that a
 * decision is waiting, and is one of the two ways to make it (the other is
 * the wpmcp_snapshot_history_limit filter). Nothing is deleted until then,
 * which is the point: undo state is not something to discard as a side
 * effect of an update nobody watched.
 */
class Snapshot_Retention_Notice
{
    public const ACK_ACTION  = 'wpmcp_ack_snapshot_retention';
    public const NONCE_ACTION = 'wpmcp_ack_snapshot_retention';

    /** Both callbacks self-gate on capability, so registration is safe. */
    public static function register(): void
    {
        add_action('admin_notices', [self::class, 'render']);
        add_action('admin_post_' . self::ACK_ACTION, [self::class, 'handle_ack']);
    }

    /** admin_notices callback. Renders nothing unless a floor is standing. */
    public static function render(): void
    {
        if (! current_user_can('manage_options') || ! Snapshot_Store::has_retention_floor()) {
            return;
        }

        $depth = Snapshot_Store::ensure_retention_floor();
        $url   = wp_nonce_url(
            admin_url('admin-post.php?action=' . self::ACK_ACTION),
            self::NONCE_ACTION
        );

        printf(
            '<div class="notice notice-warning"><p>%s</p><p><code>%s</code></p><p><a class="button" href="%s">%s</a></p></div>',
            esc_html(sprintf(
                /* translators: 1: existing snapshot count, 2: the flat retention cap. */
                __('WP MCP now keeps the same number of snapshots on every install: %2$d. This site has %1$d, and they are being kept until you decide. Set the filter below to choose your own depth, or trim to %2$d now, which also deletes the file backups behind the older snapshots.', 'wpmcp'),
                $depth,
                Snapshot_Store::DEFAULT_HISTORY_LIMIT
            )),
            esc_html("add_filter( 'wpmcp_snapshot_history_limit', fn() => {$depth} );"),
            esc_url($url),
            esc_html(sprintf(
                /* translators: %d: the flat retention cap. */
                __('Trim history to %d operations', 'wpmcp'),
                Snapshot_Store::DEFAULT_HISTORY_LIMIT
            ))
        );
    }

    /** admin_post callback: capability + nonce, then release the floor. */
    public static function handle_ack(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to change snapshot retention.', 'wpmcp'), '', ['response' => 403]);
        }
        check_admin_referer(self::NONCE_ACTION);

        Snapshot_Store::acknowledge_retention_floor();

        wp_safe_redirect(wp_get_referer() ?: admin_url());
        exit;
    }
}
