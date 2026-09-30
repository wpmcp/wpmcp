<?php

namespace WPMCP\Integrations;

use WPMCP\Safety\Post_Creation_Snapshot;
use WPMCP\Safety\Safe_Mutation;
use WPMCP\Safety\Save_Filters;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * One LMS plugin's course structure and enrollments as plugin-data ops
 * (issue #394). The op catalog, argument checks and the snapshot discipline
 * live here, once; a subclass only says where its plugin keeps things
 * (LMS_Tutor, LMS_LifterLMS).
 *
 * The tree is course > sections (Tutor LMS calls them topics) > items
 * (lessons, and in Tutor LMS also quizzes and assignments), plus a quiz
 * attached to a LifterLMS lesson. Every node is a post of the plugin's own
 * type, and a node only counts as part of a course when the plugin's own
 * links lead back to a course of that plugin: a post of a matching type
 * that is not linked (or belongs to the other LMS, which also uses the
 * "lesson" type) is refused as item_not_found.
 *
 * Writes (all paid-tier, like the reads):
 *  - create-course, add-section, add-lesson and add-quiz create a post and
 *    record it for rollback (Post_Creation_Snapshot: the undo moves it to
 *    the trash). Inserting at a position renumbers the siblings after it,
 *    each through its own post snapshot, and so does attaching a LifterLMS
 *    quiz to its lesson. One call is one session, so rollback-session undoes
 *    all of it.
 *  - update-item edits title, content, excerpt or status of one node under a
 *    post snapshot (rollback-operation).
 *  - move-item reorders a section in its course, or moves an item within or
 *    between sections of the SAME course; every post whose parent or order
 *    changes is snapshotted, one session per call.
 * Nothing is deleted here: update-item can take a node to draft, and a
 * created node's undo is the trash.
 *
 * Capabilities are the plugin's own, through its post types: creating a node
 * needs the type's create_posts, publishing needs publish_posts, and every
 * post a write changes needs edit_post on it. Enrollments are personal data
 * and read-only: list-enrollments needs list_users, the capability that
 * lists users anywhere else, and returns only id, display name, email,
 * status and date.
 */
abstract class LMS_Adapter
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.
    public const MAX_PER_PAGE = 50;

    /** Statuses a write may set. */
    private const STATUSES = [ 'publish', 'draft', 'pending', 'private' ];

    /** Statuses a course listing may filter on. */
    private const COURSE_STATUSES = [ 'publish', 'draft', 'pending', 'private', 'future' ];

    /** Normalized enrollment statuses. */
    protected const ENROLLMENT_STATUSES = [ 'enrolled', 'pending', 'cancelled', 'expired' ];

    /** Op name prefix and presence-filter slug, e.g. 'tutor'. */
    abstract public function slug(): string;

    /** The plugin's name, e.g. 'Tutor LMS'. */
    abstract public function label(): string;

    /** Whether the plugin is loaded (filterable per plugin). */
    abstract public function active(): bool;

    /** What the plugin calls a section: 'topic' or 'section'. */
    abstract protected function section_kind(): string;

    /** @return array<string, string> kind (course, section, lesson, quiz, assignment) => post type. */
    abstract protected function types(): array;

    /** The kind a new node's parent must be: lesson => section, quiz => section or lesson. */
    abstract protected function parent_kind(string $kind): string;

    /** The course a section, item or quiz post is linked to, or 0. */
    abstract protected function linked_course(\WP_Post $post, string $kind): int;

    /** The section an item is linked to, or 0. */
    abstract protected function section_of(int $item): int;

    /** @return int[] the course's section ids in the plugin's order. */
    abstract protected function section_ids(int $course): array;

    /** @return \WP_Post[] the section's items in the plugin's order. */
    abstract protected function item_posts(int $section): array;

    /** The stored order of a section or item. */
    abstract protected function stored_order(int $id): int;

    /** Write the order of a section or item. */
    abstract protected function store_order(int $id, int $order): void;

    /** Link an existing item to another section of the same course. */
    abstract protected function store_section(int $item, int $section): void;

    /**
     * The insert arguments that link a new node to its parent at $order:
     * post_parent and/or meta_input.
     */
    abstract protected function link_args(string $kind, int $parent, int $course, int $order): array;

    /** @return array<int, array<string, mixed>>|null a quiz's questions in order, or null when they cannot be read. */
    abstract protected function questions(int $quiz): ?array;

    /**
     * One page of a course's enrollments, newest first.
     *
     * @return array{total: int, rows: array<int, array{user_id: int, status: string, enrolled_at: ?string}>}
     */
    abstract protected function enrollment_page(int $course, ?string $status, int $page, int $per_page): array;

    /** Students whose enrollment in the course is active, or null when it cannot be read. */
    abstract protected function enrolled_count(int $course): ?int;

    /** Kinds move-item accepts. */
    protected function movable_kinds(): array
    {
        return [ 'section', 'lesson' ];
    }

    /** Extra fields for a lesson node in the tree (a LifterLMS lesson's quiz). */
    protected function lesson_extras(\WP_Post $lesson, bool $questions): array
    {
        return [];
    }

    /** A refusal specific to adding $kind under $parent, or null. */
    protected function add_refusal(string $kind, int $parent): ?array
    {
        return null;
    }

    /**
     * A write the plugin needs on the PARENT after a node was created under
     * it (a LifterLMS lesson learning its quiz id), run under the parent's
     * post snapshot. Null when the parent does not change.
     */
    protected function parent_update(string $kind, int $parent, int $created): ?callable
    {
        return null;
    }

    // ------------------------------------------------------------------
    // Catalog
    // ------------------------------------------------------------------

    /** @return array<string, array<string, mixed>> */
    public function operations(): array
    {
        $p        = $this->slug();
        $label    = $this->label();
        $section  = $this->section_kind();
        $requires = fn () => Ops_Status_Packs::presence($this->active(), $p . '_inactive', $label);
        $id       = [ 'type' => 'integer', 'minimum' => 1 ];
        $paging   = [
            'page'     => [ 'type' => 'integer', 'minimum' => 1 ],
            'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PER_PAGE ],
        ];
        $text     = [
            'title'   => [ 'type' => 'string', 'minLength' => 1 ],
            'content' => [ 'type' => 'string' ],
        ];
        $position = [ 'position' => [ 'type' => 'integer', 'minimum' => 0 ] ];
        $status   = [ 'status' => [ 'type' => 'string', 'enum' => self::STATUSES ] ];
        $quiz_in  = 'lesson' === $this->parent_kind('quiz') ? 'a lesson (one quiz each)' : 'a ' . $section;
        $base     = [ 'tier' => 'pro', 'requires' => $requires ];

        return [
            "{$p}-list-courses"     => $base + [
                'mode'         => 'read',
                'description'  => "List {$label} courses newest first with section, lesson and quiz counts and active enrollments. search matches the title; status filters; page and per_page (max 50)",
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'search' => [ 'type' => 'string' ],
                        'status' => [ 'type' => 'string', 'enum' => self::COURSE_STATUSES ],
                    ] + $paging,
                ],
                'handler'      => fn (array $args): array => $this->list_courses($args),
            ],
            "{$p}-get-course"       => $base + [
                'mode'         => 'read',
                'objects'      => [ 'id' => [ 'type' => 'post', 'own_type' => true ] ],
                'description'  => "One {$label} course as a tree: {$section}s in order, each with its items (lesson, quiz, assignment) in order" . ('lesson' === $this->parent_kind('quiz') ? ', a lesson\'s quiz under it' : '') . ', quiz questions (id, title, type, points) unless questions:false, and counts',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'id' => $id, 'questions' => [ 'type' => 'boolean' ] ],
                    'required'   => [ 'id' ],
                ],
                'validate'     => fn (array $args): ?array => $this->course_refusal((int) $args['id'], false),
                'handler'      => fn (array $args): array => $this->get_course((int) $args['id'], (bool) ($args['questions'] ?? true)),
            ],
            "{$p}-list-enrollments" => $base + [
                'mode'         => 'read',
                'objects'      => [ 'course_id' => [ 'type' => 'post', 'own_type' => true ] ],
                'capability'   => 'list_users',
                'description'  => "A {$label} course's enrollments newest first: user id, display name, email, status (enrolled, pending, cancelled, expired) and date. Personal data: needs list_users. Read-only; status filters; page and per_page (max 50)",
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'course_id' => $id,
                        'status'    => [ 'type' => 'string', 'enum' => self::ENROLLMENT_STATUSES ],
                    ] + $paging,
                    'required'   => [ 'course_id' ],
                ],
                'validate'     => fn (array $args): ?array => $this->course_refusal((int) $args['course_id'], false),
                'handler'      => fn (array $args): array => $this->list_enrollments($args),
            ],
            "{$p}-create-course"    => $base + [
                'mode'              => 'write',
                'self_snapshotting' => true,
                'description'       => "Create a {$label} course (status defaults to draft). Undo: rollback-session trashes it",
                'input_schema'      => [
                    'type'       => 'object',
                    'properties' => $text + [ 'excerpt' => [ 'type' => 'string' ] ] + $status,
                    'required'   => [ 'title' ],
                ],
                'validate'          => fn (array $args): ?array => $this->create_refusal('course', $args['status'] ?? 'draft'),
                'handler'           => fn (array $args, array $context): array => $this->add('course', 0, $args, $context),
            ],
            "{$p}-add-section"      => $base + [
                'mode'              => 'write',
                'objects'           => [ 'course_id' => [ 'type' => 'post', 'own_type' => true ] ],
                'self_snapshotting' => true,
                'description'       => "Add a {$section} to course_id at position (0-based; default last), renumbering the ones after it. Undo: rollback-session",
                'input_schema'      => [
                    'type'       => 'object',
                    'properties' => [ 'course_id' => $id ] + $text + $position,
                    'required'   => [ 'course_id', 'title' ],
                ],
                'validate'          => fn (array $args): ?array => $this->add_validation('section', (int) $args['course_id'], 'publish'),
                'handler'           => fn (array $args, array $context): array => $this->add('section', (int) $args['course_id'], $args, $context),
            ],
            "{$p}-add-lesson"       => $base + [
                'mode'              => 'write',
                'objects'           => [ 'parent_id' => [ 'type' => 'post', 'own_type' => true ] ],
                'self_snapshotting' => true,
                'description'       => "Add a lesson to the {$section} parent_id at position (0-based; default last); status defaults to publish. Undo: rollback-session",
                'input_schema'      => [
                    'type'       => 'object',
                    'properties' => [ 'parent_id' => $id ] + $text + $status + $position,
                    'required'   => [ 'parent_id', 'title' ],
                ],
                'validate'          => fn (array $args): ?array => $this->add_validation('lesson', (int) $args['parent_id'], $args['status'] ?? 'publish'),
                'handler'           => fn (array $args, array $context): array => $this->add('lesson', (int) $args['parent_id'], $args, $context),
            ],
            "{$p}-add-quiz"         => $base + [
                'mode'              => 'write',
                'objects'           => [ 'parent_id' => [ 'type' => 'post', 'own_type' => true ] ],
                'self_snapshotting' => true,
                'description'       => "Add a quiz to parent_id, {$quiz_in}" . ('lesson' === $this->parent_kind('quiz') ? '' : ', at position (0-based; default last)') . '. Questions are authored in the plugin. Undo: rollback-session',
                'input_schema'      => [
                    'type'       => 'object',
                    'properties' => [ 'parent_id' => $id ] + $text + $status + $position,
                    'required'   => [ 'parent_id', 'title' ],
                ],
                'validate'          => fn (array $args): ?array => $this->add_validation('quiz', (int) $args['parent_id'], $args['status'] ?? 'publish'),
                'handler'           => fn (array $args, array $context): array => $this->add('quiz', (int) $args['parent_id'], $args, $context),
            ],
            "{$p}-update-item"      => $base + [
                'mode'         => 'write',
                'objects'      => [ 'id' => [ 'type' => 'post', 'own_type' => true ] ],
                'description'  => "Edit title, content, excerpt or status of a {$label} course, {$section}, lesson or quiz. Undo: rollback-operation",
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'id' => $id, 'title' => $text['title'], 'content' => $text['content'], 'excerpt' => [ 'type' => 'string' ] ] + $status,
                    'required'   => [ 'id' ],
                ],
                'validate'     => fn (array $args): ?array => $this->update_refusal($args),
                'snapshot'     => static fn (array $args): array => [ 'object_type' => 'post', 'object_id' => (int) $args['id'] ],
                'handler'      => fn (array $args): array => $this->update_item($args),
            ],
            "{$p}-move-item"        => $base + [
                'mode'              => 'write',
                'objects'           => [ 'id' => [ 'type' => 'post', 'own_type' => true ], 'parent_id' => [ 'type' => 'post', 'own_type' => true ] ],
                'self_snapshotting' => true,
                'description'       => "Move a {$section} to position in its course, or a " . implode(' or ', array_diff($this->movable_kinds(), [ 'section' ])) . " to position in parent_id (a {$section} of the same course; default its own). 0-based; siblings renumbered. Undo: rollback-session",
                'input_schema'      => [
                    'type'       => 'object',
                    'properties' => [ 'id' => $id, 'parent_id' => $id, 'position' => $position['position'] ],
                    'required'   => [ 'id', 'position' ],
                ],
                'validate'          => fn (array $args): ?array => $this->move_refusal($args),
                'handler'           => fn (array $args, array $context): array => $this->move_item($args, $context),
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Tree
    // ------------------------------------------------------------------

    /** The kind of a post that is part of one of this plugin's courses, or null. */
    public function kind_of(int $id): ?string
    {
        $post = $id > 0 ? get_post($id) : null;
        if (! $post instanceof \WP_Post || in_array($post->post_status, [ 'trash', 'auto-draft' ], true)) {
            return null;
        }
        $kind = array_search($post->post_type, $this->types(), true);
        if (false === $kind) {
            return null;
        }
        if ('course' === $kind) {
            return 'course';
        }
        return $this->linked_course($post, $kind) > 0 ? $kind : null;
    }

    /** The course a node belongs to (itself for a course), or 0. */
    protected function course_of(int $id): int
    {
        $kind = $this->kind_of($id);
        if (null === $kind) {
            return 0;
        }
        return 'course' === $kind ? $id : $this->linked_course(get_post($id), $kind);
    }

    /** Whether $id is a live course of this plugin. */
    protected function is_course(int $id): bool
    {
        $post = get_post($id);
        return $post instanceof \WP_Post && $this->types()['course'] === $post->post_type && 'trash' !== $post->post_status;
    }

    /** A node as returned to the caller. */
    protected function node(\WP_Post $post, string $kind, bool $with_order = true): array
    {
        $node = [
            'id'     => (int) $post->ID,
            'kind'   => 'section' === $kind ? $this->section_kind() : $kind,
            'title'  => (string) $post->post_title,
            'status' => (string) $post->post_status,
        ];
        if ($with_order && 'course' !== $kind && ('quiz' !== $kind || 'section' === $this->parent_kind('quiz'))) {
            $node['order'] = $this->stored_order((int) $post->ID);
        }
        return $node;
    }

    /** @return int[] the ids under a parent, in order: a course's sections or a section's items. */
    protected function sibling_ids(string $kind, int $parent): array
    {
        if ('section' === $kind) {
            return $this->section_ids($parent);
        }
        return array_map(static fn (\WP_Post $p): int => (int) $p->ID, $this->item_posts($parent));
    }

    /** Sorted posts of $types that are the children of $parent by post_parent. */
    protected static function children_by_parent(int $parent, array $types): array
    {
        if ($parent < 1) {
            return [];
        }
        return get_posts([
            'post_type'        => $types,
            'post_parent'      => $parent,
            'post_status'      => 'any',
            'orderby'          => [ 'menu_order' => 'ASC', 'ID' => 'ASC' ],
            'posts_per_page'   => -1,
            'no_found_rows'    => true,
        ]);
    }

    /** Sorted posts of $type whose $link meta names $parent, ordered by the $order meta. */
    protected static function children_by_meta(string $type, string $link, int $parent, string $order): array
    {
        if ($parent < 1) {
            return [];
        }
        return get_posts([
            'post_type'        => $type,
            'post_status'      => 'any',
            'meta_query'       => [ [ 'key' => $link, 'value' => $parent ] ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the plugin links its tree through post meta and queries it the same way.
            'meta_key'         => $order, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- ordering by the plugin's own order meta.
            'orderby'          => [ 'meta_value_num' => 'ASC', 'ID' => 'ASC' ],
            'posts_per_page'   => -1,
            'no_found_rows'    => true,
        ]);
    }

    /** The course tree. */
    protected function get_course(int $course, bool $questions): array
    {
        $post   = get_post($course);
        $counts = [ 'sections' => 0, 'lessons' => 0, 'quizzes' => 0, 'assignments' => 0, 'questions' => $questions ? 0 : null ];
        $kinds  = array_flip($this->types());

        $sections = [];
        foreach ($this->section_ids($course) as $section_id) {
            $section = get_post($section_id);
            if (! $section instanceof \WP_Post) {
                continue;
            }
            ++$counts['sections'];
            $items = [];
            foreach ($this->item_posts($section_id) as $item) {
                $kind = $kinds[ $item->post_type ] ?? null;
                if (null === $kind) {
                    continue;
                }
                $node = $this->node($item, $kind);
                if ('quiz' === $kind) {
                    ++$counts['quizzes'];
                    if ($questions) {
                        $node += $this->question_fields((int) $item->ID, $counts);
                    }
                } elseif ('lesson' === $kind) {
                    ++$counts['lessons'];
                    $node += $this->lesson_extras($item, $questions);
                    if (isset($node['quiz'])) {
                        ++$counts['quizzes'];
                        if ($questions && is_array($node['quiz']['questions'] ?? null)) {
                            $counts['questions'] += count($node['quiz']['questions']);
                        }
                    }
                } elseif ('assignment' === $kind) {
                    ++$counts['assignments'];
                }
                $items[] = $node;
            }
            $sections[] = $this->node($section, 'section') + [ 'items' => $items ];
        }

        return [
            'course'   => $this->node($post, 'course') + [ 'excerpt' => (string) $post->post_excerpt ],
            'sections' => $sections,
            'counts'   => $counts,
        ];
    }

    /** The questions of a Tutor-style quiz item, adding to the question count. */
    private function question_fields(int $quiz, array &$counts): array
    {
        $list = $this->questions($quiz);
        if (null === $list) {
            return [ 'questions' => null, 'questions_unavailable' => 'the plugin\'s question storage is missing' ];
        }
        $counts['questions'] += count($list);
        return [ 'questions' => $list ];
    }

    /** One page of courses with their structure counts. */
    protected function list_courses(array $args): array
    {
        $per_page = max(1, min(self::MAX_PER_PAGE, (int) ($args['per_page'] ?? 20)));
        $page     = max(1, (int) ($args['page'] ?? 1));
        $query    = new \WP_Query([
            'post_type'        => $this->types()['course'],
            'post_status'      => isset($args['status']) ? (string) $args['status'] : self::COURSE_STATUSES,
            's'                => (string) ($args['search'] ?? ''),
            'orderby'          => [ 'date' => 'DESC', 'ID' => 'DESC' ],
            'posts_per_page'   => $per_page,
            'paged'            => $page,
        ]);

        $rows = [];
        foreach ($query->posts as $post) {
            $tree   = $this->get_course((int) $post->ID, false);
            $rows[] = $this->node($post, 'course') + [
                'sections' => $tree['counts']['sections'],
                'lessons'  => $tree['counts']['lessons'],
                'quizzes'  => $tree['counts']['quizzes'],
                'enrolled' => $this->enrolled_count((int) $post->ID),
            ];
        }

        return [ 'courses' => $rows, 'total' => (int) $query->found_posts, 'page' => $page, 'per_page' => $per_page ];
    }

    /** One page of enrollments with the listed user fields only. */
    protected function list_enrollments(array $args): array
    {
        $course   = (int) $args['course_id'];
        $per_page = max(1, min(self::MAX_PER_PAGE, (int) ($args['per_page'] ?? 20)));
        $page     = max(1, (int) ($args['page'] ?? 1));
        $result   = $this->enrollment_page($course, isset($args['status']) ? (string) $args['status'] : null, $page, $per_page);

        $rows = [];
        foreach ($result['rows'] as $row) {
            $user   = get_userdata((int) $row['user_id']);
            $rows[] = [
                'user_id'      => (int) $row['user_id'],
                'display_name' => $user instanceof \WP_User ? (string) $user->display_name : null,
                'email'        => $user instanceof \WP_User ? (string) $user->user_email : null,
                'status'       => (string) $row['status'],
                'enrolled_at'  => $row['enrolled_at'],
            ];
        }

        return [ 'course_id' => $course, 'enrollments' => $rows, 'total' => (int) $result['total'], 'page' => $page, 'per_page' => $per_page ];
    }

    // ------------------------------------------------------------------
    // Refusals (run before any snapshot)
    // ------------------------------------------------------------------

    protected static function refusal(string $code, string $message, array $data = []): array
    {
        return [ 'code' => $code, 'message' => $message, 'data' => $data ];
    }

    /** Null when $id is a course of this plugin the user may read (or, with $write, edit). */
    protected function course_refusal(int $id, bool $write): ?array
    {
        if (! $this->is_course($id)) {
            return self::refusal('course_not_found', sprintf('%d is not a %s course.', $id, $this->label()), [ 'id' => $id ]);
        }
        return $this->edit_refusal([ $id ], ! $write);
    }

    /** Null when the user may edit (or, with $read, read) every post in $ids. */
    protected function edit_refusal(array $ids, bool $read = false): ?array
    {
        foreach ($ids as $id) {
            if (! current_user_can($read ? 'read_post' : 'edit_post', (int) $id)) {
                return self::refusal('operation_denied', sprintf('You are not allowed to %s post %d.', $read ? 'read' : 'edit', (int) $id), [ 'reason' => 'capability', 'id' => (int) $id ]);
            }
        }
        return null;
    }

    /** Null when the user may create a $kind node with $status. */
    protected function create_refusal(string $kind, string $status): ?array
    {
        $type = get_post_type_object($this->types()[ $kind ]);
        if (! $type instanceof \WP_Post_Type) {
            return self::refusal('post_type_missing', sprintf('%s has not registered its %s post type.', $this->label(), $kind));
        }
        $caps = [ $type->cap->create_posts ];
        if (in_array($status, [ 'publish', 'private' ], true)) {
            $caps[] = $type->cap->publish_posts;
        }
        foreach ($caps as $cap) {
            if (! current_user_can($cap)) {
                return self::refusal('operation_denied', sprintf('Creating a %s %s needs the "%s" capability.', $this->label(), $kind, $cap), [ 'reason' => 'capability' ]);
            }
        }
        return null;
    }

    /** Refusal for adding a $kind node under $parent, or null. */
    protected function add_validation(string $kind, int $parent, string $status): ?array
    {
        $want = 'section' === $kind ? 'course' : $this->parent_kind($kind);
        if ($this->kind_of($parent) !== $want) {
            $noun = 'section' === $want ? $this->section_kind() : $want;
            return self::refusal('course' === $want ? 'course_not_found' : 'invalid_parent', sprintf('%d is not a %s %s.', $parent, $this->label(), $noun), [ 'parent_id' => $parent ]);
        }
        return $this->create_refusal($kind, $status)
            ?? $this->edit_refusal([ $parent ])
            ?? $this->add_refusal($kind, $parent);
    }

    /** Refusal for update-item, or null. */
    protected function update_refusal(array $args): ?array
    {
        $id   = (int) $args['id'];
        $kind = $this->kind_of($id);
        if (null === $kind) {
            return self::refusal('item_not_found', sprintf('%d is not part of a %s course.', $id, $this->label()), [ 'id' => $id ]);
        }
        if ([] === array_intersect_key($args, array_flip([ 'title', 'content', 'excerpt', 'status' ]))) {
            return self::refusal('nothing_to_update', 'Pass at least one of title, content, excerpt or status.');
        }
        $refusal = $this->edit_refusal([ $id ]);
        if (null === $refusal && in_array($args['status'] ?? '', [ 'publish', 'private' ], true)) {
            $type = get_post_type_object(get_post_type($id));
            if ($type instanceof \WP_Post_Type && ! current_user_can($type->cap->publish_posts)) {
                $refusal = self::refusal('operation_denied', sprintf('Publishing needs the "%s" capability.', $type->cap->publish_posts), [ 'reason' => 'capability' ]);
            }
        }
        return $refusal;
    }

    /** Refusal for move-item, or null. */
    protected function move_refusal(array $args): ?array
    {
        $plan = $this->move_plan($args);
        return is_array($plan['refusal'] ?? null) ? $plan['refusal'] : null;
    }

    /**
     * What a move changes: the destination and source order lists, or a
     * refusal. Shared by validate (before any snapshot) and the handler.
     *
     * @return array{refusal?: array, kind?: string, id?: int, from?: int, to?: int, dest?: int[], source?: int[]}
     */
    protected function move_plan(array $args): array
    {
        $id   = (int) $args['id'];
        $kind = $this->kind_of($id);
        if (null === $kind || 'course' === $kind) {
            return [ 'refusal' => self::refusal('item_not_found', sprintf('%d is not a %s %s or course item.', $id, $this->label(), $this->section_kind()), [ 'id' => $id ]) ];
        }
        if (! in_array($kind, $this->movable_kinds(), true)) {
            return [ 'refusal' => self::refusal('not_movable', sprintf('A %s %s cannot be moved here; it belongs to its parent.', $this->label(), $kind), [ 'id' => $id ]) ];
        }

        $course = $this->course_of($id);
        if ('section' === $kind) {
            $from = $course;
            $to   = isset($args['parent_id']) ? (int) $args['parent_id'] : $course;
            if ($to !== $course) {
                return [ 'refusal' => self::refusal('cross_course_move', sprintf('A %s stays in its course (%d).', $this->section_kind(), $course), [ 'course_id' => $course ]) ];
            }
        } else {
            $from = $this->section_of($id);
            $to   = isset($args['parent_id']) ? (int) $args['parent_id'] : $from;
            if ('section' !== $this->kind_of($to)) {
                return [ 'refusal' => self::refusal('invalid_parent', sprintf('%d is not a %s %s.', $to, $this->label(), $this->section_kind()), [ 'parent_id' => $to ]) ];
            }
            if ($this->course_of($to) !== $course) {
                return [ 'refusal' => self::refusal('cross_course_move', sprintf('Items move only between %ss of their own course (%d).', $this->section_kind(), $course), [ 'course_id' => $course ]) ];
            }
        }

        $dest = array_values(array_diff($this->sibling_ids($kind, $to), [ $id ]));
        $pos  = min((int) $args['position'], count($dest));
        array_splice($dest, $pos, 0, [ $id ]);
        $source = $from === $to ? [] : array_values(array_diff($this->sibling_ids($kind, $from), [ $id ]));

        $touched = array_merge([ $id ], $this->reordered($dest, $id), $this->reordered($source, 0));
        $refusal = $this->edit_refusal(array_unique(array_merge($touched, [ $to ])));
        if (null !== $refusal) {
            return [ 'refusal' => $refusal ];
        }

        return [ 'kind' => $kind, 'id' => $id, 'from' => $from, 'to' => $to, 'dest' => $dest, 'source' => $source ];
    }

    /** @return int[] ids in $list (except $skip) whose stored order differs from their place (1-based). */
    protected function reordered(array $list, int $skip): array
    {
        $out = [];
        foreach (array_values($list) as $i => $id) {
            if ($id !== $skip && $this->stored_order($id) !== $i + 1) {
                $out[] = $id;
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Writes
    // ------------------------------------------------------------------

    /** Run $write on post $id under its own post snapshot; returns the operation id. */
    protected function snapshotted(int $id, array $context, array $args, callable $write): string
    {
        $out = Safe_Mutation::run(
            [
                'object_type' => 'post',
                'object_id'   => $id,
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

    /** Renumber $list 1..n, snapshotting each post whose order changes (except $skip). */
    protected function renumber(array $list, int $skip, array $context, array $args): array
    {
        $ids = [];
        foreach (array_values($list) as $i => $id) {
            if ($id === $skip || $this->stored_order($id) === $i + 1) {
                continue;
            }
            $order = $i + 1;
            $ids[] = $this->snapshotted($id, $context, $args, fn () => $this->store_order($id, $order));
        }
        return $ids;
    }

    /** Create a $kind node under $parent (0 for a course). */
    protected function add(string $kind, int $parent, array $args, array $context): array
    {
        $ordered  = 'course' !== $kind && ('quiz' !== $kind || 'section' === $this->parent_kind('quiz'));
        $siblings = $ordered ? $this->sibling_ids($kind, $parent) : [];
        $pos      = min((int) ($args['position'] ?? count($siblings)), count($siblings));
        $course   = 'course' === $kind ? 0 : ('section' === $kind ? $parent : $this->course_of($parent));
        $status   = (string) ($args['status'] ?? ('course' === $kind ? 'draft' : 'publish'));

        $insert = [
            'post_type'    => $this->types()[ $kind ],
            'post_title'   => wp_slash((string) $args['title']),
            'post_content' => wp_slash((string) ($args['content'] ?? '')),
            'post_excerpt' => wp_slash((string) ($args['excerpt'] ?? '')),
            'post_status'  => $status,
            'post_author'  => get_current_user_id(),
        ];
        if ('course' !== $kind) {
            $insert = array_merge($insert, $this->link_args($kind, $parent, $course, $pos + 1));
        }

        $created = wp_insert_post($insert, true);
        if (is_wp_error($created) || 0 === (int) $created) {
            throw new Operation_Refused('insert_failed', sprintf('%s could not create the %s.', $this->label(), $kind));
        }
        $created = (int) $created;
        $ids     = [ Post_Creation_Snapshot::record((string) $context['tool_name'], [ $created ], [ 'operation' => $context['operation'], 'args' => $args ], (string) $context['session_id']) ];

        if ($ordered) {
            $list = $siblings;
            array_splice($list, $pos, 0, [ $created ]);
            $ids = array_merge($ids, $this->renumber($list, $created, $context, $args));
        }
        $update = $this->parent_update($kind, $parent, $created);
        if (null !== $update) {
            $ids[] = $this->snapshotted($parent, $context, $args, $update);
        }

        return [ 'result' => [ 'item' => $this->node(get_post($created), $kind) ], 'operation_ids' => $ids ];
    }

    /** Edit one node's fields (the dispatcher snapshots it first). */
    protected function update_item(array $args): array
    {
        $id     = (int) $args['id'];
        $kind   = (string) $this->kind_of($id);
        $fields = [ 'ID' => $id ];
        foreach ([ 'title' => 'post_title', 'content' => 'post_content', 'excerpt' => 'post_excerpt', 'status' => 'post_status' ] as $arg => $column) {
            if (array_key_exists($arg, $args)) {
                $fields[ $column ] = wp_slash((string) $args[ $arg ]);
            }
        }
        $out = Save_Filters::update_post($fields, true);
        if (is_wp_error($out)) {
            throw new Operation_Refused('update_failed', $out->get_error_message());
        }
        clean_post_cache($id);
        return [ 'item' => $this->node(get_post($id), $kind) ];
    }

    /** Move a section or item as planned, snapshotting every post it changes. */
    protected function move_item(array $args, array $context): array
    {
        $plan = $this->move_plan($args);
        if (isset($plan['refusal'])) {
            throw new Operation_Refused((string) $plan['refusal']['code'], (string) $plan['refusal']['message'], (array) $plan['refusal']['data']);
        }
        $id    = (int) $plan['id'];
        $order = array_search($id, $plan['dest'], true) + 1;
        $ids   = [];

        if ($plan['from'] !== $plan['to'] || $this->stored_order($id) !== $order) {
            $to    = (int) $plan['to'];
            $moved = $plan['from'] !== $plan['to'];
            $ids[] = $this->snapshotted($id, $context, $args, function () use ($id, $to, $order, $moved): void {
                if ($moved) {
                    $this->store_section($id, $to);
                }
                $this->store_order($id, $order);
            });
        }
        $ids = array_merge($ids, $this->renumber($plan['dest'], $id, $context, $args), $this->renumber($plan['source'], 0, $context, $args));

        return [
            'result'        => [ 'item' => $this->node(get_post($id), (string) $plan['kind']), 'order' => array_map('intval', $plan['dest']) ],
            'operation_ids' => $ids,
        ];
    }
    // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
