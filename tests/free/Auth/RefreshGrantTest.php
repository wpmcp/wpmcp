<?php

namespace WPMCP\Tests\Free\Auth;

use WPMCP\Auth\Client_Store;
use WPMCP\Auth\Code_Store;
use WPMCP\Auth\Oauth_Gc;
use WPMCP\Auth\Refresh_Token_Store;
use WPMCP\Auth\Token_Grant;
use WPMCP\Auth\Token_Store;
use WPMCP\Governance\Governance_Audit_Log;

/**
 * The refresh_token grant on the token endpoint (issue #133), end to end
 * through Token_Grant: rotation, the idempotent-refresh grace window that
 * keeps a dropped rotation response from killing a live agent session, and
 * the post-grace reuse detection that keeps the grace window from being a
 * security hole.
 */
class RefreshGrantTest extends \WP_UnitTestCase
{
    private const VERIFIER  = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
    private const CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

    private int $clock = 2000000;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([Client_Store::OPTION, Code_Store::OPTION, Token_Store::OPTION, Refresh_Token_Store::OPTION, Governance_Audit_Log::OPTION] as $option) {
            delete_option($option);
        }
        Oauth_Gc::reset_throttle();
        $this->clock = 2000000;
        Refresh_Token_Store::set_clock_override(fn () => $this->clock);
    }

    protected function tearDown(): void
    {
        Refresh_Token_Store::set_clock_override(null);
        foreach ([Client_Store::OPTION, Code_Store::OPTION, Token_Store::OPTION, Refresh_Token_Store::OPTION, Governance_Audit_Log::OPTION] as $option) {
            delete_option($option);
        }
        parent::tearDown();
    }

    /** @return array{client: array, user_id: int, tokens: array} A completed authorization_code exchange. */
    private function connect(): array
    {
        $client  = Client_Store::create(['Test App'], ['https://example.com/cb']);
        $user_id = self::factory()->user->create(['role' => 'subscriber']);

        $code = Code_Store::issue([
            'client_id'             => $client['client_id'],
            'user_id'               => $user_id,
            'redirect_uri'          => 'https://example.com/cb',
            'code_challenge'        => self::CHALLENGE,
            'code_challenge_method' => 'S256',
            'scope'                 => 'read',
        ]);

        $tokens = Token_Grant::exchange([
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => 'https://example.com/cb',
            'client_id'     => $client['client_id'],
            'client_secret' => $client['client_secret'],
            'code_verifier' => self::VERIFIER,
        ]);

        return ['client' => $client, 'user_id' => $user_id, 'tokens' => $tokens];
    }

    private function refresh(array $client, string $refresh_token): array|\WP_Error
    {
        return Token_Grant::exchange([
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refresh_token,
            'client_id'     => $client['client_id'],
            'client_secret' => $client['client_secret'],
        ]);
    }

    public function test_the_code_exchange_now_also_returns_a_refresh_token(): void
    {
        $session = $this->connect();

        $this->assertIsArray($session['tokens']);
        $this->assertArrayHasKey('refresh_token', $session['tokens']);
        $this->assertNotSame('', $session['tokens']['refresh_token']);
    }

    public function test_refreshing_returns_a_new_working_access_token_bound_to_the_same_user(): void
    {
        $session = $this->connect();

        $result = $this->refresh($session['client'], $session['tokens']['refresh_token']);

        $this->assertIsArray($result);
        $this->assertSame('Bearer', $result['token_type']);
        $this->assertSame('read', $result['scope']);
        $this->assertNotSame($session['tokens']['access_token'], $result['access_token']);

        $validated = Token_Store::validate($result['access_token']);
        $this->assertSame($session['user_id'], $validated['user_id']);
        $this->assertSame($session['client']['client_id'], $validated['client_id']);
    }

    public function test_refreshing_rotates_the_refresh_token(): void
    {
        $session = $this->connect();

        $result = $this->refresh($session['client'], $session['tokens']['refresh_token']);

        $this->assertNotSame($session['tokens']['refresh_token'], $result['refresh_token']);
        $this->assertIsArray($this->refresh($session['client'], $result['refresh_token']));
    }

    public function test_a_dropped_rotation_response_can_be_retried_inside_the_grace_window(): void
    {
        // The exact production race: the server rotated and answered, the
        // answer never arrived, and the client retries with the token it
        // still has. Strict single-use rotation would kill the session
        // here; the grace window keeps it alive.
        $session = $this->connect();
        $first   = $this->refresh($session['client'], $session['tokens']['refresh_token']);
        $this->assertIsArray($first);

        $this->clock += Refresh_Token_Store::GRACE_SECONDS - 1;

        $retry = $this->refresh($session['client'], $session['tokens']['refresh_token']);

        $this->assertIsArray($retry);
        $this->assertNotSame($first['access_token'], $retry['access_token']);
        $this->assertNotNull(Token_Store::validate($retry['access_token']));
    }

    public function test_reuse_after_the_grace_window_is_rejected_and_revokes_the_session(): void
    {
        $session = $this->connect();
        $rotated = $this->refresh($session['client'], $session['tokens']['refresh_token']);

        $this->clock += Refresh_Token_Store::GRACE_SECONDS + 1;

        $reuse = $this->refresh($session['client'], $session['tokens']['refresh_token']);

        $this->assertInstanceOf(\WP_Error::class, $reuse);
        $this->assertSame('invalid_grant', $reuse->get_error_code());

        // The successor the thief (or the confused client) would hold next
        // is dead too, and so is the access token minted along the chain.
        $this->assertInstanceOf(\WP_Error::class, $this->refresh($session['client'], $rotated['refresh_token']));
        $this->assertNull(Token_Store::validate($rotated['access_token']));
        $this->assertNull(Token_Store::validate($session['tokens']['access_token']));
    }

    public function test_reuse_detection_is_recorded_under_its_own_audit_ability(): void
    {
        $session = $this->connect();
        $this->refresh($session['client'], $session['tokens']['refresh_token']);

        $this->clock += Refresh_Token_Store::GRACE_SECONDS + 1;
        $this->refresh($session['client'], $session['tokens']['refresh_token']);

        $abilities = array_column(Governance_Audit_Log::list(), 'ability');

        $this->assertContains('oauth/refresh-reuse', $abilities);
    }

    public function test_another_client_cannot_spend_or_burn_someone_elses_refresh_token(): void
    {
        $session   = $this->connect();
        $intruder  = Client_Store::create(['Intruder'], ['https://intruder.example.com/cb']);

        $stolen = $this->refresh($intruder, $session['tokens']['refresh_token']);

        $this->assertInstanceOf(\WP_Error::class, $stolen);
        $this->assertSame('invalid_grant', $stolen->get_error_code());

        // And the rightful owner's token is untouched by the attempt.
        $this->assertIsArray($this->refresh($session['client'], $session['tokens']['refresh_token']));
    }

    public function test_an_unknown_refresh_token_is_a_flat_invalid_grant(): void
    {
        $session = $this->connect();

        $result = $this->refresh($session['client'], 'rt_not-a-real-token');

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_grant', $result->get_error_code());
    }

    public function test_a_missing_refresh_token_is_a_flat_invalid_grant(): void
    {
        $session = $this->connect();

        $result = $this->refresh($session['client'], '');

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_grant', $result->get_error_code());
    }

    public function test_client_authentication_is_required_before_the_token_is_looked_at(): void
    {
        $session = $this->connect();

        $result = Token_Grant::exchange([
            'grant_type'    => 'refresh_token',
            'refresh_token' => $session['tokens']['refresh_token'],
            'client_id'     => $session['client']['client_id'],
            'client_secret' => 'wrong-secret',
        ]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_client', $result->get_error_code());

        // The failed attempt must not have consumed the grant.
        $this->assertIsArray($this->refresh($session['client'], $session['tokens']['refresh_token']));
    }

    public function test_an_expired_refresh_token_is_rejected(): void
    {
        $session = $this->connect();

        $this->clock += Refresh_Token_Store::TTL_SECONDS + 1;

        $result = $this->refresh($session['client'], $session['tokens']['refresh_token']);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_grant', $result->get_error_code());
    }

    public function test_a_grant_does_not_outlive_the_account_it_was_issued_for(): void
    {
        $session = $this->connect();

        wpmcp_test_delete_account($session['user_id']);

        $result = $this->refresh($session['client'], $session['tokens']['refresh_token']);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_grant', $result->get_error_code());
        $this->assertFalse(Refresh_Token_Store::has_tokens_for_client($session['client']['client_id']));
    }

    public function test_an_unsupported_grant_type_is_still_rejected_by_name(): void
    {
        $result = Token_Grant::exchange(['grant_type' => 'password']);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('unsupported_grant_type', $result->get_error_code());
    }
}
