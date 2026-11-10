<?php

namespace WPMCP\Integrations;

use WPMCP\Tools\Code\Php_Snippet_Guard;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The Code Snippets activation op (issue #576), the one paid-tier op of
 * Code_Snippets_Pack, kept in its own file the way the ACF batch write is so
 * the directory build removes it whole.
 *
 * Activating a Code Snippets PHP snippet makes Code Snippets run its code on
 * every request, so this op clears exactly the gates the plugin's own
 * activate-php-snippet clears (issue #85):
 *  - Php_Snippet_Guard::assert_execution_allowed(): PHP execution enabled
 *    (WPMCP_ALLOW_PHP_EXEC or wpmcp_allow_php_exec, default off), an
 *    environment that permits it, and on multisite a network administrator;
 *  - the STORED code re-validated with Php_Snippet_Validator at call time
 *    (advisory, not a security boundary), and again inside the write, where
 *    the code Code Snippets activated is compared with the code that was
 *    validated; a mismatch switches it straight back off and refuses;
 *  - every attempt, allowed or refused, recorded to the governance trail
 *    (Php_Snippet_Guard::audit()), never with the snippet source.
 * Only PHP snippets are activated; HTML, CSS and JS snippets are refused.
 * Activation itself runs no code: Code Snippets' activate_snippet() checks
 * the code with its own validator and flips the flag; the code runs from the
 * next request on. Snapshotted first; rollback switches the snippet off.
 */
final class Code_Snippets_Activation
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.

    /** The name the governance trail records attempts under (the per-op governance check records its own under wpmcp/plugin-data-code-snippets-activate-snippet). */
    public const AUDIT_NAME = 'wpmcp/plugin-data-write:code-snippets-activate-snippet';

    /**
     * @param array<string, mixed> $base    The pack's shared capability and requires keys.
     * @param array<string, mixed> $id_only The id-only input schema.
     * @param callable             $row     The pack's row snapshot target.
     * @return array<string, mixed>
     */
    public static function definition(array $base, array $id_only, callable $row): array
    {
        return $base + [
            'mode'         => 'write',
            'tier'         => 'pro',
            'description'  => 'Activate a Code Snippets PHP snippet, so it runs on every request. Refused unless PHP execution is enabled (WPMCP_ALLOW_PHP_EXEC, default off) and the environment permits it; stored code is re-checked. Every attempt audited. Snapshotted; rollback deactivates',
            'input_schema' => $id_only,
            'validate'     => static fn (array $args): ?array => self::refusal((int) $args['id']),
            'snapshot'     => $row,
            'handler'      => static fn (array $args, array $context): array => self::activate((int) $args['id'], (string) ($context['operation_id'] ?? '')),
        ];
    }

    /** Every gate, in order; an audited refusal, or null. */
    private static function refusal(int $id): ?array
    {
        try {
            Php_Snippet_Guard::assert_execution_allowed();
        } catch (\RuntimeException $e) {
            Php_Snippet_Guard::audit(self::AUDIT_NAME, false, Php_Snippet_Guard::refusal_class($e));
            return [ 'code' => 'php_execution_disabled', 'message' => $e->getMessage(), 'data' => [] ];
        }
        $refusal = Code_Snippets_Pack::access_refusal()
            ?? Code_Snippets_Pack::missing_refusal($id)
            ?? Code_Snippets_Pack::writable_refusal($id)
            ?? Code_Snippets_Pack::state_refusal($id, false);
        if (null === $refusal) {
            $snippet = Code_Snippets_Pack::lookup($id);
            $refusal = Code_Snippets_Pack::code_refusal((string) ($snippet->code ?? ''), 'activate the snippet');
        }
        if (null !== $refusal) {
            Php_Snippet_Guard::audit(self::AUDIT_NAME, false, (string) $refusal['code']);
        }
        return $refusal;
    }

    private static function activate(int $id, string $operation): array
    {
        $written = false;
        try {
            $snippet = Code_Snippets_Pack::find($id);
            $code    = (string) $snippet->code;
            if (null !== Code_Snippets_Pack::code_refusal($code, 'activate the snippet') || ! empty($snippet->active) || 'php' !== (string) $snippet->type) {
                throw new Operation_Refused('code_moved', 'The snippet changed after it was checked; read it again and activate again.');
            }
            $out = \Code_Snippets\activate_snippet($id, false);
            if (! is_object($out)) {
                throw new Operation_Refused('activation_failed', is_string($out) ? $out : 'Code Snippets did not activate the snippet.');
            }
            $written = true;
            $active  = Code_Snippets_Pack::find($id);
            if (! hash_equals(hash('sha256', $code), hash('sha256', (string) $active->code))) {
                \Code_Snippets\deactivate_snippet($id, false);
                throw new Operation_Refused('code_moved', 'The snippet\'s code changed while it was being activated; it was switched off again.');
            }
        } catch (\Throwable $e) {
            // Nothing written: the undo point is voided. Written and switched
            // back off: it stays, so the recorded change can still be undone.
            if (! $written) {
                Code_Snippets_Pack::void($operation);
            }
            Php_Snippet_Guard::audit(self::AUDIT_NAME, false, $e instanceof Operation_Refused ? $e->error_code() : 'error');
            throw $e;
        }
        Php_Snippet_Guard::audit(self::AUDIT_NAME, true);
        return [ 'snippet' => Code_Snippets_Pack::view($active) ];
    }
}
