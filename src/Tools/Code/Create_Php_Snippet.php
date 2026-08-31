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
 *  2. Creation runs the code through Php_Snippet_Validator (static
 *     analysis only, never executed) and a critical finding or a syntax
 *     error blocks creation.
 *
 * DO NOT MISREAD (2) AS A SECURITY CONTROL. Php_Snippet_Validator is the
 * same line-based regex scanner Run_Php_Snippet documents as "a usability
 * speed-bump, NOT a security boundary": a caller who already holds
 * manage_options can defeat it trivially (string concatenation, a variable
 * function call, ...). It exists so an operator or an agent does not store
 * an obviously dangerous snippet by accident. The real gates on this
 * surface are capability (manage_options), enablement and environment, and
 * they live on the activation and execution paths, not here.
 *
 * The write goes through Safe_Mutation against THIS SNIPPET (object_type
 * 'php_snippet'), not the whole store option, so undoing this creation
 * removes exactly this record and leaves every other snippet alone.
 * Nothing here executes the snippet.
 */
class Create_Php_Snippet
{
    public function handle(array $args): array
    {
        $name = sanitize_text_field(trim((string) ($args['name'] ?? '')));
        $code = (string) ($args['code'] ?? '');

        if ('' === $name) {
            throw new \InvalidArgumentException('A snippet name is required.');
        }
        if ('' === trim($code)) {
            throw new \InvalidArgumentException('A PHP code snippet is required.');
        }

        Php_Snippet_Store::assert_code_within_limit($code);
        Php_Snippet_Store::assert_has_room();

        $validation = Php_Snippet_Validator::validate($code);
        if (! $validation['syntax_valid']) {
            throw new \RuntimeException('Refusing to store snippet: it does not parse as valid PHP.');
        }
        if (! $validation['safe']) {
            throw new \RuntimeException('Refusing to store snippet: static validation flagged it (advisory speed-bump, not a security boundary). See validate-php-snippet for the findings.');
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

        // Aggregate bound, checked before the snapshot: see
        // Php_Snippet_Store::assert_total_within_limit(). The per-snippet and
        // per-count caps alone multiply out past what the database will
        // accept in one option row.
        Php_Snippet_Store::assert_total_within_limit($snippet['id'], $snippet);

        $out = Safe_Mutation::run(
            [
                'object_type' => 'php_snippet',
                'object_id'   => $snippet['id'],
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
