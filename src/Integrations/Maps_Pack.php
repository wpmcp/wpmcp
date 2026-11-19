<?php

namespace WPMCP\Integrations;

use WPMCP\Privacy\Masking;
use WPMCP\Safety\Plugin_Table_Rows_Snapshot;
use WPMCP\Safety\Post_Creation_Snapshot;
use WPMCP\Safety\Safe_Mutation;
use WPMCP\Safety\Save_Filters;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Content\Readable_Posts;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Maps and store locators (issue #907) on the plugin-data pair, free tier,
 * so they add no top-level tools: WP Go Maps (wpgomaps-), WP Store Locator
 * (wpsl-) and WP Maps (wpmaps-).
 *
 * WP Go Maps 10 keeps maps in {prefix}wpgmza_maps (active 0 is a live map,
 * 1 a trashed one) and markers in {prefix}wpgmza, whose latlng POINT column
 * mirrors the lat and lng text columns ("POINT(lat lng)", the way its Marker
 * class writes it). A marker write sets all three together and is
 * snapshotted as a 'plugin_table_rows' image of kind wpgmza (the POINT bytes
 * ride as hex), so the undo puts the row back exactly, or removes a row the
 * write inserted (its id is reserved before the snapshot). Both plugins on a
 * table gate their admin behind manage_options, and so do these ops.
 *
 * WP Store Locator 3 keeps a store as a wpsl_stores post (capability type
 * store, granted to administrators) with wpsl_<field> post meta and
 * wpsl_store_category terms. A store write runs under a post snapshot, a new
 * store is recorded with Post_Creation_Snapshot (the undo trashes it) and a
 * delete moves it to the trash under a post snapshot. Phone, fax and email
 * are masked in lists and in a single read below manage_options.
 *
 * WP Maps (Flippercode, wp-google-map-plugin) is read-only here: maps in
 * {prefix}create_map, whose map_locations holds the ids of its locations in
 * {prefix}map_locations. Its writes are a follow-up.
 *
 * Geocoding: a marker or store given an address and no coordinates is
 * geocoded with the site's own configuration: WP Store Locator through its
 * own geocode service (whatever provider and key the site chose), WP Go Maps
 * through the Google Geocoding API with the key in its settings. No key is
 * ever returned: settings reads drop every key and error messages have the
 * key cut out. Without a key the row is refused with coordinates_required.
 *
 * Batch writes take up to 50 rows and answer per row, each row its own undo
 * point in the call's session, so rollback-session undoes the whole call.
 * Writes are off until a site opts in with wpmcp_enable_maps_write.
 *
 * Presence is filterable (wpmcp_wp_go_maps_active, default WPGMZA_VERSION
 * defined; wpmcp_store_locator_active, default WPSL_VERSION_NUM defined;
 * wpmcp_wp_maps_active, default WPGMP_VERSION defined).
 */
final class Maps_Pack
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reads and writes the map plugins' own tables live; they have no cached read API for them.

    public const OPT_IN_FILTER = 'wpmcp_enable_maps_write';

    public const STORE = 'wpsl_stores';

    public const STORE_CATEGORY = 'wpsl_store_category';

    public const MAX_BATCH = 50;

    public const MAX_PAGE_SIZE = 100;

    /** The wpsl_<field> meta an op reads and writes. */
    private const STORE_FIELDS = [ 'address', 'address2', 'city', 'state', 'zip', 'country', 'lat', 'lng', 'phone', 'fax', 'email', 'url' ];

    /** The address parts WP Store Locator geocodes from. */
    private const ADDRESS_PARTS = [ 'address', 'city', 'state', 'zip', 'country' ];

    /** Store fields masked in lists and below manage_options. */
    private const STORE_PII = [ 'phone', 'fax', 'email' ];

    /** WP Store Locator settings groups (options wpsl_<group>). */
    private const STORE_SETTINGS = [ 'api', 'map', 'search', 'appearance', 'markers', 'local_pages' ];

    private const STORE_STATUSES = [ 'publish', 'draft', 'pending', 'private' ];

    // ------------------------------------------------------------ presence

    public static function go_maps_active(): bool
    {
        return (bool) apply_filters('wpmcp_wp_go_maps_active', defined('WPGMZA_VERSION'));
    }

    public static function store_locator_active(): bool
    {
        return (bool) apply_filters('wpmcp_store_locator_active', defined('WPSL_VERSION_NUM'));
    }

    public static function wp_maps_active(): bool
    {
        return (bool) apply_filters('wpmcp_wp_maps_active', defined('WPGMP_VERSION'));
    }

    public static function active(): bool
    {
        return self::go_maps_active() || self::store_locator_active() || self::wp_maps_active();
    }

    /** @return true|array{code: string, message: string, data: array} */
    private static function go_maps_presence()
    {
        if (! self::go_maps_active()) {
            return Plugin_Refusal::make('wp_go_maps_inactive', 'WP Go Maps');
        }
        foreach ([ self::markers_table(), self::go_maps_table() ] as $table) {
            if (! Plugin_Table_Rows_Snapshot::table_exists($table)) {
                return [ 'code' => 'wp_go_maps_tables_missing', 'message' => sprintf('WP Go Maps is active but its table %s is missing. Open its settings once so it installs its tables, then try again.', $table), 'data' => [ 'table' => $table ] ];
            }
        }
        return true;
    }

    /** @return true|array{code: string, message: string, data: array} */
    private static function store_locator_presence()
    {
        if (! self::store_locator_active()) {
            return Plugin_Refusal::make('store_locator_inactive', 'WP Store Locator');
        }
        if (! post_type_exists(self::STORE)) {
            return [ 'code' => 'post_type_missing', 'message' => 'WP Store Locator has not registered its wpsl_stores post type.', 'data' => [] ];
        }
        return true;
    }

    /** @return true|array{code: string, message: string, data: array} */
    private static function wp_maps_presence()
    {
        if (! self::wp_maps_active()) {
            return Plugin_Refusal::make('wp_maps_inactive', 'WP Maps');
        }
        foreach ([ self::wp_maps_table(), self::locations_table() ] as $table) {
            if (! Plugin_Table_Rows_Snapshot::table_exists($table)) {
                return [ 'code' => 'wp_maps_tables_missing', 'message' => sprintf('WP Maps is active but its table %s is missing.', $table), 'data' => [ 'table' => $table ] ];
            }
        }
        return true;
    }

    public static function markers_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'wpgmza';
    }

    public static function go_maps_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'wpgmza_maps';
    }

    public static function wp_maps_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'create_map';
    }

    public static function locations_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'map_locations';
    }

    // ------------------------------------------------------------ catalog

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        return array_merge(self::go_maps_operations(), self::store_locator_operations(), self::wp_maps_operations());
    }

    /** @return array<string, array<string, mixed>> */
    private static function page_schema(): array
    {
        return [
            'page'     => [ 'type' => 'integer', 'minimum' => 1 ],
            'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PAGE_SIZE ],
        ];
    }

    /** @return array<string, mixed> */
    private static function write_base(): array
    {
        return [
            'mode'               => 'write',
            'enabled_by_default' => (bool) apply_filters('wpmcp_enable_maps_write', false),
            'opt_in_filter'      => self::OPT_IN_FILTER,
            'self_snapshotting'  => true,
        ];
    }

    /** @return array<string, mixed> */
    private static function coordinate_schema(): array
    {
        return [
            'lat' => [ 'type' => 'number', 'minimum' => -90, 'maximum' => 90 ],
            'lng' => [ 'type' => 'number', 'minimum' => -180, 'maximum' => 180 ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function go_maps_operations(): array
    {
        $id   = [ 'type' => 'integer', 'minimum' => 1 ];
        $base = [
            'capability' => 'manage_options',
            'requires'   => static fn () => self::go_maps_presence(),
        ];
        $row  = [
            'type'                 => 'object',
            'properties'           => [
                'id'          => $id,
                'map_id'      => $id,
                'title'       => [ 'type' => 'string', 'maxLength' => 700 ],
                'address'     => [ 'type' => 'string', 'maxLength' => 700 ],
                'description' => [ 'type' => 'string', 'maxLength' => 20000 ],
                'link'        => [ 'type' => 'string', 'maxLength' => 2083 ],
                'category'    => [ 'type' => 'string', 'maxLength' => 500 ],
                'approved'    => [ 'type' => 'boolean' ],
            ] + self::coordinate_schema(),
            'additionalProperties' => false,
        ];

        return [
            'wpgomaps-list-maps'      => $base + [
                'mode'         => 'read',
                'description'  => 'List WP Go Maps maps: id, title, start lat/lng and zoom, size, marker count, settings, and the posts that embed each ([wpgmza] or its block)',
                'input_schema' => [ 'type' => 'object', 'properties' => new \stdClass() ],
                'handler'      => static fn (): array => self::list_go_maps(),
            ],
            'wpgomaps-list-markers'   => $base + [
                'mode'         => 'read',
                'description'  => 'List WP Go Maps markers: id, map_id, title, address, lat, lng, category, approved, link. Filter by map_id, search (title or address); page, per_page (max 100)',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'map_id' => $id, 'search' => [ 'type' => 'string', 'maxLength' => 200 ] ] + self::page_schema(),
                ],
                'handler'      => static fn (array $args): array => self::list_markers($args),
            ],
            'wpgomaps-save-markers'   => $base + self::write_base() + [
                'description'  => 'Create or change WP Go Maps markers in batch (max 50): a row with id changes that marker (only the fields given), one without needs map_id and an address or lat/lng. An address with no lat/lng is geocoded with the site\'s Google key. Answers per row. Undo: rollback-session',
                'input_schema' => [
                    'type'                 => 'object',
                    'properties'           => [ 'markers' => [ 'type' => 'array', 'minItems' => 1, 'maxItems' => self::MAX_BATCH, 'items' => $row ] ],
                    'required'             => [ 'markers' ],
                    'additionalProperties' => false,
                ],
                'handler'      => static fn (array $args, array $context): array => self::save_markers($args, $context),
            ],
            'wpgomaps-delete-markers' => $base + self::write_base() + [
                'description'  => 'Delete WP Go Maps markers by id (max 50), each snapshotted first. Answers per row. Undo: rollback-session',
                'input_schema' => [
                    'type'                 => 'object',
                    'properties'           => [ 'ids' => [ 'type' => 'array', 'minItems' => 1, 'maxItems' => self::MAX_BATCH, 'items' => $id ] ],
                    'required'             => [ 'ids' ],
                    'additionalProperties' => false,
                ],
                'handler'      => static fn (array $args, array $context): array => self::delete_markers($args, $context),
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function store_locator_operations(): array
    {
        $id     = [ 'type' => 'integer', 'minimum' => 1 ];
        $text   = [ 'type' => 'string', 'maxLength' => 255 ];
        $base   = [
            'capability' => 'edit_posts',
            'requires'   => static fn () => self::store_locator_presence(),
        ];
        $fields = [];
        foreach (self::STORE_FIELDS as $field) {
            $fields[ $field ] = $text;
        }
        $row = [
            'type'                 => 'object',
            'properties'           => [
                'id'          => $id,
                'title'       => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 500 ],
                'status'      => [ 'type' => 'string', 'enum' => self::STORE_STATUSES ],
                'description' => [ 'type' => 'string', 'maxLength' => 100000 ],
                'categories'  => [ 'type' => 'array', 'maxItems' => 20, 'items' => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 200 ] ],
            ] + self::coordinate_schema() + array_diff_key($fields, [ 'lat' => 1, 'lng' => 1 ]),
            'additionalProperties' => false,
        ];

        return [
            'wpsl-list-stores'   => $base + [
                'mode'         => 'read',
                'description'  => 'List WP Store Locator stores by title: id, title, status, address, city, state, zip, country, lat, lng, url, categories; phone, fax and email masked. Filter by status, category (slug), search; page, per_page (max 100)',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'status'   => [ 'type' => 'string', 'enum' => self::STORE_STATUSES ],
                        'category' => [ 'type' => 'string', 'maxLength' => 200 ],
                        'search'   => [ 'type' => 'string', 'maxLength' => 200 ],
                    ] + self::page_schema(),
                ],
                'validate'     => static fn (): ?array => self::store_cap_refusal(),
                'handler'      => static fn (array $args): array => self::list_stores($args),
            ],
            'wpsl-get-store'     => $base + [
                'mode'         => 'read',
                'objects'      => [ 'id' => [ 'type' => 'post', 'own_type' => true, 'access' => 'read' ] ],
                'description'  => 'Read one WP Store Locator store with its description; phone, fax and email in full at manage_options, masked otherwise',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => $id ], 'required' => [ 'id' ] ],
                'validate'     => static fn (array $args): ?array => self::store_refusal((int) $args['id']),
                'handler'      => static fn (array $args): array => [ 'store' => self::view_store(get_post((int) $args['id']), true) ],
            ],
            'wpsl-get-settings'  => $base + [
                'mode'         => 'read',
                'capability'   => 'manage_options',
                'description'  => 'Read WP Store Locator settings (api, map, search, appearance, markers) with every API key left out, and the posts that embed the locator ([wpsl] or its blocks)',
                'input_schema' => [ 'type' => 'object', 'properties' => new \stdClass() ],
                'handler'      => static fn (): array => self::store_settings(),
            ],
            'wpsl-save-stores'   => $base + self::write_base() + [
                'objects'      => [ 'stores.*.id' => [ 'type' => 'post', 'own_type' => true, 'access' => 'write' ] ],
                'description'  => 'Create or change WP Store Locator stores in batch (max 50): a row with id changes that store (only the fields given), one without needs title, address, city and country (status draft by default; publish needs publish_stores). An address with no lat/lng is geocoded through the plugin\'s own geocoder. Answers per row. Undo: rollback-session',
                'input_schema' => [
                    'type'                 => 'object',
                    'properties'           => [ 'stores' => [ 'type' => 'array', 'minItems' => 1, 'maxItems' => self::MAX_BATCH, 'items' => $row ] ],
                    'required'             => [ 'stores' ],
                    'additionalProperties' => false,
                ],
                'validate'     => static fn (): ?array => self::store_cap_refusal(),
                'handler'      => static fn (array $args, array $context): array => self::save_stores($args, $context),
            ],
            'wpsl-delete-stores' => $base + self::write_base() + [
                'objects'      => [ 'ids' => [ 'type' => 'post', 'own_type' => true, 'access' => 'delete' ] ],
                'description'  => 'Move WP Store Locator stores to the trash by id (max 50), each under a post snapshot. Answers per row. Undo: rollback-session',
                'input_schema' => [
                    'type'                 => 'object',
                    'properties'           => [ 'ids' => [ 'type' => 'array', 'minItems' => 1, 'maxItems' => self::MAX_BATCH, 'items' => $id ] ],
                    'required'             => [ 'ids' ],
                    'additionalProperties' => false,
                ],
                'validate'     => static fn (): ?array => self::store_cap_refusal(),
                'handler'      => static fn (array $args, array $context): array => self::delete_stores($args, $context),
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function wp_maps_operations(): array
    {
        $id   = [ 'type' => 'integer', 'minimum' => 1 ];
        $base = [
            'capability' => 'manage_options',
            'requires'   => static fn () => self::wp_maps_presence(),
            'mode'       => 'read',
        ];

        return [
            'wpmaps-list-maps'      => $base + [
                'description'  => 'List WP Maps maps: id, title, size, zoom, type, location ids, and the posts that embed each ([put_wpgm id=N])',
                'input_schema' => [ 'type' => 'object', 'properties' => new \stdClass() ],
                'handler'      => static fn (): array => self::list_wp_maps(),
            ],
            'wpmaps-list-locations' => $base + [
                'description'  => 'List WP Maps locations: id, title, address, city, state, country, postal_code, lat, lng. Filter by map_id, search (title or address); page, per_page (max 100)',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'map_id' => $id, 'search' => [ 'type' => 'string', 'maxLength' => 200 ] ] + self::page_schema(),
                ],
                'handler'      => static fn (array $args): array => self::list_locations($args),
            ],
        ];
    }

    // ------------------------------------------------------------ shared

    private static function refusal(string $code, string $message, array $data = []): array
    {
        return [ 'code' => $code, 'message' => $message, 'data' => $data ];
    }

    /** @return array{code: string, message: string} */
    private static function row_error(string $code, string $message): array
    {
        return [ 'code' => $code, 'message' => $message ];
    }

    /**
     * Posts that carry one of the given markers in their content, with the
     * map ids each names. $parse gets a post's content and returns the ids
     * (0 for a locator that names none).
     *
     * @param callable(string): int[] $parse
     * @return array<int, array<int, array{id: int, title: string, type: string, status: string}>> map id => posts
     */
    private static function embeds(string $search, callable $parse): array
    {
        $posts = Readable_Posts::get_posts([
            'post_type'      => 'any',
            'post_status'    => [ 'publish', 'draft', 'pending', 'private', 'future' ],
            's'              => $search,
            'posts_per_page' => 200,
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ]);
        $out   = [];
        foreach ($posts as $post) {
            foreach (array_unique($parse((string) $post->post_content)) as $map) {
                $out[ (int) $map ][] = [ 'id' => (int) $post->ID, 'title' => (string) $post->post_title, 'type' => (string) $post->post_type, 'status' => (string) $post->post_status ];
            }
        }
        return $out;
    }

    /** A message with every given secret cut out. */
    private static function redact(string $message, array $secrets): string
    {
        foreach ($secrets as $secret) {
            if (is_string($secret) && strlen($secret) >= 6) {
                $message = str_replace($secret, '***', $message);
            }
        }
        return $message;
    }

    /** @return array{0: int, 1: int} page and per page */
    private static function paging(array $args, int $default): array
    {
        return [ max(1, (int) ($args['page'] ?? 1)), min(self::MAX_PAGE_SIZE, max(1, (int) ($args['per_page'] ?? $default))) ];
    }

    // ------------------------------------------------------------ WP Go Maps reads

    /** @return array<string, mixed> */
    private static function list_go_maps(): array
    {
        global $wpdb;
        $rows   = (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE active = 0 ORDER BY id ASC', self::go_maps_table()), ARRAY_A);
        $counts = [];
        foreach ((array) $wpdb->get_results($wpdb->prepare('SELECT map_id, COUNT(*) AS n FROM %i GROUP BY map_id', self::markers_table()), ARRAY_A) as $count) {
            $counts[ (int) $count['map_id'] ] = (int) $count['n'];
        }
        $embeds = self::embeds('wpgmza', [ self::class, 'go_maps_ids' ]);

        $maps = [];
        foreach ($rows as $row) {
            $id     = (int) $row['id'];
            $other  = maybe_unserialize((string) ($row['other_settings'] ?? ''));
            $maps[] = [
                'id'           => $id,
                'title'        => (string) ($row['map_title'] ?? ''),
                'start_lat'    => (string) ($row['map_start_lat'] ?? ''),
                'start_lng'    => (string) ($row['map_start_lng'] ?? ''),
                'zoom'         => (int) ($row['map_start_zoom'] ?? 0),
                'width'        => (string) ($row['map_width'] ?? '') . (string) ($row['map_width_type'] ?? ''),
                'height'       => (string) ($row['map_height'] ?? '') . (string) ($row['map_height_type'] ?? ''),
                'marker_count' => $counts[ $id ] ?? 0,
                'settings'     => is_array($other) ? self::without_keys($other) : [],
                'embedded_in'  => array_merge($embeds[ $id ] ?? [], 1 === $id ? ($embeds[0] ?? []) : []),
            ];
        }
        return [ 'maps' => $maps, 'shortcode' => '[wpgmza id="<id>"]' ];
    }

    /**
     * Map ids a post's content embeds: [wpgmza id=N] shortcodes and
     * gutenberg-wpgmza blocks. A shortcode or block without an id shows
     * map 1, WP Go Maps' default, and is reported as 0.
     *
     * @return int[]
     */
    public static function go_maps_ids(string $content): array
    {
        $ids = [];
        if (preg_match_all('/\[wpgmza(?:\s[^\]]*)?\]/i', $content, $shortcodes)) {
            foreach ($shortcodes[0] as $shortcode) {
                $ids[] = preg_match('/\bid\s*=\s*["\']?(\d+)/i', $shortcode, $m) ? (int) $m[1] : 0;
            }
        }
        if (preg_match_all('/<!--\s+wp:gutenberg-wpgmza\/[a-z-]+(\s+\{.*?\})?\s*\/?-->/s', $content, $blocks)) {
            foreach ($blocks[1] as $attrs) {
                $decoded = '' === trim($attrs) ? [] : json_decode(trim($attrs), true);
                $ids[]   = is_array($decoded) && isset($decoded['id']) ? (int) $decoded['id'] : 0;
            }
        }
        return $ids;
    }

    /**
     * A settings array without anything that is or looks like a key or a
     * secret.
     *
     * @param array<string|int, mixed> $settings
     * @return array<string|int, mixed>
     */
    private static function without_keys(array $settings): array
    {
        $out = [];
        foreach ($settings as $name => $value) {
            if (1 === preg_match('/(key|secret|token|licen[cs]e|password)/i', (string) $name)) {
                continue;
            }
            $out[ $name ] = is_array($value) ? self::without_keys($value) : $value;
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private static function view_marker(array $row, bool $full = false): array
    {
        $out = [
            'id'       => (int) $row['id'],
            'map_id'   => (int) $row['map_id'],
            'title'    => (string) $row['title'],
            'address'  => (string) $row['address'],
            'lat'      => (string) $row['lat'],
            'lng'      => (string) $row['lng'],
            'category' => (string) $row['category'],
            'approved' => '0' !== (string) ($row['approved'] ?? '1'),
            'link'     => (string) $row['link'],
        ];
        if ($full) {
            $out['description'] = (string) $row['description'];
        }
        return $out;
    }

    /** @return array<string, mixed>|null */
    private static function marker_row(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT id, map_id, title, address, description, lat, lng, category, approved, link FROM %i WHERE id = %d', self::markers_table(), $id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    private static function go_map_exists(int $map_id): bool
    {
        global $wpdb;
        return null !== $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE id = %d AND active = 0', self::go_maps_table(), $map_id));
    }

    /** @return array<string, mixed> */
    private static function list_markers(array $args): array
    {
        global $wpdb;
        [$page, $per] = self::paging($args, 50);
        $where        = '1=1';
        $params       = [ self::markers_table() ];
        if (isset($args['map_id'])) {
            $where   .= ' AND map_id = %d';
            $params[] = (int) $args['map_id'];
        }
        if (isset($args['search']) && '' !== trim((string) $args['search'])) {
            $like     = '%' . $wpdb->esc_like(trim((string) $args['search'])) . '%';
            $where   .= ' AND (title LIKE %s OR address LIKE %s)';
            $params[] = $like;
            $params[] = $like;
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where holds only fixed SQL and placeholders.
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE $where", ...$params));
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- as above; the replacements are spread.
        $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT id, map_id, title, address, lat, lng, category, approved, link FROM %i WHERE $where ORDER BY id ASC LIMIT %d OFFSET %d", ...array_merge($params, [ $per, ($page - 1) * $per ])), ARRAY_A);

        return [ 'markers' => array_map(static fn (array $row): array => self::view_marker($row), $rows), 'total' => $total, 'page' => $page, 'per_page' => $per ];
    }

    // ------------------------------------------------------------ WP Go Maps writes

    /** The next id the markers table will hand out, reserved before a create's snapshot. */
    private static function next_marker_id(): int
    {
        global $wpdb;
        $max    = (int) $wpdb->get_var($wpdb->prepare('SELECT MAX(id) FROM %i', self::markers_table()));
        $status = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS LIKE %s', self::markers_table()), ARRAY_A);
        $auto   = is_array($status) ? (int) ($status['Auto_increment'] ?? 0) : 0;
        return max($max + 1, $auto);
    }

    /**
     * Coordinates for an address from the Google Geocoding API with WP Go
     * Maps' own key. The key never leaves: it is cut out of every message.
     *
     * @return array{lat: float, lng: float}|array{code: string, message: string}
     */
    private static function geocode_go_maps(string $address): array
    {
        $key = trim((string) get_option('wpgmza_google_maps_api_key', ''));
        if ('' === $key) {
            return self::row_error('coordinates_required', 'WP Go Maps has no Google Maps API key set, so this address cannot be geocoded. Give lat and lng.');
        }
        $url      = add_query_arg([ 'address' => rawurlencode($address), 'key' => rawurlencode($key) ], 'https://maps.googleapis.com/maps/api/geocode/json');
        $response = wp_remote_get($url, [ 'timeout' => 10 ]);
        if (is_wp_error($response)) {
            return self::row_error('geocode_failed', self::redact('Geocoding failed: ' . $response->get_error_message(), [ $key, rawurlencode($key) ]));
        }
        $body   = json_decode((string) wp_remote_retrieve_body($response), true);
        $status = is_array($body) ? (string) ($body['status'] ?? '') : '';
        $place  = is_array($body) ? ($body['results'][0]['geometry']['location'] ?? null) : null;
        if ('OK' !== $status || ! is_array($place) || ! isset($place['lat'], $place['lng'])) {
            $detail = is_array($body) ? (string) ($body['error_message'] ?? '') : '';
            return self::row_error('geocode_failed', self::redact(trim(sprintf('Geocoding failed (%s). %s', '' === $status ? 'no answer' : $status, $detail)), [ $key, rawurlencode($key) ]));
        }
        return [ 'lat' => round((float) $place['lat'], 6), 'lng' => round((float) $place['lng'], 6) ];
    }

    /**
     * The columns a marker row sets, from the args given, or an error.
     *
     * @return array{set: array<string, mixed>, geocoded: bool}|array{code: string, message: string}
     */
    private static function marker_columns(array $row, ?array $existing): array
    {
        $set = [];
        if (isset($row['map_id'])) {
            if (! self::go_map_exists((int) $row['map_id'])) {
                return self::row_error('map_not_found', sprintf('%d is not a WP Go Maps map.', (int) $row['map_id']));
            }
            $set['map_id'] = (int) $row['map_id'];
        }
        if (array_key_exists('title', $row)) {
            $set['title'] = sanitize_text_field((string) $row['title']);
        }
        if (array_key_exists('address', $row)) {
            $set['address'] = sanitize_text_field((string) $row['address']);
        }
        if (array_key_exists('description', $row)) {
            $set['description'] = current_user_can('unfiltered_html') ? (string) $row['description'] : wp_kses_post((string) $row['description']);
        }
        if (array_key_exists('link', $row)) {
            $link = trim((string) $row['link']);
            if ('' !== $link && 1 !== preg_match('%^https?://%i', $link)) {
                return self::row_error('url_invalid', 'The link must start with http:// or https://.');
            }
            $set['link'] = esc_url_raw($link);
        }
        if (array_key_exists('category', $row)) {
            $set['category'] = sanitize_text_field((string) $row['category']);
        }
        if (array_key_exists('approved', $row)) {
            $set['approved'] = $row['approved'] ? 1 : 0;
        }

        $has_lat = array_key_exists('lat', $row);
        $has_lng = array_key_exists('lng', $row);
        if ($has_lat !== $has_lng) {
            return self::row_error('coordinates_incomplete', 'Give both lat and lng, or neither.');
        }
        $geocoded = false;
        if ($has_lat) {
            $set['lat'] = (float) $row['lat'];
            $set['lng'] = (float) $row['lng'];
        } elseif (isset($set['address']) && '' !== $set['address'] && (null === $existing || $set['address'] !== (string) $existing['address'])) {
            $place = self::geocode_go_maps($set['address']);
            if (isset($place['code'])) {
                return $place;
            }
            $set['lat'] = $place['lat'];
            $set['lng'] = $place['lng'];
            $geocoded   = true;
        }
        if (null === $existing) {
            if (! isset($set['map_id'])) {
                return self::row_error('map_id_required', 'A new marker needs map_id.');
            }
            if (! isset($set['lat'])) {
                return self::row_error('coordinates_required', 'A new marker needs an address or lat and lng.');
            }
        }
        if (null !== $existing && [] === $set) {
            return self::row_error('nothing_to_change', 'Give at least one field to change.');
        }
        return [ 'set' => $set, 'geocoded' => $geocoded ];
    }

    /**
     * Write one marker row: an insert of every NOT NULL column for a create,
     * otherwise an update of the columns given, with latlng rebuilt from lat
     * and lng whenever they change.
     */
    private static function write_marker(int $id, array $set, bool $create): void
    {
        global $wpdb;
        $table = self::markers_table();
        if ($create) {
            $set += [ 'address' => '', 'description' => '', 'pic' => '', 'link' => '', 'icon' => '', 'anim' => '', 'title' => '', 'infoopen' => '', 'category' => '', 'did' => '', 'other_data' => '', 'approved' => 1 ];
            $set  = [ 'id' => $id ] + $set;
        }
        $point   = isset($set['lat']) ? sprintf('POINT(%F %F)', (float) $set['lat'], (float) $set['lng']) : null;
        $columns = [];
        $values  = [];
        foreach ($set as $column => $value) {
            $columns[] = $column;
            $values[]  = in_array($column, [ 'lat', 'lng' ], true) ? (string) round((float) $value, 6) : $value;
        }
        if ($create) {
            $sql = 'INSERT INTO %i (' . implode(', ', array_fill(0, count($columns), '%i')) . ($point ? ', latlng' : '') . ') VALUES ('
                . implode(', ', array_map(static fn ($v): string => is_int($v) ? '%d' : '%s', $values)) . ($point ? ', ST_GeomFromText(%s)' : '') . ')';
            $params = array_merge([ $table ], $columns, $values, $point ? [ $point ] : []);
        } else {
            $parts  = [];
            $params = [ $table ];
            foreach ($columns as $i => $column) {
                $parts[]  = '%i = ' . (is_int($values[ $i ]) ? '%d' : '%s');
                $params[] = $column;
                $params[] = $values[ $i ];
            }
            if ($point) {
                $parts[]  = 'latlng = ST_GeomFromText(%s)';
                $params[] = $point;
            }
            $sql      = 'UPDATE %i SET ' . implode(', ', $parts) . ' WHERE id = %d';
            $params[] = $id;
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql holds only %i, %d, %s placeholders and fixed SQL.
        if (false === $wpdb->query($wpdb->prepare($sql, ...$params))) {
            throw new Operation_Refused($create ? 'insert_failed' : 'update_failed', 'Could not save the marker: ' . $wpdb->last_error);
        }
    }

    /** @return array<string, mixed> */
    private static function save_markers(array $args, array $context): array
    {
        $results = [];
        $ids     = [];
        foreach (array_values((array) $args['markers']) as $index => $row) {
            $row      = (array) $row;
            $existing = isset($row['id']) ? self::marker_row((int) $row['id']) : null;
            if (isset($row['id']) && null === $existing) {
                $results[] = [ 'index' => $index, 'ok' => false, 'error' => self::row_error('marker_not_found', sprintf('%d is not a WP Go Maps marker.', (int) $row['id'])) ];
                continue;
            }
            $columns = self::marker_columns($row, $existing);
            if (isset($columns['code'])) {
                $results[] = [ 'index' => $index, 'ok' => false, 'error' => $columns ];
                continue;
            }
            $create = null === $existing;
            $id     = $create ? self::next_marker_id() : (int) $row['id'];
            $undo   = wp_generate_uuid4();
            try {
                $out = Safe_Mutation::run(
                    [
                        'operation_id' => $undo,
                        'object_type' => Plugin_Table_Rows_Snapshot::TYPE,
                        'object_id'   => Plugin_Table_Rows_Snapshot::key('wpgmza', 'markers', [ $id ]),
                        'session_id'  => (string) $context['session_id'],
                        'tool_name'   => (string) $context['tool_name'],
                        'args'        => [ 'operation' => (string) $context['operation'], 'args' => $row ],
                    ],
                    static function () use ($id, $columns, $create): bool {
                        self::write_marker($id, $columns['set'], $create);
                        return true;
                    }
                );
            } catch (Operation_Refused $e) {
                // Nothing was written; the undo point would restore over a row that later takes this id.
                Snapshot_Store::delete_operation($undo);
                $results[] = [ 'index' => $index, 'ok' => false, 'error' => self::row_error($e->error_code(), $e->getMessage()) ];
                continue;
            }
            $ids[]     = (string) $out['operation_id'];
            $results[] = [ 'index' => $index, 'ok' => true, 'created' => $create, 'geocoded' => $columns['geocoded'], 'marker' => self::view_marker((array) self::marker_row($id)), 'operation_id' => (string) $out['operation_id'] ];
        }
        return [ 'result' => [ 'results' => $results, 'saved' => count($ids), 'failed' => count($results) - count($ids) ], 'operation_ids' => $ids ];
    }

    /** @return array<string, mixed> */
    private static function delete_markers(array $args, array $context): array
    {
        $results = [];
        $ids     = [];
        foreach (array_values(array_unique(array_map('intval', (array) $args['ids']))) as $index => $id) {
            $row = self::marker_row($id);
            if (null === $row) {
                $results[] = [ 'index' => $index, 'id' => $id, 'ok' => false, 'error' => self::row_error('marker_not_found', sprintf('%d is not a WP Go Maps marker.', $id)) ];
                continue;
            }
            $out       = Safe_Mutation::run(
                [
                    'object_type' => Plugin_Table_Rows_Snapshot::TYPE,
                    'object_id'   => Plugin_Table_Rows_Snapshot::key('wpgmza', 'markers', [ $id ]),
                    'session_id'  => (string) $context['session_id'],
                    'tool_name'   => (string) $context['tool_name'],
                    'args'        => [ 'operation' => (string) $context['operation'], 'args' => [ 'id' => $id ] ],
                ],
                static function () use ($id): int {
                    global $wpdb;
                    $deleted = $wpdb->delete(self::markers_table(), [ 'id' => $id ], [ '%d' ]);
                    if (false === $deleted) {
                        throw new Operation_Refused('delete_failed', 'Could not delete the marker: ' . $wpdb->last_error);
                    }
                    return (int) $deleted;
                }
            );
            $ids[]     = (string) $out['operation_id'];
            $results[] = [ 'index' => $index, 'id' => $id, 'ok' => true, 'deleted' => self::view_marker($row), 'operation_id' => (string) $out['operation_id'] ];
        }
        return [ 'result' => [ 'results' => $results, 'deleted' => count($ids), 'failed' => count($results) - count($ids) ], 'operation_ids' => $ids ];
    }

    // ------------------------------------------------------------ WP Store Locator reads

    /** WP Store Locator's own capability for its stores, which it grants to administrators. */
    private static function store_cap_refusal(): ?array
    {
        if (current_user_can('edit_stores')) {
            return null;
        }
        return self::refusal('operation_denied', 'This needs the edit_stores capability (WP Store Locator grants it to administrators).', [ 'reason' => 'capability', 'capability' => 'edit_stores' ]);
    }

    private static function is_store($post): bool
    {
        return $post instanceof \WP_Post && self::STORE === $post->post_type && 'trash' !== $post->post_status;
    }

    private static function store_refusal(int $id): ?array
    {
        if (! self::is_store(get_post($id))) {
            return self::refusal('store_not_found', sprintf('%d is not a WP Store Locator store.', $id), [ 'id' => $id ]);
        }
        return self::store_cap_refusal();
    }

    /** @return array<string, mixed> */
    private static function view_store(\WP_Post $post, bool $single): array
    {
        $id  = (int) $post->ID;
        $out = [
            'id'     => $id,
            'title'  => (string) $post->post_title,
            'status' => (string) $post->post_status,
        ];
        foreach (self::STORE_FIELDS as $field) {
            $out[ $field ] = (string) get_post_meta($id, 'wpsl_' . $field, true);
        }
        $terms             = taxonomy_exists(self::STORE_CATEGORY) ? wp_get_post_terms($id, self::STORE_CATEGORY) : [];
        $out['categories'] = is_array($terms) ? array_map(static fn (\WP_Term $t): array => [ 'id' => (int) $t->term_id, 'slug' => (string) $t->slug, 'name' => (string) $t->name ], $terms) : [];
        $out['modified']   = (string) $post->post_modified;
        $masked            = ! $single || ! current_user_can('manage_options');
        if ($masked) {
            $out = Masking::keys($out, self::STORE_PII);
        }
        $out['masked'] = $masked;
        if ($single) {
            $out['description'] = (string) $post->post_content;
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private static function list_stores(array $args): array
    {
        [$page, $per] = self::paging($args, 20);
        $query        = [
            'post_type'      => self::STORE,
            'post_status'    => isset($args['status']) ? [ (string) $args['status'] ] : self::STORE_STATUSES,
            'posts_per_page' => $per,
            'paged'          => $page,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ];
        if (isset($args['category']) && '' !== (string) $args['category']) {
            $query['tax_query'] = [ [ 'taxonomy' => self::STORE_CATEGORY, 'field' => 'slug', 'terms' => [ (string) $args['category'] ] ] ]; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- filtering stores by category is the point of the op.
        }
        if (isset($args['search']) && '' !== trim((string) $args['search'])) {
            $query['s'] = (string) $args['search'];
        }
        $q = Readable_Posts::query($query);
        return [ 'stores' => array_map(static fn (\WP_Post $p): array => self::view_store($p, false), $q->posts), 'total' => (int) $q->found_posts, 'masked' => true ];
    }

    /** @return array<string, mixed> */
    private static function store_settings(): array
    {
        $settings = [];
        foreach (self::STORE_SETTINGS as $group) {
            $value = get_option('wpsl_' . $group, null);
            if (is_array($value)) {
                $settings[ $group ] = self::without_keys($value);
            }
        }
        $legacy = get_option('wpsl_settings', null);
        if ([] === $settings && is_array($legacy)) {
            $settings['legacy'] = self::without_keys($legacy);
        }
        $embeds = self::embeds('wpsl', static fn (string $content): array => preg_match('/\[wpsl[\s\]_]|<!--\s+wp:wpsl\//i', $content) ? [ 0 ] : []);

        return [ 'settings' => $settings, 'embedded_in' => $embeds[0] ?? [], 'keys_omitted' => true ];
    }

    // ------------------------------------------------------------ WP Store Locator writes

    /** Every API key WP Store Locator holds, to cut out of a geocoder message. */
    private static function store_keys(): array
    {
        $keys = [];
        foreach ([ get_option('wpsl_api', []), get_option('wpsl_settings', []) ] as $group) {
            foreach ((array) $group as $name => $value) {
                if (is_string($value) && 1 === preg_match('/key/i', (string) $name)) {
                    $keys[] = $value;
                }
            }
        }
        return $keys;
    }

    /**
     * Coordinates for a store's address through WP Store Locator's own
     * geocode service, so the site's chosen provider and key are used.
     *
     * @param array<string, string> $address
     * @return array{lat: float, lng: float}|array{code: string, message: string}
     */
    private static function geocode_store(array $address): array
    {
        $service = function_exists('wpsl_get_service') ? \wpsl_get_service('geocode') : null;
        if (! is_object($service) || ! method_exists($service, 'geocode_location')) {
            return self::row_error('coordinates_required', 'This WP Store Locator version has no geocode service this can call. Give lat and lng.');
        }
        try {
            $response = $service->geocode_location($address, 0, true);
        } catch (\Throwable $e) {
            return self::row_error('geocode_failed', self::redact('Geocoding failed: ' . $e->getMessage(), self::store_keys()));
        }
        $latlng = is_array($response) ? ($response['latlng'] ?? null) : null;
        if (! is_array($latlng) || ! isset($latlng['lat'], $latlng['lng']) || isset($response['message'])) {
            $message = is_array($response) && isset($response['message']) ? wp_strip_all_tags((string) $response['message']) : 'the geocoder returned no coordinates';
            return self::row_error('geocode_failed', self::redact('Geocoding failed: ' . $message, self::store_keys()));
        }
        return [ 'lat' => round((float) $latlng['lat'], 6), 'lng' => round((float) $latlng['lng'], 6) ];
    }

    /**
     * The meta a store row sets, from the args given, or an error.
     *
     * @return array{meta: array<string, string>, geocoded: bool}|array{code: string, message: string}
     */
    private static function store_meta(array $row, ?\WP_Post $existing): array
    {
        $meta = [];
        foreach (self::STORE_FIELDS as $field) {
            if (! array_key_exists($field, $row) || in_array($field, [ 'lat', 'lng' ], true)) {
                continue;
            }
            $value = trim((string) $row[ $field ]);
            if ('email' === $field && '' !== $value && ! is_email($value)) {
                return self::row_error('email_invalid', 'The email is not a valid address.');
            }
            if ('url' === $field && '' !== $value && 1 !== preg_match('%^https?://%i', $value)) {
                return self::row_error('url_invalid', 'The url must start with http:// or https://.');
            }
            $meta[ $field ] = 'email' === $field ? sanitize_email($value) : ('url' === $field ? esc_url_raw($value) : sanitize_text_field($value));
        }
        if (null === $existing) {
            foreach ([ 'address', 'city', 'country' ] as $required) {
                if ('' === ($meta[ $required ] ?? '')) {
                    return self::row_error('address_required', 'A new store needs title, address, city and country (WP Store Locator\'s required fields).');
                }
            }
        }

        $has_lat = array_key_exists('lat', $row);
        if ($has_lat !== array_key_exists('lng', $row)) {
            return self::row_error('coordinates_incomplete', 'Give both lat and lng, or neither.');
        }
        $geocoded = false;
        if ($has_lat) {
            $meta['lat'] = (string) round((float) $row['lat'], 6);
            $meta['lng'] = (string) round((float) $row['lng'], 6);
        } else {
            $changed = null === $existing;
            $address = [];
            foreach (self::ADDRESS_PARTS as $part) {
                $current          = null === $existing ? '' : (string) get_post_meta($existing->ID, 'wpsl_' . $part, true);
                $address[ $part ] = $meta[ $part ] ?? $current;
                $changed          = $changed || (isset($meta[ $part ]) && $meta[ $part ] !== $current);
            }
            if ($changed) {
                $place = self::geocode_store($address);
                if (isset($place['code'])) {
                    return $place;
                }
                $meta['lat'] = (string) $place['lat'];
                $meta['lng'] = (string) $place['lng'];
                $geocoded    = true;
            }
        }
        return [ 'meta' => $meta, 'geocoded' => $geocoded ];
    }

    /** Category terms by slug or name, created when missing; an error when one cannot be. */
    private static function store_terms(array $names)
    {
        $ids = [];
        foreach ($names as $name) {
            $name = trim((string) $name);
            $term = get_term_by('slug', sanitize_title($name), self::STORE_CATEGORY);
            if (! $term instanceof \WP_Term) {
                $term = get_term_by('name', $name, self::STORE_CATEGORY);
            }
            if ($term instanceof \WP_Term) {
                $ids[] = (int) $term->term_id;
                continue;
            }
            if (! current_user_can(get_taxonomy(self::STORE_CATEGORY)->cap->edit_terms ?? 'manage_categories')) {
                return self::row_error('operation_denied', sprintf('The store category "%s" does not exist and you may not create it.', $name));
            }
            $made = wp_insert_term($name, self::STORE_CATEGORY);
            if (is_wp_error($made)) {
                return self::row_error('category_failed', $made->get_error_message());
            }
            $ids[] = (int) $made['term_id'];
        }
        return $ids;
    }

    /** Drop WP Store Locator's cached autoload search results, as its own save does. */
    private static function flush_store_cache(): void
    {
        $utils = function_exists('wpsl_get_service') ? \wpsl_get_service('system_utils') : null;
        if (is_object($utils) && method_exists($utils, 'flush_autoload_transients')) {
            $utils->flush_autoload_transients();
        }
    }

    /** @param array<string, string> $meta */
    private static function apply_store(int $id, array $row, array $meta, ?array $terms): void
    {
        foreach ($meta as $field => $value) {
            update_post_meta($id, 'wpsl_' . $field, $value);
        }
        if (null !== $terms) {
            $set = wp_set_object_terms($id, $terms, self::STORE_CATEGORY);
            if (is_wp_error($set)) {
                throw new Operation_Refused('category_failed', $set->get_error_message());
            }
        }
        clean_post_cache($id);
    }

    /** @return array<string, mixed> */
    private static function save_stores(array $args, array $context): array
    {
        $results = [];
        $ids     = [];
        foreach (array_values((array) $args['stores']) as $index => $row) {
            $row      = (array) $row;
            $existing = isset($row['id']) ? get_post((int) $row['id']) : null;
            if (isset($row['id']) && ! self::is_store($existing)) {
                $results[] = [ 'index' => $index, 'ok' => false, 'error' => self::row_error('store_not_found', sprintf('%d is not a WP Store Locator store.', (int) $row['id'])) ];
                continue;
            }
            if (null === $existing && '' === trim((string) ($row['title'] ?? ''))) {
                $results[] = [ 'index' => $index, 'ok' => false, 'error' => self::row_error('address_required', 'A new store needs title, address, city and country (WP Store Locator\'s required fields).') ];
                continue;
            }
            if (null !== $existing && count($row) < 2) {
                $results[] = [ 'index' => $index, 'ok' => false, 'error' => self::row_error('nothing_to_change', 'Give at least one field to change.') ];
                continue;
            }
            // A new store is a draft unless the caller asks for publish (and holds
            // publish_stores), as create-post defaults to draft.
            $status = (string) ($row['status'] ?? (null === $existing ? 'draft' : ''));
            if ('' !== $status && in_array($status, [ 'publish', 'private' ], true) && ! current_user_can('publish_stores')) {
                $results[] = [ 'index' => $index, 'ok' => false, 'error' => self::row_error('operation_denied', 'Publishing a store needs the publish_stores capability.') ];
                continue;
            }
            $meta = self::store_meta($row, $existing);
            if (isset($meta['code'])) {
                $results[] = [ 'index' => $index, 'ok' => false, 'error' => $meta ];
                continue;
            }
            $terms = isset($row['categories']) ? self::store_terms((array) $row['categories']) : null;
            if (is_array($terms) && isset($terms['code'])) {
                $results[] = [ 'index' => $index, 'ok' => false, 'error' => $terms ];
                continue;
            }

            try {
                if (null === $existing) {
                    [$id, $operation] = self::create_store($row, $status, $meta, $terms, $context);
                } else {
                    $id        = (int) $existing->ID;
                    $operation = self::update_store($id, $row, $status, $meta, $terms, $context);
                }
            } catch (Operation_Refused $e) {
                $results[] = [ 'index' => $index, 'ok' => false, 'error' => self::row_error($e->error_code(), $e->getMessage()) ];
                continue;
            }
            $ids[]     = $operation;
            $results[] = [ 'index' => $index, 'ok' => true, 'created' => null === $existing, 'geocoded' => $meta['geocoded'], 'store' => self::view_store(get_post($id), false), 'operation_id' => $operation ];
        }
        if ([] !== $ids) {
            self::flush_store_cache();
        }
        return [ 'result' => [ 'results' => $results, 'saved' => count($ids), 'failed' => count($results) - count($ids) ], 'operation_ids' => $ids ];
    }

    /** @return array{0: int, 1: string} the new store's id and its undo point */
    private static function create_store(array $row, string $status, array $meta, ?array $terms, array $context): array
    {
        $id = wp_insert_post([
            'post_type'    => self::STORE,
            'post_title'   => wp_slash((string) $row['title']),
            'post_content' => wp_slash((string) ($row['description'] ?? '')),
            'post_status'  => $status,
            'post_author'  => get_current_user_id(),
        ], true);
        if (is_wp_error($id) || 0 === (int) $id) {
            throw new Operation_Refused('insert_failed', is_wp_error($id) ? $id->get_error_message() : 'WP Store Locator could not create the store.');
        }
        $id        = (int) $id;
        $operation = Post_Creation_Snapshot::record((string) $context['tool_name'], [ $id ], [ 'operation' => $context['operation'], 'args' => $row ], (string) $context['session_id']);
        self::apply_store($id, $row, $meta['meta'], $terms);
        return [ $id, (string) $operation ];
    }

    private static function update_store(int $id, array $row, string $status, array $meta, ?array $terms, array $context): string
    {
        $out = Safe_Mutation::run(
            [
                'object_type' => 'post',
                'object_id'   => $id,
                'session_id'  => (string) $context['session_id'],
                'tool_name'   => (string) $context['tool_name'],
                'args'        => [ 'operation' => (string) $context['operation'], 'args' => $row ],
            ],
            static function () use ($id, $row, $status, $meta, $terms): bool {
                $fields = [ 'ID' => $id ];
                if (array_key_exists('title', $row)) {
                    $fields['post_title'] = wp_slash((string) $row['title']);
                }
                if (array_key_exists('description', $row)) {
                    $fields['post_content'] = wp_slash((string) $row['description']);
                }
                if ('' !== $status) {
                    $fields['post_status'] = $status;
                }
                if (count($fields) > 1) {
                    $saved = Save_Filters::update_post($fields, true);
                    if (is_wp_error($saved)) {
                        throw new Operation_Refused('update_failed', $saved->get_error_message());
                    }
                }
                self::apply_store($id, $row, $meta['meta'], $terms);
                return true;
            }
        );
        return (string) $out['operation_id'];
    }

    /** @return array<string, mixed> */
    private static function delete_stores(array $args, array $context): array
    {
        $results = [];
        $ids     = [];
        foreach (array_values(array_unique(array_map('intval', (array) $args['ids']))) as $index => $id) {
            $post = get_post($id);
            if (! self::is_store($post)) {
                $results[] = [ 'index' => $index, 'id' => $id, 'ok' => false, 'error' => self::row_error('store_not_found', sprintf('%d is not a WP Store Locator store.', $id)) ];
                continue;
            }
            $summary = self::view_store($post, false);
            $undo    = wp_generate_uuid4();
            try {
                $out = Safe_Mutation::run(
                    [
                        'operation_id' => $undo,
                        'object_type' => 'post',
                        'object_id'   => $id,
                        'session_id'  => (string) $context['session_id'],
                        'tool_name'   => (string) $context['tool_name'],
                        'args'        => [ 'operation' => (string) $context['operation'], 'args' => [ 'id' => $id ] ],
                    ],
                    static function () use ($id): bool {
                        if (! Save_Filters::trash_post($id)) {
                            throw new Operation_Refused('delete_failed', sprintf('Could not move store %d to the trash.', $id));
                        }
                        return true;
                    }
                );
            } catch (Operation_Refused $e) {
                Snapshot_Store::delete_operation($undo);
                $results[] = [ 'index' => $index, 'id' => $id, 'ok' => false, 'error' => self::row_error($e->error_code(), $e->getMessage()) ];
                continue;
            }
            $ids[]     = (string) $out['operation_id'];
            $results[] = [ 'index' => $index, 'id' => $id, 'ok' => true, 'trashed' => $summary, 'operation_id' => (string) $out['operation_id'] ];
        }
        if ([] !== $ids) {
            self::flush_store_cache();
        }
        return [ 'result' => [ 'results' => $results, 'trashed' => count($ids), 'failed' => count($results) - count($ids) ], 'operation_ids' => $ids ];
    }

    // ------------------------------------------------------------ WP Maps reads

    /** @return int[] */
    private static function location_ids($raw): array
    {
        $value = maybe_unserialize((string) $raw);
        return is_array($value) ? array_values(array_filter(array_map('intval', $value))) : [];
    }

    /** @return array<string, mixed> */
    private static function list_wp_maps(): array
    {
        global $wpdb;
        $rows   = (array) $wpdb->get_results($wpdb->prepare('SELECT map_id, map_title, map_width, map_height, map_zoom_level, map_type, map_locations FROM %i ORDER BY map_id ASC', self::wp_maps_table()), ARRAY_A);
        $embeds = self::embeds('put_wpgm', static function (string $content): array {
            preg_match_all('/\[put_wpgm[^\]]*\bid\s*=\s*["\']?(\d+)/i', $content, $m);
            return array_map('intval', $m[1]);
        });
        $maps   = [];
        foreach ($rows as $row) {
            $id     = (int) $row['map_id'];
            $maps[] = [
                'id'           => $id,
                'title'        => (string) $row['map_title'],
                'width'        => (string) $row['map_width'],
                'height'       => (string) $row['map_height'],
                'zoom'         => (string) $row['map_zoom_level'],
                'type'         => (string) $row['map_type'],
                'location_ids' => self::location_ids($row['map_locations']),
                'embedded_in'  => $embeds[ $id ] ?? [],
            ];
        }
        return [ 'maps' => $maps, 'shortcode' => '[put_wpgm id=<id>]' ];
    }

    /** @return array<string, mixed> */
    private static function list_locations(array $args): array
    {
        global $wpdb;
        [$page, $per] = self::paging($args, 50);
        $where        = '1=1';
        $params       = [ self::locations_table() ];
        if (isset($args['map_id'])) {
            $raw = $wpdb->get_var($wpdb->prepare('SELECT map_locations FROM %i WHERE map_id = %d', self::wp_maps_table(), (int) $args['map_id']));
            if (null === $raw) {
                throw new Operation_Refused('map_not_found', sprintf('%d is not a WP Maps map.', (int) $args['map_id']));
            }
            $in = self::location_ids($raw);
            if ([] === $in) {
                return [ 'locations' => [], 'total' => 0, 'page' => $page, 'per_page' => $per ];
            }
            $where .= ' AND location_id IN (' . implode(',', array_fill(0, count($in), '%d')) . ')';
            $params = array_merge($params, $in);
        }
        if (isset($args['search']) && '' !== trim((string) $args['search'])) {
            $like     = '%' . $wpdb->esc_like(trim((string) $args['search'])) . '%';
            $where   .= ' AND (location_title LIKE %s OR location_address LIKE %s)';
            $params[] = $like;
            $params[] = $like;
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $where holds only fixed SQL and placeholders.
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE $where", ...$params));
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- as above; the replacements are spread.
        $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT location_id, location_title, location_address, location_city, location_state, location_country, location_postal_code, location_latitude, location_longitude FROM %i WHERE $where ORDER BY location_id ASC LIMIT %d OFFSET %d", ...array_merge($params, [ $per, ($page - 1) * $per ])), ARRAY_A);

        $locations = array_map(static fn (array $r): array => [
            'id'          => (int) $r['location_id'],
            'title'       => (string) $r['location_title'],
            'address'     => (string) $r['location_address'],
            'city'        => (string) $r['location_city'],
            'state'       => (string) $r['location_state'],
            'country'     => (string) $r['location_country'],
            'postal_code' => (string) $r['location_postal_code'],
            'lat'         => (string) $r['location_latitude'],
            'lng'         => (string) $r['location_longitude'],
        ], $rows);

        return [ 'locations' => $locations, 'total' => $total, 'page' => $page, 'per_page' => $per ];
    }
}
