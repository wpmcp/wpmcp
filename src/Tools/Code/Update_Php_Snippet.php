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
 * An omitted argument leaves that field alone; an argument that is present
 * but blank is refused rather than treated as omitted, so a rename cannot
 * quietly report success while the old code stays put.
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

        // "Key absent" and "key present but blank" are DIFFERENT requests and
        // are kept apart deliberately. Collapsing them made {id, name, code:
        // ""} a silent rename that reported success while leaving the old
        // code in place, and made {id, code: "   "} fail with the misleading
        // "provide a name and/or code". Absent means leave the field alone;
        // present-but-blank is a bad argument, exactly as it is in
        // Create_Php_Snippet.
        $has_name = array_key_exists('name', $args);
        $has_code = array_key_exists('code', $args);

        if ($has_name && '' === trim((string) $args['name'])) {
            throw new \InvalidArgumentException('A snippet name cannot be blank. Omit "name" to leave it unchanged.');
        }
        if ($has_code && '' === trim((string) $args['code'])) {
            throw new \InvalidArgumentException('A snippet\'s code cannot be blank. Omit "code" to leave it unchanged, or delete the snippet.');
        }
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

        // Aggregate bound, checked against the record as it would be stored
        // and BEFORE Safe_Mutation snapshots anything: a write the database
        // would reject must not come back with an operation_id claiming it
        // landed.
        Php_Snippet_Store::assert_total_within_limit(
            $id,
            array_merge((array) Php_Snippet_Store::get($id), $fields)
        );

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
