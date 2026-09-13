<?php

namespace WPMCP\Tools\Elementor;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Atomically replace all four Elementor system typography slots (primary,
 * secondary, text, accent) on the active kit. Unlike
 * update-global-typography, which patches individual slots, this tool
 * requires a complete set: every system slot must be present exactly once,
 * or nothing is written (all four slots or none). Each entry carries the
 * typography_* fields for its slot and nothing else: an unrecognized key is
 * refused rather than dropped, so a misspelled payload cannot validate as a
 * complete set and wipe the four slots. Setting a font implies
 * typography_typography => 'custom' so the token actually renders, and an
 * entry that omits "title" keeps the slot's current title. Requires
 * expected_hash from get-global-settings; undoable via rollback-operation.
 */
class Replace_System_Typography
{
    public function handle(array $args)
    {
        $kit_id = Elementor_Kit_Data::guard($args);
        if (is_wp_error($kit_id)) {
            return $kit_id;
        }

        $typography = is_array($args['system_typography'] ?? null) ? $args['system_typography'] : [];
        if ([] === $typography) {
            return new \WP_Error(
                'missing_typography',
                'Provide system_typography: a complete set of the four system slots (primary, secondary, text, accent).'
            );
        }

        $replacement = self::validate_complete_set(
            $typography,
            Elementor_Kit_Data::system_slot_titles($kit_id, 'system_typography')
        );
        if (is_wp_error($replacement)) {
            return $replacement;
        }

        $result = Elementor_Kit_Data::write(
            $kit_id,
            ['system_typography' => array_values($replacement)],
            'replace-system-typography',
            $args
        );
        if (is_wp_error($result)) {
            return $result;
        }

        return array_merge($result, ['system_typography' => array_values($replacement)]);
    }

    /**
     * Validate the replacement covers every system slot exactly once. Any
     * failure rejects the whole set before a write: partial replacement of
     * the system typography is never allowed.
     *
     * @param array<string,string> $titles current slot titles keyed by _id, used
     *                                     when an entry omits its own title.
     * @return array|\WP_Error the sanitized replacement entries, slot order preserved.
     */
    private static function validate_complete_set(array $typography, array $titles)
    {
        $slots = array_column(Elementor_Kit_Data::default_system_typography(), null, '_id');
        $seen  = [];

        foreach ($typography as $entry) {
            if (! is_array($entry)) {
                return new \WP_Error('invalid_entry', 'Each system_typography entry must be an object with an _id.');
            }

            $id = isset($entry['_id']) ? (string) $entry['_id'] : '';
            if (! isset($slots[$id])) {
                return new \WP_Error(
                    'unknown_slot',
                    sprintf('"%s" is not a system typography slot. Slots: %s.', $id, implode(', ', array_keys($slots)))
                );
            }
            if (isset($seen[$id])) {
                return new \WP_Error('duplicate_slot', sprintf('System slot "%s" appears more than once.', $id));
            }

            $unknown = self::unknown_keys($entry);
            if ([] !== $unknown) {
                return new \WP_Error(
                    'unknown_field',
                    sprintf(
                        'Slot "%s": unrecognized field(s) %s. Typography fields must be prefixed "typography_" '
                        . '(e.g. typography_font_family). Nothing was written.',
                        $id,
                        implode(', ', $unknown)
                    )
                );
            }

            $fields = self::sanitize_fields($entry);
            if ([] === $fields) {
                return new \WP_Error(
                    'empty_entry',
                    sprintf(
                        'Slot "%s" carries no typography_* field. Replacement overwrites every slot, so an empty '
                        . 'entry would silently clear it; send the fields you want, or typography_typography: "" '
                        . 'to clear the slot deliberately.',
                        $id
                    )
                );
            }

            $seen[$id] = array_merge(
                [
                    '_id'   => $id,
                    'title' => sanitize_text_field((string) ($entry['title'] ?? $titles[$id])),
                ],
                $fields
            );
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

    /**
     * Every key an entry may carry beyond its typography_* fields. Anything
     * else is a typo (or a field this tool does not own) and is refused.
     *
     * @return string[] the offending keys, in input order.
     */
    private static function unknown_keys(array $entry): array
    {
        $unknown = [];
        foreach (array_keys($entry) as $key) {
            $key = (string) $key;
            if ('_id' === $key || 'title' === $key || 0 === strpos($key, 'typography_')) {
                continue;
            }
            $unknown[] = $key;
        }

        return $unknown;
    }

    /** Keep any typography_* field; enable custom typography when a font is set. */
    private static function sanitize_fields(array $entry): array
    {
        $fields = [];
        foreach ($entry as $key => $value) {
            if (0 === strpos((string) $key, 'typography_')) {
                $fields[$key] = self::sanitize_value($value);
            }
        }

        $sets_font = isset($fields['typography_font_family']) || isset($fields['typography_font_weight'])
            || isset($fields['typography_font_size']);
        if ($sets_font && ! isset($fields['typography_typography'])) {
            $fields['typography_typography'] = 'custom';
        }

        return $fields;
    }

    /**
     * Sanitize a typography value. Elementor stores several of these as small
     * arrays (typography_font_size => ['unit' => 'px', 'size' => 48], plus the
     * responsive 'sizes' map), and those end up in generated kit CSS, so every
     * leaf is sanitized rather than passed through verbatim.
     *
     * @param mixed $value
     * @return mixed
     */
    private static function sanitize_value($value)
    {
        if (! is_array($value)) {
            return sanitize_text_field((string) $value);
        }

        $clean = [];
        foreach ($value as $key => $item) {
            $clean[is_string($key) ? sanitize_key($key) : $key] = self::sanitize_value($item);
        }

        return $clean;
    }
}
