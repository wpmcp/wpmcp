<?php

namespace WPMCP\Tools\WidgetBuilder;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Storage for custom-widget specs as `wpmcp_widget` posts (spec in the
 * _wpmcp_widget_spec meta, machine name in post_name, active/inactive via
 * post_status publish/draft). A spec is ordinary post + postmeta, so it is
 * reversible through the standard trash and could be snapshotted if writes ever
 * needed it; creation and status changes here are non-destructive.
 */
class Widget_Spec_Store
{
    public const POST_TYPE = 'wpmcp_widget';

    /** Register the CPT (idempotent). Called on init and defensively before use. */
    public static function ensure_post_type(): void
    {
        if (! post_type_exists(self::POST_TYPE)) {
            register_post_type(self::POST_TYPE, [
                'public'       => false,
                'show_ui'      => false,
                'show_in_rest' => false,
                'supports'     => ['title'],
            ]);
        }
    }

    /** @return int|\WP_Error the new widget post id. */
    public static function create(array $spec)
    {
        self::ensure_post_type();
        $spec = Widget_Spec::normalize($spec);

        $id = wp_insert_post([
            'post_type'   => self::POST_TYPE,
            'post_status' => 'publish',
            'post_title'  => sanitize_text_field((string) $spec['title']),
            'post_name'   => $spec['name'],
        ], true);

        if (is_wp_error($id)) {
            return $id;
        }
        $id = (int) $id;
        update_post_meta($id, '_wpmcp_widget_spec', $spec);

        return $id;
    }

    public static function update(int $id, array $spec): bool
    {
        if (! self::is_widget($id)) {
            return false;
        }
        $spec = Widget_Spec::normalize($spec);
        wp_update_post(['ID' => $id, 'post_title' => sanitize_text_field((string) $spec['title'])]);
        update_post_meta($id, '_wpmcp_widget_spec', $spec);
        return true;
    }

    public static function get(int $id): ?array
    {
        if (! self::is_widget($id)) {
            return null;
        }
        $spec = get_post_meta($id, '_wpmcp_widget_spec', true);
        return is_array($spec) ? $spec : null;
    }

    /** @return array<int,array{widget_id:int,name:string,title:string,status:string}> */
    public static function all(bool $active_only = false): array
    {
        self::ensure_post_type();
        $rows = get_posts([
            'post_type'        => self::POST_TYPE,
            'post_status'      => $active_only ? ['publish'] : ['publish', 'draft'],
            'posts_per_page'   => 200,
            'orderby'          => 'title',
            'order'            => 'ASC',
        ]);

        $out = [];
        foreach ($rows as $row) {
            $spec  = get_post_meta($row->ID, '_wpmcp_widget_spec', true);
            $out[] = [
                'widget_id' => $row->ID,
                'name'      => is_array($spec) ? (string) ($spec['name'] ?? $row->post_name) : $row->post_name,
                'title'     => get_the_title($row),
                'status'    => $row->post_status,
            ];
        }
        return $out;
    }

    public static function is_widget(int $id): bool
    {
        return $id > 0 && self::POST_TYPE === get_post_type($id);
    }
}
