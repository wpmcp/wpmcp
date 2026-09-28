<?php

namespace WPMCP\Tests\Pro\Elementor;

use WPMCP\Tools\Elementor\Create_Popup;
use WPMCP\Tools\Elementor\Set_Popup_Settings;
use WPMCP\Tools\Elementor\List_Dynamic_Tags;
use WPMCP\Tools\Elementor\Set_Dynamic_Tag;

/**
 * Cluster 5: Elementor popups and dynamic tags.
 *
 * A popup is an `elementor_library` post of type popup; its trigger/display
 * config lives in `_elementor_page_settings`. A dynamic tag binds to an
 * element setting through the element's `settings['__dynamic__'][key]` value in
 * Elementor's `[elementor-tag ...]` shortcode format. Both are snapshot-backed.
 */
class PopupsDynamicTagsTest extends Structural_Harness
{
    // ---- create-popup -------------------------------------------------------

    public function test_create_popup_creates_popup_library_post(): void
    {
        $out = (new Create_Popup())->handle(['title' => 'Newsletter']);

        $this->assertIsArray($out);
        $pid = $out['popup_id'];
        $this->assertSame('elementor_library', get_post_type($pid));
        $this->assertSame('popup', get_post_meta($pid, '_elementor_template_type', true));
        $this->assertSame('Newsletter', get_the_title($pid));
    }

    public function test_create_popup_applies_initial_settings(): void
    {
        $out = (new Create_Popup())->handle([
            'title'    => 'Timed',
            'settings' => ['timing' => ['a1_show_after' => 5]],
        ]);

        $saved = get_post_meta($out['popup_id'], '_elementor_page_settings', true);
        $this->assertSame(['a1_show_after' => 5], $saved['timing']);
    }

    // ---- set-popup-settings -------------------------------------------------

    public function test_set_popup_settings_merges_snapshotted(): void
    {
        $pid = (new Create_Popup())->handle(['title' => 'P'])['popup_id'];

        $out = (new Set_Popup_Settings())->handle([
            'post_id'  => $pid,
            'settings' => ['triggers' => ['on_load' => 'yes']],
        ]);

        $this->assertIsArray($out);
        $this->assertArrayHasKey('operation_id', $out);
        $saved = get_post_meta($pid, '_elementor_page_settings', true);
        $this->assertSame(['on_load' => 'yes'], $saved['triggers']);
    }

    public function test_set_popup_settings_rejects_non_popup(): void
    {
        $page = $this->make_page();
        $out  = (new Set_Popup_Settings())->handle([
            'post_id'  => $page,
            'settings' => ['triggers' => []],
        ]);
        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('not_a_popup', $out->get_error_code());
    }

    // ---- list-dynamic-tags --------------------------------------------------

    public function test_list_dynamic_tags_returns_structure(): void
    {
        $out = (new List_Dynamic_Tags())->handle([]);

        $this->assertIsArray($out);
        $this->assertArrayHasKey('tags', $out);
        $this->assertIsArray($out['tags']);
        $this->assertArrayHasKey('count', $out);
        $this->assertSame(count($out['tags']), $out['count']);
    }

    // ---- set-dynamic-tag ----------------------------------------------------

    public function test_set_dynamic_tag_binds_to_element_setting(): void
    {
        $post_id = $this->make_page();

        $out = (new Set_Dynamic_Tag())->handle([
            'post_id'       => $post_id,
            'element_id'    => 'wid0001',
            'setting_key'   => 'title',
            'tag_name'      => 'post-title',
            'expected_hash' => $this->data_hash($post_id),
        ]);

        $this->assertIsArray($out);
        $this->assertArrayHasKey('operation_id', $out);
        $widget  = $this->find_in($this->tree($post_id), 'wid0001');
        $encoded = $widget['settings']['__dynamic__']['title'];
        $this->assertStringContainsString('[elementor-tag', $encoded);
        $this->assertStringContainsString('name="post-title"', $encoded);
    }

    public function test_set_dynamic_tag_preserves_other_dynamic_bindings(): void
    {
        $post_id = $this->make_page();

        // First binding on the heading.
        (new Set_Dynamic_Tag())->handle([
            'post_id'       => $post_id,
            'element_id'    => 'wid0001',
            'setting_key'   => 'link',
            'tag_name'      => 'post-url',
            'expected_hash' => $this->data_hash($post_id),
        ]);

        // A second binding on a different setting must not drop the first.
        (new Set_Dynamic_Tag())->handle([
            'post_id'       => $post_id,
            'element_id'    => 'wid0001',
            'setting_key'   => 'title',
            'tag_name'      => 'post-title',
            'expected_hash' => $this->data_hash($post_id),
        ]);

        $dynamic = $this->find_in($this->tree($post_id), 'wid0001')['settings']['__dynamic__'];
        $this->assertArrayHasKey('link', $dynamic);
        $this->assertArrayHasKey('title', $dynamic);
        $this->assertStringContainsString('name="post-url"', $dynamic['link']);
        $this->assertStringContainsString('name="post-title"', $dynamic['title']);
    }

    public function test_set_dynamic_tag_rejects_missing_element(): void
    {
        $post_id = $this->make_page();
        $out     = (new Set_Dynamic_Tag())->handle([
            'post_id'       => $post_id,
            'element_id'    => 'ghost99',
            'setting_key'   => 'title',
            'tag_name'      => 'post-title',
            'expected_hash' => $this->data_hash($post_id),
        ]);
        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('element_not_found', $out->get_error_code());
    }

    public function test_set_dynamic_tag_requires_params(): void
    {
        $post_id = $this->make_page();
        $out     = (new Set_Dynamic_Tag())->handle([
            'post_id'       => $post_id,
            'element_id'    => 'wid0001',
            'expected_hash' => $this->data_hash($post_id),
        ]);
        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('missing_params', $out->get_error_code());
    }
}
