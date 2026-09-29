<?php

namespace Bricks {
    if (! class_exists('Bricks\\Elements')) {
        /**
         * Stand-in for the Bricks element registry. Bricks keeps every
         * registered element in the static Elements::$elements array, keyed by
         * element name, each entry carrying its class, name, label, category,
         * controls and controlGroups (see Bricks includes/elements.php). Bricks
         * is not installable in the test environment, so the tests fill this
         * array the way the plugin does.
         */
        class Elements // phpcs:ignore
        {
            /** @var array<string,array<string,mixed>> */
            public static $elements = [];
        }
    }
}

namespace Breakdance\Elements {
    if (! class_exists('Breakdance\\Elements\\Element')) {
        /**
         * Stand-in for the Breakdance engine's element base class, which
         * Breakdance and Oxygen 6 elements extend (Element Studio output): a
         * set of static methods returning the element name, category and its
         * content, design and settings control trees.
         */
        abstract class Element // phpcs:ignore
        {
            public static function name()
            {
                return static::class;
            }

            public static function category()
            {
                return 'other';
            }

            public static function contentControls()
            {
                return [];
            }

            public static function designControls()
            {
                return [];
            }

            public static function settingsControls()
            {
                return [];
            }
        }
    }
}

namespace WPMCPTestElements {
    /** A Breakdance-style heading element, shaped like Element Studio output. */
    class Heading extends \Breakdance\Elements\Element // phpcs:ignore
    {
        public static function name()
        {
            return 'Heading';
        }

        public static function category()
        {
            return 'basic';
        }

        public static function contentControls()
        {
            return [[
                'slug'     => 'content',
                'label'    => 'Content',
                'options'  => ['type' => 'section'],
                'children' => [
                    ['slug' => 'text', 'label' => 'Text', 'options' => ['type' => 'text']],
                    ['slug' => 'tags', 'label' => 'Tag', 'options' => ['type' => 'dropdown', 'items' => [['value' => 'h1', 'text' => 'H1']]]],
                ],
            ]];
        }

        public static function designControls()
        {
            return [['slug' => 'typography', 'label' => 'Typography', 'options' => ['type' => 'section'], 'children' => []]];
        }
    }
}

namespace WPMCP\Tests\Pro\Builders {

    use WPMCP\Pro\Gate;
    use WPMCP\Tests\Free\Platform\RegisteredAbilities;
    use WPMCP\Tools\Builders\Get_Builder_Content;

    /**
     * get-builder-content's site-wide scopes (issue #391): the design system
     * (global classes, variables and color palettes), the local templates
     * (headers and footers included) and the element catalog with control
     * schemas, for Bricks, Breakdance and Oxygen 6.
     *
     * None of the three is installable in the test environment (all are
     * premium), so the stored rows are fixtures shaped from each builder's
     * documented storage: Bricks' options and template postmeta per the
     * Bricks data model reference, and the Breakdance engine's
     * `<prefix>*_json_string` options and per-product template post types.
     * The element registries are stubbed above the way each plugin exposes
     * them.
     */
    class BuilderDesignSystemTest extends \WP_UnitTestCase
    {
        protected function setUp(): void
        {
            parent::setUp();
            Gate::set_pro_for_tests(true);
            \Bricks\Elements::$elements = [];
        }

        protected function tearDown(): void
        {
            Gate::set_pro_for_tests(null);
            remove_all_filters('wpmcp_bricks_active');
            remove_all_filters('wpmcp_breakdance_active');
            remove_all_filters('wpmcp_oxygen_active');
            \Bricks\Elements::$elements = [];
            parent::tearDown();
        }

        private function read(array $args)
        {
            return (new Get_Builder_Content())->handle($args);
        }

        // ------------------------------------------------------------ Bricks

        public function test_bricks_design_system_reads_classes_variables_and_palettes(): void
        {
            $classes = [
                ['id' => 'kxqzcr', 'name' => 'btn-primary', 'settings' => ['_background' => ['color' => ['raw' => 'var(--primary)']], '_padding:mobile_portrait' => ['top' => '8']], 'modified' => 1717000000, 'user_id' => 1],
            ];
            $variables = [
                ['id' => 'v1a2b3', 'name' => 'space-m', 'value' => 'clamp(1rem, 2vw, 2rem)', 'category' => 'c1'],
            ];
            $palettes = [
                ['id' => 'p1', 'name' => 'Brand', 'colors' => [['id' => 'c01', 'raw' => 'var(--primary)', 'light' => '#1e40af', 'dark' => '#93c5fd', 'name' => 'primary']]],
            ];
            update_option('bricks_global_classes', $classes);
            update_option('bricks_global_classes_categories', [['id' => 'cc1', 'name' => 'Buttons']]);
            update_option('bricks_global_variables', $variables);
            update_option('bricks_global_variables_categories', [['id' => 'c1', 'name' => 'Spacing']]);
            update_option('bricks_color_palette', $palettes);

            $out = $this->read(['builder' => 'bricks', 'scope' => 'design_system']);

            $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
            $this->assertSame('bricks', $out['builder']);
            $this->assertSame('design_system', $out['scope']);
            $this->assertSame($classes, $out['classes']);
            $this->assertSame([['id' => 'cc1', 'name' => 'Buttons']], $out['class_categories']);
            $this->assertSame($variables, $out['variables']);
            $this->assertSame([['id' => 'c1', 'name' => 'Spacing']], $out['variable_categories']);
            $this->assertSame($palettes, $out['palettes']);
            $this->assertArrayHasKey('plugin_active', $out);
        }

        public function test_bricks_design_system_is_empty_lists_on_a_fresh_site(): void
        {
            $out = $this->read(['builder' => 'bricks', 'scope' => 'design_system']);

            $this->assertSame([], $out['classes']);
            $this->assertSame([], $out['variables']);
            $this->assertSame([], $out['palettes']);
        }

        public function test_bricks_templates_include_header_and_footer_areas(): void
        {
            $header_tree = [['id' => 'hdr001', 'name' => 'section', 'parent' => 0, 'children' => [], 'settings' => ['tag' => 'header']]];
            $footer_tree = [['id' => 'ftr001', 'name' => 'text-basic', 'parent' => 0, 'children' => [], 'settings' => ['text' => '(c) Acme']]];
            $section_tree = [['id' => 'sec001', 'name' => 'heading', 'parent' => 0, 'children' => [], 'settings' => ['text' => 'CTA']]];
            $conditions = ['templateConditions' => [['id' => 'tc1', 'main' => 'any']]];

            $header = self::factory()->post->create(['post_type' => 'bricks_template', 'post_title' => 'Main header', 'post_status' => 'publish']);
            update_post_meta($header, '_bricks_template_type', 'header');
            update_post_meta($header, '_bricks_page_header_2', $header_tree);
            update_post_meta($header, '_bricks_template_settings', $conditions);

            $footer = self::factory()->post->create(['post_type' => 'bricks_template', 'post_title' => 'Main footer', 'post_status' => 'publish']);
            update_post_meta($footer, '_bricks_template_type', 'footer');
            update_post_meta($footer, '_bricks_page_footer_2', $footer_tree);

            $section = self::factory()->post->create(['post_type' => 'bricks_template', 'post_title' => 'CTA', 'post_status' => 'draft']);
            update_post_meta($section, '_bricks_template_type', 'section');
            update_post_meta($section, '_bricks_page_content_2', $section_tree);

            $out = $this->read(['builder' => 'bricks', 'scope' => 'templates']);

            $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
            $by_id = array_column($out['templates'], null, 'id');
            $this->assertCount(3, $by_id);
            $this->assertSame('header', $by_id[$header]['type']);
            $this->assertSame('Main header', $by_id[$header]['title']);
            $this->assertSame($header_tree, $by_id[$header]['content']);
            $this->assertSame($conditions['templateConditions'], $by_id[$header]['conditions']);
            $this->assertSame('footer', $by_id[$footer]['type']);
            $this->assertSame($footer_tree, $by_id[$footer]['content']);
            $this->assertSame('section', $by_id[$section]['type']);
            $this->assertSame('draft', $by_id[$section]['status']);
            $this->assertSame($section_tree, $by_id[$section]['content']);
        }

        public function test_bricks_catalog_lists_elements_and_returns_one_elements_controls(): void
        {
            \Bricks\Elements::$elements = [
                'heading' => [
                    'class'         => 'Bricks\\Element_Heading',
                    'name'          => 'heading',
                    'label'         => 'Heading',
                    'category'      => 'basic',
                    'controls'      => ['tag' => ['tab' => 'content', 'label' => 'HTML tag', 'type' => 'select', 'options' => ['h1' => 'h1', 'h2' => 'h2']], 'text' => ['tab' => 'content', 'type' => 'text']],
                    'controlGroups' => ['link' => ['title' => 'Link', 'tab' => 'content']],
                ],
                'button' => [
                    'class'    => 'Bricks\\Element_Button',
                    'name'     => 'button',
                    'label'    => 'Button',
                    'category' => 'basic',
                    'controls' => ['text' => ['tab' => 'content', 'type' => 'text']],
                ],
            ];

            $list = $this->read(['builder' => 'bricks', 'scope' => 'catalog']);

            $this->assertIsArray($list, is_wp_error($list) ? $list->get_error_message() : '');
            $this->assertSame(
                [
                    ['name' => 'heading', 'label' => 'Heading', 'category' => 'basic'],
                    ['name' => 'button', 'label' => 'Button', 'category' => 'basic'],
                ],
                $list['elements']
            );

            $one = $this->read(['builder' => 'bricks', 'scope' => 'catalog', 'element' => 'heading']);

            $this->assertSame('heading', $one['element']['name']);
            $this->assertSame(\Bricks\Elements::$elements['heading']['controls'], $one['element']['controls']);
            $this->assertSame(\Bricks\Elements::$elements['heading']['controlGroups'], $one['element']['control_groups']);
        }

        public function test_catalog_refuses_an_unknown_element(): void
        {
            \Bricks\Elements::$elements = ['heading' => ['name' => 'heading', 'label' => 'Heading', 'category' => 'basic', 'controls' => []]];

            $out = $this->read(['builder' => 'bricks', 'scope' => 'catalog', 'element' => 'nope']);

            $this->assertInstanceOf(\WP_Error::class, $out);
            $this->assertSame('element_not_found', $out->get_error_code());
        }

        public function test_bricks_catalog_needs_the_plugin_loaded(): void
        {
            $out = $this->read(['builder' => 'bricks', 'scope' => 'catalog']);

            $this->assertInstanceOf(\WP_Error::class, $out);
            $this->assertSame('builder_not_loaded', $out->get_error_code());
        }

        // ------------------------------------------- Breakdance and Oxygen 6

        /** @return array<string,mixed> */
        private static function global_settings(): array
        {
            return ['settings' => ['colors' => ['brand' => '#0055ff', 'palette' => ['colors' => [['id' => 'palette-color-1', 'label' => 'Accent', 'value' => '#ff5500', 'cssVariableName' => 'palette-color-1']], 'gradients' => []]]]];
        }

        public function engine_builders(): array
        {
            return [
                'breakdance' => ['breakdance', 'breakdance_', 'breakdance_classes_json_string'],
                'oxygen'     => ['oxygen', 'oxygen_', 'oxy_selectors_json_string'],
            ];
        }

        /** @dataProvider engine_builders */
        public function test_engine_design_system_reads_selectors_variables_and_palette(string $builder, string $prefix, string $classes_key): void
        {
            $selectors = [['id' => 'sel-1', 'name' => 'card', 'type' => 'class', 'properties' => ['breakpoint_base' => ['background' => '#fff']]]];
            $variables = ['variables' => [['id' => 'var-1', 'label' => 'Space M', 'cssName' => 'space-m', 'type' => 'unit', 'value' => ['number' => 1, 'unit' => 'rem', 'style' => '1rem'], 'collection' => 'col-1']], 'collections' => [['id' => 'col-1', 'label' => 'Spacing']]];

            update_option($prefix . $classes_key, wp_json_encode($selectors));
            update_option($prefix . 'variables_json_string', wp_json_encode($variables));
            update_option($prefix . 'global_settings_json_string', wp_json_encode(self::global_settings()));

            $out = $this->read(['builder' => $builder, 'scope' => 'design_system']);

            $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
            $this->assertSame($builder, $out['builder']);
            $this->assertSame($selectors, $out['classes']);
            $this->assertSame($variables, $out['variables']);
            $this->assertSame([self::global_settings()['settings']['colors']['palette']], $out['palettes']);
            $this->assertSame(self::global_settings(), $out['global_settings']);
        }

        public function test_engine_design_system_keeps_the_products_apart(): void
        {
            update_option('breakdance_variables_json_string', wp_json_encode(['variables' => [['id' => 'bd']]]));

            $out = $this->read(['builder' => 'oxygen', 'scope' => 'design_system']);

            $this->assertSame([], $out['variables']);
            $this->assertSame([], $out['classes']);
            $this->assertSame([], $out['palettes']);
        }

        /** @dataProvider engine_builders */
        public function test_engine_templates_include_headers_footers_and_blocks(string $builder, string $prefix): void
        {
            $doc = static function (string $type, string $text): string {
                $tree = '{"root":{"id":1,"data":{"type":"root","properties":null},"children":[{"id":2,"data":{"type":"' . $type . '","properties":{"content":{"content":{"text":"' . $text . '"}}}},"children":[],"_parentId":1}]},"_nextNodeId":3,"status":"exported"}';
                return (string) wp_json_encode(['tree_json_string' => $tree]);
            };

            $ids = [];
            foreach (['header' => 'Top bar', 'footer' => 'Copyright', 'template' => 'Single post', 'block' => 'Newsletter'] as $kind => $text) {
                $ids[$kind] = self::factory()->post->create(['post_type' => $prefix . $kind, 'post_title' => ucfirst($kind), 'post_status' => 'publish']);
                add_post_meta($ids[$kind], '_' . $prefix . 'data', wp_slash($doc('EssentialElements\\\\Text', $text)));
            }
            add_post_meta($ids['header'], '_' . $prefix . 'template_settings', wp_slash((string) wp_json_encode(['type' => 'all', 'ruleGroups' => [[['ruleSlug' => 'everything']]]])));

            // A page is not a template, even with the same data row.
            $page = self::factory()->post->create(['post_type' => 'page']);
            add_post_meta($page, '_' . $prefix . 'data', wp_slash($doc('EssentialElements\\\\Text', 'Page')));

            $out = $this->read(['builder' => $builder, 'scope' => 'templates']);

            $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
            $by_id = array_column($out['templates'], null, 'id');
            $this->assertCount(4, $by_id);
            $this->assertArrayNotHasKey($page, $by_id);
            $this->assertSame('header', $by_id[$ids['header']]['type']);
            $this->assertSame('footer', $by_id[$ids['footer']]['type']);
            $this->assertSame('template', $by_id[$ids['template']]['type']);
            $this->assertSame('block', $by_id[$ids['block']]['type']);
            $this->assertSame(['type' => 'all', 'ruleGroups' => [[['ruleSlug' => 'everything']]]], $by_id[$ids['header']]['settings']);

            $header_tree = $by_id[$ids['header']]['tree'];
            // The same stored nodes a post read returns (Breakdance_Content::read).
            $this->assertSame(2, $header_tree[0]->id);
            $this->assertSame('Top bar', $header_tree[0]->data->properties->content->content->text);
        }

        /** @dataProvider engine_builders */
        public function test_engine_catalog_reads_element_classes_and_control_schemas(string $builder): void
        {
            add_filter('wpmcp_' . $builder . '_active', '__return_true');

            $list = $this->read(['builder' => $builder, 'scope' => 'catalog']);

            $this->assertIsArray($list, is_wp_error($list) ? $list->get_error_message() : '');
            $types = array_column($list['elements'], null, 'type');
            $this->assertArrayHasKey('WPMCPTestElements\\Heading', $types);
            $this->assertSame(['type' => 'WPMCPTestElements\\Heading', 'name' => 'Heading', 'category' => 'basic'], $types['WPMCPTestElements\\Heading']);

            $one = $this->read(['builder' => $builder, 'scope' => 'catalog', 'element' => 'WPMCPTestElements\\Heading']);

            $this->assertSame('Heading', $one['element']['name']);
            $this->assertSame(\WPMCPTestElements\Heading::contentControls(), $one['element']['controls']['content']);
            $this->assertSame(\WPMCPTestElements\Heading::designControls(), $one['element']['controls']['design']);
            $this->assertSame([], $one['element']['controls']['settings']);
        }

        public function test_engine_catalog_needs_the_plugin_loaded(): void
        {
            $out = $this->read(['builder' => 'breakdance', 'scope' => 'catalog']);

            $this->assertInstanceOf(\WP_Error::class, $out);
            $this->assertSame('builder_not_loaded', $out->get_error_code());
        }

        // ------------------------------------------------------ arguments

        public function test_scope_infers_the_builder_from_a_post(): void
        {
            update_option('bricks_global_classes', [['id' => 'a', 'name' => 'x', 'settings' => []]]);
            $post_id = self::factory()->post->create(['post_type' => 'page']);
            update_post_meta($post_id, '_bricks_page_content_2', [['id' => 'e1', 'name' => 'section', 'children' => []]]);

            $out = $this->read(['post_id' => $post_id, 'scope' => 'design_system']);

            $this->assertSame('bricks', $out['builder']);
            $this->assertSame([['id' => 'a', 'name' => 'x', 'settings' => []]], $out['classes']);
        }

        public function test_unknown_scope_is_refused(): void
        {
            $out = $this->read(['builder' => 'bricks', 'scope' => 'everything']);

            $this->assertInstanceOf(\WP_Error::class, $out);
            $this->assertSame('invalid_scope', $out->get_error_code());
        }

        public function test_scope_refuses_a_builder_without_design_data(): void
        {
            $out = $this->read(['builder' => 'divi', 'scope' => 'design_system']);

            $this->assertInstanceOf(\WP_Error::class, $out);
            $this->assertSame('unsupported_builder', $out->get_error_code());
        }

        public function test_scope_without_builder_or_post_is_refused(): void
        {
            $out = $this->read(['scope' => 'templates']);

            $this->assertInstanceOf(\WP_Error::class, $out);
            $this->assertSame('missing_builder', $out->get_error_code());
        }

        public function test_without_scope_a_post_id_is_still_required(): void
        {
            $out = $this->read(['builder' => 'bricks']);

            $this->assertInstanceOf(\WP_Error::class, $out);
            $this->assertSame('missing_post_id', $out->get_error_code());
        }

        public function test_registered_schema_advertises_scope_builder_and_element(): void
        {
            $ability = null;
            foreach (RegisteredAbilities::all() as $candidate) {
                if ('wpmcp/get-builder-content' === $candidate->name) {
                    $ability = $candidate;
                }
            }

            $this->assertNotNull($ability);
            $this->assertTrue($ability->read_only_hint);
            $props = $ability->input_schema['properties'];
            $this->assertArrayHasKey('scope', $props);
            $this->assertArrayHasKey('builder', $props);
            $this->assertArrayHasKey('element', $props);
            $this->assertNotContains('post_id', $ability->input_schema['required'] ?? []);
            $this->assertStringContainsString('design_system', $ability->description);
        }
    }
}
