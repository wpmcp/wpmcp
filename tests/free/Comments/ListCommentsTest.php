<?php

namespace WPMCP\Tests\Free\Comments;

use WPMCP\Tools\Comments\List_Comments;

class ListCommentsTest extends \WP_UnitTestCase
{
    private array $created = [];

    protected function tearDown(): void
    {
        foreach ($this->created as $id) {
            wp_delete_comment($id, true);
        }
        $this->created = [];
        parent::tearDown();
    }

    private function comment(array $args): int
    {
        $id = self::factory()->comment->create($args);
        $this->created[] = $id;
        return $id;
    }

    public function test_list_returns_summary_rows_for_a_post(): void
    {
        $post_id = self::factory()->post->create();
        $this->comment([
            'comment_post_ID'      => $post_id,
            'comment_content'      => 'First',
            'comment_author'       => 'Ada',
            'comment_author_email' => 'ada@example.com',
            'comment_approved'     => '1',
        ]);

        $out = (new List_Comments())->handle(['post_id' => $post_id]);

        $this->assertSame($post_id, $out['post_id']);
        $this->assertCount(1, $out['comments']);
        $row = $out['comments'][0];
        $this->assertSame('First', $row['content']);
        $this->assertSame('Ada', $row['author']);
        $this->assertSame('a***@example.com', $row['author_email']);
        $this->assertTrue($out['masked']);
        $this->assertSame('approved', $row['status']);
        $this->assertSame($post_id, $row['post_id']);
    }

    public function test_get_comment_returns_the_full_author_email(): void
    {
        $id = $this->comment([
            'comment_post_ID'      => self::factory()->post->create(),
            'comment_author'       => 'Ada',
            'comment_author_email' => 'ada@example.com',
        ]);

        // Full values at manage_options; below it get-comment masks too (issue #718).
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        $row = (new \WPMCP\Tools\Comments\Get_Comment())->handle(['id' => $id]);

        $this->assertSame('ada@example.com', $row['author_email']);
        $this->assertSame('Ada', $row['author']);
    }

    public function test_list_filters_by_status(): void
    {
        $post_id = self::factory()->post->create();
        $this->comment(['comment_post_ID' => $post_id, 'comment_approved' => '1']);
        $this->comment(['comment_post_ID' => $post_id, 'comment_approved' => 'spam']);

        $approved = (new List_Comments())->handle(['post_id' => $post_id, 'status' => 'approve']);
        $this->assertCount(1, $approved['comments']);
        $this->assertSame('approved', $approved['comments'][0]['status']);

        $spam = (new List_Comments())->handle(['post_id' => $post_id, 'status' => 'spam']);
        $this->assertCount(1, $spam['comments']);
        $this->assertSame('spam', $spam['comments'][0]['status']);
    }

    public function test_list_paginates(): void
    {
        $post_id = self::factory()->post->create();
        for ($i = 0; $i < 5; $i++) {
            $this->comment(['comment_post_ID' => $post_id, 'comment_approved' => '1']);
        }

        $page1 = (new List_Comments())->handle(['post_id' => $post_id, 'per_page' => 2, 'page' => 1]);
        $this->assertCount(2, $page1['comments']);
        $this->assertSame(5, $page1['total']);
        $this->assertSame(1, $page1['page']);

        $page3 = (new List_Comments())->handle(['post_id' => $post_id, 'per_page' => 2, 'page' => 3]);
        $this->assertCount(1, $page3['comments']);
    }
}
