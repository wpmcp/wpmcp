<?php

namespace WPMCP\Tests\Free\Memory;

use WPMCP\MCP\Ability;
use WPMCP\Memory\Memory_Guard;
use WPMCP\Memory\Memory_Store;

/**
 * Target matching for enforced guardrails (issue #131).
 *
 * Everything here is about what a published severity=block entry does and
 * does NOT catch. The honest limitation is pinned as a test too: post_id and
 * post_type targets can only match arguments the server can see, so a call
 * that addresses content some other way slips past them; tool: targets have
 * no such gap.
 */
class MemoryGuardTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Memory_Store::ensure_post_type();
        Memory_Store::flush_rules_cache();
    }

    protected function tearDown(): void
    {
        Memory_Store::flush_rules_cache();
        parent::tearDown();
    }

    private function ability(string $name, string $operation = 'update'): Ability
    {
        return new Ability(
            $name,
            'free',
            'test ability',
            ['type' => 'object', 'properties' => []],
            static fn () => null,
            'edit_posts',
            'content',
            $operation
        );
    }

    /** @param string[] $targets */
    private function publish_rule(array $targets, string $text = 'Do not.'): int
    {
        $id = Memory_Store::propose([
            'text'     => $text,
            'kind'     => 'guardrail',
            'severity' => 'block',
            'targets'  => $targets,
        ]);
        Memory_Store::approve($id);
        return $id;
    }

    public function test_a_tool_target_blocks_that_ability(): void
    {
        $id = $this->publish_rule(['tool:delete-post']);

        $rule = Memory_Guard::blocking_rule($this->ability('wpmcp/delete-post', 'delete'));

        $this->assertIsArray($rule);
        $this->assertSame($id, $rule['id']);
    }

    public function test_a_tool_target_does_not_block_a_different_ability(): void
    {
        $this->publish_rule(['tool:delete-post']);

        $this->assertNull(Memory_Guard::blocking_rule($this->ability('wpmcp/update-post')));
    }

    public function test_a_tool_target_accepts_the_prefixed_and_unprefixed_form(): void
    {
        $this->publish_rule(['tool:wpmcp/delete-post']);

        $this->assertNotNull(Memory_Guard::blocking_rule($this->ability('wpmcp/delete-post', 'delete')));
    }

    public function test_a_trailing_wildcard_matches_by_prefix(): void
    {
        $this->publish_rule(['tool:delete-*']);

        $this->assertNotNull(Memory_Guard::blocking_rule($this->ability('wpmcp/delete-media', 'delete')));
        $this->assertNotNull(Memory_Guard::blocking_rule($this->ability('wpmcp/delete-user', 'delete')));
        $this->assertNull(Memory_Guard::blocking_rule($this->ability('wpmcp/update-post')));
    }

    /**
     * A guardrail restricts what an agent may CHANGE. Denying reads would
     * break recall and diagnostics while protecting nothing, so read-only
     * abilities are exempt by construction.
     */
    public function test_read_only_abilities_are_never_blocked(): void
    {
        $this->publish_rule(['tool:get-post']);

        $this->assertNull(Memory_Guard::blocking_rule($this->ability('wpmcp/get-post', 'read')));
    }

    public function test_a_post_id_target_matches_the_conventional_argument_names(): void
    {
        $this->publish_rule(['post_id:77']);
        $ability = $this->ability('wpmcp/update-post');

        $this->assertNotNull(Memory_Guard::blocking_rule($ability, ['post_id' => 77]));
        $this->assertNotNull(Memory_Guard::blocking_rule($ability, ['id' => '77']));
        $this->assertNotNull(Memory_Guard::blocking_rule($ability, ['ids' => [3, 77]]));
        $this->assertNull(Memory_Guard::blocking_rule($ability, ['post_id' => 78]));
    }

    public function test_a_post_type_target_matches_a_declared_post_type_argument(): void
    {
        $this->publish_rule(['post_type:page']);
        $ability = $this->ability('wpmcp/create-post', 'create');

        $this->assertNotNull(Memory_Guard::blocking_rule($ability, ['post_type' => 'page']));
        $this->assertNull(Memory_Guard::blocking_rule($ability, ['post_type' => 'post']));
    }

    /** A rule on a post type must catch a call that names only an id. */
    public function test_a_post_type_target_matches_the_resolved_type_of_a_named_id(): void
    {
        $page = self::factory()->post->create(['post_type' => 'page']);
        $post = self::factory()->post->create(['post_type' => 'post']);
        $this->publish_rule(['post_type:page']);
        $ability = $this->ability('wpmcp/update-post');

        $this->assertNotNull(Memory_Guard::blocking_rule($ability, ['post_id' => $page]));
        $this->assertNull(Memory_Guard::blocking_rule($ability, ['post_id' => $post]));
    }

    /**
     * The limitation, stated rather than hidden: id/type targets can only
     * match arguments the server can see.
     */
    public function test_post_id_targets_cannot_match_a_call_that_names_no_id(): void
    {
        $this->publish_rule(['post_id:77']);

        $this->assertNull(Memory_Guard::blocking_rule($this->ability('wpmcp/update-post'), ['slug' => 'about']));
    }

    public function test_a_rule_matches_when_any_of_its_targets_matches(): void
    {
        $this->publish_rule(['post_id:1', 'tool:update-post']);

        $this->assertNotNull(Memory_Guard::blocking_rule($this->ability('wpmcp/update-post')));
    }

    public function test_the_lowest_entry_id_wins_so_the_reported_rule_is_deterministic(): void
    {
        $first  = $this->publish_rule(['tool:update-post'], 'First rule.');
        $this->publish_rule(['tool:update-*'], 'Second rule.');

        $rule = Memory_Guard::blocking_rule($this->ability('wpmcp/update-post'));

        $this->assertSame($first, $rule['id']);
    }

    public function test_nothing_is_blocked_when_no_rule_is_published(): void
    {
        $this->assertNull(Memory_Guard::blocking_rule($this->ability('wpmcp/delete-post', 'delete')));
    }

    public function test_enforcement_can_be_switched_off_by_filter(): void
    {
        $this->publish_rule(['tool:delete-post']);
        add_filter('wpmcp_memory_enforce', '__return_false');

        $this->assertNull(Memory_Guard::blocking_rule($this->ability('wpmcp/delete-post', 'delete')));

        remove_filter('wpmcp_memory_enforce', '__return_false');
    }

    public function test_candidate_ids_ignores_junk_and_deduplicates(): void
    {
        $ids = Memory_Guard::candidate_ids([
            'post_id' => '12',
            'id'      => 12,
            'ids'     => [13, 'nope', -4, 0],
            'unused'  => 'x',
        ]);

        $this->assertSame([12, 13], $ids);
    }

    public function test_a_malformed_stored_target_is_ignored_rather_than_matching(): void
    {
        $ability = $this->ability('wpmcp/delete-post', 'delete');

        $this->assertFalse(Memory_Guard::rule_matches(['targets' => ['garbage', 42, null]], $ability, []));
        $this->assertFalse(Memory_Guard::rule_matches([], $ability, []));
    }

    /**
     * Two shapes that the validator refuses on the way in, but that could
     * still reach the matcher from a hand-edited row: an unknown target type,
     * and a bare "*" that would otherwise widen into "block everything".
     */
    public function test_an_unknown_target_type_and_a_bare_wildcard_never_match(): void
    {
        $ability = $this->ability('wpmcp/delete-post', 'delete');

        $this->assertFalse(Memory_Guard::rule_matches(['targets' => ['user:5']], $ability, []));
        $this->assertFalse(Memory_Guard::rule_matches(['targets' => ['tool:*']], $ability, []));
    }

    public function test_candidate_post_types_reads_the_type_alias_argument(): void
    {
        $this->assertSame(['page'], Memory_Guard::candidate_post_types(['type' => 'page']));
        $this->assertSame([], Memory_Guard::candidate_post_types(['type' => '']));
    }

    /**
     * The deny list must not be reachable by a third-party query filter.
     *
     * Memory_Guard::blocking_rule() fails open: no rules means no block. So
     * any plugin able to empty the result set of the block_rules() read would
     * silently switch the write guard off site-wide. The read therefore keeps
     * suppress_filters explicitly true, and this test is what stops a future
     * "drop the redundant argument" cleanup from removing it.
     */
    public function test_a_third_party_the_posts_filter_cannot_empty_the_block_rules(): void
    {
        $this->publish_rule(['tool:delete-post']);
        Memory_Store::flush_rules_cache();

        $steal    = static fn (): array => [];
        $nowhere  = static fn (): string => ' AND 1=0 ';
        add_filter('the_posts', $steal, 10, 1);
        add_filter('posts_results', $steal, 10, 1);
        add_filter('posts_where', $nowhere, 10, 1);

        try {
            $rule = Memory_Guard::blocking_rule($this->ability('wpmcp/delete-post', 'delete'));
        } finally {
            remove_filter('the_posts', $steal, 10);
            remove_filter('posts_results', $steal, 10);
            remove_filter('posts_where', $nowhere, 10);
        }

        $this->assertNotNull($rule, 'a posts_* filter must not be able to remove a published block rule');
    }

    /**
     * Same protection stated at the source: the argument is present, and it
     * carries the justification the compliance rule looks for.
     */
    public function test_the_block_rules_read_records_why_it_suppresses_filters(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/src/Memory/Memory_Store.php');

        $this->assertIsString($source);
        $this->assertStringContainsString("'suppress_filters' => true", $source);
        $this->assertStringContainsString(
            'phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters --',
            $source
        );
    }
}
