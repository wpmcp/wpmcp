<?php

namespace WPMCP\Tools\Packages;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Available core, plugin and theme updates plus auto-update state (issue
 * #389), served by list-plugins updates:true. Reads core's update
 * transients only and never triggers a wordpress.org check. Each section is
 * included only for a caller holding the matching update capability.
 */
class List_Updates
{
    public function handle(): array
    {
        if (! function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $out = [];

        if (current_user_can('update_core')) {
            $available = [];
            $offer     = Manage_Updates::current_offer();
            if (null !== $offer) {
                $available[] = (string) $offer->current;
            }
            $out['core'] = [
                'version'           => (string) get_bloginfo('version'),
                'available'         => $available,
                'db_upgrade_needed' => Manage_Updates::db_upgrade_needed(),
            ];
        }

        if (current_user_can('update_plugins')) {
            $installed = get_plugins();
            $auto      = (array) get_site_option('auto_update_plugins', []);
            $transient = get_site_transient('update_plugins');
            $rows      = [];
            foreach ((array) (is_object($transient) ? ($transient->response ?? []) : []) as $file => $data) {
                $rows[] = [
                    'plugin'      => (string) $file,
                    'version'     => $installed[ $file ]['Version'] ?? null,
                    'new_version' => is_object($data) ? ($data->new_version ?? null) : ($data['new_version'] ?? null),
                    'auto_update' => in_array($file, $auto, true),
                ];
            }
            $out['plugins'] = $rows;
        }

        if (current_user_can('update_themes')) {
            $auto      = (array) get_site_option('auto_update_themes', []);
            $transient = get_site_transient('update_themes');
            $rows      = [];
            foreach ((array) (is_object($transient) ? ($transient->response ?? []) : []) as $stylesheet => $data) {
                $theme  = wp_get_theme((string) $stylesheet);
                $rows[] = [
                    'theme'       => (string) $stylesheet,
                    'version'     => $theme->exists() ? $theme->get('Version') : null,
                    'new_version' => is_array($data) ? ($data['new_version'] ?? null) : ($data->new_version ?? null),
                    'auto_update' => in_array($stylesheet, $auto, true),
                ];
            }
            $out['themes'] = $rows;
        }

        return $out;
    }
}
