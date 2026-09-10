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

        // Theme-builder site parts (issue #70): the group is not in
        // FLAVOR_GROUPS['woocommerce'] and build-woo-release.sh prunes
        // src/Tools/ThemeBuilder, so the gate and the artifact stay in sync.
        $this->assertNotContains('wpmcp/create-site-part', $names);
        $this->assertNotContains('wpmcp/resolve-site-part', $names);
        $this->assertNotContains('wpmcp/delete-site-part', $names);

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
        $theme_cpt   = ['\\WPMCP\\Tools\\ThemeBuilder\\Template_Store', 'ensure_post_type'];
        $theme_boot  = ['\\WPMCP\\Tools\\ThemeBuilder\\Render\\Adapters', 'boot'];

        // Clear what the suite bootstrap's boot() already wired so absence
        // is observable.
        remove_action('init', $widget_cpt);
        remove_action('elementor/widgets/register', $widget_reg);
        remove_action('init', $block_cpt, 5);
        remove_action('init', $block_reg, 20);
        remove_action('init', $theme_cpt);
        remove_action('wp', $theme_boot);

        Plugin::set_flavor_for_tests('woocommerce');
        Plugin::instance()->register_builder_runtime_hooks();
        $this->assertFalse(has_action('init', $widget_cpt));
        $this->assertFalse(has_action('elementor/widgets/register', $widget_reg));
        $this->assertFalse(has_action('init', $block_cpt));
        $this->assertFalse(has_action('init', $block_reg));
        $this->assertFalse(has_action('init', $theme_cpt));
        $this->assertFalse(has_action('wp', $theme_boot));

        // Default flavor restores the hooks, which also leaves global state
        // exactly as the bootstrap set it up.
        Plugin::set_flavor_for_tests(null);
        Plugin::instance()->register_builder_runtime_hooks();
        $this->assertSame(10, has_action('init', $widget_cpt));
        $this->assertSame(10, has_action('elementor/widgets/register', $widget_reg));
        $this->assertSame(5, has_action('init', $block_cpt));
        $this->assertSame(20, has_action('init', $block_reg));
        $this->assertSame(10, has_action('init', $theme_cpt));
        $this->assertSame(10, has_action('wp', $theme_boot));
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
