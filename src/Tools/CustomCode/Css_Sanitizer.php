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
 * through this class. The render pass is a SECOND CHANCE at a value that
 * arrived by another route (a direct DB edit, an older build, another
 * plugin), not an independent barrier: it is the same decision run again, so
 * anything the write pass would accept it accepts too.
 *
 * Matching happens against the raw text AND a CANONICAL form of it. CSS lets an author spell any identifier with escape sequences and
 * split any token with a comment, so "@im\port", "expres\sion(",
 * "java\script:" and "expression/**\/(" are all valid CSS that a raw-text
 * blacklist walks straight past. canonicalize() strips comments and decodes
 * escape sequences first, so those spellings collapse onto the ones the
 * pattern list names. The value that gets STORED is still the author's
 * original text: canonicalization is a lens for the decision, not a
 * rewrite of the CSS (a rewritten stylesheet that changed meaning is worse
 * than a hard error the agent can react to).
 *
 * Which is exactly why the raw text is checked too. Canonicalization only
 * ever DELETES (comments, escapes), so a payload parked inside a comment is
 * invisible to it while still sitting in the bytes the renderer prints. The
 * canonical pass catches obfuscated CSS; the raw pass catches text that is
 * not CSS at all. Both are needed.
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

        // Every lens the decision is made through. See analysis_forms().
        $forms = self::analysis_forms($clean, $canonical);

        // The pattern list runs against BOTH forms, and the raw pass is the
        // load-bearing one. canonicalize() deletes comments so a keyword
        // SPLIT by one collapses onto its plain spelling, but the value that
        // gets stored and echoed is the raw text, and a payload parked INSIDE
        // a comment survives there untouched while the canonical form comes
        // out spotless. Two spellings exploited exactly that:
        //
        //   /* </style><script>alert(1)</script> */ .a { color: red; }
        //   .a{content:"/*"}</style><script>alert(1)</script>.b{content:"*/"}
        //
        // (the second one hides the comment markers inside CSS strings, which
        // the comment regex is not string-aware enough to notice). Neither is
        // CSS the browser executes - but the HTML tokenizer that closes a
        // <style> element has never heard of CSS comments or CSS strings and
        // ends the element at the first literal "</style", so both broke out.
        // Checking the raw text costs one legitimate case - a comment that
        // spells out markup or @import - and closes the whole class.
        foreach ($forms as $subject) {
            foreach (self::FORBIDDEN_PATTERNS as $pattern) {
                if (preg_match($pattern, $subject)) {
                    throw new \InvalidArgumentException(
                        'The CSS was rejected by the sanitizer: it contains a construct that is never allowed in managed custom CSS (markup, expression()/behavior/-moz-binding, script-capable URL schemes, @import/@charset, or a data: URL), including inside a comment or a string, and including when spelled with CSS escape sequences or split by a comment.'
                    );
                }
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
     * covers all of them: comments are removed (string-aware, see
     * strip_comments()), and escape sequences are decoded (see
     * decode_escapes()).
     */
    public static function canonicalize(string $css): string
    {
        return self::decode_escapes(self::strip_comments($css));
    }

    /**
     * The forms the forbidden-pattern list is matched against. A construct
     * found in ANY of them is refused, so each form only has to close the
     * gap the others leave:
     *
     *  - the raw text: what is actually stored and echoed into <style>. The
     *    HTML tokenizer that ends that element knows nothing about CSS
     *    comments, strings or escapes, so markup parked inside any of them
     *    is still a breakout.
     *  - the canonical form (string-aware comment strip, then escape decode):
     *    catches keywords split by a comment or spelled with escapes, and is
     *    not fooled by comment markers parked inside CSS strings.
     *  - escapes decoded, comments KEPT: a comment stripper can only be as
     *    right as its idea of where comments are. Where it and a browser
     *    disagree (an unquoted url() carrying a quote is a bad-url token to
     *    the browser but opens a string to a simple scanner), a span the
     *    stripper deleted can be live CSS. Keeping everything means an
     *    escape-obfuscated keyword is visible wherever it sits; the cost is
     *    refusing a comment that spells out an escaped @import.
     *  - a naive (not string-aware) comment strip, then escape decode: the
     *    same disagreement in the other direction, for keywords split by a
     *    comment the string-aware scanner took for string content.
     *
     * @return string[]
     */
    private static function analysis_forms(string $raw, string $canonical): array
    {
        $naive = preg_replace('#/\*.*?\*/#s', '', $raw);

        return [
            $raw,
            $canonical,
            self::decode_escapes($raw),
            self::decode_escapes(null === $naive ? $raw : $naive),
        ];
    }

    /**
     * Remove CSS comments the way a CSS tokenizer does: a "/*" opens a
     * comment only OUTSIDE a string, and a quote opens a string only outside
     * a comment. The predecessor was a plain regex, which deleted everything
     * between a "/*" and a "*\/" that both sat inside CSS strings, so
     *
     *   .a{content:"/*"} @im\port url(//evil); .b{content:"*\/"}
     *
     * canonicalized to two harmless rules while the browser read an import
     * between them. It also refused content: "/*" as an unterminated comment.
     *
     * Strings end at their closing quote or at an unescaped newline (a CSS
     * bad-string), and a backslash always consumes the next byte, inside a
     * string or not, so an escaped quote neither opens nor closes one. Byte
     * iteration is safe on UTF-8: every character this looks at is ASCII,
     * and no UTF-8 continuation byte can equal one.
     */
    private static function strip_comments(string $css): string
    {
        $out   = '';
        $len   = strlen($css);
        $quote = '';

        for ($i = 0; $i < $len; $i++) {
            $c = $css[$i];

            if ('\\' === $c) {
                $out .= $c;
                if ($i + 1 < $len) {
                    $out .= $css[++$i];
                }
                continue;
            }

            if ('' !== $quote) {
                if ($c === $quote || "\n" === $c || "\r" === $c || "\f" === $c) {
                    $quote = '';
                }
                $out .= $c;
                continue;
            }

            if ('"' === $c || "'" === $c) {
                $quote = $c;
                $out  .= $c;
                continue;
            }

            if ('/' === $c && $i + 1 < $len && '*' === $css[$i + 1]) {
                $end = strpos($css, '*/', $i + 2);
                if (false === $end) {
                    // An unterminated comment hides everything after it from
                    // the pattern list. Rather than guess how a given engine
                    // recovers, refuse it: a stylesheet whose comment never
                    // closes is not something an agent meant to store.
                    throw new \InvalidArgumentException('The CSS was rejected: it contains an unterminated comment.');
                }
                $i = $end + 1;
                continue;
            }

            $out .= $c;
        }

        return $out;
    }

    /**
     * Decode escape sequences (both the 1-6 hex-digit form with its optional
     * trailing whitespace and the backslash-any-character form) to the
     * characters they denote. Non-ASCII code points decode to a single
     * placeholder: they can never be part of an ASCII keyword like "import"
     * or "script", and materializing them exactly would only add an encoding
     * dependency.
     */
    private static function decode_escapes(string $css): string
    {
        $decoded = preg_replace_callback(
            '#\\\\(?:([0-9a-fA-F]{1,6})[ \t\r\n\f]?|(\r\n|[\r\n\f])|(.))#s',
            static function (array $m): string {
                if (isset($m[1]) && '' !== $m[1]) {
                    $code = (int) hexdec($m[1]);
                    return ($code > 0 && $code < 0x80) ? chr($code) : "\u{FFFD}";
                }

                // A backslash followed by a newline is a CSS line
                // continuation: the parser REMOVES it, so it splits a keyword
                // exactly the way a comment does. Decoding it to a literal
                // newline instead left "java\<newline>script:" and
                // "expres\<newline>sion(" reading as two tokens here while
                // the browser read one.
                if (isset($m[2]) && '' !== $m[2]) {
                    return '';
                }

                return $m[3] ?? '';
            },
            $css
        );

        if (null === $decoded) {
            // Catastrophic input (e.g. PCRE backtrack limit). Fail closed:
            // an unanalyzable stylesheet is not a storable one.
            throw new \InvalidArgumentException('The CSS was rejected: it could not be parsed for analysis.');
        }

        return $decoded;
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
            throw new \InvalidArgumentException(sprintf('The selector "%s" contains characters outside the allowed selector alphabet.', esc_html($selector)));
        }

        if (substr_count($selector, '(') !== substr_count($selector, ')')) {
            throw new \InvalidArgumentException('The selector was rejected: unbalanced parentheses.');
        }

        return $selector;
    }
}
