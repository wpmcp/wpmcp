<?php

namespace WPMCP\Tests\Free\Auth;

use WPMCP\Auth\Bearer_Auth;
use WPMCP\Auth\OAuth_Config;
use WPMCP\Auth\Token_Store;
use WPMCP\Governance\Governance_Audit_Log;

/**
 * Bearer_Auth hooks WordPress's determine_current_user filter -- the same
 * seam WP core's own Application Passwords feature uses -- so a valid
 * Authorization: Bearer <token> header resolves to the WP user Token_Store
 * bound the token to, letting the existing Registrar::register()
 * permission_callback (current_user_can(...)) work unmodified for OAuth
 * callers. Every validation attempt against a present Bearer header is
 * audited under 'oauth/validate'; requests with no Bearer header at all
 * pass through untouched (existing cookie-auth behavior is not affected).
 *
 * Gated by OAuth_Config::is_enabled(): when disabled, a Bearer header is
 * never even inspected, so a default install's unauthenticated-in-dev
 * behavior is completely unchanged.
 */
class BearerAuthTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        delete_option(Token_Store::OPTION);
        delete_option(Governance_Audit_Log::OPTION);
        unset($_SERVER['HTTP_AUTHORIZATION']);
    }

    protected function tearDown(): void
    {
        delete_option(Token_Store::OPTION);
        delete_option(Governance_Audit_Log::OPTION);
        unset($_SERVER['HTTP_AUTHORIZATION']);
        remove_all_filters('wpmcp_oauth_enabled');
        parent::tearDown();
    }

    public function test_no_authorization_header_passes_the_incoming_user_through_unchanged(): void
    {
        add_filter('wpmcp_oauth_enabled', '__return_true');

        $this->assertSame(7, Bearer_Auth::resolve(7));
    }

    public function test_disabled_subsystem_never_inspects_the_header_even_if_present(): void
    {
        $token = Token_Store::issue('client_abc', 99, 'read');
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;

        // OAuth_Config::is_enabled() is false by default (no filter added).
        $this->assertSame(0, Bearer_Auth::resolve(0));
    }

    public function test_valid_bearer_token_resolves_to_its_bound_user(): void
    {
        add_filter('wpmcp_oauth_enabled', '__return_true');
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $token   = Token_Store::issue('client_abc', $user_id, 'read');
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;

        $this->assertSame($user_id, Bearer_Auth::resolve(0));
    }

    public function test_invalid_bearer_token_does_not_authenticate_anyone(): void
    {
        add_filter('wpmcp_oauth_enabled', '__return_true');
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer not-a-real-token';

        $this->assertSame(0, Bearer_Auth::resolve(0));
    }

    public function test_malformed_authorization_header_is_ignored(): void
    {
        add_filter('wpmcp_oauth_enabled', '__return_true');
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic dXNlcjpwYXNz';

        $this->assertSame(5, Bearer_Auth::resolve(5));
    }

    public function test_valid_token_presentation_is_audited_as_allowed(): void
    {
        add_filter('wpmcp_oauth_enabled', '__return_true');
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $token   = Token_Store::issue('client_abc', $user_id, 'read');
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;

        Bearer_Auth::resolve(0);

        $entries = Governance_Audit_Log::list();
        $this->assertSame('oauth/validate', $entries[0]['ability']);
        $this->assertTrue($entries[0]['allowed']);
    }

    public function test_invalid_token_presentation_is_audited_as_denied_without_leaking_the_token(): void
    {
        add_filter('wpmcp_oauth_enabled', '__return_true');
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer not-a-real-token';

        Bearer_Auth::resolve(0);

        $entries = Governance_Audit_Log::list();
        $this->assertSame('oauth/validate', $entries[0]['ability']);
        $this->assertFalse($entries[0]['allowed']);

        $serialized = wp_json_encode($entries);
        $this->assertStringNotContainsString('not-a-real-token', $serialized);
    }

    public function test_token_for_a_since_deleted_user_is_rejected(): void
    {
        add_filter('wpmcp_oauth_enabled', '__return_true');
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $token   = Token_Store::issue('client_abc', $user_id, 'read');
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;

        wp_delete_user($user_id);

        $this->assertSame(0, Bearer_Auth::resolve(0));
    }

    public function test_token_issued_before_a_password_change_is_rejected_afterward(): void
    {
        add_filter('wpmcp_oauth_enabled', '__return_true');
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $token   = Token_Store::issue('client_abc', $user_id, 'read');
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;

        wp_set_password('a-brand-new-password', $user_id);

        $this->assertSame(0, Bearer_Auth::resolve(0));
    }

    public function test_a_normal_unchanged_token_still_validates(): void
    {
        add_filter('wpmcp_oauth_enabled', '__return_true');
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $token   = Token_Store::issue('client_abc', $user_id, 'read');
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;

        $this->assertSame($user_id, Bearer_Auth::resolve(0));
    }

    public function test_the_stored_password_fingerprint_never_appears_in_audit_entries(): void
    {
        add_filter('wpmcp_oauth_enabled', '__return_true');
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $token   = Token_Store::issue('client_abc', $user_id, 'read');
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;

        Bearer_Auth::resolve(0);

        $user        = get_userdata($user_id);
        $fingerprint = hash('sha256', $user->user_pass);

        $entries    = Governance_Audit_Log::list();
        $serialized = wp_json_encode($entries);
        $this->assertStringNotContainsString($fingerprint, $serialized);
        $this->assertStringNotContainsString($user->user_pass, $serialized);
    }
}
