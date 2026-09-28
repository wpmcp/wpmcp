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
 *  3. Every call-position identifier must be a GLOBAL name in
 *     ALLOWED_CALLS. Qualified and relative names are rejected (they share a
 *     last segment with an allowed function and are a different function),
 *     `use` and `namespace` statements are rejected (they re-point an allowed
 *     name), and calling the result of an expression ("system"(), $a[0](),
 *     f()()) is rejected, because no identifier is in call position there.
 *  4. Calls that are not free-function calls are allowlisted too, by RECEIVER
 *     as well as by name: `->` and `?->` are accepted only on a literal $this
 *     receiver, `::` only on self / static / parent, and the method named must
 *     be in ALLOWED_METHODS. The single exception is ALLOWED_STATIC_CALLS,
 *     the data-control renderer, matched as an exact receiver and method. `new` is rejected outright, because the emitter
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
        // The guards the file preamble and the render body use (is_array and
        // is_scalar reduce Elementor's array-valued controls the way
        // Widget_Renderer::scalarize() does).
        'is_array', 'is_scalar', 'defined', 'class_exists',
    ];

    /**
     * The complete set of methods generated code may call, and only ever on
     * $this: the four Elementor Widget_Base entry points the emitter uses.
     */
    public const ALLOWED_METHODS = [
        'start_controls_section', 'add_control', 'end_controls_section',
        'get_settings_for_display',
    ];

    /**
     * The one fully-qualified static call generated code may make: the data
     * control renderer (issue #296). Matched exactly, receiver as one
     * fully-qualified name token and method as a plain name followed by `(`,
     * so no other method, class, dynamic name or property read gets through.
     */
    public const ALLOWED_STATIC_CALLS = [
        '\\WPMCP\\Tools\\WidgetBuilder\\Data\\Widget_Data' => ['render'],
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

        $count    = count($significant);
        $approved = [];
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
                // Calling the RESULT of an expression: "system"('id'),
                // ('sys' . 'tem')('id'), $a[0]('id'), f()('id'). No identifier
                // is in call position, so the name allowlist below would never
                // see the callee. The emitter never produces any of these.
                if ('(' === $token && self::ends_expression($prev)) {
                    return self::rejected('call of an expression result');
                }
                continue;
            }

            [$id, $text] = $token;

            if (in_array($id, [T_EVAL, T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE, T_HALT_COMPILER], true)) {
                return self::rejected(trim($text));
            }
            // An allowlisted NAME only means the allowlisted FUNCTION in the
            // global namespace with no imports: `use function system as
            // esc_html;` or `namespace Evil;` would make esc_html() resolve
            // somewhere else. The emitter writes neither statement.
            if (T_USE === $id || T_NAMESPACE === $id) {
                return self::rejected('use / namespace statement');
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
                $static_receiver = T_DOUBLE_COLON === $id && is_array($prev) && T_NAME_FULLY_QUALIFIED === $prev[0]
                    && isset(self::ALLOWED_STATIC_CALLS[ $prev[1] ]);
                if ($static_receiver) {
                    $after      = $significant[ $i + 2 ] ?? null;
                    $is_allowed = is_array($next) && T_STRING === $next[0] && '(' === $after
                        && in_array($next[1], self::ALLOWED_STATIC_CALLS[ $prev[1] ], true);
                    if (! $is_allowed) {
                        return self::rejected('static access other than an allowlisted static call');
                    }
                    $approved[ $i + 1 ] = true;
                    continue;
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

            if (! in_array($id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
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
                if (isset($approved[ $i ])) {
                    continue;
                }
                if (! is_array($next) && '(' === $next && ! in_array(strtolower($text), self::ALLOWED_METHODS, true)) {
                    return self::rejected($text . '() is not in the generated-code method allowlist');
                }
                continue;
            }

            if (is_array($next) || '(' !== $next) {
                continue;
            }
            // A qualified call tokenizes as ONE token in PHP 8 (`\exec` is a
            // single T_NAME_FULLY_QUALIFIED). Only a global name may match
            // the allowlist: `\Evil\esc_html()` or `Evil\esc_html()` is a
            // different function that merely shares the last segment.
            $callee = strtolower(ltrim($text, '\\'));
            if (T_NAME_QUALIFIED === $id || T_NAME_RELATIVE === $id || str_contains($callee, '\\')) {
                return self::rejected($text . '() is a namespaced call; generated code calls global functions only');
            }
            if (! in_array($callee, self::ALLOWED_CALLS, true)) {
                return self::rejected($text . '() is not in the generated-code call allowlist');
            }
        }

        return true;
    }

    /**
     * True when $prev closes an expression, so a `(` after it would call the
     * expression's value rather than a named function.
     *
     * @param array{0:int,1:string,2:int}|string|null $prev
     */
    private static function ends_expression($prev): bool
    {
        if (is_array($prev)) {
            return in_array($prev[0], [T_CONSTANT_ENCAPSED_STRING, T_END_HEREDOC], true);
        }
        return in_array($prev, [')', ']', '}', '"'], true);
    }

    private static function rejected(string $what): \WP_Error
    {
        return new \WP_Error(
            'wpmcp_generated_code_rejected',
            sprintf('Generated source contains a forbidden construct (%s); write aborted.', $what)
        );
    }
}
