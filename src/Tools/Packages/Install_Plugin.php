<?php

namespace WPMCP\Tools\Packages;

use WPMCP\Safety\Mutation_Failed;
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
 * The reply always says what state the site is in (issue #728): an install
 * whose activation failed, or was skipped because too little of the PHP time
 * limit was left after the download, reports installed: true,
 * activated: false and the activate-plugin call that finishes the job, and
 * leaves no activation row in the ledger.
 *
 * On a multisite network the activation follows activate-plugin's rules
 * (Plugin_Network_Scope): it stays on the site unless a network admin passes
 * network_wide: true, and a network write snapshots active_sitewide_plugins.
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

    /**
     * Seconds of the PHP time limit the activate step needs left after the
     * install. With less, the request could die inside the activation, so
     * the step is skipped and the reply says how to finish it.
     */
    public const ACTIVATION_TIME_NEEDED = 20;

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
            Plugin_Network_Scope::requested($args);
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
            throw new \RuntimeException('Could not find plugin "' . esc_html($slug) . '" on wordpress.org: ' . esc_html($info->get_error_message()));
        }

        // The Ajax skin is core's quiet skin that also keeps the errors: a
        // failed download leaves install() returning null, with the reason
        // only on the skin. Messages are HTML; the client gets plain text
        // (issue #746).
        $skin     = new \WP_Ajax_Upgrader_Skin();
        $upgrader = new \Plugin_Upgrader($skin);
        $level    = ob_get_level();
        $result   = $upgrader->install($info->download_link);
        // On an error the quiet skins open their header buffer a second
        // time and close it once; drop what is left so the reply is clean.
        while (ob_get_level() > $level) {
            ob_end_clean();
        }
        if (is_wp_error($result) || ! $result) {
            $message = is_wp_error($result) ? $result->get_error_message() : (string) $skin->get_error_messages();
            $message = Activation_Guard::plain_message($message);
            throw new \RuntimeException('Plugin install failed: ' . esc_html('' !== $message ? $message : 'unknown error'));
        }

        $plugin_file = $upgrader->plugin_info();
        if (! $plugin_file) {
            throw new \RuntimeException('Plugin installed but its main file could not be determined.');
        }

        $out = ['installed' => true, 'file' => $plugin_file, 'slug' => $slug, 'activated' => false];
        if ($activate) {
            $out = array_merge($out, self::time_left_for_activation()
                ? $this->activate_installed($plugin_file, $args)
                : [
                    'activation_skipped' => true,
                    'error'              => 'The install used most of the PHP time limit, so the activation did not run.',
                ]);
            if (empty($out['activated']) && empty($out['already_active'])) {
                $out['next_step'] = sprintf('Installed, not activated: call activate-plugin with plugin "%s".', $plugin_file);
            }
        }

        return $out;
    }

    /**
     * The activate:true step, snapshotted and verified. Public so the guarded
     * path is directly testable without a network install standing in front
     * of it.
     *
     * The mutation runs through Safe_Mutation with the same verify callback
     * Activate_Plugin uses. That matters for the case core handles badly:
     * activate_plugin() writes 'active_plugins' BEFORE it returns
     * WP_Error('unexpected_output') for a plugin that echoes on activation, so
     * without the verify step the plugin would stay active while the response
     * said otherwise. With it, the snapshot is restored and the response says
     * activated:false plus rolled_back:true.
     *
     * A failed activation is reported rather than thrown: the install itself
     * did succeed and the caller needs to hear that. No operation_id is
     * returned on that branch, since the prior state is already back in
     * place and there is nothing left for rollback-operation to undo, and
     * the voided undo point leaves no ledger row either (issue #728).
     *
     * @return array{activated: bool, already_active?: bool, operation_id?: string, rolled_back?: bool, error?: string}
     */
    public function activate_installed(string $plugin_file, array $args): array
    {
        self::require_activate_capability();
        if (! function_exists('is_network_only_plugin')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        try {
            $network_wide = Plugin_Network_Scope::for_activation($plugin_file, $args);
        } catch (\RuntimeException $e) {
            throw new \RuntimeException(sprintf('Plugin "%s" was installed but not activated: %s', esc_html($plugin_file), esc_html($e->getMessage())));
        }

        $error      = '';
        $redirected = false;
        try {
            $out = Plugin_State::locked(static function () use ($plugin_file, $network_wide, $args, &$error, &$redirected) {
                if (Plugin_State::already_active($plugin_file, $network_wide)) {
                    return null;
                }
                return Safe_Mutation::run(
                    [
                        'object_type'         => 'option',
                        'object_id'           => 'active_plugins',
                        'object_ref'          => $plugin_file,
                        'state_probe'         => [ Plugin_State::class, 'probe' ],
                        'session_id'          => (string) ($args['session_id'] ?? 'default'),
                        'tool_name'           => 'install-plugin',
                        'args'                => $args,
                        'extra_snapshot_data' => [ 'restore_capability' => 'activate_plugins' ] + Plugin_Network_Scope::snapshot_data($network_wide),
                    ],
                    function () use ($plugin_file, $network_wide, &$error, &$redirected) {
                        $result = Activation_Guard::activate($plugin_file, $network_wide, $redirected);
                        if (is_wp_error($result)) {
                            $error = Activation_Guard::plain_message($result->get_error_message());
                        } elseif ($redirected) {
                            $error = Activation_Guard::redirect_message();
                        }
                        return $result;
                    },
                    function ($result) use ($plugin_file, $network_wide) {
                        return ! is_wp_error($result) && Plugin_State::is_active($plugin_file, $network_wide);
                    }
                );
            });
        } catch (Mutation_Failed $e) {
            return [
                'activated'   => false,
                'rolled_back' => true,
                'error'       => '' !== $error ? $error : $e->getMessage(),
            ];
        } catch (\RuntimeException $e) {
            // The lock was busy, or the activation threw: the install stands
            // and the reply says whether the plugin ended up active.
            return [
                'activated' => Plugin_State::is_active($plugin_file, $network_wide),
                'error'     => $e->getMessage(),
            ];
        }

        if (null === $out) {
            // Already active (issue #747): nothing changed, nothing recorded.
            return [ 'activated' => false, 'already_active' => true ];
        }

        return [
            'activated'    => true,
            'operation_id' => $out['operation_id'],
        ];
    }

    /**
     * True unless a PHP time limit is set and less than
     * ACTIVATION_TIME_NEEDED seconds of it remain. Filterable through
     * wpmcp_install_plugin_time_left (seconds left, or null for no limit),
     * which is also the test seam.
     */
    public static function time_left_for_activation(): bool
    {
        $limit = (int) ini_get('max_execution_time');
        $left  = null;
        if ($limit > 0) {
            $started = isset($_SERVER['REQUEST_TIME_FLOAT']) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime(true);
            $left    = $limit - (microtime(true) - $started);
        }
        $left = apply_filters('wpmcp_install_plugin_time_left', $left);

        return null === $left || (float) $left >= self::ACTIVATION_TIME_NEEDED;
    }

    private static function require_activate_capability(): void
    {
        if (! current_user_can('activate_plugins')) {
            throw new \RuntimeException('Activating an installed plugin requires the activate_plugins capability; install-plugin was called with activate: true without it.');
        }
    }
}
