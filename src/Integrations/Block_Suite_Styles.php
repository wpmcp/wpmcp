<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Compiles a GenerateBlocks 2 block's `styles` attribute into the `css`
 * attribute its front end prints (issue #287).
 *
 * GenerateBlocks 2 blocks (text, element, media, shape, query, looper,
 * loop-item, query-page-numbers) print the `css` attribute verbatim; the
 * editor compiles it from `styles` whenever the block is edited. A block
 * written outside the editor therefore needs the same compile or it renders
 * unstyled. The styles object is the editor's own shape: camelCase CSS
 * properties at the top level, nested selectors ("&:hover", " a") and
 * at-rules ("@media (max-width:767px)") as objects, and an at-rule may hold
 * nested selectors of its own. Properties are sorted alphabetically inside
 * each rule, as the editor's build does.
 *
 * Values are refused, not escaped, when they could leave their declaration
 * or rule (braces, semicolons, angle brackets), so nothing an agent writes
 * can inject a rule of its own.
 */
final class Block_Suite_Styles
{
    /** GenerateBlocks block name => selector segment (its getSelector map). */
    private const SELECTORS = [
        'generateblocks/text'               => 'text',
        'generateblocks/element'            => 'element',
        'generateblocks/loop-item'          => 'loop-item',
        'generateblocks/looper'             => 'looper',
        'generateblocks/media'              => 'media',
        'generateblocks/query'              => 'query',
        'generateblocks/query-page-numbers' => 'query-page-numbers',
        'generateblocks/shape'              => 'shape',
    ];

    /** The block's CSS selector, or null for a block that does not use the styles attribute. */
    public static function selector(string $block_name, string $unique_id): ?string
    {
        return isset(self::SELECTORS[ $block_name ]) ? sprintf('.gb-%s-%s', self::SELECTORS[ $block_name ], $unique_id) : null;
    }

    /**
     * Why a styles object cannot be compiled, or null when it can.
     *
     * @param mixed $styles
     */
    public static function problem($styles): ?string
    {
        if (! is_array($styles)) {
            return 'styles must be an object.';
        }
        try {
            self::compile('.x', $styles);
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }
        return null;
    }

    /** Compile a styles object for one selector. Throws on a value it refuses. */
    public static function compile(string $selector, array $styles): string
    {
        $groups = self::group($styles);
        $css    = self::rule($selector, $groups['props']);
        foreach ($groups['nested'] as $nested => $props) {
            $css .= self::rule(self::combine($selector, (string) $nested), $props);
        }
        foreach ($groups['at'] as $at => $inner) {
            $inner_groups = self::group($inner);
            $body         = self::rule($selector, $inner_groups['props']);
            foreach ($inner_groups['nested'] as $nested => $props) {
                $body .= self::rule(self::combine($selector, (string) $nested), $props);
            }
            if ('' !== $body) {
                $css .= $at . '{' . $body . '}';
            }
        }
        return $css;
    }

    /**
     * Split a styles object into plain properties, nested rules and
     * at-rules (an at-rule nested under a selector is lifted to the top with
     * the selector inside it), each sorted by key.
     *
     * @return array{props:array,nested:array,at:array}
     */
    private static function group(array $styles): array
    {
        $out = [ 'props' => [], 'nested' => [], 'at' => [] ];
        foreach ($styles as $key => $value) {
            $key = (string) $key;
            if (0 === strpos($key, '@')) {
                self::check_at_rule($key);
                if (! is_array($value)) {
                    throw new \InvalidArgumentException(sprintf('At-rule "%s" must hold an object of styles.', esc_html($key)));
                }
                $out['at'][ $key ] = array_replace_recursive($out['at'][ $key ] ?? [], $value);
            } elseif (is_array($value)) {
                self::check_selector($key);
                foreach ($value as $inner_key => $inner) {
                    $inner_key = (string) $inner_key;
                    if (0 === strpos($inner_key, '@')) {
                        self::check_at_rule($inner_key);
                        if (! is_array($inner)) {
                            throw new \InvalidArgumentException(sprintf('At-rule "%s" must hold an object of styles.', esc_html($inner_key)));
                        }
                        $out['at'][ $inner_key ][ $key ] = array_replace($out['at'][ $inner_key ][ $key ] ?? [], $inner);
                    } else {
                        $out['nested'][ $key ][ $inner_key ] = $inner;
                    }
                }
            } else {
                $out['props'][ $key ] = $value;
            }
        }
        ksort($out['nested'], SORT_STRING);
        ksort($out['at'], SORT_STRING);
        return $out;
    }

    /** One rule, properties sorted by CSS name; empty when it has no declarations. */
    private static function rule(string $selector, array $props): string
    {
        $decls = [];
        foreach ($props as $name => $value) {
            $name = (string) $name;
            if ('settings' === $name || null === $value || '' === $value) {
                continue;
            }
            $prop = 0 === strpos($name, '--') ? $name : strtolower((string) preg_replace('/([a-z0-9]|(?=[A-Z]))([A-Z])/', '$1-$2', $name));
            if (1 !== preg_match('/^(--[A-Za-z0-9_-]+|-?[a-z][a-z0-9-]*)$/', $prop)) {
                throw new \InvalidArgumentException(sprintf('"%s" is not a CSS property name.', esc_html($name)));
            }
            if (! is_scalar($value) || is_bool($value)) {
                throw new \InvalidArgumentException(sprintf('Value for "%s" must be a string or number.', esc_html($name)));
            }
            $value = trim((string) $value);
            if (1 === preg_match('/[{};<>]/', $value)) {
                throw new \InvalidArgumentException(sprintf('Value for "%s" contains a character that could end the rule.', esc_html($name)));
            }
            $decls[ $prop ] = $value;
        }
        if ([] === $decls) {
            return '';
        }
        ksort($decls, SORT_STRING);
        $body = [];
        foreach ($decls as $prop => $value) {
            $body[] = $prop . ':' . $value;
        }
        return $selector . '{' . implode(';', $body) . '}';
    }

    /** Combine the block selector with a nested selector: "&:hover" attaches, anything else descends. */
    private static function combine(string $selector, string $nested): string
    {
        $nested = trim(html_entity_decode($nested, ENT_QUOTES, 'UTF-8'));
        if ('' === $nested) {
            return $selector;
        }
        $out = [];
        foreach (array_map('trim', explode(',', $nested)) as $part) {
            $out[] = 0 === strpos($part, '&') ? $selector . substr($part, 1) : $selector . ' ' . $part;
        }
        return implode(',', $out);
    }

    private static function check_selector(string $selector): void
    {
        if ('' === trim($selector) || 1 === preg_match('/[{};<]/', $selector)) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a nested selector the styles object accepts.', esc_html($selector)));
        }
    }

    private static function check_at_rule(string $at): void
    {
        if (1 !== preg_match('/^@(media|container|supports)\b[^{};<>]*$/', $at)) {
            throw new \InvalidArgumentException(sprintf('"%s" is not an at-rule the styles object accepts (media, container or supports).', esc_html($at)));
        }
    }
}
