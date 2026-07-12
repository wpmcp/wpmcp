<?php

namespace WPMCP\Tools\Packages;

use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Deactivate a plugin.
 *
 * This only ever changes the 'active_plugins' option, so it is routed
 * through Safe_Mutation with object_type 'option': the prior active_plugins
 * list is snapshotted before the write, and rollback-operation can restore
 * it exactly (undoing the deactivation) with a single call.
 *
 * Protected packages (wpmcp itself, Elementor free/pro) refuse outright:
 * see Package_Guard.
 */
class Deactivate_Plugin
{
    public function handle(array $args): array
    {
        $plugin = isset($args['plugin']) ? (string) $args['plugin'] : '';
        if ('' === $plugin) {
            throw new \InvalidArgumentException('A plugin file is required.');
        }

        if (Package_Guard::is_protected_plugin($plugin)) {
            throw new \RuntimeException("Refusing to deactivate protected plugin \"{$plugin}\".");
        }

        if (! function_exists('deactivate_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $out = Safe_Mutation::run(
            [
                'object_type' => 'option',
                'object_id'   => 'active_plugins',
                'session_id'  => (string) ($args['session_id'] ?? 'default'),
                'tool_name'   => 'deactivate-plugin',
                'args'        => $args,
            ],
            function () use ($plugin): void {
                deactivate_plugins([$plugin]);
            }
        );

        return ['operation_id' => $out['operation_id'], 'plugin' => $plugin, 'deactivated' => true];
    }
}
