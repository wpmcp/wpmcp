<?php

namespace WPMCP\Integrations;

use WPMCP\MCP\Registrar;
use WPMCP\Safety\Safe_Mutation;
use WPMCP\Tools\Content\Readable_Posts;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Easy Table of Contents global settings and per-post overrides (issue
 * #904), paid-tier ops on the plugin-data pair, so they add no top-level
 * tool:
 *
 *  - easy-toc-get-settings: where the table of contents goes (enabled and
 *    auto-insert post types, position, minimum headings, word count limit,
 *    heading levels, excluded headings, restricted path, archive pages,
 *    the heading text) from the 'ez-toc-settings' option. Only these
 *    placement settings are returned, not the look and feel ones.
 *  - easy-toc-update-settings: merge any of those keys into the option,
 *    every other setting left as stored. Snapshotted on the option, so
 *    rollback-operation puts it back exactly.
 *  - easy-toc-list-posts: posts by id list, post type or category with
 *    their two per-post flags (_ez-toc-disabled, _ez-toc-insert) and
 *    whether the table of contents shows, by the plugin's own rule. Kept
 *    to the posts the caller may read (Readable_Posts).
 *  - easy-toc-set-posts: set either flag on up to MAX_POSTS posts chosen
 *    the same way, stored the way the plugin's metabox stores them ('1' or
 *    ''). Every post the call would touch must be editable or nothing is
 *    written; each changed post gets its own snapshot under one session,
 *    so rollback-session undoes the call.
 *
 * Both writes are off until the site opts in through
 * wpmcp_enable_toc_write. Settings ops take manage_options, the
 * capability the plugin's settings screen takes; post ops take edit_posts
 * plus edit_post per post, what its metabox checks. Without the plugin the
 * ops answer plugin_missing or plugin_inactive naming it. Presence is
 * filterable (wpmcp_easy_toc_active).
 */
final class Easy_TOC_Pack
{
    public const ENABLE_FILTER = 'wpmcp_enable_toc_write';

    /** Largest number of posts one easy-toc-set-posts call changes. */
    public const MAX_POSTS = 200;

    private const PLUGIN = 'Easy Table of Contents';

    private const OPTION = 'ez-toc-settings';

    private const META_DISABLED = '_ez-toc-disabled';

    private const META_INSERT = '_ez-toc-insert';

    /** Positions the plugin's settings screen offers, per 2.0.90. */
    private const POSITIONS = [ 'before', 'after', 'afterpara', 'aftercustompara', 'aftercustomimg', 'top', 'bottom' ];

    /** The placement settings these ops read and write, with the plugin's 2.0.90 defaults. */
    private const DEFAULTS = [
        'enabled_post_types'     => [ 'post', 'page' ],
        'auto_insert_post_types' => [ 'post', 'page' ],
        'position'               => 'before',
        'start'                  => 2,
        'word_count_limit'       => 0,
        'heading_levels'         => [ '1', '2', '3', '4', '5', '6' ],
        'exclude'                => '',
        'restrict_path'          => '',
        'include_homepage'       => false,
        'include_category'       => false,
        'include_tag'            => false,
        'show_heading_text'      => true,
        'heading_text'           => 'Table of Contents',
    ];

    public static function easy_toc_active(): bool
    {
        return (bool) apply_filters('wpmcp_easy_toc_active', class_exists('ezTOC') && class_exists('ezTOC_Option'));
    }

    /** Whether the plugin is usable and this install may run the paid tier. */
    public static function active(): bool
    {
        return Registrar::tier_permitted('pro') && self::easy_toc_active();
    }

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        // The literal name, not ENABLE_FILTER, so the hook stays greppable.
        $enabled  = (bool) apply_filters('wpmcp_enable_toc_write', false);
        $base     = [
            'tier'     => 'pro',
            'requires' => static fn () => self::easy_toc_active() ? true : self::refusal(),
        ];
        $types    = [ 'type' => 'array', 'minItems' => 0, 'maxItems' => 50, 'items' => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 20 ] ];
        $selector = [
            'post_ids'  => [ 'type' => 'array', 'minItems' => 1, 'maxItems' => self::MAX_POSTS, 'items' => [ 'type' => 'integer', 'minimum' => 1 ] ],
            'post_type' => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 20 ],
            'category'  => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 200, 'description' => 'category slug or term id' ],
        ];
        $write    = [
            'mode'               => 'write',
            'enabled_by_default' => $enabled,
            'opt_in_filter'      => self::ENABLE_FILTER,
        ];

        return [
            'easy-toc-get-settings'    => $base + [
                'mode'         => 'read',
                'capability'   => 'manage_options',
                'description'  => 'Easy Table of Contents placement settings: post types, position, minimum headings, heading levels, excluded headings',
                'input_schema' => [ 'type' => 'object', 'properties' => [] ],
                'handler'      => static fn (): array => self::settings_out() + [ 'positions' => self::POSITIONS, 'writes_enabled' => $enabled ],
            ],
            'easy-toc-update-settings' => $base + $write + [
                'capability'   => 'manage_options',
                'description'  => 'Change Easy Table of Contents placement settings (only the keys given). Snapshotted; undo: rollback-operation. Disabled by default (wpmcp_enable_toc_write filter)',
                'input_schema' => [
                    'type'          => 'object',
                    'properties'    => [
                        'enabled_post_types'     => $types,
                        'auto_insert_post_types' => $types,
                        'position'               => [ 'type' => 'string', 'enum' => self::POSITIONS ],
                        'start'                  => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 10 ],
                        'word_count_limit'       => [ 'type' => 'integer', 'minimum' => 0 ],
                        'heading_levels'         => [ 'type' => 'array', 'minItems' => 1, 'maxItems' => 6, 'items' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 6 ] ],
                        'exclude'                => [ 'type' => 'string', 'maxLength' => 2000 ],
                        'restrict_path'          => [ 'type' => 'string', 'maxLength' => 2000 ],
                        'include_homepage'       => [ 'type' => 'boolean' ],
                        'include_category'       => [ 'type' => 'boolean' ],
                        'include_tag'            => [ 'type' => 'boolean' ],
                        'show_heading_text'      => [ 'type' => 'boolean' ],
                        'heading_text'           => [ 'type' => 'string', 'maxLength' => 200 ],
                    ],
                    'minProperties' => 1,
                ],
                'validate'     => static fn (array $args): ?array => self::settings_refusal($args),
                'snapshot'     => static fn (): array => [ 'object_type' => 'option', 'object_id' => self::OPTION ],
                'handler'      => static fn (array $args): array => self::update_settings($args),
            ],
            'easy-toc-list-posts'      => $base + [
                'mode'         => 'read',
                'capability'   => 'edit_posts',
                'objects'      => [ 'post_ids' => [ 'type' => 'post', 'access' => 'read' ], 'post_ids.*' => [ 'type' => 'post', 'access' => 'read' ] ],
                'description'  => 'Posts with their Easy Table of Contents disable and insert flags and whether the table of contents shows',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => $selector + [
                        'page'     => [ 'type' => 'integer', 'minimum' => 1 ],
                        'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ],
                    ],
                ],
                'validate'     => static function (array $args): ?array {
                    $query = self::query_args($args);
                    return isset($query['code']) ? $query : null;
                },
                'handler'      => static fn (array $args): array => self::list_posts($args),
            ],
            'easy-toc-set-posts'       => $base + $write + [
                'capability'        => 'edit_posts',
                'objects'           => [ 'post_ids' => 'post', 'post_ids.*' => 'post' ],
                'self_snapshotting' => true,
                'description'       => 'Set the Easy Table of Contents disabled and/or insert flag on posts chosen by post_ids, post_type or category (up to 200). Each post snapshotted under one session_id; undo: rollback-session. Disabled by default (wpmcp_enable_toc_write filter)',
                'input_schema'      => [
                    'type'       => 'object',
                    'properties' => $selector + [
                        'disabled' => [ 'type' => 'boolean' ],
                        'insert'   => [ 'type' => 'boolean' ],
                    ],
                ],
                'validate'          => static fn (array $args): ?array => self::posts_refusal($args),
                'handler'           => static fn (array $args, array $context): array => self::set_posts($args, $context),
            ],
        ];
    }

    /**
     * plugin_missing or plugin_inactive naming the plugin and the plugins screen.
     *
     * @return array{code: string, message: string, data: array<string, string>}
     */
    private static function refusal(): array
    {
        $refusal         = Plugin_Refusal::make('plugin_missing', self::PLUGIN);
        $refusal['code'] = 'inactive' === $refusal['data']['state'] ? 'plugin_inactive' : 'plugin_missing';
        return $refusal;
    }

    // ---------------------------------------------------------------
    // Global settings
    // ---------------------------------------------------------------

    /** @return array<string, mixed>|null The stored option, or null before the plugin saved its defaults. */
    private static function stored(): ?array
    {
        $stored = get_option(self::OPTION, null);
        return is_array($stored) && [] !== $stored ? $stored : null;
    }

    /** @return string[] */
    private static function strings($list): array
    {
        return array_values(array_unique(array_map('strval', (array) $list)));
    }

    /** @return int[] */
    private static function levels($list): array
    {
        $levels = array_values(array_unique(array_map('intval', (array) $list)));
        sort($levels);
        return $levels;
    }

    /** @return array<string, mixed> The placement settings in their reported shape. */
    private static function normalise(array $stored): array
    {
        $values = $stored + self::DEFAULTS;
        $out    = [];
        foreach (self::DEFAULTS as $key => $default) {
            $value = $values[ $key ];
            if (is_array($default)) {
                $out[ $key ] = 'heading_levels' === $key ? self::levels($value) : self::strings($value);
            } elseif (is_bool($default)) {
                $out[ $key ] = (bool) $value;
            } elseif (is_int($default)) {
                $out[ $key ] = (int) $value;
            } else {
                $out[ $key ] = (string) $value;
            }
        }
        return $out;
    }

    /** @return array{settings: array<string, mixed>, note?: string} */
    private static function settings_out(): array
    {
        $stored = self::stored();
        $out    = [ 'settings' => self::normalise($stored ?? []) ];
        if (null === $stored) {
            $out['note'] = 'Easy Table of Contents has not saved its settings yet; these are its defaults. Open any admin screen once and it saves them.';
        }
        return $out;
    }

    /** @return array<string, mixed> The requested keys in their normalised (reported) shape. */
    private static function requested(array $args): array
    {
        return array_intersect_key(self::normalise($args), array_intersect_key($args, self::DEFAULTS));
    }

    /** @return array{code: string, message: string, data?: array}|null */
    private static function settings_refusal(array $args): ?array
    {
        $stored = self::stored();
        if (null === $stored) {
            return [
                'code'    => 'settings_not_initialised',
                'message' => 'Easy Table of Contents has not saved its settings yet. Open Settings > Table of Contents once so it stores its defaults, then try again.',
            ];
        }
        $wanted = self::requested($args);
        if ([] === $wanted) {
            return [ 'code' => 'invalid_args', 'message' => 'Pass at least one setting to change.' ];
        }
        $known = array_keys(get_post_types());
        $bad   = [];
        foreach ([ 'enabled_post_types', 'auto_insert_post_types' ] as $key) {
            $bad = array_merge($bad, array_diff($wanted[ $key ] ?? [], $known));
        }
        if ([] !== $bad) {
            $bad = array_values(array_unique($bad));
            return [
                'code'    => 'unknown_post_type',
                'message' => sprintf('Not a registered post type: %s.', implode(', ', $bad)),
                'data'    => [ 'unknown' => $bad, 'post_types' => array_values(get_post_types([ 'public' => true ])) ],
            ];
        }
        if (array_intersect_key(self::normalise($stored), $wanted) == $wanted) {
            return [ 'code' => 'no_change', 'message' => 'Those settings are already set; nothing to change.' ];
        }
        return null;
    }

    /** @return array<string, mixed> */
    private static function update_settings(array $args): array
    {
        $stored = (array) self::stored();
        foreach (self::requested($args) as $key => $value) {
            if ('heading_levels' === $key) {
                // The settings screen saves the levels as strings.
                $value = array_map('strval', $value);
            } elseif ('heading_text' === $key) {
                $value = sanitize_text_field($value);
            } elseif (in_array($key, [ 'exclude', 'restrict_path' ], true)) {
                $value = sanitize_text_field($value);
            }
            $stored[ $key ] = $value;
        }
        update_option(self::OPTION, $stored);

        return self::settings_out() + [ 'check' => 'Load a post on the front end and check the table of contents; undo with rollback-operation if it is not right.' ];
    }

    // ---------------------------------------------------------------
    // Per-post flags
    // ---------------------------------------------------------------

    /**
     * The WP_Query args the selector names, or a refusal.
     *
     * @return array<string, mixed>|array{code: string, message: string}
     */
    private static function query_args(array $args): array
    {
        $ids = array_values(array_unique(array_map('intval', (array) ($args['post_ids'] ?? []))));
        if ([] !== $ids && (isset($args['post_type']) || isset($args['category']))) {
            return [ 'code' => 'invalid_args', 'message' => 'Pass post_ids, or post_type and/or category, not both.' ];
        }
        if ([] !== $ids) {
            return [ 'post__in' => $ids, 'post_type' => 'any', 'orderby' => 'post__in' ];
        }
        if (! isset($args['post_type']) && ! isset($args['category'])) {
            return [ 'code' => 'invalid_args', 'message' => 'Choose the posts with post_ids, post_type or category.' ];
        }
        $query = [ 'post_type' => (string) ($args['post_type'] ?? 'post'), 'orderby' => 'ID', 'order' => 'ASC' ];
        if (! post_type_exists($query['post_type'])) {
            return [ 'code' => 'unknown_post_type', 'message' => sprintf('Not a registered post type: %s.', $query['post_type']) ];
        }
        if (isset($args['category'])) {
            $category = (string) $args['category'];
            $term     = ctype_digit($category) ? get_term((int) $category, 'category') : get_term_by('slug', $category, 'category');
            $query['tax_query'] = [ [ 'taxonomy' => 'category', 'field' => 'term_id', 'terms' => $term instanceof \WP_Term ? (int) $term->term_id : 0 ] ]; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
        }
        return $query;
    }

    private static function flag(int $post_id, string $key): bool
    {
        return 1 === (int) get_post_meta($post_id, $key, true);
    }

    /** Whether the plugin shows its table of contents on the post, by its own rule (ezTOC::is_eligible(), restrict_path aside). */
    private static function shown(\WP_Post $post, array $settings): bool
    {
        $insert  = in_array($post->post_type, $settings['auto_insert_post_types'], true);
        $enabled = in_array($post->post_type, $settings['enabled_post_types'], true);
        if ($insert) {
            return ! self::flag($post->ID, self::META_DISABLED);
        }
        return $enabled && self::flag($post->ID, self::META_INSERT);
    }

    /** @return array<string, mixed> */
    private static function list_posts(array $args): array
    {
        $query_args = self::query_args($args);
        $per_page = (int) ($args['per_page'] ?? 50);
        $page     = (int) ($args['page'] ?? 1);
        $query    = Readable_Posts::query($query_args + [ 'posts_per_page' => $per_page, 'paged' => $page ]);
        $settings = self::normalise(self::stored() ?? []);
        $rows     = [];
        foreach ($query->posts as $post) {
            $rows[] = [
                'id'        => (int) $post->ID,
                'title'     => get_the_title($post),
                'post_type' => $post->post_type,
                'status'    => $post->post_status,
                'disabled'  => self::flag($post->ID, self::META_DISABLED),
                'insert'    => self::flag($post->ID, self::META_INSERT),
                'toc_shown' => self::shown($post, $settings),
            ];
        }

        return [
            'posts'    => $rows,
            'total'    => (int) $query->found_posts,
            'page'     => $page,
            'per_page' => $per_page,
            'note'     => 'disabled hides the table of contents on a post of an auto-insert post type; insert shows it on a post of an enabled post type that is not auto-insert.' . ('' !== $settings['restrict_path'] ? ' restrict_path is set, which overrides both flags.' : ''),
        ];
    }

    /** @return array<string, string> meta key => '1' or '', for the flags the call sets. */
    private static function flags(array $args): array
    {
        $flags = [];
        foreach ([ 'disabled' => self::META_DISABLED, 'insert' => self::META_INSERT ] as $arg => $key) {
            if (isset($args[ $arg ])) {
                $flags[ $key ] = $args[ $arg ] ? '1' : '';
            }
        }
        return $flags;
    }

    /**
     * The posts the call names and the ones whose flags would change, or a refusal.
     *
     * @return array{ids: int[], changed: int[]}|array{code: string, message: string, data?: array}
     */
    private static function plan(array $args): array
    {
        $flags = self::flags($args);
        if ([] === $flags) {
            return [ 'code' => 'invalid_args', 'message' => 'Pass disabled, insert or both.' ];
        }
        $query_args = self::query_args($args);
        if (isset($query_args['code'])) {
            return $query_args;
        }
        $ids = array_map('intval', Readable_Posts::get_posts($query_args + [ 'posts_per_page' => self::MAX_POSTS + 1, 'fields' => 'ids' ]));
        if (isset($query_args['post__in'])) {
            $missing = array_values(array_diff($query_args['post__in'], $ids));
            if ([] !== $missing) {
                return [ 'code' => 'post_not_found', 'message' => sprintf('No readable post with id %s.', implode(', ', $missing)), 'data' => [ 'post_ids' => $missing ] ];
            }
        }
        if ([] === $ids) {
            return [ 'code' => 'no_match', 'message' => 'No posts match that post_type or category.' ];
        }
        if (count($ids) > self::MAX_POSTS) {
            return [
                'code'    => 'too_many_posts',
                'message' => sprintf('More than %d posts match; narrow it with category, or list them with easy-toc-list-posts and pass post_ids in batches.', self::MAX_POSTS),
                'data'    => [ 'max' => self::MAX_POSTS ],
            ];
        }
        $locked = array_values(array_filter($ids, static fn (int $id): bool => ! current_user_can('edit_post', $id)));
        if ([] !== $locked) {
            return [
                'code'    => 'not_editable',
                'message' => sprintf('You cannot edit %d of these posts; nothing was written. Pass post_ids you can edit.', count($locked)),
                'data'    => [ 'post_ids' => array_slice($locked, 0, 50) ],
            ];
        }
        $changed = [];
        foreach ($ids as $id) {
            foreach ($flags as $key => $value) {
                if (self::flag($id, $key) !== ('1' === $value)) {
                    $changed[] = $id;
                    break;
                }
            }
        }
        if ([] === $changed) {
            return [ 'code' => 'no_change', 'message' => 'Those posts already have those flags; nothing to change.' ];
        }
        return [ 'ids' => $ids, 'changed' => $changed ];
    }

    /** @return array{code: string, message: string, data?: array}|null */
    private static function posts_refusal(array $args): ?array
    {
        $plan = self::plan($args);
        return isset($plan['code']) ? $plan : null;
    }

    /** @return array{result: array<string, mixed>, operation_ids: string[]} */
    private static function set_posts(array $args, array $context): array
    {
        $plan = self::plan($args);
        if (isset($plan['code'])) {
            throw new Operation_Refused('posts_changed', 'The posts changed after the call was checked; nothing was written. Run it again.');
        }
        $flags = self::flags($args);
        $ids   = [];
        foreach ($plan['changed'] as $post_id) {
            $out   = Safe_Mutation::run(
                [
                    'object_type' => 'post',
                    'object_id'   => $post_id,
                    'session_id'  => (string) $context['session_id'],
                    'tool_name'   => (string) $context['tool_name'],
                    'args'        => [ 'operation' => (string) $context['operation'], 'args' => [ 'post_id' => $post_id ] + $flags ],
                ],
                static function () use ($post_id, $flags): bool {
                    foreach ($flags as $key => $value) {
                        // As the plugin's metabox saves it: true or false.
                        update_post_meta($post_id, $key, '1' === $value);
                    }
                    return true;
                }
            );
            $ids[] = $out['operation_id'];
        }

        return [
            'result'        => [
                'changed'   => $plan['changed'],
                'unchanged' => array_values(array_diff($plan['ids'], $plan['changed'])),
                'flags'     => [ 'disabled' => $args['disabled'] ?? null, 'insert' => $args['insert'] ?? null ],
            ],
            'operation_ids' => $ids,
        ];
    }
}
