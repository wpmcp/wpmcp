<?php

namespace WPMCP\Tools\Content;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Post queries kept to the rows the current user may read (issues #448,
 * #461, #465).
 *
 * WP_Query applies no read permission to post_status 'any' or to an
 * explicit status, so a listing built on it shows other users' drafts and
 * private posts to anyone who can run it. Every query here adds
 * Content_Guard::readable_posts_where(), core's read_post rule in SQL, so
 * found_posts and max_num_pages count only the visible rows.
 *
 * post_status defaults to 'any': these are the listings over every status.
 * The filter runs on posts_where, so the query runs with filters on (a
 * suppress_filters request is overridden); other plugins' query filters
 * apply here as they do to any WP_Query listing.
 *
 * AnyStatusQueryGuardTest fails on a post_status 'any' query in src/ that
 * does not go through this class and is not on its documented allowlist.
 */
final class Readable_Posts
{
    /**
     * Run a WP_Query kept to the readable rows.
     *
     * @param array<string, mixed> $args WP_Query arguments.
     */
    public static function query(array $args): \WP_Query
    {
        global $wpdb;

        $args                     += [ 'post_status' => 'any' ];
        $args['suppress_filters'] = false;

        $where = Content_Guard::readable_posts_where(self::post_types($args['post_type'] ?? 'post'), $wpdb->posts);
        $query = new \WP_Query();
        $scope = static function ($sql, $q) use (&$query, $where) {
            return $q === $query ? $sql . $where : $sql;
        };
        if ('' !== $where) {
            add_filter('posts_where', $scope, 10, 2);
        }
        try {
            $query->query($args);
        } finally {
            remove_filter('posts_where', $scope, 10);
        }
        return $query;
    }

    /**
     * get_posts() kept to the readable rows: the same defaults (five rows,
     * numberposts as an alias of posts_per_page, no sticky posts, no found
     * rows count) and the same return value, posts or ids by 'fields'.
     *
     * @param array<string, mixed> $args get_posts() arguments.
     * @return array<int, \WP_Post|int>
     */
    public static function get_posts(array $args): array
    {
        if (isset($args['numberposts']) && ! isset($args['posts_per_page'])) {
            $args['posts_per_page'] = $args['numberposts'];
        }
        $args += [
            'posts_per_page'      => 5,
            'ignore_sticky_posts' => true,
            'no_found_rows'       => true,
        ];
        return self::query($args)->posts;
    }

    /**
     * The post types a query covers, with 'any' resolved the way WP_Query
     * resolves it (every type not excluded from search), so the filter
     * narrows each of them.
     *
     * @param mixed $post_type
     * @return string[]
     */
    private static function post_types($post_type): array
    {
        $types = array_map('strval', (array) $post_type);
        if (in_array('any', $types, true)) {
            return array_values(array_map('strval', get_post_types([ 'exclude_from_search' => false ])));
        }
        return $types;
    }
}
