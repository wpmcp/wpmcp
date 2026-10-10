<?php

// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps -- WP-style snake_case class name is intentional (matches the rest of WPMCP\Safety).
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WP-style snake_case method names are intentional (matches the rest of WPMCP\Safety).

namespace WPMCP\Safety;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Snapshots of the FluentCRM and MailPoet rows one CRM write touches (issue
 * #477), keyed "<kind>:<id>":
 *  - fluentcrm_contact:      the fc_subscribers row, its list and tag
 *                            memberships (fc_subscriber_pivot) and its
 *                            meta, custom field values included;
 *  - fluentcrm_contact_full: the same plus every row FluentCRM deletes with
 *                            a contact (notes, automation enrollments and
 *                            metrics, campaign emails and their click rows),
 *                            for a delete;
 *  - fluentcrm_list / _tag:  the fc_lists or fc_tags row and every
 *                            membership in it;
 *  - mailpoet_subscriber:    the mailpoet_subscribers row, its list
 *                            memberships, custom field values and tags;
 *  - mailpoet_list / _tag:   the mailpoet_segments or mailpoet_tags row and
 *                            every membership in it.
 * A kind's optional sets are left out while their table is missing.
 *
 * A create has no id when its snapshot is taken: it is keyed "<kind>:0" and
 * tracked() re-keys it on the id the plugin assigned. While a write runs, the
 * highest id of every table its kind covers is noted, and the rows that match
 * the kind's sets afterwards and lie above it are recorded as created
 * ('created_rows'), with the status every status-bearing row was left in
 * ('after'). The tables are always built from the site prefix and a fixed
 * allowlist of names, so a key can never reach any other table.
 *
 * The restore removes the created rows and puts every saved row back by id:
 * one that still exists is set back to its saved values, one that is gone is
 * re-inserted at its id. Rows the write neither saved nor created are left
 * alone. Consent is never restored over the person's own choice:
 *  - a row whose status changed since the write (a confirmed opt-in, an
 *    unsubscribe) keeps its current status, and a created row whose status
 *    changed since stays;
 *  - a contact now unsubscribed, bounced or otherwise suppressed is never
 *    set back to a status that would mail it again, even when the write
 *    itself made that change;
 *  - a created list or tag that has gained members since is left in place.
 * Each such row is reported in the restore's warning.
 *
 * Restoring takes the plugin's own permission: FluentCRM's contact manager
 * permission (fcrm_manage_contacts, which FluentCRM grants every
 * manage_options user), or MailPoet's mailpoet_manage_subscribers for a
 * subscriber and mailpoet_manage_segments for a list or tag.
 */
final class Crm_Rows_Snapshot
{
    public const TYPE = 'crm_rows';

    private const FLUENTCRM_LIST = 'FluentCrm\\App\\Models\\Lists';

    private const FLUENTCRM_TAG = 'FluentCrm\\App\\Models\\Tag';

    /**
     * kind => plugin and row sets. Each set is [table, key column, optional,
     * match column => value]. The first set is the primary row.
     */
    private const KINDS = [
        'fluentcrm_contact'      => [
            'plugin' => 'fluentcrm',
            'sets'   => [
                [ 'fc_subscribers', 'id' ],
                [ 'fc_subscriber_pivot', 'subscriber_id' ],
                [ 'fc_subscriber_meta', 'subscriber_id' ],
            ],
        ],
        'fluentcrm_contact_full' => [
            'plugin' => 'fluentcrm',
            'sets'   => [
                [ 'fc_subscribers', 'id' ],
                [ 'fc_subscriber_pivot', 'subscriber_id' ],
                [ 'fc_subscriber_meta', 'subscriber_id' ],
                [ 'fc_subscriber_notes', 'subscriber_id', true ],
                [ 'fc_funnel_subscribers', 'subscriber_id', true ],
                [ 'fc_funnel_metrics', 'subscriber_id', true ],
                [ 'fc_campaign_emails', 'subscriber_id', true ],
                [ 'fc_campaign_url_metrics', 'subscriber_id', true ],
            ],
        ],
        'fluentcrm_list'         => [
            'plugin' => 'fluentcrm',
            'sets'   => [
                [ 'fc_lists', 'id' ],
                [ 'fc_subscriber_pivot', 'object_id', false, [ 'object_type' => self::FLUENTCRM_LIST ] ],
            ],
        ],
        'fluentcrm_tag'          => [
            'plugin' => 'fluentcrm',
            'sets'   => [
                [ 'fc_tags', 'id' ],
                [ 'fc_subscriber_pivot', 'object_id', false, [ 'object_type' => self::FLUENTCRM_TAG ] ],
            ],
        ],
        'mailpoet_subscriber'    => [
            'plugin' => 'mailpoet',
            'sets'   => [
                [ 'mailpoet_subscribers', 'id' ],
                [ 'mailpoet_subscriber_segment', 'subscriber_id' ],
                [ 'mailpoet_subscriber_custom_field', 'subscriber_id' ],
                [ 'mailpoet_subscriber_tag', 'subscriber_id' ],
            ],
        ],
        'mailpoet_list'          => [
            'plugin' => 'mailpoet',
            'sets'   => [
                [ 'mailpoet_segments', 'id' ],
                [ 'mailpoet_subscriber_segment', 'segment_id' ],
            ],
        ],
        'mailpoet_tag'           => [
            'plugin' => 'mailpoet',
            'sets'   => [
                [ 'mailpoet_tags', 'id' ],
                [ 'mailpoet_subscriber_tag', 'tag_id' ],
            ],
        ],
    ];

    /**
     * Status-bearing tables: table => [status column, statuses that stop
     * mail, whether a restore may never lift them]. FluentCRM keeps one
     * status per contact, MailPoet one per subscriber and one per list
     * membership. A contact's own status is never lifted, even when the
     * write being undone set it; a membership the write itself left is put
     * back (the contact's own status still decides whether it is mailed).
     */
    private const STATUS = [
        'fc_subscribers'              => [ 'status', [ 'unsubscribed', 'bounced', 'complained', 'spammed' ], true ],
        'mailpoet_subscribers'        => [ 'status', [ 'unsubscribed', 'bounced', 'inactive' ], true ],
        'mailpoet_subscriber_segment' => [ 'status', [ 'unsubscribed' ], false ],
    ];

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

    /** The plugin a kind belongs to ('fluentcrm' or 'mailpoet'), or ''. */
    public static function plugin(string $kind): string
    {
        return (string) (self::KINDS[ $kind ]['plugin'] ?? '');
    }

    /** The statuses that stop mail for a status-bearing table. @return string[] */
    public static function suppressed(string $name): array
    {
        return self::STATUS[ $name ][1] ?? [];
    }

    /** The prefixed table for an allowlisted name. */
    public static function table(string $name): string
    {
        global $wpdb;

        if (! in_array($name, self::table_names(), true)) {
            throw new \InvalidArgumentException('Not a CRM table this snapshot type covers.');
        }
        return $wpdb->prefix . $name;
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
        $exists = [] !== $sets && self::tables_exist((string) $sets[0]['table']);

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
     * Run one write with what it created and the statuses it left recorded
     * into its snapshot. For a create, $id_of names the id the plugin
     * assigned, read from the write's result, and the snapshot is re-keyed on
     * it. The rows are recorded even when the write throws, so a half-done
     * write stays undoable.
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
     * Put the captured rows back. Any failure to read or write a table is a
     * Mutation_Failed. Returns a warning naming every row left as it is, or
     * null.
     */
    public static function restore(array $snapshot): ?string
    {
        $data = (array) ($snapshot['data'] ?? []);
        $kind = (string) ($data['kind'] ?? '');
        $id   = (int) ($data['id'] ?? 0);
        if (! isset(self::KINDS[ $kind ]) || $id <= 0 || empty($data['tables_exist'])) {
            return null;
        }
        self::authorize($kind);

        $sets = array_map(static fn ($set): array => (array) $set, (array) ($data['sets'] ?? []));
        if ([] === $sets || ! self::tables_exist(...array_unique(array_map(static fn (array $set): string => (string) $set['table'], $sets)))) {
            return null;
        }

        $after   = (array) ($data['after'] ?? []);
        $created = (array) ($data['created_rows'] ?? []);
        $kept    = [];

        // A created list or tag that has gained members since stays, and so
        // do the rows it created.
        $primary = (string) $sets[0]['table'];
        // A created contact whose status changed since (a confirmed opt-in,
        // an unsubscribe) stays whole.
        if (in_array($id, array_map('intval', (array) ($created[ $primary ] ?? [])), true)) {
            if ('contact' !== self::family($kind)) {
                $foreign = self::foreign_rows($sets, $created);
                if ($foreign > 0) {
                    return sprintf('The %s this operation created has %d member(s) added since and was left in place.', str_replace('_', ' ', $kind), $foreign);
                }
            }
            $now = self::rows_by_id($primary, [ $id ])[0] ?? null;
            if (null !== $now && null !== self::kept_status($primary, $now, null, $after)) {
                return sprintf('The contact this operation created has changed its subscription status since (now %s) and was left in place.', (string) $now[ self::STATUS[ $primary ][0] ]);
            }
        }

        foreach ($created as $name => $ids) {
            $name = (string) $name;
            if (! in_array($name, self::table_names(), true) || ! self::tables_exist($name)) {
                continue;
            }
            foreach (self::rows_by_id($name, array_map('intval', (array) $ids)) as $current) {
                if (null !== self::kept_status($name, $current, null, $after)) {
                    $kept[] = sprintf('%s #%d (status now %s)', $name, (int) $current['id'], (string) $current[ self::STATUS[ $name ][0] ]);
                    continue;
                }
                self::delete_row($name, (int) $current['id']);
            }
        }

        foreach ($sets as $set) {
            $name    = (string) $set['table'];
            $saved   = array_values(array_filter(array_map(static fn ($row): array => (array) $row, (array) ($set['rows'] ?? [])), static fn (array $row): bool => (int) ($row['id'] ?? 0) > 0));
            $current = array_column(self::rows_by_id($name, array_map(static fn (array $row): int => (int) $row['id'], $saved)), null, 'id');
            foreach ($saved as $row) {
                $row_id = (int) $row['id'];
                $now    = $current[ $row_id ] ?? $current[ (string) $row_id ] ?? null;
                if (null === $now) {
                    if (! self::insert_row($name, $row)) {
                        $kept[] = sprintf('%s #%d (could not be re-inserted: another row now holds its key)', $name, $row_id);
                    }
                    continue;
                }
                $keep = self::kept_status($name, (array) $now, $row, $after);
                if (null !== $keep) {
                    $column = self::STATUS[ $name ][0];
                    if ($keep !== (string) ($row[ $column ] ?? '')) {
                        $kept[] = sprintf('%s #%d (status kept as %s)', $name, $row_id, $keep);
                    }
                    $row[ $column ] = $keep;
                }
                self::update_row($name, $row_id, $row);
            }
        }

        self::flush(self::plugin($kind));

        return [] === $kept ? null : sprintf(
            'CRM rows left as they are, because restoring them would override a subscription choice: %s.',
            implode(', ', array_values(array_unique($kept)))
        );
    }

    /**
     * Tell the plugin its rows changed under it. MailPoet keeps loaded
     * entities in its Doctrine entity manager for the rest of the request.
     */
    public static function flush(string $plugin): void
    {
        if ('mailpoet' !== $plugin || ! class_exists('\\MailPoet\\DI\\ContainerWrapper') || ! class_exists('\\MailPoetVendor\\Doctrine\\ORM\\EntityManager')) {
            return;
        }
        try {
            \MailPoet\DI\ContainerWrapper::getInstance()->get(\MailPoetVendor\Doctrine\ORM\EntityManager::class)->clear();
        } catch (\Throwable $e) {
            // A container that cannot hand the entity manager out has none loaded to clear.
            unset($e);
        }
    }

    /**
     * Whether the current user holds the plugin's own permission to manage
     * what a kind covers.
     */
    public static function may_manage(string $kind): bool
    {
        if ('fluentcrm' === self::plugin($kind)) {
            if (class_exists('\\FluentCrm\\App\\Services\\PermissionManager')) {
                return (bool) \FluentCrm\App\Services\PermissionManager::currentUserCan('fcrm_manage_contacts');
            }
            return current_user_can('manage_options');
        }
        if ('mailpoet' === self::plugin($kind)) {
            return current_user_can('mailpoet_subscriber' === $kind ? 'mailpoet_manage_subscribers' : 'mailpoet_manage_segments');
        }
        return false;
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /** 'contact' or 'group' (a list or tag). */
    private static function family(string $kind): string
    {
        return in_array($kind, [ 'fluentcrm_contact', 'fluentcrm_contact_full', 'mailpoet_subscriber' ], true) ? 'contact' : 'group';
    }

    /** @return string[] every table any kind covers, unprefixed. */
    private static function table_names(): array
    {
        $names = [];
        foreach (self::KINDS as $kind) {
            foreach ($kind['sets'] as $set) {
                $names[] = $set[0];
            }
        }
        return array_values(array_unique($names));
    }

    /**
     * A kind's sets for one id, less the optional ones whose table is missing.
     *
     * @return array<int, array{table: string, column: string, id: int, match: array<string, string>}>
     */
    private static function sets(string $kind, int $id): array
    {
        $out = [];
        foreach (self::KINDS[ $kind ]['sets'] as $set) {
            if (! empty($set[2]) && ! self::tables_exist($set[0])) {
                continue;
            }
            $out[] = [ 'table' => $set[0], 'column' => $set[1], 'id' => $id, 'match' => (array) ($set[3] ?? []) ];
        }
        return $out;
    }

    /** @throws Mutation_Failed when the current user may not manage what the kind covers. */
    private static function authorize(string $kind): void
    {
        if (! self::may_manage($kind)) {
            throw new Mutation_Failed(
                'fluentcrm' === self::plugin($kind)
                    ? 'Rollback refused: restoring FluentCRM data requires FluentCRM\'s contact management permission.'
                    : 'Rollback refused: restoring MailPoet data requires MailPoet\'s subscriber or list management capability.'
            );
        }
    }

    /**
     * The status a row must keep instead of the one the restore would put
     * back, or null when the restore may go ahead. $saved is null for a row
     * the write created, which the restore would delete.
     *
     * @param array<string, mixed>      $current
     * @param array<string, mixed>|null $saved
     * @param array<string, mixed>      $after table => [row id => status the write left]
     */
    private static function kept_status(string $name, array $current, ?array $saved, array $after): ?string
    {
        if (! isset(self::STATUS[ $name ])) {
            return null;
        }
        [$column, $suppressed, $never_back] = self::STATUS[ $name ];
        $now  = (string) ($current[ $column ] ?? '');
        $left = ((array) ($after[ $name ] ?? []))[ (int) ($current['id'] ?? 0) ] ?? null;

        // The person changed it since the write.
        if (null !== $left && (string) $left !== $now) {
            return $now;
        }
        if (null === $saved || ! $never_back) {
            return null;
        }
        // Never back to a status that mails someone who opted out or bounced.
        $back = (string) ($saved[ $column ] ?? '');
        if (in_array($now, $suppressed, true) && ! in_array($back, $suppressed, true)) {
            return $now;
        }
        return null;
    }

    /**
     * Rows that match a created group's membership sets but were neither
     * saved nor created by the write.
     *
     * @param array<int, array<string, mixed>> $sets
     * @param array<string, mixed>             $created
     */
    private static function foreign_rows(array $sets, array $created): int
    {
        $count = 0;
        foreach (array_slice($sets, 1) as $set) {
            $known = array_map('intval', (array) ($created[ (string) $set['table'] ] ?? []));
            foreach (self::rows($set) as $row) {
                if (! in_array((int) $row['id'], $known, true)) {
                    ++$count;
                }
            }
        }
        return $count;
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
        foreach (self::sets($kind, 0) as $set) {
            $name = (string) $set['table'];
            if (! isset($marks[ $name ]) && self::tables_exist($name)) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table, read live around a write.
                $marks[ $name ] = (int) $wpdb->get_var($wpdb->prepare('SELECT COALESCE(MAX(id), 0) FROM %i', self::table($name)));
            }
        }
        return $marks;
    }

    /**
     * Record into the snapshot the rows a write created, the status it left
     * every status-bearing row in, and for a create the id it was given.
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
                $sets[ $i ]['rows'] = [];
            }
            $data['id']            = $id;
            $data['sets']          = $sets;
            $snapshot['object_id'] = self::key($kind, $id);
        }

        $created = [];
        $after   = [];
        foreach ((array) $data['sets'] as $set) {
            $set  = (array) $set;
            $name = (string) $set['table'];
            $mark = $marks[ $name ] ?? PHP_INT_MAX;
            foreach (self::rows($set) as $current) {
                $row_id = (int) $current['id'];
                if ($row_id > $mark) {
                    $created[ $name ][] = $row_id;
                }
                if (isset(self::STATUS[ $name ])) {
                    $after[ $name ][ $row_id ] = (string) ($current[ self::STATUS[ $name ][0] ] ?? '');
                }
            }
        }
        foreach ($created as $name => $ids) {
            $created[ $name ] = array_values(array_unique($ids));
        }

        $data['created_rows'] = $created;
        $data['after']        = $after;
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
        if (! in_array($column, [ 'id', 'subscriber_id', 'object_id', 'segment_id', 'tag_id' ], true)) {
            throw new \InvalidArgumentException('Not a CRM key column.');
        }
        $sql  = '%i = %d';
        $args = [ $column, (int) ($set['id'] ?? 0) ];
        foreach ((array) ($set['match'] ?? []) as $name => $value) {
            if ('object_type' !== $name) {
                throw new \InvalidArgumentException('Not a CRM filter column.');
            }
            $sql   .= ' AND %i = %s';
            $args[] = (string) $name;
            $args[] = (string) $value;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- a snapshot must read the live rows of the plugin's own table; $sql holds only placeholders over an allowlisted table and column.
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE ' . $sql . ' ORDER BY id ASC', self::table((string) $set['table']), ...$args), ARRAY_A);
        if (! is_array($rows)) {
            throw new Mutation_Failed('Could not read the CRM rows of ' . esc_html((string) $set['table']) . '.');
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
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the plugin's own table; $in holds only %d placeholders.
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM %i WHERE id IN ($in) ORDER BY id ASC", self::table($name), ...$ids), ARRAY_A);
        return array_values((array) $rows);
    }

    private static function delete_row(string $name, int $id): void
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table; a row the write created goes again.
        if (false === $wpdb->delete(self::table($name), [ 'id' => $id ], [ '%d' ])) {
            throw new Mutation_Failed('Could not remove a CRM row this operation created: ' . esc_html($wpdb->last_error));
        }
    }

    /** Insert a saved row verbatim at its own id; false when a unique key refuses it. */
    private static function insert_row(string $name, array $row): bool
    {
        global $wpdb;

        $suppress = $wpdb->suppress_errors(true);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- the plugin's own table; rows restored verbatim.
        $ok = $wpdb->insert(self::table($name), $row);
        $wpdb->suppress_errors($suppress);
        if (false !== $ok) {
            return true;
        }
        if (false !== stripos((string) $wpdb->last_error, 'Duplicate')) {
            return false;
        }
        throw new Mutation_Failed('Could not restore a CRM row: ' . esc_html($wpdb->last_error));
    }

    private static function update_row(string $name, int $id, array $row): void
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table; a saved row set back verbatim.
        if (false === $wpdb->update(self::table($name), $row, [ 'id' => $id ])) {
            throw new Mutation_Failed('Could not restore a CRM row: ' . esc_html($wpdb->last_error));
        }
    }
}
