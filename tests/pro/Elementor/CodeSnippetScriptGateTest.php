<?php

namespace WPMCP\Tests\Pro\Elementor;

use WPMCP\Governance\Governance_Audit_Log;
use WPMCP\Governance\Opt_In_Gates;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Elementor\Add_Custom_Css;
use WPMCP\Tools\Elementor\Create_Code_Snippet;

/**
 * Issue #558. create-code-snippet publishes raw markup that Elementor Pro
 * prints into wp_head, wp_body_open or wp_footer on every page, so it is the
 * same XSS-class surface as add-custom-js and must clear the same two
 * checks: the default-off wpmcp_allow_js_injection gate, and unfiltered_html
 * on top of manage_options. The Elementor-group add-custom-css writes core
 * Additional CSS and must require edit_css, as add-scoped-css does.
 *
 * "Without unfiltered_html" is exercised the way each leg really produces
 * it: on multisite by a sub-site administrator who is not a super admin
 * (core strips unfiltered_html and edit_css from them), and on a single
 * site by mapping unfiltered_html and edit_css to do_not_allow, which is
 * exactly what DISALLOW_UNFILTERED_HTML does in map_meta_cap().
 */
class CodeSnippetScriptGateTest extends \WP_UnitTestCase
{
    private const SCRIPT = '<script>fetch("/wp-json/wp/v2/users",{method:"POST"})</script>';

    /** @var callable|null */
    private $deny = null;

    public function set_up(): void
    {
        parent::set_up();
        Gate::set_pro_for_tests(true);
        Snapshot_Store::install();
        delete_option(Governance_Audit_Log::OPTION);

        if (! post_type_exists('elementor_snippet')) {
            register_post_type('elementor_snippet', ['public' => false, 'show_ui' => false]);
        }
    }

    public function tear_down(): void
    {
        remove_all_filters('wpmcp_allow_js_injection');
        if (null !== $this->deny) {
            remove_filter('map_meta_cap', $this->deny, 10);
            $this->deny = null;
        }
        if (is_multisite() && ms_is_switched()) {
            restore_current_blog();
        }
        Gate::set_pro_for_tests(null);
        parent::tear_down();
    }

    /** An administrator who holds unfiltered_html and edit_css on either leg. */
    private function act_as_trusted_admin(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        wpmcp_test_grant_network_admin(get_current_user_id());
        $this->assertTrue(current_user_can('unfiltered_html'));
    }

    /**
     * An administrator (manage_options) who lacks unfiltered_html and
     * edit_css: a sub-site admin on multisite, a DISALLOW_UNFILTERED_HTML
     * site's admin otherwise.
     */
    private function act_as_admin_without_unfiltered_html(): void
    {
        $admin = (int) self::factory()->user->create(['role' => 'administrator']);

        if (is_multisite()) {
            $this->setExpectedDeprecated('wpmu_new_blog');
            $blog = (int) self::factory()->blog->create();
            add_user_to_blog($blog, $admin, 'administrator');
            switch_to_blog($blog);
            if (! post_type_exists('elementor_snippet')) {
                register_post_type('elementor_snippet', ['public' => false, 'show_ui' => false]);
            }
        } else {
            $this->deny = static function ($caps, $cap) {
                // map_meta_cap() resolves both in one branch; DISALLOW_UNFILTERED_HTML
                // turns both into do_not_allow.
                return in_array($cap, ['unfiltered_html', 'edit_css'], true) ? ['do_not_allow'] : $caps;
            };
            add_filter('map_meta_cap', $this->deny, 10, 2);
        }

        wp_set_current_user($admin);
        if (is_multisite()) {
            $this->assertFalse(is_super_admin($admin), 'Precondition: a sub-site admin, not a super admin.');
        }
        $this->assertTrue(current_user_can('manage_options'), 'Precondition: the caller passes the ability\'s manage_options gate.');
        $this->assertFalse(current_user_can('unfiltered_html'), 'Precondition: the caller lacks unfiltered_html.');
        $this->assertFalse(current_user_can('edit_css'), 'Precondition: the caller lacks edit_css.');
    }

    private function snippet_count(): int
    {
        return count(get_posts([
            'post_type'      => 'elementor_snippet',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ]));
    }

    /** @return array<int, array<string, mixed>> */
    private function audit_rows(): array
    {
        return array_values(array_filter(
            Governance_Audit_Log::list(),
            static fn ($row) => 'wpmcp/create-code-snippet' === $row['ability']
        ));
    }

    // ---- create-code-snippet ------------------------------------------------

    public function test_create_code_snippet_is_listed_behind_the_js_injection_gate(): void
    {
        $this->assertTrue(Opt_In_Gates::is_gated('wpmcp/create-code-snippet'));
        $this->assertSame('wpmcp_allow_js_injection', Opt_In_Gates::filter_for('wpmcp/create-code-snippet'));
        $this->assertFalse(Opt_In_Gates::is_open('wpmcp/create-code-snippet'), 'The gate is default-off.');

        add_filter('wpmcp_allow_js_injection', '__return_true');
        $this->assertTrue(Opt_In_Gates::is_open('wpmcp/create-code-snippet'));
    }

    public function test_create_code_snippet_refuses_while_the_gate_is_closed(): void
    {
        $this->act_as_trusted_admin();
        $before = $this->snippet_count();

        $out = (new Create_Code_Snippet())->handle(['code' => self::SCRIPT, 'location' => 'wp_head']);

        $this->assertInstanceOf(\WP_Error::class, $out, 'create-code-snippet stored script with the default-off JS gate closed.');
        $this->assertSame('opt_in_required', $out->get_error_code());
        $this->assertSame($before, $this->snippet_count(), 'A refused call must create no elementor_snippet post.');

        $rows = $this->audit_rows();
        $this->assertCount(1, $rows);
        $this->assertFalse($rows[0]['allowed']);
        $this->assertSame('js-gate-closed', $rows[0]['reason']);
    }

    /** The gate answers before input validation, so a closed install reveals nothing. */
    public function test_create_code_snippet_checks_the_gate_before_the_payload(): void
    {
        $this->act_as_trusted_admin();

        $out = (new Create_Code_Snippet())->handle(['title' => 'x']);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('opt_in_required', $out->get_error_code());
    }

    public function test_create_code_snippet_refuses_a_caller_without_unfiltered_html(): void
    {
        add_filter('wpmcp_allow_js_injection', '__return_true');
        $this->act_as_admin_without_unfiltered_html();
        $before = $this->snippet_count();

        $out = (new Create_Code_Snippet())->handle(['code' => self::SCRIPT, 'location' => 'wp_head']);

        $this->assertInstanceOf(
            \WP_Error::class,
            $out,
            'create-code-snippet stored a published wp_head <script> for a user without unfiltered_html; add-custom-js refuses the same user.'
        );
        $this->assertSame('no_unfiltered_html', $out->get_error_code());
        $this->assertSame($before, $this->snippet_count(), 'A refused call must create no elementor_snippet post.');

        $rows = $this->audit_rows();
        $this->assertCount(1, $rows);
        $this->assertFalse($rows[0]['allowed']);
        $this->assertSame('no-unfiltered-html', $rows[0]['reason']);
    }

    public function test_create_code_snippet_refuses_a_draft_without_unfiltered_html_too(): void
    {
        add_filter('wpmcp_allow_js_injection', '__return_true');
        $this->act_as_admin_without_unfiltered_html();
        $before = $this->snippet_count();

        $out = (new Create_Code_Snippet())->handle(['code' => self::SCRIPT, 'status' => 'draft']);

        $this->assertInstanceOf(\WP_Error::class, $out, 'A draft snippet is one status change away from rendering.');
        $this->assertSame($before, $this->snippet_count());
    }

    public function test_create_code_snippet_stores_with_the_gate_open_and_unfiltered_html(): void
    {
        add_filter('wpmcp_allow_js_injection', '__return_true');
        $this->act_as_trusted_admin();

        $out = (new Create_Code_Snippet())->handle(['code' => self::SCRIPT, 'location' => 'wp_footer']);

        $this->assertIsArray($out);
        $this->assertSame(self::SCRIPT, get_post_meta($out['snippet_id'], '_elementor_code', true));
        $this->assertSame('publish', get_post_status($out['snippet_id']));

        $rows = $this->audit_rows();
        $this->assertCount(1, $rows);
        $this->assertTrue($rows[0]['allowed']);
    }

    // ---- add-custom-css (Elementor group) -----------------------------------

    public function test_add_custom_css_refuses_without_edit_css(): void
    {
        $this->act_as_admin_without_unfiltered_html();
        $before = (string) wp_get_custom_css();

        $out = (new Add_Custom_Css())->handle(['css' => 'body{background:url(https://attacker.example/x)}']);

        $this->assertInstanceOf(
            \WP_Error::class,
            $out,
            'Elementor-group add-custom-css wrote Additional CSS for a user without edit_css; add-scoped-css requires it.'
        );
        $this->assertSame('edit_css_required', $out->get_error_code());
        $this->assertSame($before, (string) wp_get_custom_css(), 'A refused call must leave Additional CSS unchanged.');
    }

    /** A replace from the same caller must not wipe the CSS a trusted admin stored. */
    public function test_add_custom_css_refuses_a_replace_without_edit_css(): void
    {
        $this->act_as_admin_without_unfiltered_html();
        // Seeded through core directly: what a trusted admin stored earlier.
        wp_update_custom_css_post('.kept{color:red}');
        $this->assertStringContainsString('.kept{color:red}', (string) wp_get_custom_css(), 'Precondition: seeded.');
        $this->assertFalse(current_user_can('edit_css'));

        $out = (new Add_Custom_Css())->handle(['css' => '.x{}', 'replace' => true]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertStringContainsString('.kept{color:red}', (string) wp_get_custom_css());
    }

    public function test_add_custom_css_still_writes_with_edit_css(): void
    {
        $this->act_as_trusted_admin();
        $this->assertTrue(current_user_can('edit_css'));

        $out = (new Add_Custom_Css())->handle(['css' => '.ok{color:blue}']);

        $this->assertIsArray($out);
        $this->assertStringContainsString('.ok{color:blue}', (string) wp_get_custom_css());
    }
}
