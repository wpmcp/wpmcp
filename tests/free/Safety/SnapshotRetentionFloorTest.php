<?php

namespace WPMCP\Tests\Free\Safety;

use WPMCP\Admin\Snapshot_Retention_Notice;
use WPMCP\Safety\Snapshot_Store;

/**
 * Issue #158: flattening the cap turned an unlimited 0.8.0 Pro history into
 * a 20-row history, and prune() deletes the File_Backup bytes behind every
 * row it drops. Deleting that on the first write after an unattended update
 * is not a product decision the site owner made, so an upgraded install
 * carries a one-time retention floor: history is held at the depth it
 * already had until the owner either sets the filter or acknowledges the
 * notice.
 */
class SnapshotRetentionFloorTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        delete_option(Snapshot_Store::HISTORY_FLOOR_OPTION);
        Snapshot_Store::install();
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_snapshot_history_limit');
        delete_option(Snapshot_Store::HISTORY_FLOOR_OPTION);
        parent::tearDown();
    }

    private function save_snapshots(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            Snapshot_Store::save(
                "op-floor-{$i}",
                'sess',
                ['object_type' => 'post', 'object_id' => $i, 'data' => ['post' => null, 'meta' => []]],
                'update-blocks',
                str_repeat('a', 64)
            );
        }
    }

    /** A fresh install has nothing to protect, so it prunes normally. */
    public function test_fresh_install_records_no_floor(): void
    {
        $this->assertSame(0, (int) get_option(Snapshot_Store::HISTORY_FLOOR_OPTION));

        $this->save_snapshots(25);
        $this->assertSame(5, Snapshot_Store::prune(20));
    }

    /**
     * The upgrade case: rows already deeper than the cap, and no floor
     * recorded because 0.8.0's install() never wrote one.
     */
    public function test_upgraded_install_holds_history_at_its_existing_depth(): void
    {
        $this->save_snapshots(40);
        delete_option(Snapshot_Store::HISTORY_FLOOR_OPTION);

        $this->assertSame(0, Snapshot_Store::prune(20));
        $this->assertCount(40, Snapshot_Store::recent(1000));
        $this->assertSame(40, (int) get_option(Snapshot_Store::HISTORY_FLOOR_OPTION));
        $this->assertTrue(Snapshot_Store::has_retention_floor());
    }

    /** The floor is a frozen number, not unlimited growth. */
    public function test_floor_does_not_grow_with_new_writes(): void
    {
        $this->save_snapshots(40);
        delete_option(Snapshot_Store::HISTORY_FLOOR_OPTION);
        Snapshot_Store::prune(20);

        $this->save_snapshots(10);
        $this->assertSame(10, Snapshot_Store::prune(20));
        $this->assertCount(40, Snapshot_Store::recent(1000));
    }

    /** Setting the filter is the owner deciding; the floor stands down. */
    public function test_filter_stands_the_floor_down(): void
    {
        $this->save_snapshots(40);
        delete_option(Snapshot_Store::HISTORY_FLOOR_OPTION);
        Snapshot_Store::prune(20);
        $this->assertSame(40, (int) get_option(Snapshot_Store::HISTORY_FLOOR_OPTION));

        add_filter('wpmcp_snapshot_history_limit', fn() => 30);
        $this->assertSame(10, Snapshot_Store::prune());
        $this->assertCount(30, Snapshot_Store::recent(1000));
        $this->assertFalse(Snapshot_Store::has_retention_floor());
    }

    /** Acknowledging the notice is the other way to decide. */
    public function test_acknowledgement_releases_the_floor(): void
    {
        $this->save_snapshots(40);
        delete_option(Snapshot_Store::HISTORY_FLOOR_OPTION);
        Snapshot_Store::prune(20);

        Snapshot_Store::acknowledge_retention_floor();
        $this->assertFalse(Snapshot_Store::has_retention_floor());
        $this->assertSame(20, Snapshot_Store::prune(20));
        $this->assertCount(20, Snapshot_Store::recent(1000));
    }

    /** The notice only exists while there is something to warn about. */
    public function test_notice_renders_only_while_the_floor_stands(): void
    {
        $this->save_snapshots(40);
        delete_option(Snapshot_Store::HISTORY_FLOOR_OPTION);
        Snapshot_Store::prune(20);

        $user = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($user);

        ob_start();
        Snapshot_Retention_Notice::render();
        $with_floor = (string) ob_get_clean();
        $this->assertStringContainsString('wpmcp_snapshot_history_limit', $with_floor);
        $this->assertStringContainsString('40', $with_floor);

        Snapshot_Store::acknowledge_retention_floor();
        ob_start();
        Snapshot_Retention_Notice::render();
        $this->assertSame('', (string) ob_get_clean());
    }

    /** A visitor without the capability never sees it. */
    public function test_notice_is_capability_gated(): void
    {
        $this->save_snapshots(40);
        delete_option(Snapshot_Store::HISTORY_FLOOR_OPTION);
        Snapshot_Store::prune(20);

        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        ob_start();
        Snapshot_Retention_Notice::render();
        $this->assertSame('', (string) ob_get_clean());
    }
}
