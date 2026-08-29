<?php

namespace WPMCP\Compliance\Rules;

use WPMCP\Compliance\Rule_Context;
use WPMCP\Compliance\Severity;

/**
 * The rest of Plugin Check's forbidden and discouraged function lists.
 *
 * The execution family lives in Dangerous_Constructs_Rule so the two can be
 * profiled independently; everything else Plugin Check errors or discourages
 * is here.
 *
 * Sources: phpcs-rulesets/plugin-check.ruleset.xml, Generic.PHP.ForbiddenFunctions
 * (error, severity 7) and Squiz.PHP.DiscouragedFunctions.
 */
final class Forbidden_Functions_Rule extends Base_Rule
{
    /** Generic.PHP.ForbiddenFunctions, minus the execution family. */
    public const FORBIDDEN = [
        'move_uploaded_file',
        'str_rot13',
        '_cleanup_header_comment',
        '_get_plugin_data_markup_translate',
        '_transition_post_status',
        '_wp_post_revision_fields',
        'do_shortcode_tag',
        'get_post_type_labels',
        'wp_get_sidebars_widgets',
        'wp_get_widget_defaults',
    ];

    /** Squiz.PHP.DiscouragedFunctions: settings changed globally. */
    public const DISCOURAGED = [
        'set_time_limit',
        'ini_set',
        'ini_alter',
        'dl',
    ];

    /**
     * WordPress.WP.AlternativeFunctions, promoted to error by the review
     * ruleset. The file_system_operations and curl groups are enumerated in
     * full: a partial list reads as a clean run on the sites it omits, and a
     * cross-check against Plugin Check 2.0.0 on this plugin caught exactly
     * that (fclose, fread, readfile, rmdir and curl_setopt were all reported
     * by the reviewer's tool while this rule stayed silent).
     */
    public const ALTERNATIVES = [
        // file_system_operations group, verbatim, less the two names
        // plugin-check.ruleset.xml excludes (file_get_contents,
        // file_put_contents). copy(), fseek(), fgets(), feof() and ftell() are
        // deliberately absent: they are not in the sniff and adding them would
        // invent errors the reviewer's tooling does not raise.
        'chgrp' => 'WP_Filesystem',
        'chmod' => 'WP_Filesystem',
        'chown' => 'WP_Filesystem',
        'fclose' => 'WP_Filesystem',
        'fopen' => 'WP_Filesystem',
        'fputs' => 'WP_Filesystem',
        'fread' => 'WP_Filesystem',
        'fsockopen' => 'the WordPress HTTP API',
        'fwrite' => 'WP_Filesystem',
        'is_writable' => 'WP_Filesystem',
        'is_writeable' => 'WP_Filesystem',
        'mkdir' => 'wp_mkdir_p()',
        'pfsockopen' => 'the WordPress HTTP API',
        'readfile' => 'WP_Filesystem',
        'rmdir' => 'WP_Filesystem',
        'touch' => 'WP_Filesystem',
        // Single-function groups.
        'unlink' => 'wp_delete_file()',
        'rename' => 'WP_Filesystem::move()',
        'parse_url' => 'wp_parse_url()',
        'strip_tags' => 'wp_strip_all_tags()',
        // rand and rand_seeding groups.
        'rand' => 'wp_rand()',
        'mt_rand' => 'wp_rand()',
        'srand' => 'wp_rand()',
        'mt_srand' => 'wp_rand()',
    ];

    /**
     * The curl group is the wildcard "curl_*" with curl_version allowed, so it
     * is matched by prefix rather than by an enumeration that would go stale.
     */
    private const CURL_ALLOWED = ['curl_version'];

    public function id(): string
    {
        return 'PCP-FORBIDDEN-FUNCTIONS';
    }

    public function guideline(): string
    {
        return 'Plugin Check plugin-check.ruleset.xml lines 92-108';
    }

    public function title(): string
    {
        return 'Forbidden, discouraged and superseded functions';
    }

    public function explanation(): string
    {
        return 'Plugin Check runs Generic.PHP.ForbiddenFunctions as an error at severity 7, '
            . 'Squiz.PHP.DiscouragedFunctions over the settings-changing four, and '
            . 'WordPress.WP.AlternativeFunctions as an error with only json_encode, file_get_contents '
            . 'and file_put_contents excluded. Raw cURL and direct filesystem writes are the two that '
            . 'come up most often in tool plugins: use the HTTP API and WP_Filesystem.';
    }

    public function check(Rule_Context $context): array
    {
        $findings = [];
        foreach ($context->php_files() as $file) {
            foreach ($file->find_calls(self::FORBIDDEN, false) as $call) {
                if ($file->has_phpcs_ignore($call['line'], 'Generic.PHP.ForbiddenFunctions')) {
                    continue;
                }
                $findings[] = $this->finding(
                    $file,
                    $call['line'],
                    sprintf('%s() is on Plugin Check\'s forbidden-functions list (error, severity 7)', $call['name'])
                );
            }
            foreach ($file->find_calls(self::DISCOURAGED, false) as $call) {
                if ($this->has_discouraged_ignore($file, $call)) {
                    continue;
                }
                $findings[] = $this->finding(
                    $file,
                    $call['line'],
                    sprintf('%s() changes PHP settings globally; reviewers flag it', $call['name']),
                    Severity::LIKELY_REJECT
                );
            }
            foreach ($file->find_calls(array_keys(self::ALTERNATIVES), false) as $call) {
                if ($file->has_phpcs_ignore($call['line'], 'WordPress.WP.AlternativeFunctions')) {
                    continue;
                }
                $findings[] = $this->finding(
                    $file,
                    $call['line'],
                    sprintf('%s() is an error under WordPress.WP.AlternativeFunctions; use %s', $call['name'], self::ALTERNATIVES[$call['name']])
                );
            }
            foreach ($this->curl_calls($file) as $call) {
                if ($file->has_phpcs_ignore($call['line'], 'WordPress.WP.AlternativeFunctions')) {
                    continue;
                }
                $findings[] = $this->finding(
                    $file,
                    $call['line'],
                    sprintf(
                        '%s() is an error under WordPress.WP.AlternativeFunctions (curl group); use the WordPress HTTP API (wp_remote_get/wp_remote_post)',
                        $call['name']
                    )
                );
            }
        }
        return $findings;
    }

    /**
     * ini_set is also reported by WordPressCS's dedicated WordPress.PHP.IniSet
     * sniff, so an annotation naming either sniff is an accepted suppression
     * for that call; the other three settings functions only ever appear
     * under Squiz.PHP.DiscouragedFunctions.
     *
     * @param array{name:string,line:int} $call
     */
    private function has_discouraged_ignore(\WPMCP\Compliance\Source_File $file, array $call): bool
    {
        if ($file->has_phpcs_ignore($call['line'], 'Squiz.PHP.DiscouragedFunctions')) {
            return true;
        }
        return 'ini_set' === $call['name']
            && $file->has_phpcs_ignore($call['line'], 'WordPress.PHP.IniSet');
    }

    /**
     * @return array<int,array{name:string,line:int}>
     */
    private function curl_calls(\WPMCP\Compliance\Source_File $file): array
    {
        $calls = [];
        $tokens = $file->tokens();
        foreach ($tokens as $index => $token) {
            if (! is_array($token) || T_STRING !== $token[0]) {
                continue;
            }
            $name = strtolower($token[1]);
            if (! str_starts_with($name, 'curl_') || in_array($name, self::CURL_ALLOWED, true)) {
                continue;
            }
            if ('(' !== $this->next_significant_token($tokens, $index)) {
                continue;
            }
            $calls[] = ['name' => $name, 'line' => $token[2]];
        }
        return $calls;
    }

    /**
     * @param array<int,array|string> $tokens
     */
    private function next_significant_token(array $tokens, int $index): ?string
    {
        $count = count($tokens);
        for ($i = $index + 1; $i < $count; $i++) {
            $token = $tokens[$i];
            if (is_array($token)) {
                if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                return $token[1];
            }
            return $token;
        }
        return null;
    }
}
