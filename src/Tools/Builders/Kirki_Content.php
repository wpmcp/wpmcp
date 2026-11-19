<?php

namespace WPMCP\Tools\Builders;

use WPMCP\Tools\Content\Readable_Posts;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read/write access to a Kirki page builder layout (issue #641), recorded
 * from Kirki 6.4.2 (the freeform builder by Themeum, wordpress.org slug
 * kirki). The element operations are Kirki_Tree's.
 *
 * Storage, as Kirki's own save writes it (PageManager::save_blocks()):
 *
 * - `kirki` postmeta: ['blocks' => a map of elements by id]. The root
 *   element is `body` (parentId 'root'); every element names its parentId,
 *   and a container lists its children ids in order. Text lives in
 *   properties.contents, the HTML tag in properties.tag, and the classes
 *   applied in styleIds (the page's style blocks are kept in
 *   `kirki_global_style_block_random`, read only here).
 * - `kirki_editor_mode` = 'kirki': Kirki renders the page (it deletes the
 *   flag when the owner goes back to the WordPress editor).
 * - Unpublished versions live in Kirki's own `kirki_stages` and
 *   `kirki_stage_data` tables; the published layout stays in the postmeta.
 *   A write here changes the published layout only, so it is refused while
 *   the page has an unpublished version (publishing that version in Kirki
 *   would overwrite the change).
 *
 * Components (Kirki symbols) are `kirki_symbol` posts with the same `kirki`
 * row holding {data, root, styleBlocks, category, setAs}; a page places one
 * as a `symbol` element whose properties.symbolId names it.
 *
 * Every write is postmeta of the page (and, on a first layout, its page
 * template), which the post snapshot captures and restores. Kirki renders
 * from that row on each request, so there is no cache to rebuild.
 */
final class Kirki_Content
{
    public const META_KEY = 'kirki';

    public const MODE_KEY = 'kirki_editor_mode';

    public const STYLE_KEY = 'kirki_global_style_block_random';

    public const SYMBOL_POST_TYPE = 'kirki_symbol';


    /**
     * Whether the Kirki page builder is loaded. KIRKI_VERSION alone is not
     * enough: themes bundle the older Kirki customizer framework, which
     * defines it too; KIRKI_POST_TYPE comes only with the builder.
     */
    public static function plugin_active(): bool
    {
        return (bool) apply_filters('wpmcp_kirki_active', defined('KIRKI_VERSION') && defined('KIRKI_POST_TYPE'));
    }

    /** The loaded Kirki's version, or null when the builder is not loaded. */
    public static function plugin_version(): ?string
    {
        if (! self::plugin_active() || ! defined('KIRKI_VERSION')) {
            return null;
        }
        $version = (string) constant('KIRKI_VERSION');

        return '' === $version ? null : $version;
    }

    /** The refusal for a write that needs Kirki active. */
    public static function unavailable(): \WP_Error
    {
        $url = admin_url('plugins.php');

        return new \WP_Error(
            'plugin_missing',
            "The Kirki page builder is not active on this site, so Kirki pages cannot be changed. Install and activate Kirki page builder ({$url}), then try again.",
            ['status' => 403, 'plugin' => 'Kirki page builder', 'url' => $url]
        );
    }

    /** Whether Kirki renders this page. */
    public static function is_kirki_page(int $post_id): bool
    {
        return 'kirki' === get_post_meta($post_id, self::MODE_KEY, true);
    }

    /** sha256 of the stored layout row, the hash writes are pinned to. */
    public static function hash(int $post_id): string
    {
        $raw = get_post_meta($post_id, self::META_KEY, true);

        return hash('sha256', is_array($raw) ? serialize($raw) : (string) $raw); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- a fingerprint of the stored row, never unserialized.
    }

    /** @return array<string,array<string,mixed>> the stored elements by id, [] when none */
    public static function blocks(int $post_id): array
    {
        $row = get_post_meta($post_id, self::META_KEY, true);

        return is_array($row) && is_array($row['blocks'] ?? null) ? $row['blocks'] : [];
    }

    /** Whether the page holds a layout (a write to it then needs the hash). */
    public static function has_layout(int $post_id): bool
    {
        return [] !== self::blocks($post_id);
    }

    /**
     * The newest version of the page when Kirki holds it unpublished, or
     * null (no versions, the newest is the published one, or no Kirki
     * tables on this site).
     */
    public static function unpublished_version(int $post_id): ?int
    {
        global $wpdb;
        $table      = $wpdb->prefix . 'kirki_stages';
        $suppressed = $wpdb->suppress_errors(true);
        $row        = $wpdb->get_row($wpdb->prepare("SELECT version, is_published FROM {$table} WHERE post_id = %d ORDER BY version DESC LIMIT 1", $post_id), ARRAY_A); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Kirki's own version table, read only.
        $wpdb->suppress_errors($suppressed);

        if (! is_array($row) || ! empty($row['is_published'])) {
            return null;
        }

        return (int) $row['version'];
    }

    /** @return array<string,mixed>|\WP_Error */
    public static function read(int $post_id)
    {
        if (! current_user_can('read_post', $post_id)) {
            return new \WP_Error('forbidden', 'You are not allowed to read this post.', ['status' => 403]);
        }

        $blocks = self::blocks($post_id);
        $styles = get_post_meta($post_id, self::STYLE_KEY, true);

        return [
            'post_id'             => $post_id,
            'builder'             => 'kirki',
            'plugin_active'       => self::plugin_active(),
            'plugin_version'      => self::plugin_version(),
            'hash'                => self::hash($post_id),
            'unpublished_version' => self::unpublished_version($post_id),
            'root'                => Kirki_Tree::ROOT,
            'element_count'       => count($blocks),
            'tree'                => Kirki_Tree::outline($blocks),
            'blocks'              => $blocks,
            'styles'              => is_array($styles) ? $styles : [],
        ];
    }

    /**
     * Store a layout as Kirki's save does: the `kirki` row (other keys of
     * the row kept) and the editor mode flag. A page taking its first
     * layout also gets Kirki's full canvas page template, as Kirki's own
     * new page does, when it has no template of its own.
     *
     * @param array<string,array<string,mixed>> $blocks
     */
    public static function save(int $post_id, array $blocks): void
    {
        $first = ! self::is_kirki_page($post_id) && ! self::has_layout($post_id);
        $row   = get_post_meta($post_id, self::META_KEY, true);
        $row   = is_array($row) ? $row : [];

        $row['blocks'] = $blocks;
        update_post_meta($post_id, self::META_KEY, wp_slash($row));
        update_post_meta($post_id, self::MODE_KEY, 'kirki');

        if ($first && 'page' === get_post_type($post_id) && defined('KIRKI_FULL_CANVAS_TEMPLATE_PATH')) {
            $template = (string) get_post_meta($post_id, '_wp_page_template', true);
            if ('' === $template || 'default' === $template) {
                update_post_meta($post_id, '_wp_page_template', (string) constant('KIRKI_FULL_CANVAS_TEMPLATE_PATH'));
            }
        }
    }

    /**
     * The element placing a Kirki component (symbol) the caller may read.
     *
     * @return array<string,mixed>
     * @throws \InvalidArgumentException
     */
    public static function symbol_element($symbol): array
    {
        $id   = is_scalar($symbol) && ctype_digit((string) $symbol) ? (int) $symbol : 0;
        $post = $id > 0 ? get_post($id) : null;
        if (! $post || self::SYMBOL_POST_TYPE !== $post->post_type) {
            throw new \InvalidArgumentException(esc_html('element {symbol} needs the id of a Kirki component (scope templates lists them); got ' . (is_scalar($symbol) ? (string) $symbol : gettype($symbol)) . '.'));
        }
        if (! current_user_can('read_post', $id)) {
            throw new \InvalidArgumentException(esc_html("You are not allowed to read component {$id}."));
        }

        return ['name' => 'symbol', 'title' => (string) $post->post_title, 'properties' => ['symbolId' => $id]];
    }

    /**
     * get-builder-content's scopes for Kirki: templates (the components
     * the caller may read) and catalog (the element types this adapter
     * builds). The design system is not read yet.
     *
     * @return array<string,mixed>|\WP_Error
     */
    public static function design(string $scope, string $element)
    {
        if ('templates' === $scope) {
            $templates = [];
            $posts     = Readable_Posts::get_posts([
                'post_type'      => self::SYMBOL_POST_TYPE,
                'post_status'    => ['publish', 'draft', 'pending', 'private', 'future'],
                'posts_per_page' => 200,
                'orderby'        => 'ID',
                'order'          => 'ASC',
            ]);
            foreach ($posts as $post) {
                if (! $post instanceof \WP_Post) {
                    continue;
                }
                $row         = get_post_meta((int) $post->ID, self::META_KEY, true);
                $row         = is_array($row) ? $row : [];
                $templates[] = [
                    'id'       => (int) $post->ID,
                    'title'    => (string) $post->post_title,
                    'status'   => (string) $post->post_status,
                    'category' => (string) ($row['category'] ?? 'other'),
                    'set_as'   => (string) ($row['setAs'] ?? ''),
                    'elements' => is_array($row['data'] ?? null) ? count($row['data']) : 0,
                ];
            }

            return ['builder' => 'kirki', 'scope' => 'templates', 'templates' => $templates];
        }

        if ('catalog' === $scope) {
            $elements = [];
            foreach (Kirki_Tree::CATALOG as $name => $shape) {
                if ('' === $element || $element === $name) {
                    $elements[] = ['name' => $name] + $shape;
                }
            }

            return [
                'builder'       => 'kirki',
                'scope'         => 'catalog',
                'plugin_active' => self::plugin_active(),
                'elements'      => $elements,
                'note'          => 'Element types whose stored shape is known; add takes {name, title, text, properties, styleIds, className, children} or {symbol: id}.',
            ];
        }

        return new \WP_Error('unsupported_scope', "Kirki's scope reads are 'templates' and 'catalog'; got '{$scope}'.");
    }
}
