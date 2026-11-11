<?php

namespace WPMCP\Integrations;

use WPMCP\MCP\Registrar;
use WPMCP\Safety\Post_Creation_Snapshot;
use WPMCP\Safety\Save_Filters;
use WPMCP\Tools\Content\Readable_Posts;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * BetterDocs knowledge base docs (issue #708) on the plugin-data pair,
 * paid-tier, so it adds no top-level tools.
 *
 * BetterDocs 4.9 keeps a doc as a `docs` post (capability type doc/docs, so
 * edit_docs, publish_docs and the rest) filed under the hierarchical
 * doc_category taxonomy and the flat doc_tag taxonomy; BetterDocs Pro adds
 * a knowledge_base taxonomy for several knowledge bases. Each category's
 * hand-set doc order is its _docs_order term meta, which BetterDocs keeps
 * current itself (its save_post_docs and set_object_terms hooks put a doc
 * into the order of every category it joins), and a doc's view count is its
 * _betterdocs_meta_views meta.
 *
 * Reads: list-docs, get-doc and list-categories, kept to the docs the
 * caller may read (Readable_Posts), so drafts and private docs of others
 * stay hidden. Docs are content, like posts, so the writes are on by
 * default:
 *  - create-doc inserts a doc (draft unless status says otherwise) with its
 *    categories and tags, recorded for rollback (Post_Creation_Snapshot:
 *    the undo moves it to the trash);
 *  - update-doc changes a doc's title, content, excerpt, status, categories
 *    or tags under a post snapshot (fields, meta and terms), so
 *    rollback-operation puts it back.
 * Both go through wp_insert_post and wp_update_post, so BetterDocs's own
 * hooks keep its category orders current. Each takes the docs post type's
 * own capabilities (edit_docs, publish_docs, edit_post on the doc).
 *
 * Presence is filterable (wpmcp_betterdocs_active, default: BETTERDOCS_VERSION
 * or BETTERDOCS_PLUGIN_FILE is defined).
 */
final class BetterDocs_Pack
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.

    public const POST_TYPE = 'docs';

    public const CATEGORY = 'doc_category';

    public const TAG = 'doc_tag';

    public const KNOWLEDGE_BASE = 'knowledge_base';

    public const MAX_PER_PAGE = 100;

    private const STATUSES = [ 'publish', 'draft', 'pending', 'private', 'future' ];

    private const WRITE_STATUSES = [ 'publish', 'draft', 'pending', 'private' ];

    public static function active(): bool
    {
        return Registrar::tier_permitted('pro') && self::loaded();
    }

    private static function loaded(): bool
    {
        return (bool) apply_filters('wpmcp_betterdocs_active', defined('BETTERDOCS_VERSION') || defined('BETTERDOCS_PLUGIN_FILE'));
    }

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        $id     = [ 'type' => 'integer', 'minimum' => 1 ];
        $paging = [
            'page'     => [ 'type' => 'integer', 'minimum' => 1 ],
            'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PER_PAGE ],
        ];
        $fields = [
            'title'      => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 500 ],
            'content'    => [ 'type' => 'string' ],
            'excerpt'    => [ 'type' => 'string' ],
            'status'     => [ 'type' => 'string', 'enum' => self::WRITE_STATUSES ],
            'categories' => [ 'type' => 'array', 'items' => $id, 'maxItems' => 50 ],
            'tags'       => [ 'type' => 'array', 'items' => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 200 ], 'maxItems' => 50 ],
        ];
        $base   = [
            'tier'     => 'pro',
            'requires' => static fn () => Ops_Status_Packs::presence(self::loaded(), 'betterdocs_inactive', 'BetterDocs'),
        ];
        $own    = static fn (string $access): array => [ 'id' => [ 'type' => 'post', 'own_type' => true, 'access' => $access ] ];

        return [
            'betterdocs-list-docs'       => $base + [
                'mode'         => 'read',
                'description'  => 'List BetterDocs docs newest first: id, title, status, dates, author, categories, tags, knowledge bases, views and link. Filter by category (doc_category id, its subcategories included), tag (slug), status, search. Only docs you may read',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'category' => $id,
                        'tag'      => [ 'type' => 'string', 'minLength' => 1 ],
                        'status'   => [ 'type' => 'string', 'enum' => self::STATUSES ],
                        'search'   => [ 'type' => 'string', 'maxLength' => 200 ],
                    ] + $paging,
                ],
                'handler'      => static fn (array $args): array => self::list_docs($args),
            ],
            'betterdocs-get-doc'         => $base + [
                'mode'         => 'read',
                'objects'      => $own('read'),
                'description'  => 'Read one BetterDocs doc: title, content, excerpt, status, dates, author, categories, tags, knowledge bases, views and link',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => $id ], 'required' => [ 'id' ] ],
                'validate'     => static fn (array $args): ?array => self::doc_refusal((int) $args['id'], false),
                'handler'      => static fn (array $args): array => [ 'doc' => self::doc(get_post((int) $args['id']), true) ],
            ],
            'betterdocs-list-categories' => $base + [
                'mode'         => 'read',
                'description'  => 'List BetterDocs doc categories (id, name, slug, parent, doc count, BetterDocs order) and, with BetterDocs Pro, knowledge bases',
                'input_schema' => [ 'type' => 'object', 'properties' => (object) [] ],
                'handler'      => static fn (): array => self::list_categories(),
            ],
            'betterdocs-create-doc'      => $base + [
                'mode'              => 'write',
                'self_snapshotting' => true,
                'description'       => 'Create a BetterDocs doc (status defaults to draft) with title, content, excerpt, categories (doc_category ids) and tags (names, created when new). Undo: rollback-session trashes it',
                'input_schema'      => [ 'type' => 'object', 'properties' => $fields, 'required' => [ 'title' ] ],
                'validate'          => static fn (array $args): ?array => self::create_refusal((string) ($args['status'] ?? 'draft')) ?? self::category_refusal($args),
                'handler'           => static fn (array $args, array $context): array => self::create_doc($args, $context),
            ],
            'betterdocs-update-doc'      => $base + [
                'mode'         => 'write',
                'objects'      => $own('write'),
                'description'  => 'Change a BetterDocs doc\'s title, content, excerpt, status, categories or tags; only the args given change, and categories or tags replace the doc\'s set. Undo: rollback-operation',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => $id ] + $fields, 'required' => [ 'id' ], 'minProperties' => 2 ],
                'validate'     => static fn (array $args): ?array => self::update_refusal($args),
                'snapshot'     => static fn (array $args): array => [ 'object_type' => 'post', 'object_id' => (int) $args['id'] ],
                'handler'      => static fn (array $args): array => self::update_doc($args),
            ],
        ];
    }

    // ------------------------------------------------------------ reads

    /** @return array<int, array{id: int, name: string}> */
    private static function terms(int $id, string $taxonomy): array
    {
        if (! taxonomy_exists($taxonomy)) {
            return [];
        }
        $terms = wp_get_post_terms($id, $taxonomy);
        if (! is_array($terms)) {
            return [];
        }
        return array_map(static fn (\WP_Term $t): array => [ 'id' => (int) $t->term_id, 'name' => (string) $t->name, 'slug' => (string) $t->slug ], $terms);
    }

    private static function doc(\WP_Post $post, bool $full): array
    {
        $doc = [
            'id'              => (int) $post->ID,
            'title'           => (string) $post->post_title,
            'status'          => (string) $post->post_status,
            'date'            => (string) $post->post_date,
            'modified'        => (string) $post->post_modified,
            'author_id'       => (int) $post->post_author,
            'categories'      => self::terms((int) $post->ID, self::CATEGORY),
            'tags'            => self::terms((int) $post->ID, self::TAG),
            'knowledge_bases' => self::terms((int) $post->ID, self::KNOWLEDGE_BASE),
            'views'           => (int) get_post_meta($post->ID, '_betterdocs_meta_views', true),
            'link'            => (string) get_permalink($post),
        ];
        if ($full) {
            $doc['content'] = (string) $post->post_content;
            $doc['excerpt'] = (string) $post->post_excerpt;
        }
        return $doc;
    }

    private static function list_docs(array $args): array
    {
        $query = [
            'post_type'      => self::POST_TYPE,
            'post_status'    => isset($args['status']) ? [ (string) $args['status'] ] : self::STATUSES,
            'posts_per_page' => (int) ($args['per_page'] ?? 20),
            'paged'          => (int) ($args['page'] ?? 1),
            'orderby'        => 'date',
            'order'          => 'DESC',
        ];
        $tax = [];
        if (isset($args['category'])) {
            $tax[] = [ 'taxonomy' => self::CATEGORY, 'field' => 'term_id', 'terms' => [ (int) $args['category'] ], 'include_children' => true ];
        }
        if (isset($args['tag'])) {
            $tax[] = [ 'taxonomy' => self::TAG, 'field' => 'slug', 'terms' => [ (string) $args['tag'] ] ];
        }
        if ([] !== $tax) {
            $query['tax_query'] = $tax; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- filtering docs by their BetterDocs category or tag is the point of the op.
        }
        if (isset($args['search']) && '' !== trim((string) $args['search'])) {
            $query['s'] = (string) $args['search'];
        }
        $q = Readable_Posts::query($query);
        return [ 'docs' => array_map(static fn (\WP_Post $p): array => self::doc($p, false), $q->posts), 'total' => (int) $q->found_posts ];
    }

    private static function list_categories(): array
    {
        $out = [ 'categories' => [], 'knowledge_bases' => [] ];
        foreach ([ self::CATEGORY => 'categories', self::KNOWLEDGE_BASE => 'knowledge_bases' ] as $taxonomy => $key) {
            if (! taxonomy_exists($taxonomy)) {
                continue;
            }
            $terms = get_terms([ 'taxonomy' => $taxonomy, 'hide_empty' => false ]);
            foreach (is_array($terms) ? $terms : [] as $term) {
                $row = [ 'id' => (int) $term->term_id, 'name' => (string) $term->name, 'slug' => (string) $term->slug, 'parent' => (int) $term->parent, 'count' => (int) $term->count ];
                if (self::CATEGORY === $taxonomy) {
                    $row['order'] = (int) get_term_meta((int) $term->term_id, 'doc_category_order', true);
                }
                $out[ $key ][] = $row;
            }
        }
        usort($out['categories'], static fn (array $a, array $b): int => [ $a['order'], $a['name'] ] <=> [ $b['order'], $b['name'] ]);
        return $out;
    }

    // ------------------------------------------------------------ refusals

    private static function refusal(string $code, string $message, array $data = []): array
    {
        return [ 'code' => $code, 'message' => $message, 'data' => $data ];
    }

    private static function doc_refusal(int $id, bool $write): ?array
    {
        $post = get_post($id);
        if (! $post instanceof \WP_Post || self::POST_TYPE !== $post->post_type || 'trash' === $post->post_status) {
            return self::refusal('doc_not_found', sprintf('%d is not a BetterDocs doc.', $id), [ 'id' => $id ]);
        }
        if (! current_user_can($write ? 'edit_post' : 'read_post', $id)) {
            return self::refusal('operation_denied', sprintf('You are not allowed to %s doc %d.', $write ? 'edit' : 'read', $id), [ 'reason' => 'capability', 'id' => $id ]);
        }
        return null;
    }

    private static function create_refusal(string $status): ?array
    {
        $object = get_post_type_object(self::POST_TYPE);
        if (! $object instanceof \WP_Post_Type) {
            return self::refusal('post_type_missing', 'BetterDocs has not registered its docs post type.');
        }
        $caps = [ $object->cap->create_posts ];
        if (in_array($status, [ 'publish', 'private' ], true)) {
            $caps[] = $object->cap->publish_posts;
        }
        foreach ($caps as $cap) {
            if (! current_user_can($cap)) {
                return self::refusal('operation_denied', sprintf('This needs the %s capability.', $cap), [ 'reason' => 'capability' ]);
            }
        }
        return null;
    }

    private static function category_refusal(array $args): ?array
    {
        $missing = [];
        foreach ((array) ($args['categories'] ?? []) as $term_id) {
            $term = get_term((int) $term_id, self::CATEGORY);
            if (! $term instanceof \WP_Term) {
                $missing[] = (int) $term_id;
            }
        }
        if ([] !== $missing) {
            return self::refusal('unknown_category', sprintf('Not a BetterDocs doc category: %s.', implode(', ', $missing)), [ 'unknown' => $missing ]);
        }
        if (isset($args['tags']) && [] !== (array) $args['tags'] && taxonomy_exists(self::TAG)) {
            $tax = get_taxonomy(self::TAG);
            if ($tax && ! current_user_can($tax->cap->assign_terms)) {
                return self::refusal('operation_denied', 'You may not assign doc tags.', [ 'reason' => 'capability' ]);
            }
        }
        return null;
    }

    private static function update_refusal(array $args): ?array
    {
        $id      = (int) $args['id'];
        $refused = self::doc_refusal($id, true) ?? self::category_refusal($args);
        if (null !== $refused) {
            return $refused;
        }
        $status = (string) ($args['status'] ?? '');
        if (in_array($status, [ 'publish', 'private' ], true) && $status !== get_post_status($id) && ! current_user_can((string) get_post_type_object(self::POST_TYPE)->cap->publish_posts)) {
            return self::refusal('operation_denied', 'Publishing a doc needs the publish_docs capability.', [ 'reason' => 'capability' ]);
        }
        return null;
    }

    // ------------------------------------------------------------ writes

    private static function set_terms(int $id, array $args): void
    {
        if (isset($args['categories'])) {
            $out = wp_set_object_terms($id, array_map('intval', (array) $args['categories']), self::CATEGORY);
            if (is_wp_error($out)) {
                throw new Operation_Refused('update_failed', $out->get_error_message());
            }
        }
        if (isset($args['tags']) && taxonomy_exists(self::TAG)) {
            $out = wp_set_object_terms($id, array_map('strval', (array) $args['tags']), self::TAG);
            if (is_wp_error($out)) {
                throw new Operation_Refused('update_failed', $out->get_error_message());
            }
        }
    }

    private static function create_doc(array $args, array $context): array
    {
        $id = wp_insert_post([
            'post_type'    => self::POST_TYPE,
            'post_title'   => wp_slash((string) $args['title']),
            'post_content' => wp_slash((string) ($args['content'] ?? '')),
            'post_excerpt' => wp_slash((string) ($args['excerpt'] ?? '')),
            'post_status'  => (string) ($args['status'] ?? 'draft'),
            'post_author'  => get_current_user_id(),
        ], true);
        if (is_wp_error($id) || 0 === (int) $id) {
            throw new Operation_Refused('insert_failed', is_wp_error($id) ? $id->get_error_message() : 'BetterDocs could not create the doc.');
        }
        $id = (int) $id;
        $op = Post_Creation_Snapshot::record((string) $context['tool_name'], [ $id ], [ 'operation' => $context['operation'], 'args' => $args ], (string) $context['session_id']);
        self::set_terms($id, $args);
        clean_post_cache($id);
        return [ 'result' => [ 'doc' => self::doc(get_post($id), false) ], 'operation_ids' => [ $op ] ];
    }

    private static function update_doc(array $args): array
    {
        $id     = (int) $args['id'];
        $fields = [ 'ID' => $id ];
        foreach ([ 'title' => 'post_title', 'content' => 'post_content', 'excerpt' => 'post_excerpt', 'status' => 'post_status' ] as $arg => $column) {
            if (array_key_exists($arg, $args)) {
                $fields[ $column ] = wp_slash((string) $args[ $arg ]);
            }
        }
        if (count($fields) > 1) {
            $out = Save_Filters::update_post($fields, true);
            if (is_wp_error($out)) {
                throw new Operation_Refused('update_failed', $out->get_error_message());
            }
        }
        self::set_terms($id, $args);
        clean_post_cache($id);
        return [ 'doc' => self::doc(get_post($id), false) ];
    }
}
