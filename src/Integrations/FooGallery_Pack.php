<?php

namespace WPMCP\Integrations;

use WPMCP\Safety\Post_Creation_Snapshot;
use WPMCP\Tools\Content\Readable_Posts;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * FooGallery (issue #705, the first plugin of the galleries and sliders
 * issue) on the plugin-data pair, free tier, so it adds no top-level tools.
 * Ops are prefixed foogallery- because the pair is shared.
 *
 * FooGallery 3.3 keeps a gallery as a non-public `foogallery` post (title,
 * status) behind its own capabilities (edit_foogalleries and the rest,
 * FooGallery_PostTypes::GALLERY_CAPABILITIES), which it grants to the
 * gallery creator role and above. Its images are the foogallery_attachments
 * post meta, a list of attachment ids in display order; its layout is
 * foogallery_template, its source foogallery_datasource (media_library
 * unless an add-on feeds it from elsewhere), its sort foogallery_sort and
 * its rendered HTML is cached in foogallery_cache
 * (includes/constants.php, gallery-management-functions.php). Galleries
 * show where their shortcode [foogallery id="<id>"] or block is placed.
 *
 * Reads: foogallery-list-galleries and -get-gallery (with each image's
 * title, alt text and URL). Galleries carry no visitor data, so nothing
 * needs masking.
 * Writes are off until a site opts in with wpmcp_enable_foogallery_write:
 *  - create-gallery inserts a gallery (a draft unless status says publish),
 *    stored as foogallery_insert_gallery() stores one, recorded for
 *    rollback (Post_Creation_Snapshot: the undo moves it to the trash);
 *  - add-images, remove-images and reorder-images change the image list
 *    under a post snapshot (fields and meta), and clear the rendered
 *    cache as the plugin's own foogallery_update_gallery_attachments() does.
 * Image writes refuse a gallery fed by a source other than the media
 * library, as the plugin's own API does.
 *
 * Presence is filterable (wpmcp_foogallery_active, default: FOOGALLERY_VERSION
 * is defined).
 */
final class FooGallery_Pack
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.

    public const GALLERY = 'foogallery';

    public const ATTACHMENTS = 'foogallery_attachments';

    public const TEMPLATE = 'foogallery_template';

    public const DATASOURCE = 'foogallery_datasource';

    public const SORT = 'foogallery_sort';

    public const CACHE = 'foogallery_cache';

    public const MEDIA_LIBRARY = 'media_library';

    public const OPT_IN_FILTER = 'wpmcp_enable_foogallery_write';

    public const CAP = 'edit_foogalleries';

    public const MAX_PAGE_SIZE = 100;

    public const MAX_IMAGES = 500;

    /** get-gallery details at most this many images; image_ids always lists them all. */
    public const MAX_IMAGE_DETAILS = 200;

    public const PLUGIN_FILES = [ 'foogallery/foogallery.php' ];

    private const STATUSES = [ 'publish', 'draft', 'pending', 'private' ];

    public static function active(): bool
    {
        return (bool) apply_filters('wpmcp_foogallery_active', defined('FOOGALLERY_VERSION'));
    }

    /** @return true|array{code: string, message: string, data: array} */
    public static function presence()
    {
        if (self::active()) {
            return true;
        }
        $installed = false;
        foreach (self::PLUGIN_FILES as $file) {
            $installed = $installed || (defined('WP_PLUGIN_DIR') && file_exists(WP_PLUGIN_DIR . '/' . $file));
        }
        $url = admin_url('plugins.php');

        return [
            'code'    => $installed ? 'plugin_inactive' : 'plugin_missing',
            'message' => sprintf(
                'FooGallery is %s on this site. Install and activate FooGallery (%s), then try again.',
                $installed ? 'installed but not active' : 'not installed',
                $url
            ),
            'data'    => [ 'plugin' => 'foogallery', 'plugins_url' => $url ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        $id      = [ 'type' => 'integer', 'minimum' => 1 ];
        $images  = [ 'type' => 'array', 'minItems' => 1, 'maxItems' => self::MAX_IMAGES, 'items' => $id ];
        $base    = [
            'capability' => 'edit_posts',
            'requires'   => static fn () => self::presence(),
        ];
        $write   = $base + [
            'mode'               => 'write',
            'enabled_by_default' => (bool) apply_filters('wpmcp_enable_foogallery_write', false),
            'opt_in_filter'      => self::OPT_IN_FILTER,
        ];
        $gallery = static fn (string $access): array => [ 'id' => [ 'type' => 'post', 'own_type' => true, 'access' => $access ] ];
        $image   = [ 'image_ids' => [ 'type' => 'post', 'access' => 'read' ] ];
        $target  = static fn (array $args): array => [ 'object_type' => 'post', 'object_id' => (int) $args['id'] ];
        $change  = [
            'type'                 => 'object',
            'properties'           => [ 'id' => $id, 'image_ids' => $images ],
            'required'             => [ 'id', 'image_ids' ],
            'additionalProperties' => false,
        ];
        $image_write = static fn (string $how): array => $write + [
            'objects'      => $gallery('write') + $image,
            'input_schema' => $change,
            'validate'     => static fn (array $args): ?array => self::images_refusal($args, $how),
            'snapshot'     => $target,
            'handler'      => static fn (array $args): array => self::change_images($args, $how),
        ];

        return [
            'foogallery-list-galleries' => $base + [
                'mode'         => 'read',
                'description'  => 'List FooGallery galleries by title: id, title, status, template, datasource, image_count, shortcode. Filter by status, search. page and per_page (max 100)',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'status'   => [ 'type' => 'string', 'enum' => self::STATUSES ],
                        'search'   => [ 'type' => 'string', 'maxLength' => 200 ],
                        'page'     => [ 'type' => 'integer', 'minimum' => 1 ],
                        'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PAGE_SIZE ],
                    ],
                ],
                'validate'     => static fn (): ?array => self::cap_refusal(),
                'handler'      => static fn (array $args): array => self::list_galleries($args),
            ],
            'foogallery-get-gallery'    => $base + [
                'mode'         => 'read',
                'objects'      => $gallery('read'),
                'description'  => 'Read one FooGallery gallery: image_ids in display order and each image\'s title, alt and url (first 200), template, sort, shortcode',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => $id ], 'required' => [ 'id' ] ],
                'validate'     => static fn (array $args): ?array => self::gallery_refusal((int) $args['id']),
                'handler'      => static fn (array $args): array => [ 'gallery' => self::view(get_post((int) $args['id']), true) ],
            ],
            'foogallery-create-gallery' => $write + [
                'self_snapshotting' => true,
                'objects'           => $image,
                'description'       => 'Create a FooGallery gallery: title required; image_ids (media library ids, display order), template (default: the site\'s), status draft (default) or publish. Undo: rollback-session trashes it',
                'input_schema'      => [
                    'type'                 => 'object',
                    'properties'           => [
                        'title'     => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 500 ],
                        'image_ids' => [ 'minItems' => 0 ] + $images,
                        'template'  => [ 'type' => 'string', 'pattern' => '^[a-z0-9_-]{1,50}$' ],
                        'status'    => [ 'type' => 'string', 'enum' => [ 'draft', 'publish' ] ],
                    ],
                    'required'             => [ 'title' ],
                    'additionalProperties' => false,
                ],
                'validate'          => static fn (array $args): ?array => self::create_refusal($args),
                'handler'           => static fn (array $args, array $context): array => self::create($args, $context),
            ],
            'foogallery-add-images'     => $image_write('add') + [
                'description' => 'Append media library images to a FooGallery gallery (ones already in it are skipped). Undo: rollback-operation',
            ],
            'foogallery-remove-images'  => $image_write('remove') + [
                'description' => 'Remove images from a FooGallery gallery (the media files stay). Undo: rollback-operation',
            ],
            'foogallery-reorder-images' => $image_write('reorder') + [
                'description' => 'Set the display order of a FooGallery gallery: image_ids must be exactly its current images. Undo: rollback-operation',
            ],
        ];
    }

    // ------------------------------------------------------------ refusals

    private static function refusal(string $code, string $message, array $data = []): array
    {
        return [ 'code' => $code, 'message' => $message, 'data' => $data ];
    }

    /** FooGallery's own screens ask for edit_foogalleries; administrators pass as they do in its menu. */
    private static function cap_refusal(): ?array
    {
        if (current_user_can('manage_options') || current_user_can(self::CAP)) {
            return null;
        }
        return self::refusal('operation_denied', sprintf('This needs the %s capability (FooGallery grants it to its gallery creator role and above).', self::CAP), [ 'reason' => 'capability', 'capability' => self::CAP ]);
    }

    private static function is_gallery($post): bool
    {
        return $post instanceof \WP_Post && self::GALLERY === $post->post_type && 'trash' !== $post->post_status;
    }

    private static function gallery_refusal(int $id): ?array
    {
        if (! self::is_gallery(get_post($id))) {
            return self::refusal('gallery_not_found', sprintf('%d is not a FooGallery gallery.', $id), [ 'id' => $id ]);
        }
        return self::cap_refusal();
    }

    private static function image_refusal(array $ids): ?array
    {
        foreach ($ids as $image) {
            if ('attachment' !== get_post_type((int) $image)) {
                return self::refusal('image_not_found', sprintf('%d is not in the media library.', (int) $image), [ 'image_id' => (int) $image ]);
            }
        }
        return null;
    }

    private static function create_refusal(array $args): ?array
    {
        if (! post_type_exists(self::GALLERY)) {
            return self::refusal('post_type_missing', 'FooGallery has not registered its gallery post type.');
        }
        if ('publish' === ($args['status'] ?? 'draft') && ! current_user_can('manage_options') && ! current_user_can('publish_foogalleries')) {
            return self::refusal('operation_denied', 'Publishing a gallery needs the publish_foogalleries capability; create it as a draft instead.', [ 'reason' => 'capability', 'capability' => 'publish_foogalleries' ]);
        }
        return self::cap_refusal() ?? self::image_refusal((array) ($args['image_ids'] ?? []));
    }

    private static function images_refusal(array $args, string $how): ?array
    {
        $id      = (int) $args['id'];
        $refused = self::gallery_refusal($id);
        if (null !== $refused) {
            return $refused;
        }
        $source = self::datasource($id);
        if (self::MEDIA_LIBRARY !== $source) {
            return self::refusal('datasource_unsupported', sprintf('This gallery takes its images from %s, not a list of media library images, so its images cannot be changed here.', $source), [ 'datasource' => $source ]);
        }
        $given = array_map('intval', (array) $args['image_ids']);
        if ('reorder' === $how) {
            $current = self::image_ids($id);
            $sorted  = $given;
            sort($current);
            sort($sorted);
            if ($sorted !== $current) {
                return self::refusal('images_mismatch', 'image_ids must list every image of the gallery exactly once; get-gallery has them.', [ 'image_ids' => self::image_ids($id) ]);
            }
            return null;
        }
        return 'add' === $how ? self::image_refusal($given) : null;
    }

    // ------------------------------------------------------------ reads

    /** The list as foogallery_normalize_attachment_ids() keeps it: unique positive ints, order kept. */
    private static function normalize(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $id = is_scalar($id) ? absint($id) : 0;
            if ($id > 0 && ! in_array($id, $out, true)) {
                $out[] = $id;
            }
        }
        return $out;
    }

    /** @return int[] */
    private static function image_ids(int $id): array
    {
        $ids = get_post_meta($id, self::ATTACHMENTS, true);
        return is_array($ids) ? self::normalize($ids) : [];
    }

    private static function datasource(int $id): string
    {
        $source = (string) get_post_meta($id, self::DATASOURCE, true);
        return '' === $source ? self::MEDIA_LIBRARY : $source;
    }

    /** @return array<string, mixed> */
    private static function view(\WP_Post $post, bool $full): array
    {
        $id  = (int) $post->ID;
        $ids = self::image_ids($id);
        $out = [
            'id'          => $id,
            'title'       => (string) $post->post_title,
            'status'      => (string) $post->post_status,
            'template'    => (string) get_post_meta($id, self::TEMPLATE, true),
            'datasource'  => self::datasource($id),
            'image_count' => count($ids),
            'shortcode'   => sprintf('[foogallery id="%d"]', $id),
            'modified'    => (string) $post->post_modified,
        ];
        if ($full) {
            $out['sort']      = (string) get_post_meta($id, self::SORT, true);
            $out['image_ids'] = $ids;
            $out['images']    = array_map(
                static fn (int $image): array => [
                    'id'    => $image,
                    'title' => (string) get_the_title($image),
                    'alt'   => (string) get_post_meta($image, '_wp_attachment_image_alt', true),
                    'url'   => (string) wp_get_attachment_url($image),
                ],
                array_slice($ids, 0, self::MAX_IMAGE_DETAILS)
            );
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private static function list_galleries(array $args): array
    {
        $query = [
            'post_type'      => self::GALLERY,
            'post_status'    => isset($args['status']) ? [ (string) $args['status'] ] : self::STATUSES,
            'posts_per_page' => (int) ($args['per_page'] ?? 20),
            'paged'          => (int) ($args['page'] ?? 1),
            'orderby'        => 'title',
            'order'          => 'ASC',
        ];
        if (isset($args['search']) && '' !== trim((string) $args['search'])) {
            $query['s'] = (string) $args['search'];
        }
        $q = Readable_Posts::query($query);
        return [ 'galleries' => array_map(static fn (\WP_Post $p): array => self::view($p, false), $q->posts), 'total' => (int) $q->found_posts ];
    }

    // ------------------------------------------------------------ writes

    /** Drop the rendered HTML, as foogallery_clear_gallery_cache() does. */
    private static function clear_cache(int $id): void
    {
        delete_post_meta($id, self::CACHE);
        clean_post_cache($id);
    }

    /** @return array<string, mixed> */
    private static function create(array $args, array $context): array
    {
        $template = (string) ($args['template'] ?? '');
        if ('' === $template) {
            $template = function_exists('foogallery_default_gallery_template') ? sanitize_key((string) foogallery_default_gallery_template()) : '';
            $template = '' === $template ? 'default' : $template;
        }
        $id = wp_insert_post([
            'post_type'   => self::GALLERY,
            'post_title'  => wp_slash(sanitize_text_field((string) $args['title'])),
            'post_status' => (string) ($args['status'] ?? 'draft'),
            'post_author' => get_current_user_id(),
        ], true);
        if (is_wp_error($id) || 0 === (int) $id) {
            throw new Operation_Refused('insert_failed', is_wp_error($id) ? $id->get_error_message() : 'FooGallery could not create the gallery.');
        }
        $id = (int) $id;
        $op = Post_Creation_Snapshot::record((string) $context['tool_name'], [ $id ], [ 'operation' => $context['operation'], 'args' => $args ], (string) $context['session_id']);
        // Settings stay empty: FooGallery builds the template's defaults when it loads a gallery without them.
        update_post_meta($id, self::TEMPLATE, $template);
        update_post_meta($id, self::ATTACHMENTS, self::normalize((array) ($args['image_ids'] ?? [])));
        update_post_meta($id, self::DATASOURCE, self::MEDIA_LIBRARY);
        update_post_meta($id, self::SORT, '');
        self::clear_cache($id);

        return [ 'result' => [ 'gallery' => self::view(get_post($id), true) ], 'operation_ids' => [ $op ] ];
    }

    /** @return array<string, mixed> */
    private static function change_images(array $args, string $how): array
    {
        $id    = (int) $args['id'];
        $given = self::normalize((array) $args['image_ids']);
        $now   = self::image_ids($id);
        if ('add' === $how) {
            $new = self::normalize(array_merge($now, $given));
        } elseif ('remove' === $how) {
            $new = array_values(array_diff($now, $given));
        } else {
            $new = $given;
        }
        update_post_meta($id, self::ATTACHMENTS, $new);
        self::clear_cache($id);

        return [ 'gallery' => self::view(get_post($id), true) ];
    }
}
