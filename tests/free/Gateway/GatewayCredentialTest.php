<?php

namespace WPMCP\Tests\Free\Gateway;

use WPMCP\Auth\Client_Store;
use WPMCP\Auth\Refresh_Token_Store;
use WPMCP\Auth\Token_Grant;
use WPMCP\Auth\Token_Store;
use WPMCP\Cloud\Gateway_Credential;

/**
 * The site-local gateway credential lifecycle (issue #142, phase 1 of
 * #130). The properties that matter, and why each is here:
 *
 *  - what is issued must actually be redeemable. Token_Grant authenticates
 *    every grant type with client_id + client_secret, so a credential that
 *    omits the secret is inert; the redemption test below is the guard
 *    against shipping that again.
 *  - re-provisioning rotates rather than accumulates: the clients store
 *    never grows, and the PREVIOUS credential is dead immediately, access
 *    tokens included.
 *  - the gateway client is selected by registration fingerprint, never by
 *    the publicly guessable name + redirect URI a DCR caller can supply.
 *  - revocation is local-only, idempotent, and converges from a
 *    half-revoked state and from duplicate rows.
 */
class GatewayCredentialTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->reset();
    }

    protected function tearDown(): void
    {
        $this->reset();
        parent::tearDown();
    }

    private function reset(): void
    {
        foreach ([Client_Store::OPTION, Token_Store::OPTION, Refresh_Token_Store::OPTION, Gateway_Credential::OPTION] as $option) {
            delete_option($option);
        }
    }

    private function admin(): int
    {
        return self::factory()->user->create(['role' => 'administrator']);
    }

    public function test_ensure_client_is_idempotent_and_never_grows_the_clients_store(): void
    {
        $first  = Gateway_Credential::ensure_client();
        $second = Gateway_Credential::ensure_client();
        $third  = Gateway_Credential::ensure_client();

        $this->assertSame($first['client_id'], $second['client_id']);
        $this->assertSame($first['client_id'], $third['client_id']);
        $this->assertSame(1, Client_Store::count());
    }

    public function test_ensure_client_never_returns_the_plaintext_secret(): void
    {
        $record = Gateway_Credential::ensure_client();

        $this->assertArrayNotHasKey('client_secret', $record);
        $this->assertArrayHasKey('client_secret_hash', $record);
    }

    public function test_issue_for_user_returns_a_redeemable_triple(): void
    {
        $user_id    = $this->admin();
        $credential = Gateway_Credential::issue_for_user($user_id);

        $this->assertArrayHasKey('client_id', $credential);
        $this->assertArrayHasKey('client_secret', $credential);
        $this->assertArrayHasKey('refresh_token', $credential);

        // The whole point: the proxy can redeem this at the token endpoint.
        $result = Token_Grant::exchange([
            'grant_type'    => 'refresh_token',
            'client_id'     => $credential['client_id'],
            'client_secret' => $credential['client_secret'],
            'refresh_token' => $credential['refresh_token'],
        ]);

        $this->assertNotWPError($result);
        $this->assertNotEmpty($result['access_token']);
        $this->assertSame(Gateway_Credential::SCOPE, $result['scope']);

        // And the minted access token authenticates as the bound user.
        $record = Token_Store::validate($result['access_token']);
        $this->assertNotNull($record);
        $this->assertSame($user_id, (int) $record['user_id']);
    }

    public function test_refresh_token_plaintext_is_never_persisted(): void
    {
        $credential = Gateway_Credential::issue_for_user($this->admin());

        $serialized = wp_json_encode([
            get_option(Refresh_Token_Store::OPTION),
            get_option(Client_Store::OPTION),
            get_option(Token_Store::OPTION),
        ]);

        $this->assertStringNotContainsString($credential['refresh_token'], (string) $serialized);
        $this->assertStringNotContainsString($credential['client_secret'], (string) $serialized);
    }

    public function test_reprovisioning_rotates_the_whole_credential(): void
    {
        $user_id = $this->admin();
        $first   = Gateway_Credential::issue_for_user($user_id);

        // Mint an access token off the first credential.
        $minted = Token_Grant::exchange([
            'grant_type'    => 'refresh_token',
            'client_id'     => $first['client_id'],
            'client_secret' => $first['client_secret'],
            'refresh_token' => $first['refresh_token'],
        ]);
        $this->assertNotWPError($minted);
        $this->assertNotNull(Token_Store::validate($minted['access_token']));

        $second = Gateway_Credential::issue_for_user($user_id);

        $this->assertSame($first['client_id'], $second['client_id'], 'the client is stable');
        $this->assertSame(1, Client_Store::count(), 'the clients store does not grow');
        $this->assertNotSame($first['client_secret'], $second['client_secret'], 'the secret rotates');
        $this->assertNotSame($first['refresh_token'], $second['refresh_token']);

        // Everything from the first credential is dead: the old secret, the
        // old refresh token, and the access token already minted from it.
        $this->assertFalse(Client_Store::verify_secret($first['client_id'], $first['client_secret']));
        $this->assertNull(Token_Store::validate($minted['access_token']));

        $replay = Token_Grant::exchange([
            'grant_type'    => 'refresh_token',
            'client_id'     => $second['client_id'],
            'client_secret' => $second['client_secret'],
            'refresh_token' => $first['refresh_token'],
        ]);
        $this->assertWPError($replay);
        $this->assertSame('invalid_grant', $replay->get_error_code());
    }

    public function test_a_password_change_kills_the_gateway_credential(): void
    {
        $user_id    = $this->admin();
        $credential = Gateway_Credential::issue_for_user($user_id);

        wp_set_password('a-completely-different-password', $user_id);
        clean_user_cache($user_id);

        $result = Token_Grant::exchange([
            'grant_type'    => 'refresh_token',
            'client_id'     => $credential['client_id'],
            'client_secret' => $credential['client_secret'],
            'refresh_token' => $credential['refresh_token'],
        ]);

        $this->assertWPError($result);
        $this->assertSame('invalid_grant', $result->get_error_code(), 'no error oracle');
    }

    public function test_a_publicly_registered_lookalike_client_is_never_adopted_as_the_gateway(): void
    {
        // What an anonymous caller can do through the DCR endpoint: pick the
        // name and the redirect URI, and keep the secret it is handed.
        $impostor = Client_Store::create(
            [Gateway_Credential::CLIENT_NAME],
            [Gateway_Credential::REDIRECT_URI],
            '203.0.113.9'
        );

        $this->assertNull(Gateway_Credential::current_client());
        $this->assertFalse(Gateway_Credential::is_provisioned());

        $gateway = Gateway_Credential::ensure_client();
        $this->assertNotSame($impostor['client_id'], $gateway['client_id']);

        // And the impostor's secret does not authenticate as the gateway.
        $this->assertFalse(Client_Store::verify_secret($gateway['client_id'], $impostor['client_secret']));
    }

    public function test_the_gateway_client_survives_the_orphan_sweep(): void
    {
        $record = Gateway_Credential::ensure_client();

        // Well past the grace window, holding no tokens: exactly the shape
        // gc() reaps, and exactly the shape the gateway is in between a
        // chain revocation and the next provision.
        $this->assertSame(0, Client_Store::gc(60, time() + 86400));
        $this->assertNotNull(Client_Store::get($record['client_id']));
        $this->assertTrue(Gateway_Credential::is_provisioned());
    }

    public function test_deprovision_kills_the_client_and_every_bound_token(): void
    {
        $user_id    = $this->admin();
        $credential = Gateway_Credential::issue_for_user($user_id);
        $minted     = Token_Grant::exchange([
            'grant_type'    => 'refresh_token',
            'client_id'     => $credential['client_id'],
            'client_secret' => $credential['client_secret'],
            'refresh_token' => $credential['refresh_token'],
        ]);
        $this->assertNotWPError($minted);

        $this->assertTrue(Gateway_Credential::deprovision());

        $this->assertFalse(Gateway_Credential::is_provisioned());
        $this->assertNull(Client_Store::get($credential['client_id']));
        $this->assertNull(Token_Store::validate($minted['access_token']));
        $this->assertFalse(Refresh_Token_Store::has_tokens_for_client($credential['client_id']));
        $this->assertSame('', (string) get_option(Gateway_Credential::OPTION, ''));
    }

    public function test_deprovision_is_idempotent(): void
    {
        Gateway_Credential::issue_for_user($this->admin());

        $this->assertTrue(Gateway_Credential::deprovision());
        $this->assertFalse(Gateway_Credential::deprovision());
        $this->assertFalse(Gateway_Credential::deprovision());
    }

    public function test_deprovision_converges_from_a_half_revoked_state(): void
    {
        $credential = Gateway_Credential::issue_for_user($this->admin());
        $client_id  = $credential['client_id'];

        // Client row gone, tokens left behind: the state the option pointer
        // is the only remaining way to find.
        $clients = get_option(Client_Store::OPTION);
        unset($clients[ $client_id ]);
        update_option(Client_Store::OPTION, $clients);
        $this->assertTrue(Refresh_Token_Store::has_tokens_for_client($client_id));

        $this->assertTrue(Gateway_Credential::deprovision(), 'a real teardown is not reported as a no-op');
        $this->assertFalse(Refresh_Token_Store::has_tokens_for_client($client_id));
    }

    public function test_deprovision_never_reprovisions(): void
    {
        Gateway_Credential::issue_for_user($this->admin());
        Gateway_Credential::deprovision();

        $this->assertSame(0, Client_Store::count());
        $this->assertFalse(Gateway_Credential::is_provisioned());
    }
}
