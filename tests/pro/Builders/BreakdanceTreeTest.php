<?php

namespace WPMCP\Tests\Pro\Builders;

use WPMCP\Tools\Builders\Breakdance_Tree;

/**
 * Tree and editor coverage for a Breakdance document: the JSON the builder
 * keeps in `tree_json_string` inside `_breakdance_data`. The fixture follows
 * the shape of Breakdance's own sample page export: a root node (id 1, type
 * root) whose children are nodes with an integer `id`, `data.type` (a
 * namespaced element class such as EssentialElements\Section),
 * `data.properties` (nested per-breakpoint objects, or null), `children` and
 * `_parentId`, plus the document's `_nextNodeId` counter and `status`. It
 * carries columns, a wrapper link with children, a global block reference,
 * an inline SVG, backslashes, quotes, non-ASCII text, an empty list and an
 * empty object. Pure data work, so the plugin never needs to be installed.
 */
class BreakdanceTreeTest extends \WP_UnitTestCase
{
    /** The document exactly as the Breakdance editor serializes it. */
    public static function fixture_json(): string
    {
        $json = <<<'JSON'
{"root":{"id":1,"data":{"type":"root","properties":[]},"children":[
{"id":100,"data":{"type":"EssentialElements\\Section","properties":{"design":{"background":{"color":"var(--bde-palette-base-1)"},"size":{"min_height":{"breakpoint_base":{"number":400,"unit":"px","style":"400px"},"breakpoint_phone_portrait":{"number":200,"unit":"px","style":"200px"}}}}}},"children":[
{"id":101,"data":{"type":"EssentialElements\\Heading","properties":{"content":{"content":{"text":"About the studio","tags":"h1"}},"design":{"typography":{"color":{"breakpoint_base":"var(--bde-palette-color-2)"}}}}},"children":[],"_parentId":100},
{"id":102,"data":{"type":"EssentialElements\\Text","properties":{"content":{"content":{"text":"Files live in C:\\Sites\\bd and say \"hi\".<br><br>Café & crème"}}}},"children":[],"_parentId":100}
],"_parentId":1},
{"id":103,"data":{"type":"EssentialElements\\Section","properties":null},"children":[
{"id":104,"data":{"type":"EssentialElements\\Columns","properties":{"design":{"spacing":{"container":{"margin_bottom":{"breakpoint_base":{"number":150,"unit":"px","style":"150px"}}}}}}},"children":[
{"id":105,"data":{"type":"EssentialElements\\Column","properties":{"design":{"size":{"width":{"unit":"%","number":57.5,"style":"57.5%"}}}}},"children":[
{"id":106,"data":{"type":"EssentialElements\\Image","properties":{"content":{"content":{"image":{"id":98,"filename":"about.png","url":"https://example.com/wp-content/uploads/2024/05/about.png","alt":"","sizes":[]}}}}},"children":[],"_parentId":105}
],"_parentId":104},
{"id":107,"data":{"type":"EssentialElements\\Column","properties":{"design":{"size":{"width":{"unit":"%","number":42.5,"style":"42.5%"}},"layout":{"gap":{"breakpoint_base":{"number":20,"unit":"px","style":"20px"}}}}}},"children":[
{"id":108,"data":{"type":"EssentialElements\\WrapperLink","properties":{"content":{"content":{"link":{"type":"url","url":"https://example.com/contact/"}}}}},"children":[
{"id":109,"data":{"type":"EssentialElements\\Text","properties":{"content":{"content":{"text":"book now"}},"design":{}}},"children":[],"_parentId":108},
{"id":110,"data":{"type":"EssentialElements\\Icon","properties":{"content":{"content":{"icon":{"slug":"icon-arrow-right.","name":"arrow right","svgCode":"<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 24 24\"><path d=\"M5 12h14\"/></svg>"}}}}},"children":[],"_parentId":108}
],"_parentId":107}
],"_parentId":104}
],"_parentId":103}
],"_parentId":1},
{"id":111,"data":{"type":"EssentialElements\\GlobalBlock","properties":{"content":{"content":{"block":2051}}}},"children":[],"_parentId":1}
]},"_nextNodeId":112,"status":"exported"}
JSON;

        return str_replace("\n", '', $json);
    }

    /** The `_breakdance_data` meta value, as Breakdance's set_meta() encodes it. */
    public static function fixture_meta(): string
    {
        return (string) wp_json_encode(['tree_json_string' => self::fixture_json()]);
    }

    private static function doc(): object
    {
        $doc = Breakdance_Tree::decode(self::fixture_json());
        self::assertNotNull($doc);

        return $doc;
    }

    /** Find a node by id anywhere under the root. */
    private static function find(object $doc, int $id): ?object
    {
        $stack = [$doc->root];
        while ($stack) {
            $node = array_pop($stack);
            if ($id === $node->id) {
                return $node;
            }
            foreach ($node->children as $child) {
                $stack[] = $child;
            }
        }

        return null;
    }

    /** One node's own JSON, children included, as it encodes in the document. */
    private static function node_json(object $doc, int $id): string
    {
        return (string) wp_json_encode(self::find($doc, $id), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    // ------------------------------------------------------------ decode

    public function test_an_unchanged_document_encodes_byte_for_byte(): void
    {
        $this->assertSame(self::fixture_json(), Breakdance_Tree::encode(self::doc()));
    }

    public function test_decode_rejects_anything_that_is_not_a_tree(): void
    {
        foreach (['', 'nope', '[]', '{"root":[]}', '{"root":{"id":1,"data":{}}}', '{"_nextNodeId":3}'] as $bad) {
            $this->assertNull(Breakdance_Tree::decode($bad), $bad);
        }
    }

    public function test_nodes_are_the_root_children_as_stored(): void
    {
        $nodes = Breakdance_Tree::nodes(self::doc());

        $this->assertSame([100, 103, 111], array_column(array_map('get_object_vars', $nodes), 'id'));
        $this->assertSame('EssentialElements\\Heading', $nodes[0]->children[0]->data->type);
        $this->assertNull($nodes[1]->data->properties);
    }

    public function test_blank_document_is_an_empty_root(): void
    {
        $doc = Breakdance_Tree::blank();

        $this->assertSame('{"root":{"id":1,"data":{"type":"root","properties":[]},"children":[]},"_nextNodeId":100,"status":"exported"}', Breakdance_Tree::encode($doc));
    }

    // ------------------------------------------------------------ update

    public function test_update_deep_merges_properties_and_null_removes_a_key(): void
    {
        $before = self::doc();
        $doc    = Breakdance_Tree::update($before, '101', [
            'content' => ['content' => ['text' => 'New "title" in D:\\x']],
            'design'  => null,
        ]);

        $props = self::find($doc, 101)->data->properties;
        $this->assertSame('New "title" in D:\\x', $props->content->content->text);
        $this->assertSame('h1', $props->content->content->tags);
        $this->assertFalse(property_exists($props, 'design'));
        // The caller's document is untouched, and so is every other node.
        $this->assertSame('About the studio', self::find($before, 101)->data->properties->content->content->text);
        $this->assertSame(self::node_json($before, 103), self::node_json($doc, 103));
        $this->assertSame(self::node_json($before, 102), self::node_json($doc, 102));
    }

    public function test_update_fills_null_properties_and_lists_replace_whole(): void
    {
        $doc = Breakdance_Tree::update(self::doc(), '103', ['design' => ['background' => ['color' => '#fff']]]);
        $this->assertSame('#fff', self::find($doc, 103)->data->properties->design->background->color);

        $doc = Breakdance_Tree::update(self::doc(), '106', ['content' => ['content' => ['image' => ['sizes' => ['a', 'b']]]]]);
        $this->assertSame(['a', 'b'], self::find($doc, 106)->data->properties->content->content->image->sizes);
        $this->assertSame(98, self::find($doc, 106)->data->properties->content->content->image->id);
    }

    public function test_update_rejects_unknown_nodes_the_root_and_empty_attrs(): void
    {
        foreach ([['999', ['a' => 1]], ['1', ['a' => 1]], ['', ['a' => 1]], ['101', []]] as [$id, $attrs]) {
            try {
                Breakdance_Tree::update(self::doc(), $id, $attrs);
                $this->fail("update {$id} should be rejected");
            } catch (\InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    // --------------------------------------------------------------- add

    public function test_add_assigns_ids_from_the_counter_and_sets_parents(): void
    {
        [$doc, $id] = Breakdance_Tree::add(self::doc(), '107', 0, [
            'type'       => 'EssentialElements\\Div',
            'properties' => ['design' => ['layout' => ['gap' => ['breakpoint_base' => ['number' => 10, 'unit' => 'px', 'style' => '10px']]]]],
            'children'   => [
                ['type' => 'EssentialElements\\Heading', 'properties' => ['content' => ['content' => ['text' => 'Hi', 'tags' => 'h3']]]],
                ['type' => 'EssentialElements\\Button'],
            ],
        ]);

        $this->assertSame('112', $id);
        $this->assertSame(115, $doc->_nextNodeId);
        $column = self::find($doc, 107);
        $this->assertSame([112, 108], [$column->children[0]->id, $column->children[1]->id]);
        $div = $column->children[0];
        $this->assertSame(107, $div->_parentId);
        $this->assertSame(['id', 'data', 'children', '_parentId'], array_keys(get_object_vars($div)));
        $this->assertSame([113, 114], [$div->children[0]->id, $div->children[1]->id]);
        $this->assertSame(112, $div->children[1]->_parentId);
        $this->assertNull($div->children[1]->data->properties);
        $this->assertSame('{"id":114,"data":{"type":"EssentialElements\\\\Button","properties":null},"children":[],"_parentId":112}', self::node_json($doc, 114));
    }

    public function test_add_at_top_level_appends_by_default(): void
    {
        [$doc, $id] = Breakdance_Tree::add(self::doc(), '', null, ['type' => 'EssentialElements\\Section']);

        $this->assertSame([100, 103, 111, 112], array_map(static fn ($n) => $n->id, $doc->root->children));
        $this->assertSame(1, self::find($doc, (int) $id)->_parentId);
    }

    public function test_add_rejects_bad_specs_and_placements(): void
    {
        $cases = [
            ['101x', ['type' => 'EssentialElements\\Text']],
            ['105', ['type' => 'heading']],
            ['105', ['type' => 'root']],
            ['105', ['type' => '']],
            ['105', ['type' => 'EssentialElements\\Text', 'properties' => 'x']],
            ['105', ['type' => 'EssentialElements\\Text', 'children' => 'x']],
            ['105', ['type' => 'EssentialElements\\Column']],
            ['104', ['type' => 'EssentialElements\\Text']],
            ['', ['type' => 'EssentialElements\\Column']],
        ];
        foreach ($cases as [$to, $spec]) {
            try {
                Breakdance_Tree::add(self::doc(), $to, null, $spec);
                $this->fail('add should be rejected: ' . wp_json_encode([$to, $spec]));
            } catch (\InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }

        $this->expectException(\InvalidArgumentException::class);
        Breakdance_Tree::add(self::doc(), '100', 3, ['type' => 'EssentialElements\\Text']);
    }

    public function test_a_column_can_be_added_to_columns(): void
    {
        [$doc, $id] = Breakdance_Tree::add(self::doc(), '104', 1, ['type' => 'EssentialElements\\Column']);

        $this->assertSame([105, (int) $id, 107], array_map(static fn ($n) => $n->id, self::find($doc, 104)->children));
    }

    // ------------------------------------------------------------ remove

    public function test_remove_drops_the_subtree_and_keeps_the_counter(): void
    {
        $doc = Breakdance_Tree::remove(self::doc(), '104');

        $this->assertNull(self::find($doc, 104));
        $this->assertNull(self::find($doc, 110));
        $this->assertSame([], self::find($doc, 103)->children);
        $this->assertSame(112, $doc->_nextNodeId);
        $this->assertSame(self::node_json(self::doc(), 100), self::node_json($doc, 100));
    }

    public function test_remove_rejects_the_root_and_unknown_ids(): void
    {
        foreach (['1', '999', ''] as $id) {
            try {
                Breakdance_Tree::remove(self::doc(), $id);
                $this->fail("remove {$id} should be rejected");
            } catch (\InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    // -------------------------------------------------------------- move

    public function test_move_reparents_at_the_final_index(): void
    {
        $doc = Breakdance_Tree::move(self::doc(), '106', '107', 0);

        $this->assertSame([], self::find($doc, 105)->children);
        $this->assertSame([106, 108], array_map(static fn ($n) => $n->id, self::find($doc, 107)->children));
        $this->assertSame(107, self::find($doc, 106)->_parentId);

        $doc = Breakdance_Tree::move(self::doc(), '100', '', 2);
        $this->assertSame([103, 111, 100], array_map(static fn ($n) => $n->id, $doc->root->children));

        $doc = Breakdance_Tree::move(self::doc(), '102', '100', 0);
        $this->assertSame([102, 101], array_map(static fn ($n) => $n->id, self::find($doc, 100)->children));
    }

    public function test_move_rejects_cycles_misplaced_columns_and_bad_indexes(): void
    {
        $cases = [
            ['103', '105', null],
            ['103', '103', null],
            ['105', '100', null],
            ['101', '104', null],
            ['1', '', null],
            ['101', '100', 2],
            ['101', '999', null],
        ];
        foreach ($cases as [$id, $to, $index]) {
            try {
                Breakdance_Tree::move(self::doc(), $id, $to, $index);
                $this->fail("move {$id} to {$to} should be rejected");
            } catch (\InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    // ----------------------------------------------------------- replace

    public function test_replacing_with_the_read_nodes_is_byte_identical(): void
    {
        $list = json_decode((string) wp_json_encode(Breakdance_Tree::nodes(self::doc())));
        $doc  = Breakdance_Tree::replace(self::doc(), $list);

        $this->assertSame(self::fixture_json(), Breakdance_Tree::encode($doc));
    }

    public function test_replace_builds_new_nodes_and_advances_the_counter(): void
    {
        $list = json_decode('[{"id":250,"data":{"type":"EssentialElements\\\\Section","properties":null},"children":[{"type":"EssentialElements\\\\Text","properties":{"content":{"content":{"text":"a\\\\b"}}}}]}]');
        $doc  = Breakdance_Tree::replace(self::doc(), $list);

        $this->assertSame(252, $doc->_nextNodeId);
        $section = $doc->root->children[0];
        $this->assertSame(250, $section->id);
        $this->assertSame(1, $section->_parentId);
        $this->assertSame(251, $section->children[0]->id);
        $this->assertSame(250, $section->children[0]->_parentId);
        $this->assertSame('a\\b', $section->children[0]->data->properties->content->content->text);
        $this->assertSame('exported', $doc->status);
    }

    public function test_replace_rejects_bad_lists(): void
    {
        foreach (['{"a":1}', '[{"id":5,"data":{"type":"EssentialElements\\\\Div"},"children":[{"id":5,"data":{"type":"EssentialElements\\\\Div"}}]}]', '[{"id":"x","data":{"type":"EssentialElements\\\\Div"}}]', '[{"data":{"type":"nope"}}]', '[{"type":"EssentialElements\\\\Column"}]'] as $bad) {
            try {
                Breakdance_Tree::replace(self::doc(), json_decode($bad));
                $this->fail("replace should be rejected: {$bad}");
            } catch (\InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }
}
