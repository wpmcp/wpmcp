<?php

namespace WPMCP\Integrations;

use WPMCP\Safety\Plugin_Table_Rows_Snapshot;
use WPMCP\Safety\Snapshot_Store;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Pretty Links (issue #682) on the plugin-data pair, free tier, so it adds no
 * top-level tools. Ops are prefixed prettylinks- because the pair is shared.
 *
 * Pretty Links (4.x defines PRLI_EDITION, 3.x PRLI_VERSION) keeps its links
 * in tables of its own (Database\Migrator in 4.0.18):
 *  - {prefix}prli_links: one row per link (slug, url, name, description,
 *    redirect_type, nofollow, sponsored, track_me, param_forwarding,
 *    new_window, clicks and uniques counters, created_at, updated_at, and in
 *    4.x deleted_at, the soft delete stamp, and source);
 *  - {prefix}prli_clicks: one row per recorded click (ip, browser, os,
 *    referer, host, uri, robot, first_click, country, device_type, vuid).
 * Nothing is cached by the plugin, so its redirect engine sees a row the
 * moment it is written.
 *
 * Reads: prettylinks-list-links, prettylinks-get-link and
 * prettylinks-get-link-clicks (click rows with the visitor IP masked and the
 * visitor id left out, plus counts taken live from prli_clicks).
 *
 * Writes (off until a site opts in with wpmcp_enable_pretty_links_write,
 * since a link redirects visitors): create-link, update-link and delete-link,
 * which is Pretty Links' own trash (deleted_at), never a hard delete. They
 * write the one prli_links row the way Repositories\Links::create(),
 * update() and softDelete() do, with the same checks (http(s) url, slug
 * alphabet, a slug no other row holds), and fire the plugin's prli-create-link,
 * prli_update_link and prli_delete_link actions. Each is snapshotted as a
 * 'plugin_table_rows' row image (kind prli, suffix links): an update or a
 * trash goes back exactly, and a create, whose id is reserved before the
 * snapshot, is removed again.
 *
 * Every op takes manage_options, the capability Pretty Links gates its admin
 * behind. Presence is filterable (wpmcp_pretty_links_active).
 */
final class Pretty_Links_Pack
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reads and writes Pretty Links' own tables live; it has no cached read API for them.

    public const CAPABILITY = 'manage_options';

    public const OPT_IN_FILTER = 'wpmcp_enable_pretty_links_write';

    public const DEFAULT_PAGE_SIZE = 20;

    public const MAX_PAGE_SIZE = 100;

    public const PLUGIN_FILES = [ 'pretty-link/pretty-link.php', 'pretty-link-pro/pretty-link.php' ];

    private const REDIRECT_TYPES = [ '301', '302', '307' ];

    private const FLAGS = [ 'nofollow', 'sponsored', 'track_me', 'new_window' ];

    public static function active(): bool
    {
        return (bool) apply_filters('wpmcp_pretty_links_active', defined('PRLI_EDITION') || defined('PRLI_VERSION'));
    }

    public static function table(string $name = 'links'): string
    {
        global $wpdb;
        return $wpdb->prefix . 'prli_' . $name;
    }

    /**
     * @return true|array{code: string, message: string, data: array}
     */
    public static function presence()
    {
        if (self::active()) {
            return true;
        }
        $installed = false;
        foreach (self::PLUGIN_FILES as $file) {
            $installed = $installed || (defined('WP_PLUGIN_DIR') && file_exists(WP_PLUGIN_DIR . '/' . $file));
        }
        $url = admin_url('plugins.php');

        return [
            'code'    => $installed ? 'plugin_inactive' : 'plugin_missing',
            'message' => sprintf(
                'Pretty Links is %s on this site. Install and activate Pretty Links (%s), then try again.',
                $installed ? 'installed but not active' : 'not installed',
                $url
            ),
            'data'    => [ 'plugin' => 'pretty-link', 'plugins_url' => $url ],
        ];
    }

    /** @return true|array{code: string, message: string, data?: array} */
    public static function tables_presence()
    {
        $present = self::presence();
        if (true !== $present) {
            return $present;
        }
        foreach ([ 'links', 'clicks' ] as $name) {
            if (! Plugin_Table_Rows_Snapshot::table_exists(self::table($name))) {
                return [
                    'code'    => 'pretty_links_tables_missing',
                    'message' => sprintf('Pretty Links\' table %s does not exist on this site. Deactivate and reactivate Pretty Links so it creates its tables, then try again.', self::table($name)),
                    'data'    => [ 'plugins_url' => admin_url('plugins.php') ],
                ];
            }
        }
        return true;
    }

    // ------------------------------------------------------------------
    // Catalog
    // ------------------------------------------------------------------

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        $id     = [ 'type' => 'integer', 'minimum' => 1 ];
        $one    = [ 'type' => 'object', 'properties' => [ 'link_id' => $id ], 'required' => [ 'link_id' ] ];
        $paging = [
            'page_size' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PAGE_SIZE ],
            'offset'    => [ 'type' => 'integer', 'minimum' => 0 ],
        ];
        $fields = [
            'url'              => [ 'type' => 'string', 'maxLength' => 2000 ],
            'slug'             => [ 'type' => 'string', 'maxLength' => 255 ],
            'name'             => [ 'type' => 'string', 'maxLength' => 255 ],
            'description'      => [ 'type' => 'string' ],
            'redirect_type'    => [ 'type' => 'string', 'enum' => self::REDIRECT_TYPES ],
            'nofollow'         => [ 'type' => 'boolean' ],
            'sponsored'        => [ 'type' => 'boolean' ],
            'track_me'         => [ 'type' => 'boolean' ],
            'new_window'       => [ 'type' => 'boolean' ],
            'param_forwarding' => [ 'type' => 'boolean' ],
        ];
        $base   = [
            'capability' => self::CAPABILITY,
            'requires'   => static fn () => self::tables_presence(),
        ];
        $write  = $base + [
            'enabled_by_default' => (bool) apply_filters('wpmcp_enable_pretty_links_write', false),
            'opt_in_filter'      => self::OPT_IN_FILTER,
        ];
        $link   = static fn (array $args): ?array => self::link_refusal((int) $args['link_id']);
        $target = static fn (array $args): array => [
            'object_type' => Plugin_Table_Rows_Snapshot::TYPE,
            'object_id'   => Plugin_Table_Rows_Snapshot::key('prli', 'links', [ (int) $args['link_id'] ]),
        ];

        return [
            'prettylinks-list-links'      => $base + [
                'mode'         => 'read',
                'description'  => 'List Pretty Links links newest first: id, slug, pretty URL, target url, name, redirect type, flags, click and unique counters, dates. status active (default), trashed or any; search over slug, name and url; redirect_type; page_size (default 20, max 100) and offset',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'status'        => [ 'type' => 'string', 'enum' => [ 'active', 'trashed', 'any' ] ],
                        'search'        => [ 'type' => 'string', 'maxLength' => 200 ],
                        'redirect_type' => [ 'type' => 'string', 'minLength' => 1 ],
                    ] + $paging,
                ],
                'handler'      => static fn (array $args): array => self::list_links($args),
            ],
            'prettylinks-get-link'        => $base + [
                'mode'         => 'read',
                'description'  => 'Read one Pretty Links link with live click and unique-visitor counts',
                'input_schema' => $one,
                'validate'     => $link,
                'handler'      => static fn (array $args): array => [ 'link' => self::link_with_counts((array) self::row((int) $args['link_id'])) ],
            ],
            'prettylinks-get-link-clicks' => $base + [
                'mode'         => 'read',
                'description'  => 'Recorded clicks of one Pretty Links link, newest first: time, host, referer, uri, browser, os, country, device, robot, first click, visitor IP MASKED. from and to (YYYY-MM-DD, UTC), unique (first clicks only), page_size and offset. Totals counted live; Simple tracking mode records counters only, no rows',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'link_id' => $id,
                        'from'    => [ 'type' => 'string', 'pattern' => '^\d{4}-\d{2}-\d{2}$' ],
                        'to'      => [ 'type' => 'string', 'pattern' => '^\d{4}-\d{2}-\d{2}$' ],
                        'unique'  => [ 'type' => 'boolean' ],
                    ] + $paging,
                    'required'   => [ 'link_id' ],
                ],
                'validate'     => $link,
                'handler'      => static fn (array $args): array => self::clicks($args),
            ],
            'prettylinks-create-link'     => $write + [
                'mode'         => 'write',
                'description'  => 'Create a Pretty Links link: url (http or https, required), slug (generated when empty), name, description, redirect_type (301, 302 or 307), nofollow, sponsored, track_me, new_window, param_forwarding. A slug another link holds, or with characters beyond letters, digits, - _ / ., is refused. Undo: rollback-operation removes it',
                'input_schema' => [ 'type' => 'object', 'properties' => $fields, 'required' => [ 'url' ] ],
                'validate'     => static fn (array $args): ?array => self::create_refusal($args),
                'snapshot'     => static fn (array $args): array => [
                    'object_type' => Plugin_Table_Rows_Snapshot::TYPE,
                    'object_id'   => Plugin_Table_Rows_Snapshot::key('prli', 'links', [ self::next_id() ]),
                ],
                'handler'      => static fn (array $args, array $context): array => self::create($args, $context),
            ],
            'prettylinks-update-link'     => $write + [
                'mode'         => 'write',
                'description'  => 'Change a Pretty Links link; only the args given change (url, slug, name, description, redirect_type, flags). A slug another link holds is refused. Undo: rollback-operation',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'link_id' => $id ] + $fields, 'required' => [ 'link_id' ], 'minProperties' => 2 ],
                'validate'     => static fn (array $args): ?array => self::update_refusal($args),
                'snapshot'     => $target,
                'handler'      => static fn (array $args): array => self::update($args),
            ],
            'prettylinks-delete-link'     => $write + [
                'mode'         => 'write',
                'description'  => 'Move a Pretty Links link to its trash (deleted_at, as the plugin does; its redirect stops, clicks are kept). Not a permanent delete. Undo: rollback-operation brings it back',
                'input_schema' => $one,
                'validate'     => static fn (array $args): ?array => self::delete_refusal((int) $args['link_id']),
                'snapshot'     => $target,
                'handler'      => static fn (array $args): array => self::trash((int) $args['link_id']),
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** @return array<string, mixed>|null */
    private static function row(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', self::table(), $id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /** @return array{code: string, message: string, data: array} */
    private static function refusal(string $code, string $message, array $data = []): array
    {
        return [ 'code' => $code, 'message' => $message, 'data' => $data ];
    }

    private static function link_refusal(int $id): ?array
    {
        return null !== self::row($id) ? null : self::refusal('link_not_found', sprintf('Pretty Links has no link %d.', $id), [ 'link_id' => $id ]);
    }

    /** A visitor IP with its host part hidden: 192.168.*.*, or the first two groups of an IPv6 address. */
    public static function mask_ip(?string $ip): ?string
    {
        if (null === $ip || '' === $ip) {
            return $ip;
        }
        if (false !== filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            return $parts[0] . '.' . $parts[1] . '.*.*';
        }
        if (false !== filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $parts = explode(':', $ip);
            return $parts[0] . ':' . ( $parts[1] ?? '' ) . ':*';
        }
        return '***';
    }

    private static function view(array $row): array
    {
        $slug = (string) $row['slug'];
        return [
            'id'               => (int) $row['id'],
            'slug'             => $slug,
            'pretty_url'       => home_url('/' . $slug),
            'url'              => (string) $row['url'],
            'name'             => (string) $row['name'],
            'description'      => (string) ($row['description'] ?? ''),
            'redirect_type'    => (string) $row['redirect_type'],
            'nofollow'         => 1 === (int) ($row['nofollow'] ?? 0),
            'sponsored'        => 1 === (int) ($row['sponsored'] ?? 0),
            'track_me'         => 1 === (int) ($row['track_me'] ?? 0),
            'new_window'       => 1 === (int) ($row['new_window'] ?? 0),
            'param_forwarding' => ! in_array((string) ($row['param_forwarding'] ?? ''), [ '', '0', 'off' ], true),
            'source'           => (string) ($row['source'] ?? 'admin'),
            'clicks'           => (int) ($row['clicks'] ?? 0),
            'uniques'          => (int) ($row['uniques'] ?? 0),
            'created_at'       => (string) $row['created_at'],
            'updated_at'       => null === ($row['updated_at'] ?? null) ? null : (string) $row['updated_at'],
            'trashed'          => ! empty($row['deleted_at']),
            'deleted_at'       => empty($row['deleted_at']) ? null : (string) $row['deleted_at'],
        ];
    }

    private static function link_with_counts(array $row): array
    {
        global $wpdb;
        $view = self::view($row);
        $n    = $wpdb->get_row($wpdb->prepare('SELECT COUNT(*) AS clicks, COALESCE(SUM(first_click), 0) AS uniques FROM %i WHERE link_id = %d', self::table('clicks'), (int) $row['id']), ARRAY_A);
        // Pretty Links keeps counters on the row; Simple tracking mode keeps them as metas and records no click rows.
        $view['recorded_clicks']  = (int) ($n['clicks'] ?? 0);
        $view['recorded_uniques'] = (int) ($n['uniques'] ?? 0);
        return $view;
    }

    // ------------------------------------------------------------------
    // Reads
    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    private static function list_links(array $args): array
    {
        global $wpdb;
        $has_trash = in_array('deleted_at', Plugin_Table_Rows_Snapshot::columns(self::table()), true);
        $status    = (string) ($args['status'] ?? 'active');
        $where     = [];
        $params    = [];
        if ($has_trash && 'any' !== $status) {
            $where[] = 'trashed' === $status ? 'deleted_at IS NOT NULL' : 'deleted_at IS NULL';
        }
        if (isset($args['redirect_type']) && '' !== (string) $args['redirect_type']) {
            $where[]  = 'redirect_type = %s';
            $params[] = (string) $args['redirect_type'];
        }
        $search = trim((string) ($args['search'] ?? ''));
        if ('' !== $search) {
            $like     = '%' . $wpdb->esc_like($search) . '%';
            $where[]  = '(slug LIKE %s OR name LIKE %s OR url LIKE %s)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        $sql   = [] === $where ? '' : ' WHERE ' . implode(' AND ', $where);
        $size  = max(1, min(self::MAX_PAGE_SIZE, (int) ($args['page_size'] ?? self::DEFAULT_PAGE_SIZE)));
        $skip  = max(0, (int) ($args['offset'] ?? 0));
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $sql holds only column names fixed above and placeholders.
        $total = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i' . $sql, self::table(), ...$params));
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.NotPrepared -- same.
        $rows  = (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM %i' . $sql . ' ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d', self::table(), ...array_merge($params, [ $size, $skip ])), ARRAY_A);

        return [
            'links'       => array_map(static fn (array $row): array => self::view($row), $rows),
            'total'       => $total,
            'next_offset' => $skip + count($rows) < $total ? $skip + count($rows) : null,
        ];
    }

    /** @return array<string, mixed> */
    private static function clicks(array $args): array
    {
        global $wpdb;
        $id     = (int) $args['link_id'];
        $where  = 'link_id = %d';
        $params = [ $id ];
        if (isset($args['from'])) {
            $where   .= ' AND created_at >= %s';
            $params[] = $args['from'] . ' 00:00:00';
        }
        if (isset($args['to'])) {
            $where   .= ' AND created_at <= %s';
            $params[] = $args['to'] . ' 23:59:59';
        }
        if (! empty($args['unique'])) {
            $where .= ' AND first_click = 1';
        }
        $size = max(1, min(self::MAX_PAGE_SIZE, (int) ($args['page_size'] ?? self::DEFAULT_PAGE_SIZE)));
        $skip = max(0, (int) ($args['offset'] ?? 0));
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $where holds only fixed column conditions and placeholders.
        $total  = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE {$where}", self::table('clicks'), ...$params));
        $uniq   = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE {$where} AND first_click = 1", self::table('clicks'), ...$params));
        $rows   = (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM %i WHERE {$where} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", self::table('clicks'), ...array_merge($params, [ $size, $skip ])), ARRAY_A);
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

        $clicks = [];
        foreach ($rows as $row) {
            $clicks[] = [
                'id'          => (int) $row['id'],
                'created_at'  => (string) $row['created_at'],
                'host'        => (string) ($row['host'] ?? ''),
                'referer'     => (string) ($row['referer'] ?? ''),
                'uri'         => (string) ($row['uri'] ?? ''),
                'browser'     => (string) ($row['btype'] ?? ''),
                'os'          => (string) ($row['os'] ?? ''),
                'country'     => (string) ($row['country'] ?? ''),
                'device_type' => (string) ($row['device_type'] ?? ''),
                'robot'       => 1 === (int) ($row['robot'] ?? 0),
                'first_click' => 1 === (int) ($row['first_click'] ?? 0),
                'ip'          => self::mask_ip($row['ip'] ?? null),
            ];
        }
        return [
            'link_id'      => $id,
            'clicks'       => $clicks,
            'total'        => $total,
            'unique_total' => $uniq,
            'masked'       => true,
            'next_offset'  => $skip + count($rows) < $total ? $skip + count($rows) : null,
        ];
    }

    // ------------------------------------------------------------------
    // Planning
    // ------------------------------------------------------------------

    /** The slug as Pretty Links stores it, or null when nothing usable is left. */
    private static function clean_slug(string $slug): ?string
    {
        $slug = trim($slug);
        if ('' !== $slug && '/' === $slug[0]) {
            return null;
        }
        if (class_exists('PrettyLinks\Redirect\Engine') && method_exists('PrettyLinks\Redirect\Engine', 'canonicalizeSlug')) {
            return \PrettyLinks\Redirect\Engine::canonicalizeSlug($slug, false);
        }
        $slug = (string) preg_replace('/\s+/u', '-', $slug);
        $slug = (string) preg_replace('#[^\p{L}\p{N}_\-/.~]#u', '', $slug);
        $slug = (string) preg_replace('#/+#', '/', $slug);
        $slug = (string) preg_replace('/-+/', '-', $slug);
        return trim($slug, '-/ ');
    }

    /**
     * Refusal for a slug Pretty Links would rewrite, or one outside letters,
     * digits, "-", "_", "/" and "." (#950): the op refuses instead of storing
     * a changed slug. A link may keep the slug it already holds.
     */
    private static function slug_shape_refusal(string $given, string $clean, int $except): ?array
    {
        if ($except > 0 && $given === (string) ( self::row($except)['slug'] ?? '' )) {
            return null;
        }
        if ($clean === $given && 1 === preg_match('#^[\p{L}\p{N}\p{M}_\-/.]+$#u', $given)) {
            return null;
        }
        $suggest = (string) preg_replace('#[^\p{L}\p{N}\p{M}_\-/.]#u', '', $clean);
        $suggest = trim((string) preg_replace([ '#/+#', '/-+/' ], [ '/', '-' ], $suggest), '-/');
        return self::refusal(
            'invalid_slug',
            sprintf('The slug "%s" is not allowed: use only letters, digits, "-", "_", "/" and ".", with no leading, trailing or repeated "-" or "/". Nothing was written.', $given),
            [ 'slug' => $given ] + ( '' !== $suggest ? [ 'suggested_slug' => $suggest ] : [] )
        );
    }

    private static function slug_taken(string $slug, int $except = 0): bool
    {
        global $wpdb;
        return null !== $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE slug = %s AND id <> %d LIMIT 1', self::table(), $slug, $except));
    }

    private static function valid_url(string $url): bool
    {
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Pretty Links' own hook, run so its extensions see the write.
        return (bool) apply_filters('prli_is_valid_url', 1 === preg_match('%^https?://%i', $url), $url);
    }

    /**
     * Refusal for the url, slug and redirect type given, or null. $except is
     * the link being updated.
     */
    private static function field_refusal(array $args, int $except): ?array
    {
        if (isset($args['url'])) {
            $url = trim(str_replace([ "\r", "\n" ], '', (string) $args['url']));
            if ('' === $url || ! self::valid_url($url)) {
                return self::refusal('url_invalid', 'The url must start with http:// or https://.', [ 'url' => $url ]);
            }
        }
        if (isset($args['slug']) && '' !== trim((string) $args['slug'])) {
            $slug = self::clean_slug((string) $args['slug']);
            if (null === $slug || '' === $slug) {
                return self::refusal('invalid_slug', 'The slug has no usable characters, or starts with a slash.', [ 'slug' => (string) $args['slug'] ]);
            }
            $bad = self::slug_shape_refusal(trim((string) $args['slug']), $slug, $except);
            if (null !== $bad) {
                return $bad;
            }
            if (self::slug_taken($slug, $except)) {
                return self::refusal('slug_in_use', sprintf('Another Pretty Links link already uses the slug "%s".', $slug), [ 'slug' => $slug ]);
            }
        }
        return null;
    }

    private static function create_refusal(array $args): ?array
    {
        return self::field_refusal($args, 0);
    }

    private static function update_refusal(array $args): ?array
    {
        $id = (int) $args['link_id'];
        $row = self::row($id);
        if (null === $row) {
            return self::refusal('link_not_found', sprintf('Pretty Links has no link %d.', $id), [ 'link_id' => $id ]);
        }
        if (! empty($row['deleted_at'])) {
            return self::refusal('link_trashed', sprintf('Link %d is in the trash. Undo its delete first.', $id), [ 'link_id' => $id ]);
        }
        $given = array_intersect_key($args, array_flip(array_merge([ 'url', 'slug', 'name', 'description', 'redirect_type', 'param_forwarding' ], self::FLAGS)));
        if ([] === $given) {
            return self::refusal('invalid_args', 'Give at least one field to change.');
        }
        if (isset($args['slug']) && '' === trim((string) $args['slug'])) {
            return self::refusal('invalid_slug', 'A link\'s slug cannot be emptied.', [ 'slug' => '' ]);
        }
        return self::field_refusal($args, $id);
    }

    private static function delete_refusal(int $id): ?array
    {
        $row = self::row($id);
        if (null === $row) {
            return self::refusal('link_not_found', sprintf('Pretty Links has no link %d.', $id), [ 'link_id' => $id ]);
        }
        if (! in_array('deleted_at', Plugin_Table_Rows_Snapshot::columns(self::table()), true)) {
            return self::refusal('trash_unsupported', 'This Pretty Links version has no link trash (needs Pretty Links 4).', [ 'link_id' => $id ]);
        }
        if (! empty($row['deleted_at'])) {
            return self::refusal('already_trashed', sprintf('Link %d is already in the trash.', $id), [ 'link_id' => $id ]);
        }
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Pretty Links' own hook, run so its extensions see the write.
        $reason = (string) apply_filters('prli_pre_link_trash', '', $id);
        if ('' !== $reason) {
            return self::refusal('trash_blocked', $reason, [ 'link_id' => $id ]);
        }
        return null;
    }

    /** The columns of the args given, cast the way Repositories\Links stores them. */
    private static function columns_from(array $args): array
    {
        $set = [];
        foreach ([ 'url', 'name', 'description', 'redirect_type' ] as $key) {
            if (array_key_exists($key, $args)) {
                $set[ $key ] = 'url' === $key ? trim(str_replace([ "\r", "\n" ], '', (string) $args[ $key ])) : (string) $args[ $key ];
            }
        }
        foreach (self::FLAGS as $key) {
            if (array_key_exists($key, $args)) {
                $set[ $key ] = $args[ $key ] ? 1 : 0;
            }
        }
        if (array_key_exists('param_forwarding', $args)) {
            $set['param_forwarding'] = $args['param_forwarding'] ? 1 : 0;
        }
        if (isset($args['slug']) && '' !== trim((string) $args['slug'])) {
            $set['slug'] = (string) self::clean_slug((string) $args['slug']);
        }
        return $set;
    }

    // ------------------------------------------------------------------
    // Writes
    // ------------------------------------------------------------------

    /** The id the next insert takes: MAX(id) + 1 or the table's auto-increment, whichever is higher. */
    private static function next_id(): int
    {
        global $wpdb;
        $max    = (int) $wpdb->get_var($wpdb->prepare('SELECT MAX(id) FROM %i', self::table()));
        $status = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS LIKE %s', self::table()), ARRAY_A);
        $auto   = is_array($status) ? (int) ($status['Auto_increment'] ?? 0) : 0;
        return max($max + 1, $auto);
    }

    private static function random_slug(): string
    {
        for ($i = 0; $i < 20; $i++) {
            $slug = strtolower(wp_generate_password(5, false, false));
            if (! self::slug_taken($slug)) {
                return $slug;
            }
        }
        return strtolower(wp_generate_password(8, false, false));
    }

    /** @return array<string, mixed> */
    private static function create(array $args, array $context): array
    {
        global $wpdb;
        $parsed = Plugin_Table_Rows_Snapshot::parse((string) $context['target']['object_id']);
        $id     = (int) ($parsed['ids'][0] ?? 0);
        $set    = self::columns_from($args);
        $now    = current_time('mysql', true);
        $slug   = $set['slug'] ?? self::random_slug();
        $row    = [
            'id'               => $id,
            'slug'             => $slug,
            'url'              => (string) $set['url'],
            'name'             => '' !== trim((string) ($set['name'] ?? '')) ? (string) $set['name'] : $slug,
            'description'      => (string) ($set['description'] ?? ''),
            'redirect_type'    => (string) ($set['redirect_type'] ?? '302'),
            'param_forwarding' => (int) ($set['param_forwarding'] ?? 1),
            'track_me'         => (int) ($set['track_me'] ?? 1),
            'nofollow'         => (int) ($set['nofollow'] ?? 0),
            'sponsored'        => (int) ($set['sponsored'] ?? 0),
            'new_window'       => (int) ($set['new_window'] ?? 0),
            'created_at'       => $now,
            'updated_at'       => $now,
        ];
        $columns = Plugin_Table_Rows_Snapshot::columns(self::table());
        if (in_array('source', $columns, true)) {
            $row['source'] = 'admin';
        }
        $row = array_intersect_key($row, array_flip($columns));

        if (false === $wpdb->insert(self::table(), $row)) {
            // Nothing was written, but the snapshot is persisted. Left in place, rolling it
            // back would remove whatever row now holds that id, so it is voided.
            if ('' !== (string) ($context['operation_id'] ?? '')) {
                Snapshot_Store::delete_operation((string) $context['operation_id']);
            }
            throw new Operation_Refused('insert_failed', 'Could not create the link: ' . $wpdb->last_error);
        }
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Pretty Links' own hook, run so its extensions see the write.
        do_action('prli-create-link', $id, $row);

        return [ 'link' => self::view((array) self::row($id)) ];
    }

    /** @return array<string, mixed> */
    private static function update(array $args): array
    {
        global $wpdb;
        $id  = (int) $args['link_id'];
        $set = self::columns_from($args);
        if (isset($args['slug']) && trim((string) $args['slug']) === (string) ( self::row($id)['slug'] ?? '' )) {
            unset($set['slug']); // The slug it already holds stays byte for byte, legacy characters included.
        }
        if ([] === $set) {
            return [ 'link' => self::view((array) self::row($id)) ];
        }
        $set['updated_at'] = current_time('mysql', true);
        if (false === $wpdb->update(self::table(), $set, [ 'id' => $id ])) {
            throw new Operation_Refused('update_failed', sprintf('Could not update link %d: %s', $id, $wpdb->last_error));
        }
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Pretty Links' own hook, run so its extensions see the write.
        do_action('prli_update_link', $id, $set);

        return [ 'link' => self::view((array) self::row($id)) ];
    }

    /** @return array<string, mixed> */
    private static function trash(int $id): array
    {
        global $wpdb;
        if (false === $wpdb->update(self::table(), [ 'deleted_at' => current_time('mysql', true) ], [ 'id' => $id ])) {
            throw new Operation_Refused('delete_failed', sprintf('Could not trash link %d: %s', $id, $wpdb->last_error));
        }
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Pretty Links' own hook, run so its extensions see the write.
        do_action('prli_delete_link', $id, [ 'soft' => true ]);

        return [ 'link' => self::view((array) self::row($id)) ];
    }
}
