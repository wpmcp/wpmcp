<?php

namespace WPMCP\Tools\Migration;

use WPMCP\Tools\Backup\Archive_Locator;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The source half of a site-to-site push (issue #191, phase 2): the
 * wpmcp/push-site-archive ability.
 *
 * Sends a site-backup archive from this site to another wpmcp install and,
 * when asked, has the target restore it and rewrite its URLs. The target
 * does all of the dangerous work under its own gates (see
 * Receive_Site_Archive); this side reads a local archive and talks HTTP to
 * exactly one host, the target_url the operator supplied.
 *
 * Resumable by construction, and time-bounded per call: each invocation
 * asks the target how much of this archive (identified by sha256 and size)
 * it already holds, sends chunks from there until max_seconds is spent, and
 * reports progress. Calling again with the same arguments continues. That
 * is how a 2GB archive gets through hosts whose request limits are a small
 * fraction of it, on either side. Nothing is kept on this side between
 * calls, credentials included: the target's upload record is the only
 * state, and it lives on the target's disk.
 *
 * Modes:
 *  - dry_run (default true): the target checks the manifest against itself
 *    (format, scope, table prefix, multisite) and reports the URL pairs it
 *    would rewrite. Nothing is uploaded.
 *  - dry_run:false + confirm:true: upload. When the upload completes without
 *    apply, the target verifies the archive and runs restore's dry run, so
 *    the report shows exactly what an apply would do.
 *  - apply:true as well: the target restores the archive (pre-restore
 *    safety archive first, refusing without one), then rewrites URLs, and
 *    this call returns the target's per-step report and safety archive id.
 */
class Push_Site_Archive
{
    public const DEFAULT_CHUNK_BYTES = 2097152;

    private const MIN_CHUNK_BYTES = 1024;

    private const DEFAULT_MAX_SECONDS = 20;

    private const MAX_MAX_SECONDS = 300;

    private const DEFAULT_APPLY_TIMEOUT = 900;

    /** Times a chunk is re-synced and retried before the call gives up. */
    private const MAX_RETRIES = 3;

    /** @var callable(): float */
    private $clock;

    /** @param callable(): float|null $clock Test seam for the time budget. */
    public function __construct(?callable $clock = null)
    {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    /** @return array<string, mixed>|\WP_Error */
    public function handle(array $args)
    {
        if (! Migration_Guard::allows_outgoing()) {
            return Migration_Guard::outgoing_disabled_error();
        }

        try {
            return $this->push($args);
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            return new \WP_Error('wpmcp_migration_push_failed', $e->getMessage());
        }
    }

    /** @return array<string, mixed>|\WP_Error */
    private function push(array $args)
    {
        $dry_run       = false !== ($args['dry_run'] ?? true);
        $confirm       = true === ($args['confirm'] ?? null);
        $apply         = true === ($args['apply'] ?? false);
        $include_files = true === ($args['include_files'] ?? false);
        $restart       = true === ($args['restart'] ?? false);
        $max_seconds   = max(1, min(self::MAX_MAX_SECONDS, (int) ($args['max_seconds'] ?? self::DEFAULT_MAX_SECONDS)));

        if (! $dry_run && ! $confirm) {
            throw new \InvalidArgumentException('Pushing an archive requires confirm:true (and apply:true replaces the target\'s database). Run with dry_run:true first.');
        }

        $archive  = Archive_Locator::resolve($args);
        $manifest = Archive_Locator::read_manifest($archive);
        if (! in_array((string) ($manifest['scope'] ?? ''), ['all', 'database'], true)) {
            throw new \InvalidArgumentException('Only archives of scope all or database carry a database and can be migrated.');
        }

        $client = new Migration_Target_Client(
            (string) ($args['target_url'] ?? ''),
            (string) ($args['target_user'] ?? ''),
            (string) ($args['target_app_password'] ?? ''),
            (string) ($args['target_token'] ?? '')
        );

        clearstatcache(true, $archive);
        $bytes  = (int) filesize($archive);
        $sha256 = self::sha256($archive, $bytes);

        $base = [
            'dry_run'    => $dry_run,
            'archive'    => $archive,
            'target_url' => untrailingslashit((string) $args['target_url']),
            'bytes'      => $bytes,
            'sha256'     => $sha256,
            'source'     => [
                'home_url' => (string) ($manifest['site']['home_url'] ?? ''),
                'site_url' => (string) ($manifest['site']['site_url'] ?? ''),
            ],
        ];

        $start = $client->call([
            'action'     => 'start',
            'sha256'     => $sha256,
            'bytes'      => $bytes,
            'manifest'   => $manifest,
            'source_url' => untrailingslashit((string) get_option('home')),
            'dry_run'    => $dry_run,
            'restart'    => $restart,
        ]);
        if (is_wp_error($start)) {
            return $start;
        }

        $base['target']  = $start['target'] ?? null;
        $base['rewrite'] = $start['rewrite'] ?? null;

        if ($dry_run) {
            return $base + [
                'status' => 'ready',
                'next'   => 'The target accepts this archive. Call again with dry_run:false and confirm:true to upload it, adding apply:true to restore it there.',
            ];
        }

        $upload_id = (string) ($start['upload_id'] ?? '');
        $state     = (string) ($start['status'] ?? '');
        $received  = (int) ($start['received_bytes'] ?? 0);

        $base['upload_id'] = $upload_id;
        $base['resumed']   = ! empty($start['resumed']);

        if (in_array($state, ['applied', 'failed', 'applying'], true)) {
            return $base + [
                'status'         => 'applying' === $state ? 'applying' : 'already_applied',
                'received_bytes' => $received,
                'target_result'  => $start['result'] ?? null,
                'next'           => 'applying' === $state
                    ? 'The target is applying this archive now; call again later to read the result.'
                    : 'The target already applied this archive; target_result is what happened. To push it again from scratch, add restart:true.',
            ];
        }

        $chunk_max = max(self::MIN_CHUNK_BYTES, (int) ($start['chunk_bytes_max'] ?? self::DEFAULT_CHUNK_BYTES));
        $chunk     = max(self::MIN_CHUNK_BYTES, min($chunk_max, (int) ($args['chunk_bytes'] ?? self::DEFAULT_CHUNK_BYTES)));
        $began     = ($this->clock)();
        $deadline  = $began + $max_seconds;
        $sent      = 0;
        $retries   = 0;
        $error     = null;

        while ('verified' !== $state && $received < $bytes) {
            if (($this->clock)() >= $deadline) {
                break;
            }

            $raw = file_get_contents($archive, false, null, $received, min($chunk, $bytes - $received));
            if (false === $raw || '' === $raw) {
                throw new \RuntimeException(sprintf('Could not read the archive at offset %d.', (int) $received));
            }

            $result = $client->call([
                'action'    => 'chunk',
                'upload_id' => $upload_id,
                'offset'    => $received,
                // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- transfer encoding for archive bytes inside a JSON request body.
                'data'      => base64_encode($raw),
            ], 120);

            if (is_wp_error($result)) {
                // Re-sync with the target and retry from wherever it says
                // it is: a lost response, a rate-limit hit or a transient
                // network error must not cost the upload.
                if ($retries >= self::MAX_RETRIES) {
                    $error = $result;
                    break;
                }
                $retries++;
                $status = $client->call(['action' => 'status', 'upload_id' => $upload_id]);
                if (is_wp_error($status)) {
                    $error = $result;
                    break;
                }
                $received = (int) ($status['received_bytes'] ?? $received);
                continue;
            }

            $received = (int) ($result['received_bytes'] ?? $received);
            $sent++;
        }

        $base += [
            'received_bytes'  => $received,
            'chunks_sent'     => $sent,
            'chunk_bytes'     => $chunk,
            'elapsed_seconds' => round(($this->clock)() - $began, 2),
        ];

        if (null !== $error) {
            return $base + [
                'status'    => 'interrupted',
                'error'     => $error->get_error_message(),
                'resumable' => true,
                'next'      => 'Call push-site-archive again with the same arguments to resume from received_bytes.',
            ];
        }

        if ($received < $bytes) {
            return $base + [
                'status'    => 'uploading',
                'resumable' => true,
                'next'      => sprintf('%d of %d bytes are on the target. Call push-site-archive again with the same arguments to continue.', $received, $bytes),
            ];
        }

        if (! $apply) {
            // Verify on the target now (sha256, manifest) and show what an
            // apply would do, so the next call is an informed one.
            $preview = $client->call([
                'action'        => 'apply',
                'upload_id'     => $upload_id,
                'dry_run'       => true,
                'include_files' => $include_files,
            ]);

            return $base + [
                'status'        => is_wp_error($preview) ? 'uploaded_unverified' : 'uploaded',
                'target_result' => is_wp_error($preview) ? ['error' => $preview->get_error_message()] : $preview,
                'next'          => 'Call again with apply:true (plus dry_run:false and confirm:true) to restore the archive on the target and rewrite its URLs.',
            ];
        }

        $applied = $client->call([
            'action'        => 'apply',
            'upload_id'     => $upload_id,
            'dry_run'       => false,
            'confirm'       => true,
            'include_files' => $include_files,
        ], max(60, (int) ($args['apply_timeout'] ?? self::DEFAULT_APPLY_TIMEOUT)));

        if (is_wp_error($applied)) {
            $sent_but_unanswered = 'wpmcp_migration_unreachable' === $applied->get_error_code();

            return $base + [
                'status' => $sent_but_unanswered ? 'apply_sent' : 'apply_failed',
                'error'  => $applied->get_error_message(),
                'next'   => $sent_but_unanswered
                    ? 'No answer from the target, which keeps applying after a dropped connection. Call push-site-archive again with the same arguments (not restart) to read the result.'
                    : 'The target refused the apply; see error.',
            ];
        }

        return $base + [
            'status'         => (string) ($applied['status'] ?? 'unknown'),
            'safety_archive' => $applied['safety_archive'] ?? null,
            'undo'           => isset($applied['undo']) ? 'On the target: ' . $applied['undo'] : null,
            'target_result'  => $applied,
        ];
    }

    /**
     * The archive's sha256, cached per path, size and mtime: hashing a 2GB
     * file on every resume call would spend most of each call's time budget.
     */
    private static function sha256(string $archive, int $bytes): string
    {
        $key    = 'wpmcp_push_sha_' . md5($archive . '|' . $bytes . '|' . (int) filemtime($archive));
        $cached = get_transient($key);
        if (is_string($cached) && preg_match('/^[a-f0-9]{64}$/', $cached)) {
            return $cached;
        }

        $hash = (string) hash_file('sha256', $archive);
        set_transient($key, $hash, DAY_IN_SECONDS);

        return $hash;
    }
}
