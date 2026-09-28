<?php

namespace WPMCP\Tests\Pro\Builders;

use WPMCP\Tools\Builders\Thrive_Html;

/**
 * The Thrive Architect layout parser and editor. Thrive stores a page as the
 * editor's HTML, not as a JSON tree: every element is a wrapper carrying the
 * thrv_wrapper class (columns are tcb-flex-col), with its styles keyed by a
 * data-css id. The fixture follows what the editor saves, taken from public
 * page exports: a page section with its out/in layers, a text element, a
 * two-column row with an image and an empty column, a button, a content box
 * with a hidden group-edit config block and a background layer, and an
 * element class Thrive does not ship. That unknown element uses single and
 * unquoted attribute values, a boolean attribute, inline SVG with a
 * self-closed path, a script whose string holds a closing div tag, and a
 * void element, so it proves unknown markup survives byte for byte.
 */
class ThriveHtmlTest extends \WP_UnitTestCase
{
    public const TEXT = '<div class="thrv_wrapper thrv_text_element" data-css="tve-u-18a0c1f0a03"><h1 data-css="tve-u-18a0c1f0a04" style="text-align: center;">Welcome to C:\\Sites\\thrive &amp; "friends"</h1></div>';

    public const BUTTON_INNER = "\n" . '<a href="https://example.com/join" class="tcb-button-link" target="_blank" rel="nofollow">' . "\n"
        . '<span class="tcb-button-texts"><span class="tcb-button-text thrv-inline-text" data-css="tve-u-18a0c1f0a08">Join now</span></span>' . "\n"
        . '</a>' . "\n";

    public const UNKNOWN = '<div class=\'thrv_wrapper acme-countdown\' data-acme-config=\'{"end":"2026-12-31","labels":["d","h"]}\' data-css=tve-u-18a0c1f0a0b hidden>'
        . '<svg viewBox="0 0 10 10"><path d="M0 0L10 10"/></svg>'
        . '<script>var tpl = "<div class=\'x\'>" + "</div>";</script>'
        . '<span class="acme-digit">0</span><br></div>';

    public const EMPTY_COLUMN = '<div class="tcb-flex-col"><div class="tcb-col"></div></div>';

    public const SECTION = '<div class="thrv_wrapper thrv-page-section" data-css="tve-u-18a0c1f0a00" style="">' . "\n"
        . '<div class="tve-page-section-out" data-css="tve-u-18a0c1f0a01"></div>' . "\n"
        . '<div class="tve-page-section-in tve_empty_dropzone" data-css="tve-u-18a0c1f0a02">' . self::TEXT . "\n"
        . '<div class="thrv_wrapper thrv-columns"><div class="tcb-flex-row tcb--cols--2" data-css="tve-u-18a0c1f0a05">'
        . '<div class="tcb-flex-col"><div class="tcb-col"><div class="thrv_wrapper tve_image_caption" data-css="tve-u-18a0c1f0a06"><span class="tve_image_frame" style="width: 100%;"><img loading="lazy" class="tve_image wp-image-12" alt="" width="512" height="512" data-id="12" src="/wp-content/uploads/hero.jpg" style="width: 100%;"></span></div></div></div>'
        . self::EMPTY_COLUMN . '</div></div>' . "\n"
        . '<div class="thrv_wrapper thrv-button" data-css="tve-u-18a0c1f0a07">' . self::BUTTON_INNER . '</div>' . "\n"
        . '</div>' . "\n"
        . '</div>';

    public const BOX_TEXT = '<div class="thrv_wrapper thrv_text_element" data-css="tve-u-18a0c1f0a0c"><p>Paragraph <strong>two</strong></p></div>';

    public const BOX = '<div class="thrv_wrapper thrv_contentbox_shortcode thrv-content-box" data-css="tve-u-18a0c1f0a09">'
        . '<div class="thrive-group-edit-config" style="display: none !important">__CONFIG_group_edit__{"jxw1b2a3":{"name":"All Paragraph(s)"}}__CONFIG_group_edit__</div>' . "\n"
        . '<div class="tve-content-box-background" data-css="tve-u-18a0c1f0a0a"></div>' . "\n"
        . '<div class="tve-cb">' . self::UNKNOWN . "\n" . self::BOX_TEXT . '</div>' . "\n"
        . '</div>';

    public const FIXTURE = self::SECTION . "\n" . self::BOX . "\n";

    private static function node(array $tree, string $path): array
    {
        $node = ['children' => $tree];
        foreach (explode('.', $path) as $step) {
            $node = $node['children'][ (int) $step ];
        }

        return $node;
    }

    // ----------------------------------------------------------------- read

    public function test_tree_addresses_sections_containers_and_elements_by_path(): void
    {
        $tree = Thrive_Html::tree(self::FIXTURE);

        $this->assertCount(2, $tree);
        $shape = static function (array $nodes) use (&$shape): array {
            $out = [];
            foreach ($nodes as $node) {
                $out[ $node['path'] ] = [$node['type'], $node['kind']];
                $out += $shape($node['children'] ?? []);
            }
            return $out;
        };
        $this->assertSame([
            '0'       => ['section', 'section'],
            '0.0'     => ['text', 'element'],
            '0.1'     => ['columns', 'container'],
            '0.1.0'   => ['column', 'container'],
            '0.1.0.0' => ['image', 'element'],
            '0.1.1'   => ['column', 'container'],
            '0.2'     => ['button', 'element'],
            '1'       => ['content-box', 'container'],
            '1.0'     => ['acme-countdown', 'element'],
            '1.1'     => ['text', 'element'],
        ], $shape($tree));
    }

    public function test_elements_expose_their_attributes_as_stored_and_their_inner_html(): void
    {
        $tree = Thrive_Html::tree(self::FIXTURE);

        $text = self::node($tree, '0.0');
        $this->assertSame('div', $text['tag']);
        $this->assertSame(['class' => 'thrv_wrapper thrv_text_element', 'data-css' => 'tve-u-18a0c1f0a03'], $text['attrs']);
        $this->assertSame('<h1 data-css="tve-u-18a0c1f0a04" style="text-align: center;">Welcome to C:\\Sites\\thrive &amp; "friends"</h1>', $text['text']);
        $this->assertArrayNotHasKey('children', $text);

        $this->assertSame(self::BUTTON_INNER, self::node($tree, '0.2')['text']);

        $unknown = self::node($tree, '1.0');
        $this->assertSame([
            'class'            => 'thrv_wrapper acme-countdown',
            'data-acme-config' => '{"end":"2026-12-31","labels":["d","h"]}',
            'data-css'         => 'tve-u-18a0c1f0a0b',
            'hidden'           => '',
        ], $unknown['attrs']);
        $this->assertStringEndsWith('<span class="acme-digit">0</span><br>', $unknown['text']);

        $section = self::node($tree, '0');
        $this->assertArrayNotHasKey('text', $section);
        $this->assertSame([], self::node($tree, '0.1.1')['children']);
    }

    public function test_an_empty_document_has_an_empty_tree(): void
    {
        $this->assertSame([], Thrive_Html::tree(''));
        $this->assertSame([], Thrive_Html::tree("<p>No Thrive elements here</p>\n"));
    }

    // --------------------------------------------------------------- update

    public function test_text_update_replaces_only_that_elements_inner_html(): void
    {
        $out = Thrive_Html::update(self::FIXTURE, '0.0', null, '<h2>New \\ heading</h2>');

        $this->assertSame(
            str_replace(self::TEXT, '<div class="thrv_wrapper thrv_text_element" data-css="tve-u-18a0c1f0a03"><h2>New \\ heading</h2></div>', self::FIXTURE),
            $out
        );
    }

    public function test_attribute_update_edits_the_opening_tag_in_place(): void
    {
        $out = Thrive_Html::update(self::FIXTURE, '0', ['style' => null, 'data-css' => 'tve-u-new', 'data-label' => 'Say "hi"'], null);

        $this->assertSame(
            str_replace(
                '<div class="thrv_wrapper thrv-page-section" data-css="tve-u-18a0c1f0a00" style="">',
                '<div class="thrv_wrapper thrv-page-section" data-css="tve-u-new" data-label="Say &quot;hi&quot;">',
                self::FIXTURE
            ),
            $out
        );
    }

    public function test_attribute_update_on_an_unknown_element_keeps_its_other_bytes(): void
    {
        $out = Thrive_Html::update(self::FIXTURE, '1.0', ['hidden' => null, 'data-css' => 'tve-u-18a0c1f0aff'], null);

        $expected = str_replace(
            "data-css=tve-u-18a0c1f0a0b hidden>",
            'data-css="tve-u-18a0c1f0aff">',
            self::UNKNOWN
        );
        $this->assertSame(str_replace(self::UNKNOWN, $expected, self::FIXTURE), $out);
    }

    // ------------------------------------------------------------------ add

    public function test_add_inserts_before_a_sibling_into_an_empty_column_and_at_the_top(): void
    {
        $spec = ['tag' => 'div', 'attrs' => ['class' => 'thrv_wrapper thrv_text_element'], 'text' => '<p>New</p>'];
        $new  = '<div class="thrv_wrapper thrv_text_element"><p>New</p></div>';

        [$out, $path] = Thrive_Html::add(self::FIXTURE, '1', 0, $spec);
        $this->assertSame('1.0', $path);
        $this->assertSame(str_replace('<div class="tve-cb">', '<div class="tve-cb">' . $new, self::FIXTURE), $out);

        [$out, $path] = Thrive_Html::add(self::FIXTURE, '0.1.1', null, $spec);
        $this->assertSame('0.1.1.0', $path);
        $this->assertSame(str_replace(self::EMPTY_COLUMN, '<div class="tcb-flex-col"><div class="tcb-col">' . $new . '</div></div>', self::FIXTURE), $out);

        [$out, $path] = Thrive_Html::add(self::FIXTURE, '', null, $spec);
        $this->assertSame('2', $path);
        $this->assertSame(self::SECTION . "\n" . self::BOX . $new . "\n", $out);
    }

    public function test_add_takes_raw_html_and_nested_children(): void
    {
        $html = '<div class="thrv_wrapper acme-other" data-x=\'1\'><i>raw</i></div>';
        [$out, $path] = Thrive_Html::add(self::FIXTURE, '0', 1, ['html' => $html]);
        $this->assertSame('0.1', $path);
        $this->assertSame(str_replace(self::TEXT . "\n", self::TEXT . "\n" . $html, self::FIXTURE), $out);

        $this->assertSame(
            '<div class="thrv_wrapper thrv-content-box"><div class="thrv_wrapper thrv_text_element"><p>A</p></div></div>',
            Thrive_Html::serialize([
                'attrs'    => ['class' => 'thrv_wrapper thrv-content-box'],
                'children' => [['attrs' => ['class' => 'thrv_wrapper thrv_text_element'], 'text' => '<p>A</p>']],
            ])
        );
    }

    // ---------------------------------------------------------- remove/move

    public function test_remove_cuts_exactly_that_element(): void
    {
        $this->assertSame(str_replace(self::UNKNOWN, '', self::FIXTURE), Thrive_Html::remove(self::FIXTURE, '1.0'));
        $this->assertSame(self::SECTION . "\n\n", Thrive_Html::remove(self::FIXTURE, '1'));
    }

    public function test_move_carries_an_unknown_element_verbatim_and_back(): void
    {
        $moved = Thrive_Html::move(self::FIXTURE, '1.0', '0.1.1', 0);

        $this->assertSame(
            str_replace(
                [self::EMPTY_COLUMN, '<div class="tve-cb">' . self::UNKNOWN],
                ['<div class="tcb-flex-col"><div class="tcb-col">' . self::UNKNOWN . '</div></div>', '<div class="tve-cb">'],
                self::FIXTURE
            ),
            $moved
        );
        $this->assertSame('acme-countdown', self::node(Thrive_Html::tree($moved), '0.1.1.0')['type']);

        // Back in front of its old sibling: the element's bytes are the same,
        // only the newline that separated the two now follows the column.
        $this->assertSame(
            str_replace(self::UNKNOWN . "\n" . self::BOX_TEXT, "\n" . self::UNKNOWN . self::BOX_TEXT, self::FIXTURE),
            Thrive_Html::move($moved, '0.1.1.0', '1', 0)
        );
    }

    public function test_move_within_the_same_parent_uses_the_final_index(): void
    {
        $out = Thrive_Html::move(self::FIXTURE, '1.1', '1', 0);

        $this->assertSame(str_replace(self::UNKNOWN . "\n" . self::BOX_TEXT, self::BOX_TEXT . self::UNKNOWN . "\n", self::FIXTURE), $out);
    }

    // --------------------------------------------------------------- errors

    /** @return array<string,array{0:callable}> */
    public static function rejected(): array
    {
        $f = self::FIXTURE;

        return [
            'no path'                => [static fn () => Thrive_Html::remove($f, '')],
            'bad path'               => [static fn () => Thrive_Html::remove($f, '0.x')],
            'missing path'           => [static fn () => Thrive_Html::remove($f, '7')],
            'nothing to update'      => [static fn () => Thrive_Html::update($f, '0.0', null, null)],
            'text on a container'    => [static fn () => Thrive_Html::update($f, '1', null, '<p>x</p>')],
            'text on a section'      => [static fn () => Thrive_Html::update($f, '0', null, '<p>x</p>')],
            'unbalanced text'        => [static fn () => Thrive_Html::update($f, '0.0', null, '<p>open')],
            'stray closing tag'      => [static fn () => Thrive_Html::update($f, '0.0', null, 'x</div>')],
            'bad attribute name'     => [static fn () => Thrive_Html::update($f, '0.0', ['on click' => 'x'], null)],
            'non-scalar attribute'   => [static fn () => Thrive_Html::update($f, '0.0', ['data-x' => ['a']], null)],
            'dropping the wrapper'   => [static fn () => Thrive_Html::update($f, '0.0', ['class' => 'plain'], null)],
            'removing the class'     => [static fn () => Thrive_Html::update($f, '0.0', ['class' => null], null)],
            'add under an element'   => [static fn () => Thrive_Html::add($f, '0.0', 0, ['attrs' => ['class' => 'thrv_wrapper'], 'text' => 'x'])],
            'add without wrapper'    => [static fn () => Thrive_Html::add($f, '1', 0, ['attrs' => ['class' => 'plain'], 'text' => 'x'])],
            'add two elements'       => [static fn () => Thrive_Html::add($f, '1', 0, ['html' => '<div class="thrv_wrapper">a</div><div class="thrv_wrapper">b</div>'])],
            'add unbalanced html'    => [static fn () => Thrive_Html::add($f, '1', 0, ['html' => '<div class="thrv_wrapper"><p>a</div>'])],
            'add bad tag'            => [static fn () => Thrive_Html::add($f, '1', 0, ['tag' => 'di v', 'attrs' => ['class' => 'thrv_wrapper']])],
            'add text and children'  => [static fn () => Thrive_Html::add($f, '1', 0, ['attrs' => ['class' => 'thrv_wrapper'], 'text' => 'x', 'children' => []])],
            'negative index'         => [static fn () => Thrive_Html::add($f, '1', -1, ['attrs' => ['class' => 'thrv_wrapper'], 'text' => 'x'])],
            'move into itself'       => [static fn () => Thrive_Html::move($f, '0', '0.1.1', 0)],
            'move under an element'  => [static fn () => Thrive_Html::move($f, '1.1', '0.0', 0)],
            'unclosed element'       => [static fn () => Thrive_Html::tree('<div class="thrv_wrapper thrv_text_element"><p>x</p>')],
        ];
    }

    /** @dataProvider rejected */
    public function test_invalid_requests_are_rejected(callable $call): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $call();
    }
}
