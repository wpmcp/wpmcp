<?php

namespace WPMCP\Tests\Free\Compliance;

use WPMCP\Compliance\Plugin_Source;
use WPMCP\Compliance\Profile;
use WPMCP\Compliance\Rules\Admin_Nag_Rule;
use WPMCP\Compliance\Rules\Code_Obfuscation_Rule;
use WPMCP\Compliance\Rules\File_Hygiene_Rule;
use WPMCP\Compliance\Rules\Gpl_License_Rule;
use WPMCP\Compliance\Rules\I18n_Rule;
use WPMCP\Compliance\Rules\Readme_Rule;
use WPMCP\Compliance\Rules\Trademark_Rule;
use WPMCP\Compliance\Rules\Updater_Rule;

/**
 * The branches the happy-path tests do not reach: placeholder readme text,
 * packaging oddities, header edge cases.
 */
class EdgeCasesTest extends Compliance_Test_Case
{
    public function test_readme_placeholders_and_bad_contributors_are_reported(): void
    {
        $findings = $this->findings(new Readme_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'readme.txt' => $this->readme([
                'Tags' => 'tag1, example',
                'Contributors' => 'someone@example.test',
                'short' => 'Here is a short description of the plugin.',
            ]),
        ]);

        $this->assert_reports($findings, 'placeholder tag "tag1"');
        $this->assert_reports($findings, 'placeholder short description');
        $this->assert_reports($findings, 'is not a WordPress.org username');
    }

    public function test_readme_markup_size_and_license_mismatch_are_reported(): void
    {
        $long_changelog = str_repeat("* A changelog entry that says something useful.\n", 260);
        $findings = $this->findings(new Readme_Rule(), [
            'example-toolkit.php' => $this->main_file(['License' => 'GPL-3.0-or-later']),
            'readme.txt' => $this->readme([
                'License' => 'GPLv2 or later',
                'short' => 'A plugin with <strong>markup</strong> in the short description.',
                'extra_sections' => $long_changelog,
            ]),
        ]);

        $this->assert_reports($findings, 'short description contains markup');
        $this->assert_reports($findings, 'license differs between readme');
        $this->assert_reports($findings, 'split the changelog out');
    }

    public function test_i18n_reports_load_plugin_textdomain_and_header_gaps(): void
    {
        $findings = $this->findings(new I18n_Rule(), [
            'example-toolkit.php' => $this->main_file(['Text Domain' => '']),
            'languages/example-toolkit.pot' => "# translation template\n",
            'includes/boot.php' => "<?php\nfunction example_boot() {\n    load_plugin_textdomain( 'example-toolkit' );\n}\n",
        ]);

        $this->assert_reports($findings, 'load_plugin_textdomain() is unnecessary');
        $this->assert_reports($findings, 'no Text Domain header');
        $this->assert_reports($findings, 'no Domain Path header');
    }

    public function test_updater_accepts_the_canonical_update_uri_and_warns_on_soft_markers(): void
    {
        $findings = $this->findings(new Updater_Rule(), [
            'example-toolkit.php' => $this->main_file(['Update URI' => 'https://wordpress.org/plugins/example-toolkit/']),
            'includes/updates.php' => "<?php\nadd_filter( 'auto_update_plugin', '__return_false' );\n",
            'includes/plugin-update-checker.php' => "<?php\nclass Puc {}\n",
        ]);

        $messages = implode("\n", $this->messages($findings));
        $this->assertStringNotContainsString('Update URI', $messages);
        $this->assertStringContainsString('auto_update_plugin', $messages);
        $this->assertStringContainsString('plugin-update-checker.php must not ship', $messages);
    }

    public function test_a_vendored_licensing_sdk_updater_is_found_even_though_vendor_is_not_analysed(): void
    {
        $files = [
            'example-toolkit.php' => $this->main_file(),
            'vendor/freemius/wordpress-sdk/start.php' => "<?php\nclass Freemius {\n    public function updates() {\n        add_filter( 'site_transient_update_plugins', [ \$this, 'inject' ] );\n    }\n}\n",
        ];

        $updater = $this->findings(new Updater_Rule(), $files);
        $this->assert_reports($updater, 'bundled licensing SDK contains an updater');
        $this->assertSame('vendor/freemius', $updater[0]->file());

        $licensing = $this->findings(new \WPMCP\Compliance\Rules\Licensing_Sdk_Rule(), $files);
        $this->assert_reports($licensing, 'the Freemius SDK is vendored into this tree');
    }

    /**
     * The Freemius loop is a named-SDK probe. Plugin_Updater_Check has no
     * vendor carve-out at all, so an extracted zip has to be scanned whole:
     * any dependency's updater is an error, not only the licensing SDK's.
     */
    public function test_a_non_freemius_vendor_updater_is_an_error_in_an_artifact_scan(): void
    {
        $findings = $this->findings(new Updater_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'vendor/acme/sdk/updates.php' => "<?php\nadd_filter( 'site_transient_update_plugins', 'acme_inject' );\n",
        ]);

        $this->assert_reports($findings, 'site_transient_update_plugins');
        $this->assert_reports($findings, 'no vendor carve-out');
        $this->assertSame('vendor/acme/sdk/updates.php', $findings[0]->file());
    }

    public function test_a_vendored_plugin_update_checker_file_is_reported_in_an_artifact_scan(): void
    {
        $findings = $this->findings(new Updater_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'vendor/acme/puc/plugin-update-checker.php' => "<?php\nclass Checker {}\n",
        ]);

        $this->assert_reports($findings, 'plugin-update-checker.php must not ship');
    }

    /**
     * Plugin_Updater_Check compiles its patterns with /i, and so does this
     * rule, so a case variant is still an error at wordpress.org.
     */
    public function test_a_vendored_updater_marker_is_matched_case_insensitively(): void
    {
        $findings = $this->findings(new Updater_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'vendor/acme/sdk/updates.php' => "<?php\n// SITE_TRANSIENT_UPDATE_PLUGINS is still the same marker.\n",
        ]);

        $this->assert_reports($findings, 'SITE_TRANSIENT_UPDATE_PLUGINS');
    }

    /**
     * A development checkout's vendor tree is full of dependencies that never
     * ship, so the whole-vendor scan is artifact-only. The named-SDK probe
     * still runs, because a vendored licensing SDK is a finding either way.
     */
    public function test_the_whole_vendor_scan_is_skipped_for_a_development_checkout(): void
    {
        $findings = $this->findings(
            new Updater_Rule(),
            [
                'example-toolkit.php' => $this->main_file(),
                'vendor/acme/sdk/updates.php' => "<?php\nadd_filter( 'site_transient_update_plugins', 'acme_inject' );\n",
            ],
            Profile::wporg_free()->with_options(['artifact' => false])
        );

        $this->assert_clean($findings);
    }

    public function test_files_under_only_walks_the_directory_it_is_given(): void
    {
        $root = $this->make_plugin([
            'example-toolkit.php' => $this->main_file(),
            'vendor/one/a.php' => "<?php\n",
            'vendor/one/nested/b.php' => "<?php\n",
            'vendor/one/readme.md' => "notes\n",
            'vendor/two/c.php' => "<?php\n",
        ]);
        $source = new Plugin_Source($root);

        $paths = array_map(
            static fn (\WPMCP\Compliance\Source_File $file) => $file->relative_path(),
            $source->files_under('vendor/one')
        );

        $this->assertSame(['vendor/one/a.php', 'vendor/one/nested/b.php'], $paths);
        $this->assertSame([], $source->files_under('vendor/missing'));

        $analysed = array_map(
            static fn (\WPMCP\Compliance\Source_File $file) => $file->relative_path(),
            $source->source_files()
        );
        $this->assertSame(['example-toolkit.php'], $analysed, 'vendor must stay out of the analysed set');
    }

    public function test_gpl_rule_reports_a_readme_without_a_license_uri(): void
    {
        $findings = $this->findings(new Gpl_License_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'readme.txt' => $this->readme(['License URI' => '']),
            'LICENSE' => "GPL\n",
        ]);

        $this->assert_reports($findings, 'readme has no License URI');
    }

    public function test_file_hygiene_reports_binaries_vcs_and_ai_directories(): void
    {
        $root = $this->make_plugin([
            'example-toolkit.php' => $this->main_file(),
            'bin/tool.exe' => "binary\n",
            'notes file.php' => "<?php\n",
            '.git/config' => "[core]\n",
            '.claude/settings.json' => "{}\n",
        ]);
        $rule = new File_Hygiene_Rule();
        $context = \WPMCP\Compliance\Rule_Context::for_path($root, Profile::wporg_free());

        $messages = implode("\n", $this->messages($rule->check($context)));

        $this->assertStringContainsString('application file ".exe"', $messages);
        $this->assertStringContainsString('must not contain spaces or special characters', $messages);
        $this->assertStringContainsString('version control checkout ".git"', $messages);
        $this->assertStringContainsString('AI instruction directory ".claude"', $messages);
    }

    public function test_admin_nag_reports_a_dashboard_widget(): void
    {
        $findings = $this->findings(new Admin_Nag_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/widget.php' => "<?php\nfunction example_widget() {\n    wp_add_dashboard_widget( 'example', 'Example', 'example_render' );\n}\n",
        ]);

        $this->assert_reports($findings, 'embedded dashboard widget');
    }

    public function test_obfuscation_reports_minified_php_and_ioncube(): void
    {
        $findings = $this->findings(new Code_Obfuscation_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/packed.php' => '<?php ' . str_repeat('$a=1;$b=2;$c=3;', 120) . "\n",
            'includes/encoded.php' => "<?php\n// ionCube loader required.\nclass Encoded {}\n",
        ]);

        $this->assert_reports($findings, 'appears to be minified');
        $this->assert_reports($findings, 'ionCube encoded file');
    }

    public function test_trademark_accepts_a_name_that_puts_the_mark_last(): void
    {
        $findings = $this->findings(new Trademark_Rule(), [
            'example-toolkit-for-woocommerce.php' => $this->main_file([
                'Plugin Name' => 'Example Toolkit for WooCommerce',
                'Text Domain' => 'example-toolkit-for-woocommerce',
            ]),
        ]);

        $this->assert_clean($findings);
    }

    public function test_trademark_reports_a_restricted_tag_from_the_wporg_list(): void
    {
        $findings = $this->findings(new Trademark_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'readme.txt' => $this->readme(['Tags' => 'woocommerce, example']),
        ]);

        $this->assert_reports($findings, 'restricted term "woocommerce"');
    }

    public function test_declaration_only_detection_covers_every_class_like_construct(): void
    {
        $root = $this->make_plugin([
            'includes/contract.php' => "<?php\n\nnamespace Example;\n\ninterface Contract\n{\n    public function run(): void;\n}\n",
            'includes/helper.php' => "<?php\n\nnamespace Example;\n\ntrait Helper\n{\n    public function help(): void\n    {\n    }\n}\n",
            'includes/state.php' => "<?php\n\nnamespace Example;\n\nenum State: string\n{\n    case On = 'on';\n}\n",
            'includes/side-effect.php' => "<?php\n\ndefine( 'EXAMPLE_READY', true );\n",
            'includes/empty.php' => '',
            'readme.txt' => "not php\n",
        ]);
        $source = new Plugin_Source($root);

        $this->assertTrue($source->file('includes/contract.php')->is_declaration_only());
        $this->assertTrue($source->file('includes/helper.php')->is_declaration_only());
        $this->assertTrue($source->file('includes/state.php')->is_declaration_only());
        $this->assertFalse($source->file('includes/side-effect.php')->is_declaration_only());
        $this->assertTrue($source->file('includes/empty.php')->is_declaration_only());
        $this->assertFalse($source->file('readme.txt')->is_php());
        $this->assertSame([], $source->file('readme.txt')->tokens());
    }

    public function test_a_tree_with_no_plugin_header_still_scans(): void
    {
        $context = $this->context([
            'loose.php' => "<?php\nclass Loose {}\n",
        ]);

        $this->assertSame('loose', $context->slug());
        $this->assertSame('loose', $context->text_domain());
        $this->assertFalse($context->readme()->exists());
        $this->assertSame('', $context->header()->name());
    }

    public function test_snippet_truncates_a_very_long_line(): void
    {
        $root = $this->make_plugin([
            'includes/long.php' => "<?php\n// " . str_repeat('x', 400) . "\n",
        ]);
        $snippet = (new Plugin_Source($root))->file('includes/long.php')->snippet(2);

        $this->assertSame(120, strlen($snippet));
        $this->assertStringEndsWith('...', $snippet);
    }
}
