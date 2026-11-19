<?php

namespace WPMCP\Tools\Export\Static_Site;

use WPMCP\Admin\Management_Access;
use WPMCP\MCP\Opt_In_Required;
use WPMCP\MCP\Pro_Refusal;
use WPMCP\Pro\Gate;
use WPMCP\Tools\Backup\Backup_Job_Store;
use WPMCP\Tools\Backup\Network_Scope;
use WPMCP\Tools\Backup\Site_Backup_Dir;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Static HTML export (issue #854, Pro, off by default): the site's public
 * pages rendered to plain HTML with their CSS, JS, images and fonts, links
 * rewritten to relative paths, as a ZIP that opens offline or uploads to
 * any static host.
 *
 * No tool of its own. It is the type=static kind of trigger-backup, reached
 * through the wpmcp_trigger_backup_kind filter so the shared backup tools
 * never name this Pro module (the directory build removes it):
 *
 *  - trigger-backup type=static plan=true lists the URLs that would be
 *    exported, with counts and a rough size, and queues nothing.
 *  - trigger-backup type=static queues the job (Run_Static_Export).
 *  - get-backup-status / list-backup-jobs / cancel-backup-job report and
 *    stop it like any backup job; delete-backup-archive removes its ZIP.
 *  - get-backup-status on a completed export carries download_url: a
 *    private admin link, signed for the asking user and good for
 *    DOWNLOAD_TTL seconds (filter wpmcp_static_export_download_ttl).
 *  - Retention: each finished export keeps the newest KEEP ZIPs (filter
 *    wpmcp_static_export_keep) and removes older ones and leftover work
 *    folders.
 *
 * The opt-in is the wpmcp_enable_static_export filter or the
 * WPMCP_ENABLE_STATIC_EXPORT constant. Administrators only: the backup
 * tools' capability (manage_options, manage_network_options on multisite).
 *
 * Nothing on the site changes: the export reads public pages as an
 * anonymous visitor and writes only inside the protected site-backup
 * directory, so, like the backup jobs, it is not snapshotted.
 */
class Static_Export
{
    public const FILTER   = 'wpmcp_enable_static_export';
    public const CONSTANT = 'WPMCP_ENABLE_STATIC_EXPORT';

    public const DOWNLOAD_ACTION = 'wpmcp_static_download';

    /** Seconds a download link works. */
    public const DOWNLOAD_TTL = 900;

    /** Finished ZIPs kept. */
    public const KEEP = 3;

    public static function register(): void
    {
        add_filter('wpmcp_trigger_backup_kind', [self::class, 'trigger'], 10, 3);
        add_filter('wpmcp_backup_job_view', [self::class, 'view']);
        add_action(Run_Static_Export::HOOK, [new Run_Static_Export(), 'handle']);
        // Behind the management check like every WP MCP admin handler; the
        // signed link is checked on top of it.
        Management_Access::add_handler('admin_post_' . self::DOWNLOAD_ACTION, [self::class, 'serve_download']);
    }

    public static function enabled(): bool
    {
        $default = defined(self::CONSTANT) && true === constant(self::CONSTANT);

        return (bool) apply_filters('wpmcp_enable_static_export', $default);
    }

    /**
     * The trigger-backup type=static handler.
     *
     * @param mixed $result What an earlier callback returned (null: unhandled).
     * @return mixed
     */
    public static function trigger($result, string $type, array $args)
    {
        if (null !== $result || Run_Static_Export::JOB_TYPE !== $type) {
            return $result;
        }

        if (! self::enabled()) {
            return Opt_In_Required::error('Static HTML export (trigger-backup type=static) is disabled.', self::FILTER, self::CONSTANT);
        }
        if (! Gate::can_use('static-export')) {
            return new \WP_Error('pro_required', Pro_Refusal::message('Static HTML export'));
        }
        if (! Network_Scope::allows_current_user()) {
            return new \WP_Error('wpmcp_backup_network_only', 'On a multisite network a static export needs a network administrator (manage_network_options).');
        }
        if (isset($args['every']) || isset($args['keep'])) {
            return new \WP_Error('wpmcp_static_export_invalid', 'A static export cannot be scheduled; drop every and keep.');
        }
        if ('' === (string) get_option('permalink_structure')) {
            return new \WP_Error('wpmcp_static_export_permalinks', 'A static export needs pretty permalinks: with plain ?p= links no page has a file path. Choose a permalink structure under Settings > Permalinks first.');
        }

        $options = Url_Discovery::options($args);
        if (is_wp_error($options)) {
            return $options;
        }

        if (! empty($args['plan'])) {
            return Url_Discovery::plan($options);
        }

        $created = Backup_Job_Store::create_exclusive(Run_Static_Export::JOB_TYPE, 'all', [
            'user_id' => get_current_user_id(),
            'options' => array_filter([
                'post_types' => implode(',', $options['post_types']),
                'urls'      => $options['urls'],
            ]),
        ]);
        if (null === $created['created']) {
            return new \WP_Error(
                'wpmcp_static_export_busy',
                sprintf('Static export job %d is already %s; wait for it or cancel it with cancel-backup-job.', (int) $created['blocking']['id'], (string) $created['blocking']['status'])
            );
        }

        $job = $created['created'];
        wp_schedule_single_event(time(), Run_Static_Export::HOOK, [(int) $job['id']]);

        return [
            'job_id' => (int) $job['id'],
            'status' => (string) $job['status'],
            'next'   => 'Poll get-backup-status with this job_id; a completed export carries download_url.',
        ];
    }

    /**
     * Why a queued or running export may not carry on, or null.
     */
    public static function run_refusal(array $job): ?string
    {
        $capability = Run_Static_Export::capability();
        $owner      = (int) ($job['user_id'] ?? 0);
        if ($owner <= 0 || ! user_can($owner, $capability)) {
            return sprintf('Canceled because the user who queued this static export no longer has %s.', $capability);
        }
        if (! self::enabled()) {
            return sprintf('Canceled because static HTML export was switched off (%s).', self::FILTER);
        }
        if (! Gate::can_use('static-export')) {
            return 'Canceled because static HTML export needs an active WP MCP Pro license on this site.';
        }

        return null;
    }

    /**
     * get-backup-status: a completed export the caller may download gets a
     * fresh signed link.
     *
     * @param mixed $job
     * @return mixed
     */
    public static function view($job)
    {
        if (! is_array($job) || Run_Static_Export::JOB_TYPE !== ($job['type'] ?? null) || 'completed' !== ($job['status'] ?? null)) {
            return $job;
        }
        $result = is_array($job['result'] ?? null) ? $job['result'] : [];
        if (! empty($result['deleted']) || ! is_file((string) ($result['file'] ?? '')) || ! current_user_can(Run_Static_Export::capability())) {
            return $job;
        }

        $expires                    = time() + self::ttl();
        $job['download_url']        = self::download_url((int) $job['id'], get_current_user_id(), $expires);
        $job['download_expires_at'] = $expires;

        return $job;
    }

    private static function ttl(): int
    {
        return max(60, (int) apply_filters('wpmcp_static_export_download_ttl', self::DOWNLOAD_TTL));
    }

    public static function download_url(int $job_id, int $user_id, int $expires): string
    {
        return add_query_arg([
            'action' => self::DOWNLOAD_ACTION,
            'job'    => $job_id,
            'exp'    => $expires,
            'sig'    => self::signature($job_id, $user_id, $expires),
        ], admin_url('admin-post.php'));
    }

    private static function signature(int $job_id, int $user_id, int $expires): string
    {
        return hash_hmac('sha256', implode('|', ['wpmcp-static', $job_id, $user_id, $expires]), wp_salt('auth'));
    }

    /**
     * The ZIP a download request may receive, or why not. The link only
     * works for the signed-in user it was made for, before it expires, and
     * only for a completed export's ZIP inside the site-backup directory.
     *
     * @param array<string, mixed> $query
     * @return string|\WP_Error
     */
    public static function download_file(array $query, int $user_id)
    {
        $job_id  = (int) ($query['job'] ?? 0);
        $expires = (int) ($query['exp'] ?? 0);
        $sig     = (string) ($query['sig'] ?? '');

        if ($user_id <= 0 || ! user_can($user_id, Run_Static_Export::capability())) {
            return new \WP_Error('wpmcp_static_download_forbidden', 'Sign in as the administrator the link was made for.', ['status' => 403]);
        }
        if (! hash_equals(self::signature($job_id, $user_id, $expires), $sig)) {
            return new \WP_Error('wpmcp_static_download_forbidden', 'This download link is not valid for this user.', ['status' => 403]);
        }
        if ($expires < time()) {
            return new \WP_Error('wpmcp_static_download_expired', 'This download link has expired; ask get-backup-status for a new one.', ['status' => 410]);
        }

        $job = Backup_Job_Store::get($job_id);
        $file = is_array($job) && Run_Static_Export::JOB_TYPE === ($job['type'] ?? null) && 'completed' === ($job['status'] ?? null)
            ? (string) ($job['result']['file'] ?? '')
            : '';
        $real = '' === $file ? false : realpath($file);
        $root = realpath(Site_Backup_Dir::path());
        if (false === $real || false === $root || ! is_file($real) || ! str_starts_with($real, trailingslashit($root))) {
            return new \WP_Error('wpmcp_static_download_missing', 'That static export has no ZIP any more.', ['status' => 404]);
        }

        return $real;
    }

    /** admin-post.php?action=wpmcp_static_download */
    public static function serve_download(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the signed, expiring, per-user link is the credential; download_file() verifies it.
        $file = self::download_file(wp_unslash($_GET), get_current_user_id());
        if (is_wp_error($file)) {
            $data = $file->get_error_data();
            wp_die(esc_html($file->get_error_message()), '', ['response' => (int) ($data['status'] ?? 403)]);
        }

        nocache_headers();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . basename($file) . '"');
        header('Content-Length: ' . (string) filesize($file));
        header('X-Robots-Tag: noindex');
        readfile($file); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streams the ZIP to the signed-in administrator without loading it into memory.
        exit;
    }

    /**
     * Retention after an export finishes: keep the newest KEEP ZIPs, remove
     * older ones (their job records are flagged deleted, as
     * delete-backup-archive does) and any work folder no running export
     * owns. Never fails the job that just finished.
     */
    public static function prune(int $finished_job_id): void
    {
        try {
            $keep = max(1, (int) apply_filters('wpmcp_static_export_keep', self::KEEP));
            $jobs = array_values(array_filter(
                Backup_Job_Store::list('completed'),
                static fn(array $job): bool => Run_Static_Export::JOB_TYPE === ($job['type'] ?? null)
                    && empty($job['result']['deleted'])
                    && '' !== (string) ($job['result']['file'] ?? '')
            ));
            usort($jobs, static fn(array $a, array $b): int => (int) $b['id'] <=> (int) $a['id']);

            foreach (array_slice($jobs, $keep) as $old) {
                if ((int) $old['id'] === $finished_job_id) {
                    continue;
                }
                $file = (string) $old['result']['file'];
                $root = realpath(Site_Backup_Dir::path());
                $real = realpath($file);
                if (false !== $root && false !== $real && str_starts_with($real, trailingslashit($root))) {
                    wp_delete_file($real);
                }
                $result            = $old['result'];
                $result['deleted'] = true;
                Backup_Job_Store::update((int) $old['id'], ['result' => $result]);
            }

            $running = [];
            foreach (Backup_Job_Store::list() as $job) {
                if (Run_Static_Export::JOB_TYPE === ($job['type'] ?? null) && in_array($job['status'] ?? null, ['queued', 'running'], true)) {
                    $running[] = (int) $job['id'];
                }
            }
            foreach ((array) glob(trailingslashit(Site_Backup_Dir::path()) . 'wpmcp-static-*.work', GLOB_ONLYDIR) as $dir) {
                if (preg_match('#wpmcp-static-(\d+)-[a-f0-9]+\.work$#', (string) $dir, $m) && ! in_array((int) $m[1], $running, true)) {
                    Static_Site_Build::discard((string) $dir);
                }
            }
        } catch (\Throwable $e) {
            unset($e);
        }
    }
}
