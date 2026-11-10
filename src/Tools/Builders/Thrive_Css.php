<?php

namespace WPMCP\Tools\Builders;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The element CSS Thrive Architect keeps beside a layout (issue #640).
 *
 * A page's `tve_custom_css` row is what the editor's
 * get_stylesheet_rules() serializes on save: the font @import rules, then
 * plain rules, then one `@media <query>{...}` block per responsive query
 * ((min-width: 300px), (max-width: 1023px), (max-width: 767px)). Element
 * rules are keyed by the element's data-css id, `[data-css="tve-u-..."]`.
 *
 * A user template stores the rules of its own elements as `media_css`, the
 * editor's collect_head_css() result: one entry per media query holding the
 * rules whose selector names one of the template's data-css ids, with key 0
 * the font imports and key 1 the plain rules. When the editor inserts a
 * template (process_saved_template()) it gives every tve-u- id a new value
 * in the markup and the rules alike, so a template inserted twice never
 * shares styles; insert() here does the same.
 *
 * Pure string work: no CSS is normalized, only whole rules are copied.
 */
final class Thrive_Css
{
    /** The editor's responsive queries, desktop first. */
    public const MEDIA = ['(min-width: 300px)', '(max-width: 1023px)', '(max-width: 767px)'];

    private const ID = '/tve-u-[A-Za-z0-9_-]+/';

    /**
     * Split a stylesheet into its top-level pieces: imports, and rules with
     * the media query they sit in ('' for none).
     *
     * @return array{imports:string[],rules:array<int,array{media:string,rule:string}>}
     */
    public static function split(string $css): array
    {
        $imports = [];
        $rules   = [];
        foreach (self::blocks($css) as $block) {
            if (0 === stripos($block, '@import')) {
                $imports[] = $block;
                continue;
            }
            if (preg_match('/^@media\s*([^{]*)\{(.*)\}$/s', $block, $m)) {
                foreach (self::blocks($m[2]) as $inner) {
                    $rules[] = ['media' => trim($m[1]), 'rule' => $inner];
                }
                continue;
            }
            $rules[] = ['media' => '', 'rule' => $block];
        }

        return ['imports' => $imports, 'rules' => $rules];
    }

    /**
     * The rules of a page's stylesheet that style the given data-css ids, in
     * the shape a user template stores them (see the class comment).
     *
     * @param string[] $ids
     * @return array<int|string,string>
     */
    public static function rules_for(string $css, array $ids): array
    {
        $out = [0 => '', 1 => ''];
        foreach (self::MEDIA as $media) {
            $out[ $media ] = '';
        }
        if ([] === $ids) {
            return $out;
        }

        $pattern = '/data-css=["\'](?:' . implode('|', array_map(static fn ($id) => preg_quote($id, '/'), $ids)) . ')["\']/';
        foreach (self::split($css)['rules'] as $item) {
            $brace    = strpos($item['rule'], '{');
            $selector = false === $brace ? $item['rule'] : substr($item['rule'], 0, $brace);
            if (! preg_match($pattern, $selector)) {
                continue;
            }
            $key         = '' === $item['media'] ? 1 : $item['media'];
            $out[ $key ] = ($out[ $key ] ?? '') . $item['rule'];
        }

        return $out;
    }

    /**
     * Give every tve-u- id in a template's markup and rules a new value no
     * page row uses, and return the remapped markup and the stylesheet with
     * the template's rules added: imports the page lacks go first, plain
     * rules and the legacy colour rules after the page's own, then one
     * @media block per query (a key that names no width is the desktop
     * query, as the editor treats it).
     *
     * @param array<int|string,mixed> $media_css
     * @return array{0:string,1:string} markup, stylesheet
     */
    public static function insert(string $page_html, string $page_css, string $markup, string $legacy_css, array $media_css): array
    {
        $taken = $page_html . $page_css;
        $map   = [];
        $remap = static function (array $m) use (&$map, &$taken): string {
            if (! isset($map[ $m[0] ])) {
                do {
                    $id = 'tve-u-' . substr(bin2hex(random_bytes(6)), 0, 11);
                } while (false !== strpos($taken, $id));
                $taken      .= $id;
                $map[ $m[0] ] = $id;
            }

            return $map[ $m[0] ];
        };

        $markup = (string) preg_replace_callback(self::ID, $remap, $markup);

        $imports = '';
        $plain   = '' === trim($legacy_css) ? '' : trim($legacy_css);
        $media   = [];
        foreach ($media_css as $key => $rules) {
            if (! is_string($rules) || '' === trim($rules)) {
                continue;
            }
            $rules = (string) preg_replace_callback(self::ID, $remap, $rules);
            if (0 === $key || '0' === $key) {
                foreach (self::split($rules)['imports'] as $import) {
                    if (false === strpos($page_css, $import)) {
                        $imports .= $import;
                    }
                }
                continue;
            }
            if (1 === $key || '1' === $key) {
                $plain .= $rules;
                continue;
            }
            $query           = false === strpos((string) $key, 'width') ? self::MEDIA[0] : trim((string) $key);
            $media[ $query ] = ($media[ $query ] ?? '') . $rules;
        }

        $css = $imports . $page_css . $plain;
        foreach ($media as $query => $rules) {
            $css .= '@media ' . $query . '{' . $rules . '}';
        }

        return [$markup, $css];
    }

    /** The data-css ids an element's markup carries. @return string[] */
    public static function ids(string $markup): array
    {
        preg_match_all('/data-css=["\'](tve-u-[^"\']+)["\']/', $markup, $m);

        return array_values(array_unique($m[1]));
    }

    /**
     * Top-level blocks of a stylesheet: each @import statement and each
     * `selector{...}` or `@media ...{...}` with its braces balanced, quotes
     * and comments respected.
     *
     * @return string[]
     */
    private static function blocks(string $css): array
    {
        $out   = [];
        $len   = strlen($css);
        $start = 0;
        $depth = 0;
        for ($i = 0; $i < $len; $i++) {
            $c = $css[ $i ];
            if ('"' === $c || "'" === $c) {
                $end = strpos($css, $c, $i + 1);
                $i   = false === $end ? $len : $end;
                continue;
            }
            if ('/' === $c && '*' === ($css[ $i + 1 ] ?? '')) {
                $end = strpos($css, '*/', $i + 2);
                $i   = false === $end ? $len : $end + 1;
                continue;
            }
            if (';' === $c && 0 === $depth) {
                $block = trim(substr($css, $start, $i + 1 - $start));
                if ('' !== $block && ';' !== $block) {
                    $out[] = $block;
                }
                $start = $i + 1;
            } elseif ('{' === $c) {
                $depth++;
            } elseif ('}' === $c && $depth > 0) {
                $depth--;
                if (0 === $depth) {
                    $out[] = trim(substr($css, $start, $i + 1 - $start));
                    $start = $i + 1;
                }
            }
        }

        return $out;
    }
}
