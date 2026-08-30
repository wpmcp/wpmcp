<?php

namespace WPMCP\Tools\Packages;

use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Install a plugin from wordpress.org by slug.
 *
 * Safe_Mutation exemption applies to the install step only: unpacking a new
 * plugin directory adds files and changes no prior state, the same reasoning
 * Create_User and create-post use to skip Safe_Mutation.
 *
 * The optional activate:true step is NOT exempt. It rewrites the
 * 'active_plugins' option, whose prior value is real state a caller may want
 * back, so it runs through Safe_Mutation exactly as Activate_Plugin does and
 * returns an operation_id that rollback-operation accepts.
 *
 * That step also does the work the wp-admin Plugins screen gates on
 * activate_plugins, while the ability itself is registered under
 * install_plugins. The extra capability is therefore checked explicitly, and
 * before the download runs, so the two capabilities a reviewer would expect
 * are both enforced and a partial request never leaves a plugin on disk it
 * was not allowed to activate.
 *
 * wordpress.org-only: the slug must be a bare plugin directory slug (letters,
 * digits, dashes, underscores), never a URL or filesystem path, so this tool
 * can never be turned into an arbitrary-zip-URL installer. plugins_api() is
 * WordPress core's own client for the wordpress.org plugin repository API,
 * so passing it a slug never reaches outside that repository.
 */
class Install_Plugin
{
    private const SLUG_PATTERN = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

    public function handle(array $args): array
    {
        $slug = isset($args['slug']) ? (string) $args['slug'] : '';
        if ('' === $slug) {
            throw new \InvalidArgumentException('A plugin slug is required.');
        }
        if (! preg_match(self::SLUG_PATTERN, $slug)) {
            throw new \InvalidArgumentException('Invalid plugin slug: only wordpress.org-style slugs (letters, digits, dashes) are allowed.');
        }

        $activate = ! empty($args['activate']);
        if ($activate) {
            self::require_activate_capability();
        }

        if (! Package_Guard::filesystem_ready()) {
            throw new \RuntimeException('Direct filesystem access is required to install plugins.');
        }

        if (! function_exists('plugins_api')) {
            require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        }
        if (! class_exists('Plugin_Upgrader')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        }

        $info = plugins_api('plugin_information', ['slug' => $slug, 'fields' => ['sections' => false]]);
        if (is_wp_error($info)) {
            throw new \RuntimeException('Could not find plugin "' . $slug . '" on wordpress.org: ' . $info->get_error_message());
        }

        $upgrader = new \Plugin_Upgrader(new \Automatic_Upgrader_Skin());
        $result   = $upgrader->install($info->download_link);
        if (is_wp_error($result) || ! $result) {
            $message = is_wp_error($result) ? $result->get_error_message() : 'unknown error';
            throw new \RuntimeException('Plugin install failed: ' . $message);
        }

        $plugin_file = $upgrader->plugin_info();
        if (! $plugin_file) {
            throw new \RuntimeException('Plugin installed but its main file could not be determined.');
        }

        $out = ['installed' => true, 'file' => $plugin_file, 'slug' => $slug, 'activated' => false];
        if ($activate) {
            $out = array_merge($out, $this->activate_installed($plugin_file, $args));
        }

        return $out;
    }

    /**
     * The activate:true step, snapshotted. Public so the guarded path is
     * directly testable without a network install standing in front of it.
     *
     * A failed activation is reported as activated:false rather than thrown:
     * the install itself did succeed and the caller needs to hear that. No
     * verify callback is passed to Safe_Mutation for the same reason.
     *
     * @return array{activated: bool, operation_id: string}
     */
    public function activate_installed(string $plugin_file, array $args): array
    {
        self::require_activate_capability();

        $out = Safe_Mutation::run(
            [
                'object_type' => 'option',
                'object_id'   => 'active_plugins',
                'session_id'  => (string) ($args['session_id'] ?? 'default'),
                'tool_name'   => 'install-plugin',
                'args'        => $args,
            ],
            function () use ($plugin_file) {
                return activate_plugin($plugin_file);
            }
        );

        return [
            'activated'    => ! is_wp_error($out['result']),
            'operation_id' => $out['operation_id'],
        ];
    }

    private static function require_activate_capability(): void
    {
        if (! current_user_can('activate_plugins')) {
            throw new \RuntimeException('Activating an installed plugin requires the activate_plugins capability; install-plugin was called with activate: true without it.');
        }
    }
}
