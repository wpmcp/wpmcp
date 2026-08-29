<?php

namespace WPMCP\Tests\Free\Compliance;

use WPMCP\Compliance\Profile;
use WPMCP\Compliance\Rules\Code_Obfuscation_Rule;
use WPMCP\Compliance\Rules\Dangerous_Constructs_Rule;
use WPMCP\Compliance\Rules\Forbidden_Functions_Rule;
use WPMCP\Compliance\Rules\Php_Hygiene_Rule;
use WPMCP\Compliance\Rules\Wp_Load_Rule;
use WPMCP\Compliance\Severity;

/**
 * Group C of the rulebook: readability and dangerous constructs.
 */
class CodeRulesTest extends Compliance_Test_Case
{
    public function test_execution_constructs_are_blockers_under_the_wporg_profile(): void
    {
        $findings = $this->findings(new Dangerous_Constructs_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/runner.php' => "<?php\nclass Runner {\n    public function run( \$code ) {\n        eval( \$code );\n        proc_open( ['ls'], [], \$pipes );\n    }\n}\n",
        ]);

        $this->assert_reports($findings, 'execution construct eval() must not ship');
        $this->assert_reports($findings, 'execution construct proc_open() must not ship');
        foreach ($findings as $finding) {
            $this->assertNull($finding->severity_override(), 'wporg-free must not soften an execution site');
        }
    }

    public function test_the_distribution_profile_allowlists_the_two_audited_execution_sites(): void
    {
        $files = [
            'example-toolkit.php' => $this->main_file(),
            'src/Tools/Code/Php_Snippet_Runner.php' => "<?php\nclass Php_Snippet_Runner {\n    private static function evaluate( \$code ) {\n        return eval( \$code );\n    }\n}\n",
            'src/Tools/Cli/Wp_Cli_Executor.php' => "<?php\nclass Wp_Cli_Executor {\n    public function run( array \$argv ) {\n        return proc_open( \$argv, [], \$pipes );\n    }\n}\n",
        ];

        $findings = $this->findings(new Dangerous_Constructs_Rule(), $files, Profile::distribution());

        $this->assertCount(2, $findings);
        foreach ($findings as $finding) {
            $this->assertSame(Severity::BEST_PRACTICE, $finding->severity_override());
            $this->assertStringContainsString('must never reach a WordPress.org build', $finding->message());
        }
    }

    public function test_the_allowlist_does_not_cover_other_files(): void
    {
        $findings = $this->findings(new Dangerous_Constructs_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'src/Tools/Code/Other_Runner.php' => "<?php\nclass Other_Runner {\n    public function run( \$code ) {\n        return eval( \$code );\n    }\n}\n",
        ], Profile::distribution());

        $this->assertCount(1, $findings);
        $this->assertNull($findings[0]->severity_override());
    }

    public function test_execution_names_inside_strings_and_comments_do_not_false_positive(): void
    {
        $findings = $this->findings(new Dangerous_Constructs_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/scanner.php' => "<?php\nclass Scanner {\n    // Detects eval( base64_decode( ... ) ) in uploaded files.\n    const PATTERNS = [ 'eval\\\\s*\\\\(', 'shell_exec', 'proc_open' ];\n\n    public function scan( \$body ) {\n        return preg_match( '/eval\\\\s*\\\\(/', \$body );\n    }\n}\n",
        ]);

        $this->assert_clean($findings);
    }

    public function test_forbidden_and_superseded_functions_are_reported_with_their_severities(): void
    {
        $findings = $this->findings(new Forbidden_Functions_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/legacy.php' => "<?php\nclass Legacy {\n    public function run( \$path ) {\n        set_time_limit( 30 );\n        \$parts = parse_url( 'https://example.test/a' );\n        return wp_get_sidebars_widgets();\n    }\n}\n",
        ]);

        $this->assert_reports($findings, 'wp_get_sidebars_widgets() is on Plugin Check');
        $this->assert_reports($findings, 'parse_url() is an error');
        $this->assert_reports($findings, 'set_time_limit() changes PHP settings globally');

        $severities = [];
        foreach ($findings as $finding) {
            $severities[$finding->message()] = $finding->severity_override();
        }
        $this->assertContains(Severity::LIKELY_REJECT, $severities);
    }

    public function test_wp_parse_url_is_not_mistaken_for_parse_url(): void
    {
        $findings = $this->findings(new Forbidden_Functions_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/modern.php' => "<?php\nclass Modern {\n    public function run() {\n        return wp_parse_url( 'https://example.test/a' );\n    }\n}\n",
        ]);

        $this->assert_clean($findings);
    }

    /**
     * The file_system_operations and curl groups have to be enumerated in full.
     * Cross-checking against Plugin Check 2.0.0 caught five names this rule was
     * silent on, and a partial list reads as a clean run on the sites it omits.
     */
    public function test_the_whole_file_system_operations_and_curl_groups_are_reported(): void
    {
        $body = "<?php\nclass Files {\n    public function run( \$path, \$dir ) {\n";
        $body .= "        \$h = fopen( \$path, 'rb' );\n        \$d = fread( \$h, 10 );\n        fclose( \$h );\n";
        $body .= "        readfile( \$path );\n        rmdir( \$dir );\n        mkdir( \$dir );\n        touch( \$path );\n";
        $body .= "        \$c = curl_init();\n        curl_setopt( \$c, CURLOPT_URL, 'https://example.test' );\n";
        $body .= "        return curl_exec( \$c );\n    }\n}\n";

        $findings = $this->findings(new Forbidden_Functions_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/files.php' => $body,
        ]);

        foreach (['fopen', 'fread', 'fclose', 'readfile', 'rmdir', 'mkdir', 'touch'] as $name) {
            $this->assert_reports($findings, $name . '() is an error under WordPress.WP.AlternativeFunctions');
        }
        foreach (['curl_init', 'curl_setopt', 'curl_exec'] as $name) {
            $this->assert_reports($findings, $name . '() is an error under WordPress.WP.AlternativeFunctions (curl group)');
        }
    }

    /**
     * PHPCS honours a justified phpcs:ignore and so does Plugin Check, which
     * runs phpcs underneath. A rule that mirrors those sniffs but ignores the
     * annotation over-reports relative to the reviewer's own tooling and
     * contradicts the remediation several findings recommend. The annotation
     * must name the mirrored sniff and carry a justification after "--".
     */
    public function test_a_justified_phpcs_ignore_for_the_mirrored_sniff_suppresses_the_finding(): void
    {
        $body = "<?php\nclass Guard {\n    public function run( \$h, \$c ) {\n";
        $body .= "        // phpcs:ignore Squiz.PHP.DiscouragedFunctions -- a printed notice corrupts JSON-RPC framing; logging untouched.\n";
        $body .= "        ini_set( 'display_errors', '0' );\n";
        $body .= "        fclose( \$h ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- proc_open pipe; WP_Filesystem cannot close process pipes.\n";
        $body .= "        // phpcs:ignore WordPress.WP.AlternativeFunctions -- DNS pinning needs CURLOPT_RESOLVE; the HTTP API has no equivalent.\n";
        $body .= "        curl_setopt( \$c, CURLOPT_RESOLVE, [] );\n";
        $body .= "        // phpcs:ignore Generic.PHP.ForbiddenFunctions -- reading, not writing, the registry.\n";
        $body .= "        return wp_get_sidebars_widgets();\n    }\n}\n";

        $findings = $this->findings(new Forbidden_Functions_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/guard.php' => $body,
        ]);

        $this->assert_clean($findings);
    }

    /**
     * ini_set is reported by WordPressCS under its dedicated
     * WordPress.PHP.IniSet sniff as well, so an annotation naming that sniff
     * is an accepted suppression for the same call.
     */
    public function test_an_ini_set_annotation_naming_the_wordpresscs_sniff_also_counts(): void
    {
        $body = "<?php\nclass Guard {\n    public function run() {\n";
        $body .= "        // phpcs:ignore WordPress.PHP.IniSet.display_errors_Disallowed -- deliberate: errors still log.\n";
        $body .= "        ini_set( 'display_errors', '0' );\n    }\n}\n";

        $findings = $this->findings(new Forbidden_Functions_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/guard.php' => $body,
        ]);

        $this->assert_clean($findings);
    }

    /**
     * A bare annotation with no justification, or one naming an unrelated
     * sniff, suppresses nothing: the annotation must not become a silent
     * mute button.
     */
    public function test_bare_or_unrelated_annotations_do_not_suppress(): void
    {
        $body = "<?php\nclass Guard {\n    public function run( \$h ) {\n";
        $body .= "        ini_set( 'display_errors', '0' ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions\n";
        $body .= "        fclose( \$h ); // phpcs:ignore WordPress.Security.EscapeOutput -- wrong sniff entirely.\n";
        $body .= "    }\n}\n";

        $findings = $this->findings(new Forbidden_Functions_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/guard.php' => $body,
        ]);

        $this->assert_reports($findings, 'ini_set() changes PHP settings globally');
        $this->assert_reports($findings, 'fclose() is an error under WordPress.WP.AlternativeFunctions');
    }

    /**
     * The mirror must not overshoot either. copy(), fseek() and curl_version()
     * are not in the sniff, and reporting them would invent errors that the
     * reviewer's own tooling does not raise. Verified against Plugin Check 2.0.0.
     */
    public function test_names_outside_the_sniff_are_not_reported(): void
    {
        $body = "<?php\nclass Files {\n    public function run( \$a, \$b, \$h, \$c ) {\n";
        $body .= "        copy( \$a, \$b );\n        fseek( \$h, 0 );\n        ftell( \$h );\n        feof( \$h );\n";
        $body .= "        return curl_version();\n    }\n}\n";

        $findings = $this->findings(new Forbidden_Functions_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/files.php' => $body,
        ]);

        $this->assert_clean($findings);
    }

    public function test_php_hygiene_reports_heredoc_goto_and_short_tags(): void
    {
        $heredoc = "<?php\nfunction example_markup( \$name ) {\n    \$out = <<<HTML\n<p>Hello</p>\nHTML;\n    goto finish;\n    finish:\n    return \$out;\n}\n";

        $findings = $this->findings(new Php_Hygiene_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/markup.php' => $heredoc,
            'includes/legacy-tag.php' => "<?\nclass Legacy_Tag {}\n",
        ]);

        $this->assert_reports($findings, 'HEREDOC is prohibited');
        $this->assert_reports($findings, 'goto');
        $this->assert_reports($findings, 'short open tag');
    }

    /**
     * PluginCheck's HeredocSniff registers T_START_HEREDOC only, and PHPCS
     * tokenises a nowdoc opener as its own T_START_NOWDOC, so <<<'ID' is not a
     * Plugin Check error. PHP's native tokeniser calls both T_START_HEREDOC,
     * which is exactly how this rule used to report a nowdoc that the
     * reviewer's own tool passes. Verified against Plugin Check 2.0.0.
     */
    public function test_php_hygiene_does_not_report_nowdoc(): void
    {
        $nowdoc = "<?php\nclass Nowdoc_Holder {\n    public function script(): string\n    {\n        return <<<'JS'\nconst x = 1;\nJS;\n    }\n}\n";

        $findings = $this->findings(new Php_Hygiene_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/nowdoc.php' => $nowdoc,
        ]);

        $this->assert_clean($findings);
    }

    public function test_php_hygiene_still_reports_a_double_quoted_heredoc(): void
    {
        $heredoc = "<?php\nclass Heredoc_Holder {\n    public function markup(string \$n): string\n    {\n        return <<<\"HTML\"\n<p>\$n</p>\nHTML;\n    }\n}\n";

        $findings = $this->findings(new Php_Hygiene_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/heredoc.php' => $heredoc,
        ]);

        $this->assert_reports($findings, 'HEREDOC is prohibited');
    }

    public function test_php_hygiene_ignores_backticks_inside_documentation(): void
    {
        $findings = $this->findings(new Php_Hygiene_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/documented.php' => "<?php\n/**\n * Calls `wp_remote_get()` and returns the `body` key.\n */\nclass Documented {}\n",
        ]);

        $this->assert_clean($findings);
    }

    public function test_php_hygiene_reports_a_real_backtick_operator(): void
    {
        $findings = $this->findings(new Php_Hygiene_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/shell.php' => "<?php\nfunction example_disk() {\n    return `df -h`;\n}\n",
        ]);

        $this->assert_reports($findings, 'backtick operator');
    }

    public function test_obfuscation_rule_reports_encoded_files(): void
    {
        $findings = $this->findings(new Code_Obfuscation_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/encoded.php' => "<?php\n// This file was encoded by a commercial encoder.\nclass Encoded {}\n",
            'js/app.min.js' => "!function(){var a=1;}();\n",
        ]);

        $this->assert_reports($findings, 'Zend Guard encoded file');
        $this->assert_reports($findings, 'minified asset ships without its unminified source');
    }

    public function test_obfuscation_rule_accepts_a_minified_asset_beside_its_source(): void
    {
        $findings = $this->findings(new Code_Obfuscation_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'js/app.js' => "function example() {\n    return 1;\n}\n",
            'js/app.min.js' => "function example(){return 1}\n",
        ], null);

        $this->assert_clean($findings);
    }

    public function test_wp_load_bootstrap_is_reported(): void
    {
        $findings = $this->findings(new Wp_Load_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'endpoint.php' => "<?php\nrequire_once dirname( __FILE__, 4 ) . '/wp-load.php';\necho 'ok';\n",
        ]);

        $this->assert_reports($findings, 'wp-load.php is included directly');
    }

    public function test_mentioning_wp_load_in_a_string_is_not_a_finding(): void
    {
        $findings = $this->findings(new Wp_Load_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/docs.php' => "<?php\nclass Docs {\n    const NOTE = 'never call wp-load.php directly';\n}\n",
        ]);

        $this->assert_clean($findings);
    }
}
