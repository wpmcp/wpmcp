<?php

namespace WPMCP\Integrations;

use WPMCP\MCP\Registrar;
use WPMCP\Privacy\Masking;
use WPMCP\Safety\Post_Creation_Snapshot;
use WPMCP\Safety\Save_Filters;
use WPMCP\Tools\Content\Readable_Posts;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Thrive Ovation testimonials (issue #829) on the plugin-data pair,
 * paid-tier, so it adds no top-level tools.
 *
 * Thrive Ovation 10.9 keeps a testimonial as a tvo_testimonials post
 * (capability type page, not public) that it reads only while published;
 * the trash is its delete. The author's name, email, role, website and
 * picture are the _tvo_testimonial_attributes meta array, the Ovation status
 * is the _tvo_testimonial_status meta ('0' ready for display, '1' awaiting
 * approval, '2' awaiting review, '3' rejected) and where it came from is the
 * _tvo_testimonial_source meta. Tags are the tvo_tags taxonomy.
 *
 * Reads: list-testimonials (author name and email masked), get-testimonial
 * (in full for manage_options, masked otherwise) and list-tags. Every op
 * needs Thrive's own tve-use-tvo capability, which the Thrive dashboard
 * gives administrators and editors.
 *
 * Writes, off until wpmcp_enable_thrive_ovation_write, write the same post,
 * metas and terms Ovation's own data layer writes:
 *  - create-testimonial publishes a testimonial post (status awaiting review
 *    unless given), recorded for rollback (the undo moves it to the trash,
 *    which Ovation treats as deleted);
 *  - update-testimonial, set-testimonial-status and tag-testimonial each
 *    run under a post snapshot (fields, meta and terms), so
 *    rollback-operation puts the testimonial back.
 * Ovation's activity log (its own table) is not written: an undo could not
 * take a log row back.
 *
 * Presence is filterable (wpmcp_thrive_ovation_active, default: TVO_VERSION
 * is defined).
 */
final class Thrive_Ovation_Pack
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.

    public const POST_TYPE = 'tvo_testimonials';

    public const TAG = 'tvo_tags';

    public const ATTRIBUTES = '_tvo_testimonial_attributes';

    public const STATUS_META = '_tvo_testimonial_status';

    public const SOURCE_META = '_tvo_testimonial_source';

    public const OPT_IN_FILTER = 'wpmcp_enable_thrive_ovation_write';

    /** Thrive's own capability for the plugin (TVO_Product::cap()). */
    public const CAPABILITY = 'tve-use-tvo';

    public const MAX_PER_PAGE = 100;

    /** Ovation status name => the value it stores. */
    private const STATUSES = [
        'ready_for_display' => '0',
        'awaiting_approval' => '1',
        'awaiting_review'   => '2',
        'rejected'          => '3',
    ];

    /** Ovation source value => name (tvo_get_testimonial_source_text()). */
    private const SOURCES = [
        '0' => 'wordpress_comments',
        '1' => 'social_media',
        '2' => 'direct_capture',
        '3' => 'manually_added',
        '4' => 'copy',
        '5' => 'thrive_comments',
    ];

    /** Source value Ovation stores for a testimonial added by hand (TVO_SOURCE_PLUGIN). */
    private const SOURCE_MANUAL = '3';

    /** Op args => key of the attributes meta. */
    private const AUTHOR_FIELDS = [
        'name'        => 'name',
        'email'       => 'email',
        'role'        => 'role',
        'website_url' => 'website_url',
        'picture_url' => 'picture_url',
    ];

    public static function active(): bool
    {
        return Registrar::tier_permitted('pro') && self::loaded();
    }

    private static function loaded(): bool
    {
        return (bool) apply_filters('wpmcp_thrive_ovation_active', defined('TVO_VERSION'));
    }

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        $id     = [ 'type' => 'integer', 'minimum' => 1 ];
        $status = [ 'type' => 'string', 'enum' => array_keys(self::STATUSES) ];
        $names  = [ 'type' => 'array', 'items' => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 200 ], 'maxItems' => 50 ];
        $fields = [
            'title'       => [ 'type' => 'string', 'maxLength' => 500 ],
            'content'     => [ 'type' => 'string', 'minLength' => 1 ],
            'name'        => [ 'type' => 'string', 'maxLength' => 200 ],
            'email'       => [ 'type' => 'string', 'maxLength' => 200 ],
            'role'        => [ 'type' => 'string', 'maxLength' => 200 ],
            'website_url' => [ 'type' => 'string', 'maxLength' => 2000 ],
            'picture_url' => [ 'type' => 'string', 'maxLength' => 2000 ],
        ];
        $base   = [
            'tier'       => 'pro',
            'capability' => self::CAPABILITY,
            'requires'   => static fn () => Ops_Status_Packs::presence(self::loaded(), 'thrive_ovation_inactive', 'Thrive Ovation'),
        ];
        $write  = $base + [
            'mode'               => 'write',
            'enabled_by_default' => (bool) apply_filters('wpmcp_enable_thrive_ovation_write', false),
            'opt_in_filter'      => self::OPT_IN_FILTER,
        ];
        $own    = static fn (string $access): array => [ 'id' => [ 'type' => 'post', 'own_type' => true, 'access' => $access ] ];
        $post   = static fn (array $args): array => [ 'object_type' => 'post', 'object_id' => (int) $args['id'] ];

        return [
            'thrive-ovation-list-testimonials'      => $base + [
                'mode'         => 'read',
                'description'  => 'List Thrive Ovation testimonials newest first: id, title, summary, author name and email MASKED, role, status (ready_for_display, awaiting_approval, awaiting_review, rejected), source, tags, date. Filter by status, tag (tvo_tags id), search. page and per_page (max 100)',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'status'   => $status,
                        'tag'      => $id,
                        'search'   => [ 'type' => 'string', 'maxLength' => 200 ],
                        'page'     => [ 'type' => 'integer', 'minimum' => 1 ],
                        'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PER_PAGE ],
                    ],
                ],
                'handler'      => static fn (array $args): array => self::list_testimonials($args),
            ],
            'thrive-ovation-get-testimonial'        => $base + [
                'mode'         => 'read',
                'objects'      => $own('read'),
                'description'  => 'Read one Thrive Ovation testimonial: title, content, author name, email, role, website and picture (masked unless you have manage_options), status, source, tags, date',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => $id ], 'required' => [ 'id' ] ],
                'validate'     => static fn (array $args): ?array => self::testimonial_refusal((int) $args['id']),
                'handler'      => static fn (array $args): array => [ 'testimonial' => self::testimonial(get_post((int) $args['id']), true) ],
            ],
            'thrive-ovation-list-tags'              => $base + [
                'mode'         => 'read',
                'description'  => 'List Thrive Ovation testimonial tags: id, name, slug and testimonial count',
                'input_schema' => [ 'type' => 'object', 'properties' => (object) [] ],
                'handler'      => static fn (): array => self::list_tags(),
            ],
            'thrive-ovation-create-testimonial'     => $write + [
                'self_snapshotting' => true,
                'description'       => 'Add a Thrive Ovation testimonial: content (required), title, author name, email, role, website_url, picture_url, status (default awaiting_review) and tags (names). Undo: rollback-session trashes it',
                'input_schema'      => [ 'type' => 'object', 'properties' => $fields + [ 'status' => $status, 'tags' => $names ], 'required' => [ 'content' ] ],
                'validate'          => static fn (array $args): ?array => self::create_refusal() ?? self::fields_refusal($args) ?? self::tags_refusal((array) ($args['tags'] ?? []), []),
                'handler'           => static fn (array $args, array $context): array => self::create($args, $context),
            ],
            'thrive-ovation-update-testimonial'     => $write + [
                'objects'      => $own('write'),
                'description'  => 'Change a Thrive Ovation testimonial\'s title, content or author name, email, role, website_url or picture_url; only the args given change. Undo: rollback-operation',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => $id ] + $fields, 'required' => [ 'id' ], 'minProperties' => 2 ],
                'validate'     => static fn (array $args): ?array => self::testimonial_refusal((int) $args['id']) ?? self::fields_refusal($args),
                'snapshot'     => $post,
                'handler'      => static fn (array $args): array => self::update($args),
            ],
            'thrive-ovation-set-testimonial-status' => $write + [
                'objects'      => $own('write'),
                'description'  => 'Set a Thrive Ovation testimonial\'s status: ready_for_display, awaiting_approval, awaiting_review or rejected. Sends no email. Undo: rollback-operation',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => $id, 'status' => $status ], 'required' => [ 'id', 'status' ] ],
                'validate'     => static fn (array $args): ?array => self::testimonial_refusal((int) $args['id']),
                'snapshot'     => $post,
                'handler'      => static fn (array $args): array => self::set_status($args),
            ],
            'thrive-ovation-tag-testimonial'        => $write + [
                'objects'      => $own('write'),
                'description'  => 'Add tags to (add, names; a new name creates the tag) or remove tags from (remove, names) a Thrive Ovation testimonial. Undo: rollback-operation',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => $id, 'add' => $names, 'remove' => $names ], 'required' => [ 'id' ], 'minProperties' => 2 ],
                'validate'     => static fn (array $args): ?array => self::testimonial_refusal((int) $args['id']) ?? self::tags_refusal((array) ($args['add'] ?? []), (array) ($args['remove'] ?? [])),
                'snapshot'     => $post,
                'handler'      => static fn (array $args): array => self::tag($args),
            ],
        ];
    }

    // ------------------------------------------------------------ reads

    /** @return array<int, array{id: int, name: string}> */
    private static function tags_of(int $id): array
    {
        if (! taxonomy_exists(self::TAG)) {
            return [];
        }
        $terms = wp_get_object_terms($id, self::TAG);
        if (! is_array($terms)) {
            return [];
        }
        return array_map(static fn (\WP_Term $t): array => [ 'id' => (int) $t->term_id, 'name' => (string) $t->name ], $terms);
    }

    /** @return array<string, string> */
    private static function attributes(int $id): array
    {
        $meta = get_post_meta($id, self::ATTRIBUTES, true);
        return is_array($meta) ? array_map('strval', array_filter($meta, 'is_scalar')) : [];
    }

    private static function status_name(int $id): string
    {
        $value = (string) get_post_meta($id, self::STATUS_META, true);
        $name  = array_search($value, self::STATUSES, true);
        return false === $name ? $value : (string) $name;
    }

    private static function testimonial(\WP_Post $post, bool $full): array
    {
        $id    = (int) $post->ID;
        $attrs = self::attributes($id);
        $row   = [
            'id'      => $id,
            'title'   => (string) $post->post_title,
            'name'    => (string) ($attrs['name'] ?? ''),
            'email'   => (string) ($attrs['email'] ?? ''),
            'role'    => (string) ($attrs['role'] ?? ''),
            'status'  => self::status_name($id),
            'source'  => self::SOURCES[ (string) get_post_meta($id, self::SOURCE_META, true) ] ?? 'manually_added',
            'tags'    => self::tags_of($id),
            'date'    => (string) $post->post_date,
        ];
        if (! $full) {
            $row['summary'] = wp_trim_words(wp_strip_all_tags((string) $post->post_content), 15);
            return Masking::keys($row, [ 'name', 'email' ]);
        }
        $row += [
            'content'     => (string) $post->post_content,
            'website_url' => (string) ($attrs['website_url'] ?? ''),
            'picture_url' => (string) ($attrs['picture_url'] ?? ''),
            'media_url'   => (string) ($attrs['media_url'] ?? ''),
            'modified'    => (string) $post->post_modified,
        ];
        // Rule of the list masking audit: a single read is in full only for manage_options.
        $masked        = ! current_user_can('manage_options');
        $row           = $masked ? Masking::keys($row, [ 'name', 'email', 'picture_url' ]) : $row;
        $row['masked'] = $masked;
        return $row;
    }

    private static function list_testimonials(array $args): array
    {
        $query = [
            'post_type'      => self::POST_TYPE,
            'post_status'    => 'publish',
            'posts_per_page' => (int) ($args['per_page'] ?? 20),
            'paged'          => (int) ($args['page'] ?? 1),
            'orderby'        => 'date',
            'order'          => 'DESC',
        ];
        if (isset($args['status'])) {
            $query['meta_query'] = [ [ 'key' => self::STATUS_META, 'value' => self::STATUSES[ (string) $args['status'] ] ] ]; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Ovation keeps the status only in this meta.
        }
        if (isset($args['tag'])) {
            $query['tax_query'] = [ [ 'taxonomy' => self::TAG, 'field' => 'term_id', 'terms' => [ (int) $args['tag'] ] ] ]; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- filtering testimonials by their Ovation tag is the point of the op.
        }
        if (isset($args['search']) && '' !== trim((string) $args['search'])) {
            $query['s'] = (string) $args['search'];
        }
        $q = Readable_Posts::query($query);
        return [
            'testimonials' => array_map(static fn (\WP_Post $p): array => self::testimonial($p, false), $q->posts),
            'total'        => (int) $q->found_posts,
            'masked'       => true,
        ];
    }

    private static function list_tags(): array
    {
        if (! taxonomy_exists(self::TAG)) {
            return [ 'tags' => [] ];
        }
        $terms = get_terms([ 'taxonomy' => self::TAG, 'hide_empty' => false, 'orderby' => 'name' ]);
        $out   = [];
        foreach (is_array($terms) ? $terms : [] as $term) {
            $out[] = [ 'id' => (int) $term->term_id, 'name' => (string) $term->name, 'slug' => (string) $term->slug, 'count' => (int) $term->count ];
        }
        return [ 'tags' => $out ];
    }

    // ------------------------------------------------------------ refusals

    private static function refusal(string $code, string $message, array $data = []): array
    {
        return [ 'code' => $code, 'message' => $message, 'data' => $data ];
    }

    private static function testimonial_refusal(int $id): ?array
    {
        $post = get_post($id);
        if (! $post instanceof \WP_Post || self::POST_TYPE !== $post->post_type) {
            return self::refusal('testimonial_not_found', sprintf('%d is not a Thrive Ovation testimonial.', $id), [ 'id' => $id ]);
        }
        if ('trash' === $post->post_status) {
            return self::refusal(
                'testimonial_trashed',
                sprintf('Testimonial %d is in the trash, which Thrive Ovation treats as deleted.', $id),
                [ 'id' => $id, 'status' => 'trash' ]
            );
        }
        if ('publish' !== $post->post_status) {
            return self::refusal('testimonial_not_found', sprintf('%d is not a Thrive Ovation testimonial Ovation shows (status %s).', $id, $post->post_status), [ 'id' => $id ]);
        }
        return null;
    }

    private static function create_refusal(): ?array
    {
        $object = get_post_type_object(self::POST_TYPE);
        if (! $object instanceof \WP_Post_Type) {
            return self::refusal('post_type_missing', 'Thrive Ovation has not registered its testimonial post type.');
        }
        foreach ([ $object->cap->create_posts, $object->cap->publish_posts ] as $cap) {
            if (! current_user_can($cap)) {
                return self::refusal('operation_denied', sprintf('This needs the %s capability.', $cap), [ 'reason' => 'capability' ]);
            }
        }
        return null;
    }

    private static function fields_refusal(array $args): ?array
    {
        $email = trim((string) ($args['email'] ?? ''));
        if ('' !== $email && ! is_email($email)) {
            return self::refusal('invalid_email', 'email is not a valid email address.', [ 'field' => 'email' ]);
        }
        return null;
    }

    /**
     * @param string[] $add
     * @param string[] $remove
     */
    private static function tags_refusal(array $add, array $remove): ?array
    {
        if ([] === $add && [] === $remove) {
            return null;
        }
        if (! taxonomy_exists(self::TAG)) {
            return self::refusal('taxonomy_missing', 'Thrive Ovation has not registered its tvo_tags taxonomy.');
        }
        $tax     = get_taxonomy(self::TAG);
        $create  = $tax && current_user_can($tax->cap->manage_terms);
        $missing = [];
        foreach ($add as $name) {
            if (! $create && null === self::tag_id((string) $name)) {
                $missing[] = (string) $name;
            }
        }
        foreach ($remove as $name) {
            if (null === self::tag_id((string) $name)) {
                $missing[] = (string) $name;
            }
        }
        if ([] !== $missing) {
            return self::refusal(
                'unknown_tag',
                sprintf('No Thrive Ovation tag named %s%s.', implode(', ', $missing), $create ? '' : ' (creating a tag needs the ' . $tax->cap->manage_terms . ' capability)'),
                [ 'unknown' => $missing ]
            );
        }
        return null;
    }

    private static function tag_id(string $name): ?int
    {
        $term = get_term_by('name', $name, self::TAG);
        return $term instanceof \WP_Term ? (int) $term->term_id : null;
    }

    // ------------------------------------------------------------ writes

    /**
     * The attributes meta with the given op args merged in, sanitized the
     * way Ovation's tvo_construct_testimonial_meta() does; keys the op does
     * not know (media_url) stay.
     *
     * @param array<string, string> $attrs
     * @return array<string, string>
     */
    private static function merge_attributes(array $attrs, array $args): array
    {
        foreach (self::AUTHOR_FIELDS as $arg => $key) {
            if (! array_key_exists($arg, $args)) {
                continue;
            }
            $value         = trim((string) $args[ $arg ]);
            $attrs[ $key ] = match ($arg) {
                'email'                      => sanitize_email($value),
                'website_url', 'picture_url' => esc_url_raw($value),
                default                      => sanitize_text_field($value),
            };
        }
        return $attrs;
    }

    /** Ovation's tvo_sanitize_testimonial_field(): script and style blocks removed. */
    private static function content(string $content): string
    {
        return (string) preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', $content);
    }

    /** @return int[] */
    private static function tag_ids(array $names): array
    {
        $ids = [];
        foreach ($names as $name) {
            $id = self::tag_id((string) $name);
            if (null === $id) {
                $made = wp_insert_term((string) $name, self::TAG);
                if (is_wp_error($made)) {
                    throw new Operation_Refused('update_failed', $made->get_error_message());
                }
                $id = (int) $made['term_id'];
            }
            $ids[] = $id;
        }
        return $ids;
    }

    private static function attach(int $id, array $names): void
    {
        if ([] === $names) {
            return;
        }
        $out = wp_set_object_terms($id, self::tag_ids($names), self::TAG, true);
        if (is_wp_error($out)) {
            throw new Operation_Refused('update_failed', $out->get_error_message());
        }
    }

    private static function create(array $args, array $context): array
    {
        $id = wp_insert_post([
            'post_type'    => self::POST_TYPE,
            'post_title'   => wp_slash(sanitize_text_field((string) ($args['title'] ?? ''))),
            'post_content' => wp_slash(self::content((string) $args['content'])),
            'post_status'  => 'publish',
            'post_author'  => get_current_user_id(),
        ], true);
        if (is_wp_error($id) || 0 === (int) $id) {
            throw new Operation_Refused('insert_failed', is_wp_error($id) ? $id->get_error_message() : 'Thrive Ovation could not create the testimonial.');
        }
        $id = (int) $id;
        $op = Post_Creation_Snapshot::record((string) $context['tool_name'], [ $id ], [ 'operation' => $context['operation'], 'args' => $args ], (string) $context['session_id']);
        $attributes = self::merge_attributes([ 'email' => '', 'role' => '', 'website_url' => '', 'picture_url' => '', 'name' => '' ], $args);
        update_post_meta($id, self::ATTRIBUTES, $attributes);
        update_post_meta($id, self::STATUS_META, self::STATUSES[ (string) ($args['status'] ?? 'awaiting_review') ]);
        update_post_meta($id, self::SOURCE_META, self::SOURCE_MANUAL);
        self::attach($id, (array) ($args['tags'] ?? []));
        clean_post_cache($id);
        return [ 'result' => [ 'testimonial' => self::testimonial(get_post($id), false) ], 'operation_ids' => [ $op ] ];
    }

    private static function update(array $args): array
    {
        $id     = (int) $args['id'];
        $fields = [ 'ID' => $id ];
        if (array_key_exists('title', $args)) {
            $fields['post_title'] = wp_slash(sanitize_text_field((string) $args['title']));
        }
        if (array_key_exists('content', $args)) {
            $fields['post_content'] = wp_slash(self::content((string) $args['content']));
        }
        if (count($fields) > 1) {
            $out = Save_Filters::update_post($fields, true);
            if (is_wp_error($out)) {
                throw new Operation_Refused('update_failed', $out->get_error_message());
            }
        }
        if ([] !== array_intersect_key($args, self::AUTHOR_FIELDS)) {
            update_post_meta($id, self::ATTRIBUTES, self::merge_attributes(self::attributes($id), $args));
        }
        clean_post_cache($id);
        return [ 'testimonial' => self::testimonial(get_post($id), false) ];
    }

    private static function set_status(array $args): array
    {
        $id = (int) $args['id'];
        update_post_meta($id, self::STATUS_META, self::STATUSES[ (string) $args['status'] ]);
        return [ 'testimonial' => self::testimonial(get_post($id), false) ];
    }

    private static function tag(array $args): array
    {
        $id = (int) $args['id'];
        self::attach($id, (array) ($args['add'] ?? []));
        $remove = array_map(static fn ($name): int => (int) self::tag_id((string) $name), (array) ($args['remove'] ?? []));
        if ([] !== $remove) {
            $out = wp_remove_object_terms($id, $remove, self::TAG);
            if (is_wp_error($out)) {
                throw new Operation_Refused('update_failed', $out->get_error_message());
            }
        }
        clean_post_cache($id);
        return [ 'testimonial' => self::testimonial(get_post($id), false) ];
    }
}
