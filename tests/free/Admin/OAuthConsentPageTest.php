<?php

namespace WPMCP\Tests\Free\Admin;

use WPMCP\Admin\OAuth_Consent_Page;
use WPMCP\Auth\Client_Access;
use WPMCP\Auth\Client_Store;
use WPMCP\Auth\Code_Store;
use WPMCP\Auth\Endpoints;
use WPMCP\Auth\Token_Grant;
use WPMCP\Identity\Identity_Store;

/**
 * Issue #454: the OAuth consent screen. A client sends the browser to the
 * authorization endpoint; the signed-in user sees which client is asking,
 * picks full access, read-only access, or (site owners) one of the scoped
 * identities, and allows or denies. The choice is stored with the client
 * for that user and the browser goes back to the client with a code, or
 * with access_denied.
 */
class OAuthConsentPageTest extends \WP_Test_REST_TestCase
{
    private const VERIFIER  = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
    private const CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

    private array $client;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([Client_Store::OPTION, Code_Store::OPTION, Client_Access::OPTION, Identity_Store::OPTION] as $option) {
            delete_option($option);
        }
        add_filter('wpmcp_oauth_enabled', '__return_true');
        $this->client = Client_Store::create(['Analytics Helper'], ['https://example.com/cb']);
    }

    protected function tearDown(): void
    {
        foreach ([Client_Store::OPTION, Code_Store::OPTION, Client_Access::OPTION, Identity_Store::OPTION] as $option) {
            delete_option($option);
        }
        remove_all_filters('wpmcp_oauth_enabled');
        wp_set_current_user(0);
        parent::tearDown();
    }

    private function params(string $scope = 'mcp'): array
    {
        return [
            'response_type'         => 'code',
            'client_id'             => $this->client['client_id'],
            'redirect_uri'          => 'https://example.com/cb',
            'code_challenge'        => self::CHALLENGE,
            'code_challenge_method' => 'S256',
            'scope'                 => $scope,
            'state'                 => 'xyz-123',
        ];
    }

    private function decision(string $decision, string $access, ?string $nonce = null): array
    {
        return $this->params() + [
            'decision' => $decision,
            'access'   => $access,
            '_wpnonce' => $nonce ?? wp_create_nonce(OAuth_Consent_Page::NONCE_ACTION),
        ];
    }

    public function test_the_authorization_endpoint_sends_a_browser_to_the_consent_screen(): void
    {
        global $wp_rest_server;
        $wp_rest_server = new \WP_REST_Server();
        do_action('rest_api_init', $wp_rest_server);

        $request = new \WP_REST_Request('GET', '/' . Endpoints::NAMESPACE . '/oauth/authorize');
        $request->set_query_params($this->params());
        $response = $wp_rest_server->dispatch($request);

        $this->assertSame(302, $response->get_status());
        $location = $response->get_headers()['Location'] ?? '';
        $this->assertStringStartsWith(admin_url('admin-post.php'), $location);
        $query = [];
        parse_str((string) wp_parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame(OAuth_Consent_Page::ACTION, $query['action']);
        $this->assertSame($this->client['client_id'], $query['client_id']);
        $this->assertSame('xyz-123', $query['state']);
    }

    public function test_the_screen_names_the_client_and_offers_full_and_read_only_access(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        Identity_Store::create('content-reader', ['domains' => ['content']]);

        $html = (new OAuth_Consent_Page())->render($this->params());

        $this->assertStringContainsString('Analytics Helper', $html);
        $this->assertStringContainsString('example.com', $html);
        $this->assertStringContainsString('value="full"', $html);
        $this->assertStringContainsString('value="read"', $html);
        $this->assertStringContainsString('name="_wpnonce"', $html);
        // Binding to a scoped identity is a site-owner decision.
        $this->assertStringNotContainsString('identity:content-reader', $html);
    }

    public function test_site_owners_can_also_pick_a_scoped_identity(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        Identity_Store::create('content-reader', ['domains' => ['content']]);

        $html = (new OAuth_Consent_Page())->render($this->params());

        $this->assertStringContainsString('value="identity:content-reader"', $html);
    }

    public function test_a_read_only_request_is_not_offered_full_access(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $html = (new OAuth_Consent_Page())->render($this->params('mcp:read'));

        $this->assertStringContainsString('value="read"', $html);
        $this->assertStringNotContainsString('value="full"', $html);
    }

    public function test_an_unknown_client_gets_an_error_and_no_form(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $html = (new OAuth_Consent_Page())->render(['client_id' => 'client_nope'] + $this->params());

        $this->assertStringNotContainsString('<form', $html);
    }

    public function test_allowing_read_only_stores_the_choice_and_returns_a_code_to_the_client(): void
    {
        $user = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($user);

        $location = (new OAuth_Consent_Page())->decide($this->decision('allow', 'read'));

        $this->assertIsString($location);
        $this->assertStringStartsWith('https://example.com/cb?', $location);
        $query = [];
        parse_str((string) wp_parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('xyz-123', $query['state']);
        $this->assertNotEmpty($query['code']);

        $stored = Client_Access::get($this->client['client_id'], $user);
        $this->assertSame('read', $stored['level']);

        $tokens = Token_Grant::exchange([
            'grant_type'    => 'authorization_code',
            'code'          => $query['code'],
            'redirect_uri'  => 'https://example.com/cb',
            'client_id'     => $this->client['client_id'],
            'client_secret' => $this->client['client_secret'],
            'code_verifier' => self::VERIFIER,
        ]);
        $this->assertIsArray($tokens);
        $this->assertSame('mcp:read', $tokens['scope']);
    }

    public function test_denying_returns_access_denied_to_the_client(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $location = (new OAuth_Consent_Page())->decide($this->decision('deny', 'full'));

        $this->assertIsString($location);
        $query = [];
        parse_str((string) wp_parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('access_denied', $query['error']);
        $this->assertSame('xyz-123', $query['state']);
        $this->assertArrayNotHasKey('code', $query);
        $this->assertNull(Client_Access::get($this->client['client_id'], get_current_user_id()));
    }

    public function test_a_forged_decision_without_a_valid_nonce_is_refused(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $result = (new OAuth_Consent_Page())->decide($this->decision('allow', 'full', 'bad-nonce'));

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame([], get_option(Code_Store::OPTION, []));
    }

    public function test_a_decision_for_an_unregistered_redirect_never_redirects_there(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $post                 = $this->decision('deny', 'full');
        $post['redirect_uri'] = 'https://attacker.example/steal';
        $result               = (new OAuth_Consent_Page())->decide($post);

        $this->assertInstanceOf(\WP_Error::class, $result);
    }
}
