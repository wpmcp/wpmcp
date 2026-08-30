<?php

namespace WPMCP\Pro\Chat;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * In-admin AI chat screen (issue #73, PRO).
 *
 * Lives under src/Pro because the whole chat feature is part of the
 * off-directory add-on: the WordPress.org build does not contain this screen
 * and never registers its submenu, so there is no locked state and no
 * pay-to-unlock copy anywhere in that build (guideline 5). The screen itself
 * therefore carries no tier branch: Plugin::register_admin_menu only adds it
 * where the feature can actually run.
 *
 * The page holds no capability of its own: every action the chat performs
 * goes through the chat REST controller, which re-checks manage_options and
 * Pro\Gate per request, and every tool call will run through the same
 * governed ability path as external MCP calls.
 */
class Chat_Page
{
    public const SLUG = 'wpmcp-chat';

    public function render(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Sorry, you are not allowed to access this page.', 'wpmcp'));
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('wpmcp: Chat', 'wpmcp') . '</h1>';

        // TODO(#73): enqueue the chat client bundle and render the key
        // management form (backed by /wpmcp/v1/chat/key). This slice ships
        // the mount point and the governed REST surface only.
        echo '<div id="wpmcp-chat-root" data-rest-namespace="wpmcp/v1">';
        echo '<p>' . esc_html__('Chat client not yet available in this build.', 'wpmcp') . '</p>';
        echo '</div>';
        echo '</div>';
    }
}
