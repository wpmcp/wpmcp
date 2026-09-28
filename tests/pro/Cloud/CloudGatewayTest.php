<?php

namespace WPMCP\Tests\Pro\Cloud;

use WPMCP\Auth\Client_Store;
use WPMCP\Auth\Refresh_Token_Store;
use WPMCP\Auth\Token_Grant;
use WPMCP\Auth\Token_Store;
use WPMCP\Cloud\Cloud_Client;
use WPMCP\Cloud\Cloud_Config;
use WPMCP\Cloud\Cloud_Credentials;
use WPMCP\Cloud\Gateway_Cloud;
use WPMCP\Cloud\Gateway_Consent;
use WPMCP\Gateway\Gateway_Binding;
use WPMCP\Gateway\Gateway_Credential;
use WPMCP\Governance\Governance_Audit_Log;
use WPMCP\Identity\Identity_Store;
use WPMCP\Pro\Gate;
use WPMCP\Tools\Cloud\Cloud_Connect;
use WPMCP\Tools\Cloud\Cloud_Gateway_Provision;
use WPMCP\Tools\Cloud\Cloud_Gateway_Status;

/**
 * The cloud-facing layer over the site's one gateway credential
 * (issue #130): identity-bound provisioning, the once-only upload, and the
 * cloud-connect gateway consent (default off, withdrawal kills what the
 * cloud holds). The credential itself is #142's and is covered in
 * tests/free/Gateway; what it may do once it authenticates is covered in
 * tests/free/Gateway/GatewayGuardTest.php.
 */
class CloudGatewayTest extends \WP_UnitTestCase
{
    /** @var array<int,array{url:string,method:string,args:array}> */
    private array $requests = [];

    private int $admin_id = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
        $this->reset();

        $this->admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($this->admin_id);
        Identity_Store::create('Agency Editor', ['domains' => ['content'], 'operations' => ['read']]);

        Cloud_Credentials::clear();
        Cloud_Config::set('https://cloud.example', 'secret-key');
        // Most tests exercise the upload, which needs the cloud-connect
        // consent. The consent tests start from the real default.
        update_option(Gateway_Consent::OPTION, ['granted' => true, 'user_id' => $this->admin_id, 'at' => time()]);

        $this->requests = [];
        add_filter('pre_http_request', [$this, 'fake_http'], 10, 3);
        add_filter('wpmcp_oauth_enabled', '__return_true');
    }

    protected function tearDown(): void
    {
        remove_filter('pre_http_request', [$this, 'fake_http'], 10);
        remove_all_filters('wpmcp_oauth_enabled');
        Cloud_Credentials::clear();
        $this->reset();
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function reset(): void
    {
        foreach ([Client_Store::OPTION, Token_Store::OPTION, Refresh_Token_Store::OPTION, Gateway_Credential::OPTION, Gateway_Binding::OPTION, Gateway_Consent::OPTION, Identity_Store::OPTION, Governance_Audit_Log::OPTION] as $option) {
            delete_option($option);
        }
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
            'body'     => (string) wp_json_encode(['ok' => true, 'account' => ['id' => 1]]),
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies'  => [],
            'filename' => null,
        ];
    }

    private function provision(array $args = []): array
    {
        $result = (new Cloud_Gateway_Provision())->handle(array_merge(['identity' => 'Agency Editor', 'consent' => true], $args));
        $this->assertNotWPError($result);

        return $result;
    }

    private function gateway_requests(): array
    {
        return array_values(array_filter($this->requests, static fn($r) => str_ends_with($r['url'], '/gateway/credential')));
    }

    // ------------------------------------------------------------ provision

    public function test_provision_mints_the_one_gateway_credential_bound_to_the_identity_and_uploads_it(): void
    {
        $result = $this->provision();

        $this->assertTrue($result['provisioned']);
        $this->assertSame('ok', $result['upload_status']);
        $this->assertSame('Agency Editor', $result['identity']);
        $this->assertNotEmpty($result['operation_id'], 'A mutating ability runs through Safe_Mutation.');
        $this->assertSame((string) Gateway_Credential::current_client()['client_id'], $result['client_id'], 'It is #142\'s credential, not a second client.');
        $this->assertSame(1, Client_Store::count());

        $sent = $this->gateway_requests();
        $this->assertCount(1, $sent);
        $this->assertSame('https://cloud.example/wpmcp-cloud/v1/gateway/credential', $sent[0]['url']);
        $this->assertSame(0, $sent[0]['args']['redirection'], 'Never follow a redirect with the secret in the body.');
        $this->assertTrue($sent[0]['args']['sslverify']);

        $granted = Token_Grant::exchange([
            'grant_type'    => 'refresh_token',
            'client_id'     => $result['client_id'],
            'client_secret' => $result['client_secret'],
            'refresh_token' => $result['refresh_token'],
        ]);
        $this->assertIsArray($granted, 'What is handed over must be redeemable.');
        $this->assertSame('Agency Editor', Gateway_Binding::identity_for_client($result['client_id']));
    }

    public function test_status_reports_the_binding_and_never_a_secret(): void
    {
        $result = $this->provision();
        $status = (new Cloud_Gateway_Status())->handle([]);

        $this->assertTrue($status['provisioned']);
        $this->assertTrue($status['identity_bound']);
        $this->assertSame('Agency Editor', $status['identity']);
        $this->assertGreaterThan(0, $status['uploaded_at']);
        $this->assertTrue($status['cloud_consent']);

        $flat = (string) wp_json_encode($status);
        $this->assertStringNotContainsString($result['client_secret'], $flat);
        $this->assertStringNotContainsString($result['refresh_token'], $flat);
    }

    public function test_replacing_requires_replace_and_kills_the_previous_credential(): void
    {
        $first = $this->provision();

        $refused = (new Cloud_Gateway_Provision())->handle(['identity' => 'Agency Editor', 'consent' => true]);
        $this->assertWPError($refused);
        $this->assertSame('gateway_already_provisioned', $refused->get_error_code());

        $second = $this->provision(['replace' => true]);
        $this->assertSame($first['client_id'], $second['client_id'], 'The same client row, rotated.');
        $this->assertNotSame('ok', Refresh_Token_Store::redeem($first['refresh_token'], $first['client_id'])['status']);
        $this->assertSame(1, Client_Store::count());
    }

    public function test_every_refusal_happens_before_minting_and_is_audited(): void
    {
        $cases = [
            'gateway_consent_required'  => ['identity' => 'Agency Editor'],
            'gateway_unknown_identity'  => ['identity' => 'No Such Identity', 'consent' => true],
            'gateway_unknown_identity ' => ['identity' => '', 'consent' => true],
        ];
        foreach ($cases as $code => $args) {
            $result = (new Cloud_Gateway_Provision())->handle($args);
            $this->assertWPError($result);
            $this->assertSame(trim($code), $result->get_error_code());
        }

        remove_all_filters('wpmcp_oauth_enabled');
        add_filter('wpmcp_oauth_enabled', '__return_false');
        $this->assertSame('gateway_oauth_disabled', (new Cloud_Gateway_Provision())->handle(['identity' => 'Agency Editor', 'consent' => true])->get_error_code());

        add_filter('wpmcp_oauth_enabled', '__return_true', 20);
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        $this->assertSame('gateway_forbidden', (new Cloud_Gateway_Provision())->handle(['identity' => 'Agency Editor', 'consent' => true])->get_error_code());

        $this->assertFalse(Gateway_Credential::is_provisioned(), 'Nothing was minted by any refused call.');
        $this->assertSame(0, Client_Store::count());
        $this->assertSame([], $this->gateway_requests());

        $refusals = array_filter(Governance_Audit_Log::list(), static fn($r) => 'gateway/credential-provision' === $r['ability'] && ! $r['allowed']);
        $this->assertCount(5, $refusals);
    }

    // ---------------------------------------------------------------- upload

    public function test_upload_refuses_a_non_https_cloud(): void
    {
        Cloud_Config::set('http://cloud.example', 'secret-key');
        $result = $this->provision();

        $this->assertSame('failed', $result['upload_status']);
        $this->assertSame([], $this->gateway_requests(), 'Nothing goes on the wire in cleartext.');
    }

    public function test_upload_refuses_a_stale_payload_and_a_second_upload(): void
    {
        $credential = Gateway_Cloud::mint($this->admin_id, 'Agency Editor');
        $this->assertTrue(Gateway_Cloud::upload(new Cloud_Client(), $credential));

        $again = Gateway_Cloud::upload(new Cloud_Client(), $credential);
        $this->assertSame('gateway_already_uploaded', $again->get_error_code());

        Gateway_Cloud::mint($this->admin_id, 'Agency Editor');
        $stale = Gateway_Cloud::upload(new Cloud_Client(), $credential);
        $this->assertSame('gateway_stale_credential', $stale->get_error_code());
    }

    public function test_an_unbound_credential_from_the_free_tool_is_never_uploaded(): void
    {
        $credential = Gateway_Credential::issue_for_user($this->admin_id);
        $result     = Gateway_Cloud::upload(new Cloud_Client(), $credential);

        $this->assertWPError($result);
        $this->assertSame('gateway_not_provisioned', $result->get_error_code());
        $this->assertSame([], $this->gateway_requests());
    }

    // ------------------------------------------------ cloud-connect consent

    public function test_cloud_consent_defaults_off_and_keeps_the_credential_local(): void
    {
        delete_option(Gateway_Consent::OPTION);
        $this->assertFalse(Gateway_Consent::granted());

        $result = $this->provision();

        $this->assertSame('consent_required', $result['upload_status']);
        $this->assertNotEmpty($result['refresh_token'], 'The once-only plaintext is still handed over for local use.');
        $this->assertSame([], $this->gateway_requests());
        $this->assertFalse((new Cloud_Gateway_Status())->handle([])['cloud_consent']);
    }

    public function test_cloud_connect_records_consent_only_when_explicitly_ticked(): void
    {
        delete_option(Gateway_Consent::OPTION);

        $plain = (new Cloud_Connect())->handle(['url' => 'https://cloud.example', 'key' => 'k']);
        $this->assertNotWPError($plain);
        $this->assertFalse($plain['gateway_consent']);

        $truthy = (new Cloud_Connect())->handle(['url' => 'https://cloud.example', 'key' => 'k', 'gateway_consent' => 'yes']);
        $this->assertFalse($truthy['gateway_consent'], 'Only a real boolean true is consent.');

        $ticked = (new Cloud_Connect())->handle(['url' => 'https://cloud.example', 'key' => 'k', 'gateway_consent' => true]);
        $this->assertTrue($ticked['gateway_consent']);
        $this->assertTrue(Gateway_Consent::granted());
        $this->assertSame($this->admin_id, Gateway_Consent::state()['user_id']);
    }

    public function test_withdrawing_consent_kills_a_credential_the_cloud_holds(): void
    {
        $result = $this->provision();
        $this->assertSame('ok', $result['upload_status']);

        $connect = (new Cloud_Connect())->handle(['url' => 'https://cloud.example', 'key' => 'k']);

        $this->assertFalse($connect['gateway_consent']);
        $this->assertTrue($connect['gateway_revoked']);
        $this->assertFalse(Gateway_Credential::is_provisioned());
        $this->assertNull(Gateway_Binding::raw());
        $this->assertNotSame('ok', Refresh_Token_Store::redeem($result['refresh_token'], $result['client_id'])['status']);
    }

    public function test_withdrawing_consent_leaves_a_credential_the_cloud_never_received(): void
    {
        $this->provision(['upload' => false]);

        $connect = (new Cloud_Connect())->handle(['url' => 'https://cloud.example', 'key' => 'k', 'gateway_consent' => false]);

        $this->assertFalse($connect['gateway_revoked']);
        $this->assertTrue(Gateway_Credential::is_provisioned());
    }
}
