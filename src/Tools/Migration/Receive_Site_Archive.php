<?php

namespace WPMCP\Tools\Migration;

use WPMCP\Tools\Backup\Restore_Site_Backup;
use WPMCP\Tools\Backup\Safety_Archive;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The target half of a site-to-site push (issue #191, phase 2): the
 * wpmcp/receive-site-archive ability, which push-site-archive on the source
 * calls over the target's own Abilities REST endpoint.
 *
 * Authentication is the target's, not ours: the source presents an
 * application password (or an MCP OAuth bearer token) of a user on THIS
 * site, WordPress resolves it to that user, and the ordinary ability gates
 * run: manage_options, governance, identity scope, rate limiting, the
 * request log. On top of those, nothing here runs unless the site owner
 * has opened Migration_Guard's receive gate on this site.
 *
 * One ability, four actions, so a governance toggle or the receive gate
 * closes the whole surface at once:
 *
 *  - start:  declare an archive (sha256, size, manifest). The manifest is
 *            checked against this site first (format, scope, table prefix,
 *            multisite), so an archive this site could never restore is
 *            refused before a single byte is sent. A start that names an
 *            archive already partly received resumes it.
 *  - chunk:  one base64 chunk at an offset (see Incoming_Archive_Store).
 *  - status: where an upload stands, including the result of an apply
 *            whose response the source never saw (a timeout mid-restore).
 *  - apply:  (a failed apply may be retried; a successful one is
 *            reported, never repeated) verify the whole archive (sha256, and the manifest inside it
 *            against the declared one), restore it with restore-site-backup's
 *            engine (which takes the pre-restore safety archive, and refuses
 *            to start without one), then rewrite every URL from the source's
 *            (read from the manifest) to this site's, recorded before the
 *            restore replaced them. dry_run defaults to true; applying needs
 *            confirm:true.
 *
 * Failures are returned as WP_Error rather than thrown, because the
 * Abilities REST run controller has no exception handling of its own and a
 * source should get a readable reason, not a critical-error page.
 */
class Receive_Site_Archive
{
    private const MAX_FORMAT_VERSION = 1;

    private const RESTORABLE_SCOPES = ['all', 'database'];

    /** Largest raw chunk this site will ask for, whatever post_max_size allows. */
    public const CHUNK_CEILING = 8388608;

    private const CHUNK_FLOOR = 65536;

    /** @var callable|null */
    private $safety_producer;

    /**
     * @param callable|null $safety_producer The pre-restore safety archive
     *        producer handed to Restore_Site_Backup; null means the real one.
     */
    public function __construct(?callable $safety_producer = null)
    {
        $this->safety_producer = $safety_producer;
    }

    /** @return array<string, mixed>|\WP_Error */
    public function handle(array $args)
    {
        if (! Migration_Guard::accepts_incoming()) {
            return Migration_Guard::incoming_disabled_error();
        }

        $action = (string) ($args['action'] ?? '');

        try {
            switch ($action) {
                case 'start':
                    return $this->start($args);
                case 'chunk':
                    return $this->chunk($args);
                case 'status':
                    return Incoming_Archive_Store::with_progress(Incoming_Archive_Store::get((string) ($args['upload_id'] ?? '')));
                case 'apply':
                    return $this->apply($args);
                default:
                    return new \WP_Error('wpmcp_migration_bad_action', 'action must be one of start, chunk, status, apply.', ['status' => 400]);
            }
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            return new \WP_Error('wpmcp_migration_failed', $e->getMessage(), ['status' => 400]);
        }
    }

    /** @return array<string, mixed>|\WP_Error */
    private function start(array $args)
    {
        $manifest = isset($args['manifest']) && is_array($args['manifest']) ? $args['manifest'] : [];
        $refusals = self::preflight($manifest);
        if ([] !== $refusals) {
            return new \WP_Error(
                'wpmcp_migration_incompatible',
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- preflight() escapes every operand it interpolates; this is a REST error message, not HTML output.
                'This site cannot receive that archive: ' . implode(' ', $refusals),
                ['status' => 409, 'refusals' => $refusals]
            );
        }

        $target = self::target_urls();
        $plan   = self::rewrite_pairs((array) ($manifest['site'] ?? []), $target['home_url'], $target['site_url']);
        $common = [
            'target'          => $target,
            'chunk_bytes_max' => self::chunk_bytes_max(),
            'rewrite'         => $plan,
        ];

        if (false !== ($args['dry_run'] ?? false)) {
            return ['dry_run' => true, 'accepted' => true] + $common;
        }

        $state = Incoming_Archive_Store::start(
            strtolower((string) ($args['sha256'] ?? '')),
            (int) ($args['bytes'] ?? 0),
            $manifest,
            esc_url_raw((string) ($args['source_url'] ?? '')),
            true === ($args['restart'] ?? false)
        );

        return ['dry_run' => false, 'accepted' => true] + $state + $common;
    }

    /** @return array<string, mixed> */
    private function chunk(array $args): array
    {
        $data = (string) ($args['data'] ?? '');
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decodes the transfer encoding of an archive chunk (JSON cannot carry raw bytes); the result is written to disk, never executed.
        $raw = base64_decode($data, true);
        if (false === $raw || '' === $raw) {
            throw new \InvalidArgumentException('data must be a non-empty base64 string.');
        }

        return Incoming_Archive_Store::append((string) ($args['upload_id'] ?? ''), (int) ($args['offset'] ?? -1), $raw);
    }

    /** @return array<string, mixed>|\WP_Error */
    private function apply(array $args)
    {
        $upload_id     = (string) ($args['upload_id'] ?? '');
        $dry_run       = false !== ($args['dry_run'] ?? true);
        $confirm       = true === ($args['confirm'] ?? null);
        $include_files = true === ($args['include_files'] ?? false);

        $state = Incoming_Archive_Store::get($upload_id);
        if ('applied' === $state['status']) {
            return ['already_applied' => true] + (array) $state['result'];
        }
        if ('applying' === $state['status']) {
            return new \WP_Error('wpmcp_migration_busy', 'That upload is being applied right now; poll action=status for the result. If the request applying it died, start with restart:true discards this record.', ['status' => 409]);
        }
        if (! $dry_run && ! $confirm) {
            throw new \WPMCP\MCP\Confirmation_Required('Applying a migration replaces this site\'s database and requires confirm:true. Run with dry_run:true first.');
        }

        // Step 1: nothing about the upload is trusted until this passes.
        $state    = Incoming_Archive_Store::finalize($upload_id);
        $archive  = (string) $state['archive'];
        $manifest = (array) Incoming_Archive_Store::get($upload_id)['manifest'];
        $verify   = ['ok' => true, 'sha256' => $state['sha256'], 'bytes' => (int) $state['bytes'], 'archive' => $archive];

        // Recorded BEFORE the restore: afterwards the options table holds
        // the source's URLs, and that is exactly what is being rewritten.
        $target = self::target_urls();
        $source = [
            'home_url' => untrailingslashit((string) ($manifest['site']['home_url'] ?? '')),
            'site_url' => untrailingslashit((string) ($manifest['site']['site_url'] ?? '')),
        ];
        $plan    = self::rewrite_pairs((array) ($manifest['site'] ?? []), $target['home_url'], $target['site_url']);
        $restore = new Restore_Site_Backup($this->safety_producer);

        if ($dry_run) {
            $report = $restore->handle(['path' => $archive, 'dry_run' => true, 'include_files' => $include_files]);
            unset($report['manifest']);

            return [
                'dry_run'   => true,
                'upload_id' => $upload_id,
                'source'    => $source,
                'target'    => $target,
                'steps'     => ['verify' => $verify, 'restore' => $report, 'rewrite' => $plan],
            ];
        }

        Incoming_Archive_Store::begin_apply($upload_id);
        if (function_exists('ignore_user_abort')) {
            ignore_user_abort(true);
        }

        $result = [
            'dry_run'   => false,
            'upload_id' => $upload_id,
            'source'    => $source,
            'target'    => $target,
            'archive'   => $archive,
        ];

        // Step 2: the restore. It takes its own pre-restore safety archive
        // and refuses to start without one, rolls itself back on a failed
        // import, and keeps the acting user signed in where it can.
        try {
            $restored = $restore->handle([
                'path'             => $archive,
                'dry_run'          => false,
                'include_files'    => $include_files,
                'preserve_session' => true,
            ]);
        } catch (\Throwable $e) {
            $result += [
                'status' => 'restore_failed',
                'steps'  => ['verify' => $verify, 'restore' => ['restored' => false, 'error' => $e->getMessage()], 'rewrite' => null],
            ];
            Incoming_Archive_Store::update($upload_id, ['status' => 'failed', 'result' => $result]);

            return $result;
        }

        $safety                   = $restored['safety_archive'] ?? null;
        $result['safety_archive'] = $safety;
        if (is_array($safety)) {
            $result['undo'] = Safety_Archive::undo_hint((int) $safety['job_id']);
        }

        if (empty($restored['restored'])) {
            $result += [
                'status' => 'restore_failed',
                'steps'  => ['verify' => $verify, 'restore' => $restored, 'rewrite' => null],
            ];
            Incoming_Archive_Store::update($upload_id, ['status' => 'failed', 'result' => $result]);

            return $result;
        }

        // Step 3: the URL rewrite, recoverable through the pre-restore
        // safety archive (which predates the whole migration), so no second
        // whole-database dump is taken.
        $rewrite = $this->rewrite($plan, is_array($safety) ? $safety : null);

        $result += [
            'status' => $rewrite['ok'] ? 'migrated' : 'migrated_with_rewrite_errors',
            'steps'  => ['verify' => $verify, 'restore' => $restored, 'rewrite' => $rewrite],
        ];
        if (! $rewrite['ok']) {
            $result['next'] = 'The database was restored but some URLs were not rewritten; see steps.rewrite. Re-run rewrite-site-urls for the listed pair, or restore the safety archive to undo the whole migration.';
        }

        Incoming_Archive_Store::update($upload_id, ['status' => 'applied', 'result' => $result]);

        return $result;
    }

    /**
     * Run each planned rewrite pair. The first pair that throws stops the
     * rest: the pairs are ordered longest-from first on purpose, and running
     * a shorter pair over a half-finished longer one is how a URL ends up
     * rewritten twice.
     *
     * @return array{ok: bool, pairs: array, results: array, warnings: array, error?: string}
     */
    private function rewrite(array $plan, ?array $safety): array
    {
        $results = [];
        $ok      = true;
        $error   = null;

        foreach ($plan['pairs'] as $pair) {
            try {
                $out = (new Rewrite_Site_Urls())->handle(
                    ['from_url' => $pair['from'], 'to_url' => $pair['to'], 'dry_run' => false, 'confirm' => true],
                    $safety
                );
            } catch (\Throwable $e) {
                $ok    = false;
                $error = sprintf('%s -> %s: %s', $pair['from'], $pair['to'], $e->getMessage());
                break;
            }

            foreach ($out['tables'] as $table) {
                if (! empty($table['rows_failed'])) {
                    $ok = false;
                }
            }
            $results[] = ['from' => $pair['from'], 'to' => $pair['to'], 'tables' => $out['tables']];
        }

        $report = ['ok' => $ok, 'pairs' => $plan['pairs'], 'results' => $results, 'warnings' => $plan['warnings']];
        if (null !== $error) {
            $report['error'] = $error;
        }

        return $report;
    }

    /**
     * The manifest checks restore would make, made before any upload. The
     * real gate still runs at apply time; this only saves pushing gigabytes
     * to hear "wrong table prefix".
     *
     * @return string[] Refusals; empty when the archive could be restored here.
     */
    public static function preflight(array $manifest): array
    {
        global $wpdb;

        $refusals = [];

        if ('wpmcp-site-backup' !== (string) ($manifest['format'] ?? '')) {
            $refusals[] = 'The manifest is not a wpmcp site-backup manifest.';
        }

        $version = $manifest['format_version'] ?? null;
        if (! is_int($version) || $version < 1 || $version > self::MAX_FORMAT_VERSION) {
            $refusals[] = sprintf('Archive format_version %s is not one this site understands.', esc_html(wp_json_encode($version)));
        }

        $scope = (string) ($manifest['scope'] ?? '');
        if (! in_array($scope, self::RESTORABLE_SCOPES, true)) {
            $refusals[] = sprintf('The archive scope "%s" carries no database; only all or database archives can be migrated.', esc_html($scope));
        }

        $prefix = (string) ($manifest['site']['table_prefix'] ?? '');
        if ($prefix !== (string) $wpdb->prefix) {
            $refusals[] = sprintf(
                'Table prefix mismatch: the archive uses "%s" but this site uses "%s".',
                esc_html($prefix),
                esc_html((string) $wpdb->prefix)
            );
        }

        if (! isset($manifest['site']['multisite']) || (bool) $manifest['site']['multisite'] !== is_multisite()) {
            $refusals[] = 'Multisite mismatch (or unrecorded): migration between single-site and multisite installs is not supported.';
        }

        foreach (['home_url', 'site_url'] as $key) {
            if (! preg_match('#^https?://#', (string) ($manifest['site'][ $key ] ?? ''))) {
                $refusals[] = sprintf('The manifest does not record the source %s, so its URLs could not be rewritten.', esc_html($key));
            }
        }

        return $refusals;
    }

    /**
     * Which URL pairs to rewrite, in which order.
     *
     * The home pair always runs when the URLs differ. The site_url pair runs
     * only when it is not already covered by the home pair (the usual case
     * of WordPress in the site root is covered: both URLs are the same).
     * Longest "from" first so a shorter URL cannot consume part of a longer
     * one. When one pair's "to" contains another's "from" (a staging copy
     * in a subdirectory of the same host), running both would rewrite fresh
     * URLs a second time, so only the home pair runs and the other is
     * reported as a warning.
     *
     * @return array{pairs: array<int, array{from: string, to: string}>, warnings: string[]}
     */
    public static function rewrite_pairs(array $source_site, string $target_home, string $target_site): array
    {
        $src_home = untrailingslashit((string) ($source_site['home_url'] ?? ''));
        $src_site = untrailingslashit((string) ($source_site['site_url'] ?? ''));
        $target_home = untrailingslashit($target_home);
        $target_site = untrailingslashit($target_site);

        $pairs    = [];
        $warnings = [];

        if ('' !== $src_home && $src_home !== $target_home) {
            $pairs[] = ['from' => $src_home, 'to' => $target_home];
        }

        if ('' !== $src_site && $src_site !== $target_site) {
            $covered = '' !== $src_home
                && str_starts_with($src_site, $src_home)
                && str_starts_with($target_site, $target_home)
                && substr($src_site, strlen($src_home)) === substr($target_site, strlen($target_home))
                && $src_home !== $target_home;
            if (! $covered) {
                $pairs[] = ['from' => $src_site, 'to' => $target_site];
            }
        }

        usort($pairs, static fn (array $a, array $b): int => strlen($b['from']) <=> strlen($a['from']));

        $kept = $pairs;
        foreach ($pairs as $i => $earlier) {
            foreach (array_slice($pairs, $i + 1) as $later) {
                if (! str_contains($earlier['to'], $later['from'])) {
                    continue;
                }
                // Two passes would rewrite fresh URLs a second time. Keep
                // only the home pair: it covers content, media and links,
                // which is nearly every URL a site stores.
                $kept = array_values(array_filter($pairs, static fn (array $p): bool => $p['from'] === $src_home));
                foreach ($pairs as $pair) {
                    if ($pair['from'] !== $src_home) {
                        $warnings[] = sprintf(
                            'Not rewriting %s -> %s automatically: the target URLs contain the source URL, so a second pass would rewrite fresh URLs again. Check URLs under that path afterwards and fix them with rewrite-site-urls if needed.',
                            $pair['from'],
                            $pair['to']
                        );
                    }
                }
                break 2;
            }
        }

        if ([] === $kept && [] === $warnings) {
            $warnings[] = 'The source and this site have the same URLs, so no rewrite is needed.';
        }

        return ['pairs' => $kept, 'warnings' => $warnings];
    }

    /**
     * This site's own URLs, from the options (which honour WP_HOME and
     * WP_SITEURL) rather than home_url(), which plugins may filter per
     * request or per language.
     *
     * @return array{home_url: string, site_url: string}
     */
    public static function target_urls(): array
    {
        return [
            'home_url' => untrailingslashit((string) get_option('home')),
            'site_url' => untrailingslashit((string) get_option('siteurl')),
        ];
    }

    /**
     * The largest raw chunk that fits this site's post_max_size once base64
     * encoded (4/3) and wrapped in JSON, with headroom, clamped to a sane
     * range.
     */
    public static function chunk_bytes_max(): int
    {
        $post_max = (int) wp_convert_hr_to_bytes((string) ini_get('post_max_size'));
        if ($post_max <= 0) {
            return self::CHUNK_CEILING;
        }

        $raw = (int) floor(($post_max - 65536) * 3 / 4 * 0.9);

        return max(self::CHUNK_FLOOR, min(self::CHUNK_CEILING, $raw));
    }
}
