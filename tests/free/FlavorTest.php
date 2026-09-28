<?php

namespace WPMCP\Tests\Free;

use WPMCP\MCP\Registrar;
use WPMCP\Plugin;

/**
 * Build flavors (wp.org vertical builds, e.g. wpmcp-for-woocommerce) gate
 * which ability groups register. The default flavor is 'full' and registers
 * everything; the 'woocommerce' flavor keeps the safety core, content,
 * blocks, and WooCommerce domains but drops builders, integrations, REST
 * passthrough, and the guarded execution tools whose files are pruned from
 * that build's zip entirely.
 */
class FlavorTest extends \WP_UnitTestCase
{
    protected function tearDown(): void
    {
        Plugin::set_flavor_for_tests(null);
        parent::tearDown();
    }

    public function test_default_flavor_is_full(): void
    {
        $this->assertSame('full', Plugin::flavor());
    }

    public function test_flavor_override_requires_testing_mode_only(): void
    {
        Plugin::set_flavor_for_tests('woocommerce');
        $this->assertSame('woocommerce', Plugin::flavor());
    }

    public function test_woocommerce_flavor_keeps_safety_content_and_woo(): void
    {
        $names = $this->registered_names('woocommerce');

        // Safety core and content survive in every flavor.
        $this->assertContains('wpmcp/get-page', $names);
        $this->assertContains('wpmcp/rollback-operation', $names);
        $this->assertContains('wpmcp/rollback-session', $names);

        // The WooCommerce domain is the point of this flavor.
        $this->assertContains('wpmcp/list-products', $names);
        $this->assertContains('wpmcp/create-product', $names);
        $this->assertContains('wpmcp/list-orders', $names);
    }

    public function test_woocommerce_flavor_drops_pruned_domains(): void
    {
        $names = $this->registered_names('woocommerce');

        // Builders: their files are pruned from the wrapper zip.
        $this->assertNotContains('wpmcp/add-widget', $names);
        $this->assertNotContains('wpmcp/get-elementor-data', $names);
        $this->assertNotContains('wpmcp/create-custom-widget', $names);
        $this->assertNotContains('wpmcp/create-custom-block', $names);

        // Guarded execution: no eval()/proc_open call sites may ship at all.
        $this->assertNotContains('wpmcp/run-php-snippet', $names);
        $this->assertNotContains('wpmcp/run-wp-cli', $names);

        // Breadth kept out of the small wp.org build.
        $this->assertNotContains('wpmcp/call-rest', $names);
        $this->assertNotContains('wpmcp/cloud-connect', $names);

        // Agent memory tools: pruned with the group. Note that ENFORCEMENT of
        // published guardrails is not a tool and is not pruned; it lives in
        // Registrar::is_permitted() on every build.
        $this->assertNotContains('wpmcp/memory-recall', $names);
        $this->assertNotContains('wpmcp/memory-propose', $names);
        $this->assertNotContains('wpmcp/memory-save-summary', $names);

        // Stored custom CSS/JS (issue #63): scripts/build-woo-release.sh
        // deletes the handlers, the sanitizer, the store and the renderer
        // from this zip, so registering either ability would name a class the
        // build does not contain.
        $this->assertNotContains('wpmcp/add-scoped-css', $names);
        $this->assertNotContains('wpmcp/add-custom-js', $names);
    }

    /**
     * The front-end output side of the same prune. The woo zip has no
     * Custom_Code_Renderer.php, so nothing may hook wp_head, wp_footer or
     * deleted_post at it: those hooks fire on every request, and a hook
     * pointing at a missing class is a fatal on a plain page view rather than
     * a quietly missing feature.
     */
    public function test_custom_code_runtime_hooks_follow_the_flavor(): void
    {
        $css     = [\WPMCP\Tools\CustomCode\Custom_Code_Renderer::class, 'print_css'];
        $js      = [\WPMCP\Tools\CustomCode\Custom_Code_Renderer::class, 'print_js'];
        $cleanup = [\WPMCP\Tools\CustomCode\Custom_Code_Store::class, 'delete_css'];

        \WPMCP\Tools\CustomCode\Custom_Code_Renderer::reset_for_tests();
        remove_action('wp_head', $css, 101);
        remove_action('wp_footer', $js, 101);
        remove_action('deleted_post', $cleanup);

        Plugin::set_flavor_for_tests('woocommerce');
        Plugin::instance()->register_custom_code_runtime_hooks();
        $this->assertFalse(has_action('wp_head', $css));
        $this->assertFalse(has_action('wp_footer', $js));
        $this->assertFalse(has_action('deleted_post', $cleanup));

        // Default flavor restores them, which also leaves global state exactly
        // as the suite bootstrap set it up.
        Plugin::set_flavor_for_tests(null);
        Plugin::instance()->register_custom_code_runtime_hooks();
        $this->assertSame(101, has_action('wp_head', $css));
        $this->assertSame(101, has_action('wp_footer', $js));
        $this->assertSame(10, has_action('deleted_post', $cleanup));
    }

    /**
     * The renderer is reached through a STRING callable, not a static
     * reference, for the same reason the widget and block builder branches
     * are: a build that prunes the file must not name the class. Asserted on
     * the source because the difference is invisible at runtime on a build
     * that still has the class.
     */
    public function test_the_custom_code_branch_names_the_renderer_as_a_string(): void
    {
        $method = new \ReflectionMethod(Plugin::class, 'register_custom_code_runtime_hooks');
        $source = implode(
            '',
            array_slice(
                file((string) $method->getFileName()),
                $method->getStartLine() - 1,
                $method->getEndLine() - $method->getStartLine() + 1
            )
        );

        $this->assertStringNotContainsString('Custom_Code_Renderer::boot', $source);
        $this->assertStringContainsString("Custom_Code_Renderer', 'boot'", $source);
    }

    public function test_memory_runtime_hooks_follow_the_flavor(): void
    {
        $cpt        = [\WPMCP\Memory\Memory_Store::class, 'ensure_post_type'];
        $transition = [\WPMCP\Memory\Memory_Store::class, 'flush_rules_cache_on_transition'];

        remove_action('init', $cpt, 5);
        remove_action('transition_post_status', $transition);

        Plugin::set_flavor_for_tests('woocommerce');
        Plugin::instance()->register_builder_runtime_hooks();
        $this->assertFalse(has_action('init', $cpt));
        $this->assertFalse(has_action('transition_post_status', $transition));

        Plugin::set_flavor_for_tests(null);
        Plugin::instance()->register_builder_runtime_hooks();
        $this->assertSame(5, has_action('init', $cpt));
        $this->assertSame(10, has_action('transition_post_status', $transition));
    }

    public function test_woocommerce_flavor_is_a_strict_subset_of_full(): void
    {
        $woo  = $this->registered_names('woocommerce');
        $full = $this->registered_names(null);

        $this->assertNotEmpty($woo);
        $this->assertLessThan(count($full), count($woo));
        $this->assertSame([], array_diff($woo, $full));
    }

    public function test_boot_wires_registration_and_respects_the_flavor(): void
    {
        global $wp_filter;
        $backup = array_map(fn ($hook) => clone $hook, $wp_filter);

        try {
            // Full flavor: boot() wires ability registration and the
            // builder runtime hooks (re-adding over the bootstrap's
            // identical registrations; state is restored below).
            Plugin::instance()->boot();
            $this->assertNotFalse(has_action('wp_abilities_api_init', [Plugin::instance(), 'register_abilities']));
            $this->assertNotFalse(has_action('init', ['\\WPMCP\\Tools\\BlockBuilder\\Block_Spec_Store', 'ensure_post_type']));

            // WooCommerce flavor: boot() must not reference the builder
            // classes (their files are pruned from that build's zip).
            $wp_filter = array_map(fn ($hook) => clone $hook, $backup);
            remove_action('init', ['\\WPMCP\\Tools\\WidgetBuilder\\Widget_Spec_Store', 'ensure_post_type']);
            remove_action('init', ['\\WPMCP\\Tools\\BlockBuilder\\Block_Spec_Store', 'ensure_post_type'], 5);
            Plugin::set_flavor_for_tests('woocommerce');
            Plugin::instance()->boot();
            $this->assertNotFalse(has_action('wp_abilities_api_init', [Plugin::instance(), 'register_abilities']));
            $this->assertFalse(has_action('init', ['\\WPMCP\\Tools\\WidgetBuilder\\Widget_Spec_Store', 'ensure_post_type']));
            $this->assertFalse(has_action('init', ['\\WPMCP\\Tools\\BlockBuilder\\Block_Spec_Store', 'ensure_post_type']));
        } finally {
            $wp_filter = $backup;
        }
    }

    public function test_builder_runtime_hooks_follow_the_flavor(): void
    {
        $widget_cpt  = ['\\WPMCP\\Tools\\WidgetBuilder\\Widget_Spec_Store', 'ensure_post_type'];
        $widget_reg  = ['\\WPMCP\\Tools\\WidgetBuilder\\Widget_Registry', 'register'];
        $block_cpt   = ['\\WPMCP\\Tools\\BlockBuilder\\Block_Spec_Store', 'ensure_post_type'];
        $block_reg   = ['\\WPMCP\\Tools\\BlockBuilder\\Block_Registry', 'register'];

        // Clear what the suite bootstrap's boot() already wired so absence
        // is observable.
        remove_action('init', $widget_cpt);
        remove_action('elementor/widgets/register', $widget_reg);
        remove_action('init', $block_cpt, 5);
        remove_action('init', $block_reg, 20);

        Plugin::set_flavor_for_tests('woocommerce');
        Plugin::instance()->register_builder_runtime_hooks();
        $this->assertFalse(has_action('init', $widget_cpt));
        $this->assertFalse(has_action('elementor/widgets/register', $widget_reg));
        $this->assertFalse(has_action('init', $block_cpt));
        $this->assertFalse(has_action('init', $block_reg));

        // Default flavor restores the hooks, which also leaves global state
        // exactly as the bootstrap set it up.
        Plugin::set_flavor_for_tests(null);
        Plugin::instance()->register_builder_runtime_hooks();
        $this->assertSame(10, has_action('init', $widget_cpt));
        $this->assertSame(10, has_action('elementor/widgets/register', $widget_reg));
        $this->assertSame(5, has_action('init', $block_cpt));
        $this->assertSame(20, has_action('init', $block_reg));
    }

    /** @return string[] declared ability names under the given flavor. */
    private function registered_names(?string $flavor): array
    {
        Plugin::set_flavor_for_tests($flavor);
        $registrar = new Registrar();
        Plugin::instance()->register_abilities_into($registrar);

        return array_map(fn ($a) => $a->name, array_values($registrar->declared()));
    }
}
