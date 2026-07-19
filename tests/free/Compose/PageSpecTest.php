<?php

namespace WPMCP\Tests\Free\Compose;

use WPMCP\Tools\Compose\Page_Spec;

/**
 * Structural validation of the declarative build-page spec (issue #57).
 *
 * Page_Spec::validate() is a pure structural validator: it must accept or
 * reject a spec, with node-path-addressed error messages, WITHOUT touching
 * the database. Referential checks (menu exists, attachment exists, pattern
 * registered, widget known) belong to Build_Page's preflight, not here.
 */
class PageSpecTest extends \WP_UnitTestCase
{
    private function valid_spec(array $overrides = []): array
    {
        return array_merge([
            'title'   => 'About Us',
            'content' => [
                ['type' => 'heading', 'settings' => ['text' => 'Hello', 'level' => 2]],
                ['type' => 'paragraph', 'settings' => ['text' => 'World']],
            ],
        ], $overrides);
    }

    private function assertRejected(array $spec, string $message_fragment): void
    {
        try {
            Page_Spec::validate($spec);
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString(
                $message_fragment,
                $e->getMessage(),
                'Rejection reason should mention: ' . $message_fragment
            );
            return;
        }
        $this->fail('Expected the spec to be rejected (' . $message_fragment . ').');
    }

    public function test_valid_spec_normalizes_with_defaults(): void
    {
        $spec = Page_Spec::validate($this->valid_spec());

        $this->assertSame('About Us', $spec['title']);
        $this->assertSame('draft', $spec['status']);
        $this->assertSame('gutenberg', $spec['dialect']);
        $this->assertCount(2, $spec['content']);
    }

    public function test_title_is_required(): void
    {
        $this->assertRejected(['content' => [['type' => 'paragraph', 'settings' => ['text' => 'x']]]], 'title');
        $this->assertRejected($this->valid_spec(['title' => '   ']), 'title');
    }

    public function test_content_must_be_a_non_empty_array(): void
    {
        $this->assertRejected(['title' => 'X'], 'content');
        $this->assertRejected($this->valid_spec(['content' => []]), 'content');
    }

    public function test_unknown_top_level_keys_are_rejected(): void
    {
        $this->assertRejected($this->valid_spec(['surprise' => true]), 'surprise');
    }

    public function test_invalid_status_and_dialect_are_rejected(): void
    {
        $this->assertRejected($this->valid_spec(['status' => 'trash']), 'status');
        $this->assertRejected($this->valid_spec(['dialect' => 'divi']), 'dialect');
    }

    public function test_unknown_node_type_error_is_node_path_addressed(): void
    {
        $spec = $this->valid_spec([
            'content' => [
                ['type' => 'paragraph', 'settings' => ['text' => 'ok']],
                ['type' => 'group', 'children' => [
                    ['type' => 'megazord'],
                ]],
            ],
        ]);

        $this->assertRejected($spec, 'content[1].children[0]');
    }

    public function test_unknown_node_keys_are_rejected(): void
    {
        $spec = $this->valid_spec([
            'content' => [
                ['type' => 'paragraph', 'settings' => ['text' => 'x'], 'onclick' => 'evil()'],
            ],
        ]);

        $this->assertRejected($spec, 'content[0]');
    }

    public function test_unknown_settings_keys_are_rejected(): void
    {
        $spec = $this->valid_spec([
            'content' => [
                ['type' => 'heading', 'settings' => ['text' => 'x', 'onload' => 'evil()']],
            ],
        ]);

        $this->assertRejected($spec, 'onload');
    }

    public function test_heading_level_is_bounded(): void
    {
        $spec = $this->valid_spec([
            'content' => [['type' => 'heading', 'settings' => ['text' => 'x', 'level' => 7]]],
        ]);

        $this->assertRejected($spec, 'level');
    }

    public function test_leaf_nodes_may_not_have_children(): void
    {
        $spec = $this->valid_spec([
            'content' => [
                ['type' => 'paragraph', 'settings' => ['text' => 'x'], 'children' => [
                    ['type' => 'paragraph', 'settings' => ['text' => 'y']],
                ]],
            ],
        ]);

        $this->assertRejected($spec, 'children');
    }

    public function test_column_must_live_inside_columns(): void
    {
        $this->assertRejected(
            $this->valid_spec(['content' => [['type' => 'column', 'children' => []]]]),
            'column'
        );

        // And columns children must all be columns.
        $this->assertRejected(
            $this->valid_spec(['content' => [
                ['type' => 'columns', 'children' => [
                    ['type' => 'paragraph', 'settings' => ['text' => 'x']],
                ]],
            ]]),
            'content[0].children[0]'
        );
    }

    public function test_buttons_children_must_be_buttons(): void
    {
        $this->assertRejected(
            $this->valid_spec(['content' => [
                ['type' => 'buttons', 'children' => [
                    ['type' => 'paragraph', 'settings' => ['text' => 'x']],
                ]],
            ]]),
            'content[0].children[0]'
        );
    }

    public function test_list_requires_non_empty_string_items(): void
    {
        $this->assertRejected(
            $this->valid_spec(['content' => [['type' => 'list', 'settings' => ['items' => []]]]]),
            'items'
        );
        $this->assertRejected(
            $this->valid_spec(['content' => [['type' => 'list', 'settings' => ['items' => [['nested' => 'array']]]]]]),
            'items'
        );
    }

    public function test_image_requires_attachment_id_or_url(): void
    {
        $this->assertRejected(
            $this->valid_spec(['content' => [['type' => 'image', 'settings' => ['alt' => 'x']]]]),
            'image'
        );
    }

    public function test_pattern_nodes_are_top_level_only(): void
    {
        $spec = $this->valid_spec([
            'content' => [
                ['type' => 'group', 'children' => [
                    ['type' => 'pattern', 'settings' => ['slug' => 'core/whatever']],
                ]],
            ],
        ]);

        $this->assertRejected($spec, 'pattern');
    }

    public function test_menu_placement_requires_menu_id(): void
    {
        $this->assertRejected($this->valid_spec(['menu' => ['title' => 'Nav label']]), 'menu_id');
    }

    public function test_media_featured_must_be_a_positive_integer(): void
    {
        $this->assertRejected($this->valid_spec(['media' => ['featured' => 'twelve']]), 'featured');
    }

    public function test_total_node_count_is_bounded(): void
    {
        $leaves = [];
        for ($i = 0; $i < Page_Spec::MAX_NODES; $i++) {
            $leaves[] = ['type' => 'paragraph', 'settings' => ['text' => 'n' . $i]];
        }

        $this->assertRejected(
            $this->valid_spec(['content' => [['type' => 'group', 'children' => $leaves]]]),
            (string) Page_Spec::MAX_NODES
        );
    }

    public function test_top_level_section_count_is_bounded(): void
    {
        $sections = [];
        for ($i = 0; $i < Page_Spec::MAX_SECTIONS + 1; $i++) {
            $sections[] = ['type' => 'paragraph', 'settings' => ['text' => 's' . $i]];
        }

        $this->assertRejected($this->valid_spec(['content' => $sections]), 'sections');
    }

    public function test_nesting_depth_is_bounded(): void
    {
        $node = ['type' => 'paragraph', 'settings' => ['text' => 'deep']];
        for ($i = 0; $i < Page_Spec::MAX_DEPTH + 1; $i++) {
            $node = ['type' => 'group', 'children' => [$node]];
        }

        $this->assertRejected($this->valid_spec(['content' => [$node]]), 'depth');
    }

    public function test_spec_payload_size_is_bounded(): void
    {
        $spec = $this->valid_spec([
            'content' => [
                ['type' => 'html', 'settings' => ['html' => str_repeat('a', Page_Spec::MAX_BYTES)]],
            ],
        ]);

        $this->assertRejected($spec, 'large');
    }

    public function test_elementor_dialect_validates_builder_nodes(): void
    {
        $spec = Page_Spec::validate([
            'title'   => 'Builder Page',
            'dialect' => 'elementor',
            'content' => [
                ['type' => 'container', 'settings' => ['flex_direction' => 'row'], 'children' => [
                    ['type' => 'container', 'children' => [
                        ['type' => 'widget', 'settings' => ['widget' => 'heading', 'widget_settings' => ['title' => 'Hi']]],
                    ]],
                ]],
            ],
        ]);

        $this->assertSame('elementor', $spec['dialect']);
    }

    public function test_elementor_dialect_rejects_gutenberg_nodes_with_path(): void
    {
        $this->assertRejected(
            [
                'title'   => 'Builder Page',
                'dialect' => 'elementor',
                'content' => [
                    ['type' => 'container', 'children' => [
                        ['type' => 'paragraph', 'settings' => ['text' => 'x']],
                    ]],
                ],
            ],
            'content[0].children[0]'
        );
    }

    public function test_elementor_widget_requires_widget_name(): void
    {
        $this->assertRejected(
            [
                'title'   => 'Builder Page',
                'dialect' => 'elementor',
                'content' => [['type' => 'widget', 'settings' => []]],
            ],
            'widget'
        );
    }

    public function test_gutenberg_dialect_rejects_builder_nodes(): void
    {
        $this->assertRejected(
            $this->valid_spec(['content' => [['type' => 'container', 'children' => []]]]),
            'container'
        );
    }
}
