<?php

namespace WPMCP\Tools\SiteEditor;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * site-templates-read (issue #378): list the active block theme's templates
 * and template parts, or wp_navigation menus, and read one as parsed blocks.
 *
 * Each template reports its source ('theme' for the theme file, 'custom'
 * for a user customization), whether a theme file backs it, whether a
 * customization exists and, for a part, its area. On a classic theme the
 * lists are empty and a message says why. Reading never writes, not even
 * for a template that only lives in a theme file.
 *
 * Entity global_styles (issue #379) reads the theme.json layers instead;
 * see Global_Styles.
 */
class Site_Templates_Read
{
    public function handle(array $args): array
    {
        $entity = (string) ($args['entity'] ?? '');
        $id     = $args['id'] ?? null;

        if ('global_styles' === $entity) {
            return (new Global_Styles())->read($id);
        }
        if ('navigation' === $entity) {
            return null === $id || '' === $id ? $this->list_navigation() : $this->read_navigation($id);
        }
        if ('' !== $entity && null !== $id && '' !== $id) {
            return $this->read_template($entity, (string) $id);
        }
        return $this->list_templates($entity);
    }

    private function list_templates(string $entity): array
    {
        $entities = '' === $entity ? array_keys(Site_Templates::POST_TYPES) : [$entity];
        $out      = [
            'theme'       => get_stylesheet(),
            'block_theme' => wp_is_block_theme(),
        ];
        $unsupported = false;
        foreach ($entities as $name) {
            $post_type = Site_Templates::post_type($name);
            $key       = $name . 's';
            if (! Site_Templates::supported($post_type)) {
                $out[ $key ] = [];
                $unsupported = true;
                continue;
            }
            $out[ $key ] = array_values(array_map(
                [Site_Templates::class, 'summary'],
                get_block_templates([], $post_type)
            ));
        }
        if ($unsupported) {
            $out['message'] = Site_Templates::classic_message();
        }
        return $out;
    }

    private function read_template(string $entity, string $id): array
    {
        $resolved = Site_Templates::resolve($entity, $id);
        if (null === $resolved['template']) {
            throw new \InvalidArgumentException(sprintf(
                'No %s "%s" in the active theme (%s).',
                esc_html(str_replace('_', ' ', $entity)),
                esc_html($resolved['slug']),
                esc_html($resolved['theme'])
            ));
        }
        $template = $resolved['template'];

        return Site_Templates::summary($template)
            + ['description' => (string) $template->description]
            + Site_Templates::blocks(Site_Templates::content($template));
    }

    private function list_navigation(): array
    {
        $posts = get_posts([
            'post_type'      => 'wp_navigation',
            'post_status'    => ['publish', 'draft'],
            'posts_per_page' => 100,
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ]);

        return [
            'navigation' => array_map(static fn (\WP_Post $post): array => [
                'id'       => $post->ID,
                'title'    => $post->post_title,
                'status'   => $post->post_status,
                'modified' => $post->post_modified_gmt,
            ], $posts),
        ];
    }

    private function read_navigation($id): array
    {
        $post = Site_Templates::navigation($id);

        return [
            'id'     => $post->ID,
            'title'  => $post->post_title,
            'status' => $post->post_status,
        ] + Site_Templates::blocks((string) $post->post_content);
    }
}
