<?php

namespace WPMCP\Tools\Migration;

use WPMCP\Tools\Backup\Archive_Locator;
use WPMCP\Tools\Backup\Site_Backup_Dir;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The target side's record of archives being pushed to it (issue #191).
 *
 * Everything lives on disk under wpmcp-site-backups/incoming/, never in the
 * database: the whole point of an incoming archive is that it is about to
 * replace the database, and an upload record in wp_options would be
 * overwritten by the very restore it describes (and lost with it, along
 * with the result a source polls for after a timeout). The directory
 * inherits the site-backup directory's deny rules, and restore keeps the
 * site-backup directory live when it swaps wp-content.
 *
 * One upload is two files: <id>.part (the bytes so far) and <id>.json (the
 * state). The received byte count is always the size of the .part file,
 * not a number in the state file, so a request that dies between writing a
 * chunk and saving the state cannot make the two disagree. Chunks must
 * arrive in order; a chunk the target already holds (a retry after a lost
 * response) is acknowledged without being written twice.
 *
 * An upload is resumed, not restarted, when a start call names the same
 * sha256 and size: that is what makes a 2GB push survive a shared host's
 * request limits. Nothing about an upload is trusted until finalize() has
 * hashed the whole file and matched the manifest inside it against the
 * one declared at start.
 */
class Incoming_Archive_Store
{
    public const DIR_NAME = 'incoming';

    /** Unapplied uploads untouched for this long are removed on the next start. */
    public const STALE_AFTER = 7 * DAY_IN_SECONDS;

    /** Manifest fields that must match between start and the archive itself. */
    private const FINGERPRINT = [
        'format',
        'format_version',
        'created_at',
        'scope',
        'site.site_url',
        'site.home_url',
        'site.table_prefix',
        'site.multisite',
        'database.bytes',
    ];

    public static function dir(): string
    {
        $dir = Site_Backup_Dir::path() . '/' . self::DIR_NAME;
        Site_Backup_Dir::protect(Site_Backup_Dir::path());
        Site_Backup_Dir::protect($dir);

        return $dir;
    }

    /**
     * Open (or resume) an upload.
     *
     * @return array<string, mixed> The state, plus 'resumed' and 'received_bytes'.
     */
    public static function start(string $sha256, int $bytes, array $manifest, string $source_url, bool $restart): array
    {
        if (! preg_match('/^[a-f0-9]{64}$/', $sha256)) {
            throw new \InvalidArgumentException('sha256 must be the 64-character lowercase hex digest of the archive.');
        }
        if ($bytes <= 0) {
            throw new \InvalidArgumentException('bytes must be the archive size in bytes.');
        }

        self::purge_stale();

        foreach (self::all() as $state) {
            if ($state['sha256'] !== $sha256 || (int) $state['bytes'] !== $bytes) {
                continue;
            }
            if ($restart) {
                self::discard((string) $state['upload_id']);
                continue;
            }

            return self::with_progress($state) + ['resumed' => true];
        }

        $free = function_exists('disk_free_space') ? disk_free_space(self::dir()) : false;
        if (false !== $free && $free < $bytes) {
            throw new \RuntimeException(sprintf(
                'This site has %d bytes of free disk space and the archive is %d bytes; free some space before pushing.',
                (int) $free,
                (int) $bytes
            ));
        }

        $state = [
            'upload_id'  => strtolower(wp_generate_password(20, false)),
            'sha256'     => $sha256,
            'bytes'      => $bytes,
            'manifest'   => $manifest,
            'source_url' => $source_url,
            'status'     => 'receiving',
            'created_at' => gmdate('c'),
            'updated_at' => time(),
            'archive'    => null,
            'result'     => null,
        ];
        file_put_contents(self::part_path($state['upload_id']), '');
        self::save($state);

        return self::with_progress($state) + ['resumed' => false];
    }

    /**
     * Append one chunk at $offset.
     *
     * @return array<string, mixed> The state with received_bytes.
     */
    public static function append(string $upload_id, int $offset, string $raw): array
    {
        return self::locked($upload_id, static function () use ($upload_id, $offset, $raw): array {
            $state = self::get($upload_id);
            if ('receiving' !== $state['status']) {
                throw new \RuntimeException(sprintf('Upload %s is %s and takes no more chunks.', esc_html($upload_id), esc_html((string) $state['status'])));
            }

            $received = self::received($upload_id);
            $length   = strlen($raw);

            if ($length <= 0) {
                throw new \InvalidArgumentException('A chunk must carry at least one byte.');
            }
            if ($offset < $received && $offset + $length <= $received) {
                // A retry of a chunk already written (the response to the
                // first attempt was lost). Acknowledge, do not append again.
                return self::with_progress($state) + ['duplicate' => true];
            }
            if ($offset !== $received) {
                throw new \RuntimeException(sprintf(
                    'Chunk offset %d does not match the %d bytes received so far; resume from offset %d.',
                    (int) $offset,
                    (int) $received,
                    (int) $received
                ));
            }
            if ($received + $length > (int) $state['bytes']) {
                throw new \RuntimeException(sprintf(
                    'That chunk would take the upload past its declared %d bytes.',
                    (int) $state['bytes']
                ));
            }

            if (false === file_put_contents(self::part_path($upload_id), $raw, FILE_APPEND | LOCK_EX)) {
                throw new \RuntimeException('The chunk could not be written to disk.');
            }

            $state['updated_at'] = time();
            self::save($state);

            return self::with_progress($state) + ['duplicate' => false];
        });
    }

    /**
     * Verify a complete upload and move it into the site-backup directory
     * as an ordinary archive. Idempotent once verified.
     *
     * @return array<string, mixed> The state; 'archive' is the final path.
     */
    public static function finalize(string $upload_id): array
    {
        return self::locked($upload_id, static function () use ($upload_id): array {
            $state = self::get($upload_id);
            if ('receiving' !== $state['status']) {
                if (! empty($state['archive']) && is_file((string) $state['archive'])) {
                    return self::with_progress($state);
                }
                throw new \RuntimeException(sprintf('Upload %s is %s and has no archive to apply.', esc_html($upload_id), esc_html((string) $state['status'])));
            }

            $part     = self::part_path($upload_id);
            $received = self::received($upload_id);
            if ($received !== (int) $state['bytes']) {
                throw new \RuntimeException(sprintf(
                    'Upload %s is incomplete: %d of %d bytes received.',
                    esc_html($upload_id),
                    (int) $received,
                    (int) $state['bytes']
                ));
            }

            $actual = (string) hash_file('sha256', $part);
            if (! hash_equals((string) $state['sha256'], $actual)) {
                // Start over rather than keep bytes known to be wrong; the
                // next push resumes from zero under the same upload id.
                file_put_contents($part, '');
                $state['updated_at'] = time();
                self::save($state);
                throw new \RuntimeException('The received archive does not match its declared sha256 (it was corrupted in transit); the partial upload was discarded, push again.');
            }

            $archive = Site_Backup_Dir::path() . '/wpmcp-migration-' . gmdate('Ymd-His') . '-' . strtolower(wp_generate_password(12, false)) . '.zip';
            require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
            require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
            if (! (new \WP_Filesystem_Direct(null))->move($part, $archive)) {
                throw new \RuntimeException('The verified archive could not be moved into the site-backup directory.');
            }

            try {
                $inside = Archive_Locator::read_manifest($archive);
            } catch (\RuntimeException $e) {
                wp_delete_file($archive);
                self::discard($upload_id);
                throw $e;
            }

            $mismatch = self::fingerprint_mismatch((array) $state['manifest'], $inside);
            if (null !== $mismatch) {
                wp_delete_file($archive);
                self::discard($upload_id);
                throw new \RuntimeException(sprintf(
                    'The archive\'s own manifest does not match the manifest declared when the upload started (%s differs); refusing it.',
                    esc_html($mismatch)
                ));
            }

            $state['status']     = 'verified';
            $state['archive']    = $archive;
            $state['manifest']   = $inside;
            $state['updated_at'] = time();
            self::save($state);

            return self::with_progress($state);
        });
    }

    /**
     * Claim a verified upload for applying, atomically: two apply calls
     * racing must not both start a restore. A failed earlier apply may be
     * retried; the archive is still on disk.
     *
     * @return array<string, mixed>
     */
    public static function begin_apply(string $upload_id): array
    {
        return self::locked($upload_id, static function () use ($upload_id): array {
            $state = self::get($upload_id);
            if (! in_array($state['status'], ['verified', 'failed'], true)) {
                throw new \RuntimeException(sprintf('Upload %s is %s and cannot be applied now.', esc_html($upload_id), esc_html((string) $state['status'])));
            }

            return self::update($upload_id, ['status' => 'applying', 'result' => null]);
        });
    }

    /** @return array<string, mixed> */
    public static function get(string $upload_id): array
    {
        self::check_id($upload_id);
        $path = self::state_path($upload_id);
        if (! is_file($path)) {
            throw new \RuntimeException(sprintf('No upload with id %s on this site.', esc_html($upload_id)));
        }

        $state = json_decode((string) file_get_contents($path), true);
        if (! is_array($state) || ($state['upload_id'] ?? null) !== $upload_id) {
            throw new \RuntimeException(sprintf('The record of upload %s is unreadable.', esc_html($upload_id)));
        }

        return $state;
    }

    /** Merge $fields into an upload's state and save it. */
    public static function update(string $upload_id, array $fields): array
    {
        $state               = array_merge(self::get($upload_id), $fields);
        $state['updated_at'] = time();
        self::save($state);

        return $state;
    }

    /** The state as reported to a caller: progress added, bulk removed. */
    public static function with_progress(array $state): array
    {
        $received = 'receiving' === ($state['status'] ?? '')
            ? self::received((string) $state['upload_id'])
            : (int) $state['bytes'];

        $out = $state;
        unset($out['manifest']);
        $out['received_bytes'] = $received;
        $out['complete']       = $received === (int) $state['bytes'];

        return $out;
    }

    /** The first fingerprint field that differs, or null when they agree. */
    public static function fingerprint_mismatch(array $declared, array $actual): ?string
    {
        foreach (self::FINGERPRINT as $dotted) {
            if (self::dig($declared, $dotted) !== self::dig($actual, $dotted)) {
                return $dotted;
            }
        }

        return null;
    }

    /** Remove an upload's record and partial bytes (never a verified archive). */
    public static function discard(string $upload_id): void
    {
        self::check_id($upload_id);
        foreach ([self::part_path($upload_id), self::state_path($upload_id)] as $path) {
            if (is_file($path)) {
                wp_delete_file($path);
            }
        }
    }

    private static function received(string $upload_id): int
    {
        $part = self::part_path($upload_id);
        clearstatcache(true, $part);

        return is_file($part) ? (int) filesize($part) : 0;
    }

    /** @return array<int, array<string, mixed>> */
    private static function all(): array
    {
        $out = [];
        foreach ((array) glob(self::dir() . '/*.json') as $path) {
            $state = json_decode((string) file_get_contents((string) $path), true);
            if (is_array($state) && isset($state['upload_id'], $state['sha256'], $state['bytes'])) {
                $out[] = $state;
            }
        }

        return $out;
    }

    private static function purge_stale(): void
    {
        $cutoff = time() - self::STALE_AFTER;
        foreach (self::all() as $state) {
            if ((int) ($state['updated_at'] ?? 0) >= $cutoff) {
                continue;
            }
            // A verified archive nobody applied is a full copy of another
            // site's secrets sitting on disk; an applied one is kept (the
            // site owner may want it, and delete-backup-archive removes it).
            if ('applied' !== ($state['status'] ?? '') && ! empty($state['archive']) && is_file((string) $state['archive'])) {
                wp_delete_file((string) $state['archive']);
            }
            self::discard((string) $state['upload_id']);
        }
    }

    private static function save(array $state): void
    {
        file_put_contents(self::state_path((string) $state['upload_id']), (string) wp_json_encode($state), LOCK_EX);
    }

    private static function check_id(string $upload_id): void
    {
        if (! preg_match('/^[a-z0-9]{20}$/', $upload_id)) {
            throw new \InvalidArgumentException('upload_id is not a valid upload id.');
        }
    }

    private static function part_path(string $upload_id): string
    {
        return self::dir() . '/' . $upload_id . '.part';
    }

    private static function state_path(string $upload_id): string
    {
        return self::dir() . '/' . $upload_id . '.json';
    }

    /** @return mixed */
    private static function dig(array $data, string $dotted)
    {
        foreach (explode('.', $dotted) as $key) {
            if (! is_array($data) || ! array_key_exists($key, $data)) {
                return null;
            }
            $data = $data[ $key ];
        }

        return $data;
    }

    /**
     * Serialize work on one upload. A MySQL named lock, not a file lock or
     * an option: two chunk requests racing for the same offset must not
     * both append, and the lock is released by the server if PHP dies.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    private static function locked(string $upload_id, callable $work)
    {
        global $wpdb;

        self::check_id($upload_id);
        $name = 'wpmcp_mig_' . $upload_id;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MySQL named lock; no API exists.
        if ('1' !== (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 10)', $name))) {
            throw new \RuntimeException('Another request is writing to this upload; retry in a moment.');
        }

        try {
            return $work();
        } finally {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MySQL named lock; no API exists.
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
        }
    }
}
