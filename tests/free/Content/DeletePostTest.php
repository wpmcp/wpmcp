<?php

namespace WPMCP\Tests\Free\Content;

use WPMCP\Tools\Content\Delete_Post;
use WPMCP\Safety\{Snapshot_Store, Rollback_Service};

class DeletePostTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
    }

    public function test_trashes_by_default(): void
    {
        $id = self::factory()->post->create(['post_status' => 'publish']);

        $out = (new Delete_Post())->handle(['post_id' => $id]);

        $this->assertSame('trashed', $out['deleted']);
        $this->assertSame('trash', get_post($id)->post_status);
    }

    public function test_force_deletes_permanently(): void
    {
        $id = self::factory()->post->create(['post_status' => 'publish']);

        $out = (new Delete_Post())->handle(['post_id' => $id, 'force' => true, 'session_id' => 's1']);

        $this->assertSame('deleted', $out['deleted']);
        $this->assertArrayHasKey('operation_id', $out);
        $this->assertNull(get_post($id));
        $this->assertNotNull(Snapshot_Store::get_by_operation($out['operation_id']));
    }

    public function test_force_delete_rollback_resurrects_the_post(): void
    {
        $id = self::factory()->post->create(['post_title' => 'keep me', 'post_content' => 'body', 'post_status' => 'publish']);

        $out = (new Delete_Post())->handle(['post_id' => $id, 'force' => true, 'session_id' => 's1']);
        $this->assertNull(get_post($id));

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));

        $restored = get_post($id);
        $this->assertNotNull($restored);
        $this->assertSame('keep me', $restored->post_title);
        $this->assertSame('body', $restored->post_content);
    }

    /**
     * Regression test: a force-delete + rollback must restore the FULL post
     * row, not just content/title/status. Before the fix, resurrection went
     * through wp_insert_post() with only those 3 fields, so every other
     * column fell back to wp_insert_post()'s defaults: post_type became
     * 'post' (a force-deleted CPT/page came back as a plain post), and
     * post_author/post_parent/post_name (slug) were lost.
     */
    public function test_force_delete_rollback_restores_type_author_parent_and_slug_for_a_cpt(): void
    {
        register_post_type('wpmcp_test_cpt', ['public' => true, 'supports' => ['title', 'editor']]);

        $author_id = self::factory()->user->create(['role' => 'editor']);
        $parent_id = self::factory()->post->create(['post_type' => 'wpmcp_test_cpt']);

        $id = self::factory()->post->create([
            'post_type'   => 'wpmcp_test_cpt',
            'post_author' => $author_id,
            'post_parent' => $parent_id,
            'post_name'   => 'my-custom-slug',
            'post_title'  => 'CPT post',
            'post_status' => 'publish',
        ]);

        $out = (new Delete_Post())->handle(['post_id' => $id, 'force' => true, 'session_id' => 's1']);
        $this->assertNull(get_post($id));

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));

        $restored = get_post($id);
        $this->assertNotNull($restored);
        $this->assertSame('wpmcp_test_cpt', $restored->post_type);
        $this->assertSame($author_id, (int) $restored->post_author);
        $this->assertSame($parent_id, (int) $restored->post_parent);
        $this->assertSame('my-custom-slug', $restored->post_name);

        unregister_post_type('wpmcp_test_cpt');
    }

    /**
     * A force-delete via wp_delete_post($id, true) destroys the post's
     * comments and commentmeta with no equivalent captured anywhere else.
     * Snapshot::capture() now records them, and rollback must recreate them
     * (content, author, and their commentmeta) so discussion isn't lost
     * along with the post.
     */
    public function test_force_delete_rollback_restores_comment_and_its_meta(): void
    {
        $id = self::factory()->post->create(['post_title' => 'has comments', 'post_status' => 'publish']);
        $comment_id = self::factory()->comment->create([
            'comment_post_ID'      => $id,
            'comment_author'       => 'Jane Reader',
            'comment_author_email' => 'jane@example.com',
            'comment_content'      => 'Great read!',
            'comment_approved'     => '1',
        ]);
        add_comment_meta($comment_id, 'helpful_votes', '3');

        $out = (new Delete_Post())->handle(['post_id' => $id, 'force' => true, 'session_id' => 's1']);
        $this->assertNull(get_post($id));
        $this->assertCount(0, get_comments(['post_id' => $id]));

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));

        $restored_comments = get_comments(['post_id' => $id]);
        $this->assertCount(1, $restored_comments);

        $restored_comment = $restored_comments[0];
        $this->assertSame('Jane Reader', $restored_comment->comment_author);
        $this->assertSame('jane@example.com', $restored_comment->comment_author_email);
        $this->assertSame('Great read!', $restored_comment->comment_content);
        $this->assertSame('3', get_comment_meta((int) $restored_comment->comment_ID, 'helpful_votes', true));
    }
}
