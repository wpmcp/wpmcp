<?php

namespace WPMCP\Tests\Pro\Builders;

use WPMCP\Pro\Gate;
use WPMCP\Safety\{Rollback_Service, Snapshot_Store};
use WPMCP\Tools\Builders\{Get_Builder_Content, Thrive_Content, Thrive_Css, Update_Builder_Content};

require_once __DIR__ . '/../../support/thrive-user-template-double.php';

/**
 * Issue #640: element-level Thrive Architect editing on the builder
 * pair, against rows stored the way Thrive Architect 11.3 stores them
 * (see Thrive_Page and Thrive_Css for the source each shape comes from):
 * the read's hash, plugin version and revisions, hash-pinned writes,
 * publish, restore from a revision, user templates (listed, inserted
 * with renewed data-css ids and their rules, saved from an element), the
 * design system and the catalog refusal. Every write is undone here.
 */
class ThriveElementEditingTest extends \WP_UnitTestCase
{
    /** A page stylesheet as the editor's get_stylesheet_rules() saves it. */
    public const CSS = '@import url("//fonts.googleapis.com/css?family=Roboto:400");'
        . '[data-css="tve-u-18a0c1f0a03"]{margin-bottom:0px !important;}'
        . '@media (min-width: 300px){[data-css="tve-u-18a0c1f0a07"] .tcb-button-link{background-color:rgb(1, 2, 3) !important;}[data-css="tve-u-18a0c1f0a04"]{color:red;}}'
        . '@media (max-width: 767px){[data-css="tve-u-18a0c1f0a07"]{width:100%;}}';

    /** A button template as "Save as template" stores it (collect_head_css() shape). */
    private const TEMPLATE_MARKUP = '<div class="thrv_wrapper thrv-button" data-css="tve-u-1700000000a">' . "\n"
        . '<a href="#" class="tcb-button-link"><span class="tcb-button-texts"><span class="tcb-button-text thrv-inline-text" data-css="tve-u-1700000000b">Buy</span></span></a>' . "\n"
        . '</div>';

    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
        Snapshot_Store::install();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    protected function tearDown(): void
    {
        Gate::set_pro_for_tests(null);
        remove_all_filters('wpmcp_thrive_active');
        delete_option('tve_user_templates');
        parent::tearDown();
    }

    private function thrive_page(string $layout = ThriveHtmlTest::FIXTURE, string $status = 'publish'): int
    {
        $post_id = self::factory()->post->create(['post_type' => 'page', 'post_status' => $status, 'post_content' => 'Welcome']);
        $rows    = [
            'tcb2_ready'             => '1',
            'tcb_editor_enabled'     => '1',
            'tve_updated_post'       => $layout,
            'tve_content_more_found' => '',
            'tve_custom_css'         => self::CSS,
            'tve_user_custom_css'    => '',
            'tve_globals'            => ['font_cls' => []],
        ];
        foreach ($rows as $key => $value) {
            update_post_meta($post_id, $key, is_string($value) ? wp_slash($value) : $value);
        }

        return $post_id;
    }

    private function update(int $post_id, array $args)
    {
        if (! array_key_exists('expected_hash', $args)) {
            $args['expected_hash'] = hash('sha256', Thrive_Content::get_content($post_id));
        }

        return (new Update_Builder_Content())->handle(['post_id' => $post_id, 'builder' => 'thrive'] + $args);
    }

    private function state(int $post_id): array
    {
        clean_post_cache($post_id);
        return [get_post($post_id)->post_status, get_post($post_id)->post_content, get_post_meta($post_id)];
    }

    /** A revision the way Thrive's save_post hook leaves it. */
    private function revision(int $post_id, string $layout, string $css, string $landing_page = ''): int
    {
        $id   = (int) _wp_put_post_revision(get_post($post_id));
        $rows = [
            'tve_landing_page'        => $landing_page,
            'tve_updated_post'        => $layout,
            'tve_custom_css'          => $css,
            'tve_content_before_more' => '',
            'tve_content_more_found'  => '',
            'tve_user_custom_css'     => '',
            'tve_globals'             => ['font_cls' => []],
        ];
        foreach ($rows as $key => $value) {
            add_metadata('post', $id, 'tve_revision_' . $key, is_string($value) ? wp_slash($value) : $value);
        }

        return $id;
    }

    private function template_post(): int
    {
        return self::factory()->post->create([
            'post_type'   => 'tve_user_template',
            'post_status' => 'publish',
            'meta_input'  => [
                'template_name'      => 'Buy%20button',
                'template_content'   => self::TEMPLATE_MARKUP,
                'template_type'      => 'button',
                'template_css'       => '',
                'template_media_css' => [
                    0                      => '',
                    1                      => '',
                    '(min-width: 300px)'   => '[data-css="tve-u-1700000000a"] .tcb-button-link{background-color:rgb(9, 9, 9) !important;}',
                    '(max-width: 767px)'   => '[data-css="tve-u-1700000000b"]{font-size:14px !important;}',
                    '(max-width: 1023px)'  => '',
                ],
            ],
        ]);
    }

    // -------------------------------------------------------------- read

    public function test_read_returns_hash_version_and_only_thrive_revisions(): void
    {
        $post_id = $this->thrive_page();
        $thrive  = $this->revision($post_id, ThriveHtmlTest::TEXT, '');
        _wp_put_post_revision(get_post($post_id)); // a plain revision, no layout rows

        $out = (new Get_Builder_Content())->handle(['post_id' => $post_id]);

        $this->assertSame(hash('sha256', ThriveHtmlTest::FIXTURE), $out['hash']);
        $this->assertNull($out['plugin_version']);
        $this->assertSame([$thrive], array_column($out['revisions'], 'id'));
        $this->assertSame(hash('sha256', ThriveHtmlTest::TEXT), $out['revisions'][0]['hash']);
    }

    // ------------------------------------------------------------- hash

    public function test_writes_need_the_current_hash_and_a_blank_layout_needs_none(): void
    {
        $post_id = $this->thrive_page();
        $before  = $this->state($post_id);

        $missing = (new Update_Builder_Content())->handle(['post_id' => $post_id, 'builder' => 'thrive', 'operation' => 'remove', 'path' => '1']);
        $this->assertSame('missing_expected_hash', $missing->get_error_code());
        foreach (['remove', 'publish'] as $operation) {
            $stale = $this->update($post_id, ['operation' => $operation, 'path' => '1', 'expected_hash' => hash('sha256', 'old')]);
            $this->assertSame('stale_expected_hash', $stale->get_error_code());
        }
        $this->assertSame($before, $this->state($post_id));

        $blank = $this->thrive_page('');
        $out   = (new Update_Builder_Content())->handle(['post_id' => $blank, 'builder' => 'thrive', 'content' => ThriveHtmlTest::TEXT]);
        $this->assertSame(hash('sha256', ThriveHtmlTest::TEXT), $out['hash']);
    }

    public function test_every_element_write_returns_the_new_hash(): void
    {
        $post_id = $this->thrive_page();

        $out = $this->update($post_id, ['operation' => 'update', 'path' => '1.1', 'text' => '<p>New</p>']);

        $this->assertSame(hash('sha256', Thrive_Content::get_content($post_id)), $out['hash']);
        $this->assertSame($out['hash'], (new Get_Builder_Content())->handle(['post_id' => $post_id])['hash']);
    }

    // ---------------------------------------------------- publish, restore

    public function test_publish_sets_the_status_and_rolls_back(): void
    {
        $post_id = $this->thrive_page(ThriveHtmlTest::FIXTURE, 'draft');
        $before  = $this->state($post_id);

        $out = $this->update($post_id, ['operation' => 'publish']);
        $this->assertSame('publish', get_post_status($post_id));
        $this->assertSame('already_published', $this->update($post_id, ['operation' => 'publish'])->get_error_code());

        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertSame($before, $this->state($post_id));
    }

    public function test_restore_puts_back_a_revision_layout_and_css_and_rolls_back(): void
    {
        $post_id  = $this->thrive_page();
        $revision = $this->revision($post_id, ThriveHtmlTest::TEXT, '[data-css="tve-u-18a0c1f0a03"]{color:blue;}');
        update_post_meta($post_id, '_tve_lightspeed_version', '1');
        $before = $this->state($post_id);

        $out = $this->update($post_id, ['operation' => 'restore', 'path' => (string) $revision]);

        $this->assertSame(ThriveHtmlTest::TEXT, Thrive_Content::get_content($post_id));
        $this->assertSame('[data-css="tve-u-18a0c1f0a03"]{color:blue;}', Thrive_Content::get_css($post_id));
        $this->assertSame('0', get_post_meta($post_id, '_tve_lightspeed_version', true));
        $this->assertSame(hash('sha256', ThriveHtmlTest::TEXT), $out['hash']);

        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertSame($before, $this->state($post_id));
    }

    public function test_restore_refuses_other_pages_plain_and_other_landing_page_revisions(): void
    {
        $post_id = $this->thrive_page();
        $other   = $this->thrive_page();
        $before  = $this->state($post_id);
        $cases   = [
            (string) $this->revision($other, ThriveHtmlTest::TEXT, ''),
            (string) _wp_put_post_revision(get_post($post_id)),
            (string) $this->revision($post_id, ThriveHtmlTest::TEXT, '', 'tcb2-blank-page'),
            'abc',
        ];
        foreach ($cases as $revision) {
            $out = $this->update($post_id, ['operation' => 'restore', 'path' => $revision]);
            $this->assertSame('invalid_thrive_request', $out->get_error_code(), $revision);
        }
        $this->assertSame($before, $this->state($post_id));
    }

    // -------------------------------------------------------- templates

    public function test_templates_scope_lists_post_and_legacy_templates_with_trees(): void
    {
        $id = $this->template_post();
        update_option('tve_user_templates', [['name' => 'Old', 'content' => ThriveHtmlTest::TEXT, 'type' => 'text', 'css' => '', 'media_css' => []]]);

        $out = (new Get_Builder_Content())->handle(['scope' => 'templates', 'builder' => 'thrive']);

        $this->assertSame('thrive', $out['builder']);
        $this->assertSame([(string) $id, 'o_0'], array_column($out['templates'], 'id'));
        $this->assertSame('Buy button', $out['templates'][0]['name']);
        $this->assertSame('button', $out['templates'][0]['tree'][0]['type']);
        $this->assertTrue($out['templates'][1]['legacy']);
        $this->assertSame('text', $out['templates'][1]['tree'][0]['type']);
    }

    public function test_add_template_renews_ids_adds_its_rules_and_rolls_back(): void
    {
        $post_id = $this->thrive_page();
        $id      = $this->template_post();
        $before  = $this->state($post_id);

        $out = $this->update($post_id, ['operation' => 'add', 'to' => '1.0', 'element' => ['template' => (string) $id]]);
        $this->assertInstanceOf(\WP_Error::class, $out, 'An element with no slot takes no child');

        $out = $this->update($post_id, ['operation' => 'add', 'to' => '0', 'index' => 0, 'element' => ['template' => $id]]);

        $this->assertSame('0.0', $out['path']);
        $layout = Thrive_Content::get_content($post_id);
        $css    = Thrive_Content::get_css($post_id);
        $this->assertStringNotContainsString('tve-u-1700000000a', $layout);
        $this->assertStringNotContainsString('tve-u-1700000000a', $css);
        $new = (new Get_Builder_Content())->handle(['post_id' => $post_id])['tree'][0]['children'][0];
        $this->assertSame('button', $new['type']);
        $ids = $new['attrs']['data-css'];
        $this->assertMatchesRegularExpression('/^tve-u-[0-9a-f]{11}$/', $ids);
        $this->assertStringStartsWith(self::CSS, $css);
        $this->assertStringContainsString('@media (min-width: 300px){[data-css="' . $ids . '"] .tcb-button-link{background-color:rgb(9, 9, 9) !important;}}', $css);
        $this->assertStringContainsString('@media (max-width: 767px){[data-css="tve-u-', $css);

        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertSame($before, $this->state($post_id));
    }

    public function test_the_same_template_twice_gets_distinct_ids_and_unknown_ids_are_refused(): void
    {
        $post_id = $this->thrive_page();
        $id      = (string) $this->template_post();

        $this->update($post_id, ['operation' => 'add', 'to' => '', 'element' => ['template' => $id]]);
        $this->update($post_id, ['operation' => 'add', 'to' => '', 'element' => ['template' => $id]]);

        $tree = (new Get_Builder_Content())->handle(['post_id' => $post_id])['tree'];
        $this->assertNotSame($tree[2]['attrs']['data-css'], $tree[3]['attrs']['data-css']);

        $page = (string) $post_id;
        foreach (['999999', $page, 'o_4', 'x'] as $bad) {
            $out = $this->update($post_id, ['operation' => 'add', 'to' => '', 'element' => ['template' => $bad]]);
            $this->assertSame('invalid_thrive_request', $out->get_error_code(), $bad);
        }
    }

    public function test_save_template_needs_thrive_then_saves_the_element_with_its_rules_and_rolls_back(): void
    {
        $post_id = $this->thrive_page();
        $args    = ['operation' => 'save_template', 'path' => '0.2', 'element' => ['title' => 'Join button']];

        $missing = $this->update($post_id, $args);
        $this->assertSame('plugin_missing', $missing->get_error_code());
        $this->assertStringContainsString('Install and activate Thrive Architect', $missing->get_error_message());

        add_filter('wpmcp_thrive_active', '__return_true');
        $this->assertSame('invalid_thrive_request', $this->update($post_id, ['element' => []] + $args)->get_error_code());

        $out = $this->update($post_id, $args);

        $template = get_post($out['template_id']);
        $this->assertSame('tve_user_template', $template->post_type);
        $this->assertSame('Join button', get_post_meta($template->ID, 'template_name', true));
        $this->assertSame('button', get_post_meta($template->ID, 'template_type', true));
        $this->assertStringStartsWith('<div class="thrv_wrapper thrv-button" data-css="tve-u-18a0c1f0a07">', get_post_meta($template->ID, 'template_content', true));
        $media = get_post_meta($template->ID, 'template_media_css', true);
        $this->assertSame('[data-css="tve-u-18a0c1f0a07"] .tcb-button-link{background-color:rgb(1, 2, 3) !important;}', $media['(min-width: 300px)']);
        $this->assertSame('[data-css="tve-u-18a0c1f0a07"]{width:100%;}', $media['(max-width: 767px)']);

        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertNotSame('publish', get_post_status($template->ID));
    }

    // ------------------------------------------------- design and catalog

    public function test_design_system_reads_global_colors_gradients_and_styles(): void
    {
        update_option('thrv_global_colours', [['id' => 1, 'color' => 'rgb(1, 2, 3)', 'name' => 'Brand']]);
        update_option('tve_global_button_styles', ['tve-global-button-1' => ['name' => 'Primary']]);

        $out = (new Get_Builder_Content())->handle(['scope' => 'design_system', 'builder' => 'thrive']);

        $this->assertSame('Brand', $out['colors'][0]['name']);
        $this->assertSame([], $out['gradients']);
        $this->assertSame('Primary', $out['global_styles']['button']['tve-global-button-1']['name']);
        $this->assertSame([], $out['global_styles']['text']);
    }

    public function test_catalog_without_thrive_names_what_to_install(): void
    {
        $out = (new Get_Builder_Content())->handle(['scope' => 'catalog', 'builder' => 'thrive']);

        $this->assertSame('builder_not_loaded', $out->get_error_code());
        $this->assertStringContainsString('Thrive Architect', $out->get_error_message());
    }

    // -------------------------------------------------------------- css

    public function test_css_rules_for_ids_follow_the_template_shape(): void
    {
        $rules = Thrive_Css::rules_for(self::CSS, ['tve-u-18a0c1f0a03', 'tve-u-18a0c1f0a04']);

        $this->assertSame('[data-css="tve-u-18a0c1f0a03"]{margin-bottom:0px !important;}', $rules[1]);
        $this->assertSame('[data-css="tve-u-18a0c1f0a04"]{color:red;}', $rules['(min-width: 300px)']);
        $this->assertSame('', $rules['(max-width: 767px)']);
        $this->assertSame(['@import url("//fonts.googleapis.com/css?family=Roboto:400");'], Thrive_Css::split(self::CSS)['imports']);
    }
}
