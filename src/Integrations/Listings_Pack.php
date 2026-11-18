<?php

namespace WPMCP\Integrations;

use WPMCP\Privacy\Masking;
use WPMCP\Safety\Post_Creation_Snapshot;
use WPMCP\Safety\Save_Filters;
use WPMCP\Tools\Content\Readable_Posts;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Directory and job board listings (issue #707) on the plugin-data pair,
 * free tier, so they add no top-level tools: WP Job Manager (jobmanager-),
 * GeoDirectory (geodirectory-) and Directorist (directorist-), six ops each:
 * list-listings, get-listing, list-listing-categories, create-listing,
 * update-listing and set-listing-status.
 *
 * All three keep a listing as a post of their own type:
 *  - WP Job Manager 2.4: a job_listing post (capability type job_listing,
 *    so edit_job_listings and the rest), filed under job_listing_category
 *    (only while the site enables categories) and job_listing_type, with its
 *    fields as post meta (_job_location, _application, _company_name,
 *    _filled, _featured, _job_expires, _remote_position, _job_salary and so
 *    on, the keys of WP_Job_Manager_Post_Types::get_job_listing_fields())
 *    and an extra expired status;
 *  - Directorist 8.11: an at_biz_dir post (capability type at_biz_dir) under
 *    at_biz_dir-category, at_biz_dir-location and at_biz_dir-tags, tied to a
 *    directory type (the atbdp_listing_types term, mirrored in the
 *    _directory_type meta), with its fields as meta (_address, _phone,
 *    _email, _website, _zip, _manual_lat, _manual_lng, _featured,
 *    _expiry_date, _never_expire and so on) and an expired status;
 *  - GeoDirectory 2.8: a post of one of its types (gd_place by default)
 *    under {type}category and {type}_tags, with everything else in a row of
 *    its own {prefix}geodir_{type}_detail table, which also mirrors the
 *    title, status, categories and tags.
 *
 * Reads are kept to the listings the caller may read (Readable_Posts and
 * read_post). Lists mask the contact fields (emails, phones, a WP Job
 * Manager application email) through Privacy\Masking; a single read
 * returns them in full to an administrator or the listing's own author and
 * masked to anyone else. GeoDirectory's submit IP is never returned.
 *
 * Writes are off until a site opts in (wpmcp_enable_listings_write), since
 * a published listing shows on the public site. Each goes through
 * wp_insert_post or Save_Filters::update_post, so the plugins' own save
 * hooks run, and takes the post type's own capabilities:
 *  - create-listing inserts a listing (draft unless status says otherwise),
 *    recorded for rollback (Post_Creation_Snapshot: the undo moves it to
 *    the trash);
 *  - update-listing and set-listing-status run under a post snapshot
 *    (fields, meta and terms), so rollback-operation puts them back.
 * GeoDirectory field writes (address, phone and the rest of its detail
 * row) are not part of this slice: the detail row is not in a post
 * snapshot, so only the post fields, categories and tags are written, and
 * the row's mirror of them is brought in step after every write and after
 * every rollback of a GeoDirectory post (after_post_restored).
 *
 * Presence is filterable: wpmcp_wp_job_manager_active (default:
 * JOB_MANAGER_VERSION is defined), wpmcp_geodirectory_active
 * (GEODIRECTORY_VERSION) and wpmcp_directorist_active (ATBDP_VERSION).
 */
final class Listings_Pack
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reads and syncs GeoDirectory's own detail table, which has no cached API.

    public const OPT_IN_FILTER = 'wpmcp_enable_listings_write';

    public const MAX_PER_PAGE = 100;

    /** Op prefix => plugin settings. */
    private const PLUGINS = [
        'jobmanager'   => [
            'label'          => 'WP Job Manager',
            'constant'       => 'JOB_MANAGER_VERSION',
            'statuses'       => [ 'publish', 'pending', 'draft', 'expired', 'preview', 'pending_payment' ],
            'write_statuses' => [ 'publish', 'pending', 'draft', 'expired' ],
            // args key => taxonomy; the first is the one list-listings filters by.
            'terms'          => [ 'categories' => 'job_listing_category', 'types' => 'job_listing_type' ],
            'tags'           => '',
            // field => [meta key, kind, capability needed to set it ('' for the post's own)].
            'fields'         => [
                'location'        => [ '_job_location', 'text', '' ],
                'application'     => [ '_application', 'email_or_url', '' ],
                'company_name'    => [ '_company_name', 'text', '' ],
                'company_website' => [ '_company_website', 'url', '' ],
                'company_tagline' => [ '_company_tagline', 'text', '' ],
                'company_twitter' => [ '_company_twitter', 'text', '' ],
                'company_video'   => [ '_company_video', 'url', '' ],
                'filled'          => [ '_filled', 'flag', '' ],
                'featured'        => [ '_featured', 'flag', 'manage_job_listings' ],
                'expires'         => [ '_job_expires', 'date', 'manage_job_listings' ],
                'remote'          => [ '_remote_position', 'flag', '' ],
                'salary'          => [ '_job_salary', 'text', '' ],
                'salary_currency' => [ '_job_salary_currency', 'text', '' ],
                'salary_unit'     => [ '_job_salary_unit', 'text', '' ],
            ],
            'contact'        => [ 'application' ],
            'defaults'       => [ '_filled' => 0, '_featured' => 0 ],
        ],
        'geodirectory' => [
            'label'          => 'GeoDirectory',
            'constant'       => 'GEODIRECTORY_VERSION',
            'statuses'       => [ 'publish', 'pending', 'draft', 'private', 'gd-closed', 'gd-expired' ],
            'write_statuses' => [ 'publish', 'pending', 'draft', 'private' ],
            'terms'          => [ 'categories' => '{type}category' ],
            'tags'           => '{type}_tags',
            'fields'         => [],
            'contact'        => [ 'phone', 'email' ],
            'defaults'       => [],
        ],
        'directorist'  => [
            'label'          => 'Directorist',
            'constant'       => 'ATBDP_VERSION',
            'statuses'       => [ 'publish', 'pending', 'draft', 'private', 'expired' ],
            'write_statuses' => [ 'publish', 'pending', 'draft', 'private', 'expired' ],
            'terms'          => [ 'categories' => 'at_biz_dir-category', 'locations' => 'at_biz_dir-location' ],
            'tags'           => 'at_biz_dir-tags',
            'fields'         => [
                'tagline'      => [ '_tagline', 'text', '' ],
                'address'      => [ '_address', 'text', '' ],
                'zip'          => [ '_zip', 'text', '' ],
                'latitude'     => [ '_manual_lat', 'coordinate', '' ],
                'longitude'    => [ '_manual_lng', 'coordinate', '' ],
                'phone'        => [ '_phone', 'text', '' ],
                'phone2'       => [ '_phone2', 'text', '' ],
                'fax'          => [ '_fax', 'text', '' ],
                'email'        => [ '_email', 'email', '' ],
                'website'      => [ '_website', 'url', '' ],
                'price'        => [ '_price', 'text', '' ],
                'featured'     => [ '_featured', 'flag', '' ],
                'expiry_date'  => [ '_expiry_date', 'datetime', '' ],
                'never_expire' => [ '_never_expire', 'flag', '' ],
            ],
            'contact'        => [ 'phone', 'phone2', 'fax', 'email' ],
            'defaults'       => [ '_featured' => 0, '_listing_status' => 'post_status' ],
        ],
    ];

    private const DIRECTORIST_TYPES = 'atbdp_listing_types';

    /** GeoDirectory detail columns a read returns (never submit_ip). */
    private const GD_DETAILS = [ 'street', 'street2', 'city', 'region', 'country', 'zip', 'latitude', 'longitude', 'phone', 'email', 'website', 'twitter', 'facebook', 'video', 'featured', 'overall_rating', 'rating_count' ];

    public static function active(): bool
    {
        foreach (array_keys(self::PLUGINS) as $prefix) {
            if (self::loaded($prefix)) {
                return true;
            }
        }
        return false;
    }

    private static function loaded(string $prefix): bool
    {
        $found = defined(self::PLUGINS[ $prefix ]['constant']);
        switch ($prefix) {
            case 'jobmanager':
                return (bool) apply_filters('wpmcp_wp_job_manager_active', $found);
            case 'geodirectory':
                return (bool) apply_filters('wpmcp_geodirectory_active', $found);
            default:
                return (bool) apply_filters('wpmcp_directorist_active', $found);
        }
    }

    /** @return true|array{code: string, message: string, data: array} */
    private static function presence(string $prefix)
    {
        if (self::loaded($prefix)) {
            return true;
        }
        $refusal = Plugin_Refusal::make('plugin_missing', self::PLUGINS[ $prefix ]['label']);
        if ('inactive' === ($refusal['data']['state'] ?? '')) {
            $refusal['code'] = 'plugin_inactive';
        }
        return $refusal;
    }

    /** The plugin's display name per op prefix, for the pair's operation_hosts(). */
    public static function label(string $prefix): string
    {
        return self::PLUGINS[ $prefix ]['label'];
    }

    /** @return string[] */
    public static function prefixes(): array
    {
        return array_keys(self::PLUGINS);
    }

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        $ops = [];
        foreach (array_keys(self::PLUGINS) as $prefix) {
            $ops += self::operations_for($prefix);
        }
        return $ops;
    }

    /** @return array<string, array<string, mixed>> */
    public static function operations_for(string $prefix): array
    {
        $p      = self::PLUGINS[ $prefix ];
        $label  = $p['label'];
        $gd     = 'geodirectory' === $prefix;
        $id     = [ 'type' => 'integer', 'minimum' => 1 ];
        $ids    = [ 'type' => 'array', 'items' => $id, 'maxItems' => 50 ];
        $type   = $gd ? [ 'post_type' => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 20 ] ] : [];
        $base   = [ 'requires' => static fn () => self::presence($prefix) ];
        $fields = [
            'title'   => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 500 ],
            'content' => [ 'type' => 'string' ],
            'excerpt' => [ 'type' => 'string' ],
            'status'  => [ 'type' => 'string', 'enum' => $p['write_statuses'] ],
        ];
        foreach (array_keys($p['terms']) as $key) {
            $fields[ $key ] = $ids;
        }
        if ('' !== $p['tags']) {
            $fields['tags'] = [ 'type' => 'array', 'items' => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 200 ], 'maxItems' => 50 ];
        }
        if ([] !== $p['fields']) {
            $fields['fields'] = [ 'type' => 'object', 'minProperties' => 1 ];
        }
        $create = $fields + $type;
        if ('directorist' === $prefix) {
            $create['directory_type'] = $id;
        }
        $write  = $base + [
            'mode'               => 'write',
            'enabled_by_default' => (bool) apply_filters('wpmcp_enable_listings_write', false),
            'opt_in_filter'      => self::OPT_IN_FILTER,
        ];
        $own    = static fn (string $access): array => [ 'id' => [ 'type' => 'post', 'own_type' => true, 'access' => $access ] ];
        $terms  = implode(', ', array_keys($p['terms'])) . ('' !== $p['tags'] ? ', tags (names)' : '');
        $extra  = [] !== $p['fields'] ? '; fields: ' . implode(', ', array_keys($p['fields'])) : '';
        $what   = $gd ? 'post fields, categories and tags (address and contact fields are read only)' : 'post fields, ' . $terms . $extra;

        return [
            "{$prefix}-list-listings"           => $base + [
                'mode'         => 'read',
                'description'  => "List {$label} listings newest first with their terms and fields, contact fields MASKED. Filter by status, category (term id), search" . ($gd ? ', post_type (default gd_place)' : '') . '. Only listings you may read',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'status'   => [ 'type' => 'string', 'enum' => $p['statuses'] ],
                        'category' => $id,
                        'search'   => [ 'type' => 'string', 'maxLength' => 200 ],
                        'page'     => [ 'type' => 'integer', 'minimum' => 1 ],
                        'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PER_PAGE ],
                    ] + $type,
                ],
                'validate'     => static fn (array $args): ?array => self::type_refusal($prefix, $args),
                'handler'      => static fn (array $args): array => self::list_listings($prefix, $args),
            ],
            "{$prefix}-get-listing"             => $base + [
                'mode'         => 'read',
                'objects'      => $own('read'),
                'description'  => "Read one {$label} listing: content, status, terms and fields; contact fields in full for an administrator or the listing's author",
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => $id ], 'required' => [ 'id' ] ],
                'validate'     => static fn (array $args): ?array => self::listing_refusal($prefix, (int) $args['id'], false),
                'handler'      => static fn (array $args): array => self::get_listing($prefix, (int) $args['id']),
            ],
            "{$prefix}-list-listing-categories" => $base + [
                'mode'         => 'read',
                'description'  => "List {$label} terms (id, name, slug, parent, count): " . implode(', ', array_keys($p['terms'])) . ('directorist' === $prefix ? ', directory_types' : ''),
                'input_schema' => [ 'type' => 'object', 'properties' => [] === $type ? (object) [] : $type ],
                'validate'     => static fn (array $args): ?array => self::type_refusal($prefix, $args),
                'handler'      => static fn (array $args): array => self::list_terms($prefix, $args),
            ],
            "{$prefix}-create-listing"          => $write + [
                'self_snapshotting' => true,
                'description'       => "Create one {$label} listing (status defaults to draft): {$what}. Undo: rollback-session trashes it",
                'input_schema'      => [ 'type' => 'object', 'properties' => $create, 'required' => [ 'title' ] ],
                'validate'          => static fn (array $args): ?array => self::create_refusal($prefix, $args),
                'handler'           => static fn (array $args, array $context): array => self::create_listing($prefix, $args, $context),
            ],
            "{$prefix}-update-listing"          => $write + [
                'objects'      => $own('write'),
                'description'  => "Change one {$label} listing: {$what}. Only the args given change; term lists replace the listing's set. Undo: rollback-operation",
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => $id ] + $fields, 'required' => [ 'id' ], 'minProperties' => 2 ],
                'validate'     => static fn (array $args): ?array => self::update_refusal($prefix, $args),
                'snapshot'     => static fn (array $args): array => [ 'object_type' => 'post', 'object_id' => (int) $args['id'] ],
                'handler'      => static fn (array $args): array => self::update_listing($prefix, $args),
            ],
            "{$prefix}-set-listing-status"      => $write + [
                'objects'      => $own('write'),
                'description'  => "Set the status of one {$label} listing (" . implode(', ', $p['write_statuses']) . '). Undo: rollback-operation',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'id' => $id, 'status' => [ 'type' => 'string', 'enum' => $p['write_statuses'] ] ],
                    'required'   => [ 'id', 'status' ],
                ],
                'validate'     => static fn (array $args): ?array => self::update_refusal($prefix, $args),
                'snapshot'     => static fn (array $args): array => [ 'object_type' => 'post', 'object_id' => (int) $args['id'] ],
                'handler'      => static fn (array $args): array => self::update_listing($prefix, [ 'id' => $args['id'], 'status' => $args['status'] ]),
            ],
        ];
    }

    // ------------------------------------------------------------ types and terms

    /** @return string[] the post types the plugin keeps listings in */
    private static function post_types(string $prefix): array
    {
        if ('jobmanager' === $prefix) {
            return [ 'job_listing' ];
        }
        if ('directorist' === $prefix) {
            return [ defined('ATBDP_POST_TYPE') ? (string) ATBDP_POST_TYPE : 'at_biz_dir' ];
        }
        $types = function_exists('geodir_get_posttypes') ? array_map('strval', (array) geodir_get_posttypes()) : [];
        return [] !== $types ? array_values($types) : [ 'gd_place' ];
    }

    private static function default_type(string $prefix): string
    {
        $types = self::post_types($prefix);
        return in_array('gd_place', $types, true) ? 'gd_place' : $types[0];
    }

    private static function taxonomy(string $template, string $post_type): string
    {
        return str_replace('{type}', $post_type, $template);
    }

    private static function type_refusal(string $prefix, array $args): ?array
    {
        if (! isset($args['post_type']) || in_array((string) $args['post_type'], self::post_types($prefix), true)) {
            return null;
        }
        return self::refusal('unknown_post_type', sprintf('"%s" is not one of the %s listing types: %s.', (string) $args['post_type'], self::label($prefix), implode(', ', self::post_types($prefix))), [ 'post_types' => self::post_types($prefix) ]);
    }

    /** @return array<int, array{id: int, name: string, slug: string}> */
    private static function post_terms(int $id, string $taxonomy): array
    {
        if (! taxonomy_exists($taxonomy)) {
            return [];
        }
        $terms = wp_get_post_terms($id, $taxonomy);
        if (! is_array($terms)) {
            return [];
        }
        return array_map(static fn (\WP_Term $t): array => [ 'id' => (int) $t->term_id, 'name' => (string) $t->name, 'slug' => (string) $t->slug ], $terms);
    }

    private static function list_terms(string $prefix, array $args): array
    {
        $p    = self::PLUGINS[ $prefix ];
        $type = (string) ($args['post_type'] ?? self::default_type($prefix));
        $map  = [];
        foreach ($p['terms'] as $key => $template) {
            $map[ $key ] = self::taxonomy($template, $type);
        }
        if ('directorist' === $prefix) {
            $map['directory_types'] = self::DIRECTORIST_TYPES;
        }
        $out = [];
        foreach ($map as $key => $taxonomy) {
            $out[ $key ] = [];
            if (! taxonomy_exists($taxonomy)) {
                continue;
            }
            $terms = get_terms([ 'taxonomy' => $taxonomy, 'hide_empty' => false ]);
            foreach (is_array($terms) ? $terms : [] as $term) {
                $out[ $key ][] = [ 'id' => (int) $term->term_id, 'name' => (string) $term->name, 'slug' => (string) $term->slug, 'parent' => (int) $term->parent, 'count' => (int) $term->count ];
            }
        }
        return $out;
    }

    // ------------------------------------------------------------ reads

    private static function list_listings(string $prefix, array $args): array
    {
        $p     = self::PLUGINS[ $prefix ];
        $type  = (string) ($args['post_type'] ?? self::default_type($prefix));
        $query = [
            'post_type'      => $type,
            'post_status'    => isset($args['status']) ? [ (string) $args['status'] ] : $p['statuses'],
            'posts_per_page' => (int) ($args['per_page'] ?? 20),
            'paged'          => (int) ($args['page'] ?? 1),
            'orderby'        => 'date',
            'order'          => 'DESC',
        ];
        if (isset($args['category'])) {
            $taxonomy           = self::taxonomy((string) reset($p['terms']), $type);
            $query['tax_query'] = [ [ 'taxonomy' => $taxonomy, 'field' => 'term_id', 'terms' => [ (int) $args['category'] ], 'include_children' => true ] ]; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- filtering listings by their category is the point of the op.
        }
        if (isset($args['search']) && '' !== trim((string) $args['search'])) {
            $query['s'] = (string) $args['search'];
        }
        $q = Readable_Posts::query($query);
        return [
            'listings' => array_map(static fn (\WP_Post $post): array => self::listing($prefix, $post, false, true), $q->posts),
            'total'    => (int) $q->found_posts,
            'masked'   => true,
        ];
    }

    private static function get_listing(string $prefix, int $id): array
    {
        $post = get_post($id);
        $full = current_user_can('manage_options') || (int) $post->post_author === get_current_user_id();
        return [ 'listing' => self::listing($prefix, $post, true, ! $full), 'masked' => ! $full ];
    }

    private static function listing(string $prefix, \WP_Post $post, bool $full, bool $mask): array
    {
        $p   = self::PLUGINS[ $prefix ];
        $id  = (int) $post->ID;
        $row = [
            'id'        => $id,
            'post_type' => (string) $post->post_type,
            'title'     => (string) $post->post_title,
            'status'    => (string) $post->post_status,
            'date'      => (string) $post->post_date,
            'modified'  => (string) $post->post_modified,
            'author_id' => (int) $post->post_author,
            'link'      => (string) get_permalink($post),
        ];
        foreach ($p['terms'] as $key => $template) {
            $row[ $key ] = self::post_terms($id, self::taxonomy($template, (string) $post->post_type));
        }
        if ('' !== $p['tags']) {
            $row['tags'] = self::post_terms($id, self::taxonomy($p['tags'], (string) $post->post_type));
        }
        if ([] !== $p['fields']) {
            $fields = [];
            foreach ($p['fields'] as $name => [ $key, $kind ]) {
                $value           = get_post_meta($id, $key, true);
                $fields[ $name ] = 'flag' === $kind ? (bool) (int) $value : (string) $value;
            }
            $row['fields'] = $mask ? Masking::keys($fields, $p['contact']) : $fields;
        }
        if ('directorist' === $prefix) {
            $row['directory_type'] = (int) get_post_meta($id, '_directory_type', true);
        }
        if ('geodirectory' === $prefix) {
            $details        = self::gd_details($id, (string) $post->post_type);
            $row['details'] = $mask ? Masking::keys($details, $p['contact']) : $details;
        }
        if ($full) {
            $row['content'] = (string) $post->post_content;
            $row['excerpt'] = (string) $post->post_excerpt;
        }
        return $row;
    }

    private static function gd_table(string $post_type): string
    {
        global $wpdb;
        return $wpdb->prefix . 'geodir_' . sanitize_key($post_type) . '_detail';
    }

    private static function gd_table_exists(string $post_type): bool
    {
        global $wpdb;
        $table = self::gd_table($post_type);
        return $table === $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
    }

    /** @return array<string, string> */
    private static function gd_details(int $id, string $post_type): array
    {
        global $wpdb;
        if (! self::gd_table_exists($post_type)) {
            return [];
        }
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE post_id = %d', self::gd_table($post_type), $id), ARRAY_A);
        if (! is_array($row)) {
            return [];
        }
        $out = [];
        foreach (self::GD_DETAILS as $column) {
            if (array_key_exists($column, $row)) {
                $out[ $column ] = (string) $row[ $column ];
            }
        }
        return $out;
    }

    // ------------------------------------------------------------ refusals

    private static function refusal(string $code, string $message, array $data = []): array
    {
        return [ 'code' => $code, 'message' => $message, 'data' => $data ];
    }

    private static function listing_refusal(string $prefix, int $id, bool $write): ?array
    {
        $label = self::label($prefix);
        $post  = get_post($id);
        if (! $post instanceof \WP_Post || ! in_array($post->post_type, self::post_types($prefix), true)) {
            return self::refusal('listing_not_found', sprintf('No %2$s listing has id %1$d.', $id, $label), [ 'id' => $id ]);
        }
        if (! current_user_can($write ? 'edit_post' : 'read_post', $id)) {
            return self::refusal('operation_denied', sprintf('You are not allowed to %s listing %d.', $write ? 'edit' : 'read', $id), [ 'reason' => 'capability', 'id' => $id ]);
        }
        if ('trash' === $post->post_status) {
            return self::refusal(
                'listing_trashed',
                sprintf('Listing %d is in the trash. Restore it from the %s trash in wp-admin to read or edit it.', $id, $label),
                [ 'id' => $id, 'status' => 'trash', 'previous_status' => (string) get_post_meta($id, '_wp_trash_meta_status', true) ]
            );
        }
        return null;
    }

    private static function capability_refusal(string $cap, string $why = ''): array
    {
        return self::refusal('operation_denied', sprintf('This needs the %s capability%s.', $cap, $why), [ 'reason' => 'capability', 'capability' => $cap ]);
    }

    private static function create_refusal(string $prefix, array $args): ?array
    {
        $refused = self::type_refusal($prefix, $args);
        if (null !== $refused) {
            return $refused;
        }
        $type   = (string) ($args['post_type'] ?? self::default_type($prefix));
        $object = get_post_type_object($type);
        if (! $object instanceof \WP_Post_Type) {
            return self::refusal('post_type_missing', sprintf('%s has not registered its %s post type.', self::label($prefix), $type));
        }
        if (! current_user_can($object->cap->create_posts)) {
            return self::capability_refusal((string) $object->cap->create_posts);
        }
        if (in_array((string) ($args['status'] ?? 'draft'), [ 'publish', 'private', 'expired' ], true) && ! current_user_can($object->cap->publish_posts)) {
            return self::capability_refusal((string) $object->cap->publish_posts, ' to publish a listing');
        }
        if ('directorist' === $prefix && isset($args['directory_type']) && ! get_term((int) $args['directory_type'], self::DIRECTORIST_TYPES) instanceof \WP_Term) {
            return self::refusal('unknown_directory_type', sprintf('%d is not a Directorist directory type. list-listing-categories lists them.', (int) $args['directory_type']), [ 'id' => (int) $args['directory_type'] ]);
        }
        return self::terms_refusal($prefix, $type, $args) ?? self::fields_refusal($prefix, $args);
    }

    private static function update_refusal(string $prefix, array $args): ?array
    {
        $id      = (int) $args['id'];
        $refused = self::listing_refusal($prefix, $id, true);
        if (null !== $refused) {
            return $refused;
        }
        $type   = (string) get_post_type($id);
        $status = (string) ($args['status'] ?? '');
        $object = get_post_type_object($type);
        if (in_array($status, [ 'publish', 'private', 'expired' ], true) && $status !== get_post_status($id) && $object instanceof \WP_Post_Type && ! current_user_can($object->cap->publish_posts)) {
            return self::capability_refusal((string) $object->cap->publish_posts, ' to publish a listing');
        }
        return self::terms_refusal($prefix, $type, $args) ?? self::fields_refusal($prefix, $args);
    }

    private static function terms_refusal(string $prefix, string $type, array $args): ?array
    {
        $p = self::PLUGINS[ $prefix ];
        foreach ($p['terms'] as $key => $template) {
            if (! isset($args[ $key ])) {
                continue;
            }
            $taxonomy = self::taxonomy($template, $type);
            if (! taxonomy_exists($taxonomy)) {
                return self::refusal('taxonomy_missing', sprintf('%s has not registered the %s taxonomy on this site (WP Job Manager registers job categories only while they are enabled in its settings).', self::label($prefix), $taxonomy), [ 'taxonomy' => $taxonomy ]);
            }
            $missing = [];
            foreach ((array) $args[ $key ] as $term_id) {
                if (! get_term((int) $term_id, $taxonomy) instanceof \WP_Term) {
                    $missing[] = (int) $term_id;
                }
            }
            if ([] !== $missing) {
                return self::refusal('unknown_term', sprintf('Not terms of %s: %s.', $taxonomy, implode(', ', $missing)), [ 'taxonomy' => $taxonomy, 'unknown' => $missing ]);
            }
            $tax = get_taxonomy($taxonomy);
            if ($tax && ! current_user_can($tax->cap->assign_terms)) {
                return self::capability_refusal((string) $tax->cap->assign_terms, ' to assign ' . $taxonomy);
            }
        }
        if (! empty($args['tags']) && '' !== $p['tags']) {
            $tax = get_taxonomy(self::taxonomy($p['tags'], $type));
            if ($tax && ! current_user_can($tax->cap->assign_terms)) {
                return self::capability_refusal((string) $tax->cap->assign_terms, ' to assign tags');
            }
        }
        return null;
    }

    private static function fields_refusal(string $prefix, array $args): ?array
    {
        $defs    = self::PLUGINS[ $prefix ]['fields'];
        $fields  = (array) ($args['fields'] ?? []);
        $unknown = array_values(array_diff(array_map('strval', array_keys($fields)), array_keys($defs)));
        if ([] !== $unknown) {
            return self::refusal('unknown_field', sprintf('Not one of the %s listing fields: %s.', self::label($prefix), implode(', ', $unknown)), [ 'unknown' => $unknown, 'fields' => array_keys($defs) ]);
        }
        foreach ($fields as $name => $value) {
            [ , $kind, $cap ] = $defs[ $name ];
            if ('' !== $cap && ! current_user_can($cap)) {
                return self::capability_refusal($cap, ' to set ' . $name);
            }
            if (null === self::field_value($kind, $value)) {
                return self::refusal('invalid_field', sprintf('%s is not a valid %s value (%s).', (string) wp_json_encode($value), $name, $kind), [ 'field' => $name, 'kind' => $kind ]);
            }
        }
        return null;
    }

    /**
     * The value stored for a field, or null when it is not valid for its
     * kind. An empty string clears a text field.
     *
     * @param mixed $value
     * @return int|string|null
     */
    private static function field_value(string $kind, $value)
    {
        if ('flag' === $kind) {
            return is_bool($value) || in_array($value, [ 0, 1, '0', '1' ], true) ? (int) (bool) $value : null;
        }
        if (! is_scalar($value) || is_bool($value)) {
            return null;
        }
        $text = trim((string) $value);
        if ('' === $text) {
            return '';
        }
        switch ($kind) {
            case 'email':
                return is_email($text) ? sanitize_email($text) : null;
            case 'url':
                return wp_http_validate_url($text) ? esc_url_raw($text) : null;
            case 'email_or_url':
                if (is_email($text)) {
                    return sanitize_email($text);
                }
                return wp_http_validate_url($text) ? esc_url_raw($text) : null;
            case 'date':
                return 1 === preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) ? $text : null;
            case 'datetime':
                return 1 === preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/', $text) ? $text : null;
            case 'coordinate':
                return is_numeric($text) && abs((float) $text) <= 180 ? $text : null;
            default:
                return sanitize_text_field($text);
        }
    }

    // ------------------------------------------------------------ writes

    private static function set_terms(string $prefix, int $id, string $type, array $args): void
    {
        $p    = self::PLUGINS[ $prefix ];
        $sets = [];
        foreach ($p['terms'] as $key => $template) {
            if (isset($args[ $key ])) {
                $sets[ self::taxonomy($template, $type) ] = array_map('intval', (array) $args[ $key ]);
            }
        }
        if (isset($args['tags']) && '' !== $p['tags'] && taxonomy_exists(self::taxonomy($p['tags'], $type))) {
            $sets[ self::taxonomy($p['tags'], $type) ] = array_map('strval', (array) $args['tags']);
        }
        foreach ($sets as $taxonomy => $terms) {
            $out = wp_set_object_terms($id, $terms, $taxonomy);
            if (is_wp_error($out)) {
                throw new Operation_Refused('update_failed', $out->get_error_message());
            }
        }
    }

    private static function set_fields(string $prefix, int $id, array $fields): void
    {
        $defs = self::PLUGINS[ $prefix ]['fields'];
        foreach ($fields as $name => $value) {
            [ $key, $kind ] = $defs[ $name ];
            update_post_meta($id, $key, self::field_value($kind, $value));
        }
    }

    private static function create_listing(string $prefix, array $args, array $context): array
    {
        $type = (string) ($args['post_type'] ?? self::default_type($prefix));
        $id   = wp_insert_post([
            'post_type'    => $type,
            'post_title'   => wp_slash((string) $args['title']),
            'post_content' => wp_slash((string) ($args['content'] ?? '')),
            'post_excerpt' => wp_slash((string) ($args['excerpt'] ?? '')),
            'post_status'  => (string) ($args['status'] ?? 'draft'),
            'post_author'  => get_current_user_id(),
        ], true);
        if (is_wp_error($id) || 0 === (int) $id) {
            throw new Operation_Refused('insert_failed', is_wp_error($id) ? $id->get_error_message() : sprintf('%s could not create the listing.', self::label($prefix)));
        }
        $id = (int) $id;
        $op = Post_Creation_Snapshot::record((string) $context['tool_name'], [ $id ], [ 'operation' => $context['operation'], 'args' => $args ], (string) $context['session_id']);
        foreach (self::PLUGINS[ $prefix ]['defaults'] as $key => $value) {
            update_post_meta($id, $key, $value);
        }
        if ('directorist' === $prefix) {
            self::directorist_setup($id, $args);
        }
        self::set_terms($prefix, $id, $type, $args);
        self::set_fields($prefix, $id, (array) ($args['fields'] ?? []));
        self::after_write($prefix, $id);
        return [ 'result' => [ 'listing' => self::listing($prefix, get_post($id), false, false) ], 'operation_ids' => [ $op ] ];
    }

    /** A new Directorist listing joins a directory type (the one asked for, else the site's default) and gets its expiry date, as Directorist's own importer does. */
    private static function directorist_setup(int $id, array $args): void
    {
        $directory = (int) ($args['directory_type'] ?? 0);
        if (0 === $directory && function_exists('directorist_get_default_directory')) {
            $directory = (int) directorist_get_default_directory();
        }
        if ($directory > 0) {
            update_post_meta($id, '_directory_type', $directory);
            wp_set_object_terms($id, [ $directory ], self::DIRECTORIST_TYPES);
        }
        $fields = (array) ($args['fields'] ?? []);
        if (! isset($fields['expiry_date']) && empty($fields['never_expire']) && function_exists('calc_listing_expiry_date')) {
            update_post_meta($id, '_expiry_date', calc_listing_expiry_date());
        }
    }

    private static function update_listing(string $prefix, array $args): array
    {
        $id     = (int) $args['id'];
        $fields = [ 'ID' => $id ];
        foreach ([ 'title' => 'post_title', 'content' => 'post_content', 'excerpt' => 'post_excerpt', 'status' => 'post_status' ] as $arg => $column) {
            if (array_key_exists($arg, $args)) {
                $fields[ $column ] = wp_slash((string) $args[ $arg ]);
            }
        }
        if (count($fields) > 1) {
            $out = Save_Filters::update_post($fields, true);
            if (is_wp_error($out)) {
                throw new Operation_Refused('update_failed', $out->get_error_message());
            }
        }
        self::set_terms($prefix, $id, (string) get_post_type($id), $args);
        self::set_fields($prefix, $id, (array) ($args['fields'] ?? []));
        self::after_write($prefix, $id);
        return [ 'listing' => self::listing($prefix, get_post($id), false, false) ];
    }

    private static function after_write(string $prefix, int $id): void
    {
        clean_post_cache($id);
        if ('geodirectory' === $prefix) {
            self::sync_geodirectory_row($id);
        }
    }

    /**
     * After a rollback put a post back (wpmcp_rollback_post_restored), bring
     * a GeoDirectory listing's detail row in step with it: the restore
     * writes the post row and terms directly, so GeoDirectory's own
     * save_post never runs.
     */
    public static function after_post_restored($post_id): void
    {
        $post_id = (int) $post_id;
        if ($post_id > 0 && self::loaded('geodirectory') && in_array((string) get_post_type($post_id), self::post_types('geodirectory'), true)) {
            self::sync_geodirectory_row($post_id);
        }
    }

    /**
     * Write the post's title, status, categories and tags into its
     * GeoDirectory detail row, in the forms GeoDir_Post_Data::save_post
     * stores them (",3,7," for categories, tag names comma separated, the
     * default category kept while the listing is still in it). A listing
     * without a row is left alone.
     */
    private static function sync_geodirectory_row(int $id): void
    {
        global $wpdb;
        $post = get_post($id);
        if (! $post instanceof \WP_Post || ! self::gd_table_exists((string) $post->post_type)) {
            return;
        }
        $table   = self::gd_table((string) $post->post_type);
        $current = $wpdb->get_row($wpdb->prepare('SELECT default_category FROM %i WHERE post_id = %d', $table, $id), ARRAY_A);
        if (! is_array($current)) {
            return;
        }
        $categories = taxonomy_exists($post->post_type . 'category') ? array_map('intval', (array) wp_get_object_terms($id, $post->post_type . 'category', [ 'fields' => 'ids' ])) : [];
        $tags       = taxonomy_exists($post->post_type . '_tags') ? array_map('strval', (array) wp_get_object_terms($id, $post->post_type . '_tags', [ 'fields' => 'names' ])) : [];
        $default    = (int) ($current['default_category'] ?? 0);
        if (! in_array($default, $categories, true)) {
            $default = $categories[0] ?? 0;
        }
        $title = (string) $post->post_title;
        $wpdb->update(
            $table,
            [
                'post_title'       => $title,
                '_search_title'    => function_exists('geodir_sanitize_keyword') ? (string) geodir_sanitize_keyword($title, (string) $post->post_type) : strtolower($title),
                'post_status'      => (string) $post->post_status,
                'post_category'    => [] !== $categories ? ',' . implode(',', $categories) . ',' : '',
                'default_category' => $default > 0 ? $default : null,
                'post_tags'        => implode(',', $tags),
            ],
            [ 'post_id' => $id ]
        );
        wp_cache_delete('gd_post_' . $id, 'gd_post');
    }
}
