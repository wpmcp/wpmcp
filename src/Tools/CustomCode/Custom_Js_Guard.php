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
    /**
     * C0 control bytes other than tab, LF, FF and CR, as a PCRE character
     * class. Shared with Css_Sanitizer. It lives here because this is the one
     * class in the namespace that every build ships. None of these bytes
     * belongs in a stylesheet or a script, and ESC is a zero-width mode switch
     * in ISO-2022-JP: on a site with a legacy blog_charset "<ESC(B/script>"
     * is "</script>" to the browser while no pattern sees such a sequence.
     */
    public const CONTROL_BYTES = '[\x00-\x08\x0b\x0e-\x1f]';

    public static function is_enabled(): bool
    {
        $default = defined('WPMCP_ALLOW_JS_INJECTION') && WPMCP_ALLOW_JS_INJECTION;

        return (bool) apply_filters('wpmcp_allow_js_injection', $default);
    }

    public static function current_user_can_inject(): bool
    {
        return current_user_can('unfiltered_html');
    }

    /**
     * Sequences that must never appear in a snippet printed as the raw text
     * of a <script> element. "</script" is the obvious one. "<!--" and
     * "<script" matter for a subtler reason: together they put the HTML
     * tokenizer into script-data DOUBLE-escaped state, where the renderer's
     * own "</script>" no longer closes the element, so the rest of the page
     * is swallowed as script data and the document never finishes. That
     * needs unfiltered_html to reach, so it is a robustness rule rather than
     * a privilege boundary - but a snippet that silently breaks every page
     * it renders on is not something this tool should be able to store.
     *
     * C0 control bytes other than tab, LF, FF and CR are refused for the
     * same reason on a different route: ESC is a zero-width mode switch in
     * ISO-2022-JP, so on a site with a legacy blog_charset "<ESC(B/script>"
     * is "</script>" to the browser while the pattern sees no such sequence.
     *
     * Lives on the guard, not on Add_Custom_Js, because the renderer applies
     * the same rule and the guard is the one class in this namespace that
     * every build ships (Opt_In_Gates references it).
     */
    public static function has_breakout(string $js): bool
    {
        // Fail closed: a PCRE error (false) counts as a breakout, not a pass.
        return 0 !== preg_match('#</\s*script|<\s*script|<!--|' . self::CONTROL_BYTES . '#i', $js);
    }
}
