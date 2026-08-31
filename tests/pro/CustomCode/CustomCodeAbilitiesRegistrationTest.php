<?php

namespace WPMCP\Tests\Pro\CustomCode;

use WPMCP\MCP\{Ability, Registrar};
use WPMCP\Pro\Gate;
use WPMCP\Tools\CustomCode\Add_Custom_Js;
use WPMCP\Tools\CustomCode\Add_Scoped_Css;
use WPMCP\Tools\CustomCode\Custom_Code_Renderer;
use WPMCP\Tools\CustomCode\Custom_Code_Store;

/**
 * The custom_code group (issue #63) is PRO tier, matching the run-php-snippet
 * and run-wp-cli precedent for advanced/dangerous site-operations features.
 * Mirrors tests/pro/Code/RunPhpSnippetAbilitiesRegistrationTest.php: the boot
 * path registers abilities once at wp_abilities_api_init, so this builds the
 * same Ability objects and drives them through a fresh Registrar.
 */
class CustomCodeAbilitiesRegistrationTest extends \WP_UnitTestCase
{
    public function tear_down(): void
    {
        Gate::set_pro_for_tests(null);
        parent::tear_down();
    }

    /** @return Ability[] */
    private function make_abilities(): array
    {
        return [
            new Ability(
                'wpmcp/add-scoped-css',
                'pro',
                'Store a custom CSS block scoped to one post/page.',
                ['type' => 'object', 'properties' => ['css' => ['type' => 'string']], 'required' => ['css', 'post_id']],
                [new Add_Scoped_Css(), 'handle'],
                'manage_options',
                'code',
                'update'
            ),
            new Ability(
                'wpmcp/add-custom-js',
                'pro',
                'Store a site-wide custom JS snippet.',
                ['type' => 'object', 'properties' => ['js' => ['type' => 'string']], 'required' => ['js']],
                [new Add_Custom_Js(), 'handle'],
                'manage_options',
                'code',
                'update'
            ),
        ];
    }

    public function test_registrar_skips_custom_code_abilities_when_free(): void
    {
        Gate::set_pro_for_tests(false);

        $registrar = new Registrar();
        foreach ($this->make_abilities() as $ability) {
            $registrar->register($ability);
        }

        $this->assertCount(0, $registrar->all());
    }

    public function test_registrar_keeps_custom_code_abilities_when_pro(): void
    {
        Gate::set_pro_for_tests(true);

        $registrar = new Registrar();
        foreach ($this->make_abilities() as $ability) {
            $registrar->register($ability);
        }

        $names = array_map(fn ($a) => $a->name, $registrar->all());
        $this->assertContains('wpmcp/add-scoped-css', $names);
        $this->assertContains('wpmcp/add-custom-js', $names);
    }

    /**
     * Registering abilities is a pure catalog operation: it is replayed in
     * wp-admin to render the ability grid and against throwaway Registrars in
     * tests, so it must not acquire a permanent front-end output side effect.
     * Rendering is wired from Plugin::register_builder_runtime_hooks()
     * instead, which is also the only path that runs on a plain page view -
     * wp_abilities_api_init does not fire for a visitor.
     */
    public function test_registering_abilities_does_not_hook_front_end_output(): void
    {
        Gate::set_pro_for_tests(true);
        remove_action('wp_head', [Custom_Code_Renderer::class, 'print_css'], 101);
        remove_action('wp_footer', [Custom_Code_Renderer::class, 'print_js'], 101);

        $registrar = new Registrar();
        foreach ($this->make_abilities() as $ability) {
            $registrar->register($ability);
        }

        $this->assertFalse(has_action('wp_head', [Custom_Code_Renderer::class, 'print_css']));
        $this->assertFalse(has_action('wp_footer', [Custom_Code_Renderer::class, 'print_js']));
    }

    /**
     * The complement of the test above: the plugin's runtime hook wiring - the
     * path that DOES run on a front-end request - is what boots the renderer.
     *
     * Calls register_custom_code_runtime_hooks() rather than the whole
     * register_builder_runtime_hooks(). Replaying the latter in-process
     * re-runs `(new Index_Hooks())->register()` and
     * `(new Memory_Page())->register_hooks()` on FRESH instances, which
     * WordPress cannot recognise as duplicates of the ones the suite
     * bootstrap already added, so every test that ran afterwards carried
     * doubled save_post/deleted_post indexing hooks. That made unrelated
     * Search and Memory tests order-dependent for the sake of an assertion
     * about three lines of wiring.
     */
    public function test_runtime_hooks_boot_the_renderer(): void
    {
        Custom_Code_Renderer::reset_for_tests();
        remove_action('wp_head', [Custom_Code_Renderer::class, 'print_css'], 101);
        remove_action('wp_footer', [Custom_Code_Renderer::class, 'print_js'], 101);

        \WPMCP\Plugin::instance()->register_custom_code_runtime_hooks();

        $this->assertSame(101, has_action('wp_head', [Custom_Code_Renderer::class, 'print_css']));
        $this->assertSame(101, has_action('wp_footer', [Custom_Code_Renderer::class, 'print_js']));
        $this->assertSame(10, has_action('deleted_post', [Custom_Code_Store::class, 'delete_css']));
    }

    /**
     * The renderer branch is reached from the full runtime hook set too, not
     * only from the narrow method the test above calls. Asserted by proxy on
     * the source so this does not have to replay the hook set and leak
     * duplicated indexing hooks into the rest of the suite.
     */
    public function test_the_full_runtime_hook_set_reaches_the_custom_code_branch(): void
    {
        $method = new \ReflectionMethod(\WPMCP\Plugin::class, 'register_builder_runtime_hooks');
        $source = implode(
            '',
            array_slice(
                file((string) $method->getFileName()),
                $method->getStartLine() - 1,
                $method->getEndLine() - $method->getStartLine() + 1
            )
        );

        $this->assertStringContainsString('register_custom_code_runtime_hooks()', $source);
    }
}
