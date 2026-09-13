<?php

namespace WPMCP\Tests\Free\Database;

use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Database\Database_Guard;

/**
 * Issue #174: the identifier interpolation that Plugin Check flagged as
 * WordPress.DB.PreparedSQL.NotPrepared is bound with wpdb::prepare()'s %i
 * placeholder (WordPress 6.2+; the plugin requires 6.9) instead of being
 * concatenated and suppressed with a phpcs:ignore.
 *
 * Binding identifiers changes two things that the existing suites do not
 * cover, so they are pinned here:
 *
 *  - before_image() no longer has a placeholder-less branch. An all-NULL
 *    WHERE used to skip wpdb::prepare() entirely because it produced no
 *    placeholders; with %i for the table and every column it always has
 *    some, so it must still return the right rows.
 *  - a column name is ESCAPED by prepare() rather than having its backticks
 *    stripped, so an identifier that legitimately contains one still matches
 *    the column it names, and a hostile one cannot leave the identifier
 *    position.
 *
 * Scratch tables are created once per class (outside the per-test
 * transaction, so MySQL's implicit DDL commit never breaks isolation) and
 * dropped after the class, matching ReversibleDbWritesTest.
 */
class IdentifierPlaceholderTest extends \WP_UnitTestCase
{
    private static string $table;

    public static function wpSetUpBeforeClass(): void
    {
        global $wpdb;
        self::$table = $wpdb->prefix . 'wpmcp_test_ident';

        $wpdb->query('CREATE TABLE ' . self::$table . ' (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(64) NULL,
            val VARCHAR(64) NULL,
            `we``ird` VARCHAR(64) NULL,
            PRIMARY KEY (id)
        )');

        Snapshot_Store::install();
    }

    public static function wpTearDownAfterClass(): void
    {
        global $wpdb;
        $wpdb->query('DROP TABLE IF EXISTS ' . self::$table);
    }

    /**
     * Inserts through an explicit NULL literal rather than $wpdb->insert(),
     * because an all-null insert makes core build a placeholder-less query
     * and trip its own _doing_it_wrong() notice, which would mask the
     * behaviour under test.
     */
    private function seed(?string $name, ?string $val): int
    {
        global $wpdb;

        $slots  = [];
        $values = [self::$table];
        foreach ([$name, $val] as $value) {
            if (null === $value) {
                $slots[] = 'NULL';
                continue;
            }
            $slots[]  = '%s';
            $values[] = $value;
        }

        $wpdb->query($wpdb->prepare(
            'INSERT INTO %i (name, val) VALUES (' . implode(', ', $slots) . ')',
            $values
        ));

        return (int) $wpdb->insert_id;
    }

    /**
     * The case that used to bypass wpdb::prepare() altogether: every WHERE
     * value is null, so the old code produced zero placeholders and ran the
     * concatenated SQL directly. With %i identifiers it is always prepared,
     * and it must still match exactly the IS NULL rows.
     */
    public function test_all_null_where_captures_only_the_null_rows(): void
    {
        $null_row  = $this->seed(null, null);
        $other_row = $this->seed('present', 'present');

        $rows = Database_Guard::before_image(self::$table, ['name' => null, 'val' => null]);

        $ids = array_map('intval', array_column($rows, 'id'));
        $this->assertContains($null_row, $ids);
        $this->assertNotContains($other_row, $ids);
    }

    /** A mixed WHERE binds identifiers and values in the right order. */
    public function test_mixed_null_and_value_where_matches_both_conditions(): void
    {
        $match = $this->seed('mixed', null);
        $this->seed('mixed', 'set');
        $this->seed('other', null);

        $rows = Database_Guard::before_image(self::$table, ['name' => 'mixed', 'val' => null]);

        $this->assertCount(1, $rows);
        $this->assertSame($match, (int) $rows[0]['id']);
    }

    /** The row cap is a bound %d, not concatenated arithmetic. */
    public function test_limit_argument_caps_the_captured_rows(): void
    {
        $this->seed('capped', 'a');
        $this->seed('capped', 'b');
        $this->seed('capped', 'c');

        $rows = Database_Guard::before_image(self::$table, ['name' => 'capped'], 2);

        $this->assertCount(2, $rows);
    }

    /**
     * A column name containing a backtick is ESCAPED by %i (the backtick is
     * doubled), not stripped. The previous code ran str_replace('`', '', ...)
     * over the identifier, which silently rewrote this column to a different
     * name that does not exist, so the WHERE could never match. Binding it
     * makes the capture correct as well as injection-proof.
     */
    public function test_column_name_with_a_backtick_is_escaped_not_stripped(): void
    {
        global $wpdb;
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO %i (name, %i) VALUES (%s, %s)',
                self::$table,
                'we`ird',
                'backtick',
                'hit'
            )
        );

        $rows = Database_Guard::before_image(self::$table, ['we`ird' => 'hit']);

        $this->assertCount(1, $rows);
        $this->assertSame('backtick', $rows[0]['name']);
    }

    /** Schema introspection still reports the live columns through %i. */
    public function test_columns_reads_the_live_table_definition(): void
    {
        $this->assertSame(['id', 'name', 'val', 'we`ird'], Database_Guard::columns(self::$table));
    }

    /** And the primary key, which the recoverable promise depends on. */
    public function test_primary_key_reads_the_live_table_definition(): void
    {
        $this->assertSame(['id'], Database_Guard::primary_key(self::$table));
    }

    /**
     * Snapshot_Store::prune() binds its own table name with %i; the rows it
     * keeps and deletes must be unchanged by that.
     */
    public function test_prune_keeps_the_requested_number_of_snapshots(): void
    {
        global $wpdb;
        Snapshot_Store::install();
        $wpdb->query('DELETE FROM ' . Snapshot_Store::table_name());

        $snapshot = ['object_type' => 'post', 'object_id' => 1, 'data' => ['post' => null, 'meta' => []]];
        for ($i = 0; $i < 5; $i++) {
            Snapshot_Store::save('op-' . $i, 'sess', $snapshot, 'delete-post', str_repeat('a', 64));
        }

        $this->assertSame(3, Snapshot_Store::prune(2));

        $this->assertCount(2, Snapshot_Store::recent(50));
        $this->assertNull(Snapshot_Store::get_by_operation('op-0'));
        $this->assertNotNull(Snapshot_Store::get_by_operation('op-4'));
    }
}
