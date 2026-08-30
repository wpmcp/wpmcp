<?php

namespace WPMCP\Tools\Code;

use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Update a stored PHP snippet's name and/or code (issue #85). A code change
 * re-runs Php_Snippet_Validator (static analysis only) and critical findings
 * or syntax errors block the update, mirroring Create_Php_Snippet, with the
 * same caveat spelled out there: the validator is an advisory speed-bump an
 * authorized caller can trivially evade, not a security boundary.
 *
 * A code change also forces the snippet back to INACTIVE: edited code has not
 * been re-approved for activation, so it must re-enter the governed
 * activation flow. Status cannot be set here at all; that is
 * Activate_Php_Snippet's and Deactivate_Php_Snippet's job.
 *
 * The mutation re-reads the record inside the Safe_Mutation closure and
 * writes only the fields this call owns, so an interleaved write to a
 * different field is not silently reverted by a stale copy read before the
 * snapshot. Snapshot is per record (object_type 'php_snippet').
 */
class Update_Php_Snippet
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

        $has_name = array_key_exists('name', $args) && '' !== trim((string) $args['name']);
        $has_code = array_key_exists('code', $args) && '' !== trim((string) $args['code']);
        if (! $has_name && ! $has_code) {
            throw new \InvalidArgumentException('Provide a new name and/or code to update.');
        }

        $fields = [];

        if ($has_name) {
            $fields['name'] = sanitize_text_field(trim((string) $args['name']));
        }

        if ($has_code) {
            $code = (string) $args['code'];
            Php_Snippet_Store::assert_code_within_limit($code);

            $validation = Php_Snippet_Validator::validate($code);
            if (! $validation['syntax_valid']) {
                throw new \RuntimeException('Refusing to update snippet: new code does not parse as valid PHP.');
            }
            if (! $validation['safe']) {
                throw new \RuntimeException('Refusing to update snippet: static validation flagged the new code (advisory speed-bump, not a security boundary). See validate-php-snippet for the findings.');
            }

            $fields['code']       = $code;
            $fields['validation'] = $validation;
            $fields['status']     = Php_Snippet_Store::STATUS_INACTIVE;
        }

        $snippet = null;

        $out = Safe_Mutation::run(
            [
                'object_type' => 'php_snippet',
                'object_id'   => $id,
                'session_id'  => (string) ($args['session_id'] ?? 'default'),
                'tool_name'   => 'update-php-snippet',
                'args'        => $args,
            ],
            function () use ($id, $fields, &$snippet): void {
                $snippet = Php_Snippet_Store::update_fields($id, $fields);
            }
        );

        return [
            'snippet'      => $snippet,
            'operation_id' => $out['operation_id'],
            'recoverable'  => true,
        ];
    }
}
