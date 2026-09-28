<?php

namespace WPMCP\Tests\Free\Auth;

use WPMCP\Auth\Authorization_Grant;
use WPMCP\Auth\Bearer_Auth;
use WPMCP\Auth\Client_Store;
use WPMCP\Auth\Code_Store;
use WPMCP\Auth\Endpoints;
use WPMCP\Auth\Mcp_Resource;
use WPMCP\Auth\Oauth_Gc;
use WPMCP\Auth\Refresh_Token_Store;
use WPMCP\Auth\Token_Grant;
use WPMCP\Auth\Token_Store;
use WPMCP\Connect\Client_Config_Generator;
use WPMCP\Governance\Governance_Audit_Log;

/**
 * OAuth tokens are bound to the MCP endpoint (RFC 8707 resource indicators,
 * RFC 9728 protected resource metadata).
 *
 * Before this, an access token minted for the MCP endpoint was accepted by
 * determine_current_user on every request the site served: the core REST
 * API, admin-ajax, anything. A token leaked from an MCP client was a
 * full-site credential for its user. These tests pin the audience at every
 * step of the flow (authorize, code exchange, refresh) and at the one place
 * a token is honoured (Bearer_Auth), plus the discovery surface a client
 * uses to learn the audience in the first place.
 */
class AudienceBindingTest extends \WP_UnitTestCase
{
    private const VERIFIER  = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
    private const CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

    private $original_request_uri;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([Client_Store::OPTION, Code_Store::OPTION, Token_Store::OPTION, Refresh_Token_Store::OPTION, Governance_Audit_Log::OPTION] as $option) {
            delete_option($option);
        }
        Oauth_Gc::reset_throttle();
        Endpoints::set_test_mode(true);
        $this->original_request_uri = $_SERVER['REQUEST_URI'] ?? null;
        unset($_SERVER['HTTP_AUTHORIZATION'], $_GET['rest_route'], $_POST['rest_route']);
        wp_set_current_user(0);
    }

    protected function tearDown(): void
    {
        foreach ([Client_Store::OPTION, Code_Store::OPTION, Token_Store::OPTION, Refresh_Token_Store::OPTION, Governance_Audit_Log::OPTION] as $option) {
            delete_option($option);
        }
        remove_all_filters('wpmcp_oauth_enabled');
        remove_all_filters('query');
        Endpoints::set_test_mode(false);
        unset($_SERVER['HTTP_AUTHORIZATION'], $_GET['rest_route'], $_POST['rest_route']);
        if (null === $this->original_request_uri) {
            unset($_SERVER['REQUEST_URI']);
        } else {
            $_SERVER['REQUEST_URI'] = $this->original_request_uri;
        }
        wp_set_current_user(0);
        parent::tearDown();
    }

    private function authorize_params(array $client, array $extra = []): array
    {
        return array_merge([
            'response_type'         => 'code',
            'client_id'             => $client['client_id'],
            'redirect_uri'          => 'https://example.com/cb',
            'code_challenge'        => self::CHALLENGE,
            'code_challenge_method' => 'S256',
            'scope'                 => 'mcp',
        ], $extra);
    }

    private function code_row(): array
    {
        $stored = get_option(Code_Store::OPTION);
        return reset($stored);
    }

    private function exchange(array $client, string $code, array $extra = []): array|\WP_Error
    {
        return Token_Grant::exchange(array_merge([
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => 'https://example.com/cb',
            'client_id'     => $client['client_id'],
            'client_secret' => $client['client_secret'],
            'code_verifier' => self::VERIFIER,
        ], $extra));
    }

    private function refresh(array $client, string $refresh_token, array $extra = []): array|\WP_Error
    {
        return Token_Grant::exchange(array_merge([
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refresh_token,
            'client_id'     => $client['client_id'],
            'client_secret' => $client['client_secret'],
        ], $extra));
    }

    /** @return array{client: array, user_id: int, tokens: array} */
    private function connect(): array
    {
        $client  = Client_Store::create(['Test App'], ['https://example.com/cb']);
        $user_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($user_id);

        $authorized = Authorization_Grant::authorize($this->authorize_params($client));
        $this->assertIsArray($authorized);
        wp_set_current_user(0);

        $tokens = $this->exchange($client, $authorized['code']);
        $this->assertIsArray($tokens);

        return ['client' => $client, 'user_id' => $user_id, 'tokens' => $tokens];
    }

    private function refresh_rows(): array
    {
        $stored = get_option(Refresh_Token_Store::OPTION);
        return is_array($stored) ? $stored : [];
    }

    // --- The canonical resource URI ---------------------------------------

    public function test_the_canonical_resource_is_the_mcp_endpoint_the_plugin_advertises(): void
    {
        $this->assertSame(untrailingslashit(Client_Config_Generator::endpoint()), Mcp_Resource::canonical());
        $this->assertSame(home_url('/wp-json/mcp/wpmcp-server'), Mcp_Resource::canonical());
    }

    public function test_resource_normalization_tolerates_case_default_port_and_trailing_slash_only(): void
    {
        $canonical = Mcp_Resource::canonical();
        $parts     = wp_parse_url($canonical);
        $default   = 'https' === $parts['scheme'] ? 443 : 80;

        $this->assertTrue(Mcp_Resource::matches($canonical));
        $this->assertTrue(Mcp_Resource::matches($canonical . '/'));
        $this->assertTrue(Mcp_Resource::matches(strtoupper($parts['scheme']) . '://' . strtoupper($parts['host']) . ':' . $default . $parts['path']));

        $this->assertFalse(Mcp_Resource::matches(home_url()));
        $this->assertFalse(Mcp_Resource::matches(home_url('/wp-json/wp/v2')));
        $this->assertFalse(Mcp_Resource::matches('https://attacker.example/wp-json/mcp/wpmcp-server'));
        $this->assertFalse(Mcp_Resource::matches($canonical . '#frag'));
        $this->assertFalse(Mcp_Resource::matches($canonical . '?x=1'));
        $this->assertFalse(Mcp_Resource::matches('not a url'));
    }

    // --- Authorize --------------------------------------------------------

    public function test_authorize_with_a_foreign_resource_is_rejected_with_invalid_target(): void
    {
        $client = Client_Store::create(['Test App'], ['https://example.com/cb']);
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));

        $result = Authorization_Grant::authorize($this->authorize_params($client, ['resource' => 'https://attacker.example/mcp']));

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_target', $result->get_error_code());
        $this->assertSame([], (array) get_option(Code_Store::OPTION, []));
    }

    public function test_authorize_with_the_site_root_as_resource_is_rejected(): void
    {
        $client = Client_Store::create(['Test App'], ['https://example.com/cb']);
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));

        $result = Authorization_Grant::authorize($this->authorize_params($client, ['resource' => home_url()]));

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_target', $result->get_error_code());
    }

    public function test_authorize_without_resource_defaults_to_and_binds_the_mcp_endpoint(): void
    {
        $client = Client_Store::create(['Test App'], ['https://example.com/cb']);
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));

        $params = $this->authorize_params($client);
        unset($params['resource']);
        $result = Authorization_Grant::authorize($params);

        $this->assertIsArray($result);
        $this->assertSame(Mcp_Resource::canonical(), $this->code_row()['resource']);
    }

    public function test_authorize_with_the_canonical_resource_binds_it_in_normalized_form(): void
    {
        $client = Client_Store::create(['Test App'], ['https://example.com/cb']);
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));

        $result = Authorization_Grant::authorize($this->authorize_params($client, ['resource' => Mcp_Resource::canonical() . '/']));

        $this->assertIsArray($result);
        $this->assertSame(Mcp_Resource::canonical(), $this->code_row()['resource']);
    }

    // --- Token endpoint ---------------------------------------------------

    public function test_token_exchange_with_a_mismatched_resource_is_rejected_with_invalid_target(): void
    {
        $client = Client_Store::create(['Test App'], ['https://example.com/cb']);
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        $code = Authorization_Grant::authorize($this->authorize_params($client))['code'];
        wp_set_current_user(0);

        $result = $this->exchange($client, $code, ['resource' => home_url('/wp-json/wp/v2')]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_target', $result->get_error_code());

        // The rejection happened before the code was spent, so the
        // legitimate exchange still works.
        $this->assertIsArray($this->exchange($client, $code, ['resource' => Mcp_Resource::canonical()]));
    }

    public function test_token_exchange_carries_the_resource_into_access_and_refresh_records(): void
    {
        $session = $this->connect();

        $validated = Token_Store::validate($session['tokens']['access_token']);
        $this->assertSame(Mcp_Resource::canonical(), $validated['resource']);

        $rows = $this->refresh_rows();
        $this->assertCount(1, $rows);
        $this->assertSame(Mcp_Resource::canonical(), reset($rows)['resource']);
    }

    public function test_refresh_keeps_the_same_audience(): void
    {
        $session = $this->connect();

        $result = $this->refresh($session['client'], $session['tokens']['refresh_token'], ['resource' => Mcp_Resource::canonical()]);

        $this->assertIsArray($result);
        $this->assertSame(Mcp_Resource::canonical(), Token_Store::validate($result['access_token'])['resource']);
        foreach ($this->refresh_rows() as $row) {
            $this->assertSame(Mcp_Resource::canonical(), $row['resource']);
        }
    }

    public function test_refresh_with_a_different_resource_is_rejected_without_burning_the_token(): void
    {
        $session = $this->connect();

        $result = $this->refresh($session['client'], $session['tokens']['refresh_token'], ['resource' => home_url()]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_target', $result->get_error_code());

        $this->assertIsArray($this->refresh($session['client'], $session['tokens']['refresh_token']));
    }

    // --- Refresh hardening ------------------------------------------------

    public function test_refresh_is_refused_after_a_password_change_and_the_chain_is_revoked(): void
    {
        $session = $this->connect();

        wp_set_password('a-brand-new-password', $session['user_id']);

        $result = $this->refresh($session['client'], $session['tokens']['refresh_token']);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_grant', $result->get_error_code());
        $this->assertSame([], $this->refresh_rows(), 'The whole grant chain must be revoked once the credentials it was issued against change.');
    }

    public function test_refresh_records_carry_a_password_fingerprint(): void
    {
        $this->connect();

        foreach ($this->refresh_rows() as $row) {
            $this->assertArrayHasKey('pass_fingerprint', $row);
            $this->assertNotEmpty($row['pass_fingerprint']);
        }
    }

    public function test_a_legacy_refresh_record_without_a_fingerprint_is_refused(): void
    {
        $session = $this->connect();

        $rows = $this->refresh_rows();
        foreach ($rows as $key => $row) {
            unset($rows[ $key ]['pass_fingerprint']);
        }
        update_option(Refresh_Token_Store::OPTION, $rows);

        $result = $this->refresh($session['client'], $session['tokens']['refresh_token']);

        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    /**
     * Two redemptions of the same fresh refresh token race: the second one
     * reads the row before the first has written its rotation. The `query`
     * filter interleaves a complete competing refresh at the exact moment
     * the first redemption is about to write, the same technique
     * CodeStoreTest uses for the authorization-code race.
     */
    public function test_a_concurrent_double_redeem_mints_at_most_one_pair(): void
    {
        $session = $this->connect();
        $client  = $session['client'];
        $token   = $session['tokens']['refresh_token'];

        $competing = null;
        $armed     = false;
        $filter    = function ($query) use (&$armed, &$competing, $client, $token) {
            if (! $armed && false !== stripos($query, 'UPDATE') && false !== strpos($query, Refresh_Token_Store::OPTION)) {
                $armed     = true;
                $competing = $this->refresh($client, $token);
            }

            return $query;
        };

        add_filter('query', $filter);
        $first = $this->refresh($client, $token);
        remove_filter('query', $filter);

        $this->assertTrue($armed, 'The interleaving hook must have fired.');

        $successes = array_filter([$first, $competing], static fn ($r) => is_array($r));
        $this->assertCount(1, $successes, 'Racing redemptions of one refresh token must mint exactly one token pair.');

        // One original (now rotated) plus exactly one successor.
        $this->assertCount(2, $this->refresh_rows());
    }

    // --- Bearer_Auth: audience enforcement ---------------------------------

    public function test_bearer_token_does_not_authenticate_the_core_rest_api(): void
    {
        add_filter('wpmcp_oauth_enabled', '__return_true');
        $session = $this->connect();
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $session['tokens']['access_token'];

        $_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/users/me';
        $this->assertSame(0, Bearer_Auth::resolve(0));

        $_SERVER['REQUEST_URI'] = '/wp-admin/admin-ajax.php';
        $this->assertSame(0, Bearer_Auth::resolve(0));

        $_SERVER['REQUEST_URI'] = '/';
        $this->assertSame(0, Bearer_Auth::resolve(0));

        $_SERVER['REQUEST_URI'] = '/wp-json/mcp/wpmcp-server';
        $this->assertSame($session['user_id'], Bearer_Auth::resolve(0));
    }

    public function test_bearer_token_on_another_route_passes_the_incoming_user_through(): void
    {
        add_filter('wpmcp_oauth_enabled', '__return_true');
        $session = $this->connect();
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $session['tokens']['access_token'];
        $_SERVER['REQUEST_URI']        = '/wp-json/wp/v2/users/me';

        $this->assertSame(42, Bearer_Auth::resolve(42));
        $this->assertFalse(Bearer_Auth::resolve(false));
    }

    public function test_a_rest_route_query_override_cannot_smuggle_another_route_past_the_check(): void
    {
        add_filter('wpmcp_oauth_enabled', '__return_true');
        $session = $this->connect();
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $session['tokens']['access_token'];

        // WordPress lets ?rest_route= override the pretty-permalink route,
        // so the path alone must never be trusted.
        $_SERVER['REQUEST_URI'] = '/wp-json/mcp/wpmcp-server?rest_route=/wp/v2/users/me';
        $_GET['rest_route']     = '/wp/v2/users/me';
        $this->assertSame(0, Bearer_Auth::resolve(0));

        // The plain-permalink form of the MCP route itself still works.
        $_SERVER['REQUEST_URI'] = '/?rest_route=/mcp/wpmcp-server';
        $_GET['rest_route']     = '/mcp/wpmcp-server';
        $this->assertSame($session['user_id'], Bearer_Auth::resolve(0));
    }

    public function test_a_path_that_only_starts_with_the_mcp_route_does_not_authenticate(): void
    {
        add_filter('wpmcp_oauth_enabled', '__return_true');
        $session = $this->connect();
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $session['tokens']['access_token'];

        $_SERVER['REQUEST_URI'] = '/wp-json/mcp/wpmcp-server-evil';
        $this->assertSame(0, Bearer_Auth::resolve(0));
    }

    public function test_a_token_bound_to_another_resource_is_ignored_even_on_the_mcp_route(): void
    {
        add_filter('wpmcp_oauth_enabled', '__return_true');
        $user_id = self::factory()->user->create(['role' => 'administrator']);
        $token   = Token_Store::issue('client_abc', $user_id, 'mcp', '', 'https://other.example/mcp');
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        $_SERVER['REQUEST_URI']        = '/wp-json/mcp/wpmcp-server';

        $this->assertSame(0, Bearer_Auth::resolve(0));
    }

    // --- Discovery --------------------------------------------------------

    public function test_protected_resource_metadata_names_the_mcp_endpoint_and_its_scopes(): void
    {
        add_filter('wpmcp_oauth_enabled', '__return_true');

        $_SERVER['REQUEST_URI'] = '/.well-known/oauth-protected-resource';
        $payload                = (new Endpoints())->maybe_serve_well_known();

        $this->assertSame(Mcp_Resource::canonical(), $payload['resource']);
        $this->assertSame([untrailingslashit(home_url())], $payload['authorization_servers']);
        $this->assertSame(Mcp_Resource::SCOPES_SUPPORTED, $payload['scopes_supported']);
        $this->assertNotEmpty($payload['scopes_supported']);
    }

    public function test_protected_resource_metadata_is_served_at_the_path_suffixed_url(): void
    {
        add_filter('wpmcp_oauth_enabled', '__return_true');

        $_SERVER['REQUEST_URI'] = '/.well-known/oauth-protected-resource/wp-json/mcp/wpmcp-server';
        $payload                = (new Endpoints())->maybe_serve_well_known();

        $this->assertIsArray($payload);
        $this->assertSame(Mcp_Resource::canonical(), $payload['resource']);
        $this->assertSame(home_url('/.well-known/oauth-protected-resource/wp-json/mcp/wpmcp-server'), Mcp_Resource::metadata_url());
    }

    public function test_an_unauthenticated_mcp_response_carries_the_resource_metadata_challenge(): void
    {
        add_filter('wpmcp_oauth_enabled', '__return_true');

        $response = new \WP_REST_Response(['code' => 'rest_forbidden'], 401);
        $request  = new \WP_REST_Request('POST', '/mcp/wpmcp-server');

        $response = Bearer_Auth::add_challenge_header($response, rest_get_server(), $request);

        $headers = $response->get_headers();
        $this->assertSame(
            'Bearer resource_metadata="' . Mcp_Resource::metadata_url() . '"',
            $headers['WWW-Authenticate'] ?? null
        );
    }

    public function test_register_wires_the_challenge_into_rest_post_dispatch(): void
    {
        add_filter('wpmcp_oauth_enabled', '__return_true');
        (new Bearer_Auth())->register();

        // The hook the REST server runs on every response before serving it:
        // a 401 from the MCP route's permission check comes through here.
        $response = apply_filters(
            'rest_post_dispatch',
            new \WP_REST_Response(['code' => 'rest_forbidden'], 401),
            rest_get_server(),
            new \WP_REST_Request('POST', '/mcp/wpmcp-server')
        );

        $this->assertSame(401, $response->get_status());
        $this->assertStringContainsString('resource_metadata=', $response->get_headers()['WWW-Authenticate'] ?? '');
    }

    public function test_the_challenge_is_not_added_to_other_routes_or_non_401_responses(): void
    {
        add_filter('wpmcp_oauth_enabled', '__return_true');

        $other = Bearer_Auth::add_challenge_header(new \WP_REST_Response(null, 401), rest_get_server(), new \WP_REST_Request('GET', '/wp/v2/users/me'));
        $this->assertArrayNotHasKey('WWW-Authenticate', $other->get_headers());

        $ok = Bearer_Auth::add_challenge_header(new \WP_REST_Response(null, 200), rest_get_server(), new \WP_REST_Request('POST', '/mcp/wpmcp-server'));
        $this->assertArrayNotHasKey('WWW-Authenticate', $ok->get_headers());
    }

    public function test_the_challenge_is_not_added_while_oauth_is_disabled(): void
    {
        $response = Bearer_Auth::add_challenge_header(new \WP_REST_Response(null, 401), rest_get_server(), new \WP_REST_Request('POST', '/mcp/wpmcp-server'));

        $this->assertArrayNotHasKey('WWW-Authenticate', $response->get_headers());
    }
}
