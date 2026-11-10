<?php

namespace WPMCP\Tests\Pro\Builders;

use WPMCP\Tools\Builders\Divi5_Blocks;

/**
 * Issue #636: parser and editor for the Divi 5 layout, the block markup
 * Divi 5 stores in post_content (`<!-- wp:divi/section {...} -->`, all of it
 * inside one `divi/placeholder` block). The fixture is recorded from Divi
 * 5.18.0's own markup: the section, row and column blocks of the
 * WooCommerce page layouts it ships (WooCommerceHooks.php) and the text
 * block of its D4-to-D5 bridge (includes/builder/functions.php), with
 * `__DIVI_BUILDER_VERSION__` filled in the way Divi fills it. Pure string
 * work, so Divi never needs to be installed.
 */
class Divi5BlocksTest extends \WP_UnitTestCase
{
    public const FIXTURE = '<!-- wp:divi/placeholder -->'
        . '<!-- wp:divi/section {"builderVersion":"5.18.0"} -->'
        . '<!-- wp:divi/row {"module":{"advanced":{"columnStructure":{"desktop":{"value":"1_2,1_2"}},"flexColumnStructure":{"desktop":{"value":"equal-columns_2"}}},"decoration":{"layout":{"desktop":{"value":{"flexWrap":"nowrap"}},"phoneWide":{"value":{"flexWrap":"wrap"}},"phone":{"value":{"flexWrap":"wrap"}}}}},"builderVersion":"5.18.0"} -->'
        . '<!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"1_2"}}},"decoration":{"sizing":{"desktop":{"value":{"flexType":"12_24"}},"phoneWide":{"value":{"flexType":"24_24"}},"phone":{"value":{"flexType":"24_24"}}}}},"builderVersion":"5.18.0"} -->'
        . '<!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"Welcome"}}},"builderVersion":"5.18.0"} --><!-- /wp:divi/heading -->'
        . '<!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"\u003cp\u003eC:\\\\Sites \u0026 \u0022quotes\u0022\u003c/p\u003e"}}},"builderVersion":"5.18.0"} /-->'
        . '<!-- /wp:divi/column -->'
        . '<!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"1_2"}}}},"builderVersion":"5.18.0"} -->'
        . '<!-- wp:divi/button {"button":{"innerContent":{"desktop":{"value":{"text":"Book now","linkUrl":"https://example.com/book"}}}},"module":{"decoration":{"spacing":{}}},"builderVersion":"5.18.0"} --><!-- /wp:divi/button -->'
        . '<!-- /wp:divi/column -->'
        . '<!-- /wp:divi/row -->'
        . '<!-- /wp:divi/section -->'
        . "\n\n"
        . '<!-- wp:divi/section {"builderVersion":"5.18.0"} --><!-- /wp:divi/section -->'
        . '<!-- /wp:divi/placeholder -->';

    public function test_recognises_divi_5_markup(): void
    {
        $this->assertTrue(Divi5_Blocks::is_divi5(self::FIXTURE));
        $this->assertTrue(Divi5_Blocks::is_divi5('<!-- wp:divi/placeholder /-->'));
        $this->assertFalse(Divi5_Blocks::is_divi5('[et_pb_section][/et_pb_section]'));
        $this->assertFalse(Divi5_Blocks::is_divi5('<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->'));
    }

    public function test_tree_starts_inside_the_placeholder_and_exposes_paths_names_and_attrs(): void
    {
        $tree = Divi5_Blocks::tree(self::FIXTURE);

        $this->assertCount(2, $tree);
        $this->assertSame('0', $tree[0]['path']);
        $this->assertSame('divi/section', $tree[0]['name']);
        $this->assertSame('5.18.0', $tree[0]['attrs']->builderVersion);

        $row = $tree[0]['children'][0];
        $this->assertSame('0.0', $row['path']);
        $this->assertSame('1_2,1_2', $row['attrs']->module->advanced->columnStructure->desktop->value);

        $text = $tree[0]['children'][0]['children'][0]['children'][1];
        $this->assertSame('0.0.0.1', $text['path']);
        $this->assertSame('divi/text', $text['name']);
        $this->assertSame('<p>C:\\Sites & "quotes"</p>', $text['attrs']->content->innerContent->desktop->value);
        $this->assertSame([], $text['children']);

        $button = $tree[0]['children'][0]['children'][1]['children'][0];
        $this->assertSame('0.0.1.0', $button['path']);
        // An empty object stays an object, so it is written back as {}.
        $this->assertEquals(new \stdClass(), $button['attrs']->module->decoration->spacing);

        $this->assertSame('1', $tree[1]['path']);
        $this->assertSame([], $tree[1]['children']);
    }

    public function test_tree_without_a_placeholder_starts_at_the_top_level(): void
    {
        $tree = Divi5_Blocks::tree('<!-- wp:divi/section --><!-- wp:divi/row /--><!-- /wp:divi/section -->');

        $this->assertSame('divi/section', $tree[0]['name']);
        $this->assertEquals(new \stdClass(), $tree[0]['attrs']);
        $this->assertSame('0.0', $tree[0]['children'][0]['path']);
    }

    public function test_unbalanced_markup_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Divi5_Blocks::tree('<!-- wp:divi/placeholder --><!-- wp:divi/section --><!-- /wp:divi/placeholder -->');
    }

    public function test_update_merges_attrs_and_rewrites_only_that_opener(): void
    {
        $out = Divi5_Blocks::update(self::FIXTURE, '0.0.0.0', [
            'title'  => ['innerContent' => ['desktop' => ['value' => 'Hello <em>there</em>']]],
            'module' => ['meta' => ['adminLabel' => ['desktop' => ['value' => 'Intro']]]],
        ], null);

        $heading = Divi5_Blocks::tree($out)[0]['children'][0]['children'][0]['children'][0];
        $this->assertSame('Hello <em>there</em>', $heading['attrs']->title->innerContent->desktop->value);
        $this->assertSame('Intro', $heading['attrs']->module->meta->adminLabel->desktop->value);
        $this->assertSame('5.18.0', $heading['attrs']->builderVersion);

        // Every byte outside the edited opener is kept.
        $before = '<!-- wp:divi/heading ';
        $start  = strpos(self::FIXTURE, $before);
        $end    = strpos(self::FIXTURE, '-->', $start) + 3;
        $this->assertSame(substr(self::FIXTURE, 0, $start), substr($out, 0, $start));
        $this->assertSame(substr(self::FIXTURE, $end), substr($out, strpos($out, '-->', $start) + 3));
        // Written the way the block serializer writes attributes.
        $this->assertStringContainsString('"Hello \u003cem\u003ethere\u003c/em\u003e"', $out);
    }

    public function test_update_null_removes_a_key_and_keeps_empty_objects(): void
    {
        $out    = Divi5_Blocks::update(self::FIXTURE, '0.0.1.0', ['builderVersion' => null], 'Call us');
        $button = Divi5_Blocks::tree($out)[0]['children'][0]['children'][1]['children'][0];

        $this->assertFalse(isset($button['attrs']->builderVersion));
        $this->assertSame('Call us', $button['attrs']->button->innerContent->desktop->value->text);
        $this->assertSame('https://example.com/book', $button['attrs']->button->innerContent->desktop->value->linkUrl);
        $this->assertStringContainsString('"spacing":{}', $out);
    }

    public function test_text_sets_the_main_content_field_of_known_modules(): void
    {
        $out  = Divi5_Blocks::update(self::FIXTURE, '0.0.0.1', null, '<p>New</p>');
        $text = Divi5_Blocks::tree($out)[0]['children'][0]['children'][0]['children'][1];
        $this->assertSame('<p>New</p>', $text['attrs']->content->innerContent->desktop->value);
        // A self-closed block stays self-closed.
        $this->assertStringContainsString('"builderVersion":"5.18.0"} /-->', $out);

        $out     = Divi5_Blocks::update(self::FIXTURE, '0.0.0.0', null, 'Title');
        $heading = Divi5_Blocks::tree($out)[0]['children'][0]['children'][0]['children'][0];
        $this->assertSame('Title', $heading['attrs']->title->innerContent->desktop->value);
    }

    public function test_text_on_a_module_without_a_known_text_field_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('attrs');
        Divi5_Blocks::update(self::FIXTURE, '0.0', null, 'Row text');
    }

    public function test_add_serializes_a_nested_element_and_returns_its_path(): void
    {
        [$out, $path] = Divi5_Blocks::add(self::FIXTURE, '0.0.1', 0, [
            'name'     => 'text',
            'attrs'    => ['module' => ['meta' => ['adminLabel' => ['desktop' => ['value' => 'Lead']]]]],
            'text'     => '<p>Lead</p>',
        ], '5.18.0');

        $this->assertSame('0.0.1.0', $path);
        $column = Divi5_Blocks::tree($out)[0]['children'][0]['children'][1];
        $this->assertSame('divi/text', $column['children'][0]['name']);
        $this->assertSame('<p>Lead</p>', $column['children'][0]['attrs']->content->innerContent->desktop->value);
        $this->assertSame('5.18.0', $column['children'][0]['attrs']->builderVersion);
        $this->assertSame('divi/button', $column['children'][1]['name']);
        $this->assertStringContainsString('<!-- wp:divi/text {', $out);
        $this->assertStringContainsString('<!-- /wp:divi/text -->', $out);

        [$out, $path] = Divi5_Blocks::add(self::FIXTURE, '', null, [
            'name'     => 'divi/section',
            'children' => [['name' => 'row', 'children' => [['name' => 'column', 'attrs' => ['builderVersion' => '5.0.0']]]]],
        ], '5.18.0');
        $this->assertSame('2', $path);
        $section = Divi5_Blocks::tree($out)[2];
        $this->assertSame('divi/column', $section['children'][0]['children'][0]['name']);
        $this->assertSame('5.0.0', $section['children'][0]['children'][0]['attrs']->builderVersion);
        // Still inside the placeholder.
        $this->assertStringEndsWith('<!-- /wp:divi/section --><!-- /wp:divi/placeholder -->', $out);
    }

    public function test_add_to_an_empty_document_creates_the_placeholder(): void
    {
        [$out, $path] = Divi5_Blocks::add('', '', null, ['name' => 'section'], null);

        $this->assertSame('0', $path);
        $this->assertSame('<!-- wp:divi/placeholder --><!-- wp:divi/section --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->', $out);

        [$out] = Divi5_Blocks::add('<!-- wp:divi/placeholder /-->', '', null, ['name' => 'section'], null);
        $this->assertSame('<!-- wp:divi/placeholder --><!-- wp:divi/section --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->', $out);
    }

    public function test_add_into_a_self_closed_block_opens_it(): void
    {
        [$out, $path] = Divi5_Blocks::add(self::FIXTURE, '0.0.0.1', null, ['name' => 'divi/icon'], null);

        $this->assertSame('0.0.0.1.0', $path);
        $text = Divi5_Blocks::tree($out)[0]['children'][0]['children'][0]['children'][1];
        $this->assertSame('divi/icon', $text['children'][0]['name']);
        $this->assertSame('<p>C:\\Sites & "quotes"</p>', $text['attrs']->content->innerContent->desktop->value);
    }

    public function test_add_refuses_bad_names_and_shapes(): void
    {
        foreach ([[], ['name' => 'Bad Name'], ['name' => 'divi/text', 'attrs' => 'x'], ['name' => 'divi/text', 'children' => 'x']] as $element) {
            try {
                Divi5_Blocks::add(self::FIXTURE, '0', null, $element, null);
                $this->fail('Expected a refusal for ' . wp_json_encode($element));
            } catch (\InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function test_remove_drops_the_block_and_everything_inside_it(): void
    {
        $out  = Divi5_Blocks::remove(self::FIXTURE, '0.0.0');
        $tree = Divi5_Blocks::tree($out);

        $this->assertCount(1, $tree[0]['children'][0]['children']);
        $this->assertSame('divi/button', $tree[0]['children'][0]['children'][0]['children'][0]['name']);
        $this->assertStringNotContainsString('wp:divi/heading', $out);
    }

    public function test_move_reorders_and_reparents(): void
    {
        $out  = Divi5_Blocks::move(self::FIXTURE, '0.0.0.1', '0.0.1', 0);
        $tree = Divi5_Blocks::tree($out);
        $this->assertSame(['divi/heading'], array_column($tree[0]['children'][0]['children'][0]['children'], 'name'));
        $this->assertSame(['divi/text', 'divi/button'], array_column($tree[0]['children'][0]['children'][1]['children'], 'name'));

        $out  = Divi5_Blocks::move(self::FIXTURE, '1', '', 0);
        $tree = Divi5_Blocks::tree($out);
        $this->assertSame([], $tree[0]['children']);
        $this->assertSame('divi/row', $tree[1]['children'][0]['name']);
    }

    public function test_move_into_itself_and_bad_paths_are_refused(): void
    {
        foreach ([['0', '0.0'], ['0.0', '0.0'], ['9', ''], ['x', '']] as [$path, $to]) {
            try {
                Divi5_Blocks::move(self::FIXTURE, $path, $to, null);
                $this->fail("Expected a refusal for {$path} -> {$to}");
            } catch (\InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function test_attr_strings_can_be_mapped_before_they_are_written(): void
    {
        $out = Divi5_Blocks::update(self::FIXTURE, '0.0.0.1', ['content' => ['innerContent' => ['desktop' => ['value' => '<script>x</script><p>ok</p>']]]], null, 'strtoupper');

        $text = Divi5_Blocks::tree($out)[0]['children'][0]['children'][0]['children'][1];
        $this->assertSame('<SCRIPT>X</SCRIPT><P>OK</P>', $text['attrs']->content->innerContent->desktop->value);
        // Values the call did not send are left as stored.
        $this->assertSame('5.18.0', $text['attrs']->builderVersion);
    }
}
