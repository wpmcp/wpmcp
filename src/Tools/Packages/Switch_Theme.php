<?php

namespace WPMCP\Tools\Packages;

use WPMCP\Safety\Operation_Context;
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
            throw new \RuntimeException("Theme \"{$stylesheet}\" was not found.");
        }

        $operation_ids = self::snapshot_and_switch($stylesheet, 'switch-theme', $args);

        return ['operation_ids' => $operation_ids, 'stylesheet' => $stylesheet, 'switched' => true];
    }

    /**
     * Snapshot 'template' and 'stylesheet', then switch_theme(). Shared with
     * Install_Theme's activate:true step so there is exactly one copy of this
     * block, one Gate call site for the wp.org build to rewrite, and one
     * place that notes the operation ids for Request_Log (issue #134).
     *
     * @param  string $stylesheet The theme to switch to.
     * @param  string $tool_name  The tool label recorded on the snapshots.
     * @param  array  $args       The tool call's arguments, hashed onto the snapshots.
     * @return array<int, string> One operation id per snapshotted option.
     */
    public static function snapshot_and_switch(string $stylesheet, string $tool_name, array $args): array
    {
        $session_id = (string) ($args['session_id'] ?? 'default');
        $args_hash  = hash('sha256', wp_json_encode($args));

        $operation_ids = [];
        foreach (['template', 'stylesheet'] as $option_name) {
            $operation_id = wp_generate_uuid4();
            Snapshot_Store::save(
                $operation_id,
                $session_id,
                Snapshot::capture('option', $option_name),
                $tool_name,
                $args_hash
            );
            Operation_Context::note($operation_id);
            $operation_ids[] = $operation_id;
        }
        Snapshot_Store::prune(Gate::history_limit());

        switch_theme($stylesheet);

        return $operation_ids;
    }
}
