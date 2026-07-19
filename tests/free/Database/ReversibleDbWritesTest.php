<?php

namespace WPMCP\Tests\Free\Database;

use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Database\Database_Guard;
use WPMCP\Tools\Database\Delete_Rows;
use WPMCP\Tools\Database\Update_Rows;
use WPMCP\Tools\Rollback_Operation;

/**
 * Issue #82: single-table row writes become snapshot-backed and restorable.
 *
 * update-rows / delete-rows must route through the safety core (snapshot of
 * the exact rows the WHERE matches, saved to the operation history) whenever
 * a faithful restore is genuinely possible, and report recoverable:true only
 * then. Cases where an exact restore CANNOT be promised (no primary key,
 * before-image cap exceeded, non-UTF-8 binary values) must stay honestly
 * recoverable:false with a machine-readable reason, exactly like before.
 *
 * The scratch tables are created once per class (outside the per-test
 * transaction, so MySQL's implicit DDL commit never breaks test isolation)
 * and dropped after the class.
 */
class ReversibleDbWritesTest extends \WP_UnitTestCase
{
    private static string $table;
    private static string $nopk_table;

    public static function wpSetUpBeforeClass(): void
    {
        global $wpdb;
        self::$table      = $wpdb->prefix . 'wpmcp_test_rows';
        self::$nopk_table = $wpdb->prefix . 'wpmcp_test_nopk';

        $wpdb->query('CREATE TABLE ' . self::$table . ' (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(64) NOT NULL DEFAULT "",
            val TEXT NULL,
            bin_val BLOB NULL,
            PRIMARY KEY (id)
        )');
        $wpdb->query('CREATE TABLE ' . self::$nopk_table . ' (
            name VARCHAR(64) NOT NULL,
            val VARCHAR(64) NOT NULL
        )');

        Snapshot_Store::install();
    }

    public static function wpTearDownAfterClass(): void
    {
        global $wpdb;
        $wpdb->query('DROP TABLE IF EXISTS ' . self::$table);
        $wpdb->query('DROP TABLE IF EXISTS ' . self::$nopk_table);
    }

    protected function setUp(): void
    {
        parent::setUp();
        add_filter('wpmcp_enable_db_writes', '__return_true');
    }

    /** Insert a row into the PK scratch table and return its id. */
    private function seed(string $name, ?string $val): int
    {
        global $wpdb;
        $wpdb->insert(self::$table, ['name' => $name, 'val' => $val]);
        return (int) $wpdb->insert_id;
    }

    private function row(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::$table . ' WHERE id = %d', $id), ARRAY_A);
        return $row ?: null;
    }

    // -----------------------------------------------------------------
    // Guard-level: primary key detection
    // -----------------------------------------------------------------

    public function test_primary_key_detects_pk_columns(): void
    {
        global $wpdb;
        $this->assertSame(['option_id'], Database_Guard::primary_key($wpdb->options));
        $this->assertSame(['id'], Database_Guard::primary_key(self::$table));
    }

    public function test_primary_key_empty_for_table_without_pk(): void
    {
        $this->assertSame([], Database_Guard::primary_key(self::$nopk_table));
    }

    // -----------------------------------------------------------------
    // update-rows: snapshot-backed happy path
    // -----------------------------------------------------------------

    public function test_update_rows_reports_recoverable_true_with_operation_id(): void
    {
        $id = $this->seed('alpha', 'v1');

        $result = (new Update_Rows())->handle([
            'table'   => self::$table,
            'data'    => ['val' => 'v2'],
            'where'   => ['id' => $id],
            'confirm' => true,
        ]);

        $this->assertTrue($result['recoverable']);
        $this->assertNotEmpty($result['operation_id']);
        $this->assertSame(1, $result['affected']);

        $stored = Snapshot_Store::get_by_operation($result['operation_id']);
        $this->assertNotNull($stored, 'A snapshot row must be saved to the operation history');
        $this->assertSame('db_rows', $stored['snapshot']['object_type']);
        $this->assertSame('v1', $stored['snapshot']['data']['rows'][0]['val']);
    }

    public function test_update_rows_rollback_restores_exact_prior_values(): void
    {
        $id = $this->seed('alpha', 'before-value');

        $result = (new Update_Rows())->handle([
            'table'   => self::$table,
            'data'    => ['val' => 'after-value', 'name' => 'renamed'],
            'where'   => ['id' => $id],
            'confirm' => true,
        ]);

        $this->assertSame('after-value', $this->row($id)['val']);

        $this->assertTrue(Rollback_Service::restore_operation($result['operation_id']));

        $restored = $this->row($id);
        $this->assertSame('before-value', $restored['val']);
        $this->assertSame('alpha', $restored['name']);
    }

    public function test_update_rows_rollback_restores_every_row_of_a_broad_where(): void
    {
        $ids = [];
        foreach (['v1', 'v2', 'v3'] as $val) {
            $ids[] = $this->seed('bulk', $val);
        }

        $result = (new Update_Rows())->handle([
            'table'   => self::$table,
            'data'    => ['val' => 'flattened'],
            'where'   => ['name' => 'bulk'],
            'confirm' => true,
        ]);

        $this->assertSame(3, $result['affected']);
        $this->assertTrue(Rollback_Service::restore_operation($result['operation_id']));

        $this->assertSame('v1', $this->row($ids[0])['val']);
        $this->assertSame('v2', $this->row($ids[1])['val']);
        $this->assertSame('v3', $this->row($ids[2])['val']);
    }

    public function test_update_rows_rollback_restores_null_values(): void
    {
        $id = $this->seed('nullable', null);

        $result = (new Update_Rows())->handle([
            'table'   => self::$table,
            'data'    => ['val' => 'filled'],
            'where'   => ['id' => $id],
            'confirm' => true,
        ]);

        $this->assertTrue(Rollback_Service::restore_operation($result['operation_id']));
        $this->assertNull($this->row($id)['val']);
    }

    // -----------------------------------------------------------------
    // delete-rows: snapshot-backed happy path
    // -----------------------------------------------------------------

    public function test_delete_rows_rollback_reinserts_rows_preserving_ids(): void
    {
        $id_a = $this->seed('doomed', 'a');
        $id_b = $this->seed('doomed', 'b');

        $result = (new Delete_Rows())->handle([
            'table'   => self::$table,
            'where'   => ['name' => 'doomed'],
            'confirm' => true,
        ]);

        $this->assertTrue($result['recoverable']);
        $this->assertSame(2, $result['affected']);
        $this->assertNull($this->row($id_a));

        $this->assertTrue(Rollback_Service::restore_operation($result['operation_id']));

        // Original primary-key ids are preserved: rows are reinserted with
        // their captured ids, not fresh auto-increment ones.
        $this->assertSame('a', $this->row($id_a)['val']);
        $this->assertSame('b', $this->row($id_b)['val']);
    }

    // -----------------------------------------------------------------
    // Conflict detection: warn when rows changed since the operation
    // -----------------------------------------------------------------

    public function test_rollback_warns_when_row_changed_since_update(): void
    {
        global $wpdb;
        $id = $this->seed('drift', 'v1');

        $result = (new Update_Rows())->handle([
            'table'   => self::$table,
            'data'    => ['val' => 'v2'],
            'where'   => ['id' => $id],
            'confirm' => true,
        ]);

        // Third party changes the row after the operation.
        $wpdb->update(self::$table, ['val' => 'external-edit'], ['id' => $id]);

        $out = (new Rollback_Operation())->handle(['operation_id' => $result['operation_id']]);

        $this->assertTrue($out['restored']);
        $this->assertNotEmpty($out['warnings'], 'Drifted row must produce a conflict warning');
        // The before-image still wins: rollback restores the captured state.
        $this->assertSame('v1', $this->row($id)['val']);
    }

    public function test_rollback_reinserts_and_warns_when_row_deleted_since_update(): void
    {
        global $wpdb;
        $id = $this->seed('vanishing', 'v1');

        $result = (new Update_Rows())->handle([
            'table'   => self::$table,
            'data'    => ['val' => 'v2'],
            'where'   => ['id' => $id],
            'confirm' => true,
        ]);

        $wpdb->delete(self::$table, ['id' => $id]);

        $out = (new Rollback_Operation())->handle(['operation_id' => $result['operation_id']]);

        $this->assertTrue($out['restored']);
        $this->assertNotEmpty($out['warnings']);
        $this->assertSame('v1', $this->row($id)['val']);
    }

    public function test_rollback_warns_when_deleted_pk_was_reclaimed(): void
    {
        global $wpdb;
        $id = $this->seed('reclaim', 'original');

        $result = (new Delete_Rows())->handle([
            'table'   => self::$table,
            'where'   => ['id' => $id],
            'confirm' => true,
        ]);

        // Something else reclaims the primary key before the rollback.
        $wpdb->insert(self::$table, ['id' => $id, 'name' => 'squatter', 'val' => 'squat']);

        $out = (new Rollback_Operation())->handle(['operation_id' => $result['operation_id']]);

        $this->assertTrue($out['restored']);
        $this->assertNotEmpty($out['warnings']);
        $this->assertSame('original', $this->row($id)['val']);
        $this->assertSame('reclaim', $this->row($id)['name']);
    }

    public function test_clean_rollback_has_no_warnings(): void
    {
        $id = $this->seed('clean', 'v1');

        $result = (new Update_Rows())->handle([
            'table'   => self::$table,
            'data'    => ['val' => 'v2'],
            'where'   => ['id' => $id],
            'confirm' => true,
        ]);

        $out = (new Rollback_Operation())->handle(['operation_id' => $result['operation_id']]);

        $this->assertTrue($out['restored']);
        $this->assertSame([], $out['warnings']);
    }

    // -----------------------------------------------------------------
    // Session rollback covers DB row operations
    // -----------------------------------------------------------------

    public function test_session_rollback_unwinds_stacked_db_operations(): void
    {
        $id      = $this->seed('sess', 'pre-session');
        $session = wp_generate_uuid4();

        (new Update_Rows())->handle([
            'table'      => self::$table,
            'data'       => ['val' => 'step-1'],
            'where'      => ['id' => $id],
            'confirm'    => true,
            'session_id' => $session,
        ]);
        (new Update_Rows())->handle([
            'table'      => self::$table,
            'data'       => ['val' => 'step-2'],
            'where'      => ['id' => $id],
            'confirm'    => true,
            'session_id' => $session,
        ]);
        (new Delete_Rows())->handle([
            'table'      => self::$table,
            'where'      => ['id' => $id],
            'confirm'    => true,
            'session_id' => $session,
        ]);

        $this->assertNull($this->row($id));

        $count = Rollback_Service::restore_session($session);

        $this->assertSame(3, $count);
        $this->assertSame('pre-session', $this->row($id)['val']);
    }

    // -----------------------------------------------------------------
    // Honestly non-recoverable cases stay recoverable:false
    // -----------------------------------------------------------------

    public function test_update_rows_on_table_without_pk_stays_recoverable_false(): void
    {
        global $wpdb;
        $wpdb->insert(self::$nopk_table, ['name' => 'x', 'val' => 'v1']);

        $result = (new Update_Rows())->handle([
            'table'   => self::$nopk_table,
            'data'    => ['val' => 'v2'],
            'where'   => ['name' => 'x'],
            'confirm' => true,
        ]);

        $this->assertFalse($result['recoverable']);
        $this->assertArrayNotHasKey('operation_id', $result);
        $this->assertStringContainsString('primary key', strtolower($result['recoverable_reason']));
        // The write itself still happens, and the before-image is still returned.
        $this->assertSame(1, $result['affected']);
        $this->assertSame('v1', $result['before_image'][0]['val']);
    }

    public function test_delete_rows_on_table_without_pk_stays_recoverable_false(): void
    {
        global $wpdb;
        $wpdb->insert(self::$nopk_table, ['name' => 'y', 'val' => 'v1']);

        $result = (new Delete_Rows())->handle([
            'table'   => self::$nopk_table,
            'where'   => ['name' => 'y'],
            'confirm' => true,
        ]);

        $this->assertFalse($result['recoverable']);
        $this->assertArrayNotHasKey('operation_id', $result);
        $this->assertStringContainsString('primary key', strtolower($result['recoverable_reason']));
    }

    public function test_update_rows_beyond_before_image_cap_stays_recoverable_false(): void
    {
        global $wpdb;

        $tuples = [];
        for ($i = 0; $i < Database_Guard::BEFORE_IMAGE_CAP + 1; $i++) {
            $tuples[] = "('capped', 'v')";
        }
        $wpdb->query('INSERT INTO ' . self::$table . ' (name, val) VALUES ' . implode(',', $tuples));

        $result = (new Update_Rows())->handle([
            'table'   => self::$table,
            'data'    => ['val' => 'changed'],
            'where'   => ['name' => 'capped'],
            'confirm' => true,
        ]);

        $this->assertFalse($result['recoverable']);
        $this->assertArrayNotHasKey('operation_id', $result);
        $this->assertStringContainsString('cap', strtolower($result['recoverable_reason']));
    }

    public function test_update_rows_with_binary_values_stays_recoverable_false(): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . self::$table . ' (name, bin_val) VALUES (%s, %s)',
            'binary',
            "\xC3\x28\x00\xFF" // invalid UTF-8: cannot survive a JSON snapshot losslessly
        ));

        $result = (new Update_Rows())->handle([
            'table'   => self::$table,
            'data'    => ['val' => 'changed'],
            'where'   => ['name' => 'binary'],
            'confirm' => true,
        ]);

        $this->assertFalse($result['recoverable']);
        $this->assertArrayNotHasKey('operation_id', $result);
        $this->assertStringContainsString('binary', strtolower($result['recoverable_reason']));
    }

    // -----------------------------------------------------------------
    // Existing gates are preserved on the new path
    // -----------------------------------------------------------------

    public function test_opt_in_filter_still_gates_the_snapshot_backed_path(): void
    {
        remove_filter('wpmcp_enable_db_writes', '__return_true');
        $id = $this->seed('gated', 'v1');

        $this->expectException(\RuntimeException::class);
        (new Update_Rows())->handle([
            'table'   => self::$table,
            'data'    => ['val' => 'v2'],
            'where'   => ['id' => $id],
            'confirm' => true,
        ]);
    }

    public function test_confirm_still_required_on_the_snapshot_backed_path(): void
    {
        $id = $this->seed('unconfirmed', 'v1');

        $this->expectException(\InvalidArgumentException::class);
        (new Update_Rows())->handle([
            'table' => self::$table,
            'data'  => ['val' => 'v2'],
            'where' => ['id' => $id],
        ]);
    }

    public function test_protected_table_refusal_unchanged(): void
    {
        global $wpdb;

        $this->expectException(\RuntimeException::class);
        (new Delete_Rows())->handle([
            'table'   => $wpdb->users,
            'where'   => ['ID' => 1],
            'confirm' => true,
        ]);
    }
}
