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
 * rule as run-php-snippet.
 *
 * TODO(#63): adversarial payload-corpus tests in tests/pro must land before
 * this graduates from WIP; the renderer keeps JS output disabled whenever
 * Custom_Js_Guard::is_enabled() is false, so a later governance disable
 * also stops rendering of previously stored JS.
 */
class Add_Custom_Js
{
    public function handle(array $args): array
    {
        $js = isset($args['js']) ? (string) $args['js'] : '';
        if ('' === trim($js)) {
            throw new \InvalidArgumentException('A js value is required.');
        }

        try {
            $this->guard($js);
        } catch (\RuntimeException $e) {
            $this->audit(false);
            throw $e;
        }

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

        $this->audit(true);

        return [
            'scope'        => 'site',
            'operation_id' => $out['operation_id'],
            'recoverable'  => true,
        ];
    }

    private function guard(string $js): void
    {
        if (! Custom_Js_Guard::is_enabled()) {
            throw new \RuntimeException(
                'Custom JS injection is disabled. Enable it with the WPMCP_ALLOW_JS_INJECTION constant or the wpmcp_allow_js_injection filter. Stored JS runs in every visitor\'s browser; only enable this deliberately.'
            );
        }

        if (! Custom_Js_Guard::current_user_can_inject()) {
            throw new \RuntimeException(
                'Custom JS injection requires the unfiltered_html capability in addition to manage_options.'
            );
        }

        if (preg_match('#</\s*script#i', $js)) {
            throw new \RuntimeException('The JS was rejected: it contains a </script> breakout sequence.');
        }
    }

    /** Audit the attempt; never logs the snippet source (it may embed secrets). */
    private function audit(bool $allowed): void
    {
        try {
            $identity = Identity_Context::current() ?? 'none';
            Governance_Audit_Log::record('wpmcp/add-custom-js', $identity, $allowed);
        } catch (\Throwable $e) {
            // Auditing must never break (or block) the outcome it observes.
        }
    }
}
