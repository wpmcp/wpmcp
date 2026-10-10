<?php

namespace WPMCP\Integrations;

use WPMCP\MCP\Registrar;
use WPMCP\Safety\Post_Creation_Snapshot;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Content\Content_Guard;
use WPMCP\Tools\Content\Duplicate_Post;
use WPMCP\Tools\Content\Post_Status_Guard;
use WPMCP\Tools\Content\Readable_Posts;
use WPMCP\Tools\I18n\I18n_Adapter;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Translate a page in a few calls (issue #498), paid-tier ops on the
 * plugin-data pair for Polylang and WPML, through I18n_Adapter:
 *  - find-missing-translations: posts of a type and status that have a
 *    language and no translation into the target language, paged;
 *  - create-translation: a draft copy of a post in the target language
 *    (content, builder data, meta, template and featured image, the
 *    featured image swapped for its translation when it has one, terms
 *    mapped to their translations), linked into the source's group;
 *  - get-page-text: every translatable string of a post as numbered
 *    segments with a content hash (I18n_Page_Text);
 *  - apply-page-text: translated segments written back as one snapshotted
 *    change, all or none, refused on a stale hash unless force:true.
 *
 * The plugin's own sync between translations (Polylang copying fields,
 * meta and terms across a group) is held off while these ops write, so
 * writing a translation never changes its source. The copy follows
 * duplicate-post's rules: the type's create capability (issue #482), a
 * protected source stays protected and is withheld from a user who cannot
 * edit it (issues #450, #483). create-translation also needs edit_post on
 * the source, since it changes the source's translation group.
 *
 * Presence is filterable (wpmcp_multilingual_active); without Polylang or
 * WPML every op stays documented in list-operations (dependency_met:false)
 * and answers multilingual_inactive.
 */
final class I18n_Pack
{
    private const MAX_PER_PAGE = 100;

    private const MAX_SEGMENTS_PER_PAGE = 500;

    private const STATUSES = [ 'publish', 'draft', 'pending', 'private', 'future', 'any' ];

    /** Whether Polylang or WPML is usable and this install may run the paid tier. */
    public static function active(): bool
    {
        return Registrar::tier_permitted('pro')
            && (bool) apply_filters('wpmcp_multilingual_active', I18n_Adapter::api_ready());
    }

    /** @return true|array{code: string, message: string} */
    public static function requirement()
    {
        return self::active() ? true : [
            'code'    => 'multilingual_inactive',
            'message' => 'Neither Polylang nor WPML is active on this site.',
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        $requires = static fn () => self::requirement();
        $post_id  = [ 'type' => 'integer', 'minimum' => 1 ];
        $language = [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 20 ];

        return [
            'find-missing-translations' => [
                'mode'         => 'read',
                'tier'         => 'pro',
                'requires'     => $requires,
                'description'  => 'Posts of a type and status that have a language and no translation into language, paged.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'language'  => $language,
                        'post_type' => [ 'type' => 'string', 'minLength' => 1, 'default' => 'page' ],
                        'status'    => [ 'type' => 'string', 'enum' => self::STATUSES, 'default' => 'publish' ],
                        'page'      => [ 'type' => 'integer', 'minimum' => 1, 'default' => 1 ],
                        'per_page'  => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PER_PAGE, 'default' => 20 ],
                    ],
                    'required'   => [ 'language' ],
                ],
                'validate'     => static fn (array $args) => self::language_refusal((string) $args['language']),
                'handler'      => [ self::class, 'find_missing' ],
            ],
            'get-page-text' => [
                'mode'         => 'read',
                'tier'         => 'pro',
                'requires'     => $requires,
                'description'  => 'Every translatable string of a post (title, excerpt, SEO title and description, Elementor widget text, block and classic content) as segments {key, kind, text, where}, paged, with a content hash for apply-page-text.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'post_id'  => $post_id,
                        'page'     => [ 'type' => 'integer', 'minimum' => 1, 'default' => 1 ],
                        'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_SEGMENTS_PER_PAGE, 'default' => 100 ],
                    ],
                    'required'   => [ 'post_id' ],
                ],
                'objects'      => [ 'post_id' => 'post' ],
                'validate'     => static fn (array $args) => self::post_refusal((int) $args['post_id'], false),
                'handler'      => [ self::class, 'get_page_text' ],
            ],
            'create-translation' => [
                'mode'              => 'write',
                'tier'              => 'pro',
                'requires'          => $requires,
                'description'       => 'Copy a post into language as a linked draft: content, builder data, meta, template and featured image, terms mapped to their translations. Undo: rollback-operation.',
                'input_schema'      => [
                    'type'       => 'object',
                    'properties' => [
                        'post_id'  => $post_id,
                        'language' => $language,
                        'title'    => [ 'type' => 'string' ],
                    ],
                    'required'   => [ 'post_id', 'language' ],
                ],
                'objects'           => [ 'post_id' => 'post' ],
                'validate'          => [ self::class, 'create_refusal' ],
                'self_snapshotting' => true,
                'handler'           => [ self::class, 'create_translation' ],
            ],
            'apply-page-text' => [
                'mode'         => 'write',
                'tier'         => 'pro',
                'requires'     => $requires,
                'description'  => 'Write translated segments {key, text} from get-page-text back as one change, all or none. A stale hash is refused unless force:true.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'post_id'  => $post_id,
                        'hash'     => [ 'type' => 'string', 'minLength' => 1 ],
                        'segments' => [
                            'type'     => 'array',
                            'minItems' => 1,
                            'items'    => [
                                'type'       => 'object',
                                'properties' => [
                                    'key'  => [ 'type' => 'string', 'minLength' => 1 ],
                                    'text' => [ 'type' => 'string' ],
                                ],
                                'required'   => [ 'key', 'text' ],
                            ],
                        ],
                        'force'    => [ 'type' => 'boolean' ],
                    ],
                    'required'   => [ 'post_id', 'hash', 'segments' ],
                ],
                'objects'      => [ 'post_id' => 'post' ],
                'validate'     => [ self::class, 'apply_refusal' ],
                'snapshot'     => static fn (array $args) => [
                    'object_type'         => 'post',
                    'object_id'           => (int) $args['post_id'],
                    'extra_snapshot_data' => [ 'hold_translation_sync' => true ],
                ],
                'handler'      => [ self::class, 'apply_page_text' ],
            ],
        ];
    }

    // ---------------------------------------------------------------
    // Checks
    // ---------------------------------------------------------------

    /** @return array{code: string, message: string, data: array}|null */
    private static function language_refusal(string $language): ?array
    {
        $codes = array_column(I18n_Adapter::list_languages(), 'code');
        if (in_array($language, $codes, true)) {
            return null;
        }
        return [
            'code'    => 'unknown_language',
            'message' => sprintf('"%s" is not a language of this site.', $language),
            'data'    => [ 'languages' => $codes ],
        ];
    }

    /**
     * A post the caller may not reach is answered as missing, like
     * duplicate-post answers it: a private type, or protected content they
     * cannot edit (issues #450, #483).
     *
     * @return array{code: string, message: string, data: array}|null
     */
    private static function post_refusal(int $post_id, bool $write): ?array
    {
        $post = get_post($post_id);
        if (! $post instanceof \WP_Post || ! Content_Guard::is_agent_readable_post_type((string) $post->post_type) || Content_Guard::withholds_protected_content($post)) {
            return [ 'code' => 'post_not_found', 'message' => sprintf('Post %d does not exist.', $post_id), 'data' => [] ];
        }
        return Plugin_Data_Integration::post_refusal($post_id, $write);
    }

    /** @return array{code: string, message: string, data: array}|null */
    public static function create_refusal(array $args): ?array
    {
        $post_id  = (int) $args['post_id'];
        $language = (string) $args['language'];
        $refusal  = self::post_refusal($post_id, true) ?? self::language_refusal($language);
        if (null !== $refusal) {
            return $refusal;
        }
        $post = get_post($post_id);
        $type = (string) $post->post_type;
        if (! I18n_Adapter::is_translated_post_type($type)) {
            return [ 'code' => 'post_type_not_translated', 'message' => sprintf('Posts of type "%s" are not translated on this site.', $type), 'data' => [] ];
        }
        $source_language = I18n_Adapter::get_post_language($post_id);
        if ('' === $source_language) {
            return [ 'code' => 'source_language_missing', 'message' => sprintf('Post %d has no language yet; set one with set-post-language first.', $post_id), 'data' => [] ];
        }
        if ($source_language === $language) {
            return [ 'code' => 'same_language', 'message' => sprintf('Post %d is already in "%s".', $post_id, $language), 'data' => [] ];
        }
        $existing = (int) (I18n_Adapter::get_post_translations($post_id)[ $language ]['post_id'] ?? 0);
        if ($existing > 0) {
            return [ 'code' => 'translation_exists', 'message' => sprintf('Post %d already has a "%s" translation, post %d.', $post_id, $language, $existing), 'data' => [ 'post_id' => $existing ] ];
        }
        $denial = Post_Status_Guard::denial($type, 'draft', true);
        if (null !== $denial) {
            return [ 'code' => 'operation_denied', 'message' => $denial, 'data' => [ 'reason' => 'capability' ] ];
        }
        return null;
    }

    /** @return array{code: string, message: string, data: array}|null */
    public static function apply_refusal(array $args): ?array
    {
        $post_id = (int) $args['post_id'];
        $refusal = self::post_refusal($post_id, true);
        if (null !== $refusal) {
            return $refusal;
        }
        $post  = get_post($post_id);
        $known = array_column(I18n_Page_Text::segments($post), 'key');
        $keys  = array_map(static fn ($s) => (string) $s['key'], (array) $args['segments']);

        $repeated = array_values(array_unique(array_diff_assoc($keys, array_unique($keys))));
        if ([] !== $repeated) {
            return [ 'code' => 'duplicate_segment', 'message' => sprintf('Each segment may be given once: %s.', implode(', ', $repeated)), 'data' => [ 'duplicate' => $repeated ] ];
        }
        $unknown = array_values(array_diff($keys, $known));
        if ([] !== $unknown) {
            return [ 'code' => 'unknown_segment', 'message' => sprintf('Not a segment of post %d: %s. Read them with get-page-text.', $post_id, implode(', ', $unknown)), 'data' => [ 'unknown' => $unknown ] ];
        }
        $hash = I18n_Page_Text::hash($post);
        if ($hash !== (string) $args['hash'] && true !== ($args['force'] ?? false)) {
            return [ 'code' => 'stale_hash', 'message' => sprintf('Post %d changed since its text was read. Read it again with get-page-text, or pass force:true.', $post_id), 'data' => [ 'hash' => $hash ] ];
        }
        return null;
    }

    // ---------------------------------------------------------------
    // Handlers
    // ---------------------------------------------------------------

    /** @return array<string, mixed> */
    public static function find_missing(array $args): array
    {
        $language  = (string) $args['language'];
        $post_type = sanitize_key((string) ($args['post_type'] ?? 'page'));
        $status    = (string) ($args['status'] ?? 'publish');
        $page      = max(1, (int) ($args['page'] ?? 1));
        $per_page  = min(self::MAX_PER_PAGE, max(1, (int) ($args['per_page'] ?? 20)));

        $ids = Content_Guard::can_read_post_type($post_type) ? Readable_Posts::get_posts([
            'post_type'        => $post_type,
            'post_status'      => $status,
            'posts_per_page'   => -1,
            'fields'           => 'ids',
            'orderby'          => 'ID',
            'order'            => 'ASC',
            // Every language: Polylang otherwise narrows a query to one.
            'lang'             => '',
        ]) : [];

        $missing = [];
        foreach ($ids as $id) {
            $id   = (int) $id;
            $lang = I18n_Adapter::get_post_language($id);
            if ('' === $lang || $language === $lang) {
                continue;
            }
            $translations = I18n_Adapter::get_post_translations($id);
            if (! isset($translations[ $language ])) {
                $missing[ $id ] = [ $lang, $translations ];
            }
        }

        $items = [];
        foreach (array_slice($missing, ($page - 1) * $per_page, $per_page, true) as $id => [ $lang, $translations ]) {
            $items[] = [
                'post_id'      => $id,
                'title'        => (string) get_the_title($id),
                'language'     => $lang,
                'status'       => (string) get_post_status($id),
                'translations' => array_map(static fn ($t) => (int) $t['post_id'], $translations),
            ];
        }

        return [
            'language'  => $language,
            'post_type' => $post_type,
            'status'    => $status,
            'total'     => count($missing),
            'page'      => $page,
            'per_page'  => $per_page,
            'pages'     => (int) ceil(count($missing) / $per_page),
            'items'     => $items,
        ];
    }

    /** @return array<string, mixed> */
    public static function get_page_text(array $args): array
    {
        $post     = get_post((int) $args['post_id']);
        $page     = max(1, (int) ($args['page'] ?? 1));
        $per_page = min(self::MAX_SEGMENTS_PER_PAGE, max(1, (int) ($args['per_page'] ?? 100)));
        $segments = I18n_Page_Text::segments($post);

        return [
            'post_id'  => (int) $post->ID,
            'language' => I18n_Adapter::get_post_language((int) $post->ID),
            'builder'  => I18n_Page_Text::builder($post),
            'hash'     => I18n_Page_Text::hash($post),
            'total'    => count($segments),
            'page'     => $page,
            'per_page' => $per_page,
            'pages'    => (int) ceil(count($segments) / $per_page),
            'segments' => array_slice($segments, ($page - 1) * $per_page, $per_page),
        ];
    }

    /**
     * The copy, its language and terms, then its undo point, then the link
     * into the source's group, all with the plugin's sync held off. A
     * failure after the copy exists removes it again.
     *
     * @param array<string, mixed> $context
     * @return array{result: array<string, mixed>, operation_ids: string[]}
     */
    public static function create_translation(array $args, array $context): array
    {
        $source   = get_post((int) $args['post_id']);
        $language = (string) $args['language'];

        return I18n_Adapter::without_sync(static function () use ($source, $language, $args, $context): array {
            $fields = [ 'post_status' => 'draft' ];
            if ('' !== (string) ($args['title'] ?? '')) {
                $fields['post_title'] = (string) $args['title'];
            }
            $new_id       = Duplicate_Post::insert_copy($source, $fields);
            $operation_id = null;
            $group        = [];
            $linked       = false;
            $unmapped     = [];
            try {
                I18n_Adapter::set_post_language($new_id, $language);
                Duplicate_Post::copy_terms($source, $new_id, static function (string $taxonomy, array $terms) use ($language, &$unmapped): ?array {
                    if (in_array($taxonomy, I18n_Adapter::POLYLANG_TAXONOMIES, true)) {
                        return null;
                    }
                    $mapped = [];
                    foreach ($terms as $term_id) {
                        $translation = I18n_Adapter::get_term_translation($term_id, $taxonomy, $language);
                        if ($translation > 0) {
                            $mapped[] = $translation;
                            continue;
                        }
                        $term       = get_term($term_id, $taxonomy);
                        $unmapped[] = [ 'taxonomy' => $taxonomy, 'term_id' => $term_id, 'name' => $term instanceof \WP_Term ? (string) $term->name : '' ];
                    }
                    return $mapped;
                });

                $thumbnail = (int) get_post_thumbnail_id($source);
                if ($thumbnail > 0) {
                    $translated = I18n_Adapter::get_attachment_translation($thumbnail, $language);
                    if ($translated !== $thumbnail) {
                        update_post_meta($new_id, '_thumbnail_id', $translated);
                    }
                }

                $group        = I18n_Adapter::capture_translation_group((int) $source->ID);
                $operation_id = Post_Creation_Snapshot::record(
                    'create-translation',
                    [ $new_id ],
                    $args,
                    (string) ($context['session_id'] ?? 'default'),
                    false,
                    $group
                );
                $linked = true;
                I18n_Adapter::add_translation((int) $source->ID, $new_id, $language);
            } catch (\Throwable $e) {
                if ($linked && [] !== $group) {
                    I18n_Adapter::restore_translation_group($group, $new_id);
                }
                if (null !== $operation_id) {
                    Snapshot_Store::delete_operation($operation_id);
                }
                wp_delete_post($new_id, true);
                throw $e;
            }

            return [
                'result'        => [
                    'source_id'      => (int) $source->ID,
                    'post_id'        => $new_id,
                    'language'       => $language,
                    'status'         => (string) get_post_status($new_id),
                    'translations'   => array_map(static fn ($t) => (int) $t['post_id'], I18n_Adapter::get_post_translations((int) $source->ID)),
                    'unmapped_terms' => $unmapped,
                    'edit_link'      => (string) get_edit_post_link($new_id, 'raw'),
                ],
                'operation_ids' => [ $operation_id ],
            ];
        });
    }

    /** @return array<string, mixed> */
    public static function apply_page_text(array $args): array
    {
        $post     = get_post((int) $args['post_id']);
        $previous = I18n_Page_Text::hash($post);
        $texts    = [];
        foreach ((array) $args['segments'] as $segment) {
            $texts[ (string) $segment['key'] ] = (string) $segment['text'];
        }

        $written = I18n_Adapter::without_sync(static fn () => I18n_Page_Text::apply($post, $texts));
        $post    = get_post((int) $args['post_id']);

        return [
            'post_id'       => (int) $post->ID,
            'written'       => $written,
            'previous_hash' => $previous,
            'hash'          => I18n_Page_Text::hash($post),
        ];
    }
}
