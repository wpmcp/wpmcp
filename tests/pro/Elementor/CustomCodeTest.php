<?php

namespace WPMCP\Tests\Pro\Elementor;

use WPMCP\Pro\Gate;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Elementor\Add_Custom_Css;
use WPMCP\Tools\Elementor\Get_Custom_Css;
use WPMCP\Tools\Elementor\Create_Code_Snippet;
use WPMCP\Tools\Elementor\List_Code_Snippets;
use WPMCP\Tools\Elementor\Delete_Code_Snippet;

/**
 * Cluster 6: custom code.
 *
 * add-custom-css / get-custom-css operate on WordPress core Additional CSS
 * (wp_update_custom_css_post), so they work on ANY site, not just Elementor Pro
 * (rather than gating custom CSS behind Pro). Code snippets
 * are stored as `elementor_snippet` posts (Elementor Pro's Custom Code storage);
 * management works everywhere, rendering needs Pro.
 */
class CustomCodeTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
        Snapshot_Store::install();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        // Elementor Pro registers this; simulate it so the CPT tools are testable.
        if (! post_type_exists('elementor_snippet')) {
            register_post_type('elementor_snippet', ['public' => false, 'show_ui' => false]);
        }
    }

    protected function tearDown(): void
    {
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    // ---- add-custom-css / get-custom-css ------------------------------------

    public function test_add_custom_css_appends_and_reads_back(): void
    {
        $out = (new Add_Custom_Css())->handle(['css' => '.a{color:red}']);
        $this->assertIsArray($out);
        $this->assertStringContainsString('.a{color:red}', wp_get_custom_css());

        (new Add_Custom_Css())->handle(['css' => '.b{color:blue}']);
        $css = wp_get_custom_css();
        $this->assertStringContainsString('.a{color:red}', $css);
        $this->assertStringContainsString('.b{color:blue}', $css);

        $read = (new Get_Custom_Css())->handle([]);
        $this->assertStringContainsString('.b{color:blue}', $read['css']);
    }

    public function test_add_custom_css_replace_overwrites(): void
    {
        (new Add_Custom_Css())->handle(['css' => '.old{}']);
        (new Add_Custom_Css())->handle(['css' => '.new{}', 'replace' => true]);

        $css = wp_get_custom_css();
        $this->assertStringContainsString('.new{}', $css);
        $this->assertStringNotContainsString('.old{}', $css);
    }

    public function test_add_custom_css_requires_css(): void
    {
        $out = (new Add_Custom_Css())->handle([]);
        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('missing_css', $out->get_error_code());
    }

    public function test_add_custom_css_returns_operation_id(): void
    {
        // Seed once so the custom_css post exists, then a second write snapshots it.
        (new Add_Custom_Css())->handle(['css' => '.a{}']);
        $out = (new Add_Custom_Css())->handle(['css' => '.b{}']);
        $this->assertArrayHasKey('operation_id', $out);
    }

    // ---- create-code-snippet / list / delete --------------------------------

    public function test_create_code_snippet_stores_elementor_snippet(): void
    {
        $out = (new Create_Code_Snippet())->handle([
            'title'    => 'Analytics',
            'code'     => 'console.log("hi")',
            'location' => 'wp_body_open',
            'priority' => 5,
        ]);

        $this->assertIsArray($out);
        $sid = $out['snippet_id'];
        $this->assertSame('elementor_snippet', get_post_type($sid));
        $this->assertSame('console.log("hi")', get_post_meta($sid, '_elementor_code', true));
        $this->assertSame('wp_body_open', get_post_meta($sid, '_elementor_location', true));
        $this->assertSame(5, (int) get_post_meta($sid, '_elementor_priority', true));
    }

    public function test_create_code_snippet_requires_code(): void
    {
        $out = (new Create_Code_Snippet())->handle(['title' => 'x']);
        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('missing_code', $out->get_error_code());
    }

    public function test_list_code_snippets_returns_created(): void
    {
        $a = (new Create_Code_Snippet())->handle(['title' => 'One', 'code' => 'a'])['snippet_id'];
        $b = (new Create_Code_Snippet())->handle(['title' => 'Two', 'code' => 'b'])['snippet_id'];

        $out = (new List_Code_Snippets())->handle([]);
        $ids = array_column($out['snippets'], 'snippet_id');
        $this->assertContains($a, $ids);
        $this->assertContains($b, $ids);
    }

    public function test_delete_code_snippet_trashes(): void
    {
        $sid = (new Create_Code_Snippet())->handle(['title' => 'Gone', 'code' => 'x'])['snippet_id'];

        $out = (new Delete_Code_Snippet())->handle(['snippet_id' => $sid]);
        $this->assertSame('trashed', $out['deleted']);
        $this->assertSame('trash', get_post_status($sid));
    }

    public function test_delete_code_snippet_rejects_non_snippet(): void
    {
        $page = self::factory()->post->create(['post_type' => 'page']);
        $out  = (new Delete_Code_Snippet())->handle(['snippet_id' => $page]);
        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('not_a_snippet', $out->get_error_code());
    }
}
