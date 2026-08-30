<?php

namespace WPMCP\Tools\Code;

use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The distinct, governed activation operation for stored PHP snippets
 * (issue #85). Activation is deliberately NOT part of create/update:
 * snippets are always created inactive, and flipping one to active must
 * clear every gate that guards execution itself, because an active stored
 * snippet is one step from running. This tool therefore refuses unless
 * Php_Snippet_Guard::is_enabled() and is_allowed_on_environment() both
 * pass, the exact same gates Run_Php_Snippet enforces, shared, not
 * duplicated, so the execution surface's guarantees are unchanged. It also
 * re-validates the stored code at activation time; a snippet whose code no
 * longer passes static validation cannot be activated. Activation only
 * flips the status flag via Safe_Mutation (snapshotted, reversible); it
 * never executes the snippet.
 *
 * TODO(#85): execution of ACTIVE stored snippets by id (through the same
 * Php_Snippet_Runner bounds as run-php-snippet) is a follow-up slice and
 * intentionally not implemented yet.
 */
class Activate_Php_Snippet
{
    public function handle(array $args): array
    {
        $id = trim((string) ($args['id'] ?? ''));
        if ('' === $id) {
            throw new \InvalidArgumentException('A snippet id is required.');
        }

        if (! Php_Snippet_Guard::is_enabled()) {
            throw new \RuntimeException('PHP snippet execution is disabled, so snippets cannot be activated. Enable it with the WPMCP_ALLOW_PHP_EXEC constant or the wpmcp_allow_php_exec filter.');
        }
        if (! Php_Snippet_Guard::is_allowed_on_environment()) {
            throw new \RuntimeException('This environment does not permit PHP snippet execution, so snippets cannot be activated here.');
        }

        $snippet = Php_Snippet_Store::get($id);
        if (null === $snippet) {
            throw new \RuntimeException("No stored snippet with id \"{$id}\".");
        }

        $validation = Php_Snippet_Validator::validate((string) $snippet['code']);
        if (! $validation['syntax_valid'] || ! $validation['safe']) {
            throw new \RuntimeException('Refusing to activate snippet: stored code no longer passes static validation.');
        }

        $snippet['validation'] = $validation;
        $snippet['status']     = Php_Snippet_Store::STATUS_ACTIVE;
        $snippet['updated_at'] = gmdate('c');

        $out = Safe_Mutation::run(
            [
                'object_type' => 'option',
                'object_id'   => Php_Snippet_Store::OPTION_NAME,
                'session_id'  => (string) ($args['session_id'] ?? 'default'),
                'tool_name'   => 'activate-php-snippet',
                'args'        => $args,
            ],
            function () use ($snippet): void {
                Php_Snippet_Store::save($snippet);
            }
        );

        return [
            'snippet'      => $snippet,
            'operation_id' => $out['operation_id'],
            'recoverable'  => true,
        ];
    }
}
