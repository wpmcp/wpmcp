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
 *
 * The template is the one field that reaches the front end as markup
 * (Widget_Renderer::render() interpolates escaped values into it but returns
 * the template itself unchanged), so this is where the markup trust decision
 * is made: verbatim for authors who hold `unfiltered_html`, wp_kses_post for
 * everyone else. The abilities are gated on `manage_options`, which on
 * multisite a site administrator has WITHOUT `unfiltered_html`, so the two are
 * not interchangeable and the capability has to be checked here.
 *
 * The gate runs on write only. A spec stored before it existed (or pulled in
 * by another write path) is not filtered retroactively; what is in postmeta
 * is what the renderer outputs.
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

    /**
     * @return int|\WP_Error the new widget post id, or a WP_Error when the
     *                       template does not survive the markup gate.
     */
    public static function create(array $spec)
    {
        self::ensure_post_type();
        $spec = self::gate_template(Widget_Spec::normalize($spec));
        if (is_wp_error($spec)) {
            return $spec;
        }

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

    /**
     * @return true|false|\WP_Error false when $id is not a widget, a WP_Error
     *                              when the template does not survive the markup
     *                              gate (the stored spec is left untouched).
     */
    public static function update(int $id, array $spec)
    {
        if (! self::is_widget($id)) {
            return false;
        }
        $spec = self::gate_template(Widget_Spec::normalize($spec));
        if (is_wp_error($spec)) {
            return $spec;
        }
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
            'suppress_filters' => true,
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

    /**
     * Whether the template stored under $id differs from the one in $submitted,
     * i.e. whether gate_template() rewrote it on the way in. The abilities use
     * this to tell the caller that the spec they sent is not the spec that was
     * stored, instead of reporting a silent success.
     */
    public static function template_was_filtered(array $submitted, int $id): bool
    {
        $stored = self::get($id);
        if (null === $stored) {
            return false;
        }

        return (string) ($submitted['template'] ?? '') !== (string) ($stored['template'] ?? '');
    }

    /**
     * Applies WordPress's own markup-authoring rule to the spec's template.
     *
     * Core does exactly this for post_content: a user with `unfiltered_html`
     * stores what they wrote, everyone else goes through wp_kses_post. The
     * template is rendered verbatim on the public front end, so it gets the
     * same treatment rather than being trusted on the strength of
     * `manage_options` alone.
     *
     * Known cost of the kses path: safecss_filter_attr() rejects any CSS
     * declaration containing `}`, so a `{{placeholder}}` inside a style
     * attribute drops the whole attribute. Template authors without
     * `unfiltered_html` have to put dynamic colours and sizes somewhere else
     * (a class, a data attribute, a CSS custom property set via a wrapper).
     *
     * Widget_Spec::validate() already required a non-empty template; when kses
     * leaves nothing behind (an <iframe>- or <script>-only template, say) the
     * write is refused rather than stored empty.
     *
     * @return array|\WP_Error
     */
    private static function gate_template(array $spec)
    {
        if (current_user_can('unfiltered_html')) {
            return $spec;
        }

        $filtered = wp_kses_post((string) ($spec['template'] ?? ''));
        if ('' === trim($filtered)) {
            return new \WP_Error(
                'template_filtered_empty',
                'The template contains no markup that survives wp_kses_post, so nothing would be rendered. '
                . 'Your account lacks the unfiltered_html capability; the template is filtered like post_content.'
            );
        }
        $spec['template'] = $filtered;

        return $spec;
    }
}
