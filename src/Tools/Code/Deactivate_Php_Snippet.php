<?php

namespace WPMCP\Tools\Code;

use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Flip a stored PHP snippet back to INACTIVE (issue #85). The counterpart
 * to Activate_Php_Snippet, and deliberately NOT gated on
 * Php_Snippet_Guard: a governed toggle that cannot be revoked is worse
 * than no toggle at all. If an operator closes the execution gate (or
 * moves the site to production) after activating a snippet, the activation
 * gates would refuse and, without this tool, the snippet would be stuck
 * marked active with no way back short of editing its code.
 *
 * Deactivation only ever reduces what a snippet is allowed to do, so it is
 * free tier and requires nothing but manage_options. It is still audited,
 * because the activation state of an exec-adjacent object is exactly what
 * the governance trail is for. Audited means every attempt that reaches a
 * stored record, allowed or failed: a Mutation_Failed from the snapshot or
 * a rejected store write lands in the trail as a denial before it is
 * rethrown. A missing or unknown id is refused WITHOUT an entry: this tool
 * is free and ungated, and auditing every bogus id would let any caller
 * flush the 500-entry ring that also holds the exec-gate trail.
 *
 * Deactivating a snippet that is already inactive changes nothing, so it
 * reports changed: false and writes no snapshot rather than burning a
 * history slot. Snapshot-first per record otherwise; never executes.
 */
class Deactivate_Php_Snippet
{
    public function handle(array $args): array
    {
        $id = trim((string) ($args['id'] ?? ''));
        if ('' === $id) {
            throw new \InvalidArgumentException('A snippet id is required.');
        }

        $snippet = Php_Snippet_Store::get($id);
        if (null === $snippet) {
            // Deliberately not audited; see the class docblock.
            throw new \RuntimeException(sprintf('No stored snippet with id "%s".', esc_html($id)));
        }

        try {
            $result = $this->deactivate($id, $snippet, $args);
        } catch (\Throwable $e) {
            Php_Snippet_Guard::audit('wpmcp/deactivate-php-snippet', false, Php_Snippet_Guard::refusal_class($e));
            throw $e;
        }

        Php_Snippet_Guard::audit('wpmcp/deactivate-php-snippet', true, $result['changed'] ? '' : 'already_inactive');

        return $result;
    }

    /** The deactivation itself, on a record known to exist. */
    private function deactivate(string $id, array $snippet, array $args): array
    {
        if (Php_Snippet_Store::STATUS_ACTIVE !== ($snippet['status'] ?? Php_Snippet_Store::STATUS_INACTIVE)) {
            return [
                'snippet'      => $snippet,
                'changed'      => false,
                'operation_id' => null,
                'recoverable'  => false,
            ];
        }

        $updated = null;

        $out = Safe_Mutation::run(
            [
                'object_type' => 'php_snippet',
                'object_id'   => $id,
                'session_id'  => (string) ($args['session_id'] ?? 'default'),
                'tool_name'   => 'deactivate-php-snippet',
                'args'        => $args,
            ],
            function () use ($id, &$updated): void {
                $updated = Php_Snippet_Store::set_status($id, Php_Snippet_Store::STATUS_INACTIVE);
            }
        );

        return [
            'snippet'      => $updated,
            'changed'      => true,
            'operation_id' => $out['operation_id'],
            'recoverable'  => true,
        ];
    }
}
