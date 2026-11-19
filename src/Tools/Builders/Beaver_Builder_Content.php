<?php

namespace WPMCP\Tools\Builders;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read/write access to a page's Beaver Builder layout, which lives in
 * postmeta: `_fl_builder_data` (the published node map), `_fl_builder_draft`
 * (the editor's working copy), their `_settings` twins (layout CSS/JS) and
 * the `_fl_builder_enabled` flag. See Beaver_Builder_Nodes for the node
 * format. All of it is ordinary postmeta, so the post snapshot in
 * Safe_Mutation::run() captures and restores every row exactly.
 *
 * Writes follow what Beaver Builder itself does when it publishes or
 * restores a revision: the new node map goes to `_fl_builder_data` and, when
 * the page has a draft, to `_fl_builder_draft` too, so the editor opens on
 * what is live; the builder is enabled; the layout asset cache is cleared.
 * A page with no draft keeps none: the editor copies the published layout
 * and its settings into a new draft when it opens. The layout settings rows
 * are never touched.
 *
 * A draft that differs from the published layout holds unpublished editor
 * changes. Writing over it would discard them and writing beside it would
 * be overwritten by the next publish, so such a page is refused until the
 * draft is published or discarded in the editor.
 *
 * `post_content` keeps the text copy Beaver Builder rendered on its last
 * publish; rendering a new one needs the plugin's front end, so it is left
 * alone.
 */
class Beaver_Builder_Content
{
    public const DATA_META_KEY    = '_fl_builder_data';
    public const DRAFT_META_KEY   = '_fl_builder_draft';
    public const ENABLED_META_KEY = '_fl_builder_enabled';

    /** Suffix of the layout settings (CSS/JS) row beside each node map row. */
    public const SETTINGS_SUFFIX = '_settings';

    /**
     * The stored node map for a status, or [] when absent or unreadable.
     *
     * @return array<string,object>
     */
    public static function get_nodes(int $post_id, string $key = self::DATA_META_KEY): array
    {
        $raw = get_post_meta($post_id, $key, true);
        if (! is_array($raw)) {
            return [];
        }

        return array_filter($raw, 'is_object');
    }

    /** Whether the draft holds editor changes the published layout lacks. */
    public static function draft_pending(int $post_id): bool
    {
        if (! metadata_exists('post', $post_id, self::DRAFT_META_KEY)) {
            return false;
        }

        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- the comparison Beaver Builder itself makes.
        return serialize(self::get_nodes($post_id, self::DRAFT_META_KEY)) !== serialize(self::get_nodes($post_id));
    }

    /** @return array<string,mixed> */
    public static function read(int $post_id): array
    {
        return [
            'post_id'       => $post_id,
            'builder'       => 'beaver-builder',
            'plugin_active'  => Beaver_Builder_Cache::plugin_active(),
            'plugin_version' => self::plugin_version(),
            'draft_pending'  => self::draft_pending($post_id),
            'hash'           => self::hash($post_id),
            'revisions'      => Beaver_Builder_Page::revisions($post_id),
            'tree'           => Beaver_Builder_Nodes::tree(self::get_nodes($post_id)),
        ];
    }

    /** The loaded Beaver Builder's version, or null when it is not loaded. */
    public static function plugin_version(): ?string
    {
        if (! Beaver_Builder_Cache::plugin_active() || ! defined('FL_BUILDER_VERSION')) {
            return null;
        }
        $version = (string) constant('FL_BUILDER_VERSION');

        return '' === $version ? null : $version;
    }

    /**
     * sha256 over the published and draft node maps as stored, the hash
     * writes are pinned to (issue #638): an editor save to either changes it.
     */
    public static function hash(int $post_id): string
    {
        $rows = [];
        foreach ([self::DATA_META_KEY, self::DRAFT_META_KEY] as $key) {
            $rows[] = metadata_exists('post', $post_id, $key) ? '1:' . maybe_serialize(get_post_meta($post_id, $key, true)) : '0:';
        }

        return hash('sha256', implode("\n", $rows));
    }

    /** Whether the page holds a layout, published or draft. */
    public static function has_layout(int $post_id): bool
    {
        return [] !== self::get_nodes($post_id) || [] !== self::get_nodes($post_id, self::DRAFT_META_KEY);
    }

    /** A layout settings row (the CSS/JS object), or null when absent. */
    public static function get_settings(int $post_id, string $key = self::DATA_META_KEY): ?object
    {
        $value = get_post_meta($post_id, $key . self::SETTINGS_SUFFIX, true);

        return is_object($value) ? $value : null;
    }

    /**
     * Put a whole layout live as Beaver Builder's restore_revision() and
     * publish do: the node map and its settings to the published rows and,
     * when the page has a draft, to the draft rows too; then enable the
     * builder and clear the asset cache.
     *
     * @param array<string,object> $nodes
     */
    public static function save_layout(int $post_id, array $nodes, ?object $settings): void
    {
        foreach ([self::DATA_META_KEY, self::DRAFT_META_KEY] as $key) {
            if (self::DRAFT_META_KEY === $key && ! metadata_exists('post', $post_id, $key)) {
                continue;
            }
            if (null !== $settings) {
                update_post_meta($post_id, $key . self::SETTINGS_SUFFIX, self::slash_value($settings));
            }
        }

        self::save($post_id, $nodes);
    }

    /**
     * Append a template's CSS and JS to the page's layout settings rows (the
     * published one, and the draft's when there is one) the way Beaver
     * Builder's merge_layout_settings() does: once, on a new line.
     */
    public static function merge_settings(int $post_id, ?object $merge): void
    {
        if (null === $merge) {
            return;
        }
        foreach ([self::DATA_META_KEY, self::DRAFT_META_KEY] as $key) {
            if (self::DRAFT_META_KEY === $key && ! metadata_exists('post', $post_id, $key)) {
                continue;
            }
            $settings = self::get_settings($post_id, $key) ?? (object) ['css' => '', 'js' => ''];
            $changed  = false;
            foreach (['css', 'js'] as $part) {
                $add = is_string($merge->{$part} ?? null) ? $merge->{$part} : '';
                $now = is_string($settings->{$part} ?? null) ? $settings->{$part} : '';
                if ('' === $add || false !== strpos($now, $add)) {
                    continue;
                }
                $settings->{$part} = ('' === $now ? '' : $now . "\n") . $add;
                $changed           = true;
            }
            if ($changed) {
                update_post_meta($post_id, $key . self::SETTINGS_SUFFIX, self::slash_value($settings));
            }
        }
    }

    /**
     * Store a node map as the published layout (and the draft, when one
     * exists), enable the builder, and clear the layout asset cache.
     *
     * @param array<string,object> $nodes
     */
    public static function save(int $post_id, array $nodes): void
    {
        update_post_meta($post_id, self::DATA_META_KEY, self::slash($nodes));

        if (metadata_exists('post', $post_id, self::DRAFT_META_KEY)) {
            update_post_meta($post_id, self::DRAFT_META_KEY, self::slash($nodes));
        }

        if ('1' !== (string) get_post_meta($post_id, self::ENABLED_META_KEY, true)) {
            update_post_meta($post_id, self::ENABLED_META_KEY, true);
        }

        Beaver_Builder_Cache::clear($post_id);
    }

    /**
     * Slash every string, inside objects too. update_post_meta() unslashes
     * objects' properties (map_deep) but wp_slash() skips them, so without
     * this every backslash in a setting would be lost. Beaver Builder slashes
     * its own writes the same way (FLBuilderModel::slash_settings).
     *
     * @param array<string,object> $nodes
     * @return array<string,object>
     */
    private static function slash(array $nodes): array
    {
        return map_deep(
            Beaver_Builder_Nodes::copy($nodes),
            static fn ($value) => is_string($value) ? addslashes($value) : $value
        );
    }

    /**
     * slash() for one stored value (a settings object).
     *
     * @param mixed $value
     * @return mixed
     */
    private static function slash_value($value)
    {
        return self::slash(['v' => $value])['v'];
    }
}
