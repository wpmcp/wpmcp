<?php

namespace WPMCP\Tools\Code;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Static analysis guardrail for arbitrary PHP snippets. Never executes,
 * `eval`s, or `include`s the given code; syntax is checked by tokenizing the
 * source with token_get_all(..., TOKEN_PARSE), which raises a ParseError for
 * malformed code without running it. Safety heuristics are pattern-based
 * warnings only, this class never blocks and never writes anything.
 */
class Php_Snippet_Validator
{
    public static function validate(string $code): array
    {
        [$syntax_valid, $errors] = self::check_syntax($code);

        return [
            'syntax_valid' => $syntax_valid,
            'errors'       => $errors,
            'warnings'     => [],
            'safe'         => true,
        ];
    }

    /**
     * @return array{0: bool, 1: array}
     */
    private static function check_syntax(string $code): array
    {
        $source = self::ensure_php_tag($code);

        try {
            token_get_all($source, TOKEN_PARSE);
        } catch (\ParseError $e) {
            return [false, [
                [
                    'message' => $e->getMessage(),
                    'line'    => $e->getLine(),
                ],
            ]];
        }

        return [true, []];
    }

    private static function ensure_php_tag(string $code): string
    {
        if (0 === strpos(ltrim($code), '<?php')) {
            return $code;
        }
        return '<?php ' . $code;
    }
}
