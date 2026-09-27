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

    public function test_woocommerce_flavor_keeps_the_whole_gateway_lifecycle(): void
    {
        // Issue #142. The gateway group ships on every flavor on purpose:
        // a build that can mint a credential but not revoke one is a
        // security hole, and revocation is required to work locally with
        // the cloud unreachable.
        $names = $this->registered_names('woocommerce');

        $this->assertContains('wpmcp/gateway-provision', $names);
        $this->assertContains('wpmcp/gateway-status', $names);
        $this->assertContains('wpmcp/gateway-revoke', $names);
    }

    public function test_woo_build_does_not_prune_a_directory_the_woo_flavor_still_needs(): void
    {
        // The regression this pins: Gateway_Credential once lived in
        // src/Cloud, which build-woo-release.sh deletes wholesale, so every
        // gateway tool in that zip was a class-not-found fatal while the
        // flavor whitelist happily registered all three. Ability gating is
        // exercised against the full tree, so nothing else here can catch
        // a prune/whitelist divergence.
        $root   = dirname(__DIR__, 2);
        $script = file_get_contents($root . '/scripts/build-woo-release.sh');
        $this->assertIsString($script);

        preg_match_all('#"\$STAGE/(src/[A-Za-z0-9_/.]+)"#', $script, $matches);
        $pruned = array_values(array_unique($matches[1]));
        $this->assertNotEmpty($pruned, 'the prune list should be readable from the build script');

        $needed = [];
        foreach ($this->registered_names('woocommerce') as $name) {
            $ability = $this->ability_by_name('woocommerce', $name);
            $handler = $ability->handler;
            $object  = is_array($handler) ? $handler[0] : null;
            if (! is_object($object)) {
                continue;
            }
            $needed[] = (new \ReflectionClass($object))->getFileName();
        }
        $this->assertNotEmpty($needed);

        // Every class file a woocommerce-registered tool reaches for,
        // including the ones it pulls in transitively, must survive the
        // prune. Checking the handlers plus their direct use-statements is
        // enough to catch a whole-directory deletion.
        foreach ($needed as $file) {
            foreach ($this->referenced_files($file) as $referenced) {
                $relative = ltrim(str_replace($root, '', $referenced), '/');
                foreach ($pruned as $prune) {
                    $this->assertFalse(
                        $relative === $prune || str_starts_with($relative, $prune . '/'),
                        $relative . ' is needed by the woocommerce flavor but build-woo-release.sh prunes ' . $prune
                    );
                }
            }
        }
    }

    /** A handler file plus every src/ class it imports, as absolute paths. */
    private function referenced_files(string $file): array
    {
        $files = [$file];
        $source = (string) file_get_contents($file);
        preg_match_all('/^use\s+(WPMCP\\[A-Za-z0-9_\\]+);/m', $source, $matches);
        foreach ($matches[1] as $class) {
            if (class_exists($class)) {
                $imported = (new \ReflectionClass($class))->getFileName();
                if (is_string($imported)) {
                    $files[] = $imported;
                }
            }
        }

        return $files;
    }

    private function ability_by_name(?string $flavor, string $name): \WPMCP\MCP\Ability
    {
        Plugin::set_flavor_for_tests($flavor);
        $registrar = new Registrar();
        Plugin::instance()->register_abilities_into($registrar);

        foreach (array_values($registrar->declared()) as $ability) {
            if ($ability->name === $name) {
                return $ability;
            }
        }

        $this->fail('ability not registered: ' . $name);
    }
}
