<?php

namespace WPMCP\Tests\Free\MCP;

use WPMCP\Governance\Governance;
use WPMCP\Identity\Identity_Context;
use WPMCP\Identity\Identity_Store;
use WPMCP\Identity\Ip_Allowlist;
use WPMCP\MCP\Ability;
use WPMCP\MCP\Availability;
use WPMCP\MCP\Registrar;
use WPMCP\MCP\Unavailable;
use WPMCP\Memory\Memory_Store;
use WPMCP\Pro\Gate;

/**
 * Issue #627: one unavailability contract. Every reason code builds the same
 * shape, Availability::explain() walks the requirements in a fixed order and
 * returns the first reason, and the refusals that used to be a bare
 * "Permission denied" (identity scope, unknown identity, memory block) now
 * name the rule that stopped the call.
 */
class UnavailabilityContractTest extends \WP_UnitTestCase
{
    protected function tearDown(): void
    {
        Gate::set_pro_for_tests(null);
        Identity_Context::set_current_for_tests(null);
        Ip_Allowlist::reset_for_tests();
        delete_option(Identity_Store::OPTION);
        delete_option(Governance::OPTION);
        Availability::set_plugin_state_for_tests('wpmcp-fixture-627', null);
        remove_all_filters('wpmcp_ability_enabled');
        remove_all_filters('wpmcp_fixture_627_opt_in');
        unset($_SERVER['REMOTE_ADDR']);
        Memory_Store::flush_rules_cache();
        parent::tearDown();
    }

    /** @param array<string, mixed> $requires */
    private function ability(array $requires = [], string $tier = 'free', string $capability = 'edit_posts', string $operation = 'update'): Ability
    {
        return new Ability('wpmcp/fixture-627', $tier, 'x', [ 'type' => 'object' ], static fn () => [], $capability, 'content', $operation, null, null, null, [], null, null, $requires);
    }

    private function admin(): void
    {
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
    }

    public function test_every_reason_code_builds_the_full_contract(): void
    {
        $this->assertCount(18, Unavailable::REASONS);
        foreach (Unavailable::REASONS as $reason => $defaults) {
            $out = ( new Unavailable($reason, 'Stopped.', 'Do this.') )->to_array();

            $this->assertSame($reason, $out['reason']);
            $this->assertContains($out['action']['who'], Unavailable::ACTORS, $reason);
            $this->assertSame('Do this.', $out['action']['text']);
            $this->assertSame('https://wpmcp-pro.com/docs.html#unavailable-' . str_replace('_', '-', $reason), $out['docs_url']);
            $this->assertContains($out['retryable'], Unavailable::RETRYABLE, $reason);
            $this->assertSame($defaults['hidden'], $out['hidden'], $reason);
            $this->assertArrayNotHasKey('admin_url', $out['action']);
        }
    }

    public function test_an_unknown_reason_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Unavailable('because', 'x', 'y');
    }

    public function test_the_wp_error_form_carries_the_contract_and_keeps_existing_data(): void
    {
        $u     = new Unavailable('rate_limited', 'Too fast.', 'Wait.', [ 'retry_after' => 30, 'admin_url' => 'https://example.org/wp-admin/' ]);
        $error = $u->to_wp_error('', [ 'extra' => 1 ]);

        $this->assertSame('rate_limited', $error->get_error_code());
        $this->assertSame('Too fast. Wait.', $error->get_error_message());
        $this->assertSame(1, $error->get_error_data()['extra']);
        $this->assertSame(30, $error->get_error_data()['unavailable']['retry_after']);
        $this->assertSame('later', $error->get_error_data()['unavailable']['retryable']);
        $this->assertSame('https://example.org/wp-admin/', $error->get_error_data()['unavailable']['action']['admin_url']);

        $kept = $u->attach(new \WP_Error('wpmcp_rate_limited', 'Old text.', [ 'remaining' => 0 ]));
        $this->assertSame('wpmcp_rate_limited', $kept->get_error_code());
        $this->assertSame('Old text.', $kept->get_error_message());
        $this->assertSame(0, $kept->get_error_data()['remaining']);
        $this->assertSame('rate_limited', $kept->get_error_data()['unavailable']['reason']);
    }

    public function test_nothing_in_the_way_explains_to_null(): void
    {
        $this->admin();
        $this->assertNull(Availability::explain($this->ability()));
    }

    public function test_environment_needs_a_newer_php(): void
    {
        $this->admin();
        $this->assertSame('environment', Availability::explain($this->ability([ 'php' => '99.0' ]))->reason);
        $this->assertSame('environment', Availability::explain($this->ability([ 'extension' => [ 'wpmcp_no_such_ext' ] ]))->reason);
    }

    public function test_license_is_pro_required(): void
    {
        $this->admin();
        Gate::set_pro_for_tests(false);
        $out = Availability::explain($this->ability([], 'pro'));
        $this->assertSame('pro_required', $out->reason);
        $this->assertSame('account_owner', $out->who);
        $this->assertTrue($out->hidden);
    }

    public function test_plugin_missing_and_inactive_are_told_apart(): void
    {
        $this->admin();
        $ability = $this->ability([ 'plugin' => [ 'wpmcp-fixture-627' => 'Fixture Plugin' ] ]);

        Availability::set_plugin_state_for_tests('wpmcp-fixture-627', 'missing');
        $out = Availability::explain($ability);
        $this->assertSame('plugin_missing', $out->reason);
        $this->assertStringContainsString('Fixture Plugin is not active', $out->message);
        $this->assertStringContainsString('plugins.php', $out->admin_url);

        Availability::set_plugin_state_for_tests('wpmcp-fixture-627', 'inactive');
        $this->assertSame('plugin_inactive', Availability::explain($ability)->reason);

        Availability::set_plugin_state_for_tests('wpmcp-fixture-627', 'active');
        $this->assertNull(Availability::explain($ability));
    }

    public function test_plugin_state_reads_active_plugins(): void
    {
        $this->assertSame('missing', Availability::plugin_state('wpmcp-fixture-627-none'));
        update_option('active_plugins', [ 'wpmcp-fixture-627-none/main.php' ]);
        $this->assertSame('active', Availability::plugin_state('wpmcp-fixture-627-none'));
    }

    public function test_multisite_required_on_a_single_site(): void
    {
        if (is_multisite()) {
            $this->markTestSkipped('Single-site check.');
        }
        $this->admin();
        $this->assertSame('multisite_required', Availability::explain($this->ability([ 'multisite' => true ]))->reason);
    }

    public function test_governance_toggle_and_filter_are_admin_and_code(): void
    {
        $this->admin();
        Governance::set_ability_toggle('wpmcp/fixture-627', false);
        $this->assertSame('disabled_by_admin', Availability::explain($this->ability())->reason);

        delete_option(Governance::OPTION);
        add_filter('wpmcp_ability_enabled', static fn ($on, $name) => 'wpmcp/fixture-627' === $name ? false : $on, 10, 2);
        $out = Availability::explain($this->ability());
        $this->assertSame('disabled_by_code', $out->reason);
        $this->assertSame('developer', $out->who);
    }

    public function test_opt_in_and_constant(): void
    {
        $this->admin();
        $out = Availability::explain($this->ability([ 'opt_in' => 'wpmcp_fixture_627_opt_in' ]));
        $this->assertSame('opt_in_required', $out->reason);
        $this->assertStringContainsString('wpmcp_fixture_627_opt_in', $out->action);
        $this->assertFalse($out->hidden);

        add_filter('wpmcp_fixture_627_opt_in', '__return_true');
        $this->assertNull(Availability::explain($this->ability([ 'opt_in' => 'wpmcp_fixture_627_opt_in' ])));

        if (! defined('WPMCP_FIXTURE_627_OFF')) {
            define('WPMCP_FIXTURE_627_OFF', true);
        }
        $this->assertSame('wp_constant', Availability::explain($this->ability([ 'constant' => 'WPMCP_FIXTURE_627_OFF' ]))->reason);
    }

    public function test_identity_scope_and_unknown_identity(): void
    {
        $this->admin();
        Identity_Store::create('posts-only', [ 'domains' => [ 'woocommerce' ] ]);
        Identity_Context::set_current_for_tests('posts-only');
        $this->assertSame('identity_scope', Availability::explain($this->ability())->reason);

        Identity_Context::set_current_for_tests('nobody-627');
        $out = Availability::explain($this->ability());
        $this->assertSame('identity_scope', $out->reason);
        $this->assertStringContainsString('does not exist', $out->message);
    }

    public function test_an_address_refusal_does_not_name_the_address_rule(): void
    {
        $this->admin();
        Identity_Store::create('pinned', [ 'allowed_ips' => [ '10.0.0.0/8' ] ]);
        Identity_Context::set_current_for_tests('pinned');
        Ip_Allowlist::set_cli_for_tests(false);
        $_SERVER['REMOTE_ADDR'] = '198.51.100.9';
        Ip_Allowlist::reset_for_tests();
        Ip_Allowlist::set_cli_for_tests(false);

        $out = Availability::explain($this->ability());
        $this->assertSame('identity_scope', $out->reason);
        $this->assertTrue($out->hidden);
        $this->assertStringNotContainsStringIgnoringCase('address', $out->message . $out->action);
        $this->assertStringContainsString('audit log', $out->action);
    }

    public function test_capability_missing_for_a_subscriber(): void
    {
        wp_set_current_user(self::factory()->user->create([ 'role' => 'subscriber' ]));
        $out = Availability::explain($this->ability());
        $this->assertSame('capability_missing', $out->reason);
        $this->assertSame('site_admin', $out->who);
    }

    public function test_object_denied_for_a_post_the_caller_may_not_edit(): void
    {
        $other = self::factory()->user->create([ 'role' => 'administrator' ]);
        $post  = self::factory()->post->create([ 'post_author' => $other, 'post_status' => 'publish' ]);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'contributor' ]));

        $out = Availability::explain($this->ability(), [ 'input' => [ 'post_id' => $post ] ]);
        $this->assertSame('object_denied', $out->reason);
        $this->assertSame('with_different_input', $out->retryable);
    }

    public function test_memory_block_is_memory_blocked(): void
    {
        $this->admin();
        $id = $this->publish_block([ 'tool:fixture-627' ]);

        $out = Availability::explain($this->ability());
        $this->assertSame('memory_blocked', $out->reason);
        $this->assertStringContainsString('entry ' . $id, $out->message);
    }

    public function test_the_walk_returns_site_level_reasons_before_user_level_ones(): void
    {
        $this->assertSame([ 'environment', 'license', 'plugin', 'multisite', 'disabled', 'opt_in', 'oauth_scope', 'identity', 'capability', 'input' ], Availability::ORDER);

        // A subscriber, an out-of-scope identity, a closed opt-in, a governance
        // toggle, a missing plugin, no license and an old PHP all at once:
        // each fix reveals the next reason, in the walk's order.
        wp_set_current_user(self::factory()->user->create([ 'role' => 'subscriber' ]));
        Identity_Store::create('other-domain', [ 'domains' => [ 'woocommerce' ] ]);
        Identity_Context::set_current_for_tests('other-domain');
        Governance::set_ability_toggle('wpmcp/fixture-627', false);
        Availability::set_plugin_state_for_tests('wpmcp-fixture-627', 'missing');
        Gate::set_pro_for_tests(false);
        $requires = [ 'php' => '99.0', 'plugin' => [ 'wpmcp-fixture-627' => 'Fixture' ], 'opt_in' => 'wpmcp_fixture_627_opt_in' ];

        $this->assertSame('environment', Availability::explain($this->ability($requires, 'pro'))->reason);
        unset($requires['php']);
        $this->assertSame('pro_required', Availability::explain($this->ability($requires, 'pro'))->reason);
        $this->assertSame('plugin_missing', Availability::explain($this->ability($requires))->reason);
        Availability::set_plugin_state_for_tests('wpmcp-fixture-627', 'active');
        $this->assertSame('disabled_by_admin', Availability::explain($this->ability($requires))->reason);
        delete_option(Governance::OPTION);
        $this->assertSame('opt_in_required', Availability::explain($this->ability($requires))->reason);
        add_filter('wpmcp_fixture_627_opt_in', '__return_true');
        $this->assertSame('identity_scope', Availability::explain($this->ability($requires))->reason);
        Identity_Context::set_current_for_tests(null);
        $this->assertSame('capability_missing', Availability::explain($this->ability($requires))->reason);
    }

    public function test_the_permission_refusal_names_the_identity_scope(): void
    {
        $this->admin();
        Identity_Store::create('shop-only', [ 'domains' => [ 'woocommerce' ] ]);
        Identity_Context::set_current_for_tests('shop-only');

        $denial = (new Registrar())->permission($this->ability());

        $this->assertInstanceOf(\WP_Error::class, $denial);
        $this->assertSame('identity_scope', $denial->get_error_code());
        $this->assertStringContainsString('outside the scope of the WP MCP identity', $denial->get_error_message());
        $this->assertSame('identity_scope', $denial->get_error_data()['unavailable']['reason']);
    }

    public function test_the_permission_refusal_for_an_unknown_identity(): void
    {
        $this->admin();
        Identity_Context::set_current_for_tests('deleted-627');

        $denial = (new Registrar())->permission($this->ability());

        $this->assertInstanceOf(\WP_Error::class, $denial);
        $this->assertSame('identity_scope', $denial->get_error_code());
        $this->assertStringContainsString('does not exist', $denial->get_error_message());
    }

    public function test_the_permission_refusal_for_a_memory_block_differs_from_the_identity_one(): void
    {
        $this->admin();
        $id = $this->publish_block([ 'post_id:1' ]);

        $denial = (new Registrar())->permission($this->ability(), [ 'post_id' => 1 ]);

        $this->assertInstanceOf(\WP_Error::class, $denial);
        $this->assertSame('memory_blocked', $denial->get_error_code());
        $this->assertStringContainsString('entry ' . $id, $denial->get_error_message());
        $this->assertSame($id, $denial->get_error_data()['memory_entry']);
        $this->assertSame('with_different_input', $denial->get_error_data()['unavailable']['retryable']);
    }

    public function test_the_capability_refusal_keeps_its_code_and_gains_the_contract(): void
    {
        wp_set_current_user(self::factory()->user->create([ 'role' => 'subscriber' ]));

        $denial = (new Registrar())->permission($this->ability());

        $this->assertSame('operation_denied', $denial->get_error_code());
        $this->assertSame('capability', $denial->get_error_data()['reason']);
        $this->assertSame('capability_missing', $denial->get_error_data()['unavailable']['reason']);
    }

    public function test_the_ip_refusal_at_the_route_stays_silent_on_the_address_but_names_the_identity(): void
    {
        Identity_Store::create('pinned', [ 'allowed_ips' => [ '10.0.0.0/8' ] ]);
        Identity_Context::set_current_for_tests('pinned');
        Ip_Allowlist::set_cli_for_tests(false);
        $_SERVER['REMOTE_ADDR'] = '198.51.100.9';
        Ip_Allowlist::reset_for_tests();
        Ip_Allowlist::set_cli_for_tests(false);

        $result = Ip_Allowlist::filter_pre_dispatch(null, null, new \WP_REST_Request('POST', '/mcp/wpmcp-server'));

        $this->assertWPError($result);
        $this->assertSame('rest_forbidden', $result->get_error_code());
        $this->assertSame(403, $result->get_error_data()['status']);
        $this->assertStringContainsString('identity', $result->get_error_message());
        $this->assertStringNotContainsStringIgnoringCase('ip', $result->get_error_message());
        $this->assertStringNotContainsStringIgnoringCase('address', $result->get_error_message());
        $this->assertSame('identity_scope', $result->get_error_data()['unavailable']['reason']);
    }

    /** @param string[] $targets */
    private function publish_block(array $targets): int
    {
        Memory_Store::ensure_post_type();
        Memory_Store::flush_rules_cache();
        $id = Memory_Store::propose([ 'text' => 'Do not.', 'kind' => 'guardrail', 'severity' => 'block', 'targets' => $targets ]);
        Memory_Store::approve($id);
        return $id;
    }
}
