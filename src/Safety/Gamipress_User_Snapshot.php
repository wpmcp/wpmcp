<?php

// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps -- WP-style snake_case class name is intentional (matches the rest of WPMCP\Safety).
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WP-style snake_case method names are intentional (matches the rest of WPMCP\Safety).

namespace WPMCP\Safety;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Snapshot of one user's GamiPress state (issue #833), taken before a points,
 * achievement or rank write. The object id is the user id.
 *
 * GamiPress keeps a user's state in three places, and one write can touch
 * all of them: awarding points can unlock points-based achievements, which
 * award more points and can move the user up a rank.
 *  - user meta: every key GamiPress writes for a user holds "_gamipress_"
 *    (the balance _gamipress_<type>_points, the last change, the awarded and
 *    deducted totals, the rank _gamipress_<type>_rank and its earned time);
 *    on multisite GamiPress stores them as user options, whose keys carry
 *    the blog prefix in front of the same name, so the match is on the
 *    substring;
 *  - the user earnings table (gamipress_user_earnings and its _meta table):
 *    one row per achievement, step, rank or points award earned;
 *  - the logs table (gamipress_logs and its _meta table): one row per event,
 *    only ever inserted by these writes.
 *
 * The image keeps every matching meta row and every earnings row (with its
 * meta) of the user verbatim, plus the highest log id there was. The restore
 * puts the meta and earnings back exactly (deleting what the write added and
 * re-inserting what it removed, ids kept) and deletes the user's log rows
 * newer than that id, with their meta. Mail GamiPress sent is not undone.
 *
 * The tables are the site's own, or the network's when GamiPress runs
 * network wide (its gamipress_is_network_wide_active()), rebuilt from the
 * prefix and these fixed names only. No GamiPress class is named, so an
 * undo recorded before a downgrade still restores. Restoring takes
 * manage_options, the capability GamiPress's own manager screens default
 * to, and the one the writes take unless the site changed it.
 */
final class Gamipress_User_Snapshot
{
    public const TYPE = 'gamipress_user';

    public const META_LIKE = '_gamipress_';

    /** The tables, unprefixed, with their primary key and the key their meta table points with. */
    private const EARNINGS = 'gamipress_user_earnings';

    private const EARNINGS_KEY = 'user_earning_id';

    private const LOGS = 'gamipress_logs';

    private const LOGS_KEY = 'log_id';

    public static function capability(): string
    {
        return 'manage_options';
    }

    /** Whether GamiPress runs network wide, so its tables carry the network prefix. */
    public static function network(): bool
    {
        return is_multisite() && function_exists('gamipress_is_network_wide_active') && (bool) gamipress_is_network_wide_active();
    }

    /** One of the four GamiPress tables by its unprefixed name. */
    public static function table(string $name, ?bool $network = null): string
    {
        global $wpdb;
        $network = $network ?? self::network();
        return ($network ? $wpdb->base_prefix : $wpdb->prefix) . $name;
    }

    public static function capture(string $key): array
    {
        $user    = max(0, (int) $key);
        $network = self::network();

        return [
            'object_type' => self::TYPE,
            'object_id'   => (string) $user,
            'data'        => [
                'user_id'  => $user,
                'network'  => $network,
                'meta'     => self::meta_rows($user),
                'earnings' => self::earning_rows($user, $network),
                'max_log'  => self::max_log($network),
            ],
        ];
    }

    /** @return array<int, array{meta_key: string, meta_value: string|null}> */
    private static function meta_rows(int $user): array
    {
        global $wpdb;
        if ($user <= 0) {
            return [];
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a snapshot must read the live rows, values verbatim.
        $rows = $wpdb->get_results($wpdb->prepare('SELECT meta_key, meta_value FROM %i WHERE user_id = %d AND meta_key LIKE %s ORDER BY umeta_id ASC', $wpdb->usermeta, $user, '%' . $wpdb->esc_like(self::META_LIKE) . '%'), ARRAY_A);
        return array_values((array) $rows);
    }

    private static function exists(string $table): bool
    {
        return Plugin_Table_Rows_Snapshot::table_exists($table);
    }

    /** @return array<int, array{row: array<string, string|null>, meta: array<int, array<string, string|null>>}> */
    private static function earning_rows(int $user, bool $network): array
    {
        global $wpdb;
        $table = self::table(self::EARNINGS, $network);
        if ($user <= 0 || ! self::exists($table)) {
            return [];
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a snapshot must read the live rows of a third-party table.
        $rows  = (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE user_id = %d ORDER BY %i ASC', $table, $user, self::EARNINGS_KEY), ARRAY_A);
        $meta  = self::table(self::EARNINGS . '_meta', $network);
        $has   = self::exists($meta);
        $out   = [];
        foreach ($rows as $row) {
            $id    = (int) $row[ self::EARNINGS_KEY ];
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- as above, the earning's own meta rows.
            $metas = $has ? (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE %i = %d ORDER BY meta_id ASC', $meta, self::EARNINGS_KEY, $id), ARRAY_A) : [];
            $out[] = [ 'row' => $row, 'meta' => array_values($metas) ];
        }
        return $out;
    }

    /** The highest log id there is, or -1 when the logs table does not exist. */
    private static function max_log(bool $network): int
    {
        global $wpdb;
        $table = self::table(self::LOGS, $network);
        if (! self::exists($table)) {
            return -1;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a snapshot must read the live high-water mark.
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COALESCE(MAX(%i), 0) FROM %i', self::LOGS_KEY, $table));
    }

    /**
     * Put the user's GamiPress state back. Returns a warning when part of it
     * could not be checked (a table GamiPress no longer has), null otherwise;
     * a failed write is a Mutation_Failed.
     */
    public static function restore(array $snapshot): ?string
    {
        $data = (array) ($snapshot['data'] ?? []);
        $user = (int) ($data['user_id'] ?? 0);
        if ($user <= 0) {
            return null;
        }
        if (! current_user_can(self::capability())) {
            throw new Mutation_Failed('Rollback refused: restoring a user\'s GamiPress points, achievements and ranks requires the ' . esc_html(self::capability()) . ' capability.');
        }
        $network = ! empty($data['network']);

        self::restore_meta($user, (array) ($data['meta'] ?? []));
        $warning = self::restore_earnings($user, (array) ($data['earnings'] ?? []), $network);
        $logs    = self::remove_new_logs($user, (int) ($data['max_log'] ?? -1), $network);

        return $warning ?? $logs;
    }

    private static function restore_meta(int $user, array $rows): void
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a restore writes the captured rows back verbatim.
        if (false === $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE user_id = %d AND meta_key LIKE %s', $wpdb->usermeta, $user, '%' . $wpdb->esc_like(self::META_LIKE) . '%'))) {
            throw new Mutation_Failed('Could not clear the GamiPress user meta being restored.');
        }
        foreach ($rows as $row) {
            $row = (array) $row;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- the captured value goes back byte for byte.
            if (false === $wpdb->insert($wpdb->usermeta, [ 'user_id' => $user, 'meta_key' => (string) $row['meta_key'], 'meta_value' => $row['meta_value'] ])) {
                throw new Mutation_Failed('Could not restore a GamiPress user meta row: ' . esc_html($wpdb->last_error));
            }
        }
        wp_cache_delete($user, 'user_meta');
    }

    private static function restore_earnings(int $user, array $earnings, bool $network): ?string
    {
        global $wpdb;
        $table = self::table(self::EARNINGS, $network);
        if (! self::exists($table)) {
            return [] === $earnings ? null : 'The GamiPress user earnings table is gone, so the earnings were not restored.';
        }
        $meta = self::table(self::EARNINGS . '_meta', $network);
        $has  = self::exists($meta);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- third-party table with no API that keeps ids.
        $ids = array_map('intval', (array) $wpdb->get_col($wpdb->prepare('SELECT %i FROM %i WHERE user_id = %d', self::EARNINGS_KEY, $table, $user)));
        foreach ($earnings as $earning) {
            $ids[] = (int) ($earning['row'][ self::EARNINGS_KEY ] ?? 0);
        }
        $ids = array_values(array_unique(array_filter($ids)));
        if ([] !== $ids) {
            $in = implode(',', array_fill(0, count($ids), '%d'));
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- third-party table with no API; $in holds only %d placeholders.
            if (false === $wpdb->query($wpdb->prepare("DELETE FROM %i WHERE %i IN ($in)", $table, self::EARNINGS_KEY, ...$ids))) {
                throw new Mutation_Failed('Could not clear the GamiPress user earnings being restored.');
            }
            if ($has && false === $wpdb->query($wpdb->prepare("DELETE FROM %i WHERE %i IN ($in)", $meta, self::EARNINGS_KEY, ...$ids))) {
                throw new Mutation_Failed('Could not clear the GamiPress user earning meta being restored.');
            }
            // phpcs:enable
        }
        foreach ($earnings as $earning) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- the captured row goes back as it was, id kept.
            if (false === $wpdb->insert($table, (array) ($earning['row'] ?? []))) {
                throw new Mutation_Failed('Could not restore a GamiPress user earning: ' . esc_html($wpdb->last_error));
            }
            foreach ($has ? (array) ($earning['meta'] ?? []) : [] as $row) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- as above.
                if (false === $wpdb->insert($meta, (array) $row)) {
                    throw new Mutation_Failed('Could not restore a GamiPress user earning meta row: ' . esc_html($wpdb->last_error));
                }
            }
        }
        return null;
    }

    private static function remove_new_logs(int $user, int $max, bool $network): ?string
    {
        global $wpdb;
        if ($max < 0) {
            return null;
        }
        $table = self::table(self::LOGS, $network);
        if (! self::exists($table)) {
            return 'The GamiPress logs table is gone, so the log entries were not removed.';
        }
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- third-party table with no delete API.
        $ids = array_map('intval', (array) $wpdb->get_col($wpdb->prepare('SELECT %i FROM %i WHERE user_id = %d AND %i > %d', self::LOGS_KEY, $table, $user, self::LOGS_KEY, $max)));
        if ([] === $ids) {
            return null;
        }
        $in   = implode(',', array_fill(0, count($ids), '%d'));
        $meta = self::table(self::LOGS . '_meta', $network);
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in holds only %d placeholders.
        if (self::exists($meta) && false === $wpdb->query($wpdb->prepare("DELETE FROM %i WHERE %i IN ($in)", $meta, self::LOGS_KEY, ...$ids))) {
            throw new Mutation_Failed('Could not remove the GamiPress log meta the write added.');
        }
        if (false === $wpdb->query($wpdb->prepare("DELETE FROM %i WHERE %i IN ($in)", $table, self::LOGS_KEY, ...$ids))) {
            throw new Mutation_Failed('Could not remove the GamiPress log entries the write added.');
        }
        // phpcs:enable
        return null;
    }
}
