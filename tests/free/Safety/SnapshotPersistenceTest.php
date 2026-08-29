<?php

namespace WPMCP\Tests\Free\Safety;

use WPMCP\Safety\Mutation_Failed;
use WPMCP\Safety\Safe_Mutation;
use WPMCP\Safety\Snapshot;
use WPMCP\Safety\Snapshot_Store;

/**
 * The undo point has to actually exist.
 *
 * Two independent defects met here and produced silent, unrecoverable data
 * loss on a real-world configuration:
 *
 *  1. Snapshot::serialize() gzips to raw binary. WordPress's SQLite
 *     integration — which WordPress Studio uses by default, and which core's
 *     own first-party SQLite support makes increasingly common — rejects
 *     that payload from $wpdb->insert() with "Processing the value for the
 *     following field failed: before_blob". Real MySQL's LONGBLOB accepts
 *     it, so the bug is invisible on the maintainer's machine and on CI.
 *
 *  2. Snapshot_Store::save() never checked the insert's return value and
 *     returned (int) $wpdb->insert_id, which is 0 on failure. Safe_Mutation
 *     took that 0 as success and ran the mutation anyway.
 *
 * Together: update-post returned a real-looking operation_id, the write
 * landed, the snapshot row was never written, list-operations came back
 * empty and rollback-operation answered {"restored": false} with no error.
 * The write succeeded and the undo silently did not exist — the exact
 * failure mode a plugin selling recoverability cannot have.
 *
 * Safe_Mutation's own comment already states the invariant these tests
 * enforce: the operation id "must never be advertised before it exists".
 */
class SnapshotPersistenceTest extends \WP_UnitTestCase
{
    private function sample_snapshot(): array
    {
        return [
            'object_type' => 'post',
            'object_id'   => 123,
            'data'        => [
                'post_title'   => "Quotes ' and \" and \\ backslash",
                'post_content' => "line\nbreak, NUL-ish \x01\x02, emoji 🎯, 100% done",
            ],
        ];
    }

    /**
     * The stored payload must survive a backend that only accepts text.
     * Asserting the round trip alone is not enough — that passes on MySQL
     * while still being unstorable on SQLite — so this pins the wire format
     * as ASCII-safe, which is the property that actually makes it portable.
     */
    public function test_serialized_snapshot_is_ascii_safe_for_a_text_column(): void
    {
        $blob = Snapshot::serialize($this->sample_snapshot());

        $this->assertSame(
            1,
            preg_match('#^[A-Za-z0-9+/]*={0,2}$#', $blob),
            'The serialized snapshot contains raw binary, which the SQLite integration refuses to store.'
        );
    }

    public function test_serialized_snapshot_round_trips(): void
    {
        $snapshot = $this->sample_snapshot();

        $this->assertSame($snapshot, Snapshot::unserialize(Snapshot::serialize($snapshot)));
    }

    /**
     * Rows written before this change hold raw gzip. They must keep
     * decoding, or shipping the fix would itself destroy every existing
     * undo point on every installed site.
     */
    public function test_legacy_raw_gzip_snapshots_still_decode(): void
    {
        $snapshot = $this->sample_snapshot();
        $legacy   = gzencode((string) wp_json_encode($snapshot));

        $this->assertSame($snapshot, Snapshot::unserialize($legacy));
    }

    /**
     * Force the snapshot INSERT to fail the way a backend rejecting the
     * payload would, without dropping the table: the suite runs each test
     * inside a transaction with CREATE TABLE rewritten to CREATE TEMPORARY
     * TABLE, so a DROP here removes the temporary shadow and the insert
     * quietly succeeds against the real table underneath. Rewriting the
     * statement through wpdb's own `query` filter fails the exact call we
     * care about and nothing else.
     *
     * @return callable Remove the filter.
     */
    private function break_snapshot_inserts(): callable
    {
        global $wpdb;

        $table  = Snapshot_Store::table_name();
        $filter = static function ($query) use ($table) {
            if (str_starts_with(strtoupper(ltrim((string) $query)), 'INSERT') && str_contains((string) $query, $table)) {
                return str_replace($table, $table . '_does_not_exist', (string) $query);
            }
            return $query;
        };

        add_filter('query', $filter);
        $suppress = $wpdb->suppress_errors(true);

        return static function () use ($filter, $suppress) {
            global $wpdb;
            remove_filter('query', $filter);
            $wpdb->suppress_errors($suppress);
        };
    }

    public function test_save_raises_when_the_row_cannot_be_written(): void
    {
        $restore = $this->break_snapshot_inserts();

        try {
            $this->expectException(Mutation_Failed::class);
            Snapshot_Store::save('op-1', 'session-1', $this->sample_snapshot(), 'update-post', 'hash');
        } finally {
            $restore();
        }
    }

    /**
     * The behavioural guarantee, stated the way a user experiences it: if the
     * undo point cannot be persisted, the change must not happen either.
     */
    public function test_safe_mutation_does_not_run_the_write_when_the_snapshot_cannot_be_saved(): void
    {
        $post_id = self::factory()->post->create(['post_title' => 'Original']);

        $restore      = $this->break_snapshot_inserts();
        $mutation_ran = false;

        try {
            Safe_Mutation::run(
                [
                    'object_type' => 'post',
                    'object_id'   => $post_id,
                    'session_id'  => 'session-1',
                    'tool_name'   => 'update-post',
                    'args'        => [],
                ],
                function () use (&$mutation_ran, $post_id) {
                    $mutation_ran = true;
                    wp_update_post(['ID' => $post_id, 'post_title' => 'Mutated']);
                    return true;
                }
            );
            $this->fail('Safe_Mutation reported success despite having no undo point.');
        } catch (Mutation_Failed $e) {
            // expected
        } finally {
            $restore();
        }

        $this->assertFalse($mutation_ran, 'The write ran even though its snapshot was never persisted.');
        $this->assertSame('Original', get_post($post_id)->post_title);
    }

    /**
     * A corrupt stored blob must be LOUD, not a polite no-op.
     *
     * Snapshot::unserialize() used to map an undecodable blob to [], and
     * Rollback_Service::restore_operation() then walked apply_snapshot()
     * finding nothing to do and still returned true - "restored" while
     * restoring nothing, the exact silent-failure mode this branch exists
     * to kill. A truncated or hand-edited row is rare; lying about it
     * cannot be.
     */
    public function test_a_corrupt_blob_throws_instead_of_decoding_to_nothing(): void
    {
        $this->expectException(\RuntimeException::class);
        Snapshot::unserialize('this is neither gzip nor base64-of-gzip');
    }

    public function test_restoring_an_operation_with_a_corrupt_blob_does_not_report_success(): void
    {
        global $wpdb;
        $operation_id = 'corrupt-blob-op';
        $wpdb->insert(Snapshot_Store::table_name(), [
            'operation_id' => $operation_id,
            'session_id'   => 'corrupt-blob-session',
            'ability'      => 'wpmcp/update-post',
            'object_type'  => 'post',
            'object_id'    => 1,
            'before_blob'  => 'truncated-garbage-that-never-was-a-snapshot',
            'created_at'   => gmdate('Y-m-d H:i:s'),
        ]);

        try {
            \WPMCP\Safety\Rollback_Service::restore_operation($operation_id);
            $this->fail('restore_operation returned instead of throwing on a corrupt snapshot row');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('snapshot', strtolower($e->getMessage()));
        }
    }

    public function test_a_genuinely_empty_json_snapshot_still_round_trips(): void
    {
        // [] is a legal serialization payload; only UNDECODABLE blobs throw.
        $this->assertSame([], Snapshot::unserialize(Snapshot::serialize([])));
    }
}
