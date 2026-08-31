<?php

namespace WPMCP\Tests\Pro\Cloud;

use WPMCP\Auth\Bearer_Auth;
use WPMCP\Auth\Client_Store;
use WPMCP\Auth\Refresh_Token_Store;
use WPMCP\Auth\Token_Grant;
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
    /** @var array<int,array{url:string,method:string,args:array}> */
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
        unset($_SERVER['HTTP_AUTHORIZATION'], $_GET['rest_route']);
        $_SERVER['REQUEST_URI'] = '/';
        delete_option('wpmcp_cloud_url');
        delete_option('wpmcp_cloud_key');
        delete_option(Gateway_Credential::OPTION);
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    public function fake_http($pre, $args, $url)
    {
        $this->requests[] = [
            'url'    => $url,
            'method' => strtoupper((string) ($args['method'] ?? 'GET')),
            'args'   => is_array($args) ? $args : [],
        ];

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
        $result = Gateway_Credential::provision($this->admin_id, $identity, true, true);
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
        $this->on_mcp_route();
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $access;

        $this->assertSame($this->admin_id, Bearer_Auth::resolve(0));
        $this->assertSame('Agency Editor', Identity_Context::current());
    }

    public function test_a_forged_gateway_scope_only_ever_narrows_the_forger(): void
    {
        $this->provision();

        // A self-registered DCR client can ask for the gateway scope, and
        // asking buys it nothing: an identity narrows what
        // Registrar::is_permitted() allows, it never grants, and claiming the
        // gateway scope also drags the forger inside the gateway's own
        // surface restriction.
        $forged = Token_Store::issue('client_someone_else', $this->admin_id, 'wpmcp:gateway identity:Agency Editor');

        Gateway_Credential::register();
        $this->on_mcp_route();
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $forged;

        $this->assertSame($this->admin_id, Bearer_Auth::resolve(0));
        $this->assertSame('Agency Editor', Identity_Context::current());

        // ... and it cannot use the claim to reach the rest of the site.
        Bearer_Auth::reset_for_tests();
        $this->on_core_rest_route();
        $this->assertSame(0, Bearer_Auth::resolve(0));
    }

    public function test_a_forged_scope_naming_no_real_identity_is_denied_not_unrestricted(): void
    {
        $this->provision();

        $forged = Token_Store::issue('client_someone_else', $this->admin_id, 'wpmcp:gateway identity:No Such Identity');

        Gateway_Credential::register();
        $this->on_mcp_route();
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $forged;

        Bearer_Auth::resolve(0);

        $this->assertSame('No Such Identity', Identity_Context::current());
        $this->assertNull(Identity_Store::get('No Such Identity'), 'An unresolvable identity denies in Governance.');
    }

    public function test_is_provisioned_self_heals_when_the_client_row_is_gone(): void
    {
        $this->provision();

        // Oauth_Gc's orphan sweep (Client_Store::gc) can reap the row once the
        // gateway's tokens lapse; the bookkeeping must not keep reporting live.
        delete_option(Client_Store::OPTION);

        $this->assertFalse(Gateway_Credential::is_provisioned());
        $this->assertNull(Gateway_Credential::record());

        Gateway_Credential::prune();
        $this->assertFalse(get_option(Gateway_Credential::OPTION, false));
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
        $this->assertSame('ok', $result['upload_status']);
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
        $this->assertFalse((new Gateway_Revoke())->handle(['confirm' => true])['was_provisioned']);

        $this->provision();

        $result = (new Gateway_Revoke())->handle(['confirm' => true]);
        $this->assertTrue($result['was_provisioned']);
        $this->assertFalse(Gateway_Credential::is_provisioned());
    }

    /** Put the request on the MCP transport surface the gateway is allowed to use. */
    private function on_mcp_route(): void
    {
        $_SERVER['REQUEST_URI'] = '/wp-json/mcp/wpmcp/mcp';
    }

    /** Put the request on a core REST route the gateway credential must not reach. */
    private function on_core_rest_route(): void
    {
        $_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/users?context=edit';
    }

    /** A live gateway access token on the recorded chain. */
    private function gateway_access_token(array $credential): string
    {
        return Token_Store::issue(
            $credential['client_id'],
            $this->admin_id,
            $credential['scope'],
            Gateway_Credential::record()['chain_id']
        );
    }

    // ------------------------------------------------------- blast radius

    public function test_the_gateway_token_does_not_authenticate_on_core_rest_routes(): void
    {
        $credential = $this->provision();
        $access     = $this->gateway_access_token($credential);

        Gateway_Credential::register();
        $this->on_core_rest_route();
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $access;

        $this->assertSame(
            0,
            Bearer_Auth::resolve(0),
            'A gateway credential must not be an administrator on /wp/v2/users.'
        );
        $this->assertSame('', Bearer_Auth::current_client_id());
    }

    public function test_the_gateway_token_does_not_authenticate_off_the_rest_api_entirely(): void
    {
        $credential = $this->provision();
        $access     = $this->gateway_access_token($credential);

        Gateway_Credential::register();
        $_SERVER['REQUEST_URI']        = '/wp-admin/admin-ajax.php?action=whatever';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $access;

        $this->assertSame(0, Bearer_Auth::resolve(0), 'admin-ajax is not the gateway surface.');
    }

    public function test_an_ordinary_oauth_token_still_authenticates_anywhere(): void
    {
        $client = Client_Store::create(['Some MCP Client'], ['https://example.test/cb'], 'dcr');
        $access = Token_Store::issue($client['client_id'], $this->admin_id, 'openid');

        Gateway_Credential::register();
        $this->on_core_rest_route();
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $access;

        $this->assertSame(
            $this->admin_id,
            Bearer_Auth::resolve(0),
            'The surface restriction is for the gateway credential only, not for OAuth as a whole.'
        );
    }

    // ------------------------------------------------- identity fails closed

    public function test_the_identity_survives_the_loss_of_the_bookkeeping_option(): void
    {
        $credential = $this->provision();
        $access     = $this->gateway_access_token($credential);

        // The option is mutable bookkeeping; losing it must not promote a live
        // gateway token from identity-scoped to full administrator.
        delete_option(Gateway_Credential::OPTION);

        Gateway_Credential::register();
        $this->on_mcp_route();
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $access;

        $this->assertSame($this->admin_id, Bearer_Auth::resolve(0));
        $this->assertSame('Agency Editor', Identity_Context::current());
    }

    public function test_a_gateway_token_whose_identity_was_deleted_denies_rather_than_promotes(): void
    {
        $credential = $this->provision();
        $access     = $this->gateway_access_token($credential);

        delete_option(Gateway_Credential::OPTION);
        Identity_Store::delete('Agency Editor');

        Gateway_Credential::register();
        $this->on_mcp_route();
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $access;

        Bearer_Auth::resolve(0);

        $current = Identity_Context::current();
        $this->assertNotNull($current, 'A null identity is the unrestricted case; a gateway token must never land there.');
        $this->assertNull(Identity_Store::get((string) $current), 'An unresolvable identity is a deny in Governance.');
    }

    public function test_a_gateway_token_carrying_no_resolvable_name_gets_the_deny_sentinel(): void
    {
        $credential = $this->provision();

        // Bookkeeping with the identity stripped out and a scope that names
        // nothing: the shape a half-written option would leave behind.
        $record = get_option(Gateway_Credential::OPTION);
        $record['identity'] = '';
        update_option(Gateway_Credential::OPTION, $record);

        $access = Token_Store::issue(
            $credential['client_id'],
            $this->admin_id,
            Gateway_Credential::SCOPE_PREFIX,
            $record['chain_id']
        );

        Gateway_Credential::register();
        $this->on_mcp_route();
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $access;

        Bearer_Auth::resolve(0);

        $current = Identity_Context::current();
        $this->assertSame(Gateway_Credential::UNBOUND_IDENTITY, $current);
        $this->assertNull(Identity_Store::get((string) $current), 'The sentinel must not resolve, so governance denies.');
    }

    // ------------------------------------------------------ refusal paths

    public function test_provision_refuses_while_oauth_is_disabled(): void
    {
        remove_all_filters('wpmcp_oauth_enabled');
        add_filter('wpmcp_oauth_enabled', '__return_false');

        $result = Gateway_Credential::provision($this->admin_id, 'Agency Editor', true, true);

        $this->assertWPError($result);
        $this->assertSame('gateway_oauth_disabled', $result->get_error_code());
        $this->assertSame([], get_option(Client_Store::OPTION, []), 'Nothing may be minted for a credential that cannot work.');
    }

    public function test_provision_refuses_to_replace_a_live_credential_without_replace(): void
    {
        $first = $this->provision();

        $result = Gateway_Credential::provision($this->admin_id, 'Agency Editor', true);

        $this->assertWPError($result);
        $this->assertSame('gateway_already_provisioned', $result->get_error_code());
        $this->assertSame(
            $first['client_id'],
            Gateway_Credential::record()['client_id'],
            'A refused re-provision must leave the live credential alone.'
        );
        $this->assertSame('ok', Refresh_Token_Store::redeem($first['refresh_token'], $first['client_id'])['status']);
    }

    public function test_reprovisioning_at_the_client_cap_leaves_the_previous_credential_intact(): void
    {
        $first = $this->provision();

        // A store already at its cap must refuse BEFORE the destructive part
        // of provisioning runs, or a re-provision bricks a working gateway.
        add_filter('wpmcp_oauth_max_clients', '__return_zero');
        delete_option(Client_Store::OPTION);

        $result = Gateway_Credential::provision($this->admin_id, 'Agency Editor', true, true);
        remove_all_filters('wpmcp_oauth_max_clients');

        $this->assertWPError($result);
        $this->assertSame('gateway_client_cap', $result->get_error_code());
        $this->assertSame(
            'ok',
            Refresh_Token_Store::redeem($first['refresh_token'], $first['client_id'])['status'],
            'The previous chain must survive a refusal.'
        );
    }

    public function test_every_refusal_path_is_audited(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        Gateway_Credential::provision($this->admin_id, 'Agency Editor', true, true);
        wp_set_current_user($this->admin_id);

        Gateway_Credential::provision($this->admin_id, 'Agency Editor', false, true);
        Gateway_Credential::provision(999999, 'Agency Editor', true, true);

        $denials = array_values(array_filter(
            Governance_Audit_Log::list(),
            static fn ($e) => 'cloud/gateway-credential-provision' === $e['ability'] && ! $e['allowed']
        ));
        $reasons = array_column($denials, 'reason');

        $this->assertCount(3, $denials, 'Every refusal leaves a trace, especially the forbidden caller.');
        $this->assertStringContainsString('forbidden', implode(' ', $reasons));
        $this->assertStringContainsString('consent_required', implode(' ', $reasons));
        $this->assertStringContainsString('unknown_user', implode(' ', $reasons));
    }

    public function test_revoke_refuses_a_caller_without_manage_options(): void
    {
        $credential = $this->provision();
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));

        $result = Gateway_Credential::revoke();

        $this->assertWPError($result);
        $this->assertSame('gateway_forbidden', $result->get_error_code());
        $this->assertTrue(Gateway_Credential::is_provisioned());
        $this->assertSame('ok', Refresh_Token_Store::redeem($credential['refresh_token'], $credential['client_id'])['status']);
    }

    public function test_revoke_tool_requires_an_explicit_confirm(): void
    {
        $this->provision();

        $result = (new Gateway_Revoke())->handle([]);

        $this->assertWPError($result);
        $this->assertSame('gateway_confirm_required', $result->get_error_code());
        $this->assertTrue(Gateway_Credential::is_provisioned());
    }

    // ---------------------------------------------- revoke ordering + counts

    public function test_revoke_kills_live_tokens_even_when_the_client_row_is_gone(): void
    {
        $credential = $this->provision();
        $access     = $this->gateway_access_token($credential);

        // Oauth_Gc reaped the client row; the access token is still live, and
        // a self-heal that deletes the bookkeeping first would strand it.
        delete_option(Client_Store::OPTION);

        $killed = Gateway_Credential::revoke();

        $this->assertIsInt($killed);
        $this->assertGreaterThan(0, $killed);
        $this->assertNull(Token_Store::validate($access), 'A missing client row must not save a live token from the kill switch.');
        $this->assertSame('unknown', Refresh_Token_Store::redeem($credential['refresh_token'])['status']);
    }

    public function test_record_is_a_pure_read(): void
    {
        $this->provision();
        delete_option(Client_Store::OPTION);

        Gateway_Credential::record();

        $this->assertIsArray(
            get_option(Gateway_Credential::OPTION, null),
            'Reading the record must not write to the options table.'
        );
    }

    public function test_revoke_tool_reports_how_much_it_killed(): void
    {
        $credential = $this->provision();
        $this->gateway_access_token($credential);

        $result = (new Gateway_Revoke())->handle(['confirm' => true]);

        $this->assertTrue($result['was_provisioned']);
        $this->assertGreaterThan(0, $result['killed']);
    }

    // ------------------------------------------------------------- upload

    public function test_upload_refuses_to_follow_a_redirect_and_pins_tls(): void
    {
        $credential = $this->provision();

        $this->assertTrue(Gateway_Credential::upload(new Cloud_Client(), $credential));

        $args = $this->requests[0]['args'] ?? [];
        $this->assertSame(0, $args['redirection'] ?? null, 'A 30x must not replay the secret at a new location.');
        $this->assertTrue($args['sslverify'] ?? null);
    }

    public function test_upload_says_not_connected_rather_than_insecure_when_there_is_no_cloud(): void
    {
        $credential = $this->provision();
        delete_option('wpmcp_cloud_url');
        delete_option('wpmcp_cloud_key');
        $this->requests = [];

        $result = Gateway_Credential::upload(new Cloud_Client(), $credential);

        $this->assertWPError($result);
        $this->assertSame('gateway_cloud_not_configured', $result->get_error_code());
        $this->assertSame([], $this->requests);
    }

    public function test_provision_tool_reports_a_skipped_upload_distinctly(): void
    {
        $result = (new Gateway_Provision())->handle([
            'identity' => 'Agency Editor',
            'consent'  => true,
            'upload'   => false,
        ]);

        $this->assertSame('skipped', $result['upload_status']);
        $this->assertFalse($result['uploaded']);
        $this->assertSame([], $this->requests);
    }

    // ------------------------------------------- the credential end to end

    public function test_the_provisioned_credential_actually_refreshes_and_keeps_its_ttl(): void
    {
        $credential = $this->provision();

        $tokens = Token_Grant::exchange([
            'grant_type'    => 'refresh_token',
            'client_id'     => $credential['client_id'],
            'client_secret' => $credential['client_secret'],
            'refresh_token' => $credential['refresh_token'],
        ]);

        $this->assertNotWPError($tokens);
        $this->assertStringStartsWith('at_', $tokens['access_token']);
        $this->assertStringStartsWith('rt_', $tokens['refresh_token']);
        $this->assertSame($credential['scope'], $tokens['scope']);

        // The rotated record must still carry the gateway lifetime, or the
        // credential quietly becomes a 30-day one the first time it refreshes.
        Refresh_Token_Store::set_clock_override(static fn () => time() + (Refresh_Token_Store::TTL_SECONDS * 2));
        $status = Refresh_Token_Store::redeem($tokens['refresh_token'], $credential['client_id'])['status'];
        Refresh_Token_Store::set_clock_override(null);

        $this->assertSame('ok', $status, 'The ten-year TTL must survive rotation.');
    }

    public function test_a_rotated_access_token_still_resolves_the_bound_identity(): void
    {
        $credential = $this->provision();

        $tokens = Token_Grant::exchange([
            'grant_type'    => 'refresh_token',
            'client_id'     => $credential['client_id'],
            'client_secret' => $credential['client_secret'],
            'refresh_token' => $credential['refresh_token'],
        ]);
        $this->assertNotWPError($tokens);

        Gateway_Credential::register();
        $this->on_mcp_route();
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $tokens['access_token'];

        $this->assertSame($this->admin_id, Bearer_Auth::resolve(0));
        $this->assertSame('Agency Editor', Identity_Context::current());
    }
}
