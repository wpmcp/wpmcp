<?php

namespace WPMCP\Tools\WidgetBuilder\Compiler;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Token-parse lint that every piece of generated widget PHP must pass BEFORE
 * it is written to disk (issue #72). Widget_Compiler is the sole PHP emitter,
 * so anything this lint flags is a generator bug, not agent input: the lint is
 * a tripwire, and a failure aborts the write entirely.
 *
 * The call surface is an ALLOWLIST, not a denylist. A denylist of scary
 * function names is the wrong shape for a safety proof here: it has to
 * enumerate every sink (including string-callable dispatchers such as
 * array_map('system', ...) and add_action('init', 'system'), where the
 * dangerous name never appears as an identifier at all), and it fails open on
 * everything it forgot. Because the emitter is the only producer, the set of
 * functions generated code may ever call is closed and tiny, so the lint
 * asserts membership of that set and fails closed on anything else.
 *
 * Three static layers (the code is never executed here):
 *  1. token_get_all() must tokenize the source without a ParseError or any
 *     other Throwable (TOKEN_PARSE also raises CompileError, e.g. for
 *     __halt_compiler() inside a function).
 *  2. No forbidden construct: eval, backticks, include/require,
 *     __halt_compiler, close tags / inline HTML, variable functions ($fn()),
 *     variable variables ($$x), dynamic method / static / property access
 *     ($o->$m(), C::$$m), dynamic instantiation (new $c), and complex string
 *     interpolation.
 *  3. Every call-position identifier - plain, qualified or fully qualified -
 *     must be in ALLOWED_CALLS.
 *  4. Calls that are not free-function calls are allowlisted too, by RECEIVER
 *     as well as by name: `->` and `?->` are accepted only on a literal $this
 *     receiver, `::` only on self / static / parent, and the method named must
 *     be in ALLOWED_METHODS. `new` is rejected outright, because the emitter
 *     never instantiates anything. Checking only the shape of the operator and
 *     not the receiver would let `Evil::system('id')`, `$o->system('id')` and
 *     `new Evil('id')` through, which is the same string-callable-dispatch
 *     family layer 3 exists to close.
 */
class Generated_Code_Lint
{
    /**
     * The complete set of free functions generated widget code may call.
     * Everything the emitter produces is here; anything else is a bug.
     */
    public const ALLOWED_CALLS = [
        // Every distinct escaper in Widget_Spec::CONTROL_TYPES, and nothing
        // else: an allowlist wider than the emitter's actual output is the
        // failure mode this class's whole argument is against, so the suite
        // asserts this list against what compiling really emits.
        'esc_html', 'esc_attr', 'esc_url', 'wp_kses_post',
        // The guards the file preamble and the render body use.
        'is_array', 'defined', 'class_exists',
    ];

    /**
     * The complete set of methods generated code may call, and only ever on
     * $this: the four Elementor Widget_Base entry points the emitter uses.
     */
    public const ALLOWED_METHODS = [
        'start_controls_section', 'add_control', 'end_controls_section',
        'get_settings_for_display',
    ];

    /** Receivers a `::` may appear on. The emitter emits none; this is a floor. */
    private const STATIC_RECEIVERS = ['self', 'static', 'parent'];

    /** Tokens that make the identifier after them a declaration, not a call. */
    private const DECLARATION_BEFORE = [
        T_FUNCTION, T_CONST, T_CLASS, T_INTERFACE, T_TRAIT,
    ];

    /**
     * @return true|\WP_Error true when the source is clean; WP_Error
     *                        (code wpmcp_generated_code_rejected) otherwise.
     */
    public static function check(string $source)
    {
        if ('' === trim($source)) {
            return new \WP_Error('wpmcp_generated_code_rejected', 'Generated source is empty.');
        }
        if (! str_starts_with(ltrim($source), '<?php')) {
            return new \WP_Error('wpmcp_generated_code_rejected', 'Generated source must begin with a PHP open tag.');
        }

        try {
            $tokens = token_get_all($source, TOKEN_PARSE);
        } catch (\Throwable $e) {
            // ParseError for a syntax error, CompileError for things the
            // compiler rejects later (__halt_compiler in a function body).
            // Either way the tripwire must report, never fatal.
            return new \WP_Error(
                'wpmcp_generated_code_rejected',
                'Generated source does not parse: ' . $e->getMessage()
            );
        }

        // Drop whitespace and comments so "followed by" / "preceded by" checks
        // below mean what they say regardless of formatting.
        $significant = [];
        foreach ($tokens as $token) {
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $significant[] = $token;
        }

        $count = count($significant);
        for ($i = 0; $i < $count; $i++) {
            $token = $significant[$i];
            $prev  = $i > 0 ? $significant[$i - 1] : null;
            $next  = $i + 1 < $count ? $significant[$i + 1] : null;

            if (! is_array($token)) {
                if ('`' === $token) {
                    return self::rejected('backtick shell execution');
                }
                // `$` before a variable is a variable variable ($$x), and
                // before `{` is the ${expr} form; both are dynamic lookups.
                if ('$' === $token) {
                    return self::rejected('variable variable ($$x)');
                }
                continue;
            }

            [$id, $text] = $token;

            if (in_array($id, [T_EVAL, T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE, T_HALT_COMPILER], true)) {
                return self::rejected(trim($text));
            }
            // A close tag would let trailing bytes leak out as raw output.
            if (T_CLOSE_TAG === $id || T_INLINE_HTML === $id) {
                return self::rejected('close tag / inline HTML');
            }
            // "{$expr}" / "${expr}" inside a string: an interpolation that can
            // reach arbitrary expressions.
            if (T_DOLLAR_OPEN_CURLY_BRACES === $id || T_CURLY_OPEN === $id) {
                return self::rejected('complex string interpolation');
            }

            if (T_VARIABLE === $id) {
                // $fn(...) - a variable function call.
                if (! is_array($next) && '(' === $next) {
                    return self::rejected('variable function call ($fn())');
                }
                // $$x written as two adjacent variable tokens is impossible,
                // but $o->$m() / C::$m() / new $c are all dynamic dispatch.
                if (is_array($prev) && in_array($prev[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW], true)) {
                    return self::rejected('dynamic method / property / class reference');
                }
                continue;
            }

            // The emitter never instantiates anything, so `new` of ANY class
            // is a generator bug. Allowing it by shape (as "not a call") is
            // what let `new Evil('id')` through.
            if (T_NEW === $id) {
                return self::rejected('object instantiation (new)');
            }

            // Member access. The receiver decides, not the operator: only
            // $this-> and self:: / static:: / parent:: are the widget API.
            if (in_array($id, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
                if (! is_array($next) && '{' === $next) {
                    return self::rejected('dynamic method / class reference');
                }
                if (T_DOUBLE_COLON === $id) {
                    $receiver = is_array($prev) ? strtolower((string) $prev[1]) : '';
                    if (! in_array($receiver, self::STATIC_RECEIVERS, true)) {
                        return self::rejected('static access on a receiver other than self/static/parent');
                    }
                } elseif (! is_array($prev) || T_VARIABLE !== $prev[0] || '$this' !== $prev[1]) {
                    return self::rejected('member access on a receiver other than $this');
                }
                continue;
            }

            if (! in_array($id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }
            // Only call position matters: `$this->copy`, a method named
            // rename(), or a const named SYSTEM are not calls to anything.
            if (is_array($prev) && in_array($prev[0], self::DECLARATION_BEFORE, true)) {
                continue;
            }

            // A member name. Its receiver was already checked when the
            // operator token was visited, so all that is left is the name: a
            // property/constant read is fine, a CALL must be in the method
            // allowlist.
            $is_member = is_array($prev) && in_array(
                $prev[0],
                [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON],
                true
            );
            if ($is_member) {
                if (! is_array($next) && '(' === $next && ! in_array(strtolower($text), self::ALLOWED_METHODS, true)) {
                    return self::rejected($text . '() is not in the generated-code method allowlist');
                }
                continue;
            }

            if (is_array($next) || '(' !== $next) {
                continue;
            }
            // A fully qualified call tokenizes as ONE token in PHP 8
            // (`\exec` is a single T_NAME_FULLY_QUALIFIED), so match on the
            // last namespace segment, not on the raw text.
            $segments = explode('\\', ltrim($text, '\\'));
            $callee   = strtolower((string) end($segments));
            if (! in_array($callee, self::ALLOWED_CALLS, true)) {
                return self::rejected($text . '() is not in the generated-code call allowlist');
            }
        }

        return true;
    }

    private static function rejected(string $what): \WP_Error
    {
        return new \WP_Error(
            'wpmcp_generated_code_rejected',
            sprintf('Generated source contains a forbidden construct (%s); write aborted.', $what)
        );
    }
}
