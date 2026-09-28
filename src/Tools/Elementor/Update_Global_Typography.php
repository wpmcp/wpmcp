<?php

namespace WPMCP\Tools\Elementor;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Patch the active Elementor kit's global typography. `system_typography`
 * entries are matched to the four system tokens by _id; `custom_typography`
 * entries update an existing token by _id or append a new one. Any typography_*
 * field an entry provides is merged in, and setting a font implies
 * typography_typography => 'custom' so the token actually renders. Requires
 * expected_hash from get-global-settings; undoable via rollback-operation.
 */
class Update_Global_Typography
{
    public function handle(array $args)
    {
        $kit_id = Elementor_Kit_Data::guard($args);
        if (is_wp_error($kit_id)) {
            return $kit_id;
        }

        $system_patch = is_array($args['system_typography'] ?? null) ? $args['system_typography'] : [];
        $custom_patch = is_array($args['custom_typography'] ?? null) ? $args['custom_typography'] : [];

        if ([] === $system_patch && [] === $custom_patch) {
            return new \WP_Error('missing_typography', 'Provide system_typography and/or custom_typography to update.');
        }

        $view   = Elementor_Kit_Data::view($kit_id);
        $system = self::patch_by_id($view['system_typography'], $system_patch, false);
        $custom = self::patch_by_id($view['custom_typography'], $custom_patch, true);

        $result = Elementor_Kit_Data::write(
            $kit_id,
            ['system_typography' => array_values($system), 'custom_typography' => array_values($custom)],
            'update-global-typography',
            $args
        );
        if (is_wp_error($result)) {
            return $result;
        }

        return array_merge($result, ['system_typography' => array_values($system), 'custom_typography' => array_values($custom)]);
    }

    /**
     * Merge each patch entry's typography_* fields into the matching token by
     * _id (appending new custom tokens when $allow_append). Providing any font
     * field flips typography_typography to 'custom'.
     */
    private static function patch_by_id(array $entries, array $patch, bool $allow_append): array
    {
        foreach ($patch as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $fields = self::sanitize_fields($entry);
            $id     = isset($entry['_id']) ? (string) $entry['_id'] : '';
            $matched = false;

            foreach ($entries as &$existing) {
                if ('' !== $id && ($existing['_id'] ?? '') === $id) {
                    $existing = array_merge($existing, $fields);
                    $matched  = true;
                    break;
                }
            }
            unset($existing);

            if (! $matched && $allow_append) {
                $title     = sanitize_text_field((string) ($entry['title'] ?? 'Custom'));
                $entries[] = array_merge(
                    ['_id' => '' !== $id ? $id : (sanitize_key($title) ?: 'style'), 'title' => '' !== $title ? $title : 'Custom'],
                    $fields
                );
            }
        }

        return $entries;
    }

    /** Keep title + any typography_* field; enable custom typography when a font is set. */
    private static function sanitize_fields(array $entry): array
    {
        $fields = [];
        foreach ($entry as $key => $value) {
            if ('_id' === $key) {
                continue;
            }
            if ('title' === $key) {
                $fields['title'] = sanitize_text_field((string) $value);
                continue;
            }
            if (0 === strpos((string) $key, 'typography_')) {
                $fields[$key] = is_array($value) ? $value : sanitize_text_field((string) $value);
            }
        }

        // Responsive sizes (typography_font_size_tablet, _mobile, ...) count
        // too: without the flag Elementor ignores the whole token, so a
        // breakpoint-only update would never render.
        $sets_font = isset($fields['typography_font_family']) || isset($fields['typography_font_weight'])
            || [] !== preg_grep('/^typography_font_size(_[a-z_]+)?$/', array_keys($fields));
        if ($sets_font && ! isset($fields['typography_typography'])) {
            $fields['typography_typography'] = 'custom';
        }

        return $fields;
    }
}
