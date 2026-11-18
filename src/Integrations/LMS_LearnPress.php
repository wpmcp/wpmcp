<?php

namespace WPMCP\Integrations;

use WPMCP\Safety\Plugin_Table_Rows_Snapshot;
use WPMCP\Safety\Post_Creation_Snapshot;
use WPMCP\Safety\Safe_Mutation;
use WPMCP\Tools\Content\Readable_Posts;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * LearnPress storage for the LMS ops (issue #672), as LearnPress 4.4.15 keeps
 * it (checked against its source, config/table/tables-v4.php and
 * inc/Models):
 *  - a course is an `lp_course` post;
 *  - its sections are NOT posts but rows of {prefix}learnpress_sections
 *    (section_id, section_name, section_course_id, a 1-based section_order,
 *    section_description), CourseSectionModel;
 *  - lessons and quizzes are `lp_lesson` and `lp_quiz` posts placed in a
 *    section by rows of {prefix}learnpress_section_items (section_id,
 *    item_id, a 1-based item_order, item_type), CourseSectionItemModel;
 *  - quiz questions are `lp_question` posts linked to their quiz by rows of
 *    {prefix}learnpress_quiz_questions ordered by question_order, with the
 *    type in _lp_type and the points in _lp_mark (default 1);
 *  - an enrollment is the latest lp_course row per student in
 *    {prefix}learnpress_user_items: status enrolled or finished (enrolled),
 *    purchased (pending) or cancel (cancelled), from start_time.
 *
 * Because a section is a row, its id lives in another number space than the
 * posts: the ops take it as parent_id (add-lesson, add-quiz, move-item) or
 * section_id (update-item and move-item of a section), never as id. A write
 * to the curriculum tables is a 'plugin_table_rows' snapshot keyed by the
 * course (its sections) or by the section (its items), so the undo also
 * removes the rows the write inserted; created posts are recorded for
 * rollback as in every LMS. LearnPress caches each course's curriculum (its
 * learnpress_courses row and object cache); every write, and every restore,
 * rebuilds it through LearnPress's own course model.
 *
 * LearnPress also registers abilities of its own (learnpress/*, including
 * deletes and enrollment writes). Those are not duplicated: with the ability
 * bridge opened, execute-site-ability runs them under their own checks,
 * outside the snapshot guarantee.
 */
final class LMS_LearnPress extends LMS_Adapter
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.
    /** LearnPress user item status => normalized status. */
    private const STATUS_MAP = [ 'enrolled' => 'enrolled', 'finished' => 'enrolled', 'purchased' => 'pending', 'cancel' => 'cancelled' ];

    public function slug(): string
    {
        return 'learnpress';
    }

    public function label(): string
    {
        return 'LearnPress';
    }

    public function active(): bool
    {
        return (bool) apply_filters('wpmcp_learnpress_active', defined('LP_PLUGIN_FILE') || defined('LEARNPRESS_VERSION'));
    }

    protected function section_kind(): string
    {
        return 'section';
    }

    protected function types(): array
    {
        return [
            'course' => 'lp_course',
            'lesson' => 'lp_lesson',
            'quiz'   => 'lp_quiz',
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

    // ------------------------------------------------------------------
    // Storage
    // ------------------------------------------------------------------

    private static function sections_table(): string
    {
        return (string) Plugin_Table_Rows_Snapshot::table('learnpress_sections', 'sections');
    }

    private static function items_table(): string
    {
        return (string) Plugin_Table_Rows_Snapshot::table('learnpress_section_items', 'section_items');
    }

    private static function tables_ready(): bool
    {
        return Plugin_Table_Rows_Snapshot::table_exists(self::sections_table()) && Plugin_Table_Rows_Snapshot::table_exists(self::items_table());
    }

    /** @return array{section_id: int, section_name: string, section_course_id: int, section_order: int, section_description: string}|null */
    private function section_row(int $section): ?array
    {
        global $wpdb;
        if ($section < 1 || ! self::tables_ready()) {
            return null;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- LearnPress's own section table; the curriculum must be live.
        $row = $wpdb->get_row($wpdb->prepare('SELECT section_id, section_name, section_course_id, section_order, section_description FROM %i WHERE section_id = %d', self::sections_table(), $section), ARRAY_A);
        if (! is_array($row)) {
            return null;
        }
        return [
            'section_id'          => (int) $row['section_id'],
            'section_name'        => (string) $row['section_name'],
            'section_course_id'   => (int) $row['section_course_id'],
            'section_order'       => (int) $row['section_order'],
            'section_description' => (string) $row['section_description'],
        ];
    }

    /** A section row of a live course of this plugin, or null. */
    private function live_section(int $section): ?array
    {
        $row = $this->section_row($section);
        return null !== $row && $this->is_course($row['section_course_id']) ? $row : null;
    }

    /** @return array<int, array<string, mixed>> the course's section rows in LearnPress's order. */
    private function section_rows(int $course): array
    {
        global $wpdb;
        if ($course < 1 || ! self::tables_ready()) {
            return [];
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- LearnPress's own section table, read in the order CourseSectionModel::find_by_course() uses.
        $rows = $wpdb->get_results($wpdb->prepare('SELECT section_id FROM %i WHERE section_course_id = %d ORDER BY section_order ASC, section_id ASC', self::sections_table(), $course), ARRAY_A);
        return array_values(array_filter(array_map(fn (array $r): ?array => $this->section_row((int) $r['section_id']), (array) $rows)));
    }

    /** @return int[] the item ids of a section in LearnPress's order, every row. */
    private function item_ids(int $section): array
    {
        global $wpdb;
        if ($section < 1 || ! self::tables_ready()) {
            return [];
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- LearnPress's own section items table, in its item_order.
        $ids = $wpdb->get_col($wpdb->prepare('SELECT item_id FROM %i WHERE section_id = %d ORDER BY item_order ASC, section_item_id ASC', self::items_table(), $section));
        return array_values(array_unique(array_map('intval', (array) $ids)));
    }

    /** @return array{section_id: int, item_order: int, course: int}|null the section placing an item. */
    private function item_row(int $item): ?array
    {
        global $wpdb;
        if ($item < 1 || ! self::tables_ready()) {
            return null;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- LearnPress's own curriculum tables; an item belongs to one course.
        $row = $wpdb->get_row($wpdb->prepare('SELECT si.section_id, si.item_order, s.section_course_id FROM %i si INNER JOIN %i s ON s.section_id = si.section_id WHERE si.item_id = %d ORDER BY si.section_item_id ASC LIMIT 1', self::items_table(), self::sections_table(), $item), ARRAY_A);
        if (! is_array($row)) {
            return null;
        }
        return [ 'section_id' => (int) $row['section_id'], 'item_order' => (int) $row['item_order'], 'course' => (int) $row['section_course_id'] ];
    }

    protected function linked_course(\WP_Post $post, string $kind): int
    {
        $row = $this->item_row((int) $post->ID);
        return null !== $row && $this->is_course($row['course']) ? $row['course'] : 0;
    }

    protected function section_of(int $item): int
    {
        $row = $this->item_row($item);
        return null === $row ? 0 : $row['section_id'];
    }

    protected function section_ids(int $course, bool $every_row = false): array
    {
        return array_map(static fn (array $r): int => (int) $r['section_id'], $this->section_rows($course));
    }

    protected function item_posts(int $section, bool $every_row = false): array
    {
        $ids = $this->item_ids($section);
        if ([] === $ids) {
            return [];
        }
        $args = [
            'post_type'      => [ 'lp_lesson', 'lp_quiz' ],
            'post__in'       => $ids,
            'orderby'        => 'post__in',
            'posts_per_page' => -1,
            'no_found_rows'  => true,
        ];
        // Only the items the caller may read (issue #465). The write path here
        // never lists posts: it renumbers every row of the section items table.
        return Readable_Posts::get_posts($args);
    }

    protected function stored_order(int $id): int
    {
        $row = $this->item_row($id);
        return null === $row ? 0 : $row['item_order'];
    }

    protected function store_order(int $id, int $order): void
    {
        $row = $this->item_row($id);
        if (null !== $row) {
            $this->set_item_order($row['section_id'], $id, $order);
        }
    }

    protected function store_section(int $item, int $section): void
    {
        global $wpdb;
        $wpdb->update(self::items_table(), [ 'section_id' => $section ], [ 'item_id' => $item ]); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- LearnPress's own section items table, as CourseSectionItemModel::save() writes it.
    }

    protected function link_args(string $kind, int $parent, int $course, int $order): array
    {
        return [];
    }

    private function set_item_order(int $section, int $item, int $order): void
    {
        global $wpdb;
        $wpdb->update(self::items_table(), [ 'item_order' => $order ], [ 'section_id' => $section, 'item_id' => $item ]); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- LearnPress's own section items table, as CourseSectionItemModel::save() writes it.
    }

    /** Write item_order 1..n for $items in $section where it differs. */
    private function renumber_items(int $section, array $items): void
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- LearnPress's own section items table.
        $orders = $wpdb->get_results($wpdb->prepare('SELECT item_id, item_order FROM %i WHERE section_id = %d', self::items_table(), $section), ARRAY_A);
        $orders = array_map('intval', array_column((array) $orders, 'item_order', 'item_id'));
        foreach (array_values($items) as $i => $item) {
            if (($orders[ $item ] ?? 0) !== $i + 1) {
                $this->set_item_order($section, (int) $item, $i + 1);
            }
        }
    }

    /** Write section_order 1..n for $sections where it differs. */
    private function renumber_sections(array $sections): void
    {
        global $wpdb;
        foreach (array_values($sections) as $i => $section) {
            $row = $this->section_row((int) $section);
            if (null !== $row && $row['section_order'] !== $i + 1) {
                $wpdb->update(self::sections_table(), [ 'section_order' => $i + 1 ], [ 'section_id' => (int) $section ]); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- LearnPress's own section table, as CourseSectionModel::save() writes it.
            }
        }
    }

    /** Whether writing $list as 1..n would change any stored section order. */
    private function sections_out_of_order(array $list): bool
    {
        foreach (array_values($list) as $i => $section) {
            $row = $this->section_row((int) $section);
            if (null !== $row && $row['section_order'] !== $i + 1) {
                return true;
            }
        }
        return false;
    }

    /** Whether writing $list as 1..n in $section would change any stored item order. */
    private function items_out_of_order(int $section, array $list): bool
    {
        foreach (array_values($list) as $i => $item) {
            $row = $this->item_row((int) $item);
            if (null === $row || $row['section_id'] !== $section || $row['item_order'] !== $i + 1) {
                return true;
            }
        }
        return false;
    }

    private function section_node(array $row): array
    {
        return [
            'id'          => (int) $row['section_id'],
            'kind'        => 'section',
            'title'       => (string) $row['section_name'],
            'description' => (string) $row['section_description'],
            'order'       => (int) $row['section_order'],
        ];
    }

    // ------------------------------------------------------------------
    // Reads
    // ------------------------------------------------------------------

    protected function get_course(int $course, bool $questions): array
    {
        $post   = get_post($course);
        $counts = [ 'sections' => 0, 'lessons' => 0, 'quizzes' => 0, 'assignments' => 0, 'questions' => $questions ? 0 : null ];
        $kinds  = array_flip($this->types());

        $sections = [];
        foreach ($this->section_rows($course) as $row) {
            ++$counts['sections'];
            $items = [];
            foreach ($this->item_posts((int) $row['section_id']) as $item) {
                $kind = $kinds[ $item->post_type ] ?? null;
                if (null === $kind) {
                    continue;
                }
                $node = $this->node($item, $kind);
                if ('quiz' === $kind) {
                    ++$counts['quizzes'];
                    if ($questions) {
                        $list = $this->questions((int) $item->ID);
                        if (null === $list) {
                            $node += [ 'questions' => null, 'questions_unavailable' => 'the plugin\'s question storage is missing' ];
                        } else {
                            $node['questions']    = $list;
                            $counts['questions'] += count($list);
                        }
                    }
                } else {
                    ++$counts['lessons'];
                }
                $items[] = $node;
            }
            $sections[] = $this->section_node($row) + [ 'items' => $items ];
        }

        return [
            'course'   => $this->node($post, 'course') + [ 'excerpt' => (string) $post->post_excerpt ],
            'sections' => $sections,
            'counts'   => $counts,
        ];
    }

    protected function questions(int $quiz): ?array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'learnpress_quiz_questions';
        if (! Plugin_Table_Rows_Snapshot::table_exists($table)) {
            return null;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- LearnPress's own quiz questions table, in its question_order.
        $rows   = $wpdb->get_results($wpdb->prepare('SELECT question_id, question_order FROM %i WHERE quiz_id = %d ORDER BY question_order ASC, quiz_question_id ASC', $table, $quiz), ARRAY_A);
        $orders = array_map('intval', array_column((array) $rows, 'question_order', 'question_id'));
        if ([] === $orders) {
            return [];
        }
        // Only the questions the caller may read (issue #465).
        $posts = Readable_Posts::get_posts([
            'post_type'      => 'lp_question',
            'post__in'       => array_keys($orders),
            'orderby'        => 'post__in',
            'posts_per_page' => -1,
            'no_found_rows'  => true,
        ]);

        return array_map(static function (\WP_Post $q) use ($orders): array {
            $mark = get_post_meta((int) $q->ID, '_lp_mark', true);
            return [
                'id'     => (int) $q->ID,
                'title'  => (string) $q->post_title,
                'type'   => (string) get_post_meta((int) $q->ID, '_lp_type', true),
                'points' => '' === $mark ? 1.0 : (float) $mark,
                'order'  => $orders[ (int) $q->ID ] ?? 0,
            ];
        }, $posts);
    }

    protected function enrollment_page(int $course, ?string $status, int $page, int $per_page): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'learnpress_user_items';
        if (! Plugin_Table_Rows_Snapshot::table_exists($table)) {
            throw new Operation_Error('learnpress_table_missing', 'The LearnPress user items table is missing on this site.');
        }
        $raw = null === $status ? array_keys(self::STATUS_MAP) : array_keys(self::STATUS_MAP, $status, true);
        if ([] === $raw) {
            return [ 'total' => 0, 'rows' => [] ];
        }
        // A student's enrollment is their latest lp_course row (a retake adds one).
        $in   = implode(', ', array_fill(0, count($raw), '%s'));
        $from = $wpdb->prepare("FROM %i ui INNER JOIN (SELECT user_id, MAX(user_item_id) AS latest FROM %i WHERE item_id = %d AND item_type = 'lp_course' GROUP BY user_id) l ON ui.user_item_id = l.latest WHERE ui.status IN ({$in})", $table, $table, $course, ...$raw); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in is only %s placeholders, one per status.
        $total = (int) $wpdb->get_var("SELECT COUNT(*) {$from}"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $from was prepared above; enrollments must be live.
        $rows  = $wpdb->get_results($wpdb->prepare("SELECT ui.user_id, ui.status, ui.start_time {$from} ORDER BY ui.start_time DESC, ui.user_item_id DESC LIMIT %d OFFSET %d", $per_page, ($page - 1) * $per_page), ARRAY_A); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $from was prepared above; enrollments must be live.

        $out = [];
        foreach ((array) $rows as $row) {
            $out[] = [
                'user_id'     => (int) $row['user_id'],
                'status'      => self::STATUS_MAP[ $row['status'] ] ?? (string) $row['status'],
                'enrolled_at' => null === $row['start_time'] ? null : (string) $row['start_time'],
            ];
        }
        return [ 'total' => $total, 'rows' => $out ];
    }

    protected function enrolled_count(int $course): ?int
    {
        try {
            return $this->enrollment_page($course, 'enrolled', 1, 1)['total'];
        } catch (Operation_Error $e) {
            return null;
        }
    }

    // ------------------------------------------------------------------
    // Catalog: sections are rows, so the ops that name one take its id as
    // parent_id or section_id and check edit_post on its course.
    // ------------------------------------------------------------------

    public function operations(): array
    {
        $p   = $this->slug();
        $ops = parent::operations();
        $id  = [ 'type' => 'integer', 'minimum' => 1 ];

        $ops["{$p}-add-section"]['description']  = 'Add a section (a LearnPress curriculum row; content is its description) to course_id at position (0-based; default last), renumbering the ones after it. Undo: rollback-session';
        $ops["{$p}-add-section"]['validate']     = fn (array $args): ?array => $this->tables_refusal() ?? $this->course_refusal((int) $args['course_id'], true);
        $ops["{$p}-add-section"]['handler']      = fn (array $args, array $context): array => $this->add_section($args, $context);

        foreach ([ 'lesson', 'quiz' ] as $kind) {
            $op = "{$p}-add-{$kind}";
            unset($ops[ $op ]['objects']);
            $ops[ $op ]['description'] = "Add a {$kind} to the section parent_id (a section id from get-course) at position (0-based; default last); status defaults to publish. Undo: rollback-session";
            $ops[ $op ]['validate']    = fn (array $args): ?array => $this->item_add_refusal($kind, (int) $args['parent_id'], (string) ($args['status'] ?? 'publish'));
            $ops[ $op ]['handler']     = fn (array $args, array $context): array => $this->add_item($kind, $args, $context);
        }

        $update                                   = &$ops["{$p}-update-item"];
        $update['description']                    = 'Edit title, content, excerpt or status of a LearnPress course, lesson or quiz (id), or the title and content (description) of a section (section_id). Undo: rollback-operation';
        $update['input_schema']['properties']    += [ 'section_id' => $id ];
        $update['input_schema']['required']       = [];
        $update['validate']                       = fn (array $args): ?array => $this->lp_update_refusal($args);
        $update['snapshot']                       = fn (array $args): array => isset($args['section_id'])
            ? [ 'object_type' => Plugin_Table_Rows_Snapshot::TYPE, 'object_id' => Plugin_Table_Rows_Snapshot::key('learnpress_sections', 'sections', [ (int) ($this->section_row((int) $args['section_id'])['section_course_id'] ?? 0) ]) ]
            : [ 'object_type' => 'post', 'object_id' => (int) $args['id'] ];
        $update['handler']                        = fn (array $args): array => isset($args['section_id']) ? $this->update_section($args) : $this->update_item($args);
        unset($update);

        $move                                = &$ops["{$p}-move-item"];
        $move['description']                 = 'Move a section (section_id) to position in its course, or a lesson or quiz (id) to position in the section parent_id (same course; default its own). 0-based; siblings renumbered. Undo: rollback-session';
        $move['objects']                     = [ 'id' => [ 'type' => 'post', 'own_type' => true ] ];
        $move['input_schema']['properties'] += [ 'section_id' => $id ];
        $move['input_schema']['required']    = [ 'position' ];
        $move['validate']                    = fn (array $args): ?array => $this->lp_move_plan($args)['refusal'] ?? null;
        $move['handler']                     = fn (array $args, array $context): array => $this->lp_move($args, $context);
        unset($move);

        return $ops;
    }

    // ------------------------------------------------------------------
    // Refusals (run before any snapshot)
    // ------------------------------------------------------------------

    private function tables_refusal(): ?array
    {
        return self::tables_ready() ? null : self::refusal('learnpress_table_missing', 'The LearnPress curriculum tables are missing on this site.');
    }

    private function item_add_refusal(string $kind, int $section, string $status): ?array
    {
        $row = $this->live_section($section);
        if (null === $row) {
            return self::refusal('invalid_parent', sprintf('%d is not a LearnPress section.', $section), [ 'parent_id' => $section ]);
        }
        return $this->create_refusal($kind, $status) ?? $this->edit_refusal([ $row['section_course_id'] ]);
    }

    private function lp_update_refusal(array $args): ?array
    {
        if (! isset($args['section_id'])) {
            return isset($args['id']) ? $this->update_refusal($args) : self::refusal('invalid_argument', 'Pass id (a course, lesson or quiz) or section_id (a section).');
        }
        if (isset($args['id'])) {
            return self::refusal('invalid_argument', 'Pass id (a course, lesson or quiz) or section_id (a section), not both.');
        }
        $section = (int) $args['section_id'];
        $row     = $this->live_section($section);
        if (null === $row) {
            return self::refusal('item_not_found', sprintf('%d is not a LearnPress section.', $section), [ 'section_id' => $section ]);
        }
        if ([] !== array_intersect_key($args, array_flip([ 'excerpt', 'status' ]))) {
            return self::refusal('invalid_argument', 'A LearnPress section has only a title and a description (content).');
        }
        if ([] === array_intersect_key($args, array_flip([ 'title', 'content' ]))) {
            return self::refusal('nothing_to_update', 'Pass title or content.');
        }
        return $this->edit_refusal([ $row['section_course_id'] ]);
    }

    /**
     * What a move changes, or a refusal. Shared by validate (before any
     * snapshot) and the handler.
     *
     * @return array{refusal?: array, kind?: string, id?: int, course?: int, from?: int, to?: int, dest?: int[], source?: int[]}
     */
    private function lp_move_plan(array $args): array
    {
        $pos = (int) $args['position'];
        if (isset($args['section_id'])) {
            if (isset($args['id'])) {
                return [ 'refusal' => self::refusal('invalid_argument', 'Pass id (a lesson or quiz) or section_id (a section), not both.') ];
            }
            $section = (int) $args['section_id'];
            $row     = $this->live_section($section);
            if (null === $row) {
                return [ 'refusal' => self::refusal('item_not_found', sprintf('%d is not a LearnPress section.', $section), [ 'section_id' => $section ]) ];
            }
            $course = $row['section_course_id'];
            if (isset($args['parent_id']) && (int) $args['parent_id'] !== $course) {
                return [ 'refusal' => self::refusal('cross_course_move', sprintf('A section stays in its course (%d).', $course), [ 'course_id' => $course ]) ];
            }
            $dest = array_values(array_diff($this->section_ids($course), [ $section ]));
            array_splice($dest, min($pos, count($dest)), 0, [ $section ]);
            $refusal = $this->edit_refusal([ $course ]);
            return null !== $refusal ? [ 'refusal' => $refusal ] : [ 'kind' => 'section', 'id' => $section, 'course' => $course, 'dest' => $dest ];
        }

        if (! isset($args['id'])) {
            return [ 'refusal' => self::refusal('invalid_argument', 'Pass id (a lesson or quiz) or section_id (a section).') ];
        }
        $id   = (int) $args['id'];
        $kind = $this->kind_of($id);
        if (null === $kind || 'course' === $kind) {
            return [ 'refusal' => self::refusal('item_not_found', sprintf('%d is not a LearnPress lesson or quiz in a course.', $id), [ 'id' => $id ]) ];
        }
        $item   = (array) $this->item_row($id);
        $course = (int) $item['course'];
        $from   = (int) $item['section_id'];
        $to     = isset($args['parent_id']) ? (int) $args['parent_id'] : $from;
        $target = $this->live_section($to);
        if (null === $target) {
            return [ 'refusal' => self::refusal('invalid_parent', sprintf('%d is not a LearnPress section.', $to), [ 'parent_id' => $to ]) ];
        }
        if ($target['section_course_id'] !== $course) {
            return [ 'refusal' => self::refusal('cross_course_move', sprintf('Items move only between sections of their own course (%d).', $course), [ 'course_id' => $course ]) ];
        }
        $dest = array_values(array_diff($this->item_ids($to), [ $id ]));
        array_splice($dest, min($pos, count($dest)), 0, [ $id ]);
        $source  = $from === $to ? [] : array_values(array_diff($this->item_ids($from), [ $id ]));
        $refusal = $this->edit_refusal([ $course, $id ]);
        if (null !== $refusal) {
            return [ 'refusal' => $refusal ];
        }
        return [ 'kind' => $kind, 'id' => $id, 'course' => $course, 'from' => $from, 'to' => $to, 'dest' => $dest, 'source' => $source ];
    }

    // ------------------------------------------------------------------
    // Writes
    // ------------------------------------------------------------------

    /** Run $write under a snapshot of LearnPress curriculum rows; returns the operation id. */
    private function rows_snapshotted(string $kind, string $suffix, array $ids, array $context, array $args, callable $write): string
    {
        $out = Safe_Mutation::run(
            [
                'object_type' => Plugin_Table_Rows_Snapshot::TYPE,
                'object_id'   => Plugin_Table_Rows_Snapshot::key($kind, $suffix, $ids),
                'session_id'  => (string) $context['session_id'],
                'tool_name'   => (string) $context['tool_name'],
                'args'        => [ 'operation' => (string) $context['operation'], 'args' => $args ],
            ],
            static function () use ($write): bool {
                $write();
                return true;
            }
        );
        return (string) $out['operation_id'];
    }

    private function add_section(array $args, array $context): array
    {
        $course  = (int) $args['course_id'];
        $list    = $this->section_ids($course);
        $pos     = min((int) ($args['position'] ?? count($list)), count($list));
        $created = 0;

        $op = $this->rows_snapshotted('learnpress_sections', 'sections', [ $course ], $context, $args, function () use ($course, $list, $pos, $args, &$created): void {
            global $wpdb;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- LearnPress's own section table, as CourseSectionModel::save() inserts it.
            $wpdb->insert(self::sections_table(), [
                'section_name'        => (string) $args['title'],
                'section_course_id'   => $course,
                'section_order'       => $pos + 1,
                'section_description' => (string) ($args['content'] ?? ''),
            ]);
            $created = (int) $wpdb->insert_id;
            if ($created < 1) {
                throw new Operation_Refused('insert_failed', 'LearnPress could not create the section.');
            }
            array_splice($list, $pos, 0, [ $created ]);
            $this->renumber_sections($list);
        });
        Plugin_Table_Rows_Snapshot::refresh_learnpress_courses([ $course ]);

        return [ 'result' => [ 'item' => $this->section_node((array) $this->section_row($created)) ], 'operation_ids' => [ $op ] ];
    }

    private function add_item(string $kind, array $args, array $context): array
    {
        $section = (int) $args['parent_id'];
        $course  = (int) $this->section_row($section)['section_course_id'];
        $list    = $this->item_ids($section);
        $pos     = min((int) ($args['position'] ?? count($list)), count($list));
        $type    = $this->types()[ $kind ];

        $created = wp_insert_post([
            'post_type'    => $type,
            'post_title'   => wp_slash((string) $args['title']),
            'post_content' => wp_slash((string) ($args['content'] ?? '')),
            'post_excerpt' => wp_slash((string) ($args['excerpt'] ?? '')),
            'post_status'  => (string) ($args['status'] ?? 'publish'),
            'post_author'  => get_current_user_id(),
        ], true);
        if (is_wp_error($created) || 0 === (int) $created) {
            throw new Operation_Refused('insert_failed', sprintf('LearnPress could not create the %s.', $kind));
        }
        $created = (int) $created;
        $ids     = [ Post_Creation_Snapshot::record((string) $context['tool_name'], [ $created ], [ 'operation' => $context['operation'], 'args' => $args ], (string) $context['session_id']) ];

        $ids[] = $this->rows_snapshotted('learnpress_section_items', 'section_items', [ $section ], $context, $args, function () use ($section, $created, $type, $list, $pos): void {
            global $wpdb;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- LearnPress's own section items table, as CourseSectionItemModel::save() inserts it.
            $wpdb->insert(self::items_table(), [ 'section_id' => $section, 'item_id' => $created, 'item_order' => $pos + 1, 'item_type' => $type ]);
            array_splice($list, $pos, 0, [ $created ]);
            $this->renumber_items($section, $list);
        });
        Plugin_Table_Rows_Snapshot::refresh_learnpress_courses([ $course ]);

        return [ 'result' => [ 'item' => $this->node(get_post($created), $kind) ], 'operation_ids' => $ids ];
    }

    private function update_section(array $args): array
    {
        global $wpdb;
        $section = (int) $args['section_id'];
        $fields  = [];
        if (array_key_exists('title', $args)) {
            $fields['section_name'] = (string) $args['title'];
        }
        if (array_key_exists('content', $args)) {
            $fields['section_description'] = (string) $args['content'];
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- LearnPress's own section table, as CourseSectionModel::save() updates it.
        if (false === $wpdb->update(self::sections_table(), $fields, [ 'section_id' => $section ])) {
            throw new Operation_Refused('update_failed', 'LearnPress could not update the section.');
        }
        $row = (array) $this->section_row($section);
        Plugin_Table_Rows_Snapshot::refresh_learnpress_courses([ (int) $row['section_course_id'] ]);
        return [ 'item' => $this->section_node($row) ];
    }

    private function lp_move(array $args, array $context): array
    {
        $plan = $this->lp_move_plan($args);
        if (isset($plan['refusal'])) {
            throw new Operation_Refused((string) $plan['refusal']['code'], (string) $plan['refusal']['message'], (array) $plan['refusal']['data']);
        }
        $id     = (int) $plan['id'];
        $course = (int) $plan['course'];
        $dest   = array_map('intval', $plan['dest']);
        $ids    = [];

        if ('section' === $plan['kind']) {
            if ($this->sections_out_of_order($dest)) {
                $ids[] = $this->rows_snapshotted('learnpress_sections', 'sections', [ $course ], $context, $args, fn () => $this->renumber_sections($dest));
            }
            $item = $this->section_node((array) $this->section_row($id));
        } else {
            $from   = (int) $plan['from'];
            $to     = (int) $plan['to'];
            $source = array_map('intval', $plan['source']);
            if ($from !== $to || $this->items_out_of_order($to, $dest)) {
                $ids[] = $this->rows_snapshotted('learnpress_section_items', 'section_items', array_unique([ $from, $to ]), $context, $args, function () use ($id, $from, $to, $dest, $source): void {
                    global $wpdb;
                    if ($from !== $to) {
                        $wpdb->update(self::items_table(), [ 'section_id' => $to ], [ 'section_id' => $from, 'item_id' => $id ]); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- LearnPress's own section items table.
                        $this->renumber_items($from, $source);
                    }
                    $this->renumber_items($to, $dest);
                });
            }
            $item = $this->node(get_post($id), (string) $plan['kind']);
        }
        if ([] !== $ids) {
            Plugin_Table_Rows_Snapshot::refresh_learnpress_courses([ $course ]);
        }

        return [ 'result' => [ 'item' => $item, 'order' => $dest ], 'operation_ids' => $ids ];
    }
    // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
