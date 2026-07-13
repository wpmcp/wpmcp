<?php

namespace WPMCP\Tools\Blocks;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Pure transform: given raw HTML, walk its top-level nodes and emit valid
 * Gutenberg block markup (the `<!-- wp:... -->` comment-delimited format
 * produced by serialize_block()). Each recognized top-level element maps to
 * a core block; anything unrecognized falls back to a core/html block so no
 * content is ever lost. No DB write, no Safe_Mutation.
 */
class Html_To_Blocks_Converter
{
    public static function convert(string $html): string
    {
        $document = new \DOMDocument();

        libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="utf-8" ?><div id="wpmcp-root">' . $html . '</div>',
            LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();

        $root = $document->getElementById('wpmcp-root');
        if (null === $root) {
            return '';
        }

        $blocks = [];
        foreach ($root->childNodes as $node) {
            $block = self::node_to_block($document, $node);
            if (null !== $block) {
                $blocks[] = $block;
            }
        }

        return serialize_blocks($blocks);
    }

    /**
     * @return array{blockName: string, attrs: array, innerBlocks: array, innerHTML: string, innerContent: array}|null
     */
    private static function node_to_block(\DOMDocument $document, \DOMNode $node): ?array
    {
        if (XML_TEXT_NODE === $node->nodeType) {
            $text = trim($node->textContent);
            return '' === $text ? null : self::html_block($text);
        }

        if (XML_ELEMENT_NODE !== $node->nodeType) {
            return null;
        }

        $tag = strtolower($node->nodeName);
        $inner_html = self::inner_html($document, $node);
        $outer_html = self::outer_html($document, $node);

        if (1 === preg_match('/^h([1-6])$/', $tag, $matches)) {
            $level = (int) $matches[1];
            return self::leaf_block('core/heading', ['level' => $level], $outer_html);
        }

        return match ($tag) {
            'p'          => self::leaf_block('core/paragraph', [], $outer_html),
            'ul'         => self::leaf_block('core/list', [], $outer_html),
            'ol'         => self::leaf_block('core/list', ['ordered' => true], $outer_html),
            'img'        => self::leaf_block('core/image', [], '<figure class="wp-block-image">' . $outer_html . '</figure>'),
            default      => self::html_block($outer_html),
        };
    }

    private static function leaf_block(string $name, array $attrs, string $html): array
    {
        return [
            'blockName'    => $name,
            'attrs'        => $attrs,
            'innerBlocks'  => [],
            'innerHTML'    => $html,
            'innerContent' => [$html],
        ];
    }

    private static function html_block(string $html): array
    {
        return self::leaf_block('core/html', [], $html);
    }

    private static function inner_html(\DOMDocument $document, \DOMNode $node): string
    {
        $html = '';
        foreach ($node->childNodes as $child) {
            $html .= $document->saveHTML($child);
        }
        return $html;
    }

    private static function outer_html(\DOMDocument $document, \DOMNode $node): string
    {
        return (string) $document->saveHTML($node);
    }
}
