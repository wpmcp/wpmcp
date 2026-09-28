<?php

namespace WPMCP\Tests\Pro\Chat;

use WPMCP\Plugin;
use WPMCP\Pro\Chat\Conversation_Store;
use WPMCP\Pro\Gate;
use WPMCP\RateLimit\Rate_Limiter;
use WPMCP\Tools\Content\Content_Guard;
use WPMCP\Tools\Content\Duplicate_Post;

/**
 * Chat conversations are never a target for the generic post tools
 * (issue #73). The attack this pins: an edit_posts caller duplicates another
 * administrator's conversation, meta and all, into a post they own, then
 * reads it back through the chat routes and gets fresh approval tokens for
 * the other administrator's parked calls.
 */
class PrivatePostGuardTest extends \WP_UnitTestCase
{
    private Conversation_Store $store;
    private int $owner_id;
    private int $editor_id;

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
        Rate_Limiter::set_clock_override(fn () => 1_700_075_000);
        $this->store     = new Conversation_Store();
        $this->owner_id  = self::factory()->user->create(['role' => 'administrator']);
        $this->editor_id = self::factory()->user->create(['role' => 'editor']);
    }

    protected function tearDown(): void
    {
        Rate_Limiter::set_clock_override(null);
        Gate::set_pro_for_tests(null);
        wp_set_current_user(0);
        parent::tearDown();
    }

    private function conversation(): int
    {
        $id = $this->store->create($this->owner_id, 'private exchange');
        $this->store->append_message($id, $this->owner_id, ['role' => 'user', 'content' => 'secret']);
        return $id;
    }

    public function test_duplicate_post_refuses_a_conversation(): void
    {
        $id = $this->conversation();
        wp_set_current_user($this->editor_id);

        try {
            (new Duplicate_Post())->handle(['post_id' => $id]);
            $this->fail('A conversation was duplicated.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Post not found.', $e->getMessage());
        }

        $this->assertSame([], $this->store->list_for_user($this->editor_id));
    }

    public function test_the_governed_path_refuses_any_ability_aimed_at_a_conversation(): void
    {
        $id = $this->conversation();
        wp_set_current_user($this->editor_id);

        foreach ([
            'wpmcp/duplicate-post' => ['post_id' => $id],
            'wpmcp/get-post-meta'  => ['post_id' => $id],
            'wpmcp/update-post'    => ['post_id' => $id, 'title' => 'x'],
            'wpmcp/delete-post'    => ['post_id' => $id],
            'wpmcp/list-posts'     => ['post_type' => Conversation_Store::POST_TYPE],
        ] as $name => $input) {
            $ability = wp_get_ability($name);
            if (null === $ability) {
                continue;
            }
            $result = $ability->execute($input);
            $this->assertInstanceOf(\WP_Error::class, $result, $name . ' ran against a conversation');
        }

        $this->assertNotNull(get_post($id));
        $this->assertSame('private exchange', get_post($id)->post_title);
    }

    public function test_the_guard_does_not_refuse_ordinary_posts_or_non_post_ids(): void
    {
        $id      = $this->conversation();
        $post_id = self::factory()->post->create();

        $this->assertFalse(Content_Guard::input_targets_private_post('content', ['post_id' => $post_id]));
        $this->assertTrue(Content_Guard::input_targets_private_post('content', ['post_id' => $id]));
        $this->assertTrue(Content_Guard::input_targets_private_post('meta', ['post_ids' => [$post_id, $id]]));
        $this->assertTrue(Content_Guard::input_targets_private_post('blocks', ['id' => $id]));
        // A bare 'id' outside the post domains names a user, comment or menu.
        $this->assertFalse(Content_Guard::input_targets_private_post('users', ['id' => $id]));

        wp_set_current_user($this->owner_id);
        $registrar = Plugin::instance()->registrar();
        $get_post  = $registrar->get('wpmcp/get-post');
        $this->assertNotNull($get_post);
        $this->assertTrue($registrar->would_permit($get_post, ['post_id' => $post_id]));
        $this->assertFalse($registrar->would_permit($get_post, ['post_id' => $id]));
    }

    /**
     * Defence in depth for any copy path the guard does not see: a copy of
     * the row with a new author is owned by nobody, not by whoever made it.
     */
    public function test_a_copied_conversation_is_not_owned_by_the_copier(): void
    {
        $id   = $this->conversation();
        $copy = wp_insert_post([
            'post_type'   => Conversation_Store::POST_TYPE,
            'post_status' => 'private',
            'post_author' => $this->editor_id,
            'post_title'  => 'copy',
        ]);
        foreach (get_post_meta($id) as $key => $values) {
            foreach ($values as $value) {
                add_post_meta($copy, $key, maybe_unserialize($value));
            }
        }

        $this->assertFalse($this->store->is_owned_by($copy, $this->editor_id));
        $this->assertSame([], $this->store->get_messages($copy, $this->editor_id));
        $this->assertSame([], $this->store->get_state($copy, $this->editor_id));
    }
}
