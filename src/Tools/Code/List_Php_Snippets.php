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
 *
 * Every field is defaulted rather than dereferenced: Php_Snippet_Store
 * filters out non-records, but a record written before a field existed (or
 * restored from an older snapshot) can still be missing one, and a listing
 * that fatals is a worse answer than a listing with a blank column.
 */
class List_Php_Snippets
{
    public function handle(array $args): array
    {
        $summaries = [];

        foreach (Php_Snippet_Store::all() as $id => $snippet) {
            $summaries[] = [
                'id'         => (string) ($snippet['id'] ?? $id),
                'name'       => (string) ($snippet['name'] ?? ''),
                'status'     => (string) ($snippet['status'] ?? Php_Snippet_Store::STATUS_INACTIVE),
                'safe'       => (bool) ($snippet['validation']['safe'] ?? false),
                'created_at' => (string) ($snippet['created_at'] ?? ''),
                'updated_at' => (string) ($snippet['updated_at'] ?? ''),
            ];
        }

        return [
            'snippets' => $summaries,
            'total'    => count($summaries),
        ];
    }
}
