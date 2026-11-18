<?php

namespace WPMCP\Tests\Pro\BlockBuilder;

use WPMCP\Pro\Gate;
use WPMCP\Tools\BlockBuilder\Block_Registry;
use WPMCP\Tools\BlockBuilder\Block_Renderer;
use WPMCP\Tools\BlockBuilder\Block_Spec;
use WPMCP\Tools\BlockBuilder\Block_Spec_Store;
use WPMCP\Tools\BlockBuilder\Create_Custom_Block;
use WPMCP\Tools\BlockBuilder\List_Block_Control_Types;

/**
 * Issue #852: select, repeater, media and link attribute types, block
 * supports (color, spacing, typography, border, shadow, align) and named
 * sidebar panels for data-driven custom blocks. Still no eval: every value is
 * escaped by its declared type, repeater items by their sub-field types.
 */
class CustomBlockAttributeTypesTest extends \WP_UnitTestCase
{
    /** @var string[] */
    private $registered = [];

    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    protected function tearDown(): void
    {
        $registry = \WP_Block_Type_Registry::get_instance();
        foreach ($this->registered as $name) {
            if ($registry->is_registered($name)) {
                unregister_block_type($name);
            }
        }
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function features_spec(): array
    {
        return [
            'name'       => 'features-852',
            'title'      => 'Features',
            'panels'     => [
                ['title' => 'Layout', 'help' => 'How the list is arranged.'],
                ['title' => 'Items'],
            ],
            'attributes' => [
                [
                    'name'    => 'layout',
                    'type'    => 'select',
                    'label'   => 'Layout',
                    'panel'   => 'Layout',
                    'help'    => 'Grid or list.',
                    'options' => [['value' => 'grid', 'label' => 'Grid'], 'list'],
                    'default' => 'grid',
                ],
                [
                    'name'   => 'items',
                    'type'   => 'repeater',
                    'label'  => 'Features',
                    'panel'  => 'Items',
                    'min'    => 1,
                    'max'    => 3,
                    'fields' => [
                        ['name' => 'title', 'type' => 'string', 'label' => 'Title'],
                        ['name' => 'body', 'type' => 'richtext', 'label' => 'Body'],
                        ['name' => 'icon', 'type' => 'media', 'label' => 'Icon'],
                        ['name' => 'featured', 'type' => 'boolean', 'label' => 'Featured'],
                    ],
                    'default' => [['title' => 'Fast', 'body' => 'Quick.']],
                ],
                ['name' => 'photo', 'type' => 'media', 'label' => 'Photo'],
                ['name' => 'cta', 'type' => 'link', 'label' => 'Call to action'],
            ],
            'template'   => '<div class="features layout-{{layout}}">{{photo}}<ul>{{#items}}<li>{{#featured}}<b>*</b>{{/featured}}<h3>{{title}}</h3>{{body}}{{icon.url}}</li>{{/items}}</ul>{{cta}}</div>',
        ];
    }

    // ---- validation ---------------------------------------------------------

    public function test_spec_with_new_types_and_panels_validates(): void
    {
        $this->assertTrue(Block_Spec::validate($this->features_spec()));
    }

    public function test_select_needs_options_and_a_known_default(): void
    {
        $spec = $this->features_spec();
        unset($spec['attributes'][0]['options']);
        $this->assertInstanceOf(\WP_Error::class, Block_Spec::validate($spec));

        $spec = $this->features_spec();
        $spec['attributes'][0]['default'] = 'carousel';
        $this->assertInstanceOf(\WP_Error::class, Block_Spec::validate($spec));
    }

    public function test_repeater_needs_typed_fields_and_sane_bounds(): void
    {
        $spec = $this->features_spec();
        $spec['attributes'][1]['fields'] = [];
        $this->assertInstanceOf(\WP_Error::class, Block_Spec::validate($spec));

        $spec = $this->features_spec();
        $spec['attributes'][1]['fields'][0]['type'] = 'bogus';
        $this->assertInstanceOf(\WP_Error::class, Block_Spec::validate($spec));

        // No repeater inside a repeater.
        $spec = $this->features_spec();
        $spec['attributes'][1]['fields'][] = ['name' => 'sub', 'type' => 'repeater', 'label' => 'Sub', 'fields' => [['name' => 'x', 'type' => 'string', 'label' => 'X']]];
        $this->assertInstanceOf(\WP_Error::class, Block_Spec::validate($spec));

        $spec = $this->features_spec();
        $spec['attributes'][1]['min'] = 5;
        $this->assertInstanceOf(\WP_Error::class, Block_Spec::validate($spec));
    }

    public function test_reserved_attribute_names_are_refused(): void
    {
        foreach (['style', 'className', 'align', 'anchor', 'lock', 'metadata', 'backgroundColor'] as $reserved) {
            $spec = $this->features_spec();
            $spec['attributes'][2]['name'] = $reserved;
            $err  = Block_Spec::validate($spec);
            $this->assertInstanceOf(\WP_Error::class, $err, $reserved);
            $this->assertStringContainsString('reserved', $err->get_error_message());
        }
    }

    public function test_supports_are_validated(): void
    {
        $spec             = $this->features_spec();
        $spec['supports'] = ['color' => true, 'spacing' => ['padding' => true], 'typography' => true, 'border' => true, 'shadow' => true, 'align' => ['wide', 'full']];
        $this->assertTrue(Block_Spec::validate($spec));

        $spec['supports'] = ['html' => true];
        $this->assertInstanceOf(\WP_Error::class, Block_Spec::validate($spec));

        $spec['supports'] = ['align' => ['sideways']];
        $this->assertInstanceOf(\WP_Error::class, Block_Spec::validate($spec));
    }

    public function test_control_types_lists_new_types_and_supports(): void
    {
        $out   = (new List_Block_Control_Types())->handle([]);
        $types = array_column($out['attribute_types'], 'type');
        foreach (['select', 'repeater', 'media', 'link'] as $type) {
            $this->assertContains($type, $types);
        }
        $this->assertContains('color', $out['supports']);
        $this->assertArrayHasKey('template_syntax', $out);
    }

    // ---- rendering ------------------------------------------------------------

    public function test_repeater_renders_each_item_escaped_and_capped_at_max(): void
    {
        $items = [];
        for ($i = 0; $i < 5; $i++) {
            $items[] = ['title' => 'T' . $i . '<script>x()</script>', 'body' => '<em>ok</em><script>bad()</script>', 'icon' => ['url' => 'javascript:alert(1)'], 'featured' => 0 === $i];
        }
        $html = Block_Renderer::render($this->features_spec(), ['items' => $items, 'layout' => 'list']);

        $this->assertSame(3, substr_count($html, '<li>'), 'max caps the rendered items');
        $this->assertSame(1, substr_count($html, '<b>*</b>'), 'boolean section renders only when true');
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('<em>ok</em>', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringContainsString('layout-list', $html);
    }

    public function test_item_values_are_not_reinterpreted_as_placeholders(): void
    {
        $html = Block_Renderer::render($this->features_spec(), ['items' => [['title' => '{{layout}}']], 'layout' => 'list']);
        $this->assertStringContainsString('<h3>{{layout}}</h3>', $html);
    }

    public function test_select_falls_back_to_default_for_unknown_value(): void
    {
        $html = Block_Renderer::render($this->features_spec(), ['layout' => '"><script>x</script>']);
        $this->assertStringContainsString('layout-grid', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function test_repeater_default_items_render(): void
    {
        $html = Block_Renderer::render($this->features_spec(), []);
        $this->assertStringContainsString('<h3>Fast</h3>Quick.', $html);
    }

    public function test_media_and_link_render_escaped_markup(): void
    {
        $html = Block_Renderer::render($this->features_spec(), [
            'photo' => ['id' => 7, 'url' => 'https://e.com/a.jpg', 'alt' => 'A "quoted" alt'],
            'cta'   => ['text' => 'Go <b>now</b>', 'url' => 'https://e.com/go', 'target' => '_blank'],
        ]);
        $this->assertStringContainsString('<img src="https://e.com/a.jpg" alt="A &quot;quoted&quot; alt"', $html);
        $this->assertStringContainsString('<a href="https://e.com/go" target="_blank" rel="noopener noreferrer">Go &lt;b&gt;now&lt;/b&gt;</a>', $html);

        $html = Block_Renderer::render($this->features_spec(), ['cta' => ['text' => 'x', 'url' => 'javascript:alert(1)', 'target' => '_evil']]);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('_evil', $html);
    }

    public function test_media_subkeys(): void
    {
        $spec             = $this->features_spec();
        $spec['template'] = '{{photo.id}}|{{photo.alt}}|{{photo.url}}|{{cta.url}}|{{cta.text}}';
        $html             = Block_Renderer::render($spec, [
            'photo' => ['id' => '12abc', 'url' => 'https://e.com/p.png', 'alt' => '<x>'],
            'cta'   => ['text' => 'Hi', 'url' => 'https://e.com'],
        ]);
        $this->assertSame('12|&lt;x&gt;|https://e.com/p.png|https://e.com|Hi', $html);
    }

    // ---- registration and supports ----------------------------------------------

    private function register(array $spec): string
    {
        $out = (new Create_Custom_Block())->handle(['spec' => $spec]);
        $this->assertIsArray($out);
        $this->registered[] = $out['name'];
        Block_Registry::register();
        return $out['name'];
    }

    public function test_registry_maps_new_types_and_supports(): void
    {
        $spec             = $this->features_spec();
        $spec['supports'] = ['color' => true, 'spacing' => true, 'align' => ['wide', 'full']];
        $name             = $this->register($spec);

        $type = \WP_Block_Type_Registry::get_instance()->get_registered($name);
        $this->assertSame('string', $type->attributes['layout']['type']);
        $this->assertSame('array', $type->attributes['items']['type']);
        $this->assertSame('object', $type->attributes['photo']['type']);
        $this->assertSame('object', $type->attributes['cta']['type']);
        $this->assertTrue($type->supports['color']['background']);
        $this->assertTrue($type->supports['spacing']['padding']);
        $this->assertSame(['wide', 'full'], $type->supports['align']);
        // WordPress adds the supports' own attributes on init priority 22.
        \WP_Block_Supports::init();
        $this->assertArrayHasKey('style', $type->attributes, 'block supports add the style attribute');
        $this->assertContains('wpmcp-custom-blocks-editor', $type->editor_script_handles);
    }

    public function test_supports_produce_classes_and_styles_on_the_front_end(): void
    {
        $spec             = $this->features_spec();
        $spec['name']     = 'styled-852';
        $spec['supports'] = ['color' => true, 'spacing' => true, 'border' => true, 'typography' => true];
        $name             = $this->register($spec);

        $html = do_blocks('<!-- wp:' . $name . ' {"backgroundColor":"vivid-red","style":{"spacing":{"padding":{"top":"12px"}},"color":{"text":"#112233"},"border":{"width":"2px"},"typography":{"lineHeight":"1.7"}}} /-->');

        $this->assertStringContainsString('has-vivid-red-background-color', $html);
        $this->assertStringContainsString('has-background', $html);
        $this->assertStringContainsString('padding-top:12px', $html);
        $this->assertStringContainsString('color:#112233', $html);
        $this->assertStringContainsString('border-width:2px', $html);
        $this->assertStringContainsString('line-height:1.7', $html);
        $this->assertStringContainsString('wp-block-wpmcp-styled-852', $html);
    }

    public function test_existing_block_without_supports_renders_unchanged(): void
    {
        $legacy = [
            'name'       => 'legacy-852',
            'title'      => 'Legacy',
            'attributes' => [['name' => 'heading', 'type' => 'string', 'label' => 'Heading', 'default' => 'Hi']],
            'template'   => '<p>{{heading}}</p>',
        ];
        $name = $this->register($legacy);
        $this->assertSame('<p>Hi</p>', do_blocks('<!-- wp:' . $name . ' /-->'));
    }

    public function test_legacy_spec_with_a_now_reserved_name_still_registers(): void
    {
        // Stored before #852 refused reserved names: it must keep working.
        $id = Block_Spec_Store::create([
            'name'       => 'old-anchor-852',
            'title'      => 'Old',
            'attributes' => [['name' => 'anchor', 'type' => 'string', 'label' => 'Anchor', 'default' => 'top']],
            'template'   => '<a id="{{anchor}}"></a>',
        ]);
        $this->assertIsInt($id);
        $this->registered[] = 'wpmcp/old-anchor-852';
        Block_Registry::register();
        $this->assertTrue(\WP_Block_Type_Registry::get_instance()->is_registered('wpmcp/old-anchor-852'));
    }

    public function test_editor_payload_carries_controls_not_the_template(): void
    {
        $spec = $this->features_spec();
        $name = $this->register($spec);

        $payload = Block_Registry::editor_payload();
        $entry   = null;
        foreach ($payload as $block) {
            if ($block['name'] === $name) {
                $entry = $block;
            }
        }
        $this->assertNotNull($entry);
        $this->assertArrayNotHasKey('template', $entry);
        $this->assertSame('Layout', $entry['panels'][0]['title']);
        $this->assertSame('How the list is arranged.', $entry['panels'][0]['help']);
        $this->assertSame([['value' => 'grid', 'label' => 'Grid'], ['value' => 'list', 'label' => 'list']], $entry['attributes'][0]['options']);
        $this->assertSame(3, $entry['attributes'][1]['max']);
    }
}
