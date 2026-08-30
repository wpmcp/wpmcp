<?php

namespace WPMCP\Tests\Pro\Elementor;

use WPMCP\Safety\Rollback_Service;
use WPMCP\Tools\Elementor\Get_Global_Settings;
use WPMCP\Tools\Elementor\Replace_System_Colors;
use WPMCP\Tools\Elementor\Replace_System_Typography;

/**
 * replace-system-colors / replace-system-typography (issue #60).
 *
 * These two tools differ from update-global-colors / update-global-typography
 * in one contractual way: they are atomic over the four Elementor system
 * slots. A replacement is accepted only when it covers every slot exactly
 * once with valid content; anything else is refused before a write, so the
 * kit is never left with a half-replaced palette. Every accepted replacement
 * still goes through Elementor_Kit_Data::write (Safe_Mutation on the kit
 * post), so rollback-operation restores the exact prior kit settings.
 */
class ReplaceSystemTokensTest extends Structural_Harness
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

    private function hash(): string
    {
        return (new Get_Global_Settings())->handle([])['settings_hash'];
    }

    /** A well-formed complete color set, one entry per system slot. */
    private function full_colors(): array
    {
        return [
            ['_id' => 'primary', 'color' => '#010101'],
            ['_id' => 'secondary', 'color' => '#020202'],
            ['_id' => 'text', 'color' => '#030303'],
            ['_id' => 'accent', 'color' => '#040404'],
        ];
    }

    /** A well-formed complete typography set, one entry per system slot. */
    private function full_typography(): array
    {
        return [
            ['_id' => 'primary', 'typography_font_family' => 'Inter'],
            ['_id' => 'secondary', 'typography_font_family' => 'Inter'],
            ['_id' => 'text', 'typography_font_family' => 'Lora'],
            ['_id' => 'accent', 'typography_font_family' => 'Lora'],
        ];
    }

    private function seeded_palette(): array
    {
        return [
            'system_colors' => [
                ['_id' => 'primary', 'title' => 'Brand Blue', 'color' => '#111111'],
                ['_id' => 'secondary', 'title' => 'Secondary', 'color' => '#222222'],
                ['_id' => 'text', 'title' => 'Text', 'color' => '#333333'],
                ['_id' => 'accent', 'title' => 'Accent', 'color' => '#444444'],
            ],
        ];
    }

    // ---- rollback restores the exact prior kit state ------------------------

    public function test_rollback_restores_the_exact_prior_kit_state(): void
    {
        $before = [
            'system_colors'     => [
                ['_id' => 'primary', 'title' => 'Brand Blue', 'color' => '#111111'],
                ['_id' => 'secondary', 'title' => 'Secondary', 'color' => '#222222'],
                ['_id' => 'text', 'title' => 'Text', 'color' => '#333333'],
                ['_id' => 'accent', 'title' => 'Accent', 'color' => '#444444'],
            ],
            'custom_colors'     => [['_id' => 'brandx', 'title' => 'Brand X', 'color' => '#abcdef']],
            'system_typography' => [['_id' => 'primary', 'title' => 'Primary', 'typography_font_family' => 'Roboto']],
        ];
        $this->seed_kit($before);

        $out = (new Replace_System_Colors())->handle([
            'expected_hash' => $this->hash(),
            'system_colors' => $this->full_colors(),
        ]);

        $this->assertIsArray($out, 'replace-system-colors should have accepted a complete set');
        $this->assertArrayHasKey('operation_id', $out);
        $this->assertSame('#010101', $this->color_of($this->kit_meta()['system_colors'], 'primary'));

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        clean_post_cache($this->kit_id());

        $this->assertSame($before, $this->kit_meta(), 'Rollback must restore the exact prior kit settings');
    }

    public function test_typography_rollback_restores_the_exact_prior_kit_state(): void
    {
        $before = [
            'system_typography' => [
                ['_id' => 'primary', 'title' => 'Headings', 'typography_typography' => 'custom', 'typography_font_family' => 'Roboto'],
                ['_id' => 'secondary', 'title' => 'Secondary'],
                ['_id' => 'text', 'title' => 'Text'],
                ['_id' => 'accent', 'title' => 'Accent'],
            ],
        ];
        $this->seed_kit($before);

        $out = (new Replace_System_Typography())->handle([
            'expected_hash'     => $this->hash(),
            'system_typography' => $this->full_typography(),
        ]);

        $this->assertIsArray($out);
        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        clean_post_cache($this->kit_id());

        $this->assertSame($before, $this->kit_meta());
    }

    // ---- atomicity: all four slots or none ---------------------------------

    /** @dataProvider refused_color_sets */
    public function test_incomplete_or_invalid_color_set_writes_nothing(array $colors, string $code): void
    {
        $this->seed_kit($this->seeded_palette());
        $before = $this->kit_meta();

        $out = (new Replace_System_Colors())->handle([
            'expected_hash' => $this->hash(),
            'system_colors' => $colors,
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame($code, $out->get_error_code());
        clean_post_cache($this->kit_id());
        $this->assertSame($before, $this->kit_meta(), 'A refused replacement must leave the kit untouched');
    }

    public function refused_color_sets(): array
    {
        return [
            'missing slots'  => [
                [['_id' => 'primary', 'color' => '#010101']],
                'incomplete_set',
            ],
            'duplicate slot' => [
                [
                    ['_id' => 'primary', 'color' => '#010101'],
                    ['_id' => 'primary', 'color' => '#020202'],
                    ['_id' => 'text', 'color' => '#030303'],
                    ['_id' => 'accent', 'color' => '#040404'],
                ],
                'duplicate_slot',
            ],
            'unknown slot'   => [
                [
                    ['_id' => 'primary', 'color' => '#010101'],
                    ['_id' => 'secondary', 'color' => '#020202'],
                    ['_id' => 'text', 'color' => '#030303'],
                    ['_id' => 'tertiary', 'color' => '#040404'],
                ],
                'unknown_slot',
            ],
            'invalid hex'    => [
                [
                    ['_id' => 'primary', 'color' => 'rebeccapurple'],
                    ['_id' => 'secondary', 'color' => '#020202'],
                    ['_id' => 'text', 'color' => '#030303'],
                    ['_id' => 'accent', 'color' => '#040404'],
                ],
                'invalid_color',
            ],
            'not an object'  => [
                ['primary', '#020202', '#030303', '#040404'],
                'invalid_entry',
            ],
        ];
    }

    /** @dataProvider refused_typography_sets */
    public function test_incomplete_or_invalid_typography_set_writes_nothing(array $typography, string $code): void
    {
        $this->seed_kit([
            'system_typography' => [
                ['_id' => 'primary', 'title' => 'Primary', 'typography_font_family' => 'Roboto'],
                ['_id' => 'secondary', 'title' => 'Secondary'],
                ['_id' => 'text', 'title' => 'Text'],
                ['_id' => 'accent', 'title' => 'Accent'],
            ],
        ]);
        $before = $this->kit_meta();

        $out = (new Replace_System_Typography())->handle([
            'expected_hash'     => $this->hash(),
            'system_typography' => $typography,
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame($code, $out->get_error_code());
        clean_post_cache($this->kit_id());
        $this->assertSame($before, $this->kit_meta(), 'A refused replacement must leave the kit untouched');
    }

    public function refused_typography_sets(): array
    {
        return [
            'missing slots'    => [
                [['_id' => 'primary', 'typography_font_family' => 'Inter']],
                'incomplete_set',
            ],
            'duplicate slot'   => [
                [
                    ['_id' => 'primary', 'typography_font_family' => 'Inter'],
                    ['_id' => 'primary', 'typography_font_family' => 'Inter'],
                    ['_id' => 'text', 'typography_font_family' => 'Lora'],
                    ['_id' => 'accent', 'typography_font_family' => 'Lora'],
                ],
                'duplicate_slot',
            ],
            'unknown slot'     => [
                [
                    ['_id' => 'primary', 'typography_font_family' => 'Inter'],
                    ['_id' => 'secondary', 'typography_font_family' => 'Inter'],
                    ['_id' => 'text', 'typography_font_family' => 'Lora'],
                    ['_id' => 'quaternary', 'typography_font_family' => 'Lora'],
                ],
                'unknown_slot',
            ],
            // A misspelled field (font_family, not typography_font_family) must
            // not silently validate and then wipe every slot's typography.
            'misspelled field' => [
                [
                    ['_id' => 'primary', 'font_family' => 'Inter'],
                    ['_id' => 'secondary', 'font_family' => 'Inter'],
                    ['_id' => 'text', 'font_family' => 'Lora'],
                    ['_id' => 'accent', 'font_family' => 'Lora'],
                ],
                'unknown_field',
            ],
            'empty entries'    => [
                [
                    ['_id' => 'primary'],
                    ['_id' => 'secondary'],
                    ['_id' => 'text'],
                    ['_id' => 'accent'],
                ],
                'empty_entry',
            ],
        ];
    }

    // ---- accepted replacements ---------------------------------------------

    public function test_replace_colors_writes_all_four_slots_in_canonical_order(): void
    {
        $this->seed_kit($this->seeded_palette());

        $out = (new Replace_System_Colors())->handle([
            'expected_hash' => $this->hash(),
            // Deliberately shuffled: the stored order must still be canonical.
            'system_colors' => array_reverse($this->full_colors()),
        ]);

        $this->assertIsArray($out);
        $stored = $this->kit_meta()['system_colors'];
        $this->assertSame(['primary', 'secondary', 'text', 'accent'], array_column($stored, '_id'));
        $this->assertSame(['#010101', '#020202', '#030303', '#040404'], array_column($stored, 'color'));
    }

    public function test_replace_colors_preserves_a_user_renamed_slot_title(): void
    {
        // 'primary' was renamed to "Brand Blue" in the builder. Replacing the
        // palette without a title must not silently rename it back to
        // Elementor's factory default.
        $this->seed_kit($this->seeded_palette());

        (new Replace_System_Colors())->handle([
            'expected_hash' => $this->hash(),
            'system_colors' => $this->full_colors(),
        ]);

        $this->assertSame('Brand Blue', $this->entry_by_id($this->kit_meta()['system_colors'], 'primary')['title']);
    }

    public function test_replace_colors_accepts_an_explicit_title(): void
    {
        $this->seed_kit($this->seeded_palette());
        $colors    = $this->full_colors();
        $colors[0] = ['_id' => 'primary', 'title' => 'Ink', 'color' => '#010101'];

        (new Replace_System_Colors())->handle([
            'expected_hash' => $this->hash(),
            'system_colors' => $colors,
        ]);

        $this->assertSame('Ink', $this->entry_by_id($this->kit_meta()['system_colors'], 'primary')['title']);
    }

    public function test_replace_colors_leaves_custom_colors_alone(): void
    {
        $seed                  = $this->seeded_palette();
        $seed['custom_colors'] = [['_id' => 'brandx', 'title' => 'Brand X', 'color' => '#abcdef']];
        $this->seed_kit($seed);

        (new Replace_System_Colors())->handle([
            'expected_hash' => $this->hash(),
            'system_colors' => $this->full_colors(),
        ]);

        $this->assertSame($seed['custom_colors'], $this->kit_meta()['custom_colors']);
    }

    public function test_replace_colors_rejects_a_stale_hash(): void
    {
        $this->seed_kit($this->seeded_palette());
        $before = $this->kit_meta();

        $out = (new Replace_System_Colors())->handle([
            'expected_hash' => 'deadbeef',
            'system_colors' => $this->full_colors(),
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('stale_expected_hash', $out->get_error_code());
        $this->assertSame($before, $this->kit_meta());
    }

    public function test_replace_typography_sets_custom_typography_and_preserves_titles(): void
    {
        $this->seed_kit([
            'system_typography' => [
                ['_id' => 'primary', 'title' => 'Headings', 'typography_font_family' => 'Roboto'],
                ['_id' => 'secondary', 'title' => 'Secondary'],
                ['_id' => 'text', 'title' => 'Text'],
                ['_id' => 'accent', 'title' => 'Accent'],
            ],
        ]);

        $out = (new Replace_System_Typography())->handle([
            'expected_hash'     => $this->hash(),
            'system_typography' => $this->full_typography(),
        ]);

        $this->assertIsArray($out);
        $primary = $this->entry_by_id($this->kit_meta()['system_typography'], 'primary');
        $this->assertSame('Headings', $primary['title'], 'A user-renamed slot title survives replacement');
        $this->assertSame('Inter', $primary['typography_font_family']);
        $this->assertSame('custom', $primary['typography_typography']);
        $this->assertSame(
            ['primary', 'secondary', 'text', 'accent'],
            array_column($this->kit_meta()['system_typography'], '_id')
        );
    }

    public function test_replace_typography_sanitizes_nested_array_fields(): void
    {
        $this->seed_kit(['system_typography' => []]);
        $typography    = $this->full_typography();
        $typography[0] = [
            '_id'                    => 'primary',
            'typography_font_family' => 'Inter',
            'typography_font_size'   => ['unit' => 'px<script>alert(1)</script>', 'size' => '48'],
        ];

        (new Replace_System_Typography())->handle([
            'expected_hash'     => $this->hash(),
            'system_typography' => $typography,
        ]);

        $size = $this->entry_by_id($this->kit_meta()['system_typography'], 'primary')['typography_font_size'];
        $this->assertIsArray($size);
        $this->assertStringNotContainsString('<script>', $size['unit']);
        $this->assertSame('48', $size['size']);
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
