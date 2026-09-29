<?php

namespace WPMCP\Tools\Builders;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Helpers the Bricks and Breakdance design readers share (issue #391).
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

    public static function not_loaded(string $builder): \WP_Error
    {
        return new \WP_Error('builder_not_loaded', "The element catalog comes from the installed plugin, and '{$builder}' is not loaded on this site.");
    }

    public static function element_not_found(string $builder, string $element): \WP_Error
    {
        return new \WP_Error('element_not_found', "'{$builder}' has no element '{$element}'; read scope catalog without element for the list.");
    }
}
