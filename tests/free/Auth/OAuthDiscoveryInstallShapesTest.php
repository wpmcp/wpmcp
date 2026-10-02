<?php

namespace WPMCP\Tests\Free\Auth;

use WPMCP\Admin\Connection_Page;
use WPMCP\Auth\Bearer_Auth;
use WPMCP\Auth\Client_Store;
use WPMCP\Auth\Code_Store;
use WPMCP\Auth\Endpoints;
use WPMCP\Auth\Mcp_Resource;
use WPMCP\Auth\Network_Sites;
use WPMCP\Auth\Refresh_Token_Store;
use WPMCP\Auth\Token_Store;

/**
 * OAuth discovery on every install shape (issue #473).
 *
 * The issuer is home_url(), so on a subdirectory install or a subdirectory
 * multisite subsite it has a path. For such an issuer the MCP authorization
 * spec has clients try, in order:
 *
 *  1. <origin>/.well-known/oauth-authorization-server/<path>  (RFC 8414 path insertion)
 *  2. <origin>/.well-known/openid-configuration/<path>        (OpenID path insertion)
 *  3. <origin>/<path>/.well-known/openid-configuration        (OpenID path appending)
 *
 * and for an issuer without a path, the first two without the suffix.
 * Forms 1 and 2 sit at the domain root, which a single site installed in a
 * subdirectory never sees, so form 3 is the one every install can answer.
 * On a subdirectory multisite the main site does see the root, and answers
 * forms 1 and 2 for its subsites.
 *
 * The harness boots WordPress single-site, so each install shape is
 * simulated: home and siteurl are filtered to the shape's URLs, and the
 * network lookup goes through Network_Sites' test seam. Each test walks
 * the same discovery a spec-following client performs, from the 401
 * challenge to the authorization server metadata, and only "sends" a
 * request to WordPress when the shape's web server would route it there.
 */
class OAuthDiscoveryInstallShapesTest extends \WP_UnitTestCase
{
    private $original_request_uri;

    /** @var callable|null */
    private $home_filter = null;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([Client_Store::OPTION, Code_Store::OPTION, Token_Store::OPTION, Refresh_Token_Store::OPTION] as $option) {
            delete_option($option);
        }
        Endpoints::set_test_mode(true);
        Bearer_Auth::reset_for_tests();
        $this->original_request_uri = $_SERVER['REQUEST_URI'] ?? null;
        unset($_SERVER['HTTP_AUTHORIZATION'], $_GET['rest_route'], $_POST['rest_route']);
        add_filter('wpmcp_oauth_enabled', '__return_true');
    }

    protected function tearDown(): void
    {
        $this->as_site(null);
        Network_Sites::set_test_sites(null);
        foreach ([Client_Store::OPTION, Code_Store::OPTION, Token_Store::OPTION, Refresh_Token_Store::OPTION] as $option) {
            delete_option($option);
        }
        remove_all_filters('wpmcp_oauth_enabled');
        remove_all_filters('wpmcp_oauth_cimd_enabled');
        Endpoints::set_test_mode(false);
        Bearer_Auth::reset_for_tests();
        unset($_SERVER['HTTP_AUTHORIZATION'], $_GET['rest_route'], $_POST['rest_route']);
        if (null === $this->original_request_uri) {
            unset($_SERVER['REQUEST_URI']);
        } else {
            $_SERVER['REQUEST_URI'] = $this->original_request_uri;
        }
        wp_set_current_user(0);
        parent::tearDown();
    }

    // --- Helpers -----------------------------------------------------------

    /** Make WordPress believe it is the site at $home (null restores the real one). */
    private function as_site(?string $home): void
    {
        if (null !== $this->home_filter) {
            remove_filter('pre_option_home', $this->home_filter);
            remove_filter('pre_option_siteurl', $this->home_filter);
            $this->home_filter = null;
        }
        if (null === $home) {
            return;
        }
        $this->home_filter = static fn () => $home;
        add_filter('pre_option_home', $this->home_filter);
        add_filter('pre_option_siteurl', $this->home_filter);
    }

    /**
     * The authorization server metadata URLs the MCP authorization spec has
     * a client try, in order, for $issuer.
     *
     * @return list<string>
     */
    private static function as_metadata_candidates(string $issuer): array
    {
        $parts  = wp_parse_url($issuer);
        $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $path   = rtrim($parts['path'] ?? '', '/');

        if ('' === $path) {
            return [
                $origin . '/.well-known/oauth-authorization-server',
                $origin . '/.well-known/openid-configuration',
            ];
        }

        return [
            $origin . '/.well-known/oauth-authorization-server' . $path,
            $origin . '/.well-known/openid-configuration' . $path,
            $origin . $path . '/.well-known/openid-configuration',
        ];
    }

    /**
     * GET $url as the install shape would deliver it: $route maps a URL to
     * the home URL of the WordPress site its web server hands it to, or null
     * when the URL never reaches WordPress (a 404 from whatever owns it).
     */
    private function fetch(string $url, callable $route): ?array
    {
        $home = $route($url);
        if (null === $home) {
            return null;
        }

        $this->as_site($home);
        $parts                  = wp_parse_url($url);
        $_SERVER['REQUEST_URI'] = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');

        return Endpoints::maybe_serve_well_known();
    }

    /**
     * Discovery as a spec-following client performs it against the MCP
     * endpoint served by the site at $home: 401 challenge, protected
     * resource metadata, then each authorization server metadata URL in
     * order. Asserts the RFC 9728 3.3 and RFC 8414 3.3 identity checks a
     * client applies, and returns what it found.
     *
     * @return array{resource_metadata: string, prm: array, as_url: string, as: array}
     */
    private function discover(string $home, callable $route): array
    {
        $this->as_site($home);
        $mcp_url = Mcp_Resource::canonical();

        $challenge = Bearer_Auth::add_challenge_header(
            new \WP_REST_Response(['code' => 'rest_forbidden'], 401),
            rest_get_server(),
            new \WP_REST_Request('POST', Mcp_Resource::route())
        );
        $header = $challenge->get_headers()['WWW-Authenticate'] ?? '';
        $this->assertSame(1, preg_match('/resource_metadata="([^"]+)"/', $header, $m), 'The 401 carries a resource_metadata URL.');
        $resource_metadata = $m[1];

        $prm = $this->fetch($resource_metadata, $route);
        $this->assertIsArray($prm, 'The advertised resource_metadata URL is served: ' . $resource_metadata);
        // RFC 9728 3.3: metadata reached through the challenge names the URL the client called.
        $this->assertSame($mcp_url, $prm['resource']);

        $issuer = $prm['authorization_servers'][0];
        foreach (self::as_metadata_candidates($issuer) as $url) {
            $doc = $this->fetch($url, $route);
            if (null !== $doc) {
                // RFC 8414 3.3: the issuer must be the one the URL was built from.
                $this->assertSame($issuer, $doc['issuer'], 'Issuer served at ' . $url);
                $this->assertContains('S256', $doc['code_challenge_methods_supported']);

                return ['resource_metadata' => $resource_metadata, 'prm' => $prm, 'as_url' => $url, 'as' => $doc];
            }
        }

        $this->fail('No authorization server metadata URL a client tries for ' . $issuer . ' was served.');
    }

    // --- Wiring -------------------------------------------------------------

    public function test_the_well_known_handler_is_attached_at_plugin_load_not_on_rest_api_init(): void
    {
        // rest_api_init only fires when something builds the REST server,
        // which a plain front-end GET for /.well-known/... never does, so a
        // handler attached from inside rest_api_init is never there to answer.
        $this->assertNotFalse(has_action('parse_request', [Endpoints::class, 'maybe_serve_well_known']));
    }

    // --- Single site at the domain root --------------------------------------

    public function test_root_install_keeps_its_urls_and_discovers_at_the_root_form(): void
    {
        $route = static fn (string $url) => str_starts_with($url, 'http://example.org/') ? 'http://example.org' : null;

        $found = $this->discover('http://example.org', $route);

        $this->assertSame('http://example.org/.well-known/oauth-protected-resource/wp-json/mcp/wpmcp-server', $found['resource_metadata']);
        $this->assertSame(['http://example.org'], $found['prm']['authorization_servers']);
        $this->assertSame('http://example.org/.well-known/oauth-authorization-server', $found['as_url']);
        $this->assertSame('http://example.org/wp-json/wpmcp/v1/oauth/authorize', $found['as']['authorization_endpoint']);
        $this->assertSame('http://example.org/wp-json/wpmcp/v1/oauth/token', $found['as']['token_endpoint']);

        // The root protected resource form keeps answering too.
        $this->assertSame(
            'http://example.org/wp-json/mcp/wpmcp-server',
            $this->fetch('http://example.org/.well-known/oauth-protected-resource', $route)['resource']
        );
    }

    public function test_openid_configuration_is_the_same_metadata_with_the_openid_fields_and_no_id_tokens(): void
    {
        $route = static fn (string $url) => 'http://example.org';

        $oauth  = $this->fetch('http://example.org/.well-known/oauth-authorization-server', $route);
        $openid = $this->fetch('http://example.org/.well-known/openid-configuration', $route);

        $this->assertIsArray($openid);
        $this->assertSame(['public'], $openid['subject_types_supported']);
        $this->assertSame(['code'], $openid['response_types_supported']);
        $this->assertArrayNotHasKey('id_token_signing_alg_values_supported', $openid);
        $this->assertArrayNotHasKey('subject_types_supported', $oauth, 'The RFC 8414 document is unchanged.');

        unset($openid['subject_types_supported']);
        $this->assertSame($oauth, $openid);
    }

    // --- Single site in a subdirectory ---------------------------------------

    public function test_subdirectory_install_is_discovered_through_openid_path_appending(): void
    {
        // Only /blog/... reaches WordPress; the domain root belongs to something else.
        $route = static fn (string $url) => str_starts_with($url, 'http://example.org/blog/') ? 'http://example.org/blog' : null;

        $found = $this->discover('http://example.org/blog', $route);

        $this->assertSame('http://example.org/blog/.well-known/oauth-protected-resource/wp-json/mcp/wpmcp-server', $found['resource_metadata']);
        $this->assertSame('http://example.org/blog/wp-json/mcp/wpmcp-server', $found['prm']['resource']);
        $this->assertSame(['http://example.org/blog'], $found['prm']['authorization_servers']);
        $this->assertSame('http://example.org/blog/.well-known/openid-configuration', $found['as_url']);
        $this->assertSame('http://example.org/blog/wp-json/wpmcp/v1/oauth/authorize', $found['as']['authorization_endpoint']);
        $this->assertSame('http://example.org/blog/wp-json/wpmcp/v1/oauth/token', $found['as']['token_endpoint']);
    }

    public function test_subdirectory_install_still_answers_its_own_rfc_8414_address(): void
    {
        // The target a site owner's optional root rewrite points at.
        $route = static fn (string $url) => 'http://example.org/blog';

        $doc = $this->fetch('http://example.org/blog/.well-known/oauth-authorization-server', $route);

        $this->assertSame('http://example.org/blog', $doc['issuer']);
    }

    public function test_a_sibling_path_that_only_shares_the_prefix_is_not_served(): void
    {
        $this->as_site('http://example.org/blog');
        $_SERVER['REQUEST_URI'] = '/blogger/.well-known/openid-configuration';

        $this->assertNull(Endpoints::maybe_serve_well_known());
    }

    // --- Subdirectory multisite ---------------------------------------------

    private function subdirectory_network(): callable
    {
        Network_Sites::set_test_sites([
            'example.org/'      => 'http://example.org',
            'example.org/shop/' => 'http://example.org/shop',
            'example.org/news/' => 'http://example.org/news',
        ]);

        // WordPress multisite routes /shop/... to the shop subsite and
        // everything else on the host, the root included, to the main site.
        return static function (string $url): ?string {
            $path = (string) wp_parse_url($url, PHP_URL_PATH);
            foreach (['/shop/' => 'http://example.org/shop', '/news/' => 'http://example.org/news'] as $prefix => $home) {
                if (str_starts_with($path, $prefix)) {
                    return $home;
                }
            }
            return 'http://example.org';
        };
    }

    public function test_subdirectory_subsite_is_discovered_at_the_path_insertion_form_answered_by_the_main_site(): void
    {
        $route = $this->subdirectory_network();

        $found = $this->discover('http://example.org/shop', $route);

        $this->assertSame('http://example.org/shop/wp-json/mcp/wpmcp-server', $found['prm']['resource']);
        $this->assertSame(['http://example.org/shop'], $found['prm']['authorization_servers']);
        $this->assertSame('http://example.org/.well-known/oauth-authorization-server/shop', $found['as_url']);
        $this->assertSame('http://example.org/shop/wp-json/wpmcp/v1/oauth/token', $found['as']['token_endpoint']);
    }

    public function test_the_main_site_answers_every_path_insertion_form_for_a_subsite(): void
    {
        $route = $this->subdirectory_network();

        $openid = $this->fetch('http://example.org/.well-known/openid-configuration/shop', $route);
        $this->assertSame('http://example.org/shop', $openid['issuer']);
        $this->assertSame(['public'], $openid['subject_types_supported']);

        $prm = $this->fetch('http://example.org/.well-known/oauth-protected-resource/shop/wp-json/mcp/wpmcp-server', $route);
        $this->assertSame('http://example.org/shop/wp-json/mcp/wpmcp-server', $prm['resource']);
        $this->assertSame(['http://example.org/shop'], $prm['authorization_servers']);

        // The subsite answers path appending itself as well.
        $this->assertSame('http://example.org/shop', $this->fetch('http://example.org/shop/.well-known/openid-configuration', $route)['issuer']);

        // And the main site keeps its own documents.
        $this->assertSame('http://example.org', $this->fetch('http://example.org/.well-known/oauth-authorization-server', $route)['issuer']);
    }

    public function test_a_path_that_names_no_site_in_the_network_is_not_served(): void
    {
        $route = $this->subdirectory_network();

        $this->assertNull($this->fetch('http://example.org/.well-known/oauth-authorization-server/nope', $route));
        $this->assertNull($this->fetch('http://example.org/.well-known/openid-configuration/shop/extra', $route));
        $this->assertNull($this->fetch('http://example.org/.well-known/oauth-protected-resource/shop/wp-json/wp/v2', $route));
    }

    public function test_path_insertion_is_not_answered_for_another_sites_path_when_oauth_is_off(): void
    {
        $route = $this->subdirectory_network();
        remove_all_filters('wpmcp_oauth_enabled');

        $this->assertNull($this->fetch('http://example.org/.well-known/oauth-authorization-server/shop', $route));
        $this->assertNull($this->fetch('http://example.org/shop/.well-known/openid-configuration', $route));
        $this->assertNull($this->fetch('http://example.org/.well-known/oauth-authorization-server', $route));
    }

    public function test_a_token_issued_for_one_subsite_is_refused_on_another(): void
    {
        $this->subdirectory_network();
        $user_id = self::factory()->user->create(['role' => 'administrator']);

        $this->as_site('http://example.org/shop');
        $token = Token_Store::issue('client_abc', $user_id, 'mcp', '', false, Mcp_Resource::canonical());
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;

        $_SERVER['REQUEST_URI'] = '/shop/wp-json/mcp/wpmcp-server';
        $this->assertSame($user_id, Bearer_Auth::resolve(0));
        Bearer_Auth::reset_for_tests();

        $this->as_site('http://example.org/news');
        $_SERVER['REQUEST_URI'] = '/news/wp-json/mcp/wpmcp-server';
        $this->assertSame(0, Bearer_Auth::resolve(0));
    }

    // --- Subdomain multisite ------------------------------------------------

    public function test_subdomain_subsite_is_discovered_at_its_own_root(): void
    {
        Network_Sites::set_test_sites([
            'example.org/'      => 'http://example.org',
            'shop.example.org/' => 'http://shop.example.org',
        ]);
        $route = static fn (string $url) => str_starts_with($url, 'http://shop.example.org/') ? 'http://shop.example.org' : 'http://example.org';

        $found = $this->discover('http://shop.example.org', $route);

        $this->assertSame('http://shop.example.org/.well-known/oauth-authorization-server', $found['as_url']);
        $this->assertSame('http://shop.example.org/wp-json/mcp/wpmcp-server', $found['prm']['resource']);

        // The main site does not answer for another host.
        $this->assertNull($this->fetch('http://example.org/.well-known/oauth-authorization-server/shop', $route));
    }

    // --- Client ID Metadata Documents (#388) and the consent flow (#454) ---

    public function test_client_metadata_document_support_is_advertised_on_every_form(): void
    {
        $route = $this->subdirectory_network();

        foreach (['http://example.org/.well-known/oauth-authorization-server/shop', 'http://example.org/shop/.well-known/openid-configuration'] as $url) {
            $doc = $this->fetch($url, $route);
            $this->assertTrue($doc['client_id_metadata_document_supported'], $url);
            $this->assertContains('none', $doc['token_endpoint_auth_methods_supported'], $url);
        }

        add_filter('wpmcp_oauth_cimd_enabled', '__return_false');
        $doc = $this->fetch('http://example.org/shop/.well-known/openid-configuration', $route);
        $this->assertArrayNotHasKey('client_id_metadata_document_supported', $doc);
    }

    public function test_the_authorization_endpoint_of_a_subdirectory_site_leads_to_its_own_consent_screen(): void
    {
        $this->as_site('http://example.org/blog');

        $response = (new Endpoints())->authorize_redirect(new \WP_REST_Request('GET', '/wpmcp/v1/oauth/authorize'));

        $this->assertSame(302, $response->get_status());
        $this->assertStringStartsWith('http://example.org/blog/wp-admin/admin-post.php?', $response->get_headers()['Location']);
    }

    // --- Connection screen ----------------------------------------------------

    public function test_connection_screen_shows_the_discovery_address_a_subdirectory_install_relies_on(): void
    {
        $admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin);
        $this->as_site('http://example.org/blog');

        ob_start();
        (new Connection_Page())->render();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('http://example.org/blog/.well-known/openid-configuration', $html);
        $this->assertStringContainsString('http://example.org/blog/.well-known/oauth-protected-resource/wp-json/mcp/wpmcp-server', $html);
    }
}
