<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * GeneratePress settings pack (issue #288), built by Theme_Settings_Pack.
 *
 * Storage, as the theme reads it: every setting is a key of the
 * generate_settings option, which generate_get_option() merges over
 * generate_get_defaults() one TOP-LEVEL key at a time. So a stored
 * global_colors or typography list replaces the default list whole, and a
 * write into either seeds it with the theme's default first.
 *
 *  - global_colors is a list of {name, slug, color}; the palette is exposed
 *    by slug (accent, contrast, base-2, ...).
 *  - typography is a list of rules keyed by selector and module; body and
 *    heading families are the 'body' and 'all-headings' core rules, which
 *    GeneratePress_Typography merges over its own per-rule defaults.
 *
 * After a write the cached dynamic CSS is dropped the way the theme's own
 * REST reset does, so the next page view regenerates it.
 */
final class Theme_Pack_GeneratePress
{
    /** @return array<string,mixed> */
    public static function spec(): array
    {
        // Color settings take a global color reference such as var(--accent).
        $color    = [ 'rule' => 'color', 'var' => '/^var\(--[a-z0-9-]{1,40}\)$/' ];
        $palette  = [
            'contrast'   => [ 'Contrast', '#222222' ],
            'contrast-2' => [ 'Contrast 2', '#575760' ],
            'contrast-3' => [ 'Contrast 3', '#b2b2be' ],
            'base'       => [ 'Base', '#f0f0f0' ],
            'base-2'     => [ 'Base 2', '#f7f8f9' ],
            'base-3'     => [ 'Base 3', '#ffffff' ],
            'accent'     => [ 'Accent', '#1e73be' ],
        ];
        $defaults = [];
        foreach ($palette as $slug => [ $name, $hex ]) {
            $defaults[] = [ 'name' => $name, 'slug' => $slug, 'color' => $hex ];
        }

        $settings = [];
        foreach ($palette as $slug => [ $name ]) {
            $settings[ $slug ] = [
                'store' => 'settings',
                'path'  => [ 'global_colors', [ 'match' => [ 'slug' => $slug ], 'create' => [ 'name' => $name, 'slug' => $slug, 'color' => '' ] ], 'color' ],
                'rule'  => [ 'rule' => 'color' ],
                'seed'  => $defaults,
            ];
        }
        foreach ([ 'text_color', 'link_color', 'link_color_hover', 'background_color', 'header_background_color', 'footer_background_color' ] as $key) {
            $settings[ $key ] = [ 'store' => 'settings', 'path' => [ $key ], 'rule' => $color ];
        }

        $rule = static fn (string $selector, string $group, string $field, array $check): array => [
            'store' => 'settings',
            'path'  => [
                'typography',
                [ 'match' => [ 'selector' => $selector, 'module' => 'core' ], 'create' => [ 'selector' => $selector, 'module' => 'core', 'group' => $group ] ],
                $field,
            ],
            'rule'  => $check,
            'seed'  => [],
        ];

        return [
            'family'   => 'generatepress',
            'label'    => 'GeneratePress',
            'tier'     => 'pro',
            'stores'   => [ 'settings' => [ 'type' => 'option', 'option' => 'generate_settings' ] ],
            'settings' => $settings + [
                'body_font_family'         => $rule('body', 'base', 'fontFamily', [ 'rule' => 'font' ]),
                'body_font_size'           => $rule('body', 'base', 'fontSize', [ 'rule' => 'int', 'min' => 8, 'max' => 72 ]),
                'heading_font_family'      => $rule('all-headings', 'content', 'fontFamily', [ 'rule' => 'font' ]),
                'container_width'          => [ 'store' => 'settings', 'path' => [ 'container_width' ], 'rule' => [ 'rule' => 'width' ] ],
                'header_layout_setting'    => [ 'store' => 'settings', 'path' => [ 'header_layout_setting' ], 'rule' => [ 'fluid-header', 'contained-header' ] ],
                'header_alignment_setting' => [ 'store' => 'settings', 'path' => [ 'header_alignment_setting' ], 'rule' => [ 'left', 'center', 'right' ] ],
                'nav_position_setting'     => [ 'store' => 'settings', 'path' => [ 'nav_position_setting' ], 'rule' => [ 'nav-below-header', 'nav-above-header', 'nav-float-right', 'nav-float-left', 'nav-left-sidebar', 'nav-right-sidebar', '' ] ],
                'footer_layout_setting'    => [ 'store' => 'settings', 'path' => [ 'footer_layout_setting' ], 'rule' => [ 'fluid-footer', 'contained-footer' ] ],
                'footer_widget_setting'    => [ 'store' => 'settings', 'path' => [ 'footer_widget_setting' ], 'rule' => [ '0', '1', '2', '3', '4', '5' ] ],
                'footer_bar_alignment'     => [ 'store' => 'settings', 'path' => [ 'footer_bar_alignment' ], 'rule' => [ 'left', 'center', 'right' ] ],
            ],
            'refresh'  => static function (): void {
                delete_option('generate_dynamic_css_output');
                delete_option('generate_dynamic_css_cached_version');
            },
        ];
    }
}
