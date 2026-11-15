<?php

namespace WPMCP\Integrations;

use WPMCP\Safety\Save_Filters;
use WPMCP\Tools\Content\Readable_Posts;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Site Reviews by Gemini Labs (issue #687) on the plugin-data pair, free
 * tier, so it adds no top-level tools. Ops are prefixed sitereviews- because
 * the pair is shared.
 *
 * Site Reviews 8.x keeps a review as a `site-review` post (post_content is
 * the review text, post_title its title, post_author the reviewer's user id
 * or 0, and the post status is the moderation state: publish is approved,
 * pending is unapproved, trash is trashed) and mirrors the rest in tables of
 * its own, prefixed glsr_ (Database\Tables):
 *  - {prefix}glsr_ratings: one row per review (ID, review_id = the post id,
 *    rating 0 to 5, type, is_approved, is_pinned, is_flagged, is_verified,
 *    name, email, avatar, ip_address, url, terms, score);
 *  - {prefix}glsr_assigned_posts (rating_id, post_id, is_published), and the
 *    assigned_terms and assigned_users tables, which say what a review is
 *    about.
 * The owner's reply is the `_response` post meta, with the replying user id
 * in `_response_by`. The per-post, per-term and per-user rating counters are
 * cached meta the plugin recomputes (CountManager::recalculate) whenever a
 * review's status changes, which Controllers\ReviewController does on the
 * transition_post_status hook, so every status change here goes through
 * WordPress's own transition (the plugin's glsr_update_review() for approve
 * and unapprove, wp_trash_post for trash) and the ratings table and counters
 * follow.
 *
 * Reads: sitereviews-list-reviews (filter by status, rating, assigned post
 * and date; reviewer email and IP masked), sitereviews-get-review (all of
 * it, the email and IP in full for the people who can moderate) and
 * sitereviews-review-summary (counts per status and rating, the average of
 * the approved ones). Both lists and single reads are kept to the reviews
 * the caller may read (Readable_Posts, read_post).
 *
 * Writes (off until a site opts in with wpmcp_enable_site_reviews_write,
 * since an approval puts a review on the public site and a reply is shown
 * under it): approve-review, unapprove-review, trash-review and
 * reply-to-review. Each runs under a post snapshot (fields and meta), so
 * rollback-operation puts the status and the reply back through WordPress's
 * own update, which makes the plugin sync its ratings table and recount
 * again. Site Reviews has no spam status of its own (it refuses spam as a
 * review is submitted), so there is no spam op; trash is the way to drop a
 * bad review, and it is never a permanent delete.
 *
 * The status and reply writes take the capabilities Site Reviews registers
 * for its post type (publish_site-reviews, respond_to_site-reviews, and
 * delete_post on the review). Presence is filterable
 * (wpmcp_site_reviews_active, default: the glsr() function exists).
 */
final class Site_Reviews_Pack
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reads Site Reviews' own tables live; it has no cached read API for a filtered list.

    public const POST_TYPE = 'site-review';

    public const OPT_IN_FILTER = 'wpmcp_enable_site_reviews_write';

    public const MAX_PER_PAGE = 100;

    public const PLUGIN_FILES = [ 'site-reviews/site-reviews.php' ];

    /** Most review ids a filtered list is resolved over before the readable ones are paged. */
    private const MAX_CANDIDATES = 5000;

    /** moderation state => post status. */
    private const STATES = [ 'approved' => 'publish', 'unapproved' => 'pending', 'trashed' => 'trash' ];

    /** @var callable|null stands in for glsr_update_review(), for tests */
    private static $updater = null;

    public static function active(): bool
    {
        return (bool) apply_filters('wpmcp_site_reviews_active', function_exists('glsr'));
    }

    /** Replace the plugin's glsr_update_review() in tests, which run without it. Null restores it. */
    public static function use_updater_for_tests(?callable $updater): void
    {
        if (! defined('WPMCP_TESTING') || ! WPMCP_TESTING) {
            return;
        }
        self::$updater = $updater;
    }

    public static function table(string $name): string
    {
        global $wpdb;
        return $wpdb->get_blog_prefix() . 'glsr_' . $name;
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
                'Site Reviews is %s on this site. Install and activate Site Reviews (%s), then try again.',
                $installed ? 'installed but not active' : 'not installed',
                $url
            ),
            'data'    => [ 'plugin' => 'site-reviews', 'plugins_url' => $url ],
        ];
    }

    /** @return true|array{code: string, message: string, data?: array} */
    private static function ready()
    {
        $present = self::presence();
        if (true !== $present) {
            return $present;
        }
        global $wpdb;
        if (null === $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like(self::table('ratings'))))) {
            return [
                'code'    => 'site_reviews_tables_missing',
                'message' => sprintf('Site Reviews\' table %s does not exist on this site. Deactivate and reactivate Site Reviews so it creates its tables, then try again.', self::table('ratings')),
                'data'    => [ 'plugins_url' => admin_url('plugins.php') ],
            ];
        }
        return true;
    }

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        $id     = [ 'type' => 'integer', 'minimum' => 1 ];
        $one    = [ 'type' => 'object', 'properties' => [ 'id' => $id ], 'required' => [ 'id' ] ];
        $date   = [ 'type' => 'string', 'pattern' => '^\d{4}-\d{2}-\d{2}$' ];
        $filter = [
            'status'           => [ 'type' => 'string', 'enum' => [ 'approved', 'unapproved', 'trashed', 'any' ] ],
            'rating'           => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 5 ],
            'assigned_post_id' => $id,
            'date_from'        => $date,
            'date_to'          => $date,
        ];
        $base   = [
            'capability' => 'edit_posts',
            'requires'   => static fn () => self::ready(),
        ];
        $write  = $base + [
            'mode'               => 'write',
            'enabled_by_default' => (bool) apply_filters('wpmcp_enable_site_reviews_write', false),
            'opt_in_filter'      => self::OPT_IN_FILTER,
            'objects'            => [ 'id' => [ 'type' => 'post', 'own_type' => true, 'access' => 'write' ] ],
            'input_schema'       => $one,
            'snapshot'           => static fn (array $args): array => [ 'object_type' => 'post', 'object_id' => (int) $args['id'] ],
        ];

        return [
            'sitereviews-list-reviews'    => $base + [
                'mode'         => 'read',
                'description'  => 'List Site Reviews reviews newest first: id, title, excerpt, status (approved, unapproved, trashed), rating, type, reviewer name, reviewer email and IP MASKED, pinned, verified, date, assigned post ids, whether it has a reply. Filter by status (default any except trashed), rating (1 to 5), assigned_post_id, date_from and date_to (YYYY-MM-DD), search. page and per_page (max 100). Only reviews you may read',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => $filter + [
                        'search'   => [ 'type' => 'string', 'maxLength' => 200 ],
                        'page'     => [ 'type' => 'integer', 'minimum' => 1 ],
                        'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PER_PAGE ],
                    ],
                ],
                'handler'      => static fn (array $args): array => self::list_reviews($args),
            ],
            'sitereviews-get-review'      => $base + [
                'mode'         => 'read',
                'objects'      => [ 'id' => [ 'type' => 'post', 'own_type' => true, 'access' => 'read' ] ],
                'description'  => 'Read one Site Reviews review in full: title, content, status, rating, reviewer name, email and IP, assigned posts, the reply and who wrote it',
                'input_schema' => $one,
                'validate'     => static fn (array $args): ?array => self::review_refusal((int) $args['id'], false),
                'handler'      => static fn (array $args): array => [ 'review' => self::view((int) $args['id'], true) ],
            ],
            'sitereviews-review-summary'  => $base + [
                'mode'         => 'read',
                'description'  => 'Site Reviews totals: reviews per status, approved reviews per rating (1 to 5) and their average. assigned_post_id narrows it to the reviews about one post',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'assigned_post_id' => $id ] ],
                'handler'      => static fn (array $args): array => self::summary($args),
            ],
            'sitereviews-approve-review'  => array_replace($write, [
                'description' => 'Approve a Site Reviews review (status publish: it shows on the site and counts in the ratings). A trashed review is refused. Undo: rollback-operation',
                'validate'    => static fn (array $args): ?array => self::status_refusal((int) $args['id'], 'publish'),
                'handler'     => static fn (array $args): array => self::set_status((int) $args['id'], 'publish'),
            ]),
            'sitereviews-unapprove-review' => array_replace($write, [
                'description' => 'Unapprove a Site Reviews review (status pending: it leaves the site and the ratings). A trashed review is refused. Undo: rollback-operation',
                'validate'    => static fn (array $args): ?array => self::status_refusal((int) $args['id'], 'pending'),
                'handler'     => static fn (array $args): array => self::set_status((int) $args['id'], 'pending'),
            ]),
            'sitereviews-trash-review'    => array_replace($write, [
                'description' => 'Move a Site Reviews review to the WordPress trash (it leaves the site and the ratings; the row is kept). Not a permanent delete, and Site Reviews has no spam status. Undo: rollback-operation brings it back with its status',
                'validate'    => static fn (array $args): ?array => self::trash_refusal((int) $args['id']),
                'handler'     => static fn (array $args): array => self::trash((int) $args['id']),
            ]),
            'sitereviews-reply-to-review' => array_replace($write, [
                'description'  => 'Set the owner reply shown under a Site Reviews review (it replaces any earlier reply and is public once the review is approved). Undo: rollback-operation puts the earlier reply back',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'id' => $id, 'response' => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 5000 ] ],
                    'required'   => [ 'id', 'response' ],
                ],
                'validate'     => static fn (array $args): ?array => self::reply_refusal((int) $args['id'], (string) $args['response']),
                'handler'      => static fn (array $args): array => self::reply((int) $args['id'], (string) $args['response']),
            ]),
        ];
    }

    // ------------------------------------------------------------ helpers

    private static function refusal(string $code, string $message, array $data = []): array
    {
        return [ 'code' => $code, 'message' => $message, 'data' => $data ];
    }

    /** A reviewer email with all but its first letter and its domain hidden: j***@example.com. */
    public static function mask_email(string $email): string
    {
        $email = trim($email);
        $at    = strrpos($email, '@');
        if (false === $at || 0 === $at) {
            return '' === $email ? '' : '***';
        }
        return substr($email, 0, 1) . '***' . substr($email, $at);
    }

    /** A reviewer IP with its host part hidden: 192.168.*.*, or the first two groups of an IPv6 address. */
    public static function mask_ip(string $ip): string
    {
        if ('' === $ip) {
            return '';
        }
        if (false !== filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            return $parts[0] . '.' . $parts[1] . '.*.*';
        }
        if (false !== filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $parts = explode(':', $ip);
            return $parts[0] . ':' . ( $parts[1] ?? '' ) . ':*';
        }
        return '***';
    }

    private static function state(string $post_status): string
    {
        $state = array_search($post_status, self::STATES, true);
        return false === $state ? $post_status : (string) $state;
    }

    /** @return array<string, mixed>|null the ratings row of a review */
    private static function rating_row(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE review_id = %d', self::table('ratings'), $id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /** @return int[] */
    private static function assigned_post_ids(int $rating_id): array
    {
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare('SELECT post_id FROM %i WHERE rating_id = %d ORDER BY post_id', self::table('assigned_posts'), $rating_id));
        return array_map('intval', (array) $ids);
    }

    /** @return array<string, mixed> */
    private static function view(int $id, bool $full): array
    {
        $post   = get_post($id);
        $rating = (array) self::rating_row($id);
        $email  = (string) ($rating['email'] ?? '');
        $ip     = (string) ($rating['ip_address'] ?? '');
        $reply  = (string) get_post_meta($id, '_response', true);
        // read_post on a published review needs only `read`, so the reviewer's contact details stay masked unless the caller may edit the review (the people who moderate it).
        $private = $full && current_user_can('edit_post', $id);
        $view   = [
            'id'            => $id,
            'title'         => (string) $post->post_title,
            'status'        => self::state((string) $post->post_status),
            'rating'        => (int) ($rating['rating'] ?? 0),
            'type'          => (string) ($rating['type'] ?? 'local'),
            'name'          => (string) ($rating['name'] ?? ''),
            'email'         => $private ? $email : self::mask_email($email),
            'ip_address'    => $private ? $ip : self::mask_ip($ip),
            'pinned'        => 1 === (int) ($rating['is_pinned'] ?? 0),
            'verified'      => 1 === (int) ($rating['is_verified'] ?? 0),
            'date'          => (string) $post->post_date,
            'assigned_posts' => isset($rating['ID']) ? self::assigned_post_ids((int) $rating['ID']) : [],
            'has_reply'     => '' !== $reply,
        ];
        if ($full) {
            $view['content']     = (string) $post->post_content;
            $view['reply']       = $reply;
            $view['reply_by']    = (int) get_post_meta($id, '_response_by', true);
            $view['author_id']   = (int) $post->post_author;
            $view['modified']    = (string) $post->post_modified;
            $view['url']         = (string) ($rating['url'] ?? '');
        } else {
            $view['excerpt'] = wp_trim_words(wp_strip_all_tags((string) $post->post_content), 40);
        }
        return $view;
    }

    // ------------------------------------------------------------ reads

    /**
     * The review ids a filter selects, newest first, from the plugin's own
     * ratings table joined to the post rows.
     *
     * @return int[]
     */
    private static function candidate_ids(array $args): array
    {
        global $wpdb;
        $state = (string) ($args['status'] ?? 'any');
        $joins = [ 'INNER JOIN %i p ON p.ID = r.review_id' ];
        $params = [ self::table('ratings'), $wpdb->posts ];
        $where = [ 'p.post_type = %s' ];
        $where_params = [ self::POST_TYPE ];
        if (isset(self::STATES[ $state ])) {
            $where[]        = 'p.post_status = %s';
            $where_params[] = self::STATES[ $state ];
        } else {
            $where[]        = 'p.post_status IN (%s, %s)';
            $where_params[] = 'publish';
            $where_params[] = 'pending';
        }
        if (isset($args['rating'])) {
            $where[]        = 'r.rating = %d';
            $where_params[] = (int) $args['rating'];
        }
        if (isset($args['assigned_post_id'])) {
            $joins[]      = 'INNER JOIN %i ap ON ap.rating_id = r.ID AND ap.post_id = %d';
            $params[]     = self::table('assigned_posts');
            $params[]     = (int) $args['assigned_post_id'];
        }
        if (isset($args['date_from'])) {
            $where[]        = 'p.post_date_gmt >= %s';
            $where_params[] = $args['date_from'] . ' 00:00:00';
        }
        if (isset($args['date_to'])) {
            $where[]        = 'p.post_date_gmt <= %s';
            $where_params[] = $args['date_to'] . ' 23:59:59';
        }
        $sql = 'SELECT DISTINCT r.review_id FROM %i r ' . implode(' ', $joins)
            . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY p.post_date_gmt DESC, r.review_id DESC LIMIT ' . self::MAX_CANDIDATES;
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- the SQL is built from literal fragments with %i and %s placeholders only.
        $ids = $wpdb->get_col($wpdb->prepare($sql, array_merge($params, $where_params)));
        return array_map('intval', (array) $ids);
    }

    /** @return array<string, mixed> */
    private static function list_reviews(array $args): array
    {
        $state = (string) ($args['status'] ?? 'any');
        $ids   = self::candidate_ids($args);
        if ([] === $ids) {
            return [ 'reviews' => [], 'total' => 0 ];
        }
        $query = [
            'post_type'      => self::POST_TYPE,
            'post_status'    => isset(self::STATES[ $state ]) ? [ self::STATES[ $state ] ] : [ 'publish', 'pending' ],
            'post__in'       => $ids,
            'orderby'        => 'post__in',
            'posts_per_page' => (int) ($args['per_page'] ?? 20),
            'paged'          => (int) ($args['page'] ?? 1),
        ];
        if (isset($args['search']) && '' !== trim((string) $args['search'])) {
            $query['s'] = (string) $args['search'];
        }
        $q = Readable_Posts::query($query);
        return [
            'reviews' => array_map(static fn (\WP_Post $p): array => self::view((int) $p->ID, false), $q->posts),
            'total'   => (int) $q->found_posts,
        ];
    }

    /** @return array<string, mixed> */
    private static function summary(array $args): array
    {
        global $wpdb;
        $join   = '';
        $params = [ self::table('ratings'), $wpdb->posts, self::POST_TYPE ];
        if (isset($args['assigned_post_id'])) {
            $join     = ' INNER JOIN %i ap ON ap.rating_id = r.ID AND ap.post_id = %d';
            $params   = [ self::table('ratings'), $wpdb->posts, self::table('assigned_posts'), (int) $args['assigned_post_id'], self::POST_TYPE ];
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- literal fragments with %i and %d placeholders only.
        $rows = (array) $wpdb->get_results($wpdb->prepare('SELECT p.post_status AS status, r.rating AS rating, COUNT(DISTINCT r.review_id) AS n FROM %i r INNER JOIN %i p ON p.ID = r.review_id' . $join . ' WHERE p.post_type = %s GROUP BY p.post_status, r.rating', $params), ARRAY_A);

        $by_status = [ 'approved' => 0, 'unapproved' => 0, 'trashed' => 0 ];
        $by_rating = [ 1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0 ];
        $sum       = 0;
        foreach ($rows as $row) {
            $n     = (int) $row['n'];
            $state = self::state((string) $row['status']);
            if (isset($by_status[ $state ])) {
                $by_status[ $state ] += $n;
            }
            if ('approved' === $state && isset($by_rating[ (int) $row['rating'] ])) {
                $by_rating[ (int) $row['rating'] ] += $n;
                $sum                               += $n * (int) $row['rating'];
            }
        }
        $rated = array_sum($by_rating);

        return [
            'by_status'      => $by_status,
            'approved_by_rating' => $by_rating,
            'approved_rated' => $rated,
            'average'        => $rated > 0 ? round($sum / $rated, 2) : null,
        ];
    }

    // ------------------------------------------------------------ refusals

    private static function review_refusal(int $id, bool $write): ?array
    {
        $post = get_post($id);
        if (! $post instanceof \WP_Post || self::POST_TYPE !== $post->post_type) {
            return self::refusal('review_not_found', sprintf('%d is not a Site Reviews review.', $id), [ 'id' => $id ]);
        }
        if (! current_user_can($write ? 'edit_post' : 'read_post', $id)) {
            return self::refusal('operation_denied', sprintf('You are not allowed to %s review %d.', $write ? 'edit' : 'read', $id), [ 'reason' => 'capability', 'id' => $id ]);
        }
        return null;
    }

    private static function type_cap(string $cap): string
    {
        $object = get_post_type_object(self::POST_TYPE);
        return $object instanceof \WP_Post_Type && isset($object->cap->$cap) ? (string) $object->cap->$cap : 'manage_options';
    }

    private static function status_refusal(int $id, string $to): ?array
    {
        $refused = self::review_refusal($id, true);
        if (null !== $refused) {
            return $refused;
        }
        $from = (string) get_post_status($id);
        if ('trash' === $from) {
            return self::refusal('review_trashed', sprintf('Review %d is in the trash; bring it back with rollback-operation first.', $id), [ 'id' => $id ]);
        }
        if ($from === $to) {
            return self::refusal('no_change', sprintf('Review %d is already %s.', $id, self::state($to)), [ 'id' => $id ]);
        }
        $cap = self::type_cap('publish_posts');
        return current_user_can($cap) ? null : self::refusal('operation_denied', sprintf('This needs the %s capability.', $cap), [ 'reason' => 'capability' ]);
    }

    private static function trash_refusal(int $id): ?array
    {
        $refused = self::review_refusal($id, true);
        if (null !== $refused) {
            return $refused;
        }
        if ('trash' === get_post_status($id)) {
            return self::refusal('no_change', sprintf('Review %d is already in the trash.', $id), [ 'id' => $id ]);
        }
        return current_user_can('delete_post', $id) ? null : self::refusal('operation_denied', sprintf('You are not allowed to delete review %d.', $id), [ 'reason' => 'capability', 'id' => $id ]);
    }

    private static function reply_refusal(int $id, string $response): ?array
    {
        $refused = self::review_refusal($id, true);
        if (null !== $refused) {
            return $refused;
        }
        if ('trash' === get_post_status($id)) {
            return self::refusal('review_trashed', sprintf('Review %d is in the trash.', $id), [ 'id' => $id ]);
        }
        if ('' === trim(wp_strip_all_tags($response))) {
            return self::refusal('invalid_params', 'The reply is empty.');
        }
        $caps = [ self::type_cap('respond_to_posts') ];
        if ((int) get_post_field('post_author', $id) !== get_current_user_id()) {
            $caps[] = self::type_cap('respond_to_others_posts');
        }
        foreach ($caps as $cap) {
            if (! current_user_can($cap)) {
                return self::refusal('operation_denied', sprintf('This needs the %s capability.', $cap), [ 'reason' => 'capability' ]);
            }
        }
        return null;
    }

    // ------------------------------------------------------------ writes

    /**
     * Site Reviews' own update, which sanitizes the values, stores the reply
     * and fires its review/updated and review/responded actions.
     *
     * @param array<string, mixed> $values
     */
    private static function update_review(int $id, array $values): void
    {
        if (null !== self::$updater) {
            $out = (self::$updater)($id, $values);
        } elseif (function_exists('glsr_update_review')) {
            $out = glsr_update_review($id, $values);
        } else {
            throw new Operation_Refused('plugin_inactive', 'Site Reviews is not loaded.');
        }
        if (false === $out) {
            throw new Operation_Refused('update_failed', sprintf('Site Reviews could not update review %d.', $id));
        }
        clean_post_cache($id);
    }

    /** @return array<string, mixed> */
    private static function set_status(int $id, string $status): array
    {
        // UpdateReviewDefaults derives the post status from is_approved and ReviewManager::updateReview()
        // keeps only keys the caller sent, so both are sent.
        self::update_review($id, [ 'is_approved' => 'publish' === $status, 'status' => $status ]);
        if ($status !== get_post_status($id)) {
            throw new Operation_Refused('update_failed', sprintf('Site Reviews did not change review %d to %s.', $id, self::state($status)));
        }
        return [ 'review' => self::view($id, false) ];
    }

    /** @return array<string, mixed> */
    private static function trash(int $id): array
    {
        // core's trash (the plugin's own path too): its transition_post_status hook syncs the ratings row and the counters.
        if (! Save_Filters::trash_post($id)) {
            throw new Operation_Refused('delete_failed', sprintf('Could not move review %d to the trash.', $id));
        }
        clean_post_cache($id);
        return [ 'review' => self::view($id, false) ];
    }

    /** @return array<string, mixed> */
    private static function reply(int $id, string $response): array
    {
        self::update_review($id, [ 'response' => $response ]);
        return [ 'review' => self::view($id, true) ];
    }
}
