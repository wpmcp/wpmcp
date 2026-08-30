<?php

namespace WPMCP\Tools\Code;

use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Create a stored PHP snippet (issue #85). Two hard invariants enforced
 * here, matching the issue's acceptance criteria:
 *  1. Every snippet is created INACTIVE. There is no argument that can
 *     create an active snippet; activation is a distinct, governed
 *     operation (Activate_Php_Snippet).
 *  2. Creation is validation-gated: the snippet is run through
 *     Php_Snippet_Validator (static analysis only, never executed) and a
 *     critical finding or a syntax error blocks creation outright.
 *
 * The write goes through Safe_Mutation against the store option so it is
 * snapshotted and reversible. Nothing here executes the snippet.
 */
class Create_Php_Snippet
{
    public function handle(array $args): array
    {
        $name = trim((string) ($args['name'] ?? ''));
        $code = (string) ($args['code'] ?? '');

        if ('' === $name) {
            throw new \InvalidArgumentException('A snippet name is required.');
        }
        if ('' === trim($code)) {
            throw new \InvalidArgumentException('A PHP code snippet is required.');
        }

        $validation = Php_Snippet_Validator::validate($code);
        if (! $validation['syntax_valid']) {
            throw new \RuntimeException('Refusing to store snippet: it does not parse as valid PHP.');
        }
        if (! $validation['safe']) {
            throw new \RuntimeException('Refusing to store snippet: static validation reported critical safety findings.');
        }

        $now     = gmdate('c');
        $snippet = [
            'id'         => wp_generate_uuid4(),
            'name'       => $name,
            'code'       => $code,
            'status'     => Php_Snippet_Store::STATUS_INACTIVE,
            'validation' => $validation,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $out = Safe_Mutation::run(
            [
                'object_type' => 'option',
                'object_id'   => Php_Snippet_Store::OPTION_NAME,
                'session_id'  => (string) ($args['session_id'] ?? 'default'),
                'tool_name'   => 'create-php-snippet',
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
