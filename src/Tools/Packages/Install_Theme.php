<?php

namespace WPMCP\Tools\Packages;

use WPMCP\Safety\Snapshot;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Pro\Gate;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Install a theme from wordpress.org by slug.
 *
 * Safe_Mutation exemption applies to the install step only: unpacking a new
 * theme directory adds files and changes no prior state, the same reasoning
 * Install_Plugin uses.
 *
 * The optional activate:true step is NOT exempt. The theme being switched TO
 * is new, but the theme being switched FROM is not: switch_theme() overwrites
 * the 'template' and 'stylesheet' options, and those prior values are real
 * state. Both are snapshotted before the switch, through the same
 * Snapshot/Snapshot_Store machinery Switch_Theme uses and for the same reason
 * (one core call changes two options, so the snapshots are taken up front and
 * recorded individually rather than through Safe_Mutation::run).
 *
 * That step also does the work the wp-admin Themes screen gates on
 * switch_themes, while the ability itself is registered under install_themes.
 * The extra capability is therefore checked explicitly, and before the
 * download runs, so a partial request never leaves a theme on disk it was not
 * allowed to activate.
 *
 * wordpress.org-only: the slug must be a bare theme directory slug (letters,
 * digits, dashes, underscores), never a URL or filesystem path. themes_api()
 * is WordPress core's own client for the wordpress.org theme repository API,
 * so passing it a slug never reaches outside that repository.
 */
class Install_Theme
{
    private const SLUG_PATTERN = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

    public function handle(array $args): array
    {
        $slug = isset($args['slug']) ? (string) $args['slug'] : '';
        if ('' === $slug) {
            throw new \InvalidArgumentException('A theme slug is required.');
        }
        if (! preg_match(self::SLUG_PATTERN, $slug)) {
            throw new \InvalidArgumentException('Invalid theme slug: only wordpress.org-style slugs (letters, digits, dashes) are allowed.');
        }

        $activate = ! empty($args['activate']);
        if ($activate) {
            self::require_switch_capability();
        }

        if (! Package_Guard::filesystem_ready()) {
            throw new \RuntimeException('Direct filesystem access is required to install themes.');
        }

        if (! function_exists('themes_api')) {
            require_once ABSPATH . 'wp-admin/includes/theme.php';
        }
        if (! class_exists('Theme_Upgrader')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        }

        $info = themes_api('theme_information', ['slug' => $slug, 'fields' => ['sections' => false]]);
        if (is_wp_error($info)) {
            throw new \RuntimeException('Could not find theme "' . $slug . '" on wordpress.org: ' . $info->get_error_message());
        }

        $upgrader = new \Theme_Upgrader(new \Automatic_Upgrader_Skin());
        $result   = $upgrader->install($info->download_link);
        if (is_wp_error($result) || ! $result) {
            $message = is_wp_error($result) ? $result->get_error_message() : 'unknown error';
            throw new \RuntimeException('Theme install failed: ' . $message);
        }

        $out = ['installed' => true, 'slug' => $slug, 'activated' => false];
        if ($activate) {
            $out = array_merge($out, $this->activate_installed($slug, $args));
        }

        return $out;
    }

    /**
     * The activate:true step, snapshotted. Public so the guarded path is
     * directly testable without a network install standing in front of it.
     *
     * @return array{activated: bool, operation_ids: array<int, string>}
     */
    public function activate_installed(string $slug, array $args): array
    {
        self::require_switch_capability();

        $session_id = (string) ($args['session_id'] ?? 'default');
        $args_hash  = hash('sha256', wp_json_encode($args));

        $operation_ids = [];
        foreach (['template', 'stylesheet'] as $option_name) {
            $operation_id = wp_generate_uuid4();
            Snapshot_Store::save(
                $operation_id,
                $session_id,
                Snapshot::capture('option', $option_name),
                'install-theme',
                $args_hash
            );
            $operation_ids[] = $operation_id;
        }
        Snapshot_Store::prune(Gate::history_limit());

        switch_theme($slug);

        return [
            'activated'     => get_stylesheet() === $slug,
            'operation_ids' => $operation_ids,
        ];
    }

    private static function require_switch_capability(): void
    {
        if (! current_user_can('switch_themes')) {
            throw new \RuntimeException('Activating an installed theme requires the switch_themes capability; install-theme was called with activate: true without it.');
        }
    }
}
