<?php

namespace WPMCP\Tools\Redirects;

use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Remove one managed redirect (issue #128).
 *
 * No confirm:true gate, on purpose. The confirm gate exists for writes whose
 * physical record cannot be brought back (force-deleting a post, overwriting
 * a file); deleting a redirect captures the entire row first, id included, so
 * rollback-operation resurrects the same row. Demanding a confirmation for a
 * fully reversible write would train agents to pass confirm:true reflexively,
 * which is exactly what makes the gate worthless where it actually matters.
 *
 * Callers that want the redirect to stop firing without losing its hit
 * history should use update-redirect with enabled:false instead.
 */
class Delete_Redirect
{
    public function handle(array $args): array
    {
        $id  = (int) ($args['redirect_id'] ?? 0);
        $row = $id > 0 ? Redirect_Store::get($id) : null;
        if (null === $row) {
            throw new \InvalidArgumentException(sprintf('Redirect %d not found.', (int) $id));
        }

        $out = Safe_Mutation::run(
            [
                'object_type' => 'redirect',
                'object_id'   => $row['source_path'],
                'session_id'  => (string) ($args['session_id'] ?? 'default'),
                'tool_name'   => 'delete-redirect',
                'args'        => $args,
            ],
            static function () use ($id): bool {
                Redirect_Store::delete($id);
                return true;
            }
        );

        return [
            'operation_id' => $out['operation_id'],
            'redirect_id'  => $id,
            'source_path'  => $row['source_path'],
            'deleted'      => true,
        ];
    }
}
