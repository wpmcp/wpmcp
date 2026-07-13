<?php

namespace WPMCP\Tests\Free\Auth;

use WPMCP\Auth\Client_Store;
use WPMCP\Auth\Code_Store;
use WPMCP\Auth\Endpoints;
use WPMCP\Auth\OAuth_Config;
use WPMCP\Auth\Token_Store;

/**
 * End-to-end wiring for the OAuth subsystem (issue #43): the three DCR/
 * authorize/token routes are registered under wpmcp/v1 via
 * register_rest_route(); the two RFC 8414/9728 /.well-known/ discovery
 * documents are served via parse_request (register_rest_route() refuses an
 * empty namespace, and the well-known paths must be true top-level paths,
 * not /wp-json/{ns}/-prefixed). Everything is gated by
 * OAuth_Config::is_enabled() (default false), so when disabled none of this
 * exists at all and an existing install is unaffected.
 */
class EndpointsTest extends \WP_Test_REST_TestCase
{
    private $original_request_uri;

    protected function setUp(): void
    {
        parent::setUp();
        delete_option(Client_Store::OPTION);
        delete_option(Code_Store::OPTION);
        delete_option(Token_Store::OPTION);
        Endpoints::set_test_mode(true);
        $this->original_request_uri = $_SERVER['REQUEST_URI'] ?? null;
    }

    protected function tearDown(): void
    {
        delete_option(Client_Store::OPTION);
        delete_option(Code_Store::OPTION);
        delete_option(Token_Store::OPTION);
        remove_all_filters('wpmcp_oauth_enabled');
        remove_all_actions('parse_request');
        Endpoints::set_test_mode(false);
        if (null === $this->original_request_uri) {
            unset($_SERVER['REQUEST_URI']);
        } else {
            $_SERVER['REQUEST_URI'] = $this->original_request_uri;
        }
        parent::tearDown();
    }

    private function server_with_oauth_enabled(): \WP_REST_Server
    {
        add_filter('wpmcp_oauth_enabled', '__return_true');

        global $wp_rest_server;
        $wp_rest_server = new \WP_REST_Server();
        do_action('rest_api_init', $wp_rest_server);

        return $wp_rest_server;
    }

    public function test_dcr_routes_are_absent_when_oauth_is_disabled(): void
    {
        $this->assertFalse(OAuth_Config::is_enabled());

        global $wp_rest_server;
        $wp_rest_server = new \WP_REST_Server();
        do_action('rest_api_init', $wp_rest_server);

        $routes = $wp_rest_server->get_routes();

        $this->assertArrayNotHasKey('/wpmcp/v1/oauth/register', $routes);
        $this->assertArrayNotHasKey('/wpmcp/v1/oauth/token', $routes);
        $this->assertArrayNotHasKey('/wpmcp/v1/oauth/authorize', $routes);
    }

    public function test_well_known_paths_are_not_served_when_oauth_is_disabled(): void
    {
        $this->assertFalse(OAuth_Config::is_enabled());

        // register() early-returns when disabled (see test_dcr_routes_are_absent_...),
        // so no parse_request listener for the well-known paths is ever
        // attached in the first place. Firing parse_request directly (as
        // WordPress itself would on every front-end request) must therefore
        // produce no output at all.
        do_action('rest_api_init', new \WP_REST_Server());

        $_SERVER['REQUEST_URI'] = '/.well-known/oauth-authorization-server';

        ob_start();
        do_action('parse_request');
        $output = ob_get_clean();

        $this->assertSame('', $output);
    }

    public function test_dcr_routes_exist_when_oauth_is_enabled(): void
    {
        $server = $this->server_with_oauth_enabled();
        $routes = $server->get_routes();

        $this->assertArrayHasKey('/wpmcp/v1/oauth/register', $routes);
        $this->assertArrayHasKey('/wpmcp/v1/oauth/token', $routes);
        $this->assertArrayHasKey('/wpmcp/v1/oauth/authorize', $routes);
    }

    public function test_authorization_server_metadata_is_served_at_the_well_known_path(): void
    {
        $this->server_with_oauth_enabled();

        $_SERVER['REQUEST_URI'] = '/.well-known/oauth-authorization-server';
        $payload                = (new Endpoints())->maybe_serve_well_known();

        $this->assertIsArray($payload);
        $this->assertArrayHasKey('issuer', $payload);
    }

    public function test_protected_resource_metadata_is_served_at_the_well_known_path(): void
    {
        $this->server_with_oauth_enabled();

        $_SERVER['REQUEST_URI'] = '/.well-known/oauth-protected-resource';
        $payload                = (new Endpoints())->maybe_serve_well_known();

        $this->assertIsArray($payload);
        $this->assertArrayHasKey('resource', $payload);
    }

    public function test_unrelated_paths_are_not_served_as_well_known_documents(): void
    {
        $this->server_with_oauth_enabled();

        $_SERVER['REQUEST_URI'] = '/some/other/path';
        $payload                = (new Endpoints())->maybe_serve_well_known();

        $this->assertNull($payload);
    }

    public function test_register_endpoint_creates_a_client(): void
    {
        $server = $this->server_with_oauth_enabled();

        $request = new \WP_REST_Request('POST', '/wpmcp/v1/oauth/register');
        $request->set_body_params([
            'client_name'   => 'Test Client',
            'redirect_uris' => ['https://example.com/cb'],
        ]);

        $response = $server->dispatch($request);

        $this->assertSame(201, $response->get_status());
        $data = $response->get_data();
        $this->assertArrayHasKey('client_id', $data);
        $this->assertArrayHasKey('client_secret', $data);
    }

    public function test_register_endpoint_rejects_an_invalid_redirect_uri_with_400(): void
    {
        $server = $this->server_with_oauth_enabled();

        $request = new \WP_REST_Request('POST', '/wpmcp/v1/oauth/register');
        $request->set_body_params([
            'client_name'   => 'Bad Client',
            'redirect_uris' => ['http://example.com/cb'],
        ]);

        $response = $server->dispatch($request);

        $this->assertSame(400, $response->get_status());
    }

    public function test_token_endpoint_rejects_an_unknown_code_with_400(): void
    {
        $server = $this->server_with_oauth_enabled();

        $request = new \WP_REST_Request('POST', '/wpmcp/v1/oauth/token');
        $request->set_body_params([
            'grant_type'    => 'authorization_code',
            'code'          => 'never-issued',
            'redirect_uri'  => 'https://example.com/cb',
            'client_id'     => 'no-such-client',
            'code_verifier' => 'whatever',
        ]);

        $response = $server->dispatch($request);

        $this->assertSame(400, $response->get_status());
    }

    public function test_authorize_endpoint_requires_a_logged_in_user(): void
    {
        $server = $this->server_with_oauth_enabled();
        wp_set_current_user(0);

        $request = new \WP_REST_Request('POST', '/wpmcp/v1/oauth/authorize');
        $request->set_body_params([
            'response_type'         => 'code',
            'client_id'             => 'whatever',
            'redirect_uri'          => 'https://example.com/cb',
            'code_challenge'        => 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
            'code_challenge_method' => 'S256',
        ]);

        $response = $server->dispatch($request);

        $this->assertSame(401, $response->get_status());
    }

    public function test_full_register_authorize_token_happy_path_through_rest(): void
    {
        $server = $this->server_with_oauth_enabled();

        $register_request = new \WP_REST_Request('POST', '/wpmcp/v1/oauth/register');
        $register_request->set_body_params([
            'client_name'   => 'E2E Client',
            'redirect_uris' => ['https://example.com/cb'],
        ]);
        $register_data = $server->dispatch($register_request)->get_data();

        $user = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user);

        $authorize_request = new \WP_REST_Request('POST', '/wpmcp/v1/oauth/authorize');
        $authorize_request->set_body_params([
            'response_type'         => 'code',
            'client_id'             => $register_data['client_id'],
            'redirect_uri'          => 'https://example.com/cb',
            'code_challenge'        => 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
            'code_challenge_method' => 'S256',
            'scope'                 => 'read',
        ]);
        $authorize_response = $server->dispatch($authorize_request);
        $this->assertSame(200, $authorize_response->get_status());
        $code = $authorize_response->get_data()['code'];

        $token_request = new \WP_REST_Request('POST', '/wpmcp/v1/oauth/token');
        $token_request->set_body_params([
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => 'https://example.com/cb',
            'client_id'     => $register_data['client_id'],
            'client_secret' => $register_data['client_secret'],
            'code_verifier' => 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk',
        ]);
        $token_response = $server->dispatch($token_request);

        $this->assertSame(200, $token_response->get_status());
        $token_data = $token_response->get_data();
        $this->assertSame('Bearer', $token_data['token_type']);

        $validated = Token_Store::validate($token_data['access_token']);
        $this->assertSame($user, $validated['user_id']);

        // The token endpoint response must never expose the credential
        // fingerprint (or the raw password it is derived from) that
        // Token_Store now binds tokens to at issuance (issue #43 C1/C2).
        $wp_user     = get_userdata($user);
        $fingerprint = hash('sha256', $wp_user->user_pass);
        $serialized  = wp_json_encode($token_data);
        $this->assertStringNotContainsString($fingerprint, $serialized);
        $this->assertStringNotContainsString($wp_user->user_pass, $serialized);
    }
}
