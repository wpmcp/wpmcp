<?php

namespace WPMCP\Integrations;

use WPMCP\Safety\Post_Creation_Snapshot;
use WPMCP\Safety\Save_Filters;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Custom layouts, elements and hooks of the premium theme add-ons (issue
 * #656) as ops on the theme pair, for the active theme family only:
 * Astra Pro Custom Layouts, Kadence Pro Elements and GP Premium Elements.
 * Each family gets the same six ops, named for it (list-astra-layouts,
 * get-kadence-layout, ...): list, get and the hook catalog on theme-read;
 * create, update and set-display-rules on theme-write.
 *
 * Contract, per op:
 *  - tier pro, capability edit_theme_options, and a 'requires' check: the
 *    add-on missing or inactive answers plugin_missing or plugin_inactive
 *    with the install hint and the plugins screen; the add-on's layouts
 *    module switched off answers module_disabled with where to switch it on.
 *  - writes are off until the site opts in with wpmcp_enable_theme_write,
 *    the same switch as the rest of the theme pair.
 *  - every refusal (unknown type or hook, a hook on a type that takes none,
 *    a rule naming a post type, taxonomy or role the site lacks) is decided
 *    in 'validate', before any snapshot.
 *  - create records a post creation, so rollback-operation moves the new
 *    layout to the trash; update and set-display-rules snapshot the layout
 *    post with all its meta, so rollback-operation restores it exactly.
 *
 * Code is out of scope on purpose: an Astra code-editor layout, a Kadence
 * HTML code element and a GP hook element that executes PHP keep their
 * content unread and unwritten, and these ops never switch PHP execution on.
 */
final class Theme_Layouts_Pack
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.

    /** Hooks a custom hook may never name (GP Premium's own list): they run before the page exists or around every request. */
    private const BLOCKED_HOOKS = [
        'muplugins_loaded', 'registered_taxonomy', 'plugins_loaded', 'setup_theme', 'after_setup_theme', 'init', 'widgets_init',
        'wp_loaded', 'pre_get_posts', 'wp', 'template_redirect', 'get_header', 'wp_enqueue_scripts', 'the_post', 'dynamic_sidebar',
        'get_footer', 'get_sidebar', 'wp_print_footer_scripts', 'shutdown',
    ];

    private const STATUSES = [ 'publish', 'draft', 'pending', 'private' ];

    /** Layouts scanned by a list; the add-ons themselves load at most 500 per request. */
    private const MAX_SCAN = 500;

    private const ENABLE_HINT = 'Theme writes are off until the site enables them with the wpmcp_enable_theme_write filter.';

    public static function adapter(?string $family): ?Theme_Layouts_Adapter
    {
        if ('astra' === $family) {
            return new Theme_Layouts_Astra();
        }
        if ('kadence' === $family) {
            return new Theme_Layouts_Kadence();
        }
        if ('generatepress' === $family) {
            return new Theme_Layouts_GeneratePress();
        }
        return null;
    }

    /** @return array<string,array<string,mixed>> */
    public static function operations(?string $family): array
    {
        $a = self::adapter($family);
        if (null === $a) {
            return [];
        }
        $f        = $a->family();
        $product  = $a->product();
        $base     = [
            'tier'       => 'pro',
            'capability' => 'edit_theme_options',
            'requires'   => static fn () => self::requirement($a),
        ];
        $write    = $base + [
            'mode'               => 'write',
            'enabled_by_default' => (bool) apply_filters('wpmcp_enable_theme_write', false),
            'enable_hint'        => self::ENABLE_HINT,
        ];
        $objects  = [ 'layout_id' => [ 'type' => 'post', 'own_type' => true ] ];
        $id       = [ 'type' => 'integer', 'minimum' => 1 ];
        $types    = array_keys($a->types());
        $rules    = [ 'type' => 'object', 'description' => 'Rules in the add-on\'s own shape; see list-' . $f . '-hooks' ];
        $place    = [
            'hook'        => [ 'type' => 'string', 'maxLength' => 100 ],
            'custom_hook' => [ 'type' => 'boolean' ],
            'priority'    => [ 'type' => 'integer', 'minimum' => 0, 'maximum' => 9999 ],
        ];
        $fields   = [
            'title'   => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 200 ],
            'content' => [ 'type' => 'string' ],
            'status'  => [ 'type' => 'string', 'enum' => self::STATUSES ],
        ];
        $snapshot = static fn (array $args): array => [ 'object_type' => 'post', 'object_id' => (int) $args['layout_id'] ];

        return [
            "list-{$f}-layouts"               => $base + [
                'mode'         => 'read',
                'description'  => "List {$product} layouts (id, title, status, type, hook, priority, display rules), filtered by type or status; paged",
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'type'     => [ 'type' => 'string', 'enum' => $types ],
                        'status'   => [ 'type' => 'string', 'enum' => array_merge(self::STATUSES, [ 'any' ]) ],
                        'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ],
                        'page'     => [ 'type' => 'integer', 'minimum' => 1 ],
                    ],
                ],
                'handler'      => static fn (array $args): array => self::list($a, $args),
            ],
            "get-{$f}-layout"                 => $base + [
                'mode'         => 'read',
                'objects'      => $objects,
                'description'  => "Read one {$product} layout with its content and display rules",
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'layout_id' => $id ], 'required' => [ 'layout_id' ] ],
                'validate'     => static fn (array $args): ?array => self::refusal(static fn () => self::must_layout($a, (int) $args['layout_id'])),
                'handler'      => static fn (array $args): array => [ 'layout' => self::view($a, self::must_layout($a, (int) $args['layout_id']), true) ],
            ],
            "list-{$f}-hooks"                 => $base + [
                'mode'         => 'read',
                'description'  => "List the layout types {$product} has (and which these ops write), the hooks a layout type can be placed on, and the display rule format with the add-on's own rule options",
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'type' => [ 'type' => 'string', 'enum' => $types ] ] ],
                'handler'      => static fn (array $args): array => self::hooks($a, $args),
            ],
            "create-{$f}-layout"              => $write + [
                'self_snapshotting' => true,
                'description'       => "Create a {$product} layout of a writable type. Hook types need hook (from list-{$f}-hooks, or any action name with custom_hook:true). status defaults to draft. Recorded as a creation: rollback-operation trashes it",
                'input_schema'      => [
                    'type'       => 'object',
                    'properties' => $fields + [ 'type' => [ 'type' => 'string', 'enum' => $types ] ] + $place + [ 'rules' => $rules ],
                    'required'   => [ 'title', 'type' ],
                ],
                'validate'          => static fn (array $args): ?array => self::refusal(static fn () => self::plan_create($a, $args)),
                'handler'           => static fn (array $args, array $context): array => self::create($a, $args, $context),
            ],
            "update-{$f}-layout"              => $write + [
                'objects'      => $objects,
                'description'  => "Change a {$product} layout's title, content, status, hook or priority; omitted fields are kept and the type is fixed. Snapshotted and reversible",
                'input_schema' => [
                    'type'          => 'object',
                    'properties'    => [ 'layout_id' => $id ] + $fields + $place,
                    'required'      => [ 'layout_id' ],
                    'minProperties' => 2,
                ],
                'validate'     => static fn (array $args): ?array => self::refusal(static fn () => self::plan_update($a, $args)),
                'snapshot'     => $snapshot,
                'handler'      => static fn (array $args): array => self::update($a, $args),
            ],
            "set-{$f}-layout-display-rules"   => $write + [
                'objects'      => $objects,
                'description'  => "Set where a {$product} layout shows. Each rules key passed replaces that key, keys left out are kept. Snapshotted and reversible",
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'layout_id' => $id, 'rules' => $rules + [ 'minProperties' => 1 ] ],
                    'required'   => [ 'layout_id', 'rules' ],
                ],
                'validate'     => static fn (array $args): ?array => self::refusal(static fn () => self::plan_rules($a, $args)),
                'snapshot'     => $snapshot,
                'handler'      => static fn (array $args): array => self::set_rules($a, $args),
            ],
        ];
    }

    /** @return true|array{code:string,message:string} */
    public static function requirement(Theme_Layouts_Adapter $a)
    {
        if (! $a->active()) {
            $installed = file_exists(WP_PLUGIN_DIR . '/' . $a->plugin_file());
            return [
                'code'    => $installed ? 'plugin_inactive' : 'plugin_missing',
                'message' => sprintf(
                    '%1$s is %2$s on this site. Install and activate %1$s: %3$s',
                    $a->product(),
                    $installed ? 'installed but not active' : 'not installed',
                    admin_url('plugins.php')
                ),
            ];
        }
        if (! post_type_exists($a->post_type())) {
            return [
                'code'    => 'module_disabled',
                'message' => sprintf('%s is active but its layouts module is switched off. %s', $a->product(), $a->module_hint()),
            ];
        }
        return true;
    }

    /** @return array<string,mixed> */
    private static function list(Theme_Layouts_Adapter $a, array $args): array
    {
        $status = (string) ($args['status'] ?? 'any');
        $ids    = get_posts([
            'post_type'        => $a->post_type(),
            'post_status'      => 'any' === $status ? self::STATUSES : $status,
            'numberposts'      => self::MAX_SCAN,
            'orderby'          => 'ID',
            'order'            => 'ASC',
            'fields'           => 'ids',
            'suppress_filters' => true,
        ]);
        if (isset($args['type'])) {
            $ids = array_values(array_filter($ids, static fn ($id): bool => $a->type_of((int) $id) === (string) $args['type']));
        }
        $per_page = (int) ($args['per_page'] ?? 50);
        $page     = (int) ($args['page'] ?? 1);
        $layouts  = [];
        foreach (array_slice($ids, ($page - 1) * $per_page, $per_page) as $post_id) {
            $post = get_post((int) $post_id);
            if ($post instanceof \WP_Post) {
                $layouts[] = self::view($a, $post, false);
            }
        }
        return [ 'family' => $a->family(), 'total' => count($ids), 'page' => $page, 'per_page' => $per_page, 'layouts' => $layouts ];
    }

    /** @return array<string,mixed> */
    private static function hooks(Theme_Layouts_Adapter $a, array $args): array
    {
        $types = $a->types();
        $only  = isset($args['type']) ? [ (string) $args['type'] ] : array_keys($types);
        $hooks = [];
        foreach ($only as $type) {
            if (! empty($types[ $type ]['hook'])) {
                $hooks = array_merge($hooks, $a->hook_groups($type));
            }
        }
        // One group per name, so a type listed twice (GP's two hook types) is not doubled.
        $hooks = array_values(array_column($hooks, null, 'group'));

        return [
            'family'         => $a->family(),
            'types'          => $types,
            'writable_types' => array_keys(array_filter($types, static fn (array $t): bool => $t['writable'])),
            'hooks'          => $hooks,
            'custom_hooks'   => 'Any other action name works with custom_hook:true, except hooks that run before the page exists: ' . implode(', ', self::BLOCKED_HOOKS),
            'rules'          => $a->rule_vocabulary(),
        ];
    }

    /** @return array<string,mixed> */
    private static function view(Theme_Layouts_Adapter $a, \WP_Post $post, bool $full): array
    {
        $id   = (int) $post->ID;
        $type = $a->type_of($id);
        $out  = [
            'id'       => $id,
            'title'    => $post->post_title,
            'status'   => $post->post_status,
            'type'     => $type,
            'modified' => $post->post_modified_gmt,
        ] + $a->placement($id) + [ 'rules' => $a->read_rules($id) ] + $a->extras($id);

        if ($full) {
            try {
                $key            = $a->content_key($type, $id);
                $out['content'] = 'post_content' === $key ? $post->post_content : (string) get_post_meta($id, $key, true);
            } catch (Operation_Refused $e) {
                $out['content']      = '';
                $out['content_note'] = $e->getMessage();
            }
        }
        return $out;
    }

    /** @throws Operation_Refused */
    private static function must_layout(Theme_Layouts_Adapter $a, int $id): \WP_Post
    {
        $post = get_post($id);
        if (! $post instanceof \WP_Post || $a->post_type() !== $post->post_type || 'trash' === $post->post_status) {
            throw new Operation_Refused('layout_not_found', sprintf('No %s layout with id %d.', $a->product(), $id), [ 'layout_id' => $id ]);
        }
        return $post;
    }

    /**
     * The checked write a create would make.
     *
     * @return array<string,mixed>
     * @throws Operation_Refused
     */
    private static function plan_create(Theme_Layouts_Adapter $a, array $args): array
    {
        $type  = (string) ($args['type'] ?? '');
        $types = $a->types();
        if (! isset($types[ $type ])) {
            throw self::refused('invalid_layout_type', sprintf('%s has no layout type "%s".', $a->product(), $type), [ 'types' => array_keys($types) ]);
        }
        if (! $types[ $type ]['writable']) {
            throw self::refused('layout_type_read_only', sprintf('%s "%s" layouts are read only here; edit them in wp-admin.', $a->product(), $type), [
                'writable_types' => array_keys(array_filter($types, static fn (array $t): bool => $t['writable'])),
            ]);
        }

        $plan = [
            'type'      => $type,
            'title'     => sanitize_text_field((string) $args['title']),
            'status'    => (string) ($args['status'] ?? 'draft'),
            'placement' => self::plan_placement($a, $type, $args, null),
            'rules'     => isset($args['rules']) ? $a->normalize_rules((array) $args['rules']) : [],
        ];
        if (array_key_exists('content', $args)) {
            $plan['content'] = [ $a->content_key($type), (string) $args['content'] ];
        }
        return $plan;
    }

    /**
     * @return array<string,mixed>
     * @throws Operation_Refused
     */
    private static function plan_update(Theme_Layouts_Adapter $a, array $args): array
    {
        $post = self::must_layout($a, (int) $args['layout_id']);
        $id   = (int) $post->ID;
        $type = $a->type_of($id);
        $plan = [ 'id' => $id, 'type' => $type, 'post' => [], 'placement' => self::plan_placement($a, $type, $args, $a->placement($id)) ];
        if (isset($args['title'])) {
            $plan['post']['post_title'] = sanitize_text_field((string) $args['title']);
        }
        if (isset($args['status'])) {
            $plan['post']['post_status'] = (string) $args['status'];
        }
        if (array_key_exists('content', $args)) {
            $key = $a->content_key($type, $id);
            if ('post_content' === $key) {
                $plan['post']['post_content'] = (string) $args['content'];
            } else {
                $plan['content'] = [ $key, (string) $args['content'] ];
            }
        }
        return $plan;
    }

    /**
     * @return array<string,mixed>
     * @throws Operation_Refused
     */
    private static function plan_rules(Theme_Layouts_Adapter $a, array $args): array
    {
        $post = self::must_layout($a, (int) $args['layout_id']);
        return [ 'id' => (int) $post->ID, 'type' => $a->type_of((int) $post->ID), 'rules' => $a->normalize_rules((array) $args['rules']) ];
    }

    /**
     * The placement to write, merged over what is stored, or null when the
     * call changes none.
     *
     * @param array{hook:?string,custom_hook:bool,priority:?int}|null $stored
     * @return array{hook:string,custom:bool,priority:int}|null
     * @throws Operation_Refused
     */
    private static function plan_placement(Theme_Layouts_Adapter $a, string $type, array $args, ?array $stored): ?array
    {
        $takes = ! empty($a->types()[ $type ]['hook']);
        $given = isset($args['hook']) || isset($args['priority']) || isset($args['custom_hook']);
        if (! $takes) {
            if ($given) {
                throw self::refused('hook_not_allowed', sprintf('%s "%s" layouts have a fixed place and take no hook or priority.', $a->product(), $type));
            }
            return null;
        }
        if (null !== $stored && ! $given) {
            return null;
        }

        $hook   = isset($args['hook']) ? trim((string) $args['hook']) : (string) ($stored['hook'] ?? '');
        $custom = isset($args['hook']) ? ! empty($args['custom_hook']) : (bool) ($stored['custom_hook'] ?? false);
        if ('' === $hook) {
            throw self::refused('hook_required', sprintf('%s "%s" layouts are placed on a hook; pass hook (see list-%s-hooks).', $a->product(), $type, $a->family()));
        }
        if ($custom) {
            if (1 !== preg_match('/^[A-Za-z0-9_\-\/.]{1,100}$/', $hook)) {
                throw self::refused('invalid_hook', 'A custom hook is an action name of letters, digits, _ - . and /.', [ 'hook' => $hook ]);
            }
            if (in_array($hook, self::BLOCKED_HOOKS, true)) {
                throw self::refused('blocked_hook', sprintf('Hook "%s" runs before the page exists, so a layout cannot be placed on it.', $hook), [ 'hook' => $hook ]);
            }
        } elseif (! in_array($hook, $a->hook_names($type), true)) {
            throw self::refused('unknown_hook', sprintf('%s does not list hook "%s" for "%s" layouts. Pick one from list-%s-hooks, or pass custom_hook:true for an action of your own.', $a->product(), $hook, $type, $a->family()), [ 'hook' => $hook, 'hooks' => $a->hook_names($type) ]);
        }

        $priority = isset($args['priority']) ? (int) $args['priority'] : (int) ($stored['priority'] ?? 10);
        return [ 'hook' => $hook, 'custom' => $custom, 'priority' => $priority ];
    }

    /** @return array{result:array<string,mixed>,operation_ids:string[]} */
    private static function create(Theme_Layouts_Adapter $a, array $args, array $context): array
    {
        $plan    = self::plan_create($a, $args);
        $content = $plan['content'] ?? null;
        $id      = wp_insert_post(wp_slash([
            'post_type'    => $a->post_type(),
            'post_title'   => $plan['title'],
            'post_status'  => $plan['status'],
            'post_content' => null !== $content && 'post_content' === $content[0] ? $content[1] : '',
        ]), true);
        if (is_wp_error($id)) {
            throw new Operation_Refused('layout_not_created', $id->get_error_message());
        }
        $id = (int) $id;

        try {
            $a->write_new($id, $plan['type']);
            if (null !== $plan['placement']) {
                $a->write_placement($id, $plan['type'], $plan['placement']['hook'], $plan['placement']['custom'], $plan['placement']['priority']);
            }
            if (null !== $content && 'post_content' !== $content[0]) {
                self::write_meta_content($id, $content[0], $content[1]);
            }
            $a->write_rules($id, $plan['type'], $plan['rules']);
            $operation_id = Post_Creation_Snapshot::record((string) $context['tool_name'], [ $id ], [ 'operation' => $context['operation'], 'args' => $args ], (string) $context['session_id']);
        } catch (\Throwable $e) {
            wp_delete_post($id, true);
            throw $e;
        }

        return [ 'result' => [ 'layout' => self::view($a, get_post($id), true) ], 'operation_ids' => [ $operation_id ] ];
    }

    /** @return array<string,mixed> */
    private static function update(Theme_Layouts_Adapter $a, array $args): array
    {
        $plan = self::plan_update($a, $args);
        $id   = $plan['id'];
        if ([] !== $plan['post']) {
            $done = Save_Filters::update_post(wp_slash([ 'ID' => $id ] + $plan['post']), true);
            if (is_wp_error($done)) {
                throw new Operation_Refused('layout_not_updated', $done->get_error_message());
            }
        }
        if (null !== $plan['placement']) {
            $a->write_placement($id, $plan['type'], $plan['placement']['hook'], $plan['placement']['custom'], $plan['placement']['priority']);
        }
        if (isset($plan['content'])) {
            self::write_meta_content($id, $plan['content'][0], $plan['content'][1]);
        }
        return [ 'layout' => self::view($a, get_post($id), true) ];
    }

    /** @return array<string,mixed> */
    private static function set_rules(Theme_Layouts_Adapter $a, array $args): array
    {
        $plan = self::plan_rules($a, $args);
        $a->write_rules($plan['id'], $plan['type'], $plan['rules']);
        return [ 'layout' => self::view($a, get_post($plan['id']), false) ];
    }

    /** Content kept in post meta, filtered the way the add-on filters it: as is with unfiltered_html, through wp_kses_post without. */
    private static function write_meta_content(int $id, string $key, string $content): void
    {
        $value = current_user_can('unfiltered_html') ? $content : wp_kses_post($content);
        if ('' === $value) {
            delete_post_meta($id, $key);
            return;
        }
        update_post_meta($id, $key, wp_slash($value));
    }

    /** @param array<string,mixed> $data */
    private static function refused(string $code, string $message, array $data = []): Operation_Refused
    {
        return new Operation_Refused($code, $message, $data);
    }

    private static function refusal(callable $plan): ?array
    {
        try {
            $plan();
        } catch (Operation_Refused $e) {
            return [ 'code' => $e->error_code(), 'message' => $e->getMessage(), 'data' => $e->error_data() ];
        }
        return null;
    }
}
