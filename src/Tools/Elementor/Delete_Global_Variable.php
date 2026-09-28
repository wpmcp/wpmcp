<?php

namespace WPMCP\Tools\Elementor;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Delete an Elementor 4 global variable, with the blast radius reported first.
 *
 * Without confirm:true this is a dry run returning every post and global class
 * that references the variable. With confirm:true it is deleted through
 * Elementor's service (a soft delete: elements that used it fall back to an
 * unresolved CSS variable), after the variables record is snapshotted, so
 * rollback-operation brings it back exactly.
 */
class Delete_Global_Variable
{
    public function handle(array $args)
    {
        $id = sanitize_text_field((string) ($args['id'] ?? ''));
        if ('' === $id) {
            return new \WP_Error('missing_id', 'A global variable "id" is required.');
        }

        $state = Global_Variables_Store::guard($args);
        if (is_wp_error($state)) {
            return $state;
        }

        $current = $state['variables'][$id] ?? null;
        if (! is_array($current)) {
            return new \WP_Error('variable_not_found', sprintf('No global variable found with id "%s".', $id));
        }

        $label = (string) ($current['label'] ?? '');
        $usage = Global_Variable_Usage::scan($id);

        if (true !== ($args['confirm'] ?? null)) {
            return [
                'deleted'          => false,
                'confirm_required' => true,
                'id'               => $id,
                'label'            => $label,
                'usage'            => $usage,
                'message'          => sprintf(
                    'Nothing was deleted. "%s" is used by %d post(s); pass confirm:true to delete it (snapshotted, so rollback-operation brings it back).',
                    $label,
                    $usage['total']
                ),
            ];
        }

        $out = Global_Variables_Store::write(
            $state,
            static fn ($service) => $service->delete($id),
            static fn (array $after): bool => ! isset($after['variables'][$id]),
            'delete-global-variable',
            $args
        );
        if (is_wp_error($out)) {
            return $out;
        }

        unset($out['result']);

        return $out + ['deleted' => true, 'id' => $id, 'label' => $label, 'usage' => $usage];
    }
}
