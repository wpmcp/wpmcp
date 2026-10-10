<?php

namespace WPMCP\Tests\Free\Auth;

use WPMCP\Admin\Connection_Page;
use WPMCP\Admin\OAuth_Consent_Page;
use WPMCP\Auth\Authorization_Grant;
use WPMCP\Auth\Bearer_Auth;
use WPMCP\Auth\Client_Access;
use WPMCP\Auth\Client_Store;
use WPMCP\Auth\Code_Store;
use WPMCP\Auth\Mcp_Resource;
use WPMCP\Auth\Oauth_Gc;
use WPMCP\Auth\Refresh_Token_Store;
use WPMCP\Auth\Token_Grant;
use WPMCP\Auth\Token_Store;
use WPMCP\Identity\Identity_Store;
use WPMCP\Identity\Ip_Allowlist;

/**
 * Issue #505: running the OAuth consent screen again must not undo the
 * access level a site owner set on a connection.
 *
 * The Connection screen lets the owner lower a connection (client and
 * user) to read-only or bind it to a scoped identity (issue #454). Consent
 * used to overwrite that row unconditionally, so the user could approve the
 * same client again with "Full access" and give the token the owner had
 * narrowed its write access back, dropping any identity binding and its IP
 * pin with it. A non-owner's re-consent may now keep or narrow a level
 * someone else set, never widen it or drop a binding, and the consent
 * screen says so. The user's own earlier choice, and an owner's, can still
 * be changed freely.
 */
class OAuthReconsentTest extends \WP_UnitTestCase
{
    private const VERIFIER  = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
    private const CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

    private $original_request_uri;

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
    }

    protected function tearDown(): void
    {
        $this->reset();
        remove_all_filters('wpmcp_oauth_enabled');
        unset($_SERVER['HTTP_AUTHORIZATION']);
        if (null === $this->original_request_uri) {
            unset($_SERVER['REQUEST_URI']);
        } else {
            $_SERVER['REQUEST_URI'] = $this->original_request_uri;
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

    private function params(array $client, string $scope = 'mcp'): array
    {
        return [
            'response_type'         => 'code',
            'client_id'             => $client['client_id'],
            'redirect_uri'          => 'https://example.com/cb',
            'code_challenge'        => self::CHALLENGE,
            'code_challenge_method' => 'S256',
            'scope'                 => $scope,
        ];
    }

    /** Approve $client as $user with $access and exchange the code. */
    private function authorize(array $client, int $user, string $access): array
    {
        wp_set_current_user($user);
        $grant = Authorization_Grant::authorize($this->params($client) + ['access' => $access]);
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

        return $tokens;
    }

    private function present(string $access_token): void
    {
        Bearer_Auth::reset_for_tests();
        $_SERVER['REQUEST_URI']        = Mcp_Resource::relative_path();
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $access_token;
        $user                          = Bearer_Auth::resolve(0);
        $this->assertGreaterThan(0, (int) $user);
        wp_set_current_user($user);
    }

    private function check(string $ability)
    {
        return wp_get_abilities()[ $ability ]->check_permissions([]);
    }

    /** A site owner changes the connection on the Connection screen. */
    private function owner_sets(string $client_id, int $user, string $access, ?int $owner = null): int
    {
        $owner ??= self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($owner);
        $result = (new Connection_Page())->handle_request([
            'wpmcp_connection_action' => 'oauth_access',
            '_wpnonce'                => wp_create_nonce(Connection_Page::NONCE_ACTION),
            'client_id'               => $client_id,
            'user_id'                 => (string) $user,
            'access'                  => $access,
        ]);
        $this->assertArrayNotHasKey('error', $result);
        wp_set_current_user(0);

        return $owner;
    }

    public function test_reconsenting_with_full_access_leaves_an_owner_lowered_connection_read_only(): void
    {
        $editor = self::factory()->user->create(['role' => 'editor']);
        $client = Client_Store::create(['Assistant'], ['https://example.com/cb']);
        $first  = $this->authorize($client, $editor, 'full');

        $owner = $this->owner_sets($client['client_id'], $editor, 'read');
        $this->present($first['access_token']);
        $this->assertInstanceOf(\WP_Error::class, $this->check('wpmcp/update-post'));

        $second = $this->authorize($client, $editor, 'full');

        $this->present($first['access_token']);
        $this->assertInstanceOf(\WP_Error::class, $this->check('wpmcp/update-post'), 'Re-consent restored write access the site owner removed.');
        $this->present($second['access_token']);
        $this->assertInstanceOf(\WP_Error::class, $this->check('wpmcp/update-post'), 'The new token got the write access the site owner removed.');
        $this->assertTrue($this->check('wpmcp/get-post'));

        $row = Client_Access::get($client['client_id'], $editor);
        $this->assertSame(Client_Access::READ, $row['level']);
        $this->assertSame($owner, $row['set_by'], 'The owner\'s restriction must stay the owner\'s, or the next re-consent could widen it.');
    }

    public function test_reconsent_by_a_non_owner_keeps_an_owner_bound_identity(): void
    {
        $editor = self::factory()->user->create(['role' => 'editor']);
        Identity_Store::create('office-only', ['domains' => ['content'], 'allowed_ips' => ['10.0.0.1']]);
        $client = Client_Store::create(['Assistant'], ['https://example.com/cb']);
        $this->authorize($client, $editor, 'full');

        $this->owner_sets($client['client_id'], $editor, 'identity:office-only');

        foreach (['full', 'read'] as $choice) {
            $this->authorize($client, $editor, $choice);

            $row = Client_Access::get($client['client_id'], $editor);
            $this->assertSame(Client_Access::IDENTITY, $row['level'], "Re-consent with {$choice} dropped the identity binding the site owner set.");
            $this->assertSame('office-only', $row['identity']);
        }
    }

    public function test_choosing_read_only_under_an_owner_bound_identity_still_narrows_the_new_token(): void
    {
        $editor = self::factory()->user->create(['role' => 'editor']);
        Identity_Store::create('content-editor', ['domains' => ['content']]);
        $client = Client_Store::create(['Assistant'], ['https://example.com/cb']);
        $this->authorize($client, $editor, 'full');
        $this->owner_sets($client['client_id'], $editor, 'identity:content-editor');

        $tokens = $this->authorize($client, $editor, 'read');

        $this->assertSame(Client_Access::SCOPE_READ, $tokens['scope']);
        $this->present($tokens['access_token']);
        $this->assertInstanceOf(\WP_Error::class, $this->check('wpmcp/update-post'));
    }

    public function test_the_user_can_still_narrow_their_own_connection(): void
    {
        $editor = self::factory()->user->create(['role' => 'editor']);
        $client = Client_Store::create(['Assistant'], ['https://example.com/cb']);
        $first  = $this->authorize($client, $editor, 'full');

        $this->authorize($client, $editor, 'read');

        $this->assertSame(Client_Access::READ, Client_Access::get($client['client_id'], $editor)['level']);
        $this->present($first['access_token']);
        $this->assertInstanceOf(\WP_Error::class, $this->check('wpmcp/update-post'));
    }

    public function test_the_user_can_narrow_a_connection_the_owner_left_at_full_access(): void
    {
        $editor = self::factory()->user->create(['role' => 'editor']);
        $client = Client_Store::create(['Assistant'], ['https://example.com/cb']);
        $this->authorize($client, $editor, 'read');
        $this->owner_sets($client['client_id'], $editor, 'full');

        $this->authorize($client, $editor, 'read');

        $this->assertSame(Client_Access::READ, Client_Access::get($client['client_id'], $editor)['level']);
    }

    public function test_the_user_can_widen_their_own_earlier_choice_again(): void
    {
        $editor = self::factory()->user->create(['role' => 'editor']);
        $client = Client_Store::create(['Assistant'], ['https://example.com/cb']);
        $this->authorize($client, $editor, 'read');

        $tokens = $this->authorize($client, $editor, 'full');

        $this->assertSame(Client_Access::FULL, Client_Access::get($client['client_id'], $editor)['level']);
        $this->present($tokens['access_token']);
        $this->assertTrue($this->check('wpmcp/update-post'));
    }

    public function test_an_owner_reconsenting_can_change_the_level(): void
    {
        $admin  = self::factory()->user->create(['role' => 'administrator']);
        $client = Client_Store::create(['Assistant'], ['https://example.com/cb']);
        $this->authorize($client, $admin, 'full');
        // Another site owner lowered this administrator's connection.
        $this->owner_sets($client['client_id'], $admin, 'read');

        $tokens = $this->authorize($client, $admin, 'full');

        $this->assertSame(Client_Access::FULL, Client_Access::get($client['client_id'], $admin)['level']);
        $this->present($tokens['access_token']);
        $this->assertTrue($this->check('wpmcp/update-post'));
    }

    public function test_the_consent_screen_tells_a_non_owner_their_choice_is_limited_to_read_only(): void
    {
        $editor = self::factory()->user->create(['role' => 'editor']);
        $client = Client_Store::create(['Assistant'], ['https://example.com/cb']);
        $this->authorize($client, $editor, 'full');
        $this->owner_sets($client['client_id'], $editor, 'read');

        wp_set_current_user($editor);
        $html = (new OAuth_Consent_Page())->render($this->params($client));

        $this->assertStringContainsString('A site owner has limited', $html);
        $this->assertStringNotContainsString('value="full"', $html, 'Full access cannot be honored, so it must not be offered.');
        $this->assertStringContainsString('value="read"', $html);
    }

    public function test_the_consent_screen_tells_a_non_owner_the_identity_binding_stays(): void
    {
        $editor = self::factory()->user->create(['role' => 'editor']);
        Identity_Store::create('office-only', ['domains' => ['content'], 'allowed_ips' => ['10.0.0.1']]);
        $client = Client_Store::create(['Assistant'], ['https://example.com/cb']);
        $this->authorize($client, $editor, 'full');
        $this->owner_sets($client['client_id'], $editor, 'identity:office-only');

        wp_set_current_user($editor);
        $html = (new OAuth_Consent_Page())->render($this->params($client));

        $this->assertStringContainsString('A site owner has limited', $html);
        $this->assertStringContainsString('office-only', $html);
    }

    public function test_the_consent_screen_shows_no_limit_notice_without_an_owner_restriction(): void
    {
        $editor = self::factory()->user->create(['role' => 'editor']);
        $client = Client_Store::create(['Assistant'], ['https://example.com/cb']);
        $this->authorize($client, $editor, 'read');

        wp_set_current_user($editor);
        $html = (new OAuth_Consent_Page())->render($this->params($client));

        $this->assertStringNotContainsString('A site owner has limited', $html);
        $this->assertStringContainsString('value="full"', $html);
    }
}
