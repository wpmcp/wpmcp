<?php

namespace WPMCP\Tests\Free\MCP;

use WPMCP\Governance\Governance;
use WPMCP\Governance\Governance_Audit_Log;
use WPMCP\Identity\Identity_Context;
use WPMCP\Identity\Identity_Store;
use WPMCP\MCP\Ability;
use WPMCP\MCP\Tool_Exposure;
use WPMCP\Plugin;
use WPMCP\Pro\Gate;
use WPMCP\RateLimit\Rate_Limiter;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Dispatch\Call_Tool;

/**
 * The security-critical conformance suite for the call-tool dispatcher
 * (issue #79): dispatching an ability through wpmcp/call-tool MUST be
 * permission-identical to calling it directly. The dispatcher only ever
 * invokes the registered WP_Ability's execute(), which runs the exact same
 * permission_callback (Registrar::is_permitted: capability + Governance +
 * identity scope + pro-tier license, audited) and the exact same wrapped
 * execute_callback (rate limiter + Safety snapshot path) as a direct MCP
 * tool call. These tests prove a dispatched invocation bypasses zero
 * checks, in both exposure modes.
 */
class CallToolConformanceTest extends \WP_UnitTestCase
{
    public static function wpSetUpBeforeClass(): void
    {
        if (0 === did_action('wp_abilities_api_init')) {
            do_action('wp_abilities_api_init');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        Governance::reset_for_tests();
        delete_option(Identity_Store::OPTION);
        delete_option(Governance_Audit_Log::OPTION);
        delete_option(Tool_Exposure::OPTION);
        // A unique, frozen clock bucket per test class run so rate-limit
        // counters from other tests never bleed in.
        Rate_Limiter::set_clock_override(fn() => 1_790_000_000);
        add_filter('wpmcp_rate_limit', fn() => 100000);
    }

    protected function tearDown(): void
    {
        Identity_Context::set_current_for_tests(null);
        Gate::set_pro_for_tests(null);
        Rate_Limiter::set_clock_override(null);
        Governance::reset_for_tests();
        delete_option(Identity_Store::OPTION);
        delete_option(Governance_Audit_Log::OPTION);
        delete_option(Tool_Exposure::OPTION);
        remove_all_filters('wpmcp_rate_limit');
        remove_all_filters('wpmcp_rate_limit_window');
        parent::tearDown();
    }

    private function as_user(string $role): int
    {
        $id = self::factory()->user->create(['role' => $role]);
        wp_set_current_user($id);
        return $id;
    }

    /** Dispatch through the LIVE wpmcp/call-tool ability, end to end. */
    private function dispatch(string $name, array $arguments = [])
    {
        return wp_get_ability('wpmcp/call-tool')->execute([
            'name'      => $name,
            'arguments' => $arguments,
        ]);
    }

    public function test_dispatch_executes_a_read_tool_identically_to_a_direct_call(): void
    {
        $this->as_user('administrator');
        $id = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'Parity']);

        $direct     = wp_get_ability('wpmcp/get-page')->execute(['id' => $id]);
        $dispatched = $this->dispatch('wpmcp/get-page', ['id' => $id]);

        $this->assertIsArray($direct);
        $this->assertSame($direct, $dispatched);
    }

    /**
     * THE core security property: a caller who lacks the target's capability
     * cannot reach it through the dispatcher. wpmcp/get-settings requires
     * manage_options; an editor holds edit_posts (enough for call-tool's own
     * shell) but not manage_options.
     */
    public function test_dispatch_cannot_reach_a_tool_the_caller_could_not_call_directly(): void
    {
        $this->as_user('editor');

        $target = wp_get_ability('wpmcp/get-settings');
        $this->assertNotTrue($target->check_permissions([]), 'Precondition: editor must be denied directly.');

        $direct     = $target->execute([]);
        $dispatched = $this->dispatch('wpmcp/get-settings');

        $this->assertInstanceOf(\WP_Error::class, $direct);
        $this->assertInstanceOf(\WP_Error::class, $dispatched);
        $this->assertSame('ability_invalid_permissions', $dispatched->get_error_code());
    }

    public function test_dispatch_honors_a_live_governance_disable(): void
    {
        $this->as_user('administrator');
        $id = self::factory()->post->create(['post_type' => 'page']);

        $this->assertIsArray($this->dispatch('wpmcp/get-page', ['id' => $id]), 'Sanity: allowed before the toggle.');

        Governance::set_ability_toggle('wpmcp/get-page', false);

        $this->assertNotTrue(wp_get_ability('wpmcp/get-page')->check_permissions(['id' => $id]));
        $dispatched = $this->dispatch('wpmcp/get-page', ['id' => $id]);
        $this->assertInstanceOf(\WP_Error::class, $dispatched);
    }

    public function test_dispatch_honors_identity_scope_narrowing(): void
    {
        $this->as_user('administrator');
        $id = self::factory()->post->create(['post_type' => 'page']);

        // In scope: identity allows the dispatch domain (the dispatcher shell)
        // and the target's core domain.
        Identity_Store::create('core-bot', ['domains' => ['dispatch', 'core']]);
        Identity_Context::set_current_for_tests('core-bot');
        $this->assertIsArray($this->dispatch('wpmcp/get-page', ['id' => $id]));

        // Out of scope: same shell access, target domain no longer allowed.
        Identity_Store::create('meta-only-bot', ['domains' => ['dispatch']]);
        Identity_Context::set_current_for_tests('meta-only-bot');

        $this->assertNotTrue(wp_get_ability('wpmcp/get-page')->check_permissions(['id' => $id]));
        $this->assertInstanceOf(\WP_Error::class, $this->dispatch('wpmcp/get-page', ['id' => $id]));
    }

    /**
     * Pro-tier license parity (issue #54 semantics preserved through the
     * dispatcher): a pro ability whose license lapses AFTER registration
     * denies identically on both paths. The synthetic pro ability is
     * registered through a private wp_abilities_api_init window (only this
     * test's hook fires; the framework restores all hooks at tearDown)
     * against a temporarily swapped fresh Registrar, so the shared
     * registrar — which other suites assert has zero pro-tier entries on
     * an unlicensed install — is never polluted. The live registry entry
     * is removed again in the finally block.
     */
    public function test_dispatch_honors_the_live_pro_license_check(): void
    {
        $this->as_user('administrator');

        $plugin   = Plugin::instance();
        $prop     = new \ReflectionProperty(Plugin::class, 'registrar');
        $original = $prop->getValue($plugin);
        $fresh    = new \WPMCP\MCP\Registrar();
        $prop->setValue($plugin, $fresh);

        try {
            Gate::set_pro_for_tests(true);
            remove_all_actions('wp_abilities_api_init');
            add_action('wp_abilities_api_init', function () use ($fresh) {
                $fresh->register(new Ability(
                    'wpmcp/test-pro-tool',
                    'pro',
                    'Synthetic pro tool for license-parity testing',
                    ['type' => 'object', 'properties' => []],
                    fn(array $args = []) => ['ok' => true],
                    'edit_posts',
                    'content',
                    'read'
                ));
            });
            do_action('wp_abilities_api_init');

            // Licensed: both paths succeed.
            $this->assertSame(['ok' => true], wp_get_ability('wpmcp/test-pro-tool')->execute([]));
            $this->assertSame(['ok' => true], $this->dispatch('wpmcp/test-pro-tool'));

            // License lapses AFTER registration: both paths must deny.
            Gate::set_pro_for_tests(false);
            $this->assertInstanceOf(\WP_Error::class, wp_get_ability('wpmcp/test-pro-tool')->execute([]));
            $this->assertInstanceOf(\WP_Error::class, $this->dispatch('wpmcp/test-pro-tool'));
        } finally {
            Gate::set_pro_for_tests(null);
            wp_unregister_ability('wpmcp/test-pro-tool');
            $prop->setValue($plugin, $original);
        }
    }

    public function test_dispatched_invocations_are_rate_limited_like_direct_calls(): void
    {
        $this->as_user('administrator');
        $id = self::factory()->post->create(['post_type' => 'page']);

        remove_all_filters('wpmcp_rate_limit');
        add_filter('wpmcp_rate_limit', fn() => 1);
        add_filter('wpmcp_rate_limit_window', fn() => 60);

        // The single budget unit is spent on the dispatcher shell itself, so
        // the dispatched target's own rate check (the same global per-client
        // counter) must throttle — the limiter is not bypassable via dispatch.
        $dispatched = $this->dispatch('wpmcp/get-page', ['id' => $id]);

        $this->assertInstanceOf(\WP_Error::class, $dispatched);
        $this->assertSame('wpmcp_rate_limited', $dispatched->get_error_code());
    }

    public function test_dispatched_mutations_snapshot_identically_to_direct_calls(): void
    {
        $this->as_user('administrator');
        $id = self::factory()->post->create(['post_type' => 'post', 'post_title' => 'Before']);

        $result = $this->dispatch('wpmcp/update-post', [
            'post_id'    => $id,
            'title'      => 'After',
            'session_id' => 'conformance-session',
        ]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('operation_id', $result);
        $this->assertSame('After', get_post($id)->post_title);

        $snapshot = Snapshot_Store::get_by_operation($result['operation_id']);
        $this->assertNotNull($snapshot, 'A dispatched mutation must leave the same snapshot trail as a direct one.');
    }

    public function test_dispatch_refuses_abilities_not_registered_by_wpmcp(): void
    {
        $this->as_user('administrator');

        remove_all_actions('wp_abilities_api_init');
        add_action('wp_abilities_api_init', function () {
            wp_register_ability('wpmcp-tests/foreign-tool', [
                'label'               => 'Foreign tool',
                'description'         => 'Registered by another plugin, outside the wpmcp Registrar.',
                'category'            => 'wpmcp',
                'input_schema'        => ['type' => 'object', 'properties' => []],
                'execute_callback'    => fn($input = []) => ['leaked' => true],
                'permission_callback' => '__return_true',
            ]);
        });
        do_action('wp_abilities_api_init');

        try {
            // The foreign ability IS directly callable for this user...
            $this->assertSame(['leaked' => true], wp_get_ability('wpmcp-tests/foreign-tool')->execute([]));

            // ...but the wpmcp dispatcher must refuse to be a gateway to it.
            $dispatched = $this->dispatch('wpmcp-tests/foreign-tool');
            $this->assertInstanceOf(\WP_Error::class, $dispatched);
            $this->assertSame('wpmcp_call_tool_unknown', $dispatched->get_error_code());
        } finally {
            wp_unregister_ability('wpmcp-tests/foreign-tool');
        }
    }

    public function test_dispatch_refuses_the_meta_tools_themselves(): void
    {
        $this->as_user('administrator');

        foreach (Tool_Exposure::META_ABILITIES as $meta) {
            $dispatched = $this->dispatch($meta);
            $this->assertInstanceOf(\WP_Error::class, $dispatched, $meta);
            $this->assertSame('wpmcp_call_tool_meta', $dispatched->get_error_code(), $meta);
        }
    }

    public function test_call_tool_handler_validates_the_name_argument(): void
    {
        $this->as_user('administrator');

        $handler = new Call_Tool();
        $this->assertInstanceOf(\WP_Error::class, $handler->handle([]));
        $this->assertInstanceOf(\WP_Error::class, $handler->handle(['name' => '']));
        $this->assertInstanceOf(\WP_Error::class, $handler->handle(['name' => 42]));
    }

    /**
     * An ability known to the Registrar but absent from the live Abilities
     * registry (e.g. the Abilities API window never ran for it) must produce
     * a clean error, not a crash or a raw-handler fallback — the dispatcher
     * has no invocation path other than the live WP_Ability.
     */
    public function test_dispatch_errors_cleanly_when_the_live_registry_entry_is_missing(): void
    {
        $this->as_user('administrator');

        $plugin   = Plugin::instance();
        $prop     = new \ReflectionProperty(Plugin::class, 'registrar');
        $original = $prop->getValue($plugin);
        $fresh    = new \WPMCP\MCP\Registrar();
        $prop->setValue($plugin, $fresh);

        try {
            // Registered outside a wp_abilities_api_init window: lands in the
            // Registrar's map but never in the live Abilities registry.
            $fresh->register(new Ability(
                'wpmcp/registrar-only-tool',
                'free',
                'Registrar-only tool for the unavailable branch',
                ['type' => 'object', 'properties' => []],
                fn(array $args = []) => ['ok' => true]
            ));

            $result = (new Call_Tool())->handle(['name' => 'wpmcp/registrar-only-tool']);

            $this->assertInstanceOf(\WP_Error::class, $result);
            $this->assertSame('wpmcp_call_tool_unavailable', $result->get_error_code());
        } finally {
            $prop->setValue($plugin, $original);
        }
    }

    public function test_dispatch_denials_are_audited_under_the_target_ability_name(): void
    {
        $this->as_user('editor');

        delete_option(Governance_Audit_Log::OPTION);
        $this->dispatch('wpmcp/get-settings');

        $denied = array_filter(
            Governance_Audit_Log::list(),
            fn(array $e) => 'wpmcp/get-settings' === $e['ability'] && false === $e['allowed']
        );

        $this->assertNotEmpty($denied, 'The dispatched target denial must land in the governance audit log.');
    }

    /**
     * The shared conformance sweep, run in BOTH exposure modes: for every
     * registered ability, the dispatcher's permission outcome must equal the
     * direct outcome. Under an identity scoped to the meta domain only,
     * every non-meta target must deny on both paths — across the entire
     * surface, in full and compact mode alike (mode is exposure-only and
     * must never alter a permission decision).
     */
    public function test_full_surface_conformance_sweep_in_both_modes(): void
    {
        $this->as_user('administrator');

        Identity_Store::create('meta-only-bot', ['domains' => ['dispatch']]);
        Identity_Context::set_current_for_tests('meta-only-bot');

        foreach ([Tool_Exposure::MODE_FULL, Tool_Exposure::MODE_COMPACT] as $mode) {
            update_option(Tool_Exposure::OPTION, $mode);

            foreach (Plugin::instance()->registrar()->all() as $ability) {
                if (in_array($ability->name, Tool_Exposure::META_ABILITIES, true)) {
                    continue;
                }

                $live = wp_get_ability($ability->name);
                $this->assertNotNull($live, $ability->name);

                $direct_allowed = true === $live->check_permissions([]);
                $this->assertFalse(
                    $direct_allowed,
                    "[$mode] {$ability->name}: expected direct denial under the meta-only identity."
                );

                $dispatched = $this->dispatch($ability->name);
                $this->assertInstanceOf(
                    \WP_Error::class,
                    $dispatched,
                    "[$mode] {$ability->name}: dispatcher must deny whenever the direct path denies."
                );
            }
        }
    }
}
