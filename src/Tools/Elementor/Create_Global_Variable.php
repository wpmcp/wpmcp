<?php

namespace WPMCP\Tools\Elementor;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Create an Elementor 4 global variable (color, font, size or custom-size
 * design token) through Elementor's own Variables_Service, returning the new
 * e-gv- id. Type, label and value are validated first and an unknown type is
 * refused. Requires expected_hash from list-global-variables; snapshotted, so
 * rollback-operation removes it again.
 */
class Create_Global_Variable
{
    public function handle(array $args)
    {
        $type = Global_Variable_Schema::type($args['type'] ?? null);
        if (is_wp_error($type)) {
            return $type;
        }

        $label = Global_Variable_Schema::label($args['label'] ?? null);
        if (is_wp_error($label)) {
            return $label;
        }

        $value = Global_Variable_Schema::value($type, $args['value'] ?? null);
        if (is_wp_error($value)) {
            return $value;
        }

        $state = Global_Variables_Store::guard($args);
        if (is_wp_error($state)) {
            return $state;
        }

        if (Global_Variable_Schema::is_size($type) && ! Global_Variables_Store::sizes_supported()) {
            return new \WP_Error(
                'requires_elementor_pro',
                'Size variables need Elementor Pro; without it Elementor hides them from the editor.'
            );
        }

        $taken = self::label_owner($state['variables'], $label);
        if (null !== $taken) {
            return self::duplicate($label, $taken);
        }

        $elementor_type = Global_Variable_Schema::TYPES[$type];

        $out = Global_Variables_Store::write(
            $state,
            static fn ($service) => $service->create([
                'type'  => $elementor_type,
                'label' => $label,
                'value' => $value,
            ]),
            static function (array $after, array $result) use ($label, $value, $elementor_type): bool {
                $id     = (string) ($result['variable']['id'] ?? '');
                $stored = $after['variables'][$id] ?? null;

                return is_array($stored)
                    && $label === ($stored['label'] ?? null)
                    && $value === ($stored['value'] ?? null)
                    && $elementor_type === ($stored['type'] ?? null);
            },
            'create-global-variable',
            $args
        );
        if (is_wp_error($out)) {
            return $out;
        }

        $id = (string) $out['result']['variable']['id'];
        unset($out['result']);

        return $out + ['id' => $id, 'label' => $label, 'type' => $type, 'value' => $value];
    }

    /** Id of the active variable already using $label (case-insensitive, as Elementor compares). */
    public static function label_owner(array $variables, string $label, string $ignore_id = ''): ?string
    {
        foreach ($variables as $id => $variable) {
            if ((string) $id !== $ignore_id && 0 === strcasecmp((string) ($variable['label'] ?? ''), $label)) {
                return (string) $id;
            }
        }

        return null;
    }

    public static function duplicate(string $label, string $owner): \WP_Error
    {
        return new \WP_Error(
            'duplicate_label',
            sprintf('A global variable labelled "%s" already exists (%s). Labels are case-insensitive; update it instead.', $label, $owner)
        );
    }
}
