<?php

namespace WPMCP\Integrations;

use WPMCP\Tools\Content\Readable_Posts;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Sensei LMS storage for the LMS ops (issue #673), as Sensei LMS 4.27 keeps
 * it (checked against its source: Sensei_Course_Structure,
 * Meta_Based_Course_Lessons_Repository, Sensei_Core_Modules,
 * Sensei_Lesson_Quiz_Saver, Sensei_Course_Enrolment):
 *  - a course is a `course` post, a lesson a `lesson` post whose
 *    _lesson_course meta is the course;
 *  - modules are `module` TERMS on the course (not posts), ordered by the
 *    course's _module_order meta, modules missing from it last; a lesson in
 *    a module carries the term and its 1-based place in
 *    _order_module_{term};
 *  - a lesson outside every module of its course is ordered by its
 *    _order_{course} meta (unordered ones last, then by date), and the
 *    course keeps that list in _lesson_order (comma separated). Sensei lists
 *    these lessons after the modules;
 *  - a quiz is a `quiz` post whose post_parent and _quiz_lesson are its
 *    lesson, which names it in _lesson_quiz (one quiz per lesson);
 *  - a question is a `question` (or `multiple_question`) post whose _quiz_id
 *    meta is the quiz, ordered by _quiz_question_order{quiz}, its type the
 *    question-type term and its points _question_grade (default 1);
 *  - an enrollment is the student's sensei_learner term (slug user-{id}) on
 *    the course, the result Sensei stores after it works out access; the
 *    date is the student's sensei_course_status comment (course start), when
 *    there is one.
 *
 * In the shared tree the modules are the sections (kind "module", the term
 * id as id) and the lessons outside them are listed under `lessons`. Since a
 * module is a term, this adapter writes no module: add-section is left out,
 * add-lesson adds a lesson to the course outside the modules, and move-item
 * reorders a lesson within its own list (its module, or the lessons outside
 * modules). Every write changes posts and post meta only, each under its
 * post snapshot, and a new lesson rewrites the course's _lesson_order under
 * the course's snapshot in the same session.
 *
 * Sensei LMS maps edit_published_posts of its quiz type to do_not_allow (a
 * published quiz is edited through its lesson), so update-item on a
 * published quiz is refused by the same capability check as everywhere else.
 */
final class LMS_Sensei extends LMS_Adapter
{
    private const MODULE = 'module';

    private const LEARNER = 'sensei_learner';

    /** Order for a lesson with no stored place: after the ordered ones, as Sensei_Course::course_lessons() sorts. */
    private const UNORDERED = 100000;

    public function slug(): string
    {
        return 'sensei';
    }

    public function label(): string
    {
        return 'Sensei LMS';
    }

    public function active(): bool
    {
        return (bool) apply_filters('wpmcp_sensei_active', defined('SENSEI_LMS_VERSION'));
    }

    protected function section_kind(): string
    {
        return 'module';
    }

    protected function types(): array
    {
        return [
            'course' => 'course',
            'lesson' => 'lesson',
            'quiz'   => 'quiz',
        ];
    }

    protected function movable_kinds(): array
    {
        return [ 'lesson' ];
    }

    protected function parent_kind(string $kind): string
    {
        return 'quiz' === $kind ? 'lesson' : 'course';
    }

    /** @return array<string, array<string, mixed>> */
    public function operations(): array
    {
        $p   = $this->slug();
        $ops = parent::operations();
        unset($ops["{$p}-add-section"]);

        $ops["{$p}-get-course"]['description'] = 'One Sensei LMS course as a tree: modules in course order (kind module, id is the module term), each with its lessons in order, then the lessons outside modules under lessons; a lesson\'s quiz under it with questions (id, title, type, points) unless questions:false, and counts';
        $ops["{$p}-add-lesson"]['description'] = 'Add a lesson to the Sensei LMS course parent_id, outside its modules, at position (0-based among those lessons; default last); status defaults to publish. Undo: rollback-session';
        $ops["{$p}-move-item"]['description']  = 'Move a Sensei LMS lesson to position (0-based) within its own list: its module, or the course\'s lessons outside modules. parent_id, if given, must be its course. Siblings renumbered. Undo: rollback-session';
        $ops["{$p}-update-item"]['description'] = 'Edit title, content, excerpt or status of a Sensei LMS course, lesson or quiz (a published quiz is edited through its lesson in Sensei, so it is refused). Undo: rollback-operation';

        return $ops;
    }

    // ------------------------------------------------------------------
    // Links
    // ------------------------------------------------------------------

    protected function linked_course(\WP_Post $post, string $kind): int
    {
        if ('quiz' === $kind) {
            $lesson = (int) get_post_meta((int) $post->ID, '_quiz_lesson', true);
            $lesson = $lesson > 0 ? $lesson : (int) $post->post_parent;
            $post   = $lesson > 0 ? get_post($lesson) : null;
            if (! $post instanceof \WP_Post || 'lesson' !== $post->post_type || 'trash' === $post->post_status) {
                return 0;
            }
        }
        $course = (int) get_post_meta((int) $post->ID, '_lesson_course', true);
        return $this->is_course($course) ? $course : 0;
    }

    /** A lesson's list: its course (the lessons outside modules). */
    protected function section_of(int $item): int
    {
        return (int) get_post_meta($item, '_lesson_course', true);
    }

    /** The course's module term ids in Sensei's order (Sensei_Core_Modules::get_course_modules()). */
    protected function section_ids(int $course, bool $every_row = false): array
    {
        $terms = wp_get_object_terms($course, self::MODULE, [ 'fields' => 'ids', 'orderby' => 'name' ]);
        if (! is_array($terms) || [] === $terms) {
            return [];
        }
        $terms = array_map('intval', $terms);
        $order = array_map('intval', (array) get_post_meta($course, '_module_order', true));
        $first = array_values(array_intersect($order, $terms));
        return array_values(array_unique(array_merge($first, array_diff($terms, $first))));
    }

    /** The lessons of course $parent outside its modules, in Sensei's order. */
    protected function item_posts(int $section, bool $every_row = false): array
    {
        $modules = $this->section_ids($section);
        $args    = [];
        if ([] !== $modules) {
            $args['tax_query'] = [ [ 'taxonomy' => self::MODULE, 'field' => 'term_id', 'terms' => $modules, 'operator' => 'NOT IN' ] ]; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- the lessons outside the course's modules, the query Sensei runs (get_none_module_lessons()).
        }
        return $this->sorted($this->course_lessons($section, $args, $every_row), '_order_' . $section, true);
    }

    /** The lessons of $course in module $module, in Sensei's order. */
    private function module_lessons(int $course, int $module, bool $every_row = false): array
    {
        $args = [ 'tax_query' => [ [ 'taxonomy' => self::MODULE, 'field' => 'term_id', 'terms' => [ $module ] ] ] ]; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Sensei links a lesson to its module by the term.
        return $this->sorted($this->course_lessons($course, $args, $every_row), '_order_module_' . $module);
    }

    /** @return \WP_Post[] lessons whose _lesson_course is $course, matching $args. */
    private function course_lessons(int $course, array $args, bool $every_row): array
    {
        if ($course < 1) {
            return [];
        }
        $args += [
            'post_type'      => 'lesson',
            'meta_query'     => [ [ 'key' => '_lesson_course', 'value' => $course ] ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Sensei links a lesson to its course through post meta and queries it the same way.
            'orderby'        => [ 'date' => 'ASC', 'ID' => 'ASC' ],
            'posts_per_page' => -1,
            'no_found_rows'  => true,
        ];
        return $every_row ? get_posts([ 'post_status' => 'any' ] + $args) : Readable_Posts::get_posts($args);
    }

    /**
     * $posts by their $key meta, keeping the query order among equals. A
     * missing place sorts last; so does 0 when $zero_last, the way
     * Sensei_Course::course_lessons() sorts the lessons outside modules
     * (a module list and a quiz sort 0 first, by meta_value_num).
     *
     * @param \WP_Post[] $posts
     * @return \WP_Post[]
     */
    private function sorted(array $posts, string $key, bool $zero_last = false): array
    {
        $place = [];
        foreach (array_values($posts) as $i => $post) {
            $value              = get_post_meta((int) $post->ID, $key, true);
            $last               = '' === $value || ($zero_last && 0 === (int) $value);
            $place[ $post->ID ] = [ $last ? self::UNORDERED : (int) $value, $i ];
        }
        usort($posts, static fn (\WP_Post $a, \WP_Post $b): int => $place[ $a->ID ] <=> $place[ $b->ID ]);
        return $posts;
    }

    /** The course module $lesson sits in, or 0 when it is outside the modules. */
    private function module_of(int $lesson, int $course): int
    {
        $terms = wp_get_object_terms($lesson, self::MODULE, [ 'fields' => 'ids' ]);
        if (! is_array($terms)) {
            return 0;
        }
        $mine = array_values(array_intersect(array_map('intval', $terms), $this->section_ids($course)));
        return $mine[0] ?? 0;
    }

    /** The order meta key of a lesson's place in its list. */
    private function order_key(int $lesson): string
    {
        $course = (int) get_post_meta($lesson, '_lesson_course', true);
        $module = $this->module_of($lesson, $course);
        return $module > 0 ? '_order_module_' . $module : '_order_' . $course;
    }

    protected function stored_order(int $id): int
    {
        return (int) get_post_meta($id, $this->order_key($id), true);
    }

    protected function store_order(int $id, int $order): void
    {
        update_post_meta($id, $this->order_key($id), $order);
    }

    /** Never reached: a Sensei lesson moves only within its own list (move_plan()). */
    protected function store_section(int $item, int $section): void
    {
        throw new Operation_Refused('not_movable', 'A Sensei LMS lesson keeps its module.');
    }

    protected function link_args(string $kind, int $parent, int $course, int $order): array
    {
        if ('lesson' === $kind) {
            return [ 'meta_input' => [ '_lesson_course' => $course, '_order_' . $course => $order ] ];
        }
        return [ 'post_parent' => $parent, 'meta_input' => [ '_quiz_lesson' => $parent ] ];
    }

    // ------------------------------------------------------------------
    // Quizzes
    // ------------------------------------------------------------------

    /** The live quiz of $lesson, or null. */
    private function quiz_of(int $lesson): ?\WP_Post
    {
        $quiz = (int) get_post_meta($lesson, '_lesson_quiz', true);
        $post = $quiz > 0 ? get_post($quiz) : null;
        return $post instanceof \WP_Post && 'quiz' === $post->post_type && 'trash' !== $post->post_status ? $post : null;
    }

    protected function add_refusal(string $kind, int $parent): ?array
    {
        $quiz = 'quiz' === $kind ? $this->quiz_of($parent) : null;
        if (null === $quiz) {
            return null;
        }
        return self::refusal('quiz_exists', sprintf('Lesson %d already has quiz %d; a Sensei LMS lesson holds one quiz.', $parent, (int) $quiz->ID), [ 'quiz_id' => (int) $quiz->ID ]);
    }

    /** The lesson learns its new quiz, as Sensei_Lesson_Quiz_Saver does (with the quiz type it sets). */
    protected function parent_update(string $kind, int $parent, int $created): ?callable
    {
        if ('quiz' !== $kind) {
            return null;
        }
        return static function () use ($parent, $created): void {
            update_post_meta($parent, '_lesson_quiz', $created);
            if (taxonomy_exists('quiz-type')) {
                wp_set_object_terms($created, [ 'multiple-choice' ], 'quiz-type');
            }
        };
    }

    /** The course's _lesson_order rewritten from the lessons outside its modules, when it changed. */
    protected function course_update(int $course): ?callable
    {
        $order = implode(',', array_map(static fn (\WP_Post $p): int => (int) $p->ID, $this->item_posts($course, true)));
        if ((string) get_post_meta($course, '_lesson_order', true) === $order) {
            return null;
        }
        return static function () use ($course, $order): void {
            update_post_meta($course, '_lesson_order', $order);
        };
    }

    protected function lesson_extras(\WP_Post $lesson, bool $questions): array
    {
        $quiz = $this->quiz_of((int) $lesson->ID);
        if (null === $quiz || ! current_user_can('read_post', (int) $quiz->ID)) {
            return [];
        }
        $node = $this->node($quiz, 'quiz');
        if ($questions) {
            $node['questions'] = $this->questions((int) $quiz->ID);
        }
        return [ 'quiz' => $node ];
    }

    protected function questions(int $quiz): ?array
    {
        // Only the questions the caller may read (issue #465).
        $posts = Readable_Posts::get_posts([
            'post_type'      => [ 'question', 'multiple_question' ],
            'post_status'    => 'any',
            'meta_query'     => [ [ 'key' => '_quiz_id', 'value' => $quiz ] ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Sensei links questions to their quiz through post meta and queries it the same way.
            'orderby'        => [ 'title' => 'ASC', 'ID' => 'ASC' ],
            'posts_per_page' => -1,
            'no_found_rows'  => true,
        ]);
        $posts = $this->sorted($posts, '_quiz_question_order' . $quiz);

        return array_map(static function (\WP_Post $q) use ($quiz): array {
            $types = 'question' === $q->post_type ? wp_get_object_terms((int) $q->ID, 'question-type', [ 'fields' => 'slugs' ]) : [];
            $grade = get_post_meta((int) $q->ID, '_question_grade', true);
            return [
                'id'     => (int) $q->ID,
                'title'  => (string) $q->post_title,
                'type'   => 'question' === $q->post_type ? (is_array($types) && [] !== $types ? (string) $types[0] : '') : 'category',
                'points' => '' === $grade ? 1 : (int) $grade,
                'order'  => (int) get_post_meta((int) $q->ID, '_quiz_question_order' . $quiz, true),
            ];
        }, $posts);
    }

    // ------------------------------------------------------------------
    // Tree
    // ------------------------------------------------------------------

    /** The course tree: modules (terms) with their lessons, then the lessons outside modules. */
    protected function get_course(int $course, bool $questions): array
    {
        $post   = get_post($course);
        $counts = [ 'sections' => 0, 'lessons' => 0, 'quizzes' => 0, 'assignments' => 0, 'questions' => $questions ? 0 : null ];

        $sections = [];
        foreach ($this->section_ids($course) as $module) {
            $term = get_term($module, self::MODULE);
            if (! $term instanceof \WP_Term) {
                continue;
            }
            ++$counts['sections'];
            $sections[] = [
                'id'    => (int) $term->term_id,
                'kind'  => 'module',
                'title' => (string) $term->name,
                'items' => $this->lesson_nodes($this->module_lessons($course, $module), $questions, $counts),
            ];
        }

        return [
            'course'   => $this->node($post, 'course') + [ 'excerpt' => (string) $post->post_excerpt ],
            'sections' => $sections,
            'lessons'  => $this->lesson_nodes($this->item_posts($course), $questions, $counts),
            'counts'   => $counts,
        ];
    }

    /** @param \WP_Post[] $lessons */
    private function lesson_nodes(array $lessons, bool $questions, array &$counts): array
    {
        $nodes = [];
        foreach ($lessons as $lesson) {
            ++$counts['lessons'];
            $node = $this->node($lesson, 'lesson') + $this->lesson_extras($lesson, $questions);
            if (isset($node['quiz'])) {
                ++$counts['quizzes'];
                if ($questions && is_array($node['quiz']['questions'] ?? null)) {
                    $counts['questions'] += count($node['quiz']['questions']);
                }
            }
            $nodes[] = $node;
        }
        return $nodes;
    }

    /**
     * A Sensei lesson moves within its own list only: its module, or the
     * course's lessons outside modules. parent_id, when given, must be the
     * lesson's course.
     */
    protected function move_plan(array $args): array
    {
        $id   = (int) $args['id'];
        $kind = $this->kind_of($id);
        if (null === $kind || 'course' === $kind) {
            return [ 'refusal' => self::refusal('item_not_found', sprintf('%d is not a %s lesson.', $id, $this->label()), [ 'id' => $id ]) ];
        }
        if ('lesson' !== $kind) {
            return [ 'refusal' => self::refusal('not_movable', sprintf('A %s %s cannot be moved here; it belongs to its lesson.', $this->label(), $kind), [ 'id' => $id ]) ];
        }
        $course = $this->course_of($id);
        if (isset($args['parent_id']) && (int) $args['parent_id'] !== $course) {
            return [ 'refusal' => self::refusal('cross_course_move', sprintf('A %s lesson moves only within its own course (%d) and module.', $this->label(), $course), [ 'course_id' => $course ]) ];
        }

        $module   = $this->module_of($id, $course);
        $siblings = $module > 0 ? $this->module_lessons($course, $module, true) : $this->item_posts($course, true);
        $dest     = array_values(array_diff(array_map(static fn (\WP_Post $p): int => (int) $p->ID, $siblings), [ $id ]));
        $pos      = min((int) $args['position'], count($dest));
        array_splice($dest, $pos, 0, [ $id ]);

        $refusal = $this->edit_refusal(array_unique(array_merge([ $id, $course ], $this->reordered($dest, $id))));
        if (null !== $refusal) {
            return [ 'refusal' => $refusal ];
        }
        return [ 'kind' => 'lesson', 'id' => $id, 'from' => $course, 'to' => $course, 'dest' => $dest, 'source' => [] ];
    }

    // ------------------------------------------------------------------
    // Enrollments
    // ------------------------------------------------------------------

    protected function enrollment_page(int $course, ?string $status, int $page, int $per_page): array
    {
        global $wpdb;
        if (null !== $status && 'enrolled' !== $status) {
            return [ 'total' => 0, 'rows' => [] ];
        }
        // The course's learner terms, newest learner term first. Sensei's
        // taxonomy may not be registered outside its own requests, so this
        // reads the term tables directly, the rows wp_get_object_terms()
        // would return.
        $like = $wpdb->esc_like('user-') . '%';
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- core term tables; enrollments must be live.
        $total = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM %i tr INNER JOIN %i tt ON tt.term_taxonomy_id = tr.term_taxonomy_id INNER JOIN %i t ON t.term_id = tt.term_id WHERE tt.taxonomy = %s AND tr.object_id = %d AND t.slug LIKE %s',
            $wpdb->term_relationships,
            $wpdb->term_taxonomy,
            $wpdb->terms,
            self::LEARNER,
            $course,
            $like
        ));
        $slugs = $wpdb->get_col($wpdb->prepare(
            'SELECT t.slug FROM %i tr INNER JOIN %i tt ON tt.term_taxonomy_id = tr.term_taxonomy_id INNER JOIN %i t ON t.term_id = tt.term_id WHERE tt.taxonomy = %s AND tr.object_id = %d AND t.slug LIKE %s ORDER BY t.term_id DESC LIMIT %d OFFSET %d',
            $wpdb->term_relationships,
            $wpdb->term_taxonomy,
            $wpdb->terms,
            self::LEARNER,
            $course,
            $like,
            $per_page,
            ($page - 1) * $per_page
        ));
        // phpcs:enable

        $rows = [];
        foreach ((array) $slugs as $slug) {
            $user = (int) substr((string) $slug, 5);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Sensei's course progress comment, read live.
            $started = $wpdb->get_var($wpdb->prepare("SELECT comment_date_gmt FROM %i WHERE comment_post_ID = %d AND user_id = %d AND comment_type = 'sensei_course_status' ORDER BY comment_ID ASC LIMIT 1", $wpdb->comments, $course, $user));
            $rows[]  = [
                'user_id'     => $user,
                'status'      => 'enrolled',
                'enrolled_at' => null === $started ? null : (string) $started,
            ];
        }
        return [ 'total' => $total, 'rows' => $rows ];
    }

    protected function enrolled_count(int $course): ?int
    {
        return $this->enrollment_page($course, 'enrolled', 1, 1)['total'];
    }
}
