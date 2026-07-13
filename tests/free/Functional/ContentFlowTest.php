<?php

namespace WPMCP\Tests\Free\Functional;

use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Content\Create_Post;
use WPMCP\Tools\Content\Update_Post;
use WPMCP\Tools\List_Operations;
use WPMCP\Tools\Rollback_Session;

/**
 * End-to-end agent-realistic flow: create a post, apply two separate
 * update-post edits under one session, confirm list-operations surfaces
 * both mutations, then roll the whole session back and confirm the post
 * is restored to its pre-session (post-creation) state.
 */
class ContentFlowTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
    }

    public function test_create_update_list_and_rollback_session_round_trips(): void
    {
        $session_id = 'flow-session-' . uniqid();

        $created = (new Create_Post())->handle([
            'title'   => 'Original Title',
            'content' => 'Original content',
            'status'  => 'draft',
        ]);
        $post_id = $created['post_id'];

        $update_one = (new Update_Post())->handle([
            'post_id'    => $post_id,
            'session_id' => $session_id,
            'title'      => 'First Edit',
        ]);

        $update_two = (new Update_Post())->handle([
            'post_id'    => $post_id,
            'session_id' => $session_id,
            'content'    => 'Second edit content',
        ]);

        // The post reflects both edits before any rollback.
        $post = get_post($post_id);
        $this->assertSame('First Edit', $post->post_title);
        $this->assertSame('Second edit content', $post->post_content);

        // list-operations surfaces both mutations under the same session.
        $ops = (new List_Operations())->handle(['session_id' => $session_id]);
        $this->assertSame(2, $ops['total_count']);
        $operation_ids = array_column($ops['operations'], 'operation_id');
        $this->assertContains($update_one['operation_id'], $operation_ids);
        $this->assertContains($update_two['operation_id'], $operation_ids);
        foreach ($ops['operations'] as $op) {
            $this->assertSame('update-post', $op['tool_name']);
            $this->assertSame($post_id, $op['object_id']);
            $this->assertTrue($op['rollback_available']);
        }

        // rollback-session unwinds both edits, restoring the pre-session state.
        $result = (new Rollback_Session())->handle(['session_id' => $session_id]);
        $this->assertSame(2, $result['restored_count']);

        $restored = get_post($post_id);
        $this->assertSame('Original Title', $restored->post_title);
        $this->assertSame('Original content', $restored->post_content);
    }
}
