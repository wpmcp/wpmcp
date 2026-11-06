<?php

namespace WPMCP\Tools\Context;

use WPMCP\Pro\Gate;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only orientation payload for an agent connecting to this site: site
 * identity, WordPress/PHP versions, active theme, active plugin summary,
 * public post types and taxonomies (with counts), user count, locale,
 * timezone, multisite status, integration capabilities, and our own plugin
 * version/Pro status.
 *
 * Deliberately excludes the admin email and any other secret-shaped value:
 * this tool is meant to be safe at a low capability bar (edit_posts), so
 * nothing it returns should require a stronger gate to see.
 */
class Get_Site_Context
{
    public function handle(array $args): array
    {
        $theme          = wp_get_theme();
        // deactivate_plugins() unsets entries without renumbering, so the
        // option can hold keys like {"1": ...}; reindexed so active_slugs is
        // always a JSON list (issue #722).
        $active_plugins = array_values((array) get_option('active_plugins', []));

        $post_types = [];
        foreach (get_post_types(['public' => true], 'objects') as $name => $object) {
            $post_types[] = [
                'name'  => (string) $name,
                'label' => (string) ($object->label ?? $name),
                'count' => (int) wp_count_posts($name)->publish,
            ];
        }

        $taxonomies = [];
        foreach (get_taxonomies(['public' => true], 'objects') as $name => $object) {
            $taxonomies[] = [
                'name'  => (string) $name,
                'label' => (string) ($object->label ?? $name),
            ];
        }

        return [
            'site' => [
                'name'    => get_bloginfo('name'),
                'url'     => home_url(),
                'tagline' => get_bloginfo('description'),
            ],
            'wordpress_version' => get_bloginfo('version'),
            'php_version'       => PHP_VERSION,
            'theme'             => [
                'name'     => $theme->get('Name'),
                'version'  => $theme->get('Version'),
                'is_child' => (bool) $theme->parent(),
            ],
            'plugins' => [
                'active_count' => count($active_plugins),
                'active_slugs' => $active_plugins,
            ],
            'post_types'   => $post_types,
            'taxonomies'   => $taxonomies,
            'user_count'   => (int) count_users()['total_users'],
            'locale'       => get_locale(),
            'timezone'     => get_option('timezone_string'),
            'is_multisite' => is_multisite(),
            'capabilities' => $this->capabilities(),
            'wpmcp'        => [
                'version'    => defined('WPMCP_VERSION') ? WPMCP_VERSION : '',
                'pro_active' => Gate::is_pro(),
            ],
        ];
    }

    /**
     * Which third-party integrations are active. Detection idioms mirror the
     * ones already used elsewhere in the plugin (Elementor_Page_Data,
     * SEO_Adapter) so this stays consistent with how the rest of the code
     * checks for these plugins.
     */
    private function capabilities(): array
    {
        return [
            'elementor'   => class_exists('\\Elementor\\Plugin'),
            'woocommerce' => class_exists('WooCommerce') && function_exists('WC'),
            'acf'         => function_exists('get_field') || class_exists('ACF'),
            'yoast'       => defined('WPSEO_VERSION') || class_exists('WPSEO_Options'),
            'rankmath'    => class_exists('RankMath'),
        ];
    }
}
