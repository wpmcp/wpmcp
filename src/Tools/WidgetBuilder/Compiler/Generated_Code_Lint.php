<?php

namespace WPMCP\Tools\WidgetBuilder\Compiler;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Token-parse lint that every piece of generated widget PHP must pass BEFORE
 * it is written to disk (issue #72). The generator is the sole PHP emitter,
 * so anything this lint flags is a generator bug, not agent input: the lint
 * is a tripwire, and a failure aborts the write entirely.
 *
 * Two layers, both static (the code is never executed here):
 *  1. token_get_all() must tokenize the source without a ParseError.
 *  2. No forbidden token may appear anywhere: eval, backticks, variable
 *     variables, exec-family / filesystem-write / network calls, and
 *     include/require (compiled widgets are self-contained by design).
 */
class Generated_Code_Lint
{
    private const FORBIDDEN_FUNCTIONS = [
        'exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen',
        'pcntl_exec', 'assert', 'create_function', 'call_user_func',
        'call_user_func_array', 'file_put_contents', 'fopen', 'fwrite',
        'unlink', 'rename', 'copy', 'mkdir', 'rmdir', 'chmod', 'touch',
        'curl_init', 'curl_exec', 'fsockopen', 'file_get_contents',
        'wp_remote_get', 'wp_remote_post', 'wp_remote_request',
        'base64_decode', 'gzinflate', 'gzuncompress', 'str_rot13',
        'extract', 'ob_start',
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
        } catch (\ParseError $e) {
            return new \WP_Error(
                'wpmcp_generated_code_rejected',
                'Generated source does not parse: ' . $e->getMessage()
            );
        }

        foreach ($tokens as $i => $token) {
            if (! is_array($token)) {
                // Single-char token: backtick means shell execution.
                if ('`' === $token) {
                    return self::rejected('backtick shell execution');
                }
                continue;
            }

            [$id, $text] = $token;

            if (in_array($id, [T_EVAL, T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE, T_HALT_COMPILER], true)) {
                return self::rejected(trim($text));
            }
            // Close tag would let trailing bytes leak out as raw output.
            if (T_CLOSE_TAG === $id || T_INLINE_HTML === $id) {
                return self::rejected('close tag / inline HTML');
            }
            // Variable function call ($fn()) or variable variable ($$x).
            if (T_DOLLAR_OPEN_CURLY_BRACES === $id || T_CURLY_OPEN === $id) {
                return self::rejected('complex string interpolation');
            }
            if (T_STRING === $id && in_array(strtolower($text), self::FORBIDDEN_FUNCTIONS, true)) {
                return self::rejected($text . '()');
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
