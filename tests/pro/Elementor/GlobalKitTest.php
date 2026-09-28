<?php

namespace WPMCP\Tests\Pro\Elementor;

use WPMCP\Tools\Elementor\Get_Global_Settings;
use WPMCP\Tools\Elementor\Update_Global_Colors;
use WPMCP\Tools\Elementor\Update_Global_Typography;
use WPMCP\Tools\Elementor\List_Global_Classes;

/**
 * Cluster 1: the Elementor global Kit surface.
 *
 * The active kit is an ordinary post; its design tokens live in the kit's
 * `_elementor_page_settings` meta (system/custom colors + typography), so the
 * existing post snapshot in Safe_Mutation captures and restores every write.
 * Reads are sourced from the persisted meta (the source of truth for user
 * overrides) with Elementor's four system tokens filled from known defaults
 * when the kit has never been customized, keeping this deterministic and
 * decoupled from Elementor's runtime control-default merging.
 */
class GlobalKitTest extends Structural_Harness
{
    private function kit_id(): int
    {
        return (int) \Elementor\Plugin::instance()->kits_manager->get_active_id();
    }

    private function seed_kit(array $settings): void
    {
        update_post_meta($this->kit_id(), '_elementor_page_settings', $settings);
        clean_post_cache($this->kit_id());
    }

    private function kit_meta(): array
    {
        $s = get_post_meta($this->kit_id(), '_elementor_page_settings', true);
        return is_array($s) ? $s : [];
    }

    // ---- get-global-settings ------------------------------------------------

    public function test_get_returns_seeded_colors_and_typography_with_hash(): void
    {
        $this->seed_kit([
            'system_colors'     => [
                ['_id' => 'primary', 'title' => 'Primary', 'color' => '#111111'],
                ['_id' => 'secondary', 'title' => 'Secondary', 'color' => '#222222'],
            ],
            'custom_colors'     => [
                ['_id' => 'brandx', 'title' => 'Brand X', 'color' => '#abcdef'],
            ],
            'system_typography' => [
                ['_id' => 'primary', 'title' => 'Primary', 'typography_typography' => 'custom', 'typography_font_family' => 'Roboto'],
            ],
        ]);

        $out = (new Get_Global_Settings())->handle([]);

        $this->assertIsArray($out);
        $this->assertSame($this->kit_id(), $out['kit_id']);
        $this->assertContains('primary', array_column($out['system_colors'], '_id'));
        $this->assertSame('#111111', $this->color_of($out['system_colors'], 'primary'));
        $this->assertContains('brandx', array_column($out['custom_colors'], '_id'));
        $this->assertContains('primary', array_column($out['system_typography'], '_id'));
        $this->assertArrayHasKey('settings_hash', $out);
        $this->assertNotSame('', $out['settings_hash']);
    }

    public function test_get_fills_default_system_tokens_for_untouched_kit(): void
    {
        // No kit override seeded: the four Elementor system tokens must still
        // be reported so an agent can patch them by _id.
        delete_post_meta($this->kit_id(), '_elementor_page_settings');
        clean_post_cache($this->kit_id());

        $out = (new Get_Global_Settings())->handle([]);

        $ids = array_column($out['system_colors'], '_id');
        $this->assertContains('primary', $ids);
        $this->assertContains('secondary', $ids);
        $this->assertContains('text', $ids);
        $this->assertContains('accent', $ids);
        $this->assertContains('primary', array_column($out['system_typography'], '_id'));
    }

    // ---- update-global-colors -----------------------------------------------

    public function test_update_colors_patches_system_token_by_id_snapshotted(): void
    {
        $this->seed_kit([
            'system_colors' => [
                ['_id' => 'primary', 'title' => 'Primary', 'color' => '#111111'],
                ['_id' => 'secondary', 'title' => 'Secondary', 'color' => '#222222'],
            ],
        ]);
        $hash = (new Get_Global_Settings())->handle([])['settings_hash'];

        $out = (new Update_Global_Colors())->handle([
            'expected_hash' => $hash,
            'system_colors' => [['_id' => 'primary', 'color' => '#ff0000']],
        ]);

        $this->assertIsArray($out);
        $this->assertArrayHasKey('operation_id', $out);
        $this->assertSame('#ff0000', $this->color_of($this->kit_meta()['system_colors'], 'primary'));
        // Untouched token survives.
        $this->assertSame('#222222', $this->color_of($this->kit_meta()['system_colors'], 'secondary'));
    }

    public function test_update_colors_appends_new_custom_color(): void
    {
        $this->seed_kit(['custom_colors' => []]);
        $hash = (new Get_Global_Settings())->handle([])['settings_hash'];

        (new Update_Global_Colors())->handle([
            'expected_hash' => $hash,
            'custom_colors' => [['title' => 'Sunset', 'color' => '#ff7a00']],
        ]);

        $custom = $this->kit_meta()['custom_colors'];
        $this->assertCount(1, $custom);
        $this->assertSame('#ff7a00', $custom[0]['color']);
        $this->assertNotEmpty($custom[0]['_id']);
    }

    public function test_update_colors_rejects_stale_hash(): void
    {
        $this->seed_kit(['system_colors' => [['_id' => 'primary', 'title' => 'Primary', 'color' => '#111111']]]);

        $out = (new Update_Global_Colors())->handle([
            'expected_hash' => 'deadbeef',
            'system_colors' => [['_id' => 'primary', 'color' => '#ff0000']],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('stale_expected_hash', $out->get_error_code());
        // Nothing written.
        $this->assertSame('#111111', $this->color_of($this->kit_meta()['system_colors'], 'primary'));
    }

    public function test_update_colors_rejects_invalid_hex(): void
    {
        $this->seed_kit(['system_colors' => [['_id' => 'primary', 'title' => 'Primary', 'color' => '#111111']]]);
        $hash = (new Get_Global_Settings())->handle([])['settings_hash'];

        $out = (new Update_Global_Colors())->handle([
            'expected_hash' => $hash,
            'system_colors' => [['_id' => 'primary', 'color' => 'not-a-color']],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('invalid_color', $out->get_error_code());
    }

    // ---- update-global-typography -------------------------------------------

    public function test_update_typography_patches_system_token(): void
    {
        $this->seed_kit([
            'system_typography' => [
                ['_id' => 'primary', 'title' => 'Primary'],
            ],
        ]);
        $hash = (new Get_Global_Settings())->handle([])['settings_hash'];

        $out = (new Update_Global_Typography())->handle([
            'expected_hash'     => $hash,
            'system_typography' => [[
                '_id'                    => 'primary',
                'typography_font_family' => 'Poppins',
                'typography_font_weight' => '600',
            ]],
        ]);

        $this->assertIsArray($out);
        $entry = $this->entry_by_id($this->kit_meta()['system_typography'], 'primary');
        $this->assertSame('Poppins', $entry['typography_font_family']);
        $this->assertSame('600', $entry['typography_font_weight']);
        // Setting a font implies enabling custom typography so it renders.
        $this->assertSame('custom', $entry['typography_typography']);
    }

    public function test_update_global_typography_accepts_per_breakpoint_sizes(): void
    {
        $hash = (new Get_Global_Settings())->handle([])['settings_hash'];

        $out = (new Update_Global_Typography())->handle([
            'expected_hash'     => $hash,
            'system_typography' => [[
                '_id'                           => 'primary',
                'typography_font_size_tablet'   => ['unit' => 'px', 'size' => 32],
                'typography_font_size_mobile'   => ['unit' => 'px', 'size' => 24],
                'typography_line_height_mobile' => ['unit' => 'em', 'size' => 1.2],
            ]],
        ]);

        $this->assertIsArray($out);
        $entry = $this->entry_by_id($this->kit_meta()['system_typography'], 'primary');
        $this->assertSame(['unit' => 'px', 'size' => 32], $entry['typography_font_size_tablet']);
        $this->assertSame(['unit' => 'px', 'size' => 24], $entry['typography_font_size_mobile']);
        $this->assertSame(['unit' => 'em', 'size' => 1.2], $entry['typography_line_height_mobile']);
        // A responsive-only size must still enable custom typography, or
        // Elementor ignores the token and the breakpoint value never renders.
        $this->assertSame('custom', $entry['typography_typography']);
    }

    // ---- list-global-classes ------------------------------------------------

    public function test_list_global_classes_reads_kit_store(): void
    {
        update_post_meta($this->kit_id(), '_elementor_global_classes', [
            'items' => [
                'g-hero'  => ['id' => 'g-hero', 'label' => 'Hero', 'type' => 'class'],
                'g-card'  => ['id' => 'g-card', 'label' => 'Card', 'type' => 'class'],
            ],
            'order' => ['g-hero', 'g-card'],
        ]);
        clean_post_cache($this->kit_id());

        $out = (new List_Global_Classes())->handle([]);

        $this->assertIsArray($out);
        $labels = array_column($out['classes'], 'label');
        $this->assertContains('Hero', $labels);
        $this->assertContains('Card', $labels);
    }

    public function test_list_global_classes_empty_when_none(): void
    {
        delete_post_meta($this->kit_id(), '_elementor_global_classes');
        clean_post_cache($this->kit_id());

        $out = (new List_Global_Classes())->handle([]);

        $this->assertIsArray($out);
        $this->assertSame([], $out['classes']);
    }

    // ---- helpers ------------------------------------------------------------

    private function color_of(array $entries, string $id): ?string
    {
        $e = $this->entry_by_id($entries, $id);
        return $e['color'] ?? null;
    }

    private function entry_by_id(array $entries, string $id): ?array
    {
        foreach ($entries as $e) {
            if (($e['_id'] ?? null) === $id) {
                return $e;
            }
        }
        return null;
    }
}
