<?php

namespace WPMCP\Pro\Chat;

use WPMCP\Pro\Gate;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * REST surface for the in-admin AI chat (issue #73).
 *
 * SCOPE OF THIS SLICE, stated plainly so nobody reads a guarantee into it
 * that the code does not yet make: this controller manages the per-user
 * provider key and the private conversation store. It does NOT execute
 * abilities, and there is no provider turn yet.
 *
 * The design the executor slice will implement (and which nothing here
 * anticipates by minting credentials early): tool calls proposed by the model
 * will be resolved through the SAME registrar/permission/governance/
 * rate-limit/snapshot path as external MCP calls, under the calling admin's
 * identity, and destructive calls will additionally require a server-verified
 * Approval_Gate token minted from a server-stored model proposal. Until that
 * executor exists there is deliberately no approval-minting endpoint: a token
 * nobody validates is attack surface with no function.
 *
 * All routes fail closed: manage_options AND Pro\Gate::is_pro() are both
 * required, checked server-side per request.
 */
class Chat_Rest_Controller
{
    public const REST_NAMESPACE = 'wpmcp/v1';

    /**
     * Upper bound on one user message. Large enough for a pasted file, small
     * enough that a single caller cannot drive the conversation meta row into
     * megabytes in a handful of requests.
     */
    public const MAX_MESSAGE_LENGTH = 32768;

    /** Provider keys are short; anything longer is not a key. */
    public const MAX_API_KEY_LENGTH = 512;

    /**
     * Dependencies are resolved lazily, never in the constructor.
     *
     * Key_Vault's constructor throws when aes-256-gcm is unavailable, and
     * this object is built from a hook on hosts that may not have the cipher.
     * Building it eagerly would turn a PRO feature the site cannot use into a
     * site-wide fatal; building it at first use turns it into one failing
     * chat route.
     */
    public function __construct(
        private ?Key_Vault $vault = null,
        private ?Conversation_Store $store = null
    ) {
    }

    private function vault(): Key_Vault
    {
        return $this->vault ??= new Key_Vault();
    }

    private function store(): Conversation_Store
    {
        return $this->store ??= new Conversation_Store();
    }

    /**
     * Hooked on rest_api_init.
     */
    public function register_routes(): void
    {
        register_rest_route(self::REST_NAMESPACE, '/chat/key', [
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'store_key'],
                'permission_callback' => [$this, 'permission_check'],
                'args'                => [
                    'api_key' => [
                        'type'      => 'string',
                        'required'  => true,
                        'maxLength' => self::MAX_API_KEY_LENGTH,
                    ],
                ],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [$this, 'delete_key'],
                'permission_callback' => [$this, 'permission_check'],
            ],
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'key_status'],
                'permission_callback' => [$this, 'permission_check'],
            ],
        ]);

        register_rest_route(self::REST_NAMESPACE, '/chat/message', [
            'methods'             => 'POST',
            'callback'            => [$this, 'send_message'],
            'permission_callback' => [$this, 'permission_check'],
            'args'                => [
                'conversation_id' => ['type' => 'integer', 'required' => false],
                'message'         => [
                    'type'      => 'string',
                    'required'  => true,
                    'maxLength' => self::MAX_MESSAGE_LENGTH,
                ],
                // Client-generated id for the user turn. Present so a retry
                // after a failed or slow response cannot append the same
                // message twice.
                'client_message_id' => [
                    'type'      => 'string',
                    'required'  => false,
                    'maxLength' => 64,
                ],
            ],
        ]);
    }

    /**
     * Server-side gate for every chat route: an authenticated admin on a
     * pro install. The Gate check is per request, never cached client-side.
     */
    public function permission_check(): bool
    {
        return current_user_can('manage_options') && Gate::is_pro();
    }

    public function store_key(\WP_REST_Request $request): \WP_REST_Response
    {
        $key = trim((string) $request->get_param('api_key'));
        if ($key === '') {
            return new \WP_REST_Response(['stored' => false, 'error' => 'empty_key'], 400);
        }
        if (strlen($key) > self::MAX_API_KEY_LENGTH) {
            return new \WP_REST_Response(['stored' => false, 'error' => 'key_too_long'], 400);
        }
        try {
            $stored = $this->vault()->store_key(get_current_user_id(), $key);
        } catch (\RuntimeException) {
            // Host without aes-256-gcm: the feature cannot work here, but the
            // request is answered rather than fataling the whole endpoint.
            return new \WP_REST_Response(['stored' => false, 'error' => 'cipher_unavailable'], 503);
        }
        return new \WP_REST_Response(['stored' => $stored], $stored ? 200 : 500);
    }

    public function delete_key(): \WP_REST_Response
    {
        try {
            $deleted = $this->vault()->delete_key(get_current_user_id());
        } catch (\RuntimeException) {
            return new \WP_REST_Response(['deleted' => false, 'error' => 'cipher_unavailable'], 503);
        }
        return new \WP_REST_Response(['deleted' => $deleted]);
    }

    public function key_status(): \WP_REST_Response
    {
        try {
            return new \WP_REST_Response($this->vault()->get_status(get_current_user_id()));
        } catch (\RuntimeException) {
            return new \WP_REST_Response(
                ['configured' => false, 'status' => 'cipher_unavailable', 'masked' => null],
                503
            );
        }
    }

    /**
     * Accepts one user message and persists it. The provider turn is the next
     * slice; this slice establishes the governed request shape and the
     * conversation persistence path.
     *
     * The key presence test goes through Key_Vault::get_status(), never
     * get_key(): get_status already models the salt_rotated and corrupted
     * states that get_key signals by throwing, so a routine wp_salt('auth')
     * rotation returns a 409 with a machine-readable status instead of an
     * uncaught Key_Vault_Corrupted_Exception.
     *
     * TODO(#73): call the provider API with System_Prompt::build() and the
     * lazily loaded tool groups; loop tool_use blocks through the governed
     * executor; persist assistant/tool messages.
     */
    public function send_message(\WP_REST_Request $request): \WP_REST_Response
    {
        $user_id = get_current_user_id();

        try {
            $status = $this->vault()->get_status($user_id);
        } catch (\RuntimeException) {
            return new \WP_REST_Response(['error' => 'cipher_unavailable'], 503);
        }
        if (($status['status'] ?? '') !== 'valid') {
            return new \WP_REST_Response([
                'error'      => 'no_usable_provider_key',
                'key_status' => $status['status'] ?? 'missing',
            ], 409);
        }

        $text = (string) $request->get_param('message');
        if (strlen($text) > self::MAX_MESSAGE_LENGTH) {
            return new \WP_REST_Response(['error' => 'message_too_long'], 400);
        }

        $conversation_id = (int) $request->get_param('conversation_id');
        if ($conversation_id === 0) {
            $conversation_id = $this->store()->create($user_id);
            if ($conversation_id === 0) {
                return new \WP_REST_Response(['error' => 'store_failed'], 500);
            }
        }

        $client_id = (string) $request->get_param('client_message_id');
        $ok        = $this->store()->append_message($conversation_id, $user_id, [
            'role'      => 'user',
            'content'   => $text,
            'client_id' => $client_id,
        ]);
        if (! $ok) {
            // Ownership mismatch or unknown conversation: fail closed with no
            // detail about whether the conversation exists for someone else.
            return new \WP_REST_Response(['error' => 'invalid_conversation'], 404);
        }

        return new \WP_REST_Response([
            'conversation_id' => $conversation_id,
            'reply'           => null,
            // The caller is told when the oldest turns fell off the history
            // rather than discovering it in the model's context later.
            'history_trimmed' => $this->store()->last_append_trimmed(),
            'pending'         => 'provider_turn_not_implemented',
        ], 202);
    }
}
