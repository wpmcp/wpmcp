<?php

namespace WPMCP\Tests\Free\Content;

use WPMCP\Tools\Content\List_Posts;

class ListPostsTest extends \WP_UnitTestCase
{
    public function test_compact_shape_and_paging(): void
    {
        self::factory()->post->create(['post_title' => 'A', 'post_status' => 'publish']);
        self::factory()->post->create(['post_title' => 'B', 'post_status' => 'draft']);

        // A caller who may read every row (issue #448 keeps others' drafts
        // from callers who may not).
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        $out = (new List_Posts())->handle(['per_page' => 20, 'status' => 'any']);
        wp_set_current_user(0);

        $this->assertSame(2, $out['total']);
        $this->assertCount(2, $out['posts']);
        $this->assertArrayHasKey('is_elementor', $out['posts'][0]);
        $this->assertArrayNotHasKey('content', $out['posts'][0]);
    }
}
