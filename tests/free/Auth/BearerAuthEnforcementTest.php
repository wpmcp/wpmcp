<?php

namespace WPMCP\Tests\Free\Auth;

use WPMCP\Auth\Bearer_Auth;
use WPMCP\Auth\Token_Store;
use WPMCP\Safety\Snapshot_Store;

/**
 * Proves Bearer_Auth is actually wired into ability execution end to end via
 * the real, globally-registered wpmcp/get-page ability's permission_callback
 * -- not just unit-tested against determine_current_user in isolation.
 * Mirrors WPMCP\Tests\Free\RateLimit\RateLimitEnforcementTest's shape.
 */
class BearerAuthEnforcementTest extends \WP_UnitTestCase
{
    private $original_request_uri;

    public static function wpSetUpBeforeClass(): void
    {
        if (0 === did_action('wp_abilities_api_init')) {
            do_action('wp_abilities_api_init');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        delete_option(Token_Store::OPTION);
        unset($_SERVER['HTTP_AUTHORIZATION']);
        $this->original_request_uri = $_SERVER['REQUEST_URI'] ?? null;
        // Tokens are honoured only on requests to the MCP endpoint they are
        // bound to (AudienceBindingTest covers every other route), so these
        // tests present them the way a real MCP client does.
        $_SERVER['REQUEST_URI'] = '/wp-json/mcp/wpmcp-server';
        self::reset_current_user_resolution();
    }

    /**
     * wp_get_current_user() only ever consults determine_current_user when
     * the $current_user global is still empty (see _wp_get_current_user()):
     * once anything (including a prior wp_set_current_user(0) call) has
     * populated it with a WP_User object, that value is cached for the rest
     * of the request and the filter is never run again. Production requests
     * never call wp_set_current_user() before Bearer_Auth gets a chance to
     * resolve identity, so to accurately simulate "no one has authenticated
     * yet" here, the global must be nulled out rather than set to a
     * logged-out (id 0) WP_User.
     */
    private static function reset_current_user_resolution(): void
    {
        global $current_user;
        $current_user = null;
    }

    protected function tearDown(): void
    {
        delete_option(Token_Store::OPTION);
        unset($_SERVER['HTTP_AUTHORIZATION']);
        if (null === $this->original_request_uri) {
            unset($_SERVER['REQUEST_URI']);
        } else {
            $_SERVER['REQUEST_URI'] = $this->original_request_uri;
        }
        remove_all_filters('wpmcp_oauth_enabled');
        wp_set_current_user(0);
        parent::tearDown();
    }

    private function get_page_ability(): \WP_Ability
    {
        return wp_get_abilities()['wpmcp/get-page'];
    }

    public function test_a_valid_bearer_token_authenticates_an_ability_call_as_its_bound_user(): void
    {
        add_filter('wpmcp_oauth_enabled', '__return_true');
        (new Bearer_Auth())->register();

        $admin = self::factory()->user->create(['role' => 'administrator']);
        $token = Token_Store::issue('client_abc', $admin, 'read');
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;

        // self::factory()->user->create() resolves (and caches) the current
        // user as a side effect, so it must be reset again after creating
        // fixtures and before the Bearer-authenticated call below -- exactly
        // mirroring a real request, where nothing calls wp_set_current_user()
        // before Bearer_Auth gets a chance to resolve identity via
        // determine_current_user.
        self::reset_current_user_resolution();
        $id = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'Hi']);
        self::reset_current_user_resolution();

        // No cookie-based wp_set_current_user() call at all: the Bearer
        // token alone must be enough for current_user_can() (called inside
        // Registrar's permission_callback) to see the admin user.
        $result = $this->get_page_ability()->execute(['id' => $id]);

        $this->assertIsArray($result, 'Bearer-authenticated admin must be able to execute a read ability.');
        $this->assertSame('Hi', $result['title']);
    }

    public function test_an_invalid_bearer_token_leaves_the_caller_unauthenticated_and_denied(): void
    {
        add_filter('wpmcp_oauth_enabled', '__return_true');
        (new Bearer_Auth())->register();

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer not-a-real-token';

        $id = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'Hi']);
        self::reset_current_user_resolution();

        $result = $this->get_page_ability()->execute(['id' => $id]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('ability_invalid_permissions', $result->get_error_code());
    }

    public function test_when_oauth_is_disabled_a_bearer_header_grants_nothing(): void
    {
        // No wpmcp_oauth_enabled filter added: subsystem is disabled (default).
        (new Bearer_Auth())->register();

        $admin = self::factory()->user->create(['role' => 'administrator']);
        $token = Token_Store::issue('client_abc', $admin, 'read');
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;

        $id = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'Hi']);
        self::reset_current_user_resolution();

        $result = $this->get_page_ability()->execute(['id' => $id]);

        $this->assertInstanceOf(\WP_Error::class, $result, 'A Bearer token must grant nothing while the OAuth subsystem is disabled.');
    }
}
