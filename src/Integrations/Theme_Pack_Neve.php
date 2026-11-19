<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Neve settings pack (issue #655), built by Theme_Settings_Pack.
 *
 * Storage, as the theme reads it (inc/core/settings/config.php): theme_mods.
 *
 *  - neve_global_colors is {activePalette, palettes: {slug: {name,
 *    allowDeletion, colors: {nv-primary-accent, ...}}}}. The colors are
 *    exposed by their Neve slug and written into the ACTIVE palette; a first
 *    write seeds the theme's default palettes (neve_get_global_colors_default()),
 *    since the theme takes a stored value whole.
 *  - neve_body_font_family and neve_headings_font_family are plain strings.
 *  - neve_default_container_style is contained or full-width.
 *
 * Container width and font sizes are JSON-encoded responsive strings in
 * Neve, outside what the pack builder writes, so they are left to the
 * Customizer for now.
 */
final class Theme_Pack_Neve
{
    /** The base palette's colors, as neve_get_global_colors_default() ships them. */
    private const BASE = [
        'nv-primary-accent'   => '#2f5aae',
        'nv-secondary-accent' => '#2f5aae',
        'nv-site-bg'          => '#ffffff',
        'nv-light-bg'         => '#f4f5f7',
        'nv-dark-bg'          => '#121212',
        'nv-text-color'       => '#272626',
        'nv-text-dark-bg'     => '#ffffff',
        'nv-c-1'              => '#9463ae',
        'nv-c-2'              => '#be574b',
    ];

    /** The dark mode palette's colors, same source. */
    private const DARK = [
        'nv-primary-accent'   => '#00c2ff',
        'nv-secondary-accent' => '#00c2ff',
        'nv-site-bg'          => '#121212',
        'nv-light-bg'         => '#1a1a1a',
        'nv-dark-bg'          => '#000000',
        'nv-text-color'       => '#ffffff',
        'nv-text-dark-bg'     => '#ffffff',
        'nv-c-1'              => '#198754',
        'nv-c-2'              => '#be574b',
    ];

    /** @return array<string,mixed> */
    public static function spec(): array
    {
        $seed = [
            'activePalette' => 'base',
            'palettes'      => [
                'base'     => [ 'name' => 'Base', 'allowDeletion' => false, 'colors' => self::BASE ],
                'darkMode' => [ 'name' => 'Dark Mode', 'allowDeletion' => false, 'colors' => self::DARK ],
            ],
        ];

        $settings = [];
        foreach (array_keys(self::BASE) as $slug) {
            $settings[ $slug ] = [
                'store' => 'mods',
                'path'  => [ 'neve_global_colors', 'palettes', [ 'key_from_path' => [ 'neve_global_colors', 'activePalette' ], 'default' => 'base' ], 'colors', $slug ],
                'rule'  => [ 'rule' => 'color' ],
                'seed'  => $seed,
            ];
        }

        return [
            'family'   => 'neve',
            'label'    => 'Neve',
            'tier'     => 'free',
            'stores'   => [ 'mods' => [ 'type' => 'theme_mods' ] ],
            'settings' => $settings + [
                'body_font_family'        => [ 'store' => 'mods', 'path' => [ 'neve_body_font_family' ], 'rule' => [ 'rule' => 'font' ] ],
                'headings_font_family'    => [ 'store' => 'mods', 'path' => [ 'neve_headings_font_family' ], 'rule' => [ 'rule' => 'font' ] ],
                'default_container_style' => [ 'store' => 'mods', 'path' => [ 'neve_default_container_style' ], 'rule' => [ 'contained', 'full-width' ] ],
            ],
        ];
    }
}
