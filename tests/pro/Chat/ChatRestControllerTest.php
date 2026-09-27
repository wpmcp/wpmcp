<?php

namespace WPMCP\Tests\Pro\Chat;

use WPMCP\Pro\Chat\Chat_Rest_Controller;
use WPMCP\Pro\Chat\Conversation_Store;
use WPMCP\Pro\Chat\Key_Vault;
use WPMCP\Pro\Gate;
use WPMCP\Tests\Support\Fake_Chat_Provider;

require_once __DIR__ . '/../../support/chat-fake-provider.php';

/**
 * The chat REST surface (issue #73): fail-closed gating, lazy dependency
 * construction, and the error paths for a key that cannot be read.
 */
class ChatRestControllerTest extends \WP_UnitTestCase
{
    private const SALT = 'test_chat_controller_salt_123';

    private Chat_Rest_Controller $controller;
    private Fake_Chat_Provider $provider;
    private Key_Vault $vault;
    private Conversation_Store $store;
    private int $admin_id;
    private int $other_admin_id;
    private int $editor_id;

    /**
     * Fired once for the class, not per test: wp_abilities_api_init is not
     * idempotent, and re-running it makes the registry emit an "already
     * registered" doing_it_wrong for every ability.
     */
    public static function wpSetUpBeforeClass(): void
    {
        if (0 === did_action('wp_abilities_api_init')) {
            do_action('wp_abilities_api_init');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Conversation_Store::register_post_type();
        Gate::set_pro_for_tests(true);

        $this->vault          = new Key_Vault(self::SALT);
        $this->store          = new Conversation_Store();
        $this->provider       = new Fake_Chat_Provider();
        $this->controller     = new Chat_Rest_Controller($this->vault, $this->store, $this->provider);
        $this->admin_id       = self::factory()->user->create(['role' => 'administrator']);
        $this->other_admin_id = self::factory()->user->create(['role' => 'administrator']);
        $this->editor_id      = self::factory()->user->create(['role' => 'editor']);
    }

    protected function tearDown(): void
    {
        Gate::set_pro_for_tests(null);
        wp_set_current_user(0);
        parent::tearDown();
    }

    private function request(array $params): \WP_REST_Request
    {
        $request = new \WP_REST_Request('POST', '/wpmcp/v1/chat/message');
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }
        return $request;
    }

    // ------------------------------------------------------------ gating

    public function test_permission_check_requires_both_admin_and_pro(): void
    {
        wp_set_current_user($this->admin_id);
        $this->assertTrue($this->controller->permission_check());

        Gate::set_pro_for_tests(false);
        $this->assertFalse($this->controller->permission_check());

        Gate::set_pro_for_tests(true);
        wp_set_current_user($this->editor_id);
        $this->assertFalse($this->controller->permission_check());

        wp_set_current_user(0);
        $this->assertFalse($this->controller->permission_check());
    }

    // --------------------------------------------------- lazy construction

    /**
     * Key_Vault's constructor throws when aes-256-gcm is missing, and this
     * controller is built from a hook, so constructing the vault eagerly
     * would turn an unsupported host into a site-wide fatal. Nothing may be
     * built until a route callback actually runs.
     */
    public function test_dependencies_are_not_constructed_eagerly(): void
    {
        $controller = new Chat_Rest_Controller();

        $reflection = new \ReflectionClass($controller);
        foreach (['vault', 'store'] as $property) {
            $prop = $reflection->getProperty($property);
            $this->assertNull($prop->getValue($controller), $property . ' must stay unbuilt until first use');
        }
    }

    // ------------------------------------------------------------- routes

    public function test_registers_the_key_message_turn_and_approval_routes(): void
    {
        // register_rest_route() is only valid inside rest_api_init (WordPress
        // raises a _doing_it_wrong notice otherwise, which WP_UnitTestCase
        // turns into a failure), so register through the action exactly as
        // the plugin wiring in Plugin.php does.
        global $wp_rest_server;
        $wp_rest_server = new \WP_REST_Server();
        add_action('rest_api_init', [$this->controller, 'register_routes']);
        do_action('rest_api_init', $wp_rest_server);
        remove_action('rest_api_init', [$this->controller, 'register_routes']);
        $routes = $wp_rest_server->get_routes();

        $this->assertArrayHasKey('/wpmcp/v1/chat/key', $routes);
        $this->assertArrayHasKey('/wpmcp/v1/chat/message', $routes);
        $this->assertArrayHasKey('/wpmcp/v1/chat/continue', $routes);
        $this->assertArrayHasKey('/wpmcp/v1/chat/tools', $routes);
        // /chat/approve consumes a token for a server-stored proposal; there
        // is still no route that mints a token from client-named arguments.
        $this->assertArrayHasKey('/wpmcp/v1/chat/approve', $routes);
        $this->assertArrayNotHasKey('/wpmcp/v1/chat/token', $routes);
    }

    // ---------------------------------------------------------------- key

    public function test_store_key_rejects_empty_and_oversized_keys(): void
    {
        wp_set_current_user($this->admin_id);

        $response = $this->controller->store_key($this->request(['api_key' => '   ']));
        $this->assertSame(400, $response->get_status());
        $this->assertSame('empty_key', $response->get_data()['error']);

        $long = str_repeat('k', Chat_Rest_Controller::MAX_API_KEY_LENGTH + 1);
        $response = $this->controller->store_key($this->request(['api_key' => $long]));
        $this->assertSame(400, $response->get_status());
        $this->assertSame('key_too_long', $response->get_data()['error']);
    }

    public function test_key_round_trip_through_the_routes(): void
    {
        wp_set_current_user($this->admin_id);

        $this->assertSame('missing', $this->controller->key_status()->get_data()['status']);

        $stored = $this->controller->store_key($this->request(['api_key' => 'sk-test-key-abcd']));
        $this->assertSame(200, $stored->get_status());

        $status = $this->controller->key_status()->get_data();
        $this->assertSame('valid', $status['status']);
        $this->assertSame('...abcd', $status['masked']);

        $this->assertTrue($this->controller->delete_key()->get_data()['deleted']);
        $this->assertSame('missing', $this->controller->key_status()->get_data()['status']);
    }

    // ------------------------------------------------------------ message

    public function test_send_message_409s_without_a_key(): void
    {
        wp_set_current_user($this->admin_id);

        $response = $this->controller->send_message($this->request(['message' => 'hello']));
        $this->assertSame(409, $response->get_status());
        $this->assertSame('no_usable_provider_key', $response->get_data()['error']);
        $this->assertSame('missing', $response->get_data()['key_status']);
    }

    /**
     * A rotated wp_salt('auth') makes Key_Vault::get_key() throw. The route
     * must answer with the status get_status already models, not propagate
     * an uncaught Key_Vault_Corrupted_Exception out of a REST callback.
     */
    public function test_send_message_reports_an_unreadable_key_instead_of_throwing(): void
    {
        wp_set_current_user($this->admin_id);
        $this->vault->store_key($this->admin_id, 'sk-test-key-abcd');

        $rotated    = new Key_Vault('a_completely_different_salt_value');
        $controller = new Chat_Rest_Controller($rotated, $this->store);

        $response = $controller->send_message($this->request(['message' => 'hello']));
        $this->assertSame(409, $response->get_status());
        $this->assertSame('salt_rotated', $response->get_data()['key_status']);
    }

    public function test_send_message_reports_a_corrupted_key_instead_of_throwing(): void
    {
        wp_set_current_user($this->admin_id);
        $this->vault->store_key($this->admin_id, 'sk-test-key-abcd');

        $raw = get_user_meta($this->admin_id, '_wpmcp_chat_anthropic_key', true);
        // Keep the salt fingerprint intact so the tamper is detected by the
        // GCM tag, which is the path that throws.
        [$prefix, $fingerprint, $body] = explode(':', $raw, 3);
        update_user_meta(
            $this->admin_id,
            '_wpmcp_chat_anthropic_key',
            $prefix . ':' . $fingerprint . ':' . base64_encode(random_bytes(64))
        );

        $response = $this->controller->send_message($this->request(['message' => 'hello']));
        $this->assertSame(409, $response->get_status());
        $this->assertSame('corrupted', $response->get_data()['key_status']);
    }

    public function test_send_message_persists_the_turn_and_opens_a_conversation(): void
    {
        wp_set_current_user($this->admin_id);
        $this->vault->store_key($this->admin_id, 'sk-test-key-abcd');

        $this->provider->queue(Fake_Chat_Provider::text('Hello back'));
        $response = $this->controller->send_message($this->request(['message' => 'hello \\path\\here']));
        $this->assertSame(200, $response->get_status());

        $data = $response->get_data();
        $this->assertGreaterThan(0, $data['conversation_id']);
        $this->assertFalse($data['history_trimmed']);
        $this->assertSame('done', $data['status']);
        $this->assertSame('Hello back', $data['reply']);

        $messages = $this->store->get_messages($data['conversation_id'], $this->admin_id);
        $this->assertCount(2, $messages);
        $this->assertSame('hello \\path\\here', $messages[0]['content']);
        $this->assertSame('assistant', $messages[1]['role']);
    }

    public function test_send_message_404s_on_another_admins_conversation(): void
    {
        wp_set_current_user($this->other_admin_id);
        $foreign_id = $this->store->create($this->other_admin_id);

        wp_set_current_user($this->admin_id);
        $this->vault->store_key($this->admin_id, 'sk-test-key-abcd');

        $response = $this->controller->send_message($this->request([
            'message'         => 'let me read your chat',
            'conversation_id' => $foreign_id,
        ]));

        $this->assertSame(404, $response->get_status());
        $this->assertSame('invalid_conversation', $response->get_data()['error']);
        $this->assertSame([], $this->store->get_messages($foreign_id, $this->admin_id));
        $this->assertCount(0, $this->store->get_messages($foreign_id, $this->other_admin_id));
    }

    public function test_send_message_rejects_an_oversized_message(): void
    {
        wp_set_current_user($this->admin_id);
        $this->vault->store_key($this->admin_id, 'sk-test-key-abcd');

        $response = $this->controller->send_message($this->request([
            'message' => str_repeat('a', Chat_Rest_Controller::MAX_MESSAGE_LENGTH + 1),
        ]));

        $this->assertSame(400, $response->get_status());
        $this->assertSame('message_too_long', $response->get_data()['error']);
    }

    public function test_a_retried_send_does_not_duplicate_the_user_turn(): void
    {
        wp_set_current_user($this->admin_id);
        $this->vault->store_key($this->admin_id, 'sk-test-key-abcd');

        $first = $this->controller->send_message($this->request([
            'message'           => 'did this land?',
            'client_message_id' => 'retry-1',
        ]));
        $conversation_id = $first->get_data()['conversation_id'];

        $this->controller->send_message($this->request([
            'message'           => 'did this land?',
            'conversation_id'   => $conversation_id,
            'client_message_id' => 'retry-1',
        ]));

        $this->assertCount(1, $this->user_turns($conversation_id));
        $this->assertCount(1, $this->provider->requests, 'A retry billed a second provider call.');
    }

    /** @return array<int, array<string, mixed>> */
    private function user_turns(int $conversation_id): array
    {
        return array_values(array_filter(
            $this->store->get_messages($conversation_id, $this->admin_id),
            static fn ($m) => 'user' === $m['role']
        ));
    }

    /**
     * The retry this key exists for comes from a client that never saw the
     * response, so it has NO conversation_id to send back. The existing test
     * above passes one, which is the case the client cannot be in when it
     * matters. Without a lookup scoped to (user, client_message_id) across the
     * user's conversations, this opens a second conversation every time and
     * leaves an orphan post behind.
     */
    public function test_a_retried_first_turn_with_no_conversation_id_reuses_the_conversation(): void
    {
        wp_set_current_user($this->admin_id);
        $this->vault->store_key($this->admin_id, 'sk-test-key-abcd');

        $first = $this->controller->send_message($this->request([
            'message'           => 'first turn',
            'client_message_id' => 'first-turn-1',
        ]));
        $conversation_id = (int) $first->get_data()['conversation_id'];

        $retry = $this->controller->send_message($this->request([
            'message'           => 'first turn',
            'client_message_id' => 'first-turn-1',
        ]));

        $this->assertSame($conversation_id, (int) $retry->get_data()['conversation_id']);
        $this->assertSame('duplicate', $retry->get_data()['status']);
        $this->assertCount(1, $this->user_turns($conversation_id));
        $this->assertCount(
            1,
            get_posts([
                'post_type'   => Conversation_Store::POST_TYPE,
                'author'      => $this->admin_id,
                'post_status' => 'any',
                'numberposts' => -1,
                'fields'      => 'ids',
            ]),
            'The retry left an orphan conversation behind.'
        );
    }

    /**
     * maxLength in the arg schema is measured with mb_strlen by
     * rest_validate_value_from_schema, so a byte-counting check here would
     * reject a legitimate multibyte message the schema advertises as fine.
     */
    public function test_a_multibyte_message_under_the_character_limit_is_accepted(): void
    {
        wp_set_current_user($this->admin_id);
        $this->vault->store_key($this->admin_id, 'sk-test-key-abcd');

        // Three bytes per character: well past the byte limit, well under the
        // character limit the schema states.
        $text = str_repeat('あ', Chat_Rest_Controller::MAX_MESSAGE_LENGTH - 1);
        $this->assertGreaterThan(Chat_Rest_Controller::MAX_MESSAGE_LENGTH, strlen($text));

        $response = $this->controller->send_message($this->request(['message' => $text]));
        $this->assertSame(200, $response->get_status());
    }

    // ------------------------------------------------------------ read path

    public function test_a_conversation_is_readable_and_deletable_by_its_owner_only(): void
    {
        wp_set_current_user($this->admin_id);
        $this->vault->store_key($this->admin_id, 'sk-test-key-abcd');
        $id = (int) $this->controller->send_message($this->request(['message' => 'mine']))
            ->get_data()['conversation_id'];

        $read = $this->controller->get_conversation($this->read_request($id));
        $this->assertSame(200, $read->get_status());
        $this->assertSame('mine', $read->get_data()['messages'][0]['content']);

        $list = $this->controller->list_conversations()->get_data()['conversations'];
        $this->assertSame([$id], array_column($list, 'id'));

        wp_set_current_user($this->other_admin_id);
        $this->assertSame(404, $this->controller->get_conversation($this->read_request($id))->get_status());
        $this->assertSame(404, $this->controller->delete_conversation($this->read_request($id))->get_status());
        $this->assertSame([], $this->controller->list_conversations()->get_data()['conversations']);

        wp_set_current_user($this->admin_id);
        $this->assertSame(200, $this->controller->delete_conversation($this->read_request($id))->get_status());
        $this->assertNull(get_post($id));
    }

    private function read_request(int $conversation_id): \WP_REST_Request
    {
        $request = new \WP_REST_Request('GET', '/wpmcp/v1/chat/conversations/' . $conversation_id);
        $request->set_param('conversation_id', $conversation_id);
        return $request;
    }

    /**
     * A lost meta write is a storage fault. Answering it with the 404 that
     * means "no such conversation" sends the client hunting for a
     * conversation that is sitting right there.
     */
    public function test_a_failed_history_write_is_reported_as_a_storage_failure_not_a_missing_conversation(): void
    {
        wp_set_current_user($this->admin_id);
        $this->vault->store_key($this->admin_id, 'sk-test-key-abcd');
        $id = (int) $this->controller->send_message($this->request(['message' => 'one']))
            ->get_data()['conversation_id'];

        $fail = static fn () => false;
        add_filter('update_post_metadata', $fail, 10, 0);
        $response = $this->controller->send_message($this->request([
            'message'         => 'two',
            'conversation_id' => $id,
        ]));
        remove_filter('update_post_metadata', $fail, 10);

        $this->assertSame(500, $response->get_status());
        $this->assertSame('store_failed', $response->get_data()['error']);
    }

    // ------------------------------------------------------ turn + approval

    private function post(string $route, array $params): \WP_REST_Request
    {
        $request = new \WP_REST_Request('POST', '/wpmcp/v1/chat/' . $route);
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }
        return $request;
    }

    public function test_a_parked_call_blocks_new_messages_and_approves_through_the_route(): void
    {
        \WPMCP\Safety\Snapshot_Store::install();
        wp_set_current_user($this->admin_id);
        $this->vault->store_key($this->admin_id, 'sk-test-key-abcd');
        $post_id = self::factory()->post->create(['post_title' => 'Before']);
        $this->provider->queue(Fake_Chat_Provider::tool_calls([
            ['tu_1', 'wpmcp__update-post', ['post_id' => $post_id, 'title' => 'After']],
        ]));

        $parked = $this->controller->send_message($this->request(['message' => 'rename']))->get_data();
        $this->assertSame('approval_required', $parked['status']);
        $conversation_id = (int) $parked['conversation_id'];

        $blocked = $this->controller->send_message($this->request([
            'message'         => 'something else',
            'conversation_id' => $conversation_id,
        ]));
        $this->assertSame(409, $blocked->get_status());
        $this->assertSame('approval_pending', $blocked->get_data()['error']);

        // Client-supplied arguments are not a parameter of the route at all:
        // whatever the client sends, the stored arguments run.
        $wrong = $this->controller->approve($this->post('approve', [
            'conversation_id' => $conversation_id,
            'tool_use_id'     => 'tu_1',
            'decision'        => 'approve',
            'approval_token'  => 'not-the-token',
        ]));
        $this->assertSame(403, $wrong->get_status());
        $this->assertSame('Before', get_post($post_id)->post_title);

        $ok = $this->controller->approve($this->post('approve', [
            'conversation_id' => $conversation_id,
            'tool_use_id'     => 'tu_1',
            'decision'        => 'approve',
            'approval_token'  => $parked['proposals'][0]['approval_token'],
            'args'            => ['post_id' => $post_id, 'title' => 'Injected'],
        ]));
        $this->assertSame(200, $ok->get_status(), print_r($ok->get_data(), true));
        $this->assertSame('continue', $ok->get_data()['status']);
        $this->assertSame('After', get_post($post_id)->post_title);

        $this->provider->queue(Fake_Chat_Provider::text('Renamed'));
        $next = $this->controller->continue_turn($this->post('continue', ['conversation_id' => $conversation_id]));
        $this->assertSame('done', $next->get_data()['status']);
        $this->assertSame('Renamed', $next->get_data()['reply']);
    }

    public function test_the_tools_route_reports_the_governed_inventory_under_the_chat_identity(): void
    {
        wp_set_current_user($this->admin_id);

        $data  = $this->controller->list_tools()->get_data();
        $tools = array_column($data['tools'], null, 'ability');

        $this->assertSame(\WPMCP\Pro\Chat\Chat_Identity::NAME, $data['identity']);
        $this->assertFalse($tools['wpmcp/get-post']['requires_approval']);
        $this->assertTrue($tools['wpmcp/delete-post']['requires_approval']);
    }

    public function test_a_provider_failure_answers_502(): void
    {
        wp_set_current_user($this->admin_id);
        $this->vault->store_key($this->admin_id, 'sk-test-key-abcd');
        $this->provider->queue(new \WP_Error('provider_error', 'overloaded'));

        $response = $this->controller->send_message($this->request(['message' => 'hello']));

        $this->assertSame(502, $response->get_status());
        $this->assertSame('provider_error', $response->get_data()['error']);
    }
}
