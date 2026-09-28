<?php

namespace WPMCP\Tests\Free\Auth;

use WPMCP\Admin\Connection_Page;
use WPMCP\Auth\Authorization_Grant;
use WPMCP\Auth\Authorization_Server_Metadata;
use WPMCP\Auth\Client_Metadata_Document;
use WPMCP\Auth\Client_Store;
use WPMCP\Auth\Code_Store;
use WPMCP\Auth\Refresh_Token_Store;
use WPMCP\Auth\Token_Grant;
use WPMCP\Auth\Token_Store;
use WPMCP\Governance\Governance_Audit_Log;

/**
 * Issue #388: OAuth Client ID Metadata Documents (CIMD).
 *
 * The MCP authorization spec prefers CIMD over dynamic client registration:
 * the client_id is an https URL, the authorization server fetches the JSON
 * document at that URL, requires its client_id to equal the URL exactly, and
 * takes the redirect_uris from it. CIMD clients are public clients (no
 * secret), so the token endpoint accepts them with PKCE alone.
 *
 * The fetch is SSRF-guarded (https only, default port, a host that is not
 * and does not resolve to a private address, no redirects, a small byte cap)
 * and cached, honouring Cache-Control. A site owner can require approval of
 * every newly seen client from the Connection screen.
 */
class ClientMetadataDocumentTest extends \WP_UnitTestCase
{
    private const CLIENT_ID = 'https://client.example.com/oauth/client-metadata.json';
    private const REDIRECT  = 'http://127.0.0.1:3000/callback';
    private const VERIFIER  = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
    private const CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

    /** @var array<int, string> URLs the fake transport was asked for. */
    private array $requests = [];

    /** @var array{code: int, headers: array, body: string} */
    private array $response;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reset_state();
        $this->requests = [];
        $this->response = $this->document_response($this->document());

        add_filter('pre_http_request', [$this, 'fake_transport'], 10, 3);
        add_filter('wpmcp_remote_host_addresses', [$this, 'public_addresses'], 10, 2);
    }

    protected function tearDown(): void
    {
        remove_filter('pre_http_request', [$this, 'fake_transport'], 10);
        remove_filter('wpmcp_remote_host_addresses', [$this, 'public_addresses'], 10);
        remove_all_filters('wpmcp_oauth_cimd_enabled');
        $this->reset_state();
        wp_set_current_user(0);
        parent::tearDown();
    }

    private function reset_state(): void
    {
        delete_option(Client_Store::OPTION);
        delete_option(Code_Store::OPTION);
        delete_option(Token_Store::OPTION);
        delete_option(Refresh_Token_Store::OPTION);
        delete_option(Governance_Audit_Log::OPTION);
        delete_option(Client_Metadata_Document::REGISTRY_OPTION);
        delete_option(Client_Metadata_Document::APPROVAL_OPTION);
        Client_Metadata_Document::flush_cache(self::CLIENT_ID);
        Client_Metadata_Document::flush_cache('https://client.example.com/other.json');
    }

    public function fake_transport($pre, array $args, string $url)
    {
        $this->requests[] = $url;

        return [
            'headers'  => $this->response['headers'],
            'body'     => $this->response['body'],
            'response' => ['code' => $this->response['code'], 'message' => ''],
            'cookies'  => [],
            'filename' => null,
        ];
    }

    /** @return string[] */
    public function public_addresses($addresses, string $host): array
    {
        return 'internal.example.com' === $host ? ['10.0.0.5'] : ['93.184.216.34'];
    }

    private function document(array $overrides = []): array
    {
        return array_merge([
            'client_id'                  => self::CLIENT_ID,
            'client_name'                => 'Example MCP Client',
            'redirect_uris'              => [self::REDIRECT],
            'grant_types'                => ['authorization_code', 'refresh_token'],
            'response_types'             => ['code'],
            'token_endpoint_auth_method' => 'none',
        ], $overrides);
    }

    private function document_response(array $document, array $headers = []): array
    {
        return [
            'code'    => 200,
            'headers' => array_merge(['content-type' => 'application/json'], $headers),
            'body'    => (string) wp_json_encode($document),
        ];
    }

    private function log_in(string $role = 'subscriber'): int
    {
        $user = self::factory()->user->create(['role' => $role]);
        wp_set_current_user($user);
        return $user;
    }

    private function authorize(string $client_id = self::CLIENT_ID, string $redirect = self::REDIRECT)
    {
        return Authorization_Grant::authorize([
            'response_type'         => 'code',
            'client_id'             => $client_id,
            'redirect_uri'          => $redirect,
            'code_challenge'        => self::CHALLENGE,
            'code_challenge_method' => 'S256',
            'scope'                 => 'mcp',
        ]);
    }

    private function exchange(string $code, array $extra = [])
    {
        return Token_Grant::exchange(array_merge([
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => self::REDIRECT,
            'client_id'     => self::CLIENT_ID,
            'code_verifier' => self::VERIFIER,
        ], $extra));
    }

    private function admin_post(string $action, array $extra = []): ?array
    {
        return (new Connection_Page())->handle_request(array_merge([
            'wpmcp_connection_action' => $action,
            '_wpnonce'                => wp_create_nonce(Connection_Page::NONCE_ACTION),
        ], $extra));
    }

    // Discovery.

    public function test_authorization_server_metadata_advertises_cimd_support(): void
    {
        $doc = Authorization_Server_Metadata::build('https://example.com');

        $this->assertTrue($doc['client_id_metadata_document_supported']);
        $this->assertContains('none', $doc['token_endpoint_auth_methods_supported']);
        // Dynamic registration stays available for older clients.
        $this->assertSame('https://example.com/wp-json/wpmcp/v1/oauth/register', $doc['registration_endpoint']);
    }

    public function test_cimd_can_be_switched_off_and_then_is_not_advertised_or_accepted(): void
    {
        add_filter('wpmcp_oauth_cimd_enabled', '__return_false');
        $this->log_in();

        $doc = Authorization_Server_Metadata::build('https://example.com');
        $this->assertArrayNotHasKey('client_id_metadata_document_supported', $doc);
        $this->assertSame(['client_secret_post'], $doc['token_endpoint_auth_methods_supported']);

        $result = $this->authorize();
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_client', $result->get_error_code());
        $this->assertSame([], $this->requests, 'no fetch when CIMD is off');
    }

    // The flow.

    public function test_a_cimd_client_completes_the_oauth_flow_as_a_public_client(): void
    {
        $user = $this->log_in();

        $authorized = $this->authorize();
        $this->assertIsArray($authorized, is_wp_error($authorized) ? $authorized->get_error_message() : '');
        $this->assertSame([self::CLIENT_ID], $this->requests);

        $token = $this->exchange($authorized['code']);
        $this->assertIsArray($token, is_wp_error($token) ? $token->get_error_message() : '');
        $this->assertSame('Bearer', $token['token_type']);

        $validated = Token_Store::validate($token['access_token']);
        $this->assertSame($user, $validated['user_id']);
        $this->assertSame(self::CLIENT_ID, $validated['client_id']);

        $refreshed = Token_Grant::exchange([
            'grant_type'    => 'refresh_token',
            'refresh_token' => $token['refresh_token'],
            'client_id'     => self::CLIENT_ID,
        ]);
        $this->assertIsArray($refreshed, is_wp_error($refreshed) ? $refreshed->get_error_message() : '');

        // A CIMD client never becomes a DCR row.
        $this->assertNull(Client_Store::get(self::CLIENT_ID));
    }

    public function test_a_public_cimd_client_presenting_a_secret_is_refused_at_the_token_endpoint(): void
    {
        $this->log_in();
        $authorized = $this->authorize();

        $result = $this->exchange($authorized['code'], ['client_secret' => 'secret_guess']);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_client', $result->get_error_code());
    }

    public function test_a_dcr_client_still_needs_its_secret(): void
    {
        $client = Client_Store::create(['Test App'], ['https://example.com/cb']);

        $result = Token_Grant::exchange([
            'grant_type'    => 'authorization_code',
            'code'          => 'whatever',
            'client_id'     => $client['client_id'],
            'code_verifier' => self::VERIFIER,
        ]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_client', $result->get_error_code());
    }

    // Document validation.

    public function test_document_whose_client_id_differs_from_its_url_is_refused(): void
    {
        $this->log_in();
        $this->response = $this->document_response($this->document(['client_id' => 'https://client.example.com/other.json']));

        $result = $this->authorize();

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_client', $result->get_error_code());
    }

    public function test_redirect_uri_not_listed_in_the_document_is_refused(): void
    {
        $this->log_in();

        $result = $this->authorize(self::CLIENT_ID, 'https://attacker.example.net/cb');

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_request', $result->get_error_code());
    }

    public function test_document_missing_required_fields_is_refused(): void
    {
        $this->log_in();
        $doc = $this->document();
        unset($doc['client_name']);
        $this->response = $this->document_response($doc);

        $this->assertInstanceOf(\WP_Error::class, $this->authorize());
    }

    public function test_document_with_an_invalid_redirect_uri_is_refused(): void
    {
        $this->log_in();
        $this->response = $this->document_response($this->document(['redirect_uris' => ['javascript:alert(1)']]));

        $this->assertInstanceOf(\WP_Error::class, $this->authorize(self::CLIENT_ID, 'javascript:alert(1)'));
    }

    public function test_document_declaring_a_shared_secret_auth_method_is_refused(): void
    {
        $this->log_in();
        $this->response = $this->document_response($this->document(['token_endpoint_auth_method' => 'client_secret_basic']));

        $result = $this->authorize();

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_client', $result->get_error_code());
    }

    public function test_non_json_document_is_refused(): void
    {
        $this->log_in();
        $this->response = ['code' => 200, 'headers' => [], 'body' => '<html>nope</html>'];

        $this->assertInstanceOf(\WP_Error::class, $this->authorize());
    }

    // SSRF.

    /** @dataProvider unsafe_client_ids */
    public function test_unsafe_client_id_urls_are_refused_without_a_request(string $client_id): void
    {
        $this->log_in();

        $result = $this->authorize($client_id);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_client', $result->get_error_code());
        $this->assertSame([], $this->requests, "no request for {$client_id}");
    }

    public static function unsafe_client_ids(): array
    {
        return [
            'plain http'          => ['http://client.example.com/meta.json'],
            'no path'             => ['https://client.example.com'],
            'root path'           => ['https://client.example.com/'],
            'custom port'         => ['https://client.example.com:8443/meta.json'],
            'credentials'         => ['https://user:pass@client.example.com/meta.json'],
            'fragment'            => ['https://client.example.com/meta.json#x'],
            'dot segment'         => ['https://client.example.com/a/../meta.json'],
            'loopback literal'    => ['https://127.0.0.1/meta.json'],
            'localhost'           => ['https://localhost/meta.json'],
            'private resolution'  => ['https://internal.example.com/meta.json'],
        ];
    }

    public function test_redirect_responses_are_not_followed(): void
    {
        $this->log_in();
        $this->response = ['code' => 302, 'headers' => ['location' => 'http://10.0.0.5/meta.json'], 'body' => ''];

        $result = $this->authorize();

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertCount(1, $this->requests);
    }

    public function test_oversized_documents_are_refused(): void
    {
        $this->log_in();
        $this->response = $this->document_response($this->document(['padding' => str_repeat('x', 20000)]));

        $this->assertInstanceOf(\WP_Error::class, $this->authorize());
    }

    // Caching.

    public function test_the_document_is_cached_between_authorizations(): void
    {
        $this->log_in();

        $this->assertIsArray($this->authorize());
        $this->assertIsArray($this->authorize());

        $this->assertCount(1, $this->requests);
    }

    public function test_no_store_is_honoured(): void
    {
        $this->log_in();
        $this->response = $this->document_response($this->document(), ['cache-control' => 'no-store']);

        $this->assertIsArray($this->authorize());
        $this->assertIsArray($this->authorize());

        $this->assertCount(2, $this->requests);
    }

    public function test_cache_lifetime_follows_max_age_within_bounds(): void
    {
        $this->assertSame(600, Client_Metadata_Document::cache_ttl('public, max-age=600'));
        $this->assertSame(Client_Metadata_Document::DEFAULT_TTL, Client_Metadata_Document::cache_ttl(''));
        $this->assertSame(Client_Metadata_Document::MAX_TTL, Client_Metadata_Document::cache_ttl('max-age=99999999'));
        $this->assertSame(0, Client_Metadata_Document::cache_ttl('no-cache'));
        $this->assertSame(0, Client_Metadata_Document::cache_ttl('max-age=0'));
    }

    // Admin approval.

    public function test_seen_clients_are_listed_for_the_admin(): void
    {
        $this->log_in();
        $this->authorize();

        $clients = Client_Metadata_Document::clients();
        $this->assertArrayHasKey(self::CLIENT_ID, $clients);
        $this->assertSame('Example MCP Client', $clients[self::CLIENT_ID]['client_name']);
        $this->assertSame(['127.0.0.1'], $clients[self::CLIENT_ID]['redirect_hosts']);
    }

    public function test_admin_can_require_approval_and_new_clients_wait_for_it(): void
    {
        $admin = $this->log_in('administrator');
        $toggle = $this->admin_post('oauth_client_approval', ['require' => '1']);
        $this->assertArrayNotHasKey('error', (array) $toggle);
        $this->assertTrue(Client_Metadata_Document::requires_approval());

        $this->log_in();
        $pending = $this->authorize();
        $this->assertInstanceOf(\WP_Error::class, $pending);
        $this->assertSame('access_denied', $pending->get_error_code());
        $this->assertSame('pending', Client_Metadata_Document::status(self::CLIENT_ID));

        wp_set_current_user($admin);
        $approved = $this->admin_post('oauth_client_approve', ['client_id' => self::CLIENT_ID]);
        $this->assertArrayNotHasKey('error', (array) $approved);
        $this->assertSame('approved', Client_Metadata_Document::status(self::CLIENT_ID));

        $this->log_in();
        $this->assertIsArray($this->authorize());
    }

    public function test_denying_a_client_refuses_it_and_revokes_its_tokens(): void
    {
        $admin = $this->log_in('administrator');
        $this->log_in();
        $authorized = $this->authorize();
        $token      = $this->exchange($authorized['code']);
        $this->assertNotNull(Token_Store::validate($token['access_token']));

        wp_set_current_user($admin);
        $this->admin_post('oauth_client_deny', ['client_id' => self::CLIENT_ID]);
        $this->assertSame('denied', Client_Metadata_Document::status(self::CLIENT_ID));

        $this->assertNull(Token_Store::validate($token['access_token']));
        $refreshed = Token_Grant::exchange([
            'grant_type'    => 'refresh_token',
            'refresh_token' => $token['refresh_token'],
            'client_id'     => self::CLIENT_ID,
        ]);
        $this->assertInstanceOf(\WP_Error::class, $refreshed);

        // Denied stays denied even with approval switched off.
        $this->log_in();
        $again = $this->authorize();
        $this->assertInstanceOf(\WP_Error::class, $again);
        $this->assertSame('access_denied', $again->get_error_code());
    }

    public function test_an_unapproved_client_cannot_redeem_a_code_issued_before_approval_was_required(): void
    {
        $this->log_in();
        $authorized = $this->authorize();

        update_option(Client_Metadata_Document::APPROVAL_OPTION, true);
        Client_Metadata_Document::forget(self::CLIENT_ID);

        $result = $this->exchange($authorized['code']);
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_client', $result->get_error_code());
    }

    public function test_approval_actions_require_manage_options(): void
    {
        $this->log_in('editor');

        $result = $this->admin_post('oauth_client_approval', ['require' => '1']);

        $this->assertArrayHasKey('error', (array) $result);
        $this->assertFalse(Client_Metadata_Document::requires_approval());
    }
}
