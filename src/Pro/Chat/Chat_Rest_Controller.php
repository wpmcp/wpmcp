<?php

namespace WPMCP\Pro\Chat;

use WPMCP\Pro\Gate;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * REST surface for the in-admin AI chat (issue #73).
 *
 * Routes: the per-user provider key (/chat/key, no reveal), the private
 * conversation store (/chat/conversations), the advertised tool inventory
 * (/chat/tools), and the turn loop: /chat/message appends the admin's text
 * and runs one model step, /chat/continue runs the next step after tool
 * results, and /chat/approve approves or declines one parked call.
 *
 * Every tool call the model proposes goes through Turn_Runner and
 * Tool_Executor, which execute the registered ability through the same
 * permission / governance / identity-scope / rate-limit / snapshot path as
 * an external MCP client, under the chat identity. A call that changes the
 * site is only ever run by /chat/approve, from the arguments the server
 * stored when the model proposed it, with a single-use Approval_Gate token
 * minted for exactly that call.
 *
 * All routes fail closed: manage_options AND Pro\Gate::is_pro() are both
 * required, checked server-side per request, and the REST cookie nonce
 * applies as for every wp-json route.
 */
class Chat_Rest_Controller
{
    public const REST_NAMESPACE = 'wpmcp/v1';

    /**
     * Upper bound on one user message, in CHARACTERS, not bytes.
     *
     * The unit is stated because both halves of the check have to agree on
     * it: rest_validate_value_from_schema measures a schema maxLength with
     * mb_strlen, so a byte-counting strlen() here would reject a legitimate
     * multibyte message well under the advertised limit. Byte pressure on the
     * stored row is bounded separately, and in bytes, by the conversation
     * store's own per-entry and per-history caps.
     */
    public const MAX_MESSAGE_LENGTH = 32768;

    /** Provider keys are short; anything longer is not a key. Characters, as above. */
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
        private ?Conversation_Store $store = null,
        private ?Chat_Provider $provider = null,
        private ?Tool_Inventory $inventory = null,
        private ?Approval_Gate $gate = null
    ) {
    }

    private function runner(): Turn_Runner
    {
        return new Turn_Runner(
            $this->store(),
            $this->vault(),
            $this->provider ??= new Anthropic_Provider(),
            null,
            $this->inventory ??= new Tool_Inventory(),
            $this->gate ??= new Approval_Gate()
        );
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

        // Read paths. Without them /chat/message would be write-only: the
        // store would accumulate private posts that nothing could retrieve,
        // and no caller could verify the ownership scoping it depends on.
        register_rest_route(self::REST_NAMESPACE, '/chat/conversations', [
            'methods'             => 'GET',
            'callback'            => [$this, 'list_conversations'],
            'permission_callback' => [$this, 'permission_check'],
        ]);

        register_rest_route(
            self::REST_NAMESPACE,
            '/chat/conversations/(?P<conversation_id>[0-9]+)',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [$this, 'get_conversation'],
                    'permission_callback' => [$this, 'permission_check'],
                ],
                [
                    'methods'             => 'DELETE',
                    'callback'            => [$this, 'delete_conversation'],
                    'permission_callback' => [$this, 'permission_check'],
                ],
            ]
        );

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

        register_rest_route(self::REST_NAMESPACE, '/chat/continue', [
            'methods'             => 'POST',
            'callback'            => [$this, 'continue_turn'],
            'permission_callback' => [$this, 'permission_check'],
            'args'                => [
                'conversation_id' => ['type' => 'integer', 'required' => true],
            ],
        ]);

        register_rest_route(self::REST_NAMESPACE, '/chat/approve', [
            'methods'             => 'POST',
            'callback'            => [$this, 'approve'],
            'permission_callback' => [$this, 'permission_check'],
            'args'                => [
                'conversation_id' => ['type' => 'integer', 'required' => true],
                'tool_use_id'     => ['type' => 'string', 'required' => true, 'maxLength' => 128],
                'decision'        => ['type' => 'string', 'required' => true, 'enum' => ['approve', 'deny']],
                'approval_token'  => ['type' => 'string', 'required' => false, 'maxLength' => 1024],
            ],
        ]);

        register_rest_route(self::REST_NAMESPACE, '/chat/tools', [
            'methods'             => 'GET',
            'callback'            => [$this, 'list_tools'],
            'permission_callback' => [$this, 'permission_check'],
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
        if (mb_strlen($key, 'UTF-8') > self::MAX_API_KEY_LENGTH) {
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
     * Accepts one user message, persists it, and runs one model step.
     *
     * The key presence test goes through Key_Vault::get_status(), never
     * get_key(): get_status already models the salt_rotated and corrupted
     * states that get_key signals by throwing, so a routine wp_salt('auth')
     * rotation returns a 409 with a machine-readable status instead of an
     * uncaught Key_Vault_Corrupted_Exception.
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
        if (mb_strlen($text, 'UTF-8') > self::MAX_MESSAGE_LENGTH) {
            return new \WP_REST_Response(['error' => 'message_too_long'], 400);
        }
        if ('' === trim($text)) {
            return new \WP_REST_Response(['error' => 'empty_message'], 400);
        }

        $conversation_id = (int) $request->get_param('conversation_id');
        $client_id       = (string) $request->get_param('client_message_id');

        if ($conversation_id === 0 && $client_id !== '') {
            // The retry this idempotency key exists for is precisely the one
            // from a client that never saw the response, so it has no
            // conversation_id to send back. Dedupe scoped to a single
            // conversation would miss it and open a second conversation with
            // the same message in it, leaving an orphan behind every time.
            $conversation_id = $this->store()->find_by_client_id($user_id, $client_id);
        }

        if ($conversation_id === 0) {
            $conversation_id = $this->store()->create($user_id);
            if ($conversation_id === 0) {
                return new \WP_REST_Response(['error' => 'store_failed'], 500);
            }
        }

        // The pending-approval check, the append and the model step all run
        // under the conversation's turn lock inside the runner.
        $result = $this->runner()->send($user_id, $conversation_id, $text, $client_id);
        return $this->turn_response($conversation_id, $result);
    }

    /** Runs the next model step after tool results were recorded. */
    public function continue_turn(\WP_REST_Request $request): \WP_REST_Response
    {
        $conversation_id = (int) $request->get_param('conversation_id');
        $result          = $this->runner()->step(get_current_user_id(), $conversation_id);
        return $this->turn_response($conversation_id, $result);
    }

    /** Approves or declines one parked tool call. */
    public function approve(\WP_REST_Request $request): \WP_REST_Response
    {
        $conversation_id = (int) $request->get_param('conversation_id');
        $result          = $this->runner()->resolve(
            get_current_user_id(),
            $conversation_id,
            (string) $request->get_param('tool_use_id'),
            'approve' === $request->get_param('decision'),
            (string) $request->get_param('approval_token')
        );
        return $this->turn_response($conversation_id, $result);
    }

    /**
     * The inventory the chat advertises, computed under the chat identity:
     * the same set the system prompt lists and the executor accepts.
     */
    public function list_tools(): \WP_REST_Response
    {
        $inventory  = $this->inventory ??= new Tool_Inventory();
        $advertised = Chat_Identity::run(static fn () => $inventory->advertised());

        $tools = [];
        foreach ($advertised as $tool => $ability) {
            $tools[] = [
                'tool'              => $tool,
                'ability'           => $ability->name,
                'domain'            => $ability->domain,
                'operation'         => $ability->operation,
                'requires_approval' => Tool_Executor::requires_approval($ability),
            ];
        }
        return new \WP_REST_Response(['identity' => Chat_Identity::NAME, 'tools' => $tools]);
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $extra
     */
    private function turn_response(int $conversation_id, array $result, array $extra = []): \WP_REST_Response
    {
        $body = array_merge(['conversation_id' => $conversation_id], $extra, $result);
        if (Turn_Runner::ERROR !== ($result['status'] ?? '')) {
            return new \WP_REST_Response($body, 200);
        }
        $code = match ((string) ($result['error'] ?? '')) {
            'invalid_conversation', 'unknown_proposal' => 404,
            'no_usable_provider_key', 'busy', 'approval_pending' => 409,
            'invalid_approval'                         => 403,
            'provider_error'                           => 502,
            'store_failed'                             => 500,
            default                                    => 400,
        };
        return new \WP_REST_Response($body, $code);
    }

    /**
     * Lists the calling admin's own conversations. There is deliberately no
     * parameter that could widen this to another user: a conversation can
     * contain another admin's provider exchange, so there is no admin-wide
     * view of them anywhere in this surface.
     */
    public function list_conversations(): \WP_REST_Response
    {
        return new \WP_REST_Response([
            'conversations' => $this->store()->list_for_user(get_current_user_id()),
        ]);
    }

    /** Returns one conversation's history, for its owner only. */
    public function get_conversation(\WP_REST_Request $request): \WP_REST_Response
    {
        $user_id = get_current_user_id();
        $id      = (int) $request->get_param('conversation_id');
        if (! $this->store()->is_owned_by($id, $user_id)) {
            // Same answer whether the conversation does not exist or belongs
            // to someone else: the difference is itself information.
            return new \WP_REST_Response(['error' => 'invalid_conversation'], 404);
        }
        return new \WP_REST_Response([
            'conversation_id' => $id,
            'messages'        => $this->store()->get_messages($id, $user_id),
            // Parked calls come back with fresh tokens so a reload of the
            // chat screen can still approve or decline them.
            'proposals'       => $this->runner()->pending_proposals($user_id, $id),
        ]);
    }

    /** Force-deletes one conversation, for its owner only. */
    public function delete_conversation(\WP_REST_Request $request): \WP_REST_Response
    {
        $user_id = get_current_user_id();
        $id      = (int) $request->get_param('conversation_id');
        if (! $this->store()->delete($id, $user_id)) {
            return new \WP_REST_Response(['error' => 'invalid_conversation'], 404);
        }
        return new \WP_REST_Response(['deleted' => true]);
    }
}
