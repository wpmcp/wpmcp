<?php

namespace WPMCP\Tools\CustomCode;

use WPMCP\Governance\Governance_Audit_Log;
use WPMCP\Identity\Identity_Context;
use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The add-custom-js tool handler (issue #63). Stores a site-wide JS snippet
 * that Custom_Code_Renderer wraps in a <script> element in wp_footer. This
 * is an XSS-class surface, so it composes every guard before any write:
 *
 *  1. Custom_Js_Guard::is_enabled()               - default OFF (constant or
 *                                                   governance filter)
 *  2. Custom_Js_Guard::current_user_can_inject()  - unfiltered_html required
 *                                                   on top of manage_options
 *  3. the snippet must not contain a </script> breakout
 *
 * Unlike run-php-snippet the WRITE stays inside the snapshot/rollback
 * model: it routes through Safe_Mutation on the store option, so the
 * snippet's presence is fully reversible. Every attempt, allowed or denied,
 * is audited via Governance_Audit_Log with the same never-log-the-payload
 * rule as run-php-snippet, and each refusal carries a machine-readable
 * reason so a tool-level refusal is distinguishable from the permission
 * denial Registrar::is_permitted() records under the same ability name.
 *
 * The renderer keeps JS output disabled whenever Custom_Js_Guard::is_enabled()
 * is false, so a later CODE-LEVEL disable (constant or filter) also stops
 * rendering previously stored JS. The ability grid's governance toggle is a
 * different lever: it withdraws the tool, not the gate. That is why
 * wpmcp/add-custom-js is listed in Opt_In_Gates - so the grid labels the row
 * dangerous and refuses to write an enabling toggle while the gate is shut.
 */
class Add_Custom_Js
{
    public const REASON_GATE_CLOSED        = 'js-gate-closed';
    public const REASON_NO_UNFILTERED_HTML = 'no-unfiltered-html';
    public const REASON_SCRIPT_BREAKOUT    = 'script-breakout';
    public const REASON_EMPTY              = 'empty-js';
    public const REASON_STORED             = 'js-stored';

    public function handle(array $args): array
    {
        $js = isset($args['js']) ? (string) $args['js'] : '';
        if ('' === trim($js)) {
            $this->audit(false, self::REASON_EMPTY);
            throw new \InvalidArgumentException('A js value is required.');
        }

        $this->guard($js);

        $out = Safe_Mutation::run(
            [
                'object_type' => 'option',
                'object_id'   => Custom_Code_Store::OPTION,
                'session_id'  => (string) ($args['session_id'] ?? 'default'),
                'tool_name'   => 'add-custom-js',
                'args'        => $args,
            ],
            function () use ($js): void {
                Custom_Code_Store::set_js(trim($js));
            }
        );

        $this->audit(true, self::REASON_STORED);

        return [
            'scope'        => 'site',
            'operation_id' => $out['operation_id'],
            'recoverable'  => true,
        ];
    }

    /** The guard chain, in refusal order. Every refusal is audited first. */
    private function guard(string $js): void
    {
        if (! Custom_Js_Guard::is_enabled()) {
            $this->refuse(
                'Custom JS injection is disabled. Enable it with the WPMCP_ALLOW_JS_INJECTION constant or the wpmcp_allow_js_injection filter. Stored JS runs in every visitor\'s browser; only enable this deliberately.',
                self::REASON_GATE_CLOSED
            );
        }

        if (! Custom_Js_Guard::current_user_can_inject()) {
            $this->refuse(
                'Custom JS injection requires the unfiltered_html capability in addition to manage_options.',
                self::REASON_NO_UNFILTERED_HTML
            );
        }

        if (preg_match('#</\s*script#i', $js)) {
            $this->refuse(
                'The JS was rejected: it contains a </script> breakout sequence.',
                self::REASON_SCRIPT_BREAKOUT
            );
        }
    }

    /** Record the denial, then raise it. Never returns. */
    private function refuse(string $message, string $reason): void
    {
        $this->audit(false, $reason);

        throw new \RuntimeException($message);
    }

    /** Audit the attempt; never logs the snippet source (it may embed secrets). */
    private function audit(bool $allowed, string $reason = ''): void
    {
        try {
            $identity = Identity_Context::current() ?? 'none';
            Governance_Audit_Log::record('wpmcp/add-custom-js', $identity, $allowed, $reason);
        } catch (\Throwable $e) {
            // Auditing must never break (or block) the outcome it observes.
        }
    }
}
