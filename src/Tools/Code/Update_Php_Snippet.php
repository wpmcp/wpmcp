<?php

namespace WPMCP\Tools\Code;

use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Update a stored PHP snippet's name and/or code (issue #85). A code change
 * ALWAYS re-validates through Php_Snippet_Validator (static analysis only)
 * and critical findings or syntax errors block the update, mirroring
 * Create_Php_Snippet. A code change also forces the snippet back to
 * INACTIVE: edited code has not been re-approved for activation, so it must
 * re-enter the governed activation flow. Status cannot be changed here at
 * all; that is Activate_Php_Snippet's job. Snapshot-first via Safe_Mutation.
 */
class Update_Php_Snippet
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

        $has_name = array_key_exists('name', $args) && '' !== trim((string) $args['name']);
        $has_code = array_key_exists('code', $args) && '' !== trim((string) $args['code']);
        if (! $has_name && ! $has_code) {
            throw new \InvalidArgumentException('Provide a new name and/or code to update.');
        }

        if ($has_name) {
            $snippet['name'] = trim((string) $args['name']);
        }

        if ($has_code) {
            $code       = (string) $args['code'];
            $validation = Php_Snippet_Validator::validate($code);
            if (! $validation['syntax_valid']) {
                throw new \RuntimeException('Refusing to update snippet: new code does not parse as valid PHP.');
            }
            if (! $validation['safe']) {
                throw new \RuntimeException('Refusing to update snippet: static validation reported critical safety findings.');
            }
            $snippet['code']       = $code;
            $snippet['validation'] = $validation;
            $snippet['status']     = Php_Snippet_Store::STATUS_INACTIVE;
        }

        $snippet['updated_at'] = gmdate('c');

        $out = Safe_Mutation::run(
            [
                'object_type' => 'option',
                'object_id'   => Php_Snippet_Store::OPTION_NAME,
                'session_id'  => (string) ($args['session_id'] ?? 'default'),
                'tool_name'   => 'update-php-snippet',
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
