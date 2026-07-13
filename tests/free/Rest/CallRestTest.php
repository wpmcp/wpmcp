<?php

namespace WPMCP\Tests\Free\Rest;

use WPMCP\Tools\Rest\Call_Rest;

class CallRestTest extends \WP_UnitTestCase
{
    protected function tearDown(): void
    {
        wp_set_current_user(0);
        remove_all_filters('wpmcp_enable_rest_writes');
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

    public function test_subscriber_gets_the_endpoints_own_permission_denial(): void
    {
        // context=edit on /wp/v2/posts requires edit_posts in core's own
        // permission_callback. A subscriber lacks it, so the endpoint itself
        // must refuse the request; call-rest must not bypass that check.
        $subscriber = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber);

        $out = (new Call_Rest())->handle([
            'method' => 'GET',
            'route'  => '/wp/v2/posts',
            'params' => ['context' => 'edit'],
        ]);

        $this->assertContains($out['status'], [401, 403]);
        $this->assertIsArray($out['body']);
        $this->assertArrayHasKey('code', $out['body']);
    }

    public function test_mutating_method_is_refused_when_writes_are_disabled(): void
    {
        $admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin);

        $this->expectException(\RuntimeException::class);
        (new Call_Rest())->handle([
            'method' => 'POST',
            'route'  => '/wp/v2/posts',
            'params' => ['title' => 'Should not be created', 'status' => 'publish'],
            'confirm' => true,
        ]);
    }
}
