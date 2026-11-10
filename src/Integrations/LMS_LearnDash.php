<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * LearnDash storage for the LMS ops (issue #394), as LearnDash 4.x keeps it:
 *  - a course is a `sfwd-courses` post;
 *  - its lessons are `sfwd-lessons` posts whose course_id meta is the course
 *    (plus the ld_course_{course} marker LearnDash's shared course steps
 *    read), ordered by menu_order;
 *  - topics are `sfwd-topic` posts and quizzes `sfwd-quiz` posts whose
 *    course_id and lesson_id meta link them to the course and to the lesson
 *    (or, for a topic's quiz, to the topic), ordered by menu_order;
 *  - the course builder also keeps the whole tree on the course, in the
 *    ld_course_steps post meta (steps.h: lessons > topics > quizzes, plus
 *    course-level quizzes);
 *  - questions are `sfwd-question` posts whose quiz_id meta is the quiz;
 *  - an enrollment is the user meta course_{course}_access_from (a
 *    timestamp), progress is the `_sfwd-course_progress` user meta keyed by
 *    course, completion the course_completed_{course} user meta, and quiz
 *    attempts the `_sfwd-quizzes` user meta (each attempt names its course).
 *
 * In the shared tree a LearnDash lesson is the SECTION and its topics are the
 * items (kind "lesson"); lesson-level quizzes sit beside the topics and a
 * topic's quizzes hang under the topic as `quizzes`. Course-level quizzes
 * (no lesson) are not part of the tree, and stay in ld_course_steps as they
 * were.
 *
 * Everything is read and written as that storage, never through LearnDash's
 * write functions, so every write is covered by its snapshot and a rollback
 * is exact: a tree write changes posts and post meta (post snapshots, and
 * the course's own snapshot when its ld_course_steps is rewritten); a
 * learner write changes only the learner's user meta (a user snapshot).
 * LearnDash's own hooks for these changes do not fire, and its activity log
 * table is not written, so LearnDash's reports keep the history they had.
 *
 * Shared course steps (one lesson in several courses, the course builder's
 * shared_steps setting) are not supported: the tree follows the per-course
 * course_id links, and tree writes are refused while shared steps are on,
 * since rewriting ld_course_steps from those links would drop the shared
 * ones.
 *
 * Capabilities are LearnDash's own: its course types map their capabilities
 * onto the course ones (edit_courses, publish_courses, ...), so the shared
 * checks in LMS_Adapter ask for those. Beyond the shared catalog this adapter
 * adds learner ops: list a user's enrollments with progress (list_users,
 * personal data) and enroll, unenroll or reset a user's progress in one
 * course (edit_users plus edit_user on that user, through Object_Guard),
 * each under a user snapshot.
 */
final class LMS_LearnDash extends LMS_Adapter
{
    private const PROGRESS_META = '_sfwd-course_progress';

    private const QUIZZES_META = '_sfwd-quizzes';

    private const STEPS_META = 'ld_course_steps';

    public function slug(): string
    {
        return 'learndash';
    }

    public function label(): string
    {
        return 'LearnDash';
    }

    public function active(): bool
    {
        return (bool) apply_filters('wpmcp_learndash_active', defined('LEARNDASH_VERSION'));
    }

    protected function section_kind(): string
    {
        return 'lesson';
    }

    protected function types(): array
    {
        return [
            'course'  => 'sfwd-courses',
            'section' => 'sfwd-lessons',
            'lesson'  => 'sfwd-topic',
            'quiz'    => 'sfwd-quiz',
        ];
    }

    protected function movable_kinds(): array
    {
        return [ 'section', 'lesson', 'quiz' ];
    }

    protected function parent_kind(string $kind): string
    {
        return 'section';
    }

    protected function linked_course(\WP_Post $post, string $kind): int
    {
        $course = (int) get_post_meta($post->ID, 'course_id', true);
        if (! $this->is_course($course)) {
            return 0;
        }
        if ('section' === $kind) {
            return $course;
        }
        $lesson = (int) get_post_meta($post->ID, 'lesson_id', true);
        $type   = get_post_type($lesson);
        if ('sfwd-lessons' !== $type && ! ('quiz' === $kind && 'sfwd-topic' === $type)) {
            return 0;
        }
        return (int) get_post_meta($lesson, 'course_id', true) === $course ? $course : 0;
    }

    protected function section_of(int $item): int
    {
        $lesson = (int) get_post_meta($item, 'lesson_id', true);
        if ('sfwd-topic' === get_post_type($lesson)) {
            $lesson = (int) get_post_meta($lesson, 'lesson_id', true);
        }
        return $lesson;
    }

    protected function section_ids(int $course, bool $every_row = false): array
    {
        return array_map(static fn (\WP_Post $p): int => (int) $p->ID, $this->children_linked('sfwd-lessons', 'course_id', $course, $every_row));
    }

    protected function item_posts(int $section, bool $every_row = false): array
    {
        return $this->children_linked([ 'sfwd-topic', 'sfwd-quiz' ], 'lesson_id', $section, $every_row);
    }

    protected function lesson_extras(\WP_Post $lesson, bool $questions): array
    {
        $quizzes = [];
        foreach ($this->children_linked('sfwd-quiz', 'lesson_id', (int) $lesson->ID) as $quiz) {
            $node = $this->node($quiz, 'quiz');
            if ($questions) {
                $node['questions'] = $this->questions((int) $quiz->ID);
            }
            $quizzes[] = $node;
        }
        return [ 'quizzes' => $quizzes ];
    }

    protected function stored_order(int $id): int
    {
        return (int) get_post_field('menu_order', $id);
    }

    protected function store_order(int $id, int $order): void
    {
        global $wpdb;
        $wpdb->update($wpdb->posts, [ 'menu_order' => $order ], [ 'ID' => $id ]); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one column, as LearnDash's course builder writes it; the cache is cleaned below.
        clean_post_cache($id);
    }

    protected function store_section(int $item, int $section): void
    {
        update_post_meta($item, 'lesson_id', $section);
    }

    protected function link_args(string $kind, int $parent, int $course, int $order): array
    {
        $meta = [ 'course_id' => $course, "ld_course_{$course}" => $course ];
        if ('section' !== $kind) {
            $meta['lesson_id'] = $parent;
        }
        return [ 'menu_order' => $order, 'meta_input' => $meta ];
    }

    protected function add_refusal(string $kind, int $parent): ?array
    {
        return $this->shared_steps_refusal();
    }

    protected function move_refusal(array $args): ?array
    {
        return $this->shared_steps_refusal() ?? parent::move_refusal($args);
    }

    /**
     * Rewrite the course's ld_course_steps tree from the course_id and
     * lesson_id links after an add or a move, keeping its course-level
     * quizzes and every other key. A course without it is left alone:
     * LearnDash builds it from the same links when it first needs it.
     */
    protected function course_update(int $course): ?callable
    {
        $steps = get_post_meta($course, self::STEPS_META, true);
        if (! is_array($steps)) {
            return null;
        }
        return function () use ($course, $steps): void {
            $h     = (array) ($steps['steps']['h'] ?? []);
            $tree  = [];
            $count = 0;
            foreach ($this->section_ids($course, true) as $lesson) {
                $node = [ 'sfwd-topic' => [], 'sfwd-quiz' => [] ];
                ++$count;
                foreach ($this->item_posts($lesson, true) as $item) {
                    ++$count;
                    if ('sfwd-quiz' === $item->post_type) {
                        $node['sfwd-quiz'][ (int) $item->ID ] = [];
                        continue;
                    }
                    $quizzes = [];
                    foreach ($this->children_linked('sfwd-quiz', 'lesson_id', (int) $item->ID, true) as $quiz) {
                        $quizzes[ (int) $quiz->ID ] = [];
                        ++$count;
                    }
                    $node['sfwd-topic'][ (int) $item->ID ] = [ 'sfwd-quiz' => $quizzes ];
                }
                $tree[ $lesson ] = $node;
            }
            $finals = (array) ($h['sfwd-quiz'] ?? []);
            $count += count($finals);

            $steps['steps']['h'] = [ 'sfwd-lessons' => $tree, 'sfwd-quiz' => $finals ] + $h;
            if (array_key_exists('steps_count', $steps)) {
                $steps['steps_count'] = $count;
            }
            if (array_key_exists('empty', $steps)) {
                $steps['empty'] = 0 === $count;
            }
            update_post_meta($course, self::STEPS_META, $steps);
        };
    }

    /** Refusal for a tree write while the course builder shares steps between courses. */
    private function shared_steps_refusal(): ?array
    {
        $builder = get_option('learndash_settings_courses_builder');
        if (is_array($builder) && 'yes' === ($builder['shared_steps'] ?? '')) {
            return self::refusal('shared_steps_unsupported', 'LearnDash shares course steps between courses on this site (Course Builder, shared steps); course tree writes are refused so no course loses a shared step. Reads still work.');
        }
        return null;
    }

    protected function questions(int $quiz): ?array
    {
        $rows = [];
        foreach ($this->children_linked('sfwd-question', 'quiz_id', $quiz, true) as $q) {
            $points = get_post_meta($q->ID, 'question_points', true);
            $rows[] = [
                'id'     => (int) $q->ID,
                'title'  => (string) $q->post_title,
                'type'   => (string) get_post_meta($q->ID, 'question_type', true),
                'points' => '' === $points ? null : (float) $points,
                'order'  => (int) $q->menu_order,
            ];
        }
        return $rows;
    }

    protected function enrollment_page(int $course, ?string $status, int $page, int $per_page): array
    {
        global $wpdb;
        if (null !== $status && 'enrolled' !== $status) {
            return [ 'total' => 0, 'rows' => [] ];
        }
        $key = self::access_key($course);
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- LearnDash keeps enrollments as user meta; this is the query it runs itself, and enrollments must be live.
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s", $key));
        $rows  = $wpdb->get_results($wpdb->prepare("SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s ORDER BY CAST(meta_value AS UNSIGNED) DESC, umeta_id DESC LIMIT %d OFFSET %d", $key, $per_page, ($page - 1) * $per_page), ARRAY_A);
        // phpcs:enable

        return [
            'total' => $total,
            'rows'  => array_map(static fn (array $r): array => [
                'user_id'     => (int) $r['user_id'],
                'status'      => 'enrolled',
                'enrolled_at' => is_numeric($r['meta_value']) ? gmdate('Y-m-d H:i:s', (int) $r['meta_value']) : null,
            ], (array) $rows),
        ];
    }

    protected function enrolled_count(int $course): ?int
    {
        return $this->enrollment_page($course, 'enrolled', 1, 1)['total'];
    }

    // ------------------------------------------------------------------
    // Learner ops
    // ------------------------------------------------------------------

    public function operations(): array
    {
        $p        = $this->slug();
        $requires = fn () => Ops_Status_Packs::presence($this->active(), $p . '_inactive', $this->label());
        $id       = [ 'type' => 'integer', 'minimum' => 1 ];
        $pair     = [
            'type'       => 'object',
            'properties' => [ 'user_id' => $id, 'course_id' => $id ],
            'required'   => [ 'user_id', 'course_id' ],
        ];
        $base     = [ 'tier' => 'pro', 'requires' => $requires ];
        $write    = $base + [
            'mode'         => 'write',
            'capability'   => 'edit_users',
            'objects'      => [
                'user_id'   => [ 'type' => 'user' ],
                'course_id' => [ 'type' => 'post', 'own_type' => true, 'access' => 'read' ],
            ],
            'input_schema' => $pair,
            'validate'     => fn (array $args): ?array => $this->learner_refusal($args),
            'snapshot'     => static fn (array $args): array => [ 'object_type' => 'user', 'object_id' => (int) $args['user_id'] ],
        ];

        return parent::operations() + [
            "{$p}-list-user-courses" => $base + [
                'mode'         => 'read',
                'capability'   => 'list_users',
                'objects'      => [ 'user_id' => [ 'type' => 'user', 'access' => 'read' ] ],
                'description'  => "A user's direct LearnDash enrollments with progress (completed steps, total, percentage). Personal data: needs list_users",
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'user_id' => $id ], 'required' => [ 'user_id' ] ],
                'validate'     => fn (array $args): ?array => $this->user_refusal((int) $args['user_id']),
                'handler'      => fn (array $args): array => $this->list_user_courses((int) $args['user_id']),
            ],
            "{$p}-enroll-user"       => $write + [
                'description' => 'Enroll a user in a LearnDash course. Needs edit_users. Undo: rollback-operation',
                'handler'     => fn (array $args): array => $this->set_enrollment((int) $args['user_id'], (int) $args['course_id'], true),
            ],
            "{$p}-unenroll-user"     => $write + [
                'description' => 'Remove a user\'s direct enrollment in a LearnDash course. Needs edit_users. Undo: rollback-operation',
                'handler'     => fn (array $args): array => $this->set_enrollment((int) $args['user_id'], (int) $args['course_id'], false),
            ],
            "{$p}-reset-progress"    => $write + [
                'description' => "Clear a user's LearnDash progress, completion and quiz attempts in one course; the enrollment stays. Needs edit_users. Undo: rollback-operation",
                'handler'     => fn (array $args): array => $this->reset_progress((int) $args['user_id'], (int) $args['course_id']),
            ],
        ];
    }

    /** @return int[] the courses the user holds a direct enrollment in. */
    private function enrolled_courses(int $user): array
    {
        $ids = [];
        foreach (array_keys((array) get_user_meta($user)) as $key) {
            if (preg_match('/^course_(\d+)_access_from$/', (string) $key, $m) && $this->is_course((int) $m[1])) {
                $ids[] = (int) $m[1];
            }
        }
        sort($ids);
        return $ids;
    }

    /** @return array{completed: int, total: int, percentage: int} */
    private function progress(int $user, int $course): array
    {
        $all       = get_user_meta($user, self::PROGRESS_META, true);
        $row       = is_array($all) && is_array($all[ $course ] ?? null) ? $all[ $course ] : [];
        $completed = (int) ($row['completed'] ?? 0);
        $total     = (int) ($row['total'] ?? 0);
        return [ 'completed' => $completed, 'total' => $total, 'percentage' => $total > 0 ? (int) round($completed / $total * 100) : 0 ];
    }

    private function list_user_courses(int $user): array
    {
        $rows = [];
        foreach ($this->enrolled_courses($user) as $course) {
            $post = get_post($course);
            if (! $post instanceof \WP_Post || ! current_user_can('read_post', $course)) {
                continue;
            }
            $since  = get_user_meta($user, self::access_key($course), true);
            $done   = get_user_meta($user, "course_completed_{$course}", true);
            $rows[] = [
                'id'           => $course,
                'title'        => (string) $post->post_title,
                'status'       => (string) $post->post_status,
                'enrolled_at'  => is_numeric($since) ? gmdate('Y-m-d H:i:s', (int) $since) : null,
                'completed_at' => is_numeric($done) && (int) $done > 0 ? gmdate('Y-m-d H:i:s', (int) $done) : null,
                'progress'     => $this->progress($user, $course),
            ];
        }
        return [ 'user_id' => $user, 'courses' => $rows, 'total' => count($rows) ];
    }

    private function set_enrollment(int $user, int $course, bool $enroll): array
    {
        $key = self::access_key($course);
        $was = metadata_exists('user', $user, $key);
        if ($was !== $enroll) {
            if ($enroll) {
                update_user_meta($user, $key, time());
            } else {
                delete_user_meta($user, $key);
            }
        }
        return [ 'user_id' => $user, 'course_id' => $course, 'enrolled' => $enroll, 'changed' => $was !== $enroll ];
    }

    private function reset_progress(int $user, int $course): array
    {
        $changed = false;

        $all = get_user_meta($user, self::PROGRESS_META, true);
        if (is_array($all) && array_key_exists($course, $all)) {
            unset($all[ $course ]);
            update_user_meta($user, self::PROGRESS_META, $all);
            $changed = true;
        }

        if (metadata_exists('user', $user, "course_completed_{$course}")) {
            delete_user_meta($user, "course_completed_{$course}");
            $changed = true;
        }

        $attempts = get_user_meta($user, self::QUIZZES_META, true);
        if (is_array($attempts)) {
            $kept = array_values(array_filter($attempts, static fn ($a): bool => ! is_array($a) || (int) ($a['course'] ?? 0) !== $course));
            if (count($kept) !== count($attempts)) {
                update_user_meta($user, self::QUIZZES_META, $kept);
                $changed = true;
            }
        }

        return [ 'user_id' => $user, 'course_id' => $course, 'reset' => true, 'changed' => $changed ];
    }

    private function user_refusal(int $user): ?array
    {
        if (! get_userdata($user) instanceof \WP_User) {
            return self::refusal('user_not_found', sprintf('User %d does not exist.', $user), [ 'user_id' => $user ]);
        }
        return null;
    }

    private function learner_refusal(array $args): ?array
    {
        return $this->user_refusal((int) $args['user_id']) ?? $this->course_refusal((int) $args['course_id'], false);
    }

    private static function access_key(int $course): string
    {
        return "course_{$course}_access_from";
    }

    /**
     * Posts of $types whose $link meta is $parent, ordered by menu_order; a
     * read keeps to the rows the caller may read (issue #465).
     *
     * @param string|string[] $types
     * @return \WP_Post[]
     */
    private function children_linked($types, string $link, int $parent, bool $every_row = false): array
    {
        if ($parent < 1) {
            return [];
        }
        $args = [
            'post_type'      => $types,
            'meta_query'     => [ [ 'key' => $link, 'value' => $parent ] ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- LearnDash links its tree through post meta and queries it the same way.
            'orderby'        => [ 'menu_order' => 'ASC', 'ID' => 'ASC' ],
            'posts_per_page' => -1,
            'no_found_rows'  => true,
        ];
        if (! $every_row) {
            return \WPMCP\Tools\Content\Readable_Posts::get_posts($args);
        }
        return get_posts([ 'post_status' => 'any' ] + $args);
    }
}
