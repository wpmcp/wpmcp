<?php

namespace WPMCP\Tests\Free\Gateway;

use WPMCP\Auth\Bearer_Auth;
use WPMCP\Auth\Client_Store;
use WPMCP\Auth\Refresh_Token_Store;
use WPMCP\Auth\Token_Grant;
use WPMCP\Auth\Token_Store;
use WPMCP\Gateway\Gateway_Binding;
use WPMCP\Gateway\Gateway_Credential;
use WPMCP\Gateway\Gateway_Guard;
use WPMCP\Governance\Governance_Audit_Log;
use WPMCP\Identity\Identity_Context;
use WPMCP\Identity\Identity_Store;
use WPMCP\MCP\Ability;
use WPMCP\MCP\Registrar;

/**
 * What the site's gateway credential (#142) may do once it authenticates
 * (issue #130): only the MCP connection, and only as the scoped identity it
 * is bound to. Every token here comes out of the real refresh grant, so the
 * tests exercise the credential a proxy would actually hold.
 */
class GatewayGuardTest extends \WP_UnitTestCase
{
    private int $admin_id = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reset();
        add_filter('wpmcp_oauth_enabled', '__return_true');
        Gateway_Guard::register();

        $this->admin_id = self::factory()->user->create(['role' => 'administrator']);
        Identity_Store::create('Agency Editor', ['domains' => ['content'], 'operations' => ['read']]);
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_oauth_enabled');
        unset($_SERVER['HTTP_AUTHORIZATION'], $_GET['rest_route']);
        $_SERVER['REQUEST_URI'] = '/';
        Bearer_Auth::reset_for_tests();
        Identity_Context::set_current_for_tests(null);
        wp_set_current_user(0);
        $this->reset();
        parent::tearDown();
    }

    private function reset(): void
    {
        foreach ([Client_Store::OPTION, Token_Store::OPTION, Refresh_Token_Store::OPTION, Gateway_Credential::OPTION, Gateway_Binding::OPTION, Identity_Store::OPTION, Governance_Audit_Log::OPTION] as $option) {
            delete_option($option);
        }
    }

    /** Provision, optionally bind, and redeem once: a live gateway access token. */
    private function gateway_token(?string $identity = 'Agency Editor'): string
    {
        $credential = Gateway_Credential::issue_for_user($this->admin_id);
        if (null !== $identity) {
            Gateway_Binding::bind($identity, $this->admin_id);
        }

        $granted = Token_Grant::exchange([
            'grant_type'    => 'refresh_token',
            'client_id'     => $credential['client_id'],
            'client_secret' => $credential['client_secret'],
            'refresh_token' => $credential['refresh_token'],
        ]);
        $this->assertIsArray($granted, 'the gateway credential must be redeemable');

        return $granted['access_token'];
    }

    private function present(string $token, string $uri): int
    {
        Bearer_Auth::reset_for_tests();
        $_SERVER['REQUEST_URI']        = $uri;
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;

        return (int) Bearer_Auth::resolve(0);
    }

    private function parse(array $query_vars): void
    {
        $wp             = new \WP();
        $wp->query_vars = $query_vars;
        Gateway_Guard::enforce_dispatched_route($wp);
    }

    // ------------------------------------------------------- blast radius

    public function test_the_gateway_token_authenticates_on_the_mcp_connection(): void
    {
        $this->assertSame($this->admin_id, $this->present($this->gateway_token(), '/wp-json/mcp/wpmcp-server'));
    }

    public function test_the_gateway_token_is_refused_on_core_rest_admin_ajax_and_non_rest_requests(): void
    {
        $token = $this->gateway_token();

        $this->assertSame(0, $this->present($token, '/wp-json/wp/v2/users?context=edit'), 'Not an administrator on core REST.');
        $this->assertSame(0, $this->present($token, '/wp-json/batch/v1'), 'Not through the batch endpoint either.');
        $this->assertSame(0, $this->present($token, '/wp-admin/admin-ajax.php?action=anything'));
        $this->assertSame(0, $this->present($token, '/'), 'A request with no REST route fails closed.');
        $this->assertSame('', Bearer_Auth::current_client_id());
    }

    public function test_a_query_rest_route_cannot_smuggle_the_gateway_onto_core_rest(): void
    {
        $token              = $this->gateway_token();
        $_GET['rest_route'] = '/wp/v2/plugins';

        $this->assertSame(0, $this->present($token, '/wp-json/mcp/wpmcp-server'));
    }

    /**
     * WordPress gives a form-encoded POST rest_route precedence over the
     * path, so the early check (which cannot see it) lets the token in, and
     * the parse_request re-check must log it out before core REST runs.
     */
    public function test_a_dispatched_core_route_logs_the_gateway_out_before_dispatch(): void
    {
        wp_set_current_user($this->present($this->gateway_token(), '/wp-json/mcp/wpmcp-server'));
        $this->assertSame($this->admin_id, get_current_user_id());

        $this->parse(['rest_route' => '/wp/v2/users']);

        $this->assertSame(0, get_current_user_id());
        $this->assertSame('', Bearer_Auth::current_client_id());
        $this->assertNull(Identity_Context::current());
    }

    public function test_the_dispatched_mcp_route_keeps_the_gateway_logged_in(): void
    {
        wp_set_current_user($this->present($this->gateway_token(), '/wp-json/mcp/wpmcp-server'));

        $this->parse(['rest_route' => '/mcp/wpmcp-server']);

        $this->assertSame($this->admin_id, get_current_user_id());
        $this->assertSame('Agency Editor', Identity_Context::current());
    }

    public function test_a_gateway_request_that_is_not_rest_at_all_is_logged_out_on_parse(): void
    {
        wp_set_current_user($this->present($this->gateway_token(), '/wp-json/mcp/wpmcp-server'));

        $this->parse(['p' => '1']);

        $this->assertSame(0, get_current_user_id());
    }

    public function test_ordinary_oauth_tokens_are_untouched_by_both_checks(): void
    {
        $client = Client_Store::create(['Some MCP Client'], ['https://example.test/cb'], 'dcr');
        $token  = Token_Store::issue($client['client_id'], $this->admin_id, 'openid');

        // Every OAuth token is bound to the MCP endpoint (audience binding),
        // so the guard's job is only to leave an ordinary token alone there:
        // neither the early check nor the parse_request re-check may refuse it.
        wp_set_current_user($this->present($token, '/wp-json/mcp/wpmcp-server'));
        $this->assertSame($this->admin_id, get_current_user_id());
        $this->assertNull(Identity_Context::current(), 'an ordinary token must not pick up a gateway identity');

        $this->parse(['rest_route' => '/mcp/wpmcp-server']);
        $this->assertSame($this->admin_id, get_current_user_id());

        // Off the MCP endpoint the refusal is audience binding's, not the
        // guard's, and it holds for ordinary tokens and gateway tokens alike.
        $this->assertSame(0, $this->present($token, '/wp-json/wp/v2/users'));
    }

    // ------------------------------------------------------ identity binding

    /**
     * The DoD item end to end: a gateway call goes through
     * Registrar::is_permitted(), is narrowed to the bound identity although
     * its user is an administrator, and every decision lands in
     * Governance_Audit_Log under that identity.
     */
    public function test_a_gateway_call_is_narrowed_by_its_identity_and_audited_under_it(): void
    {
        wp_set_current_user($this->present($this->gateway_token(), '/wp-json/mcp/wpmcp-server'));
        delete_option(Governance_Audit_Log::OPTION);

        $read  = new Ability('wpmcp/test-gateway-read', 'free', 't', ['type' => 'object'], static fn () => null, 'edit_posts', 'content', 'read');
        $write = new Ability('wpmcp/test-gateway-write', 'free', 't', ['type' => 'object'], static fn () => null, 'edit_posts', 'content', 'update');
        $other = new Ability('wpmcp/test-gateway-plugins', 'free', 't', ['type' => 'object'], static fn () => null, 'activate_plugins', 'plugins', 'read');

        $registrar = new Registrar();
        $this->assertTrue($registrar->is_permitted($read), 'Inside the identity allowlist.');
        $this->assertFalse($registrar->is_permitted($write), 'The identity allows reads only, although the user is an administrator.');
        $this->assertFalse($registrar->is_permitted($other), 'The identity allows the content domain only.');

        $rows = array_column(Governance_Audit_Log::list(), null, 'ability');
        foreach (['wpmcp/test-gateway-read' => true, 'wpmcp/test-gateway-write' => false, 'wpmcp/test-gateway-plugins' => false] as $name => $allowed) {
            $this->assertArrayHasKey($name, $rows);
            $this->assertSame('Agency Editor', $rows[ $name ]['identity']);
            $this->assertSame($allowed, (bool) $rows[ $name ]['allowed']);
        }
    }

    public function test_a_binding_whose_identity_was_deleted_denies_rather_than_promotes(): void
    {
        wp_set_current_user($this->present($this->gateway_token(), '/wp-json/mcp/wpmcp-server'));
        Identity_Store::delete('Agency Editor');

        $read = new Ability('wpmcp/test-gateway-read', 'free', 't', ['type' => 'object'], static fn () => null, 'edit_posts', 'content', 'read');

        $this->assertSame('Agency Editor', Identity_Context::current());
        $this->assertFalse((new Registrar())->is_permitted($read));
    }

    /**
     * Issue #416: a bound identity pinned to allowed_ips refuses the gateway
     * token at authentication time from any other address, so the request
     * is anonymous before anything runs, and the refusal is audited.
     */
    public function test_a_bound_identity_pinned_to_other_addresses_refuses_the_token_at_authentication(): void
    {
        $previous = $_SERVER['REMOTE_ADDR'] ?? null;
        Identity_Store::create('Agency Editor', ['domains' => ['content'], 'allowed_ips' => ['203.0.113.0/24']]);
        $token = $this->gateway_token();

        try {
            $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
            \WPMCP\Identity\Ip_Allowlist::reset_for_tests();
            $this->assertSame($this->admin_id, $this->present($token, '/wp-json/mcp/wpmcp-server'), 'An allowed address authenticates.');

            $_SERVER['REMOTE_ADDR'] = '198.51.100.9';
            \WPMCP\Identity\Ip_Allowlist::reset_for_tests();
            $this->assertSame(0, $this->present($token, '/wp-json/mcp/wpmcp-server'), 'Any other address is refused.');
            $this->assertContains('identity/ip-refused', array_column(Governance_Audit_Log::list(), 'ability'));
        } finally {
            if (null === $previous) {
                unset($_SERVER['REMOTE_ADDR']);
            } else {
                $_SERVER['REMOTE_ADDR'] = $previous;
            }
            \WPMCP\Identity\Ip_Allowlist::reset_for_tests();
        }
    }

    public function test_an_unbound_credential_keeps_142_semantics_but_stays_on_the_mcp_connection(): void
    {
        $token = $this->gateway_token(null);

        $this->assertSame($this->admin_id, $this->present($token, '/wp-json/mcp/wpmcp-server'));
        $this->assertNull(Identity_Context::current());
        $this->assertSame(0, $this->present($token, '/wp-json/wp/v2/users'));
    }

    /**
     * A binding belongs to one issuance. Re-provisioning through another
     * path (the free gateway-provision) rotates the secret, and the old
     * binding must neither describe nor narrow the new credential.
     */
    public function test_a_reprovisioned_credential_does_not_inherit_the_old_binding(): void
    {
        Gateway_Credential::issue_for_user($this->admin_id);
        Gateway_Binding::bind('Agency Editor', $this->admin_id);
        $this->assertNotNull(Gateway_Binding::current());

        Gateway_Credential::issue_for_user($this->admin_id);

        $this->assertNull(Gateway_Binding::current());
        $client_id = (string) Gateway_Credential::current_client()['client_id'];
        $this->assertNull(Gateway_Binding::identity_for_client($client_id));
    }

    public function test_the_binding_stores_no_secret_material(): void
    {
        $credential = Gateway_Credential::issue_for_user($this->admin_id);
        Gateway_Binding::bind('Agency Editor', $this->admin_id);

        $flat = (string) wp_json_encode(get_option(Gateway_Binding::OPTION));
        $this->assertStringNotContainsString($credential['client_secret'], $flat);
        $this->assertStringNotContainsString($credential['refresh_token'], $flat);
        $this->assertStringNotContainsString((string) Client_Store::get($credential['client_id'])['client_secret_hash'], $flat);
    }

    // ------------------------------------------------------------ kill switch

    public function test_kill_is_total_local_and_clears_the_binding(): void
    {
        $requests = 0;
        $count    = static function ($pre) use (&$requests) {
            $requests++;
            return $pre;
        };
        add_filter('pre_http_request', $count);

        $token = $this->gateway_token();
        $this->assertTrue(Gateway_Guard::kill('test'));

        remove_filter('pre_http_request', $count);

        $this->assertNull(Token_Store::validate($token), 'A live access token dies with the credential.');
        $this->assertFalse(Gateway_Credential::is_provisioned());
        $this->assertNull(Gateway_Binding::raw());
        $this->assertSame(0, $requests, 'The kill switch makes no network call.');
        $this->assertFalse(Gateway_Guard::kill('test'), 'Idempotent.');
    }
}
