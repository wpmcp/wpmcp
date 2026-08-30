<?php

namespace WPMCP\Tools\CustomCode;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Security core for custom JS injection (issue #63), mirroring
 * Php_Snippet_Guard's shape. JS injection is an XSS-class surface: a stored
 * script runs in every visitor's browser, so this follows the plugin's
 * default-off, governance-gated convention. BOTH checks must pass:
 *
 *  1. is_enabled(): the WPMCP_ALLOW_JS_INJECTION constant or the
 *     wpmcp_allow_js_injection filter. Default (neither set) is OFF; a
 *     disabled install can never store or render agent-provided JS.
 *  2. current_user_can_inject(): the acting user must hold unfiltered_html
 *     IN ADDITION to the ability's manage_options gate, matching how
 *     WordPress itself decides who may author raw script markup (and which
 *     multisite strips from everyone but super admins).
 *
 * Unlike run-php-snippet there is no environment refusal here: stored JS is
 * a persistent site asset, not an eval, and it stays inside the
 * snapshot/rollback safety model (the write is undoable). The gates exist
 * because the RENDERED effect on visitors is not undoable while the snippet
 * is live.
 */
class Custom_Js_Guard
{
    public static function is_enabled(): bool
    {
        $default = defined('WPMCP_ALLOW_JS_INJECTION') && WPMCP_ALLOW_JS_INJECTION;

        return (bool) apply_filters('wpmcp_allow_js_injection', $default);
    }

    public static function current_user_can_inject(): bool
    {
        return current_user_can('unfiltered_html');
    }
}
