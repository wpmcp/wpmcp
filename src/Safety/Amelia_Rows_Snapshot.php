<?php

// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps -- WP-style snake_case class name is intentional (matches the rest of WPMCP\Safety).
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WP-style snake_case method names are intentional (matches the rest of WPMCP\Safety).

namespace WPMCP\Safety;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Snapshots of the Amelia rows one Amelia write touches (issue #499), keyed
 * "<kind>:<id>":
 *  - amelia_category:    the amelia_categories row;
 *  - amelia_service:     the amelia_services row and its employee
 *                        assignments (amelia_providers_to_services);
 *  - amelia_customer:    the amelia_users row of the customer;
 *  - amelia_appointment: the amelia_appointments row, its customer bookings
 *                        and their payments.
 *
 * A create has no id when its snapshot is taken: it is keyed "<kind>:0" and
 * tracked() re-keys it on the id Amelia assigned. While a write runs, the
 * highest id of every table its kind covers is noted, and the rows that
 * match the kind's sets afterwards and lie above it are recorded as created
 * ('created_rows'). The tables are always built from the site prefix,
 * Amelia's own "amelia_" prefix and a fixed allowlist of names, so a key can
 * never reach any other table.
 *
 * The restore removes the created rows and puts every saved row back by id,
 * verbatim, straight into Amelia's tables: Amelia's notifications are never
 * sent again by an undo. It is refused, before anything changes, while later
 * bookings depend on what it would undo (refusal()):
 *  - a created category that services were added to since, a created
 *    service that has been booked since, a created customer who has been
 *    booked since, a created appointment that other customers joined since;
 *  - an appointment whose saved time and status would overlap another
 *    approved or pending appointment of the same employee booked since
 *    (an undone cancel or reschedule would double-book the slot).
 *
 * Restoring takes Amelia's own write capability for what the kind covers
 * (amelia_write_services, amelia_write_customers,
 * amelia_write_appointments), which Amelia grants administrators.
 */
final class Amelia_Rows_Snapshot
{
    public const TYPE = 'amelia_rows';

    /**
     * kind => [capability, sets, dependents]. Each set is [table, key
     * column] or [table, key column, via table, via column] for rows keyed
     * on a child of the primary row (payments of the appointment's
     * bookings). The first set is the primary row. Dependents are [table,
     * column] holding rows that point at a created primary row.
     */
    private const KINDS = [
        'amelia_category'    => [
            'capability' => 'amelia_write_services',
            'sets'       => [
                [ 'categories', 'id' ],
            ],
            'dependents' => [ [ 'services', 'categoryId' ] ],
        ],
        'amelia_service'     => [
            'capability' => 'amelia_write_services',
            'sets'       => [
                [ 'services', 'id' ],
                [ 'providers_to_services', 'serviceId' ],
            ],
            'dependents' => [ [ 'appointments', 'serviceId' ] ],
        ],
        'amelia_customer'    => [
            'capability' => 'amelia_write_customers',
            'sets'       => [
                [ 'users', 'id' ],
            ],
            'dependents' => [ [ 'customer_bookings', 'customerId' ] ],
        ],
        'amelia_appointment' => [
            'capability' => 'amelia_write_appointments',
            'sets'       => [
                [ 'appointments', 'id' ],
                [ 'customer_bookings', 'appointmentId' ],
                [ 'payments', 'customerBookingId', 'customer_bookings', 'appointmentId' ],
            ],
            'dependents' => [ [ 'customer_bookings', 'appointmentId' ] ],
        ],
    ];

    /** Appointment and booking statuses that hold a slot. */
    private const ACTIVE = [ 'approved', 'pending' ];

    /** The snapshot key for one object; 0 for a create whose id is not known yet. */
    public static function key(string $kind, int $id): string
    {
        return $kind . ':' . max(0, $id);
    }

    /** @return string[] every kind this type covers. */
    public static function kinds(): array
    {
        return array_keys(self::KINDS);
    }

    /** Amelia's capability for restoring a kind, or ''. */
    public static function capability(string $kind): string
    {
        return (string) (self::KINDS[ $kind ]['capability'] ?? '');
    }

    /** The prefixed table for an allowlisted name. */
    public static function table(string $name): string
    {
        global $wpdb;

        if (! in_array($name, self::table_names(), true)) {
            throw new \InvalidArgumentException('Not an Amelia table this snapshot type covers.');
        }
        return $wpdb->prefix . 'amelia_' . $name;
    }

    /** Whether every named table exists (SHOW COLUMNS, which also sees temporary tables). */
    public static function tables_exist(string ...$names): bool
    {
        global $wpdb;

        foreach ($names as $name) {
            $suppress = $wpdb->suppress_errors(true);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- probing a third-party table's live shape; nothing to cache.
            $columns = $wpdb->get_col($wpdb->prepare('SHOW COLUMNS FROM %i', self::table($name)));
            $wpdb->suppress_errors($suppress);
            if ([] === (array) $columns) {
                return false;
            }
        }
        return true;
    }

    /** @return array{kind: string, id: int} */
    public static function parse(string $key): array
    {
        [$kind, $id] = array_pad(explode(':', $key, 2), 2, '');
        return [ 'kind' => (string) $kind, 'id' => max(0, (int) $id) ];
    }

    public static function capture(string $key): array
    {
        $parsed = self::parse($key);
        $kind   = isset(self::KINDS[ $parsed['kind'] ]) ? $parsed['kind'] : '';
        $sets   = '' === $kind ? [] : self::sets($kind, $parsed['id']);
        $exists = [] !== $sets && self::tables_exist(...self::kind_tables($kind));

        foreach ($sets as $i => $set) {
            $sets[ $i ]['rows'] = $exists && $parsed['id'] > 0 ? self::rows($set) : [];
        }

        return [
            'object_type' => self::TYPE,
            'object_id'   => $key,
            'data'        => [
                'kind'         => $kind,
                'id'           => $parsed['id'],
                'tables_exist' => $exists,
                'sets'         => $exists ? $sets : [],
            ],
        ];
    }

    /**
     * Run one write with what it created recorded into its snapshot. For a
     * create, $id_of names the id Amelia assigned, read from the write's
     * result, and the snapshot is re-keyed on it. The rows are recorded even
     * when the write throws, so a half-done write stays undoable.
     *
     * @param callable(): mixed            $write
     * @param (callable(mixed): int)|null $id_of
     * @return mixed the write's result
     */
    public static function tracked(string $operation_id, callable $write, ?callable $id_of = null)
    {
        $row  = '' === $operation_id ? null : Snapshot_Store::get_by_operation($operation_id);
        $data = (array) ($row['snapshot']['data'] ?? []);
        $kind = (string) ($data['kind'] ?? '');
        if (null === $row || self::TYPE !== ($row['snapshot']['object_type'] ?? '') || ! isset(self::KINDS[ $kind ]) || empty($data['tables_exist'])) {
            return $write();
        }

        $marks  = self::watermarks($kind);
        $result = null;
        try {
            $result = $write();
        } finally {
            $id = (int) ($data['id'] ?? 0);
            if (0 === $id && null !== $id_of && null !== $result) {
                $id = (int) $id_of($result);
            }
            if ($id > 0) {
                self::record($operation_id, $kind, $id, $marks);
            }
        }
        return $result;
    }

    /**
     * Whether a write left every row its snapshot covers as it was: nothing
     * created and every saved row unchanged. A write Amelia refused this way
     * has nothing to undo.
     */
    public static function unchanged(string $operation_id): bool
    {
        $row  = Snapshot_Store::get_by_operation($operation_id);
        $data = (array) ($row['snapshot']['data'] ?? []);
        if (null === $row || ! isset(self::KINDS[ (string) ($data['kind'] ?? '') ]) || empty($data['tables_exist'])) {
            return true;
        }
        foreach ((array) ($data['created_rows'] ?? []) as $ids) {
            if ([] !== (array) $ids) {
                return false;
            }
        }
        if ((int) ($data['id'] ?? 0) <= 0) {
            return true;
        }
        foreach ((array) ($data['sets'] ?? []) as $set) {
            $set   = (array) $set;
            $saved = array_values(array_map(static fn ($r): array => (array) $r, (array) ($set['rows'] ?? [])));
            if (wp_json_encode(self::rows($set)) !== wp_json_encode($saved)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Why restoring this snapshot is refused, or null when it may go ahead:
     * later bookings depend on what it would undo.
     */
    public static function refusal(array $snapshot): ?string
    {
        if (self::TYPE !== ($snapshot['object_type'] ?? null)) {
            return null;
        }
        $data = (array) ($snapshot['data'] ?? []);
        $kind = (string) ($data['kind'] ?? '');
        $id   = (int) ($data['id'] ?? 0);
        if (! isset(self::KINDS[ $kind ]) || $id <= 0 || empty($data['tables_exist']) || ! self::tables_exist(...self::kind_tables($kind))) {
            return null;
        }
        $created = (array) ($data['created_rows'] ?? []);
        $primary = (string) self::KINDS[ $kind ]['sets'][0][0];

        if (in_array($id, array_map('intval', (array) ($created[ $primary ] ?? [])), true)) {
            foreach (self::KINDS[ $kind ]['dependents'] as [$table, $column]) {
                $known   = array_map('intval', (array) ($created[ $table ] ?? []));
                $foreign = array_filter(
                    self::rows([ 'table' => $table, 'column' => $column, 'id' => $id ]),
                    static fn (array $row): bool => ! in_array((int) $row['id'], $known, true)
                );
                if ([] !== $foreign) {
                    return sprintf(
                        'Undo refused: the %s this operation created now has %d later %s row(s) depending on it (%s). Cancel or move those first.',
                        str_replace('amelia_', '', $kind),
                        count($foreign),
                        str_replace('_', ' ', $table),
                        implode(', ', array_map(static fn (array $row): string => '#' . $row['id'], array_slice(array_values($foreign), 0, 10)))
                    );
                }
            }
            return null;
        }

        if ('amelia_appointment' === $kind) {
            $saved = (array) (((array) ($data['sets'][0]['rows'] ?? []))[0] ?? []);
            if ([] !== $saved && in_array((string) ($saved['status'] ?? ''), self::ACTIVE, true)) {
                $clash = self::overlapping((int) $saved['providerId'], (string) $saved['bookingStart'], (string) $saved['bookingEnd'], $id);
                if ([] !== $clash) {
                    return sprintf(
                        'Undo refused: putting appointment #%d back at %s would overlap appointment(s) booked since for the same employee (%s). Move or cancel those first.',
                        $id,
                        (string) $saved['bookingStart'] . ' UTC',
                        implode(', ', array_map(static fn (int $other): string => '#' . $other, $clash))
                    );
                }
            }
        }
        return null;
    }

    /**
     * Put the captured rows back. Any failure to read or write a table is a
     * Mutation_Failed. Rows go back verbatim, so no Amelia notification runs.
     */
    public static function restore(array $snapshot): ?string
    {
        $data = (array) ($snapshot['data'] ?? []);
        $kind = (string) ($data['kind'] ?? '');
        $id   = (int) ($data['id'] ?? 0);
        if (! isset(self::KINDS[ $kind ]) || $id <= 0 || empty($data['tables_exist'])) {
            return null;
        }
        if (! current_user_can(self::capability($kind))) {
            throw new Mutation_Failed('Rollback refused: restoring Amelia data requires Amelia\'s own write capability (' . esc_html(self::capability($kind)) . ').');
        }
        if (! self::tables_exist(...self::kind_tables($kind))) {
            return null;
        }
        $refused = self::refusal($snapshot);
        if (null !== $refused) {
            throw new Mutation_Failed(esc_html($refused));
        }

        $kept    = [];
        $created = (array) ($data['created_rows'] ?? []);
        foreach ($created as $name => $ids) {
            $name = (string) $name;
            if (! in_array($name, self::table_names(), true)) {
                continue;
            }
            foreach (array_map('intval', (array) $ids) as $row_id) {
                self::delete_row($name, $row_id);
            }
        }

        foreach ((array) ($data['sets'] ?? []) as $set) {
            $set     = (array) $set;
            $name    = (string) $set['table'];
            $saved   = array_values(array_filter(array_map(static fn ($row): array => (array) $row, (array) ($set['rows'] ?? [])), static fn (array $row): bool => (int) ($row['id'] ?? 0) > 0));
            $current = array_column(self::rows_by_id($name, array_map(static fn (array $row): int => (int) $row['id'], $saved)), null, 'id');
            foreach ($saved as $row) {
                $row_id = (int) $row['id'];
                if (isset($current[ $row_id ]) || isset($current[ (string) $row_id ])) {
                    self::update_row($name, $row_id, $row);
                    continue;
                }
                if (! self::insert_row($name, $row)) {
                    $kept[] = sprintf('%s #%d (another row now holds its unique key)', $name, $row_id);
                }
            }
        }

        return [] === $kept ? null : sprintf('Amelia rows that could not be put back: %s.', implode(', ', $kept));
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /** @return string[] every table any kind covers, unprefixed. */
    private static function table_names(): array
    {
        $names = [];
        foreach (self::KINDS as $kind) {
            foreach ($kind['sets'] as $set) {
                $names[] = $set[0];
                if (isset($set[2])) {
                    $names[] = $set[2];
                }
            }
            foreach ($kind['dependents'] as $dependent) {
                $names[] = $dependent[0];
            }
        }
        return array_values(array_unique($names));
    }

    /** @return string[] the tables one kind reads. */
    private static function kind_tables(string $kind): array
    {
        $names = [];
        foreach (self::KINDS[ $kind ]['sets'] as $set) {
            $names[] = $set[0];
        }
        foreach (self::KINDS[ $kind ]['dependents'] as $dependent) {
            $names[] = $dependent[0];
        }
        return array_values(array_unique($names));
    }

    /**
     * A kind's sets for one id.
     *
     * @return array<int, array{table: string, column: string, id: int, via?: array{0: string, 1: string}}>
     */
    private static function sets(string $kind, int $id): array
    {
        $out = [];
        foreach (self::KINDS[ $kind ]['sets'] as $set) {
            $entry = [ 'table' => $set[0], 'column' => $set[1], 'id' => $id ];
            if (isset($set[2])) {
                $entry['via'] = [ $set[2], $set[3] ];
            }
            $out[] = $entry;
        }
        return $out;
    }

    /**
     * Other approved or pending appointments of one employee that overlap a
     * UTC time range.
     *
     * @return int[]
     */
    private static function overlapping(int $provider_id, string $start, string $end, int $except): array
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Amelia's own table, read live before an undo.
        $ids = $wpdb->get_col($wpdb->prepare(
            'SELECT id FROM %i WHERE providerId = %d AND id <> %d AND status IN (%s, %s) AND bookingStart < %s AND bookingEnd > %s ORDER BY id ASC',
            self::table('appointments'),
            $provider_id,
            $except,
            self::ACTIVE[0],
            self::ACTIVE[1],
            $end,
            $start
        ));
        return array_map('intval', (array) $ids);
    }

    /**
     * The highest id in every table a kind covers, just before a write.
     *
     * @return array<string, int>
     */
    private static function watermarks(string $kind): array
    {
        global $wpdb;

        $marks = [];
        foreach (self::KINDS[ $kind ]['sets'] as $set) {
            $name = (string) $set[0];
            if (! isset($marks[ $name ])) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Amelia's own table, read live around a write.
                $marks[ $name ] = (int) $wpdb->get_var($wpdb->prepare('SELECT COALESCE(MAX(id), 0) FROM %i', self::table($name)));
            }
        }
        return $marks;
    }

    /**
     * Record into the snapshot the rows a write created, and for a create
     * the id it was given.
     *
     * @param array<string, int> $marks
     */
    private static function record(string $operation_id, string $kind, int $id, array $marks): void
    {
        $row = Snapshot_Store::get_by_operation($operation_id);
        if (null === $row) {
            return;
        }
        $snapshot = (array) $row['snapshot'];
        $data     = (array) ($snapshot['data'] ?? []);

        if ((int) ($data['id'] ?? 0) !== $id) {
            $sets = self::sets($kind, $id);
            foreach ($sets as $i => $set) {
                // An existing row the write merged into (a booking added to
                // an appointment already at that time) is saved as it is
                // now, less what the write created, which the restore
                // removes.
                $sets[ $i ]['rows'] = array_values(array_filter(
                    self::rows($set),
                    static fn (array $current): bool => (int) $current['id'] <= ($marks[ (string) $set['table'] ] ?? PHP_INT_MAX)
                ));
            }
            $data['id']            = $id;
            $data['sets']          = $sets;
            $snapshot['object_id'] = self::key($kind, $id);
        }

        $created = [];
        foreach ((array) $data['sets'] as $set) {
            $set  = (array) $set;
            $name = (string) $set['table'];
            $mark = $marks[ $name ] ?? PHP_INT_MAX;
            foreach (self::rows($set) as $current) {
                if ((int) $current['id'] > $mark) {
                    $created[ $name ][] = (int) $current['id'];
                }
            }
        }
        foreach ($created as $name => $ids) {
            $created[ $name ] = array_values(array_unique($ids));
        }

        $data['created_rows'] = $created;
        $snapshot['data']     = $data;
        Snapshot_Store::update_snapshot($operation_id, $snapshot);
    }

    /**
     * The rows one set matches, oldest id first, read live.
     *
     * @param array<string, mixed> $set
     * @return array<int, array<string, string|null>>
     */
    private static function rows(array $set): array
    {
        global $wpdb;

        $column = (string) ($set['column'] ?? '');
        if (! in_array($column, [ 'id', 'serviceId', 'categoryId', 'customerId', 'appointmentId', 'customerBookingId' ], true)) {
            throw new \InvalidArgumentException('Not an Amelia key column.');
        }
        $table = self::table((string) $set['table']);
        $id    = (int) ($set['id'] ?? 0);
        if (isset($set['via'])) {
            [$via_table, $via_column] = array_map('strval', (array) $set['via']);
            if ('appointmentId' !== $via_column) {
                throw new \InvalidArgumentException('Not an Amelia key column.');
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a snapshot must read the live rows of Amelia's own tables; allowlisted tables and columns only.
            $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE %i IN (SELECT id FROM %i WHERE %i = %d) ORDER BY id ASC', $table, $column, self::table($via_table), $via_column, $id), ARRAY_A);
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a snapshot must read the live rows of Amelia's own tables; allowlisted tables and columns only.
            $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE %i = %d ORDER BY id ASC', $table, $column, $id), ARRAY_A);
        }
        if (! is_array($rows)) {
            throw new Mutation_Failed('Could not read the Amelia rows of ' . esc_html((string) $set['table']) . '.');
        }
        return array_values($rows);
    }

    /**
     * Rows of one table by id.
     *
     * @param int[] $ids
     * @return array<int, array<string, string|null>>
     */
    private static function rows_by_id(string $name, array $ids): array
    {
        global $wpdb;

        $ids = array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
        if ([] === $ids) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '%d'));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Amelia's own table; $in holds only %d placeholders.
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM %i WHERE id IN ($in) ORDER BY id ASC", self::table($name), ...$ids), ARRAY_A);
        return array_values((array) $rows);
    }

    private static function delete_row(string $name, int $id): void
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Amelia's own table; a row the write created goes again.
        if (false === $wpdb->delete(self::table($name), [ 'id' => $id ], [ '%d' ])) {
            throw new Mutation_Failed('Could not remove an Amelia row this operation created: ' . esc_html($wpdb->last_error));
        }
    }

    /** Insert a saved row verbatim at its own id; false when a unique key refuses it. */
    private static function insert_row(string $name, array $row): bool
    {
        global $wpdb;

        $suppress = $wpdb->suppress_errors(true);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Amelia's own table; rows restored verbatim.
        $ok = $wpdb->insert(self::table($name), $row);
        $wpdb->suppress_errors($suppress);
        if (false !== $ok) {
            return true;
        }
        if (false !== stripos((string) $wpdb->last_error, 'Duplicate')) {
            return false;
        }
        throw new Mutation_Failed('Could not restore an Amelia row: ' . esc_html($wpdb->last_error));
    }

    private static function update_row(string $name, int $id, array $row): void
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Amelia's own table; a saved row set back verbatim.
        if (false === $wpdb->update(self::table($name), $row, [ 'id' => $id ])) {
            throw new Mutation_Failed('Could not restore an Amelia row: ' . esc_html($wpdb->last_error));
        }
    }
}
