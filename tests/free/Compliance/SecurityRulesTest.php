<?php

namespace WPMCP\Tests\Free\Compliance;

use WPMCP\Compliance\Rules\Direct_File_Access_Rule;
use WPMCP\Compliance\Rules\Input_Sanitization_Rule;
use WPMCP\Compliance\Rules\Nonce_Capability_Rule;
use WPMCP\Compliance\Rules\Output_Escaping_Rule;

/**
 * Group C of the rulebook, security half: guards, escaping, sanitization,
 * nonces and capabilities.
 */
class SecurityRulesTest extends Compliance_Test_Case
{
    public function test_a_file_with_side_effects_and_no_guard_is_reported(): void
    {
        $findings = $this->findings(new Direct_File_Access_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/bootstrap.php' => "<?php\nadd_action( 'init', 'example_boot' );\nfunction example_boot() {}\n",
        ]);

        $this->assert_reports($findings, 'no ABSPATH guard');
        $this->assertSame(['includes/bootstrap.php:2'], $this->locations($findings));
    }

    public function test_a_guarded_file_and_a_pure_class_declaration_are_both_accepted(): void
    {
        $findings = $this->findings(new Direct_File_Access_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/guarded.php' => "<?php\nif ( ! defined( 'ABSPATH' ) ) { exit; }\nadd_action( 'init', 'example_boot' );\nfunction example_boot() {}\n",
            'includes/value.php' => "<?php\n\nnamespace Example\\Values;\n\nfinal class Value\n{\n    public function get(): int\n    {\n        return 1;\n    }\n}\n",
        ]);

        $this->assert_clean($findings);
    }

    /**
     * Direct_File_Access_Check accepts five exact shapes. A guard carrying an
     * extra conjunct matches none of them, so Plugin Check reports the file as
     * unprotected even though the constant is named. Confirmed against Plugin
     * Check 2.0.0, which flagged exactly this line in wpmcp's own src/Plugin.php.
     */
    public function test_a_guard_with_an_extra_conjunct_is_reported(): void
    {
        $body = "<?php\n\nnamespace Example;\n\n";
        $body .= "if (! defined('ABSPATH') && ! defined('EXAMPLE_TESTING')) {\n    exit;\n}\n\n";
        $body .= "add_action('init', 'example_boot');\nfunction example_boot() {}\n";

        $findings = $this->findings(new Direct_File_Access_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/bootstrap.php' => $body,
        ]);

        $this->assert_reports($findings, 'not in a form Direct_File_Access_Check accepts');
        $this->assertSame(['includes/bootstrap.php:5'], $this->locations($findings));
    }

    /**
     * @dataProvider accepted_guard_provider
     */
    public function test_every_accepted_guard_shape_is_clean(string $guard): void
    {
        $findings = $this->findings(new Direct_File_Access_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/guarded.php' => "<?php\n" . $guard . "\nadd_action( 'init', 'example_boot' );\nfunction example_boot() {}\n",
        ]);

        $this->assert_clean($findings);
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function accepted_guard_provider(): array
    {
        return [
            'abspath or exit' => ["defined( 'ABSPATH' ) || exit;"],
            'abspath or die' => ["defined( 'ABSPATH' ) or die;"],
            'wpinc or exit' => ["defined( 'WPINC' ) || exit;"],
            'if not abspath exit' => ["if ( ! defined( 'ABSPATH' ) ) exit;"],
            'if not abspath braced' => ["if ( ! defined( 'ABSPATH' ) ) { exit; }"],
            'if not wpinc braced die' => ["if ( ! defined( 'WPINC' ) ) { die(); }"],
        ];
    }

    /**
     * Plugin Check does not read the whole file looking for the guard. Its AST
     * pass only walks top-level statements, which a namespaced file never
     * reaches, and its regex fallback reads a window at the head of the file.
     * A guard parked below a long use block is therefore invisible to it, and
     * that is exactly the shape src/Plugin.php shipped for issue #170: the
     * bare guard sat at line 297, under 286 use statements.
     */
    public function test_a_guard_below_the_head_of_the_file_is_reported(): void
    {
        $body = "<?php\n\nnamespace Example;\n\n";
        for ($i = 0; $i < 60; $i++) {
            $body .= sprintf("use Example\\Vendor\\Thing%d;\n", $i);
        }
        $body .= "\nif (! defined('ABSPATH')) {\n    exit;\n}\n\n";
        $body .= "add_action('init', 'example_boot');\nfunction example_boot() {}\n";

        $findings = $this->findings(new Direct_File_Access_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/bootstrap.php' => $body,
        ]);

        $this->assert_reports($findings, 'too far down the file');
        $this->assertSame(['includes/bootstrap.php:66'], $this->locations($findings));
    }

    /**
     * The same file with the guard moved directly under the namespace, which
     * is the fix issue #170 asks for.
     */
    public function test_the_same_guard_directly_under_the_namespace_is_accepted(): void
    {
        $body = "<?php\n\nnamespace Example;\n\n";
        $body .= "if (! defined('ABSPATH')) {\n    exit;\n}\n\n";
        for ($i = 0; $i < 60; $i++) {
            $body .= sprintf("use Example\\Vendor\\Thing%d;\n", $i);
        }
        $body .= "\nadd_action('init', 'example_boot');\nfunction example_boot() {}\n";

        $findings = $this->findings(new Direct_File_Access_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/bootstrap.php' => $body,
        ]);

        $this->assert_clean($findings);
    }

    /**
     * A comment does not buy extra room: the checker reads raw lines, so a
     * header long enough to push the guard out of the window still hides it.
     */
    public function test_a_long_header_comment_counts_against_the_guard_window(): void
    {
        $body = "<?php\n/**\n";
        for ($i = 0; $i < 60; $i++) {
            $body .= " * Line " . $i . " of a very long file header.\n";
        }
        $body .= " */\n";
        $body .= "if (! defined('ABSPATH')) {\n    exit;\n}\n";
        $body .= "add_action('init', 'example_boot');\nfunction example_boot() {}\n";

        $findings = $this->findings(new Direct_File_Access_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/bootstrap.php' => $body,
        ]);

        $this->assert_reports($findings, 'too far down the file');
    }

    /**
     * A guard quoted in a docblock is not a guard. The checker strips comments
     * before matching, so this rule does too.
     */
    public function test_a_guard_mentioned_only_in_a_comment_is_not_accepted(): void
    {
        $body = "<?php\n/**\n * Callers must add defined( 'ABSPATH' ) || exit; at the top.\n */\n";
        $body .= "add_action( 'init', 'example_boot' );\nfunction example_boot() {}\n";

        $findings = $this->findings(new Direct_File_Access_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/bootstrap.php' => $body,
        ]);

        $this->assert_reports($findings, 'not in a form Direct_File_Access_Check accepts');
    }

    public function test_unescaped_output_is_reported(): void
    {
        $findings = $this->findings(new Output_Escaping_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/screen.php' => "<?php\nif ( ! defined( 'ABSPATH' ) ) { exit; }\nfunction example_screen( \$label ) {\n    echo '<p>' . \$label . '</p>';\n}\n",
        ]);

        $this->assert_reports($findings, 'no escaping function or integer cast');
        $this->assertSame('likely-reject', (new Output_Escaping_Rule())->default_severity());
    }

    public function test_escaped_cast_and_literal_ternary_output_are_accepted(): void
    {
        $body = "<?php\nif ( ! defined( 'ABSPATH' ) ) { exit; }\nfunction example_screen( \$label, \$rows, \$on ) {\n";
        $body .= "    echo esc_html( \$label );\n";
        $body .= "    echo (int) count( \$rows );\n";
        $body .= "    echo \$on ? '0' : '1';\n";
        $body .= "    echo 'static markup';\n}\n";

        $findings = $this->findings(new Output_Escaping_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/screen.php' => $body,
        ]);

        $this->assert_clean($findings);
    }

    /**
     * Plugin Check reports this either way, because it does not follow calls,
     * so the finding stands. What must not stand is the claim that no escaping
     * happens: the renderer escapes every value it interpolates, and the fix is
     * a justified phpcs:ignore rather than double escaping. The message has to
     * name the callee so that note can be written from the finding.
     */
    public function test_output_from_an_escaping_renderer_names_the_callee(): void
    {
        $renderer = "<?php\n\nnamespace Example;\n\nif ( ! defined( 'ABSPATH' ) ) { exit; }\n\n";
        $renderer .= "class Widget_Renderer\n{\n    public static function render( array \$spec, array \$settings ): string\n    {\n";
        $renderer .= "        return esc_html( (string) ( \$settings['title'] ?? '' ) );\n    }\n}\n";

        $widget = "<?php\nif ( ! defined( 'ABSPATH' ) ) { exit; }\n";
        $widget .= "function example_render( \$spec, \$settings ) {\n    echo Widget_Renderer::render( \$spec, \$settings );\n}\n";

        $findings = $this->findings(new Output_Escaping_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/renderer.php' => $renderer,
            'includes/widget.php' => $widget,
        ]);

        $this->assert_reports($findings, 'Widget_Renderer::render()');
        $this->assert_reports($findings, 'includes/renderer.php:9');
        $this->assert_reports($findings, 'phpcs:ignore');
    }

    /**
     * Resolution is class-qualified and refuses to guess. A render() that is
     * not the one being called must never be named in the finding.
     */
    public function test_callee_resolution_does_not_name_an_unrelated_same_named_method(): void
    {
        $other = "<?php\n\nnamespace Example;\n\nif ( ! defined( 'ABSPATH' ) ) { exit; }\n\n";
        $other .= "class Admin_Screen\n{\n    public function render(): string\n    {\n        return esc_html( 'x' );\n    }\n}\n";

        $widget = "<?php\nif ( ! defined( 'ABSPATH' ) ) { exit; }\n";
        $widget .= "function example_render( \$spec ) {\n    echo Widget_Renderer::render( \$spec );\n}\n";

        $findings = $this->findings(new Output_Escaping_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/admin.php' => $other,
            'includes/widget.php' => $widget,
        ]);

        $this->assert_reports($findings, 'no escaping function or integer cast');
        $this->assertStringNotContainsString('Admin_Screen', implode("\n", $this->messages($findings)));
    }

    /**
     * PHPCS honours a justified phpcs:ignore, and so does Plugin Check, so a
     * rule that mirrors a sniff has to honour it too. Binary output is the
     * case that forces the issue: every escaper on the list would corrupt it,
     * and this rule's own message tells you to annotate rather than escape.
     */
    public function test_a_justified_phpcs_ignore_suppresses_an_escaping_finding(): void
    {
        $body = "<?php\nif ( ! defined( 'ABSPATH' ) ) { exit; }\nfunction example_download( \$bundle ) {\n";
        $body .= "    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary body sent as octet-stream.\n";
        $body .= "    echo \$bundle;\n}\n";

        $findings = $this->findings(new Output_Escaping_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/download.php' => $body,
        ]);

        $this->assert_clean($findings);
    }

    /**
     * The annotation is a record, not a mute button. WordPressCS requires the
     * "--" justification and so does this: without one, the finding stands.
     */
    public function test_a_bare_phpcs_ignore_suppresses_nothing(): void
    {
        $body = "<?php\nif ( ! defined( 'ABSPATH' ) ) { exit; }\nfunction example_download( \$bundle ) {\n";
        $body .= "    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped\n";
        $body .= "    echo \$bundle;\n}\n";

        $findings = $this->findings(new Output_Escaping_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/download.php' => $body,
        ]);

        $this->assert_reports($findings, 'no escaping function or integer cast');
    }

    /** An annotation for a different sniff must not suppress this one. */
    public function test_a_phpcs_ignore_for_another_sniff_suppresses_nothing(): void
    {
        $body = "<?php\nif ( ! defined( 'ABSPATH' ) ) { exit; }\nfunction example_download( \$bundle ) {\n";
        $body .= "    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- unrelated.\n";
        $body .= "    echo \$bundle;\n}\n";

        $findings = $this->findings(new Output_Escaping_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/download.php' => $body,
        ]);

        $this->assert_reports($findings, 'no escaping function or integer cast');
    }

    public function test_a_justified_phpcs_ignore_suppresses_a_sanitization_finding(): void
    {
        $body = "<?php\nif ( ! defined( 'ABSPATH' ) ) { exit; }\nfunction example_page() {\n";
        $body .= "    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- nonce and capability are verified by the caller.\n";
        $body .= "    return \$_POST;\n}\n";

        $findings = $this->findings(new Input_Sanitization_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/handler.php' => $body,
        ]);

        $this->assert_clean($findings);
    }

    public function test_unsanitized_superglobal_read_is_reported(): void
    {
        $findings = $this->findings(new Input_Sanitization_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/handler.php' => "<?php\nif ( ! defined( 'ABSPATH' ) ) { exit; }\nfunction example_page() {\n    \$page = \$_GET['page'];\n    return \$page;\n}\n",
        ]);

        $this->assert_reports($findings, '$_GET read without wp_unslash()');
    }

    public function test_sanitized_superglobal_read_is_accepted(): void
    {
        $findings = $this->findings(new Input_Sanitization_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/handler.php' => "<?php\nif ( ! defined( 'ABSPATH' ) ) { exit; }\nfunction example_page() {\n    return isset( \$_GET['page'] ) ? sanitize_key( wp_unslash( \$_GET['page'] ) ) : '';\n}\n",
        ]);

        $this->assert_clean($findings);
    }

    public function test_a_write_handler_without_a_nonce_or_capability_check_is_reported(): void
    {
        $findings = $this->findings(new Nonce_Capability_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/save.php' => "<?php\nif ( ! defined( 'ABSPATH' ) ) { exit; }\nfunction example_save() {\n    update_option( 'example', sanitize_text_field( wp_unslash( \$_POST['value'] ) ) );\n}\n",
        ]);

        $this->assert_reports($findings, 'never verifies a nonce');
        $this->assert_reports($findings, 'never checks a capability');
    }

    public function test_a_write_handler_with_both_checks_is_accepted(): void
    {
        $body = "<?php\nif ( ! defined( 'ABSPATH' ) ) { exit; }\nfunction example_save() {\n";
        $body .= "    check_admin_referer( 'example_save' );\n";
        $body .= "    if ( ! current_user_can( 'manage_options' ) ) {\n        return;\n    }\n";
        $body .= "    update_option( 'example', sanitize_text_field( wp_unslash( \$_POST['value'] ) ) );\n}\n";

        $findings = $this->findings(new Nonce_Capability_Rule(), [
            'example-toolkit.php' => $this->main_file(),
            'includes/save.php' => $body,
        ]);

        $this->assert_clean($findings);
    }
}
