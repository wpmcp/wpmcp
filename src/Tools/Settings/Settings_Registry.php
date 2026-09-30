<?php

namespace WPMCP\Tools\Settings;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The single source of truth for which WordPress options the settings tools
 * expose, and how. This is a strict allowlist: any option not listed here is
 * invisible to Get_Settings and unwritable by Update_Settings, regardless of
 * what the caller asks for.
 *
 * Each entry:
 *  - group:    which settings screen it belongs to (general/reading/writing/
 *              discussion/media/permalinks), used by the 'group' filter.
 *  - type:     'string'|'int'|'bool'|'enum', used both to coerce the raw
 *              option value on read and to validate/coerce a write.
 *  - options:  for type 'enum' only, the allowed values.
 *  - min/max:  for type 'int' only, the range a write is clamped to.
 *  - writable: false marks a read-only field (currently just admin_email,
 *              which needs its own confirmation flow outside these tools).
 */
class Settings_Registry
{
    private const SCHEMA = [
        'blogname'            => ['group' => 'general', 'type' => 'string', 'writable' => true],
        'blogdescription'     => ['group' => 'general', 'type' => 'string', 'writable' => true],
        'admin_email'         => ['group' => 'general', 'type' => 'string', 'writable' => false],
        'siteurl'             => ['group' => 'general', 'type' => 'string', 'writable' => false],
        'home'                => ['group' => 'general', 'type' => 'string', 'writable' => false],

        'show_on_front'       => ['group' => 'reading', 'type' => 'enum', 'options' => ['posts', 'page'], 'writable' => true],
        'page_on_front'       => ['group' => 'reading', 'type' => 'int', 'min' => 0, 'max' => PHP_INT_MAX, 'writable' => true],
        'page_for_posts'      => ['group' => 'reading', 'type' => 'int', 'min' => 0, 'max' => PHP_INT_MAX, 'writable' => true],
        'posts_per_page'      => ['group' => 'reading', 'type' => 'int', 'min' => 1, 'max' => 100, 'writable' => true],
        'blog_public'         => ['group' => 'reading', 'type' => 'bool', 'writable' => true],

        'default_category'    => ['group' => 'writing', 'type' => 'int', 'min' => 1, 'max' => PHP_INT_MAX, 'writable' => true],
        'default_post_format' => ['group' => 'writing', 'type' => 'string', 'writable' => true],

        'default_comment_status' => ['group' => 'discussion', 'type' => 'enum', 'options' => ['open', 'closed'], 'writable' => true],
        'default_ping_status'    => ['group' => 'discussion', 'type' => 'enum', 'options' => ['open', 'closed'], 'writable' => true],
        'comment_registration'   => ['group' => 'discussion', 'type' => 'bool', 'writable' => true],

        'thumbnail_size_w' => ['group' => 'media', 'type' => 'int', 'min' => 0, 'max' => 9999, 'writable' => true],
        'thumbnail_size_h' => ['group' => 'media', 'type' => 'int', 'min' => 0, 'max' => 9999, 'writable' => true],
        'medium_size_w'    => ['group' => 'media', 'type' => 'int', 'min' => 0, 'max' => 9999, 'writable' => true],
        'medium_size_h'    => ['group' => 'media', 'type' => 'int', 'min' => 0, 'max' => 9999, 'writable' => true],
        'large_size_w'     => ['group' => 'media', 'type' => 'int', 'min' => 0, 'max' => 9999, 'writable' => true],
        'large_size_h'     => ['group' => 'media', 'type' => 'int', 'min' => 0, 'max' => 9999, 'writable' => true],

        'permalink_structure' => ['group' => 'permalinks', 'type' => 'string', 'writable' => true],
    ];

    /**
     * The full allowlist, keyed by option name. A feature that owns an
     * option adds it through the wpmcp_settings_registry filter, so the
     * option is listed only on builds that ship the feature (issue #432);
     * a malformed entry is dropped rather than trusted.
     *
     * @return array<string,array>
     */
    public static function all(): array
    {
        $schema = apply_filters('wpmcp_settings_registry', self::SCHEMA);
        if (! is_array($schema)) {
            return self::SCHEMA;
        }
        $groups = self::groups();
        foreach ($schema as $key => $meta) {
            if (! is_array($meta) || ! in_array($meta['group'] ?? null, $groups, true) || ! isset($meta['type'], $meta['writable'])) {
                unset($schema[ $key ]);
            }
        }
        // Core entries always win: a filter adds options, it never loosens
        // one of these (admin_email stays read-only).
        return self::SCHEMA + $schema;
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::all());
    }

    public static function get(string $key): ?array
    {
        return self::all()[ $key ] ?? null;
    }

    /** @return string[] All group names, in a stable order. */
    public static function groups(): array
    {
        return ['general', 'reading', 'writing', 'discussion', 'media', 'permalinks'];
    }
}
