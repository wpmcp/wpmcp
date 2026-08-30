<?php

namespace WPMCP\Tools\Code;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only listing of stored PHP snippets (issue #85). Returns summaries
 * only (no code bodies) so an agent can enumerate snippets cheaply and
 * fetch full detail with get-php-snippet. Never executes anything and
 * writes nothing, so this never touches Safe_Mutation.
 */
class List_Php_Snippets
{
    public function handle(array $args): array
    {
        $summaries = [];

        foreach (Php_Snippet_Store::all() as $snippet) {
            $summaries[] = [
                'id'         => $snippet['id'],
                'name'       => $snippet['name'],
                'status'     => $snippet['status'],
                'safe'       => (bool) ($snippet['validation']['safe'] ?? false),
                'created_at' => $snippet['created_at'],
                'updated_at' => $snippet['updated_at'],
            ];
        }

        return [
            'snippets' => $summaries,
            'total'    => count($summaries),
        ];
    }
}
