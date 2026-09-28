<?php

namespace WPMCP\Tools\Elementor;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * List the active kit's Elementor 4 global variables (design tokens) in
 * editor order, soft-deleted ones excluded. The state_hash is the optimistic
 * lock every variable write requires as expected_hash. Read-only.
 */
class List_Global_Variables
{
    public function handle(array $args)
    {
        if (! Global_Variables_Store::is_supported()) {
            return new \WP_Error(
                'unsupported',
                'Global variables need Elementor 4 with atomic elements active; this site does not expose them.'
            );
        }

        $state = Global_Variables_Store::read();
        if (is_wp_error($state)) {
            return $state;
        }

        $variables = [];
        foreach ($state['variables'] as $variable) {
            $variables[] = self::shape($variable);
        }

        return [
            'kit_id'          => $state['kit_id'],
            'variables'       => $variables,
            'sizes_supported' => Global_Variables_Store::sizes_supported(),
            'state_hash'      => Global_Variables_Store::state_hash($state),
        ];
    }

    /** Public shape of one stored variable. */
    public static function shape(array $variable): array
    {
        $elementor_type = (string) ($variable['type'] ?? '');
        $friendly       = Global_Variable_Schema::type($elementor_type);

        return [
            'id'             => (string) $variable['id'],
            'label'          => (string) ($variable['label'] ?? ''),
            'type'           => is_wp_error($friendly) ? $elementor_type : $friendly,
            'elementor_type' => $elementor_type,
            'value'          => $variable['value'] ?? '',
            'order'          => isset($variable['order']) ? (int) $variable['order'] : null,
        ];
    }
}
