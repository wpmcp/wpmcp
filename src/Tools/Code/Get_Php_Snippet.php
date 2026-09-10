<?php

namespace WPMCP\Tools\Code;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only fetch of a single stored PHP snippet (issue #85): code, status,
 * and the last validation report, exactly as the issue's acceptance
 * criteria require. Never executes anything and writes nothing, so this
 * never touches Safe_Mutation.
 */
class Get_Php_Snippet
{
    public function handle(array $args): array
    {
        $id = trim((string) ($args['id'] ?? ''));
        if ('' === $id) {
            throw new \InvalidArgumentException('A snippet id is required.');
        }

        $snippet = Php_Snippet_Store::get($id);
        if (null === $snippet) {
            throw new \RuntimeException("No stored snippet with id \"{$id}\".");
        }

        return ['snippet' => $snippet];
    }
}
