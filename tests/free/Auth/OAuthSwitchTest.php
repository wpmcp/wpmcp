<?php

namespace WPMCP\Tests\Free\Auth;

use WPMCP\Admin\Connection_Page;
use WPMCP\Auth\Bearer_Auth;
use WPMCP\Auth\OAuth_Config;
use WPMCP\Auth\Redirect_Uri_Validator;
use WPMCP\Auth\Token_Store;

/**
 * Issue #842: OAuth can be switched on from the Connection screen, so a
 * client that can only sign in with OAuth (ChatGPT, the claude.ai custom
 * connector) connects without a wp-config.php edit. The option is the
 * lowest layer: the WPMCP_OAUTH_ENABLED constant and the
 * wpmcp_oauth_enabled filter keep working and win over it.
 */
class OAuthSwitchTest extends \WP_UnitTestCase
{
    private $original_request_uri;

    protected function setUp(): void
    {
        parent::setUp();
        remove_all_filters('wpmcp_oauth_enabled');
        delete_option(OAuth_Config::OPTION);
        delete_option(Token_Store::OPTION);
        unset($_SERVER['HTTP_AUTHORIZATION']);
        $this->original_request_uri = $_SERVER['REQUEST_URI'] ?? null;
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_oauth_enabled');
        delete_option(OAuth_Config::OPTION);
        delete_option(Token_Store::OPTION);
        unset($_SERVER['HTTP_AUTHORIZATION']);
        if (null === $this->original_request_uri) {
            unset($_SERVER['REQUEST_URI']);
        } else {
            $_SERVER['REQUEST_URI'] = $this->original_request_uri;
        }
        Bearer_Auth::reset_for_tests();
        parent::tearDown();
    }

    public function test_off_by_default_and_the_option_turns_it_on(): void
    {
        $this->assertFalse(OAuth_Config::is_enabled(), 'OAuth stays off on a fresh install.');
        $this->assertNull(OAuth_Config::locked_by());

        $this->assertTrue(OAuth_Config::set_enabled(true));
        $this->assertTrue(OAuth_Config::is_enabled());

        $this->assertTrue(OAuth_Config::set_enabled(false));
        $this->assertFalse(OAuth_Config::is_enabled());
    }

    /** The constant decides over the option, either way. */
    public function test_constant_wins_over_the_option(): void
    {
        $this->assertTrue(OAuth_Config::resolve(true, false));
        $this->assertFalse(OAuth_Config::resolve(false, true));
        $this->assertTrue(OAuth_Config::resolve(null, true));
        $this->assertFalse(OAuth_Config::resolve(null, false));
    }

    /** The filter wins over the option and locks the switch. */
    public function test_filter_wins_over_the_option_and_locks_the_switch(): void
    {
        update_option(OAuth_Config::OPTION, '1');
        add_filter('wpmcp_oauth_enabled', '__return_false');

        $this->assertFalse(OAuth_Config::is_enabled());
        $this->assertSame('filter', OAuth_Config::locked_by());
        $this->assertFalse(OAuth_Config::set_enabled(false), 'A locked switch refuses to save.');
        $this->assertSame('1', get_option(OAuth_Config::OPTION), 'The refused save leaves the option alone.');
    }

    /** Turning the switch off refuses OAuth tokens issued while it was on. */
    public function test_switching_off_refuses_existing_bearer_tokens(): void
    {
        $_SERVER['REQUEST_URI'] = '/wp-json/mcp/wpmcp-server';
        OAuth_Config::set_enabled(true);
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $token   = Token_Store::issue('client_abc', $user_id, 'read');
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;

        $this->assertSame($user_id, Bearer_Auth::resolve(0));

        OAuth_Config::set_enabled(false);
        Bearer_Auth::reset_for_tests();

        $this->assertSame(0, Bearer_Auth::resolve(0), 'With the switch off, an earlier OAuth token authenticates no one.');
    }

    /** The Connection screen flips the switch for a manager, with a nonce. */
    public function test_connection_screen_action_flips_the_switch(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        if (is_multisite()) {
            grant_super_admin(get_current_user_id());
        }
        $page = new Connection_Page();

        $on = $page->handle_request([
            'wpmcp_connection_action' => 'oauth_enabled',
            '_wpnonce'                => wp_create_nonce(Connection_Page::NONCE_ACTION),
            'enabled'                 => '1',
        ]);
        $this->assertSame(['action' => 'oauth_enabled', 'enabled' => true], $on);
        $this->assertTrue(OAuth_Config::is_enabled());

        $bad_nonce = $page->handle_request([
            'wpmcp_connection_action' => 'oauth_enabled',
            '_wpnonce'                => 'nope',
            'enabled'                 => '0',
        ]);
        $this->assertArrayHasKey('error', $bad_nonce);
        $this->assertTrue(OAuth_Config::is_enabled());

        add_filter('wpmcp_oauth_enabled', '__return_true');
        $locked = $page->handle_request([
            'wpmcp_connection_action' => 'oauth_enabled',
            '_wpnonce'                => wp_create_nonce(Connection_Page::NONCE_ACTION),
            'enabled'                 => '0',
        ]);
        $this->assertArrayHasKey('error', $locked);
        $this->assertStringContainsString('wpmcp_oauth_enabled', $locked['error']);
    }

    public function test_a_user_who_cannot_manage_wpmcp_cannot_flip_it(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));

        $out = (new Connection_Page())->handle_request([
            'wpmcp_connection_action' => 'oauth_enabled',
            '_wpnonce'                => wp_create_nonce(Connection_Page::NONCE_ACTION),
            'enabled'                 => '1',
        ]);

        $this->assertArrayHasKey('error', $out);
        $this->assertFalse(OAuth_Config::is_enabled());
    }

    /** ChatGPT's connector redirect address passes the redirect URI check. */
    public function test_chatgpt_redirect_uri_is_accepted(): void
    {
        $this->assertTrue(Redirect_Uri_Validator::is_valid('https://chatgpt.com/connector_platform_oauth_redirect'));
    }
}
