<?php

namespace WPMCP\Tools\Code;

use WPMCP\Governance\Governance_Audit_Log;
use WPMCP\Identity\Identity_Context;
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
 * the governance trail is for. "Still audited" means every attempt, not
 * only the ones that work: the whole body runs inside one
 * try/catch(\Throwable) so an unknown id, a Mutation_Failed from the
 * snapshot and a rejected store write all reach the trail as denials before
 * they are rethrown. Snapshot-first per record; never executes.
 */
class Deactivate_Php_Snippet
{
    public function handle(array $args): array
    {
        try {
            $result = $this->deactivate($args);
        } catch (\Throwable $e) {
            $this->audit(false);
            throw $e;
        }

        $this->audit(true);

        return $result;
    }

    /** The deactivation itself; see handle() for why it is wrapped. */
    private function deactivate(array $args): array
    {
        $id = trim((string) ($args['id'] ?? ''));
        if ('' === $id) {
            throw new \InvalidArgumentException('A snippet id is required.');
        }

        if (! Php_Snippet_Store::exists($id)) {
            throw new \RuntimeException("No stored snippet with id \"{$id}\".");
        }

        $snippet = null;

        $out = Safe_Mutation::run(
            [
                'object_type' => 'php_snippet',
                'object_id'   => $id,
                'session_id'  => (string) ($args['session_id'] ?? 'default'),
                'tool_name'   => 'deactivate-php-snippet',
                'args'        => $args,
            ],
            function () use ($id, &$snippet): void {
                $snippet = Php_Snippet_Store::set_status($id, Php_Snippet_Store::STATUS_INACTIVE);
            }
        );

        return [
            'snippet'      => $snippet,
            'operation_id' => $out['operation_id'],
            'recoverable'  => true,
        ];
    }

    /**
     * Same shape as Activate_Php_Snippet::audit(). A deactivation that
     * happened is an allow; one that was refused or failed is a denial, and
     * the trail records the difference rather than only the happy path.
     */
    private function audit(bool $allowed): void
    {
        try {
            $identity = Identity_Context::current() ?? 'none';
            Governance_Audit_Log::record('wpmcp/deactivate-php-snippet', $identity, $allowed);
        } catch (\Throwable $e) {
            // Auditing must never break (or block) the outcome it observes.
        }
    }
}
