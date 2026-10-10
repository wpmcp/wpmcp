<?php

// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps -- WP-style snake_case class name is intentional (matches the rest of WPMCP\Safety).
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WP-style snake_case method names are intentional (matches the rest of WPMCP\Safety).

namespace WPMCP\Safety;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * FunnelKit funnel snapshots and funnel creation rows (issue #500).
 *
 * A funnel is spread over three stores: its row in {prefix}bwf_funnels (the
 * ordered step list is the steps column, as JSON), its rows in
 * {prefix}bwf_funnelmeta, and one post per step, of the step's own post
 * type, with that step's settings in its post meta (a checkout's products
 * in _wfacp_selected_products, for example). 'funnelkit_funnel' captures
 * all three verbatim, keyed by the funnel id, so rollback puts back exactly
 * what was there: the row and its meta rows are rewritten raw, each step
 * post returns at its own id with exactly its captured meta, and a step
 * post the write added (listed now, not captured, and of a FunnelKit step
 * type) is deleted again.
 *
 * Rows are written back raw rather than through FunnelKit, whose own save
 * stamps the funnel and fires its update hooks; a restore must do neither.
 * FunnelKit's caches are dropped afterwards.
 *
 * 'funnelkit_funnel_create' is the creation row for a funnel a tool
 * created, written after the funnel exists (its id cannot be known before).
 * Undoing it deletes the funnel, its meta and its step posts. date_added is
 * fixed at creation, so a mismatch means a different funnel holds the id,
 * and it is left alone.
 *
 * Restoring either takes manage_woocommerce, the capability the funnel
 * writes take (Rollback_Service).
 */
final class FunnelKit_Funnel_Snapshot
{
    public const TYPE        = 'funnelkit_funnel';
    public const CREATE_TYPE = 'funnelkit_funnel_create';

    /** The post types FunnelKit stores its steps as. */
    public const STEP_POST_TYPES = [ 'wffn_landing', 'wffn_optin', 'wffn_oty', 'wfacp_checkout', 'wfocu_funnel', 'wfocu_offer', 'wffn_ty' ];

    public static function funnels_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bwf_funnels';
    }

    public static function meta_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'bwf_funnelmeta';
    }

    public static function capture(int $funnel_id): array
    {
        $row = self::row($funnel_id);
        if (null === $row) {
            return [ 'object_type' => self::TYPE, 'object_id' => $funnel_id, 'data' => [ 'exists' => false ] ];
        }

        $posts = [];
        foreach (self::step_ids($row) as $post_id) {
            $post = self::post_row($post_id);
            if (null !== $post && in_array($post['post_type'], self::STEP_POST_TYPES, true)) {
                $posts[] = [ 'post' => $post, 'meta' => self::post_meta_rows($post_id) ];
            }
        }

        return [
            'object_type' => self::TYPE,
            'object_id'   => $funnel_id,
            'data'        => [
                'exists' => true,
                'row'    => $row,
                'meta'   => self::meta_rows($funnel_id),
                'posts'  => $posts,
            ],
        ];
    }

    /** Put a funnel back to a 'funnelkit_funnel' snapshot. Returns a warning, or null. */
    public static function restore(array $snapshot): ?string
    {
        global $wpdb;

        $data = (array) ($snapshot['data'] ?? []);
        $id   = (int) ($snapshot['object_id'] ?? 0);
        if (empty($data['exists']) || $id <= 0 || ! is_array($data['row'] ?? null) || ! self::table_exists(self::funnels_table())) {
            return null;
        }

        $captured = [];
        foreach ((array) ($data['posts'] ?? []) as $entry) {
            $post_id = (int) ($entry['post']['ID'] ?? 0);
            if ($post_id > 0 && in_array((string) ($entry['post']['post_type'] ?? ''), self::STEP_POST_TYPES, true)) {
                $captured[ $post_id ] = $entry;
            }
        }

        // Step posts the write added: listed on the funnel now, not captured.
        $current = self::row($id);
        foreach (null === $current ? [] : self::step_ids($current) as $post_id) {
            if (! isset($captured[ $post_id ]) && in_array((string) get_post_type($post_id), self::STEP_POST_TYPES, true)) {
                wp_delete_post($post_id, true);
            }
        }

        $failed = [];
        foreach ($captured as $post_id => $entry) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- restoring the raw post row at its own id; caches are cleaned below.
            if (false === $wpdb->replace($wpdb->posts, (array) $entry['post'])) {
                $failed[] = $post_id;
                continue;
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- replacing the post's meta rows with the captured ones.
            $wpdb->delete($wpdb->postmeta, [ 'post_id' => $post_id ], [ '%d' ]);
            foreach ((array) ($entry['meta'] ?? []) as $meta) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- raw meta row at its own meta_id.
                $wpdb->insert($wpdb->postmeta, (array) $meta);
            }
            clean_post_cache($post_id);
            wp_cache_delete($post_id, 'post_meta');
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- FunnelKit's own table; its cache is dropped below.
        $written = $wpdb->replace(self::funnels_table(), (array) $data['row']);
        if (self::table_exists(self::meta_table())) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- FunnelKit's own meta table.
            $wpdb->delete(self::meta_table(), [ 'bwf_funnel_id' => $id ], [ '%d' ]);
            foreach ((array) ($data['meta'] ?? []) as $meta) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- raw meta row at its own meta_id.
                $wpdb->insert(self::meta_table(), (array) $meta);
            }
        }
        self::flush_caches($id);

        if (false === $written) {
            return "FunnelKit funnel {$id} could not be restored.";
        }
        return [] === $failed ? null : sprintf('FunnelKit funnel %d was restored, but its step posts %s could not be.', $id, implode(', ', $failed));
    }

    /**
     * Write the creation row for a funnel a tool just created. If the row
     * cannot be written the funnel is deleted again and the error rethrown.
     */
    public static function record_creation(int $funnel_id, string $tool_name, array $args, string $session_id): string
    {
        $row          = self::row($funnel_id);
        $operation_id = wp_generate_uuid4();
        try {
            Snapshot_Store::save(
                $operation_id,
                '' === $session_id ? 'default' : $session_id,
                [
                    'object_type' => self::CREATE_TYPE,
                    'object_id'   => $funnel_id,
                    'data'        => [ 'date_added' => (string) ($row['date_added'] ?? '') ],
                ],
                $tool_name,
                hash('sha256', (string) wp_json_encode($args))
            );
        } catch (\Throwable $e) {
            self::delete($funnel_id);
            throw $e;
        }
        Operation_Context::note($operation_id);
        Snapshot_Store::prune();

        return $operation_id;
    }

    /** Undo a 'funnelkit_funnel_create' row: delete the funnel. Returns a warning, or null. */
    public static function undo_creation(array $snapshot): ?string
    {
        $id  = (int) ($snapshot['object_id'] ?? 0);
        $row = self::row($id);
        if (null === $row) {
            return null; // Already gone: nothing to undo.
        }
        if ((string) ($snapshot['data']['date_added'] ?? '') !== (string) ($row['date_added'] ?? '')) {
            return "FunnelKit funnel {$id} is not the funnel this operation created (the id was reclaimed); it was left untouched.";
        }
        self::delete($id);
        return null === self::row($id) ? null : "FunnelKit funnel {$id} created by this operation could not be deleted; it was left in place.";
    }

    /** The raw funnel row, or null. */
    public static function row(int $funnel_id): ?array
    {
        global $wpdb;
        if ($funnel_id <= 0 || ! self::table_exists(self::funnels_table())) {
            return null;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- snapshot capture must read the live row, not a cache.
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', self::funnels_table(), $funnel_id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /**
     * The post ids a funnel row lists: each step, then its substeps.
     *
     * @return int[]
     */
    public static function step_ids(array $row): array
    {
        $steps = json_decode((string) ($row['steps'] ?? ''), true);
        $ids   = [];
        foreach (is_array($steps) ? $steps : [] as $step) {
            if (! is_array($step)) {
                continue;
            }
            $ids[] = (int) ($step['id'] ?? 0);
            foreach ((array) ($step['substeps'] ?? []) as $substeps) {
                foreach ((array) $substeps as $substep) {
                    $ids[] = (int) $substep;
                }
            }
        }
        return array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
    }

    /** Whether a table exists. */
    public static function table_exists(string $table): bool
    {
        global $wpdb;
        $suppress = $wpdb->suppress_errors(true);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- probing a third-party table; nothing to cache.
        $columns = $wpdb->get_col($wpdb->prepare('SHOW COLUMNS FROM %i', $table));
        $wpdb->suppress_errors($suppress);
        return [] !== (array) $columns;
    }

    /** @return array<int, array<string, mixed>> */
    private static function meta_rows(int $funnel_id): array
    {
        global $wpdb;
        if (! self::table_exists(self::meta_table())) {
            return [];
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- snapshot capture must read the live rows.
        return (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE bwf_funnel_id = %d ORDER BY meta_id ASC', self::meta_table(), $funnel_id), ARRAY_A);
    }

    private static function post_row(int $post_id): ?array
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- snapshot capture must read the live row.
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->posts} WHERE ID = %d", $post_id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /** @return array<int, array<string, mixed>> */
    private static function post_meta_rows(int $post_id): array
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- snapshot capture must read the live rows.
        return (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->postmeta} WHERE post_id = %d ORDER BY meta_id ASC", $post_id), ARRAY_A);
    }

    /** Delete a funnel, its meta rows and its step posts. */
    private static function delete(int $funnel_id): void
    {
        global $wpdb;
        $row = self::row($funnel_id);
        foreach (null === $row ? [] : self::step_ids($row) as $post_id) {
            if (in_array((string) get_post_type($post_id), self::STEP_POST_TYPES, true)) {
                wp_delete_post($post_id, true);
            }
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- FunnelKit's own table; its cache is dropped below.
        $wpdb->delete(self::funnels_table(), [ 'id' => $funnel_id ], [ '%d' ]);
        if (self::table_exists(self::meta_table())) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- FunnelKit's own meta table.
            $wpdb->delete(self::meta_table(), [ 'bwf_funnel_id' => $funnel_id ], [ '%d' ]);
        }
        self::flush_caches($funnel_id);
    }

    /**
     * Drop the funnel FunnelKit's admin keeps for the rest of the request.
     * WFFN_Admin::get_funnel() answers that object for any later call with
     * the same id, and for a call with no id at all, so after a create, a
     * delete or a raw restore it would hand FunnelKit's next write a stale
     * funnel (and a second create in one request would reuse the first).
     */
    public static function forget_cached_funnel(): void
    {
        if (! function_exists('WFFN_Core')) {
            return;
        }
        try {
            $admin = WFFN_Core()->admin;
            if (is_object($admin) && property_exists($admin, 'funnel')) {
                // A private property of WFFN_Admin with no reset method.
                \Closure::bind(function (): void {
                    $this->funnel = null;
                }, $admin, get_class($admin))();
            }
        } catch (\Throwable $e) {
            unset($e);
        }
    }

    /** FunnelKit's funnel cache, its funnel meta cache and its cached list of published checkouts. */
    private static function flush_caches(int $funnel_id): void
    {
        self::forget_cached_funnel();
        wp_cache_delete($funnel_id, 'bwf_funnel_meta');
        if (function_exists('WFFN_Core')) {
            $db = WFFN_Core()->get_dB();
            if (is_object($db) && method_exists($db, 'clear_cache')) {
                $db->clear_cache();
            }
        }
        if (is_callable([ 'WFACP_Common', 'save_publish_checkout_pages_in_transient' ])) {
            call_user_func([ 'WFACP_Common', 'save_publish_checkout_pages_in_transient' ]);
        }
    }
}
