<?php

namespace WPMCP\Tests\Pro\Cloud;

use WPMCP\Auth\Bearer_Auth;
use WPMCP\Auth\Client_Store;
use WPMCP\Auth\Refresh_Token_Store;
use WPMCP\Auth\Token_Store;
use WPMCP\Cloud\Cloud_Client;
use WPMCP\Cloud\Gateway_Credential;
use WPMCP\Governance\Governance_Audit_Log;
use WPMCP\Identity\Identity_Context;
use WPMCP\Identity\Identity_Store;
use WPMCP\Pro\Gate;
use WPMCP\Tools\Cloud\Gateway_Provision;
use WPMCP\Tools\Cloud\Gateway_Revoke;
use WPMCP\Tools\Cloud\Gateway_Status;

/**
 * The site's WP MCP Gateway credential (issue #130).
 *
 * What these tests pin, in the order the class's own claims are made:
 *  - provisioning is idempotent at the client level EVEN when the previous
 *    credential minted a live access token, because the kill happens first
 *    and Client_Store::find_reusable() only recycles token-free rows;
 *  - the kill switch is total and local: after revoke() an access token
 *    that was valid a moment earlier no longer authenticates anyone, and
 *    no HTTP request is made to get there (offline-proof);
 *  - the identity binding is server-side state, not a self-asserted scope
 *    string: a token from any other client carrying the same scope text
 *    resolves to no identity at all;
 *  - provisioning refuses without consent, without manage_options, and for
 *    an identity that does not exist in Identity_Store.
 */
class GatewayCredentialTest extends \WP_UnitTestCase
{
    /** @var array<int,array{url:string,method:string}> */
    private array $requests = [];

    private int $admin_id = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
        $this->admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($this->admin_id);

        delete_option(Gateway_Credential::OPTION);
        delete_option(Client_Store::OPTION);
        delete_option(Token_Store::OPTION);
        delete_option(Refresh_Token_Store::OPTION);
        delete_option(Identity_Store::OPTION);
        delete_option(Governance_Audit_Log::OPTION);

        Identity_Store::create('Agency Editor', ['domains' => ['content'], 'operations' => ['read']]);

        update_option('wpmcp_cloud_url', 'https://cloud.example');
        update_option('wpmcp_cloud_key', 'secret-key');

        $this->requests = [];
        add_filter('pre_http_request', [$this, 'fake_http'], 10, 3);
        add_filter('wpmcp_oauth_enabled', '__return_true');
    }

    protected function tearDown(): void
    {
        remove_filter('pre_http_request', [$this, 'fake_http'], 10);
        remove_all_filters('wpmcp_oauth_enabled');
        remove_all_filters('wpmcp_current_identity');
        Identity_Context::set_current_for_tests(null);
        Bearer_Auth::reset_for_tests();
        unset($_SERVER['HTTP_AUTHORIZATION']);
        delete_option('wpmcp_cloud_url');
        delete_option('wpmcp_cloud_key');
        delete_option(Gateway_Credential::OPTION);
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    public function fake_http($pre, $args, $url)
    {
        $this->requests[] = ['url' => $url, 'method' => strtoupper((string) ($args['method'] ?? 'GET'))];

        return [
            'headers'  => [],
            'body'     => (string) wp_json_encode(['ok' => true]),
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies'  => [],
        ];
    }

    /** @return array The provision() success payload, failing the test on WP_Error. */
    private function provision(string $identity = 'Agency Editor'): array
    {
        $result = Gateway_Credential::provision($this->admin_id, $identity, true);
        $this->assertNotWPError($result);
        return $result;
    }

    public function test_provision_returns_plaintext_once_and_stores_no_secrets(): void
    {
        $credential = $this->provision();

        $this->assertStringStartsWith('client_', $credential['client_id']);
        $this->assertStringStartsWith('secret_', $credential['client_secret']);
        $this->assertStringStartsWith('rt_', $credential['refresh_token']);
        $this->assertSame('wpmcp:gateway identity:Agency%20Editor', $credential['scope']);

        $record = Gateway_Credential::record();
        $this->assertSame($credential['client_id'], $record['client_id']);
        $this->assertSame($this->admin_id, $record['user_id']);
        $this->assertSame('Agency Editor', $record['identity']);
        $this->assertNotEmpty($record['chain_id']);

        $flat = (string) wp_json_encode($record);
        $this->assertStringNotContainsString($credential['client_secret'], $flat);
        $this->assertStringNotContainsString($credential['refresh_token'], $flat);
    }

    public function test_identity_name_round_trips_verbatim_to_the_identity_store(): void
    {
        $credential = $this->provision();

        $identity = Gateway_Credential::record()['identity'];
        $this->assertNotNull(
            Identity_Store::get($identity),
            'The bound identity name must resolve in Identity_Store, not a sanitize_key() derivative.'
        );
        $this->assertSame('Agency Editor', Gateway_Credential::identity_from_scope($credential['scope']));
    }

    public function test_reprovisioning_with_a_live_access_token_reuses_the_same_client_id(): void
    {
        $first = $this->provision();

        // Simulate the gateway having exchanged its refresh token for an
        // access token: this is the steady state, and it is exactly the state
        // that defeated Client_Store's fingerprint dedup before.
        Token_Store::issue($first['client_id'], $this->admin_id, $first['scope'], Gateway_Credential::record()['chain_id']);

        $second = $this->provision();

        $this->assertSame($first['client_id'], $second['client_id'], 'Re-provisioning must reuse the gateway client row.');
        $this->assertNotSame($first['client_secret'], $second['client_secret'], 'The secret must rotate.');
        $this->assertCount(1, get_option(Client_Store::OPTION, []), 'Re-provisioning must not accumulate client rows.');
    }

    public function test_reprovisioning_kills_the_previous_refresh_token(): void
    {
        $first = $this->provision();
        $this->provision();

        $this->assertSame('unknown', Refresh_Token_Store::redeem($first['refresh_token'])['status']);
    }

    public function test_revoke_immediately_kills_already_issued_access_tokens(): void
    {
        $credential = $this->provision();
        $access     = Token_Store::issue(
            $credential['client_id'],
            $this->admin_id,
            $credential['scope'],
            Gateway_Credential::record()['chain_id']
        );

        $this->assertNotNull(Token_Store::validate($access), 'Sanity: the access token is live before the revoke.');

        Gateway_Credential::revoke();

        $this->assertNull(
            Token_Store::validate($access),
            'The kill switch must not leave a working access token behind for the rest of its TTL.'
        );
        $this->assertSame('unknown', Refresh_Token_Store::redeem($credential['refresh_token'])['status']);
        $this->assertFalse(Gateway_Credential::is_provisioned());
    }

    public function test_revoke_makes_no_network_call_so_it_works_with_the_cloud_unreachable(): void
    {
        $this->provision();
        $this->requests = [];

        Gateway_Credential::revoke();

        $this->assertSame([], $this->requests, 'Revocation must be purely local.');
    }

    public function test_provision_refuses_without_consent(): void
    {
        $result = Gateway_Credential::provision($this->admin_id, 'Agency Editor', false);

        $this->assertWPError($result);
        $this->assertSame('gateway_consent_required', $result->get_error_code());
        $this->assertFalse(Gateway_Credential::is_provisioned());
        $this->assertSame([], get_option(Client_Store::OPTION, []));
    }

    public function test_provision_refuses_an_identity_that_does_not_exist(): void
    {
        $result = Gateway_Credential::provision($this->admin_id, 'No Such Identity', true);

        $this->assertWPError($result);
        $this->assertSame('gateway_unknown_identity', $result->get_error_code());
        $this->assertSame([], get_option(Client_Store::OPTION, []));
    }

    public function test_provision_refuses_an_empty_identity(): void
    {
        $result = Gateway_Credential::provision($this->admin_id, '   ', true);

        $this->assertWPError($result);
        $this->assertSame('gateway_unknown_identity', $result->get_error_code());
    }

    public function test_provision_refuses_a_non_administrator_caller(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));

        $result = Gateway_Credential::provision($this->admin_id, 'Agency Editor', true);

        $this->assertWPError($result);
        $this->assertSame('gateway_forbidden', $result->get_error_code());
        $this->assertSame([], get_option(Client_Store::OPTION, []));
    }

    public function test_provision_refuses_to_bind_the_credential_to_a_non_administrator(): void
    {
        $subscriber = self::factory()->user->create(['role' => 'subscriber']);

        $result = Gateway_Credential::provision($subscriber, 'Agency Editor', true);

        $this->assertWPError($result);
        $this->assertSame('gateway_user_not_admin', $result->get_error_code());
    }

    public function test_provision_refuses_an_unknown_user_id(): void
    {
        $result = Gateway_Credential::provision(999999, 'Agency Editor', true);

        $this->assertWPError($result);
        $this->assertSame('gateway_unknown_user', $result->get_error_code());
    }

    public function test_bearer_token_from_the_gateway_client_resolves_the_bound_identity(): void
    {
        $credential = $this->provision();
        $access     = Token_Store::issue(
            $credential['client_id'],
            $this->admin_id,
            $credential['scope'],
            Gateway_Credential::record()['chain_id']
        );

        Gateway_Credential::register();
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $access;

        $this->assertSame($this->admin_id, Bearer_Auth::resolve(0));
        $this->assertSame('Agency Editor', Identity_Context::current());
    }

    public function test_a_forged_gateway_scope_on_another_client_grants_no_identity(): void
    {
        $this->provision();

        // A self-registered DCR client cannot promote itself by asking for the
        // gateway scope: the binding is looked up from the stored client_id.
        $forged = Token_Store::issue('client_someone_else', $this->admin_id, 'wpmcp:gateway identity:Agency Editor');

        Gateway_Credential::register();
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $forged;

        $this->assertSame($this->admin_id, Bearer_Auth::resolve(0));
        $this->assertNull(Identity_Context::current(), 'The scope string must not be a self-asserted privilege claim.');
    }

    public function test_is_provisioned_self_heals_when_the_client_row_is_gone(): void
    {
        $this->provision();

        // Oauth_Gc's orphan sweep (Client_Store::gc) can reap the row once the
        // gateway's tokens lapse; the bookkeeping must not keep reporting live.
        delete_option(Client_Store::OPTION);

        $this->assertFalse(Gateway_Credential::is_provisioned());
        $this->assertNull(Gateway_Credential::record());
    }

    public function test_gateway_refresh_token_outlives_the_ordinary_thirty_day_ceiling(): void
    {
        $credential = $this->provision();

        Refresh_Token_Store::set_clock_override(static fn () => time() + (Refresh_Token_Store::TTL_SECONDS * 2));
        $status = Refresh_Token_Store::redeem($credential['refresh_token'])['status'];
        Refresh_Token_Store::set_clock_override(null);

        $this->assertSame('ok', $status, 'The gateway chain carries its own long TTL, not the 30-day session default.');
    }

    public function test_upload_requires_an_https_cloud_url(): void
    {
        update_option('wpmcp_cloud_url', 'http://cloud.example');
        $credential = $this->provision();
        $this->requests = [];

        $result = Gateway_Credential::upload(new Cloud_Client(), $credential);

        $this->assertWPError($result);
        $this->assertSame('gateway_insecure_cloud_url', $result->get_error_code());
        $this->assertSame([], $this->requests, 'Nothing may go on the wire in cleartext.');
    }

    public function test_upload_stamps_the_record_once_and_refuses_a_second_upload(): void
    {
        $credential = $this->provision();

        $this->assertTrue(Gateway_Credential::upload(new Cloud_Client(), $credential));
        $this->assertGreaterThan(0, Gateway_Credential::record()['uploaded_at']);

        $again = Gateway_Credential::upload(new Cloud_Client(), $credential);
        $this->assertWPError($again);
        $this->assertSame('gateway_already_uploaded', $again->get_error_code());
    }

    public function test_upload_refuses_a_credential_that_is_not_the_current_one(): void
    {
        $stale = $this->provision();
        $this->provision();

        $result = Gateway_Credential::upload(new Cloud_Client(), $stale);

        $this->assertWPError($result);
        $this->assertSame('gateway_stale_credential', $result->get_error_code());
    }

    public function test_provision_and_revoke_are_audited_without_leaking_plaintext(): void
    {
        $credential = $this->provision();
        Gateway_Credential::revoke();

        $entries = Governance_Audit_Log::list();
        $names   = array_column($entries, 'ability');
        $this->assertContains('cloud/gateway-credential-provision', $names);
        $this->assertContains('cloud/gateway-credential-revoke', $names);

        $flat = (string) wp_json_encode($entries);
        $this->assertStringNotContainsString($credential['client_secret'], $flat);
        $this->assertStringNotContainsString($credential['refresh_token'], $flat);
        $this->assertStringContainsString('Agency Editor', $flat);
    }

    public function test_provision_tool_refuses_without_the_consent_flag(): void
    {
        $result = (new Gateway_Provision())->handle(['identity' => 'Agency Editor']);

        $this->assertWPError($result);
        $this->assertSame('gateway_consent_required', $result->get_error_code());
    }

    public function test_provision_tool_returns_plaintext_once_and_uploads(): void
    {
        $result = (new Gateway_Provision())->handle(['identity' => 'Agency Editor', 'consent' => true]);

        $this->assertNotWPError($result);
        $this->assertTrue($result['provisioned']);
        $this->assertTrue($result['uploaded']);
        $this->assertNotEmpty($result['refresh_token']);
        $this->assertSame(
            'https://cloud.example/wpmcp-cloud/v1/gateway/credential',
            $this->requests[0]['url'] ?? ''
        );

        // The status tool is the only way back to this credential, and it
        // must never be a second look at the plaintext.
        $status = (new Gateway_Status())->handle([]);
        $this->assertTrue($status['provisioned']);
        $this->assertSame('Agency Editor', $status['identity']);
        $flat = (string) wp_json_encode($status);
        $this->assertStringNotContainsString($result['client_secret'], $flat);
        $this->assertStringNotContainsString($result['refresh_token'], $flat);
    }

    public function test_revoke_tool_reports_whether_there_was_anything_to_kill(): void
    {
        $this->assertFalse((new Gateway_Revoke())->handle([])['was_provisioned']);

        $this->provision();

        $this->assertTrue((new Gateway_Revoke())->handle([])['was_provisioned']);
        $this->assertFalse(Gateway_Credential::is_provisioned());
    }
}
