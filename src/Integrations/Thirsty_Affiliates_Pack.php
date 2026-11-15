<?php

namespace WPMCP\Integrations;

use WPMCP\Safety\Post_Creation_Snapshot;
use WPMCP\Safety\Save_Filters;
use WPMCP\Tools\Content\Readable_Posts;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * ThirstyAffiliates (issue #682) on the plugin-data pair, free tier, so it
 * adds no top-level tools. Ops are prefixed thirstyaffiliates- because the
 * pair is shared.
 *
 * ThirstyAffiliates 3.12 keeps an affiliate link as a `thirstylink` post
 * (title = the link's name, post_name = its slug, the cloaked URL is the
 * permalink under the link prefix) with its settings in post meta under the
 * `_ta_` prefix (Models\Affiliate_Link): destination_url, redirect_type
 * (global, 301, 302 or 307), no_follow, new_window, uncloak_link and
 * pass_query_str (each global, yes or no), rel_tags and css_classes. Its
 * categories are the thirstylink-category taxonomy. Clicks are rows of
 * {prefix}ta_link_clicks (link_id, date_clicked) with their details as rows
 * of {prefix}ta_link_clicks_meta (user_ip_address, http_referer,
 * redirect_url, redirect_type, keyword, browser_device).
 *
 * Reads: thirstyaffiliates-list-links, -get-link and -get-link-clicks (visitor
 * IP masked, counts taken live), kept to the links the caller may read
 * (Readable_Posts); the destination URLs are for editors (edit_posts) and
 * the click report, which carries visitor data, for administrators
 * (manage_options, the capability ThirstyAffiliates' reports default to).
 * Writes are off until a site opts in with
 * wpmcp_enable_thirstyaffiliates_write, since a link redirects visitors:
 *  - create-link inserts a link post (published unless status says otherwise)
 *    with its meta and categories, recorded for rollback (Post_Creation_Snapshot:
 *    the undo moves it to the trash);
 *  - update-link changes the post and its `_ta_` meta under a post snapshot
 *    (fields, meta and terms), so rollback-operation puts it back;
 *  - delete-link moves the link to the WordPress trash under the same post
 *    snapshot, so the undo brings it back. Never a permanent delete.
 * They go through wp_insert_post, wp_update_post, wp_trash_post and
 * update_post_meta, as the plugin's own save() does, so its hooks run. Each
 * takes the link post type's own capabilities.
 *
 * Presence is filterable (wpmcp_thirstyaffiliates_active, default: the
 * ThirstyAffiliates() function exists).
 */
final class Thirsty_Affiliates_Pack
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reads ThirstyAffiliates' own click tables live; it has no cached read API for them.

    public const POST_TYPE = 'thirstylink';

    public const CATEGORY = 'thirstylink-category';

    public const OPT_IN_FILTER = 'wpmcp_enable_thirstyaffiliates_write';

    public const MAX_PAGE_SIZE = 100;

    public const PLUGIN_FILES = [ 'thirstyaffiliates/thirstyaffiliates.php' ];

    private const STATUSES = [ 'publish', 'draft', 'pending', 'private' ];

    private const TOGGLE = [ 'global', 'yes', 'no' ];

    /** arg => `_ta_` meta key, for the plain string settings. */
    private const META = [
        'url'            => 'destination_url',
        'redirect_type'  => 'redirect_type',
        'no_follow'      => 'no_follow',
        'new_window'     => 'new_window',
        'uncloak_link'   => 'uncloak_link',
        'pass_query_str' => 'pass_query_str',
        'rel_tags'       => 'rel_tags',
        'css_classes'    => 'css_classes',
    ];

    public static function active(): bool
    {
        return (bool) apply_filters('wpmcp_thirstyaffiliates_active', function_exists('ThirstyAffiliates') || defined('THIRSTY_VERSION'));
    }

    public static function table(string $name): string
    {
        global $wpdb;
        return $wpdb->prefix . 'ta_link_' . $name;
    }

    /** @return true|array{code: string, message: string, data: array} */
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
                'ThirstyAffiliates is %s on this site. Install and activate ThirstyAffiliates (%s), then try again.',
                $installed ? 'installed but not active' : 'not installed',
                $url
            ),
            'data'    => [ 'plugin' => 'thirstyaffiliates', 'plugins_url' => $url ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        $id     = [ 'type' => 'integer', 'minimum' => 1 ];
        $one    = [ 'type' => 'object', 'properties' => [ 'id' => $id ], 'required' => [ 'id' ] ];
        $fields = [
            'name'           => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 500 ],
            'slug'           => [ 'type' => 'string', 'maxLength' => 200 ],
            'url'            => [ 'type' => 'string', 'maxLength' => 2000 ],
            'status'         => [ 'type' => 'string', 'enum' => self::STATUSES ],
            'redirect_type'  => [ 'type' => 'string', 'enum' => [ 'global', '301', '302', '307' ] ],
            'no_follow'      => [ 'type' => 'string', 'enum' => self::TOGGLE ],
            'new_window'     => [ 'type' => 'string', 'enum' => self::TOGGLE ],
            'uncloak_link'   => [ 'type' => 'string', 'enum' => self::TOGGLE ],
            'pass_query_str' => [ 'type' => 'string', 'enum' => self::TOGGLE ],
            'rel_tags'       => [ 'type' => 'string', 'maxLength' => 200 ],
            'css_classes'    => [ 'type' => 'string', 'maxLength' => 200 ],
            'categories'     => [ 'type' => 'array', 'items' => $id, 'maxItems' => 50 ],
        ];
        $base   = [
            'capability' => 'edit_posts',
            'requires'   => static fn () => self::presence(),
        ];
        $write  = $base + [
            'enabled_by_default' => (bool) apply_filters('wpmcp_enable_thirstyaffiliates_write', false),
            'opt_in_filter'      => self::OPT_IN_FILTER,
        ];
        $own    = static fn (string $access): array => [ 'id' => [ 'type' => 'post', 'own_type' => true, 'access' => $access ] ];
        $target = static fn (array $args): array => [ 'object_type' => 'post', 'object_id' => (int) $args['id'] ];

        return [
            'thirstyaffiliates-list-links'      => $base + [
                'mode'         => 'read',
                'description'  => 'List ThirstyAffiliates links newest first: id, name, slug, cloaked URL, destination url, redirect type, toggles, categories, status, clicks. Filter by category (thirstylink-category id), status, search. page and per_page (max 100). Only links you may read',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'category' => $id,
                        'status'   => [ 'type' => 'string', 'enum' => self::STATUSES ],
                        'search'   => [ 'type' => 'string', 'maxLength' => 200 ],
                        'page'     => [ 'type' => 'integer', 'minimum' => 1 ],
                        'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PAGE_SIZE ],
                    ],
                ],
                'handler'      => static fn (array $args): array => self::list_links($args),
            ],
            'thirstyaffiliates-get-link'        => $base + [
                'mode'         => 'read',
                'objects'      => $own('read'),
                'description'  => 'Read one ThirstyAffiliates link with its settings, categories and live click count',
                'input_schema' => $one,
                'validate'     => static fn (array $args): ?array => self::link_refusal((int) $args['id'], false),
                'handler'      => static fn (array $args): array => [ 'link' => self::view(get_post((int) $args['id']), true) ],
            ],
            'thirstyaffiliates-get-link-clicks' => [ 'capability' => 'manage_options' ] + $base + [
                'mode'         => 'read',
                'objects'      => $own('read'),
                'description'  => 'Recorded clicks of one ThirstyAffiliates link, newest first: time, referer, redirect url and type, keyword, browser, visitor IP MASKED. from and to (YYYY-MM-DD, UTC), page_size (max 100) and offset. Total counted live',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'id'        => $id,
                        'from'      => [ 'type' => 'string', 'pattern' => '^\d{4}-\d{2}-\d{2}$' ],
                        'to'        => [ 'type' => 'string', 'pattern' => '^\d{4}-\d{2}-\d{2}$' ],
                        'page_size' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PAGE_SIZE ],
                        'offset'    => [ 'type' => 'integer', 'minimum' => 0 ],
                    ],
                    'required'   => [ 'id' ],
                ],
                'validate'     => static fn (array $args): ?array => self::link_refusal((int) $args['id'], false),
                'handler'      => static fn (array $args): array => self::clicks($args),
            ],
            'thirstyaffiliates-create-link'     => $write + [
                'mode'              => 'write',
                'self_snapshotting' => true,
                'description'       => 'Create a ThirstyAffiliates link: name and url (http or https) required; status defaults to publish; slug, redirect_type, toggles, categories (thirstylink-category ids). Undo: rollback-session trashes it',
                'input_schema'      => [ 'type' => 'object', 'properties' => $fields, 'required' => [ 'name', 'url' ] ],
                'validate'          => static fn (array $args): ?array => self::create_refusal($args),
                'handler'           => static fn (array $args, array $context): array => self::create($args, $context),
            ],
            'thirstyaffiliates-update-link'     => $write + [
                'mode'         => 'write',
                'objects'      => $own('write'),
                'description'  => 'Change a ThirstyAffiliates link; only the args given change, and categories replace the link\'s set. Undo: rollback-operation',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => $id ] + $fields, 'required' => [ 'id' ], 'minProperties' => 2 ],
                'validate'     => static fn (array $args): ?array => self::update_refusal($args),
                'snapshot'     => $target,
                'handler'      => static fn (array $args): array => self::update($args),
            ],
            'thirstyaffiliates-delete-link'     => $write + [
                'mode'         => 'write',
                'objects'      => $own('write'),
                'description'  => 'Move a ThirstyAffiliates link to the WordPress trash (its redirect stops; clicks are kept). Not a permanent delete. Undo: rollback-operation brings it back',
                'input_schema' => $one,
                'validate'     => static fn (array $args): ?array => self::delete_refusal((int) $args['id']),
                'snapshot'     => $target,
                'handler'      => static fn (array $args): array => self::trash((int) $args['id']),
            ],
        ];
    }

    // ------------------------------------------------------------ reads

    private static function refusal(string $code, string $message, array $data = []): array
    {
        return [ 'code' => $code, 'message' => $message, 'data' => $data ];
    }

    private static function link_refusal(int $id, bool $write): ?array
    {
        $post = get_post($id);
        if (! $post instanceof \WP_Post || self::POST_TYPE !== $post->post_type || 'trash' === $post->post_status) {
            return self::refusal('link_not_found', sprintf('%d is not a ThirstyAffiliates link.', $id), [ 'id' => $id ]);
        }
        if (! current_user_can($write ? 'edit_post' : 'read_post', $id)) {
            return self::refusal('operation_denied', sprintf('You are not allowed to %s link %d.', $write ? 'edit' : 'read', $id), [ 'reason' => 'capability', 'id' => $id ]);
        }
        return null;
    }

    private static function count_clicks(int $id): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE link_id = %d', self::table('clicks'), $id));
    }

    /** @return array<string, mixed> */
    private static function view(\WP_Post $post, bool $full): array
    {
        $meta = static fn (string $key): string => (string) get_post_meta($post->ID, '_ta_' . $key, true);
        $out  = [
            'id'             => (int) $post->ID,
            'name'           => (string) $post->post_title,
            'slug'           => (string) $post->post_name,
            'cloaked_url'    => (string) get_permalink($post),
            'url'            => $meta('destination_url'),
            'redirect_type'  => '' === $meta('redirect_type') ? 'global' : $meta('redirect_type'),
            'no_follow'      => '' === $meta('no_follow') ? 'global' : $meta('no_follow'),
            'new_window'     => '' === $meta('new_window') ? 'global' : $meta('new_window'),
            'uncloak_link'   => '' === $meta('uncloak_link') ? 'global' : $meta('uncloak_link'),
            'pass_query_str' => '' === $meta('pass_query_str') ? 'global' : $meta('pass_query_str'),
            'status'         => (string) $post->post_status,
            'categories'     => self::categories((int) $post->ID),
            'date'           => (string) $post->post_date,
            'modified'       => (string) $post->post_modified,
        ];
        if ($full) {
            $out['rel_tags']    = $meta('rel_tags');
            $out['css_classes'] = $meta('css_classes');
        }
        $out['clicks'] = self::count_clicks((int) $post->ID);
        return $out;
    }

    /** @return array<int, array{id: int, name: string, slug: string}> */
    private static function categories(int $id): array
    {
        if (! taxonomy_exists(self::CATEGORY)) {
            return [];
        }
        $terms = wp_get_post_terms($id, self::CATEGORY);
        return is_array($terms) ? array_map(static fn (\WP_Term $t): array => [ 'id' => (int) $t->term_id, 'name' => (string) $t->name, 'slug' => (string) $t->slug ], $terms) : [];
    }

    /** @return array<string, mixed> */
    private static function list_links(array $args): array
    {
        $query = [
            'post_type'      => self::POST_TYPE,
            'post_status'    => isset($args['status']) ? [ (string) $args['status'] ] : self::STATUSES,
            'posts_per_page' => (int) ($args['per_page'] ?? 20),
            'paged'          => (int) ($args['page'] ?? 1),
            'orderby'        => 'date',
            'order'          => 'DESC',
        ];
        if (isset($args['category'])) {
            $query['tax_query'] = [ [ 'taxonomy' => self::CATEGORY, 'field' => 'term_id', 'terms' => [ (int) $args['category'] ], 'include_children' => true ] ]; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- filtering links by their category is the point of the op.
        }
        if (isset($args['search']) && '' !== trim((string) $args['search'])) {
            $query['s'] = (string) $args['search'];
        }
        $q = Readable_Posts::query($query);
        return [ 'links' => array_map(static fn (\WP_Post $p): array => self::view($p, false), $q->posts), 'total' => (int) $q->found_posts ];
    }

    /** @return array<string, mixed> */
    private static function clicks(array $args): array
    {
        global $wpdb;
        $id     = (int) $args['id'];
        $where  = 'link_id = %d';
        $params = [ $id ];
        if (isset($args['from'])) {
            $where   .= ' AND date_clicked >= %s';
            $params[] = $args['from'] . ' 00:00:00';
        }
        if (isset($args['to'])) {
            $where   .= ' AND date_clicked <= %s';
            $params[] = $args['to'] . ' 23:59:59';
        }
        $size = max(1, min(self::MAX_PAGE_SIZE, (int) ($args['page_size'] ?? 20)));
        $skip = max(0, (int) ($args['offset'] ?? 0));
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $where holds only fixed column conditions and placeholders.
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE {$where}", self::table('clicks'), ...$params));
        $rows  = (array) $wpdb->get_results($wpdb->prepare("SELECT id, date_clicked FROM %i WHERE {$where} ORDER BY date_clicked DESC, id DESC LIMIT %d OFFSET %d", self::table('clicks'), ...array_merge($params, [ $size, $skip ])), ARRAY_A);
        // phpcs:enable

        $metas = [];
        $ids   = array_map('intval', array_column($rows, 'id'));
        if ([] !== $ids) {
            $in = implode(',', array_fill(0, count($ids), '%d'));
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in holds only %d placeholders.
            foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT click_id, meta_key, meta_value FROM %i WHERE click_id IN ($in)", self::table('clicks_meta'), ...$ids), ARRAY_A) as $meta) {
                $metas[ (int) $meta['click_id'] ][ (string) $meta['meta_key'] ] = (string) $meta['meta_value'];
            }
        }
        $clicks = [];
        foreach ($rows as $row) {
            $m        = $metas[ (int) $row['id'] ] ?? [];
            $clicks[] = [
                'id'            => (int) $row['id'],
                'date_clicked'  => (string) $row['date_clicked'],
                'referer'       => $m['http_referer'] ?? '',
                'redirect_url'  => $m['redirect_url'] ?? '',
                'redirect_type' => $m['redirect_type'] ?? '',
                'keyword'       => $m['keyword'] ?? '',
                'browser'       => $m['browser_device'] ?? '',
                'ip'            => Pretty_Links_Pack::mask_ip($m['user_ip_address'] ?? null),
            ];
        }
        return [
            'link_id'     => $id,
            'clicks'      => $clicks,
            'total'       => $total,
            'masked'      => true,
            'next_offset' => $skip + count($rows) < $total ? $skip + count($rows) : null,
        ];
    }

    // ------------------------------------------------------------ refusals

    private static function valid_url(string $url): bool
    {
        return 1 === preg_match('%^https?://%i', $url);
    }

    private static function field_refusal(array $args): ?array
    {
        if (isset($args['url']) && ! self::valid_url(trim((string) $args['url']))) {
            return self::refusal('url_invalid', 'The url must start with http:// or https://.', [ 'url' => (string) $args['url'] ]);
        }
        $missing = [];
        foreach ((array) ($args['categories'] ?? []) as $term_id) {
            if (! get_term((int) $term_id, self::CATEGORY) instanceof \WP_Term) {
                $missing[] = (int) $term_id;
            }
        }
        if ([] !== $missing) {
            return self::refusal('unknown_category', sprintf('Not a ThirstyAffiliates category: %s.', implode(', ', $missing)), [ 'unknown' => $missing ]);
        }
        return null;
    }

    private static function create_refusal(array $args): ?array
    {
        $object = get_post_type_object(self::POST_TYPE);
        if (! $object instanceof \WP_Post_Type) {
            return self::refusal('post_type_missing', 'ThirstyAffiliates has not registered its link post type.');
        }
        $status = (string) ($args['status'] ?? 'publish');
        $caps   = [ $object->cap->create_posts ];
        if (in_array($status, [ 'publish', 'private' ], true)) {
            $caps[] = $object->cap->publish_posts;
        }
        foreach ($caps as $cap) {
            if (! current_user_can($cap)) {
                return self::refusal('operation_denied', sprintf('This needs the %s capability.', $cap), [ 'reason' => 'capability' ]);
            }
        }
        return self::field_refusal($args);
    }

    private static function update_refusal(array $args): ?array
    {
        $id      = (int) $args['id'];
        $refused = self::link_refusal($id, true) ?? self::field_refusal($args);
        if (null !== $refused) {
            return $refused;
        }
        $status = (string) ($args['status'] ?? '');
        if (in_array($status, [ 'publish', 'private' ], true) && $status !== get_post_status($id) && ! current_user_can((string) get_post_type_object(self::POST_TYPE)->cap->publish_posts)) {
            return self::refusal('operation_denied', 'Publishing a link needs the publish_posts capability.', [ 'reason' => 'capability' ]);
        }
        return null;
    }

    private static function delete_refusal(int $id): ?array
    {
        $refused = self::link_refusal($id, true);
        if (null !== $refused) {
            return $refused;
        }
        return current_user_can('delete_post', $id) ? null : self::refusal('operation_denied', sprintf('You are not allowed to delete link %d.', $id), [ 'reason' => 'capability', 'id' => $id ]);
    }

    // ------------------------------------------------------------ writes

    private static function save_meta(int $id, array $args): void
    {
        foreach (self::META as $arg => $key) {
            if (array_key_exists($arg, $args)) {
                $value = 'url' === $arg ? trim((string) $args[ $arg ]) : (string) $args[ $arg ];
                update_post_meta($id, '_ta_' . $key, wp_slash($value));
            }
        }
        if (isset($args['categories'])) {
            $out = wp_set_object_terms($id, array_map('intval', (array) $args['categories']), self::CATEGORY);
            if (is_wp_error($out)) {
                throw new Operation_Refused('update_failed', $out->get_error_message());
            }
        }
    }

    /** @return array<string, mixed> */
    private static function create(array $args, array $context): array
    {
        $post = [
            'post_type'   => self::POST_TYPE,
            'post_title'  => wp_slash((string) $args['name']),
            'post_status' => (string) ($args['status'] ?? 'publish'),
            'post_author' => get_current_user_id(),
        ];
        if (isset($args['slug']) && '' !== trim((string) $args['slug'])) {
            $post['post_name'] = sanitize_title((string) $args['slug']);
        }
        $id = wp_insert_post($post, true);
        if (is_wp_error($id) || 0 === (int) $id) {
            throw new Operation_Refused('insert_failed', is_wp_error($id) ? $id->get_error_message() : 'ThirstyAffiliates could not create the link.');
        }
        $id = (int) $id;
        $op = Post_Creation_Snapshot::record((string) $context['tool_name'], [ $id ], [ 'operation' => $context['operation'], 'args' => $args ], (string) $context['session_id']);
        self::save_meta($id, $args);
        clean_post_cache($id);

        return [ 'result' => [ 'link' => self::view(get_post($id), true) ], 'operation_ids' => [ $op ] ];
    }

    /** @return array<string, mixed> */
    private static function update(array $args): array
    {
        $id     = (int) $args['id'];
        $fields = [ 'ID' => $id ];
        if (array_key_exists('name', $args)) {
            $fields['post_title'] = wp_slash((string) $args['name']);
        }
        if (isset($args['slug']) && '' !== trim((string) $args['slug'])) {
            $fields['post_name'] = sanitize_title((string) $args['slug']);
        }
        if (array_key_exists('status', $args)) {
            $fields['post_status'] = (string) $args['status'];
        }
        if (count($fields) > 1) {
            $out = Save_Filters::update_post($fields, true);
            if (is_wp_error($out)) {
                throw new Operation_Refused('update_failed', $out->get_error_message());
            }
        }
        self::save_meta($id, $args);
        clean_post_cache($id);

        return [ 'link' => self::view(get_post($id), true) ];
    }

    /** @return array<string, mixed> */
    private static function trash(int $id): array
    {
        $out = Save_Filters::trash_post($id);
        if (! $out) {
            throw new Operation_Refused('delete_failed', sprintf('Could not move link %d to the trash.', $id));
        }
        clean_post_cache($id);

        return [ 'link' => self::view(get_post($id), false) ];
    }
}
