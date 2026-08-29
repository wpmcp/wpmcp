<?php

namespace WPMCP\Tools\Packages;

use WPMCP\Safety\Safe_Mutation;
use WPMCP\Safety\Snapshot;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Pro\Gate;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Activate (switch to) an installed theme.
 *
 * The actual switch is delegated to WordPress core's own switch_theme(), not
 * reimplemented here: core also fires 'switch_theme'/'after_switch_theme',
 * migrates theme mods, and updates 'current_theme', side effects themes and
 * plugins (including Elementor) may depend on. Calling core directly means
 * those all still fire correctly.
 *
 * switch_theme() itself changes both the 'template' and 'stylesheet' options
 * (a child theme's stylesheet differs from its parent's template). Both are
 * snapshotted individually, through the same Snapshot/Snapshot_Store
 * machinery Safe_Mutation::run() uses, BEFORE switch_theme() runs, so
 * rollback-operation can restore either option afterward to undo the switch.
 * Safe_Mutation::run() isn't used directly here because it expects one
 * mutation callback per snapshot; switch_theme() is a single call that
 * changes both options at once, so the two snapshots are taken up front and
 * recorded individually instead.
 */
class Switch_Theme
{
    public function handle(array $args): array
    {
        $stylesheet = isset($args['stylesheet']) ? (string) $args['stylesheet'] : '';
        if ('' === $stylesheet) {
            throw new \InvalidArgumentException('A stylesheet (theme slug) is required.');
        }

        $theme = wp_get_theme($stylesheet);
        if (! $theme->exists()) {
            throw new \RuntimeException('Theme "' . esc_html($stylesheet) . '" was not found.');
        }

        $session_id = (string) ($args['session_id'] ?? 'default');
        $args_hash  = hash('sha256', wp_json_encode($args));

        $operation_ids = [];
        foreach (['template', 'stylesheet'] as $option_name) {
            $operation_id = wp_generate_uuid4();
            Snapshot_Store::save(
                $operation_id,
                $session_id,
                Snapshot::capture('option', $option_name),
                'switch-theme',
                $args_hash
            );
            $operation_ids[] = $operation_id;
        }
        Snapshot_Store::prune(Gate::history_limit());

        switch_theme($stylesheet);

        return ['operation_ids' => $operation_ids, 'stylesheet' => $stylesheet, 'switched' => true];
    }
}
