<?php

namespace WPMCP\Tests\Free\Compliance;

use WPMCP\Compliance\Profile;
use WPMCP\Compliance\Rules\Code_Obfuscation_Rule;
use WPMCP\Compliance\Rules\Dangerous_Constructs_Rule;
use WPMCP\Compliance\Rules\Forbidden_Functions_Rule;
use WPMCP\Compliance\Rules\Php_Hygiene_Rule;
use WPMCP\Compliance\Rules\Suppress_Filters_Rule;
use WPMCP\Compliance\Rules\Wp_Load_Rule;
use WPMCP\Compliance\Rule_Context;
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

    public function test_qualified_calls_and_backticks_are_execution_sites(): void
    {
        // Leading-backslash globals are the house style of every vendor tree,
        // and the artifact-level run walks vendor. A matcher that only saw
        // bare T_STRING names passed `\proc_open(` straight through.
        $findings = $this->findings(new Dangerous_Constructs_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/runner.php' => "<?php\nnamespace Vendor;\nclass Runner {\n    public function run( \$c ) {\n        \\proc_open( \$c, [], \$p );\n        Shell\\system( \$c );\n        return `id`;\n    }\n}\n",
        ]);

        $this->assert_reports($findings, 'execution construct proc_open() must not ship');
        $this->assert_reports($findings, 'execution construct system() must not ship');
        $this->assert_reports($findings, 'execution construct the backtick operator (shell_exec) must not ship');
        $this->assertSame(['includes/runner.php:5', 'includes/runner.php:6', 'includes/runner.php:7'], $this->locations($findings));
    }

    public function test_declarations_members_and_attributes_are_not_execution_sites(): void
    {
        $findings = $this->findings(new Dangerous_Constructs_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/shapes.php' => "<?php\nclass Shapes {\n    public function &exec() {}\n    Function popen() {}\n    public function run( \$o ) {\n        \$o->system(); \$o?->passthru(); Shapes::exec(); return New Popen();\n    }\n}\n#[Assert()]\nclass Tagged {}\n",
        ]);

        $this->assert_clean($findings);
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

    /**
     * PHPCS, and therefore Plugin Check, honours a justified phpcs:ignore, so
     * a rule that mirrors a sniff has to honour it too. Both the
     * AlternativeFunctions enumeration and the curl prefix match go through
     * the same annotation check; without it the remediation this rule prints
     * ("use a scoped ignore with a justification") would not silence the rule
     * that printed it.
     */
    public function test_a_justified_ignore_suppresses_an_alternative_functions_site(): void
    {
        $body = "<?php\nclass Pipes {\n    public function run( \$handle, \$pipes ) {\n";
        $body .= "        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- proc_open() pipe handle; WP_Filesystem cannot close process pipes.\n";
        $body .= "        fclose( \$pipes[0] );\n";
        $body .= "        // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- deliberate SSRF defence; the WP HTTP API has no CURLOPT_RESOLVE equivalent.\n";
        $body .= "        curl_setopt( \$handle, CURLOPT_RESOLVE, [] );\n    }\n}\n";

        $findings = $this->findings(new Forbidden_Functions_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/pipes.php' => $body,
        ]);

        $this->assert_clean($findings);
    }

    /**
     * A trailing annotation on the call line itself is the other placement
     * PHPCS accepts, and the forbidden and discouraged groups carry their own
     * sniff codes rather than AlternativeFunctions.
     */
    public function test_a_justified_ignore_suppresses_the_forbidden_and_discouraged_groups(): void
    {
        $body = "<?php\nclass Legacy {\n    public function run() {\n";
        $body .= "        set_time_limit( 30 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- long-running CLI job, restored below.\n";
        $body .= "        // phpcs:ignore Generic.PHP.ForbiddenFunctions.Found -- reading the raw map is the only way to see unregistered sidebars.\n";
        $body .= "        return wp_get_sidebars_widgets();\n    }\n}\n";

        $findings = $this->findings(new Forbidden_Functions_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/legacy.php' => $body,
        ]);

        $this->assert_clean($findings);
    }

    /**
     * ini_set() is reported by WordPressCS under its own WordPress.PHP.IniSet
     * sniff as well, so an annotation naming that sniff has to count for the
     * discouraged group too, or the accepted remediation leaves the rule
     * shouting.
     */
    public function test_an_iniset_annotation_counts_for_the_discouraged_group(): void
    {
        $body = "<?php\nclass Transport {\n    public function run() {\n";
        $body .= "        // phpcs:ignore WordPress.PHP.IniSet.Risky -- request-scoped, restored in the finally block.\n";
        $body .= "        ini_set( 'zlib.output_compression', '0' );\n    }\n}\n";

        $findings = $this->findings(new Forbidden_Functions_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/transport.php' => $body,
        ]);

        $this->assert_clean($findings);
    }

    /**
     * The escape hatch must not become a silent mute button: WordPressCS
     * requires a justification after "--", an annotation for an unrelated
     * sniff must not suppress this one, and neither must one written for a
     * different function under the same sniff. PHPCS scopes an annotation to
     * its own line, or to the next line when it stands alone, so a trailing
     * annotation on the line above does not leak downward, an annotation on
     * a two-call line covers only the code it names, and a partial segment
     * ("WordPress.WP.Alt") is not a hierarchy prefix of anything.
     */
    public function test_a_bare_or_unrelated_ignore_does_not_suppress_the_site(): void
    {
        $body = "<?php\nclass Pipes {\n    public function run( \$pipes, \$handle, \$path ) {\n";
        $body .= "        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose\n";
        $body .= "        fclose( \$pipes[0] );\n";
        $body .= "        // phpcs:ignore WordPress.Security.EscapeOutput -- unrelated sniff.\n";
        $body .= "        curl_setopt( \$handle, CURLOPT_RESOLVE, [] );\n";
        $body .= "        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- written for an fclose, not for this call.\n";
        $body .= "        curl_exec( \$handle );\n";
        $body .= "        \$f = fopen( \$path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- read-only probe.\n";
        $body .= "        fclose( \$pipes[1] );\n";
        $body .= "        fwrite( \$f, 'x' ); unlink( \$path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- pipe write.\n";
        $body .= "        // phpcs:ignore WordPress.WP.Alt -- a partial segment, not a sniff.\n";
        $body .= "        return rand();\n    }\n}\n";

        $findings = $this->findings(new Forbidden_Functions_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/pipes.php' => $body,
        ]);

        $reported = array_map(
            static fn (string $message): string => substr($message, 0, (int) strpos($message, '(')),
            $this->messages($findings)
        );
        sort($reported);
        $this->assertSame(['curl_exec', 'curl_setopt', 'fclose', 'fclose', 'rand', 'unlink'], $reported);
        $locations = $this->locations($findings);
        sort($locations, SORT_NATURAL);
        $this->assertSame(
            ['includes/pipes.php:5', 'includes/pipes.php:7', 'includes/pipes.php:9', 'includes/pipes.php:11', 'includes/pipes.php:12', 'includes/pipes.php:14'],
            $locations
        );
    }

    /**
     * PHPCS matches an annotation by hierarchy, so naming the sniff (or the
     * standard) covers every code under it; that is the most an annotation
     * can be asked to suppress, so it is honoured the same way here.
     */
    public function test_a_sniff_level_ignore_covers_every_code_under_it(): void
    {
        $body = "<?php\nclass Pipes {\n    public function run( \$pipes, \$handle ) {\n";
        $body .= "        // phpcs:ignore WordPress.WP.AlternativeFunctions -- whole sniff.\n";
        $body .= "        fclose( \$pipes[0] );\n";
        $body .= "        // phpcs:ignore WordPress.WP -- whole category.\n";
        $body .= "        curl_setopt( \$handle, CURLOPT_RESOLVE, [] );\n";
        $body .= "        // phpcs:ignore WordPress.Security.EscapeOutput, WordPress.WP.AlternativeFunctions.unlink_unlink -- second code in a list.\n";
        $body .= "        unlink( '/tmp/x' );\n    }\n}\n";

        $findings = $this->findings(new Forbidden_Functions_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/pipes.php' => $body,
        ]);

        $this->assert_clean($findings);
    }

    /**
     * dl() and str_rot13() are also reported by WordPressCS under
     * WordPress.PHP.DiscouragedPHPFunctions, so an annotation naming that
     * code is one PHPCS accepts for the call and has to count here too. The
     * code is per function: the dl() code does not cover str_rot13().
     */
    public function test_a_discouraged_php_functions_annotation_counts_for_dl_and_str_rot13(): void
    {
        $body = "<?php\nclass Legacy {\n    public function run( \$text ) {\n";
        $body .= "        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_dl -- legacy host without the extension preloaded.\n";
        $body .= "        dl( 'example.so' );\n";
        $body .= "        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_str_rot13 -- puzzle answer, not obfuscation.\n";
        $body .= "        \$rot = str_rot13( \$text );\n";
        $body .= "        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_dl -- wrong code for this call.\n";
        $body .= "        return str_rot13( \$rot );\n    }\n}\n";

        $findings = $this->findings(new Forbidden_Functions_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/legacy.php' => $body,
        ]);

        $this->assertSame(['includes/legacy.php:9'], $this->locations($findings));
        $this->assert_reports($findings, 'str_rot13() is on Plugin Check\'s forbidden-functions list');
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

    /**
     * B-26 / issue #175: nothing in the repo could detect a reintroduced
     * suppress_filters, because the VIP sniff that Plugin Check runs is not in
     * our ruleset and vipwpcs is not a dependency. This rule is the guard.
     * Plugin Check reports the sniff at error level, so the finding is a
     * blocker in both shipped profiles and fails CI instead of accumulating.
     */
    public function test_suppress_filters_is_reported_at_every_unjustified_site(): void
    {
        $rule = new Suppress_Filters_Rule();
        $findings = $this->findings($rule, [
            'example-toolkit.php' => $this->main_file(),
            'src/Catalog.php' => "<?php\nclass Catalog {\n    public function all() {\n        return get_posts( [\n            'post_type' => 'thing',\n            'suppress_filters' => true,\n        ] );\n    }\n}\n",
        ]);

        $this->assert_reports($findings, 'suppress_filters');
        $this->assertCount(1, $findings);
        $this->assertSame(['src/Catalog.php:6'], $this->locations($findings));
        $this->assertSame(Severity::BLOCKER, Profile::wporg_free()->severity_for($rule));
        $this->assertSame(Severity::BLOCKER, Profile::distribution()->severity_for($rule));
    }

    /**
     * The annotation has to carry the code PHPCS emits. The VIP sniff builds
     * it as "<group>_<key>", so the full code ends in
     * "SuppressFilters_suppress_filters"; the owning sniff
     * "WordPressVIPMinimum.Performance.WPQueryParams" covers it too, as it
     * does in PHPCS.
     */
    public function test_a_justified_suppress_filters_site_is_accepted(): void
    {
        $findings = $this->findings(new Suppress_Filters_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'src/Guard.php' => "<?php\nclass Guard {\n    public function rules() {\n        return get_posts( [\n            'post_type' => 'rule',\n            // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters -- guardrail read; a third-party posts_* filter must not be able to remove block rules.\n            'suppress_filters' => true,\n        ] );\n    }\n}\n",
            'src/Sniff_Level.php' => "<?php\nclass Sniff_Level {\n    public function rules() {\n        return get_posts( [\n            // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams -- same read, annotated at the sniff.\n            'suppress_filters' => true,\n        ] );\n    }\n}\n",
        ]);

        $this->assert_clean($findings);
    }

    /**
     * "...WPQueryParams.SuppressFilters" is the sniff's group name, not a
     * message code, and PHPCS matches phpcs:ignore on whole dot-separated
     * segments, so Plugin Check ignores that annotation. The rule has to
     * agree with Plugin Check, or it blesses an annotation that fails the
     * directory scan. This is the mistake the first cut of #175 made.
     */
    public function test_an_annotation_for_the_group_name_rather_than_the_message_code_is_not_accepted(): void
    {
        $findings = $this->findings(new Suppress_Filters_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'src/Guard.php' => "<?php\nclass Guard {\n    public function rules() {\n        return get_posts( [\n            // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters -- looks right, suppresses nothing in PHPCS.\n            'suppress_filters' => true,\n        ] );\n    }\n}\n",
        ]);

        $this->assertSame(['src/Guard.php:6'], $this->locations($findings));
    }

    public function test_an_explicit_false_and_a_bare_ignore_are_treated_differently(): void
    {
        $findings = $this->findings(new Suppress_Filters_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'src/Opted_In.php' => "<?php\nclass Opted_In {\n    public function all() {\n        return get_posts( [ 'suppress_filters' => false ] );\n    }\n}\n",
            'src/Muted.php' => "<?php\nclass Muted {\n    public function all() {\n        // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters\n        return get_posts( [ 'suppress_filters' => true ] );\n    }\n}\n",
        ]);

        $this->assertCount(1, $findings, 'false opts filters back in and is fine; a bare ignore with no reason is not');
        $this->assertSame('src/Muted.php', $findings[0]->file());
    }

    /**
     * Token-based like the other code rules: a comment or a string that
     * quotes the argument is not a site. Call sites in this plugin carry long
     * explanatory comments, and several of them quote the literal.
     */
    public function test_suppress_filters_inside_strings_and_comments_does_not_false_positive(): void
    {
        $findings = $this->findings(new Suppress_Filters_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'src/Notes.php' => "<?php\n/**\n * `'suppress_filters' => true` turns off the posts_* chain.\n */\nclass Notes {\n    const HINT = \"was 'suppress_filters' => true before #175\";\n\n    public function all() {\n        // was 'suppress_filters' => true before; get_posts() defaults it.\n        return get_posts( [ 'post_type' => 'note', 'suppress_filters' => false ] );\n    }\n}\n",
        ]);

        $this->assert_clean($findings);
    }

    /**
     * The shipped tree is the real subject: this is what would have caught
     * B-26 before Plugin Check did. The rule itself runs over the checkout,
     * with the engine's default excludes, so the guard is the rule and not a
     * second implementation of it.
     */
    public function test_the_shipped_source_tree_has_no_unjustified_suppress_filters(): void
    {
        $context = Rule_Context::for_path(dirname(__DIR__, 3), Profile::wporg_free());

        $this->assert_clean((new Suppress_Filters_Rule())->check($context));
    }
}
