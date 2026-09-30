<?php

namespace WPMCP\Safety;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Stands the core save filters down for one write.
 *
 * The core writers run save filters (kses, balanceTags, sanitize_text_field
 * and friends) over every text column they store, including the ones a call
 * never changed: wp_update_post() re-saves the whole row, so changing only a
 * post's status still hands the stored title, content and excerpt to kses for
 * a user without unfiltered_html, and kses rewrites markup the site stored on
 * purpose ("<script>" goes, a lone "<" becomes "&lt;").
 *
 * The status and single-field helpers here lift only the filters of the
 * columns the call leaves alone. A column the call does set keeps its filters,
 * so an ordinary content edit is filtered exactly as before. Every hook
 * (save_post, transition_post_status, post_updated) still fires.
 */
final class Save_Filters
{
    /** The filters sanitize_post() runs over each text column of a post row (db context). */
    public const POST_COLUMNS = [
        'post_content'          => ['pre_post_content', 'content_save_pre'],
        'post_excerpt'          => ['pre_post_excerpt', 'excerpt_save_pre'],
        'post_title'            => ['pre_post_title', 'title_save_pre'],
        'post_content_filtered' => ['pre_post_content_filtered', 'content_filtered_save_pre'],
    ];

    /**
     * Every post text column's save filters. For a user without
     * unfiltered_html these carry kses.
     */
    public const POST = [
        'pre_post_content',
        'content_save_pre',
        'pre_post_excerpt',
        'excerpt_save_pre',
        'pre_post_title',
        'title_save_pre',
        'pre_post_content_filtered',
        'content_filtered_save_pre',
    ];

    /**
     * Run $write with the given filters lifted for its whole duration.
     *
     * The filters are put back in a finally block, so a write that throws
     * cannot leave the site without them.
     *
     * @param string[] $hooks
     * @return mixed Whatever $write returns.
     */
    public static function without(array $hooks, callable $write)
    {
        $lifted = self::lift($hooks);
        try {
            return $write();
        } finally {
            self::put_back($lifted);
        }
    }

    /**
     * wp_update_post() that runs the save filters only over the text columns
     * $postarr sets. The rest of the row is written back as stored.
     *
     * @param array<string, mixed> $postarr Slashed, as wp_update_post() expects.
     * @return int|\WP_Error Whatever wp_update_post() returns.
     */
    public static function update_post(array $postarr, bool $wp_error = false)
    {
        $hooks = [];
        foreach (self::POST_COLUMNS as $column => $filters) {
            if (! array_key_exists($column, $postarr)) {
                $hooks = array_merge($hooks, $filters);
            }
        }
        return self::for_one_row($hooks, static fn () => wp_update_post($postarr, $wp_error));
    }

    /**
     * $fields without the text columns the stored row already holds byte for
     * byte. A write that carries a whole row (a sync, a published stage) then
     * leaves its unchanged text out of the save filters entirely.
     *
     * @param array<string, mixed> $fields Unslashed.
     * @return array<string, mixed>
     */
    public static function changed_text(int $post_id, array $fields): array
    {
        $stored = get_post($post_id, ARRAY_A);
        if (! is_array($stored)) {
            return $fields;
        }
        foreach (array_keys(self::POST_COLUMNS) as $column) {
            if (array_key_exists($column, $fields) && (string) $stored[ $column ] === (string) $fields[ $column ]) {
                unset($fields[ $column ]);
            }
        }
        return $fields;
    }

    /**
     * Change only a post's status.
     *
     * @return int|\WP_Error Whatever wp_update_post() returns.
     */
    public static function set_post_status(int $post_id, string $status, bool $wp_error = false)
    {
        return self::update_post(['ID' => $post_id, 'post_status' => $status], $wp_error);
    }

    /**
     * wp_trash_post() without rewriting the row it moves to the trash.
     *
     * @return \WP_Post|false|null Whatever wp_trash_post() returns.
     */
    public static function trash_post(int $post_id)
    {
        return self::for_one_row(self::POST, static fn () => wp_trash_post($post_id));
    }

    /**
     * wp_untrash_post() without rewriting the row it brings back.
     *
     * @return \WP_Post|false|null Whatever wp_untrash_post() returns.
     */
    public static function untrash_post(int $post_id)
    {
        return self::for_one_row(self::POST, static fn () => wp_untrash_post($post_id));
    }

    /**
     * Lift $hooks until the row is sanitized, not for the whole write.
     *
     * wp_insert_post() sanitizes the row, then applies wp_insert_post_data,
     * then stores it and fires the save hooks. The filters come back at
     * wp_insert_post_data, so any post a save_post or transition listener
     * writes in turn is filtered as usual. They also come back in a finally
     * block if the write never gets that far.
     *
     * @param string[] $hooks The filters to lift.
     * @return mixed Whatever $write returns.
     */
    private static function for_one_row(array $hooks, callable $write)
    {
        $lifted  = self::lift($hooks);
        $pending = true;
        $restore = static function ($data) use (&$lifted, &$pending, &$restore) {
            if ($pending) {
                $pending = false;
                self::put_back($lifted);
            }
            remove_filter('wp_insert_post_data', $restore, PHP_INT_MIN);
            return $data;
        };
        add_filter('wp_insert_post_data', $restore, PHP_INT_MIN);

        try {
            return $write();
        } finally {
            remove_filter('wp_insert_post_data', $restore, PHP_INT_MIN);
            if ($pending) {
                $pending = false;
                self::put_back($lifted);
            }
        }
    }

    /**
     * @param string[] $hooks
     * @return array<string, \WP_Hook>
     */
    private static function lift(array $hooks): array
    {
        global $wp_filter;

        $lifted = [];
        foreach (array_unique($hooks) as $hook) {
            if (isset($wp_filter[ $hook ])) {
                $lifted[ $hook ] = $wp_filter[ $hook ];
                unset($wp_filter[ $hook ]);
            }
        }
        return $lifted;
    }

    /** @param array<string, \WP_Hook> $lifted */
    private static function put_back(array $lifted): void
    {
        global $wp_filter;

        foreach ($lifted as $hook => $callbacks) {
            // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- puts back the exact hook object lifted above.
            $wp_filter[ $hook ] = $callbacks;
        }
    }
}
