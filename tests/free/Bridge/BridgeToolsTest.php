<?php

namespace WPMCP\Tests\Free\Bridge;

use WPMCP\Governance\Governance_Audit_Log;
use WPMCP\Plugin;
use WPMCP\Tools\Bridge\Execute_Site_Ability;
use WPMCP\Tools\Bridge\Get_Site_Ability;
use WPMCP\Tools\Bridge\List_Site_Abilities;

/**
 * The third-party ability bridge (issue #194): list-site-abilities,
 * get-site-ability and execute-site-ability discover and invoke abilities
 * OTHER plugins registered through the Abilities API, and must never widen
 * access while doing so. Fixture abilities stand in for a third-party
 * plugin: one exposed and permitted, one exposed but denied by its own
 * permission callback, one the owner never exposed (show_in_rest false,
 * core's default), and one exposed without an input schema.
 */
class BridgeToolsTest extends \WP_UnitTestCase
{
    private const ECHO      = 'wpmcptest/echo';
    private const DENIED    = 'wpmcptest/denied';
    private const INTERNAL  = 'wpmcptest/internal';
    private const NO_SCHEMA = 'wpmcptest/no-schema';

    private const FIXTURES = [self::ECHO, self::DENIED, self::INTERNAL, self::NO_SCHEMA];

    private const SHELLS = [
        'wpmcp/list-site-abilities',
        'wpmcp/get-site-ability',
        'wpmcp/execute-site-ability',
    ];

    /** @var array<string, mixed>|null The input the echo fixture last received. */
    private static $echo_received = null;

    protected function setUp(): void
    {
        parent::setUp();

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        delete_option(Governance_Audit_Log::OPTION);
        self::$echo_received = null;

        // Make sure the registry exists and has fired its own init (so wpmcp's
        // abilities are registered) BEFORE we hook the fixtures, otherwise the
        // lazy registry construction would re-fire the hook inside our
        // do_action() and register everything twice.
        wp_get_abilities();

        remove_all_actions('wp_abilities_api_init');
        add_action('wp_abilities_api_init', [$this, 'register_fixtures']);
        do_action('wp_abilities_api_init');
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
        delete_option(Governance_Audit_Log::OPTION);

        parent::tearDown();
    }

    public function register_fixtures(): void
    {
        wp_register_ability(self::ECHO, [
            'label'               => 'Echo',
            'description'         => 'A third-party ability that returns its input. Exposed by its owner.',
            'category'            => 'wpmcp',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => ['message' => ['type' => 'string']],
            ],
            'execute_callback'    => static function ($input = null) {
                self::$echo_received = $input;
                return ['echo' => $input];
            },
            'permission_callback' => '__return_true',
            'meta'                => ['show_in_rest' => true],
        ]);

        wp_register_ability(self::DENIED, [
            'label'               => 'Denied',
            'description'         => 'Exposed, but its own permission callback refuses everyone.',
            'category'            => 'wpmcp',
            'input_schema'        => ['type' => 'object', 'properties' => []],
            'execute_callback'    => static fn ($input = null) => ['leaked' => true],
            'permission_callback' => '__return_false',
            'meta'                => ['show_in_rest' => true],
        ]);

        // No show_in_rest: core's default (false). The owner never opened
        // this ability to REST/MCP clients, so the bridge must not either,
        // however permissive its permission callback is.
        wp_register_ability(self::INTERNAL, [
            'label'               => 'Internal',
            'description'         => 'An internal-only ability with a lax permission callback.',
            'category'            => 'wpmcp',
            'input_schema'        => ['type' => 'object', 'properties' => []],
            'execute_callback'    => static fn ($input = null) => ['leaked' => true],
            'permission_callback' => '__return_true',
        ]);

        wp_register_ability(self::NO_SCHEMA, [
            'label'               => 'No schema',
            'description'         => 'Exposed, takes no input at all.',
            'category'            => 'wpmcp',
            'execute_callback'    => static fn ($input = null) => ['ran' => true, 'input' => $input],
            'permission_callback' => '__return_true',
            'meta'                => ['show_in_rest' => true],
        ]);
    }

    private function open_the_gate(): void
    {
        add_filter('wpmcp_enable_ability_bridge', '__return_true');
    }

    private function list(array $args = [])
    {
        return (new List_Site_Abilities())->handle($args);
    }

    private function get(string $name)
    {
        return (new Get_Site_Ability())->handle(['name' => $name]);
    }

    private function execute(array $args)
    {
        return (new Execute_Site_Ability())->handle($args);
    }

    /** @return array<int, string> */
    private function listed_names(array $listing): array
    {
        return array_column($listing['abilities'], 'name');
    }

    private function assertBridgeError($result, string $code, string $context = ''): void
    {
        $this->assertInstanceOf(\WP_Error::class, $result, $context);
        $this->assertSame($code, $result->get_error_code(), $context);
    }

    // ---------------------------------------------------------------
    // Registration shape
    // ---------------------------------------------------------------

    public function test_the_three_shells_are_free_tier_in_the_bridge_domain(): void
    {
        $registrar = Plugin::instance()->registrar();
        foreach (self::SHELLS as $name) {
            $ability = $registrar->get($name);
            $this->assertNotNull($ability, "{$name} missing from Registrar.");
            $this->assertSame('free', $ability->tier, $name);
            $this->assertSame('bridge', $ability->domain, $name);
            $this->assertArrayHasKey($name, wp_get_abilities(), "{$name} missing from the live Abilities API registry.");
        }
    }

    public function test_execute_site_ability_is_honestly_annotated_as_a_potentially_destructive_dispatcher(): void
    {
        $execute = Plugin::instance()->registrar()->get('wpmcp/execute-site-ability');

        $this->assertFalse($execute->read_only_hint, 'execute-site-ability proxies writes we did not author.');
        $this->assertTrue($execute->destructive_hint, 'execute-site-ability can proxy deletes, so it must warn destructive.');
        $this->assertFalse($execute->idempotent_hint);
    }

    public function test_the_discovery_shells_are_read_only(): void
    {
        foreach (['wpmcp/list-site-abilities', 'wpmcp/get-site-ability'] as $name) {
            $ability = Plugin::instance()->registrar()->get($name);
            $this->assertTrue($ability->read_only_hint, $name);
            $this->assertFalse($ability->destructive_hint, $name);
        }
    }

    // ---------------------------------------------------------------
    // Gate closed by default
    // ---------------------------------------------------------------

    public function test_every_bridge_tool_refuses_while_the_gate_is_closed(): void
    {
        $this->assertBridgeError($this->list(), 'wpmcp_bridge_disabled', 'list');
        $this->assertBridgeError($this->get(self::ECHO), 'wpmcp_bridge_disabled', 'get');
        $this->assertBridgeError($this->execute(['name' => self::ECHO]), 'wpmcp_bridge_disabled', 'execute');

        // The gate is checked before anything else: even the permitted,
        // exposed fixture never ran.
        $this->assertNull(self::$echo_received);
    }

    // ---------------------------------------------------------------
    // Refusals: own names, unknown names, invalid input
    // ---------------------------------------------------------------

    public function test_own_abilities_including_the_meta_and_bridge_tools_are_refused(): void
    {
        $this->open_the_gate();

        $own = array_merge(['wpmcp/get-post', 'wpmcp/call-tool', 'wpmcp/list-tools', 'wpmcp/get-tool-schema'], self::SHELLS);
        foreach ($own as $name) {
            $this->assertBridgeError($this->get($name), 'wpmcp_bridge_not_foreign', "get {$name}");
            $this->assertBridgeError($this->execute(['name' => $name]), 'wpmcp_bridge_not_foreign', "execute {$name}");
        }

        foreach ($this->listed_names($this->list()) as $name) {
            $this->assertStringStartsNotWith('wpmcp/', $name, 'The listing must never contain a wpmcp ability.');
        }
    }

    public function test_unknown_names_are_refused(): void
    {
        $this->open_the_gate();

        $this->assertBridgeError($this->get('nobody/nothing'), 'wpmcp_bridge_unknown');
        $this->assertBridgeError($this->execute(['name' => 'nobody/nothing']), 'wpmcp_bridge_unknown');
    }

    public function test_the_name_argument_is_validated(): void
    {
        $this->open_the_gate();

        $this->assertBridgeError($this->get(''), 'wpmcp_bridge_invalid');
        $this->assertBridgeError($this->execute([]), 'wpmcp_bridge_invalid');
        $this->assertBridgeError($this->execute(['name' => '']), 'wpmcp_bridge_invalid');
        $this->assertBridgeError($this->execute(['name' => 42]), 'wpmcp_bridge_invalid');
    }

    // ---------------------------------------------------------------
    // Registration is not exposure: show_in_rest is a hard precondition
    // ---------------------------------------------------------------

    public function test_an_ability_the_owner_did_not_expose_is_invisible_to_every_bridge_tool(): void
    {
        $this->open_the_gate();

        // Directly callable in-process for this user...
        $this->assertSame(['leaked' => true], wp_get_ability(self::INTERNAL)->execute([]));

        // ...but absent from the bridge listing,
        $this->assertNotContains(self::INTERNAL, $this->listed_names($this->list()));

        // unreadable,
        $get = $this->get(self::INTERNAL);
        $this->assertBridgeError($get, 'wpmcp_bridge_unknown', 'get');

        // and unexecutable, with the same code AND message an unregistered
        // name gets so the bridge cannot be used to enumerate hidden names.
        $execute = $this->execute(['name' => self::INTERNAL]);
        $this->assertBridgeError($execute, 'wpmcp_bridge_unknown', 'execute');

        $unknown = $this->execute(['name' => 'nobody/nothing']);
        $this->assertSame(
            str_replace('nobody/nothing', self::INTERNAL, $unknown->get_error_message()),
            $execute->get_error_message()
        );
        $this->assertSame($get->get_error_message(), $execute->get_error_message());
    }

    // ---------------------------------------------------------------
    // Denied stays denied
    // ---------------------------------------------------------------

    public function test_a_target_that_denies_the_caller_stays_denied_and_the_denial_is_audited(): void
    {
        $this->open_the_gate();

        $result = $this->execute(['name' => self::DENIED, 'arguments' => []]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('ability_invalid_permissions', $result->get_error_code(), 'The target\'s own refusal must reach the caller unchanged.');

        $entries = Governance_Audit_Log::list();
        $this->assertCount(1, $entries);
        $this->assertSame(self::DENIED, $entries[0]['ability'], 'The denial is attributed to the foreign ability, not the shell.');
        $this->assertFalse($entries[0]['allowed']);
        $this->assertSame('bridge:wpmcptest:ability_invalid_permissions', $entries[0]['reason']);
    }

    // ---------------------------------------------------------------
    // Success path: attribution, reversible:false, audit
    // ---------------------------------------------------------------

    public function test_a_permitted_exposed_ability_runs_through_its_own_execute_path(): void
    {
        $this->open_the_gate();

        $result = $this->execute(['name' => self::ECHO, 'arguments' => ['message' => 'hi']]);

        $this->assertIsArray($result);
        $this->assertSame(self::ECHO, $result['ability']);
        $this->assertSame('wpmcptest', $result['plugin']);
        $this->assertFalse($result['reversible'], 'Bridged results are outside the rollback guarantee.');
        $this->assertSame(['echo' => ['message' => 'hi']], $result['result']);
        $this->assertSame(['message' => 'hi'], self::$echo_received);

        $entries = Governance_Audit_Log::list();
        $this->assertCount(1, $entries);
        $this->assertSame(self::ECHO, $entries[0]['ability']);
        $this->assertTrue($entries[0]['allowed']);
        $this->assertSame('bridge:wpmcptest', $entries[0]['reason']);
    }

    public function test_invalid_input_is_refused_by_the_target_schema_not_by_the_bridge(): void
    {
        $this->open_the_gate();

        $result = $this->execute(['name' => self::ECHO, 'arguments' => ['message' => 42]]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('ability_invalid_input', $result->get_error_code());
        $this->assertNull(self::$echo_received, 'Validation runs before the handler.');
    }

    // ---------------------------------------------------------------
    // Schema-less targets
    // ---------------------------------------------------------------

    public function test_a_schema_less_ability_is_executable_with_omitted_or_empty_arguments(): void
    {
        $this->open_the_gate();

        $omitted = $this->execute(['name' => self::NO_SCHEMA]);
        $this->assertIsArray($omitted, 'Omitted arguments must become the null input core requires.');
        $this->assertSame(['ran' => true, 'input' => null], $omitted['result']);

        $empty = $this->execute(['name' => self::NO_SCHEMA, 'arguments' => []]);
        $this->assertIsArray($empty, 'An empty arguments object must become the null input core requires.');
        $this->assertSame(['ran' => true, 'input' => null], $empty['result']);

        // Real arguments to an ability that declares no schema are core's
        // refusal to make, and it reaches the caller unchanged.
        $with_input = $this->execute(['name' => self::NO_SCHEMA, 'arguments' => ['x' => 1]]);
        $this->assertBridgeError($with_input, 'ability_missing_input_schema');
    }

    // ---------------------------------------------------------------
    // Discovery payloads
    // ---------------------------------------------------------------

    public function test_listing_describes_exposed_foreign_abilities_with_owner_and_reversible_false(): void
    {
        $this->open_the_gate();

        $listing = $this->list();
        $this->assertIsArray($listing);
        $this->assertSame(count($listing['abilities']), $listing['total']);
        $this->assertStringContainsString('reversible:false', $listing['note']);

        $by_name = array_column($listing['abilities'], null, 'name');
        foreach ([self::ECHO, self::DENIED, self::NO_SCHEMA] as $name) {
            $this->assertArrayHasKey($name, $by_name, "{$name} is exposed and must be listed.");
            $this->assertSame('wpmcptest', $by_name[ $name ]['plugin']);
            $this->assertFalse($by_name[ $name ]['reversible']);
        }
        $this->assertTrue($by_name[ self::ECHO ]['has_input_schema']);
        $this->assertFalse($by_name[ self::NO_SCHEMA ]['has_input_schema']);

        $names = array_keys($by_name);
        $sorted = $names;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $names, 'Listing is sorted by name.');
    }

    public function test_listing_can_be_narrowed_to_one_owner(): void
    {
        $this->open_the_gate();

        $names = $this->listed_names($this->list(['plugin' => 'wpmcptest']));
        $this->assertSame([self::DENIED, self::ECHO, self::NO_SCHEMA], $names);

        $this->assertSame(0, $this->list(['plugin' => 'nobody'])['total']);
    }

    public function test_get_returns_the_full_contract_with_owner_and_reversible_false(): void
    {
        $this->open_the_gate();

        $contract = $this->get(self::ECHO);

        $this->assertIsArray($contract);
        $this->assertSame(self::ECHO, $contract['name']);
        $this->assertSame('wpmcptest', $contract['plugin']);
        $this->assertFalse($contract['reversible']);
        $this->assertSame(wp_get_ability(self::ECHO)->get_description(), $contract['description']);
        $this->assertSame(wp_get_ability(self::ECHO)->get_input_schema(), $contract['input_schema']);
        $this->assertTrue($contract['meta']['show_in_rest']);
    }
}
