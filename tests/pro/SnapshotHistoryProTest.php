<?php

namespace WPMCP\Tests\Pro;

use WPMCP\Pro\Gate;
use WPMCP\Pro\Snapshot_History;
use WPMCP\Safety\Snapshot_Store;

/**
 * Pro lifts the flat snapshot cap (issue #158 kept the free store licence
 * free; this lifts it from src/Pro through the public filter).
 */
class SnapshotHistoryProTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        delete_option(Snapshot_Store::HISTORY_FLOOR_OPTION);
        delete_option(Snapshot_History::SEEN_OPTION);
        Snapshot_Store::install();
    }

    private function save_snapshots(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            Snapshot_Store::save(
                "op-pro-{$i}",
                'default',
                ['object_type' => 'post', 'object_id' => $i, 'data' => ['post' => null, 'meta' => []]],
                'update-blocks',
                str_repeat('a', 64)
            );
        }
    }

    protected function tearDown(): void
    {
        delete_option(Snapshot_Store::HISTORY_FLOOR_OPTION);
        delete_option(Snapshot_History::SEEN_OPTION);
        remove_all_filters('wpmcp_snapshot_history_limit');
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    public function test_licensed_site_has_unlimited_history(): void
    {
        Gate::set_pro_for_tests(true);
        Snapshot_History::register();

        $this->assertSame(PHP_INT_MAX, Snapshot_Store::history_limit());
    }

    public function test_free_site_keeps_the_flat_cap_and_no_filter(): void
    {
        Gate::set_pro_for_tests(false);
        Snapshot_History::register();

        $this->assertFalse(has_filter('wpmcp_snapshot_history_limit'));
        $this->assertSame(Snapshot_Store::DEFAULT_HISTORY_LIMIT, Snapshot_Store::history_limit());
    }

    public function test_lapsed_licence_falls_back_to_the_cap(): void
    {
        Gate::set_pro_for_tests(true);
        Snapshot_History::register();
        Gate::set_pro_for_tests(false);

        $this->assertSame(Snapshot_Store::DEFAULT_HISTORY_LIMIT, Snapshot_Store::history_limit());
    }

    /** Nothing was ever lifted, so nothing is held when a free site stays free. */
    public function test_free_site_that_never_had_pro_sets_no_floor(): void
    {
        $this->save_snapshots(25);
        Gate::set_pro_for_tests(false);
        Snapshot_History::register();

        $this->assertSame(0, (int) get_option(Snapshot_Store::HISTORY_FLOOR_OPTION));
        $this->assertSame(5, Snapshot_Store::prune());
    }

    /**
     * The point of the floor: a lapsed renewal must not delete the history
     * the licence let the site build up.
     */
    public function test_lapse_holds_the_history_the_site_built_up(): void
    {
        Gate::set_pro_for_tests(true);
        Snapshot_History::register();
        $this->save_snapshots(40);
        $this->assertSame(0, Snapshot_Store::prune());

        remove_all_filters('wpmcp_snapshot_history_limit');
        Gate::set_pro_for_tests(false);
        Snapshot_History::register();

        $this->assertSame(40, (int) get_option(Snapshot_Store::HISTORY_FLOOR_OPTION));
        $this->assertFalse(get_option(Snapshot_History::SEEN_OPTION));
        $this->assertSame(0, Snapshot_Store::prune());
        $this->assertCount(40, Snapshot_Store::recent(1000));
        $this->assertTrue(Snapshot_Store::has_retention_floor());
    }

    /** A site under the cap has nothing to protect on lapse. */
    public function test_lapse_with_a_shallow_history_sets_no_floor(): void
    {
        Gate::set_pro_for_tests(true);
        Snapshot_History::register();
        $this->save_snapshots(10);

        remove_all_filters('wpmcp_snapshot_history_limit');
        Gate::set_pro_for_tests(false);
        Snapshot_History::register();

        $this->assertSame(0, (int) get_option(Snapshot_Store::HISTORY_FLOOR_OPTION));
    }
}
