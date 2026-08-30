<?php

namespace WPMCP\Tools\CustomCode;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Pure, independently testable CSS sanitizer for the custom CSS injection
 * tools (issue #63). Stored CSS is printed inside a <style> element on the
 * front end, so the attack to prevent is breaking OUT of that element
 * (</style><script>...) or smuggling script-capable constructs through
 * legacy CSS features. Sanitize-on-write AND sanitize-on-render both route
 * through this class, so a payload that somehow reaches storage unfiltered
 * still cannot reach the page.
 *
 * This deliberately mirrors the shape of core's wp_update_custom_css_post
 * validation (balanced braces, no markup) while being stricter: anything
 * that even resembles a script vector is rejected outright rather than
 * cleaned, because "cleaned" CSS that changed meaning is worse than a hard
 * error the agent can react to.
 *
 * TODO(#63): grow the payload corpus in tests/pro alongside every new
 * rejection rule here; the corpus tests come before any relaxation of these
 * rules.
 */
class Css_Sanitizer
{
    /**
     * Substring/pattern vectors that are never legitimate in the CSS this
     * plugin manages. Case-insensitive match against the raw input.
     */
    private const FORBIDDEN_PATTERNS = [
        '#</?\s*(style|script)#i',        // element breakout
        '#<\s*/?\s*[a-z!/]#i',            // any other markup / comment opener
        '#expression\s*\(#i',             // IE expression()
        '#behavior\s*:#i',                // IE HTC behaviors
        '#-moz-binding\s*:#i',            // XBL bindings
        '#javascript\s*:#i',              // script scheme (url() or otherwise)
        '#vbscript\s*:#i',
        '#@import\b#i',                   // remote stylesheet pull-in
        '#@charset\b#i',
        '#url\s*\(\s*[\'"]?\s*data:#i',   // data: URLs inside url()
        '#\\\\[0-9a-f]{2}#i',             // CSS escape obfuscation of the above
    ];

    /**
     * Validate and normalize a CSS block. Returns the CSS to store, or
     * throws InvalidArgumentException naming the first violated rule.
     */
    public static function sanitize(string $css): string
    {
        $css = (string) wp_check_invalid_utf8($css);

        foreach (self::FORBIDDEN_PATTERNS as $pattern) {
            if (preg_match($pattern, $css)) {
                throw new \InvalidArgumentException(
                    'The CSS was rejected by the sanitizer: it contains a construct that is never allowed in managed custom CSS (markup, expression()/behavior/-moz-binding, script-capable URL schemes, @import/@charset, or escape-sequence obfuscation).'
                );
            }
        }

        if (substr_count($css, '{') !== substr_count($css, '}')) {
            throw new \InvalidArgumentException('The CSS was rejected: unbalanced braces.');
        }

        return trim($css);
    }

    /**
     * Validate a bare selector (used when the caller passes a selector and
     * declarations separately). Conservative allowlist: the characters that
     * appear in real-world selectors and nothing script-capable.
     */
    public static function sanitize_selector(string $selector): string
    {
        $selector = trim($selector);

        if ('' === $selector) {
            throw new \InvalidArgumentException('A selector is required.');
        }

        if (! preg_match('#\A[a-zA-Z0-9_\-\.\#\*\s>+~:\[\]="\'(),^$|]+\z#', $selector) || false !== strpos($selector, '<')) {
            throw new \InvalidArgumentException("The selector \"{$selector}\" contains characters outside the allowed selector alphabet.");
        }

        if (substr_count($selector, '(') !== substr_count($selector, ')')) {
            throw new \InvalidArgumentException('The selector was rejected: unbalanced parentheses.');
        }

        return $selector;
    }
}
