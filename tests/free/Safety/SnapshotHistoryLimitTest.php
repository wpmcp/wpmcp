<?php

namespace WPMCP\Tests\Free\Safety;

use WPMCP\Safety\Snapshot_Store;

/**
 * Issue #158: the snapshot retention cap is one flat number for every
 * install, with no licence check anywhere, and it is raisable for free
 * through a filter. These are the assertions that replace the deleted
 * Gate::history_limit() coverage.
 */
class SnapshotHistoryLimitTest extends \WP_UnitTestCase
{
    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_snapshot_history_limit');
        parent::tearDown();
    }

    public function test_default_is_the_flat_constant(): void
    {
        $this->assertSame(20, Snapshot_Store::DEFAULT_HISTORY_LIMIT);
        $this->assertSame(20, Snapshot_Store::history_limit());
    }

    public function test_filter_can_raise_the_cap_for_free(): void
    {
        add_filter('wpmcp_snapshot_history_limit', fn() => 100);
        $this->assertSame(100, Snapshot_Store::history_limit());
    }

    public function test_filter_can_lower_the_cap(): void
    {
        add_filter('wpmcp_snapshot_history_limit', fn() => 5);
        $this->assertSame(5, Snapshot_Store::history_limit());
    }

    /**
     * A filter returning something that would disable pruning entirely, or
     * that is not a number at all, falls back to the constant rather than
     * letting the snapshots table grow without bound.
     *
     * @dataProvider bad_filter_values
     */
    public function test_non_positive_or_junk_filter_values_fall_back(mixed $value): void
    {
        add_filter('wpmcp_snapshot_history_limit', fn() => $value);
        $this->assertSame(20, Snapshot_Store::history_limit());
    }

    public function bad_filter_values(): array
    {
        return [
            'zero'          => [0],
            'negative'      => [-1],
            'null'          => [null],
            'empty string'  => [''],
            'non numeric'   => ['unlimited'],
            'false'         => [false],
            'true'          => [true],
            'empty array'   => [[]],
            'non empty array' => [[20]],
            'object'        => [new \stdClass()],
            'float above int max' => [1.9e20],
            'numeric string above int max' => ['1.9e20'],
        ];
    }

    public function test_numeric_string_is_coerced(): void
    {
        add_filter('wpmcp_snapshot_history_limit', fn() => '50');
        $this->assertSame(50, Snapshot_Store::history_limit());
    }

    /**
     * Every call site now calls prune() with no argument, so the flat cap
     * cannot be forgotten by a new caller.
     */
    public function test_prune_defaults_to_the_filtered_cap(): void
    {
        Snapshot_Store::install();
        add_filter('wpmcp_snapshot_history_limit', fn() => 4);
        $this->save_snapshots(10);

        $this->assertSame(6, Snapshot_Store::prune());
        $this->assertCount(4, Snapshot_Store::recent(100));
    }

    /**
     * A site upgrading with a deep history must not pay for the whole
     * catch-up prune inside one write: prune() deletes at most one batch,
     * and the next write picks up where it left off.
     */
    public function test_prune_deletes_at_most_one_batch_per_call(): void
    {
        Snapshot_Store::install();
        $this->save_snapshots(Snapshot_Store::PRUNE_BATCH_LIMIT + 30);

        $first = Snapshot_Store::prune(5);
        $this->assertSame(Snapshot_Store::PRUNE_BATCH_LIMIT, $first);
        $this->assertCount(30, Snapshot_Store::recent(1000));

        $second = Snapshot_Store::prune(5);
        $this->assertSame(25, $second);
        $this->assertCount(5, Snapshot_Store::recent(1000));
        $this->assertSame(0, Snapshot_Store::prune(5));
    }

    /** @param int $count */
    private function save_snapshots(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            Snapshot_Store::save(
                "op-limit-{$i}",
                'sess',
                ['object_type' => 'post', 'object_id' => $i, 'data' => ['post' => null, 'meta' => []]],
                'update-blocks',
                str_repeat('a', 64)
            );
        }
    }

    /** The cap the tools actually prune to is the one this accessor returns. */
    public function test_prune_honours_the_filtered_cap(): void
    {
        Snapshot_Store::install();
        add_filter('wpmcp_snapshot_history_limit', fn() => 3);

        for ($i = 0; $i < 10; $i++) {
            Snapshot_Store::save(
                "op-limit-{$i}",
                'sess',
                ['object_type' => 'post', 'object_id' => $i, 'data' => ['post' => null, 'meta' => []]],
                'update-blocks',
                str_repeat('a', 64)
            );
        }

        $this->assertSame(7, Snapshot_Store::prune(Snapshot_Store::history_limit()));
        $this->assertCount(3, Snapshot_Store::recent(100));
    }
}
