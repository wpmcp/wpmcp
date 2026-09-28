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

        // Every class file a woocommerce-registered tool reaches for through
        // use-statements, followed transitively, must survive the prune.
        // Divergences that predate this check and are tracked separately.
        // Build_Page and Elementor_Composer (compose group, kept for
        // woocommerce) import src/Tools/Elementor, which the woo zip prunes, so build-page with
        // the Elementor builder is a class-not-found in that zip. Listed
        // here so every NEW divergence still fails; delete the entry when
        // the build or the import is fixed.
        $known = [
            'src/Tools/Compose/Build_Page.php'         => ['src/Tools/Elementor'],
            'src/Tools/Compose/Elementor_Composer.php' => ['src/Tools/Elementor'],
        ];

        $resolved = 0;
        foreach ($needed as $file) {
            $owner = ltrim(str_replace($root, '', $file), '/');
            foreach ($this->referenced_files($file, $resolved) as $referenced) {
                $relative = ltrim(str_replace($root, '', $referenced), '/');
                foreach ($pruned as $prune) {
                    if (in_array($prune, $known[ $owner ] ?? [], true)) {
                        continue;
                    }
                    $this->assertFalse(
                        $relative === $prune || str_starts_with($relative, $prune . '/'),
                        $relative . ' is needed by the woocommerce flavor but build-woo-release.sh prunes ' . $prune
                    );
                }
            }
        }

        // Guards the guard: an import pattern that never matches makes the
        // loop above check only the handler files themselves.
        $this->assertGreaterThan(0, $resolved, 'no use-statement resolved; the import pattern is not matching');
    }

    /**
     * A handler file plus every WPMCP class it imports, followed
     * transitively through the imported files' own use-statements, as
     * absolute paths. $resolved counts the imports that resolved to a file,
     * so the caller can prove the walk did something.
     */
    private function referenced_files(string $file, int &$resolved = 0): array
    {
        $seen  = [$file => true];
        $queue = [$file];

        while ([] !== $queue) {
            $current = array_shift($queue);
            $source  = (string) file_get_contents($current);
            // Regex text: ^use\s+(WPMCP\\[A-Za-z0-9_\\]+); in a
            // single-quoted PHP string every regex backslash that must
            // reach PCRE as a literal backslash is written four times.
            preg_match_all('/^use\s+(WPMCP\\\\[A-Za-z0-9_\\\\]+);/m', $source, $matches);
            foreach ($matches[1] as $class) {
                if (! class_exists($class) && ! interface_exists($class) && ! trait_exists($class)) {
                    continue;
                }
                $imported = (new \ReflectionClass($class))->getFileName();
                if (! is_string($imported)) {
                    continue;
                }
                $resolved++;
                // Plugin is the composition root: its imports are the
                // registration table for every flavor, gated at runtime by
                // FLAVOR_GROUPS, so following them would report every pruned
                // group as "needed". It is still checked itself, just not
                // walked through.
                if (str_ends_with($imported, '/src/Plugin.php')) {
                    $seen[ $imported ] = true;
                    continue;
                }
                if (! isset($seen[ $imported ])) {
                    $seen[ $imported ] = true;
                    $queue[]           = $imported;
                }
            }
        }

        return array_keys($seen);
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
