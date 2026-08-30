<?php

namespace WPMCP\Tests\Free\Compliance;

use WPMCP\Compliance\Rules\Admin_Nag_Rule;
use WPMCP\Compliance\Rules\I18n_Rule;
use WPMCP\Compliance\Rules\Readme_Rule;
use WPMCP\Compliance\Rules\Short_Url_Rule;
use WPMCP\Compliance\Rules\Trademark_Rule;

/**
 * Group D of the rulebook: the listing, the name and the admin experience.
 */
class ListingRulesTest extends Compliance_Test_Case
{
    public function test_readme_rule_accepts_a_conformant_readme(): void
    {
        $findings = $this->findings(new Readme_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'readme.txt' => $this->readme(),
        ]);

        $this->assert_clean($findings);
    }

    public function test_readme_rule_reports_a_stable_tag_mismatch_and_too_many_tags(): void
    {
        $findings = $this->findings(new Readme_Rule(), [
            'example-toolkit.php' => $this->main_file(['Version' => '1.2.3']),
            'readme.txt' => $this->readme([
                'Stable tag' => '1.2.2',
                'Tags' => 'one, two, three, four, five, six',
            ]),
        ]);

        $this->assert_reports($findings, 'Stable tag "1.2.2" does not match the Version "1.2.3"');
        $this->assert_reports($findings, '6 tags');
    }

    public function test_readme_rule_reports_missing_headers_sections_and_a_long_short_description(): void
    {
        $readme = "=== Example Toolkit ===\nContributors: examplecontributor\n\n" . str_repeat('long ', 40) . "\n";

        $findings = $this->findings(new Readme_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'readme.txt' => $readme,
        ]);

        $this->assert_reports($findings, 'missing required header "stable tag"');
        $this->assert_reports($findings, 'missing required section "== Description =="');
        $this->assert_reports($findings, 'short description is');
    }

    public function test_readme_rule_reports_a_missing_readme(): void
    {
        $findings = $this->findings(new Readme_Rule(), [
            'example-toolkit.php' => $this->main_file(),
        ]);

        $this->assertCount(1, $findings);
        $this->assert_reports($findings, 'readme.txt is missing');
    }

    public function test_readme_rule_reports_a_non_numeric_version_header_and_trunk(): void
    {
        $findings = $this->findings(new Readme_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'readme.txt' => $this->readme(['Stable tag' => 'trunk', 'Tested up to' => 'WP 6.9']),
        ]);

        $this->assert_reports($findings, 'Stable tag: trunk');
        $this->assert_reports($findings, 'must be numbers only');
    }

    public function test_readme_rule_requires_an_external_services_section_when_the_plugin_calls_out(): void
    {
        $findings = $this->findings(new Readme_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'readme.txt' => $this->readme(),
            'includes/api.php' => "<?php\nclass Api {\n    public function run() {\n        return wp_remote_get( 'https://api.example-service.test/v1' );\n    }\n}\n",
        ]);

        $this->assert_reports($findings, 'no "== External services ==" section');
    }

    public function test_trademark_rule_reports_a_restricted_term_in_the_name_and_tags(): void
    {
        $findings = $this->findings(new Trademark_Rule(), [
            'example-toolkit.php' => $this->main_file(['Plugin Name' => 'Example Toolkit', 'Text Domain' => 'example-toolkit']),
            'readme.txt' => $this->readme([
                'title' => 'Example Toolkit - AI Agents for WordPress with Snapshots',
                'Tags' => 'example, claude, testing',
            ]),
        ]);

        $this->assert_reports($findings, 'restricted term "wordpress"');
        $this->assert_reports($findings, 'tag "claude" is a third-party trademark');
    }

    public function test_trademark_rule_accepts_the_trailing_for_woocommerce_form(): void
    {
        $rule = new Trademark_Rule();

        $this->assertSame([], $rule->matches('example-toolkit-for-woocommerce'));
        $this->assertContains('woocommerce', $rule->matches('woocommerce-example-toolkit'));
        $this->assertContains('woo', $rule->matches('woo-example-toolkit'));
    }

    public function test_trademark_rule_treats_the_wp_prefix_as_a_warning_only(): void
    {
        $findings = $this->findings(new Trademark_Rule(), [
            'wpmcp.php' => $this->main_file(['Plugin Name' => 'wpmcp', 'Text Domain' => 'wpmcp']),
        ]);

        $this->assertNotSame([], $findings);
        foreach ($findings as $finding) {
            $this->assertSame('best-practice', $finding->severity_override(), $finding->message());
        }
    }

    public function test_trademark_rule_reports_a_text_domain_that_is_not_the_slug(): void
    {
        $findings = $this->findings(new Trademark_Rule(), [
            'example-toolkit.php' => $this->main_file(['Text Domain' => 'example']),
        ]);

        $this->assert_reports($findings, 'does not match the slug');
    }

    public function test_short_url_rule_reports_shorteners_in_code_and_readme(): void
    {
        $findings = $this->findings(new Short_Url_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'readme.txt' => $this->readme(['short' => 'Docs at https://bit.ly/example-docs for more.']),
            'includes/links.php' => "<?php\nclass Links {\n    const DOCS = 'https://tinyurl.com/example';\n}\n",
        ]);

        $this->assert_reports($findings, 'bit.ly');
        $this->assert_reports($findings, 'tinyurl.com');
    }

    public function test_admin_nag_rule_reports_notices_and_pay_to_unlock_copy(): void
    {
        $findings = $this->findings(new Admin_Nag_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/notice.php' => "<?php\nadd_action( 'admin_notices', 'example_notice' );\nfunction example_notice() {\n    echo '<div class=\"notice\">Upgrade to unlock scheduled exports</div>';\n}\n",
        ]);

        $this->assert_reports($findings, "hooks admin_notices");
        $this->assert_reports($findings, 'pay-to-unlock copy "upgrade to unlock"');
    }

    public function test_admin_nag_rule_is_quiet_on_a_settings_screen_without_upsell(): void
    {
        $findings = $this->findings(new Admin_Nag_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/settings.php' => "<?php\nfunction example_settings() {\n    echo '<div class=\"wrap\"><h1>Example Toolkit</h1></div>';\n}\n",
        ]);

        $this->assert_clean($findings);
    }

    public function test_i18n_rule_reports_a_mismatched_missing_and_dynamic_text_domain(): void
    {
        $body = "<?php\nfunction example_strings( \$domain ) {\n";
        $body .= "    \$a = __( 'Saved', 'other-domain' );\n";
        $body .= "    \$b = esc_html__( 'Deleted' );\n";
        $body .= "    \$c = __( 'Moved', \$domain );\n";
        $body .= "    return [ \$a, \$b, \$c ];\n}\n";

        $findings = $this->findings(new I18n_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/strings.php' => $body,
        ]);

        $this->assert_reports($findings, 'text domain "other-domain" does not match');
        $this->assert_reports($findings, 'has no text domain argument');
        $this->assert_reports($findings, 'non-literal text domain');
    }

    public function test_i18n_rule_accepts_consistent_domains(): void
    {
        $body = "<?php\nfunction example_strings() {\n";
        $body .= "    \$a = __( 'Saved', 'example-toolkit' );\n";
        $body .= "    \$b = _x( 'Draft', 'post status', 'example-toolkit' );\n";
        $body .= "    /* translators: %d: number of items. */\n";
        $body .= "    \$c = _n( '%d item', '%d items', 2, 'example-toolkit' );\n";
        $body .= "    return [ \$a, \$b, \$c ];\n}\n";

        $findings = $this->findings(new I18n_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/strings.php' => $body,
        ]);

        $this->assert_clean($findings);
    }

    public function test_i18n_rule_reports_untranslated_menu_labels(): void
    {
        $findings = $this->findings(new I18n_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/menu.php' => "<?php\nfunction example_menu() {\n    add_menu_page( 'Example', 'Example', 'manage_options', 'example', 'example_render' );\n}\n",
        ]);

        $this->assert_reports($findings, 'add_menu_page() is given an untranslated label');
    }

    public function test_i18n_rule_reports_a_placeholder_string_without_a_translators_comment(): void
    {
        $body = "<?php\nfunction example_strings( \$count ) {\n";
        $body .= "    \$a = sprintf( __( 'Deleted %d posts', 'example-toolkit' ), \$count );\n";
        $body .= "    return \$a;\n}\n";

        $findings = $this->findings(new I18n_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/strings.php' => $body,
        ]);

        $this->assert_reports($findings, 'has placeholders but no translators comment');
    }

    public function test_i18n_rule_accepts_a_translators_comment_on_the_line_above(): void
    {
        $body = "<?php\nfunction example_strings( \$count ) {\n";
        $body .= "    /* translators: %d: number of posts deleted. */\n";
        $body .= "    \$a = sprintf( __( 'Deleted %d posts', 'example-toolkit' ), \$count );\n";
        $body .= "    \$b = sprintf(\n        /* translators: %s: post title. */\n        __( '%s (copy)', 'example-toolkit' ),\n        'Hello'\n    );\n";
        $body .= "    return [ \$a, \$b ];\n}\n";

        $findings = $this->findings(new I18n_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/strings.php' => $body,
        ]);

        $this->assert_clean($findings);
    }

    public function test_i18n_rule_ignores_a_literal_percent_and_a_string_without_placeholders(): void
    {
        $body = "<?php\nfunction example_strings() {\n";
        $body .= "    \$a = __( 'Saved', 'example-toolkit' );\n";
        $body .= "    \$b = __( '100%% complete', 'example-toolkit' );\n";
        $body .= "    return [ \$a, \$b ];\n}\n";

        $findings = $this->findings(new I18n_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/strings.php' => $body,
        ]);

        $this->assert_clean($findings);
    }

    public function test_i18n_rule_reports_a_concatenated_translatable_string(): void
    {
        $body = "<?php\nfunction example_strings() {\n";
        $body .= "    return esc_html__(\n        'One long sentence that was wrapped '\n        . 'across two source lines.',\n        'example-toolkit'\n    );\n}\n";

        $findings = $this->findings(new I18n_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/strings.php' => $body,
        ]);

        $this->assert_reports($findings, 'is not a single string literal');
    }
}
