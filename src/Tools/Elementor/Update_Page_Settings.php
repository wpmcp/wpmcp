<?php

namespace WPMCP\Tools\Elementor;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Non-destructive merge into a page's `_elementor_page_settings`
 * (issue #58): given keys are overwritten or added, every other stored
 * setting survives. Guarded by expected_hash over the settings meta (the
 * settings_hash reported by get-elementor-data), so a stale read is
 * refused before anything is written. Post fields (title, status,
 * template, ...) are refused - Elementor's settings manager would apply
 * them to the post itself, which belongs to the post tools, not here.
 */
class Update_Page_Settings
{
    /**
     * Keys Elementor's page settings manager treats as post fields rather
     * than page settings (it writes them to the post/post meta directly).
     */
    private const POST_FIELD_KEYS = [
        'post_title',
        'post_excerpt',
        'post_status',
        'comment_status',
        'menu_order',
        'template',
        'post_featured_image',
    ];

    public function handle(array $args)
    {
        $settings = is_array($args['settings'] ?? null) ? $args['settings'] : [];
        if ([] === $settings) {
            return new \WP_Error('missing_settings', 'A non-empty settings object is required.');
        }

        $refused = array_values(array_intersect(array_keys($settings), self::POST_FIELD_KEYS));
        if ([] !== $refused) {
            return new \WP_Error(
                'unsupported_setting',
                sprintf(
                    'Refusing post field key(s) %s: these change the post itself, not its Elementor page settings. Use the post tools instead.',
                    implode(', ', $refused)
                )
            );
        }

        $read = Element_Tree::read_settings_for_edit($args);
        if (is_wp_error($read)) {
            return $read;
        }
        [$post_id, $current] = $read;

        return Element_Tree::write_settings($post_id, array_merge($current, $settings), 'update-page-settings', $args);
    }
}
