<?php

namespace WPMCP\Tests\Pro\Memory;

use WPMCP\Memory\Memory_Store;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tests\Free\Platform\RegisteredAbilities;
use WPMCP\Tools\Memory\Memory_Propose;
use WPMCP\Tools\Memory\Memory_Recall;
use WPMCP\Tools\Memory\Memory_Save_Summary;

/**
 * The three agent-facing memory tools (issue #131): PRO tier, opt-in, and
 * incapable of publishing anything.
 *
 * memory-propose has no status parameter by construction, so the only thing
 * an agent can do is queue a proposal for a human. The enforcement side of
 * the feature is deliberately NOT gated on this license or this opt-in (see
 * tests/free/Memory/MemoryEnforcementTest): a guardrail an administrator
 * published must keep denying writes even after the tools are switched off.
 */
class MemoryToolsTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
        Memory_Store::ensure_post_type();
        Memory_Store::flush_rules_cache();
        Snapshot_Store::install();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        add_filter('wpmcp_enable_memory', '__return_true');
    }

    protected function tearDown(): void
    {
        remove_filter('wpmcp_enable_memory', '__return_true');
        Memory_Store::flush_rules_cache();
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    public function test_all_three_tools_register_as_pro_tier_in_the_memory_domain(): void
    {
        $found = [];
        foreach (RegisteredAbilities::all() as $ability) {
            if (0 === strpos($ability->name, 'wpmcp/memory-')) {
                $found[ $ability->name ] = [$ability->tier, $ability->domain, $ability->capability];
            }
        }

        $this->assertSame(
            [
                'wpmcp/memory-recall'       => ['pro', 'memory', 'manage_options'],
                'wpmcp/memory-propose'      => ['pro', 'memory', 'manage_options'],
                'wpmcp/memory-save-summary' => ['pro', 'memory', 'manage_options'],
            ],
            $found
        );
    }

    public function test_every_tool_is_off_until_the_site_opts_in(): void
    {
        remove_filter('wpmcp_enable_memory', '__return_true');

        foreach ([new Memory_Recall(), new Memory_Propose(), new Memory_Save_Summary()] as $tool) {
            $result = $tool->handle(['text' => 'x', 'session_id' => 's']);
            $this->assertWPError($result);
            $this->assertSame('opt_in_required', $result->get_error_code());
        }
    }

    public function test_propose_stores_a_pending_entry_and_says_it_is_not_enforced(): void
    {
        $out = (new Memory_Propose())->handle([
            'text'     => 'Never delete the homepage.',
            'kind'     => 'guardrail',
            'severity' => 'block',
            'targets'  => ['post_id:5'],
        ]);

        $this->assertSame('pending', $out['status']);
        $this->assertFalse($out['enforced']);
        $this->assertSame(['post_id:5'], $out['targets']);
        $this->assertSame('pending', get_post_status($out['id']));
        $this->assertSame([], Memory_Store::block_rules());
    }

    public function test_propose_surfaces_the_validator_error(): void
    {
        $out = (new Memory_Propose())->handle(['text' => 'Block everything.', 'severity' => 'block']);

        $this->assertWPError($out);
        $this->assertStringContainsString('at least one target', $out->get_error_message());
    }

    public function test_recall_returns_published_entries_only_and_counts_the_pending_ones(): void
    {
        $published = Memory_Store::propose(['text' => 'Menus live in the customizer.']);
        Memory_Store::approve($published);
        Memory_Store::propose(['text' => 'An unapproved suggestion.']);

        $out = (new Memory_Recall())->handle([]);

        $this->assertSame([$published], array_column($out['guidance'], 'id'));
        $this->assertSame(1, $out['pending_count']);
        $this->assertStringNotContainsString('unapproved', wp_json_encode($out));
    }

    public function test_recall_reports_the_enforced_rule_set(): void
    {
        $id = Memory_Store::propose([
            'text'     => 'Never delete the homepage.',
            'kind'     => 'guardrail',
            'severity' => 'block',
            'targets'  => ['post_id:5'],
        ]);
        Memory_Store::approve($id);

        $out = (new Memory_Recall())->handle([]);

        $this->assertSame([['id' => $id, 'title' => 'Never delete the homepage.', 'targets' => ['post_id:5']]], $out['enforced']);
    }

    public function test_recall_separates_session_summaries_from_guidance(): void
    {
        $fact    = Memory_Store::propose(['text' => 'A durable fact.']);
        $session = Memory_Store::propose(['text' => 'Session sess-a did things.', 'kind' => 'session-summary']);
        Memory_Store::approve($fact);
        Memory_Store::approve($session);

        $out = (new Memory_Recall())->handle([]);

        $this->assertSame([$fact], array_column($out['guidance'], 'id'));
        $this->assertSame([$session], array_column($out['sessions'], 'id'));
    }

    public function test_recall_can_filter_by_kind_and_topic(): void
    {
        $convention = Memory_Store::propose(['text' => 'Checkout uses WooCommerce.', 'kind' => 'convention']);
        $fact       = Memory_Store::propose(['text' => 'Menus live in the customizer.']);
        Memory_Store::approve($convention);
        Memory_Store::approve($fact);

        $by_kind = (new Memory_Recall())->handle(['kind' => 'convention']);
        $this->assertSame([$convention], array_column($by_kind['guidance'], 'id'));

        $by_topic = (new Memory_Recall())->handle(['topic' => 'customizer']);
        $this->assertSame([$fact], array_column($by_topic['guidance'], 'id'));

        $summaries = (new Memory_Recall())->handle(['kind' => 'session-summary']);
        $this->assertSame([], $summaries['guidance']);
    }

    public function test_recall_rejects_an_unknown_kind(): void
    {
        $out = (new Memory_Recall())->handle(['kind' => 'vibes']);

        $this->assertWPError($out);
        $this->assertSame('wpmcp_memory_invalid', $out->get_error_code());
    }

    /**
     * The factual part of a summary comes from the snapshot rows, not from
     * the prose: the agent's own account is kept, but it cannot displace the
     * server's record of what actually changed.
     */
    public function test_save_summary_composes_the_digest_from_snapshot_rows(): void
    {
        $snapshot = ['object_type' => 'post', 'object_id' => 42, 'data' => ['post' => null, 'meta' => []]];
        Snapshot_Store::save('op-1', 'sess-a', $snapshot, 'update-post', str_repeat('a', 64));

        $out = (new Memory_Save_Summary())->handle([
            'session_id' => 'sess-a',
            'summary'    => 'I rebuilt the whole site.',
        ]);

        $this->assertSame('pending', $out['status']);
        $this->assertSame(1, $out['digest']['operation_count']);

        $text = Memory_Store::get($out['id'])['text'];
        $this->assertStringContainsString('I rebuilt the whole site.', $text);
        $this->assertStringContainsString('update-post x1', $text);
        $this->assertStringContainsString('post #42', $text);
    }

    public function test_save_summary_records_an_honest_digest_for_a_session_that_changed_nothing(): void
    {
        $out = (new Memory_Save_Summary())->handle([
            'session_id' => 'sess-quiet',
            'summary'    => 'I rewrote every page.',
        ]);

        $this->assertSame(0, $out['digest']['operation_count']);
        $this->assertStringContainsString('no snapshotted changes recorded', Memory_Store::get($out['id'])['text']);
    }

    public function test_save_summary_requires_a_session_id(): void
    {
        $this->assertWPError((new Memory_Save_Summary())->handle([]));
    }

    public function test_takeaways_become_individual_pending_proposals(): void
    {
        $out = (new Memory_Save_Summary())->handle([
            'session_id' => 'sess-a',
            'takeaways'  => [
                ['text' => 'The header is a reusable block.'],
                ['text' => 'Do not touch the homepage.', 'severity' => 'block', 'targets' => ['post_id:5']],
                ['text' => 'Block everything.', 'severity' => 'block'],
                'not-an-array',
            ],
        ]);

        $this->assertCount(2, $out['proposed']);
        $this->assertCount(1, $out['rejected']);
        foreach ($out['proposed'] as $id) {
            $this->assertSame('pending', get_post_status($id));
        }
        $this->assertSame([], Memory_Store::block_rules());
    }

    public function test_takeaways_are_capped(): void
    {
        $takeaways = [];
        for ($i = 0; $i < Memory_Save_Summary::MAX_TAKEAWAYS + 5; $i++) {
            $takeaways[] = ['text' => 'Takeaway number ' . $i . '.'];
        }

        $out = (new Memory_Save_Summary())->handle(['session_id' => 'sess-a', 'takeaways' => $takeaways]);

        $this->assertCount(Memory_Save_Summary::MAX_TAKEAWAYS, $out['proposed']);
    }

    public function test_non_array_takeaways_are_ignored(): void
    {
        $out = (new Memory_Save_Summary())->handle(['session_id' => 'sess-a', 'takeaways' => 'nope']);

        $this->assertSame([], $out['proposed']);
    }
}
