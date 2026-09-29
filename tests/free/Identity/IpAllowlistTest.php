<?php

namespace WPMCP\Tests\Free\Identity;

use WPMCP\Governance\Governance;
use WPMCP\Governance\Governance_Audit_Log;
use WPMCP\Identity\Identity_Context;
use WPMCP\Identity\Identity_Store;
use WPMCP\Identity\Ip_Allowlist;
use WPMCP\MCP\Ability;
use WPMCP\Tools\Identity\Create_Identity;

/**
 * Scoped identities pinned to network addresses (issue #416). An identity
 * with an allowed_ips list is refused, before any ability runs, for any
 * request whose client address is outside the list. The client address is
 * REMOTE_ADDR unless the site owner names trusted proxies, so a forwarded
 * header cannot be spoofed past the list. Identities without the list, and
 * the WP-CLI stdio transport, behave exactly as before.
 */
class IpAllowlistTest extends \WP_UnitTestCase
{
    private ?string $remote_addr = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->remote_addr = $_SERVER['REMOTE_ADDR'] ?? null;
        delete_option(Identity_Store::OPTION);
        delete_option(Governance_Audit_Log::OPTION);
        Ip_Allowlist::reset_for_tests();
    }

    protected function tearDown(): void
    {
        if (null === $this->remote_addr) {
            unset($_SERVER['REMOTE_ADDR']);
        } else {
            $_SERVER['REMOTE_ADDR'] = $this->remote_addr;
        }
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);
        remove_all_filters('wpmcp_trusted_proxies');
        Identity_Context::set_current_for_tests(null);
        Ip_Allowlist::reset_for_tests();
        delete_option(Identity_Store::OPTION);
        delete_option(Governance_Audit_Log::OPTION);
        parent::tearDown();
    }

    private function ability(): Ability
    {
        return new Ability('wpmcp/get-post', 'free', 'desc', [], fn() => [], 'edit_posts', 'content', 'read');
    }

    private function from(string $ip): void
    {
        $_SERVER['REMOTE_ADDR'] = $ip;
        Ip_Allowlist::reset_for_tests();
    }

    // ------------------------------------------------------------ validation

    public function test_create_identity_accepts_and_normalizes_addresses_and_ranges(): void
    {
        $out = (new Create_Identity())->handle([
            'name'        => 'pinned',
            'allowed_ips' => [' 203.0.113.7 ', '10.0.0.0/8', '2001:DB8::/32', '::1', '10.0.0.0/8'],
        ]);

        $this->assertSame(['203.0.113.7', '10.0.0.0/8', '2001:db8::/32', '::1'], $out['allowed_ips']);
        $this->assertSame($out['allowed_ips'], Identity_Store::get('pinned')['allowed_ips']);
    }

    /** @dataProvider invalid_entries */
    public function test_create_identity_rejects_an_invalid_entry(string $entry): void
    {
        try {
            (new Create_Identity())->handle(['name' => 'pinned', 'allowed_ips' => ['10.0.0.1', $entry]]);
            $this->fail('An invalid allowed_ips entry must be refused.');
        } catch (\InvalidArgumentException $e) {
            $this->assertNull(Identity_Store::get('pinned'), 'Nothing is stored when the list is invalid.');
        }
    }

    public static function invalid_entries(): array
    {
        return [
            'not an address'   => ['example.com'],
            'empty'            => [''],
            'v4 prefix too big' => ['10.0.0.0/33'],
            'v6 prefix too big' => ['2001:db8::/129'],
            'non-numeric bits' => ['10.0.0.0/x'],
            'negative bits'    => ['10.0.0.0/-1'],
            'double slash'     => ['10.0.0.0/8/8'],
            'wildcard'         => ['10.0.0.*'],
        ];
    }

    public function test_allowed_ips_must_be_a_list(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Create_Identity())->handle(['name' => 'pinned', 'allowed_ips' => '10.0.0.1']);
    }

    public function test_overwriting_an_identity_without_the_list_clears_it(): void
    {
        (new Create_Identity())->handle(['name' => 'pinned', 'allowed_ips' => ['10.0.0.1']]);
        (new Create_Identity())->handle(['name' => 'pinned', 'allowed_ips' => []]);

        $this->assertSame([], Identity_Store::get('pinned')['allowed_ips']);
    }

    // -------------------------------------------------------------- matching

    public function test_contains_matches_single_addresses_and_ipv4_and_ipv6_ranges(): void
    {
        $list = ['203.0.113.7', '10.0.0.0/8', '192.168.1.128/25', '2001:db8::/32', '::1'];

        $this->assertTrue(Ip_Allowlist::contains($list, '203.0.113.7'));
        $this->assertTrue(Ip_Allowlist::contains($list, '10.255.3.4'));
        $this->assertTrue(Ip_Allowlist::contains($list, '192.168.1.200'));
        $this->assertTrue(Ip_Allowlist::contains($list, '2001:db8:1::abcd'));
        $this->assertTrue(Ip_Allowlist::contains($list, '::1'));
        $this->assertTrue(Ip_Allowlist::contains($list, '::ffff:10.1.2.3'), 'An IPv4-mapped IPv6 client matches its IPv4 range.');

        $this->assertFalse(Ip_Allowlist::contains($list, '203.0.113.8'));
        $this->assertFalse(Ip_Allowlist::contains($list, '11.0.0.1'));
        $this->assertFalse(Ip_Allowlist::contains($list, '192.168.1.127'));
        $this->assertFalse(Ip_Allowlist::contains($list, '2001:db9::1'));
        $this->assertFalse(Ip_Allowlist::contains($list, 'garbage'));
        $this->assertFalse(Ip_Allowlist::contains([], '10.0.0.1'));
    }

    // --------------------------------------------------------- client address

    public function test_forwarded_headers_are_ignored_unless_trusted_proxies_are_configured(): void
    {
        $this->from('198.51.100.9');
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '10.0.0.1';

        $this->assertSame('198.51.100.9', Ip_Allowlist::client_ip());
    }

    public function test_forwarded_headers_are_ignored_when_the_peer_is_not_a_trusted_proxy(): void
    {
        add_filter('wpmcp_trusted_proxies', static fn () => ['172.16.0.0/12']);
        $this->from('198.51.100.9');
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '10.0.0.1';

        $this->assertSame('198.51.100.9', Ip_Allowlist::client_ip());
    }

    public function test_a_trusted_proxy_forwards_the_nearest_untrusted_hop(): void
    {
        add_filter('wpmcp_trusted_proxies', static fn () => ['172.16.0.0/12']);
        $this->from('172.16.0.5');
        // The leftmost entry is whatever the client claimed; only the hop the
        // trusted proxy itself appended is believed.
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '10.0.0.1, 203.0.113.7, 172.16.0.9';

        $this->assertSame('203.0.113.7', Ip_Allowlist::client_ip());
    }

    public function test_a_malformed_forwarded_hop_fails_closed(): void
    {
        add_filter('wpmcp_trusted_proxies', static fn () => ['172.16.0.5']);
        $this->from('172.16.0.5');
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '10.0.0.1, not-an-ip';

        $this->assertNull(Ip_Allowlist::client_ip());
    }

    // ------------------------------------------------------------ enforcement

    public function test_an_identity_with_allowed_ips_refuses_other_addresses_and_accepts_matching_ranges(): void
    {
        Identity_Store::create('pinned', ['allowed_ips' => Ip_Allowlist::validate(['10.0.0.0/8', '2001:db8::/32'])]);
        Identity_Context::set_current_for_tests('pinned');

        $this->from('10.1.2.3');
        $this->assertTrue(Governance::is_within_identity_scope($this->ability()));
        $this->from('2001:db8::7');
        $this->assertTrue(Governance::is_within_identity_scope($this->ability()));

        $this->from('198.51.100.9');
        $this->assertFalse(Governance::is_within_identity_scope($this->ability()));
        $this->from('2001:db9::7');
        $this->assertFalse(Governance::is_within_identity_scope($this->ability()));
    }

    public function test_a_spoofed_forwarded_header_cannot_bypass_the_list(): void
    {
        Identity_Store::create('pinned', ['allowed_ips' => ['10.0.0.0/8']]);
        Identity_Context::set_current_for_tests('pinned');
        $this->from('198.51.100.9');
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '10.0.0.1';

        $this->assertFalse(Governance::is_within_identity_scope($this->ability()));
    }

    public function test_an_unknown_client_address_fails_closed(): void
    {
        Identity_Store::create('pinned', ['allowed_ips' => ['10.0.0.0/8']]);
        Identity_Context::set_current_for_tests('pinned');
        unset($_SERVER['REMOTE_ADDR']);
        Ip_Allowlist::reset_for_tests();

        $this->assertFalse(Governance::is_within_identity_scope($this->ability()));
    }

    public function test_identities_without_the_list_behave_exactly_as_before(): void
    {
        Identity_Store::create('open', ['domains' => ['content']]);
        Identity_Context::set_current_for_tests('open');
        unset($_SERVER['REMOTE_ADDR']);
        Ip_Allowlist::reset_for_tests();

        $this->assertSame([], Identity_Store::get('open')['allowed_ips']);
        $this->assertTrue(Governance::is_within_identity_scope($this->ability()));
        $this->assertSame([], get_option(Governance_Audit_Log::OPTION, []));
    }

    public function test_the_stdio_transport_is_unaffected(): void
    {
        Identity_Store::create('pinned', ['allowed_ips' => ['10.0.0.0/8']]);
        Identity_Context::set_current_for_tests('pinned');
        unset($_SERVER['REMOTE_ADDR']);
        Ip_Allowlist::set_cli_for_tests(true);

        $this->assertTrue(Governance::is_within_identity_scope($this->ability()));
    }

    public function test_a_refusal_is_audited_once_per_request_without_telling_the_client_why(): void
    {
        Identity_Store::create('pinned', ['allowed_ips' => ['10.0.0.0/8']]);
        Identity_Context::set_current_for_tests('pinned');
        $this->from('198.51.100.9');

        Governance::is_within_identity_scope($this->ability());
        Governance::is_within_identity_scope($this->ability());

        $rows = array_values(array_filter(
            (array) get_option(Governance_Audit_Log::OPTION, []),
            static fn ($row) => 'identity/ip-refused' === $row['ability']
        ));
        $this->assertCount(1, $rows);
        $this->assertSame('pinned', $rows[0]['identity']);
        $this->assertFalse($rows[0]['allowed']);
        $this->assertSame('client:198.51.100.9', $rows[0]['reason']);
    }

    public function test_the_mcp_route_is_refused_with_a_generic_error_before_dispatch(): void
    {
        Identity_Store::create('pinned', ['allowed_ips' => ['10.0.0.0/8']]);
        Identity_Context::set_current_for_tests('pinned');
        $this->from('198.51.100.9');

        $result = Ip_Allowlist::filter_pre_dispatch(null, rest_get_server(), new \WP_REST_Request('POST', '/mcp/wpmcp-server'));

        $this->assertWPError($result);
        $this->assertSame(403, $result->get_error_data()['status']);
        $this->assertStringNotContainsStringIgnoringCase('ip', $result->get_error_message());
        $this->assertStringNotContainsStringIgnoringCase('address', $result->get_error_message());
    }

    public function test_the_admin_ui_and_other_routes_are_never_refused(): void
    {
        Identity_Store::create('pinned', ['allowed_ips' => ['10.0.0.0/8']]);
        Identity_Context::set_current_for_tests('pinned');
        $this->from('198.51.100.9');

        $this->assertNull(Ip_Allowlist::filter_pre_dispatch(null, rest_get_server(), new \WP_REST_Request('GET', '/wp/v2/posts')));

        Identity_Context::set_current_for_tests(null);
        $this->assertNull(Ip_Allowlist::filter_pre_dispatch(null, rest_get_server(), new \WP_REST_Request('POST', '/mcp/wpmcp-server')));
    }

    public function test_the_filter_passes_an_earlier_result_through(): void
    {
        $earlier = new \WP_Error('x', 'y');
        $this->assertSame($earlier, Ip_Allowlist::filter_pre_dispatch($earlier, rest_get_server(), new \WP_REST_Request('POST', '/mcp/wpmcp-server')));
    }
}
