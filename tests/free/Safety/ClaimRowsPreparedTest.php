<?php

namespace WPMCP\Tests\Free\Safety;

use WPMCP\Safety\Snapshot_Store;

/**
 * The row-level claim and release (issue #1147). The queries were rewritten
 * so the %d list is built inside prepare(); these pin the paths the other
 * suites never reach: a partial claim that releases what it stamped, and a
 * release by row ids.
 */
class ClaimRowsPreparedTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        $GLOBALS['wpdb']->query('DELETE FROM ' . Snapshot_Store::table_name());
    }

    /** @return int[] */
    private function rows(int $count): array
    {
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $ids[] = Snapshot_Store::save("claim-op-{$i}", 'default', [ 'object_type' => 'post', 'object_id' => $i + 1, 'data' => [ 'post' => null, 'meta' => [] ] ], 'update-blocks', str_repeat('a', 64));
        }
        return $ids;
    }

    /** @param int[] $ids @return array<int, string|null> id => rolled_back_at */
    private function stamps(array $ids): array
    {
        global $wpdb;
        $out = [];
        foreach ($ids as $id) {
            $out[ $id ] = $wpdb->get_var($wpdb->prepare('SELECT rolled_back_at FROM %i WHERE id = %d', Snapshot_Store::table_name(), $id));
        }
        return $out;
    }

    public function test_claim_rows_stamps_every_row_once(): void
    {
        $ids = $this->rows(3);

        $this->assertTrue(Snapshot_Store::claim_rows($ids));
        $this->assertNotContains(null, $this->stamps($ids));
        $this->assertFalse(Snapshot_Store::claim_rows($ids), 'A second claim of the same rows fails');
        $this->assertFalse(Snapshot_Store::claim_rows([]), 'No rows, no claim');
    }

    public function test_a_partial_claim_releases_the_rows_it_stamped(): void
    {
        global $wpdb;
        $ids = $this->rows(3);
        // An earlier undo, stamped in an earlier second: the release matches
        // on this call's own stamp, so a claim from the same second would be
        // released too.
        $taken = '2000-01-01 00:00:00';
        $wpdb->query($wpdb->prepare('UPDATE %i SET rolled_back_at = %s WHERE id = %d', Snapshot_Store::table_name(), $taken, $ids[0]));

        $this->assertFalse(Snapshot_Store::claim_rows($ids), 'One row was already claimed');

        $after = $this->stamps($ids);
        $this->assertSame($taken, $after[ $ids[0] ], 'The earlier claim keeps its stamp');
        $this->assertNull($after[ $ids[1] ], 'A row this call stamped is released again');
        $this->assertNull($after[ $ids[2] ], 'A row this call stamped is released again');
    }

    public function test_release_by_row_ids_clears_only_those_rows(): void
    {
        $ids = $this->rows(3);
        $this->assertTrue(Snapshot_Store::claim_rows($ids));

        Snapshot_Store::release_rollback('', [ $ids[0], $ids[2], $ids[2] ]);

        $after = $this->stamps($ids);
        $this->assertNull($after[ $ids[0] ]);
        $this->assertNotNull($after[ $ids[1] ], 'A row not named stays claimed');
        $this->assertNull($after[ $ids[2] ]);
        $this->assertTrue(Snapshot_Store::claim_rows([ $ids[0], $ids[2] ]), 'Released rows can be claimed again');
    }
}
