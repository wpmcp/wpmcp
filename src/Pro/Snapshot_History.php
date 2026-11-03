<?php

namespace WPMCP\Pro;

use WPMCP\Safety\Snapshot_Store;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Unlimited snapshot history for a licensed Pro site.
 *
 * Snapshot_Store keeps one flat cap with no licence check (issue #158) and
 * stays that way in every build. Pro raises it through the same public
 * filter any site owner can use, so nothing in the free store knows about
 * the licence and the wp.org cut, which carries no src/Pro, is unaffected.
 *
 * The filter is added only while the licence is active. Registering it
 * unconditionally would make has_filter() true on every install, and
 * Snapshot_Store reads that as the owner having chosen a depth, which would
 * clear the retention-floor notice for free users who never chose anything.
 */
class Snapshot_History
{
    /** Set once this site has run with the licence active. */
    public const SEEN_OPTION = 'wpmcp_pro_history_seen';

    public static function register(): void
    {
        if (Gate::is_pro()) {
            add_filter('wpmcp_snapshot_history_limit', [self::class, 'limit']);
            if (! get_option(self::SEEN_OPTION)) {
                update_option(self::SEEN_OPTION, 1, false);
            }
            return;
        }

        if (get_option(self::SEEN_OPTION)) {
            self::hold_history_after_lapse();
        }
    }

    /**
     * The licence was active and is not any more, so the cap is about to
     * fall back to the flat number. prune() deletes the file backups behind
     * every row it drops, and an expired renewal is not the owner deciding
     * to lose their undo history, so the depth the site has now is held as
     * the retention floor. The owner gets the existing notice, which lets
     * them trim to the cap or set their own depth.
     */
    private static function hold_history_after_lapse(): void
    {
        Snapshot_Store::hold_retention_floor(Snapshot_Store::row_count());
        delete_option(self::SEEN_OPTION);
    }

    /**
     * @param mixed $default the cap so far (the flat constant unless another
     *                       filter changed it)
     * @return int unlimited while the licence is active
     */
    public static function limit($default): int
    {
        return Gate::is_pro() ? PHP_INT_MAX : (int) $default;
    }
}
