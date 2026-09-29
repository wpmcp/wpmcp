<?php

namespace WPMCP\Tools\Packages;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only listing of installed plugins. Direct read, no snapshot: nothing
 * is mutated here. updates:true switches to the update listing (core,
 * plugins and themes, see List_Updates) instead.
 */
class List_Plugins
{
    public function handle(array $args): array
    {
        if (true === ($args['updates'] ?? null)) {
            return (new List_Updates())->handle();
        }

        if (! function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins    = get_plugins();
        $active_plugins = (array) get_option('active_plugins', []);
        $update_plugins = get_site_transient('update_plugins');
        $updates        = is_object($update_plugins) ? (array) ($update_plugins->response ?? []) : [];

        $rows = [];
        foreach ($all_plugins as $file => $data) {
            $has_update = isset($updates[ $file ]);
            $rows[]     = [
                'file'             => $file,
                'name'             => $data['Name'] ?? '',
                'version'          => $data['Version'] ?? '',
                'author'           => $data['Author'] ?? '',
                'active'           => in_array($file, $active_plugins, true),
                'is_protected'     => Package_Guard::is_protected_plugin($file),
                'update_available' => $has_update,
                'new_version'      => $has_update ? ($updates[ $file ]->new_version ?? null) : null,
            ];
        }

        return ['plugins' => $rows];
    }
}
