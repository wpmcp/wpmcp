<?php

namespace WPMCP\Admin;

use WPMCP\Pro\Gate;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * In-admin AI chat screen (issue #73, PRO).
 *
 * Renders the mount point for the chat client. The page itself holds no
 * capability of its own: every action the chat performs goes through the
 * chat REST controller, which re-checks manage_options and Pro\Gate per
 * request, and every tool call runs through the same governed ability path
 * as external MCP calls.
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

        if (! Gate::is_pro()) {
            echo '<p>' . esc_html__('The in-admin AI chat is a PRO feature.', 'wpmcp') . '</p>';
            echo '</div>';
            return;
        }

        // TODO(#73): enqueue the chat client bundle and render the key
        // management form (backed by /wpmcp/v1/chat/key). This slice ships
        // the mount point and gating only.
        echo '<div id="wpmcp-chat-root" data-rest-namespace="wpmcp/v1">';
        echo '<p>' . esc_html__('Chat client not yet available in this build.', 'wpmcp') . '</p>';
        echo '</div>';
        echo '</div>';
    }
}
