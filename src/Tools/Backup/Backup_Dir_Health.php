<?php

namespace WPMCP\Tools\Backup;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Keeps the site-backup folder where visitors cannot download it (issue #846).
 *
 * Two jobs:
 *
 *  - self_check(): write a canary file into the backup folder and request
 *    it over HTTP from the site itself. If the body comes back, the web
 *    server serves the folder (nginx ignores the .htaccess deny rule), and
 *    every archive in it is one guessed name away from a download. The
 *    result is stored and shown by get-backup-status and, when the folder
 *    is served, as a Dashboard warning. A folder outside the web root has
 *    no URL, so it is not requested at all. The request goes only to this
 *    site's own URL and runs only while trigger-backup or get-backup-status
 *    is called, at most once a day unless the folder changed.
 *
 *  - migrate_legacy(): once WPMCP_BACKUP_DIR is in use, move archives made
 *    earlier from uploads into the new folder and point their job records
 *    at the new file, so restore and push-site-archive still find them by
 *    job id. It runs in the background after a backup job and before
 *    trigger-backup queues one, and waits while any backup or restore job is
 *    queued or running, because a running job holds paths into the old
 *    folder.
 */
class Backup_Dir_Health
{
    /** The last self-check result. Autoload off: read only by the tools and the notice. */
    public const OPTION = 'wpmcp_backup_dir_check';

    /** How long a self-check result stays current. */
    public const MAX_AGE = DAY_IN_SECONDS;

    private const CANARY_PREFIX = 'wpmcp-canary-';

    /** What get-backup-status reports about the folder in use. */
    public static function report(): array
    {
        $where = Site_Backup_Dir::resolve();

        return [
            'path'            => $where['path'],
            'source'          => $where['source'],
            'outside_web_root' => ! Site_Backup_Dir::inside_web_root($where['path']),
            'fallback_reason' => $where['fallback_reason'],
            'last_check'      => self::last(),
        ];
    }

    /**
     * The warning to show when the last check found the folder served, or
     * null. Shared by trigger-backup and the Dashboard notice.
     */
    public static function warning(): ?string
    {
        $last = self::last();
        if (null === $last || 'exposed' !== ($last['status'] ?? null) || (string) ($last['path'] ?? '') !== Site_Backup_Dir::path()) {
            return null;
        }

        return sprintf(
            /* translators: %s: the backup folder path. */
            __('WP MCP backup archives in %s can be downloaded from the web: the server returned a test file placed there. Block that folder in the web server config, or define WPMCP_BACKUP_DIR in wp-config.php with a folder outside the web root.', 'wpmcp'),
            (string) $last['path']
        );
    }

    /** The stored self-check result, or null when none has run. */
    public static function last(): ?array
    {
        $last = get_option(self::OPTION, null);

        return is_array($last) ? $last : null;
    }

    /** Whether the stored result is missing, older than a day, or for another folder. */
    public static function is_due(): bool
    {
        $last = self::last();
        if (null === $last) {
            return true;
        }

        return (string) ($last['path'] ?? '') !== Site_Backup_Dir::path()
            || (int) ($last['checked_at'] ?? 0) < time() - self::MAX_AGE;
    }

    /** Move legacy archives when possible, then refresh the self-check when it is due. Never throws. */
    public static function maintain(): void
    {
        try {
            self::migrate_legacy();
        } catch (\Throwable $e) {
            unset($e);
        }

        try {
            if (self::is_due()) {
                self::self_check();
            }
        } catch (\Throwable $e) {
            unset($e);
        }
    }

    /**
     * Request a canary file in the backup folder and record whether it was
     * served.
     *
     * status is one of:
     *  - outside_web_root: the folder has no URL, nothing was requested;
     *  - protected: the canary URL did not return the canary;
     *  - exposed: the canary came back, archives here can be downloaded;
     *  - error: the canary could not be written.
     */
    public static function self_check(): array
    {
        $dir    = Site_Backup_Dir::path();
        $result = [
            'path'       => $dir,
            'checked_at' => time(),
            'status'     => 'outside_web_root',
            'http_code'  => null,
            'url'        => null,
        ];

        $url = Site_Backup_Dir::public_url($dir);
        if (null !== $url) {
            Site_Backup_Dir::protect($dir);
            $token  = wp_generate_password(32, false);
            $name   = self::CANARY_PREFIX . strtolower(wp_generate_password(12, false)) . '.txt';
            $canary = $dir . '/' . $name;

            if (! wp_is_writable($dir) || false === file_put_contents($canary, $token)) {
                $result['status'] = 'error';
            } else {
                try {
                    $response = wp_remote_get($url . '/' . $name, [
                        'timeout'     => 5,
                        'redirection' => 0,
                        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core's own loopback filter.
                        'sslverify'   => (bool) apply_filters('https_local_ssl_verify', false),
                    ]);
                } finally {
                    wp_delete_file($canary);
                }

                $code = is_wp_error($response) ? null : (int) wp_remote_retrieve_response_code($response);
                $body = is_wp_error($response) ? '' : (string) wp_remote_retrieve_body($response);

                $result['http_code'] = $code;
                $result['url']       = $url;
                $result['status']    = (200 === $code && str_contains($body, $token)) ? 'exposed' : 'protected';
            }
        }

        update_option(self::OPTION, $result, false);

        return $result;
    }

    /**
     * Move archives left in the uploads folder into the WPMCP_BACKUP_DIR
     * folder and repoint the job records that name them.
     *
     * @return array{moved: string[], skipped: ?string}
     */
    public static function migrate_legacy(): array
    {
        $target = Site_Backup_Dir::path();
        $legacy = Site_Backup_Dir::uploads_path();
        if ($target === $legacy) {
            return [ 'moved' => [], 'skipped' => 'not_configured' ];
        }

        $files = glob($legacy . '/*.zip');
        if (empty($files)) {
            return [ 'moved' => [], 'skipped' => 'nothing_to_move' ];
        }

        if (self::job_in_flight()) {
            return [ 'moved' => [], 'skipped' => 'job_in_flight' ];
        }

        Site_Backup_Dir::protect($target);
        $target_real = realpath($target);
        if (false === $target_real) {
            return [ 'moved' => [], 'skipped' => 'target_unavailable' ];
        }

        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
        $fs = new \WP_Filesystem_Direct(null);

        $moves = [];
        foreach ($files as $file) {
            $real = realpath($file);
            if (false === $real || ! is_file($real) || is_link($file)) {
                continue;
            }
            $dest = $target_real . '/' . basename($real);
            if (file_exists($dest)) {
                continue;
            }
            if ($fs->move($real, $dest)) {
                $moves[ $real ] = $dest;
            }
        }

        if ([] !== $moves) {
            foreach (Backup_Job_Store::list() as $job) {
                $file = (string) ($job['result']['file'] ?? '');
                if ('' === $file) {
                    continue;
                }
                $key = realpath(dirname($file));
                $key = false !== $key ? $key . '/' . basename($file) : $file;
                if (isset($moves[ $key ])) {
                    $result         = (array) $job['result'];
                    $result['file'] = $moves[ $key ];
                    Backup_Job_Store::update((int) $job['id'], [ 'result' => $result ]);
                }
            }
        }

        return [ 'moved' => array_values($moves), 'skipped' => null ];
    }

    /** Whether a backup or restore is queued, running, or left a restore in progress. */
    private static function job_in_flight(): bool
    {
        foreach ([ 'queued', 'running' ] as $status) {
            if ([] !== Backup_Job_Store::list($status)) {
                return true;
            }
        }

        foreach (Site_Backup_Dir::roots() as $root) {
            if (is_file($root . '/restore-in-progress.json')) {
                return true;
            }
        }

        return false;
    }
}
