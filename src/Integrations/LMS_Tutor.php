<?php

namespace WPMCP\Integrations;

use WPMCP\Safety\Plugin_Table_Rows_Snapshot;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Tutor LMS storage for the LMS ops (issue #394), as Tutor LMS 4.1.0 keeps
 * it (checked against its source):
 *  - a course is a `courses` post (tutor()->course_post_type);
 *  - its topics are `topics` posts whose post_parent is the course, ordered
 *    by menu_order from 1 (Utils::get_next_topic_order_id());
 *  - lessons, quizzes and assignments are `lesson`, `tutor_quiz` and
 *    `tutor_assignments` posts whose post_parent is the topic, ordered by
 *    menu_order from 1 (Utils::get_next_course_content_order_id()), the list
 *    Utils::get_course_contents_by_topic() reads;
 *  - quiz questions are rows of {prefix}tutor_quiz_questions ordered by
 *    question_order;
 *  - an enrollment is a `tutor_enrolled` post whose post_parent is the course
 *    and post_author the student, status completed (enrolled), pending or
 *    cancel (EnrollmentModel).
 * Order changes write menu_order directly, the way Tutor LMS's own curriculum
 * sorter does, so no save filter rewrites the rest of the row. Tutor LMS
 * registers no abilities of its own.
 */
final class LMS_Tutor extends LMS_Adapter
{
    private const ENROLLED = 'tutor_enrolled';

    /** Tutor enrollment post status => normalized status. */
    private const STATUS_MAP = [ 'completed' => 'enrolled', 'pending' => 'pending', 'cancel' => 'cancelled' ];

    /** The callback Tutor LMS hooks on trashed_post (Course::__construct()). */
    private const TRASH_REDIRECT = 'TUTOR\\Course::redirect_to_course_list_page';

    /**
     * Tutor LMS answers the trashing of a course with wp_safe_redirect() and
     * exit, so its admin course list reloads. Outside a wp-admin screen load
     * (an MCP or REST request, admin-ajax, WP-CLI) that exit would end the
     * request mid-response, including a rollback that trashes a course this
     * pack created. Hooked on trashed_post ahead of Tutor LMS (Plugin::boot()),
     * it takes the redirect off for the rest of such a request only.
     */
    public static function keep_request_alive(int $post_id): void
    {
        $screen = is_admin() && ! wp_doing_ajax() && ! (defined('REST_REQUEST') && REST_REQUEST);
        if (! $screen && 'courses' === get_post_type($post_id)) {
            remove_action('trashed_post', self::TRASH_REDIRECT);
        }
    }

    public function slug(): string
    {
        return 'tutor';
    }

    public function label(): string
    {
        return 'Tutor LMS';
    }

    public function active(): bool
    {
        return (bool) apply_filters('wpmcp_tutor_active', defined('TUTOR_VERSION'));
    }

    protected function section_kind(): string
    {
        return 'topic';
    }

    protected function types(): array
    {
        return [
            'course'     => 'courses',
            'section'    => 'topics',
            'lesson'     => 'lesson',
            'quiz'       => 'tutor_quiz',
            'assignment' => 'tutor_assignments',
        ];
    }

    protected function movable_kinds(): array
    {
        return [ 'section', 'lesson', 'quiz', 'assignment' ];
    }

    protected function parent_kind(string $kind): string
    {
        return 'section';
    }

    protected function linked_course(\WP_Post $post, string $kind): int
    {
        $topic = 'section' === $kind ? (int) $post->ID : (int) $post->post_parent;
        if ('section' !== $kind && 'topics' !== get_post_type($topic)) {
            return 0;
        }
        $course = (int) wp_get_post_parent_id($topic);
        return $this->is_course($course) ? $course : 0;
    }

    protected function section_of(int $item): int
    {
        return (int) wp_get_post_parent_id($item);
    }

    protected function section_ids(int $course, bool $every_row = false): array
    {
        return array_map(static fn (\WP_Post $p): int => (int) $p->ID, self::children_by_parent($course, [ 'topics' ], $every_row));
    }

    protected function item_posts(int $section, bool $every_row = false): array
    {
        return self::children_by_parent($section, [ 'lesson', 'tutor_quiz', 'tutor_assignments' ], $every_row);
    }

    protected function stored_order(int $id): int
    {
        return (int) get_post_field('menu_order', $id);
    }

    protected function store_order(int $id, int $order): void
    {
        global $wpdb;
        $wpdb->update($wpdb->posts, [ 'menu_order' => $order ], [ 'ID' => $id ]); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one column, as Tutor LMS's own sorter writes it; the cache is cleaned below.
        clean_post_cache($id);
    }

    protected function store_section(int $item, int $section): void
    {
        global $wpdb;
        $wpdb->update($wpdb->posts, [ 'post_parent' => $section ], [ 'ID' => $item ]); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one column, as Tutor LMS moves content between topics; the cache is cleaned below.
        clean_post_cache($item);
    }

    protected function link_args(string $kind, int $parent, int $course, int $order): array
    {
        return [ 'post_parent' => $parent, 'menu_order' => $order ];
    }

    protected function questions(int $quiz): ?array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'tutor_quiz_questions';
        if (! Plugin_Table_Rows_Snapshot::table_exists($table)) {
            return null;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Tutor LMS's own question table, which has no read API outside its admin screens.
        $rows = $wpdb->get_results($wpdb->prepare('SELECT question_id, question_title, question_type, question_mark, question_order FROM %i WHERE quiz_id = %d ORDER BY question_order ASC, question_id ASC', $table, $quiz), ARRAY_A);

        return array_map(static fn (array $r): array => [
            'id'     => (int) $r['question_id'],
            'title'  => (string) $r['question_title'],
            'type'   => (string) $r['question_type'],
            'points' => null === $r['question_mark'] ? null : (float) $r['question_mark'],
            'order'  => (int) $r['question_order'],
        ], (array) $rows);
    }

    protected function enrollment_page(int $course, ?string $status, int $page, int $per_page): array
    {
        global $wpdb;
        $raw = null === $status ? array_keys(self::STATUS_MAP) : array_keys(self::STATUS_MAP, $status, true);
        if ([] === $raw) {
            return [ 'total' => 0, 'rows' => [] ];
        }
        // Tutor LMS registers none of its enrollment statuses, so WP_Query
        // cannot select them; this is the query Tutor LMS itself runs.
        $in    = implode(', ', array_fill(0, count($raw), '%s'));
        $where = $wpdb->prepare("post_type = %s AND post_parent = %d AND post_status IN ({$in})", self::ENROLLED, $course, ...$raw); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in is only %s placeholders, one per value.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where was prepared above; enrollments must be live.
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE {$where}");
        $posts = $wpdb->get_results($wpdb->prepare("SELECT post_author, post_status, post_date_gmt FROM {$wpdb->posts} WHERE {$where} ORDER BY post_date_gmt DESC, ID DESC LIMIT %d OFFSET %d", $per_page, ($page - 1) * $per_page), ARRAY_A);
        // phpcs:enable

        $rows = [];
        foreach ((array) $posts as $post) {
            $rows[] = [
                'user_id'     => (int) $post['post_author'],
                'status'      => self::STATUS_MAP[ $post['post_status'] ] ?? (string) $post['post_status'],
                'enrolled_at' => (string) $post['post_date_gmt'],
            ];
        }
        return [ 'total' => $total, 'rows' => $rows ];
    }

    protected function enrolled_count(int $course): ?int
    {
        return $this->enrollment_page($course, 'enrolled', 1, 1)['total'];
    }
}
