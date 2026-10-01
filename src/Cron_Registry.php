<?php

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols -- ABSPATH guard is an intentional side effect.

namespace WPMCP;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Every WP-Cron hook the plugin schedules, in one place (issue #468).
 *
 * Deactivation and uninstall clear exactly these hooks, so a scheduling call
 * on a hook missing from here would leave events behind once the plugin is
 * gone. tests/free/Cron/CronHookRegistryTest walks src/ at token level and
 * fails on any such call.
 *
 * Hooks and job stores are named by string, never by class: the directory
 * builds prune some of the classes behind them (the async wp-cli jobs, the
 * image optimizer), and this file ships in every build and is read by
 * uninstall.php without an autoloader. The guard test pins each string to
 * the owning class's constant.
 *
 * Two kinds:
 *
 *  - RECURRING_HOOKS are driven by settings (the OAuth sweep while OAuth is
 *    on, one event per backup schedule). Deactivation clears them; the
 *    settings stay, and Activator puts back only the ones those settings
 *    still ask for, at the configured time.
 *  - JOB_HOOKS are single events that move one background job (a backup,
 *    an async wp-cli command, a broken-link scan, an image optimization run)
 *    one step forward, passing the job id. A job still waiting on its next
 *    event when the plugin is deactivated is marked canceled, with the reason
 *    in its error field, and is not resumed on reactivation. Resuming would
 *    run a backup or a wp-cli command at an arbitrary later time the user
 *    did not choose, and a half-finished optimize run would resume against a
 *    library that may have changed since. Leaving it "queued" or "running"
 *    with no event to move it would hold snapshot pruning (optimize runs) and
 *    count against the in-flight cap (wp-cli jobs) until it went stale.
 *    Progress made so far stays on the record.
 */
final class Cron_Registry
{
    /** Settings-driven recurring hooks. Oauth_Gc::HOOK, Backup_Schedule::HOOK. */
    public const RECURRING_HOOKS = [
        'wpmcp_oauth_gc',
        'wpmcp_run_backup_schedule',
    ];

    /**
     * Background-job hooks => [store option, record collection key]. Each
     * store keeps { next_id, <collection>: { id => { status, error, ... } } }.
     */
    public const JOB_HOOKS = [
        'wpmcp_run_backup_job'         => [ 'wpmcp_backup_jobs', 'jobs' ],
        'wpmcp_run_cli_job'            => [ 'wpmcp_cli_jobs', 'jobs' ],
        'wpmcp_run_broken_link_scan'   => [ 'wpmcp_broken_link_scans', 'scans' ],
        'wpmcp_run_optimize_media_job' => [ 'wpmcp_optimize_media_jobs', 'jobs' ],
    ];

    /** Statuses a job can still be moved out of by its next event. */
    private const ACTIVE = [ 'queued', 'running' ];

    public const CANCELED_ERROR = 'Canceled because WP MCP was deactivated before the job finished. Start it again if you still need it.';

    /** @return string[] Every hook the plugin schedules. */
    public static function hooks(): array
    {
        return array_merge(self::RECURRING_HOOKS, array_keys(self::JOB_HOOKS));
    }

    /**
     * Remove every event on every registered hook from this site's cron
     * array, marking the background jobs they would have moved as canceled
     * first.
     */
    public static function clear_all(): void
    {
        self::cancel_pending_jobs();

        foreach (self::hooks() as $hook) {
            wp_unschedule_hook($hook);
        }
    }

    /**
     * Run $callback once per site: every site of the network when $all_sites
     * is set on multisite, otherwise just the current one.
     */
    public static function for_each_site(bool $all_sites, callable $callback): void
    {
        if (! $all_sites || ! is_multisite() || ! function_exists('get_sites')) {
            $callback();
            return;
        }

        foreach (get_sites([ 'fields' => 'ids', 'number' => 0 ]) as $site_id) {
            switch_to_blog((int) $site_id);
            try {
                $callback();
            } finally {
                restore_current_blog();
            }
        }
    }

    /** Mark canceled every active job that still has an event pending. */
    private static function cancel_pending_jobs(): void
    {
        $pending = [];
        foreach ((array) _get_cron_array() as $hooks) {
            foreach (array_intersect_key((array) $hooks, self::JOB_HOOKS) as $hook => $events) {
                foreach ((array) $events as $event) {
                    $id = (int) (((array) ($event['args'] ?? []))[0] ?? 0);
                    if ($id > 0) {
                        $pending[ $hook ][ $id ] = true;
                    }
                }
            }
        }

        foreach ($pending as $hook => $ids) {
            [ $option, $collection ] = self::JOB_HOOKS[ $hook ];
            $stored = get_option($option, []);
            if (! is_array($stored) || ! is_array($stored[ $collection ] ?? null)) {
                continue;
            }

            $changed = false;
            foreach (array_keys($ids) as $id) {
                $record = $stored[ $collection ][ $id ] ?? null;
                if (! is_array($record) || ! in_array($record['status'] ?? '', self::ACTIVE, true)) {
                    continue;
                }
                $record['status']     = 'canceled';
                $record['error']      = self::CANCELED_ERROR;
                $record['updated_at'] = time();
                $stored[ $collection ][ $id ] = $record;
                $changed = true;
            }

            if ($changed) {
                update_option($option, $stored);
            }
        }
    }
}
