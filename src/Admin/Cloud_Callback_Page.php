<?php

namespace WPMCP\Admin;

use WPMCP\Cloud\Cloud_Config;
use WPMCP\Cloud\Cloud_Oauth;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The OAuth redirect target for the WP MCP Cloud connect flow (issue #135).
 *
 * Cloud_Oauth::redirect_uri() names admin.php?page=wpmcp-cloud-callback, so
 * something must actually answer there: the authorization server sends the
 * browser back with ?code=&state=, and this screen turns that into a sealed
 * token bundle by calling Cloud_Oauth::exchange().
 *
 * Registered as a hidden submenu (parent null) rather than a visible one: it is
 * a landing page for a redirect, not a destination an operator navigates to,
 * and a menu entry for it would only ever render the "no code" error.
 *
 * There is no nonce on the inbound request, and there cannot be: the caller is
 * the authorization server, which has never seen this site's nonces. The
 * defence is the OAuth `state` parameter, which is exactly the CSRF token for
 * this exchange -- generated in begin(), stored server-side, single-use, and
 * compared with hash_equals() inside exchange(). manage_options is still
 * required, because sealing a bundle changes how the whole site authenticates.
 */
class Cloud_Callback_Page
{
    public const SLUG = 'wpmcp-cloud-callback';

    public static function register(): void
    {
        add_submenu_page(
            '',
            'wpmcp: Cloud Connect',
            'Cloud Connect',
            'manage_options',
            self::SLUG,
            [new self(), 'render']
        );
    }

    public function render(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to complete a cloud connect.', 'wpmcp'));
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The OAuth state parameter is the CSRF token here; see the class docblock.
        $code = isset($_GET['code']) ? sanitize_text_field(wp_unslash($_GET['code'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Ditto.
        $state = isset($_GET['state']) ? sanitize_text_field(wp_unslash($_GET['state'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Ditto.
        $error = isset($_GET['error']) ? sanitize_text_field(wp_unslash($_GET['error'])) : '';

        echo '<div class="wrap"><h1>' . esc_html__('wpmcp: Cloud Connect', 'wpmcp') . '</h1>';

        $result = self::complete($code, $state, $error);

        if (is_wp_error($result)) {
            printf(
                '<div class="notice notice-error"><p>%s</p></div>',
                esc_html($result->get_error_message())
            );
        } else {
            printf(
                '<div class="notice notice-success"><p>%s</p></div>',
                esc_html(
                    sprintf(
                        /* translators: %s: cloud base URL. */
                        __('Connected to WP MCP Cloud at %s. The token bundle is sealed in this site database.', 'wpmcp'),
                        Cloud_Config::base_url()
                    )
                )
            );
        }

        printf(
            '<p><a href="%s">%s</a></p>',
            esc_url(admin_url('admin.php?page=wpmcp-connection')),
            esc_html__('Back to Connections', 'wpmcp')
        );
        echo '</div>';
    }

    /**
     * The exchange itself, split out so it is testable without rendering.
     *
     * @return true|\WP_Error
     */
    public static function complete(string $code, string $state, string $error = '')
    {
        if ('' !== $error) {
            return new \WP_Error('oauth_denied', 'WP MCP Cloud refused the connect: ' . $error);
        }
        if ('' === $code || '' === $state) {
            return new \WP_Error('oauth_missing_code', 'This page is the OAuth redirect target; it needs a code and state from WP MCP Cloud. Start over with cloud-connect.');
        }

        return Cloud_Oauth::exchange($code, $state);
    }
}
