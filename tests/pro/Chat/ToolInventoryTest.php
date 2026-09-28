<?php

namespace WPMCP\Tests\Pro\Chat;

use WPMCP\Governance\Governance;
use WPMCP\Governance\Governance_Audit_Log;
use WPMCP\Identity\Identity_Store;
use WPMCP\Plugin;
use WPMCP\Pro\Chat\Chat_Identity;
use WPMCP\Pro\Chat\System_Prompt;
use WPMCP\Pro\Chat\Tool_Inventory;
use WPMCP\Pro\Gate;

/**
 * Acceptance criterion 4 of issue #73: the advertised tool inventory provably
 * matches the active governed set, checked against the server-authored
 * system prompt.
 *
 * "Governed set" is computed here independently of Tool_Inventory, straight
 * from the Registrar's audited permission decision (is_permitted) under the
 * chat identity, so the test compares two separate derivations rather than
 * one function against itself.
 */
class ToolInventoryTest extends \WP_UnitTestCase
{
    private int $admin_id;

    public static function wpSetUpBeforeClass(): void
    {
        if (0 === did_action('wp_abilities_api_init')) {
            do_action('wp_abilities_api_init');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
        Governance::reset_for_tests();
        Identity_Store::delete(Chat_Identity::NAME);
        $this->admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($this->admin_id);
    }

    protected function tearDown(): void
    {
        Governance::reset_for_tests();
        Identity_Store::delete(Chat_Identity::NAME);
        Gate::set_pro_for_tests(null);
        wp_set_current_user(0);
        parent::tearDown();
    }

    /** @return string[] ability names the governed path would allow right now, as tool names */
    private function governed_set(): array
    {
        $registrar = Plugin::instance()->registrar();
        $names     = Chat_Identity::run(function () use ($registrar): array {
            $out = [];
            foreach ($registrar->all() as $ability) {
                if ($registrar->is_permitted($ability) && null !== wp_get_ability($ability->name)) {
                    $out[] = Tool_Inventory::tool_name($ability->name);
                }
            }
            return $out;
        });
        sort($names, SORT_STRING);
        return $names;
    }

    /** @return string[] tool names listed in the system prompt's inventory block */
    private function prompt_set(string $prompt): array
    {
        $open  = strpos($prompt, System_Prompt::INVENTORY_OPEN);
        $close = strpos($prompt, System_Prompt::INVENTORY_CLOSE);
        $this->assertNotFalse($open);
        $this->assertNotFalse($close);
        $block = substr($prompt, $open + strlen(System_Prompt::INVENTORY_OPEN), $close - $open - strlen(System_Prompt::INVENTORY_OPEN));

        $names = [];
        foreach (explode("\n", trim($block)) as $line) {
            if (! preg_match('/^- [^:]+: (.*)$/', $line, $m)) {
                continue;
            }
            foreach (explode(', ', $m[1]) as $name) {
                $names[] = trim($name);
            }
        }
        sort($names, SORT_STRING);
        return $names;
    }

    /** @return string[] */
    private function advertised_and_prompt(): array
    {
        $inventory  = new Tool_Inventory(Plugin::instance()->registrar());
        $advertised = Chat_Identity::run(fn () => $inventory->advertised());
        $prompt     = System_Prompt::build($this->admin_id, Tool_Inventory::by_domain($advertised));

        $names = array_keys($advertised);
        sort($names, SORT_STRING);
        $this->assertSame($names, $this->prompt_set($prompt), 'The prompt lists a different set than the inventory.');
        return $names;
    }

    public function test_the_prompt_inventory_equals_the_active_governed_set(): void
    {
        $advertised = $this->advertised_and_prompt();

        $this->assertNotEmpty($advertised);
        $this->assertSame($this->governed_set(), $advertised);
    }

    public function test_every_governed_ability_is_advertised_under_a_valid_provider_tool_name(): void
    {
        $advertised = $this->advertised_and_prompt();
        foreach ($advertised as $tool) {
            $this->assertMatchesRegularExpression('/^[a-zA-Z0-9_-]{1,64}$/', $tool);
        }
        $this->assertSame(count($this->governed_set()), count($advertised), 'An ability was dropped from the inventory.');
    }

    public function test_a_governance_toggle_changes_both_sets_together(): void
    {
        $this->assertContains('wpmcp__update-post', $this->advertised_and_prompt());

        Governance::set_ability_toggle('wpmcp/update-post', false);
        $after = $this->advertised_and_prompt();

        $this->assertNotContains('wpmcp__update-post', $after);
        $this->assertSame($this->governed_set(), $after);
    }

    public function test_a_domain_toggle_changes_both_sets_together(): void
    {
        Governance::set_domain_toggle('content', false);
        $after = $this->advertised_and_prompt();

        $this->assertNotContains('wpmcp__get-post', $after);
        $this->assertSame($this->governed_set(), $after);
    }

    public function test_the_chat_identity_scope_narrows_both_sets_together(): void
    {
        Identity_Store::create(Chat_Identity::NAME, ['operations' => ['read']]);
        $after = $this->advertised_and_prompt();

        $this->assertContains('wpmcp__get-post', $after);
        $this->assertNotContains('wpmcp__update-post', $after);
        $this->assertSame($this->governed_set(), $after);
    }

    public function test_the_provider_tool_list_only_contains_advertised_tools(): void
    {
        $inventory  = new Tool_Inventory(Plugin::instance()->registrar());
        $advertised = Chat_Identity::run(fn () => $inventory->advertised());
        $domains    = array_keys(Tool_Inventory::by_domain($advertised));

        $defs  = Tool_Inventory::definitions($advertised, $domains);
        $names = array_column($defs, 'name');

        $this->assertSame(Tool_Inventory::LOAD_TOOLS, array_shift($names));
        sort($names, SORT_STRING);
        $this->assertSame(array_keys($advertised), $names);

        // Every schema is a JSON object at the top level, as the provider requires.
        foreach ($defs as $def) {
            $json = wp_json_encode($def['input_schema']);
            $this->assertStringStartsWith('{', $json, $def['name']);
            $this->assertStringNotContainsString('"properties":[]', $json, $def['name']);
        }
    }

    public function test_building_the_inventory_writes_no_audit_rows(): void
    {
        delete_option(Governance_Audit_Log::OPTION);
        $inventory = new Tool_Inventory(Plugin::instance()->registrar());
        Chat_Identity::run(fn () => $inventory->advertised());

        $this->assertSame([], Governance_Audit_Log::list());
    }

    public function test_resolve_only_returns_advertised_abilities(): void
    {
        Governance::set_ability_toggle('wpmcp/update-post', false);
        $inventory = new Tool_Inventory(Plugin::instance()->registrar());

        Chat_Identity::run(function () use ($inventory): void {
            $this->assertNotNull($inventory->resolve('wpmcp__get-post'));
            $this->assertNull($inventory->resolve('wpmcp__update-post'));
            $this->assertNull($inventory->resolve('wpmcp/get-post'));
            $this->assertNull($inventory->resolve('../wpmcp__get-post'));
        });
    }
}
