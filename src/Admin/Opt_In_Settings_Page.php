<?php

namespace WPMCP\Admin;

use WPMCP\Governance\Governance_Audit_Log;
use WPMCP\Governance\Opt_In_Settings;
use WPMCP\Plugin;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The opt-in operations screen (issue #823 part 4): one row per group of
 * operations that is off by default behind an opt-in filter, with a toggle
 * that switches the group on without code.
 *
 * Same request pattern as Ability_Grid_Page: the form posts back to the
 * screen, handle_request() checks capability and nonce, and every change is
 * recorded in the governance audit log with the acting user. Writing a
 * toggle takes manage_options on the site screen and
 * manage_network_options on the network screen (multisite only); viewing
 * either takes the management capability like the other WP MCP screens.
 *
 * A group whose filter is set in code shows "set in code" and has no
 * working toggle: the code decides (Opt_In_Settings::apply() stands down),
 * and the screen does not pretend otherwise.
 */
class Opt_In_Settings_Page
{
    public const SLUG         = 'wpmcp-opt-in';
    public const NONCE_ACTION = 'wpmcp_opt_in_settings';

    /** network_admin_menu: the network-wide screen under Network Settings. */
    public static function register_network_menu(): void
    {
        if ([] === Opt_In_Settings::groups()) {
            return;
        }
        add_submenu_page(
            'settings.php',
            Plugin::page_title(__('Opt-in operations', 'wpmcp')),
            __('WP MCP opt-in operations', 'wpmcp'),
            'manage_network_options',
            self::SLUG,
            [ new self(), 'render_network' ]
        );
    }

    /**
     * Dispatch a posted toggle. Null when nothing was posted, otherwise a
     * result for the screen; failures come back as ['error' => message].
     *
     * @param array  $post  The (unslashed) POST payload.
     * @param string $scope 'site' or 'network'.
     */
    public function handle_request(array $post, string $scope = 'site'): ?array
    {
        if ('toggle' !== self::str($post['wpmcp_opt_in_action'] ?? '')) {
            return null;
        }

        $network = 'network' === $scope;
        if ($network && ! is_multisite()) {
            return [ 'error' => __('Network-wide toggles exist only on a multisite network.', 'wpmcp') ];
        }
        $allowed = $network
            ? current_user_can('manage_network_options')
            : current_user_can('manage_options') && current_user_can(Management_Access::CAPABILITY);
        if (! $allowed) {
            return [ 'error' => __('You are not allowed to change opt-in operations here.', 'wpmcp') ];
        }

        if (! wp_verify_nonce(self::str($post['_wpnonce'] ?? ''), self::NONCE_ACTION)) {
            return [ 'error' => __('Security check failed. Reload the page and try again.', 'wpmcp') ];
        }

        $filter  = sanitize_key(self::str($post['filter'] ?? ''));
        $enabled = '1' === self::str($post['enabled'] ?? '');
        if (! isset(Opt_In_Settings::groups()[ $filter ])) {
            return [ 'error' => __('Unknown opt-in group.', 'wpmcp') ];
        }
        if (Opt_In_Settings::set_in_code($filter)) {
            return [
                'error' => sprintf(
                    /* translators: %s: filter name. */
                    __('%s is set in code on this site, so the code decides and this toggle cannot change it.', 'wpmcp'),
                    $filter
                ),
            ];
        }

        Opt_In_Settings::set($filter, $enabled, $network ? 'network' : 'site');

        $user  = wp_get_current_user();
        $actor = $user instanceof \WP_User && $user->exists() ? $user->user_login : 'unknown';
        try {
            Governance_Audit_Log::record('opt-in:' . $filter, 'user:' . $actor, $enabled, $network ? 'network' : 'site');
        } catch (\Throwable $e) {
            unset($e);
        }

        return [ 'action' => 'toggle', 'filter' => $filter, 'enabled' => $enabled ];
    }

    /**
     * The screen model: filter => row.
     *
     * @return array<string, array{filter: string, label: string, destructive: bool, warning: string, set_in_code: bool, on: bool, on_network: bool}>
     */
    public function rows(string $scope = 'site'): array
    {
        $labels = Opt_In_Settings::labels();
        $rows   = [];
        foreach (Opt_In_Settings::groups() as $filter => $group) {
            $rows[ $filter ] = [
                'filter'      => $filter,
                'label'       => $labels[ $filter ] ?? $filter,
                'destructive' => $group['destructive'],
                'warning'     => $group['destructive']
                    ? __('Includes operations that delete data or that undo cannot fully reverse (mail sent, external calls).', 'wpmcp')
                    : '',
                'set_in_code' => Opt_In_Settings::set_in_code($filter),
                'on'          => Opt_In_Settings::stored_on($filter, 'network' === $scope ? 'network' : 'site'),
                'on_network'  => is_multisite() && Opt_In_Settings::stored_on($filter, 'network'),
            ];
        }
        return $rows;
    }

    public function render(): void
    {
        $this->render_scope('site');
    }

    public function render_network(): void
    {
        $this->render_scope('network');
    }

    private function render_scope(string $scope): void
    {
        // phpcs:ignore -- nonce + capability are verified inside handle_request().
        $result  = $this->handle_request(wp_unslash($_POST), $scope);
        $nonce   = wp_create_nonce(self::NONCE_ACTION);
        $network = 'network' === $scope;
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(Plugin::page_title(__('Opt-in operations', 'wpmcp'))); ?></h1>
            <p>
                <?php
                echo esc_html(
                    $network
                        ? __('These operations are off by default. Switching a group on here enables it on every site of the network. A filter set in code always wins. Every change is recorded in the governance audit log.', 'wpmcp')
                        : __('These operations are off by default. Switching a group on lets connected agents run it on this site, subject to their usual permissions. A filter set in code always wins. Every change is recorded in the governance audit log.', 'wpmcp')
                );
                ?>
            </p>

            <?php if (isset($result['error'])) : ?>
                <div class="notice notice-error"><p><?php echo esc_html($result['error']); ?></p></div>
            <?php elseif (isset($result['action'])) : ?>
                <div class="notice notice-success"><p>
                    <?php
                    printf(
                        /* translators: 1: filter name, 2: on/off. */
                        esc_html__('%1$s is now %2$s.', 'wpmcp'),
                        '<code>' . esc_html($result['filter']) . '</code>',
                        $result['enabled'] ? esc_html__('on', 'wpmcp') : esc_html__('off', 'wpmcp')
                    );
                    ?>
                </p></div>
            <?php endif; ?>

            <table class="widefat striped">
                <thead><tr>
                    <th><?php echo esc_html__('Group', 'wpmcp'); ?></th>
                    <th><?php echo esc_html__('Filter', 'wpmcp'); ?></th>
                    <th><?php echo esc_html__('State', 'wpmcp'); ?></th>
                    <th></th>
                </tr></thead>
                <tbody>
                <?php foreach ($this->rows($scope) as $row) : ?>
                    <tr>
                        <td>
                            <?php echo esc_html($row['label']); ?>
                            <?php if ('' !== $row['warning']) : ?>
                                <br><span class="description" style="color: #b32d2e;">
                                    <span class="dashicons dashicons-warning" aria-hidden="true"></span>
                                    <?php echo esc_html($row['warning']); ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td><code><?php echo esc_html($row['filter']); ?></code></td>
                        <td>
                            <?php if ($row['set_in_code']) : ?>
                                <strong><?php echo esc_html__('set in code', 'wpmcp'); ?></strong>
                            <?php elseif ($row['on']) : ?>
                                <strong style="color: #00753d;"><?php echo esc_html__('on', 'wpmcp'); ?></strong>
                            <?php elseif (! $network && $row['on_network']) : ?>
                                <strong style="color: #00753d;"><?php echo esc_html__('on for the network', 'wpmcp'); ?></strong>
                            <?php else : ?>
                                <?php echo esc_html__('off', 'wpmcp'); ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <form method="post">
                                <input type="hidden" name="wpmcp_opt_in_action" value="toggle">
                                <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>">
                                <input type="hidden" name="filter" value="<?php echo esc_attr($row['filter']); ?>">
                                <?php if ($row['set_in_code']) : ?>
                                    <button type="button" class="button button-small" disabled
                                        title="<?php echo esc_attr__('Set in code: remove the filter to manage it here.', 'wpmcp'); ?>">
                                        <?php echo esc_html__('Set in code', 'wpmcp'); ?>
                                    </button>
                                <?php elseif ($row['on']) : ?>
                                    <button type="submit" name="enabled" value="0" class="button button-small">
                                        <?php echo esc_html__('Switch off', 'wpmcp'); ?>
                                    </button>
                                <?php else : ?>
                                    <button type="submit" name="enabled" value="1" class="button button-small button-primary">
                                        <?php echo esc_html__('Switch on', 'wpmcp'); ?>
                                    </button>
                                <?php endif; ?>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Request values may arrive as arrays; anything that is not a plain
     * string is treated as absent.
     *
     * @param mixed $value
     */
    private static function str($value): string
    {
        return is_string($value) ? $value : '';
    }
}
