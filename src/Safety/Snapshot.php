<?php

namespace WPMCP\Safety;

if (! defined('ABSPATH')) {
    exit;
}

class Snapshot
{
    /**
     * Capture the pre-mutation state of an object so it can later be restored
     * by Rollback_Service::apply_snapshot(). The object identifier's type
     * depends on $object_type: posts (and attachments, which are posts) are
     * identified by their integer ID; options are identified by their string
     * name. Dispatching here on $object_type, rather than on the PHP type of
     * $object_id, keeps the door open for future object types (e.g. users)
     * that might also use an int identifier but need different capture logic.
     */
    public static function capture(string $object_type, $object_id): array
    {
        if ('option' === $object_type) {
            return self::capture_option((string) $object_id);
        }
        if ('user' === $object_type) {
            return self::capture_user((int) $object_id);
        }
        if ('comment' === $object_type) {
            return self::capture_comment((int) $object_id);
        }
        if ('wc_order' === $object_type) {
            return self::capture_wc_order((int) $object_id);
        }
        if ('wc_order_full' === $object_type) {
            return Wc_Order_Snapshot::capture((int) $object_id);
        }
        if ('wc_shipping_zone' === $object_type) {
            return Wc_Shipping_Zone_Snapshot::capture((int) $object_id);
        }
        if ('wc_webhook' === $object_type) {
            return Wc_Webhook_Snapshot::capture((int) $object_id);
        }
        if ('db_rows' === $object_type) {
            return self::capture_db_rows((string) $object_id);
        }
        if ('redirect' === $object_type) {
            return self::capture_redirect((string) $object_id);
        }
        if ('php_snippet' === $object_type) {
            return self::capture_php_snippet((string) $object_id);
        }
        if ('term' === $object_type) {
            return self::capture_term((string) $object_id);
        }
        if ('yoast_term_seo' === $object_type) {
            return self::capture_yoast_term_seo((string) $object_id);
        }
        if ('wc_tax_rate' === $object_type) {
            return self::capture_wc_tax_rate((int) $object_id);
        }
        if ('aioseo_row' === $object_type) {
            return self::capture_aioseo_row((string) $object_id);
        }
        if (Redirection_Item_Snapshot::TYPE === $object_type) {
            return Redirection_Item_Snapshot::capture((int) $object_id);
        }
        if (Plugin_Table_Rows_Snapshot::TYPE === $object_type) {
            return Plugin_Table_Rows_Snapshot::capture((string) $object_id);
        }
        if (BuddyPress_Rows_Snapshot::TYPE === $object_type) {
            return BuddyPress_Rows_Snapshot::capture((string) $object_id);
        }
        if (Site_Template_Snapshot::TYPE === $object_type) {
            return Site_Template_Snapshot::capture((string) $object_id);
        }
        if ('theme_scaffold' === $object_type) {
            return self::capture_theme_scaffold((string) $object_id);
        }
        if ('package_install' === $object_type) {
            return self::capture_package_install((string) $object_id);
        }
        if (Core_Files_Snapshot::TYPE === $object_type) {
            return Core_Files_Snapshot::capture((string) $object_id);
        }
        if ('acf_structure' === $object_type) {
            return self::capture_acf_structure((string) $object_id);
        }
        if ('acf_options' === $object_type) {
            return self::capture_acf_options((string) $object_id);
        }
        if ('option_set' === $object_type) {
            return self::capture_option_set((string) $object_id);
        }
        return self::capture_post($object_id);
    }

    /** Post types ACF stores its own structure in, keyed by the prefix of the ACF key that names one. */
    public const ACF_STRUCTURE_POST_TYPES = [
        'group_'     => 'acf-field-group',
        'post_type_' => 'acf-post-type',
        'taxonomy_'  => 'acf-taxonomy',
    ];

    /**
     * Capture one piece of ACF structure (a field group, an ACF post type or
     * an ACF taxonomy, issue #291), keyed by its ACF key rather than by post
     * ID for the same reason terms are keyed by slug: the key is known BEFORE
     * a create, the post ID is not, so a create runs through Safe_Mutation
     * like any other write and its undo is "delete whatever now owns the key".
     *
     * A field group is more than its own post: every field (and every sub
     * field of a group, repeater or flexible content field) is an acf-field
     * post parented under it. An update can add, change and delete those, so
     * the whole tree is captured as ordinary post snapshots, and the restore
     * puts back exactly that tree and removes anything the write added.
     */
    private static function capture_acf_structure(string $key): array
    {
        $root  = self::acf_structure_root_id($key);
        $posts = [];
        foreach (null === $root ? [] : self::acf_structure_tree($root) as $id) {
            $posts[ (string) $id ] = self::capture_post($id)['data'];
        }

        return [
            'object_type' => 'acf_structure',
            'object_id'   => $key,
            'data'        => [
                'key'     => $key,
                'existed' => null !== $root,
                'root_id' => $root,
                'posts'   => $posts,
            ],
        ];
    }

    /**
     * The database post that holds an ACF key, or null. Read from the live
     * table rather than through ACF's lookups, which cache key-to-ID answers
     * in the object cache: a snapshot must see the row, not a memo of it.
     */
    public static function acf_structure_root_id(string $key): ?int
    {
        $post_type = null;
        foreach (self::ACF_STRUCTURE_POST_TYPES as $prefix => $type) {
            if (str_starts_with($key, $prefix)) {
                $post_type = $type;
                break;
            }
        }
        if (null === $post_type) {
            return null;
        }

        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a snapshot must read the live row; ACF memoizes key lookups in the object cache.
        $id = $wpdb->get_var($wpdb->prepare(
            'SELECT ID FROM %i WHERE post_name = %s AND post_type = %s ORDER BY ID ASC LIMIT 1',
            $wpdb->posts,
            $key,
            $post_type
        ));

        return null === $id ? null : (int) $id;
    }

    /**
     * $root plus every acf-field post beneath it, parents before children.
     *
     * @return int[]
     */
    public static function acf_structure_tree(int $root): array
    {
        global $wpdb;
        $all      = [ $root ];
        $frontier = [ $root ];
        while ([] !== $frontier) {
            $next = [];
            foreach ($frontier as $parent) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a snapshot must read the live rows; ACF memoizes field lists in the object cache.
                $children = $wpdb->get_col($wpdb->prepare("SELECT ID FROM %i WHERE post_type = 'acf-field' AND post_parent = %d ORDER BY ID ASC", $wpdb->posts, $parent));
                foreach ((array) $children as $child) {
                    if (! in_array((int) $child, $all, true)) {
                        $all[]  = (int) $child;
                        $next[] = (int) $child;
                    }
                }
            }
            $frontier = $next;
        }
        return $all;
    }

    /**
     * Capture the option rows an ACF options page write can touch (issue
     * #291). $id is "<acf post_id>|<field name>,<field name>", for example
     * "options|hero_title,hero_items". ACF stores a value as the option
     * "<post_id>_<name>" plus a hidden "_<post_id>_<name>" reference to the
     * field key, and a repeater or group also writes "<post_id>_<name>_*"
     * rows for its sub values, so every row under those prefixes is captured.
     * The restore removes rows the write added and puts every captured row
     * back, which is exact where an 'option' snapshot per name could not be:
     * the set of rows a repeater write produces is not known in advance.
     */
    private static function capture_acf_options(string $id): array
    {
        [ $post_id, $names ] = self::split_acf_options_id($id);

        $rows = [];
        foreach (self::acf_option_names($post_id, $names) as $name) {
            $rows[ $name ] = get_option($name);
        }

        return [
            'object_type' => 'acf_options',
            'object_id'   => $id,
            'data'        => [
                'post_id' => $post_id,
                'names'   => $names,
                'rows'    => $rows,
            ],
        ];
    }

    /**
     * Capture several named options as ONE undo point (issue #285). A classic
     * widget write changes the widget_{id_base} option holding the instance
     * AND the sidebars_widgets option placing it; two separate 'option'
     * snapshots would let a rollback restore one without the other and leave
     * a placed widget with no settings, or settings nobody placed. $id is the
     * comma-joined option names from option_set_id(). Each option is captured
     * exactly as capture_option() would, existence included.
     */
    private static function capture_option_set(string $id): array
    {
        $options = [];
        foreach (self::split_option_set_id($id) as $name) {
            $options[ $name ] = [
                'value'   => get_option($name),
                'existed' => self::option_exists($name),
            ];
        }

        return [
            'object_type' => 'option_set',
            'object_id'   => $id,
            'data'        => [ 'options' => $options ],
        ];
    }

    /** Build the option_set snapshot id for a list of option names. */
    public static function option_set_id(array $names): string
    {
        $names = array_values(array_unique(array_filter(array_map('strval', $names), 'strlen')));
        sort($names);
        return implode(',', $names);
    }

    /** @return string[] */
    public static function split_option_set_id(string $id): array
    {
        return array_values(array_filter(explode(',', $id), 'strlen'));
    }

    /** Build the acf_options snapshot id for a set of field names on one options post_id. */
    public static function acf_options_id(string $post_id, array $names): string
    {
        $names = array_values(array_unique(array_map('strval', $names)));
        sort($names);
        return $post_id . '|' . implode(',', $names);
    }

    /** @return array{0: string, 1: string[]} */
    public static function split_acf_options_id(string $id): array
    {
        $parts = explode('|', $id, 2);
        $names = array_values(array_filter(explode(',', (string) ($parts[1] ?? '')), 'strlen'));
        return [ (string) $parts[0], $names ];
    }

    /**
     * Every option row currently stored for these ACF field names under an
     * options post_id: the value row, its hidden reference row, and any sub
     * value rows beneath either.
     *
     * @return string[]
     */
    public static function acf_option_names(string $post_id, array $names): array
    {
        if ('' === $post_id || [] === $names) {
            return [];
        }

        global $wpdb;
        $found = [];
        foreach ($names as $name) {
            foreach ([ "{$post_id}_{$name}", "_{$post_id}_{$name}" ] as $base) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a snapshot must enumerate the live rows under a prefix; no options API lists by prefix.
                $rows = $wpdb->get_col($wpdb->prepare(
                    'SELECT option_name FROM %i WHERE option_name = %s OR option_name LIKE %s ORDER BY option_name ASC',
                    $wpdb->options,
                    $base,
                    $wpdb->esc_like($base . '_') . '%'
                ));
                foreach ((array) $rows as $row) {
                    $found[ (string) $row ] = true;
                }
            }
        }
        return array_keys($found);
    }

    /**
     * Capture a taxonomy term, keyed by "taxonomy:slug" rather than by
     * term_id, for exactly the reason capture_redirect() is keyed by source
     * path: the slug is the term's natural key within its taxonomy (WordPress
     * enforces it as unique there), and it is known BEFORE the write, while
     * the auto-increment term_id is not.
     *
     * That is what lets create-term run through Safe_Mutation like every
     * other write instead of recording an after-the-fact "I made this" row:
     * the capture is simply "no term owned this slug yet", and the undo is to
     * delete whatever now does.
     *
     * The full row is captured, term_id included, so restoring a deleted term
     * resurrects the same id rather than a copy. term_taxonomy_id, parent,
     * description and count come along because wp_insert_term() cannot
     * reconstruct them from the name alone, and a category that comes back
     * without its parent has silently moved in the site's hierarchy.
     */
    /**
     * Cap on how many object assignments a term snapshot carries. See
     * capture_term(); exceeding it is recorded, never silently dropped.
     */
    public const MAX_TERM_OBJECTS = 5000;

    private static function capture_term(string $key): array
    {
        [$taxonomy, $slug] = self::split_term_key($key);

        $term = ('' !== $taxonomy && '' !== $slug)
            ? get_term_by('slug', $slug, $taxonomy, ARRAY_A)
            : false;

        $meta      = [];
        $objects   = [];
        $truncated = false;

        if (is_array($term) && isset($term['term_id'])) {
            $term_id = (int) $term['term_id'];

            $stored = get_term_meta($term_id);
            $meta   = is_array($stored) ? $stored : [];

            // The object relationships have to come along. wp_delete_term()
            // deletes the wp_term_relationships rows as well as the term, so
            // restoring the term and term_taxonomy rows alone resurrects an
            // EMPTY term: it looks correct in wp-admin while every post that
            // was filed under it has silently lost the assignment.
            $objects = get_objects_in_term([$term_id], [$taxonomy]);
            $objects = is_wp_error($objects) ? [] : array_map('intval', (array) $objects);

            if (count($objects) > self::MAX_TERM_OBJECTS) {
                // A term on a large site can hold tens of thousands of posts,
                // and a snapshot blob that big is its own problem. The cap is
                // recorded rather than hidden so the restore can say plainly
                // that it reattached a subset.
                $objects   = array_slice($objects, 0, self::MAX_TERM_OBJECTS);
                $truncated = true;
            }
        }

        return [
            'object_type' => 'term',
            'object_id'   => $key,
            'data'        => [
                'taxonomy'         => $taxonomy,
                'slug'             => $slug,
                'existed'          => is_array($term),
                'term'             => is_array($term) ? $term : null,
                'meta'             => $meta,
                'objects'          => $objects,
                'objects_truncated' => $truncated,
            ],
        ];
    }

    /**
     * All in One SEO's per-object tables: kind => [table suffix, id column].
     * The terms table ships only with the paid plugin.
     */
    public const AIOSEO_TABLES = [
        'post' => ['aioseo_posts', 'post_id'],
        'term' => ['aioseo_terms', 'term_id'],
    ];

    /**
     * Capture every All in One SEO row one post or term has, keyed
     * "post:<id>" or "term:<id>" (issue #294). AIOSEO keeps those fields in
     * its own table, which the post and term snapshots do not see.
     *
     * The rows are kept verbatim (primary key and NULLs included), so the
     * restore puts back exactly this set and removes any row the write
     * added. Not db_rows: that type restores only rows a WHERE matched, so it
     * cannot undo an insert, and its restore needs manage_options while the
     * SEO tools run at edit_posts.
     */
    private static function capture_aioseo_row(string $key): array
    {
        [$kind, $id] = array_pad(explode(':', $key, 2), 2, '');
        $rows        = self::aioseo_rows((string) $kind, (int) $id);

        return [
            'object_type' => 'aioseo_row',
            'object_id'   => $key,
            'data'        => [
                'kind'         => (string) $kind,
                'id'           => (int) $id,
                'table_exists' => null !== $rows,
                'rows'         => $rows ?? [],
            ],
        ];
    }

    /** The prefixed AIOSEO table and id column for a kind, or null for an unknown kind. */
    public static function aioseo_table(string $kind): ?array
    {
        global $wpdb;

        if (! isset(self::AIOSEO_TABLES[$kind])) {
            return null;
        }

        return [$wpdb->prefix . self::AIOSEO_TABLES[$kind][0], self::AIOSEO_TABLES[$kind][1]];
    }

    /**
     * The live column names of an AIOSEO table, or [] when it does not exist.
     * SHOW COLUMNS rather than SHOW TABLES, which does not list temporary
     * tables.
     *
     * @return string[]
     */
    public static function aioseo_columns(string $kind): array
    {
        global $wpdb;

        $table = self::aioseo_table($kind);
        if (null === $table) {
            return [];
        }

        $suppress = $wpdb->suppress_errors(true);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- probing a third-party table's live shape; nothing to cache.
        $columns = $wpdb->get_col($wpdb->prepare('SHOW COLUMNS FROM %i', $table[0]));
        $wpdb->suppress_errors($suppress);

        return array_map('strval', (array) $columns);
    }

    /**
     * Every AIOSEO row for one post or term, oldest first, read live; null
     * when the table does not exist on this site.
     *
     * @return array<int, array<string, string|null>>|null
     */
    public static function aioseo_rows(string $kind, int $id): ?array
    {
        global $wpdb;

        $table = self::aioseo_table($kind);
        if (null === $table || $id <= 0 || [] === self::aioseo_columns($kind)) {
            return null;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a snapshot must read the live row of a third-party table; there is no WP API for it.
        $rows = $wpdb->get_results(
            $wpdb->prepare('SELECT * FROM %i WHERE %i = %d ORDER BY id ASC', $table[0], $table[1], $id),
            ARRAY_A
        );

        return array_values((array) $rows);
    }

    /** Where Yoast SEO keeps every term's SEO fields, in one option. */
    public const YOAST_TAXONOMY_META_OPTION = 'wpseo_taxonomy_meta';

    /**
     * Capture ONE term's row inside Yoast's `wpseo_taxonomy_meta` option,
     * keyed "taxonomy:term_id" (issue #67).
     *
     * Yoast stores the SEO fields of every term on the site in that single
     * option. An 'option' snapshot would capture all of it, and restoring it
     * would silently revert every other term's SEO edits made since. This
     * captures and restores only the row the write touched, which is the same
     * blast radius the term-meta plugins get from a 'term' snapshot.
     */
    private static function capture_yoast_term_seo(string $key): array
    {
        [$taxonomy, $term_id] = array_pad(explode(':', $key, 2), 2, '');
        $term_id              = (int) $term_id;

        $option = get_option(self::YOAST_TAXONOMY_META_OPTION, []);
        $row    = is_array($option) && isset($option[$taxonomy][$term_id]) && is_array($option[$taxonomy][$term_id])
            ? $option[$taxonomy][$term_id]
            : null;

        return [
            'object_type' => 'yoast_term_seo',
            'object_id'   => $key,
            'data'        => [
                'taxonomy' => (string) $taxonomy,
                'term_id'  => $term_id,
                'existed'  => null !== $row,
                'row'      => $row,
            ],
        ];
    }

    /**
     * Split a "taxonomy:slug" snapshot key. Only the FIRST colon separates
     * the two: a taxonomy name cannot contain a colon (sanitize_key strips
     * it) but a slug can arrive with one before sanitisation, so splitting on
     * the last colon would mis-key those terms.
     *
     * @return array{0: string, 1: string}
     */
    public static function split_term_key(string $key): array
    {
        $at = strpos($key, ':');
        if (false === $at) {
            return ['', ''];
        }

        return [substr($key, 0, $at), substr($key, $at + 1)];
    }

    /** Build the snapshot key for a term. */
    public static function term_key(string $taxonomy, string $slug): string
    {
        return $taxonomy . ':' . $slug;
    }

    /**
     * Capture a managed redirect (issue #128), keyed by its SOURCE PATH
     * rather than its row id, exactly like an option is keyed by its name.
     *
     * The source path is the redirect's natural key (it is the UNIQUE column
     * the front-end handler looks requests up by) and, unlike the
     * auto-increment id, it is known BEFORE the write. That is what lets
     * create-redirect run through Safe_Mutation like every other write
     * instead of recording an after-the-fact "I created this" row: the
     * capture is simply "no redirect owned this path yet", and the undo is to
     * delete whatever now does.
     *
     * The whole row is captured, id included, so restoring a deleted
     * redirect resurrects the same row rather than a copy with a new id.
     */
    private static function capture_redirect(string $source_path): array
    {
        $source = \WPMCP\Tools\Redirects\Redirect_Store::normalize_path($source_path);
        $row    = \WPMCP\Tools\Redirects\Redirect_Store::find_by_source($source);

        return [
            'object_type' => 'redirect',
            'object_id'   => $source,
            'data'        => [
                'source_path' => $source,
                'existed'     => null !== $row,
                'row'         => $row,
            ],
        ];
    }

    /**
     * Capture ONE stored PHP snippet (issue #85), keyed by its id, for
     * exactly the reason capture_redirect() is keyed by source path: the
     * snippets all live in a single option, and snapshotting that option
     * would make every snippet write a snapshot of the whole collection.
     * Rolling back the creation of snippet A would then delete every
     * snippet created after it and resurrect every snippet deleted since,
     * which is not "reversible", it is collateral damage.
     *
     * So the unit of capture is the record, not the option. The undo is
     * "put this one record back exactly as it was", or, when it did not
     * exist before the write, "remove whatever now holds that id" -
     * leaving every sibling snippet untouched either way.
     *
     * $object_id is the snippet id (a string), so Snapshot_Store persists 0
     * in its BIGINT object_id column, exactly like 'option' snapshots.
     */
    private static function capture_php_snippet(string $id): array
    {
        $record = \WPMCP\Tools\Code\Php_Snippet_Store::get($id);

        return [
            'object_type' => 'php_snippet',
            'object_id'   => $id,
            'data'        => [
                'id'      => $id,
                'existed' => null !== $record,
                'record'  => $record,
            ],
        ];
    }

    /**
     * Skeleton snapshot for a generic single-table row write (update-rows /
     * delete-rows, issue #82). Unlike every other object type, the state to
     * capture cannot be derived from ($object_type, $object_id) alone: the
     * rows are selected by the tool's WHERE clause, and the tool has already
     * fetched them (before-image) to decide recoverability. The tool
     * therefore supplies the real payload via Safe_Mutation's additive
     * 'extra_snapshot_data' seam, which merges into 'data' here:
     *   table       string  validated real table name
     *   operation   string  'update' | 'delete'
     *   primary_key array   PK column names, in index order (never empty)
     *   where       array   the tool call's equality WHERE (context)
     *   set         array   update only: the applied column => value map,
     *                       used for conflict detection at rollback time
     *   rows        array   full before-image rows (SELECT *, capped)
     * $object_id is the table name (a string), so Snapshot_Store persists 0
     * in its BIGINT object_id column, exactly like 'option' snapshots.
     */
    private static function capture_db_rows(string $table): array
    {
        return [
            'object_type' => 'db_rows',
            'object_id'   => $table,
            'data'        => [],
        ];
    }

    /**
     * Capture a WooCommerce order's prior status so update-order-status can be
     * undone. Only the status is captured, deliberately: an order can live in
     * HPOS custom tables or the legacy CPT, and a full generic row-restore
     * across both stores would be unsafe to promise. update-order-status only
     * ever changes the status, so restoring exactly that (via WC_Order's CRUD
     * setter, which writes correctly to whichever store is active) is an
     * honest, complete undo of what the tool did. If the order no longer
     * exists at capture time the status is null, matching how the post/comment
     * paths record a missing object.
     */
    private static function capture_wc_order(int $order_id): array
    {
        $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
        return [
            'object_type' => 'wc_order',
            'object_id'   => $order_id,
            'data'        => [
                'status' => $order ? $order->get_status() : null,
            ],
        ];
    }

    /**
     * Capture a WooCommerce tax rate (issue #195). A tax rate is not a post:
     * it is a row in woocommerce_tax_rates plus its postcode and city rows in
     * woocommerce_tax_rate_locations, so neither the post nor the option path
     * can hold it.
     *
     * The whole row is captured, tax_rate_id included, so restoring a deleted
     * rate resurrects the SAME id rather than a copy: order tax line items
     * record the rate id they were charged under, and a copy at a new id would
     * leave every past order pointing at a rate that no longer exists. The
     * locations come along because WC_Tax::_delete_tax_rate() deletes them
     * with the rate, and a rate restored without its postcode list silently
     * starts applying to the whole country.
     *
     * The row is read through WC_Tax::_get_tax_rate(); the locations have no
     * WooCommerce getter keyed by rate id, so they are read with one prepared
     * SELECT. If the rate no longer exists the row is null, matching how
     * capture_post() records a missing post.
     */
    private static function capture_wc_tax_rate(int $tax_rate_id): array
    {
        $row       = null;
        $postcodes = [];
        $cities    = [];

        if ($tax_rate_id > 0 && class_exists('WC_Tax')) {
            $found = \WC_Tax::_get_tax_rate($tax_rate_id, ARRAY_A);
            $row   = is_array($found) && ! empty($found) ? $found : null;
        }

        if (null !== $row) {
            global $wpdb;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- WooCommerce has no getter for one rate's locations; a snapshot must read the live rows, never a cache.
            $locations = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT location_code, location_type FROM %i WHERE tax_rate_id = %d ORDER BY location_id ASC',
                    $wpdb->prefix . 'woocommerce_tax_rate_locations',
                    $tax_rate_id
                ),
                ARRAY_A
            );
            foreach ((array) $locations as $location) {
                if ('postcode' === $location['location_type']) {
                    $postcodes[] = (string) $location['location_code'];
                } elseif ('city' === $location['location_type']) {
                    $cities[] = (string) $location['location_code'];
                }
            }
        }

        return [
            'object_type' => 'wc_tax_rate',
            'object_id'   => $tax_rate_id,
            'data'        => [
                'rate'      => $row,
                'postcodes' => $postcodes,
                'cities'    => $cities,
            ],
        ];
    }

    /**
     * Capture a comment's full row (plus its commentmeta) so moderate/edit
     * writes can be undone and a force-deleted comment can be resurrected.
     *
     * The full get_comment(ARRAY_A) row is kept rather than a hand-picked
     * subset (mirroring the post path's stance): a partial capture would let
     * a resurrection rebuild the missing columns from wp_insert_comment()'s
     * defaults (comment_date, comment_author_IP, comment_agent, comment_type,
     * comment_parent all lost). If the comment no longer exists at capture
     * time the row is null, matching how capture_post() records a missing post.
     */
    private static function capture_comment(int $comment_id): array
    {
        $comment = get_comment($comment_id, ARRAY_A);
        return [
            'object_type' => 'comment',
            'object_id'   => $comment_id,
            'data'        => [
                'comment' => $comment ?: null,
                'meta'    => get_comment_meta($comment_id),
            ],
        ];
    }

    /** Comment columns that identify the commenter beyond the name they chose to show. */
    public const COMMENT_PERSONAL_FIELDS = [ 'comment_author_email', 'comment_author_IP', 'comment_agent' ];

    /** Commentmeta that copies those fields (Akismet keeps the submitted comment verbatim). */
    public const COMMENT_PERSONAL_META = [ 'akismet_as_submitted' ];

    /** What a redacted field reads as. */
    public const REDACTED = '[redacted]';

    /**
     * A copy of a snapshot safe to show outside a restore (issue #348): the
     * author email, IP and user agent of every comment it holds are
     * redacted, and meta that copies them is dropped. That covers a comment
     * snapshot's row and the comments a post snapshot carries for its
     * resurrection. The stored snapshot keeps them, because a restore needs
     * the exact row; anything that reports on a snapshot uses this instead.
     */
    public static function without_personal_data(array $snapshot): array
    {
        if ('comment' === ($snapshot['object_type'] ?? '') && is_array($snapshot['data'] ?? null)) {
            if (is_array($snapshot['data']['comment'] ?? null)) {
                $snapshot['data']['comment'] = self::redact_comment($snapshot['data']['comment']);
            }
            if (is_array($snapshot['data']['meta'] ?? null)) {
                $snapshot['data']['meta'] = array_diff_key($snapshot['data']['meta'], array_flip(self::COMMENT_PERSONAL_META));
            }
        }
        if (is_array($snapshot['data']['comments'] ?? null)) {
            foreach ($snapshot['data']['comments'] as $i => $comment) {
                if (is_array($comment)) {
                    $snapshot['data']['comments'][ $i ] = self::redact_comment($comment);
                }
            }
        }
        return $snapshot;
    }

    /**
     * A comment row (with its 'meta' map, when it carries one) with the
     * commenter's email, IP and user agent replaced and the meta that copies
     * them dropped. Reports pass nothing and read REDACTED; a restore for a
     * caller without moderate_comments passes '' so the fields land blank
     * (issue #362).
     */
    public static function redact_comment(array $row, string $replacement = self::REDACTED): array
    {
        foreach (self::COMMENT_PERSONAL_FIELDS as $field) {
            if (isset($row[ $field ]) && '' !== $row[ $field ]) {
                $row[ $field ] = $replacement;
            }
        }
        if (is_array($row['meta'] ?? null)) {
            $row['meta'] = array_diff_key($row['meta'], array_flip(self::COMMENT_PERSONAL_META));
        }
        return $row;
    }

    /**
     * Capture a user's editable profile so Update_User's write can be undone.
     *
     * Only the columns wp_update_user() can restore are kept, plus the full
     * usermeta map (mirroring the post path's "full row, not a hand-picked
     * subset" stance so a rollback is exact even for meta the mutation added).
     * The password hash (user_pass) is deliberately NEVER captured: there is
     * no update-password tool, so a restore never needs it, and keeping it out
     * of the snapshot blob means the stored secret can never leak. There is
     * also no delete-user tool, so unlike posts there is no force-delete /
     * resurrection case to plan for here: the user always still exists at
     * rollback time and is restored in place.
     */
    private static function capture_user(int $user_id): array
    {
        $user = get_userdata($user_id);
        return [
            'object_type' => 'user',
            'object_id'   => $user_id,
            'data'        => [
                'fields' => $user ? [
                    'display_name' => $user->display_name,
                    'user_email'   => $user->user_email,
                    'user_url'     => $user->user_url,
                    'nickname'     => $user->nickname,
                    'first_name'   => $user->first_name,
                    'last_name'    => $user->last_name,
                    'description'  => $user->description,
                ] : null,
                'meta'   => $user ? get_user_meta($user_id) : [],
            ],
        ];
    }

    private static function capture_post(int $object_id): array
    {
        $post = get_post($object_id, ARRAY_A);
        return [
            'object_type' => 'post',
            'object_id'   => $object_id,
            'data'        => [
                // Full row (all columns), not a hand-picked subset: a partial
                // capture means resurrection after a force-delete rebuilds
                // the missing columns from wp_insert_post()'s defaults
                // (post_type becomes 'post', post_author/post_parent/post_name/
                // dates/menu_order/etc are lost). See apply_snapshot() for how
                // the in-place vs resurrection restore paths use this.
                'post'     => $post ?: null,
                'meta'     => get_post_meta($object_id),
                'terms'    => $post ? self::capture_terms($object_id, $post['post_type']) : [],
                // Only needed for the force-delete -> resurrect path: wp_delete_post($id, true)
                // destroys comments + commentmeta, which have no equivalent in
                // the trash/in-place-update paths.
                'comments' => $post ? self::capture_comments($object_id) : [],
            ],
        ];
    }

    /** Files a child-theme scaffold writes, and therefore the only files its snapshot captures or its rollback touches. */
    public const THEME_SCAFFOLD_FILES = ['style.css', 'functions.php'];

    /** Largest pre-existing scaffold file captured inline; a scaffold file is a few hundred bytes. */
    public const THEME_SCAFFOLD_MAX_BYTES = 1048576;

    /**
     * Capture a child-theme directory under get_theme_root() BEFORE the
     * create-child-theme scaffolder writes into it, keyed by the directory
     * slug (known before the write, like a term slug).
     *
     * The usual case is "the directory did not exist", and the undo is to
     * remove the files the scaffold created. The repair case (a marker-bearing
     * half scaffold being completed) overwrites a file that already exists,
     * so the prior bytes of each scaffold file are captured inline, base64
     * encoded so arbitrary bytes survive the JSON blob. A file too large to
     * capture is recorded as such, and its rollback leaves it alone with a
     * warning rather than pretending it can restore it.
     */
    private static function capture_theme_scaffold(string $slug): array
    {
        $clean = sanitize_key($slug);
        $dir   = ('' !== $clean && $clean === $slug) ? trailingslashit(get_theme_root()) . $clean : '';

        $files     = [];
        $too_large = [];
        foreach (self::THEME_SCAFFOLD_FILES as $file) {
            $path           = $dir . '/' . $file;
            $files[ $file ] = null;
            if ('' === $dir || is_link($path) || ! is_file($path)) {
                continue;
            }
            if ((int) filesize($path) > self::THEME_SCAFFOLD_MAX_BYTES) {
                $too_large[] = $file;
                continue;
            }
            $bytes = file_get_contents($path);
            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- keeps arbitrary file bytes intact inside the JSON snapshot blob, not obfuscation.
            $files[ $file ] = false === $bytes ? null : base64_encode($bytes);
        }

        return [
            'object_type' => 'theme_scaffold',
            'object_id'   => $slug,
            'data'        => [
                'slug'        => $clean,
                'dir_existed' => '' !== $dir && is_dir($dir),
                'files'       => $files,
                'too_large'   => $too_large,
            ],
        ];
    }

    /** A package directory name: what install-package-from-zip will create or replace. */
    public const PACKAGE_SLUG_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]*$/';

    /**
     * Capture a plugin or theme directory BEFORE install-package-from-zip
     * writes it (issue #282), keyed "plugin:<dir>" or "theme:<dir>", known
     * before the write like a term slug.
     *
     * Only the facts are captured here: whether the directory existed and
     * whether it was empty. The prior files themselves are too large for a
     * snapshot blob, so the tool archives them with
     * File_Backup::backup_directory() and adds that operation's id to the
     * snapshot as backup_operation_id.
     */
    private static function capture_package_install(string $key): array
    {
        [$type, $slug] = array_pad(explode(':', $key, 2), 2, '');

        $valid   = in_array($type, ['plugin', 'theme'], true) && 1 === preg_match(self::PACKAGE_SLUG_PATTERN, $slug);
        $dir     = $valid ? trailingslashit('theme' === $type ? get_theme_root() : WP_PLUGIN_DIR) . $slug : '';
        $existed = '' !== $dir && is_dir($dir) && ! is_link($dir);

        return [
            'object_type' => 'package_install',
            'object_id'   => $key,
            'data'        => [
                'type'                => $type,
                'slug'                => $slug,
                'existed'             => $existed,
                'was_empty'           => $existed && [] === array_diff((array) scandir($dir), ['.', '..']),
                'backup_operation_id' => '',
            ],
        ];
    }

    /**
     * Options have no equivalent of trash/force-delete: a write either
     * changes an existing option's value or, if it didn't exist yet, an
     * update introduces one. 'existed' records which case this was so
     * Rollback_Service can decide between update_option() (put the old
     * value back) and delete_option() (remove the option entirely, since it
     * wasn't there before the mutation).
     */
    private static function capture_option(string $name): array
    {
        return [
            'object_type' => 'option',
            'object_id'   => $name,
            'data'        => [
                'name'    => $name,
                'value'   => get_option($name),
                'existed' => self::option_exists($name),
            ],
        ];
    }

    /** True if $name has a row in the options table, distinguishing "unset" from a falsy stored value. */
    private static function option_exists(string $name): bool
    {
        $sentinel = '__wpmcp_missing__' . $name;
        return get_option($name, $sentinel) !== $sentinel;
    }

    /** Comments (with their commentmeta) attached to the post, for resurrection after a force-delete. */
    private static function capture_comments(int $post_id): array
    {
        $comments = get_comments(['post_id' => $post_id, 'status' => 'all', 'orderby' => 'comment_ID', 'order' => 'ASC']);
        $out = [];
        foreach ($comments as $comment) {
            $data = $comment->to_array();
            $data['meta'] = get_comment_meta((int) $comment->comment_ID);
            $out[] = $data;
        }
        return $out;
    }

    /** Map of taxonomy => term IDs currently assigned to the post, for terms rollback. */
    private static function capture_terms(int $post_id, string $post_type): array
    {
        $terms = [];
        foreach ((array) get_object_taxonomies($post_type) as $taxonomy) {
            $ids = wp_get_object_terms($post_id, $taxonomy, ['fields' => 'ids']);
            if (is_array($ids)) {
                $terms[ $taxonomy ] = $ids;
            }
        }
        return $terms;
    }

    /**
     * Base64 over the gzip, so the stored value is plain ASCII.
     *
     * The compression is worth keeping (snapshots of post_content are large
     * and highly compressible), but the raw gzip bytes are not portable
     * across database backends: WordPress's SQLite integration refuses a
     * binary payload from $wpdb->insert() with "Processing the value for the
     * following field failed", where MySQL's LONGBLOB accepts it. That split
     * meant the safety net silently did not exist on SQLite-backed installs
     * while every test on MySQL passed. Base64 costs ~33% of an already
     * compressed payload and makes the column backend-agnostic.
     */
    public static function serialize(array $before): string
    {
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary column portability encoding for the gzip payload (see docblock), not obfuscation.
        return base64_encode(gzencode((string) wp_json_encode($before)));
    }

    /**
     * Accepts both encodings. Rows written before the base64 change hold raw
     * gzip, and they are still the only undo point those sites have, so they
     * must keep decoding: gzip's magic bytes (1f 8b) identify them
     * unambiguously, and they are not valid base64 output.
     */
    public static function unserialize(string $blob): array
    {
        if (str_starts_with($blob, "\x1f\x8b")) {
            $json = @gzdecode($blob);
        } else {
            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decodes the portability encoding written by serialize() (see docblocks), not obfuscation.
            $decoded = base64_decode($blob, true);
            $json    = false === $decoded ? false : @gzdecode($decoded);
        }

        if (false === $json) {
            // An undecodable blob must be LOUD. Mapping it to [] made
            // restore_operation() walk apply_snapshot() with nothing to do
            // and still report restored: true - "restored" while restoring
            // nothing, the silent-failure mode this file exists to prevent.
            // A genuinely empty snapshot serializes to valid gzip of "[]",
            // so it never lands here; only corruption does.
            throw new \RuntimeException(
                'Snapshot blob is undecodable (truncated or corrupted row); refusing to report a restore that cannot happen.'
            );
        }

        $data = json_decode($json, true);

        if (! is_array($data)) {
            throw new \RuntimeException(
                'Snapshot blob decoded but is not a snapshot (corrupted row); refusing to report a restore that cannot happen.'
            );
        }

        return $data;
    }
}
