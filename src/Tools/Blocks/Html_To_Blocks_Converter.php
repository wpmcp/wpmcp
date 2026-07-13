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

        if (! $node instanceof \DOMElement) {
            return null;
        }

        $tag = strtolower($node->nodeName);
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
            'blockquote' => self::leaf_block('core/quote', [], $outer_html),
            'pre'        => self::leaf_block('core/code', [], self::pre_to_code_html($document, $node)),
            'code'       => self::leaf_block('core/code', [], self::pre_to_code_html($document, $node, wrap_in_pre: true)),
            'hr'         => self::leaf_block('core/separator', [], '<hr class="wp-block-separator"/>'),
            'table'      => self::leaf_block('core/table', [], self::with_added_class($document, $node, 'wp-block-table')),
            default      => self::html_block($outer_html),
        };
    }

    /**
     * Merge $class into $node's existing class attribute (if any) and return
     * the resulting outer HTML, matching the wp-block-* class WordPress adds
     * to the wrapper element of several core blocks.
     */
    private static function with_added_class(\DOMDocument $document, \DOMElement $node, string $class): string
    {
        $existing = trim((string) $node->getAttribute('class'));
        $classes  = array_filter(array_unique(array_merge(
            '' === $existing ? [] : preg_split('/\s+/', $existing),
            [$class]
        )));
        $node->setAttribute('class', implode(' ', $classes));

        return self::outer_html($document, $node);
    }

    /**
     * Normalize a <pre>/<code> node into the exact markup real Gutenberg
     * produces for core/code: <pre class="wp-block-code"><code>...</code></pre>.
     * When $wrap_in_pre is true, $node is itself a bare top-level <code>
     * element that needs its own <pre> wrapper; otherwise $node is the <pre>
     * and its inner content is reused as-is (already containing a <code>).
     */
    private static function pre_to_code_html(\DOMDocument $document, \DOMNode $node, bool $wrap_in_pre = false): string
    {
        if ($wrap_in_pre) {
            $code_html = self::outer_html($document, $node);
            return '<pre class="wp-block-code">' . $code_html . '</pre>';
        }

        $inner = self::inner_html($document, $node);
        return '<pre class="wp-block-code">' . $inner . '</pre>';
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
