<?php

namespace WPMCP\Tools\Backup;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Keeps the site in maintenance mode for the duration of a restore
 * (issue #190), through the same wpmcp_maintenance option that
 * WPMCP\Maintenance\Maintenance_Guard enforces on the front end.
 *
 * The option lives in the table the restore is replacing, which is the
 * whole difficulty. Once the dump's wp_options has been imported, the row
 * holds whatever the backup recorded (almost always "off"), and the rest of
 * the import (posts, postmeta, terms, users, which all sort after options)
 * would run with the guard switched off. So the importer calls
 * after_options_imported() as soon as it moves past the options table: that
 * remembers the imported value as the one to put back and switches the
 * guard on again. While the options table itself is being rebuilt the
 * guard cannot read its row at all; WordPress then refuses to render (it
 * reports a database error because other core tables still exist) rather
 * than serving content or the installer.
 *
 * leave() always runs, success or failure, and writes back the value the
 * site should end up with: the imported one if the options table made it
 * across, otherwise the value from before the restore. It never leaves the
 * guard on just because a restore failed; the failure report and the
 * pre-restore safety archive are how a failed restore is recovered.
 */
class Restore_Maintenance
{
    public const OPTION = 'wpmcp_maintenance';

    /** Seconds a blocked visitor is told to wait before retrying. */
    public const RETRY_AFTER = 300;

    private bool $active = false;

    /** The option value to write back on leave(); null means "no row". */
    private $restore_to = null;

    private bool $had_row = false;

    public function enter(): void
    {
        [$this->had_row, $this->restore_to] = self::read();
        $this->switch_on();
        $this->active = true;
    }

    public function is_active(): bool
    {
        return $this->active;
    }

    /**
     * The dump has just replaced the options table: the row we wrote is
     * gone and the backup's own value is in its place.
     */
    public function after_options_imported(): void
    {
        if (! $this->active) {
            return;
        }

        // The object cache still holds the pre-import alloptions; reading
        // through it would return our own marker, and update_option() would
        // skip the write because the cached value already matches.
        wp_cache_flush();

        [$this->had_row, $this->restore_to] = self::read();
        $this->switch_on();
    }

    public function leave(): void
    {
        if (! $this->active) {
            return;
        }

        wp_cache_flush();

        if ($this->had_row) {
            update_option(self::OPTION, $this->restore_to);
        } else {
            delete_option(self::OPTION);
        }

        $this->active = false;
    }

    private function switch_on(): void
    {
        update_option(self::OPTION, [
            'enabled'     => true,
            'message'     => __('This site is being restored from a backup and will be back shortly.', 'wpmcp'),
            'retry_after' => self::RETRY_AFTER,
            'restore'     => true,
        ]);
    }

    /** @return array{0: bool, 1: mixed} Whether the row exists, and its value. */
    private static function read(): array
    {
        $missing = new \stdClass();
        $value   = get_option(self::OPTION, $missing);

        if ($value === $missing) {
            return [false, null];
        }

        return [true, $value];
    }
}
