<?php

namespace WPMCP\Tools\Builders;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The rendered side of a Visual Composer page (issue #642): post_content
 * as its browser editor saved it, where each element's main node carries
 * id="el-<element id>". Only one change is made here: swapping the whole
 * inner HTML of one such node, and only when what is there now matches the
 * attribute value the editor rendered it from, so the markup and the
 * editor's document stay in step.
 */
final class Visual_Composer_Html
{
    private const VOID = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr'];

    /**
     * $html with the inner HTML of the el-<id> node set to $new, or null
     * when there is not exactly one such node or its inner HTML is not $old
     * as rendered.
     */
    public static function replace_inner(string $html, string $id, string $old, string $new): ?string
    {
        $range = self::inner_range($html, $id);
        if (null === $range) {
            return null;
        }
        [$start, $length] = $range;
        if (self::normalize(substr($html, $start, $length)) !== self::normalize($old)) {
            return null;
        }

        return substr($html, 0, $start) . $new . substr($html, $start + $length);
    }

    /**
     * Offset and length of the inner HTML of the one element whose id is
     * el-<id>, or null.
     *
     * @return array{0:int,1:int}|null
     */
    public static function inner_range(string $html, string $id): ?array
    {
        if (1 !== preg_match('/^[A-Za-z0-9_-]+$/', $id)) {
            return null;
        }
        $pattern = '/<([a-zA-Z][a-zA-Z0-9-]*)\b[^>]*?\sid\s*=\s*(["\'])el-' . preg_quote($id, '/') . '\2[^>]*>/';
        if (1 !== preg_match_all($pattern, $html, $matches, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $tag = strtolower($matches[1][0][0]);
        if (in_array($tag, self::VOID, true)) {
            return null;
        }
        $start = (int) $matches[0][0][1] + strlen($matches[0][0][0]);

        $depth = 1;
        $pos   = $start;
        $tags  = '/<(\/?)' . preg_quote($tag, '/') . '\b[^>]*>/i';
        while ($depth > 0 && 1 === preg_match($tags, $html, $m, PREG_OFFSET_CAPTURE, $pos)) {
            $offset = (int) $m[0][1];
            if ('/' === $m[1][0]) {
                --$depth;
                if (0 === $depth) {
                    return [$start, $offset - $start];
                }
            } elseif ('/>' !== substr($m[0][0], -2)) {
                ++$depth;
            }
            $pos = $offset + strlen($m[0][0]);
        }

        return null;
    }

    /** Markup compared the way a browser would see it: entities decoded, void tags and whitespace between tags folded. */
    public static function normalize(string $html): string
    {
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $html = (string) preg_replace('/<(' . implode('|', self::VOID) . ')\b([^>]*?)\s*\/?>/i', '<$1$2>', $html);
        $html = (string) preg_replace('/>\s+</', '><', $html);
        $html = (string) preg_replace('/\s+/', ' ', $html);

        return trim($html);
    }
}
