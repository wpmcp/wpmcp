<?php

namespace WPMCP\Tests\Free\Auth;

use WPMCP\Auth\Client_Store;
use WPMCP\Auth\Code_Store;
use WPMCP\Auth\Token_Grant;
use WPMCP\Auth\Token_Store;
use WPMCP\Governance\Governance_Audit_Log;

/**
 * Token_Grant is the authorization_code exchange handler behind the token
 * endpoint (RFC 6749 4.1.3, PKCE-bound per RFC 7636). It is the single place
 * that turns a validly-issued code into a bearer token, and is also where
 * every hard OAuth 2.1 security constraint for the exchange step is proven:
 * PKCE S256 is mandatory, codes are single-use, redirect_uri must match what
 * the code was issued for, and unknown/mismatched clients are rejected.
 */
class TokenGrantTest extends \WP_UnitTestCase
{
    private const VERIFIER  = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
    private const CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

    protected function setUp(): void
    {
        parent::setUp();
        delete_option(Client_Store::OPTION);
        delete_option(Code_Store::OPTION);
        delete_option(Token_Store::OPTION);
        delete_option(Governance_Audit_Log::OPTION);
    }

    protected function tearDown(): void
    {
        delete_option(Client_Store::OPTION);
        delete_option(Code_Store::OPTION);
        delete_option(Token_Store::OPTION);
        delete_option(Governance_Audit_Log::OPTION);
        parent::tearDown();
    }

    /** @return array{client_id: string, client_secret: string} */
    private function register_client(): array
    {
        return Client_Store::create(['Test App'], ['https://example.com/cb']);
    }

    private function issue_code(string $client_id, int $user_id = 42): string
    {
        return Code_Store::issue([
            'client_id'             => $client_id,
            'user_id'               => $user_id,
            'redirect_uri'          => 'https://example.com/cb',
            'code_challenge'        => self::CHALLENGE,
            'code_challenge_method' => 'S256',
            'scope'                 => 'read',
        ]);
    }

    public function test_happy_path_exchanges_a_valid_code_for_a_bearer_token(): void
    {
        $client  = $this->register_client();
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $code    = $this->issue_code($client['client_id'], $user_id);

        $result = Token_Grant::exchange([
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => 'https://example.com/cb',
            'client_id'     => $client['client_id'],
            'client_secret' => $client['client_secret'],
            'code_verifier' => self::VERIFIER,
        ]);

        $this->assertIsArray($result);
        $this->assertSame('Bearer', $result['token_type']);
        $this->assertArrayHasKey('access_token', $result);
        $this->assertSame('read', $result['scope']);

        $validated = Token_Store::validate($result['access_token']);
        $this->assertSame($user_id, $validated['user_id']);
        $this->assertSame($client['client_id'], $validated['client_id']);
    }

    public function test_missing_client_secret_is_rejected_for_a_confidential_client(): void
    {
        $client = $this->register_client();
        $code   = $this->issue_code($client['client_id']);

        $result = Token_Grant::exchange([
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => 'https://example.com/cb',
            'client_id'     => $client['client_id'],
            'code_verifier' => self::VERIFIER,
        ]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_client', $result->get_error_code());
    }

    public function test_wrong_client_secret_is_rejected(): void
    {
        $client = $this->register_client();
        $code   = $this->issue_code($client['client_id']);

        $result = Token_Grant::exchange([
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => 'https://example.com/cb',
            'client_id'     => $client['client_id'],
            'client_secret' => 'wrong-secret',
            'code_verifier' => self::VERIFIER,
        ]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_client', $result->get_error_code());
    }

    public function test_missing_code_verifier_is_rejected(): void
    {
        $client = $this->register_client();
        $code   = $this->issue_code($client['client_id']);

        $result = Token_Grant::exchange([
            'grant_type'   => 'authorization_code',
            'code'         => $code,
            'redirect_uri' => 'https://example.com/cb',
            'client_id'    => $client['client_id'],
            'client_secret' => $client['client_secret'],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_grant', $result->get_error_code());
    }

    public function test_wrong_code_verifier_is_rejected(): void
    {
        $client = $this->register_client();
        $code   = $this->issue_code($client['client_id']);

        $result = Token_Grant::exchange([
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => 'https://example.com/cb',
            'client_id'     => $client['client_id'],
            'client_secret' => $client['client_secret'],
            'code_verifier' => 'wrong-verifier',
        ]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_grant', $result->get_error_code());
    }

    public function test_a_code_issued_with_a_non_s256_method_can_never_be_exchanged(): void
    {
        // Defense in depth: even if something upstream ever stored a
        // non-S256 method (Authorization_Endpoint's own validation is
        // supposed to prevent this), the exchange step itself refuses it.
        $client = $this->register_client();
        $code   = Code_Store::issue([
            'client_id'             => $client['client_id'],
            'user_id'               => 42,
            'redirect_uri'          => 'https://example.com/cb',
            'code_challenge'        => self::CHALLENGE,
            'code_challenge_method' => 'plain',
            'scope'                 => 'read',
        ]);

        $result = Token_Grant::exchange([
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => 'https://example.com/cb',
            'client_id'     => $client['client_id'],
            'client_secret' => $client['client_secret'],
            'code_verifier' => self::CHALLENGE,
        ]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_grant', $result->get_error_code());
    }

    public function test_replaying_an_already_consumed_code_is_rejected(): void
    {
        $client = $this->register_client();
        $code   = $this->issue_code($client['client_id']);

        $args = [
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => 'https://example.com/cb',
            'client_id'     => $client['client_id'],
            'client_secret' => $client['client_secret'],
            'code_verifier' => self::VERIFIER,
        ];

        $this->assertIsArray(Token_Grant::exchange($args));

        $replay = Token_Grant::exchange($args);
        $this->assertInstanceOf(\WP_Error::class, $replay);
        $this->assertSame('invalid_grant', $replay->get_error_code());
    }

    public function test_unknown_code_is_rejected(): void
    {
        $client = $this->register_client();

        $result = Token_Grant::exchange([
            'grant_type'    => 'authorization_code',
            'code'          => 'never-issued',
            'redirect_uri'  => 'https://example.com/cb',
            'client_id'     => $client['client_id'],
            'client_secret' => $client['client_secret'],
            'code_verifier' => self::VERIFIER,
        ]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_grant', $result->get_error_code());
    }

    public function test_mismatched_redirect_uri_is_rejected(): void
    {
        $client = $this->register_client();
        $code   = $this->issue_code($client['client_id']);

        $result = Token_Grant::exchange([
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => 'https://attacker.example.com/cb',
            'client_id'     => $client['client_id'],
            'client_secret' => $client['client_secret'],
            'code_verifier' => self::VERIFIER,
        ]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_grant', $result->get_error_code());
    }

    public function test_mismatched_client_id_is_rejected(): void
    {
        $client       = $this->register_client();
        $other_client = Client_Store::create(['Other'], ['https://other.example.com/cb']);
        $code         = $this->issue_code($client['client_id']);

        $result = Token_Grant::exchange([
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => 'https://example.com/cb',
            'client_id'     => $other_client['client_id'],
            'client_secret' => $other_client['client_secret'],
            'code_verifier' => self::VERIFIER,
        ]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_grant', $result->get_error_code());
    }

    public function test_unsupported_grant_type_is_rejected(): void
    {
        $client = $this->register_client();

        $result = Token_Grant::exchange([
            'grant_type' => 'client_credentials',
            'client_id'  => $client['client_id'],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('unsupported_grant_type', $result->get_error_code());
    }

    public function test_successful_exchange_is_audited(): void
    {
        $client = $this->register_client();
        $code   = $this->issue_code($client['client_id']);

        Token_Grant::exchange([
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => 'https://example.com/cb',
            'client_id'     => $client['client_id'],
            'client_secret' => $client['client_secret'],
            'code_verifier' => self::VERIFIER,
        ]);

        $entries = Governance_Audit_Log::list();
        $this->assertSame('oauth/token', $entries[0]['ability']);
        $this->assertTrue($entries[0]['allowed']);
    }

    public function test_a_rejected_exchange_is_audited_as_denied_without_leaking_the_code_or_token(): void
    {
        $client = $this->register_client();
        $code   = $this->issue_code($client['client_id']);

        Token_Grant::exchange([
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => 'https://example.com/cb',
            'client_id'     => $client['client_id'],
            'client_secret' => $client['client_secret'],
            'code_verifier' => 'wrong-verifier',
        ]);

        $entries = Governance_Audit_Log::list();
        $this->assertSame('oauth/token', $entries[0]['ability']);
        $this->assertFalse($entries[0]['allowed']);

        $serialized = wp_json_encode($entries);
        $this->assertStringNotContainsString($code, $serialized);
    }
}
