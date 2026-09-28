<?php

namespace WPMCP\Compliance\Rules;

use WPMCP\Compliance\Rule_Context;
use WPMCP\Compliance\Severity;

/**
 * WordPress.Security.ValidatedSanitizedInput: superglobal reads must be
 * unslashed and sanitized before use.
 *
 * Matches code tokens only (Source_File::grep_code()): a superglobal named
 * in a comment or docblock is not a read, and a sanitizer named there does
 * not sanitize anything (issue #347). The phpcs:ignore check still reads the
 * raw line, because that annotation lives in a comment.
 */
final class Input_Sanitization_Rule extends Base_Rule
{
    private const SUPERGLOBALS = ['_GET', '_POST', '_REQUEST', '_COOKIE', '_SERVER', '_FILES'];

    private const SANITIZERS = [
        'sanitize_text_field', 'sanitize_key', 'sanitize_email', 'sanitize_title', 'sanitize_file_name',
        'sanitize_textarea_field', 'sanitize_user', 'absint', 'intval', 'floatval', 'wp_unslash',
        'esc_url_raw', 'wp_kses', 'wp_kses_post', 'map_deep', 'wp_parse_id_list', 'rest_sanitize_boolean',
        'wp_verify_nonce', 'check_admin_referer', 'check_ajax_referer', 'isset', 'empty', 'array_key_exists',
    ];

    public function default_severity(): string
    {
        return Severity::LIKELY_REJECT;
    }

    public function id(): string
    {
        return 'PCP-INPUT-SANITIZATION';
    }

    public function guideline(): string
    {
        return 'WordPress.Security.ValidatedSanitizedInput';
    }

    public function title(): string
    {
        return 'Unsanitized superglobal read';
    }

    public function explanation(): string
    {
        return 'Request data must be unslashed with wp_unslash() and sanitized with the function that '
            . 'matches the expected shape before it is used or stored. A value that is only escaped on '
            . 'output still trips the sniff, and reviewers ask for it to be fixed.';
    }

    public function check(Rule_Context $context): array
    {
        $pattern = '/\$(' . implode('|', self::SUPERGLOBALS) . ')\s*\[/';
        $findings = [];
        foreach ($context->php_files() as $file) {
            foreach ($file->grep_code($pattern) as $hit) {
                if ($this->is_handled($hit['text'])) {
                    continue;
                }
                if ($file->has_phpcs_ignore($hit['line'], 'WordPress.Security.ValidatedSanitizedInput')) {
                    continue;
                }
                $findings[] = $this->finding(
                    $file,
                    $hit['line'],
                    sprintf('%s read without wp_unslash() or a sanitizer on the same statement', rtrim($hit['match'], "[ \t"))
                );
            }
        }
        return $findings;
    }

    private function is_handled(string $line): bool
    {
        foreach (self::SANITIZERS as $sanitizer) {
            if (preg_match('/\b' . preg_quote($sanitizer, '/') . '\s*\(/', $line)) {
                return true;
            }
        }
        return (bool) preg_match('/\(\s*(int|integer|float|bool|boolean)\s*\)\s*\$_/', $line);
    }
}
