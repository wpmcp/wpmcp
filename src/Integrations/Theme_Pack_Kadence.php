<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Kadence settings pack (issue #288), built by Theme_Settings_Pack.
 *
 * Storage, as the theme reads it (inc/components/options/component.php):
 *  - the global palette is the JSON-encoded kadence_global_palette option,
 *    whose 'active' field names the live palette list; palette_option()
 *    finds each color by its paletteN slug in that list.
 *  - everything else is a theme_mod read through kadence()->option(), which
 *    takes a stored array WHOLE (it does not merge defaults into it), so a
 *    sub-field write seeds the key with the theme's own default first.
 */
final class Theme_Pack_Kadence
{
    /** Kadence's default palette colors, palette1 to palette15 (palette_defaults()). */
    private const PALETTE = [
        '#2B6CB0', '#215387', '#1A202C', '#2D3748', '#4A5568', '#718096', '#EDF2F7', '#F7FAFC', '#ffffff',
        '#FfFfFf', '#13612e', '#1159af', '#b82105', '#f7630c', '#f5a524',
    ];

    /** @return array<string,mixed> */
    public static function spec(): array
    {
        // Kadence color fields take a palette slug (palette4) as well as a
        // literal color; the palette entries themselves must be literal.
        $color    = [ 'rule' => 'color', 'var' => '/^palette\d{1,2}$/' ];
        $settings = [];
        for ($i = 1; $i <= 9; $i++) {
            $settings[ 'palette' . $i ] = [
                'store' => 'palette',
                'path'  => [ [ 'key_from' => 'active', 'default' => 'palette' ], [ 'match' => [ 'slug' => 'palette' . $i ] ], 'color' ],
                'rule'  => [ 'rule' => 'color' ],
            ];
        }
        $width = static fn (string $key, int $size): array => [
            'store' => 'mods',
            'path'  => [ $key, 'size' ],
            'rule'  => [ 'rule' => 'width' ],
            'seed'  => [ 'size' => $size, 'unit' => 'px' ],
        ];
        $base_font = [
            'size'       => [ 'desktop' => 17 ],
            'lineHeight' => [ 'desktop' => 1.6 ],
            'family'     => '-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Oxygen-Sans,Ubuntu,Cantarell,"Helvetica Neue",sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol"',
            'google'     => false,
            'weight'     => '400',
            'variant'    => 'regular',
            'color'      => 'palette4',
        ];

        return [
            'family'   => 'kadence',
            'label'    => 'Kadence',
            'tier'     => 'free',
            'stores'   => [
                'mods'    => [ 'type' => 'theme_mods' ],
                'palette' => [
                    'type'   => 'json_option',
                    'option' => 'kadence_global_palette',
                    'seed'   => [
                        'palette'        => self::palette_list(),
                        'second-palette' => self::palette_list(),
                        'third-palette'  => self::palette_list(),
                        'active'         => 'palette',
                    ],
                ],
            ],
            'settings' => $settings + [
                'content_width'             => $width('content_width', 1290),
                'content_narrow_width'      => $width('content_narrow_width', 842),
                'base_font_family'          => [ 'store' => 'mods', 'path' => [ 'base_font', 'family' ], 'rule' => [ 'rule' => 'font' ], 'seed' => $base_font ],
                'base_font_size'            => [ 'store' => 'mods', 'path' => [ 'base_font', 'size', 'desktop' ], 'rule' => [ 'rule' => 'int', 'min' => 8, 'max' => 72 ], 'seed' => $base_font ],
                'heading_font_family'       => [ 'store' => 'mods', 'path' => [ 'heading_font', 'family' ], 'rule' => [ 'rule' => 'font' ], 'seed' => [ 'family' => 'inherit' ] ],
                'header_background'         => [ 'store' => 'mods', 'path' => [ 'header_main_background', 'desktop', 'color' ], 'rule' => $color ],
                'header_sticky'             => [ 'store' => 'mods', 'path' => [ 'header_sticky' ], 'rule' => [ 'no', 'main', 'top_main', 'top_main_bottom', 'top', 'bottom' ] ],
                'transparent_header_enable' => [ 'store' => 'mods', 'path' => [ 'transparent_header_enable' ], 'rule' => [ 'rule' => 'bool' ] ],
                'footer_background'         => [ 'store' => 'mods', 'path' => [ 'footer_wrap_background', 'desktop', 'color' ], 'rule' => $color ],
                'footer_html_content'       => [ 'store' => 'mods', 'path' => [ 'footer_html_content' ], 'rule' => [ 'rule' => 'html' ] ],
            ],
        ];
    }

    /** @return array<int,array<string,string>> one palette list in Kadence's stored shape. */
    private static function palette_list(): array
    {
        $named = [ 10 => 'Complement', 11 => 'Success', 12 => 'Info', 13 => 'Alert', 14 => 'Warning', 15 => 'Rating' ];
        $list  = [];
        foreach (self::PALETTE as $i => $hex) {
            $n      = $i + 1;
            $list[] = [ 'color' => $hex, 'slug' => 'palette' . $n, 'name' => 'Palette Color ' . ($named[ $n ] ?? (string) $n) ];
        }
        return $list;
    }
}
