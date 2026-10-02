<?php

namespace WPMCP\Tools\Settings;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only: lists WordPress options from the Settings_Registry allowlist,
 * each coerced to its declared type and annotated with the metadata a caller
 * needs to know how to safely write it back (group, type, enum options,
 * writable). Never touches Safe_Mutation; reads have nothing to roll back.
 */
class Get_Settings
{
    public function handle(array $args): array
    {
        $group = isset($args['group']) ? (string) $args['group'] : null;
        $keys  = isset($args['keys']) ? array_map('strval', (array) $args['keys']) : null;

        $rows = [];
        foreach (Settings_Registry::all() as $key => $meta) {
            if (null !== $group && $meta['group'] !== $group) {
                continue;
            }
            if (null !== $keys && ! in_array($key, $keys, true)) {
                continue;
            }
            $rows[] = $this->row($key, $meta);
        }

        return ['settings' => $rows];
    }

    private function row(string $key, array $meta): array
    {
        $row = [
            'key'      => $key,
            'group'    => $meta['group'],
            'type'     => $meta['type'],
            'value'    => $this->coerce($meta, get_option($key)),
            'writable' => $meta['writable'],
        ];
        if (isset($meta['options'])) {
            $row['options'] = $meta['options'];
        }
        return $row;
    }

    private function coerce(array $meta, $value)
    {
        switch ($meta['type']) {
            case 'int':
                return (int) $value;
            case 'bool':
                return (bool) $value;
            case 'float':
                return (float) $value;
            default:
                return $value;
        }
    }
}
