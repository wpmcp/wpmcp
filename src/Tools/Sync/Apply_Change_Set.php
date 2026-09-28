<?php

namespace WPMCP\Tools\Sync;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Apply a local-live sync change set to THIS site (issue #192, phase 2).
 *
 * Transport is the connect layer the plugin already has: an agent connected
 * to both sites (directly, or through bin/wpmcp-proxy.php with a named site
 * per install) reads the artifact on the origin with get-change-set raw=true
 * and passes it here as `change_set`. An artifact already copied into this
 * site's backup directory can be named by `path` instead.
 *
 * dry_run defaults to TRUE: without an explicit dry_run=false this only
 * reports, per object, what would be applied, skipped or refused. The
 * decisions, the per-object conflict policy and the snapshot-first writes
 * live in Change_Set_Applier; see its docblock for the guarantees.
 *
 * Failures that make the whole artifact unusable (not a change set, wrong
 * format version, checksum mismatch) throw, so Registrar records ok:false.
 * Per-object refusals are not failures of the call: they are the report.
 */
class Apply_Change_Set
{
    /** @throws \RuntimeException */
    public function handle(array $args): array
    {
        $has_inline = isset($args['change_set']);
        $has_path   = isset($args['path']) && '' !== trim((string) $args['path']);

        if ($has_inline === $has_path) {
            throw new \RuntimeException(
                'Pass exactly one of change_set (the artifact, as returned by get-change-set raw=true on the origin) or path (an artifact in this site\'s backup directory).'
            );
        }

        if ($has_inline) {
            $set = $args['change_set'];
            if (is_string($set)) {
                $set = json_decode($set, true);
            }
            if (! is_array($set)) {
                throw new \RuntimeException('change_set is not a change-set artifact.');
            }
        } else {
            [, $set] = Change_Set_Format::load_path((string) $args['path']);
        }

        $force = [];
        if (isset($args['force'])) {
            if (! is_array($args['force'])) {
                throw new \RuntimeException('force must be a list of object keys from the change set, such as post:12.');
            }
            $force = array_values(array_map('strval', $args['force']));
        }

        return (new Change_Set_Applier())->apply($set, [
            'dry_run'    => ! array_key_exists('dry_run', $args) || false !== filter_var($args['dry_run'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            'force'      => $force,
            'session_id' => (string) ($args['session_id'] ?? ''),
        ]);
    }
}
