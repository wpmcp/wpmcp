<?php

namespace WPMCP\Tests\Pro\Chat;

use WPMCP\Pro\Chat\Conversation_Store;

/**
 * The conversation lifecycle guarantees that only fire through WordPress
 * itself (issue #73): the user-deletion hooks, the capability map, and the
 * content tools' internal-type guard.
 *
 * ConversationStoreTest calls the store directly, which cannot catch a hook
 * that is never fired. Everything here goes through core: wp_delete_user(),
 * the registered post type object, and the content tools.
 */
class ConversationLifecycleTest extends \WP_UnitTestCase
{
    private Conversation_Store $store;
    private int $owner_id;
    private int $other_admin_id;

    protected function setUp(): void
    {
        parent::setUp();
        Conversation_Store::register_post_type();
        Conversation_Store::register_user_deletion_hooks();
        $this->store          = new Conversation_Store();
        $this->owner_id       = self::factory()->user->create(['role' => 'administrator']);
        $this->other_admin_id = self::factory()->user->create(['role' => 'administrator']);
        require_once ABSPATH . 'wp-admin/includes/user.php';
    }

    /**
     * The reassigning deletion is the case that matters: core's reassign
     * branch runs a raw UPDATE wp_posts SET post_author, and it never
     * consults delete_with_user, which core reads only when $reassign is
     * null. Without a purge on delete_user this hands one admin another
     * admin's provider exchanges.
     */
    public function test_reassigning_user_deletion_destroys_conversations_rather_than_reattributing_them(): void
    {
        $id = $this->store->create($this->owner_id, 'private exchange');
        $this->store->append_message($id, $this->owner_id, ['role' => 'user', 'content' => 'secret']);

        wp_delete_user($this->owner_id, $this->other_admin_id);

        $this->assertNull(get_post($id), 'Conversation survived a reassigning user deletion.');
        $this->assertSame([], get_post_meta($id, '_wpmcp_chat_messages', true) ?: []);
    }

    public function test_plain_user_deletion_destroys_conversations(): void
    {
        $mine   = $this->store->create($this->owner_id);
        $theirs = $this->store->create($this->other_admin_id);

        wp_delete_user($this->owner_id);

        $this->assertNull(get_post($mine));
        $this->assertNotNull(get_post($theirs));
    }

    /**
     * Ownership is enforced in the store, not by post_status: WP_Query skips
     * the private-post permission clause on the 'any' status branch. The
     * capability map is what keeps a generic query or a core export from
     * being a second, weaker read path.
     */
    public function test_every_capability_is_narrowed_to_manage_options(): void
    {
        $object = get_post_type_object(Conversation_Store::POST_TYPE);
        $this->assertNotNull($object);

        // Primitive caps only. The three meta caps must keep their own names:
        // _post_type_meta_capabilities() registers whatever a meta cap is
        // remapped to as a meta capability site-wide, so remapping
        // delete_post to manage_options would turn every manage_options check
        // on the site into an unqualified delete_post check.
        $this->assertSame('edit_post', $object->cap->edit_post);
        $this->assertSame('read_post', $object->cap->read_post);
        $this->assertSame('delete_post', $object->cap->delete_post);

        foreach (
            [
                'edit_posts',
                'edit_others_posts',
                'delete_posts',
                'delete_others_posts',
                'publish_posts',
                'read_private_posts',
                'create_posts',
            ] as $cap
        ) {
            $this->assertSame(
                'manage_options',
                $object->cap->$cap ?? null,
                "Capability {$cap} is not narrowed to manage_options."
            );
        }
    }

    /** A WXR export must not carry another admin's provider exchanges. */
    public function test_conversations_are_excluded_from_wxr_export(): void
    {
        $object = get_post_type_object(Conversation_Store::POST_TYPE);
        $this->assertFalse($object->can_export);
    }

    /**
     * The content tools are the generic read/write path an agent already has.
     * They must not treat a conversation as ordinary content.
     */
    public function test_content_tools_treat_conversations_as_internal(): void
    {
        $this->assertFalse(
            \WPMCP\Tools\Content\Content_Guard::is_writable_post_type(Conversation_Store::POST_TYPE)
        );

        $this->store->create($this->owner_id, 'listed?');
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));

        $rows = (new \WPMCP\Tools\Content\List_Post_Types())->handle(['public_only' => false]);
        $names = array_column($rows['post_types'] ?? $rows, 'name');
        $this->assertNotContains(Conversation_Store::POST_TYPE, $names);
    }
}
