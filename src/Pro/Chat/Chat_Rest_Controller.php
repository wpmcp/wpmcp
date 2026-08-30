<?php

namespace WPMCP\Pro\Chat;

use WPMCP\Pro\Gate;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * REST surface for the in-admin AI chat (issue #73).
 *
 * The chat is deliberately "just another MCP client": nothing in this
 * controller executes an ability directly. Tool calls proposed by the model
 * are resolved through the SAME registrar/permission/governance/rate-limit/
 * snapshot path as external MCP calls, under the calling admin's identity,
 * and destructive calls additionally require a server-verified approval
 * token minted by Approval_Gate. There is no second, weaker permission path.
 *
 * All routes fail closed: manage_options AND Pro\Gate::is_pro() are both
 * required, checked server-side per request.
 */
class Chat_Rest_Controller
{
    public const REST_NAMESPACE = 'wpmcp/v1';

    public function __construct(
        private ?Key_Vault $vault = null,
        private ?Approval_Gate $approval_gate = null,
        private ?Conversation_Store $store = null
    ) {
        $this->vault         = $vault ?? new Key_Vault();
        $this->approval_gate = $approval_gate ?? new Approval_Gate();
        $this->store         = $store ?? new Conversation_Store();
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
                    'api_key' => ['type' => 'string', 'required' => true],
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
                'message'         => ['type' => 'string', 'required' => true],
            ],
        ]);

        register_rest_route(self::REST_NAMESPACE, '/chat/approve', [
            'methods'             => 'POST',
            'callback'            => [$this, 'approve_tool_call'],
            'permission_callback' => [$this, 'permission_check'],
            'args'                => [
                'ability' => ['type' => 'string', 'required' => true],
                'args'    => ['type' => 'object', 'required' => false],
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
        $stored = $this->vault->store_key(get_current_user_id(), $key);
        return new \WP_REST_Response(['stored' => $stored], $stored ? 200 : 500);
    }

    public function delete_key(): \WP_REST_Response
    {
        return new \WP_REST_Response(['deleted' => $this->vault->delete_key(get_current_user_id())]);
    }

    public function key_status(): \WP_REST_Response
    {
        return new \WP_REST_Response($this->vault->get_status(get_current_user_id()));
    }

    /**
     * Accepts one user message, persists it, and (TODO) runs a provider
     * turn. The provider turn is the next slice; this slice establishes the
     * governed request shape and the conversation persistence path.
     *
     * TODO(#73): call the provider API with System_Prompt::build() and the
     * lazily loaded tool groups; loop tool_use blocks through
     * execute_governed_tool_call(); persist assistant/tool messages.
     */
    public function send_message(\WP_REST_Request $request): \WP_REST_Response
    {
        $user_id = get_current_user_id();
        if ($this->vault->get_key($user_id) === null) {
            return new \WP_REST_Response(['error' => 'no_provider_key'], 409);
        }

        $conversation_id = (int) $request->get_param('conversation_id');
        if ($conversation_id === 0) {
            $conversation_id = $this->store->create($user_id);
            if ($conversation_id === 0) {
                return new \WP_REST_Response(['error' => 'store_failed'], 500);
            }
        }

        $text = (string) $request->get_param('message');
        $ok   = $this->store->append_message($conversation_id, $user_id, [
            'role'    => 'user',
            'content' => $text,
        ]);
        if (! $ok) {
            // Ownership mismatch or unknown conversation: fail closed with no
            // detail about whether the conversation exists for someone else.
            return new \WP_REST_Response(['error' => 'invalid_conversation'], 404);
        }

        return new \WP_REST_Response([
            'conversation_id' => $conversation_id,
            'reply'           => null,
            'pending'         => 'provider_turn_not_implemented',
        ], 202);
    }

    /**
     * Mints a single-use, server-verified approval token for ONE destructive
     * tool call with EXACTLY these arguments. The executor consumes the
     * token via Approval_Gate::validate_and_consume with the actual args, so
     * an approval can never be replayed or transferred to different input.
     */
    public function approve_tool_call(\WP_REST_Request $request): \WP_REST_Response
    {
        $ability = (string) $request->get_param('ability');
        $args    = $request->get_param('args');
        $token   = $this->approval_gate->issue_token(
            get_current_user_id(),
            $ability,
            is_array($args) ? $args : []
        );
        return new \WP_REST_Response(['approval_token' => $token]);
    }
}
