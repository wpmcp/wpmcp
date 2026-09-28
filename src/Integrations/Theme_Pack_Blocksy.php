<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Blocksy settings pack (issue #288), built by Theme_Settings_Pack.
 *
 * Storage, as the theme reads it: every setting is a theme_mod read through
 * blocksy_get_theme_mod(), which returns a stored value WHOLE.
 *
 *  - colorPalette is {colorN: {color}}; blocksy_get_colors() merges it with
 *    the defaults per colorN, and the text/link/heading colors per picker
 *    (default, hover), so partial arrays are safe there.
 *  - rootTypography is taken whole, so a family or size write seeds it with
 *    the theme's default base font first.
 *  - The header and footer are builder placements (header_placements,
 *    footer_placements): sections, each with an 'items' list of
 *    {id, values}. A write edits the default section (type-1, else the
 *    first) and refuses when the placements were never saved, since the
 *    builder's default structure only exists inside the theme.
 */
final class Theme_Pack_Blocksy
{
    /** @return array<string,mixed> */
    public static function spec(): array
    {
        $color    = [ 'rule' => 'color', 'var' => '/^var\(--theme-palette-color-\d{1,2}\)$/' ];
        $settings = [];
        for ($i = 1; $i <= 8; $i++) {
            $settings[ 'color' . $i ] = [ 'store' => 'mods', 'path' => [ 'colorPalette', 'color' . $i, 'color' ], 'rule' => [ 'rule' => 'color' ] ];
        }
        $root = [
            'family'          => 'System Default',
            'variation'       => 'n4',
            'size'            => '16px',
            'line-height'     => '1.65',
            'letter-spacing'  => '0em',
            'text-transform'  => 'none',
            'text-decoration' => 'none',
        ];
        $item = static fn (string $placements, string $id, string $field, array $check): array => [
            'store' => 'mods',
            'path'  => [
                $placements,
                'sections',
                [ 'match' => [ 'id' => 'type-1' ], 'first' => true ],
                'items',
                [ 'match' => [ 'id' => $id ], 'create' => [ 'id' => $id, 'values' => [] ] ],
                'values',
                $field,
            ],
            'rule'  => $check,
        ];

        return [
            'family'   => 'blocksy',
            'label'    => 'Blocksy',
            'tier'     => 'pro',
            'stores'   => [ 'mods' => [ 'type' => 'theme_mods' ] ],
            'settings' => $settings + [
                'fontColor'             => [ 'store' => 'mods', 'path' => [ 'fontColor', 'default', 'color' ], 'rule' => $color ],
                'linkColor'             => [ 'store' => 'mods', 'path' => [ 'linkColor', 'default', 'color' ], 'rule' => $color ],
                'linkHoverColor'        => [ 'store' => 'mods', 'path' => [ 'linkColor', 'hover', 'color' ], 'rule' => $color ],
                'headingColor'          => [ 'store' => 'mods', 'path' => [ 'headingColor', 'default', 'color' ], 'rule' => $color ],
                'rootFontFamily'        => [ 'store' => 'mods', 'path' => [ 'rootTypography', 'family' ], 'rule' => [ 'rule' => 'font' ], 'seed' => $root ],
                'rootFontSize'          => [ 'store' => 'mods', 'path' => [ 'rootTypography', 'size' ], 'rule' => [ 'rule' => 'px', 'min' => 8, 'max' => 72 ], 'seed' => $root ],
                'maxSiteWidth'          => [ 'store' => 'mods', 'path' => [ 'maxSiteWidth' ], 'rule' => [ 'rule' => 'int', 'min' => 700, 'max' => 1900 ] ],
                'narrowContainerWidth'  => [ 'store' => 'mods', 'path' => [ 'narrowContainerWidth' ], 'rule' => [ 'rule' => 'int', 'min' => 400, 'max' => 1000 ] ],
                'header_logo_height'    => $item('header_placements', 'logo', 'logoMaxHeight', [ 'rule' => 'int', 'min' => 0, 'max' => 300 ]),
                'footer_copyright_text' => $item('footer_placements', 'copyright', 'copyright_text', [ 'rule' => 'html' ]),
            ],
            'refresh'  => static function (): void {
                // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- invoking Blocksy's own dynamic CSS refresh action (inc/dynamic-css.php), not defining a hook.
                do_action('blocksy:dynamic-css:refresh-caches');
            },
        ];
    }
}
