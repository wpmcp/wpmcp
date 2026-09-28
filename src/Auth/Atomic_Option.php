<?php

namespace WPMCP\Auth;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Compare-and-swap over a single wp_options row, shared by the OAuth stores
 * that must not lose a concurrent write (Code_Store since issue #43 C3,
 * Refresh_Token_Store since refresh redemption was made atomic).
 *
 * update_option() is unconditional last-write-wins, so a plain load, modify,
 * save lets two concurrent requests both act on the same snapshot. swap()
 * instead issues `UPDATE ... WHERE option_name = ... AND option_value =
 * <value just read>`, which MySQL executes under a row lock: at most one
 * concurrent caller matches and affects the row, and every other caller sees
 * zero rows affected and must re-read and retry.
 */
class Atomic_Option
{
    /** Bounded retry count for callers' compare-and-swap loops. */
    public const MAX_ATTEMPTS = 10;

    /**
     * Read the row straight from the table, bypassing the object cache.
     *
     * get_option() cannot be used: an autoloaded option is served from the
     * in-process `alloptions` blob, so every retry would compare the same
     * stale snapshot and never match a row another process rewrote (issue
     * #182). The compare half of a compare-and-swap has to see the row.
     *
     * @return array|null The decoded value, or null when the row is absent.
     */
    public static function read(string $option): ?array
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Deliberately uncached: this read is the compare half of a compare-and-swap and must observe the row as other processes left it, which the autoloaded-options cache cannot show.
        $value = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
                $option
            )
        );

        if (null === $value) {
            return null;
        }

        $stored = maybe_unserialize($value);

        return is_array($stored) ? $stored : [];
    }

    /**
     * Replace the row's value from $before to $after only if it still holds
     * exactly $before. Returns true when this caller's write won.
     */
    public static function swap(string $option, array $before, array $after): bool
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- update_option() is unconditional last-write-wins and cannot express "write only if the row still holds what I read". This conditional UPDATE takes a MySQL row lock, so at most one concurrent writer affects the row. The caches it invalidates are cleared on the winning path below.
        $affected = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
                maybe_serialize($after),
                $option,
                maybe_serialize($before)
            )
        );

        if (false === $affected || $affected < 1) {
            return false;
        }

        // The row moved behind get_option()'s back. Clearing only the
        // per-option key is not enough for an autoloaded option: leaving the
        // `alloptions` blob stale would let the next plain load-modify-save
        // write the old value straight back.
        wp_cache_delete($option, 'options');
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('notoptions', 'options');

        return true;
    }

    /**
     * Apply $mutator to the row atomically, retrying on contention.
     *
     * $mutator receives the current value and whether this is a retry after
     * a lost race, and returns [ $after, $result ]. Returning null for
     * $after means "no write needed": $result is returned as-is. The row is
     * created (empty, not autoloaded) first if it does not exist, so the
     * conditional UPDATE always has a row to match.
     *
     * @param callable(array, bool): array{0: ?array, 1: mixed} $mutator
     * @param mixed $on_exhausted Returned when every attempt lost a race.
     * @return mixed
     */
    public static function mutate(string $option, callable $mutator, $on_exhausted = null)
    {
        $lost_race = false;

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $before = self::read($option);
            if (null === $before) {
                add_option($option, [], '', false);
                $before = self::read($option) ?? [];
            }

            [$after, $result] = $mutator($before, $lost_race);

            if (null === $after || $after === $before) {
                return $result;
            }

            if (self::swap($option, $before, $after)) {
                return $result;
            }

            $lost_race = true;
        }

        return $on_exhausted;
    }
}
