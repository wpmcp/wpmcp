<?php

namespace WPMCP\Tests\Free\Auth;

use WPMCP\Auth\Token_Store;

/**
 * Token_Store issues and validates OAuth 2.1 bearer access tokens.
 * Token_Store::validate() is the helper the MCP permission layer
 * (Registrar) calls to authenticate a Bearer token to a WP user + scope
 * (issue #43). Security properties covered:
 *  - the token is stored hashed, never plaintext, so a leaked DB/options row
 *    cannot be replayed as a bearer token;
 *  - tokens expire; validate() rejects an expired token.
 */
class TokenStoreTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        delete_option(Token_Store::OPTION);
        Token_Store::set_clock_override(null);
    }

    protected function tearDown(): void
    {
        delete_option(Token_Store::OPTION);
        Token_Store::set_clock_override(null);
        parent::tearDown();
    }

    private function create_user(): int
    {
        return self::factory()->user->create(['role' => 'subscriber']);
    }

    public function test_issue_returns_a_non_empty_token(): void
    {
        $token = Token_Store::issue('client_abc', $this->create_user(), 'read');

        $this->assertIsString($token);
        $this->assertNotSame('', $token);
    }

    public function test_stored_record_never_contains_the_plaintext_token(): void
    {
        $token = Token_Store::issue('client_abc', $this->create_user(), 'read');

        $stored     = get_option(Token_Store::OPTION);
        $serialized = wp_json_encode($stored);

        $this->assertStringNotContainsString($token, $serialized);
    }

    public function test_validate_returns_the_bound_client_user_and_scope(): void
    {
        $user_id = $this->create_user();
        $token   = Token_Store::issue('client_abc', $user_id, 'read write');
        $record  = Token_Store::validate($token);

        $this->assertIsArray($record);
        $this->assertSame('client_abc', $record['client_id']);
        $this->assertSame($user_id, $record['user_id']);
        $this->assertSame('read write', $record['scope']);
    }

    public function test_validate_rejects_an_unknown_token(): void
    {
        $this->assertNull(Token_Store::validate('never-issued'));
    }

    public function test_validate_rejects_an_expired_token(): void
    {
        Token_Store::set_clock_override(fn() => 1000);
        $token = Token_Store::issue('client_abc', $this->create_user(), 'read');

        Token_Store::set_clock_override(fn() => 1000 + Token_Store::TTL_SECONDS + 1);

        $this->assertNull(Token_Store::validate($token));
    }

    public function test_validate_accepts_a_token_that_has_not_yet_expired(): void
    {
        Token_Store::set_clock_override(fn() => 1000);
        $token = Token_Store::issue('client_abc', $this->create_user(), 'read');

        Token_Store::set_clock_override(fn() => 1000 + Token_Store::TTL_SECONDS);

        $this->assertIsArray(Token_Store::validate($token));
    }

    public function test_validate_can_be_called_repeatedly_unlike_a_single_use_code(): void
    {
        $token = Token_Store::issue('client_abc', $this->create_user(), 'read');

        $this->assertIsArray(Token_Store::validate($token));
        $this->assertIsArray(Token_Store::validate($token));
    }

    public function test_a_leaked_stored_hash_cannot_be_replayed_as_a_bearer_token(): void
    {
        Token_Store::issue('client_abc', $this->create_user(), 'read');

        $stored = get_option(Token_Store::OPTION);
        $hashes = array_keys($stored);

        // Presenting the raw stored hash itself (what an attacker with DB
        // access would have) must NOT validate as a bearer token.
        $this->assertNull(Token_Store::validate($hashes[0]));
    }

    public function test_validate_rejects_a_token_for_a_since_deleted_user(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $token   = Token_Store::issue('client_abc', $user_id, 'read');

        wp_delete_user($user_id);

        $this->assertNull(Token_Store::validate($token));
    }

    public function test_validate_rejects_a_token_issued_before_a_password_change(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $token   = Token_Store::issue('client_abc', $user_id, 'read');

        wp_set_password('a-brand-new-password', $user_id);

        $this->assertNull(Token_Store::validate($token));
    }

    public function test_validate_accepts_a_token_whose_user_and_password_are_unchanged(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $token   = Token_Store::issue('client_abc', $user_id, 'read');

        $record = Token_Store::validate($token);

        $this->assertIsArray($record);
        $this->assertSame($user_id, $record['user_id']);
    }

    public function test_stored_record_never_contains_the_raw_password(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        Token_Store::issue('client_abc', $user_id, 'read');

        $user       = get_userdata($user_id);
        $stored     = get_option(Token_Store::OPTION);
        $serialized = wp_json_encode($stored);

        $this->assertStringNotContainsString($user->user_pass, $serialized);
    }

    public function test_validate_never_returns_the_password_fingerprint_in_the_record(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $token   = Token_Store::issue('client_abc', $user_id, 'read');

        $record = Token_Store::validate($token);

        $this->assertIsArray($record);
        $this->assertArrayNotHasKey('pass_fingerprint', $record);
    }
}
