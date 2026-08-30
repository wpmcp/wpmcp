<?php

namespace WPMCP\Tools\Elementor;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Atomically replace all four Elementor system color slots (primary,
 * secondary, text, accent) on the active kit. Unlike update-global-colors,
 * which patches individual slots, this tool requires a complete set: every
 * system slot must be present exactly once with a valid hex color, or
 * nothing is written (all four slots or none). An entry that omits "title"
 * keeps the slot's current title (a slot the user renamed in the builder is
 * not silently renamed back). Requires expected_hash from
 * get-global-settings; undoable via rollback-operation since the kit's
 * _elementor_page_settings is captured by the post snapshot.
 */
class Replace_System_Colors
{
    public function handle(array $args)
    {
        $kit_id = Elementor_Kit_Data::guard($args);
        if (is_wp_error($kit_id)) {
            return $kit_id;
        }

        $colors = is_array($args['system_colors'] ?? null) ? $args['system_colors'] : [];
        if ([] === $colors) {
            return new \WP_Error(
                'missing_colors',
                'Provide system_colors: a complete set of the four system slots (primary, secondary, text, accent).'
            );
        }

        $replacement = self::validate_complete_set(
            $colors,
            Elementor_Kit_Data::system_slot_titles($kit_id, 'system_colors')
        );
        if (is_wp_error($replacement)) {
            return $replacement;
        }

        $result = Elementor_Kit_Data::write(
            $kit_id,
            ['system_colors' => array_values($replacement)],
            'replace-system-colors',
            $args
        );
        if (is_wp_error($result)) {
            return $result;
        }

        return array_merge($result, ['system_colors' => array_values($replacement)]);
    }

    /**
     * Validate the replacement covers every system slot exactly once with a
     * valid hex color. Any failure rejects the whole set before a write:
     * partial replacement of the system palette is never allowed.
     *
     * @param array<string,string> $titles current slot titles keyed by _id, used
     *                                     when an entry omits its own title.
     * @return array|\WP_Error the sanitized replacement entries, slot order preserved.
     */
    private static function validate_complete_set(array $colors, array $titles)
    {
        $slots = array_column(Elementor_Kit_Data::default_system_colors(), null, '_id');
        $seen  = [];

        foreach ($colors as $entry) {
            if (! is_array($entry)) {
                return new \WP_Error('invalid_entry', 'Each system_colors entry must be an object with _id and color.');
            }

            $id = isset($entry['_id']) ? (string) $entry['_id'] : '';
            if (! isset($slots[$id])) {
                return new \WP_Error(
                    'unknown_slot',
                    sprintf('"%s" is not a system color slot. Slots: %s.', $id, implode(', ', array_keys($slots)))
                );
            }
            if (isset($seen[$id])) {
                return new \WP_Error('duplicate_slot', sprintf('System slot "%s" appears more than once.', $id));
            }

            $color = sanitize_hex_color((string) ($entry['color'] ?? ''));
            if (empty($color)) {
                return new \WP_Error(
                    'invalid_color',
                    sprintf('Slot "%s": "%s" is not a valid hex color.', $id, (string) ($entry['color'] ?? ''))
                );
            }

            $seen[$id] = [
                '_id'   => $id,
                'title' => sanitize_text_field((string) ($entry['title'] ?? $titles[$id])),
                'color' => $color,
            ];
        }

        $missing = array_diff(array_keys($slots), array_keys($seen));
        if ([] !== $missing) {
            return new \WP_Error(
                'incomplete_set',
                sprintf('Replacement is atomic: all four system slots are required. Missing: %s.', implode(', ', $missing))
            );
        }

        // Preserve the canonical slot order regardless of input order.
        $ordered = [];
        foreach (array_keys($slots) as $id) {
            $ordered[] = $seen[$id];
        }

        return $ordered;
    }
}
