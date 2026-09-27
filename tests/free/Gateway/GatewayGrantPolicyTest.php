<?php

namespace WPMCP\Tests\Free\Gateway;

use WPMCP\Auth\Client_Store;
use WPMCP\Auth\Code_Store;
use WPMCP\Auth\Oauth_Gc;
use WPMCP\Auth\Refresh_Token_Store;
use WPMCP\Auth\Token_Grant;
use WPMCP\Auth\Token_Store;
use WPMCP\Gateway\Gateway_Credential;
use WPMCP\Governance\Governance_Audit_Log;

/**
 * The gateway policy layered on top of the shared refresh grant (issue
 * #142). The refresh grant itself stays open to every DCR client (#133
 * depends on it), so the issue's "restricted to the gateway client" is
 * enforced as a policy on the reserved 'gateway' scope and on the gateway
 * client, in both directions:
 *
 *  - no non-gateway client can obtain, redeem, or authenticate with a
 *    'gateway'-scoped token (the scope string is client-supplied at
 *    /authorize, so without this any DCR client could claim the gateway
 *    TTL for its own session);
 *  - the gateway client can only redeem its locally minted gateway chain,
 *    never an authorization code;
 *  - a rotated-away gateway refresh token is rejected on replay, with no
 *    grace window by default, and the replay kills the chain;
 *  - a gateway access token stops authenticating the moment the gateway
 *    client row is gone, even if a racing refresh wrote the token after
 *    the revoke swept the token store.
 */
class GatewayGrantPolicyTest extends \WP_UnitTestCase
{
    private const VERIFIER  = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
    private const CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

    private int $clock = 2000000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reset();
        Oauth_Gc::reset_throttle();
        $this->clock = 2000000;
        Refresh_Token_Store::set_clock_override(fn () => $this->clock);
    }

    protected function tearDown(): void
    {
        Refresh_Token_Store::set_clock_override(null);
        remove_all_filters('wpmcp_gateway_refresh_grace');
        remove_all_filters('wpmcp_oauth_refresh_grace');
        $this->reset();
        parent::tearDown();
    }

    private function reset(): void
    {
        foreach ([Client_Store::OPTION, Code_Store::OPTION, Token_Store::OPTION, Refresh_Token_Store::OPTION, Governance_Audit_Log::OPTION, Gateway_Credential::OPTION] as $option) {
            delete_option($option);
        }
    }

    private function admin(): int
    {
        return self::factory()->user->create(['role' => 'administrator']);
    }

    private function code_for(string $client_id, int $user_id, string $redirect_uri, string $scope): string
    {
        return Code_Store::issue([
            'client_id'             => $client_id,
            'user_id'               => $user_id,
            'redirect_uri'          => $redirect_uri,
            'code_challenge'        => self::CHALLENGE,
            'code_challenge_method' => 'S256',
            'scope'                 => $scope,
        ]);
    }

    private function redeem_code(array $client, string $code, string $redirect_uri)
    {
        return Token_Grant::exchange([
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $redirect_uri,
            'client_id'     => $client['client_id'],
            'client_secret' => $client['client_secret'],
            'code_verifier' => self::VERIFIER,
        ]);
    }

    private function refresh(string $client_id, string $client_secret, string $refresh_token)
    {
        return Token_Grant::exchange([
            'grant_type'    => 'refresh_token',
            'client_id'     => $client_id,
            'client_secret' => $client_secret,
            'refresh_token' => $refresh_token,
        ]);
    }

    /** @return array<int, string> */
    public static function gateway_scope_strings(): array
    {
        return [
            'bare'          => ['gateway'],
            'space-joined'  => ['read gateway'],
            'padded'        => [' gateway '],
        ];
    }

    /**
     * @dataProvider gateway_scope_strings
     */
    public function test_an_ordinary_client_cannot_obtain_the_reserved_gateway_scope(string $scope): void
    {
        $client  = Client_Store::create(['Some App'], ['https://example.com/cb']);
        $user_id = $this->admin();
        $code    = $this->code_for($client['client_id'], $user_id, 'https://example.com/cb', $scope);

        $result = $this->redeem_code($client, $code, 'https://example.com/cb');

        $this->assertWPError($result);
        $this->assertSame('invalid_grant', $result->get_error_code(), 'no oracle: the refusal is the flat invalid_grant');
        $this->assertSame([], get_option(Token_Store::OPTION, []), 'nothing is minted');
        $this->assertSame([], get_option(Refresh_Token_Store::OPTION, []), 'nothing is minted');
    }

    public function test_an_ordinary_client_still_completes_an_ordinary_code_exchange(): void
    {
        $client  = Client_Store::create(['Some App'], ['https://example.com/cb']);
        $user_id = $this->admin();
        $code    = $this->code_for($client['client_id'], $user_id, 'https://example.com/cb', 'read gatewayish');

        $result = $this->redeem_code($client, $code, 'https://example.com/cb');

        $this->assertIsArray($result, 'only the exact reserved scope token is refused');
    }

    public function test_the_gateway_client_cannot_use_the_authorization_code_grant(): void
    {
        $user_id    = $this->admin();
        $credential = Gateway_Credential::issue_for_user($user_id);
        $code       = $this->code_for($credential['client_id'], $user_id, Gateway_Credential::REDIRECT_URI, Gateway_Credential::SCOPE);

        $result = $this->redeem_code(
            ['client_id' => $credential['client_id'], 'client_secret' => $credential['client_secret']],
            $code,
            Gateway_Credential::REDIRECT_URI
        );

        $this->assertWPError($result);
        $this->assertSame('invalid_grant', $result->get_error_code());
    }

    public function test_a_gateway_scoped_refresh_token_held_by_an_ordinary_client_is_refused(): void
    {
        // A record like this can exist from before the scope was reserved,
        // when /authorize accepted 'gateway' from any client.
        $client  = Client_Store::create(['Some App'], ['https://example.com/cb']);
        $user_id = $this->admin();
        $token   = Refresh_Token_Store::issue($client['client_id'], $user_id, Gateway_Credential::SCOPE);

        $result = $this->refresh($client['client_id'], $client['client_secret'], $token);

        $this->assertWPError($result);
        $this->assertSame('invalid_grant', $result->get_error_code());
        $this->assertSame([], get_option(Token_Store::OPTION, []), 'no access token is minted');
    }

    public function test_the_gateway_client_cannot_redeem_a_non_gateway_refresh_token(): void
    {
        $user_id    = $this->admin();
        $credential = Gateway_Credential::issue_for_user($user_id);
        $foreign    = Refresh_Token_Store::issue($credential['client_id'], $user_id, 'read');

        $result = $this->refresh($credential['client_id'], $credential['client_secret'], $foreign);

        $this->assertWPError($result);
        $this->assertSame('invalid_grant', $result->get_error_code());
    }

    public function test_a_replayed_gateway_refresh_token_is_rejected_immediately_and_kills_the_chain(): void
    {
        $credential = Gateway_Credential::issue_for_user($this->admin());

        $rotated = $this->refresh($credential['client_id'], $credential['client_secret'], $credential['refresh_token']);
        $this->assertIsArray($rotated);

        // Same second: no grace window for the gateway credential by default.
        $replay = $this->refresh($credential['client_id'], $credential['client_secret'], $credential['refresh_token']);

        $this->assertWPError($replay);
        $this->assertSame('invalid_grant', $replay->get_error_code());

        // The replay is treated as theft: the successor and the access token
        // minted along the chain are dead too.
        $this->assertWPError($this->refresh($credential['client_id'], $credential['client_secret'], $rotated['refresh_token']));
        $this->assertNull(Token_Store::validate($rotated['access_token']));
        $this->assertContains('oauth/refresh-reuse', array_column(Governance_Audit_Log::list(), 'ability'));
    }

    public function test_the_successor_keeps_rotating_normally(): void
    {
        $credential = Gateway_Credential::issue_for_user($this->admin());

        $first  = $this->refresh($credential['client_id'], $credential['client_secret'], $credential['refresh_token']);
        $this->clock += 10;
        $second = $this->refresh($credential['client_id'], $credential['client_secret'], $first['refresh_token']);
        $this->clock += 10;
        $third  = $this->refresh($credential['client_id'], $credential['client_secret'], $second['refresh_token']);

        $this->assertIsArray($third);
        $this->assertNotNull(Token_Store::validate($third['access_token']));
    }

    public function test_an_operator_can_opt_the_gateway_into_a_grace_window(): void
    {
        add_filter('wpmcp_gateway_refresh_grace', fn () => 60);

        $credential = Gateway_Credential::issue_for_user($this->admin());
        $this->refresh($credential['client_id'], $credential['client_secret'], $credential['refresh_token']);

        $this->clock += 30;
        $this->assertIsArray($this->refresh($credential['client_id'], $credential['client_secret'], $credential['refresh_token']));

        $this->clock += 31;
        $this->assertWPError($this->refresh($credential['client_id'], $credential['client_secret'], $credential['refresh_token']));
    }

    public function test_the_general_grace_filter_does_not_reopen_the_gateway_window(): void
    {
        add_filter('wpmcp_oauth_refresh_grace', fn () => 600);

        $credential = Gateway_Credential::issue_for_user($this->admin());
        $this->refresh($credential['client_id'], $credential['client_secret'], $credential['refresh_token']);

        $this->clock += 5;
        $this->assertWPError($this->refresh($credential['client_id'], $credential['client_secret'], $credential['refresh_token']));
    }

    public function test_a_zero_general_grace_rejects_a_same_second_replay_too(): void
    {
        // With the window filtered to zero, "now > rotated_at + 0" used to
        // let a replay inside the same second through as a grace hit.
        add_filter('wpmcp_oauth_refresh_grace', '__return_zero');

        $user_id = $this->admin();
        $client  = Client_Store::create(['Some App'], ['https://example.com/cb']);
        $token   = Refresh_Token_Store::issue($client['client_id'], $user_id, 'read');

        $this->assertIsArray($this->refresh($client['client_id'], $client['client_secret'], $token));
        $this->assertWPError($this->refresh($client['client_id'], $client['client_secret'], $token));
    }

    public function test_a_gateway_access_token_dies_with_the_gateway_client_row(): void
    {
        // Models the race a revoke can lose: a refresh that loaded the token
        // store before deprovision() swept it writes its access token back
        // afterwards. The client row is gone either way, and a gateway
        // token must not outlive it.
        $credential = Gateway_Credential::issue_for_user($this->admin());
        $granted    = $this->refresh($credential['client_id'], $credential['client_secret'], $credential['refresh_token']);
        $this->assertNotNull(Token_Store::validate($granted['access_token']));

        $clients = get_option(Client_Store::OPTION, []);
        unset($clients[ $credential['client_id'] ]);
        update_option(Client_Store::OPTION, $clients);

        $this->assertNull(Token_Store::validate($granted['access_token']));
    }

    public function test_a_gateway_scoped_access_token_on_an_ordinary_client_does_not_authenticate(): void
    {
        $client = Client_Store::create(['Some App'], ['https://example.com/cb']);
        $token  = Token_Store::issue($client['client_id'], $this->admin(), Gateway_Credential::SCOPE);

        $this->assertNull(Token_Store::validate($token));
    }

    public function test_ordinary_access_tokens_are_unaffected_by_the_gateway_check(): void
    {
        $token = Token_Store::issue('client_without_a_row', $this->admin(), 'read');

        $this->assertNotNull(Token_Store::validate($token));
    }
}
