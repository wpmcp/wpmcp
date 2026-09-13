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
 *
 * Every group honours a justified phpcs:ignore for its own message code,
 * because PHPCS and Plugin Check do. Some of these calls have no WordPress
 * equivalent at all (a proc_open() pipe handle cannot be closed through
 * WP_Filesystem, and CURLOPT_RESOLVE has no HTTP API counterpart), so the
 * annotation is the remediation this rule recommends; it would be incoherent
 * to keep reporting a site that has taken it. The match is on the exact code
 * PHPCS would emit for that call (or a hierarchy prefix of it), so an ignore
 * written for fopen() does not also cover the fclose() beside it.
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
     * ruleset, as function => [alternative, message code]. The
     * file_system_operations and curl groups are enumerated in full: a
     * partial list reads as a clean run on the sites it omits, and a
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
        'chgrp' => ['WP_Filesystem', 'file_system_operations_chgrp'],
        'chmod' => ['WP_Filesystem', 'file_system_operations_chmod'],
        'chown' => ['WP_Filesystem', 'file_system_operations_chown'],
        'fclose' => ['WP_Filesystem', 'file_system_operations_fclose'],
        'fopen' => ['WP_Filesystem', 'file_system_operations_fopen'],
        'fputs' => ['WP_Filesystem', 'file_system_operations_fputs'],
        'fread' => ['WP_Filesystem', 'file_system_operations_fread'],
        'fsockopen' => ['the WordPress HTTP API', 'file_system_operations_fsockopen'],
        'fwrite' => ['WP_Filesystem', 'file_system_operations_fwrite'],
        'is_writable' => ['WP_Filesystem', 'file_system_operations_is_writable'],
        'is_writeable' => ['WP_Filesystem', 'file_system_operations_is_writeable'],
        'mkdir' => ['wp_mkdir_p()', 'file_system_operations_mkdir'],
        'pfsockopen' => ['the WordPress HTTP API', 'file_system_operations_pfsockopen'],
        'readfile' => ['WP_Filesystem', 'file_system_operations_readfile'],
        'rmdir' => ['WP_Filesystem', 'file_system_operations_rmdir'],
        'touch' => ['WP_Filesystem', 'file_system_operations_touch'],
        // Single-function groups.
        'unlink' => ['wp_delete_file()', 'unlink_unlink'],
        'rename' => ['WP_Filesystem::move()', 'rename_rename'],
        'parse_url' => ['wp_parse_url()', 'parse_url_parse_url'],
        'strip_tags' => ['wp_strip_all_tags()', 'strip_tags_strip_tags'],
        // rand and rand_seeding groups.
        'rand' => ['wp_rand()', 'rand_rand'],
        'mt_rand' => ['wp_rand()', 'rand_mt_rand'],
        'srand' => ['wp_rand()', 'rand_seeding_srand'],
        'mt_srand' => ['wp_rand()', 'rand_seeding_mt_srand'],
    ];

    /**
     * Sniff codes, one per group, as PHPCS emits them. AlternativeFunctions
     * codes are group_function, the way AbstractFunctionRestrictionsSniff
     * builds them; the curl group is 'curl_' . $name for the same reason.
     */
    private const FORBIDDEN_SNIFF = 'Generic.PHP.ForbiddenFunctions';
    private const DISCOURAGED_SNIFF = 'Squiz.PHP.DiscouragedFunctions';
    private const ALTERNATIVES_SNIFF = 'WordPress.WP.AlternativeFunctions';

    /**
     * Calls a second WordPressCS sniff reports as well. PHPCS accepts an
     * annotation naming either code for the call, so this rule does too:
     * ini_set() has the dedicated WordPress.PHP.IniSet sniff (every code
     * under it is an ini_set() call, so the sniff itself is enough), and
     * dl() and str_rot13() sit in WordPress.PHP.DiscouragedPHPFunctions
     * under their group codes. set_time_limit() and ini_alter() appear only
     * under Squiz.PHP.DiscouragedFunctions.
     */
    private const ALSO_REPORTED_AS = [
        'ini_set' => 'WordPress.PHP.IniSet',
        'dl' => 'WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_dl',
        'str_rot13' => 'WordPress.PHP.DiscouragedPHPFunctions.obfuscation_str_rot13',
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
                if ($this->is_suppressed($file, $call, self::FORBIDDEN_SNIFF)) {
                    continue;
                }
                $findings[] = $this->finding(
                    $file,
                    $call['line'],
                    sprintf('%s() is on Plugin Check\'s forbidden-functions list (error, severity 7)', $call['name'])
                );
            }
            foreach ($file->find_calls(self::DISCOURAGED, false) as $call) {
                if ($this->is_suppressed($file, $call, self::DISCOURAGED_SNIFF)) {
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
                [$alternative, $code] = self::ALTERNATIVES[$call['name']];
                if ($this->is_suppressed($file, $call, self::ALTERNATIVES_SNIFF . '.' . $code)) {
                    continue;
                }
                $findings[] = $this->finding(
                    $file,
                    $call['line'],
                    sprintf('%s() is an error under WordPress.WP.AlternativeFunctions; use %s', $call['name'], $alternative)
                );
            }
            foreach ($this->curl_calls($file) as $call) {
                if ($this->is_suppressed($file, $call, self::ALTERNATIVES_SNIFF . '.curl_' . $call['name'])) {
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
     * True when the call carries a justified phpcs:ignore for the code PHPCS
     * would emit for it, or for a second sniff that also reports it.
     *
     * @param array{name:string,line:int} $call
     */
    private function is_suppressed(\WPMCP\Compliance\Source_File $file, array $call, string $code): bool
    {
        if ($file->has_phpcs_ignore($call['line'], $code)) {
            return true;
        }
        $also = self::ALSO_REPORTED_AS[$call['name']] ?? null;
        return null !== $also && $file->has_phpcs_ignore($call['line'], $also);
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
