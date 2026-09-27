<?php

namespace WPMCP\Pro\Chat;

use WPMCP\Plugin;

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
 * The screen has two parts: the per-user provider key form (backed by
 * /chat/key) and the conversation view (backed by /chat/message,
 * /chat/continue and /chat/approve), including the approval card that shows
 * the exact ability and arguments of every call that would change the site.
 *
 * The page holds no capability of its own: every action the chat performs
 * goes through the chat REST controller, which re-checks manage_options and
 * Pro\Gate per request, and every tool call runs through the same
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
        echo '<h1>' . esc_html(Plugin::page_title(__('Chat', 'wpmcp'))) . '</h1>';

        echo '<h2>' . esc_html__('Provider key', 'wpmcp') . '</h2>';
        echo '<p>' . esc_html($this->status_line((string) ($status['status'] ?? 'missing')));
        if (! empty($status['masked'])) {
            echo ' <code>' . esc_html((string) $status['masked']) . '</code>';
        }
        echo '</p>';
        echo '<p class="description">' . esc_html__(
            'The key is encrypted per user and is never sent back to the browser. It is yours alone: other administrators cannot read it, and it is deleted with your account.',
            'wpmcp'
        ) . '</p>';
        echo '<p class="description">' . esc_html__(
            'Messages you send here, with the tool results the assistant needs, are sent from this server to the Anthropic API using your key. Nothing is sent until you send a message.',
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

        echo '<h2>' . esc_html__('Conversation', 'wpmcp') . '</h2>';
        echo '<p class="description">' . esc_html__(
            'Reads run straight away. Anything that changes the site waits for you to approve that exact call, and every change is snapshotted so it can be rolled back from the History screen.',
            'wpmcp'
        ) . '</p>';
        echo '<div id="wpmcp-chat-root">';
        echo '<div id="wpmcp-chat-log" role="log" aria-live="polite" '
            . 'style="max-height:28em;overflow:auto;border:1px solid #c3c4c7;background:#fff;padding:8px 12px;margin-bottom:8px;"></div>';
        echo '<p><label for="wpmcp-chat-input" class="screen-reader-text">' . esc_html__('Message', 'wpmcp') . '</label>';
        echo '<textarea id="wpmcp-chat-input" class="large-text" rows="3" '
            . 'maxlength="' . esc_attr((string) Chat_Rest_Controller::MAX_MESSAGE_LENGTH) . '"></textarea></p>';
        echo '<p>';
        echo '<button type="button" class="button button-primary" id="wpmcp-chat-send">' . esc_html__('Send', 'wpmcp') . '</button> ';
        echo '<button type="button" class="button" id="wpmcp-chat-new">' . esc_html__('New conversation', 'wpmcp') . '</button>';
        echo '</p>';
        echo '<p id="wpmcp-chat-status" role="status" aria-live="polite"></p>';
        echo '</div>';
        echo '</div>';

        $this->print_key_script();
        $this->print_chat_script();
    }

    /**
     * The conversation client. It renders text only (textContent, never
     * innerHTML), so neither model output nor tool arguments can inject
     * markup into the admin screen. It holds no key and no authority of its
     * own: every action is a nonce-checked request to the chat routes, and an
     * approval only works with the server-minted token for that one call.
     */
    private function print_chat_script(): void
    {
        $script = <<<'JS'
(function () {
    var c = window.wpmcpChat, log = document.getElementById('wpmcp-chat-log');
    if (!c || !log) { return; }
    var input = document.getElementById('wpmcp-chat-input');
    var sendBtn = document.getElementById('wpmcp-chat-send');
    var status = document.getElementById('wpmcp-chat-status');
    var conversationId = 0, steps = 0, busy = false;

    function line(who, text, cls) {
        var p = document.createElement('div');
        p.className = cls || '';
        p.style.margin = '6px 0';
        var b = document.createElement('strong');
        b.textContent = who + ': ';
        p.appendChild(b);
        var t = document.createElement('span');
        t.style.whiteSpace = 'pre-wrap';
        t.textContent = text;
        p.appendChild(t);
        log.appendChild(p);
        log.scrollTop = log.scrollHeight;
        return p;
    }
    // Send stays disabled while any approval card is open: the server
    // refuses a new message until every parked call is answered, and the
    // typed text would be lost to that 409.
    function parked() { return log.querySelector('[data-proposal]') !== null; }
    function setBusy(on, text) {
        busy = on;
        sendBtn.disabled = on || parked();
        status.textContent = text || '';
    }
    function call(path, body) {
        return fetch(c.root + path, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': c.nonce },
            body: JSON.stringify(body)
        }).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (d) { return { ok: r.ok, data: d || {} }; });
        });
    }
    function events(list) {
        (list || []).forEach(function (e) {
            var text = e.tool + ': ' + e.status + (e.code ? ' (' + e.code + ')' : '') + (e.operation_id ? ' [' + c.i18n.undo + ' ' + e.operation_id + ']' : '');
            line(c.i18n.tool, text, 'description');
        });
    }
    function handle(r) {
        var d = r.data;
        if (d.conversation_id) { conversationId = d.conversation_id; }
        if (d.reply) { line(c.i18n.assistant, d.reply); }
        events(d.tool_events);
        if (!r.ok || d.status === 'error') {
            if (d.proposals) { d.proposals.forEach(propose); }
            setBusy(false, (d.error || c.i18n.failed) + (d.key_status ? ' (' + d.key_status + ')' : '') + (d.message ? ': ' + d.message : ''));
            return;
        }
        if (d.status === 'duplicate') { setBusy(false, c.i18n.duplicate); return; }
        if (d.status === 'approval_required') {
            (d.proposals || []).forEach(propose);
            setBusy(false, c.i18n.waiting);
            return;
        }
        if (d.status === 'continue') {
            steps++;
            if (steps > c.maxSteps) { setBusy(false, c.i18n.limit); return; }
            setBusy(true, c.i18n.working);
            call('/continue', { conversation_id: conversationId }).then(handle, fail);
            return;
        }
        setBusy(false, '');
    }
    function fail() { setBusy(false, c.i18n.failed); }
    function propose(p) {
        var existing = document.getElementById('wpmcp-prop-' + p.tool_use_id);
        if (existing) {
            existing.dataset.token = p.approval_token || '';
            return;
        }
        var box = document.createElement('div');
        box.id = 'wpmcp-prop-' + p.tool_use_id;
        box.dataset.proposal = '1';
        box.dataset.token = p.approval_token || '';
        box.style.cssText = 'border-left:4px solid #dba617;background:#fcf9e8;padding:6px 10px;margin:8px 0;';
        var h = document.createElement('strong');
        h.textContent = c.i18n.approveTitle + ' ' + p.ability;
        box.appendChild(h);
        var pre = document.createElement('pre');
        pre.style.cssText = 'white-space:pre-wrap;max-height:16em;overflow:auto;';
        pre.textContent = JSON.stringify(p.args, null, 2);
        box.appendChild(pre);
        [['approve', c.i18n.approve, 'button button-primary'], ['deny', c.i18n.deny, 'button']].forEach(function (a) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = a[2];
            btn.textContent = a[1];
            btn.style.marginRight = '6px';
            if (a[0] === 'approve' && !p.approval_token) {
                // No token could be minted: the call can only be declined.
                btn.disabled = true;
                btn.title = c.i18n.noToken;
            }
            btn.addEventListener('click', function () {
                if (busy) { return; }
                box.querySelectorAll('button').forEach(function (x) { x.disabled = true; });
                setBusy(true, c.i18n.working);
                call('/approve', {
                    conversation_id: conversationId,
                    tool_use_id: p.tool_use_id,
                    decision: a[0],
                    approval_token: a[0] === 'approve' ? box.dataset.token : ''
                }).then(function (r) {
                    if (r.ok) {
                        box.remove();
                    } else {
                        box.querySelectorAll('button').forEach(function (x) { x.disabled = false; });
                        if (!box.dataset.token) { box.querySelector('.button-primary').disabled = true; }
                    }
                    if (r.ok && r.data.status === 'approval_required') { events(r.data.tool_events); setBusy(false, c.i18n.waiting); return; }
                    handle(r);
                }, fail);
            });
            box.appendChild(btn);
        });
        log.appendChild(box);
        log.scrollTop = log.scrollHeight;
    }
    sendBtn.addEventListener('click', function () {
        var text = input.value.trim();
        if (!text || busy) { return; }
        line(c.i18n.you, text);
        input.value = '';
        steps = 0;
        setBusy(true, c.i18n.working);
        var id = String(Date.now()) + '-' + Math.random().toString(36).slice(2, 10);
        call('/message', { conversation_id: conversationId, message: text, client_message_id: id }).then(handle, fail);
    });
    document.getElementById('wpmcp-chat-new').addEventListener('click', function () {
        if (busy) { return; }
        conversationId = 0;
        log.textContent = '';
        setBusy(false, '');
    });
}());
JS;

        wp_print_inline_script_tag(
            'var wpmcpChat = ' . wp_json_encode([
                'root'     => esc_url_raw(rest_url(Chat_Rest_Controller::REST_NAMESPACE . '/chat')),
                'nonce'    => wp_create_nonce('wp_rest'),
                'maxSteps' => Turn_Runner::MAX_ROUNDS,
                'i18n'     => [
                    'you'          => __('You', 'wpmcp'),
                    'assistant'    => __('Assistant', 'wpmcp'),
                    'tool'         => __('Tool', 'wpmcp'),
                    'undo'         => __('undo point', 'wpmcp'),
                    'working'      => __('Working...', 'wpmcp'),
                    'waiting'      => __('Waiting for your approval.', 'wpmcp'),
                    'failed'       => __('The request failed.', 'wpmcp'),
                    'duplicate'    => __('That message was already sent.', 'wpmcp'),
                    'limit'        => __('Stopped after too many steps. Send a message to continue.', 'wpmcp'),
                    'approveTitle' => __('The assistant wants to run', 'wpmcp'),
                    'approve'      => __('Approve this call', 'wpmcp'),
                    'deny'         => __('Decline', 'wpmcp'),
                    'noToken'      => __('This call could not be prepared for approval. You can decline it.', 'wpmcp'),
                ],
            ]) . ";\n" . $script
        );
    }

    /**
     * The key form's behavior. Inline, like the conversation client, because
     * the plugin enqueues no script files anywhere and the whole add-on
     * screen is stripped from the directory build with src/Pro.
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
