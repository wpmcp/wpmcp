<?php

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols -- ABSPATH guard is an intentional side effect.
// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps -- WP-style snake_case class name is intentional.

namespace WPMCP\Admin;

use WPMCP\Governance\New_Tools_Policy;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The Dashboard notice after an update that added tools (issue #853): lists
 * each tool the defaults seeder handled and the state it arrived in, with a
 * link to the Abilities screen, until an administrator dismisses it.
 */
class New_Tools_Notice
{
    public const DISMISS_ACTION = 'wpmcp_dismiss_new_tools';
    public const NONCE_ACTION   = 'wpmcp_dismiss_new_tools';

    /** Both callbacks self-gate on capability, so registration is safe. */
    public static function register(): void
    {
        add_action('admin_notices', [self::class, 'render']);
        Management_Access::add_handler('admin_post_' . self::DISMISS_ACTION, [self::class, 'handle_dismiss']);
    }

    /** admin_notices callback. */
    public static function render(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        self::render_for_screen($screen instanceof \WP_Screen ? (string) $screen->id : '');
    }

    /** Renders the notice on the Dashboard only, and only when an update added tools. */
    public static function render_for_screen(string $screen_id): void
    {
        if ('dashboard' !== $screen_id || ! current_user_can(Management_Access::CAPABILITY)) {
            return;
        }
        $added = New_Tools_Policy::last_added();
        if (null === $added) {
            return;
        }

        $labels = [
            'on'         => __('switched on', 'wpmcp'),
            'off'        => __('off until you switch it on', 'wpmcp'),
            'never_auto' => __('off, never switched on automatically', 'wpmcp'),
            'kept'       => __('kept as you set it', 'wpmcp'),
        ];

        $dismiss = wp_nonce_url(admin_url('admin-post.php?action=' . self::DISMISS_ACTION), self::NONCE_ACTION);
        $screen  = admin_url('admin.php?page=' . Ability_Grid_Page::SLUG);

        echo '<div class="notice notice-info"><p>' . esc_html__('This WP MCP update added new tools:', 'wpmcp') . '</p>';
        echo '<ul style="list-style: disc; padding-left: 2em;">';
        foreach ($added['tools'] as $name => $state) {
            printf(
                '<li><code>%s</code>: %s</li>',
                esc_html((string) $name),
                esc_html($labels[ $state ] ?? (string) $state)
            );
        }
        printf(
            '</ul><p><a class="button" href="%s">%s</a> <a href="%s">%s</a></p></div>',
            esc_url($screen),
            esc_html__('Review on the Abilities screen', 'wpmcp'),
            esc_url($dismiss),
            esc_html__('Dismiss', 'wpmcp')
        );
    }

    /** admin_post callback: capability + nonce, then forget the list. */
    public static function handle_dismiss(): void
    {
        if (! current_user_can(Management_Access::CAPABILITY)) {
            wp_die(esc_html__('You are not allowed to change WP MCP settings.', 'wpmcp'), '', ['response' => 403]);
        }
        check_admin_referer(self::NONCE_ACTION);

        New_Tools_Policy::dismiss_notice();

        wp_safe_redirect(wp_get_referer() ?: admin_url());
        exit;
    }
}
