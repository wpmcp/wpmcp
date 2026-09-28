<?php

namespace WPMCP\Tools\Elementor;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Update an Elementor 4 global variable by e-gv- id: rename it, change its
 * value, or switch between size and custom-size (the only type change
 * Elementor allows). The value is validated against the resulting type.
 * Requires expected_hash from list-global-variables; snapshotted, so
 * rollback-operation restores the variables exactly.
 */
class Update_Global_Variable
{
    public function handle(array $args)
    {
        $id = sanitize_text_field((string) ($args['id'] ?? ''));
        if ('' === $id) {
            return new \WP_Error('missing_id', 'A global variable "id" is required.');
        }

        $has_label = isset($args['label']);
        $has_value = isset($args['value']);
        $has_type  = isset($args['type']);
        if (! $has_label && ! $has_value && ! $has_type) {
            return new \WP_Error('nothing_to_update', 'Provide a new "label", "value" and/or "type" to update.');
        }

        $state = Global_Variables_Store::guard($args);
        if (is_wp_error($state)) {
            return $state;
        }

        $current = $state['variables'][$id] ?? null;
        if (! is_array($current)) {
            return new \WP_Error('variable_not_found', sprintf('No global variable found with id "%s".', $id));
        }

        $current_type = Global_Variable_Schema::type($current['type'] ?? null);
        if (is_wp_error($current_type)) {
            return $current_type;
        }

        $type = $current_type;
        if ($has_type) {
            $type = Global_Variable_Schema::type($args['type']);
            if (is_wp_error($type)) {
                return $type;
            }
            $size_switch = Global_Variable_Schema::is_size($type) && Global_Variable_Schema::is_size($current_type);
            if ($type !== $current_type && ! $size_switch) {
                return new \WP_Error(
                    'type_change_forbidden',
                    sprintf('A %s variable cannot become %s; Elementor only allows switching between size and custom-size. Create a new variable instead.', $current_type, $type)
                );
            }
        }

        $changes = [];

        if ($has_label) {
            $label = Global_Variable_Schema::label($args['label']);
            if (is_wp_error($label)) {
                return $label;
            }
            $owner = Create_Global_Variable::label_owner($state['variables'], $label, $id);
            if (null !== $owner) {
                return Create_Global_Variable::duplicate($label, $owner);
            }
            $changes['label'] = $label;
        }

        // The value is always re-checked against the resulting type, so a
        // size -> custom-size switch cannot leave an invalid value behind.
        $value = Global_Variable_Schema::value($type, $has_value ? $args['value'] : (string) ($current['value'] ?? ''));
        if (is_wp_error($value)) {
            return $value;
        }
        $changes['value'] = $value;

        if ($type !== $current_type) {
            $changes['type'] = Global_Variable_Schema::TYPES[$type];
        }

        $expected = [
            'label' => $changes['label'] ?? (string) ($current['label'] ?? ''),
            'value' => $value,
            'type'  => Global_Variable_Schema::TYPES[$type],
        ];

        $out = Global_Variables_Store::write(
            $state,
            static fn ($service) => $service->update($id, $changes),
            static function (array $after) use ($id, $expected): bool {
                $stored = $after['variables'][$id] ?? null;

                return is_array($stored)
                    && $expected['label'] === ($stored['label'] ?? null)
                    && $expected['value'] === ($stored['value'] ?? null)
                    && $expected['type'] === ($stored['type'] ?? null);
            },
            'update-global-variable',
            $args
        );
        if (is_wp_error($out)) {
            return $out;
        }

        unset($out['result']);

        return $out + ['id' => $id, 'label' => $expected['label'], 'type' => $type, 'value' => $value];
    }
}
