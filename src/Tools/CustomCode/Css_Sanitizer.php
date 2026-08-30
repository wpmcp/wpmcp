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
 * Matching happens against a CANONICAL form of the input, never the raw
 * text. CSS lets an author spell any identifier with escape sequences and
 * split any token with a comment, so "@im\port", "expres\sion(",
 * "java\script:" and "expression/**\/(" are all valid CSS that a raw-text
 * blacklist walks straight past. canonicalize() strips comments and decodes
 * escape sequences first, so those spellings collapse onto the ones the
 * pattern list names. The value that gets STORED is still the author's
 * original text: canonicalization is a lens for the decision, not a
 * rewrite of the CSS (a rewritten stylesheet that changed meaning is worse
 * than a hard error the agent can react to).
 *
 * The predecessor of canonicalize() was a blanket "reject any \XX escape"
 * rule. It was both too weak (only the hex form was covered, so the
 * one-backslash spellings above passed) and too strong (content: "\f105"
 * icon fonts and "\201C" typographic quotes are ordinary CSS and were
 * refused), which pushed agents back to raw file editing - the exact
 * behavior issue #63 exists to prevent.
 *
 * This deliberately mirrors the shape of core's wp_update_custom_css_post
 * validation (balanced braces, no markup) while being stricter: anything
 * that even resembles a script vector is rejected outright rather than
 * cleaned.
 */
class Css_Sanitizer
{
    /**
     * Vectors that are never legitimate in the CSS this plugin manages.
     * Matched case-insensitively against canonicalize()'s output.
     */
    private const FORBIDDEN_PATTERNS = [
        '#</?\s*(style|script)#i',        // element breakout
        // Markup / comment openers. Deliberately NOT '<\s*[a-z]': CSS media
        // range syntax ("@media (400px < width)") puts a bare '<' next to an
        // identifier and is valid, while HTML never allows whitespace between
        // '<' and the tag name.
        '#<(?:/|!|[a-z][a-z0-9]*[\s/>])#i',
        '#expression\s*\(#i',             // IE expression()
        '#behaviou?r\s*:#i',              // IE HTC behaviors
        '#-moz-binding\s*:#i',            // XBL bindings
        '#javascript\s*:#i',              // script scheme (url() or otherwise)
        '#vbscript\s*:#i',
        '#@import\b#i',                   // remote stylesheet pull-in
        '#@charset\b#i',
        '#url\s*\(\s*[\'"]?\s*data:#i',   // data: URLs inside url()
    ];

    /**
     * Validate and normalize a CSS block. Returns the CSS to store, or
     * throws InvalidArgumentException naming the first violated rule.
     */
    public static function sanitize(string $css): string
    {
        $clean = (string) wp_check_invalid_utf8($css);

        // wp_check_invalid_utf8() returns '' for input it cannot validate.
        // Storing that silently would overwrite the page's existing block
        // with nothing while the tool reported a successful write, so a
        // non-empty input that empties out is an error, not a result.
        if ('' === $clean && '' !== $css) {
            throw new \InvalidArgumentException('The CSS was rejected: it is not valid UTF-8.');
        }

        $canonical = self::canonicalize($clean);

        foreach (self::FORBIDDEN_PATTERNS as $pattern) {
            if (preg_match($pattern, $canonical)) {
                throw new \InvalidArgumentException(
                    'The CSS was rejected by the sanitizer: it contains a construct that is never allowed in managed custom CSS (markup, expression()/behavior/-moz-binding, script-capable URL schemes, @import/@charset, or a data: URL), including when spelled with CSS escape sequences or split by a comment.'
                );
            }
        }

        // Braces are counted on the canonical form too: a '{' hidden behind
        // an escape or a comment would otherwise let an unbalanced block
        // through and swallow whatever the renderer prints after it.
        if (substr_count($canonical, '{') !== substr_count($canonical, '}')) {
            throw new \InvalidArgumentException('The CSS was rejected: unbalanced braces.');
        }

        return trim($clean);
    }

    /**
     * Collapse the spellings CSS treats as equivalent so one pattern list
     * covers all of them: comments are removed, and escape sequences (both
     * the 1-6 hex-digit form with its optional trailing whitespace and the
     * backslash-any-character form) are decoded to the characters they
     * denote. Non-ASCII code points decode to a single placeholder: they can
     * never be part of an ASCII keyword like "import" or "script", and
     * materializing them exactly would only add an encoding dependency.
     */
    public static function canonicalize(string $css): string
    {
        $out = preg_replace('#/\*.*?\*/#s', '', $css);
        if (null === $out) {
            // Catastrophic input (e.g. PCRE backtrack limit). Fail closed:
            // an unanalyzable stylesheet is not a storable one.
            throw new \InvalidArgumentException('The CSS was rejected: it could not be parsed for analysis.');
        }

        // An unterminated comment hides everything after it from the pattern
        // list. Rather than guess how a given engine recovers, refuse it: a
        // stylesheet whose comment never closes is not something an agent
        // meant to store.
        if (false !== strpos((string) $out, '/*')) {
            throw new \InvalidArgumentException('The CSS was rejected: it contains an unterminated comment.');
        }

        $decoded = preg_replace_callback(
            '#\\\\(?:([0-9a-fA-F]{1,6})[ \t\r\n\f]?|(.))#s',
            static function (array $m): string {
                if (isset($m[1]) && '' !== $m[1]) {
                    $code = (int) hexdec($m[1]);
                    return ($code > 0 && $code < 0x80) ? chr($code) : "\u{FFFD}";
                }
                return $m[2] ?? '';
            },
            (string) $out
        );

        return (string) $decoded;
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
