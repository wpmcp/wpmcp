<?php

namespace WPMCP\Tools\Diagnostics;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Delete a single named transient via delete_transient().
 *
 * NOT routed through Safe_Mutation and does NOT touch the safety core:
 * transients are cache-like, regenerable data with no meaningful
 * before-image to restore, the same reasoning Clear_Cache already documents
 * for flushing every transient at once. Deleting one by name is simply a
 * narrower version of that same safe, idempotent operation.
 *
 * A cleanup list instead runs a database cleanup (issue #414, see
 * Db_Cleanup): a dry run by default, and a confirmed run that snapshots every
 * row it deletes except expired transients.
 */
class Delete_Transient
{
    public function handle(array $args): array
    {
        if (isset($args['cleanup'])) {
            return (new Db_Cleanup())->run($args);
        }

        $name = isset($args['name']) ? (string) $args['name'] : '';
        if ('' === $name) {
            throw new \InvalidArgumentException('A transient name or a cleanup list is required.');
        }

        return ['name' => $name, 'deleted' => delete_transient($name)];
    }
}
