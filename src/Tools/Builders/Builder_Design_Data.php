<?php

namespace WPMCP\Tools\Builders;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Helpers the Bricks and Breakdance design readers and the design writer
 * share (issue #391).
 * Kept apart from the scope dispatcher so the readers never point back at
 * it: references run one way (dispatcher, readers, this class), which lets
 * the directory build sweep all of them once the paid get-builder-content
 * registration is gone.
 */
final class Builder_Design_Data
{
    /**
     * Template posts of the given types in every editable status, oldest
     * first so the order is stable.
     *
     * @param string[] $post_types
     * @return \WP_Post[]
     */
    public static function posts(array $post_types): array
    {
        $posts = get_posts([
            'post_type'        => $post_types,
            'post_status'      => ['publish', 'draft', 'pending', 'private', 'future'],
            'posts_per_page'   => -1,
            'orderby'          => 'ID',
            'order'            => 'ASC',
            'no_found_rows'    => true,
        ]);

        return array_values(array_filter($posts, static fn ($post) => $post instanceof \WP_Post));
    }

    /**
     * Optimistic-lock fingerprint of one stored option: whether its row
     * exists and its exact stored value. The design_system read returns one
     * per writable list, and a design write is refused unless the caller
     * passes back the hash of the option it changes.
     */
    public static function option_hash(string $option): string
    {
        $missing = '__wpmcp_missing__' . $option;
        $value   = get_option($option, $missing);

        return hash('sha256', $missing === $value ? '0:' : ('1:' . maybe_serialize($value)));
    }

    /**
     * Decode a Breakdance engine `*_json_string` option. The engine's option
     * API (set_global_option()) JSON-encodes the JSON string it is given, so
     * the stored value is a JSON string literal holding the document; a
     * value holding the document directly is accepted too.
     *
     * @return array{0:bool,1:mixed} whether the option decoded (a missing
     *                               option decodes to null), and the document
     */
    public static function engine_decode(string $option): array
    {
        $raw = get_option($option, null);
        if (null === $raw || false === $raw || '' === $raw) {
            return [true, null];
        }
        if (is_array($raw)) {
            return [true, $raw];
        }

        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (is_string($decoded)) {
            $decoded = json_decode($decoded, true);
        }

        return is_array($decoded) ? [true, $decoded] : [false, null];
    }

    /** Encode a document the way the engine's set_global_option() stores it. */
    public static function engine_encode(array $document): string
    {
        return (string) wp_json_encode((string) wp_json_encode($document));
    }

    public static function not_loaded(string $builder): \WP_Error
    {
        return new \WP_Error('builder_not_loaded', "The element catalog comes from the installed plugin, and '{$builder}' is not loaded on this site.");
    }

    public static function element_not_found(string $builder, string $element): \WP_Error
    {
        return new \WP_Error('element_not_found', "'{$builder}' has no element '{$element}'; read scope catalog without element for the list.");
    }
}
