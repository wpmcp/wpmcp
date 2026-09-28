<?php

namespace WPMCP\Tests\Pro\Elementor;

use WPMCP\Tools\Elementor\Update_Page_Settings;
use WPMCP\Tools\Rollback_Operation;

/**
 * update-page-settings (issue #58): non-destructive merge into the page's
 * `_elementor_page_settings`, hash-guarded against the settings meta the
 * same way element mutations are guarded against `_elementor_data`. Post
 * fields (title, status, ...) are refused - those belong to the post
 * tools, not the Elementor settings surface.
 */
class UpdatePageSettingsTest extends Structural_Harness
{
    public function test_merges_page_settings_non_destructively(): void
    {
        $post_id = $this->make_page();
        update_post_meta($post_id, '_elementor_page_settings', ['hide_title' => 'yes']);

        $out = (new Update_Page_Settings())->handle([
            'post_id'       => $post_id,
            'expected_hash' => $this->settings_hash($post_id),
            'settings'      => ['padding' => ['top' => '0'], 'hide_title' => 'no'],
        ]);

        $this->assertIsArray($out);
        $this->assertArrayHasKey('operation_id', $out);

        $stored = get_post_meta($post_id, '_elementor_page_settings', true);
        $this->assertSame('no', $stored['hide_title'], 'Given keys are overwritten.');
        $this->assertSame(['top' => '0'], $stored['padding'], 'New keys are added.');
        $this->assertSame($this->settings_hash($post_id), $out['settings_hash']);
    }

    public function test_works_when_no_settings_exist_yet(): void
    {
        $post_id = $this->make_page();

        $out = (new Update_Page_Settings())->handle([
            'post_id'       => $post_id,
            'expected_hash' => $this->settings_hash($post_id),
            'settings'      => ['hide_title' => 'yes'],
        ]);

        $this->assertIsArray($out);
        $stored = get_post_meta($post_id, '_elementor_page_settings', true);
        $this->assertSame('yes', $stored['hide_title']);
    }

    public function test_refuses_post_field_keys(): void
    {
        $post_id = $this->make_page();

        $out = (new Update_Page_Settings())->handle([
            'post_id'       => $post_id,
            'expected_hash' => $this->settings_hash($post_id),
            'settings'      => ['post_title' => 'Hijacked', 'hide_title' => 'yes'],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('unsupported_setting', $out->get_error_code());
        $this->assertStringContainsString('post_title', $out->get_error_message());
        $this->assertSame('', (string) get_post_meta($post_id, '_elementor_page_settings', true));
    }

    public function test_requires_expected_hash_and_refuses_stale(): void
    {
        $post_id = $this->make_page();
        update_post_meta($post_id, '_elementor_page_settings', ['hide_title' => 'yes']);

        $missing = (new Update_Page_Settings())->handle([
            'post_id'  => $post_id,
            'settings' => ['hide_title' => 'no'],
        ]);
        $this->assertInstanceOf(\WP_Error::class, $missing);
        $this->assertSame('missing_expected_hash', $missing->get_error_code());

        $stale = (new Update_Page_Settings())->handle([
            'post_id'       => $post_id,
            'expected_hash' => hash('sha256', 'stale'),
            'settings'      => ['hide_title' => 'no'],
        ]);
        $this->assertInstanceOf(\WP_Error::class, $stale);
        $this->assertSame('stale_expected_hash', $stale->get_error_code());

        $stored = get_post_meta($post_id, '_elementor_page_settings', true);
        $this->assertSame('yes', $stored['hide_title'], 'Refusals must not write.');
    }

    public function test_refuses_empty_settings(): void
    {
        $post_id = $this->make_page();

        $out = (new Update_Page_Settings())->handle([
            'post_id'       => $post_id,
            'expected_hash' => $this->settings_hash($post_id),
            'settings'      => [],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('missing_settings', $out->get_error_code());
    }

    public function test_clears_generated_css_for_the_page(): void
    {
        $post_id = $this->make_page();
        update_post_meta($post_id, '_elementor_css', ['status' => 'stale-probe']);

        $out = (new Update_Page_Settings())->handle([
            'post_id'       => $post_id,
            'expected_hash' => $this->settings_hash($post_id),
            'settings'      => ['hide_title' => 'yes'],
        ]);

        $this->assertIsArray($out);
        $this->assertEmpty(
            get_post_meta($post_id, '_elementor_css', true),
            'The page\'s generated-CSS cache must be invalidated by a settings write.'
        );
    }

    public function test_rollback_operation_restores_previous_settings(): void
    {
        $post_id = $this->make_page();
        update_post_meta($post_id, '_elementor_page_settings', ['hide_title' => 'yes']);
        $before = get_post_meta($post_id, '_elementor_page_settings', true);

        $out = (new Update_Page_Settings())->handle([
            'post_id'       => $post_id,
            'expected_hash' => $this->settings_hash($post_id),
            'settings'      => ['hide_title' => 'no', 'padding' => ['top' => '0']],
        ]);

        $this->assertIsArray($out);
        $result = (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertTrue($result['restored']);
        $this->assertSame($before, get_post_meta($post_id, '_elementor_page_settings', true));
    }
}
