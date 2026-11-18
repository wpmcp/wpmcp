<?php

namespace WPMCP\Integrations;

use WPMCP\Privacy\Masking;
use WPMCP\Safety\Post_Creation_Snapshot;
use WPMCP\Safety\Save_Filters;
use WPMCP\Tools\Content\Readable_Posts;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Shared pieces of the event calendar writes (issue #677): The Events
 * Calendar (tec pair) and Modern Events Calendar (mec pair) both keep an
 * event as a custom post plus postmeta, so creating one, editing its post
 * fields, checking the caller may publish it and checking the terms it is
 * filed under work the same way for both.
 *
 * A creation inserts the new post (every field is new input, so it is
 * filtered as a new post is) and records a Post_Creation_Snapshot right
 * after, so rollback-operation moves it to the trash. An edit of an
 * existing post goes through Save_Filters, never a whole-row re-save.
 */
final class Event_Calendar_Writes
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.

    /** Post statuses a write may set. */
    public const STATUSES = [ 'draft', 'pending', 'publish', 'private' ];

    /** @return array{code: string, message: string, data: array<string, mixed>} */
    public static function refusal(string $code, string $message, array $data = []): array
    {
        return [ 'code' => $code, 'message' => $message, 'data' => $data ];
    }

    /**
     * plugin_missing or plugin_inactive naming the plugin and the plugins
     * screen, for an op called while its plugin is not loaded.
     *
     * @param string[] $files Plugin files relative to the plugins directory.
     * @return true|array{code: string, message: string, data: array<string, mixed>}
     */
    public static function presence(bool $active, array $files, string $name, string $slug)
    {
        if ($active) {
            return true;
        }
        $installed = false;
        foreach ($files as $file) {
            $installed = $installed || (defined('WP_PLUGIN_DIR') && file_exists(WP_PLUGIN_DIR . '/' . $file));
        }
        $url = admin_url('plugins.php');

        return [
            'code'    => $installed ? 'plugin_inactive' : 'plugin_missing',
            'message' => sprintf(
                '%1$s is %2$s on this site. Install and activate %1$s (%3$s), then try again.',
                $name,
                $installed ? 'installed but not active' : 'not installed',
                $url
            ),
            'data'    => [ 'plugin' => $slug, 'plugins_url' => $url ],
        ];
    }

    /** A capability of a post type, or $fallback while the type is not registered. */
    public static function type_cap(string $post_type, string $cap, string $fallback = 'edit_posts'): string
    {
        $object = get_post_type_object($post_type);
        return $object && isset($object->cap->{$cap}) ? (string) $object->cap->{$cap} : $fallback;
    }

    /**
     * operation_denied when the status would make the post public (or
     * private) and the caller may not publish posts of this type.
     */
    public static function publish_refusal(string $post_type, ?string $status): ?array
    {
        if (null === $status || ! in_array($status, [ 'publish', 'private' ], true)) {
            return null;
        }
        $cap = self::type_cap($post_type, 'publish_posts', 'publish_posts');
        if (current_user_can($cap)) {
            return null;
        }
        return self::refusal('operation_denied', sprintf('Setting the status to %s needs the %s capability; leave status out to save a draft.', $status, $cap), [ 'reason' => 'capability', 'capability' => $cap ]);
    }

    /** The post when it is a live (not trashed) post of the type, else null. */
    public static function live_post(int $id, string $post_type): ?\WP_Post
    {
        $post = get_post($id);
        return $post instanceof \WP_Post && $post_type === $post->post_type && 'trash' !== $post->post_status ? $post : null;
    }

    /**
     * A refusal naming the first id that is not a live post of the type.
     *
     * @param int[] $ids
     */
    public static function posts_refusal(array $ids, string $post_type, string $code, string $label): ?array
    {
        foreach ($ids as $id) {
            if (null === self::live_post((int) $id, $post_type)) {
                return self::refusal($code, sprintf('%d is not a %s.', (int) $id, $label), [ 'id' => (int) $id ]);
            }
        }
        return null;
    }

    /**
     * A refusal naming the first id that is not a term of the taxonomy.
     *
     * @param int[] $ids
     */
    public static function terms_refusal(array $ids, string $taxonomy, string $code, string $label): ?array
    {
        foreach ($ids as $id) {
            $term = get_term((int) $id, $taxonomy);
            if (! $term instanceof \WP_Term) {
                return self::refusal($code, sprintf('%d is not a %s.', (int) $id, $label), [ 'id' => (int) $id ]);
            }
        }
        if ([] !== $ids) {
            $tax = get_taxonomy($taxonomy);
            $cap = $tax && isset($tax->cap->assign_terms) ? (string) $tax->cap->assign_terms : 'edit_posts';
            if (! current_user_can($cap)) {
                return self::refusal('operation_denied', sprintf('Filing an event under a %s needs the %s capability.', $label, $cap), [ 'reason' => 'capability', 'capability' => $cap ]);
            }
        }
        return null;
    }

    /**
     * Insert a new post, run $fill to write its meta and terms, and record
     * the creation, so rollback-operation trashes it. A failure removes the
     * half-made post again.
     *
     * @param array<string, mixed> $post    wp_insert_post() fields, unslashed.
     * @param array<string, mixed> $args    The op's args (for the ledger).
     * @param array<string, mixed> $context The dispatcher's self_snapshotting context.
     * @return array{0: int, 1: string} The new post id and its operation id.
     */
    public static function insert(array $post, callable $fill, array $args, array $context): array
    {
        $id = wp_insert_post(wp_slash($post + [ 'post_author' => get_current_user_id(), 'ping_status' => 'closed' ]), true);
        if (is_wp_error($id) || 0 === (int) $id) {
            throw new Operation_Refused('insert_failed', is_wp_error($id) ? $id->get_error_message() : 'The post could not be created.');
        }
        $id = (int) $id;
        try {
            $fill($id);
            $operation_id = Post_Creation_Snapshot::record((string) $context['tool_name'], [ $id ], [ 'operation' => $context['operation'], 'args' => $args ], (string) $context['session_id']);
        } catch (\Throwable $e) {
            wp_delete_post($id, true);
            throw $e;
        }
        return [ $id, $operation_id ];
    }

    /**
     * Change only the post fields the call names (title, content, excerpt,
     * status), through Save_Filters.
     *
     * @param array<string, mixed> $args
     */
    public static function update_fields(int $id, array $args): void
    {
        $map    = [ 'title' => 'post_title', 'content' => 'post_content', 'excerpt' => 'post_excerpt', 'status' => 'post_status' ];
        $fields = [];
        foreach ($map as $arg => $column) {
            if (array_key_exists($arg, $args)) {
                $fields[ $column ] = (string) $args[ $arg ];
            }
        }
        if ([] === $fields) {
            return;
        }
        $done = Save_Filters::update_post(wp_slash([ 'ID' => $id ] + $fields), true);
        if (is_wp_error($done)) {
            throw new Operation_Refused('update_failed', $done->get_error_message());
        }
    }

    /** An http(s) URL, or null when the value is not one. */
    public static function url(string $value): ?string
    {
        $value = trim($value);
        if ('' === $value) {
            return '';
        }
        $clean = esc_url_raw($value, [ 'http', 'https' ]);
        return '' === $clean ? null : $clean;
    }

    /**
     * Posts of a type the caller may read, as id, title, status plus the
     * named meta (meta key => output field); the $masked fields are masked
     * as personal data.
     *
     * @param array<string, mixed>  $args
     * @param array<string, string> $meta
     * @param string[]              $masked
     * @return array<string, mixed>
     */
    public static function list_posts(string $post_type, string $key, array $args, array $meta, array $masked = []): array
    {
        $search = isset($args['search']) ? trim((string) $args['search']) : '';
        $q      = Readable_Posts::query(array_filter([
            'post_type'      => $post_type,
            'post_status'    => isset($args['status']) ? (string) $args['status'] : 'any',
            'posts_per_page' => (int) ($args['page_size'] ?? 20),
            'paged'          => (int) ($args['page'] ?? 1),
            'orderby'        => 'title',
            'order'          => 'ASC',
            's'              => '' === $search ? null : $search,
        ], static fn ($value): bool => null !== $value));
        $rows = [];
        foreach ($q->posts as $post) {
            $row = [ 'id' => (int) $post->ID, 'title' => (string) $post->post_title, 'status' => (string) $post->post_status ];
            foreach ($meta as $meta_key => $field) {
                $row[ $field ] = (string) get_post_meta($post->ID, $meta_key, true);
            }
            $rows[] = [] === $masked ? $row : Masking::keys($row, $masked);
        }
        $out = [ $key => $rows, 'total' => (int) $q->found_posts ];
        if ([] !== $masked) {
            $out['masked'] = true;
        }
        return $out;
    }

    /**
     * Terms of a taxonomy with id, name, slug, parent and count, plus the
     * named term meta; the $masked fields are masked as personal data.
     *
     * @param array<string, mixed>  $args
     * @param array<string, string> $meta term meta key => output field
     * @param string[]              $masked
     * @return array<string, mixed>
     */
    public static function list_terms(string $taxonomy, string $key, array $args, array $meta = [], array $masked = []): array
    {
        $size  = (int) ($args['page_size'] ?? 50);
        $page  = (int) ($args['page'] ?? 1);
        $query = [ 'taxonomy' => $taxonomy, 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC' ];
        if (isset($args['search']) && '' !== (string) $args['search']) {
            $query['search'] = (string) $args['search'];
        }
        $total = (int) wp_count_terms($query);
        $terms = get_terms($query + [ 'number' => $size, 'offset' => ($page - 1) * $size ]);
        $rows  = [];
        foreach (is_array($terms) ? $terms : [] as $term) {
            $row = [ 'id' => (int) $term->term_id, 'name' => (string) $term->name, 'slug' => (string) $term->slug, 'parent' => (int) $term->parent, 'count' => (int) $term->count ];
            foreach ($meta as $meta_key => $field) {
                $row[ $field ] = (string) get_term_meta((int) $term->term_id, $meta_key, true);
            }
            $rows[] = [] === $masked ? $row : Masking::keys($row, $masked);
        }
        $out = [ $key => $rows, 'total' => $total ];
        if ([] !== $masked) {
            $out['masked'] = true;
        }
        return $out;
    }

    /** The JSON schema of a paged list read. */
    public static function list_schema(bool $status, int $max = 100): array
    {
        $props = [
            'page_size' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => $max ],
            'page'      => [ 'type' => 'integer', 'minimum' => 1 ],
            'search'    => [ 'type' => 'string', 'maxLength' => 200 ],
        ];
        if ($status) {
            $props['status'] = [ 'type' => 'string', 'enum' => array_merge(self::STATUSES, [ 'any' ]) ];
        }
        return [ 'type' => 'object', 'properties' => $props ];
    }
}
