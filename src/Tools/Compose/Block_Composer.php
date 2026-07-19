<?php

namespace WPMCP\Tools\Compose;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Deterministic Gutenberg composition: turn a validated build-page node tree
 * (see Page_Spec) into serialized block markup. A pure transform — no DB
 * writes, and nothing from the spec is ever evaluated or executed; text
 * settings are escaped (a whitelist of inline tags survives), URLs go
 * through esc_url, and code is fully entity-escaped. Only an explicit
 * "html" node passes markup through verbatim, into a core/html block —
 * exactly the stance the existing convert-html-to-blocks fallback takes.
 *
 * Pattern nodes (top level only, enforced by Page_Spec) inline the
 * registered pattern's own block markup; Build_Page::preflight() has
 * already verified the slug is registered.
 */
class Block_Composer
{
    private const INLINE_TAGS = [
        'strong' => [],
        'em'     => [],
        'br'     => [],
        'code'   => [],
        'a'      => ['href' => true],
    ];

    /**
     * @param array[] $sections Normalized top-level nodes from Page_Spec.
     * @return array{markup: string, count: int} Serialized markup + node count.
     */
    public static function compose(array $sections): array
    {
        $pieces = [];
        $count  = 0;

        foreach ($sections as $node) {
            if ('pattern' === $node['type']) {
                $pattern  = \WP_Block_Patterns_Registry::get_instance()->get_registered((string) $node['settings']['slug']);
                $pieces[] = (string) ($pattern['content'] ?? '');
                $count++;
                continue;
            }
            $pieces[] = serialize_block(self::block($node, $count));
        }

        return ['markup' => implode("\n\n", $pieces), 'count' => $count];
    }

    /** Build one parse_blocks()-shaped block array from a normalized node. */
    private static function block(array $node, int &$count): array
    {
        $count++;
        $settings = $node['settings'];

        switch ($node['type']) {
            case 'group':
                return self::container('core/group', ['layout' => ['type' => 'constrained']], '<div class="wp-block-group">', '</div>', $node['children'], $count);
            case 'columns':
                return self::container('core/columns', [], '<div class="wp-block-columns">', '</div>', $node['children'], $count);
            case 'column':
                return self::container('core/column', [], '<div class="wp-block-column">', '</div>', $node['children'], $count);
            case 'buttons':
                return self::container('core/buttons', [], '<div class="wp-block-buttons">', '</div>', $node['children'], $count);
            case 'heading':
                $level = (int) ($settings['level'] ?? 2);
                return self::leaf(
                    'core/heading',
                    2 === $level ? [] : ['level' => $level],
                    sprintf('<h%1$d class="wp-block-heading">%2$s</h%1$d>', $level, self::inline($settings['text']))
                );
            case 'paragraph':
                return self::leaf('core/paragraph', [], '<p>' . self::inline($settings['text']) . '</p>');
            case 'list':
                return self::list_block($settings);
            case 'quote':
                $cite = '' !== trim((string) ($settings['citation'] ?? ''))
                    ? '<cite>' . self::inline($settings['citation']) . '</cite>'
                    : '';
                return self::leaf(
                    'core/quote',
                    [],
                    '<blockquote class="wp-block-quote"><p>' . self::inline($settings['text']) . '</p>' . $cite . '</blockquote>'
                );
            case 'image':
                return self::image($settings);
            case 'button':
                $href = '' !== trim((string) ($settings['url'] ?? '')) ? ' href="' . esc_url((string) $settings['url']) . '"' : '';
                return self::leaf(
                    'core/button',
                    [],
                    '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button"' . $href . '>'
                        . self::inline($settings['text']) . '</a></div>'
                );
            case 'separator':
                return self::leaf('core/separator', [], '<hr class="wp-block-separator has-alpha-channel-opacity"/>');
            case 'spacer':
                $height = (int) ($settings['height'] ?? 100);
                return self::leaf(
                    'core/spacer',
                    ['height' => $height . 'px'],
                    sprintf('<div style="height:%dpx" aria-hidden="true" class="wp-block-spacer"></div>', $height)
                );
            case 'code':
                return self::leaf('core/code', [], '<pre class="wp-block-code"><code>' . esc_html((string) $settings['text']) . '</code></pre>');
            case 'html':
            default:
                return self::leaf('core/html', [], (string) $settings['html']);
        }
    }

    private static function list_block(array $settings): array
    {
        $ordered = ! empty($settings['ordered']);
        $tag     = $ordered ? 'ol' : 'ul';
        $items   = [];
        foreach ((array) $settings['items'] as $item) {
            $items[] = self::leaf('core/list-item', [], '<li>' . self::inline((string) $item) . '</li>');
        }

        return [
            'blockName'    => 'core/list',
            'attrs'        => $ordered ? ['ordered' => true] : [],
            'innerBlocks'  => $items,
            'innerHTML'    => sprintf('<%1$s class="wp-block-list"></%1$s>', $tag),
            'innerContent' => array_merge(
                [sprintf('<%s class="wp-block-list">', $tag)],
                array_fill(0, count($items), null),
                [sprintf('</%s>', $tag)]
            ),
        ];
    }

    private static function image(array $settings): array
    {
        $alt = esc_attr((string) ($settings['alt'] ?? ''));

        if (! empty($settings['attachment_id'])) {
            $id  = (int) $settings['attachment_id'];
            $src = esc_url((string) wp_get_attachment_url($id));
            return self::leaf(
                'core/image',
                ['id' => $id, 'sizeSlug' => 'full', 'linkDestination' => 'none'],
                sprintf(
                    '<figure class="wp-block-image size-full"><img src="%s" alt="%s" class="wp-image-%d"/></figure>',
                    $src,
                    $alt,
                    $id
                )
            );
        }

        return self::leaf(
            'core/image',
            [],
            sprintf('<figure class="wp-block-image"><img src="%s" alt="%s"/></figure>', esc_url((string) $settings['url']), $alt)
        );
    }

    private static function container(string $name, array $attrs, string $open, string $close, array $children, int &$count): array
    {
        $inner = [];
        foreach ($children as $child) {
            $inner[] = self::block($child, $count);
        }

        return [
            'blockName'    => $name,
            'attrs'        => $attrs,
            'innerBlocks'  => $inner,
            'innerHTML'    => $open . $close,
            'innerContent' => array_merge([$open], array_fill(0, count($inner), null), [$close]),
        ];
    }

    private static function leaf(string $name, array $attrs, string $html): array
    {
        return [
            'blockName'    => $name,
            'attrs'        => $attrs,
            'innerBlocks'  => [],
            'innerHTML'    => $html,
            'innerContent' => [$html],
        ];
    }

    /** Escape a text setting, keeping only a small whitelist of inline tags. */
    private static function inline($text): string
    {
        return wp_kses((string) $text, self::INLINE_TAGS);
    }
}
