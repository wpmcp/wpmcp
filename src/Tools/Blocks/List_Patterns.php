<?php

namespace WPMCP\Tools\Blocks;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only pattern discovery (issue #56): list the block patterns in
 * WP_Block_Patterns_Registry (name, title, description, categories).
 * Content markup is deliberately omitted — insert-pattern consumes it
 * server-side, so clients never need the raw markup to use a pattern.
 */
class List_Patterns
{
    public function handle(array $args): array
    {
        $search   = strtolower(trim((string) ($args['search'] ?? '')));
        $patterns = [];
        foreach (\WP_Block_Patterns_Registry::get_instance()->get_all_registered() as $pattern) {
            $name  = (string) ($pattern['name'] ?? '');
            $title = (string) ($pattern['title'] ?? '');
            if (
                '' !== $search
                && ! str_contains(strtolower($name), $search)
                && ! str_contains(strtolower($title), $search)
            ) {
                continue;
            }
            $patterns[] = [
                'name'        => $name,
                'title'       => $title,
                'description' => (string) ($pattern['description'] ?? ''),
                'categories'  => array_values((array) ($pattern['categories'] ?? [])),
            ];
        }
        return ['patterns' => $patterns];
    }
}
