<?php

namespace WPMCP\Integrations;

use WPMCP\Safety\Redirection_Item_Snapshot;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Redirection plugin adapter (issue #300, first slice): reads and writes the
 * plugin's own redirects as free ops on the theme dispatcher pair, so the
 * adapter adds no top-level tools.
 *
 * Reads: list-redirection-groups, list-redirection-redirects (filter by group,
 * status or a search string, paged) and get-redirection-redirect. Writes:
 * create-, update-, enable-, disable- and delete-redirection-redirect.
 *
 * Every write is snapshotted as a 'redirection_item' row image
 * (Redirection_Item_Snapshot), so rollback-operation restores exactly: an
 * edit goes back in place, a deleted redirect returns at its own id, and a
 * created one is removed again. That exactness is why the writes are
 * prepared SQL on the plugin's table rather than Red_Item::create(): the
 * model picks the new row's id itself, after the snapshot, so a create could
 * not name the row its undo must remove. The rows are written in the shape
 * the model writes (the match_url Redirection looks a request up by comes
 * from its own Red_Url_Match when the plugin is loaded), and afterwards the
 * plugin's module flush and redirect cache key move on exactly as its own
 * writes do.
 *
 * Scope of the writes: plain URL matches (match_type 'url', not regex) whose
 * action is a redirect to a URL or an HTTP error. A conditional redirect
 * (login state, referrer, cookie and the like) is listed and can be enabled,
 * disabled or deleted, but update refuses it rather than flattening
 * conditions it cannot represent. Its stored match data is serialized PHP,
 * which is never unserialized or echoed.
 *
 * Guards, all decided before any snapshot: the source is a site-relative
 * path (never the site root, never a scheme or control characters), the
 * target is a site-relative path or an absolute http(s) URL that
 * esc_url_raw() leaves unchanged, a source already owned by another plain
 * redirect is refused, and a redirect that would loop through the site's
 * enabled Redirection redirects (itself included) is refused with the
 * cycle reported.
 *
 * Every op declares a 'requires' check, so an inactive plugin is skipped
 * cleanly: the ops stay documented in list-operations (dependency_met:false)
 * and answer redirection_inactive without touching anything. Presence is
 * filterable through wpmcp_redirection_active. Every op runs at
 * manage_options, the capability Redirection gates its own admin behind.
 */
final class Redirection_Pack
{
    /** Redirect codes the url action accepts (Red_Item_Sanitize::is_valid_redirect_code). */
    private const REDIRECT_CODES = [ 301, 302, 303, 307, 308 ];

    /** Error codes the error action accepts (Red_Item_Sanitize::is_valid_error_code). */
    private const ERROR_CODES = [ 400, 401, 403, 404, 410, 418, 451, 500, 501, 502, 503, 504 ];

    /** Redirection's WordPress module; its groups are where new redirects go by default. */
    private const WORDPRESS_MODULE = 1;

    /** Longest source or target accepted (the match_url column is VARCHAR(2000)). */
    private const MAX_URL_LENGTH = 2000;

    /** Hops followed before a chain is treated as a loop. */
    private const MAX_CHAIN_DEPTH = 10;

    private const MAX_PER_PAGE = 100;

    /** @return array<string,array<string,mixed>> */
    public static function operations(): array
    {
        $requires = static fn () => self::requirement();
        $id_only  = [
            'type'       => 'object',
            'properties' => [ 'id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
            'required'   => [ 'id' ],
        ];
        $item     = static fn (array $args, array $context) => (int) $context['target']['object_id'];
        $target   = static fn (array $args): array => [ 'object_type' => Redirection_Item_Snapshot::TYPE, 'object_id' => (int) $args['id'] ];

        return [
            'list-redirection-groups'      => [
                'mode'         => 'read',
                'capability'   => 'manage_options',
                'description'  => 'List the Redirection plugin\'s groups (id, name, module, enabled, redirect count)',
                'input_schema' => [ 'type' => 'object', 'properties' => [] ],
                'requires'     => $requires,
                'handler'      => static fn (): array => [ 'groups' => self::groups() ],
            ],
            'list-redirection-redirects'   => [
                'mode'         => 'read',
                'capability'   => 'manage_options',
                'description'  => 'List Redirection plugin redirects (source, target, action type and code, match type, group, enabled, hits). Filter by group_id, status or a search over source, target and title; paged',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'group_id' => [ 'type' => 'integer', 'minimum' => 1 ],
                        'status'   => [ 'type' => 'string', 'enum' => [ 'enabled', 'disabled' ] ],
                        'search'   => [ 'type' => 'string', 'maxLength' => 200 ],
                        'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PER_PAGE ],
                        'page'     => [ 'type' => 'integer', 'minimum' => 1 ],
                    ],
                ],
                'requires'     => $requires,
                'handler'      => static fn (array $args): array => self::list_redirects($args),
            ],
            'get-redirection-redirect'     => [
                'mode'         => 'read',
                'capability'   => 'manage_options',
                'description'  => 'Read one Redirection plugin redirect by id',
                'input_schema' => $id_only,
                'requires'     => $requires,
                'validate'     => static fn (array $args): ?array => self::exists_refusal((int) $args['id']),
                'handler'      => static fn (array $args): array => [ 'redirect' => self::view(self::must_row((int) $args['id'])) ],
            ],
            'create-redirection-redirect'  => [
                'mode'         => 'write',
                'capability'   => 'manage_options',
                'description'  => 'Create a Redirection plugin redirect from a site-relative source path to a target path or http(s) URL (action_type url, codes 301/302/303/307/308, default 301), or to an HTTP error (action_type error, e.g. 410). group_id defaults to the first WordPress-module group. Loops, duplicate sources and unsafe targets are refused. Snapshotted; rollback-operation removes it',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => self::write_properties(),
                    'required'   => [ 'source' ],
                ],
                'requires'     => $requires,
                'validate'     => static fn (array $args): ?array => self::refusal(static fn () => self::plan_create($args)),
                'snapshot'     => static fn (array $args): array => [ 'object_type' => Redirection_Item_Snapshot::TYPE, 'object_id' => self::next_id() ],
                'handler'      => static fn (array $args, array $context): array => self::create($args, $item($args, $context)),
            ],
            'update-redirection-redirect'  => [
                'mode'         => 'write',
                'capability'   => 'manage_options',
                'description'  => 'Change a Redirection plugin redirect\'s source, target, action_type, action_code, title or group_id; only the fields passed change. Plain URL redirects only. Loops, duplicate sources and unsafe targets are refused. Snapshotted and reversible',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'id' => [ 'type' => 'integer', 'minimum' => 1 ] ] + array_diff_key(self::write_properties(), [ 'enabled' => true ]),
                    'required'   => [ 'id' ],
                ],
                'requires'     => $requires,
                'validate'     => static fn (array $args): ?array => self::refusal(static fn () => self::plan_update($args)),
                'snapshot'     => $target,
                'handler'      => static fn (array $args): array => self::update($args),
            ],
            'enable-redirection-redirect'  => [
                'mode'         => 'write',
                'capability'   => 'manage_options',
                'description'  => 'Enable a Redirection plugin redirect; refused if it would close a redirect loop. Snapshotted and reversible',
                'input_schema' => $id_only,
                'requires'     => $requires,
                'validate'     => static fn (array $args): ?array => self::refusal(static fn () => self::plan_status((int) $args['id'], 'enabled')),
                'snapshot'     => $target,
                'handler'      => static fn (array $args): array => self::set_status((int) $args['id'], 'enabled'),
            ],
            'disable-redirection-redirect' => [
                'mode'         => 'write',
                'capability'   => 'manage_options',
                'description'  => 'Disable a Redirection plugin redirect, keeping it and its hit count. Snapshotted and reversible',
                'input_schema' => $id_only,
                'requires'     => $requires,
                'validate'     => static fn (array $args): ?array => self::exists_refusal((int) $args['id']),
                'snapshot'     => $target,
                'handler'      => static fn (array $args): array => self::set_status((int) $args['id'], 'disabled'),
            ],
            'delete-redirection-redirect'  => [
                'mode'         => 'destructive',
                'capability'   => 'manage_options',
                'description'  => 'Delete a Redirection plugin redirect. The whole row is snapshotted, so rollback-operation brings it back at its own id; disable keeps it instead',
                'input_schema' => $id_only,
                'requires'     => $requires,
                'validate'     => static fn (array $args): ?array => self::exists_refusal((int) $args['id']),
                'snapshot'     => $target,
                'handler'      => static fn (array $args): array => self::delete((int) $args['id']),
            ],
        ];
    }

    /** Whether the Redirection plugin is loaded, filterable through wpmcp_redirection_active. */
    public static function is_active(): bool
    {
        return (bool) apply_filters('wpmcp_redirection_active', defined('REDIRECTION_VERSION'));
    }

    /** @return true|array{code:string,message:string} */
    private static function requirement()
    {
        if (! self::is_active()) {
            return [
                'code'    => 'redirection_inactive',
                'message' => 'The Redirection plugin is not active on this site.',
            ];
        }
        if (! Redirection_Item_Snapshot::table_exists(Redirection_Item_Snapshot::items_table()) || ! Redirection_Item_Snapshot::table_exists(Redirection_Item_Snapshot::groups_table())) {
            return [
                'code'    => 'redirection_not_installed',
                'message' => 'The Redirection plugin is active but its database tables are missing; finish its setup under Tools > Redirection.',
            ];
        }
        return true;
    }

    /** @return array<string, array<string, mixed>> the shared create/update fields. */
    private static function write_properties(): array
    {
        return [
            'source'      => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => self::MAX_URL_LENGTH ],
            'target'      => [ 'type' => 'string', 'maxLength' => self::MAX_URL_LENGTH ],
            'action_type' => [ 'type' => 'string', 'enum' => [ 'url', 'error' ] ],
            'action_code' => [ 'type' => 'integer' ],
            'group_id'    => [ 'type' => 'integer', 'minimum' => 1 ],
            'title'       => [ 'type' => 'string', 'maxLength' => 500 ],
            'enabled'     => [ 'type' => 'boolean' ],
        ];
    }

    // -----------------------------------------------------------------
    // Reads
    // -----------------------------------------------------------------

    /** @return array<int, array<string, mixed>> */
    private static function groups(): array
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Redirection's own tables have no WP API; an agent must see the live state.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT g.id, g.name, g.module_id, g.status, g.position, COUNT(i.id) AS items FROM %i g LEFT JOIN %i i ON i.group_id = g.id GROUP BY g.id ORDER BY g.position ASC, g.id ASC',
                Redirection_Item_Snapshot::groups_table(),
                Redirection_Item_Snapshot::items_table()
            ),
            ARRAY_A
        );

        $out = [];
        foreach ((array) $rows as $row) {
            $out[] = [
                'id'        => (int) $row['id'],
                'name'      => (string) $row['name'],
                'module_id' => (int) $row['module_id'],
                'enabled'   => 'enabled' === $row['status'],
                'position'  => (int) $row['position'],
                'items'     => (int) $row['items'],
            ];
        }
        return $out;
    }

    private static function list_redirects(array $args): array
    {
        global $wpdb;

        $where  = [ '1=1' ];
        $params = [ Redirection_Item_Snapshot::items_table() ];
        if (isset($args['group_id'])) {
            $where[]  = 'group_id = %d';
            $params[] = (int) $args['group_id'];
        }
        if (isset($args['status'])) {
            $where[]  = 'status = %s';
            $params[] = (string) $args['status'];
        }
        $search = trim((string) ($args['search'] ?? ''));
        if ('' !== $search) {
            $like     = '%' . $wpdb->esc_like($search) . '%';
            $where[]  = '(url LIKE %s OR action_data LIKE %s OR title LIKE %s)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        $clause   = implode(' AND ', $where);
        $per_page = (int) ($args['per_page'] ?? 50);
        $page     = (int) ($args['page'] ?? 1);

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $clause is assembled only from the literal fragments above and the spread carries one value per placeholder. Redirection's own table has no WP API.
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE {$clause}", ...$params));
        $rows  = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM %i WHERE {$clause} ORDER BY group_id ASC, position ASC, id ASC LIMIT %d OFFSET %d",
                ...array_merge($params, [ $per_page, ( $page - 1 ) * $per_page ])
            ),
            ARRAY_A
        );
        // phpcs:enable

        return [
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $per_page,
            'redirects' => array_map([ self::class, 'view' ], (array) $rows),
        ];
    }

    /**
     * The agent-facing shape of a row. The target is reported only when it is
     * a plain string: conditional match data is serialized PHP, which is never
     * unserialized.
     */
    private static function view(array $row): array
    {
        $data   = $row['action_data'] ?? null;
        $target = is_string($data) && '' !== $data && ! is_serialized($data) ? $data : null;

        return [
            'id'          => (int) $row['id'],
            'source'      => (string) $row['url'],
            'match_url'   => null === $row['match_url'] ? null : (string) $row['match_url'],
            'target'      => $target,
            'action_type' => (string) $row['action_type'],
            'action_code' => (int) $row['action_code'],
            'match_type'  => (string) $row['match_type'],
            'regex'       => 1 === (int) $row['regex'],
            'group_id'    => (int) $row['group_id'],
            'position'    => (int) $row['position'],
            'enabled'     => 'enabled' === $row['status'],
            'title'       => null === $row['title'] ? null : (string) $row['title'],
            'hits'        => (int) $row['last_count'],
            'last_access' => (string) $row['last_access'],
        ];
    }

    // -----------------------------------------------------------------
    // Writes
    // -----------------------------------------------------------------

    private static function create(array $args, int $id): array
    {
        global $wpdb;

        $fields = self::plan_create($args);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Redirection's own table; placing new rows last in their group, as Red_Item::create() does.
        $position = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE group_id = %d', Redirection_Item_Snapshot::items_table(), $fields['group_id']));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Redirection's own table; the id was reserved before the snapshot so the undo removes exactly this row.
        $ok = $wpdb->insert(Redirection_Item_Snapshot::items_table(), [ 'id' => $id, 'position' => $position ] + $fields);
        if (false === $ok) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- surfaced as a JSON tool error by Integration_Dispatcher, never rendered as HTML.
            throw new Operation_Refused('write_failed', 'Could not create the redirect: ' . $wpdb->last_error);
        }
        Redirection_Item_Snapshot::flush((int) $fields['group_id']);

        return [ 'redirect' => self::view(self::must_row($id)) ];
    }

    private static function update(array $args): array
    {
        global $wpdb;

        $id     = (int) $args['id'];
        $before = self::must_row($id);
        $fields = self::plan_update($args);
        if ([] !== $fields) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Redirection's own table; snapshotted by the dispatcher first.
            if (false === $wpdb->update(Redirection_Item_Snapshot::items_table(), $fields, [ 'id' => $id ])) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- surfaced as a JSON tool error by Integration_Dispatcher, never rendered as HTML.
                throw new Operation_Refused('write_failed', 'Could not update the redirect: ' . $wpdb->last_error);
            }
        }
        Redirection_Item_Snapshot::flush((int) $before['group_id'], (int) ($fields['group_id'] ?? 0));

        return [
            'redirect' => self::view(self::must_row($id)),
            'changed'  => array_keys($fields),
        ];
    }

    private static function set_status(int $id, string $status): array
    {
        global $wpdb;

        self::plan_status($id, $status);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Redirection's own table; snapshotted by the dispatcher first.
        if (false === $wpdb->update(Redirection_Item_Snapshot::items_table(), [ 'status' => $status ], [ 'id' => $id ])) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- surfaced as a JSON tool error by Integration_Dispatcher, never rendered as HTML.
            throw new Operation_Refused('write_failed', 'Could not change the redirect: ' . $wpdb->last_error);
        }
        $row = self::must_row($id);
        Redirection_Item_Snapshot::flush((int) $row['group_id']);

        return [ 'redirect' => self::view($row) ];
    }

    private static function delete(int $id): array
    {
        global $wpdb;

        $row = self::must_row($id);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Redirection's own table; the whole row was snapshotted by the dispatcher first.
        if (false === $wpdb->delete(Redirection_Item_Snapshot::items_table(), [ 'id' => $id ], [ '%d' ])) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- surfaced as a JSON tool error by Integration_Dispatcher, never rendered as HTML.
            throw new Operation_Refused('write_failed', 'Could not delete the redirect: ' . $wpdb->last_error);
        }
        Redirection_Item_Snapshot::flush((int) $row['group_id']);

        return [ 'deleted' => self::view($row) ];
    }

    /**
     * The next id to create under. Reserved in the snapshot callable, before
     * the snapshot is taken, so the create writes exactly the row its undo
     * removes. Past both the highest id and the table's AUTO_INCREMENT
     * counter, so a deleted redirect's id (which Redirection's logs may
     * still reference) is not handed out again.
     */
    private static function next_id(): int
    {
        global $wpdb;

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- live id reservation on Redirection's own table.
        $max    = (int) $wpdb->get_var($wpdb->prepare('SELECT MAX(id) FROM %i', Redirection_Item_Snapshot::items_table()));
        $status = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS LIKE %s', Redirection_Item_Snapshot::items_table()), ARRAY_A);
        // phpcs:enable
        $auto = is_array($status) ? (int) ($status['Auto_increment'] ?? 0) : 0;

        return max($max + 1, $auto);
    }

    // -----------------------------------------------------------------
    // Planning (shared by validate() and the handlers)
    // -----------------------------------------------------------------

    /**
     * The column map a create writes. Throws Operation_Error on any refusal.
     *
     * @return array<string, mixed>
     */
    private static function plan_create(array $args): array
    {
        $source = self::source((string) $args['source']);
        $match  = self::match_url($source);

        $duplicate = self::find_plain($match, 0, false);
        if (null !== $duplicate) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- surfaced as a JSON tool error by Integration_Dispatcher, never rendered as HTML.
            throw new Operation_Error('duplicate_source', sprintf('Source "%s" is already redirected by Redirection redirect %d; update that one instead.', $source, (int) $duplicate['id']), [ 'redirect_id' => (int) $duplicate['id'] ]);
        }

        $type   = (string) ($args['action_type'] ?? 'url');
        $code   = self::action_code($type, isset($args['action_code']) ? (int) $args['action_code'] : null);
        $target = 'url' === $type ? self::target((string) ($args['target'] ?? '')) : null;
        $group  = self::group(isset($args['group_id']) ? (int) $args['group_id'] : null);
        $status = false === ($args['enabled'] ?? true) ? 'disabled' : 'enabled';

        if (null !== $target && 'enabled' === $status) {
            self::assert_no_loop($source, $target, 0);
        }

        return [
            'url'         => $source,
            'match_url'   => $match,
            'match_data'  => null,
            'regex'       => 0,
            'group_id'    => $group,
            'status'      => $status,
            'action_type' => $type,
            'action_code' => $code,
            'action_data' => $target,
            'match_type'  => 'url',
            'title'       => self::title($args['title'] ?? null),
        ];
    }

    /**
     * The changed columns an update writes. Throws Operation_Error on any
     * refusal.
     *
     * @return array<string, mixed>
     */
    private static function plan_update(array $args): array
    {
        $id  = (int) $args['id'];
        $row = self::must_row($id);
        if ('url' !== $row['match_type'] || 0 !== (int) $row['regex'] || ! in_array($row['action_type'], [ 'url', 'error' ], true)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- surfaced as a JSON tool error by Integration_Dispatcher, never rendered as HTML.
            throw new Operation_Error('unsupported_redirect', sprintf('Redirection redirect %d is a %s-matched %s redirect%s; only plain URL redirects can be updated here. Enable, disable or delete still work, or edit it under Tools > Redirection.', $id, (string) $row['match_type'], (string) $row['action_type'], 0 !== (int) $row['regex'] ? ' with a regular expression' : ''), [ 'redirect_id' => $id ]);
        }

        $fields = [];
        $source = (string) $row['url'];
        if (isset($args['source'])) {
            $source = self::source((string) $args['source']);
            $match  = self::match_url($source);
            $dup    = self::find_plain($match, $id, false);
            if (null !== $dup) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- surfaced as a JSON tool error by Integration_Dispatcher, never rendered as HTML.
                throw new Operation_Error('duplicate_source', sprintf('Source "%s" is already redirected by Redirection redirect %d.', $source, (int) $dup['id']), [ 'redirect_id' => (int) $dup['id'] ]);
            }
            $fields['url']       = $source;
            $fields['match_url'] = $match;
        }

        $type = (string) ($args['action_type'] ?? $row['action_type']);
        if ($type !== $row['action_type']) {
            $fields['action_type'] = $type;
        }

        $target = null;
        if ('url' === $type) {
            $current = 'url' === $row['action_type'] && is_string($row['action_data']) ? $row['action_data'] : '';
            $target  = self::target(isset($args['target']) ? (string) $args['target'] : $current);
            if ($target !== $row['action_data']) {
                $fields['action_data'] = $target;
            }
        } elseif (null !== $row['action_data']) {
            $fields['action_data'] = null;
        }

        $code = self::action_code($type, isset($args['action_code']) ? (int) $args['action_code'] : ($type === $row['action_type'] ? (int) $row['action_code'] : null));
        if ($code !== (int) $row['action_code']) {
            $fields['action_code'] = $code;
        }

        if (isset($args['group_id'])) {
            $group = self::group((int) $args['group_id']);
            if ($group !== (int) $row['group_id']) {
                $fields['group_id'] = $group;
            }
        }
        if (array_key_exists('title', $args)) {
            $fields['title'] = self::title($args['title']);
        }

        if (null !== $target && 'enabled' === $row['status']) {
            self::assert_no_loop($source, $target, $id);
        }

        return $fields;
    }

    /** Refuse a status change that is impossible or would close a loop. */
    private static function plan_status(int $id, string $status): void
    {
        $row = self::must_row($id);
        if ('enabled' !== $status || 'url' !== $row['match_type'] || 0 !== (int) $row['regex'] || 'url' !== $row['action_type']) {
            return;
        }
        if (is_string($row['action_data']) && '' !== $row['action_data'] && ! is_serialized($row['action_data'])) {
            self::assert_no_loop((string) $row['url'], $row['action_data'], $id);
        }
    }

    /** Run a planner and turn an Operation_Error into the dispatcher's refusal shape. */
    private static function refusal(callable $plan): ?array
    {
        try {
            $plan();
        } catch (Operation_Error $e) {
            return [ 'code' => $e->error_code(), 'message' => $e->getMessage(), 'data' => $e->error_data() ];
        }
        return null;
    }

    private static function exists_refusal(int $id): ?array
    {
        return self::refusal(static fn () => self::must_row($id));
    }

    /** @return array<string, string|null> */
    private static function must_row(int $id): array
    {
        $row = Redirection_Item_Snapshot::row($id);
        if (null === $row) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- surfaced as a JSON tool error by Integration_Dispatcher, never rendered as HTML.
            throw new Operation_Error('redirect_not_found', sprintf('Redirection redirect %d does not exist.', $id), [ 'redirect_id' => $id ]);
        }
        return $row;
    }

    // -----------------------------------------------------------------
    // Field guards
    // -----------------------------------------------------------------

    /** A site-relative source path, stored as given (Redirection keeps its case and slash). */
    private static function source(string $raw): string
    {
        $source = trim($raw, ' ');
        if ('' === $source || '/' !== $source[0] || 0 === strpos($source, '//') || self::has_control_chars($source) || strlen($source) > self::MAX_URL_LENGTH) {
            throw new Operation_Error('invalid_source', 'The source must be a site-relative path starting with "/" (no scheme, host or control characters).');
        }
        if ('/' === self::match_url($source)) {
            throw new Operation_Error('invalid_source', 'The site root cannot be redirected.');
        }
        return $source;
    }

    /** A site-relative path or an absolute http(s) URL that esc_url_raw() leaves unchanged. */
    private static function target(string $raw): string
    {
        $target = trim($raw, ' ');
        if ('' === $target) {
            throw new Operation_Error('invalid_target', 'A url redirect needs a target path or URL; use action_type error for an HTTP error instead.');
        }
        if (self::has_control_chars($target) || strlen($target) > self::MAX_URL_LENGTH) {
            throw new Operation_Error('invalid_target', 'The target contains control characters or is too long.');
        }
        if ('/' === $target[0] && 0 !== strpos($target, '//')) {
            return $target;
        }

        $scheme = strtolower((string) wp_parse_url($target, PHP_URL_SCHEME));
        $host   = (string) wp_parse_url($target, PHP_URL_HOST);
        if (! in_array($scheme, [ 'http', 'https' ], true) || '' === $host || esc_url_raw($target, [ 'http', 'https' ]) !== $target) {
            throw new Operation_Error('invalid_target', 'The target must be a site-relative path starting with "/" or an absolute http(s) URL.');
        }
        return $target;
    }

    private static function has_control_chars(string $value): bool
    {
        return 1 === preg_match('/[\x00-\x1F\x7F]/', $value);
    }

    private static function action_code(string $type, ?int $code): int
    {
        $allowed = 'error' === $type ? self::ERROR_CODES : self::REDIRECT_CODES;
        if (null === $code) {
            return 'error' === $type ? 404 : 301;
        }
        if (! in_array($code, $allowed, true)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- surfaced as a JSON tool error by Integration_Dispatcher, never rendered as HTML.
            throw new Operation_Error('invalid_action_code', sprintf('Code %d is not valid for a %s action (%s).', $code, $type, implode(', ', $allowed)));
        }
        return $code;
    }

    /** The group a write goes to: the one named, or the first WordPress-module group. */
    private static function group(?int $group_id): int
    {
        global $wpdb;

        if (null !== $group_id) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Redirection's own table; checked live.
            $found = $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE id = %d', Redirection_Item_Snapshot::groups_table(), $group_id));
            if (null === $found) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- surfaced as a JSON tool error by Integration_Dispatcher, never rendered as HTML.
                throw new Operation_Error('group_not_found', sprintf('Redirection group %d does not exist; list-redirection-groups shows the groups.', $group_id));
            }
            return $group_id;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Redirection's own table; checked live.
        $default = $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE module_id = %d ORDER BY id ASC LIMIT 1', Redirection_Item_Snapshot::groups_table(), self::WORDPRESS_MODULE));
        if (null === $default) {
            throw new Operation_Error('group_not_found', 'Redirection has no group for WordPress redirects; pass group_id (see list-redirection-groups).');
        }
        return (int) $default;
    }

    private static function title($raw): ?string
    {
        if (null === $raw) {
            return null;
        }
        $title = trim(substr(sanitize_text_field((string) $raw), 0, 500));
        return '' === $title ? null : $title;
    }

    // -----------------------------------------------------------------
    // Matching and loops
    // -----------------------------------------------------------------

    /**
     * The value Redirection looks a request up by: Red_Url_Match when the
     * plugin is loaded, otherwise the same steps (path before any query,
     * decoded, trailing slash dropped, re-encoded, lowercased).
     */
    private static function match_url(string $url): string
    {
        if (class_exists('Red_Url_Match')) {
            return (string) ( new \Red_Url_Match($url) )->get_url();
        }

        $query = strpos($url, '?');
        $path  = urldecode(false === $query ? $url : substr($url, 0, $query));
        if ('/' !== $path) {
            $path = (string) preg_replace('@/$@', '', $path);
        }
        $path = rawurlencode($path);
        foreach ([ '/', ':', '[', ']', '@', '~', ',', '(', ')', ';' ] as $char) {
            $path = str_replace(rawurlencode($char), $char, $path);
        }
        $path = function_exists('mb_strtolower') ? mb_strtolower($path, 'UTF-8') : strtolower($path);

        return '' === $path ? '/' : $path;
    }

    /** The site path a target leads to, or null when it leaves the site. */
    private static function internal_path(string $target): ?string
    {
        if ('' !== $target && '/' === $target[0] && 0 !== strpos($target, '//')) {
            return $target;
        }
        $host = strtolower((string) wp_parse_url($target, PHP_URL_HOST));
        if ('' === $host || strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST)) !== $host) {
            return null;
        }
        $path  = (string) wp_parse_url($target, PHP_URL_PATH);
        $query = (string) wp_parse_url($target, PHP_URL_QUERY);
        return ( '' === $path ? '/' : $path ) . ( '' === $query ? '' : '?' . $query );
    }

    /**
     * A plain (non-regex, URL-matched) redirect owning a match_url, other than
     * $ignore_id. With $redirects_only, only enabled redirects to a URL: the
     * ones a visitor would actually be sent on by.
     *
     * @return array<string, string|null>|null
     */
    private static function find_plain(string $match_url, int $ignore_id, bool $redirects_only): ?array
    {
        global $wpdb;

        $extra = $redirects_only ? " AND status = 'enabled' AND action_type = 'url'" : '';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $extra is one of two literals; Redirection's own table, read live.
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM %i WHERE match_url = %s AND regex = 0 AND match_type = 'url' AND id <> %d{$extra} ORDER BY position ASC, id ASC LIMIT 1", Redirection_Item_Snapshot::items_table(), $match_url, $ignore_id), ARRAY_A);

        return is_array($row) ? $row : null;
    }

    /**
     * Refuse a redirect from $source to $target that would come back to a
     * path already on its walk, following the site's enabled plain URL
     * redirects. $ignore_id is the redirect being changed, so it is not
     * followed as its own old self.
     */
    private static function assert_no_loop(string $source, string $target, int $ignore_id): void
    {
        $chain = [ $source ];
        $seen  = [ self::match_url($source) => true ];
        $next  = $target;

        for ($hop = 0; $hop <= self::MAX_CHAIN_DEPTH; $hop++) {
            $path = self::internal_path($next);
            if (null === $path) {
                return;
            }
            $chain[] = $path;
            $match   = self::match_url($path);
            if (isset($seen[ $match ])) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- surfaced as a JSON tool error by Integration_Dispatcher, never rendered as HTML.
                throw new Operation_Error('redirect_loop', sprintf('Refused: this redirect would loop (%s).', implode(' -> ', $chain)), [ 'chain' => $chain ]);
            }
            $seen[ $match ] = true;

            $row = self::find_plain($match, $ignore_id, true);
            if (null === $row || ! is_string($row['action_data']) || '' === $row['action_data'] || is_serialized($row['action_data'])) {
                return;
            }
            $next = $row['action_data'];
        }

        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- surfaced as a JSON tool error by Integration_Dispatcher, never rendered as HTML.
        throw new Operation_Error('redirect_loop', sprintf('Refused: this redirect leads through more than %d redirects, which is treated as a loop.', self::MAX_CHAIN_DEPTH), [ 'chain' => $chain ]);
    }
}
