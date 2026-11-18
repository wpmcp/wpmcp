<?php

namespace WPMCP\Tools\Elementor;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Warn when an Elementor header or footer template may clash with the active
 * theme's own header and footer builder (issue #870).
 *
 * Divi (and Extra, which shares Divi's theme builder) override the header the
 * same way Elementor Pro does. With both overrides active on the same request,
 * Elementor Pro clears wp_head and Divi stores it back as a plain array, so the
 * next do_action('wp_head') fatals and every front-end page returns 500. This
 * is a clash between the two products, so the write still runs; the result
 * only carries a warning.
 */
final class Theme_Header_Builder_Clash
{
    /** Parent theme directory (lowercase) => the theme's display name. */
    private const THEMES = [
        'divi'  => 'Divi',
        'extra' => 'Extra',
    ];

    private const LOCATIONS = ['header', 'footer'];

    /** The active parent theme's name when it has its own header builder, else ''. */
    public static function theme_name(): string
    {
        $template = strtolower((string) get_template());
        return self::THEMES[$template] ?? '';
    }

    /**
     * Warnings for a header or footer template, [] when there is no clash.
     *
     * @param string $type         The template's theme-builder location.
     * @param bool   $site_wide    Whether its conditions cover the entire site.
     */
    public static function warnings(string $type, bool $site_wide): array
    {
        if (! in_array($type, self::LOCATIONS, true)) {
            return [];
        }
        $theme = self::theme_name();
        if ('' === $theme) {
            return [];
        }

        $scope = $site_wide
            ? 'Its conditions cover the entire site, so every front-end page is at risk.'
            : 'Once its conditions include a page, that page is at risk.';

        return [sprintf(
            'The active theme, %1$s, has its own header and footer builder. Its override and Elementor Pro\'s %2$s override clash on the same request and the front end may return HTTP 500. %3$s Narrow the conditions, check the front end right after, or use a theme without its own header builder.',
            $theme,
            $type,
            $scope
        )];
    }

    /** Whether normalized conditions include the entire site. */
    public static function is_site_wide(array $conditions): bool
    {
        return in_array('include/general', $conditions, true);
    }
}
