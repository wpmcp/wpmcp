<?php

namespace WPMCP\Tests\Free\Bridge;

use WPMCP\Governance\Governance;
use WPMCP\Governance\Governance_Audit_Log;
use WPMCP\Identity\Identity_Context;
use WPMCP\Identity\Identity_Store;
use WPMCP\Memory\Memory_Store;
use WPMCP\Plugin;
use WPMCP\Tools\Bridge\Bridge_Guard;
use WPMCP\Tools\Bridge\Execute_Site_Ability;
use WPMCP\Tools\Bridge\Get_Site_Ability;
use WPMCP\Tools\Bridge\List_Site_Abilities;
use WPMCP\Tools\Governance\Update_Governance_Settings;

/**
 * Governance of individual bridged abilities (issue #194).
 *
 * Opening the site-level bridge gate is not the end of the story: every
 * bridged ability is governed like one of ours, by name, by the "bridge"
 * domain and by an operation derived from its own annotations, and by the
 * active identity's scope and published project-memory block rules. On top
 * of that, a site can narrow the bridge to named abilities or whole
 * namespaces (the allowlist). Every one of these layers can only take
 * access away: none of them can reach the target ability without going
 * through its own permission callback, which this file proves both at
 * runtime and by reading the bridge source.
 */
class BridgeGovernanceTest extends \WP_UnitTestCase
{
    private const ECHO      = 'wpmcptest/echo';
    private const READER    = 'wpmcptest/reader';
    private const DESTROYER = 'wpmcptest/destroyer';
    private const DENIED    = 'wpmcptest/denied';
    private const COUNTED   = 'wpmcptest/counted';
    private const OTHER     = 'wpmcpother/ping';

    private const FIXTURES = [self::ECHO, self::READER, self::DESTROYER, self::DENIED, self::COUNTED, self::OTHER];

    /** @var int How many times the COUNTED fixture's permission callback ran. */
    private static $permission_checks = 0;

    /** @var int How many times any fixture handler ran. */
    private static $handler_runs = 0;

    protected function setUp(): void
    {
        parent::setUp();

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        delete_option(Governance_Audit_Log::OPTION);
        Governance::reset_for_tests();
        Identity_Context::set_current_for_tests(null);
        self::$permission_checks = 0;
        self::$handler_runs      = 0;

        wp_get_abilities();

        remove_all_actions('wp_abilities_api_init');
        add_action('wp_abilities_api_init', [$this, 'register_fixtures']);
        do_action('wp_abilities_api_init');

        add_filter('wpmcp_enable_ability_bridge', '__return_true');
    }

    protected function tearDown(): void
    {
        foreach (self::FIXTURES as $name) {
            if (wp_has_ability($name)) {
                wp_unregister_ability($name);
            }
        }
        remove_all_actions('wp_abilities_api_init');
        remove_all_filters('wpmcp_enable_ability_bridge');
        remove_all_filters('wpmcp_ability_bridge_allowlist');
        remove_all_filters('wpmcp_ability_enabled');
        remove_all_filters('wpmcp_domain_enabled');
        remove_all_filters('wpmcp_operation_enabled');
        delete_option(Governance_Audit_Log::OPTION);
        delete_option(Identity_Store::OPTION);
        Governance::reset_for_tests();
        Identity_Context::set_current_for_tests(null);

        parent::tearDown();
    }

    public function register_fixtures(): void
    {
        $open = static function (string $label, array $annotations = []) {
            return [
                'label'               => $label,
                'description'         => $label . ' fixture standing in for a third-party ability.',
                'category'            => 'wpmcp',
                'input_schema'        => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]],
                'execute_callback'    => static function ($input = null) use ($label) {
                    ++self::$handler_runs;
                    return ['ran' => $label];
                },
                'permission_callback' => '__return_true',
                'meta'                => ['show_in_rest' => true, 'annotations' => $annotations],
            ];
        };

        wp_register_ability(self::ECHO, $open('Echo'));
        wp_register_ability(self::READER, $open('Reader', ['readonly' => true]));
        wp_register_ability(self::DESTROYER, $open('Destroyer', ['destructive' => true]));
        wp_register_ability(self::OTHER, $open('Other'));

        $denied                        = $open('Denied');
        $denied['permission_callback'] = '__return_false';
        wp_register_ability(self::DENIED, $denied);

        $counted                        = $open('Counted');
        $counted['permission_callback'] = static function () {
            ++self::$permission_checks;
            return true;
        };
        wp_register_ability(self::COUNTED, $counted);
    }

    private function list(array $args = [])
    {
        return (new List_Site_Abilities())->handle($args);
    }

    private function get(string $name)
    {
        return (new Get_Site_Ability())->handle(['name' => $name]);
    }

    private function execute(string $name, array $arguments = ['id' => 7])
    {
        return (new Execute_Site_Ability())->handle(['name' => $name, 'arguments' => $arguments]);
    }

    /** @return array<int, string> */
    private function listed(): array
    {
        return array_column($this->list()['abilities'], 'name');
    }

    private function assertGovernanceDenied($result, string $context = ''): void
    {
        $this->assertInstanceOf(\WP_Error::class, $result, $context);
        $this->assertSame('wpmcp_bridge_governance_denied', $result->get_error_code(), $context);
    }

    /** @return array<string, mixed> The newest audit entry. */
    private function last_audit(): array
    {
        $entries = Governance_Audit_Log::list(1);
        $this->assertNotEmpty($entries, 'Expected an audit entry.');
        return $entries[0];
    }

    // ---------------------------------------------------------------
    // The synthetic governance subject
    // ---------------------------------------------------------------

    public function test_a_bridged_ability_is_governed_by_its_own_name_the_bridge_domain_and_its_annotated_operation(): void
    {
        $echo      = Bridge_Guard::governed(wp_get_ability(self::ECHO));
        $reader    = Bridge_Guard::governed(wp_get_ability(self::READER));
        $destroyer = Bridge_Guard::governed(wp_get_ability(self::DESTROYER));

        $this->assertSame(self::ECHO, $echo->name);
        $this->assertSame('bridge', $echo->domain);

        $this->assertSame('update', $echo->operation, 'Unannotated foreign code is treated as a write, never as a read.');
        $this->assertFalse($echo->read_only_hint);
        $this->assertSame('read', $reader->operation);
        $this->assertTrue($reader->read_only_hint);
        $this->assertSame('delete', $destroyer->operation);
        $this->assertTrue($destroyer->destructive_hint);
    }

    // ---------------------------------------------------------------
    // Per-ability governance: toggle, filter, the MCP governance tool
    // ---------------------------------------------------------------

    public function test_an_ability_toggle_disables_one_bridged_ability_everywhere_and_the_denial_is_audited(): void
    {
        Governance::set_ability_toggle(self::ECHO, false);

        $this->assertNotContains(self::ECHO, $this->listed(), 'A governance-disabled bridged ability is not listed.');
        $this->assertContains(self::OTHER, $this->listed(), 'Only the named ability is affected.');
        $this->assertGovernanceDenied($this->get(self::ECHO), 'get');

        $result = $this->execute(self::ECHO);
        $this->assertGovernanceDenied($result, 'execute');
        $this->assertSame(0, self::$handler_runs, 'A governance denial happens before the target runs.');

        $entry = $this->last_audit();
        $this->assertSame(self::ECHO, $entry['ability'], 'The denial is attributed to the foreign ability.');
        $this->assertFalse($entry['allowed']);
        $this->assertSame('bridge:wpmcptest:governance:ability_toggle', $entry['reason']);

        $this->assertIsArray($this->execute(self::OTHER), 'Another bridged ability still runs.');
    }

    public function test_the_update_governance_settings_tool_accepts_a_bridged_ability_name(): void
    {
        (new Update_Governance_Settings())->handle(['ability' => [self::ECHO => false]]);

        $this->assertGovernanceDenied($this->execute(self::ECHO));
    }

    public function test_the_ability_enabled_filter_receives_the_foreign_name(): void
    {
        add_filter('wpmcp_ability_enabled', static fn ($enabled, $name) => self::ECHO === $name ? false : $enabled, 10, 2);

        $this->assertGovernanceDenied($this->execute(self::ECHO));
        $this->assertSame('bridge:wpmcptest:governance:ability_filter', $this->last_audit()['reason']);
        $this->assertIsArray($this->execute(self::OTHER));
    }

    public function test_the_bridge_domain_toggle_disables_every_bridged_ability(): void
    {
        Governance::set_domain_toggle('bridge', false);

        foreach ([self::ECHO, self::READER, self::OTHER] as $name) {
            $this->assertGovernanceDenied($this->execute($name), $name);
        }
        $this->assertSame(0, self::$handler_runs);
    }

    public function test_an_operation_filter_narrows_by_the_target_annotations(): void
    {
        // A typical environment policy: no deletes on this environment.
        add_filter('wpmcp_operation_enabled', static fn ($enabled, $operation) => 'delete' === $operation ? false : $enabled, 10, 2);

        $this->assertGovernanceDenied($this->execute(self::DESTROYER), 'destructive target');
        $this->assertSame('bridge:wpmcptest:governance:operation_filter', $this->last_audit()['reason']);
        $this->assertIsArray($this->execute(self::READER), 'read-only target');
        $this->assertIsArray($this->execute(self::ECHO), 'unannotated target');
    }

    // ---------------------------------------------------------------
    // Identity scope and project-memory block rules
    // ---------------------------------------------------------------

    public function test_a_deny_mode_identity_can_block_one_bridged_ability(): void
    {
        Identity_Store::create('agent', ['abilities' => [self::ECHO], 'mode' => 'deny']);
        Identity_Context::set_current_for_tests('agent');

        $this->assertGovernanceDenied($this->execute(self::ECHO));
        $entry = $this->last_audit();
        $this->assertSame('agent', $entry['identity']);
        $this->assertSame('bridge:wpmcptest:identity_scope', $entry['reason']);

        $this->assertIsArray($this->execute(self::OTHER));
    }

    public function test_an_allow_mode_identity_reaches_only_the_bridged_abilities_it_names(): void
    {
        Identity_Store::create('agent', ['abilities' => ['wpmcp/execute-site-ability', self::OTHER]]);
        Identity_Context::set_current_for_tests('agent');

        $this->assertGovernanceDenied($this->execute(self::ECHO), 'Scoping the shell alone does not grant every bridged ability.');
        $this->assertIsArray($this->execute(self::OTHER));
        $this->assertSame([self::OTHER], $this->listed());
    }

    public function test_an_unknown_identity_is_denied_every_bridged_ability(): void
    {
        Identity_Context::set_current_for_tests('nobody-registered-this');

        $this->assertGovernanceDenied($this->execute(self::ECHO));
        $this->assertSame([], $this->listed());
    }

    public function test_a_published_memory_block_rule_targeting_a_bridged_tool_denies_it(): void
    {
        Memory_Store::ensure_post_type();
        Memory_Store::flush_rules_cache();
        $id = Memory_Store::propose([
            'text'     => 'Never run the echo ability.',
            'kind'     => 'guardrail',
            'severity' => 'block',
            'targets'  => ['tool:' . self::ECHO],
        ]);
        Memory_Store::approve($id);

        try {
            $this->assertGovernanceDenied($this->execute(self::ECHO));
            $this->assertSame('bridge:wpmcptest:memory-block:' . $id, $this->last_audit()['reason']);
            $this->assertIsArray($this->execute(self::OTHER));
        } finally {
            Memory_Store::flush_rules_cache();
        }
    }

    // ---------------------------------------------------------------
    // Opt-in per ability or per namespace: the allowlist
    // ---------------------------------------------------------------

    public function test_without_an_allowlist_every_exposed_ability_is_bridgeable(): void
    {
        $this->assertNull(Bridge_Guard::allowlist());
        $this->assertContains(self::ECHO, $this->listed());
        $this->assertContains(self::OTHER, $this->listed());
    }

    public function test_an_allowlist_of_exact_names_narrows_the_bridge_to_those_names(): void
    {
        add_filter('wpmcp_ability_bridge_allowlist', static fn () => [self::ECHO]);

        $this->assertSame([self::ECHO], $this->listed());
        $this->assertIsArray($this->execute(self::ECHO));

        // Not allowlisted answers exactly like an unregistered name.
        $this->assertSame('wpmcp_bridge_unknown', $this->execute(self::OTHER)->get_error_code());
        $this->assertSame('wpmcp_bridge_unknown', $this->get(self::READER)->get_error_code());
    }

    public function test_an_allowlist_can_name_a_whole_namespace(): void
    {
        add_filter('wpmcp_ability_bridge_allowlist', static fn () => ['wpmcpother/*']);
        $this->assertSame([self::OTHER], $this->listed());

        remove_all_filters('wpmcp_ability_bridge_allowlist');
        add_filter('wpmcp_ability_bridge_allowlist', static fn () => ['wpmcpother']);
        $this->assertSame([self::OTHER], $this->listed(), 'A bare namespace means the whole namespace.');
    }

    public function test_a_namespace_entry_does_not_match_a_longer_namespace_sharing_its_prefix(): void
    {
        add_filter('wpmcp_ability_bridge_allowlist', static fn () => ['wpmcp*', 'wpmcpte/*', 'wpmcpte']);

        $this->assertSame([], $this->listed());
    }

    public function test_an_empty_or_malformed_allowlist_fails_closed(): void
    {
        add_filter('wpmcp_ability_bridge_allowlist', '__return_empty_array');
        $this->assertSame([], $this->listed());

        remove_all_filters('wpmcp_ability_bridge_allowlist');
        add_filter('wpmcp_ability_bridge_allowlist', static fn () => 'not-a-list');
        $this->assertSame([], $this->listed());
    }

    public function test_the_allowlist_never_opens_the_site_gate(): void
    {
        remove_all_filters('wpmcp_enable_ability_bridge');
        add_filter('wpmcp_ability_bridge_allowlist', static fn () => [self::ECHO]);

        $this->assertSame('wpmcp_bridge_disabled', $this->execute(self::ECHO)->get_error_code());
    }

    public function test_the_allowlist_never_admits_a_wpmcp_ability_or_an_unexposed_one(): void
    {
        add_filter('wpmcp_ability_bridge_allowlist', static fn () => ['wpmcp/*', 'wpmcp/get-post']);

        $this->assertSame('wpmcp_bridge_not_foreign', $this->execute('wpmcp/get-post')->get_error_code());
        $this->assertSame([], $this->listed());
    }

    // ---------------------------------------------------------------
    // Nothing skips the target's own permission callback
    // ---------------------------------------------------------------

    public function test_the_target_permission_callback_runs_on_every_bridged_execution(): void
    {
        // Every wpmcp seam that could conceivably be read as "allow" is set
        // to allow as loudly as possible. None of them is a bypass.
        Governance::set_ability_toggle(self::COUNTED, true);
        Governance::set_domain_toggle('bridge', true);
        add_filter('wpmcp_ability_enabled', '__return_true', 99);
        add_filter('wpmcp_domain_enabled', '__return_true', 99);
        add_filter('wpmcp_operation_enabled', '__return_true', 99);
        add_filter('wpmcp_ability_bridge_allowlist', static fn () => [self::COUNTED, self::DENIED]);

        $this->assertIsArray($this->execute(self::COUNTED));
        $this->assertIsArray($this->execute(self::COUNTED));
        $this->assertSame(2, self::$permission_checks, 'One permission check per bridged execution, never zero.');
        $this->assertSame(2, self::$handler_runs);
    }

    public function test_a_target_denial_survives_every_allow_seam_wpmcp_has(): void
    {
        Governance::set_ability_toggle(self::DENIED, true);
        add_filter('wpmcp_ability_enabled', '__return_true', 99);
        add_filter('wpmcp_ability_bridge_allowlist', static fn () => [self::DENIED]);
        Identity_Store::create('agent', ['abilities' => ['wpmcp/execute-site-ability', self::DENIED]]);
        Identity_Context::set_current_for_tests('agent');

        $result = $this->execute(self::DENIED);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('ability_invalid_permissions', $result->get_error_code());
        $this->assertSame(0, self::$handler_runs);
        $this->assertSame('bridge:wpmcptest:ability_invalid_permissions', $this->last_audit()['reason']);
    }

    /**
     * Structural proof, not just behavioural: the bridge source contains
     * exactly one invocation of a foreign ability, WP_Ability::execute()
     * (which runs the target's permission_callback before its handler),
     * and no way to reach the raw handler or to register a filter that
     * could stand in for the permission check.
     */
    public function test_the_bridge_source_has_no_path_around_the_target_permission_callback(): void
    {
        $dir     = dirname(__DIR__, 3) . '/src/Tools/Bridge';
        $sources = [];
        foreach (glob($dir . '/*.php') as $file) {
            $sources[ basename($file) ] = $this->code_only((string) file_get_contents($file));
        }
        $this->assertArrayHasKey('Execute_Site_Ability.php', $sources);

        $forbidden = [
            'get_execute_callback' => 'reads the raw handler',
            'do_execute'           => 'calls the handler without the permission check',
            'execute_callback'     => 'touches the raw handler',
            'permission_callback'  => 'touches the permission callback',
            'check_permissions'    => 'decides permission itself',
            'Reflection'           => 'reaches private internals',
            'Closure::bind'        => 'reaches private internals',
            'call_user_func'       => 'invokes something indirectly',
            'bypass'               => 'names a bypass',
        ];
        foreach ($sources as $file => $code) {
            foreach ($forbidden as $needle => $why) {
                $this->assertStringNotContainsStringIgnoringCase($needle, $code, "{$file} {$why}.");
            }
        }

        $this->assertSame(
            1,
            preg_match_all('/->\s*execute\s*\(/', implode("\n", $sources)),
            'Exactly one foreign invocation, and it is WP_Ability::execute().'
        );
        $this->assertSame(1, preg_match_all('/\$ability\s*->\s*execute\s*\(/', $sources['Execute_Site_Ability.php']));

        // The only filters the bridge reads are the two opt-in narrowings.
        preg_match_all('/apply_filters\s*\(\s*[\'"]([^\'"]+)[\'"]/', implode("\n", $sources), $m);
        $filters = array_values(array_unique($m[1]));
        sort($filters);
        $this->assertSame(['wpmcp_ability_bridge_allowlist', 'wpmcp_enable_ability_bridge'], $filters);
    }

    /** PHP source with comments and docblocks removed. */
    private function code_only(string $php): string
    {
        $out = '';
        foreach (token_get_all($php) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }
        return $out;
    }

    // ---------------------------------------------------------------
    // The ability drift guard (issue #86) never sees bridged abilities
    // ---------------------------------------------------------------

    public function test_bridged_abilities_never_enter_the_wpmcp_registrar(): void
    {
        $this->list();
        $this->get(self::ECHO);
        $this->execute(self::ECHO);
        Bridge_Guard::governed(wp_get_ability(self::ECHO));

        $registrar = Plugin::instance()->registrar();
        $declared  = array_map(static fn ($ability) => $ability->name, $registrar->declared());
        foreach (self::FIXTURES as $name) {
            $this->assertNull($registrar->get($name), "{$name} leaked into Registrar::all().");
            $this->assertNotContains($name, $declared, "{$name} leaked into Registrar::declared().");
        }
        foreach ($declared as $name) {
            $this->assertStringStartsWith('wpmcp/', $name, 'Only wpmcp abilities are declared, so the manifest stays ours.');
        }
    }

    public function test_the_governance_subject_is_never_a_literal_registration_the_static_guard_would_pin(): void
    {
        $guard = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Tools/Bridge/Bridge_Guard.php');

        // bin/check-ability-drift.php pins every new Ability('literal', 'tier')
        // against the manifest. The synthetic subject must take its name from
        // the live foreign ability, never from a literal.
        $this->assertSame(0, preg_match('/new\s+Ability\s*\(\s*[\'"]/', $guard));
        $this->assertSame(1, preg_match('/new\s+Ability\s*\(/', $guard));
    }
}
