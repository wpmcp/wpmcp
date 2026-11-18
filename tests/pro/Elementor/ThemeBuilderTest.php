<?php

namespace WPMCP\Tests\Pro\Elementor;

use WPMCP\Tools\Elementor\Create_Theme_Template;
use WPMCP\Tools\Elementor\Set_Template_Conditions;
use WPMCP\Tools\Elementor\Get_Theme_Template;
use WPMCP\Tools\Elementor\List_Theme_Templates;
use WPMCP\Tools\Elementor\Delete_Theme_Template;

/**
 * Cluster 3: the Elementor theme builder surface.
 *
 * Theme templates are `elementor_library` posts whose `_elementor_template_type`
 * is a theme LOCATION (header, footer, single, archive, ...). Their display
 * rules live in `_elementor_conditions` meta as Elementor's slash strings
 * ("include/general", "include/singular/post"). Setting conditions goes through
 * Elementor Pro's conditions manager when present, and otherwise writes the
 * same meta directly, so it is testable without Elementor Pro installed.
 */
class ThemeBuilderTest extends Structural_Harness
{
    private function make_theme_template(string $type = 'header', array $conditions = []): int
    {
        $id = self::factory()->post->create(['post_type' => 'elementor_library']);
        update_post_meta($id, '_elementor_edit_mode', 'builder');
        update_post_meta($id, '_elementor_template_type', $type);
        if ($conditions) {
            update_post_meta($id, '_elementor_conditions', $conditions);
        }
        return $id;
    }

    // ---- create-theme-template ----------------------------------------------

    public function test_create_theme_template_creates_header(): void
    {
        $out = (new Create_Theme_Template())->handle([
            'title'         => 'Site Header',
            'template_type' => 'header',
        ]);

        $this->assertIsArray($out);
        $tid = $out['template_id'];
        $this->assertSame('elementor_library', get_post_type($tid));
        $this->assertSame('header', get_post_meta($tid, '_elementor_template_type', true));
        $this->assertSame('Site Header', get_the_title($tid));
    }

    public function test_create_theme_template_rejects_non_theme_type(): void
    {
        $out = (new Create_Theme_Template())->handle([
            'title'         => 'Nope',
            'template_type' => 'section',
        ]);
        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('invalid_theme_type', $out->get_error_code());
    }

    public function test_create_theme_template_sets_conditions_when_given(): void
    {
        $out = (new Create_Theme_Template())->handle([
            'title'         => 'Global Header',
            'template_type' => 'header',
            'conditions'    => ['include/general'],
        ]);

        $this->assertContains('include/general', get_post_meta($out['template_id'], '_elementor_conditions', true));
    }

    // ---- set-template-conditions --------------------------------------------

    public function test_set_conditions_writes_normalized_meta_snapshotted(): void
    {
        $tid = $this->make_theme_template('footer');

        $out = (new Set_Template_Conditions())->handle([
            'post_id'    => $tid,
            'conditions' => [['include', 'singular', 'post']],
        ]);

        $this->assertIsArray($out);
        $this->assertArrayHasKey('operation_id', $out);
        $this->assertContains('include/singular/post', get_post_meta($tid, '_elementor_conditions', true));
    }

    public function test_set_conditions_accepts_slash_strings(): void
    {
        $tid = $this->make_theme_template('header');

        (new Set_Template_Conditions())->handle([
            'post_id'    => $tid,
            'conditions' => ['include/general', 'exclude/singular/page'],
        ]);

        $saved = get_post_meta($tid, '_elementor_conditions', true);
        $this->assertContains('include/general', $saved);
        $this->assertContains('exclude/singular/page', $saved);
    }

    public function test_set_conditions_rejects_non_template(): void
    {
        $page = $this->make_page();
        $out  = (new Set_Template_Conditions())->handle([
            'post_id'    => $page,
            'conditions' => ['include/general'],
        ]);
        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('not_a_template', $out->get_error_code());
    }

    // ---- get-theme-template -------------------------------------------------

    public function test_get_theme_template_returns_type_and_conditions(): void
    {
        $tid = $this->make_theme_template('single', ['include/singular/post']);

        $out = (new Get_Theme_Template())->handle(['post_id' => $tid]);

        $this->assertSame('single', $out['template_type']);
        $this->assertContains('include/singular/post', $out['conditions']);
    }

    // ---- list-theme-templates -----------------------------------------------

    public function test_list_theme_templates_filters_to_theme_types(): void
    {
        $header = $this->make_theme_template('header');
        $footer = $this->make_theme_template('footer');
        // A non-theme library template (a saved section) must NOT appear.
        $section = self::factory()->post->create(['post_type' => 'elementor_library']);
        update_post_meta($section, '_elementor_template_type', 'section');

        $out = (new List_Theme_Templates())->handle([]);

        $ids = array_column($out['templates'], 'template_id');
        $this->assertContains($header, $ids);
        $this->assertContains($footer, $ids);
        $this->assertNotContains($section, $ids);
    }

    public function test_list_theme_templates_can_filter_by_type(): void
    {
        $header = $this->make_theme_template('header');
        $footer = $this->make_theme_template('footer');

        $out = (new List_Theme_Templates())->handle(['template_type' => 'header']);

        $ids = array_column($out['templates'], 'template_id');
        $this->assertContains($header, $ids);
        $this->assertNotContains($footer, $ids);
    }

    // ---- delete-theme-template ----------------------------------------------

    public function test_delete_theme_template_trashes(): void
    {
        $tid = $this->make_theme_template('archive');

        $out = (new Delete_Theme_Template())->handle(['post_id' => $tid]);

        $this->assertSame('trashed', $out['deleted']);
        $this->assertSame('trash', get_post_status($tid));
    }

    public function test_delete_theme_template_rejects_non_template(): void
    {
        $page = $this->make_page();
        $out  = (new Delete_Theme_Template())->handle(['post_id' => $page]);
        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('not_a_template', $out->get_error_code());
    }
    // ---- theme header builder clash (issue #870) -----------------------------

    private function use_parent_theme(string $template): void
    {
        add_filter('template', static fn () => $template);
    }

    public function test_create_header_warns_when_divi_is_the_active_theme(): void
    {
        $this->use_parent_theme('Divi');

        $out = (new Create_Theme_Template())->handle([
            'title'         => 'Site Header',
            'template_type' => 'header',
            'conditions'    => ['include/general'],
        ]);

        $this->assertIsArray($out);
        $this->assertArrayHasKey('warnings', $out);
        $this->assertCount(1, $out['warnings']);
        $this->assertStringContainsString('Divi', $out['warnings'][0]);
        $this->assertStringContainsString('500', $out['warnings'][0]);
        // A warning, not a refusal: the template exists.
        $this->assertSame('header', get_post_meta($out['template_id'], '_elementor_template_type', true));
    }

    public function test_create_footer_warns_under_extra(): void
    {
        $this->use_parent_theme('Extra');

        $out = (new Create_Theme_Template())->handle([
            'title'         => 'Site Footer',
            'template_type' => 'footer',
        ]);

        $this->assertStringContainsString('Extra', $out['warnings'][0] ?? '');
    }

    public function test_create_non_header_template_does_not_warn_under_divi(): void
    {
        $this->use_parent_theme('Divi');

        $out = (new Create_Theme_Template())->handle([
            'title'         => 'Single Post',
            'template_type' => 'single',
        ]);

        $this->assertArrayNotHasKey('warnings', $out);
    }

    public function test_create_header_does_not_warn_without_a_theme_header_builder(): void
    {
        $this->use_parent_theme('generatepress');

        $out = (new Create_Theme_Template())->handle([
            'title'         => 'Site Header',
            'template_type' => 'header',
            'conditions'    => ['include/general'],
        ]);

        $this->assertArrayNotHasKey('warnings', $out);
    }

    public function test_set_conditions_warns_when_header_goes_site_wide_under_divi(): void
    {
        $this->use_parent_theme('Divi');
        $tid = $this->make_theme_template('header');

        $out = (new Set_Template_Conditions())->handle([
            'post_id'    => $tid,
            'conditions' => ['include/general'],
        ]);

        $this->assertIsArray($out);
        $this->assertStringContainsString('Divi', $out['warnings'][0] ?? '');
        $this->assertContains('include/general', get_post_meta($tid, '_elementor_conditions', true));
    }

    public function test_set_conditions_does_not_warn_for_narrow_header_conditions_under_divi(): void
    {
        $this->use_parent_theme('Divi');
        $tid = $this->make_theme_template('header');

        $out = (new Set_Template_Conditions())->handle([
            'post_id'    => $tid,
            'conditions' => ['include/singular/page'],
        ]);

        $this->assertArrayNotHasKey('warnings', $out);
    }

    public function test_set_conditions_does_not_warn_without_a_theme_header_builder(): void
    {
        $this->use_parent_theme('generatepress');
        $tid = $this->make_theme_template('footer');

        $out = (new Set_Template_Conditions())->handle([
            'post_id'    => $tid,
            'conditions' => ['include/general'],
        ]);

        $this->assertArrayNotHasKey('warnings', $out);
    }
}
