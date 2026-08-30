<?php

namespace WPMCP\Tests\Pro\Chat;

use WPMCP\Pro\Chat\Conversation_Store;

/**
 * Owner scoping, slash fidelity and history bounding for the private chat
 * conversation CPT (issue #73).
 */
class ConversationStoreTest extends \WP_UnitTestCase
{
    private Conversation_Store $store;
    private int $owner_id;
    private int $other_admin_id;

    protected function setUp(): void
    {
        parent::setUp();
        Conversation_Store::register_post_type();
        $this->store          = new Conversation_Store();
        $this->owner_id       = self::factory()->user->create(['role' => 'administrator']);
        $this->other_admin_id = self::factory()->user->create(['role' => 'administrator']);
    }

    public function test_create_returns_owned_private_conversation(): void
    {
        $id = $this->store->create($this->owner_id);
        $this->assertGreaterThan(0, $id);

        $post = get_post($id);
        $this->assertSame(Conversation_Store::POST_TYPE, $post->post_type);
        $this->assertSame('private', $post->post_status);
        $this->assertTrue($this->store->is_owned_by($id, $this->owner_id));
        $this->assertFalse($this->store->is_owned_by($id, $this->other_admin_id));
    }

    public function test_another_admin_can_neither_read_nor_append(): void
    {
        $id = $this->store->create($this->owner_id);
        $this->store->append_message($id, $this->owner_id, ['role' => 'user', 'content' => 'secret']);

        $this->assertFalse(
            $this->store->append_message($id, $this->other_admin_id, ['role' => 'user', 'content' => 'intrusion'])
        );
        $this->assertSame([], $this->store->get_messages($id, $this->other_admin_id));
        $this->assertCount(1, $this->store->get_messages($id, $this->owner_id));
    }

    public function test_unknown_conversation_fails_closed(): void
    {
        $this->assertFalse($this->store->append_message(99999999, $this->owner_id, ['role' => 'user', 'content' => 'x']));
        $this->assertSame([], $this->store->get_messages(99999999, $this->owner_id));
    }

    public function test_non_chat_post_id_is_never_owned(): void
    {
        $post_id = self::factory()->post->create([
            'post_author' => $this->owner_id,
            'post_status' => 'draft',
        ]);
        $this->assertFalse($this->store->is_owned_by($post_id, $this->owner_id));
    }

    public function test_unknown_role_is_rejected(): void
    {
        $id = $this->store->create($this->owner_id);
        $this->assertFalse($this->store->append_message($id, $this->owner_id, ['role' => 'system', 'content' => 'x']));
    }

    /**
     * update_metadata() runs wp_unslash() on whatever it stores, so an
     * unslashed write silently eats a backslash level. Every append rewrites
     * the whole array, so the erosion compounds across turns: this asserts
     * the FIRST message still reads back byte-identically after several
     * later appends.
     */
    public function test_backslashes_survive_repeated_appends(): void
    {
        $id      = $this->store->create($this->owner_id);
        $payload = 'C:\\Users\\dev\\site preg_match("/\\d+\\\\s/", $s) and a literal \\\\ pair';

        $this->assertTrue($this->store->append_message($id, $this->owner_id, [
            'role'    => 'user',
            'content' => $payload,
        ]));

        for ($i = 0; $i < 5; $i++) {
            $this->store->append_message($id, $this->owner_id, [
                'role'    => 'assistant',
                'content' => 'turn ' . $i . ' with a trailing backslash \\',
            ]);
        }

        $messages = $this->store->get_messages($id, $this->owner_id);
        $this->assertSame($payload, $messages[0]['content']);
        $this->assertSame('turn 4 with a trailing backslash \\', $messages[5]['content']);
    }

    public function test_client_message_id_makes_append_idempotent(): void
    {
        $id = $this->store->create($this->owner_id);

        $this->assertTrue($this->store->append_message($id, $this->owner_id, [
            'role'      => 'user',
            'content'   => 'only once',
            'client_id' => 'abc-123',
        ]));
        // A retry of the same request must not duplicate the turn.
        $this->assertTrue($this->store->append_message($id, $this->owner_id, [
            'role'      => 'user',
            'content'   => 'only once',
            'client_id' => 'abc-123',
        ]));

        $this->assertCount(1, $this->store->get_messages($id, $this->owner_id));
    }

    public function test_history_is_bounded_by_count_and_reports_the_trim(): void
    {
        $id = $this->store->create($this->owner_id);
        for ($i = 0; $i < 205; $i++) {
            $this->store->append_message($id, $this->owner_id, ['role' => 'user', 'content' => 'm' . $i]);
        }

        $messages = $this->store->get_messages($id, $this->owner_id);
        $this->assertCount(200, $messages);
        $this->assertSame('m204', $messages[199]['content']);
        $this->assertTrue($this->store->last_append_trimmed());
    }

    public function test_history_is_bounded_by_bytes_not_only_by_count(): void
    {
        $id    = $this->store->create($this->owner_id);
        $chunk = str_repeat('x', 60000);

        for ($i = 0; $i < 10; $i++) {
            $this->store->append_message($id, $this->owner_id, ['role' => 'user', 'content' => $chunk]);
        }

        $messages = $this->store->get_messages($id, $this->owner_id);
        $this->assertLessThan(200, count($messages));
        $this->assertLessThanOrEqual(262144, strlen(serialize($messages)));
        $this->assertTrue($this->store->last_append_trimmed());
    }

    public function test_conversations_are_purged_with_their_owner(): void
    {
        $mine   = $this->store->create($this->owner_id);
        $theirs = $this->store->create($this->other_admin_id);

        Conversation_Store::purge_for_user($this->owner_id);

        $this->assertNull(get_post($mine));
        $this->assertNotNull(get_post($theirs));
    }

    public function test_post_type_is_private_and_never_rest_exposed(): void
    {
        $object = get_post_type_object(Conversation_Store::POST_TYPE);
        $this->assertNotNull($object);
        $this->assertFalse($object->public);
        $this->assertFalse($object->publicly_queryable);
        $this->assertFalse($object->show_in_rest);
        $this->assertTrue($object->delete_with_user);
    }
}
