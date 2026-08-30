<?php

namespace WPMCP\Tools\Code;

use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Delete a stored PHP snippet (issue #85). Snapshot-first via Safe_Mutation
 * against the store option, so a deletion is reversible through the normal
 * rollback flow like every other governed mutation. Never executes anything.
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
                'object_type' => 'option',
                'object_id'   => Php_Snippet_Store::OPTION_NAME,
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
