<?php

namespace WPMCP\Integrations;

use WPMCP\Safety\Post_Creation_Snapshot;
use WPMCP\Safety\Safe_Mutation;
use WPMCP\Safety\Save_Filters;
use WPMCP\Safety\Snapshot;
use WPMCP\Tools\Content\Readable_Posts;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Thrive Apprentice courses, modules, chapters and lessons as LMS ops on the
 * plugin-data pair (issue #826), paid-tier, listed by LMS_Pack next to the
 * LMS_Adapter plugins. Thrive Apprentice keeps a course as a TERM, not a
 * post, so it has its own catalog rather than an LMS_Adapter subclass.
 *
 * Storage, as Thrive Apprentice 10.9.1.4 keeps it (checked against its
 * source: inc/functions.php, class-tva-const.php, class-tva-manager.php,
 * class-tva-course-v2.php, endpoints/class-tva-courses-controller.php):
 *  - a course is a term of the `tva_courses` taxonomy; its status
 *    (publish, draft, hidden, archived), description, excerpt and list
 *    order are the tva_status, tva_description, tva_excerpt and tva_order
 *    term meta;
 *  - modules, chapters, lessons and assessments are `tva_module`,
 *    `tva_chapter`, `tva_lesson` and `tva_assessment` posts filed under the
 *    course term and nested by post_parent: a course holds modules,
 *    chapters or lessons at its top level (post_parent 0), a module holds
 *    chapters or lessons, a chapter holds lessons;
 *  - the order is post meta (tva_module_order, tva_chapter_order, and
 *    tva_lesson_order for lessons and assessments): the parent's own order
 *    string followed by the 0-based position, sorted numerically, the way
 *    TVA_Manager reads it and the course editor's update_children_order()
 *    writes it;
 *  - a module or chapter is published exactly when one of its children is
 *    (TVA_Manager::review_status()), so the writes keep that rule.
 *
 * Reads keep to the posts the caller may read (Readable_Posts, issue #575),
 * and a course that is not published only to a caller who may edit it.
 * Writes are off until wpmcp_enable_thrive_apprentice_write and snapshotted:
 * create-course and update-course as a term snapshot (rollback-operation
 * deletes a created course, restores an edited one), the lesson writes and
 * the reorder through a post snapshot per post they change and a creation
 * record for a new lesson, one session per call (rollback-session).
 * Nothing is deleted. Access products, students and progress are not part of
 * this slice.
 */
final class LMS_Thrive_Apprentice
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.
    public const WRITE_FILTER = 'wpmcp_enable_thrive_apprentice_write';

    public const TAXONOMY = 'tva_courses';

    private const MAX_PER_PAGE = 50;

    /** Post type => kind. */
    private const KINDS = [
        'tva_module'     => 'module',
        'tva_chapter'    => 'chapter',
        'tva_lesson'     => 'lesson',
        'tva_assessment' => 'assessment',
    ];

    /** Course statuses Thrive Apprentice uses. */
    private const COURSE_STATUSES = [ 'publish', 'draft', 'hidden', 'archived' ];

    /** Statuses a write may set (Thrive Apprentice's own editor sets these two). */
    private const WRITE_STATUSES = [ 'publish', 'draft' ];

    /** Every live status, for the write path, which renumbers every sibling. */
    private const ROW_STATUSES = [ 'publish', 'draft', 'future', 'pending', 'private' ];

    public function slug(): string
    {
        return 'thrive-apprentice';
    }

    public function label(): string
    {
        return 'Thrive Apprentice';
    }

    /** Thrive Apprentice's constants class (its header's THEME_VERSION belongs to the bundled theme builder). */
    public function active(): bool
    {
        return (bool) apply_filters('wpmcp_thrive_apprentice_active', class_exists('TVA_Const'));
    }

    // ------------------------------------------------------------------
    // Catalog
    // ------------------------------------------------------------------

    /** @return array<string, array<string, mixed>> */
    public function operations(): array
    {
        $p        = $this->slug();
        $requires = fn () => Ops_Status_Packs::presence($this->active(), 'thrive_apprentice_inactive', $this->label());
        $id       = [ 'type' => 'integer', 'minimum' => 1 ];
        $status   = [ 'type' => 'string', 'enum' => self::WRITE_STATUSES ];
        $read     = [ 'tier' => 'pro', 'requires' => $requires, 'mode' => 'read' ];
        $write    = [
            'tier'               => 'pro',
            'requires'           => $requires,
            'mode'               => 'write',
            'enabled_by_default' => (bool) apply_filters('wpmcp_enable_thrive_apprentice_write', false),
            'opt_in_filter'      => self::WRITE_FILTER,
        ];

        return [
            "{$p}-list-courses"           => $read + [
                'description'  => 'List Thrive Apprentice courses with module, chapter, lesson and assessment counts. search matches the name; status filters; page and per_page (max 50)',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'search'   => [ 'type' => 'string' ],
                        'status'   => [ 'type' => 'string', 'enum' => self::COURSE_STATUSES ],
                        'page'     => [ 'type' => 'integer', 'minimum' => 1 ],
                        'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PER_PAGE ],
                    ],
                ],
                'handler'      => fn (array $args): array => $this->list_courses($args),
            ],
            "{$p}-get-course"             => $read + [
                'objects'      => [ 'id' => 'term' ],
                'description'  => 'One Thrive Apprentice course (a term id) as a tree: modules, chapters, lessons and assessments in order, with counts',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'id' => $id ],
                    'required'   => [ 'id' ],
                ],
                'validate'     => fn (array $args): ?array => $this->course_refusal((int) $args['id'], false),
                'handler'      => fn (array $args): array => $this->get_course((int) $args['id']),
            ],
            "{$p}-create-course"          => $write + [
                'description'  => 'Create a Thrive Apprentice course (status defaults to draft). Undo: rollback-operation deletes it',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'title'       => [ 'type' => 'string', 'minLength' => 1 ],
                        'description' => [ 'type' => 'string' ],
                        'excerpt'     => [ 'type' => 'string' ],
                        'status'      => $status,
                    ],
                    'required'   => [ 'title' ],
                ],
                'validate'     => fn (array $args): ?array => $this->create_course_refusal((string) $args['title']),
                'snapshot'     => static fn (array $args): array => [ 'object_type' => 'term', 'object_id' => Snapshot::term_key(self::TAXONOMY, self::free_slug((string) $args['title'])) ],
                'handler'      => fn (array $args, array $context): array => $this->create_course($args, $context),
            ],
            "{$p}-update-course"          => $write + [
                'objects'      => [ 'id' => 'term' ],
                'description'  => 'Edit a Thrive Apprentice course\'s title, description, excerpt or status. Undo: rollback-operation',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'id'          => $id,
                        'title'       => [ 'type' => 'string', 'minLength' => 1 ],
                        'description' => [ 'type' => 'string' ],
                        'excerpt'     => [ 'type' => 'string' ],
                        'status'      => $status,
                    ],
                    'required'   => [ 'id' ],
                ],
                'validate'     => fn (array $args): ?array => $this->update_course_refusal($args),
                'snapshot'     => static fn (array $args): array => [ 'object_type' => 'term', 'object_id' => Snapshot::term_key(self::TAXONOMY, (string) get_term_field('slug', (int) $args['id'], self::TAXONOMY)) ],
                'handler'      => fn (array $args): array => $this->update_course($args),
            ],
            "{$p}-create-lesson"          => $write + [
                'objects'           => [ 'course_id' => 'term', 'parent_id' => [ 'type' => 'post', 'own_type' => true ] ],
                'self_snapshotting' => true,
                'description'       => 'Add a lesson to course_id under parent_id (a chapter, or a module without chapters; omit for a course without modules or chapters) at position (0-based; default last); status defaults to draft, and a published lesson publishes its parents. Undo: rollback-session',
                'input_schema'      => [
                    'type'       => 'object',
                    'properties' => [
                        'course_id' => $id,
                        'parent_id' => $id,
                        'title'     => [ 'type' => 'string', 'minLength' => 1 ],
                        'content'   => [ 'type' => 'string' ],
                        'excerpt'   => [ 'type' => 'string' ],
                        'status'    => $status,
                        'position'  => [ 'type' => 'integer', 'minimum' => 0 ],
                    ],
                    'required'   => [ 'course_id', 'title' ],
                ],
                'validate'          => fn (array $args): ?array => $this->create_lesson_refusal($args),
                'handler'           => fn (array $args, array $context): array => $this->create_lesson($args, $context),
            ],
            "{$p}-update-lesson"          => $write + [
                'objects'           => [ 'id' => [ 'type' => 'post', 'own_type' => true ] ],
                'self_snapshotting' => true,
                'description'       => 'Edit title, content or excerpt of a Thrive Apprentice lesson, chapter or module, or a lesson\'s status (a module or chapter follows its lessons). Undo: rollback-session',
                'input_schema'      => [
                    'type'       => 'object',
                    'properties' => [
                        'id'      => $id,
                        'title'   => [ 'type' => 'string', 'minLength' => 1 ],
                        'content' => [ 'type' => 'string' ],
                        'excerpt' => [ 'type' => 'string' ],
                        'status'  => $status,
                    ],
                    'required'   => [ 'id' ],
                ],
                'validate'          => fn (array $args): ?array => $this->update_lesson_refusal($args),
                'handler'           => fn (array $args, array $context): array => $this->update_lesson($args, $context),
            ],
            "{$p}-reorder-course-content" => $write + [
                'objects'           => [ 'course_id' => 'term', 'parent_id' => [ 'type' => 'post', 'own_type' => true ] ],
                'self_snapshotting' => true,
                'description'       => 'Reorder the children of parent_id (a module or chapter; omit for the course\'s top level): order lists every child id in the new order. Undo: rollback-session',
                'input_schema'      => [
                    'type'       => 'object',
                    'properties' => [
                        'course_id' => $id,
                        'parent_id' => $id,
                        'order'     => [ 'type' => 'array', 'items' => $id, 'minItems' => 1, 'uniqueItems' => true ],
                    ],
                    'required'   => [ 'course_id', 'order' ],
                ],
                'validate'          => fn (array $args): ?array => $this->reorder_refusal($args),
                'handler'           => fn (array $args, array $context): array => $this->reorder($args, $context),
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Storage
    // ------------------------------------------------------------------

    /** The course term, or null. */
    private static function course_term(int $id): ?\WP_Term
    {
        $term = $id > 0 ? get_term($id, self::TAXONOMY) : null;
        return $term instanceof \WP_Term ? $term : null;
    }

    /** The order meta key of a post type. */
    private static function order_key(string $type): string
    {
        return in_array($type, [ 'tva_lesson', 'tva_assessment' ], true) ? 'tva_lesson_order' : $type . '_order';
    }

    private static function stored_order(int $id): string
    {
        return (string) get_post_meta($id, self::order_key((string) get_post_type($id)), true);
    }

    /** The kind of a live Thrive Apprentice post filed under a course, or null. */
    private function kind_of(int $id): ?string
    {
        $post = $id > 0 ? get_post($id) : null;
        if (! $post instanceof \WP_Post || ! isset(self::KINDS[ $post->post_type ]) || in_array($post->post_status, [ 'trash', 'auto-draft' ], true)) {
            return null;
        }
        return $this->course_of($id) > 0 ? self::KINDS[ $post->post_type ] : null;
    }

    /** The course a post is filed under, or 0. */
    private function course_of(int $id): int
    {
        $terms = wp_get_object_terms($id, self::TAXONOMY, [ 'fields' => 'ids' ]);
        return is_array($terms) && [] !== $terms ? (int) $terms[0] : 0;
    }

    /**
     * The children of $parent (0: the course's top level) in Thrive
     * Apprentice's order: modules, then chapters, then lessons and
     * assessments, each group sorted by its order meta. A read keeps to the
     * posts the caller may read; the write path ($every_row) takes them all.
     *
     * @return \WP_Post[]
     */
    private function children(int $course, int $parent, bool $every_row): array
    {
        $groups = [ [ 'tva_module' ], [ 'tva_chapter' ], [ 'tva_lesson', 'tva_assessment' ] ];
        if ($parent > 0) {
            $type   = (string) get_post_type($parent);
            $groups = 'tva_module' === $type ? array_slice($groups, 1) : ('tva_chapter' === $type ? [ $groups[2] ] : []);
        }

        $out = [];
        foreach ($groups as $types) {
            $args = [
                'post_type'      => $types,
                'post_parent'    => $parent,
                'tax_query'      => [ [ 'taxonomy' => self::TAXONOMY, 'field' => 'term_id', 'terms' => [ $course ] ] ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- the course is a term; Thrive Apprentice queries its tree the same way.
                'meta_key'       => self::order_key($types[0]), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- ordering by the plugin's own order meta.
                'orderby'        => [ 'meta_value_num' => 'ASC', 'ID' => 'ASC' ],
                'posts_per_page' => -1,
                'no_found_rows'  => true,
            ];
            $posts = $every_row ? get_posts([ 'post_status' => self::ROW_STATUSES ] + $args) : Readable_Posts::get_posts($args);
            $out   = array_merge($out, $posts);
        }
        return $out;
    }

    /** @return int[] */
    private function child_ids(int $course, int $parent): array
    {
        return array_map(static fn (\WP_Post $p): int => (int) $p->ID, $this->children($course, $parent, true));
    }

    /** A free course slug for $title (the term snapshot is keyed by it before the insert). */
    private static function free_slug(string $title): string
    {
        $base = sanitize_title($title);
        $base = '' !== $base ? $base : 'course';
        $slug = $base;
        for ($n = 2; get_term_by('slug', $slug, self::TAXONOMY) instanceof \WP_Term; ++$n) {
            $slug = $base . '-' . $n;
        }
        return $slug;
    }

    // ------------------------------------------------------------------
    // Reads
    // ------------------------------------------------------------------

    /** A course as returned to the caller. */
    private function course_node(\WP_Term $term): array
    {
        $description = get_term_meta($term->term_id, 'tva_description', true);
        return [
            'id'          => (int) $term->term_id,
            'title'       => (string) $term->name,
            'slug'        => (string) $term->slug,
            'status'      => (string) get_term_meta($term->term_id, 'tva_status', true),
            'description' => is_string($description) && '' !== $description ? $description : (string) $term->description,
            'excerpt'     => (string) get_term_meta($term->term_id, 'tva_excerpt', true),
        ];
    }

    /** A post node as returned to the caller. */
    private static function node(\WP_Post $post): array
    {
        $node = [
            'id'     => (int) $post->ID,
            'kind'   => self::KINDS[ $post->post_type ] ?? $post->post_type,
            'title'  => (string) $post->post_title,
            'status' => (string) $post->post_status,
            'order'  => self::stored_order((int) $post->ID),
        ];
        if ('tva_lesson' === $post->post_type) {
            $node['lesson_type'] = (string) get_post_meta($post->ID, 'tva_lesson_type', true);
        }
        return $node;
    }

    /** The readable tree under $parent, adding to $counts. */
    private function tree(int $course, int $parent, array &$counts): array
    {
        $items = [];
        foreach ($this->children($course, $parent, false) as $post) {
            $node = self::node($post);
            ++$counts[ $node['kind'] . 's' ];
            if (in_array($post->post_type, [ 'tva_module', 'tva_chapter' ], true)) {
                $node['items'] = $this->tree($course, (int) $post->ID, $counts);
            }
            $items[] = $node;
        }
        return $items;
    }

    private function get_course(int $id): array
    {
        $counts = [ 'modules' => 0, 'chapters' => 0, 'lessons' => 0, 'assessments' => 0 ];
        $items  = $this->tree($id, 0, $counts);
        return [ 'course' => $this->course_node(self::course_term($id)), 'items' => $items, 'counts' => $counts ];
    }

    /** Whether the caller may see a course: published, or one they may edit. */
    private static function visible(\WP_Term $term): bool
    {
        return 'publish' === get_term_meta($term->term_id, 'tva_status', true) || current_user_can('edit_term', $term->term_id);
    }

    private function list_courses(array $args): array
    {
        $per_page = max(1, min(self::MAX_PER_PAGE, (int) ($args['per_page'] ?? 20)));
        $page     = max(1, (int) ($args['page'] ?? 1));
        $terms    = get_terms([ 'taxonomy' => self::TAXONOMY, 'hide_empty' => false, 'search' => (string) ($args['search'] ?? '') ]);
        $terms    = is_array($terms) ? $terms : [];

        $terms = array_values(array_filter($terms, static function ($term) use ($args): bool {
            if (! $term instanceof \WP_Term || ! self::visible($term)) {
                return false;
            }
            return ! isset($args['status']) || get_term_meta($term->term_id, 'tva_status', true) === $args['status'];
        }));
        // Thrive Apprentice lists courses by tva_order, highest first.
        usort($terms, static fn (\WP_Term $a, \WP_Term $b): int => [ (int) get_term_meta($b->term_id, 'tva_order', true), $b->term_id ] <=> [ (int) get_term_meta($a->term_id, 'tva_order', true), $a->term_id ]);

        $rows = [];
        foreach (array_slice($terms, ($page - 1) * $per_page, $per_page) as $term) {
            $counts = [ 'modules' => 0, 'chapters' => 0, 'lessons' => 0, 'assessments' => 0 ];
            $this->tree((int) $term->term_id, 0, $counts);
            $node   = $this->course_node($term);
            unset($node['description'], $node['excerpt']);
            $rows[] = $node + $counts;
        }

        return [ 'courses' => $rows, 'total' => count($terms), 'page' => $page, 'per_page' => $per_page ];
    }

    // ------------------------------------------------------------------
    // Refusals (run before any snapshot)
    // ------------------------------------------------------------------

    private static function refusal(string $code, string $message, array $data = []): array
    {
        return [ 'code' => $code, 'message' => $message, 'data' => $data ];
    }

    /** Null when $id is a course the caller may read (or, with $write, edit). */
    private function course_refusal(int $id, bool $write): ?array
    {
        $term = self::course_term($id);
        if (null === $term) {
            return self::refusal('course_not_found', sprintf('%d is not a Thrive Apprentice course.', $id), [ 'id' => $id ]);
        }
        if (($write || ! self::visible($term)) && ! current_user_can('edit_term', $id)) {
            return self::refusal('operation_denied', sprintf('You are not allowed to %s course %d.', $write ? 'edit' : 'read', $id), [ 'reason' => 'capability', 'id' => $id ]);
        }
        return null;
    }

    /** Null when the caller may edit every post in $ids. */
    private static function edit_refusal(array $ids): ?array
    {
        foreach ($ids as $id) {
            if (! current_user_can('edit_post', (int) $id)) {
                return self::refusal('operation_denied', sprintf('You are not allowed to edit post %d.', (int) $id), [ 'reason' => 'capability', 'id' => (int) $id ]);
            }
        }
        return null;
    }

    /** Null when the caller may publish lessons, or $status does not publish. */
    private static function publish_refusal(string $status): ?array
    {
        $type = get_post_type_object('tva_lesson');
        if ('publish' === $status && $type instanceof \WP_Post_Type && ! current_user_can($type->cap->publish_posts)) {
            return self::refusal('operation_denied', sprintf('Publishing needs the "%s" capability.', $type->cap->publish_posts), [ 'reason' => 'capability' ]);
        }
        return null;
    }

    private function create_course_refusal(string $title): ?array
    {
        $taxonomy = get_taxonomy(self::TAXONOMY);
        if (! $taxonomy instanceof \WP_Taxonomy) {
            return self::refusal('taxonomy_missing', 'Thrive Apprentice has not registered its course taxonomy.');
        }
        if (! current_user_can($taxonomy->cap->edit_terms)) {
            return self::refusal('operation_denied', sprintf('Creating a course needs the "%s" capability.', $taxonomy->cap->edit_terms), [ 'reason' => 'capability' ]);
        }
        $existing = term_exists(trim($title), self::TAXONOMY, 0);
        if (is_array($existing)) {
            return self::refusal('course_exists', sprintf('A Thrive Apprentice course named "%s" already exists (%d).', trim($title), (int) $existing['term_id']), [ 'id' => (int) $existing['term_id'] ]);
        }
        return null;
    }

    private function update_course_refusal(array $args): ?array
    {
        $refusal = $this->course_refusal((int) $args['id'], true);
        if (null === $refusal && [] === array_intersect_key($args, array_flip([ 'title', 'description', 'excerpt', 'status' ]))) {
            $refusal = self::refusal('nothing_to_update', 'Pass at least one of title, description, excerpt or status.');
        }
        return $refusal;
    }

    /** Refusal for adding a lesson under $args' parent, or null. */
    private function create_lesson_refusal(array $args): ?array
    {
        $course  = (int) $args['course_id'];
        $parent  = (int) ($args['parent_id'] ?? 0);
        $refusal = $this->course_refusal($course, true);
        if (null !== $refusal) {
            return $refusal;
        }
        if ($parent > 0) {
            $kind = $this->kind_of($parent);
            if (! in_array($kind, [ 'module', 'chapter' ], true) || $this->course_of($parent) !== $course) {
                return self::refusal('invalid_parent', sprintf('%d is not a module or chapter of course %d.', $parent, $course), [ 'parent_id' => $parent ]);
            }
        }
        foreach ($this->children($course, $parent, true) as $child) {
            if (! in_array($child->post_type, [ 'tva_lesson', 'tva_assessment' ], true)) {
                return self::refusal('invalid_parent', $parent > 0
                    ? sprintf('Module %d holds chapters; add the lesson to one of them.', $parent)
                    : sprintf('Course %d is organised in modules or chapters; pass the one to add the lesson to as parent_id.', $course), [ 'parent_id' => $parent ]);
            }
        }
        $type = get_post_type_object('tva_lesson');
        if (! $type instanceof \WP_Post_Type) {
            return self::refusal('post_type_missing', 'Thrive Apprentice has not registered its lesson post type.');
        }
        if (! current_user_can($type->cap->create_posts)) {
            return self::refusal('operation_denied', sprintf('Creating a lesson needs the "%s" capability.', $type->cap->create_posts), [ 'reason' => 'capability' ]);
        }
        return self::publish_refusal((string) ($args['status'] ?? 'draft'))
            ?? self::edit_refusal(array_merge($this->child_ids($course, $parent), $this->ancestors($parent)));
    }

    private function update_lesson_refusal(array $args): ?array
    {
        $id   = (int) $args['id'];
        $kind = $this->kind_of($id);
        if (! in_array($kind, [ 'lesson', 'chapter', 'module' ], true)) {
            return self::refusal('item_not_found', sprintf('%d is not a lesson, chapter or module of a Thrive Apprentice course.', $id), [ 'id' => $id ]);
        }
        if ([] === array_intersect_key($args, array_flip([ 'title', 'content', 'excerpt', 'status' ]))) {
            return self::refusal('nothing_to_update', 'Pass at least one of title, content, excerpt or status.');
        }
        if (isset($args['status']) && 'lesson' !== $kind) {
            return self::refusal('status_derived', sprintf('The %s %d is published while one of its lessons is; change the lessons\' status instead.', $kind, $id), [ 'id' => $id ]);
        }
        return self::edit_refusal([ $id ])
            ?? self::publish_refusal((string) ($args['status'] ?? ''))
            ?? (isset($args['status']) ? self::edit_refusal($this->ancestors((int) wp_get_post_parent_id($id))) : null);
    }

    /**
     * What a reorder changes: post id => new order string, for the listed
     * children and everything below them, only where it differs; or a
     * refusal. Shared by validate (before any snapshot) and the handler.
     *
     * @return array{refusal?: array, changes?: array<int, string>}
     */
    private function reorder_plan(array $args): array
    {
        $course  = (int) $args['course_id'];
        $parent  = (int) ($args['parent_id'] ?? 0);
        $refusal = $this->course_refusal($course, true);
        if (null !== $refusal) {
            return [ 'refusal' => $refusal ];
        }
        if ($parent > 0 && (! in_array($this->kind_of($parent), [ 'module', 'chapter' ], true) || $this->course_of($parent) !== $course)) {
            return [ 'refusal' => self::refusal('invalid_parent', sprintf('%d is not a module or chapter of course %d.', $parent, $course), [ 'parent_id' => $parent ]) ];
        }
        $current = $this->child_ids($course, $parent);
        $order   = array_map('intval', (array) $args['order']);
        $sorted  = $order;
        sort($sorted);
        $have = $current;
        sort($have);
        if ($sorted !== $have) {
            return [ 'refusal' => self::refusal('order_mismatch', 'order must list every child of the parent exactly once.', [ 'children' => $current ]) ];
        }

        $prefix  = $parent > 0 ? self::stored_order($parent) : '';
        $changes = [];
        $this->layout($course, $order, $prefix, $changes);
        $refusal = self::edit_refusal(array_keys($changes));
        return null !== $refusal ? [ 'refusal' => $refusal ] : [ 'changes' => $changes ];
    }

    /** Collect the order strings $ids (and their descendants) get under $prefix, where they change. */
    private function layout(int $course, array $ids, string $prefix, array &$changes): void
    {
        foreach (array_values($ids) as $i => $id) {
            $want = $prefix . $i;
            if (self::stored_order($id) !== $want) {
                $changes[ $id ] = $want;
            }
            if (in_array(get_post_type($id), [ 'tva_module', 'tva_chapter' ], true)) {
                $this->layout($course, $this->child_ids($course, $id), $want, $changes);
            }
        }
    }

    private function reorder_refusal(array $args): ?array
    {
        $plan = $this->reorder_plan($args);
        return $plan['refusal'] ?? null;
    }

    // ------------------------------------------------------------------
    // Writes
    // ------------------------------------------------------------------

    /** Run $write on post $id under its own post snapshot; returns the operation id. */
    private static function snapshotted(int $id, array $context, array $args, callable $write): string
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

    private static function store_order(int $id, string $order): void
    {
        update_post_meta($id, self::order_key((string) get_post_type($id)), $order);
    }

    /** $id and the module above it, when they are a chapter or module. @return int[] */
    private function ancestors(int $id): array
    {
        $out = [];
        while ($id > 0 && in_array(get_post_type($id), [ 'tva_module', 'tva_chapter' ], true)) {
            $out[] = $id;
            $id    = (int) wp_get_post_parent_id($id);
        }
        return $out;
    }

    /**
     * Thrive Apprentice's status rule (TVA_Manager::review_status()): a
     * chapter or module is published exactly when one of its children is.
     * Applied up from $parent, each change under its own post snapshot.
     *
     * @return string[] operation ids
     */
    private function review_status(int $course, int $parent, array $context, array $args): array
    {
        $ids = [];
        foreach ($this->ancestors($parent) as $id) {
            $want = 'draft';
            foreach ($this->children($course, $id, true) as $child) {
                if ('publish' === $child->post_status) {
                    $want = 'publish';
                    break;
                }
            }
            if (get_post_status($id) !== $want) {
                $ids[] = self::snapshotted($id, $context, $args, static function () use ($id, $want): void {
                    $out = Save_Filters::set_post_status($id, $want, true);
                    if (is_wp_error($out)) {
                        throw new Operation_Refused('update_failed', $out->get_error_message());
                    }
                });
            }
        }
        return $ids;
    }

    /** Bump Thrive Apprentice's course cache version, so its course lists reload. */
    private static function clear_course_cache(): void
    {
        if (class_exists('TVA_Course_V2') && method_exists('TVA_Course_V2', 'clear_courses_cache')) {
            \TVA_Course_V2::clear_courses_cache();
        }
    }

    /** Create the course term under the slug the dispatcher captured. */
    private function create_course(array $args, array $context): array
    {
        $slug  = Snapshot::split_term_key((string) ($context['target']['object_id'] ?? ''))[1];
        $title = trim((string) $args['title']);
        $made  = wp_insert_term(wp_slash($title), self::TAXONOMY, [ 'slug' => $slug, 'description' => wp_slash((string) ($args['description'] ?? '')) ]);
        if (is_wp_error($made)) {
            throw new Operation_Refused('insert_failed', $made->get_error_message());
        }
        $id = (int) $made['term_id'];

        $top = 0;
        foreach ((array) get_terms([ 'taxonomy' => self::TAXONOMY, 'hide_empty' => false, 'fields' => 'ids', 'exclude' => [ $id ] ]) as $other) {
            $top = max($top, (int) get_term_meta((int) $other, 'tva_order', true));
        }
        // The term meta TVA_Course_V2::_insert() writes for a new course.
        $meta = [
            'tva_status'                    => (string) ($args['status'] ?? 'draft'),
            'tva_order'                     => $top + 1,
            'tva_description'               => (string) ($args['description'] ?? ''),
            'tva_excerpt'                   => (string) ($args['excerpt'] ?? ''),
            'tva_message'                   => '',
            'tva_excluded'                  => 0,
            'tva_video_status'              => false,
            'tva_last_edit_date'            => time(),
            'tva_editable_module'           => true,
            'tva_send_course_welcome_email' => true,
        ];
        foreach ($meta as $key => $value) {
            update_term_meta($id, $key, is_string($value) ? wp_slash($value) : $value);
        }
        self::clear_course_cache();

        return [ 'course' => $this->course_node(get_term($id, self::TAXONOMY)) ];
    }

    /** Edit a course (the dispatcher took its term snapshot first). */
    private function update_course(array $args): array
    {
        $id     = (int) $args['id'];
        $fields = [];
        if (isset($args['title'])) {
            $fields['name'] = wp_slash((string) $args['title']);
        }
        if (isset($args['description'])) {
            $fields['description'] = wp_slash((string) $args['description']);
            update_term_meta($id, 'tva_description', wp_slash((string) $args['description']));
        }
        if ([] !== $fields) {
            $out = wp_update_term($id, self::TAXONOMY, $fields);
            if (is_wp_error($out)) {
                throw new Operation_Refused('update_failed', $out->get_error_message());
            }
        }
        if (isset($args['excerpt'])) {
            update_term_meta($id, 'tva_excerpt', wp_slash((string) $args['excerpt']));
        }
        if (isset($args['status'])) {
            update_term_meta($id, 'tva_status', (string) $args['status']);
        }
        update_term_meta($id, 'tva_last_edit_date', time());
        clean_term_cache([ $id ], self::TAXONOMY);
        self::clear_course_cache();

        return [ 'course' => $this->course_node(get_term($id, self::TAXONOMY)) ];
    }

    /** Add a lesson at a position, renumbering the siblings after it. */
    private function create_lesson(array $args, array $context): array
    {
        $course   = (int) $args['course_id'];
        $parent   = (int) ($args['parent_id'] ?? 0);
        $siblings = $this->child_ids($course, $parent);
        $pos      = min((int) ($args['position'] ?? count($siblings)), count($siblings));
        $prefix   = $parent > 0 ? self::stored_order($parent) : '';

        $created = wp_insert_post([
            'post_type'    => 'tva_lesson',
            'post_title'   => wp_slash((string) $args['title']),
            'post_content' => wp_slash((string) ($args['content'] ?? '')),
            'post_excerpt' => wp_slash((string) ($args['excerpt'] ?? '')),
            'post_status'  => (string) ($args['status'] ?? 'draft'),
            'post_author'  => get_current_user_id(),
            'post_parent'  => $parent,
            'meta_input'   => [
                'tva_lesson_order'                 => $prefix . $pos,
                'tva_lesson_type'                  => 'text',
                'tva_video_progress_mark_complete' => [ 'video_progress' => false ],
            ],
        ], true);
        if (is_wp_error($created) || 0 === (int) $created) {
            throw new Operation_Refused('insert_failed', 'Thrive Apprentice could not create the lesson.');
        }
        $created = (int) $created;
        wp_set_object_terms($created, [ $course ], self::TAXONOMY);
        $ids = [ Post_Creation_Snapshot::record((string) $context['tool_name'], [ $created ], [ 'operation' => $context['operation'], 'args' => $args ], (string) $context['session_id']) ];

        $list = $siblings;
        array_splice($list, $pos, 0, [ $created ]);
        foreach ($list as $i => $id) {
            $want = $prefix . $i;
            if ($id !== $created && self::stored_order($id) !== $want) {
                $ids[] = self::snapshotted($id, $context, $args, static fn () => self::store_order($id, $want));
            }
        }
        $ids = array_merge($ids, $this->review_status($course, $parent, $context, $args));

        return [ 'result' => [ 'lesson' => self::node(get_post($created)) ], 'operation_ids' => $ids ];
    }

    /** Edit one lesson, chapter or module; a status change reviews its parents. */
    private function update_lesson(array $args, array $context): array
    {
        $id     = (int) $args['id'];
        $fields = [ 'ID' => $id ];
        foreach ([ 'title' => 'post_title', 'content' => 'post_content', 'excerpt' => 'post_excerpt', 'status' => 'post_status' ] as $arg => $column) {
            if (array_key_exists($arg, $args)) {
                $fields[ $column ] = wp_slash((string) $args[ $arg ]);
            }
        }
        $ids = [
            self::snapshotted($id, $context, $args, static function () use ($fields): void {
                $out = Save_Filters::update_post($fields, true);
                if (is_wp_error($out)) {
                    throw new Operation_Refused('update_failed', $out->get_error_message());
                }
            }),
        ];
        clean_post_cache($id);
        if (isset($args['status'])) {
            $ids = array_merge($ids, $this->review_status($this->course_of($id), (int) wp_get_post_parent_id($id), $context, $args));
        }

        return [ 'result' => [ 'item' => self::node(get_post($id)) ], 'operation_ids' => $ids ];
    }

    /** Write the planned order strings, each post under its own snapshot. */
    private function reorder(array $args, array $context): array
    {
        $plan = $this->reorder_plan($args);
        if (isset($plan['refusal'])) {
            throw new Operation_Refused((string) $plan['refusal']['code'], (string) $plan['refusal']['message'], (array) $plan['refusal']['data']);
        }
        $ids = [];
        foreach ($plan['changes'] as $id => $want) {
            $ids[] = self::snapshotted((int) $id, $context, $args, static fn () => self::store_order((int) $id, (string) $want));
        }

        return [ 'result' => [ 'order' => array_map('intval', (array) $args['order']), 'changed' => count($ids) ], 'operation_ids' => $ids ];
    }
    // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
