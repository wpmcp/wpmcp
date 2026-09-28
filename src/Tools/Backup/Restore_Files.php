<?php

namespace WPMCP\Tools\Backup;

use WPMCP\Safety\File_Backup;
use WPMCP\Tools\Export\Export_Dir;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The opt-in wp-content half of a restore (issue #190): extract to a
 * staging directory, then swap it in, never extract over the live tree.
 *
 * Extracting over a live wp-content leaves a mix of old and new files the
 * moment anything fails (a full disk, a permission error on one plugin
 * directory), and a half-replaced plugin is a fatal error on every request.
 * So the archive's wp-content is extracted in full first, into
 * wp-content/wpmcp-restore/staging-*, before the database is touched; the
 * swap then renames each top-level entry (plugins, themes, uploads, ...)
 * out of the way and the staged one into place. Each rename is atomic on
 * one filesystem, every one is journalled, and a failure part-way replays
 * the journal backwards so the live tree ends up exactly as it was.
 *
 * The replaced entries are kept, not deleted, under
 * wp-content/wpmcp-restore/previous-*: the database has a pre-restore
 * safety archive, and this is the files equivalent. The result reports the
 * path so the site owner can delete it once satisfied.
 *
 * Three things are carried across from the live tree instead of being
 * replaced, because replacing them breaks the restore itself or loses data
 * the archive never contained: this plugin's own directory (the running
 * code must not be swapped for an older copy mid-request), and the
 * site-backup, export and file-backup directories under uploads (backups
 * are excluded from archives by construction, so the archive has no copy of
 * them, including the safety archive this very restore just took).
 */
class Restore_Files
{
    public const DIR_NAME = 'wpmcp-restore';

    private const ARCHIVE_PREFIX = 'wp-content/';

    private string $content_dir;

    public function __construct(?string $content_dir = null)
    {
        $dir = null !== $content_dir ? $content_dir : (defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : ABSPATH . 'wp-content');
        $this->content_dir = rtrim($dir, '/\\');
    }

    /**
     * The wp-content entries of an archive, validated. Every name must stay
     * inside wp-content once extracted: no "..", no absolute path, no drive
     * letter, no backslash. One bad name refuses the whole archive.
     *
     * @return string[] Entry names to extract (directories omitted).
     * @throws \RuntimeException
     */
    public static function entries(\ZipArchive $zip): array
    {
        $names = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (false === $name || ! str_starts_with($name, self::ARCHIVE_PREFIX)) {
                continue;
            }

            $relative = substr($name, strlen(self::ARCHIVE_PREFIX));
            // A colon is a legal filename character on Linux, so only a
            // drive-letter segment ("C:") is treated as unsafe.
            if (str_contains($name, "\0") || str_contains($name, '\\') || preg_match('#(^|/)[A-Za-z]:(/|$)#', $relative)) {
                throw new \RuntimeException(sprintf('The archive entry %s has an unsafe name; refusing to extract it.', esc_html($name)));
            }

            $segments = explode('/', rtrim($relative, '/'));
            foreach ($segments as $segment) {
                if ('' === $segment || '.' === $segment || '..' === $segment) {
                    throw new \RuntimeException(sprintf('The archive entry %s would extract outside wp-content; refusing it.', esc_html($name)));
                }
            }

            if (self::DIR_NAME === $segments[0] || str_ends_with($name, '/')) {
                continue;
            }

            $names[] = $name;
        }

        return $names;
    }

    /**
     * Extract the archive's wp-content into a fresh staging directory and
     * return the staged wp-content root. Touches nothing live.
     *
     * @throws \RuntimeException When extraction fails; the partial staging
     *                           directory is removed first.
     */
    public function stage(string $archive): string
    {
        $zip = new \ZipArchive();
        if (true !== $zip->open($archive)) {
            throw new \RuntimeException('That archive could not be opened; it may be truncated.');
        }

        try {
            $names = self::entries($zip);
            if ([] === $names) {
                throw new \RuntimeException('include_files was requested but the archive holds no wp-content files.');
            }

            $staging = $this->work_dir() . '/staging-' . wp_generate_password(12, false);
            if (! wp_mkdir_p($staging)) {
                throw new \RuntimeException('Could not create the staging directory for the files restore.');
            }

            if (true !== $zip->extractTo($staging, $names)) {
                $this->remove($staging);
                throw new \RuntimeException('Extracting wp-content to the staging directory failed (out of disk space, or a corrupt archive); nothing was changed.');
            }
        } finally {
            $zip->close();
        }

        return $staging . '/wp-content';
    }

    /**
     * Swap the staged wp-content into place, entry by entry.
     *
     * @return array{swapped: string[], kept_live: string[], previous_tree: string}
     * @throws \RuntimeException After rolling back every move already made.
     */
    public function swap(string $staged_root): array
    {
        $fs       = self::filesystem();
        $previous = $this->work_dir() . '/previous-' . gmdate('Ymd-His') . '-' . wp_generate_password(8, false);
        $parked   = dirname($staged_root) . '/replaced';
        $journal  = [];
        $swapped  = [];
        $kept     = [];

        $move = static function (string $from, string $to) use ($fs, &$journal): void {
            wp_mkdir_p(dirname($to));
            if (! $fs->move($from, $to)) {
                throw new \RuntimeException(sprintf('Could not move %s into place.', esc_html($from)));
            }
            $journal[] = [$from, $to];
        };

        $entries = array_values(array_diff((array) scandir($staged_root), ['.', '..', self::DIR_NAME]));

        try {
            foreach ($entries as $name) {
                $live = $this->content_dir . '/' . $name;

                if (file_exists($live) || is_link($live)) {
                    $move($live, $previous . '/' . $name);
                }
                $move($staged_root . '/' . $name, $live);
                $swapped[] = $name;

                foreach ($this->keep_live() as $relative) {
                    if (! str_starts_with($relative, $name . '/')) {
                        continue;
                    }
                    $original = $previous . '/' . $relative;
                    if (! file_exists($original) && ! is_link($original)) {
                        continue;
                    }
                    $target = $this->content_dir . '/' . $relative;
                    if (file_exists($target) || is_link($target)) {
                        $move($target, $parked . '/' . $relative);
                    }
                    $move($original, $target);
                    $kept[] = $relative;
                }
            }
        } catch (\Throwable $e) {
            $stuck = [];
            foreach (array_reverse($journal) as [$from, $to]) {
                if (! $fs->move($to, $from)) {
                    $stuck[] = $to;
                }
            }
            $detail = [] === $stuck
                ? ' Every file move was rolled back; wp-content is unchanged.'
                : ' Rolling back also failed for: ' . implode(', ', $stuck) . '.';
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $e's message escapes the path it names; $stuck are server-side paths built above from WP_CONTENT_DIR and names read back from the staging directory.
            throw new \RuntimeException('The wp-content swap failed: ' . $e->getMessage() . $detail);
        }

        self::reset_opcache();

        return [
            'swapped'       => $swapped,
            'kept_live'     => $kept,
            'previous_tree' => $previous,
        ];
    }

    /** Remove a staging directory (the parent of a staged wp-content root). */
    public function discard(string $staged_root): void
    {
        $this->remove(dirname($staged_root));
    }

    /**
     * Paths, relative to wp-content, that stay as they are on the live site
     * rather than being replaced by the archive's copy.
     *
     * @return string[]
     */
    public function keep_live(): array
    {
        $keep = [];

        $content = realpath($this->content_dir);

        // By directory name under the plugins directory, not by realpath:
        // PHP resolves symlinks in __FILE__, so a symlinked install (common
        // in development and on some managed hosts) would otherwise never
        // match and the running plugin would be swapped away.
        $plugins = defined('WP_PLUGIN_DIR') ? realpath(WP_PLUGIN_DIR) : false;
        if (defined('WPMCP_FILE') && false !== $plugins && false !== $content && $plugins === $content . '/plugins') {
            $keep[] = 'plugins/' . basename(dirname((string) WPMCP_FILE));
        }

        $uploads = wp_upload_dir();
        $basedir = isset($uploads['basedir']) ? realpath((string) $uploads['basedir']) : false;
        if (false !== $basedir && false !== $content && str_starts_with($basedir, $content . '/')) {
            $relative = substr($basedir, strlen($content) + 1);
            foreach ([Site_Backup_Dir::DIR_NAME, Export_Dir::DIR_NAME, File_Backup::BACKUP_DIR] as $dir) {
                $keep[] = $relative . '/' . $dir;
            }
        }

        return $keep;
    }

    /** wp-content/wpmcp-restore, created and blocked from web access. */
    private function work_dir(): string
    {
        $dir = $this->content_dir . '/' . self::DIR_NAME;
        Site_Backup_Dir::protect($dir);

        return $dir;
    }

    private function remove(string $dir): void
    {
        if (is_dir($dir)) {
            self::filesystem()->delete($dir, true);
        }
    }

    /**
     * Swapped-in PHP files share paths with the ones they replaced; without
     * a reset, opcache can keep serving the old bytecode until it next
     * revalidates timestamps.
     */
    private static function reset_opcache(): void
    {
        if (function_exists('opcache_reset') && '' === (string) ini_get('opcache.restrict_api')) {
            opcache_reset();
        }
    }

    /**
     * The direct filesystem, always. Every path here is local to this
     * server and inside wp-content; an FTP or SSH transport would resolve
     * them against a different root, and prompting for credentials from a
     * tool call is not an option.
     */
    private static function filesystem(): \WP_Filesystem_Direct
    {
        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';

        return new \WP_Filesystem_Direct(null);
    }
}
