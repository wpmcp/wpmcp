<?php

namespace WPMCP\Integrations;

use WPMCP\Safety\Plugin_Table_Rows_Snapshot;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * LifterLMS storage for the LMS ops (issue #394), as LifterLMS 10.2.1 keeps
 * it (checked against its source; every model property is post meta under
 * the _llms_ prefix, LLMS_Post_Model):
 *  - a course is a `course` post;
 *  - a section is a `section` post with _llms_parent_course and a 1-based
 *    _llms_order (LLMS_Course::get_sections() orders by it);
 *  - a lesson is a `lesson` post with _llms_parent_section,
 *    _llms_parent_course and _llms_order (LLMS_Section::get_lessons());
 *  - a quiz is an `llms_quiz` post with _llms_lesson_id, and its lesson
 *    carries _llms_quiz and _llms_quiz_enabled (yes/no);
 *  - a question is an `llms_question` post with _llms_parent_id (the quiz),
 *    _llms_question_type and _llms_points, ordered by menu_order
 *    (LLMS_Question_Manager);
 *  - enrollment is {prefix}lifterlms_user_postmeta: the latest _status row
 *    per student and course (by updated_date, then meta_id, as
 *    LLMS_Student::get_enrollment_status() reads it) is the status, and the
 *    _start_date row's updated_date is when the student enrolled.
 *
 * LifterLMS 10.1+ also registers abilities of its own (lifterlms/*:
 * memberships, access plans, certificates, quiz attempts and grading,
 * enrollment writes, student progress). Those are not duplicated here: with
 * the ability bridge opened, execute-site-ability runs them under their own
 * permission checks. They are outside the snapshot guarantee, which is why
 * the course-tree writes a site most often undoes live here instead.
 */
final class LMS_LifterLMS extends LMS_Adapter
{
    private const USER_POSTMETA = 'lifterlms_user_postmeta';

    public function slug(): string
    {
        return 'lifterlms';
    }

    public function label(): string
    {
        return 'LifterLMS';
    }

    public function active(): bool
    {
        return (bool) apply_filters('wpmcp_lifterlms_active', defined('LLMS_PLUGIN_FILE'));
    }

    protected function section_kind(): string
    {
        return 'section';
    }

    protected function types(): array
    {
        return [
            'course'  => 'course',
            'section' => 'section',
            'lesson'  => 'lesson',
            'quiz'    => 'llms_quiz',
        ];
    }

    protected function parent_kind(string $kind): string
    {
        return 'quiz' === $kind ? 'lesson' : 'section';
    }

    protected function linked_course(\WP_Post $post, string $kind): int
    {
        if ('quiz' === $kind) {
            $lesson = (int) get_post_meta((int) $post->ID, '_llms_lesson_id', true);
            $post   = $lesson > 0 ? get_post($lesson) : null;
            if (! $post instanceof \WP_Post || 'lesson' !== $post->post_type) {
                return 0;
            }
        }
        $course = (int) get_post_meta((int) $post->ID, '_llms_parent_course', true);
        return $this->is_course($course) ? $course : 0;
    }

    protected function section_of(int $item): int
    {
        return (int) get_post_meta($item, '_llms_parent_section', true);
    }

    protected function section_ids(int $course): array
    {
        return array_map(static fn (\WP_Post $p): int => (int) $p->ID, self::children_by_meta('section', '_llms_parent_course', $course, '_llms_order'));
    }

    protected function item_posts(int $section): array
    {
        return self::children_by_meta('lesson', '_llms_parent_section', $section, '_llms_order');
    }

    protected function stored_order(int $id): int
    {
        return (int) get_post_meta($id, '_llms_order', true);
    }

    protected function store_order(int $id, int $order): void
    {
        update_post_meta($id, '_llms_order', $order);
    }

    protected function store_section(int $item, int $section): void
    {
        update_post_meta($item, '_llms_parent_section', $section);
    }

    protected function link_args(string $kind, int $parent, int $course, int $order): array
    {
        if ('section' === $kind) {
            return [ 'meta_input' => [ '_llms_parent_course' => $course, '_llms_order' => $order ] ];
        }
        if ('lesson' === $kind) {
            return [ 'meta_input' => [ '_llms_parent_course' => $course, '_llms_parent_section' => $parent, '_llms_order' => $order ] ];
        }
        return [ 'meta_input' => [ '_llms_lesson_id' => $parent ] ];
    }

    protected function add_refusal(string $kind, int $parent): ?array
    {
        if ('quiz' !== $kind) {
            return null;
        }
        $quiz = (int) get_post_meta($parent, '_llms_quiz', true);
        if ($quiz > 0 && 'llms_quiz' === get_post_type($quiz) && 'trash' !== get_post_status($quiz)) {
            return self::refusal('quiz_exists', sprintf('Lesson %d already has quiz %d; a LifterLMS lesson holds one quiz.', $parent, $quiz), [ 'quiz_id' => $quiz ]);
        }
        return null;
    }

    protected function parent_update(string $kind, int $parent, int $created): ?callable
    {
        if ('quiz' !== $kind) {
            return null;
        }
        return static function () use ($parent, $created): void {
            update_post_meta($parent, '_llms_quiz', $created);
            update_post_meta($parent, '_llms_quiz_enabled', 'yes');
        };
    }

    protected function lesson_extras(\WP_Post $lesson, bool $questions): array
    {
        $quiz = (int) get_post_meta((int) $lesson->ID, '_llms_quiz', true);
        $post = $quiz > 0 ? get_post($quiz) : null;
        if (! $post instanceof \WP_Post || 'llms_quiz' !== $post->post_type || 'trash' === $post->post_status) {
            return [];
        }
        $node = $this->node($post, 'quiz') + [ 'enabled' => 'yes' === get_post_meta((int) $lesson->ID, '_llms_quiz_enabled', true) ];
        if ($questions) {
            $node['questions'] = $this->questions($quiz);
        }
        return [ 'quiz' => $node ];
    }

    protected function questions(int $quiz): ?array
    {
        $posts = get_posts([
            'post_type'        => 'llms_question',
            'post_status'      => 'any',
            'meta_query'       => [ [ 'key' => '_llms_parent_id', 'value' => $quiz ] ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- LifterLMS links questions to their quiz through post meta and queries it the same way.
            'orderby'          => [ 'menu_order' => 'ASC', 'ID' => 'ASC' ],
            'posts_per_page'   => -1,
            'no_found_rows'    => true,
        ]);

        return array_map(static fn (\WP_Post $q): array => [
            'id'     => (int) $q->ID,
            'title'  => (string) $q->post_title,
            'type'   => (string) get_post_meta((int) $q->ID, '_llms_question_type', true),
            'points' => (int) get_post_meta((int) $q->ID, '_llms_points', true),
            'order'  => (int) $q->menu_order,
        ], $posts);
    }

    /**
     * Every student's current status in a course, latest first:
     * user id => [status, status date].
     *
     * @return array<int, array{0: string, 1: string}>|null null when the table is missing
     */
    private function statuses(int $course): ?array
    {
        global $wpdb;
        $table = $wpdb->prefix . self::USER_POSTMETA;
        if (! Plugin_Table_Rows_Snapshot::table_exists($table)) {
            return null;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- LifterLMS's own user postmeta table; enrollment status must be live.
        $rows = $wpdb->get_results($wpdb->prepare("SELECT user_id, meta_value, updated_date FROM %i WHERE post_id = %d AND meta_key = '_status' ORDER BY updated_date DESC, meta_id DESC", $table, $course), ARRAY_A);

        $latest = [];
        foreach ((array) $rows as $row) {
            $user = (int) $row['user_id'];
            if (! isset($latest[ $user ])) {
                $latest[ $user ] = [ (string) $row['meta_value'], (string) $row['updated_date'] ];
            }
        }
        return $latest;
    }

    protected function enrollment_page(int $course, ?string $status, int $page, int $per_page): array
    {
        global $wpdb;
        $latest = $this->statuses($course);
        if (null === $latest) {
            throw new Operation_Error('lifterlms_table_missing', 'The LifterLMS enrollment table is missing on this site.');
        }
        if (null !== $status) {
            $latest = array_filter($latest, static fn (array $s): bool => $s[0] === $status);
        }
        $total = count($latest);
        $slice = array_slice($latest, ($page - 1) * $per_page, $per_page, true);
        if ([] === $slice) {
            return [ 'total' => $total, 'rows' => [] ];
        }

        $users = array_keys($slice);
        $in    = implode(', ', array_fill(0, count($users), '%d'));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in is only %d placeholders, one per user id; LifterLMS's own table.
        $starts = $wpdb->get_results($wpdb->prepare("SELECT user_id, MAX(updated_date) AS started FROM %i WHERE post_id = %d AND meta_key = '_start_date' AND user_id IN ({$in}) GROUP BY user_id", $wpdb->prefix . self::USER_POSTMETA, $course, ...$users), ARRAY_A);
        $starts = array_column((array) $starts, 'started', 'user_id');

        $rows = [];
        foreach ($slice as $user => [$state, $date]) {
            $rows[] = [
                'user_id'     => (int) $user,
                'status'      => $state,
                'enrolled_at' => isset($starts[ $user ]) ? (string) $starts[ $user ] : $date,
            ];
        }
        return [ 'total' => $total, 'rows' => $rows ];
    }

    protected function enrolled_count(int $course): ?int
    {
        $latest = $this->statuses($course);
        return null === $latest ? null : count(array_filter($latest, static fn (array $s): bool => 'enrolled' === $s[0]));
    }
}
