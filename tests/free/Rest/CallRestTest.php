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

    public function test_permitted_write_succeeds_when_enabled_and_confirmed(): void
    {
        add_filter('wpmcp_enable_rest_writes', '__return_true');

        $admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin);

        $out = (new Call_Rest())->handle([
            'method'  => 'POST',
            'route'   => '/wp/v2/posts',
            'params'  => ['title' => 'Call-Rest POST Test Post', 'status' => 'publish'],
            'confirm' => true,
        ]);

        $this->assertSame(201, $out['status']);
        $this->assertIsArray($out['body']);
        $this->assertSame('Call-Rest POST Test Post', $out['body']['title']['raw'] ?? null);
        $this->assertFalse($out['recoverable']);

        $post = get_post((int) $out['body']['id']);
        $this->assertSame('Call-Rest POST Test Post', $post->post_title);
    }
    /** Issue #865: a registered credential option comes back masked through /wp/v2/settings. */
    public function test_settings_route_masks_a_registered_secret_option(): void
    {
        register_setting('general', 'sib_token_store', [ 'type' => 'string', 'show_in_rest' => true ]);
        register_setting('general', 'wpmcp_test_plain_setting', [ 'type' => 'string', 'show_in_rest' => true ]);
        update_option('sib_token_store', 'RESTLEAK');
        update_option('wpmcp_test_plain_setting', 'visible');
        $GLOBALS['wp_rest_server'] = null;
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));

        $out = (new Call_Rest())->handle([ 'method' => 'GET', 'route' => '/wp/v2/settings' ]);

        $this->assertSame(200, $out['status']);
        $this->assertSame('[redacted]', $out['body']['sib_token_store']);
        $this->assertSame('visible', $out['body']['wpmcp_test_plain_setting']);
        $this->assertStringNotContainsString('RESTLEAK', wp_json_encode($out));

        unregister_setting('general', 'sib_token_store');
        unregister_setting('general', 'wpmcp_test_plain_setting');
        delete_option('sib_token_store');
        delete_option('wpmcp_test_plain_setting');
        $GLOBALS['wp_rest_server'] = null;
    }

    public function test_a_settings_write_with_a_masked_credential_keeps_the_stored_one(): void
    {
        register_setting('general', 'sib_token_store', [ 'type' => 'string', 'show_in_rest' => true ]);
        update_option('sib_token_store', 'KEEPME');
        add_filter('wpmcp_enable_rest_writes', '__return_true');
        $GLOBALS['wp_rest_server'] = null;
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));

        (new Call_Rest())->handle([ 'method' => 'POST', 'route' => '/wp/v2/settings', 'confirm' => true, 'params' => [ 'sib_token_store' => '[redacted]' ] ]);

        $this->assertSame('KEEPME', get_option('sib_token_store'));

        unregister_setting('general', 'sib_token_store');
        delete_option('sib_token_store');
        $GLOBALS['wp_rest_server'] = null;
    }
}
