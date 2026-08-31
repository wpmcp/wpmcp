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
 * This slice renders the provider-key management the /chat/key routes already
 * back, so the menu entry does something the day it appears: a menu item whose
 * only content is "not available yet" is a dead entry with extra steps. The
 * conversation view arrives with the executor slice.
 *
 * The page holds no capability of its own: every action the chat performs
 * goes through the chat REST controller, which re-checks manage_options and
 * Pro\Gate per request, and every tool call will run through the same
 * governed ability path as external MCP calls. The form below is not an
 * alternate write path for the same reason: it posts to the same route with
 * the same nonce, and the key never round-trips back to the browser.
 */
class Chat_Page
{
    public const SLUG = 'wpmcp-chat';

    /**
     * Human-readable line for each Key_Vault status. salt_rotated and
     * corrupted are separate states on purpose: the first is a routine
     * wp_salt('auth') rotation and the fix is to re-enter the key, the second
     * means the stored ciphertext failed its authentication tag.
     */
    private function status_line(string $status): string
    {
        return match ($status) {
            'valid'              => __('A provider key is stored for your account.', 'wpmcp'),
            'salt_rotated'       => __(
                'The site salts changed since this key was stored, so it can no longer be decrypted. Enter it again.',
                'wpmcp'
            ),
            'corrupted'          => __(
                'The stored key failed its integrity check and was not accepted. Enter it again.',
                'wpmcp'
            ),
            'cipher_unavailable' => __(
                'This host does not support aes-256-gcm, so keys cannot be stored encrypted here.',
                'wpmcp'
            ),
            default              => __('No provider key is stored for your account yet.', 'wpmcp'),
        };
    }

    public function render(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Sorry, you are not allowed to access this page.', 'wpmcp'));
        }

        try {
            $status = (new Key_Vault())->get_status(get_current_user_id());
        } catch (\RuntimeException) {
            $status = ['configured' => false, 'status' => 'cipher_unavailable', 'masked' => null];
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('wpmcp: Chat', 'wpmcp') . '</h1>';

        echo '<h2>' . esc_html__('Provider key', 'wpmcp') . '</h2>';
        echo '<p>' . esc_html($this->status_line((string) ($status['status'] ?? 'missing')));
        if (! empty($status['masked'])) {
            echo ' <code>' . esc_html((string) $status['masked']) . '</code>';
        }
        echo '</p>';
        echo '<p class="description">' . esc_html__(
            'The key is encrypted per user and is never sent back to the browser. It is yours alone: '
            . 'other administrators cannot read it, and it is deleted with your account.',
            'wpmcp'
        ) . '</p>';

        echo '<form id="wpmcp-chat-key-form" method="post" onsubmit="return false;">';
        echo '<p><label for="wpmcp-chat-key">' . esc_html__('API key', 'wpmcp') . '</label><br />';
        echo '<input type="password" id="wpmcp-chat-key" class="regular-text" autocomplete="off" '
            . 'maxlength="' . esc_attr((string) Chat_Rest_Controller::MAX_API_KEY_LENGTH) . '" /></p>';
        echo '<p>';
        submit_button(__('Save key', 'wpmcp'), 'primary', 'wpmcp-chat-key-save', false);
        echo ' ';
        submit_button(__('Delete key', 'wpmcp'), 'delete', 'wpmcp-chat-key-delete', false);
        echo '</p>';
        echo '<p id="wpmcp-chat-key-result" role="status" aria-live="polite"></p>';
        echo '</form>';

        // The conversation UI is the executor slice. The mount point is here
        // so that slice adds a bundle rather than restructuring this screen.
        echo '<div id="wpmcp-chat-root" data-rest-namespace="wpmcp/v1"></div>';
        echo '</div>';

        $this->print_key_script();
    }

    /**
     * The key form's behavior. Inline rather than an enqueued file because it
     * is the whole client this slice has; the executor slice replaces it with
     * a registered bundle.
     */
    private function print_key_script(): void
    {
        $endpoint = esc_url_raw(rest_url(Chat_Rest_Controller::REST_NAMESPACE . '/chat/key'));
        $nonce    = wp_create_nonce('wp_rest');

        $script = <<<'JS'
(function () {
    var form = document.getElementById('wpmcp-chat-key-form');
    if (!form) { return; }
    var input = document.getElementById('wpmcp-chat-key');
    var out = document.getElementById('wpmcp-chat-key-result');
    var send = function (method, body) {
        out.textContent = wpmcpChatKey.working;
        fetch(wpmcpChatKey.endpoint, {
            method: method,
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': wpmcpChatKey.nonce },
            body: body ? JSON.stringify(body) : null
        }).then(function (r) {
            return r.json().then(function (d) { return { ok: r.ok, data: d }; });
        }).then(function (r) {
            out.textContent = r.ok ? wpmcpChatKey.done : (r.data && r.data.error ? r.data.error : wpmcpChatKey.failed);
            if (r.ok) { input.value = ''; }
        }).catch(function () { out.textContent = wpmcpChatKey.failed; });
    };
    document.getElementById('wpmcp-chat-key-save').addEventListener('click', function () {
        send('POST', { api_key: input.value });
    });
    document.getElementById('wpmcp-chat-key-delete').addEventListener('click', function () {
        send('DELETE', null);
    });
}());
JS;

        wp_print_inline_script_tag(
            'var wpmcpChatKey = ' . wp_json_encode([
                'endpoint' => $endpoint,
                'nonce'    => $nonce,
                'working'  => __('Saving...', 'wpmcp'),
                'done'     => __('Saved. Reload to see the current status.', 'wpmcp'),
                'failed'   => __('The request failed.', 'wpmcp'),
            ]) . ";\n" . $script
        );
    }
}
