<?php

namespace WPMCP\Tools\ThemeBuilder;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * List the theme-builder site parts on this site (id, part type, title,
 * conditions, priority, status), optionally filtered by part type. An unknown
 * part type is an error rather than an empty list, so a typo does not read as
 * "this site has no templates". Read-only.
 */
class List_Site_Parts
{
    /** Bounded page size: this is a display listing, not the resolver's input. */
    private const PAGE_SIZE = 200;

    public function handle(array $args)
    {
        $part_type = isset($args['part_type']) ? trim((string) $args['part_type']) : '';
        if ('' !== $part_type) {
            $checked = Template_Store::validate_part_type($part_type);
            if (is_wp_error($checked)) {
                return $checked;
            }
        }

        return [
            'templates' => Template_Store::all(false, '' === $part_type ? null : $part_type, self::PAGE_SIZE),
        ];
    }
}
