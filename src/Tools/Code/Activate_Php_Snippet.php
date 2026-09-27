<?php

namespace WPMCP\Tools\Code;

use WPMCP\Safety\Safe_Mutation;
use WPMCP\Safety\Snapshot_Store;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The distinct, governed activation operation for stored PHP snippets
 * (issue #85). Activation is deliberately NOT part of create/update:
 * snippets are always created inactive, and flipping one to active must
 * clear the gates that guard execution itself, because an active stored
 * snippet is one step from running.
 *
 * The gate chain is genuinely SHARED, not re-typed: both this tool and
 * Run_Php_Snippet::guard() call Php_Snippet_Guard::assert_execution_allowed(),
 * so a third gate added there applies to both surfaces at once.
 *
 * The stored code is re-validated at activation time, but read
 * Create_Php_Snippet's docblock first: Php_Snippet_Validator is an
 * advisory speed-bump that an authorized caller can trivially evade, not a
 * security boundary. The real gates are capability + enablement +
 * environment.
 *
 * EVERY attempt, allowed or refused, is recorded to Governance_Audit_Log
 * exactly as Run_Php_Snippet records its own: this is exec-adjacent, so an
 * admin reviewing the execution-gate trail must see it. "Every" is meant
 * literally, so the whole body runs inside one try/catch(\Throwable):
 * a Mutation_Failed from the snapshot, a concurrent delete, a rejected
 * store write and the empty-id refusal all land in the trail as denials
 * before they are rethrown, each with a refusal class as its reason
 * (Php_Snippet_Guard::audit()). Never the snippet source.
 *
 * A refusal inside the Safe_Mutation closure voids that operation's
 * snapshot row (Snapshot_Store::delete_operation()): nothing was written,
 * and the captured record may predate a concurrent write that was, so a
 * later rollback of it would clobber newer code.
 *
 * The flip happens inside the Safe_Mutation closure via
 * Php_Snippet_Store::update_fields(), which re-reads the record: writing
 * back a record read before the snapshot would silently revert an
 * interleaved update (resurrecting pre-update code as ACTIVE) or undo an
 * interleaved delete. That re-read closes the stale-write window, but on
 * its own it opens a worse one: the code is validated OUTSIDE the closure,
 * so an update-php-snippet interleaved between the two would leave an
 * ACTIVE snippet whose current code was never validated and whose stored
 * validation report describes the code it replaced, which is precisely what
 * Update_Php_Snippet forces status back to inactive to prevent. So the
 * validated code is hashed and the hash re-checked against the re-read
 * record inside the closure; a mismatch aborts the mutation. Activation
 * never executes the snippet.
 *
 * TODO(#85): execution of ACTIVE stored snippets by id (through the same
 * Php_Snippet_Runner bounds as run-php-snippet) is a follow-up slice and
 * intentionally not implemented yet. When it lands it MUST re-run
 * Php_Snippet_Guard and Php_Snippet_Validator against the stored code at
 * call time rather than trusting the persisted status or validation report.
 */
class Activate_Php_Snippet
{
    private const CODE_MOVED = 'Refusing to activate snippet: its code changed after it was validated and before it could be activated. Re-read the snippet and activate again.';

    public function handle(array $args): array
    {
        try {
            $result = $this->activate($args);
        } catch (\Throwable $e) {
            Php_Snippet_Guard::audit('wpmcp/activate-php-snippet', false, Php_Snippet_Guard::refusal_class($e));
            throw $e;
        }

        Php_Snippet_Guard::audit('wpmcp/activate-php-snippet', true);

        return $result;
    }

    /**
     * The activation itself. Every exit from here, including the argument
     * refusal, passes through handle()'s catch, which is what makes the
     * "every attempt is audited" claim true rather than aspirational.
     */
    private function activate(array $args): array
    {
        $id = trim((string) ($args['id'] ?? ''));
        if ('' === $id) {
            throw new \InvalidArgumentException('A snippet id is required.');
        }

        Php_Snippet_Guard::assert_execution_allowed();

        $snippet = Php_Snippet_Store::get($id);
        if (null === $snippet) {
            throw new Php_Snippet_Refusal(sprintf('No stored snippet with id "%s".', esc_html($id)), 'snippet_missing');
        }

        $code       = (string) ($snippet['code'] ?? '');
        $code_hash  = hash('sha256', $code);
        $validation = Php_Snippet_Validator::validate($code);
        if (! $validation['syntax_valid'] || ! $validation['safe']) {
            throw new Php_Snippet_Refusal('Refusing to activate snippet: stored code no longer passes static validation (advisory speed-bump, not a security boundary).', 'validation_failed');
        }

        // Checked once BEFORE the snapshot, so the common race (an update
        // landing while the code was being validated) is refused without
        // creating an undo point at all, and again inside the closure by
        // update_fields() against the record it writes.
        $this->assert_code_unchanged($id, $code_hash);

        $activated    = null;
        $operation_id = wp_generate_uuid4();

        try {
            $out = Safe_Mutation::run(
                [
                    'operation_id' => $operation_id,
                    'object_type'  => 'php_snippet',
                    'object_id'    => $id,
                    'session_id'   => (string) ($args['session_id'] ?? 'default'),
                    'tool_name'    => 'activate-php-snippet',
                    'args'         => $args,
                ],
                function () use ($id, $validation, $code_hash, &$activated): void {
                    // update_fields() re-checks the hash against the very
                    // record it re-reads and writes, so no update can land
                    // between the check and the write.
                    $activated = Php_Snippet_Store::update_fields(
                        $id,
                        [
                            'status'     => Php_Snippet_Store::STATUS_ACTIVE,
                            'validation' => $validation,
                        ],
                        $code_hash
                    );
                }
            );
        } catch (\Throwable $e) {
            // Nothing was written, but Safe_Mutation already persisted the
            // snapshot, and that capture may predate a concurrent write that
            // did land. Left in place, rolling it back would clobber the
            // newer record, so the undo point for a refused activation is
            // voided rather than left restorable.
            Snapshot_Store::delete_operation($operation_id);
            if ($e instanceof Php_Snippet_Refusal && 'code_moved' === $e->reason()) {
                throw new Php_Snippet_Refusal(esc_html(self::CODE_MOVED), 'code_moved');
            }
            throw $e;
        }

        return [
            'snippet'      => $activated,
            'operation_id' => $out['operation_id'],
            'recoverable'  => true,
        ];
    }

    /** Refuse when the stored code no longer matches what was validated. */
    private function assert_code_unchanged(string $id, string $code_hash): void
    {
        $current = Php_Snippet_Store::get($id);
        if (null === $current) {
            throw new Php_Snippet_Refusal(sprintf('No stored snippet with id "%s"; it was removed since this operation started.', esc_html($id)), 'snippet_missing');
        }
        if (! hash_equals($code_hash, hash('sha256', (string) ($current['code'] ?? '')))) {
            throw new Php_Snippet_Refusal(esc_html(self::CODE_MOVED), 'code_moved');
        }
    }
}
