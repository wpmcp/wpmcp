<?php

namespace WPMCP\Tools\Code;

use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Delete a stored PHP snippet (issue #85). Snapshot-first via Safe_Mutation
 * against THIS RECORD (object_type 'php_snippet'), so undoing the deletion
 * restores exactly this snippet and does not disturb snippets created since.
 * Never executes anything.
 */
class Delete_Php_Snippet
{
    public function handle(array $args): array
    {
        $id = trim((string) ($args['id'] ?? ''));
        if ('' === $id) {
            throw new \InvalidArgumentException('A snippet id is required.');
        }

        if (! Php_Snippet_Store::exists($id)) {
            throw new \RuntimeException("No stored snippet with id \"{$id}\".");
        }

        $out = Safe_Mutation::run(
            [
                'object_type' => 'php_snippet',
                'object_id'   => $id,
                'session_id'  => (string) ($args['session_id'] ?? 'default'),
                'tool_name'   => 'delete-php-snippet',
                'args'        => $args,
            ],
            function () use ($id): void {
                Php_Snippet_Store::delete($id);
            }
        );

        return [
            'deleted'      => true,
            'id'           => $id,
            'operation_id' => $out['operation_id'],
            'recoverable'  => true,
        ];
    }
}
