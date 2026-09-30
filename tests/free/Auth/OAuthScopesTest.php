<?php

namespace WPMCP\Tests\Free\Auth;

use WPMCP\Admin\Connection_Page;
use WPMCP\Auth\Authorization_Grant;
use WPMCP\Auth\Authorization_Server_Metadata;
use WPMCP\Auth\Bearer_Auth;
use WPMCP\Auth\Client_Access;
use WPMCP\Auth\Client_Store;
use WPMCP\Auth\Code_Store;
use WPMCP\Auth\Mcp_Resource;
use WPMCP\Auth\Oauth_Gc;
use WPMCP\Auth\Protected_Resource_Metadata;
use WPMCP\Auth\Refresh_Token_Store;
use WPMCP\Auth\Token_Grant;
use WPMCP\Auth\Token_Store;
use WPMCP\Identity\Identity_Store;
use WPMCP\Identity\Ip_Allowlist;

/**
 * Issue #454: an OAuth connection can be limited to reading, or bound to a
 * scoped identity, when it is approved.
 *
 * Before this, every token carried whatever scope string the client sent and
 * nothing read it: a token did whatever its user could do. Now the metadata
 * advertises `mcp` and `mcp:read`, the approving user picks the access level,
 * the level is stored with the client for that user and rides along every
 * refreshed token, and Registrar refuses any non-read ability to a read-only
 * connection with `insufficient_scope`. Connections made before this keep
 * full access until the owner lowers them from the Connection screen.
 */
class OAuthScopesTest extends \WP_UnitTestCase
{
    private const VERIFIER  = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
    private const CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

    private $original_request_uri;
    private $original_remote_addr;

    public static function wpSetUpBeforeClass(): void
    {
        if (0 === did_action('wp_abilities_api_init')) {
            do_action('wp_abilities_api_init');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->reset();
        Oauth_Gc::reset_throttle();
        add_filter('wpmcp_oauth_enabled', '__return_true');
        $this->original_request_uri = $_SERVER['REQUEST_URI'] ?? null;
        $this->original_remote_addr = $_SERVER['REMOTE_ADDR'] ?? null;
    }

    protected function tearDown(): void
    {
        $this->reset();
        remove_all_filters('wpmcp_oauth_enabled');
        unset($_SERVER['HTTP_AUTHORIZATION']);
        foreach (['REQUEST_URI' => $this->original_request_uri, 'REMOTE_ADDR' => $this->original_remote_addr] as $key => $value) {
            if (null === $value) {
                unset($_SERVER[ $key ]);
            } else {
                $_SERVER[ $key ] = $value;
            }
        }
        Bearer_Auth::reset_for_tests();
        Ip_Allowlist::reset_for_tests();
        wp_set_current_user(0);
        parent::tearDown();
    }

    private function reset(): void
    {
        foreach ([Client_Store::OPTION, Code_Store::OPTION, Token_Store::OPTION, Refresh_Token_Store::OPTION, Client_Access::OPTION, Identity_Store::OPTION] as $option) {
            delete_option($option);
        }
    }

    private function admin(): int
    {
        return self::factory()->user->create(['role' => 'administrator']);
    }

    /** Authorize as $user with the given scope and access choice, then redeem the code. */
    private function connect(int $user, string $scope, ?string $access = null): array
    {
        $client = Client_Store::create(['Assistant'], ['https://example.com/cb']);
        wp_set_current_user($user);

        $params = [
            'response_type'         => 'code',
            'client_id'             => $client['client_id'],
            'redirect_uri'          => 'https://example.com/cb',
            'code_challenge'        => self::CHALLENGE,
            'code_challenge_method' => 'S256',
            'scope'                 => $scope,
        ];
        if (null !== $access) {
            $params['access'] = $access;
        }
        $grant = Authorization_Grant::authorize($params);
        $this->assertIsArray($grant, is_wp_error($grant) ? $grant->get_error_message() : '');

        $tokens = Token_Grant::exchange([
            'grant_type'    => 'authorization_code',
            'code'          => $grant['code'],
            'redirect_uri'  => 'https://example.com/cb',
            'client_id'     => $client['client_id'],
            'client_secret' => $client['client_secret'],
            'code_verifier' => self::VERIFIER,
        ]);
        $this->assertIsArray($tokens, is_wp_error($tokens) ? $tokens->get_error_message() : '');
        wp_set_current_user(0);

        return ['client' => $client, 'tokens' => $tokens, 'user' => $user];
    }

    /** Present $access_token on an MCP request, the way a client does. */
    private function present(string $access_token): void
    {
        Bearer_Auth::reset_for_tests();
        $_SERVER['REQUEST_URI']        = Mcp_Resource::relative_path();
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $access_token;
        $user                          = Bearer_Auth::resolve(0);
        $this->assertIsInt($user);
        $this->assertGreaterThan(0, $user);
        wp_set_current_user($user);
    }

    /** @return true|\WP_Error|bool */
    private function check(string $ability)
    {
        $abilities = wp_get_abilities();
        $this->assertArrayHasKey($ability, $abilities);

        return $abilities[ $ability ]->check_permissions([]);
    }

    public function test_both_metadata_documents_advertise_mcp_and_mcp_read(): void
    {
        $as  = Authorization_Server_Metadata::build('https://example.org');
        $prm = Protected_Resource_Metadata::build(Mcp_Resource::canonical(), 'https://example.org');

        foreach ([$as, $prm] as $doc) {
            $this->assertContains('mcp', $doc['scopes_supported']);
            $this->assertContains('mcp:read', $doc['scopes_supported']);
        }
    }

    public function test_a_client_that_requests_only_mcp_read_gets_a_read_only_token(): void
    {
        $session = $this->connect($this->admin(), 'mcp:read');

        $this->assertSame('mcp:read', $session['tokens']['scope']);
        $this->assertSame('mcp:read', Token_Store::validate($session['tokens']['access_token'])['scope']);
    }

    public function test_choosing_read_only_on_consent_narrows_a_full_request(): void
    {
        $session = $this->connect($this->admin(), 'mcp', 'read');

        $this->assertSame('mcp:read', $session['tokens']['scope']);
        $stored = Client_Access::get($session['client']['client_id'], $session['user']);
        $this->assertSame('read', $stored['level']);
    }

    public function test_consent_cannot_widen_a_read_only_request(): void
    {
        $session = $this->connect($this->admin(), 'mcp:read', 'full');

        $this->assertSame('mcp:read', $session['tokens']['scope']);
    }

    public function test_the_reserved_gateway_scope_is_refused_at_authorization(): void
    {
        $client = Client_Store::create(['Assistant'], ['https://example.com/cb']);
        wp_set_current_user($this->admin());

        $result = Authorization_Grant::authorize([
            'response_type'         => 'code',
            'client_id'             => $client['client_id'],
            'redirect_uri'          => 'https://example.com/cb',
            'code_challenge'        => self::CHALLENGE,
            'code_challenge_method' => 'S256',
            'scope'                 => 'mcp gateway',
        ]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_scope', $result->get_error_code());
    }

    public function test_a_read_only_token_reads_and_is_refused_writes_with_insufficient_scope(): void
    {
        $session = $this->connect($this->admin(), 'mcp:read');
        $this->present($session['tokens']['access_token']);

        $this->assertTrue($this->check('wpmcp/get-post'));

        $denied = $this->check('wpmcp/update-post');
        $this->assertInstanceOf(\WP_Error::class, $denied);
        $this->assertSame('insufficient_scope', $denied->get_error_code());
        $this->assertStringContainsString('mcp', $denied->get_error_message());
    }

    public function test_a_full_token_can_still_write(): void
    {
        $session = $this->connect($this->admin(), 'mcp');
        $this->present($session['tokens']['access_token']);

        $this->assertTrue($this->check('wpmcp/update-post'));
    }

    public function test_the_read_only_level_survives_refresh(): void
    {
        $session = $this->connect($this->admin(), 'mcp:read');

        $refreshed = Token_Grant::exchange([
            'grant_type'    => 'refresh_token',
            'refresh_token' => $session['tokens']['refresh_token'],
            'client_id'     => $session['client']['client_id'],
            'client_secret' => $session['client']['client_secret'],
        ]);

        $this->assertIsArray($refreshed);
        $this->assertSame('mcp:read', $refreshed['scope']);

        $this->present($refreshed['access_token']);
        $denied = $this->check('wpmcp/update-post');
        $this->assertInstanceOf(\WP_Error::class, $denied);
        $this->assertSame('insufficient_scope', $denied->get_error_code());
    }

    public function test_a_refresh_asking_for_more_than_was_granted_is_refused_without_burning_the_token(): void
    {
        $session = $this->connect($this->admin(), 'mcp:read');
        $params  = [
            'grant_type'    => 'refresh_token',
            'refresh_token' => $session['tokens']['refresh_token'],
            'client_id'     => $session['client']['client_id'],
            'client_secret' => $session['client']['client_secret'],
        ];

        $widened = Token_Grant::exchange($params + ['scope' => 'mcp']);
        $this->assertInstanceOf(\WP_Error::class, $widened);
        $this->assertSame('invalid_scope', $widened->get_error_code());

        // The refresh token was not rotated by the refused attempt.
        $same = Token_Grant::exchange($params);
        $this->assertIsArray($same);
        $this->assertSame('mcp:read', $same['scope']);
    }

    public function test_connections_made_before_scopes_keep_full_access(): void
    {
        $admin = $this->admin();
        // A token minted by an earlier version: the client's own scope string, no stored level.
        $token = Token_Store::issue('client_legacy', $admin, 'read write');

        $this->present($token);

        $this->assertTrue($this->check('wpmcp/update-post'));
    }

    public function test_the_owner_can_lower_a_connection_to_read_only_without_reconnecting(): void
    {
        $user    = $this->admin();
        $session = $this->connect($user, 'mcp');
        $client  = $session['client']['client_id'];

        $this->present($session['tokens']['access_token']);
        $this->assertTrue($this->check('wpmcp/update-post'));

        $owner = $this->admin();
        wp_set_current_user($owner);
        $result = (new Connection_Page())->handle_request([
            'wpmcp_connection_action' => 'oauth_access',
            '_wpnonce'                => wp_create_nonce(Connection_Page::NONCE_ACTION),
            'client_id'               => $client,
            'user_id'                 => (string) $user,
            'access'                  => 'read',
        ]);
        $this->assertIsArray($result);
        $this->assertArrayNotHasKey('error', $result);

        // The very next request with the same access token is read-only.
        $this->present($session['tokens']['access_token']);
        $this->assertTrue($this->check('wpmcp/get-post'));
        $denied = $this->check('wpmcp/update-post');
        $this->assertInstanceOf(\WP_Error::class, $denied);
        $this->assertSame('insufficient_scope', $denied->get_error_code());
    }

    public function test_the_owner_can_lower_a_connection_made_before_scopes(): void
    {
        $user  = $this->admin();
        $token = Token_Store::issue('client_legacy', $user, '');
        Refresh_Token_Store::issue('client_legacy', $user, '');

        $this->assertContains(
            ['client_id' => 'client_legacy', 'user_id' => $user],
            array_map(static fn (array $c): array => ['client_id' => $c['client_id'], 'user_id' => $c['user_id']], Client_Access::connections())
        );

        Client_Access::set('client_legacy', $user, 'read');
        $this->present($token);

        $this->assertInstanceOf(\WP_Error::class, $this->check('wpmcp/update-post'));
    }

    public function test_the_connection_screen_refuses_non_owners(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));

        $result = (new Connection_Page())->handle_request([
            'wpmcp_connection_action' => 'oauth_access',
            '_wpnonce'                => wp_create_nonce(Connection_Page::NONCE_ACTION),
            'client_id'               => 'client_x',
            'user_id'                 => '1',
            'access'                  => 'read',
        ]);

        $this->assertArrayHasKey('error', $result);
        $this->assertNull(Client_Access::get('client_x', 1));
    }

    public function test_a_client_bound_to_an_identity_is_limited_as_that_identity_including_its_ips(): void
    {
        Identity_Store::create('content-reader', ['domains' => ['content'], 'operations' => ['read'], 'allowed_ips' => ['203.0.113.7']]);

        $session = $this->connect($this->admin(), 'mcp', 'identity:content-reader');
        $stored  = Client_Access::get($session['client']['client_id'], $session['user']);
        $this->assertSame('identity', $stored['level']);
        $this->assertSame('content-reader', $stored['identity']);

        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        $this->present($session['tokens']['access_token']);
        $this->assertTrue($this->check('wpmcp/get-post'));
        $this->assertNotTrue($this->check('wpmcp/update-post'));
        $this->assertNotTrue($this->check('wpmcp/query'));

        $_SERVER['REMOTE_ADDR'] = '198.51.100.9';
        $this->present($session['tokens']['access_token']);
        $this->assertNotTrue($this->check('wpmcp/get-post'));
    }

    public function test_an_unknown_identity_choice_is_refused(): void
    {
        $client = Client_Store::create(['Assistant'], ['https://example.com/cb']);
        wp_set_current_user($this->admin());

        $result = Authorization_Grant::authorize([
            'response_type'         => 'code',
            'client_id'             => $client['client_id'],
            'redirect_uri'          => 'https://example.com/cb',
            'code_challenge'        => self::CHALLENGE,
            'code_challenge_method' => 'S256',
            'scope'                 => 'mcp',
            'access'                => 'identity:nobody',
        ]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_request', $result->get_error_code());
    }

    public function test_only_site_owners_may_bind_a_connection_to_an_identity(): void
    {
        Identity_Store::create('content-reader', ['domains' => ['content']]);
        $client = Client_Store::create(['Assistant'], ['https://example.com/cb']);
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));

        $result = Authorization_Grant::authorize([
            'response_type'         => 'code',
            'client_id'             => $client['client_id'],
            'redirect_uri'          => 'https://example.com/cb',
            'code_challenge'        => self::CHALLENGE,
            'code_challenge_method' => 'S256',
            'scope'                 => 'mcp',
            'access'                => 'identity:content-reader',
        ]);

        $this->assertInstanceOf(\WP_Error::class, $result);
    }
}
