<?php

namespace WPMCP\Integrations;

use WPMCP\Safety\Post_Creation_Snapshot;
use WPMCP\Safety\Safe_Mutation;
use WPMCP\Safety\Save_Filters;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Custom post type builders (issue #710): post types and taxonomies made in
 * Custom Post Type UI or in MB Custom Post Types (Meta Box's free extension,
 * also bundled in Meta Box AIO), as free ops on the plugin-data pair, so
 * they add no top-level tool. A type is changed in the plugin that owns it,
 * never in code:
 *
 *  - cpt-list-types: every custom post type and taxonomy, registered or
 *    only stored, with the plugin that owns it (cptui, metabox, or code
 *    for one a theme or another plugin registers).
 *  - cpt-get-type: one type's stored config, as its owner keeps it.
 *  - cpt-create-post-type, cpt-create-taxonomy: a new type in the chosen
 *    plugin (the active one when only one is).
 *  - cpt-update-post-type, cpt-update-taxonomy: change a builder-owned
 *    type in its owner; settings the call does not name are kept, and a
 *    slug is never renamed (that would orphan its posts or terms).
 *
 * Storage, as the plugins keep it (verified against Custom Post Type UI
 * 1.19.3 and MB Custom Post Types 2.13.1): CPT UI keeps every type in the
 * cptui_post_types and cptui_taxonomies options keyed by slug, booleans as
 * the strings "true" and "false", and flushes rewrite rules on the next
 * admin load after its cptui_flush_rewrite_rules transient is set. Meta Box
 * keeps one mb-post-type or mb-taxonomy post per type, its settings as JSON
 * in post_content, and registers the published ones.
 *
 * Every write is snapshotted: a CPT UI write on its option (rollback puts
 * the option back exactly, or removes it when the write created it), a Meta
 * Box update on its definition post, and a Meta Box create as a created
 * post that rollback trashes, which unregisters the type. Only the fields
 * in the schemas are written; callbacks (register_meta_box_cb,
 * meta_box_cb) and capability settings are never set by these ops.
 *
 * All ops need manage_options, the capability both plugins' screens take.
 * Writes are off until the site opts in (wpmcp_enable_cpt_builder_write).
 * Presence is filterable (wpmcp_cptui_active, default CPTUI_VERSION;
 * wpmcp_mb_cpt_active, default mb_cpt_load()).
 */
final class Cpt_Builder_Pack
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.

    public const OPT_IN_FILTER = 'wpmcp_enable_cpt_builder_write';

    private const CPTUI_TYPES = 'cptui_post_types';

    private const CPTUI_TAXES = 'cptui_taxonomies';

    private const MB_TYPE = 'mb-post-type';

    private const MB_TAX = 'mb-taxonomy';

    private const PLUGIN_NAMES = [ 'cptui' => 'Custom Post Type UI', 'metabox' => 'MB Custom Post Types' ];

    private const PLUGIN_FILES = [
        'cptui'   => [ 'custom-post-type-ui/custom-post-type-ui.php' ],
        'metabox' => [ 'mb-custom-post-type/mb-custom-post-type.php', 'meta-box-aio/meta-box-aio.php' ],
    ];

    /** Longest slug WordPress accepts: register_post_type() 20, register_taxonomy() 32. */
    private const MAX_SLUG = [ 'post_type' => 20, 'taxonomy' => 32 ];

    private const SUPPORTS = [ 'title', 'editor', 'author', 'thumbnail', 'excerpt', 'trackbacks', 'custom-fields', 'comments', 'revisions', 'page-attributes', 'post-formats' ];

    /** Booleans each kind takes, by the arg name the ops use. */
    private const FLAGS = [
        'post_type' => [ 'public', 'hierarchical', 'has_archive', 'show_in_rest', 'exclude_from_search' ],
        'taxonomy'  => [ 'public', 'hierarchical', 'show_in_rest', 'show_admin_column' ],
    ];

    private const CHECK_NOTE = 'The change takes effect on the next page load. If its URLs answer 404, save Settings > Permalinks once. Undo with rollback-operation.';

    public static function cptui_active(): bool
    {
        return (bool) apply_filters('wpmcp_cptui_active', defined('CPTUI_VERSION'));
    }

    public static function metabox_active(): bool
    {
        return (bool) apply_filters('wpmcp_mb_cpt_active', function_exists('mb_cpt_load'));
    }

    public static function active(): bool
    {
        return self::cptui_active() || self::metabox_active();
    }

    /** @return true|array{code: string, message: string, data: array} */
    public static function presence()
    {
        return self::active() ? true : self::plugin_refusal(array_keys(self::PLUGIN_NAMES));
    }

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        $base  = [
            'capability' => 'manage_options',
            'requires'   => static fn () => self::presence(),
        ];
        $write = $base + [
            'mode'               => 'write',
            'enabled_by_default' => (bool) apply_filters('wpmcp_enable_cpt_builder_write', false),
            'opt_in_filter'      => self::OPT_IN_FILTER,
        ];
        $kind  = [ 'type' => 'string', 'enum' => [ 'post_type', 'taxonomy' ] ];
        $slug  = [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 32 ];

        return [
            'cpt-list-types'       => $base + [
                'mode'         => 'read',
                'description'  => 'List custom post types and taxonomies with the plugin that owns each (cptui, metabox, or code), active and registered flags. kind filters',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'kind' => $kind ] ],
                'handler'      => static fn (array $args): array => self::list_types((string) ($args['kind'] ?? '')),
            ],
            'cpt-get-type'         => $base + [
                'mode'         => 'read',
                'description'  => 'One custom post type or taxonomy: owning plugin and its stored config',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'kind' => $kind, 'slug' => $slug ], 'required' => [ 'kind', 'slug' ] ],
                'validate'     => static fn (array $args): ?array => null === self::find((string) $args['kind'], (string) $args['slug']) ? self::not_found((string) $args['slug']) : null,
                'handler'      => static fn (array $args): array => self::view((string) $args['kind'], (string) $args['slug']),
            ],
            'cpt-create-post-type' => $write + [
                'self_snapshotting' => true,
                'description'       => 'Create a post type in Custom Post Type UI (plugin cptui) or MB Custom Post Types (metabox). Undo: rollback-session',
                'input_schema'      => self::schema('post_type', true),
                'validate'          => static fn (array $args): ?array => self::create_refusal('post_type', $args),
                'handler'           => static fn (array $args, array $context): array => self::create('post_type', $args, $context),
            ],
            'cpt-update-post-type' => $write + [
                'description'  => 'Change a builder-owned post type in its plugin; unnamed settings kept, slug never renamed. Undo: rollback-operation',
                'input_schema' => self::schema('post_type', false),
                'validate'     => static fn (array $args): ?array => self::update_refusal('post_type', $args),
                'snapshot'     => static fn (array $args): array => self::target('post_type', (string) $args['slug']),
                'handler'      => static fn (array $args): array => self::update('post_type', $args),
            ],
            'cpt-create-taxonomy'  => $write + [
                'self_snapshotting' => true,
                'description'       => 'Create a taxonomy in Custom Post Type UI (plugin cptui) or MB Custom Post Types (metabox). Undo: rollback-session',
                'input_schema'      => self::schema('taxonomy', true),
                'validate'          => static fn (array $args): ?array => self::create_refusal('taxonomy', $args),
                'handler'           => static fn (array $args, array $context): array => self::create('taxonomy', $args, $context),
            ],
            'cpt-update-taxonomy'  => $write + [
                'description'  => 'Change a builder-owned taxonomy in its plugin; unnamed settings kept, slug never renamed. Undo: rollback-operation',
                'input_schema' => self::schema('taxonomy', false),
                'validate'     => static fn (array $args): ?array => self::update_refusal('taxonomy', $args),
                'snapshot'     => static fn (array $args): array => self::target('taxonomy', (string) $args['slug']),
                'handler'      => static fn (array $args): array => self::update('taxonomy', $args),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function schema(string $kind, bool $create): array
    {
        $text  = [ 'type' => 'string', 'maxLength' => 200 ];
        $names = [ 'type' => 'array', 'maxItems' => 50, 'items' => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 32 ] ];
        $props = [
            'slug'           => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 40 ],
            'label'          => $text,
            'singular_label' => $text,
            'description'    => [ 'type' => 'string', 'maxLength' => 1000 ],
            'rewrite_slug'   => $text,
        ];
        foreach (self::FLAGS[ $kind ] as $flag) {
            $props[ $flag ] = [ 'type' => 'boolean' ];
        }
        if ('post_type' === $kind) {
            $props['supports']   = [ 'type' => 'array', 'items' => [ 'type' => 'string', 'enum' => self::SUPPORTS ] ];
            $props['taxonomies'] = $names;
            $props['menu_icon']  = [ 'type' => 'string', 'pattern' => '^dashicons-[a-z0-9-]+$', 'maxLength' => 60 ];
        } else {
            $props['post_types'] = $names;
        }
        if ($create) {
            $props['plugin'] = [ 'type' => 'string', 'enum' => array_keys(self::PLUGIN_NAMES) ];
        }

        return [
            'type'                 => 'object',
            'properties'           => $props,
            'required'             => [ 'slug' ],
            'additionalProperties' => false,
        ] + ($create ? [] : [ 'minProperties' => 2 ]);
    }

    // ---------------------------------------------------------------
    // Refusals
    // ---------------------------------------------------------------

    /**
     * plugin_missing or plugin_inactive naming the plugins.
     *
     * @param string[] $plugins
     * @return array{code: string, message: string, data: array}
     */
    private static function plugin_refusal(array $plugins): array
    {
        $installed = false;
        foreach ($plugins as $plugin) {
            foreach (self::PLUGIN_FILES[ $plugin ] as $file) {
                $installed = $installed || (defined('WP_PLUGIN_DIR') && file_exists(WP_PLUGIN_DIR . '/' . $file));
            }
        }
        $names = array_map(static fn (string $p): string => self::PLUGIN_NAMES[ $p ], $plugins);
        $url   = admin_url('plugins.php');

        return [
            'code'    => $installed ? 'plugin_inactive' : 'plugin_missing',
            'message' => sprintf(
                '%s %s %s on this site. Install and activate %s (%s), then try again.',
                implode(' and ', $names),
                count($names) > 1 ? 'are' : 'is',
                $installed ? 'installed but not active' : 'not installed',
                implode(' or ', $names),
                $url
            ),
            'data'    => [ 'plugins' => $plugins, 'plugins_url' => $url ],
        ];
    }

    private static function refusal(string $code, string $message, array $data = []): array
    {
        return [ 'code' => $code, 'message' => $message, 'data' => $data ];
    }

    private static function not_found(string $slug): array
    {
        return self::refusal('type_not_found', sprintf('No custom post type or taxonomy "%s" is registered or stored by Custom Post Type UI or MB Custom Post Types. cpt-list-types lists them.', $slug));
    }

    /** The plugin a create writes to: the one named, else the only active one. */
    private static function chosen_plugin(array $args): ?string
    {
        if (isset($args['plugin'])) {
            return (string) $args['plugin'];
        }
        $active = array_values(array_filter(array_keys(self::PLUGIN_NAMES), static fn (string $p): bool => self::plugin_active($p)));
        return 1 === count($active) ? $active[0] : null;
    }

    private static function plugin_active(string $plugin): bool
    {
        return 'cptui' === $plugin ? self::cptui_active() : self::metabox_active();
    }

    /** @return array{code: string, message: string, data: array}|null */
    private static function create_refusal(string $kind, array $args): ?array
    {
        $plugin = self::chosen_plugin($args);
        if (null === $plugin) {
            return self::refusal('plugin_required', 'Both Custom Post Type UI and MB Custom Post Types are active; pass plugin (cptui or metabox) to say which one keeps the new type.');
        }
        if (! self::plugin_active($plugin)) {
            return self::plugin_refusal([ $plugin ]);
        }
        $slug = (string) $args['slug'];
        if (1 !== preg_match('/^[a-z0-9_-]+$/', $slug) || strlen($slug) > self::MAX_SLUG[ $kind ]) {
            return self::refusal('invalid_slug', sprintf('Slugs of this kind (%s) are lowercase letters, digits, dashes and underscores, at most %d characters.', $kind, self::MAX_SLUG[ $kind ]));
        }
        if (post_type_exists($slug) || taxonomy_exists($slug) || null !== self::find('post_type', $slug) || null !== self::find('taxonomy', $slug)) {
            return self::refusal('slug_taken', sprintf('"%s" is already a post type or taxonomy on this site; pick another slug.', $slug));
        }
        global $wp;
        $reserved = array_merge(
            [ 'action', 'author', 'order', 'theme', 'type', 'types', 'fields', 'custom', 'page', 'name', 'term', 'terms', 'taxonomy', 'year', 'month', 'day', 'hour', 'minute', 'second', 'search', 'post_type', 'comments', 'embed', 'feed' ],
            is_object($wp) ? (array) $wp->public_query_vars : []
        );
        if (in_array($slug, $reserved, true)) {
            return self::refusal('slug_reserved', sprintf('"%s" is reserved by WordPress and would break its URLs; pick another slug.', $slug));
        }
        return self::links_refusal($kind, $args);
    }

    /** @return array{code: string, message: string, data: array}|null */
    private static function update_refusal(string $kind, array $args): ?array
    {
        $slug  = (string) $args['slug'];
        $found = self::find($kind, $slug);
        if (null === $found) {
            $registered = 'post_type' === $kind ? get_post_type_object($slug) : get_taxonomy($slug);
            if (is_object($registered) && empty($registered->_builtin)) {
                return self::refusal('not_builder_owned', sprintf('"%s" is registered in code by the theme or another plugin, not by Custom Post Type UI or MB Custom Post Types, so it is changed there.', $slug));
            }
            return self::not_found($slug);
        }
        if (! self::plugin_active($found['plugin'])) {
            return self::plugin_refusal([ $found['plugin'] ]);
        }
        return self::links_refusal($kind, $args);
    }

    /** Refuse taxonomies or post types that do not exist. */
    private static function links_refusal(string $kind, array $args): ?array
    {
        $key     = 'post_type' === $kind ? 'taxonomies' : 'post_types';
        $other   = 'post_type' === $kind ? 'taxonomy' : 'post_type';
        $unknown = [];
        foreach ((array) ($args[ $key ] ?? []) as $name) {
            $name   = (string) $name;
            $exists = 'taxonomy' === $other ? taxonomy_exists($name) : post_type_exists($name);
            if (! $exists && null === self::find($other, $name)) {
                $unknown[] = $name;
            }
        }
        if ([] === $unknown) {
            return null;
        }
        return self::refusal('unknown_' . $other, sprintf('Unknown on this site (%s): %s.', 'taxonomy' === $other ? 'taxonomies' : 'post types', implode(', ', $unknown)), [ 'unknown' => $unknown ]);
    }

    // ---------------------------------------------------------------
    // Lookups
    // ---------------------------------------------------------------

    /** @return array<string, array<string, mixed>> CPT UI's stored entries for a kind, by slug. */
    private static function cptui_entries(string $kind): array
    {
        if (! self::cptui_active()) {
            return [];
        }
        $all = get_option('post_type' === $kind ? self::CPTUI_TYPES : self::CPTUI_TAXES, []);
        return is_array($all) ? array_filter($all, 'is_array') : [];
    }

    /** @return array<string, array{post: \WP_Post, settings: array}> Meta Box definitions for a kind, by slug. */
    private static function mb_entries(string $kind): array
    {
        if (! self::metabox_active()) {
            return [];
        }
        $posts = get_posts([
            'post_type'        => 'post_type' === $kind ? self::MB_TYPE : self::MB_TAX,
            'post_status'      => [ 'publish', 'draft', 'pending', 'private', 'future' ],
            'posts_per_page'   => -1,
            'orderby'          => 'ID',
            'order'            => 'ASC',
            'no_found_rows'    => true,
        ]);
        $out   = [];
        foreach ($posts as $post) {
            $settings = json_decode((string) $post->post_content, true);
            if (is_array($settings) && '' !== (string) ($settings['slug'] ?? '')) {
                $out[ (string) $settings['slug'] ] = [ 'post' => $post, 'settings' => $settings ];
            }
        }
        return $out;
    }

    /** @return array{plugin: string, config: array, post?: \WP_Post}|null The builder that stores a type, CPT UI first. */
    private static function find(string $kind, string $slug): ?array
    {
        $cptui = self::cptui_entries($kind);
        if (isset($cptui[ $slug ])) {
            return [ 'plugin' => 'cptui', 'config' => $cptui[ $slug ] ];
        }
        $mb = self::mb_entries($kind);
        if (isset($mb[ $slug ])) {
            return [ 'plugin' => 'metabox', 'config' => $mb[ $slug ]['settings'], 'post' => $mb[ $slug ]['post'] ];
        }
        return null;
    }

    /** @return array{object_type: string, object_id: int|string} */
    private static function target(string $kind, string $slug): array
    {
        $found = self::find($kind, $slug);
        if (null !== $found && isset($found['post'])) {
            return [ 'object_type' => 'post', 'object_id' => (int) $found['post']->ID ];
        }
        return [ 'object_type' => 'option', 'object_id' => 'post_type' === $kind ? self::CPTUI_TYPES : self::CPTUI_TAXES ];
    }

    // ---------------------------------------------------------------
    // Reads
    // ---------------------------------------------------------------

    /** @return array<string, mixed> */
    private static function list_types(string $only): array
    {
        $out = [];
        foreach ([ 'post_type' => 'post_types', 'taxonomy' => 'taxonomies' ] as $kind => $key) {
            if ('' === $only || $only === $kind) {
                $out[ $key ] = self::rows($kind);
            }
        }
        return $out;
    }

    /** @return array<int, array<string, mixed>> */
    private static function rows(string $kind): array
    {
        $rows = [];
        foreach (self::cptui_entries($kind) as $slug => $entry) {
            $rows[ (string) $slug ] = [
                'slug'   => (string) $slug,
                'label'  => html_entity_decode((string) ($entry['label'] ?? $slug), ENT_QUOTES),
                'plugin' => 'cptui',
                'active' => true,
                'links'  => array_values(array_map('strval', (array) ($entry['post_type' === $kind ? 'taxonomies' : 'object_types'] ?? []))),
            ];
        }
        foreach (self::mb_entries($kind) as $slug => $entry) {
            if (isset($rows[ (string) $slug ])) {
                continue;
            }
            $settings               = $entry['settings'];
            $rows[ (string) $slug ] = [
                'slug'          => (string) $slug,
                'label'         => (string) ($settings['labels']['name'] ?? $entry['post']->post_title),
                'plugin'        => 'metabox',
                'definition_id' => (int) $entry['post']->ID,
                'active'        => 'publish' === $entry['post']->post_status,
                'links'         => array_values(array_map('strval', (array) ($settings['post_type' === $kind ? 'taxonomies' : 'types'] ?? []))),
            ];
        }
        $objects = 'post_type' === $kind ? get_post_types([ '_builtin' => false ], 'objects') : get_taxonomies([ '_builtin' => false ], 'objects');
        foreach ($objects as $slug => $object) {
            if (isset($rows[ (string) $slug ]) || in_array($slug, [ self::MB_TYPE, self::MB_TAX ], true)) {
                continue;
            }
            $rows[ (string) $slug ] = [
                'slug'   => (string) $slug,
                'label'  => (string) $object->label,
                'plugin' => 'code',
                'active' => true,
                'links'  => array_values('post_type' === $kind ? get_object_taxonomies((string) $slug) : (array) $object->object_type),
            ];
        }

        $link = 'post_type' === $kind ? 'taxonomies' : 'post_types';
        $out  = [];
        foreach ($rows as $slug => $row) {
            $row[ $link ]      = $row['links'];
            $row['registered'] = 'post_type' === $kind ? post_type_exists($slug) : taxonomy_exists($slug);
            unset($row['links']);
            $out[] = $row;
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private static function view(string $kind, string $slug): array
    {
        $found = (array) self::find($kind, $slug);
        $out   = [
            'kind'       => $kind,
            'slug'       => $slug,
            'plugin'     => $found['plugin'],
            'registered' => 'post_type' === $kind ? post_type_exists($slug) : taxonomy_exists($slug),
            'config'     => $found['config'],
        ];
        if (isset($found['post'])) {
            $out['definition_id'] = (int) $found['post']->ID;
            $out['active']        = 'publish' === $found['post']->post_status;
        }
        return $out;
    }

    // ---------------------------------------------------------------
    // Writes
    // ---------------------------------------------------------------

    /** A label as CPT UI's own save keeps it: no double quotes, HTML escaped. */
    private static function cptui_label(string $label): string
    {
        return htmlspecialchars(trim(str_replace('"', '', sanitize_text_field($label))), ENT_QUOTES);
    }

    private static function default_labels(string $slug): array
    {
        $singular = ucwords(str_replace([ '_', '-' ], ' ', $slug));
        return [ $singular . 's', $singular ];
    }

    /** CPT UI's entry with the call's settings applied. */
    private static function cptui_entry(string $kind, string $slug, array $entry, array $args): array
    {
        if ([] === $entry) {
            [ $plural, $singular ] = self::default_labels($slug);
            $entry                 = 'post_type' === $kind ? [
                'name' => $slug, 'label' => $plural, 'singular_label' => $singular, 'description' => '', 'public' => 'true',
                'publicly_queryable' => 'true', 'show_ui' => 'true', 'show_in_nav_menus' => 'true', 'delete_with_user' => 'false',
                'show_in_rest' => 'true', 'rest_base' => '', 'rest_controller_class' => '', 'rest_namespace' => '', 'has_archive' => 'false',
                'has_archive_string' => '', 'exclude_from_search' => 'false', 'capability_type' => 'post', 'hierarchical' => 'false',
                'can_export' => 'false', 'rewrite' => 'true', 'rewrite_slug' => '', 'rewrite_withfront' => 'true', 'query_var' => 'true',
                'query_var_slug' => '', 'menu_position' => '', 'show_in_menu' => 'true', 'show_in_menu_string' => '', 'menu_icon' => null,
                'register_meta_box_cb' => null, 'supports' => [ 'title', 'editor', 'thumbnail' ], 'taxonomies' => [], 'labels' => [],
                'custom_supports' => '', 'enter_title_here' => '',
            ] : [
                'name' => $slug, 'label' => $plural, 'singular_label' => $singular, 'description' => '', 'public' => 'true',
                'publicly_queryable' => 'true', 'hierarchical' => 'false', 'show_ui' => 'true', 'show_in_menu' => 'true',
                'show_in_nav_menus' => 'true', 'query_var' => 'true', 'query_var_slug' => '', 'rewrite' => 'true', 'rewrite_slug' => '',
                'rewrite_withfront' => '1', 'rewrite_hierarchical' => '0', 'show_admin_column' => 'false', 'show_in_rest' => 'true',
                'show_tagcloud' => 'false', 'sort' => 'false', 'show_in_quick_edit' => '', 'rest_base' => '', 'rest_controller_class' => '',
                'rest_namespace' => '', 'labels' => [], 'meta_box_cb' => '', 'default_term' => '', 'object_types' => [],
            ];
        }
        foreach ([ 'label', 'singular_label' ] as $key) {
            if (isset($args[ $key ])) {
                $entry[ $key ] = self::cptui_label((string) $args[ $key ]);
            }
        }
        if (isset($args['description'])) {
            $entry['description'] = sanitize_textarea_field((string) $args['description']);
        }
        if (isset($args['rewrite_slug'])) {
            $entry['rewrite_slug'] = sanitize_title((string) $args['rewrite_slug']);
        }
        foreach (self::FLAGS[ $kind ] as $flag) {
            if (isset($args[ $flag ])) {
                $entry[ $flag ] = $args[ $flag ] ? 'true' : 'false';
            }
        }
        if (isset($args['supports'])) {
            $entry['supports'] = array_values(array_unique(array_map('strval', (array) $args['supports'])));
        }
        if (isset($args['taxonomies'])) {
            $entry['taxonomies'] = array_values(array_unique(array_map('strval', (array) $args['taxonomies'])));
        }
        if (isset($args['post_types'])) {
            $entry['object_types'] = array_values(array_unique(array_map('strval', (array) $args['post_types'])));
        }
        if (isset($args['menu_icon'])) {
            $entry['menu_icon'] = (string) $args['menu_icon'];
        }
        return $entry;
    }

    /** Meta Box settings with the call's settings applied. */
    private static function mb_settings(string $kind, string $slug, array $settings, array $args): array
    {
        if ([] === $settings) {
            [ $plural, $singular ] = self::default_labels($slug);
            $settings              = [
                'slug'         => $slug,
                'labels'       => [ 'name' => $plural, 'singular_name' => $singular, 'menu_name' => $plural ],
                'public'       => true,
                'show_ui'      => true,
                'show_in_rest' => true,
                'hierarchical' => false,
            ] + ('post_type' === $kind ? [ 'has_archive' => false, 'supports' => [ 'title', 'editor', 'thumbnail' ], 'taxonomies' => [] ] : [ 'types' => [] ]);
        }
        if (isset($args['label'])) {
            $settings['labels']['name']      = sanitize_text_field((string) $args['label']);
            $settings['labels']['menu_name'] = $settings['labels']['name'];
        }
        if (isset($args['singular_label'])) {
            $settings['labels']['singular_name'] = sanitize_text_field((string) $args['singular_label']);
        }
        if (isset($args['description'])) {
            $settings['description'] = sanitize_textarea_field((string) $args['description']);
        }
        if (isset($args['rewrite_slug'])) {
            $settings['rewrite']         = (array) ($settings['rewrite'] ?? []);
            $settings['rewrite']['slug'] = sanitize_title((string) $args['rewrite_slug']);
        }
        foreach (self::FLAGS[ $kind ] as $flag) {
            if (isset($args[ $flag ])) {
                $settings[ $flag ] = (bool) $args[ $flag ];
            }
        }
        foreach ([ 'supports' => 'supports', 'taxonomies' => 'taxonomies', 'post_types' => 'types' ] as $arg => $key) {
            if (isset($args[ $arg ])) {
                $settings[ $key ] = array_values(array_unique(array_map('strval', (array) $args[ $arg ])));
            }
        }
        if (isset($args['menu_icon'])) {
            $settings['icon_type'] = 'dashicons';
            $settings['icon']      = (string) $args['menu_icon'];
            $settings['menu_icon'] = (string) $args['menu_icon'];
        }
        return $settings;
    }

    /** Store a CPT UI entry and ask CPT UI to flush rewrite rules on its next admin load, as its own save does. */
    private static function cptui_save(string $kind, string $slug, array $entry): void
    {
        $all          = get_option('post_type' === $kind ? self::CPTUI_TYPES : self::CPTUI_TAXES, []);
        $all          = is_array($all) ? $all : [];
        $all[ $slug ] = $entry;
        if ('post_type' === $kind) {
            update_option(self::CPTUI_TYPES, $all);
        } else {
            update_option(self::CPTUI_TAXES, $all);
        }
        set_transient('cptui_flush_rewrite_rules', 'true', 5 * MINUTE_IN_SECONDS);
    }

    /** @return array{result: array, operation_ids: string[]} */
    private static function create(string $kind, array $args, array $context): array
    {
        $plugin = (string) self::chosen_plugin($args);
        $slug   = (string) $args['slug'];

        if ('cptui' === $plugin) {
            $option = 'post_type' === $kind ? self::CPTUI_TYPES : self::CPTUI_TAXES;
            $run    = Safe_Mutation::run(
                [
                    'object_type'         => 'option',
                    'object_id'           => $option,
                    'session_id'          => (string) $context['session_id'],
                    'tool_name'           => (string) $context['tool_name'],
                    'args'                => [ 'operation' => $context['operation'], 'args' => $args ],
                    'extra_snapshot_data' => [ 'restore_capability' => 'manage_options' ],
                ],
                static function () use ($kind, $slug, $args): array {
                    self::cptui_save($kind, $slug, self::cptui_entry($kind, $slug, [], $args));
                    return self::view($kind, $slug) + [ 'check' => self::CHECK_NOTE ];
                }
            );
            return [ 'result' => $run['result'], 'operation_ids' => [ $run['operation_id'] ] ];
        }

        $settings = self::mb_settings($kind, $slug, [], $args);
        $id       = wp_insert_post([
            'post_type'    => 'post_type' === $kind ? self::MB_TYPE : self::MB_TAX,
            'post_status'  => 'publish',
            'post_title'   => wp_slash((string) $settings['labels']['name']),
            'post_name'    => $slug,
            'post_content' => wp_slash((string) wp_json_encode($settings, JSON_UNESCAPED_UNICODE)),
        ], true);
        if (is_wp_error($id) || 0 === (int) $id) {
            throw new Operation_Refused('insert_failed', is_wp_error($id) ? $id->get_error_message() : 'MB Custom Post Types could not store the definition.');
        }
        $op = Post_Creation_Snapshot::record((string) $context['tool_name'], [ (int) $id ], [ 'operation' => $context['operation'], 'args' => $args ], (string) $context['session_id']);

        return [ 'result' => self::view($kind, $slug) + [ 'check' => self::CHECK_NOTE ], 'operation_ids' => [ $op ] ];
    }

    /** @return array<string, mixed> */
    private static function update(string $kind, array $args): array
    {
        $slug  = (string) $args['slug'];
        $found = (array) self::find($kind, $slug);

        if ('cptui' === $found['plugin']) {
            self::cptui_save($kind, $slug, self::cptui_entry($kind, $slug, (array) $found['config'], $args));
        } else {
            $settings = self::mb_settings($kind, $slug, (array) $found['config'], $args);
            $fields   = [
                'ID'           => (int) $found['post']->ID,
                'post_content' => wp_slash((string) wp_json_encode($settings, JSON_UNESCAPED_UNICODE)),
            ];
            if (isset($args['label'])) {
                $fields['post_title'] = wp_slash((string) $settings['labels']['name']);
            }
            $out = Save_Filters::update_post($fields, true);
            if (is_wp_error($out)) {
                throw new Operation_Refused('update_failed', $out->get_error_message());
            }
            clean_post_cache((int) $found['post']->ID);
        }

        return self::view($kind, $slug) + [ 'check' => self::CHECK_NOTE ];
    }
}
