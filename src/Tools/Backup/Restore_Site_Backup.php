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
 * Current state: only the dry_run compatibility report is implemented.
 * A real restore (dry_run=false) passes the same gate and is then refused
 * with a self-contained message. The execution path (pre-restore safety
 * archive, maintenance mode, statement-by-statement import, session
 * survival, staged wp-content swap) is tracked in issue #190.
 */
class Restore_Site_Backup
{
    /**
     * The newest archive format this build knows how to restore. An archive
     * with a newer format_version is refused: it may contain structures
     * this importer does not understand, and guessing is how sites die.
     */
    private const MAX_FORMAT_VERSION = 1;

    /**
     * Archive scopes that carry a database dump. A files-only or
     * uploads-only archive has nothing this tool can restore in phase 1.
     */
    private const RESTORABLE_SCOPES = ['all', 'database'];

    public function handle(array $args): array
    {
        $dry_run       = ! isset($args['dry_run']) || (bool) $args['dry_run'];
        $include_files = ! empty($args['include_files']);

        $archive  = Archive_Locator::resolve($args);
        $manifest = Archive_Locator::read_manifest($archive);

        $report = $this->compatibility_report($archive, $manifest, $include_files);

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

        // The execution path (issue #190) is not in this build. Nothing above
        // this line has written anything, so refusing here is side-effect free.
        throw new \RuntimeException(
            'Executing a restore is not implemented in this build; only dry_run compatibility reports are supported.'
        );
    }

    /**
     * Compare the archive against the target site. Refusals are conditions
     * under which a restore would corrupt the target or cannot be carried
     * out at all (wrong prefix, unknown or newer format, a manifest missing
     * the fields the gate depends on, an archive without a database dump);
     * warnings are survivable but worth surfacing (WordPress downgrade,
     * BLOB tables that round-tripped through escaped string literals).
     *
     * Absent fields are refusals, not defaults: a manifest that does not say
     * which prefix it was taken from is not evidence that it matches.
     *
     * @return array{refusals: string[], warnings: string[]}
     */
    private function compatibility_report(string $archive, array $manifest, bool $include_files): array
    {
        global $wpdb, $wp_version;

        $refusals = [];
        $warnings = [];

        if ('wpmcp-site-backup' !== (string) ($manifest['format'] ?? '')) {
            $refusals[] = 'This archive was not produced by this plugin (unknown manifest format).';
        }

        $format_version = $manifest['format_version'] ?? null;
        if (! is_int($format_version) || $format_version < 1) {
            $refusals[] = 'The manifest has no usable format_version; refusing to guess which archive layout this is.';
        } elseif ($format_version > self::MAX_FORMAT_VERSION) {
            $refusals[] = sprintf(
                'Archive format_version %d is newer than the latest this build understands (%d); update the plugin before restoring.',
                $format_version,
                self::MAX_FORMAT_VERSION
            );
        }

        $scope = (string) ($manifest['scope'] ?? '');
        if (! in_array($scope, self::RESTORABLE_SCOPES, true)) {
            $refusals[] = sprintf(
                'The archive scope is "%s", which carries no database dump; only "all" or "database" archives can be restored.',
                esc_html($scope)
            );
        } elseif (! self::zip_has_entry($archive, 'db.sql')) {
            $refusals[] = 'The archive has no db.sql entry although its manifest claims a database scope; it may be truncated or hand-edited.';
        }

        if ($include_files && 'all' !== $scope) {
            $refusals[] = sprintf(
                'include_files was requested but the archive scope is "%s", which carries no wp-content.',
                esc_html($scope)
            );
        }

        if (! isset($manifest['site']['table_prefix']) || '' === (string) $manifest['site']['table_prefix']) {
            $refusals[] = 'The manifest does not record a table_prefix; refusing to guess whether it matches this site.';
        } else {
            $archive_prefix = (string) $manifest['site']['table_prefix'];
            if ($archive_prefix !== $wpdb->prefix) {
                $refusals[] = sprintf(
                    'Table prefix mismatch: the archive uses "%s" but this site uses "%s". A same-site restore cannot change the prefix.',
                    esc_html($archive_prefix),
                    esc_html($wpdb->prefix)
                );
            }
        }

        if (! isset($manifest['site']['multisite'])) {
            $refusals[] = 'The manifest does not record whether the source was multisite; refusing to guess.';
        } else {
            $archive_multisite = (bool) $manifest['site']['multisite'];
            if ($archive_multisite !== is_multisite()) {
                $refusals[] = sprintf(
                    'Multisite mismatch: the archive is from a %s install but this site is %s.',
                    $archive_multisite ? 'multisite' : 'single-site',
                    is_multisite() ? 'multisite' : 'single-site'
                );
            }
        }

        $archive_wp = (string) ($manifest['versions']['wordpress'] ?? '');
        $target_wp  = (string) ($wp_version ?? '');
        if ('' !== $archive_wp && '' !== $target_wp && version_compare($target_wp, $archive_wp, '<')) {
            $warnings[] = sprintf(
                'The archive was taken on WordPress %s but this site runs %s; restoring is a database downgrade and core may re-run migrations.',
                esc_html($archive_wp),
                esc_html($target_wp)
            );
        }

        $blob_tables = (array) ($manifest['database']['blob_tables'] ?? []);
        if (! empty($blob_tables)) {
            $warnings[] = sprintf(
                'These tables hold BLOB columns that round-tripped through escaped string literals and may need spot-checking after restore: %s.',
                esc_html(implode(', ', array_map('strval', $blob_tables)))
            );
        }

        return [
            'refusals' => $refusals,
            'warnings' => $warnings,
        ];
    }

    /**
     * Whether the archive contains an entry with exactly this name, without
     * extracting anything. The manifest was already read, so the zip is
     * known to open; a failure here is treated as the entry being absent.
     */
    private static function zip_has_entry(string $archive, string $name): bool
    {
        $zip = new \ZipArchive();
        if (true !== $zip->open($archive)) {
            return false;
        }

        $found = false !== $zip->locateName($name);
        $zip->close();

        return $found;
    }
}
