<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * OceanWP settings pack (issue #655), built by Theme_Settings_Pack.
 *
 * Storage, as the theme reads it (inc/customizer/options/*.php): every
 * setting is a flat theme_mod (ocean_primary_color, ocean_main_container_width,
 * ocean_header_style, ...) except typography, which is one theme_mod array
 * per element (body_typography[font-family], headings_typography[color]),
 * read key by key with the theme's default for any key that is missing, so a
 * sub-key write needs no seed.
 *
 * OceanWP prints its customizer CSS inline on each request, so a write
 * needs no cache refresh of its own.
 */
final class Theme_Pack_OceanWP
{
    /** @return array<string,mixed> */
    public static function spec(): array
    {
        $mod   = static fn (string $key, $rule): array => [ 'store' => 'mods', 'path' => [ $key ], 'rule' => $rule ];
        $typo  = static fn (string $group, string $field, $rule): array => [ 'store' => 'mods', 'path' => [ $group, $field ], 'rule' => $rule ];
        $color = [ 'rule' => 'color' ];

        return [
            'family'   => 'oceanwp',
            'label'    => 'OceanWP',
            'tier'     => 'free',
            'stores'   => [ 'mods' => [ 'type' => 'theme_mods' ] ],
            'settings' => [
                'primary_color'            => $mod('ocean_primary_color', $color),
                'primary_hover_color'      => $mod('ocean_hover_primary_color', $color),
                'background_color'         => $mod('ocean_background_color', $color),
                'border_color'             => $mod('ocean_main_border_color', $color),
                'links_color'              => $mod('ocean_links_color', $color),
                'links_hover_color'        => $mod('ocean_links_color_hover', $color),
                'body_text_color'          => $typo('body_typography', 'color', $color),
                'headings_color'           => $typo('headings_typography', 'color', $color),
                'body_font_family'         => $typo('body_typography', 'font-family', [ 'rule' => 'font' ]),
                'body_font_size'           => $typo('body_typography', 'font-size', [ 'rule' => 'int', 'min' => 8, 'max' => 72 ]),
                'headings_font_family'     => $typo('headings_typography', 'font-family', [ 'rule' => 'font' ]),
                'container_width'          => $mod('ocean_main_container_width', [ 'rule' => 'width' ]),
                'layout_style'             => $mod('ocean_main_layout_style', [ 'wide', 'boxed', 'separate' ]),
                'header_style'             => $mod('ocean_header_style', [ 'minimal', 'transparent', 'top', 'full_screen', 'center', 'medium', 'vertical', 'custom' ]),
                'header_background'        => $mod('ocean_header_background', $color),
                'footer_background'        => $mod('ocean_footer_background', $color),
                'bottom_footer_background' => $mod('ocean_bottom_footer_background', $color),
                'footer_widgets_columns'   => $mod('ocean_footer_widgets_columns', [ 'rule' => 'int', 'min' => 1, 'max' => 4 ]),
                'footer_copyright_text'    => $mod('ocean_footer_copyright_text', [ 'rule' => 'html' ]),
            ],
        ];
    }
}
