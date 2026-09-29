<?php

namespace WPMCP\Tools\SiteEditor;

use WPMCP\Tools\Blocks\Parse_Blocks;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Shared lookups for the block theme template tools (issue #378). Not a
 * tool itself.
 *
 * Entities: 'template' (wp_template), 'template_part' (wp_template_part)
 * and 'navigation' (wp_navigation). A template or part is addressed the way
 * the site editor addresses it, "<theme>//<slug>", or by its bare slug,
 * which means the active theme. Only the active theme's templates are
 * editable, as in the site editor.
 *
 * Content model: a customized template's content is its post's stored
 * post_content, byte for byte, so a content_hash read here is what a later
 * path edit is checked against. A template with no customization reads as
 * core resolves it from the theme file.
 */
class Site_Templates
{
    /** Entity name => the post type core stores its customizations in. */
    public const POST_TYPES = [
        'template'      => 'wp_template',
        'template_part' => 'wp_template_part',
    ];

    public static function post_type(string $entity): string
    {
        if (! isset(self::POST_TYPES[ $entity ])) {
            throw new \InvalidArgumentException('"entity" must be one of: template, template_part, navigation, global_styles.');
        }
        return self::POST_TYPES[ $entity ];
    }

    /**
     * Whether the active theme has site editor templates of this type: a
     * block theme has both, and a classic theme that declares
     * block-template-parts support has parts only.
     */
    public static function supported(string $post_type): bool
    {
        if (wp_is_block_theme()) {
            return true;
        }
        return 'wp_template_part' === $post_type && current_theme_supports('block-template-parts');
    }

    public static function classic_message(): string
    {
        return sprintf(
            'The active theme (%s) is a classic theme: it has no site editor templates, and its header, footer and layouts live in PHP theme files. Use theme-read and the menu tools instead.',
            get_stylesheet()
        );
    }

    /**
     * Resolve an id to the active theme's template of this type.
     *
     * @return array{theme:string,slug:string,post_type:string,template:?\WP_Block_Template}
     */
    public static function resolve(string $entity, string $id): array
    {
        $post_type = self::post_type($entity);
        if (! self::supported($post_type)) {
            throw new \InvalidArgumentException(esc_html(self::classic_message()));
        }

        $theme = get_stylesheet();
        $slug  = trim($id);
        if (str_contains($slug, '//')) {
            [$given_theme, $slug] = explode('//', $slug, 2);
            if ($given_theme !== $theme) {
                throw new \InvalidArgumentException(sprintf(
                    'Template "%s" belongs to %s, not the active theme (%s); only the active theme\'s templates can be read or edited.',
                    esc_html($id),
                    esc_html($given_theme),
                    esc_html($theme)
                ));
            }
        }
        if ('' === $slug || sanitize_title($slug) !== $slug) {
            throw new \InvalidArgumentException('"id" must be a template slug, or "<theme>//<slug>".');
        }

        $template = get_block_template($theme . '//' . $slug, $post_type);

        return [
            'theme'     => $theme,
            'slug'      => $slug,
            'post_type' => $post_type,
            'template'  => $template instanceof \WP_Block_Template ? $template : null,
        ];
    }

    /** One template's listing row. */
    public static function summary(\WP_Block_Template $template): array
    {
        $row = [
            'id'             => $template->id,
            'slug'           => $template->slug,
            'title'          => is_string($template->title) ? $template->title : '',
            'source'         => $template->source,
            'has_theme_file' => (bool) $template->has_theme_file,
            'customized'     => (int) $template->wp_id > 0,
            'wp_id'          => (int) $template->wp_id,
        ];
        if ('wp_template_part' === $template->type) {
            $row['area'] = (string) $template->area;
        }
        return $row;
    }

    /**
     * The content a path edit works on and a read hashes: the stored
     * customization when there is one, else what core resolved from the file.
     */
    public static function content(\WP_Block_Template $template): string
    {
        if ((int) $template->wp_id > 0) {
            $post = get_post((int) $template->wp_id);
            if ($post) {
                return (string) $post->post_content;
            }
        }
        return (string) $template->content;
    }

    /** Parsed blocks plus content and content_hash, as parse-blocks reports them. */
    public static function blocks(string $content): array
    {
        return ['content' => $content] + (new Parse_Blocks())->handle(['blocks' => $content]);
    }

    /** A wp_navigation post by id, or a refusal. */
    public static function navigation($id): \WP_Post
    {
        $post = is_numeric($id) ? get_post((int) $id) : null;
        if (! $post || 'wp_navigation' !== $post->post_type) {
            throw new \InvalidArgumentException(sprintf(
                'Navigation %s not found: "id" must be the ID of a wp_navigation menu (list them with entity navigation).',
                esc_html(is_scalar($id) ? (string) $id : '')
            ));
        }
        return $post;
    }
}
