<?php

namespace WPMCP\Integrations;

use WPMCP\Safety\Post_Creation_Snapshot;
use WPMCP\Safety\Save_Filters;
use WPMCP\Tools\Content\Readable_Posts;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Advanced Ads (issue #688) on the plugin-data pair, free tier, so it adds
 * no top-level tools. Ops are prefixed advancedads- because the pair is
 * shared.
 *
 * Advanced Ads 2.0 keeps an ad as an `advanced_ads` post (title, the ad code
 * in post_content, status publish when it shows and draft when it does not)
 * with its settings in the advanced_ads_ad_options post meta array (type,
 * width, height, url, description, image_id, position, conditions, allow_php
 * and the rest, Ad_Repository). Ad groups are terms of advanced_ads_groups.
 * A placement is an `advanced_ads_plcmnt` post with the post meta type,
 * item ("ad_<id>", "group_<id>" or empty) and options (Placement_Repository).
 * Both post types take the plugin's own capabilities, which its activation
 * grants to administrators: advanced_ads_edit_ads for ads and groups,
 * advanced_ads_manage_placements for placements.
 *
 * Reads: advancedads-list-ads, -get-ad (with the ad code and the placements
 * that show it) and -list-placements (with the ad or group each shows).
 * Ads carry no visitor data, so nothing needs masking.
 * Writes are off until a site opts in with wpmcp_enable_advanced_ads_write,
 * since an ad's code runs on every page it shows on:
 *  - create-ad inserts an ad as a draft (enable-ad shows it), recorded for
 *    rollback (Post_Creation_Snapshot: the undo moves it to the trash);
 *  - update-ad changes the title, code and the given settings, merged into
 *    the options array, under a post snapshot (fields and meta);
 *  - enable-ad and disable-ad set the ad's status to publish or draft, the
 *    switch the plugin's own list uses, under a post snapshot;
 *  - assign-placement sets which ad or group a placement shows, or clears
 *    it, under a post snapshot of the placement.
 * Code of a plain ad (raw HTML and JavaScript) is taken only from users with
 * unfiltered_html, as Advanced Ads itself requires, and other content goes
 * through WordPress's own save filters. allow_php is never set: it is not an
 * argument and update-ad keeps whatever the site chose.
 *
 * Presence is filterable (wpmcp_advanced_ads_active, default: ADVADS_VERSION
 * is defined).
 */
final class Advanced_Ads_Pack
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.

    public const AD = 'advanced_ads';

    public const PLACEMENT = 'advanced_ads_plcmnt';

    public const GROUP = 'advanced_ads_groups';

    public const OPTIONS = 'advanced_ads_ad_options';

    public const OPT_IN_FILTER = 'wpmcp_enable_advanced_ads_write';

    public const ADS_CAP = 'advanced_ads_edit_ads';

    public const PLACEMENTS_CAP = 'advanced_ads_manage_placements';

    public const MAX_PAGE_SIZE = 100;

    public const PLUGIN_FILES = [ 'advanced-ads/advanced-ads.php' ];

    private const STATUSES = [ 'publish', 'draft', 'pending', 'future', 'private', 'advanced_ads_expired' ];

    /** The ad types of the free plugin an agent may create. */
    private const TYPES = [ 'plain', 'content', 'image', 'dummy' ];

    public static function active(): bool
    {
        return (bool) apply_filters('wpmcp_advanced_ads_active', defined('ADVADS_VERSION'));
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
                'Advanced Ads is %s on this site. Install and activate Advanced Ads (%s), then try again.',
                $installed ? 'installed but not active' : 'not installed',
                $url
            ),
            'data'    => [ 'plugin' => 'advanced-ads', 'plugins_url' => $url ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        $id     = [ 'type' => 'integer', 'minimum' => 1 ];
        $one    = [ 'type' => 'object', 'properties' => [ 'id' => $id ], 'required' => [ 'id' ] ];
        $fields = [
            'title'       => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 500 ],
            'content'     => [ 'type' => 'string', 'maxLength' => 200000 ],
            'url'         => [ 'type' => 'string', 'maxLength' => 2000 ],
            'width'       => [ 'type' => 'integer', 'minimum' => 0, 'maximum' => 10000 ],
            'height'      => [ 'type' => 'integer', 'minimum' => 0, 'maximum' => 10000 ],
            'image_id'    => $id,
            'description' => [ 'type' => 'string', 'maxLength' => 2000 ],
        ];
        $base   = [
            'capability' => 'edit_posts',
            'requires'   => static fn () => self::presence(),
        ];
        $write  = $base + [
            'mode'               => 'write',
            'enabled_by_default' => (bool) apply_filters('wpmcp_enable_advanced_ads_write', false),
            'opt_in_filter'      => self::OPT_IN_FILTER,
        ];
        $ad     = static fn (string $access): array => [ 'id' => [ 'type' => 'post', 'own_type' => true, 'access' => $access ] ];
        $image  = [ 'image_id' => [ 'type' => 'post', 'access' => 'read' ] ];
        $target = static fn (array $args): array => [ 'object_type' => 'post', 'object_id' => (int) $args['id'] ];
        $page   = [
            'page'     => [ 'type' => 'integer', 'minimum' => 1 ],
            'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PAGE_SIZE ],
        ];

        return [
            'advancedads-list-ads'         => $base + [
                'mode'         => 'read',
                'description'  => 'List Advanced Ads ads by title: id, title, type, enabled, status, size, url, expiry, groups. Filter by status, type, group (advanced_ads_groups id), search. page and per_page (max 100). No ad code; get-ad has it',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'status' => [ 'type' => 'string', 'enum' => self::STATUSES ],
                        'type'   => [ 'type' => 'string', 'maxLength' => 50 ],
                        'group'  => $id,
                        'search' => [ 'type' => 'string', 'maxLength' => 200 ],
                    ] + $page,
                ],
                'validate'     => static fn (): ?array => self::cap_refusal(self::ADS_CAP),
                'handler'      => static fn (array $args): array => self::list_ads($args),
            ],
            'advancedads-get-ad'           => $base + [
                'mode'         => 'read',
                'objects'      => $ad('read'),
                'description'  => 'Read one Advanced Ads ad: code, settings, groups and the placements that show it',
                'input_schema' => $one,
                'validate'     => static fn (array $args): ?array => self::ad_refusal((int) $args['id']),
                'handler'      => static fn (array $args): array => [ 'ad' => self::view_ad(get_post((int) $args['id']), true) ],
            ],
            'advancedads-list-placements'  => $base + [
                'mode'         => 'read',
                'description'  => 'List Advanced Ads placements: id, title, slug, type, status and the ad or group each shows (item null when empty). Filter by type',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'type' => [ 'type' => 'string', 'maxLength' => 50 ] ] + $page,
                ],
                'validate'     => static fn (): ?array => self::cap_refusal(self::PLACEMENTS_CAP),
                'handler'      => static fn (array $args): array => self::list_placements($args),
            ],
            'advancedads-create-ad'        => $write + [
                'self_snapshotting' => true,
                'objects'           => $image,
                'description'       => 'Create an Advanced Ads ad as a draft (enable-ad shows it): title and type (plain, content, image, dummy) required; content (plain needs unfiltered_html), url, width, height, image_id (image ads), description. Undo: rollback-session trashes it',
                'input_schema'      => [
                    'type'                 => 'object',
                    'properties'           => [ 'type' => [ 'type' => 'string', 'enum' => self::TYPES ] ] + $fields,
                    'required'             => [ 'title', 'type' ],
                    'additionalProperties' => false,
                ],
                'validate'          => static fn (array $args): ?array => self::create_refusal($args),
                'handler'           => static fn (array $args, array $context): array => self::create($args, $context),
            ],
            'advancedads-update-ad'        => $write + [
                'objects'      => $ad('write') + $image,
                'description'  => 'Change an Advanced Ads ad; only the args given change, merged into its settings. allow_php is never changed. Undo: rollback-operation',
                'input_schema' => [
                    'type'                 => 'object',
                    'properties'           => [ 'id' => $id ] + $fields,
                    'required'             => [ 'id' ],
                    'minProperties'        => 2,
                    'additionalProperties' => false,
                ],
                'validate'     => static fn (array $args): ?array => self::update_refusal($args),
                'snapshot'     => $target,
                'handler'      => static fn (array $args): array => self::update($args),
            ],
            'advancedads-enable-ad'        => $write + [
                'objects'      => $ad('write'),
                'description'  => 'Show an Advanced Ads ad (status publish). Undo: rollback-operation',
                'input_schema' => $one,
                'validate'     => static fn (array $args): ?array => self::ad_refusal((int) $args['id']),
                'snapshot'     => $target,
                'handler'      => static fn (array $args): array => self::set_status((int) $args['id'], 'publish'),
            ],
            'advancedads-disable-ad'       => $write + [
                'objects'      => $ad('write'),
                'description'  => 'Stop showing an Advanced Ads ad (status draft); its placements keep it. Undo: rollback-operation',
                'input_schema' => $one,
                'validate'     => static fn (array $args): ?array => self::ad_refusal((int) $args['id']),
                'snapshot'     => $target,
                'handler'      => static fn (array $args): array => self::set_status((int) $args['id'], 'draft'),
            ],
            'advancedads-assign-placement' => $write + [
                'objects'      => [
                    'id'       => [ 'type' => 'post', 'own_type' => true, 'access' => 'write' ],
                    'ad_id'    => [ 'type' => 'post', 'own_type' => true, 'access' => 'read' ],
                    'group_id' => [ 'type' => 'term', 'access' => 'read' ],
                ],
                'description'  => 'Set what an Advanced Ads placement shows: exactly one of ad_id, group_id (advanced_ads_groups id) or clear:true. Undo: rollback-operation',
                'input_schema' => [
                    'type'                 => 'object',
                    'properties'           => [ 'id' => $id, 'ad_id' => $id, 'group_id' => $id, 'clear' => [ 'type' => 'boolean' ] ],
                    'required'             => [ 'id' ],
                    'additionalProperties' => false,
                ],
                'validate'     => static fn (array $args): ?array => self::assign_refusal($args),
                'snapshot'     => $target,
                'handler'      => static fn (array $args): array => self::assign($args),
            ],
        ];
    }

    // ------------------------------------------------------------ refusals

    private static function refusal(string $code, string $message, array $data = []): array
    {
        return [ 'code' => $code, 'message' => $message, 'data' => $data ];
    }

    /** Advanced Ads lets administrators through, then asks for its own capability (Conditional::user_can). */
    private static function can(string $cap): bool
    {
        return current_user_can('manage_options') || current_user_can($cap);
    }

    private static function cap_refusal(string $cap): ?array
    {
        return self::can($cap) ? null : self::refusal('operation_denied', sprintf('This needs the %s capability (Advanced Ads grants it to administrators).', $cap), [ 'reason' => 'capability', 'capability' => $cap ]);
    }

    private static function is_ad($post): bool
    {
        return $post instanceof \WP_Post && self::AD === $post->post_type && 'trash' !== $post->post_status;
    }

    private static function ad_refusal(int $id): ?array
    {
        if (! self::is_ad(get_post($id))) {
            return self::refusal('ad_not_found', sprintf('%d is not an Advanced Ads ad.', $id), [ 'id' => $id ]);
        }
        return self::cap_refusal(self::ADS_CAP);
    }

    private static function field_refusal(array $args, string $type): ?array
    {
        if (isset($args['url']) && '' !== trim((string) $args['url']) && 1 !== preg_match('%^https?://%i', trim((string) $args['url']))) {
            return self::refusal('url_invalid', 'The url must start with http:// or https://.', [ 'url' => (string) $args['url'] ]);
        }
        if (isset($args['image_id']) || ('image' === $type && ! isset($args['id']))) {
            $image = (int) ($args['image_id'] ?? 0);
            if (! wp_attachment_is_image($image)) {
                return self::refusal('image_not_found', 'An image ad needs image_id, the id of an image in the media library.', [ 'image_id' => $image ]);
            }
        }
        if ('plain' === $type && array_key_exists('content', $args) && ! current_user_can('unfiltered_html')) {
            return self::refusal('operation_denied', 'The code of a plain ad is raw HTML and JavaScript, so it needs the unfiltered_html capability.', [ 'reason' => 'capability', 'capability' => 'unfiltered_html' ]);
        }
        return null;
    }

    private static function create_refusal(array $args): ?array
    {
        if (! post_type_exists(self::AD)) {
            return self::refusal('post_type_missing', 'Advanced Ads has not registered its ad post type.');
        }
        return self::cap_refusal(self::ADS_CAP) ?? self::field_refusal($args, (string) $args['type']);
    }

    private static function update_refusal(array $args): ?array
    {
        $id = (int) $args['id'];
        return self::ad_refusal($id) ?? self::field_refusal($args, self::type_of($id));
    }

    private static function assign_refusal(array $args): ?array
    {
        $id   = (int) $args['id'];
        $post = get_post($id);
        if (! $post instanceof \WP_Post || self::PLACEMENT !== $post->post_type || 'trash' === $post->post_status) {
            return self::refusal('placement_not_found', sprintf('%d is not an Advanced Ads placement.', $id), [ 'id' => $id ]);
        }
        $refused = self::cap_refusal(self::PLACEMENTS_CAP);
        if (null !== $refused) {
            return $refused;
        }
        $given = (int) isset($args['ad_id']) + (int) isset($args['group_id']) + (int) ! empty($args['clear']);
        if (1 !== $given) {
            return self::refusal('item_required', 'Give exactly one of ad_id, group_id or clear:true.');
        }
        if (isset($args['ad_id']) && ! self::is_ad(get_post((int) $args['ad_id']))) {
            return self::refusal('ad_not_found', sprintf('%d is not an Advanced Ads ad.', (int) $args['ad_id']), [ 'id' => (int) $args['ad_id'] ]);
        }
        if (isset($args['group_id']) && ! get_term((int) $args['group_id'], self::GROUP) instanceof \WP_Term) {
            return self::refusal('group_not_found', sprintf('%d is not an Advanced Ads group.', (int) $args['group_id']), [ 'id' => (int) $args['group_id'] ]);
        }
        return null;
    }

    // ------------------------------------------------------------ reads

    /** @return array<string, mixed> */
    private static function options(int $id): array
    {
        $options = get_post_meta($id, self::OPTIONS, true);
        return is_array($options) ? $options : [];
    }

    private static function type_of(int $id): string
    {
        return (string) (self::options($id)['type'] ?? 'dummy');
    }

    /** @return array<string, mixed> */
    private static function view_ad(\WP_Post $post, bool $full): array
    {
        $o   = self::options((int) $post->ID);
        $out = [
            'id'          => (int) $post->ID,
            'title'       => (string) $post->post_title,
            'type'        => (string) ($o['type'] ?? 'dummy'),
            'enabled'     => 'publish' === $post->post_status,
            'status'      => (string) $post->post_status,
            'width'       => (int) ($o['width'] ?? 0),
            'height'      => (int) ($o['height'] ?? 0),
            'url'         => (string) ($o['url'] ?? ''),
            'expiry_date' => (int) ($o['expiry_date'] ?? 0),
            'groups'      => self::groups((int) $post->ID),
            'modified'    => (string) $post->post_modified,
        ];
        if ($full) {
            $out['content']          = (string) $post->post_content;
            $out['description']      = (string) ($o['description'] ?? '');
            $out['image_id']         = (int) ($o['image_id'] ?? 0);
            $out['position']         = (string) ($o['position'] ?? 'none');
            $out['allow_shortcodes'] = ! empty($o['allow_shortcodes']);
            $out['allow_php']        = ! empty($o['allow_php']);
            $out['placements']       = self::placements_showing('ad_' . (int) $post->ID);
        }
        return $out;
    }

    /** @return array<int, array{id: int, name: string}> */
    private static function groups(int $id): array
    {
        if (! taxonomy_exists(self::GROUP)) {
            return [];
        }
        $terms = wp_get_post_terms($id, self::GROUP);
        return is_array($terms) ? array_map(static fn (\WP_Term $t): array => [ 'id' => (int) $t->term_id, 'name' => (string) $t->name ], $terms) : [];
    }

    /** @return array<int, array{id: int, title: string}> */
    private static function placements_showing(string $item): array
    {
        $posts = Readable_Posts::get_posts([
            'post_type'      => self::PLACEMENT,
            'post_status'    => [ 'publish', 'draft' ],
            'posts_per_page' => self::MAX_PAGE_SIZE,
            'meta_key'       => 'item', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- the placement's item is where Advanced Ads keeps the link.
            'meta_value'     => $item, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- as above.
        ]);
        return array_map(static fn (\WP_Post $p): array => [ 'id' => (int) $p->ID, 'title' => (string) $p->post_title ], $posts);
    }

    /** @return array<string, mixed> */
    private static function list_ads(array $args): array
    {
        $query = [
            'post_type'      => self::AD,
            'post_status'    => isset($args['status']) ? [ (string) $args['status'] ] : self::STATUSES,
            'posts_per_page' => (int) ($args['per_page'] ?? 20),
            'paged'          => (int) ($args['page'] ?? 1),
            'orderby'        => 'title',
            'order'          => 'ASC',
        ];
        if (isset($args['group'])) {
            $query['tax_query'] = [ [ 'taxonomy' => self::GROUP, 'field' => 'term_id', 'terms' => [ (int) $args['group'] ] ] ]; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- filtering ads by their group is the point of the op.
        }
        if (isset($args['search']) && '' !== trim((string) $args['search'])) {
            $query['s'] = (string) $args['search'];
        }
        if (isset($args['type']) && '' !== (string) $args['type']) {
            // The type sits inside the serialized options array, so match it there.
            $query['meta_query'] = [ [ 'key' => self::OPTIONS, 'value' => serialize('type') . serialize((string) $args['type']), 'compare' => 'LIKE' ] ]; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query, WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- the serialized options array is how Advanced Ads stores the type.
        }
        $q = Readable_Posts::query($query);
        return [ 'ads' => array_map(static fn (\WP_Post $p): array => self::view_ad($p, false), $q->posts), 'total' => (int) $q->found_posts ];
    }

    /** @return array{kind: string, id: int, title: string}|null */
    private static function item(string $item): ?array
    {
        if (1 !== preg_match('/^(ad|group)_(\d+)$/', $item, $m)) {
            return null;
        }
        $id = (int) $m[2];
        if ('ad' === $m[1]) {
            $post = get_post($id);
            return [ 'kind' => 'ad', 'id' => $id, 'title' => self::is_ad($post) ? (string) $post->post_title : '' ];
        }
        $term = get_term($id, self::GROUP);
        return [ 'kind' => 'group', 'id' => $id, 'title' => $term instanceof \WP_Term ? (string) $term->name : '' ];
    }

    /** @return array<string, mixed> */
    private static function view_placement(\WP_Post $post): array
    {
        $type = (string) get_post_meta($post->ID, 'type', true);
        return [
            'id'     => (int) $post->ID,
            'title'  => (string) $post->post_title,
            'slug'   => (string) $post->post_name,
            'type'   => '' === $type ? 'default' : $type,
            'status' => (string) $post->post_status,
            'item'   => self::item((string) get_post_meta($post->ID, 'item', true)),
        ];
    }

    /** @return array<string, mixed> */
    private static function list_placements(array $args): array
    {
        $query = [
            'post_type'      => self::PLACEMENT,
            'post_status'    => [ 'publish', 'draft', 'pending', 'private' ],
            'posts_per_page' => (int) ($args['per_page'] ?? 50),
            'paged'          => (int) ($args['page'] ?? 1),
            'orderby'        => 'title',
            'order'          => 'ASC',
        ];
        if (isset($args['type']) && '' !== (string) $args['type']) {
            $query['meta_key']   = 'type'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- the placement type is post meta.
            $query['meta_value'] = (string) $args['type']; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- as above.
        }
        $q = Readable_Posts::query($query);
        return [ 'placements' => array_map(static fn (\WP_Post $p): array => self::view_placement($p), $q->posts), 'total' => (int) $q->found_posts ];
    }

    // ------------------------------------------------------------ writes

    /**
     * Merge the given settings into the options array, as Ad_Repository
     * saves them. Keys the call does not name, allow_php among them, stay.
     */
    private static function save_options(int $id, array $args, ?string $type = null): void
    {
        $options = self::options($id);
        if (null !== $type) {
            $options['type'] = $type;
        }
        foreach ([ 'width', 'height', 'image_id' ] as $key) {
            if (array_key_exists($key, $args)) {
                $options[ $key ] = (int) $args[ $key ];
            }
        }
        if (array_key_exists('url', $args)) {
            $options['url'] = esc_url_raw(trim((string) $args['url']));
        }
        if (array_key_exists('description', $args)) {
            $options['description'] = sanitize_textarea_field((string) $args['description']);
        }
        update_post_meta($id, self::OPTIONS, $options);
    }

    /** @return array<string, mixed> */
    private static function create(array $args, array $context): array
    {
        $post = [
            'post_type'      => self::AD,
            'post_title'     => wp_slash((string) $args['title']),
            'post_content'   => wp_slash((string) ($args['content'] ?? '')),
            'post_status'    => 'draft',
            'post_author'    => get_current_user_id(),
            'comment_status' => 'closed',
            'ping_status'    => 'closed',
        ];
        $id   = wp_insert_post($post, true);
        if (is_wp_error($id) || 0 === (int) $id) {
            throw new Operation_Refused('insert_failed', is_wp_error($id) ? $id->get_error_message() : 'Advanced Ads could not create the ad.');
        }
        $id = (int) $id;
        $op = Post_Creation_Snapshot::record((string) $context['tool_name'], [ $id ], [ 'operation' => $context['operation'], 'args' => $args ], (string) $context['session_id']);
        self::save_options($id, $args, (string) $args['type']);
        if (defined('ADVADS_VERSION')) {
            update_post_meta($id, '_ad_version', (string) constant('ADVADS_VERSION'));
        }
        clean_post_cache($id);

        return [ 'result' => [ 'ad' => self::view_ad(get_post($id), true) ], 'operation_ids' => [ $op ] ];
    }

    /** @return array<string, mixed> */
    private static function update(array $args): array
    {
        $id     = (int) $args['id'];
        $fields = [ 'ID' => $id ];
        if (array_key_exists('title', $args)) {
            $fields['post_title'] = wp_slash((string) $args['title']);
        }
        if (array_key_exists('content', $args)) {
            $fields['post_content'] = wp_slash((string) $args['content']);
        }
        if (count($fields) > 1) {
            $out = Save_Filters::update_post($fields, true);
            if (is_wp_error($out)) {
                throw new Operation_Refused('update_failed', $out->get_error_message());
            }
        }
        self::save_options($id, $args);
        clean_post_cache($id);

        return [ 'ad' => self::view_ad(get_post($id), true) ];
    }

    /** @return array<string, mixed> */
    private static function set_status(int $id, string $status): array
    {
        $out = Save_Filters::set_post_status($id, $status, true);
        if (is_wp_error($out)) {
            throw new Operation_Refused('update_failed', $out->get_error_message());
        }
        clean_post_cache($id);

        return [ 'ad' => self::view_ad(get_post($id), false) ];
    }

    /** @return array<string, mixed> */
    private static function assign(array $args): array
    {
        $id   = (int) $args['id'];
        $item = '';
        if (isset($args['ad_id'])) {
            $item = 'ad_' . (int) $args['ad_id'];
        } elseif (isset($args['group_id'])) {
            $item = 'group_' . (int) $args['group_id'];
        }
        update_post_meta($id, 'item', $item);
        clean_post_cache($id);

        return [ 'placement' => self::view_placement(get_post($id)) ];
    }
}
