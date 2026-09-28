<?php

namespace WPMCP\Tests\Pro\Builders;

use WPMCP\Tools\Builders\Oxygen_Classic_Json;

/**
 * The classic Oxygen (4.x) layout parser and editor. Oxygen 4 stores a page
 * as a JSON tree in its ct_builder_json postmeta: a root
 * {"id":0,"name":"root","depth":0,"children":[...]} whose nodes are
 * {"id","name","options","depth","children"?}, with the element's settings
 * in options (ct_id and ct_parent repeat the ids, selector names its CSS id,
 * original holds the desktop values, media the breakpoint ones and
 * ct_content the text). A text element that holds inline elements keeps a
 * <span id="ct-placeholder-N"></span> for each child in its ct_content.
 *
 * The fixture is written the way Oxygen's save encodes the tree
 * (json_encode with JSON_UNESCAPED_UNICODE, so slashes are escaped and
 * Unicode is raw), following public page exports: two sections, a div
 * block with a headline and a text block holding an inline span, a new
 * columns element whose columns are div blocks (one empty, with no children
 * key), a code block, a link button and a headline carrying a signed dynamic
 * data shortcode. It adds a third-party element Oxygen does not ship, whose
 * options hold a Unicode escape, a float written as 1.50 and an empty list
 * and object, which a decode and re-encode would all rewrite, so it proves
 * untouched nodes keep their bytes.
 */
class OxygenClassicJsonTest extends \WP_UnitTestCase
{
    public const H3 = '{"id":3,"name":"ct_headline","options":{"ct_id":3,"ct_parent":2,"selector":"headline-3-77","original":{"tag":"h1","font-size":"40"},"nicename":"Title","activeselector":false,"ct_content":"Welcome to Café Olé"},"depth":3}';

    public const H3_OPTIONS = '{"ct_id":3,"ct_parent":2,"selector":"headline-3-77","original":{"tag":"h1","font-size":"40"},"nicename":"Title","activeselector":false,"ct_content":"Welcome to Café Olé"}';

    public const SP5 = '{"id":5,"name":"ct_span","options":{"ct_id":5,"ct_parent":4,"selector":"span-5-77","original":{"color":"#4ba4f8"},"nicename":"Span (#5)","activeselector":false,"ct_content":"plans"},"depth":4}';

    public const T4_OPTIONS = '{"ct_id":4,"ct_parent":2,"selector":"text_block-4-77","original":{"color":"#7d8fa3"},"nicename":"Description","activeselector":false,"ct_content":"Flexible <span id=\"ct-placeholder-5\"><\/span> for any team"}';

    public const T4 = '{"id":4,"name":"ct_text_block","options":' . self::T4_OPTIONS . ',"depth":3,"children":[' . self::SP5 . ']}';

    public const D2 = '{"id":2,"name":"ct_div_block","options":{"ct_id":2,"ct_parent":1,"selector":"div_block-2-77","original":{"display":"flex","flex-direction":"column"},"nicename":"Header","activeselector":false},"depth":2,"children":[' . self::H3 . ',' . self::T4 . ']}';

    public const CB9 = '{"id":9,"name":"ct_code_block","options":{"ct_id":9,"ct_parent":7,"selector":"code_block-9-77","original":{"code-php":"<?php echo \"Café\"; ?>","code-css":"#%%ELEMENT_ID%% a{color:red}"},"nicename":"Code Block (#9)","activeselector":false},"depth":3}';

    public const COL7 = '{"id":7,"name":"ct_div_block","options":{"ct_id":7,"ct_parent":6,"selector":"div_block-7-77","original":{"width":"50","width-unit":"%"},"nicename":"Div (#7)","activeselector":false},"depth":2,"children":[' . self::CB9 . ']}';

    public const COL8 = '{"id":8,"name":"ct_div_block","options":{"ct_id":8,"ct_parent":6,"selector":"div_block-8-77","original":{"width":"50","width-unit":"%"},"nicename":"Div (#8)","activeselector":false},"depth":2}';

    public const C6 = '{"id":6,"name":"ct_new_columns","options":{"ct_id":6,"ct_parent":1,"selector":"new_columns-6-77","original":{},"nicename":"Columns (#6)","activeselector":false},"depth":2,"children":[' . self::COL7 . ',' . self::COL8 . ']}';

    public const X10 = '{"id":10,"name":"oxy-acme-countdown","options":{"ct_id":10,"ct_parent":1,"selector":"-acme-countdown-10-77","original":{"oxy-acme-countdown_label":"café","oxy-acme-countdown_ratio":1.50,"oxy-acme-countdown_steps":[],"oxy-acme-countdown_extra":{}},"nicename":"Countdown (#10)","activeselector":false},"depth":2}';

    public const S1 = '{"id":1,"name":"ct_section","options":{"ct_id":1,"ct_parent":0,"selector":"section-1-77","original":{"background-color":"#f2f4f7","padding-top":"80"},"nicename":"Hero","activeselector":false,"media":{"tablet":{"original":{"padding-top":"40"}}}},"depth":1,"children":[' . self::D2 . ',' . self::C6 . ',' . self::X10 . ']}';

    public const H12 = '{"id":12,"name":"ct_headline","options":{"ct_id":12,"ct_parent":11,"selector":"headline-12-77","original":{"tag":"h2"},"nicename":"Heading (#12)","activeselector":false,"ct_content":"[oxygen data=\'title\' ct_sign_sha256=\'5b1c0e\']"},"depth":2}';

    public const LB13 = '{"id":13,"name":"ct_link_button","options":{"ct_id":13,"ct_parent":11,"selector":"link_button-13-77","original":{"url":"https:\/\/example.com\/pricing","button-style":"1"},"nicename":"Button (#13)","activeselector":false,"ct_content":"See pricing"},"depth":2}';

    public const S2 = '{"id":11,"name":"ct_section","options":{"ct_id":11,"ct_parent":0,"selector":"section-11-77","original":{},"nicename":"Footer","activeselector":false},"depth":1,"children":[' . self::H12 . ',' . self::LB13 . ']}';

    public const FIXTURE = '{"id":0,"name":"root","depth":0,"children":[' . self::S1 . ',' . self::S2 . '],"meta_keys":["oxygen_lock_post_edit_mode"]}';

    private static function node(array $tree, string $path): array
    {
        $node = ['children' => $tree];
        foreach (explode('.', $path) as $step) {
            $node = $node['children'][ (int) $step ];
        }

        return $node;
    }

    /** Replace one exact fragment, which must occur exactly once. */
    private function swap(string $haystack, string $old, string $new): string
    {
        $this->assertSame(1, substr_count($haystack, $old), 'Fixture fragment must be unique');

        return str_replace($old, $new, $haystack);
    }

    // ----------------------------------------------------------------- read

    public function test_fixture_is_what_oxygen_would_store(): void
    {
        $this->assertIsArray(json_decode(self::FIXTURE, true));
        $this->assertStringContainsString('Café', self::FIXTURE, 'Unicode is stored raw');
        $this->assertStringContainsString('https:\/\/example.com', self::FIXTURE, 'Slashes are escaped');
    }

    public function test_tree_addresses_every_element_by_path(): void
    {
        $tree = Oxygen_Classic_Json::tree(self::FIXTURE);

        $shape = static function (array $nodes) use (&$shape): array {
            $out = [];
            foreach ($nodes as $node) {
                $out[ $node['path'] ] = [$node['id'], $node['name']];
                $out += $shape($node['children'] ?? []);
            }
            return $out;
        };
        $this->assertSame([
            '0'       => [1, 'ct_section'],
            '0.0'     => [2, 'ct_div_block'],
            '0.0.0'   => [3, 'ct_headline'],
            '0.0.1'   => [4, 'ct_text_block'],
            '0.0.1.0' => [5, 'ct_span'],
            '0.1'     => [6, 'ct_new_columns'],
            '0.1.0'   => [7, 'ct_div_block'],
            '0.1.0.0' => [9, 'ct_code_block'],
            '0.1.1'   => [8, 'ct_div_block'],
            '0.2'     => [10, 'oxy-acme-countdown'],
            '1'       => [11, 'ct_section'],
            '1.0'     => [12, 'ct_headline'],
            '1.1'     => [13, 'ct_link_button'],
        ], $shape($tree));
    }

    public function test_nodes_expose_options_text_and_children(): void
    {
        $tree = Oxygen_Classic_Json::tree(self::FIXTURE);

        $h3 = self::node($tree, '0.0.0');
        $this->assertSame('Welcome to Café Olé', $h3['text']);
        $this->assertSame(['tag' => 'h1', 'font-size' => '40'], $h3['options']['original']);
        $this->assertArrayNotHasKey('ct_content', $h3['options'], 'The text is exposed once, as text');
        $this->assertArrayNotHasKey('children', $h3, 'A headline holds no elements');

        $t4 = self::node($tree, '0.0.1');
        $this->assertSame('Flexible <span id="ct-placeholder-5"></span> for any team', $t4['text']);
        $this->assertSame('plans', $t4['children'][0]['text']);

        $this->assertSame([], self::node($tree, '0.1.1')['children'], 'An empty div block is still a container');
        $this->assertSame('café', self::node($tree, '0.2')['options']['original']['oxy-acme-countdown_label']);
        $this->assertSame('<?php echo "Café"; ?>', self::node($tree, '0.1.0.0')['options']['original']['code-php']);
    }

    public function test_has_elements_follows_oxygen(): void
    {
        $this->assertTrue(Oxygen_Classic_Json::has_elements(self::FIXTURE));
        $this->assertFalse(Oxygen_Classic_Json::has_elements('{"id":0,"name":"root","depth":0,"children":[]}'));
        $this->assertFalse(Oxygen_Classic_Json::has_elements(''));
        $this->assertFalse(Oxygen_Classic_Json::has_elements('not json'));
    }

    public function test_validate_accepts_the_fixture_and_rejects_broken_trees(): void
    {
        Oxygen_Classic_Json::validate(self::FIXTURE);

        $bad = [
            'not json',
            '[]',
            '{"id":0,"name":"page","children":[]}',
            '{"id":0,"name":"root","depth":0,"children":[{"id":1,"name":"ct_section","depth":1}]}',
            '{"id":0,"name":"root","depth":0,"children":[{"id":1,"name":"ct_section","options":{"ct_id":2,"ct_parent":0},"depth":1}]}',
            '{"id":0,"name":"root","depth":0,"children":[{"id":1,"name":"ct_section","options":{"ct_id":1,"ct_parent":5},"depth":1}]}',
            '{"id":0,"name":"root","depth":0,"children":[{"id":1,"name":"ct_section","options":{"ct_id":1,"ct_parent":0},"depth":1},{"id":1,"name":"ct_section","options":{"ct_id":1,"ct_parent":0},"depth":1}]}',
        ];
        foreach ($bad as $json) {
            try {
                Oxygen_Classic_Json::validate($json);
                $this->fail("Accepted an invalid tree: {$json}");
            } catch (\InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    // --------------------------------------------------------------- update

    public function test_update_rewrites_only_the_options_of_the_target(): void
    {
        $out = Oxygen_Classic_Json::update(self::FIXTURE, '0.0.0', ['original' => ['font-size' => '48', 'tag' => null], 'nicename' => 'Hero title'], null);

        $expected = $this->swap(
            self::FIXTURE,
            self::H3_OPTIONS,
            '{"ct_id":3,"ct_parent":2,"selector":"headline-3-77","original":{"font-size":"48"},"nicename":"Hero title","activeselector":false,"ct_content":"Welcome to Café Olé"}'
        );
        $this->assertSame($expected, $out);
    }

    public function test_update_text_is_encoded_the_way_oxygen_stores_it(): void
    {
        $out = Oxygen_Classic_Json::update(self::FIXTURE, '0.0.0', null, 'Pick a plan / save 20% "today"');

        $this->assertSame(
            $this->swap(self::FIXTURE, '"ct_content":"Welcome to Café Olé"', '"ct_content":"Pick a plan \/ save 20% \"today\""'),
            $out
        );
    }

    public function test_update_of_a_text_with_inline_children_keeps_their_placeholders(): void
    {
        $out = Oxygen_Classic_Json::update(self::FIXTURE, '0.0.1', null, 'Simple <span id="ct-placeholder-5"></span>!');
        $this->assertSame(
            $this->swap(self::FIXTURE, 'Flexible <span id=\"ct-placeholder-5\"><\/span> for any team', 'Simple <span id=\"ct-placeholder-5\"><\/span>!'),
            $out
        );

        $this->expectException(\InvalidArgumentException::class);
        Oxygen_Classic_Json::update(self::FIXTURE, '0.0.1', null, 'No placeholder left');
    }

    public function test_update_of_an_unknown_element_keeps_every_other_byte(): void
    {
        $out = Oxygen_Classic_Json::update(self::FIXTURE, '1.1', ['original' => ['url' => 'https://example.com/new']], 'Buy');

        $this->assertSame(
            $this->swap(
                self::FIXTURE,
                '"original":{"url":"https:\/\/example.com\/pricing","button-style":"1"},"nicename":"Button (#13)","activeselector":false,"ct_content":"See pricing"',
                '"original":{"url":"https:\/\/example.com\/new","button-style":"1"},"nicename":"Button (#13)","activeselector":false,"ct_content":"Buy"'
            ),
            $out
        );
        $this->assertStringContainsString(self::X10, $out);
    }

    public function test_update_refuses_structural_keys_and_bad_requests(): void
    {
        $cases = [
            ['0.0.0', ['ct_id' => 9], null],
            ['0.0.0', ['ct_parent' => 0], null],
            ['0.0.0', ['selector' => 'x'], null],
            ['0.0.0', ['ct_content' => 'x'], null],
            ['0.0.0', null, null],
            ['9', ['nicename' => 'x'], null],
            ['0.x', ['nicename' => 'x'], null],
            ['', ['nicename' => 'x'], null],
        ];
        foreach ($cases as [$path, $attrs, $text]) {
            try {
                Oxygen_Classic_Json::update(self::FIXTURE, $path, $attrs, $text);
                $this->fail('Accepted update at ' . $path);
            } catch (\InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function test_update_passes_only_new_fragments_through_the_filter(): void
    {
        $seen = [];
        $sign = static function (string $fragment) use (&$seen): string {
            $seen[] = $fragment;
            return str_replace("[oxygen data='x']", "[oxygen data='x' ct_sign_sha256='new']", $fragment);
        };

        $out = Oxygen_Classic_Json::update(self::FIXTURE, '0.0.0', null, "[oxygen data='x']", $sign);

        $this->assertCount(1, $seen);
        $this->assertStringContainsString('"ct_content":"[oxygen data=\'x\' ct_sign_sha256=\'new\']"', $out);
        $this->assertStringContainsString(self::H12, $out, 'An existing signature elsewhere is untouched');
    }

    // ------------------------------------------------------------------ add

    public function test_add_into_an_empty_column_creates_its_children_list(): void
    {
        [$out, $path] = Oxygen_Classic_Json::add(self::FIXTURE, '0.1.1', null, ['name' => 'ct_headline', 'options' => ['original' => ['tag' => 'h3']], 'text' => 'New'], 77);

        $this->assertSame('0.1.1.0', $path);
        $this->assertSame(
            $this->swap(
                self::FIXTURE,
                self::COL8,
                substr(self::COL8, 0, -1) . ',"children":[{"id":14,"name":"ct_headline","options":{"ct_id":14,"ct_parent":8,"selector":"headline-14-77","original":{"tag":"h3"},"ct_content":"New"},"depth":3}]}'
            ),
            $out
        );
    }

    public function test_add_at_the_top_builds_a_nested_section_with_fresh_ids(): void
    {
        [$out, $path] = Oxygen_Classic_Json::add(self::FIXTURE, '', 1, [
            'name'     => 'ct_section',
            'children' => [['name' => 'ct_text_block', 'text' => 'Hi']],
        ], 42);

        $this->assertSame('1', $path);
        $section = '{"id":14,"name":"ct_section","options":{"ct_id":14,"ct_parent":0,"selector":"section-14-42","original":{}},"depth":1,"children":['
            . '{"id":15,"name":"ct_text_block","options":{"ct_id":15,"ct_parent":14,"selector":"text_block-15-42","original":{},"ct_content":"Hi"},"depth":2}]}';
        $this->assertSame($this->swap(self::FIXTURE, self::S1 . ',' . self::S2, self::S1 . ',' . $section . ',' . self::S2), $out);
    }

    public function test_add_follows_oxygen_depth_rules(): void
    {
        // A div block directly in new columns is a column: it keeps the
        // parent's depth.
        [$out] = Oxygen_Classic_Json::add(self::FIXTURE, '0.1', 0, ['name' => 'ct_div_block'], 77);
        $this->assertStringContainsString('"children":[{"id":14,"name":"ct_div_block","options":{"ct_id":14,"ct_parent":6,"selector":"div_block-14-77","original":{}},"depth":2},' . self::COL7, $out);

        // A third-party element gets Oxygen's selector prefix (its name less
        // the first three characters) and the next depth.
        [$out] = Oxygen_Classic_Json::add(self::FIXTURE, '1', null, ['name' => 'oxy-acme-countdown', 'options' => ['oxy-acme-countdown_label' => 'x']], 77);
        $this->assertStringContainsString(self::LB13 . ',{"id":14,"name":"oxy-acme-countdown","options":{"ct_id":14,"ct_parent":11,"selector":"-acme-countdown-14-77","original":{},"oxy-acme-countdown_label":"x"},"depth":2}]', $out);
    }

    public function test_add_refuses_bad_targets_and_specs(): void
    {
        $cases = [
            ['0.0.0', ['name' => 'ct_text_block']],
            ['0.0.1', ['name' => 'ct_span']],
            ['0.1.0.0', ['name' => 'ct_text_block']],
            ['7', ['name' => 'ct_text_block']],
            ['0', ['name' => 'bad name']],
            ['0', []],
            ['0', ['name' => 'ct_text_block', 'options' => ['ct_id' => 3]]],
            ['0', ['name' => 'ct_text_block', 'options' => 'x']],
            ['0', ['name' => 'ct_text_block', 'text' => 'a', 'children' => [['name' => 'ct_span']]]],
        ];
        foreach ($cases as [$to, $element]) {
            try {
                Oxygen_Classic_Json::add(self::FIXTURE, $to, null, $element, 77);
                $this->fail('Accepted add under ' . $to);
            } catch (\InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    // --------------------------------------------------------------- remove

    public function test_remove_takes_the_element_and_its_separator(): void
    {
        $this->assertSame($this->swap(self::FIXTURE, ',' . self::LB13, ''), Oxygen_Classic_Json::remove(self::FIXTURE, '1.1'));
        $this->assertSame($this->swap(self::FIXTURE, self::H12 . ',', ''), Oxygen_Classic_Json::remove(self::FIXTURE, '1.0'));
        $this->assertSame($this->swap(self::FIXTURE, self::D2 . ',', ''), Oxygen_Classic_Json::remove(self::FIXTURE, '0.0'));
    }

    public function test_remove_of_an_inline_child_drops_its_placeholder(): void
    {
        $out = Oxygen_Classic_Json::remove(self::FIXTURE, '0.0.1.0');

        $t4 = '{"id":4,"name":"ct_text_block","options":' . str_replace('<span id=\"ct-placeholder-5\"><\/span>', '', self::T4_OPTIONS) . ',"depth":3,"children":[]}';
        $this->assertSame($this->swap(self::FIXTURE, self::T4, $t4), $out);
    }

    // ----------------------------------------------------------------- move

    public function test_move_rewrites_only_parent_and_depth_numbers(): void
    {
        $out = Oxygen_Classic_Json::move(self::FIXTURE, '1.0', '0.1.1', 0);

        $moved    = str_replace(['"ct_parent":11', '"depth":2'], ['"ct_parent":8', '"depth":3'], self::H12);
        $expected = $this->swap(self::FIXTURE, self::H12 . ',', '');
        $expected = $this->swap($expected, self::COL8, substr(self::COL8, 0, -1) . ',"children":[' . $moved . ']}');
        $this->assertSame($expected, $out);
    }

    public function test_move_of_a_column_to_the_top_recomputes_its_subtree_depths(): void
    {
        $out = Oxygen_Classic_Json::move(self::FIXTURE, '0.1.0', '', 0);

        $cb9  = str_replace('"depth":3', '"depth":2', self::CB9);
        $col7 = str_replace(['"ct_parent":6', '"depth":2,"children":[' . self::CB9], ['"ct_parent":0', '"depth":1,"children":[' . $cb9], self::COL7);
        $expected = $this->swap(self::FIXTURE, self::COL7 . ',', '');
        $expected = $this->swap($expected, '"name":"root","depth":0,"children":[', '"name":"root","depth":0,"children":[' . $col7 . ',');
        $this->assertSame($expected, $out);
    }

    public function test_move_within_the_same_parent_reorders_bytes_only(): void
    {
        $out = Oxygen_Classic_Json::move(self::FIXTURE, '1.1', '1', 0);

        $this->assertSame($this->swap(self::FIXTURE, self::H12 . ',' . self::LB13, self::LB13 . ',' . self::H12), $out);
    }

    public function test_move_refuses_cycles_and_inline_elements(): void
    {
        $cases = [
            ['0', '0.0', null],
            ['0.1', '0.1.0', null],
            ['0.0.1.0', '1', null],
            ['1.0', '0.0.1', null],
            ['1.0', '0.0.0', null],
            ['1.0', '5', null],
        ];
        foreach ($cases as [$path, $to, $index]) {
            try {
                Oxygen_Classic_Json::move(self::FIXTURE, $path, $to, $index);
                $this->fail("Accepted move of {$path} to {$to}");
            } catch (\InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }
}
