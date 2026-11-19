<?php

namespace WPMCP\Tests\Free\Memory;

use WPMCP\Governance\Governance_Audit_Log;
use WPMCP\MCP\Ability;
use WPMCP\MCP\Registrar;
use WPMCP\Memory\Memory_Store;

/**
 * The differentiator (issue #131): a published guardrail is ENFORCED, not
 * suggested.
 *
 * Enforcement lives in Registrar::is_permitted(), the single gate every
 * wpmcp ability passes through, so these tests drive the REAL registered
 * abilities through check_permissions() (the same call the Abilities API
 * makes before every execution) rather than a stand-in. That is the whole
 * claim: no per-tool opt-in, nothing for a future write path to remember to
 * call, and abilities written after the rule are covered automatically.
 */
class MemoryEnforcementTest extends \WP_UnitTestCase
{
    public static function wpSetUpBeforeClass(): void
    {
        // wp_abilities_api_init fires lazily on first registry access; the
        // real abilities (with their wrapped permission_callback) only exist
        // once it has fired. See WPMCP\Tests\Free\PluginAbilitiesTest.
        if (0 === did_action('wp_abilities_api_init')) {
            do_action('wp_abilities_api_init');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Memory_Store::ensure_post_type();
        Memory_Store::flush_rules_cache();
        delete_option(Governance_Audit_Log::OPTION);
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    protected function tearDown(): void
    {
        Memory_Store::flush_rules_cache();
        delete_option(Governance_Audit_Log::OPTION);
        parent::tearDown();
    }

    /** @param string[] $targets */
    private function publish_rule(array $targets): int
    {
        $id = Memory_Store::propose([
            'text'     => 'Guardrail under test.',
            'kind'     => 'guardrail',
            'severity' => 'block',
            'targets'  => $targets,
        ]);
        Memory_Store::approve($id);
        return $id;
    }

    public function test_a_published_tool_rule_denies_the_real_registered_ability(): void
    {
        $abilities = wp_get_abilities();
        $this->assertTrue($abilities['wpmcp/delete-post']->check_permissions(['post_id' => 1]));

        $this->publish_rule(['tool:delete-post']);

        $this->assertNotTrue($abilities['wpmcp/delete-post']->check_permissions(['post_id' => 1]));
    }

    public function test_a_pending_rule_denies_nothing(): void
    {
        Memory_Store::propose([
            'text'     => 'Guardrail under test.',
            'kind'     => 'guardrail',
            'severity' => 'block',
            'targets'  => ['tool:delete-post'],
        ]);

        $this->assertTrue(wp_get_abilities()['wpmcp/delete-post']->check_permissions(['post_id' => 1]));
    }

    /**
     * The rule names a post id, and the DENIAL happens in the permission
     * check, before the tool runs at all: the invocation input reaches
     * is_permitted() through WP_Ability::check_permissions($input).
     */
    public function test_a_post_id_rule_denies_only_the_call_that_names_that_post(): void
    {
        $protected = self::factory()->post->create();
        $other     = self::factory()->post->create();
        $this->publish_rule(['post_id:' . $protected]);

        $ability = wp_get_abilities()['wpmcp/update-post'];

        $this->assertNotTrue($ability->check_permissions(['post_id' => $protected]));
        $this->assertTrue($ability->check_permissions(['post_id' => $other]));
    }

    public function test_a_post_type_rule_denies_a_call_that_names_only_the_id(): void
    {
        $page = self::factory()->post->create(['post_type' => 'page']);
        $this->publish_rule(['post_type:page']);

        $this->assertNotTrue(wp_get_abilities()['wpmcp/update-post']->check_permissions(['post_id' => $page]));
    }

    /** Reads stay open: a guardrail restricts changes, not visibility. */
    public function test_read_only_abilities_stay_permitted_under_a_matching_rule(): void
    {
        $post = self::factory()->post->create();
        $this->publish_rule(['post_id:' . $post]);

        $this->assertTrue(wp_get_abilities()['wpmcp/get-post']->check_permissions(['post_id' => $post]));
    }

    /**
     * The memory check runs LAST and can only narrow. It never turns a
     * capability/governance denial into an allow.
     */
    public function test_the_memory_check_cannot_widen_an_existing_denial(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        $registrar = new Registrar();
        $ability   = new Ability(
            'wpmcp/test-locked',
            'free',
            'test',
            ['type' => 'object', 'properties' => []],
            static fn () => null,
            'manage_options',
            'content',
            'update'
        );

        $this->assertFalse($registrar->is_permitted($ability));
    }

    public function test_a_memory_denial_is_recorded_in_the_audit_log_with_the_entry_id(): void
    {
        $id = $this->publish_rule(['tool:delete-post']);

        wp_get_abilities()['wpmcp/delete-post']->check_permissions(['post_id' => 1]);

        $entries = array_values(array_filter(
            Governance_Audit_Log::list(),
            static fn (array $e): bool => 'wpmcp/delete-post' === $e['ability']
        ));

        $this->assertNotEmpty($entries);
        $latest = $entries[ count($entries) - 1 ];
        $this->assertFalse($latest['allowed']);
        $this->assertSame('memory-block:' . $id, $latest['reason']);
    }

    public function test_an_ordinary_decision_records_an_empty_reason(): void
    {
        wp_get_abilities()['wpmcp/get-post']->check_permissions(['post_id' => 1]);

        $entries = array_values(array_filter(
            Governance_Audit_Log::list(),
            static fn (array $e): bool => 'wpmcp/get-post' === $e['ability']
        ));

        $this->assertNotEmpty($entries);
        $this->assertSame('', $entries[ count($entries) - 1 ]['reason']);
    }

    /**
     * The rule was published while these abilities already existed, but the
     * point is that it needs no cooperation from them: a wildcard rule
     * denies every matching ability uniformly, including ones added later.
     */
    public function test_a_wildcard_rule_denies_across_the_surface_with_no_per_tool_opt_in(): void
    {
        $this->publish_rule(['tool:delete-*']);

        $abilities = wp_get_abilities();
        $denied    = 0;
        foreach (['wpmcp/delete-post', 'wpmcp/delete-media', 'wpmcp/delete-comment'] as $name) {
            if (isset($abilities[ $name ]) && true !== $abilities[ $name ]->check_permissions([])) {
                $denied++;
            }
        }

        $this->assertSame(3, $denied);
    }

    public function test_switching_enforcement_off_restores_the_call(): void
    {
        $this->publish_rule(['tool:delete-post']);
        add_filter('wpmcp_memory_enforce', '__return_false');

        $this->assertTrue(wp_get_abilities()['wpmcp/delete-post']->check_permissions(['post_id' => 1]));

        remove_filter('wpmcp_memory_enforce', '__return_false');
    }
}
