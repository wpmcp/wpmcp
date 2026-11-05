<?php

namespace WPMCP\Tools\Filesystem;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * One-time upgrade for the file tool backups written before #609 (issue
 * #622). Those were uploads/wpmcp-fs-backups/<Ymd-His>-<flattened path>,
 * keeping the original extension, so a backed-up functions.php sat under
 * uploads as a .php file guarded only by an .htaccess that nginx ignores.
 *
 * run() renames every regular file in that folder whose name has an
 * executable extension segment (.php, .phtml, .php5, .phar ...) to a name
 * with every dot turned into an underscore plus ".bak", so neither
 * nginx's \.php$ rule nor Apache's multi-extension handler mapping can run
 * it. Each old ABSPATH-relative name is recorded in RENAMES_OPTION, which
 * Filesystem_Guard::restore() consults, so a restore by the name a tool
 * response once gave still returns the original bytes. The folder gets an
 * index.php guard and the .htaccess deny if they are missing. Symlinks,
 * subdirectories and anything outside the folder are never touched. On a
 * network every site's folder is converted, in batches across requests.
 */
class Legacy_Backup_Migration
{
    /** Network-wide (site option): set once every site has been converted. */
    public const DONE_OPTION = 'wpmcp_fs_backups_renamed';

    /** Network-wide (site option): offset of the next batch of sites. */
    public const CURSOR_OPTION = 'wpmcp_fs_backups_rename_cursor';

    /** Per site: old ABSPATH-relative backup name => new one. */
    public const RENAMES_OPTION = 'wpmcp_fs_backup_renames';

    /** Sites converted per request on a network. */
    public const SITES_PER_RUN = 100;

    /** A name with an extension segment a PHP server may execute. */
    public const EXECUTABLE_PATTERN = '/\.(php\d*|phtml|phps|pht|phar)(\.|$)/i';

    /** Hooked on init: cheap once done. */
    public static function maybe_run(): void
    {
        if (get_site_option(self::DONE_OPTION)) {
            return;
        }
        self::run();
    }

    /** Convert this site, or the next batch of a network's sites. */
    public static function run(): void
    {
        if (! is_multisite()) {
            self::convert_current_site();
            update_site_option(self::DONE_OPTION, 1);
            return;
        }

        $offset = (int) get_site_option(self::CURSOR_OPTION, 0);
        $ids    = get_sites(['fields' => 'ids', 'number' => self::SITES_PER_RUN, 'offset' => $offset, 'orderby' => 'id']);
        foreach ((array) $ids as $site_id) {
            switch_to_blog((int) $site_id);
            try {
                self::convert_current_site();
            } finally {
                restore_current_blog();
            }
        }

        if (count((array) $ids) < self::SITES_PER_RUN) {
            update_site_option(self::DONE_OPTION, 1);
            delete_site_option(self::CURSOR_OPTION);
            return;
        }
        update_site_option(self::CURSOR_OPTION, $offset + self::SITES_PER_RUN);
    }

    /** The current site's legacy backup folder, or '' when it is not a real directory. */
    public static function current_dir(): string
    {
        $uploads = wp_upload_dir(null, false);
        if (empty($uploads['basedir'])) {
            return '';
        }
        $dir = rtrim((string) $uploads['basedir'], '/\\') . '/' . Filesystem_Guard::BACKUP_DIR;
        return (is_dir($dir) && ! is_link($dir)) ? $dir : '';
    }

    private static function convert_current_site(): void
    {
        $dir = self::current_dir();
        if ('' === $dir) {
            return;
        }

        // .htaccess deny plus an index.php guard (the folder only had an index.html).
        \WPMCP\Safety\File_Backup::protect_dir($dir);

        $map     = (array) get_option(self::RENAMES_OPTION, []);
        $changed = false;
        foreach ((array) scandir($dir) as $entry) {
            if ('.' === $entry || '..' === $entry || 'index.php' === $entry) {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_link($path) || ! is_file($path) || ! preg_match(self::EXECUTABLE_PATTERN, $entry)) {
                continue;
            }
            $new = $dir . '/' . self::safe_name($entry, $dir);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- an atomic same-directory rename of a plugin-owned backup; WP_Filesystem::move() copies and deletes on some transports, which could leave both names behind.
            if (! rename($path, $new)) {
                continue;
            }
            $map[ Filesystem_Guard::to_relative($path) ] = Filesystem_Guard::to_relative($new);
            $changed = true;
        }
        if ($changed) {
            update_option(self::RENAMES_OPTION, $map, false);
        }
    }

    /** Pure-ish: a free, non-executable name for $entry inside $dir. */
    private static function safe_name(string $entry, string $dir): string
    {
        $base = str_replace('.', '_', $entry);
        $name = $base . '.bak';
        for ($i = 2; file_exists($dir . '/' . $name); $i++) {
            $name = $base . '-' . $i . '.bak';
        }
        return $name;
    }

    /**
     * Where a backup recorded under $backup_abs now lives, or null. Only a
     * file inside the current site's legacy backup folder is ever returned,
     * so the mapping cannot point a restore at another file.
     */
    public static function renamed_path(string $backup_abs): ?string
    {
        $map = get_option(self::RENAMES_OPTION, []);
        $rel = Filesystem_Guard::to_relative($backup_abs);
        if (! is_array($map) || ! isset($map[ $rel ]) || ! is_string($map[ $rel ])) {
            return null;
        }
        $dir = self::current_dir();
        $new = realpath(ABSPATH . ltrim($map[ $rel ], '/'));
        $dir = '' === $dir ? false : realpath($dir);
        if (false === $new || false === $dir || dirname($new) !== $dir || is_link(ABSPATH . ltrim($map[ $rel ], '/')) || ! is_file($new)) {
            return null;
        }
        return $new;
    }
}
