<?php

namespace WPMCP\Integrations;

use WPMCP\Safety\Plugin_Table_Rows_Snapshot;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Smash Balloon Instagram Feed (issue #902) on the plugin-data pair, free
 * tier, so it adds no top-level tools. Ops are prefixed instagramfeed-
 * because the pair is shared.
 *
 * Instagram Feed 6.x (free and Pro both define SBIVER) keeps its data in
 * tables of its own (inc/Builder/SBI_Db.php):
 *  - {prefix}sbi_feeds: one row per Feed Builder feed (feed_name,
 *    feed_title, status, last_modified, and settings, a JSON object in
 *    which the builder stores scalars as strings, booleans as booleans and
 *    the connected account ids under 'id');
 *  - {prefix}sbi_sources: one row per connected account (account_id,
 *    account_type, connect_type, username, expires, error, and the access
 *    token, which these ops never read out);
 *  - {prefix}sbi_feed_caches: the fetched and rendered data of each feed
 *    (feed_id is the feed's id, with _CUSTOMIZER variants for the builder
 *    preview; cache_key posts, header and the posts_backup and
 *    header_backup copies the plugin falls back to when a fetch fails);
 *  - {prefix}sbi_instagram_feed_locator: where each feed was seen embedded
 *    (feed_id '*<id>', post_id, html_location), recorded by the plugin as
 *    pages render.
 *
 * Reads: instagramfeed-list-feeds, instagramfeed-get-feed (settings with
 * any token-bearing key removed, placements and cache rows) and
 * instagramfeed-list-sources (connection state: connected, expiring_soon,
 * expired or error, with the expiry date and the error code and message;
 * never the token). Reconnecting an account is an OAuth step in wp-admin,
 * so the sources read returns the plugin's settings screen link for it.
 *
 * Writes (off until a site opts in with wpmcp_enable_instagram_feed_write):
 *  - instagramfeed-update-feed merges an allowlist of display settings
 *    into the feed's settings the way the plugin's own SBI_Feed_Saver does,
 *    then clears the feed's rendered cache like the Feed Builder's save
 *    (and its fetched posts when a setting changes what is fetched), so the
 *    change shows. Snapshotted as the feed row (kind sbi, suffix feeds).
 *  - instagramfeed-clear-feed-cache does what SB_Instagram_Cache::clear()
 *    does for 'all' and, by default, 'posts': it blanks the feed's cache
 *    rows except the two backups, so the next page view fetches fresh posts.
 *    Snapshotted as those cache rows (kind sbi, suffix feed_caches), so an
 *    undo puts the cached data back.
 *
 * Every op takes manage_options, the capability the plugin's own settings
 * screens fall back to. Presence is filterable (wpmcp_instagram_feed_active).
 */
final class Instagram_Feed_Pack
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reads and writes Instagram Feed's own tables live; it has no cached read API for them.

    public const CAPABILITY = 'manage_options';

    public const OPT_IN_FILTER = 'wpmcp_enable_instagram_feed_write';

    public const PLUGIN = 'Smash Balloon Instagram Feed';

    public const MAX_PAGE_SIZE = 50;

    /** Days before expiry from which a source reads expiring_soon. */
    private const EXPIRY_WARNING_DAYS = 7;

    /** Cache keys a clear leaves alone: the copies the plugin falls back to when a fetch fails. */
    private const BACKUP_KEYS = [ 'posts_backup', 'header_backup' ];

    /** Settings whose change alters which posts are fetched (the plugin's RETRIEVAL_KEYS). */
    private const RETRIEVAL_KEYS = [ 'num', 'nummobile', 'sortby', 'imageres' ];

    /** Settings keys never returned: they can hold an access token. */
    private const HIDDEN_KEY = '/token|secret|password|connected_accounts|^sources$/i';

    public static function active(): bool
    {
        return (bool) apply_filters('wpmcp_instagram_feed_active', defined('SBIVER'));
    }

    public static function table(string $name): string
    {
        global $wpdb;
        return $wpdb->prefix . 'sbi_' . $name;
    }

    /** @return true|array{code: string, message: string, data: array} */
    public static function presence()
    {
        if (! self::active()) {
            $refusal = Plugin_Refusal::make('plugin_missing', self::PLUGIN);
            if ('inactive' === ($refusal['data']['state'] ?? '')) {
                $refusal['code'] = 'plugin_inactive';
            }
            return $refusal;
        }
        if (! Plugin_Table_Rows_Snapshot::table_exists(self::table('feeds'))) {
            return [
                'code'    => 'instagram_feed_tables_missing',
                'message' => sprintf('Instagram Feed\'s table %s does not exist on this site. Open Instagram Feed in wp-admin once so it creates its tables, then try again.', self::table('feeds')),
                'data'    => [ 'settings_url' => self::settings_url() ],
            ];
        }
        return true;
    }

    private static function settings_url(): string
    {
        return admin_url('admin.php?page=sbi-settings');
    }

    /**
     * The display settings update-feed may change, with the plugin's own
     * bounds (Abilities/Services/SBI_Settings_Schema.php in 6.16.0).
     *
     * @return array<string, array<string, mixed>>
     */
    private static function writable(): array
    {
        $cols = [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 10 ];
        $num  = [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ];
        $flag = [ 'type' => 'boolean' ];
        $text = [ 'type' => 'string', 'maxLength' => 200 ];

        return [
            'cols'             => $cols,
            'colstablet'       => $cols,
            'colsmobile'       => $cols,
            'num'              => $num,
            'nummobile'        => $num,
            'imagepadding'     => [ 'type' => 'integer', 'minimum' => 0, 'maximum' => 100 ],
            'imageaspectratio' => [ 'type' => 'string', 'enum' => [ '1:1', '3:4', '4:5' ] ],
            'sortby'           => [ 'type' => 'string', 'enum' => [ 'none', 'random' ] ],
            'imageres'         => [ 'type' => 'string', 'enum' => [ 'auto', 'thumb', 'medium', 'full' ] ],
            'showheader'       => $flag,
            'headersize'       => [ 'type' => 'string', 'enum' => [ 'small', 'medium', 'large' ] ],
            'showbio'          => $flag,
            'showbutton'       => $flag,
            'buttontext'       => $text,
            'showfollow'       => $flag,
            'followtext'       => $text,
        ];
    }

    // ------------------------------------------------------------------
    // Catalog
    // ------------------------------------------------------------------

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        $feed  = [ 'type' => 'integer', 'minimum' => 1 ];
        $base  = [
            'capability' => self::CAPABILITY,
            'requires'   => static fn () => self::presence(),
        ];
        $write = $base + [
            'mode'               => 'write',
            'enabled_by_default' => (bool) apply_filters('wpmcp_enable_instagram_feed_write', false),
            'opt_in_filter'      => self::OPT_IN_FILTER,
        ];
        $known = static fn (array $args): ?array => self::feed_refusal((int) $args['feed_id']);

        return [
            'instagramfeed-list-feeds'       => $base + [
                'mode'         => 'read',
                'description'  => 'List Smash Balloon Instagram Feed feeds: id, name, status, shortcode, source usernames, embed count and when its posts were last cached. status publish, draft or any (default); page and page_size (max 50)',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'status'    => [ 'type' => 'string', 'enum' => [ 'publish', 'draft', 'any' ] ],
                        'page'      => [ 'type' => 'integer', 'minimum' => 1 ],
                        'page_size' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PAGE_SIZE ],
                    ],
                ],
                'handler'      => static fn (array $args): array => self::list_feeds($args),
            ],
            'instagramfeed-get-feed'         => $base + [
                'mode'         => 'read',
                'description'  => 'One Instagram Feed feed: its settings (layout, columns, number of posts, header and buttons; token keys never returned), the posts and widgets it is embedded on, and its cache rows with age',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'feed_id' => $feed ], 'required' => [ 'feed_id' ] ],
                'validate'     => $known,
                'handler'      => static fn (array $args): array => [ 'feed' => self::feed((int) $args['feed_id']) ],
            ],
            'instagramfeed-list-sources'     => $base + [
                'mode'         => 'read',
                'description'  => 'Connected Instagram accounts and their state: connected, expiring_soon, expired or error (with code and message), expiry, feeds using each. Tokens are never returned; reconnecting is an OAuth step at reconnect_url in wp-admin',
                'input_schema' => [ 'type' => 'object', 'properties' => (object) [] ],
                'handler'      => static fn (): array => self::sources(),
            ],
            'instagramfeed-update-feed'      => $write + [
                'description'  => 'Change display settings of an Instagram Feed feed; only the keys given change. Clears the feed\'s rendered cache so it shows (and its fetched posts when num, nummobile, sortby or imageres change). Undo: rollback-operation',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'feed_id'  => $feed,
                        'settings' => [ 'type' => 'object', 'properties' => self::writable(), 'additionalProperties' => false, 'minProperties' => 1 ],
                    ],
                    'required'   => [ 'feed_id', 'settings' ],
                ],
                'validate'     => $known,
                'snapshot'     => static fn (array $args): array => [
                    'object_type' => Plugin_Table_Rows_Snapshot::TYPE,
                    'object_id'   => Plugin_Table_Rows_Snapshot::key('sbi', 'feeds', [ (int) $args['feed_id'] ]),
                ],
                'handler'      => static fn (array $args): array => self::update_feed((int) $args['feed_id'], (array) $args['settings']),
            ],
            'instagramfeed-clear-feed-cache' => $write + [
                'description'  => 'Clear an Instagram Feed feed\'s cache so the next page view fetches fresh posts from Instagram (include_posts false keeps the fetched posts and clears only the header and rendered data). Backups are kept. Undo: rollback-operation puts the cached data back',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'feed_id' => $feed, 'include_posts' => [ 'type' => 'boolean' ] ],
                    'required'   => [ 'feed_id' ],
                ],
                'validate'     => $known,
                'snapshot'     => static fn (array $args): array => [
                    'object_type' => Plugin_Table_Rows_Snapshot::TYPE,
                    'object_id'   => Plugin_Table_Rows_Snapshot::key('sbi', 'feed_caches', self::cache_ids_to_clear((int) $args['feed_id'], false !== ($args['include_posts'] ?? true))),
                ],
                'handler'      => static fn (array $args, array $context): array => self::clear_cache((int) $args['feed_id'], $context),
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
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', self::table('feeds'), $id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    private static function feed_refusal(int $id): ?array
    {
        if (null !== self::row($id)) {
            return null;
        }
        return [ 'code' => 'feed_not_found', 'message' => sprintf('Instagram Feed has no feed %d.', $id), 'data' => [ 'feed_id' => $id ] ];
    }

    /** @return array<string, mixed> */
    private static function settings_of(array $row): array
    {
        $settings = json_decode((string) ($row['settings'] ?? ''), true);
        return is_array($settings) ? $settings : [];
    }

    /**
     * The account ids a feed uses (settings 'id': a list, or one id the
     * saver collapsed from a one-element list).
     *
     * @return string[]
     */
    private static function account_ids(array $settings): array
    {
        $ids = $settings['id'] ?? [];
        $ids = is_array($ids) ? $ids : explode(',', (string) $ids);
        return array_values(array_filter(array_map(static fn ($id): string => trim((string) $id), $ids), static fn (string $id): bool => '' !== $id));
    }

    /** @return string[] The usernames of a feed's sources, from its source_details. */
    private static function usernames(array $settings): array
    {
        $details = $settings['source_details'] ?? [];
        if (is_array($details) && isset($details['username'])) {
            $details = [ $details ];
        }
        $out = [];
        foreach ((array) $details as $detail) {
            if (is_array($detail) && '' !== (string) ($detail['username'] ?? '')) {
                $out[] = (string) $detail['username'];
            }
        }
        return $out;
    }

    /** A settings array with every key that can hold a token removed, at any depth. */
    private static function redact(array $settings): array
    {
        $out = [];
        foreach ($settings as $key => $value) {
            if (1 === preg_match(self::HIDDEN_KEY, (string) $key)) {
                continue;
            }
            $out[ $key ] = is_array($value) ? self::redact($value) : $value;
        }
        return $out;
    }

    private static function table_ready(string $name): bool
    {
        return Plugin_Table_Rows_Snapshot::table_exists(self::table($name));
    }

    /** @return array<int, array<string, mixed>> Locator rows of a feed. */
    private static function placements(int $id): array
    {
        global $wpdb;
        if (! self::table_ready('instagram_feed_locator')) {
            return [];
        }
        $rows = (array) $wpdb->get_results($wpdb->prepare('SELECT post_id, html_location, last_update FROM %i WHERE feed_id = %s ORDER BY last_update DESC LIMIT 100', self::table('instagram_feed_locator'), '*' . $id), ARRAY_A);
        $out  = [];
        foreach ($rows as $row) {
            $post_id = (int) $row['post_id'];
            $post    = $post_id > 0 ? get_post($post_id) : null;
            $out[]   = [
                'post_id'   => $post_id,
                'title'     => $post instanceof \WP_Post ? (string) $post->post_title : null,
                'post_type' => $post instanceof \WP_Post ? (string) $post->post_type : null,
                'url'       => $post instanceof \WP_Post ? (string) get_permalink($post) : null,
                'location'  => (string) $row['html_location'],
                'seen_at'   => null !== $row['last_update'] ? (string) $row['last_update'] : null,
            ];
        }
        return $out;
    }

    /** @return array<int, int> feed id => embed count, for the given feeds. */
    private static function embed_counts(array $ids): array
    {
        global $wpdb;
        if ([] === $ids || ! self::table_ready('instagram_feed_locator')) {
            return [];
        }
        $keys = array_map(static fn (int $id): string => '*' . $id, $ids);
        $in   = implode(',', array_fill(0, count($keys), '%s'));
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in holds only %s placeholders, one per feed.
        $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT feed_id, COUNT(DISTINCT post_id) AS n FROM %i WHERE feed_id IN ($in) GROUP BY feed_id", self::table('instagram_feed_locator'), ...$keys), ARRAY_A);
        $out  = [];
        foreach ($rows as $row) {
            $out[ (int) ltrim((string) $row['feed_id'], '*') ] = (int) $row['n'];
        }
        return $out;
    }

    /** @return array<int, string> feed id => last_updated of its non-empty posts cache. */
    private static function posts_cached_at(array $ids): array
    {
        global $wpdb;
        if ([] === $ids || ! self::table_ready('feed_caches')) {
            return [];
        }
        $keys = array_map('strval', $ids);
        $in   = implode(',', array_fill(0, count($keys), '%s'));
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in holds only %s placeholders, one per feed.
        $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT feed_id, last_updated FROM %i WHERE cache_key = 'posts' AND cache_value <> '' AND feed_id IN ($in)", self::table('feed_caches'), ...$keys), ARRAY_A);
        $out  = [];
        foreach ($rows as $row) {
            $out[ (int) $row['feed_id'] ] = (string) $row['last_updated'];
        }
        return $out;
    }

    /**
     * The ids of a feed's cache rows that a clear blanks: every row of the
     * feed and of its _CUSTOMIZER variants except the two backups, and the
     * feed's own posts row only when $posts is true (SB_Instagram_Cache's
     * clear('all') then clear('posts')).
     *
     * @return int[]
     */
    private static function cache_ids_to_clear(int $id, bool $posts): array
    {
        global $wpdb;
        if (! self::table_ready('feed_caches')) {
            return [];
        }
        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, feed_id, cache_key FROM %i WHERE (feed_id = %s OR feed_id LIKE %s) AND cache_value <> '' ORDER BY id ASC",
                self::table('feed_caches'),
                (string) $id,
                $wpdb->esc_like($id . '_CUSTOMIZER') . '%'
            ),
            ARRAY_A
        );
        $ids = [];
        foreach ($rows as $row) {
            $own = (string) $id === (string) $row['feed_id'];
            $key = (string) $row['cache_key'];
            if ($own && (in_array($key, self::BACKUP_KEYS, true) || (! $posts && 'posts' === $key))) {
                continue;
            }
            $ids[] = (int) $row['id'];
        }
        return $ids;
    }

    /** Blank the given cache rows and drop the plugin's object cache entry for the feed. */
    private static function blank_cache_rows(int $feed, array $ids): int
    {
        global $wpdb;
        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));
        wp_cache_delete('sbi_feed_' . $feed . '_1');
        if ([] === $ids) {
            return 0;
        }
        $in = implode(',', array_fill(0, count($ids), '%d'));
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in holds only %d placeholders.
        $done = $wpdb->query($wpdb->prepare("UPDATE %i SET cache_value = '' WHERE id IN ($in)", self::table('feed_caches'), ...$ids));
        if (false === $done) {
            throw new Operation_Refused('cache_clear_failed', sprintf('Could not clear the cache of feed %d: %s', $feed, $wpdb->last_error));
        }
        return (int) $done;
    }

    // ------------------------------------------------------------------
    // Reads
    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    private static function list_feeds(array $args): array
    {
        global $wpdb;
        $status = (string) ($args['status'] ?? 'any');
        $size   = min(self::MAX_PAGE_SIZE, max(1, (int) ($args['page_size'] ?? 20)));
        $page   = max(1, (int) ($args['page'] ?? 1));
        $table  = self::table('feeds');
        $offset = ($page - 1) * $size;

        if ('any' === $status) {
            $total = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $table));
            $rows  = (array) $wpdb->get_results($wpdb->prepare('SELECT id, feed_name, feed_title, settings, status, last_modified FROM %i ORDER BY id ASC LIMIT %d OFFSET %d', $table, $size, $offset), ARRAY_A);
        } else {
            $total = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE status = %s', $table, $status));
            $rows  = (array) $wpdb->get_results($wpdb->prepare('SELECT id, feed_name, feed_title, settings, status, last_modified FROM %i WHERE status = %s ORDER BY id ASC LIMIT %d OFFSET %d', $table, $status, $size, $offset), ARRAY_A);
        }

        $ids    = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $embeds = self::embed_counts($ids);
        $cached = self::posts_cached_at($ids);
        $feeds  = [];
        foreach ($rows as $row) {
            $id       = (int) $row['id'];
            $settings = self::settings_of($row);
            $feeds[]  = [
                'id'              => $id,
                'name'            => (string) $row['feed_name'],
                'title'           => (string) $row['feed_title'],
                'type'            => (string) ($settings['type'] ?? 'user'),
                'status'          => (string) $row['status'],
                'last_modified'   => (string) $row['last_modified'],
                'shortcode'       => '[instagram-feed feed=' . $id . ']',
                'sources'         => self::usernames($settings),
                'embeds'          => $embeds[ $id ] ?? 0,
                'posts_cached_at' => $cached[ $id ] ?? null,
            ];
        }
        return [ 'feeds' => $feeds, 'total' => $total, 'page' => $page, 'page_size' => $size ];
    }

    /** @return array<string, mixed> */
    private static function feed(int $id): array
    {
        global $wpdb;
        $row      = (array) self::row($id);
        $settings = self::settings_of($row);
        $cache    = [];
        if (self::table_ready('feed_caches')) {
            $rows = (array) $wpdb->get_results($wpdb->prepare('SELECT feed_id, cache_key, cron_update, last_updated, CHAR_LENGTH(cache_value) AS size FROM %i WHERE feed_id = %s OR feed_id LIKE %s ORDER BY feed_id, cache_key', self::table('feed_caches'), (string) $id, $wpdb->esc_like($id . '_CUSTOMIZER') . '%'), ARRAY_A);
            foreach ($rows as $cached) {
                $updated = strtotime((string) $cached['last_updated'] . ' UTC');
                $cache[] = [
                    'key'         => (string) $cached['cache_key'],
                    'preview'     => (string) $id !== (string) $cached['feed_id'],
                    'empty'       => 0 === (int) $cached['size'],
                    'bytes'       => (int) $cached['size'],
                    'cron_update' => 'yes' === (string) $cached['cron_update'],
                    'updated_at'  => (string) $cached['last_updated'],
                    'age_seconds' => false !== $updated ? max(0, time() - $updated) : null,
                ];
            }
        }

        return [
            'id'            => $id,
            'name'          => (string) $row['feed_name'],
            'title'         => (string) $row['feed_title'],
            'type'          => (string) ($settings['type'] ?? 'user'),
            'status'        => (string) $row['status'],
            'last_modified' => (string) $row['last_modified'],
            'shortcode'     => '[instagram-feed feed=' . $id . ']',
            'sources'       => self::usernames($settings),
            'account_ids'   => self::account_ids($settings),
            'settings'      => self::redact($settings),
            'placements'    => self::placements($id),
            'cache'         => $cache,
        ];
    }

    /** @return array<string, mixed> The error code and message of a source, never the request it came from. */
    private static function source_error(string $raw): ?array
    {
        if ('' === trim($raw)) {
            return null;
        }
        $decoded = json_decode($raw, true);
        $error   = is_array($decoded) ? ($decoded['error'] ?? $decoded) : [ 'message' => $raw ];
        $error   = is_array($error) ? $error : [ 'message' => (string) $error ];
        $message = is_scalar($error['message'] ?? null) ? (string) $error['message'] : 'The account has a connection error.';
        $message = (string) preg_replace('/(access_token=)[^&\s"\']+/i', '$1[hidden]', wp_strip_all_tags($message));
        $code    = $error['code'] ?? null;

        return [
            'code'    => is_numeric($code) ? (int) $code : (is_scalar($code) ? (string) $code : null),
            'message' => function_exists('mb_substr') ? mb_substr($message, 0, 300) : substr($message, 0, 300),
        ];
    }

    /** @return array<string, mixed> */
    private static function sources(): array
    {
        global $wpdb;
        if (! self::table_ready('sources')) {
            return [ 'sources' => [], 'reconnect_url' => self::settings_url() ];
        }
        $rows  = (array) $wpdb->get_results($wpdb->prepare('SELECT id, account_id, account_type, connect_type, privilege, username, error, expires, last_updated FROM %i ORDER BY id ASC LIMIT %d', self::table('sources'), 500), ARRAY_A);
        $usage = [];
        foreach ((array) $wpdb->get_col($wpdb->prepare('SELECT settings FROM %i', self::table('feeds'))) as $json) {
            $settings = json_decode((string) $json, true);
            foreach (array_unique(self::account_ids(is_array($settings) ? $settings : [])) as $account) {
                $usage[ $account ] = ($usage[ $account ] ?? 0) + 1;
            }
        }

        $now  = time();
        $out  = [];
        foreach ($rows as $row) {
            $expires = (string) $row['expires'];
            $stamp   = '' !== $expires && ! str_starts_with($expires, '0000') ? strtotime($expires . ' UTC') : false;
            $stamp   = false !== $stamp && $stamp > 86400 ? $stamp : null;
            $error   = self::source_error((string) $row['error']);
            if (null !== $error) {
                $state = 'error';
            } elseif (null !== $stamp && $stamp <= $now) {
                $state = 'expired';
            } elseif (null !== $stamp && $stamp - $now < self::EXPIRY_WARNING_DAYS * DAY_IN_SECONDS) {
                $state = 'expiring_soon';
            } else {
                $state = 'connected';
            }
            $out[] = [
                'id'            => (int) $row['id'],
                'account_id'    => (string) $row['account_id'],
                'username'      => (string) $row['username'],
                'account_type'  => (string) $row['account_type'],
                'connect_type'  => (string) $row['connect_type'],
                'tagged'        => 'tagged' === (string) $row['privilege'],
                'state'         => $state,
                'expires'       => null !== $stamp ? gmdate('Y-m-d H:i:s', $stamp) : null,
                'error'         => $error,
                'last_updated'  => (string) $row['last_updated'],
                'used_in_feeds' => $usage[ (string) $row['account_id'] ] ?? 0,
            ];
        }
        return [ 'sources' => $out, 'reconnect_url' => self::settings_url() ];
    }

    // ------------------------------------------------------------------
    // Writes
    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    private static function update_feed(int $id, array $changes): array
    {
        global $wpdb;
        $stored   = self::settings_of((array) self::row($id));
        $writable = self::writable();
        $changed  = [];
        foreach ($changes as $key => $value) {
            if (! isset($writable[ $key ])) {
                throw new Operation_Refused('setting_not_writable', sprintf('%s is not a display setting this op changes.', (string) $key), [ 'writable' => array_keys($writable) ]);
            }
            // The Feed Builder stores scalars as strings and booleans as booleans.
            $stored[ $key ] = is_bool($value) ? $value : (string) $value;
            $changed[]      = (string) $key;
        }
        unset($stored['sources']);
        // SBI_Feed_Saver collapses a one-element source_details list on save; the plugin re-wraps it before a partial write.
        if (isset($stored['source_details']['id'], $stored['source_details']['username'])) {
            $stored['source_details'] = [ $stored['source_details'] ];
        }

        $done = $wpdb->update(
            self::table('feeds'),
            [ 'settings' => (string) wp_json_encode($stored), 'last_modified' => gmdate('Y-m-d H:i:s') ],
            [ 'id' => $id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );
        if (false === $done) {
            throw new Operation_Refused('update_failed', sprintf('Could not update feed %d: %s', $id, $wpdb->last_error));
        }
        $refetch = [] !== array_intersect(self::RETRIEVAL_KEYS, $changed);
        $cleared = self::blank_cache_rows($id, self::cache_ids_to_clear($id, $refetch));

        return [ 'updated' => $changed, 'cache_rows_cleared' => $cleared, 'posts_refetched' => $refetch, 'feed' => self::feed($id) ];
    }

    /** @return array<string, mixed> */
    private static function clear_cache(int $id, array $context): array
    {
        $parsed  = Plugin_Table_Rows_Snapshot::parse((string) ($context['target']['object_id'] ?? ''));
        $cleared = self::blank_cache_rows($id, (array) $parsed['ids']);

        return [ 'feed_id' => $id, 'cleared' => $cleared, 'note' => 'The next page view showing this feed fetches it again.' ];
    }
}
