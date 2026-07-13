<?php

namespace WPMCP\Tests\Free\Rest;

use WPMCP\Tools\Rest\Call_Rest;

class CallRestTest extends \WP_UnitTestCase
{
    protected function tearDown(): void
    {
        wp_set_current_user(0);
        parent::tearDown();
    }

    public function test_get_returns_posts_created_in_this_test(): void
    {
        $admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin);

        $post_id = self::factory()->post->create([
            'post_title'  => 'Call-Rest GET Test Post',
            'post_status' => 'publish',
        ]);

        $out = (new Call_Rest())->handle([
            'method' => 'GET',
            'route'  => '/wp/v2/posts',
            'params' => ['search' => 'Call-Rest GET Test Post'],
        ]);

        $this->assertSame(200, $out['status']);
        $this->assertIsArray($out['body']);

        $ids = array_column($out['body'], 'id');
        $this->assertContains($post_id, $ids);
    }
}
