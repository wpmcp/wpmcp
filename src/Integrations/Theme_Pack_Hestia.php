<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Hestia settings pack (issue #655), built by Theme_Settings_Pack.
 *
 * Storage, as the theme reads it (inc/customizer/general/*): flat
 * theme_mods. Only the settings the free theme itself renders are on the
 * allowlist; the extra colors and footer credits are Hestia Pro controls.
 * Hestia prints its customizer CSS inline, so no cache refresh is needed.
 */
final class Theme_Pack_Hestia
{
    /** @return array<string,mixed> */
    public static function spec(): array
    {
        $mod    = static fn (string $key, $rule): array => [ 'store' => 'mods', 'path' => [ $key ], 'rule' => $rule ];
        $layout = [ 'full-width', 'sidebar-left', 'sidebar-right' ];

        return [
            'family'   => 'hestia',
            'label'    => 'Hestia',
            'tier'     => 'free',
            'stores'   => [ 'mods' => [ 'type' => 'theme_mods' ] ],
            'settings' => [
                'accent_color'          => $mod('accent_color', [ 'rule' => 'color' ]),
                'header_gradient_color' => $mod('hestia_header_gradient_color', [ 'rule' => 'color' ]),
                'body_font'             => $mod('hestia_body_font', [ 'rule' => 'font' ]),
                'headings_font'         => $mod('hestia_headings_font', [ 'rule' => 'font' ]),
                'boxed_layout'          => $mod('hestia_general_layout', [ 'rule' => 'bool' ]),
                'page_sidebar_layout'   => $mod('hestia_page_sidebar_layout', $layout),
                'blog_sidebar_layout'   => $mod('hestia_blog_sidebar_layout', $layout),
                'scroll_to_top'         => $mod('hestia_enable_scroll_to_top', [ 'rule' => 'bool' ]),
            ],
        ];
    }
}
