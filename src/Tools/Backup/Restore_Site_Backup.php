<?php

namespace WPMCP\Tools\Backup;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Restore a site in place from a site-backup archive produced by
 * Site_Archive_Builder (issue #190, phase 1).
 *
 * This is the one tool in the plugin that can destroy a site, so it is
 * built gate-first: everything that can refuse does so before anything
 * writes. dry_run defaults to TRUE on purpose: an agent that fires this
 * ability from a vague instruction hits a compatibility report, not a
 * restore. The archive reference is resolved through Archive_Locator, so
 * the same containment rules apply as for reading or deleting an archive.
 *
 * Order of operations for a real restore (each one a hard stop on failure):
 *   1. Resolve the archive and read its manifest (a truncated or
 *      non-archive zip is refused here, before anything else happens).
 *   2. Compatibility gate: format_version, table_prefix, multisite must
 *      match the target; a WordPress downgrade is a warning, not a refusal.
 *   3. Pre-restore safety archive (database scope) of the CURRENT state,
 *      built synchronously. If it fails, the restore does not start.
 *   4. Maintenance mode on (wpmcp_maintenance option, enforced by
 *      Maintenance\Maintenance_Guard) for the duration of the import.
 *   5. Statement-by-statement SQL import with a bounded read of db.sql out
 *      of the zip (never getFromString on the whole dump), tracking the
 *      last statement executed so a mid-import failure reports where it
 *      stopped and still turns maintenance mode off.
 *   6. Optional wp-content restore (include_files), extracted to a staging
 *      directory and swapped, never over the live tree.
 */
class Restore_Site_Backup
{
    /**
     * The newest archive format this build knows how to restore. An archive
     * with a newer format_version is refused: it may contain structures
     * this importer does not understand, and guessing is how sites die.
     */
    private const MAX_FORMAT_VERSION = 1;

    public function handle(array $args): array
    {
        $dry_run       = ! isset($args['dry_run']) || (bool) $args['dry_run'];
        $include_files = ! empty($args['include_files']);

        $archive  = Archive_Locator::resolve($args);
        $manifest = Archive_Locator::read_manifest($archive);

        $report = $this->compatibility_report($manifest);

        if ($dry_run) {
            return [
                'dry_run'       => true,
                'file'          => $archive,
                'compatible'    => empty($report['refusals']),
                'refusals'      => $report['refusals'],
                'warnings'      => $report['warnings'],
                'scope'         => (string) ($manifest['scope'] ?? ''),
                'include_files' => $include_files,
                'manifest'      => $manifest,
            ];
        }

        if (! empty($report['refusals'])) {
            throw new \RuntimeException(
                'Restore refused: ' . implode(' ', $report['refusals'])
            );
        }

        // TODO(#190): pre-restore safety archive (database scope) of the
        // current state via Site_Archive_Builder, synchronously; abort the
        // restore if it fails, and report its path/job id in the result
        // even when the restore itself later fails.
        // TODO(#190): enable maintenance mode (wpmcp_maintenance option)
        // for the duration, and guarantee it is turned off again on every
        // exit path, including a mid-import throw.
        // TODO(#190): statement-by-statement import of db.sql via a bounded
        // zip stream read, tracking the last executed statement.
        // TODO(#190): session survival after wp_users/wp_usermeta are
        // replaced, or an explicit re-login notice in the result.
        // TODO(#190): include_files staging-dir extract and swap.
        throw new \RuntimeException(
            'Executing a restore is not implemented yet; this build only supports dry_run compatibility reports. Track progress in docs/wip/issue-190.md.'
        );
    }

    /**
     * Compare the archive's manifest against the target site. Refusals are
     * conditions under which a restore would corrupt the target (wrong
     * prefix, unknown format); warnings are survivable but worth surfacing
     * (WordPress downgrade, BLOB tables that round-tripped through escaped
     * string literals).
     *
     * @return array{refusals: string[], warnings: string[]}
     */
    private function compatibility_report(array $manifest): array
    {
        global $wpdb, $wp_version;

        $refusals = [];
        $warnings = [];

        if ('wpmcp-site-backup' !== (string) ($manifest['format'] ?? '')) {
            $refusals[] = 'This archive was not produced by this plugin (unknown manifest format).';
        }

        $format_version = (int) ($manifest['format_version'] ?? 0);
        if ($format_version > self::MAX_FORMAT_VERSION) {
            $refusals[] = sprintf(
                'Archive format_version %d is newer than the latest this build understands (%d); update the plugin before restoring.',
                $format_version,
                self::MAX_FORMAT_VERSION
            );
        }

        $archive_prefix = (string) ($manifest['site']['table_prefix'] ?? '');
        if ('' !== $archive_prefix && $archive_prefix !== $wpdb->prefix) {
            $refusals[] = sprintf(
                'Table prefix mismatch: the archive uses "%s" but this site uses "%s". A same-site restore cannot change the prefix.',
                $archive_prefix,
                $wpdb->prefix
            );
        }

        $archive_multisite = (bool) ($manifest['site']['multisite'] ?? false);
        if ($archive_multisite !== is_multisite()) {
            $refusals[] = sprintf(
                'Multisite mismatch: the archive is from a %s install but this site is %s.',
                $archive_multisite ? 'multisite' : 'single-site',
                is_multisite() ? 'multisite' : 'single-site'
            );
        }

        $archive_wp = (string) ($manifest['versions']['wordpress'] ?? '');
        $target_wp  = (string) ($wp_version ?? '');
        if ('' !== $archive_wp && '' !== $target_wp && version_compare($target_wp, $archive_wp, '<')) {
            $warnings[] = sprintf(
                'The archive was taken on WordPress %s but this site runs %s; restoring is a database downgrade and core may re-run migrations.',
                $archive_wp,
                $target_wp
            );
        }

        $blob_tables = (array) ($manifest['database']['blob_tables'] ?? []);
        if (! empty($blob_tables)) {
            $warnings[] = sprintf(
                'These tables hold BLOB columns that round-tripped through escaped string literals and may need spot-checking after restore: %s.',
                implode(', ', array_map('strval', $blob_tables))
            );
        }

        return [
            'refusals' => $refusals,
            'warnings' => $warnings,
        ];
    }
}
