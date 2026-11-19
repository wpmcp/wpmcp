<?php

namespace WPMCP\Integrations;

use WPMCP\Safety\File_Backup;
use WPMCP\Safety\Post_Creation_Snapshot;
use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * WordPress core's Font Library (issue #712, WordPress 6.5+) as free ops on
 * the theme dispatcher pair, so it adds no top-level tool.
 *
 * An installed font family is a wp_font_family post (name in post_title,
 * slug in post_name, the rest of its settings as JSON in post_content);
 * each face is a wp_font_face child post whose settings JSON holds the src
 * URL, and whose _wp_font_face_file meta rows name the files core keeps in
 * the fonts directory (wp_get_font_dir()). Core deletes those files when a
 * face post is deleted, and the faces when their family is deleted.
 *
 * Reads: list-font-families and get-font-family, each reporting whether the
 * family is active in global styles (the custom fontFamilies list the
 * editor's Font Library writes on activation). Installing does not activate
 * a family: the result says how, through site-templates-write.
 *
 * Writes, off until the site opts in through wpmcp_enable_font_library_write:
 *  - install-google-font downloads the chosen faces of a family in core's
 *    google-fonts collection to the fonts directory, as the Font Library
 *    does, so the site serves them itself;
 *  - install-font-from-upload installs one face from a font file given as
 *    base64 or an https URL;
 *  - delete-font-family deletes a family with its faces and files.
 *
 * An install adds missing faces to a family with the same slug, or creates
 * the family. It records a creation row for the posts it made, and undoing
 * it deletes them (core has no trash for fonts), which takes their files.
 * A delete backs up every face's files first, then snapshots and deletes
 * each face and the family under one session id, so rollback-session puts
 * the posts back at their ids and the file bytes in place.
 */
final class Core_Fonts_Pack
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.

    public const ENABLE_FILTER = 'wpmcp_enable_font_library_write';

    private const FAMILY = 'wp_font_family';
    private const FACE   = 'wp_font_face';

    private const FILE_META = '_wp_font_face_file';

    /** The collection install-google-font reads from. */
    private const COLLECTION = 'google-fonts';

    /** Largest font file one call accepts. */
    private const MAX_BYTES = 10485760;

    /** Most faces one install downloads. */
    private const MAX_FACES = 50;

    /** Face settings keys core's font face schema knows, besides src and preview. */
    private const FACE_KEYS = [
        'fontFamily', 'fontStyle', 'fontWeight', 'fontDisplay', 'fontStretch', 'ascentOverride', 'descentOverride',
        'fontVariant', 'fontFeatureSettings', 'fontVariationSettings', 'lineGapOverride', 'sizeAdjust', 'unicodeRange',
    ];

    /** Leading bytes of each font format core's Font Library accepts. */
    private const MAGIC = [ 'wOF2', 'wOFF', "\x00\x01\x00\x00", 'true', 'OTTO' ];

    /** @return array<string, array<string, mixed>> op name => definition for the theme pair. */
    public static function operations(): array
    {
        // The literal name, not ENABLE_FILTER, so the hook stays greppable.
        $enabled  = (bool) apply_filters('wpmcp_enable_font_library_write', false);
        $requires = static fn () => Ops_Status_Packs::presence(post_type_exists(self::FAMILY), 'plugin_missing', 'WordPress core 6.5+');
        $id       = [ 'type' => 'integer', 'minimum' => 1 ];
        $write    = [
            'requires'           => $requires,
            'enabled_by_default' => $enabled,
            'opt_in_filter'      => self::ENABLE_FILTER,
            'self_snapshotting'  => true,
        ];
        $style    = [ 'type' => 'string', 'enum' => [ 'normal', 'italic', 'oblique' ] ];
        $weight   = [ 'type' => [ 'string', 'integer' ], 'maxLength' => 20 ];
        $done     = ' Undo: rollback-operation deletes the posts and files it made. Disabled by default (wpmcp_enable_font_library_write filter)';

        return [
            'list-font-families'       => [
                'mode'         => 'read',
                'requires'     => $requires,
                'description'  => 'List installed Font Library families: id, name, slug, CSS font-family, face count, and active (in the global styles custom fontFamilies)',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'search'   => [ 'type' => 'string' ],
                        'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ],
                        'page'     => [ 'type' => 'integer', 'minimum' => 1 ],
                    ],
                ],
                'handler'      => static fn (array $args): array => self::list_families($args),
            ],
            'get-font-family'          => [
                'mode'         => 'read',
                'requires'     => $requires,
                'objects'      => [ 'id' => [ 'type' => 'post', 'access' => 'read' ] ],
                'description'  => 'Read one installed font family by id or slug, with each face: weight, style, src, and whether its file is stored on this site',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => $id, 'slug' => [ 'type' => 'string', 'minLength' => 1 ] ] ],
                'validate'     => static fn (array $args): ?array => null === self::find_family($args) ? self::not_found($args) : null,
                'handler'      => static fn (array $args): array => self::family_detail(self::find_family($args)),
            ],
            'install-google-font'      => $write + [
                'mode'         => 'write',
                'description'  => 'Install a family from core\'s Google Fonts collection by slug or name, downloading its faces to this site (all, or those in faces; a face without style matches any style). Adds missing faces to an installed family with that slug. Does not activate it in global styles.' . $done,
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'slug'  => [ 'type' => 'string', 'minLength' => 1 ],
                        'name'  => [ 'type' => 'string', 'minLength' => 1 ],
                        'faces' => [
                            'type'     => 'array',
                            'minItems' => 1,
                            'maxItems' => self::MAX_FACES,
                            'items'    => [
                                'type'       => 'object',
                                'properties' => [ 'weight' => $weight, 'style' => $style ],
                                'required'   => [ 'weight' ],
                            ],
                        ],
                    ],
                ],
                'validate'     => static fn (array $args): ?array => self::google_plan($args)['refusal'],
                'handler'      => static fn (array $args, array $context): array => self::install_google($args, $context),
            ],
            'install-font-from-upload' => $write + [
                'mode'         => 'write',
                'description'  => 'Install one font face (woff2, woff, ttf or otf, up to 10 MB) from content_base64 with filename, or from an https url, under font_family (created when no family has its slug).' . $done,
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'font_family'    => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 200 ],
                        'slug'           => [ 'type' => 'string', 'maxLength' => 200 ],
                        'font_weight'    => $weight,
                        'font_style'     => $style,
                        'filename'       => [ 'type' => 'string', 'maxLength' => 200 ],
                        'content_base64' => [ 'type' => 'string' ],
                        'url'            => [ 'type' => 'string', 'maxLength' => 2000 ],
                    ],
                    'required'   => [ 'font_family' ],
                ],
                'validate'     => static fn (array $args): ?array => self::upload_refusal($args),
                'handler'      => static fn (array $args, array $context): array => self::install_upload($args, $context),
            ],
            'delete-font-family'       => $write + [
                'mode'            => 'destructive',
                'objects'         => [ 'id' => 'post' ],
                'description'     => 'Delete an installed font family with its faces and their files. Needs confirm:true. Every face and its files are backed up first; undo: rollback-session. Disabled by default (wpmcp_enable_font_library_write filter)',
                'input_schema'    => [ 'type' => 'object', 'properties' => [ 'id' => $id ], 'required' => [ 'id' ] ],
                'validate'        => static fn (array $args): ?array => null === self::family((int) $args['id']) ? self::not_found($args) : null,
                'confirm_preview' => static fn (array $args): array => self::delete_preview((int) $args['id']),
                'handler'         => static fn (array $args, array $context): array => self::delete_family((int) $args['id'], $args, $context),
            ],
        ];
    }

    // ------------------------------------------------------------ helpers

    private static function refusal(string $code, string $message, array $data = []): array
    {
        return [ 'code' => $code, 'message' => $message, 'data' => $data ];
    }

    private static function not_found(array $args): array
    {
        $what = isset($args['id']) ? 'id ' . (int) $args['id'] : 'slug "' . (string) ($args['slug'] ?? '') . '"';
        return self::refusal('font_family_not_found', sprintf('No installed font family with %s. list-font-families shows them.', $what));
    }

    private static function family(int $id): ?\WP_Post
    {
        $post = $id > 0 ? get_post($id) : null;
        return $post instanceof \WP_Post && self::FAMILY === $post->post_type ? $post : null;
    }

    private static function family_by_slug(string $slug): ?\WP_Post
    {
        $posts = get_posts([
            'post_type'        => self::FAMILY,
            'name'             => sanitize_title($slug),
            'post_status'      => 'publish',
            'numberposts'      => 1,
        ]);
        return $posts[0] ?? null;
    }

    private static function find_family(array $args): ?\WP_Post
    {
        if (isset($args['id'])) {
            return self::family((int) $args['id']);
        }
        return isset($args['slug']) ? self::family_by_slug((string) $args['slug']) : null;
    }

    /** @return \WP_Post[] */
    private static function faces(int $family_id): array
    {
        return get_posts([
            'post_type'        => self::FACE,
            'post_parent'      => $family_id,
            'post_status'      => 'any',
            'numberposts'      => -1,
            'orderby'          => 'ID',
            'order'            => 'ASC',
        ]);
    }

    private static function settings(\WP_Post $post): array
    {
        $settings = json_decode((string) $post->post_content, true);
        return is_array($settings) ? $settings : [];
    }

    /** Slugs of the families global styles has active (the custom origin the Font Library writes). */
    private static function active_slugs(): array
    {
        $families = wp_get_global_settings([ 'typography', 'fontFamilies' ]);
        $custom   = is_array($families) && isset($families['custom']) && is_array($families['custom']) ? $families['custom'] : [];
        return array_values(array_filter(array_map(static fn ($f): string => is_array($f) ? (string) ($f['slug'] ?? '') : '', $custom)));
    }

    private static function family_summary(\WP_Post $post, array $active): array
    {
        $settings = self::settings($post);
        return [
            'id'          => (int) $post->ID,
            'name'        => (string) $post->post_title,
            'slug'        => (string) $post->post_name,
            'font_family' => (string) ($settings['fontFamily'] ?? ''),
            'face_count'  => count(self::faces((int) $post->ID)),
            'active'      => in_array((string) $post->post_name, $active, true),
        ];
    }

    private static function face_summary(\WP_Post $face): array
    {
        $settings = self::settings($face);
        $files    = array_values(array_map('strval', (array) get_post_meta($face->ID, self::FILE_META, false)));
        return [
            'id'          => (int) $face->ID,
            'font_weight' => (string) ($settings['fontWeight'] ?? '400'),
            'font_style'  => (string) ($settings['fontStyle'] ?? 'normal'),
            'src'         => array_values(array_map('strval', (array) ($settings['src'] ?? []))),
            'local'       => [] !== $files,
            'files'       => $files,
        ];
    }

    private static function list_families(array $args): array
    {
        $per_page = (int) ($args['per_page'] ?? 50);
        $query    = new \WP_Query([
            'post_type'      => self::FAMILY,
            'post_status'    => 'publish',
            's'              => (string) ($args['search'] ?? ''),
            'posts_per_page' => $per_page,
            'paged'          => (int) ($args['page'] ?? 1),
            'orderby'        => 'title',
            'order'          => 'ASC',
        ]);
        $active   = self::active_slugs();

        return [
            'total'    => (int) $query->found_posts,
            'families' => array_map(static fn (\WP_Post $p): array => self::family_summary($p, $active), $query->posts),
        ];
    }

    private static function family_detail(?\WP_Post $post): array
    {
        if (null === $post) {
            throw new Operation_Refused('font_family_not_found', 'No such installed font family.');
        }
        $summary = self::family_summary($post, self::active_slugs());
        unset($summary['face_count']);

        return $summary + [ 'faces' => array_map([ self::class, 'face_summary' ], self::faces((int) $post->ID)) ];
    }

    /** The global styles entry that activates a family, for the install result. */
    private static function activation_note(\WP_Post $family): array
    {
        $settings = self::settings($family);
        return [
            'active' => in_array((string) $family->post_name, self::active_slugs(), true),
            'notes'  => 'Installed, not activated. To use it in the editor, add { name, slug, fontFamily } of this family to settings.typography.fontFamilies.custom with site-templates-write entity global_styles.',
        ] + [ 'font_family' => (string) ($settings['fontFamily'] ?? '') ];
    }

    // ------------------------------------------------------------ files

    private static function extension(string $name): string
    {
        return strtolower((string) pathinfo((string) wp_parse_url($name, PHP_URL_PATH), PATHINFO_EXTENSION));
    }

    private static function allowed_extension(string $name): bool
    {
        return isset(\WP_Font_Utils::get_allowed_font_mime_types()[ self::extension($name) ]);
    }

    private static function is_font(string $bytes): bool
    {
        foreach (self::MAGIC as $magic) {
            if (str_starts_with($bytes, $magic)) {
                return true;
            }
        }
        return false;
    }

    /** An https URL WordPress may fetch, or null. */
    private static function https_url(string $url): ?string
    {
        $valid = wp_http_validate_url($url);
        return is_string($valid) && 'https' === wp_parse_url($valid, PHP_URL_SCHEME) ? $valid : null;
    }

    /**
     * Download $url to a temp file and check it is a font.
     *
     * @return string The temp file path, which the caller removes.
     */
    private static function download(string $url): string
    {
        if (! function_exists('download_url')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        $tmp = download_url($url, 60);
        if (is_wp_error($tmp)) {
            throw new Operation_Refused('download_failed', sprintf('Could not download %s (%s).', $url, $tmp->get_error_message()), [ 'url' => $url ]);
        }
        $size = (int) filesize($tmp);
        $head = (string) file_get_contents($tmp, false, null, 0, 4);
        if ($size > self::MAX_BYTES || ! self::is_font($head)) {
            wp_delete_file($tmp);
            throw new Operation_Refused('not_a_font', sprintf('%s is not a woff2, woff, ttf or otf font of at most 10 MB.', $url), [ 'url' => $url ]);
        }
        return $tmp;
    }

    /**
     * Move a font file into the fonts directory the way core's font face
     * endpoint does, under core's font MIME types.
     *
     * @return string The stored file's path relative to the fonts directory.
     */
    private static function store(string $tmp, string $filename): string
    {
        if (! function_exists('wp_handle_sideload')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        $mimes = \WP_Font_Utils::get_allowed_font_mime_types();
        add_filter('upload_mimes', [ \WP_Font_Utils::class, 'get_allowed_font_mime_types' ]);
        add_filter('upload_dir', '_wp_filter_font_directory');
        try {
            $file = [ 'name' => sanitize_file_name(wp_basename($filename)), 'tmp_name' => $tmp, 'size' => (int) filesize($tmp), 'error' => 0 ];
            $out  = wp_handle_sideload($file, [ 'test_form' => false, 'mimes' => $mimes ]);
        } finally {
            remove_filter('upload_dir', '_wp_filter_font_directory');
            remove_filter('upload_mimes', [ \WP_Font_Utils::class, 'get_allowed_font_mime_types' ]);
        }
        if (! empty($out['error']) || empty($out['file'])) {
            wp_delete_file($tmp);
            throw new Operation_Refused('font_upload_failed', 'WordPress refused the font file: ' . (string) ($out['error'] ?? 'unknown error') . '.');
        }
        $base = trailingslashit(wp_get_font_dir()['basedir']);
        return ltrim(str_starts_with($out['file'], $base) ? substr($out['file'], strlen($base)) : wp_basename($out['file']), '/');
    }

    // ------------------------------------------------------------ install

    private static function face_slug(array $settings): string
    {
        return \WP_Font_Utils::get_font_face_slug($settings);
    }

    private static function face_exists(array $settings): bool
    {
        $query = new \WP_Query([
            'post_type'              => self::FACE,
            'posts_per_page'         => 1,
            'title'                  => self::face_slug($settings),
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ]);
        return [] !== $query->posts;
    }

    private static function insert(array $fields): int
    {
        $id = wp_insert_post(wp_slash($fields + [ 'post_status' => 'publish' ]), true);
        if (is_wp_error($id) || 0 === (int) $id) {
            throw new Operation_Refused('insert_failed', is_wp_error($id) ? $id->get_error_message() : 'WordPress could not save the font.');
        }
        return (int) $id;
    }

    /**
     * Install $faces (each: settings without src, plus 'file' => temp path
     * and 'filename') under the family $family describes, creating it when
     * no family has its slug. Every temp file is consumed or removed.
     *
     * @param array{name: string, slug: string, fontFamily: string} $family
     * @param array<int, array<string, mixed>> $faces
     */
    private static function install(array $family, array $faces, array $args, array $context): array
    {
        $created = [];
        $temps   = array_column($faces, 'file');
        try {
            $post = self::family_by_slug($family['slug']);
            if (null === $post) {
                $id      = self::insert([
                    'post_type'    => self::FAMILY,
                    'post_title'   => $family['name'],
                    'post_name'    => sanitize_title($family['slug']),
                    'post_content' => (string) wp_json_encode([ 'fontFamily' => $family['fontFamily'] ]),
                ]);
                $created = [ $id ];
                $post    = get_post($id);
            }
            $installed = [];
            foreach ($faces as $face) {
                $tmp      = (string) $face['file'];
                $relative = self::store($tmp, (string) $face['filename']);
                $temps    = array_diff($temps, [ $tmp ]);
                $settings = array_diff_key($face, [ 'file' => 1, 'filename' => 1 ]);
                $settings['src'] = trailingslashit(wp_get_font_dir()['baseurl']) . $relative;
                $face_id  = self::insert([
                    'post_type'    => self::FACE,
                    'post_parent'  => (int) $post->ID,
                    'post_title'   => self::face_slug($settings),
                    'post_name'    => sanitize_title(self::face_slug($settings)),
                    'post_content' => (string) wp_json_encode($settings),
                ]);
                add_post_meta($face_id, self::FILE_META, $relative);
                $created[]   = $face_id;
                $installed[] = self::face_summary(get_post($face_id));
            }
            $op = Post_Creation_Snapshot::record((string) $context['tool_name'], $created, [ 'operation' => $context['operation'], 'args' => array_diff_key($args, [ 'content_base64' => 1 ]) ], (string) $context['session_id']);
        } catch (\Throwable $e) {
            // Faces first: deleting one takes its stored file with it.
            foreach (array_reverse($created) as $id) {
                wp_delete_post($id, true);
            }
            throw $e;
        } finally {
            foreach ($temps as $tmp) {
                wp_delete_file((string) $tmp);
            }
        }

        return [
            'result'        => [
                'family' => [ 'id' => (int) $post->ID, 'name' => (string) $post->post_title, 'slug' => (string) $post->post_name, 'created' => in_array((int) $post->ID, $created, true) ],
                'faces'  => $installed,
            ] + self::activation_note($post),
            'operation_ids' => [ $op ],
        ];
    }

    /** Face settings from a collection or caller, limited to core's schema keys and sanitized. */
    private static function clean_face(array $face): array
    {
        $out = [];
        foreach (self::FACE_KEYS as $key) {
            if (isset($face[ $key ]) && is_scalar($face[ $key ]) && '' !== (string) $face[ $key ]) {
                $out[ $key ] = 'fontFamily' === $key ? \WP_Font_Utils::sanitize_font_family((string) $face[ $key ]) : sanitize_text_field((string) $face[ $key ]);
            }
        }
        return $out + [ 'fontStyle' => 'normal', 'fontWeight' => '400' ];
    }

    // ---------------------------------------------- install-font-from-upload

    private static function upload_family(array $args): array
    {
        $name = sanitize_text_field((string) $args['font_family']);
        $slug = sanitize_title('' !== (string) ($args['slug'] ?? '') ? (string) $args['slug'] : $name);
        $post = self::family_by_slug($slug);
        $css  = null !== $post ? (string) (self::settings($post)['fontFamily'] ?? '') : '';

        return [ 'name' => $name, 'slug' => $slug, 'fontFamily' => '' !== $css ? $css : \WP_Font_Utils::sanitize_font_family($name) ];
    }

    private static function upload_face(array $args): array
    {
        $family = self::upload_family($args);
        return self::clean_face([
            'fontFamily' => $family['name'],
            'fontWeight' => (string) ($args['font_weight'] ?? '400'),
            'fontStyle'  => (string) ($args['font_style'] ?? 'normal'),
        ]);
    }

    /** The font bytes a caller sent as content_base64, or null when they are not valid base64. */
    private static function decoded(array $args): ?string
    {
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- a font file sent as base64 by the caller, checked for font magic bytes before use; not obfuscation.
        $bytes = base64_decode((string) ($args['content_base64'] ?? ''), true);
        return false === $bytes ? null : $bytes;
    }

    private static function upload_refusal(array $args): ?array
    {
        $has_data = isset($args['content_base64']);
        $has_url  = isset($args['url']);
        if ($has_data === $has_url) {
            return self::refusal('invalid_font_file', 'Pass exactly one of content_base64 (with filename) or url.');
        }
        if ('' === sanitize_title((string) $args['font_family'])) {
            return self::refusal('invalid_font_family', 'font_family needs letters or digits.');
        }
        if ($has_url) {
            $url = self::https_url((string) $args['url']);
            if (null === $url || ! self::allowed_extension($url)) {
                return self::refusal('invalid_font_file', 'url must be an https URL of a .woff2, .woff, .ttf or .otf file.');
            }
        } else {
            if (! self::allowed_extension((string) ($args['filename'] ?? ''))) {
                return self::refusal('invalid_font_file', 'filename must end in .woff2, .woff, .ttf or .otf.');
            }
            $bytes = self::decoded($args);
            if (null === $bytes || strlen($bytes) > self::MAX_BYTES || ! self::is_font($bytes)) {
                return self::refusal('not_a_font', 'content_base64 is not a woff2, woff, ttf or otf font of at most 10 MB.');
            }
        }
        if (self::face_exists(self::upload_face($args))) {
            return self::refusal('font_face_exists', 'That family already has a face with this weight and style.');
        }
        return null;
    }

    private static function install_upload(array $args, array $context): array
    {
        if (isset($args['url'])) {
            $url      = (string) self::https_url((string) $args['url']);
            $tmp      = self::download($url);
            $filename = wp_basename((string) wp_parse_url($url, PHP_URL_PATH));
        } else {
            $tmp      = wp_tempnam((string) $args['filename']);
            $filename = (string) $args['filename'];
            file_put_contents($tmp, (string) self::decoded($args));
        }

        return self::install(self::upload_family($args), [ self::upload_face($args) + [ 'file' => $tmp, 'filename' => $filename ] ], $args, $context);
    }

    // --------------------------------------------------- install-google-font

    /**
     * The collection family and faces an install-google-font call names,
     * or the refusal that explains why it cannot.
     *
     * @return array{refusal: ?array, family?: array, faces?: array}
     */
    private static function google_plan(array $args): array
    {
        $query = trim((string) ($args['slug'] ?? $args['name'] ?? ''));
        if ('' === $query) {
            return [ 'refusal' => self::refusal('invalid_font_family', 'Pass slug or name of a Google Fonts family.') ];
        }
        $collection = \WP_Font_Library::get_instance()->get_font_collection(self::COLLECTION);
        $data       = null === $collection ? null : $collection->get_data();
        if (! is_array($data)) {
            return [ 'refusal' => self::refusal('collection_unavailable', 'The Google Fonts collection could not be loaded' . (is_wp_error($data) ? ' (' . $data->get_error_message() . ')' : '') . '.') ];
        }

        $wanted      = sanitize_title($query);
        $suggestions = [];
        $match       = null;
        foreach ((array) ($data['font_families'] ?? []) as $item) {
            $settings = (array) ($item['font_family_settings'] ?? []);
            $slug     = (string) ($settings['slug'] ?? '');
            if ($wanted === $slug || $wanted === sanitize_title((string) ($settings['name'] ?? ''))) {
                $match = $settings;
                break;
            }
            if (count($suggestions) < 10 && '' !== $slug && str_contains($slug, $wanted)) {
                $suggestions[] = $slug;
            }
        }
        if (null === $match) {
            return [ 'refusal' => self::refusal('font_not_in_collection', sprintf('No family "%s" in the Google Fonts collection.', $query), [ 'suggestions' => $suggestions ]) ];
        }

        $faces = [];
        foreach ((array) ($match['fontFace'] ?? []) as $face) {
            if (! is_array($face) || ! self::wanted_face($face, $args['faces'] ?? null)) {
                continue;
            }
            $src = self::face_src($face['src'] ?? null);
            $clean = self::clean_face($face + [ 'fontFamily' => (string) ($match['name'] ?? '') ]);
            if (null !== $src && ! self::face_exists($clean)) {
                $faces[] = $clean + [ 'url' => $src ];
            }
        }
        if ([] === $faces) {
            return [ 'refusal' => self::refusal('faces_not_in_collection', 'None of the requested faces is in the collection or missing from the installed family.') ];
        }
        if (count($faces) > self::MAX_FACES) {
            return [ 'refusal' => self::refusal('too_many_faces', sprintf('The family has %d faces; pass faces to pick at most %d.', count($faces), self::MAX_FACES)) ];
        }

        $family = [
            'name'       => sanitize_text_field((string) ($match['name'] ?? $query)),
            'slug'       => sanitize_title((string) ($match['slug'] ?? $wanted)),
            'fontFamily' => \WP_Font_Utils::sanitize_font_family((string) ($match['fontFamily'] ?? '')),
        ];
        return [ 'refusal' => null, 'family' => $family, 'faces' => $faces ];
    }

    /** @param mixed $wanted The faces argument: null for every face. */
    private static function wanted_face(array $face, $wanted): bool
    {
        if (! is_array($wanted)) {
            return true;
        }
        foreach ($wanted as $spec) {
            $weight_ok = (string) ($spec['weight'] ?? '') === (string) ($face['fontWeight'] ?? '400');
            $style_ok  = ! isset($spec['style']) || (string) $spec['style'] === (string) ($face['fontStyle'] ?? 'normal');
            if ($weight_ok && $style_ok) {
                return true;
            }
        }
        return false;
    }

    /** @param mixed $src A collection face's src: a URL or a list of them. */
    private static function face_src($src): ?string
    {
        foreach ((array) $src as $url) {
            $url = self::https_url((string) $url);
            if (null !== $url && self::allowed_extension($url)) {
                return $url;
            }
        }
        return null;
    }

    private static function install_google(array $args, array $context): array
    {
        $plan  = self::google_plan($args);
        if (null !== $plan['refusal']) {
            throw new Operation_Refused($plan['refusal']['code'], $plan['refusal']['message'], $plan['refusal']['data']);
        }
        $faces = [];
        try {
            foreach ($plan['faces'] as $face) {
                $url     = (string) $face['url'];
                unset($face['url']);
                $faces[] = $face + [ 'file' => self::download($url), 'filename' => wp_basename((string) wp_parse_url($url, PHP_URL_PATH)) ];
            }
        } catch (\Throwable $e) {
            foreach ($faces as $face) {
                wp_delete_file((string) $face['file']);
            }
            throw $e;
        }

        return self::install($plan['family'], $faces, $args, $context);
    }

    // ---------------------------------------------------- delete-font-family

    /** @return string[] Absolute paths of a face's stored files. */
    private static function face_files(int $face_id): array
    {
        $dir = untrailingslashit(wp_get_font_dir()['basedir']);
        return array_map(static fn ($rel): string => $dir . '/' . ltrim((string) $rel, '/'), (array) get_post_meta($face_id, self::FILE_META, false));
    }

    private static function delete_preview(int $id): array
    {
        $family = self::family($id);
        $faces  = null === $family ? [] : self::faces($id);
        $files  = array_sum(array_map(static fn (\WP_Post $f): int => count(self::face_files((int) $f->ID)), $faces));

        return [
            'message' => sprintf('Delete font family "%s" with %d faces and %d files. Pass confirm:true to proceed.', null === $family ? '' : $family->post_title, count($faces), $files),
            'data'    => [ 'id' => $id, 'faces' => count($faces), 'files' => $files ],
        ];
    }

    private static function delete_family(int $id, array $args, array $context): array
    {
        $family = self::family($id);
        if (null === $family) {
            throw new Operation_Refused('font_family_not_found', sprintf('No installed font family with id %d.', $id));
        }
        $summary = self::family_detail($family);

        // Back up every face's files before anything is deleted, so a file
        // that cannot be copied stops the call with nothing changed.
        $plan = [];
        foreach (self::faces($id) as $face) {
            $op_id    = wp_generate_uuid4();
            $files    = self::face_files((int) $face->ID);
            $manifest = File_Backup::backup($op_id, $files);
            $plan[]   = [ 'face' => (int) $face->ID, 'op' => $op_id, 'manifest' => $manifest ];
            $missing  = array_diff(array_filter($files, 'is_file'), array_keys($manifest));
            if ([] !== $missing) {
                foreach ($plan as $row) {
                    File_Backup::delete_backup_dir($row['op']);
                }
                throw new Operation_Refused('backup_failed', 'Nothing was deleted: these font files could not be backed up: ' . implode(', ', array_map('wp_basename', $missing)) . '.');
            }
        }

        $base = [
            'session_id' => (string) $context['session_id'],
            'tool_name'  => (string) $context['tool_name'],
            'args'       => [ 'operation' => (string) $context['operation'], 'args' => $args ],
        ];
        $ids  = [];
        foreach ($plan as $row) {
            $out   = Safe_Mutation::run(
                $base + [
                    'object_type'         => 'post',
                    'object_id'           => $row['face'],
                    'operation_id'        => $row['op'],
                    'extra_snapshot_data' => [] !== $row['manifest'] ? [ 'files' => [ 'operation_id' => $row['op'], 'manifest' => $row['manifest'] ] ] : [],
                ],
                static fn () => wp_delete_post($row['face'], true),
                static fn (): bool => null === get_post($row['face'])
            );
            $ids[] = (string) $out['operation_id'];
        }
        $out   = Safe_Mutation::run(
            $base + [ 'object_type' => 'post', 'object_id' => $id ],
            static fn () => wp_delete_post($id, true),
            static fn (): bool => null === get_post($id)
        );
        $ids[] = (string) $out['operation_id'];

        return [
            'result'        => [
                'deleted' => $summary,
                'notes'   => $summary['active'] ? 'The family is still listed in the global styles custom fontFamilies; remove it there with site-templates-write.' : '',
            ],
            'operation_ids' => $ids,
        ];
    }
}
