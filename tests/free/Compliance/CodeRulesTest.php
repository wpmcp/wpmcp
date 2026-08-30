<?php

namespace WPMCP\Tests\Free\Compliance;

use WPMCP\Compliance\Profile;
use WPMCP\Compliance\Rules\Code_Obfuscation_Rule;
use WPMCP\Compliance\Rules\Dangerous_Constructs_Rule;
use WPMCP\Compliance\Rules\Forbidden_Functions_Rule;
use WPMCP\Compliance\Rules\Php_Hygiene_Rule;
use WPMCP\Compliance\Rules\Suppress_Filters_Rule;
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

    /**
     * B-26 / issue #175: nothing in the repo could detect a reintroduced
     * suppress_filters, because the VIP sniff that Plugin Check runs is not in
     * our ruleset and vipwpcs is not a dependency. This rule is the guard, so
     * the finding fails CI instead of accumulating.
     */
    public function test_suppress_filters_is_reported_at_every_unjustified_site(): void
    {
        $findings = $this->findings(new Suppress_Filters_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'src/Catalog.php' => "<?php\nclass Catalog {\n    public function all() {\n        return get_posts( [\n            'post_type' => 'thing',\n            'suppress_filters' => true,\n        ] );\n    }\n}\n",
        ]);

        $this->assert_reports($findings, 'suppress_filters');
        $this->assertCount(1, $findings);
    }

    public function test_a_justified_suppress_filters_site_is_accepted(): void
    {
        $findings = $this->findings(new Suppress_Filters_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'src/Guard.php' => "<?php\nclass Guard {\n    public function rules() {\n        return get_posts( [\n            'post_type' => 'rule',\n            // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters -- guardrail read; a third-party posts_* filter must not be able to remove block rules.\n            'suppress_filters' => true,\n        ] );\n    }\n}\n",
        ]);

        $this->assert_clean($findings);
    }

    public function test_an_explicit_false_and_a_bare_ignore_are_treated_differently(): void
    {
        $findings = $this->findings(new Suppress_Filters_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'src/Opted_In.php' => "<?php\nclass Opted_In {\n    public function all() {\n        return get_posts( [ 'suppress_filters' => false ] );\n    }\n}\n",
            'src/Muted.php' => "<?php\nclass Muted {\n    public function all() {\n        // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters\n        return get_posts( [ 'suppress_filters' => true ] );\n    }\n}\n",
        ]);

        $this->assertCount(1, $findings, 'false opts filters back in and is fine; a bare ignore with no reason is not');
        $this->assertSame('src/Muted.php', $findings[0]->file());
    }

    /**
     * The shipped tree is the real subject: this is what would have caught
     * B-26 before Plugin Check did.
     */
    public function test_the_shipped_source_tree_has_no_unjustified_suppress_filters(): void
    {
        $root = dirname(__DIR__, 3);
        $hits = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src'));
        foreach ($iterator as $file) {
            if (! $file->isFile() || 'php' !== $file->getExtension()) {
                continue;
            }
            $lines = explode("\n", (string) file_get_contents($file->getPathname()));
            foreach ($lines as $index => $line) {
                if (! preg_match("/'suppress_filters'\\s*=>\\s*true/", $line)) {
                    continue;
                }
                $context = ($lines[$index - 1] ?? '') . "\n" . $line;
                if (preg_match('/phpcs:ignore\\s+[^-\\n]*SuppressFilters[^-\\n]*--\\s*\\S/', $context)) {
                    continue;
                }
                $hits[] = str_replace($root . '/', '', $file->getPathname()) . ':' . ($index + 1);
            }
        }

        $this->assertSame([], $hits, 'unjustified suppress_filters => true in shipped source');
    }
}
