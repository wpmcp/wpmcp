<?php

namespace WPMCP\Integrations;

use WPMCP\Safety\Save_Filters;
use WPMCP\Tools\Content\Readable_Posts;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The PublishPress editorial workflow plugins (issue #709) on the
 * plugin-data pair, free tier, so they add no top-level tools. Each plugin
 * is detected on its own and its ops say which one they need:
 *
 *  - PublishPress Planner (PUBLISHPRESS_VERSION): publishpress- ops. The
 *    content calendar shows the post types switched on in the calendar
 *    module's settings (the publishpress_calendar_options option, post_types
 *    => [type => 'on'|'off']) on their post_date, in every status that is
 *    not internal; custom statuses are registered post statuses (Planner 4
 *    takes them from PublishPress Statuses). get-editorial-calendar reads
 *    that view, kept to the posts the caller may read (Readable_Posts), and
 *    set-post-status moves an unpublished post between editorial statuses
 *    (draft, pending and the custom ones) under a post snapshot. Publishing,
 *    unpublishing and trashing are left to the content tools, which carry
 *    their own guards.
 *  - PublishPress Checklists (PPCH_VERSION): checklists-list-requirements
 *    reads the publishpress_checklists_checklists_options option, where each
 *    task <name> keeps <name>_rule => [post type => off|warning|only_display
 *    |block], an optional <name>_min and <name>_max, and a custom task its
 *    <name>_title (listed in custom_items).
 *  - PublishPress Future (PUBLISHPRESS_FUTURE_VERSION): future- ops. A
 *    post has at most one future action. The plugin keeps it as an Action
 *    Scheduler action plus a row in {prefix}ppfuture_actions_args (post_id,
 *    enabled, scheduled_date in UTC, args JSON: expireType, newStatus,
 *    category, categoryTaxonomy) and mirrors it in post meta
 *    (_expiration-date, _expiration-date-status 'saved', -type,
 *    -post-status, -categories, -taxonomy, -options). The writes go through
 *    the plugin's own publishpressfuture_schedule_expiration and
 *    publishpressfuture_unschedule_expiration actions, which check the
 *    caller's capability for the action and keep all three in step. The
 *    'delete' action is not offered: once it runs, the post is gone with no
 *    undo.
 *
 * Undo: every write runs under a post snapshot (fields and meta). For the
 * Future writes the meta is the plugin's own record of the schedule, so
 * after a rollback puts a post back (wpmcp_rollback_post_restored)
 * after_post_restored() schedules or unschedules the action again through
 * the plugin to match the restored meta, then puts the meta back exactly.
 *
 * The writes are off until a site opts in (wpmcp_enable_publishpress_write):
 * a status change or a scheduled change alters what the site shows. Presence
 * is filterable per plugin (wpmcp_publishpress_planner_active,
 * wpmcp_publishpress_checklists_active, wpmcp_publishpress_future_active).
 */
final class PublishPress_Pack
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reads PublishPress Future's own table live; it has no read API for a filtered list.

    public const OPT_IN_FILTER = 'wpmcp_enable_publishpress_write';

    public const CALENDAR_OPTION = 'publishpress_calendar_options';

    public const CHECKLISTS_OPTION = 'publishpress_checklists_checklists_options';

    public const SCHEDULE_HOOK = 'publishpressfuture_schedule_expiration';

    public const UNSCHEDULE_HOOK = 'publishpressfuture_unschedule_expiration';

    public const MAX_PER_PAGE = 100;

    /** Most posts one calendar read returns. */
    public const MAX_CALENDAR_POSTS = 500;

    /** Longest calendar range, in days. */
    public const MAX_CALENDAR_DAYS = 92;

    /** The Future actions offered (the plugin's 'delete' is left out: it cannot be undone once it runs). */
    public const FUTURE_ACTIONS = [ 'change-status', 'stick', 'unstick', 'category', 'category-add', 'category-remove', 'category-remove-all' ];

    /** Statuses a scheduled change-status may move a post to, as the plugin's own editor offers. */
    public const FUTURE_STATUSES = [ 'draft', 'pending', 'private', 'trash' ];

    /** The post meta PublishPress Future keeps its schedule in. */
    private const FUTURE_META = [ '_expiration-date', '_expiration-date-status', '_expiration-date-type', '_expiration-date-post-status', '_expiration-date-categories', '_expiration-date-taxonomy', '_expiration-date-options' ];

    /** Checklists rule => what its settings screen calls it. */
    private const LEVELS = [ 'off' => 'disabled', 'warning' => 'recommended', 'only_display' => 'recommended', 'block' => 'required' ];

    /** plugin key => [name, slug, constant]; presence is filterable per plugin in plugin_active(). */
    private const PLUGINS = [
        'planner'    => [ 'PublishPress Planner', 'publishpress', 'PUBLISHPRESS_VERSION' ],
        'checklists' => [ 'PublishPress Checklists', 'publishpress-checklists', 'PPCH_VERSION' ],
        'future'     => [ 'PublishPress Future', 'post-expirator', 'PUBLISHPRESS_FUTURE_VERSION' ],
    ];

    public static function plugin_active(string $plugin): bool
    {
        $present = defined(self::PLUGINS[ $plugin ][2]);
        switch ($plugin) {
            case 'planner':
                return (bool) apply_filters('wpmcp_publishpress_planner_active', $present);
            case 'checklists':
                return (bool) apply_filters('wpmcp_publishpress_checklists_active', $present);
            default:
                return (bool) apply_filters('wpmcp_publishpress_future_active', $present);
        }
    }

    public static function active(): bool
    {
        return self::plugin_active('planner') || self::plugin_active('checklists') || self::plugin_active('future');
    }

    /** @return true|array{code: string, message: string, data: array} */
    public static function presence(string $plugin)
    {
        if (self::plugin_active($plugin)) {
            return true;
        }
        [ $name, $slug ] = self::PLUGINS[ $plugin ];
        $installed       = defined('WP_PLUGIN_DIR') && file_exists(WP_PLUGIN_DIR . '/' . $slug . '/' . $slug . '.php');
        $url             = admin_url('plugins.php');

        return [
            'code'    => $installed ? 'plugin_inactive' : 'plugin_missing',
            'message' => sprintf(
                '%1$s is %2$s on this site. Install and activate %1$s (%3$s), then try again.',
                $name,
                $installed ? 'installed but not active' : 'not installed',
                $url
            ),
            'data'    => [ 'plugin' => $slug, 'plugins_url' => $url ],
        ];
    }

    /** @return true|array{code: string, message: string, data?: array} */
    private static function future_ready()
    {
        $present = self::presence('future');
        if (true !== $present) {
            return $present;
        }
        global $wpdb;
        if (null === $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like(self::future_table())))) {
            return [
                'code'    => 'publishpress_future_table_missing',
                'message' => sprintf('PublishPress Future\'s table %s does not exist on this site. Deactivate and reactivate PublishPress Future so it creates it, then try again.', self::future_table()),
                'data'    => [ 'plugins_url' => admin_url('plugins.php') ],
            ];
        }
        return true;
    }

    public static function future_table(): string
    {
        global $wpdb;
        return $wpdb->get_blog_prefix() . 'ppfuture_actions_args';
    }

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        return array_merge(self::planner_operations(), self::checklists_operations(), self::future_operations());
    }

    /** @return array<string, mixed> the parts every write shares */
    private static function write_base(callable $requires): array
    {
        return [
            'capability'         => 'edit_posts',
            'requires'           => $requires,
            'mode'               => 'write',
            'enabled_by_default' => (bool) apply_filters('wpmcp_enable_publishpress_write', false),
            'opt_in_filter'      => self::OPT_IN_FILTER,
            'objects'            => [ 'id' => [ 'type' => 'post', 'access' => 'write' ] ],
            'snapshot'           => static fn (array $args): array => [ 'object_type' => 'post', 'object_id' => (int) $args['id'] ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function planner_operations(): array
    {
        $date     = [ 'type' => 'string', 'pattern' => '^\d{4}-\d{2}-\d{2}$' ];
        $requires = static fn () => self::presence('planner');

        return [
            'publishpress-get-editorial-calendar' => [
                'capability'   => 'edit_posts',
                'requires'     => $requires,
                'mode'         => 'read',
                'description'  => 'PublishPress Planner content calendar: the posts of the calendar\'s post types by day (post date) from date_from to date_to (YYYY-MM-DD, default today and the next 27 days, at most 92 days), in every status but trash, each with id, title, post type, status and its label, time and author id; plus the statuses the site has (custom ones included). post_type and status narrow it. Only posts you may read',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'date_from' => $date,
                        'date_to'   => $date,
                        'post_type' => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 20 ],
                        'status'    => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 20 ],
                    ],
                ],
                'validate'     => static fn (array $args): ?array => self::calendar_refusal($args),
                'handler'      => static fn (array $args): array => self::calendar($args),
            ],
            'publishpress-set-post-status'        => array_merge(self::write_base($requires), [
                'description'  => 'Move an unpublished post to another editorial status: draft, pending or a custom status the site registers (PublishPress Planner and Statuses). Publishing, scheduling, making private and trashing are refused here (use the content tools), and so is a post that is already published. Undo: rollback-operation',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'id' => [ 'type' => 'integer', 'minimum' => 1 ], 'status' => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 20 ] ],
                    'required'   => [ 'id', 'status' ],
                ],
                'validate'     => static fn (array $args): ?array => self::status_refusal((int) $args['id'], (string) $args['status']),
                'handler'      => static fn (array $args): array => self::set_status((int) $args['id'], (string) $args['status']),
            ]),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function checklists_operations(): array
    {
        return [
            'checklists-list-requirements' => [
                'capability'   => 'edit_posts',
                'requires'     => static fn () => self::presence('checklists'),
                'mode'         => 'read',
                'description'  => 'PublishPress Checklists tasks per post type: requirement name, rule (block, warning, only_display, off) and level (required, recommended, disabled), min and max where the task counts something, and the title of a custom task. Disabled tasks are left out unless include_disabled. post_type narrows it',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'post_type'        => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 20 ],
                        'include_disabled' => [ 'type' => 'boolean' ],
                    ],
                ],
                'handler'      => static fn (array $args): array => self::requirements($args),
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function future_operations(): array
    {
        $id       = [ 'type' => 'integer', 'minimum' => 1 ];
        $date     = [ 'type' => 'string', 'pattern' => '^\d{4}-\d{2}-\d{2}$' ];
        $requires = static fn () => self::future_ready();

        return [
            'future-list-scheduled-changes' => [
                'capability'   => 'edit_posts',
                'requires'     => $requires,
                'mode'         => 'read',
                'description'  => 'PublishPress Future scheduled post changes, soonest first: post id, title, type and status, action (change-status, stick, unstick, category, category-add, category-remove, category-remove-all, delete), new status, taxonomy and term ids, and when (UTC and site time). Filter by post_type, date_from and date_to (YYYY-MM-DD, site time). page and per_page (max 100). Only posts you may read',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'post_type' => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 20 ],
                        'date_from' => $date,
                        'date_to'   => $date,
                        'page'      => [ 'type' => 'integer', 'minimum' => 1 ],
                        'per_page'  => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PER_PAGE ],
                    ],
                ],
                'handler'      => static fn (array $args): array => self::scheduled_changes($args),
            ],
            'future-schedule-post-change'   => array_merge(self::write_base($requires), [
                'description'  => 'Schedule a PublishPress Future change for a post at date (YYYY-MM-DD HH:MM, site time, in the future): change-status (new_status draft, pending, private or trash), stick, unstick, or category, category-add, category-remove (taxonomy and terms, term ids) or category-remove-all. It replaces the post\'s earlier scheduled change. Permanent delete is not offered. Undo: rollback-operation puts the earlier schedule back',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'id'         => $id,
                        'date'       => [ 'type' => 'string', 'pattern' => '^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$' ],
                        'action'     => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 30 ],
                        'new_status' => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 20 ],
                        'taxonomy'   => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 32 ],
                        'terms'      => [ 'type' => 'array', 'items' => $id, 'maxItems' => 50 ],
                    ],
                    'required'   => [ 'id', 'date', 'action' ],
                ],
                'validate'     => static fn (array $args): ?array => self::schedule_refusal($args),
                'handler'      => static fn (array $args): array => self::schedule($args),
            ]),
            'future-cancel-post-change'     => array_merge(self::write_base($requires), [
                'description'  => 'Cancel the PublishPress Future change scheduled for a post. Undo: rollback-operation schedules it again',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => $id ], 'required' => [ 'id' ] ],
                'validate'     => static fn (array $args): ?array => self::cancel_refusal((int) $args['id']),
                'handler'      => static fn (array $args): array => self::cancel((int) $args['id']),
            ]),
        ];
    }

    // ------------------------------------------------------------ helpers

    private static function refusal(string $code, string $message, array $data = []): array
    {
        return [ 'code' => $code, 'message' => $message, 'data' => $data ];
    }

    /** @return array<string, mixed>|null */
    private static function post_refusal(int $id): ?array
    {
        $post = get_post($id);
        if (! $post instanceof \WP_Post || in_array($post->post_type, [ 'revision', 'attachment', 'nav_menu_item' ], true)) {
            return self::refusal('post_not_found', sprintf('%d is not a post.', $id), [ 'id' => $id ]);
        }
        if (! current_user_can('edit_post', $id)) {
            return self::refusal('operation_denied', sprintf('You are not allowed to edit post %d.', $id), [ 'reason' => 'capability', 'id' => $id ]);
        }
        return null;
    }

    private static function status_label(string $status): string
    {
        $object = get_post_status_object($status);
        return $object instanceof \stdClass && isset($object->label) ? (string) $object->label : $status;
    }

    /** @return array<string, mixed> */
    private static function option(string $name): array
    {
        $value = get_option($name, []);
        return is_object($value) ? get_object_vars($value) : (array) $value;
    }

    /** A site-time 'Y-m-d H:i:s' as the timestamp PublishPress Future's schedule action takes (site time read as UTC). */
    private static function local_timestamp(string $local): int
    {
        return (new \DateTimeImmutable($local, new \DateTimeZone('UTC')))->getTimestamp();
    }

    // ------------------------------------------------------------ Planner

    /** @return string[] the post types the calendar shows */
    private static function calendar_post_types(): array
    {
        $types = (array) ( self::option(self::CALENDAR_OPTION)['post_types'] ?? [ 'post' => 'on' ] );
        $on    = [];
        foreach ($types as $type => $state) {
            if ('on' === $state && post_type_exists((string) $type)) {
                $on[] = (string) $type;
            }
        }
        return $on;
    }

    /** @return string[] */
    private static function calendar_statuses(): array
    {
        return array_values(array_diff(get_post_stati([ 'internal' => false ]), [ 'trash', 'auto-draft', 'inherit' ]));
    }

    /** @return array{0: string, 1: string} */
    private static function calendar_range(array $args): array
    {
        $from = (string) ( $args['date_from'] ?? wp_date('Y-m-d') );
        $to   = (string) ( $args['date_to'] ?? gmdate('Y-m-d', (int) strtotime($from . ' +27 days')) );
        return [ $from, $to ];
    }

    private static function calendar_refusal(array $args): ?array
    {
        [ $from, $to ] = self::calendar_range($args);
        $start         = strtotime($from . ' 00:00:00 UTC');
        $end           = strtotime($to . ' 00:00:00 UTC');
        if (false === $start || false === $end || $end < $start) {
            return self::refusal('invalid_params', 'date_to must be a date on or after date_from.');
        }
        if (( $end - $start ) / DAY_IN_SECONDS >= self::MAX_CALENDAR_DAYS) {
            return self::refusal('invalid_params', sprintf('The calendar range is at most %d days.', self::MAX_CALENDAR_DAYS));
        }
        if (isset($args['post_type']) && ! in_array($args['post_type'], self::calendar_post_types(), true)) {
            return self::refusal('invalid_params', sprintf('%s is not one of the calendar\'s post types (%s).', $args['post_type'], implode(', ', self::calendar_post_types())));
        }
        if (isset($args['status']) && ! in_array($args['status'], self::calendar_statuses(), true)) {
            return self::refusal('invalid_params', sprintf('%s is not a post status the calendar shows.', $args['status']));
        }
        return null;
    }

    /** @return array<string, mixed> */
    private static function calendar(array $args): array
    {
        [ $from, $to ] = self::calendar_range($args);
        $types         = isset($args['post_type']) ? [ (string) $args['post_type'] ] : self::calendar_post_types();
        $statuses      = isset($args['status']) ? [ (string) $args['status'] ] : self::calendar_statuses();
        $status_list   = array_map(static fn (string $s): array => [ 'slug' => $s, 'label' => self::status_label($s) ], self::calendar_statuses());
        $out           = [ 'from' => $from, 'to' => $to, 'post_types' => $types, 'statuses' => $status_list, 'days' => [], 'total' => 0, 'truncated' => false ];
        if ([] === $types) {
            return $out;
        }

        $q = Readable_Posts::query([
            'post_type'      => $types,
            'post_status'    => $statuses,
            'date_query'     => [ [ 'after' => $from . ' 00:00:00', 'before' => $to . ' 23:59:59', 'inclusive' => true, 'column' => 'post_date' ] ],
            'orderby'        => [ 'date' => 'ASC', 'ID' => 'ASC' ],
            'posts_per_page' => self::MAX_CALENDAR_POSTS,
            'no_found_rows'  => false,
        ]);
        $days = [];
        foreach ($q->posts as $post) {
            $day            = substr((string) $post->post_date, 0, 10);
            $days[ $day ][] = [
                'id'           => (int) $post->ID,
                'title'        => (string) $post->post_title,
                'post_type'    => (string) $post->post_type,
                'status'       => (string) $post->post_status,
                'status_label' => self::status_label((string) $post->post_status),
                'time'         => substr((string) $post->post_date, 11, 5),
                'author_id'    => (int) $post->post_author,
            ];
        }
        foreach ($days as $day => $posts) {
            $out['days'][] = [ 'date' => (string) $day, 'posts' => $posts ];
        }
        $out['total']     = (int) $q->found_posts;
        $out['truncated'] = $out['total'] > count($q->posts);
        return $out;
    }

    private static function status_refusal(int $id, string $status): ?array
    {
        $refused = self::post_refusal($id);
        if (null !== $refused) {
            return $refused;
        }
        $object = get_post_status_object($status);
        if (! $object instanceof \stdClass) {
            return self::refusal('unknown_status', sprintf('%s is not a post status this site registers.', $status), [ 'status' => $status ]);
        }
        if (! empty($object->internal) || ! empty($object->public) || ! empty($object->private) || 'future' === $status) {
            return self::refusal('status_not_allowed', sprintf('%s is not an editorial status. Publish, schedule, make private or trash a post with the content tools.', $status), [ 'status' => $status ]);
        }
        $from = (string) get_post_status($id);
        if (in_array($from, [ 'publish', 'future', 'private' ], true)) {
            return self::refusal('post_published', sprintf('Post %d is %s. Unpublish it with the content tools first.', $id, $from), [ 'id' => $id, 'status' => $from ]);
        }
        if ($from === $status) {
            return self::refusal('no_change', sprintf('Post %d is already %s.', $id, $status), [ 'id' => $id ]);
        }
        return null;
    }

    /** @return array<string, mixed> */
    private static function set_status(int $id, string $status): array
    {
        $out = Save_Filters::set_post_status($id, $status, true);
        if (is_wp_error($out)) {
            throw new Operation_Refused('update_failed', $out->get_error_message());
        }
        clean_post_cache($id);
        if ($status !== get_post_status($id)) {
            throw new Operation_Refused('update_failed', sprintf('Post %d did not change to %s.', $id, $status));
        }
        $post = get_post($id);
        return [
            'post' => [
                'id'           => $id,
                'title'        => (string) $post->post_title,
                'post_type'    => (string) $post->post_type,
                'status'       => $status,
                'status_label' => self::status_label($status),
            ],
        ];
    }

    // ------------------------------------------------------------ Checklists

    /** @return array<string, mixed> */
    private static function requirements(array $args): array
    {
        $options = self::option(self::CHECKLISTS_OPTION);
        $custom  = array_map('strval', (array) ( $options['custom_items'] ?? [] ));
        $all     = ! empty($args['include_disabled']);
        $only    = isset($args['post_type']) ? (string) $args['post_type'] : null;
        $out     = [];
        foreach ($options as $key => $rules) {
            if (! is_string($key) || '_rule' !== substr($key, -5) || ! is_array($rules) && ! is_object($rules)) {
                continue;
            }
            $name = substr($key, 0, -5);
            foreach ((array) $rules as $type => $rule) {
                $type = (string) $type;
                $rule = (string) $rule;
                if (null !== $only && $only !== $type || ! $all && ( 'off' === $rule || '' === $rule )) {
                    continue;
                }
                $row = [ 'requirement' => $name, 'rule' => $rule, 'level' => self::LEVELS[ $rule ] ?? $rule ];
                foreach ([ 'min', 'max' ] as $bound) {
                    $value = ( (array) ( $options[ $name . '_' . $bound ] ?? [] ) )[ $type ] ?? null;
                    if (null !== $value && '' !== $value) {
                        $row[ $bound ] = (int) $value;
                    }
                }
                if (in_array($name, $custom, true)) {
                    $row['custom'] = true;
                    $row['title']  = (string) ( ( (array) ( $options[ $name . '_title' ] ?? [] ) )[ $type ] ?? '' );
                }
                $out[ $type ][] = $row;
            }
        }
        if (null !== $only && ! isset($out[ $only ])) {
            $out[ $only ] = [];
        }
        ksort($out);
        return [ 'post_types' => $out ];
    }

    // ------------------------------------------------------------ Future

    /** @return array<string, mixed>|null the post's enabled action row */
    private static function enabled_row(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE post_id = %d AND enabled = 1 ORDER BY id DESC LIMIT 1', self::future_table(), $id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed> */
    private static function change_view(array $row, ?\WP_Post $post = null): array
    {
        $args  = json_decode((string) $row['args'], true);
        $args  = is_array($args) ? $args : [];
        $type  = (string) ( $args['expireType'] ?? '' );
        $state = (string) ( $args['newStatus'] ?? '' );
        if (in_array($type, [ 'draft', 'private', 'trash' ], true)) {
            $state = $type;
            $type  = 'change-status';
        }
        $terms = $args['category'] ?? [];
        $post  = $post ?? get_post((int) $row['post_id']);
        return [
            'post_id'             => (int) $row['post_id'],
            'title'               => $post instanceof \WP_Post ? (string) $post->post_title : '',
            'post_type'           => $post instanceof \WP_Post ? (string) $post->post_type : '',
            'post_status'         => $post instanceof \WP_Post ? (string) $post->post_status : '',
            'action'              => $type,
            'new_status'          => $state,
            'taxonomy'            => (string) ( $args['categoryTaxonomy'] ?? '' ),
            'terms'               => array_values(array_map('intval', is_array($terms) ? $terms : array_filter(explode(',', (string) $terms)))),
            'scheduled_date'      => (string) $row['scheduled_date'],
            'scheduled_date_site' => get_date_from_gmt((string) $row['scheduled_date']),
        ];
    }

    /** @return array<string, mixed> */
    private static function scheduled_changes(array $args): array
    {
        global $wpdb;
        $where  = [ 'a.enabled = 1' ];
        $params = [ self::future_table(), $wpdb->posts ];
        if (isset($args['post_type'])) {
            $where[]  = 'p.post_type = %s';
            $params[] = (string) $args['post_type'];
        }
        if (isset($args['date_from'])) {
            $where[]  = 'a.scheduled_date >= %s';
            $params[] = get_gmt_from_date($args['date_from'] . ' 00:00:00');
        }
        if (isset($args['date_to'])) {
            $where[]  = 'a.scheduled_date <= %s';
            $params[] = get_gmt_from_date($args['date_to'] . ' 23:59:59');
        }
        $sql = 'SELECT a.* FROM %i a INNER JOIN %i p ON p.ID = a.post_id WHERE ' . implode(' AND ', $where) . ' ORDER BY a.scheduled_date ASC, a.id ASC LIMIT 5000';
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- the SQL is built from literal fragments with %i and %s placeholders only.
        $rows = (array) $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        $by   = [];
        foreach ($rows as $row) {
            $by[ (int) $row['post_id'] ] = $by[ (int) $row['post_id'] ] ?? $row;
        }
        if ([] === $by) {
            return [ 'changes' => [], 'total' => 0 ];
        }
        $q = Readable_Posts::query([
            'post_type'      => 'any',
            'post_status'    => 'any',
            'post__in'       => array_keys($by),
            'orderby'        => 'post__in',
            'posts_per_page' => (int) ( $args['per_page'] ?? 20 ),
            'paged'          => (int) ( $args['page'] ?? 1 ),
        ]);
        return [
            'changes' => array_map(static fn (\WP_Post $p): array => self::change_view($by[ (int) $p->ID ], $p), $q->posts),
            'total'   => (int) $q->found_posts,
        ];
    }

    private static function schedule_refusal(array $args): ?array
    {
        $id      = (int) $args['id'];
        $refused = self::post_refusal($id);
        if (null !== $refused) {
            return $refused;
        }
        $action = (string) $args['action'];
        if (! in_array($action, self::FUTURE_ACTIONS, true)) {
            return self::refusal('invalid_params', sprintf('action must be one of %s.', implode(', ', self::FUTURE_ACTIONS)), [ 'action' => $action ]);
        }
        $utc = (int) get_gmt_from_date((string) $args['date'] . ':00', 'U');
        if ($utc <= time()) {
            return self::refusal('invalid_params', 'date must be in the future (site time).', [ 'date' => $args['date'] ]);
        }
        if ('change-status' === $action && ! in_array($args['new_status'] ?? null, self::FUTURE_STATUSES, true)) {
            return self::refusal('invalid_params', sprintf('change-status needs new_status, one of %s.', implode(', ', self::FUTURE_STATUSES)));
        }
        if (in_array($action, [ 'category', 'category-add', 'category-remove' ], true)) {
            $taxonomy = (string) ( $args['taxonomy'] ?? '' );
            if ('' === $taxonomy || ! is_object_in_taxonomy((string) get_post_type($id), $taxonomy)) {
                return self::refusal('invalid_params', sprintf('%s needs taxonomy, one this post\'s type uses.', $action));
            }
            if (empty($args['terms'])) {
                return self::refusal('invalid_params', sprintf('%s needs terms (term ids).', $action));
            }
            foreach ((array) $args['terms'] as $term_id) {
                $term = get_term((int) $term_id, $taxonomy);
                if (! $term instanceof \WP_Term) {
                    return self::refusal('invalid_params', sprintf('%d is not a term of taxonomy "%s".', (int) $term_id, $taxonomy), [ 'term_id' => (int) $term_id ]);
                }
            }
        }
        return null;
    }

    /** @return array<string, mixed> */
    private static function schedule(array $args): array
    {
        $id     = (int) $args['id'];
        $action = (string) $args['action'];
        $local  = (string) $args['date'] . ':00';
        $opts   = [
            'expireType'       => $action,
            'newStatus'        => 'change-status' === $action ? (string) $args['new_status'] : '',
            'category'         => in_array($action, [ 'category', 'category-add', 'category-remove' ], true) ? array_values(array_map('intval', (array) $args['terms'])) : [],
            'categoryTaxonomy' => in_array($action, [ 'category', 'category-add', 'category-remove', 'category-remove-all' ], true) ? (string) ( $args['taxonomy'] ?? 'category' ) : '',
        ];
        $before = self::enabled_row($id);
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- PublishPress Future's own action, which schedules through its own checks.
        do_action('publishpressfuture_schedule_expiration', $id, self::local_timestamp($local), $opts);
        $row = self::enabled_row($id);
        if (null === $row || ( null !== $before && (int) $before['id'] === (int) $row['id'] ) || get_gmt_from_date($local) !== (string) $row['scheduled_date']) {
            throw new Operation_Refused('schedule_failed', sprintf('PublishPress Future did not schedule the change for post %d. Check that you may make this change and that PublishPress Future is enabled for this post type.', $id), [ 'id' => $id ]);
        }
        return [ 'change' => self::change_view($row) ];
    }

    private static function cancel_refusal(int $id): ?array
    {
        $refused = self::post_refusal($id);
        if (null !== $refused) {
            return $refused;
        }
        if (null === self::enabled_row($id)) {
            return self::refusal('no_change', sprintf('Post %d has no scheduled change.', $id), [ 'id' => $id ]);
        }
        return null;
    }

    /** @return array<string, mixed> */
    private static function cancel(int $id): array
    {
        $row = (array) self::enabled_row($id);
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- PublishPress Future's own action, which schedules through its own checks.
        do_action('publishpressfuture_unschedule_expiration', $id);
        if (null !== self::enabled_row($id)) {
            throw new Operation_Refused('cancel_failed', sprintf('PublishPress Future did not cancel the change scheduled for post %d.', $id), [ 'id' => $id ]);
        }
        return [ 'canceled' => self::change_view($row) ];
    }

    /**
     * After a rollback put a post back (wpmcp_rollback_post_restored): when
     * PublishPress Future's meta and its scheduled action disagree, schedule
     * or unschedule through the plugin to match the restored meta, then put
     * the meta back as the rollback left it.
     *
     * @param mixed $post_id
     * @param mixed $unused
     */
    public static function after_post_restored($post_id, $unused = null): void
    {
        $id = (int) $post_id;
        if ($id <= 0 || ! self::plugin_active('future') || true !== self::future_ready()) {
            return;
        }
        $utc       = (int) get_post_meta($id, '_expiration-date', true);
        $scheduled = $utc > 0 && in_array(get_post_meta($id, '_expiration-date-status', true), [ 'saved', '1', 1 ], true);
        $row       = self::enabled_row($id);
        $type      = (string) get_post_meta($id, '_expiration-date-type', true);
        $state     = (string) get_post_meta($id, '_expiration-date-post-status', true);
        if (in_array($type, [ 'draft', 'private', 'trash' ], true)) {
            $state = $type;
            $type  = 'change-status';
        }
        if ($scheduled && null !== $row) {
            $view = self::change_view($row);
            if (gmdate('Y-m-d H:i:s', $utc) === $view['scheduled_date'] && $type === $view['action'] && $state === $view['new_status']) {
                return;
            }
        }
        if (! $scheduled && null === $row) {
            return;
        }

        $kept = [];
        foreach (self::FUTURE_META as $key) {
            $kept[ $key ] = get_post_meta($id, $key, false);
        }
        if ($scheduled) {
            $opts = [
                'expireType'       => $type,
                'newStatus'        => $state,
                'category'         => get_post_meta($id, '_expiration-date-categories', true),
                'categoryTaxonomy' => (string) get_post_meta($id, '_expiration-date-taxonomy', true),
            ];
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- PublishPress Future's own action, which schedules through its own checks.
            do_action('publishpressfuture_schedule_expiration', $id, self::local_timestamp(get_date_from_gmt(gmdate('Y-m-d H:i:s', $utc))), $opts);
        } else {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- PublishPress Future's own action, which schedules through its own checks.
            do_action('publishpressfuture_unschedule_expiration', $id);
        }
        foreach ($kept as $key => $values) {
            delete_post_meta($id, $key);
            foreach ($values as $value) {
                add_post_meta($id, $key, wp_slash($value));
            }
        }
    }
}
