<?php

namespace WPMCP\Tools\WidgetBuilder;

use WPMCP\Safety\Save_Filters;

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
     * @param string $status 'publish' (active) or 'draft' (inactive, e.g. a
     *                       marketplace install awaiting review).
     * @return int|\WP_Error the new widget post id, or a WP_Error when the
     *                       template does not survive the markup gate.
     */
    public static function create(array $spec, string $status = 'publish')
    {
        self::ensure_post_type();
        if (! in_array($status, ['publish', 'draft'], true)) {
            return new \WP_Error('invalid_status', 'status must be publish or draft.');
        }
        $spec = self::gate_template(Widget_Spec::normalize($spec));
        if (is_wp_error($spec)) {
            return $spec;
        }

        $id = wp_insert_post(wp_slash([
            'post_type'   => self::POST_TYPE,
            'post_status' => $status,
            'post_title'  => sanitize_text_field((string) $spec['title']),
            // Pinned even for a draft, where WordPress would otherwise leave
            // the GMT date empty and stamp a new one on first publish. The
            // creation row's identity check compares post_date_gmt, so an
            // inactive spec activated later must keep the date it was born
            // with, or rolling back its creation would skip it as a
            // reclaimed id.
            'post_date_gmt' => gmdate('Y-m-d H:i:s'),
            'post_name'   => $spec['name'],
        ]), true);

        if (is_wp_error($id)) {
            return $id;
        }
        $id = (int) $id;
        update_post_meta($id, '_wpmcp_widget_spec', wp_slash($spec));

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
        Save_Filters::update_post(wp_slash(['ID' => $id, 'post_title' => sanitize_text_field((string) $spec['title'])]));
        update_post_meta($id, '_wpmcp_widget_spec', wp_slash($spec));
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
        // No suppress_filters: get_posts() defaults it to true, and the explicit
        // argument is what Plugin Check flags. Not false either, since this reads a
        // plugin-internal post type with no translated content to correct.
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

    /**
     * The id of the widget whose stored spec name is exactly $name, or null.
     *
     * A targeted lookup rather than a scan of all(), which stops at 200 rows:
     * a LIKE on the serialized spec's `"name";s:N:"<name>";` fragment narrows
     * the candidates in SQL, and each candidate is then confirmed against the
     * unserialized spec, since a control or attribute carrying the same name
     * would match the fragment too.
     */
    public static function find_by_name(string $name): ?int
    {
        if ('' === $name) {
            return null;
        }
        self::ensure_post_type();
        $fragment = '"name";s:' . strlen($name) . ':"' . $name . '";';
        $page     = 1;
        do {
            $ids = get_posts([
                'post_type'      => self::POST_TYPE,
                'post_status'    => ['publish', 'draft'],
                'fields'         => 'ids',
                'posts_per_page' => 50,
                'paged'          => $page,
                'orderby'        => 'ID',
                'order'          => 'ASC',
                'meta_query'     => [['key' => '_wpmcp_widget_spec', 'value' => $fragment, 'compare' => 'LIKE']], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Plugin-internal post type, queried only on a marketplace install; the alternative is loading every spec.
            ]);
            foreach ($ids as $id) {
                $spec = get_post_meta((int) $id, '_wpmcp_widget_spec', true);
                if (is_array($spec) && ($spec['name'] ?? null) === $name) {
                    return (int) $id;
                }
            }
            $page++;
        } while (50 === count($ids));

        return null;
    }
}
