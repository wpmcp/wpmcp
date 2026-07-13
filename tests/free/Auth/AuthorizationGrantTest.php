<?php

namespace WPMCP\Tests\Free\Auth;

use WPMCP\Auth\Authorization_Grant;
use WPMCP\Auth\Client_Store;
use WPMCP\Auth\Code_Store;
use WPMCP\Governance\Governance_Audit_Log;

/**
 * Authorization_Grant::authorize() is the programmatic core behind the
 * authorization endpoint: given a validated request (client_id,
 * redirect_uri, PKCE challenge+method) and the currently logged-in WP user,
 * it issues a single-use authorization code bound to that user's consent.
 *
 * Scope note: this plugin does NOT implement an interactive consent-screen
 * UI (a user clicking "Allow"/"Deny" in a browser). What is implemented and
 * tested here is the binding step an interactive flow would call once
 * consent is obtained: a currently-logged-in WP user is required, and every
 * PKCE/client/redirect_uri validation OAuth 2.1 requires at authorization
 * time (not just at token exchange) is enforced before a code is ever
 * issued, since S256 must be rejected as early as possible, not deferred to
 * the token endpoint.
 */
class AuthorizationGrantTest extends \WP_UnitTestCase
{
    private const CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

    protected function setUp(): void
    {
        parent::setUp();
        delete_option(Client_Store::OPTION);
        delete_option(Code_Store::OPTION);
        delete_option(Governance_Audit_Log::OPTION);
        wp_set_current_user(0);
    }

    protected function tearDown(): void
    {
        delete_option(Client_Store::OPTION);
        delete_option(Code_Store::OPTION);
        delete_option(Governance_Audit_Log::OPTION);
        wp_set_current_user(0);
        parent::tearDown();
    }

    private function register_client(): array
    {
        return Client_Store::create(['Test App'], ['https://example.com/cb']);
    }

    private function logged_in_user(): int
    {
        $user = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user);
        return $user;
    }

    public function test_happy_path_issues_a_code_bound_to_the_current_user(): void
    {
        $client = $this->register_client();
        $user   = $this->logged_in_user();

        $result = Authorization_Grant::authorize([
            'response_type'         => 'code',
            'client_id'             => $client['client_id'],
            'redirect_uri'          => 'https://example.com/cb',
            'code_challenge'        => self::CHALLENGE,
            'code_challenge_method' => 'S256',
            'scope'                 => 'read',
        ]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('code', $result);

        $record = Code_Store::consume($result['code']);
        $this->assertSame($user, $record['user_id']);
        $this->assertSame($client['client_id'], $record['client_id']);
        $this->assertSame('https://example.com/cb', $record['redirect_uri']);
        $this->assertSame(self::CHALLENGE, $record['code_challenge']);
    }

    public function test_no_logged_in_user_is_rejected(): void
    {
        $client = $this->register_client();

        $result = Authorization_Grant::authorize([
            'response_type'         => 'code',
            'client_id'             => $client['client_id'],
            'redirect_uri'          => 'https://example.com/cb',
            'code_challenge'        => self::CHALLENGE,
            'code_challenge_method' => 'S256',
        ]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('login_required', $result->get_error_code());
    }

    public function test_missing_code_challenge_is_rejected(): void
    {
        $client = $this->register_client();
        $this->logged_in_user();

        $result = Authorization_Grant::authorize([
            'response_type' => 'code',
            'client_id'     => $client['client_id'],
            'redirect_uri'  => 'https://example.com/cb',
        ]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_request', $result->get_error_code());
    }

    public function test_plain_code_challenge_method_is_rejected(): void
    {
        $client = $this->register_client();
        $this->logged_in_user();

        $result = Authorization_Grant::authorize([
            'response_type'         => 'code',
            'client_id'             => $client['client_id'],
            'redirect_uri'          => 'https://example.com/cb',
            'code_challenge'        => self::CHALLENGE,
            'code_challenge_method' => 'plain',
        ]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_request', $result->get_error_code());
    }

    public function test_unsupported_response_type_is_rejected(): void
    {
        $client = $this->register_client();
        $this->logged_in_user();

        $result = Authorization_Grant::authorize([
            'response_type'         => 'token',
            'client_id'             => $client['client_id'],
            'redirect_uri'          => 'https://example.com/cb',
            'code_challenge'        => self::CHALLENGE,
            'code_challenge_method' => 'S256',
        ]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('unsupported_response_type', $result->get_error_code());
    }

    public function test_unknown_client_id_is_rejected(): void
    {
        $this->logged_in_user();

        $result = Authorization_Grant::authorize([
            'response_type'         => 'code',
            'client_id'             => 'no-such-client',
            'redirect_uri'          => 'https://example.com/cb',
            'code_challenge'        => self::CHALLENGE,
            'code_challenge_method' => 'S256',
        ]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_client', $result->get_error_code());
    }

    public function test_redirect_uri_not_registered_for_the_client_is_rejected(): void
    {
        $client = $this->register_client();
        $this->logged_in_user();

        $result = Authorization_Grant::authorize([
            'response_type'         => 'code',
            'client_id'             => $client['client_id'],
            'redirect_uri'          => 'https://attacker.example.com/cb',
            'code_challenge'        => self::CHALLENGE,
            'code_challenge_method' => 'S256',
        ]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_request', $result->get_error_code());
    }

    public function test_successful_authorization_is_audited(): void
    {
        $client = $this->register_client();
        $this->logged_in_user();

        Authorization_Grant::authorize([
            'response_type'         => 'code',
            'client_id'             => $client['client_id'],
            'redirect_uri'          => 'https://example.com/cb',
            'code_challenge'        => self::CHALLENGE,
            'code_challenge_method' => 'S256',
        ]);

        $entries = Governance_Audit_Log::list();
        $this->assertSame('oauth/authorize', $entries[0]['ability']);
        $this->assertTrue($entries[0]['allowed']);
    }
}
