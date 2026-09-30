<?php

namespace WPMCP\Tests\Pro\Elementor;

use WPMCP\Tests\Free\Content\Slash_Payload;
use WPMCP\Tools\Elementor\Create_Code_Snippet;
use WPMCP\Tools\Elementor\Create_Popup;
use WPMCP\Tools\Elementor\Element_Tree;
use WPMCP\Tools\Elementor\Elementor_Kit_Data;
use WPMCP\Tools\Elementor\Import_Template;
use WPMCP\Tools\Elementor\Set_Popup_Settings;

/**
 * Issue #425 for the Elementor write paths: page settings, kit settings,
 * template titles and code snippets must land byte for byte, backslashes
 * included (custom CSS escapes, JSON \/ and regular expressions in code).
 */
class SlashRoundTripTest extends Structural_Harness
{
    use Slash_Payload;

    private function settings(): array
    {
        return ['custom_css' => self::body(), 'wpmcp_slash' => ['nested' => self::line()]];
    }

    public function test_code_snippet_keeps_backslashes_in_code_and_title(): void
    {
        if (! post_type_exists('elementor_snippet')) {
            register_post_type('elementor_snippet', ['public' => false, 'show_ui' => false]);
        }
        $code = '<script>var re = /a\/b\\d+/; var s = "C:\\temp";</script>' . self::body();

        $out = (new Create_Code_Snippet())->handle(['code' => $code, 'title' => self::line()]);

        $this->assertSame($code, get_post_meta($out['snippet_id'], '_elementor_code', true));
        $this->assertSame(self::line(), get_post($out['snippet_id'])->post_title);
    }

    public function test_create_popup_keeps_backslashes_in_title_and_settings(): void
    {
        $out = (new Create_Popup())->handle(['title' => self::line(), 'settings' => $this->settings()]);

        $this->assertSame(self::line(), get_post($out['popup_id'])->post_title);
        $saved = get_post_meta($out['popup_id'], '_elementor_page_settings', true);
        $this->assertSame(self::body(), $saved['custom_css']);
        $this->assertSame(self::line(), $saved['wpmcp_slash']['nested']);
    }

    public function test_set_popup_settings_keeps_backslashes_new_and_existing(): void
    {
        $id = (new Create_Popup())->handle(['title' => 'P'])['popup_id'];
        update_post_meta($id, '_elementor_page_settings', wp_slash(['existing' => self::line()]));

        $out = (new Set_Popup_Settings())->handle(['post_id' => $id, 'settings' => $this->settings()]);

        $this->assertIsArray($out);
        clean_post_cache($id);
        $saved = get_post_meta($id, '_elementor_page_settings', true);
        $this->assertSame(self::body(), $saved['custom_css']);
        $this->assertSame(self::line(), $saved['existing']);
    }

    public function test_import_template_keeps_backslashes_in_title_and_page_settings(): void
    {
        $out = (new Import_Template())->handle(['export' => [
            'title'         => self::line(),
            'type'          => 'page',
            'content'       => [['id' => 'abc1234', 'elType' => 'container', 'settings' => [], 'elements' => [], 'isInner' => false]],
            'page_settings' => $this->settings(),
        ]]);

        $this->assertIsArray($out);
        $id = (int) $out['template_id'];
        $this->assertSame(self::line(), get_post($id)->post_title);
        $saved = get_post_meta($id, '_elementor_page_settings', true);
        $this->assertSame(self::body(), $saved['custom_css']);
    }

    public function test_kit_write_keeps_backslashes_new_and_existing(): void
    {
        $kit = Elementor_Kit_Data::active_kit_id();
        update_post_meta($kit, '_elementor_page_settings', wp_slash(['existing' => self::line()]));
        clean_post_cache($kit);

        $out = Elementor_Kit_Data::write($kit, ['custom_css' => self::body()], 'update-global-colors', []);

        $this->assertIsArray($out);
        clean_post_cache($kit);
        $saved = get_post_meta($kit, '_elementor_page_settings', true);
        $this->assertSame(self::body(), $saved['custom_css']);
        $this->assertSame(self::line(), $saved['existing']);
    }

    public function test_page_settings_without_a_document_keep_backslashes(): void
    {
        $id = self::factory()->post->create(['post_type' => 'page']);
        // With no active kit Elementor has no document to save through, so
        // the settings go straight to post meta.
        update_option('elementor_active_kit', 0);

        $out = Element_Tree::write_settings($id, $this->settings(), 'update-page-settings', []);

        $this->assertIsArray($out);
        clean_post_cache($id);
        $this->assertSame($this->settings(), get_post_meta($id, '_elementor_page_settings', true));
    }
}
