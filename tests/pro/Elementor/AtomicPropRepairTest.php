<?php

namespace WPMCP\Tests\Pro\Elementor;

use WPMCP\Tools\Elementor\Add_Atomic_Widget;
use WPMCP\Tools\Elementor\Atomic_Prop_Schema;
use WPMCP\Tools\Elementor\Atomic_Props;
use WPMCP\Tools\Elementor\Update_Atomic_Widget;

/**
 * Atomic v4 prop coercion and repair (issue #137).
 *
 * The mapper is driven by Elementor's OWN prop metadata: prop keys, the
 * aliases Elementor declares (e-heading's title accepts text/content/heading),
 * and the enums it constrains strings to. Two layers are tested:
 *
 *  - against recorded fixtures (tests/support/elementor-atomic-prop-fixtures.php)
 *    so the behaviour is pinned to known Elementor shapes, and
 *  - against the live install, so the recorded fixtures cannot drift away
 *    from what Elementor actually declares.
 *
 * Anything the mapper cannot express in the declared type is dropped and
 * reported rather than written, because a wrongly-typed prop is exactly what
 * makes the v4 editor refuse to open an element. Every atomic write is still
 * snapshot-first, so even a dropped prop is a recoverable mistake.
 */
class AtomicPropRepairTest extends Structural_Harness
{
    protected function setUp(): void
    {
        parent::setUp();
        Atomic_Prop_Schema::set_for_tests(require dirname(__DIR__, 2) . '/support/elementor-atomic-prop-fixtures.php');
    }

    protected function tearDown(): void
    {
        Atomic_Prop_Schema::set_for_tests(null);
        parent::tearDown();
    }

    // ---- schema reading -----------------------------------------------------

    public function test_recorded_fixtures_match_the_live_elementor_schema(): void
    {
        Atomic_Prop_Schema::set_for_tests(null);

        if (! class_exists('\\Elementor\\Modules\\AtomicWidgets\\Module')) {
            $this->markTestSkipped('This Elementor build has no atomic-widgets module.');
        }

        $recorded = require dirname(__DIR__, 2) . '/support/elementor-atomic-prop-fixtures.php';
        $compared = 0;

        foreach ($recorded as $type => $props) {
            $live = Atomic_Prop_Schema::for_type($type);
            if ([] === $live) {
                // Synthetic fixture entries (e-kinds) and element types this
                // build does not register have nothing to compare against.
                continue;
            }
            $compared++;

            foreach ($props as $name => $meta) {
                $this->assertArrayHasKey($name, $live, sprintf('%s lost its "%s" prop.', $type, $name));
                $this->assertSame($meta['kind'], $live[ $name ]['kind'], sprintf('%s.%s changed $$type.', $type, $name));
                $this->assertSame(
                    $meta['aliases'] ?? [],
                    $live[ $name ]['aliases'],
                    sprintf('%s.%s aliases drifted from the recording.', $type, $name)
                );
                $this->assertSame(
                    $meta['enum'] ?? null,
                    $live[ $name ]['enum'],
                    sprintf('%s.%s enum drifted from the recording.', $type, $name)
                );
            }
        }

        $this->assertGreaterThan(0, $compared, 'No recorded atomic element was verifiable against this Elementor build.');
    }

    public function test_unknown_element_type_has_no_schema(): void
    {
        $this->assertFalse(Atomic_Prop_Schema::known('e-not-a-thing'));
        $this->assertSame([], Atomic_Prop_Schema::for_type('e-not-a-thing'));
        $this->assertNull(Atomic_Prop_Schema::kind('e-not-a-thing', 'title'));
    }

    public function test_canonical_resolves_elementor_declared_aliases(): void
    {
        $this->assertSame('title', Atomic_Prop_Schema::canonical('e-heading', 'text'));
        $this->assertSame('title', Atomic_Prop_Schema::canonical('e-heading', 'heading'));
        $this->assertSame('paragraph', Atomic_Prop_Schema::canonical('e-paragraph', 'content'));
        $this->assertSame('text', Atomic_Prop_Schema::canonical('e-button', 'label'));
        $this->assertNull(Atomic_Prop_Schema::canonical('e-heading', 'headline'));
    }

    // ---- coercion of plain values ------------------------------------------

    public function test_plain_string_becomes_the_declared_typed_prop(): void
    {
        $out = Atomic_Props::map('e-heading', ['title' => 'Plain text']);

        $this->assertSame(['$$type' => 'escaped-html', 'value' => 'Plain text'], $out['settings']['title']);
        $this->assertSame([], $out['warnings']);
        $this->assertStringContainsString('escaped-html', $out['coerced'][0]);
    }

    public function test_aliased_prop_name_is_renamed_to_the_real_prop(): void
    {
        $out = Atomic_Props::map('e-paragraph', ['text' => 'Body copy']);

        $this->assertArrayHasKey('paragraph', $out['settings']);
        $this->assertArrayNotHasKey('text', $out['settings']);
        $this->assertSame('Body copy', $out['settings']['paragraph']['value']);
        $this->assertStringContainsString('Renamed "text" to "paragraph"', $out['coerced'][0]);
    }

    public function test_plain_link_string_becomes_a_link_prop(): void
    {
        $out = Atomic_Props::map('e-button', ['link' => 'https://example.com/pricing']);

        $this->assertSame('link', $out['settings']['link']['$$type']);
        $this->assertSame('https://example.com/pricing', $out['settings']['link']['value']['destination']['value']);
    }

    public function test_link_object_with_target_is_coerced(): void
    {
        $out = Atomic_Props::map('e-button', ['link' => ['url' => 'https://example.com', 'target_blank' => true]]);

        $this->assertTrue($out['settings']['link']['value']['isTargetBlank']['value']);
    }

    public function test_plain_attachment_id_becomes_an_image_prop(): void
    {
        $out = Atomic_Props::map('e-image', ['image' => 42]);

        $this->assertSame('image', $out['settings']['image']['$$type']);
        $this->assertSame(42, $out['settings']['image']['value']['src']['value']['id']['value']);
    }

    public function test_class_list_string_becomes_a_classes_prop(): void
    {
        $out = Atomic_Props::map('e-heading', ['classes' => 'hero primary']);

        $this->assertSame(['hero', 'primary'], $out['settings']['classes']['value']);
    }

    public function test_image_prop_accepts_a_url_or_an_object(): void
    {
        $from_url = Atomic_Props::map('e-image', ['image' => 'https://example.com/hero.png']);
        $this->assertSame('https://example.com/hero.png', $from_url['settings']['image']['value']['src']['value']['url']['value']);

        $from_object = Atomic_Props::map('e-image', ['image' => ['id' => 7, 'alt' => 'Hero']]);
        $this->assertSame(7, $from_object['settings']['image']['value']['src']['value']['id']['value']);
        $this->assertSame('Hero', $from_object['settings']['image']['value']['alt']['value']);
    }

    public function test_link_and_classes_refuse_values_they_cannot_express(): void
    {
        $out = Atomic_Props::map('e-button', ['link' => ['title' => 'no url here'], 'classes' => [['nested']]]);

        $this->assertSame([], $out['settings']);
        $this->assertCount(2, $out['warnings']);
    }

    public function test_already_typed_props_pass_through_untouched(): void
    {
        $typed = ['$$type' => 'escaped-html', 'value' => 'Kept'];

        $out = Atomic_Props::map('e-heading', ['title' => $typed]);

        $this->assertSame($typed, $out['settings']['title']);
        $this->assertSame([], $out['coerced']);
        $this->assertSame([], $out['warnings']);
    }

    /** @return array<string, array{0: string, 1: mixed, 2: array}> prop => [input, expected typed prop] */
    public function kind_coercion_cases(): array
    {
        return [
            'string'       => ['label', 'Hi', ['$$type' => 'string', 'value' => 'Hi']],
            'number'       => ['count', '7', ['$$type' => 'number', 'value' => 7]],
            'boolean'      => ['toggle', 1, ['$$type' => 'boolean', 'value' => true]],
            'html'         => ['markup', '<b>x</b>', ['$$type' => 'html', 'value' => '<b>x</b>']],
            'html-v3'      => ['rich', 'Legacy', ['$$type' => 'html-v3', 'value' => ['content' => ['$$type' => 'string', 'value' => 'Legacy'], 'children' => []]]],
            'url'          => ['href', 'https://example.com', ['$$type' => 'url', 'value' => 'https://example.com']],
            'color'        => ['shade', '#ff0000', ['$$type' => 'color', 'value' => '#ff0000']],
            'string array' => ['tags', ['a', 'b'], ['$$type' => 'string-array', 'value' => ['a', 'b']]],
            'size number'  => ['gap', 24, ['$$type' => 'size', 'value' => ['size' => 24.0, 'unit' => 'px']]],
            'size string'  => ['gap', '2.5rem', ['$$type' => 'size', 'value' => ['size' => 2.5, 'unit' => 'rem']]],
            'size object'  => ['gap', ['size' => 10, 'unit' => '%'], ['$$type' => 'size', 'value' => ['size' => 10.0, 'unit' => '%']]],
        ];
    }

    /**
     * @dataProvider kind_coercion_cases
     * @param mixed $input
     */
    public function test_plain_values_are_coerced_per_declared_kind(string $prop, $input, array $expected): void
    {
        $out = Atomic_Props::map('e-kinds', [$prop => $input]);

        $this->assertSame($expected, $out['settings'][ $prop ]);
        $this->assertSame([], $out['warnings']);
    }

    public function test_a_kind_the_mapper_cannot_build_is_dropped_with_a_warning(): void
    {
        $out = Atomic_Props::map('e-kinds', ['unknown' => '10px']);

        $this->assertSame([], $out['settings']);
        $this->assertStringContainsString('$$type "dimensions"', $out['warnings'][0]);
    }

    public function test_uncoercible_scalars_are_refused_per_kind(): void
    {
        $out = Atomic_Props::map('e-kinds', [
            'count' => 'not a number',
            'gap'   => 'wide',
            'tags'  => [['nested']],
        ]);

        $this->assertSame([], $out['settings']);
        $this->assertCount(3, $out['warnings']);
    }

    // ---- repair of malformed props -----------------------------------------

    public function test_wrongly_typed_prop_is_rewrapped_into_the_declared_type(): void
    {
        // The classic hand-written-JSON mistake: a plain string prop where
        // Elementor declares rich text.
        $out = Atomic_Props::map('e-heading', ['title' => ['$$type' => 'string', 'value' => 'Was flat']]);

        $this->assertSame(['$$type' => 'escaped-html', 'value' => 'Was flat'], $out['settings']['title']);
        $this->assertStringContainsString('Rewrapped "title"', $out['coerced'][0]);
        $this->assertSame([], $out['warnings']);
    }

    public function test_pre_4_3_html_v3_rich_text_is_rewrapped_as_escaped_html(): void
    {
        // Pages and prompts written against Elementor 4.0 to 4.2 carry the
        // nested html-v3 shape; 4.3 declares escaped-html for the same prop.
        $legacy = ['$$type' => 'html-v3', 'value' => ['content' => ['$$type' => 'string', 'value' => 'From 4.2'], 'children' => []]];

        $out = Atomic_Props::map('e-heading', ['title' => $legacy]);

        $this->assertSame(['$$type' => 'escaped-html', 'value' => 'From 4.2'], $out['settings']['title']);
        $this->assertStringContainsString('from $$type "html-v3" to "escaped-html"', $out['coerced'][0]);
        $this->assertSame([], $out['warnings']);
    }

    public function test_rich_text_follows_the_declared_kind(): void
    {
        $this->assertSame(['$$type' => 'escaped-html', 'value' => 'Hi'], Atomic_Props::rich_text('e-heading', 'title', 'Hi'));
        $this->assertSame('html-v3', Atomic_Props::rich_text('e-kinds', 'rich', 'Hi')['$$type']);
    }

    public function test_rich_text_without_schema_falls_back_on_the_elementor_version(): void
    {
        // An unreadable registry: no element type has metadata.
        Atomic_Prop_Schema::set_for_tests([]);

        $expected = Atomic_Props::fallback_rich_kind(defined('ELEMENTOR_VERSION') ? (string) ELEMENTOR_VERSION : null);
        $prop     = Atomic_Props::rich_text('e-heading', 'title', 'Hi');

        $this->assertSame($expected, $prop['$$type']);
        if ('escaped-html' === $expected) {
            $this->assertSame('Hi', $prop['value']);
        } else {
            $this->assertSame('Hi', $prop['value']['content']['value']);
        }
    }

    /** @return array<string, array{0: ?string, 1: string}> */
    public function fallback_versions(): array
    {
        return [
            'unknown'     => [null, 'html-v3'],
            'empty'       => ['', 'html-v3'],
            '4.0.0'       => ['4.0.0', 'html-v3'],
            '4.2.4'       => ['4.2.4', 'html-v3'],
            '4.3.0-beta1' => ['4.3.0-beta1', 'escaped-html'],
            '4.3.0'       => ['4.3.0', 'escaped-html'],
            '4.3.2'       => ['4.3.2', 'escaped-html'],
            '5.0.0'       => ['5.0.0', 'escaped-html'],
        ];
    }

    /** @dataProvider fallback_versions */
    public function test_fallback_rich_kind_per_elementor_version(?string $version, string $expected): void
    {
        $this->assertSame($expected, Atomic_Props::fallback_rich_kind($version));
    }

    public function test_escaped_html_is_rewrapped_as_html_v3_where_that_is_declared(): void
    {
        // The reverse, for a site still on Elementor 4.0 to 4.2.
        $out = Atomic_Props::map('e-kinds', ['rich' => ['$$type' => 'escaped-html', 'value' => 'From 4.3']]);

        $this->assertSame('html-v3', $out['settings']['rich']['$$type']);
        $this->assertSame('From 4.3', $out['settings']['rich']['value']['content']['value']);
        $this->assertSame([], $out['warnings']);
    }

    public function test_rich_text_prop_is_flattened_when_the_target_is_a_string(): void
    {
        $rich = ['$$type' => 'html-v3', 'value' => ['content' => ['$$type' => 'string', 'value' => 'h3'], 'children' => []]];

        $out = Atomic_Props::map('e-heading', ['tag' => $rich]);

        $this->assertSame(['$$type' => 'string', 'value' => 'h3'], $out['settings']['tag']);
    }

    public function test_enum_value_is_normalized_by_case(): void
    {
        $out = Atomic_Props::map('e-heading', ['tag' => 'H3']);

        $this->assertSame('h3', $out['settings']['tag']['value']);
        $this->assertStringContainsString('Normalized "tag"', implode(' ', $out['coerced']));
    }

    public function test_value_outside_the_declared_enum_is_kept_but_warned_about(): void
    {
        $out = Atomic_Props::map('e-heading', ['tag' => 'h9']);

        $this->assertSame('h9', $out['settings']['tag']['value']);
        $this->assertStringContainsString('outside the values', $out['warnings'][0]);
    }

    public function test_unreadable_prop_is_dropped_with_a_warning(): void
    {
        // An object where Elementor declares an image: nothing sensible to build.
        $out = Atomic_Props::map('e-image', ['image' => ['nonsense' => true]]);

        $this->assertArrayNotHasKey('image', $out['settings']);
        $this->assertStringContainsString('Dropped "image"', $out['warnings'][0]);
        $this->assertStringContainsString('$$type "image"', $out['warnings'][0]);
    }

    public function test_prop_the_element_does_not_declare_is_dropped_with_a_warning(): void
    {
        $out = Atomic_Props::map('e-heading', ['headline' => 'Nope']);

        $this->assertSame([], $out['settings']);
        $this->assertStringContainsString('no such prop', $out['warnings'][0]);
        $this->assertStringContainsString('title', $out['warnings'][0], 'The warning lists the real props.');
    }

    public function test_elementor_internal_keys_pass_through(): void
    {
        $out = Atomic_Props::map('e-heading', ['__globals__' => ['title' => 'globals://x']]);

        $this->assertSame(['title' => 'globals://x'], $out['settings']['__globals__']);
        $this->assertSame([], $out['warnings']);
    }

    public function test_unknown_element_types_still_get_inferred_typed_props(): void
    {
        $out = Atomic_Props::map('e-third-party', ['label' => 'Hello', 'count' => 3, 'raw' => ['a' => 1]]);

        $this->assertSame(['$$type' => 'string', 'value' => 'Hello'], $out['settings']['label']);
        $this->assertSame(['$$type' => 'number', 'value' => 3], $out['settings']['count']);
        $this->assertSame(['a' => 1], $out['settings']['raw'], 'Structural values are left exactly as sent.');
        $this->assertSame([], $out['warnings']);
    }

    // ---- the tools use the same mapper -------------------------------------

    public function test_add_atomic_widget_repairs_raw_settings_and_reports_it(): void
    {
        $post_id = $this->make_page([]);

        $out = (new Add_Atomic_Widget())->handle([
            'post_id'       => $post_id,
            'widget_type'   => 'e-heading',
            'settings'      => ['text' => 'Aliased and plain', 'tag' => 'H1'],
            'expected_hash' => $this->data_hash($post_id),
        ]);

        $this->assertIsArray($out);
        $settings = $this->tree($post_id)[0]['settings'];
        $this->assertSame(['$$type' => 'escaped-html', 'value' => 'Aliased and plain'], $settings['title']);
        $this->assertSame('h1', $settings['tag']['value']);
        $this->assertNotEmpty($out['coerced']);
    }

    public function test_add_atomic_widget_reports_dropped_props(): void
    {
        $post_id = $this->make_page([]);

        $out = (new Add_Atomic_Widget())->handle([
            'post_id'       => $post_id,
            'widget_type'   => 'e-heading',
            'settings'      => ['title' => 'Fine', 'bogus' => 'nope'],
            'expected_hash' => $this->data_hash($post_id),
        ]);

        $this->assertArrayNotHasKey('bogus', $this->tree($post_id)[0]['settings']);
        $this->assertStringContainsString('bogus', $out['warnings'][0]);
    }

    public function test_update_atomic_widget_repairs_a_patch(): void
    {
        $post_id = self::factory()->post->create(['post_type' => 'page']);
        update_post_meta($post_id, '_elementor_data', wp_json_encode([[
            'id'         => 'head001',
            'elType'     => 'widget',
            'widgetType' => 'e-heading',
            'settings'   => ['tag' => ['$$type' => 'string', 'value' => 'h2']],
            'elements'   => [],
        ]]));

        (new Update_Atomic_Widget())->handle([
            'post_id'       => $post_id,
            'element_id'    => 'head001',
            'settings'      => ['content' => 'Patched via alias'],
            'expected_hash' => $this->data_hash($post_id),
        ]);

        $settings = $this->tree($post_id)[0]['settings'];
        $this->assertSame('Patched via alias', $settings['title']['value']);
        $this->assertSame('h2', $settings['tag']['value'], 'Untouched props survive.');
    }

    public function test_update_atomic_widget_refuses_a_patch_that_maps_to_nothing(): void
    {
        $post_id = self::factory()->post->create(['post_type' => 'page']);
        update_post_meta($post_id, '_elementor_data', wp_json_encode([[
            'id'         => 'head001',
            'elType'     => 'widget',
            'widgetType' => 'e-heading',
            'settings'   => [],
            'elements'   => [],
        ]]));
        $before = get_post_meta($post_id, '_elementor_data', true);

        $out = (new Update_Atomic_Widget())->handle([
            'post_id'       => $post_id,
            'element_id'    => 'head001',
            'settings'      => ['not_a_prop' => 'x'],
            'expected_hash' => $this->data_hash($post_id),
        ]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('unmappable_settings', $out->get_error_code());
        $this->assertSame($before, get_post_meta($post_id, '_elementor_data', true), 'A refused patch writes nothing.');
    }
}
