<?php

namespace WPMCP\Tests\Free\Elementor;

use WPMCP\Tools\Elementor\Widget_Catalog;

/**
 * Pure-data validation of the curated widget catalog (issue #59): every
 * entry must be well formed (typed params, defaults, categories, tier
 * requirements), the catalog must cover at least all free core Elementor
 * widgets, and the settings builder must map curated params onto Elementor's
 * real control value shapes. None of these tests need Elementor loaded —
 * the catalog is pure data and the builder is pure code.
 */
class WidgetCatalogTest extends \WP_UnitTestCase
{
    /**
     * Every widget bundled with free Elementor core that the catalog must
     * cover. Names are Elementor's own registered widget-type names.
     */
    private const FREE_CORE_WIDGETS = [
        'heading',
        'image',
        'text-editor',
        'video',
        'button',
        'divider',
        'spacer',
        'google_maps',
        'icon',
        'image-box',
        'icon-box',
        'star-rating',
        'rating',
        'image-carousel',
        'image-gallery',
        'icon-list',
        'counter',
        'progress',
        'testimonial',
        'tabs',
        'accordion',
        'toggle',
        'social-icons',
        'alert',
        'audio',
        'shortcode',
        'html',
        'menu-anchor',
        'sidebar',
        'read-more',
        'text-path',
    ];

    private const PARAM_TYPES = [
        'string',
        'html',
        'number',
        'integer',
        'bool',
        'choice',
        'link',
        'media',
        'icons',
        'slider',
        'gallery',
        'repeater',
    ];

    public function test_catalog_covers_all_free_core_widgets(): void
    {
        $types = array_keys(Widget_Catalog::all());

        foreach (self::FREE_CORE_WIDGETS as $type) {
            $this->assertContains($type, $types, "Catalog is missing free core widget '{$type}'.");
        }
    }

    public function test_catalog_includes_popular_elementor_pro_widgets(): void
    {
        $pro = array_filter(
            Widget_Catalog::all(),
            static fn (array $entry) => 'elementor-pro' === $entry['requires']
        );

        $this->assertGreaterThanOrEqual(
            10,
            count($pro),
            'Catalog must curate at least ten popular Elementor Pro widgets.'
        );

        foreach (['form', 'nav-menu', 'animated-headline', 'price-table', 'call-to-action'] as $type) {
            $this->assertArrayHasKey($type, $pro, "Catalog is missing popular Elementor Pro widget '{$type}'.");
        }
    }

    public function test_every_entry_is_well_formed(): void
    {
        foreach (Widget_Catalog::all() as $type => $entry) {
            $this->assertSame($type, $entry['type'], "Entry '{$type}' key must match its type field.");
            $this->assertNotSame('', trim((string) $entry['title']), "Entry '{$type}' needs a title.");
            $this->assertNotSame('', trim((string) $entry['category']), "Entry '{$type}' needs a category.");
            $this->assertNotSame('', trim((string) $entry['purpose']), "Entry '{$type}' needs a purpose line.");
            $this->assertIsArray($entry['keywords'], "Entry '{$type}' needs a keywords array.");
            $this->assertNotEmpty($entry['keywords'], "Entry '{$type}' needs at least one keyword.");
            foreach ($entry['keywords'] as $keyword) {
                $this->assertIsString($keyword, "Entry '{$type}' keywords must all be strings.");
            }
            $this->assertContains(
                $entry['requires'],
                ['elementor', 'elementor-pro'],
                "Entry '{$type}' must require either elementor or elementor-pro."
            );
            $this->assertIsArray($entry['params'], "Entry '{$type}' needs a params array.");
            $this->assertNotEmpty($entry['params'], "Entry '{$type}' needs at least one curated param.");

            foreach ($entry['params'] as $name => $spec) {
                $this->assert_param_spec_well_formed($type, (string) $name, $spec, true);
            }
        }
    }

    /** @param mixed $spec */
    private function assert_param_spec_well_formed(string $type, string $name, $spec, bool $allow_repeater): void
    {
        $where = "{$type}.{$name}";

        $this->assertIsArray($spec, "Param {$where} must be an array spec.");
        $this->assertContains($spec['type'] ?? null, self::PARAM_TYPES, "Param {$where} has an unknown type.");
        $this->assertNotSame(
            '',
            trim((string) ($spec['description'] ?? '')),
            "Param {$where} needs a description."
        );

        if ('choice' === $spec['type']) {
            $this->assertIsArray($spec['enum'] ?? null, "Choice param {$where} needs an enum.");
            $this->assertNotEmpty($spec['enum'], "Choice param {$where} needs enum values.");
            if (array_key_exists('default', $spec) && '' !== $spec['default']) {
                $this->assertContains(
                    $spec['default'],
                    $spec['enum'],
                    "Choice param {$where} default must be one of its enum values."
                );
            }
        }

        if ('repeater' === $spec['type']) {
            $this->assertTrue($allow_repeater, "Param {$where}: repeaters cannot nest repeaters.");
            $this->assertIsArray($spec['fields'] ?? null, "Repeater param {$where} needs a fields array.");
            $this->assertNotEmpty($spec['fields'], "Repeater param {$where} needs at least one field.");
            foreach ($spec['fields'] as $field_name => $field_spec) {
                $this->assert_param_spec_well_formed($type, "{$name}.{$field_name}", $field_spec, false);
            }
        }

        if (! empty($spec['responsive'])) {
            $this->assertNotSame(
                'repeater',
                $spec['type'],
                "Param {$where}: repeaters cannot be responsive."
            );
        }
    }

    public function test_summaries_expose_type_purpose_category_and_requirement(): void
    {
        $summaries = Widget_Catalog::summaries();

        $this->assertArrayHasKey('heading', $summaries);
        $heading = $summaries['heading'];

        $this->assertSame('heading', $heading['type']);
        $this->assertSame('Heading', $heading['title']);
        $this->assertSame('basic', $heading['category']);
        $this->assertSame('elementor', $heading['requires']);
        $this->assertNotSame('', trim($heading['purpose']));
    }

    public function test_curated_schema_exposes_typed_params_with_defaults(): void
    {
        $schema = Widget_Catalog::curated_schema('heading');

        $this->assertSame('heading', $schema['type']);
        $this->assertSame('elementor', $schema['requires']);
        $this->assertArrayHasKey('title', $schema['params']);
        $this->assertSame('string', $schema['params']['title']['type']);
        $this->assertTrue($schema['params']['title']['required']);

        $this->assertArrayHasKey('header_size', $schema['params']);
        $this->assertSame('choice', $schema['params']['header_size']['type']);
        $this->assertSame('h2', $schema['params']['header_size']['default']);
        $this->assertContains('h1', $schema['params']['header_size']['enum']);
    }

    public function test_curated_schema_flags_responsive_params(): void
    {
        $schema = Widget_Catalog::curated_schema('image-carousel');

        $this->assertArrayHasKey('slides_to_show', $schema['params']);
        $this->assertTrue(
            (bool) ($schema['params']['slides_to_show']['responsive'] ?? false),
            'image-carousel slides_to_show must be marked responsive.'
        );
    }

    public function test_unknown_type_has_no_schema(): void
    {
        $this->assertFalse(Widget_Catalog::has('totally-fake-widget'));
        $this->assertNull(Widget_Catalog::get('totally-fake-widget'));
    }

    // ---- validation ----

    public function test_validate_accepts_minimal_required_params(): void
    {
        $this->assertNull(Widget_Catalog::validate('heading', ['title' => 'Hello']));
    }

    public function test_validate_rejects_unknown_param(): void
    {
        $out = Widget_Catalog::validate('heading', ['title' => 'Hello', 'bogus' => 1]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('unknown_param', $out->get_error_code());
        $this->assertStringContainsString('bogus', $out->get_error_message());
    }

    public function test_validate_rejects_missing_required_param(): void
    {
        $out = Widget_Catalog::validate('heading', []);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('missing_required_param', $out->get_error_code());
        $this->assertStringContainsString('title', $out->get_error_message());
    }

    public function test_validate_skips_required_check_when_patching(): void
    {
        $this->assertNull(Widget_Catalog::validate('heading', ['header_size' => 'h3'], false));
    }

    public function test_validate_rejects_enum_violation(): void
    {
        $out = Widget_Catalog::validate('heading', ['title' => 'Hello', 'header_size' => 'h9']);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('invalid_param', $out->get_error_code());
        $this->assertStringContainsString('header_size', $out->get_error_message());
    }

    public function test_validate_rejects_wrong_scalar_type(): void
    {
        $out = Widget_Catalog::validate('counter', ['ending_number' => 'lots']);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('invalid_param', $out->get_error_code());
    }

    public function test_validate_accepts_responsive_variant_of_responsive_param(): void
    {
        $this->assertNull(
            Widget_Catalog::validate('image-carousel', ['slides_to_show_tablet' => '2'], false)
        );
    }

    public function test_validate_rejects_responsive_variant_of_plain_param(): void
    {
        $out = Widget_Catalog::validate('heading', ['title_tablet' => 'Hello']);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('unknown_param', $out->get_error_code());
    }

    public function test_validate_rejects_malformed_repeater_item(): void
    {
        $out = Widget_Catalog::validate('icon-list', ['icon_list' => [['text' => 42]]]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('invalid_param', $out->get_error_code());
    }

    // ---- settings building ----

    public function test_build_settings_passes_through_strings_and_choices(): void
    {
        $settings = Widget_Catalog::build_settings('heading', [
            'title'       => 'Hello',
            'header_size' => 'h1',
        ]);

        $this->assertSame(['title' => 'Hello', 'header_size' => 'h1'], $settings);
    }

    public function test_build_settings_maps_bool_to_elementor_switcher_values(): void
    {
        $on  = Widget_Catalog::build_settings('video', ['autoplay' => true]);
        $off = Widget_Catalog::build_settings('video', ['autoplay' => false]);

        $this->assertSame('yes', $on['autoplay']);
        $this->assertSame('', $off['autoplay']);
    }

    public function test_build_settings_honors_custom_switcher_on_value(): void
    {
        // alert.show_dismiss is an Elementor switcher whose "on" value is 'show'.
        $on = Widget_Catalog::build_settings('alert', ['show_dismiss' => true]);

        $this->assertSame('show', $on['show_dismiss']);
    }

    public function test_build_settings_shapes_link_values(): void
    {
        $settings = Widget_Catalog::build_settings('button', [
            'text' => 'Go',
            'link' => ['url' => 'https://example.com', 'is_external' => true],
        ]);

        $this->assertSame('https://example.com', $settings['link']['url']);
        $this->assertSame('on', $settings['link']['is_external']);
        $this->assertSame('', $settings['link']['nofollow']);
    }

    public function test_build_settings_shapes_media_values(): void
    {
        $settings = Widget_Catalog::build_settings('image', [
            'image' => ['url' => 'https://example.com/a.jpg', 'id' => 42],
        ]);

        $this->assertSame(42, $settings['image']['id']);
        $this->assertSame('https://example.com/a.jpg', $settings['image']['url']);
    }

    public function test_build_settings_shapes_slider_values_from_bare_numbers(): void
    {
        $settings = Widget_Catalog::build_settings('spacer', ['space' => 80]);

        $this->assertSame(['unit' => 'px', 'size' => 80.0, 'sizes' => []], $settings['space']);
    }

    public function test_build_settings_uses_param_default_unit_for_sliders(): void
    {
        $settings = Widget_Catalog::build_settings('progress', ['percent' => 70]);

        $this->assertSame('%', $settings['percent']['unit']);
        $this->assertSame(70.0, $settings['percent']['size']);
    }

    public function test_build_settings_generates_repeater_item_ids(): void
    {
        $settings = Widget_Catalog::build_settings('icon-list', [
            'icon_list' => [
                ['text' => 'One'],
                ['text' => 'Two', 'link' => ['url' => 'https://example.com']],
            ],
        ]);

        $this->assertCount(2, $settings['icon_list']);
        foreach ($settings['icon_list'] as $item) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{7}$/', $item['_id']);
        }
        $this->assertSame('One', $settings['icon_list'][0]['text']);
        $this->assertSame('https://example.com', $settings['icon_list'][1]['link']['url']);
        $this->assertNotSame($settings['icon_list'][0]['_id'], $settings['icon_list'][1]['_id']);
    }

    public function test_build_settings_maps_responsive_variants_to_device_controls(): void
    {
        $settings = Widget_Catalog::build_settings('image-carousel', [
            'slides_to_show'        => '3',
            'slides_to_show_tablet' => '2',
            'slides_to_show_mobile' => '1',
        ]);

        $this->assertSame('3', $settings['slides_to_show']);
        $this->assertSame('2', $settings['slides_to_show_tablet']);
        $this->assertSame('1', $settings['slides_to_show_mobile']);
    }
}
