<?php

namespace WPMCP\Tests\Pro\Builders;

use WPMCP\Tools\Builders\Beaver_Builder_Nodes;

/**
 * Tree and editor coverage for the Beaver Builder node map, against a
 * fixture shaped like what the Beaver Builder editor saves in
 * `_fl_builder_data`: a flat array keyed by 12-character node ids of stdClass
 * nodes (row, column-group, column, module) with parent and position, nested
 * columns, a global (saved) module carrying template ids, repeater settings
 * as arrays of objects and a backslash in a text setting. Pure array work, so
 * the plugin never needs to be installed.
 */
class BeaverBuilderNodesTest extends \WP_UnitTestCase
{
    /**
     * The fixture as Beaver Builder serializes it into postmeta, in the map
     * order its editor writes (depth first).
     */
    public static function fixture_serialized(): string
    {
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
        return serialize(self::fixture());
    }

    /** @return array<string,object> */
    public static function fixture(): array
    {
        $n = static function (string $id, string $type, ?string $parent, int $position, array $settings, array $extra = []): object {
            $node           = new \stdClass();
            $node->node     = $id;
            $node->type     = $type;
            $node->parent   = $parent;
            $node->position = $position;
            $node->settings = json_decode((string) wp_json_encode((object) $settings));
            foreach ($extra as $key => $value) {
                $node->{$key} = $value;
            }
            return $node;
        };

        $nodes = [
            $n('r4k8p2m6x1qa', 'row', null, 0, ['width' => 'fixed', 'bg_type' => 'color', 'bg_color' => 'f7f7f7', 'padding_top' => '40', 'id' => '', 'class' => '']),
            $n('g7d2k9s4v1nb', 'column-group', 'r4k8p2m6x1qa', 0, []),
            $n('c1h5j8w3e6tc', 'column', 'g7d2k9s4v1nb', 0, ['size' => '50', 'content_alignment' => 'top']),
            $n('m2f6q9z3b7ud', 'module', 'c1h5j8w3e6tc', 0, ['type' => 'heading', 'heading' => 'Welcome', 'tag' => 'h2', 'link' => '']),
            $n('m8y3n5c1p4ve', 'module', 'c1h5j8w3e6tc', 1, ['type' => 'rich-text', 'text' => '<p>Files live in C:\\Sites\\bb and say "hi"</p>']),
            $n('c9t4r7a2k5wf', 'column', 'g7d2k9s4v1nb', 1, ['size' => '50']),
            $n('m5b1x8h3j6yg', 'module', 'c9t4r7a2k5wf', 0, ['type' => 'photo', 'photo' => 123, 'photo_src' => 'https://example.com/a.jpg', 'crop' => ''], ['template_id' => '5f3a9c1b2d4e6', 'template_node_id' => 'm5b1x8h3j6yg']),
            $n('r2w6e9q4t8zh', 'row', null, 1, ['width' => 'full']),
            $n('g3s8d1f5g9ai', 'column-group', 'r2w6e9q4t8zh', 0, []),
            $n('c6z2x7v4b1bj', 'column', 'g3s8d1f5g9ai', 0, ['size' => '100']),
            $n('m7n4m1k8j2ck', 'module', 'c6z2x7v4b1bj', 0, ['type' => 'icon-group', 'icons' => [['icon' => 'fab fa-x', 'link' => 'https://x.com'], ['icon' => 'fab fa-github', 'link' => '']], 'spacing' => '10']),
            $n('g4l9k2j7h3dl', 'column-group', 'c6z2x7v4b1bj', 1, []),
            $n('c8p3o6i1u5em', 'column', 'g4l9k2j7h3dl', 0, ['size' => '100']),
            $n('m1q7w4e9r2fn', 'module', 'c8p3o6i1u5em', 0, ['type' => 'button', 'text' => 'Book now', 'link' => 'https://example.com/book']),
        ];

        $map = [];
        foreach ($nodes as $node) {
            $map[$node->node] = $node;
        }

        return $map;
    }

    private static function ser(array $nodes): string
    {
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
        return serialize($nodes);
    }

    public function test_tree_nests_by_parent_and_position_and_keeps_extra_properties(): void
    {
        $tree = Beaver_Builder_Nodes::tree(self::fixture());

        $this->assertCount(2, $tree);
        $this->assertSame('r4k8p2m6x1qa', $tree[0]['node']);
        $this->assertSame('row', $tree[0]['type']);

        $column = $tree[0]['children'][0]['children'][0];
        $this->assertSame('column', $column['type']);
        $this->assertSame(['heading', 'rich-text'], array_map(static fn ($m) => $m['settings']->type, $column['children']));
        $this->assertArrayNotHasKey('children', $column['children'][0]);

        $photo = $tree[0]['children'][0]['children'][1]['children'][0];
        $this->assertSame('5f3a9c1b2d4e6', $photo['template_id']);
        $this->assertArrayNotHasKey('parent', $photo);
        $this->assertArrayNotHasKey('position', $photo);

        // Nested column group inside a column.
        $this->assertSame('button', $tree[1]['children'][0]['children'][0]['children'][1]['children'][0]['children'][0]['settings']->type);
    }

    public function test_children_are_ordered_by_position_not_map_order(): void
    {
        $nodes = self::fixture();
        $nodes['m2f6q9z3b7ud']->position = 1;
        $nodes['m8y3n5c1p4ve']->position = 0;

        $this->assertSame(['m8y3n5c1p4ve', 'm2f6q9z3b7ud'], Beaver_Builder_Nodes::child_ids($nodes, 'c1h5j8w3e6tc'));
    }

    public function test_tree_round_trips_to_the_identical_serialized_map(): void
    {
        $tree = json_decode((string) wp_json_encode(Beaver_Builder_Nodes::tree(self::fixture())));

        $this->assertSame(self::fixture_serialized(), self::ser(Beaver_Builder_Nodes::from_tree($tree)));
    }

    public function test_update_merges_settings_removes_null_keys_and_touches_nothing_else(): void
    {
        $before = self::fixture();
        $after  = Beaver_Builder_Nodes::update($before, 'm2f6q9z3b7ud', ['heading' => 'Hello', 'link' => null, 'responsive' => ['size' => '24']]);

        $this->assertSame('Hello', $after['m2f6q9z3b7ud']->settings->heading);
        $this->assertObjectNotHasProperty('link', $after['m2f6q9z3b7ud']->settings);
        $this->assertInstanceOf(\stdClass::class, $after['m2f6q9z3b7ud']->settings->responsive);
        $this->assertSame('heading', $after['m2f6q9z3b7ud']->settings->type);
        // The caller's map is untouched, and so is every other node.
        $this->assertSame(self::fixture_serialized(), self::ser($before));
        unset($before['m2f6q9z3b7ud'], $after['m2f6q9z3b7ud']);
        $this->assertSame(self::ser($before), self::ser($after));
    }

    public function test_add_generates_a_beaver_style_id_and_renumbers_siblings(): void
    {
        [$nodes, $id] = Beaver_Builder_Nodes::add(self::fixture(), 'c1h5j8w3e6tc', 1, [
            'type'     => 'module',
            'settings' => ['type' => 'rich-text', 'text' => '<p>Middle</p>'],
        ]);

        $this->assertMatchesRegularExpression('/^[0-9a-z]{12}$/', $id);
        $this->assertSame(['m2f6q9z3b7ud', $id, 'm8y3n5c1p4ve'], Beaver_Builder_Nodes::child_ids($nodes, 'c1h5j8w3e6tc'));
        $this->assertSame([0, 1, 2], [$nodes['m2f6q9z3b7ud']->position, $nodes[$id]->position, $nodes['m8y3n5c1p4ve']->position]);
        $this->assertSame('c1h5j8w3e6tc', $nodes[$id]->parent);
        $this->assertSame($id, array_key_last($nodes));
    }

    public function test_add_builds_a_whole_row_from_a_nested_spec(): void
    {
        [$nodes, $id] = Beaver_Builder_Nodes::add(self::fixture(), '', 0, [
            'type'     => 'row',
            'children' => [[
                'type'     => 'column-group',
                'children' => [[
                    'type'     => 'column',
                    'settings' => ['size' => 100],
                    'children' => [['type' => 'module', 'settings' => ['type' => 'heading', 'heading' => 'Top']]],
                ]],
            ]],
        ]);

        $this->assertSame([$id, 'r4k8p2m6x1qa', 'r2w6e9q4t8zh'], Beaver_Builder_Nodes::child_ids($nodes, null));
        $this->assertNull($nodes[$id]->parent);
        $tree = Beaver_Builder_Nodes::tree($nodes);
        $this->assertSame('Top', $tree[0]['children'][0]['children'][0]['children'][0]['settings']->heading);
        $this->assertCount(14 + 4, $nodes);
    }

    public function test_remove_drops_the_subtree_and_closes_the_gap(): void
    {
        $nodes = Beaver_Builder_Nodes::remove(self::fixture(), 'r4k8p2m6x1qa');

        $this->assertCount(7, $nodes);
        $this->assertSame(0, $nodes['r2w6e9q4t8zh']->position);
        $this->assertArrayNotHasKey('m5b1x8h3j6yg', $nodes);
    }

    public function test_move_between_parents_and_within_one(): void
    {
        $nodes = Beaver_Builder_Nodes::move(self::fixture(), 'm1q7w4e9r2fn', 'c1h5j8w3e6tc', 0);
        $this->assertSame(['m1q7w4e9r2fn', 'm2f6q9z3b7ud', 'm8y3n5c1p4ve'], Beaver_Builder_Nodes::child_ids($nodes, 'c1h5j8w3e6tc'));
        $this->assertSame([], Beaver_Builder_Nodes::child_ids($nodes, 'c8p3o6i1u5em'));
        $this->assertSame([0, 1, 2], [$nodes['m1q7w4e9r2fn']->position, $nodes['m2f6q9z3b7ud']->position, $nodes['m8y3n5c1p4ve']->position]);

        $nodes = Beaver_Builder_Nodes::move(self::fixture(), 'r4k8p2m6x1qa', '', 1);
        $this->assertSame(['r2w6e9q4t8zh', 'r4k8p2m6x1qa'], Beaver_Builder_Nodes::child_ids($nodes, null));
        $this->assertSame([0, 1], [$nodes['r2w6e9q4t8zh']->position, $nodes['r4k8p2m6x1qa']->position]);
    }

    /** @return array<string,array{0:callable}> */
    public static function invalid_edits(): array
    {
        return [
            'unknown node'           => [static fn ($n) => Beaver_Builder_Nodes::update($n, 'nope', ['a' => 1])],
            'empty settings'         => [static fn ($n) => Beaver_Builder_Nodes::update($n, 'm2f6q9z3b7ud', [])],
            'module loses its type'  => [static fn ($n) => Beaver_Builder_Nodes::update($n, 'm2f6q9z3b7ud', ['type' => null])],
            'column at top level'    => [static fn ($n) => Beaver_Builder_Nodes::add($n, '', null, ['type' => 'column'])],
            'row inside a column'    => [static fn ($n) => Beaver_Builder_Nodes::add($n, 'c1h5j8w3e6tc', null, ['type' => 'row'])],
            'module without a slug'  => [static fn ($n) => Beaver_Builder_Nodes::add($n, 'c1h5j8w3e6tc', null, ['type' => 'module'])],
            'unknown type'           => [static fn ($n) => Beaver_Builder_Nodes::add($n, 'c1h5j8w3e6tc', null, ['type' => 'widget'])],
            'duplicate id'           => [static fn ($n) => Beaver_Builder_Nodes::add($n, 'c1h5j8w3e6tc', null, ['node' => 'm2f6q9z3b7ud', 'type' => 'module', 'settings' => ['type' => 'html']])],
            'index out of range'     => [static fn ($n) => Beaver_Builder_Nodes::add($n, 'c1h5j8w3e6tc', 3, ['type' => 'module', 'settings' => ['type' => 'html']])],
            'move into itself'       => [static fn ($n) => Beaver_Builder_Nodes::move($n, 'r2w6e9q4t8zh', 'c8p3o6i1u5em', 0)],
            'column into a row'      => [static fn ($n) => Beaver_Builder_Nodes::move($n, 'c1h5j8w3e6tc', 'r2w6e9q4t8zh', 0)],
            'remove unknown'         => [static fn ($n) => Beaver_Builder_Nodes::remove($n, '')],
            'tree is not a list'     => [static fn ($n) => Beaver_Builder_Nodes::from_tree(['a' => ['type' => 'row']])],
        ];
    }

    /** @dataProvider invalid_edits */
    public function test_invalid_edits_throw_and_leave_the_input_alone(callable $edit): void
    {
        $nodes = self::fixture();

        try {
            $edit($nodes);
            $this->fail('Expected InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            $this->assertNotSame('', $e->getMessage());
        }

        $this->assertSame(self::fixture_serialized(), self::ser($nodes));
    }
}
