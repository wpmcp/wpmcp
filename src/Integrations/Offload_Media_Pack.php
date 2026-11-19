<?php

namespace WPMCP\Integrations;

use WPMCP\MCP\Registrar;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * WP Offload Media (Lite and Pro) offload status (issue #701) on the
 * plugin-data pair, paid-tier, so it adds no top-level tools. Ops are
 * prefixed offloadmedia- because the pair is shared.
 *
 * WP Offload Media 3.x keeps one row per offloaded attachment in its
 * {prefix}as3cf_items table (provider, region, bucket, path = the object
 * key of the full size file, is_private, source_type 'media-library',
 * source_id = the attachment id) and, since 3.4, one row per offloaded
 * size in {prefix}as3cf_files (item_id, size, path). Its settings are the
 * tantan_wordpress_s3 option, which an AS3CF_SETTINGS define may override;
 * the plugin's own get_setting() is asked first when it is loaded so a
 * define wins as it does for the plugin. While the plugin delivers from the
 * bucket it rewrites wp_get_attachment_url() to the bucket or CDN URL, so
 * the url these ops report is the one the site really serves.
 *
 * Reads only:
 *  - offloadmedia-get-status (manage_options): edition and version, the
 *    storage provider, region and bucket, the offload and delivery
 *    switches, whether credentials are set (never the keys themselves) and
 *    how many attachments are offloaded;
 *  - offloadmedia-get-attachment (upload_files and read_post on the
 *    attachment): whether one attachment is in the bucket, its provider,
 *    bucket, object key, privacy, how many of its sizes are offloaded,
 *    whether the local file is still on disk and its served URL;
 *  - offloadmedia-list-attachments (upload_files): the same per attachment,
 *    filtered by offloaded or not_offloaded, paged.
 *
 * Offloading a file and removing its local copy are left out on purpose:
 * both talk to the remote bucket, and a removed local file cannot be put
 * back from a snapshot, so they need their own design (issue #701 notes).
 *
 * Presence is filterable (wpmcp_offload_media_active, default: the plugin's
 * main class is loaded or its main file constant is defined).
 */
final class Offload_Media_Pack
{
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reads WP Offload Media's own tables live; it has no cached read API for a filtered list.

    public const MAX_PER_PAGE = 100;

    public const SOURCE_TYPE = 'media-library';

    public const SETTINGS_OPTION = 'tantan_wordpress_s3';

    private const LITE_FILE = 'amazon-s3-and-cloudfront/wordpress-s3.php';

    private const PRO_FILE = 'amazon-s3-and-cloudfront-pro/amazon-s3-and-cloudfront-pro.php';

    /** @var string|null stands in for the items table name, for tests */
    private static ?string $items_table = null;

    public static function active(): bool
    {
        return Registrar::tier_permitted('pro') && self::loaded();
    }

    private static function loaded(): bool
    {
        return (bool) apply_filters(
            'wpmcp_offload_media_active',
            class_exists('Amazon_S3_And_CloudFront', false) || defined('AS3CF_FILE') || defined('AS3CFPRO_FILE')
        );
    }

    /** Point the items table elsewhere in tests. Null restores it. */
    public static function use_items_table_for_tests(?string $table): void
    {
        if (! defined('WPMCP_TESTING') || ! WPMCP_TESTING) {
            return;
        }
        self::$items_table = $table;
    }

    /**
     * plugin_missing or plugin_inactive naming WP Offload Media, the plugins
     * screen and the main file of whichever edition is installed.
     *
     * @return true|array{code: string, message: string, data: array}
     */
    public static function presence()
    {
        if (self::loaded()) {
            return true;
        }
        $file = defined('WP_PLUGIN_DIR') && file_exists(WP_PLUGIN_DIR . '/' . self::PRO_FILE) ? self::PRO_FILE : self::LITE_FILE;
        $refusal = Plugin_Refusal::make('plugin_missing', 'WP Offload Media', $file);
        if ('inactive' === ($refusal['data']['state'] ?? '')) {
            $refusal['code'] = 'plugin_inactive';
        }
        return $refusal;
    }

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        $id   = [ 'type' => 'integer', 'minimum' => 1 ];
        $base = [
            'tier'     => 'pro',
            'mode'     => 'read',
            'requires' => static fn () => self::presence(),
        ];

        return [
            'offloadmedia-get-status'       => $base + [
                'capability'   => 'manage_options',
                'description'  => 'WP Offload Media status: edition and version, storage provider, region and bucket, the copy, deliver and remove-local switches, delivery domain, whether credentials are set (keys are never returned), and attachments total, offloaded and not offloaded',
                'input_schema' => [ 'type' => 'object', 'properties' => (object) [] ],
                'handler'      => static fn (): array => self::status(),
            ],
            'offloadmedia-get-attachment'   => $base + [
                'capability'   => 'upload_files',
                'objects'      => [ 'id' => [ 'type' => 'post', 'own_type' => true, 'access' => 'read' ] ],
                'description'  => 'Whether one attachment is offloaded by WP Offload Media: provider, region, bucket, object key, private, sizes offloaded, whether the local file still exists, and the URL the site serves (the bucket or CDN URL while delivery is on)',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => $id ], 'required' => [ 'id' ] ],
                'validate'     => static fn (array $args): ?array => self::attachment_refusal((int) $args['id']),
                'handler'      => static fn (array $args): array => [ 'attachment' => self::view((int) $args['id'], true) ],
            ],
            'offloadmedia-list-attachments' => $base + [
                'capability'   => 'upload_files',
                'description'  => 'List attachments newest first with their WP Offload Media state: id, title, mime type, offloaded, provider, bucket, object key, private and served URL. state: offloaded, not_offloaded or any (default). page and per_page (max 100)',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'state'    => [ 'type' => 'string', 'enum' => [ 'offloaded', 'not_offloaded', 'any' ] ],
                        'page'     => [ 'type' => 'integer', 'minimum' => 1 ],
                        'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PER_PAGE ],
                    ],
                ],
                'handler'      => static fn (array $args): array => self::list_attachments($args),
            ],
        ];
    }

    // ------------------------------------------------------------ helpers

    private static function items_table(): string
    {
        global $wpdb;
        return self::$items_table ?? $wpdb->get_blog_prefix() . 'as3cf_items';
    }

    private static function files_table(): string
    {
        global $wpdb;
        return $wpdb->get_blog_prefix() . 'as3cf_files';
    }

    private static function table_exists(string $table): bool
    {
        global $wpdb;
        return $table === $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
    }

    /** One setting, through the plugin when it is loaded (so a define wins), else the stored option. */
    private static function setting(string $key)
    {
        global $as3cf;
        if (is_object($as3cf) && method_exists($as3cf, 'get_setting')) {
            try {
                return $as3cf->get_setting($key, '');
            } catch (\Throwable $e) {
                unset($e);
            }
        }
        $stored = get_option(self::SETTINGS_OPTION, []);
        return is_array($stored) ? ($stored[ $key ] ?? '') : '';
    }

    private static function flag(string $key): bool
    {
        $value = self::setting($key);
        return true === $value || 1 === $value || '1' === $value;
    }

    private static function text(string $key): ?string
    {
        $value = self::setting($key);
        return is_scalar($value) && '' !== (string) $value ? (string) $value : null;
    }

    /** @return array{edition: string, version: string|null} */
    private static function edition(): array
    {
        $pro  = class_exists('Amazon_S3_And_CloudFront_Pro', false) || defined('AS3CFPRO_FILE');
        $slug = $pro ? 'amazon-s3-and-cloudfront-pro' : 'amazon-s3-and-cloudfront';
        $meta = $GLOBALS['aws_meta'][ $slug ]['version'] ?? null;
        return [ 'edition' => $pro ? 'pro' : 'lite', 'version' => is_scalar($meta) ? (string) $meta : null ];
    }

    /**
     * The items rows of the given attachments, keyed by attachment id.
     *
     * @param int[] $ids
     * @return array<int, array<string, mixed>>
     */
    private static function items(array $ids): array
    {
        global $wpdb;
        if ([] === $ids || ! self::table_exists(self::items_table())) {
            return [];
        }
        $in   = implode(',', array_fill(0, count($ids), '%d'));
        $sql  = 'SELECT id, provider, region, bucket, path, is_private, source_id FROM %i WHERE source_type = %s AND source_id IN (' . $in . ')';
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in is a list of %d placeholders, one per id.
        $rows = (array) $wpdb->get_results($wpdb->prepare($sql, array_merge([ self::items_table(), self::SOURCE_TYPE ], $ids)), ARRAY_A);
        $out  = [];
        foreach ($rows as $row) {
            $out[ (int) $row['source_id'] ] = $row;
        }
        return $out;
    }

    private static function files_offloaded(int $item_id): ?int
    {
        global $wpdb;
        if (! self::table_exists(self::files_table())) {
            return null;
        }
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE item_id = %d', self::files_table(), $item_id));
    }

    /** @return array<string, mixed> */
    private static function view(int $id, bool $full, ?array $item = null): array
    {
        $item = $item ?? (self::items([ $id ])[ $id ] ?? null);
        $post = get_post($id);
        $view = [
            'id'        => $id,
            'title'     => (string) $post->post_title,
            'mime_type' => (string) $post->post_mime_type,
            'offloaded' => null !== $item,
            'provider'  => null !== $item ? (string) $item['provider'] : null,
            'region'    => null !== $item ? (string) $item['region'] : null,
            'bucket'    => null !== $item ? (string) $item['bucket'] : null,
            'key'       => null !== $item ? (string) $item['path'] : null,
            'private'   => null !== $item && 1 === (int) $item['is_private'],
            'url'       => (string) wp_get_attachment_url($id),
        ];
        if ($full) {
            $file                      = get_attached_file($id, true);
            $view['files_offloaded']   = null !== $item ? self::files_offloaded((int) $item['id']) : 0;
            $view['local_file_exists'] = is_string($file) && '' !== $file && file_exists($file);
        }
        return $view;
    }

    // ------------------------------------------------------------ reads

    /** @return array<string, mixed> */
    private static function status(): array
    {
        global $wpdb;
        $total     = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE post_type = %s', $wpdb->posts, 'attachment'));
        $offloaded = 0;
        if (self::table_exists(self::items_table())) {
            $offloaded = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE source_type = %s', self::items_table(), self::SOURCE_TYPE));
        }

        return self::edition() + [
            'provider'        => self::text('provider'),
            'region'          => self::text('region'),
            'bucket'          => self::text('bucket'),
            'credentials_set' => null !== self::text('access-key-id') || null !== self::text('key-file') || null !== self::text('key-file-path') || self::flag('use-server-roles'),
            'settings'        => [
                'copy_to_bucket'         => self::flag('copy-to-s3'),
                'deliver_from_bucket'    => self::flag('serve-from-s3'),
                'remove_local_file'      => self::flag('remove-local-file'),
                'object_prefix'          => self::flag('enable-object-prefix') ? self::text('object-prefix') : null,
                'year_month_folders'     => self::flag('use-yearmonth-folders'),
                'object_versioning'      => self::flag('object-versioning'),
                'delivery_provider'      => self::text('delivery-provider'),
                'delivery_domain'        => self::flag('enable-delivery-domain') ? self::text('delivery-domain') : null,
                'signed_urls'            => self::flag('enable-signed-urls'),
                'force_https'            => self::flag('force-https'),
            ],
            'attachments'     => [
                'total'         => $total,
                'offloaded'     => $offloaded,
                'not_offloaded' => max($total - $offloaded, 0),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function list_attachments(array $args): array
    {
        global $wpdb;
        $state    = (string) ($args['state'] ?? 'any');
        $per_page = min(self::MAX_PER_PAGE, max(1, (int) ($args['per_page'] ?? 20)));
        $page     = max(1, (int) ($args['page'] ?? 1));
        $has      = self::table_exists(self::items_table());

        if ('offloaded' === $state && ! $has) {
            return [ 'attachments' => [], 'total' => 0, 'page' => $page, 'per_page' => $per_page ];
        }
        $from   = 'FROM %i p';
        $params = [ $wpdb->posts ];
        $where  = "p.post_type = 'attachment' AND p.post_status IN ('inherit', 'private')";
        if ($has && 'any' !== $state) {
            $from    .= ' LEFT JOIN %i i ON i.source_type = %s AND i.source_id = p.ID';
            $params[] = self::items_table();
            $params[] = self::SOURCE_TYPE;
            $where   .= 'offloaded' === $state ? ' AND i.id IS NOT NULL' : ' AND i.id IS NULL';
        }
        $count_sql = 'SELECT COUNT(*) ' . $from . ' WHERE ' . $where;
        $page_sql  = 'SELECT p.ID ' . $from . ' WHERE ' . $where . ' ORDER BY p.post_date DESC, p.ID DESC LIMIT %d OFFSET %d';
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- built from literal fragments with %i, %s and %d placeholders only.
        $total = (int) $wpdb->get_var($wpdb->prepare($count_sql, $params));
        $ids   = array_map('intval', (array) $wpdb->get_col($wpdb->prepare($page_sql, array_merge($params, [ $per_page, ($page - 1) * $per_page ]))));
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

        $items = self::items($ids);
        $rows  = [];
        foreach ($ids as $id) {
            if (current_user_can('read_post', $id)) {
                $rows[] = self::view($id, false, $items[ $id ] ?? null);
            }
        }
        return [ 'attachments' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $per_page ];
    }

    // ------------------------------------------------------------ refusals

    private static function attachment_refusal(int $id): ?array
    {
        $post = get_post($id);
        if (! $post instanceof \WP_Post || 'attachment' !== $post->post_type) {
            return [ 'code' => 'attachment_not_found', 'message' => sprintf('%d is not an attachment in the Media Library.', $id), 'data' => [ 'id' => $id ] ];
        }
        if (! current_user_can('read_post', $id)) {
            return [ 'code' => 'operation_denied', 'message' => sprintf('You are not allowed to read attachment %d.', $id), 'data' => [ 'reason' => 'capability', 'id' => $id ] ];
        }
        return null;
    }
}
