<?php

namespace WPMCP\Tests\Free\Elementor;

use WPMCP\Tools\Elementor\Get_Global_Settings;

/**
 * get-global-settings is the free-tier read half of issue #60 ("read free /
 * writes pro"): any site can read the active kit's design tokens as
 * structured data, while every kit write stays pro. These tests live in
 * tests/free because the read must work with the pro gate closed.
 */
class GlobalSettingsReadTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! wpmcp_elementor_active()) {
            $this->markTestSkipped('Elementor not active');
        }
        $kits = \Elementor\Plugin::instance()->kits_manager;
        if (! $kits->get_active_id() || ! get_post((int) $kits->get_active_id())) {
            update_option('elementor_active_kit', \Elementor\Core\Kits\Manager::create_default_kit());
        }
    }

    private function kit_id(): int
    {
        return (int) \Elementor\Plugin::instance()->kits_manager->get_active_id();
    }

    private function seed_kit(array $settings): void
    {
        update_post_meta($this->kit_id(), '_elementor_page_settings', $settings);
        clean_post_cache($this->kit_id());
    }

    public function test_read_returns_colors_typography_spacing_and_layout(): void
    {
        $this->seed_kit([
            'system_colors'         => [['_id' => 'primary', 'title' => 'Primary', 'color' => '#111111']],
            'space_between_widgets' => ['unit' => 'px', 'size' => 24],
            'container_width'       => ['unit' => 'px', 'size' => 1140],
        ]);

        $out = (new Get_Global_Settings())->handle([]);

        $this->assertIsArray($out);
        foreach (['system_colors', 'custom_colors', 'system_typography', 'custom_typography', 'spacing', 'layout'] as $key) {
            $this->assertArrayHasKey($key, $out, "The read must expose a '{$key}' group");
        }
        $this->assertSame(24, $out['spacing']['space_between_widgets']['size']);
        // container_width is a layout width, not a spacing token.
        $this->assertArrayNotHasKey('container_width', $out['spacing']);
        $this->assertSame(1140, $out['layout']['container_width']['size']);
        $this->assertNotSame('', $out['settings_hash']);
    }

    public function test_read_is_registered_on_the_free_tier(): void
    {
        $abilities = \WPMCP\Tests\Free\Platform\RegisteredAbilities::manifest_map();

        $this->assertArrayHasKey('wpmcp/get-global-settings', $abilities);
        $this->assertSame(
            'free',
            $abilities['wpmcp/get-global-settings'],
            'Issue #60 specifies the kit read as free tier; only the kit writes are pro.'
        );
    }

    public function test_writes_stay_on_the_pro_tier(): void
    {
        $abilities = \WPMCP\Tests\Free\Platform\RegisteredAbilities::manifest_map();

        foreach (
            [
                'wpmcp/update-global-colors',
                'wpmcp/update-global-typography',
                'wpmcp/replace-system-colors',
                'wpmcp/replace-system-typography',
            ] as $name
        ) {
            $this->assertSame('pro', $abilities[$name] ?? null, "{$name} must stay pro tier");
        }
    }
}
