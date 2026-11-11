<?php

namespace WPMCP\Tools\Builders;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read access to a page's Cornerstone (Themeco) layout, and the checks a
 * write runs before Cornerstone saves it (issue #646). Recorded from
 * Cornerstone 7.8.12:
 *
 * - the layout is the `_cornerstone_data` postmeta, a JSON list of elements
 *   (see Cornerstone_Tree), and the page's custom CSS and JS sit in
 *   `_cornerstone_settings`; post_content holds the page as Cornerstone
 *   rendered it on save, between `<!-- cs-content -->` comments;
 * - `_cornerstone_override` marks a page whose owner went back to the
 *   WordPress editor, which Cornerstone then no longer renders
 *   (cs_uses_cornerstone());
 * - its save_post hook copies `_cornerstone_data` and
 *   `_cornerstone_settings` onto each revision under the same keys
 *   (Services\Revisions), and its restore hook puts them back.
 *
 * Reads work from these rows alone. Writes go through Cornerstone itself
 * (Cornerstone_Engine), since only it renders post_content, so they need it
 * loaded. Every row a write touches is postmeta or post_content, which the
 * post snapshot captures, so a rollback restores layout and rendered page
 * together.
 */
final class Cornerstone_Content
{
    public const DATA_META_KEY = '_cornerstone_data';

    public const SETTINGS_META_KEY = '_cornerstone_settings';

    public const OVERRIDE_META_KEY = '_cornerstone_override';

    /** How many revisions a page read lists, newest first. */
    public const REVISION_LIMIT = 20;

    /** Whether Cornerstone is loaded (standalone, or inside the X or Pro theme). */
    public static function plugin_active(): bool
    {
        return (bool) apply_filters('wpmcp_cornerstone_active', Cornerstone_Engine::loaded());
    }

    /** The loaded Cornerstone's version, or null when it is not loaded. */
    public static function plugin_version(): ?string
    {
        if (! self::plugin_active() || ! defined('CS_VERSION')) {
            return null;
        }
        $version = (string) constant('CS_VERSION');

        return '' === $version ? null : $version;
    }

    /** The stored layout row, exactly as stored. */
    public static function raw(int $post_id): string
    {
        $value = get_post_meta($post_id, self::DATA_META_KEY, true);

        return is_string($value) ? $value : '';
    }

    /** @return array<int,array<string,mixed>> the page's elements ([] when it has none) */
    public static function elements(int $post_id): array
    {
        return Cornerstone_Tree::decode(self::raw($post_id)) ?? [];
    }

    /** Whether the page holds at least one element. */
    public static function has_layout(int $post_id): bool
    {
        return [] !== self::elements($post_id);
    }

    /** sha256 of the stored layout row, the hash writes are pinned to. */
    public static function hash(int $post_id): string
    {
        return hash('sha256', self::raw($post_id));
    }

    /** @return array<string,mixed> */
    public static function read(int $post_id): array
    {
        $raw      = self::raw($post_id);
        $elements = Cornerstone_Tree::decode($raw);
        $settings = Cornerstone_Tree::decode_object(get_post_meta($post_id, self::SETTINGS_META_KEY, true));

        $out = [
            'post_id'        => $post_id,
            'builder'        => 'cornerstone',
            'plugin_active'  => self::plugin_active(),
            'plugin_version' => self::plugin_version(),
            'hash'           => self::hash($post_id),
            'revisions'      => self::revisions($post_id),
            'settings'       => [
                'custom_css' => (string) ($settings['customCSS'] ?? ''),
                'custom_js'  => (string) ($settings['customJS'] ?? ''),
            ],
            'tree'           => Cornerstone_Tree::tree($elements ?? []),
            'content'        => $elements ?? [],
        ];
        if (null === $elements && '' !== $raw) {
            $out['content_error'] = 'The stored layout is not a list of Cornerstone elements; open the page in Cornerstone and save it once.';
        }

        return $out;
    }

    /** @return array<int,array{id:int,date_gmt:string,author:int,hash:string}> */
    public static function revisions(int $post_id): array
    {
        $out = [];
        foreach (wp_get_post_revisions($post_id, ['posts_per_page' => self::REVISION_LIMIT, 'check_enabled' => false]) as $revision) {
            $id  = (int) $revision->ID;
            $raw = get_post_meta($id, self::DATA_META_KEY, true);
            if (wp_is_post_autosave($revision) || ! is_string($raw) || null === Cornerstone_Tree::decode($raw)) {
                continue;
            }
            $out[] = [
                'id'       => $id,
                'date_gmt' => (string) $revision->post_date_gmt,
                'author'   => (int) $revision->post_author,
                'hash'     => hash('sha256', $raw),
            ];
        }

        return $out;
    }

    /**
     * The elements one of the page's revisions holds, as Cornerstone's
     * restore hook puts them back.
     *
     * @return array<int,array<string,mixed>>
     * @throws \InvalidArgumentException when $revision is not a Cornerstone revision of $post_id
     */
    public static function revision_elements(int $post_id, string $revision): array
    {
        $id   = ctype_digit($revision) ? (int) $revision : 0;
        $post = $id > 0 ? get_post($id) : null;
        if (! $post || 'revision' !== $post->post_type || (int) $post->post_parent !== $post_id) {
            throw new \InvalidArgumentException(esc_html("restore needs path: the id of a revision of this page (see revisions in get-builder-content); got '{$revision}'."));
        }
        $elements = Cornerstone_Tree::decode(get_post_meta($id, self::DATA_META_KEY, true));
        if (null === $elements) {
            throw new \InvalidArgumentException(esc_html("Revision {$id} holds no Cornerstone layout."));
        }

        return $elements;
    }

    /** The refusal for a write while Cornerstone is not loaded. */
    public static function unavailable(): \WP_Error
    {
        $url = admin_url('plugins.php');

        return new \WP_Error(
            'plugin_missing',
            "Cornerstone (Themeco) is not active on this site, so this cannot run. Install and activate Cornerstone (Themeco) ({$url}), or the X or Pro theme that bundles it, then try again.",
            ['status' => 403, 'plugin' => 'Cornerstone (Themeco)', 'url' => $url]
        );
    }

    /**
     * The placement check Cornerstone's builder applies, against its own
     * definitions: the parent must take children of this type
     * (valid_children) and the child must accept this parent
     * (valid_parent), 'region' being the top level. Classic (legacy)
     * elements are only checked to exist.
     *
     * @return callable(string,string):?string
     */
    public static function placement(object $engine): callable
    {
        return static function (string $parent, string $child) use ($engine): ?string {
            if (in_array($child, ['region', 'root', 'undefined'], true)) {
                return "{$child} is not an element that can be placed on a page.";
            }
            $definition = $engine->definition($child);
            if (null === $definition) {
                return "Cornerstone has no element type {$child}; read get-builder-content scope catalog for the types.";
            }
            if (0 === strpos($child, 'classic:') || 0 === strpos($parent, 'classic:')) {
                return null;
            }

            $holder  = $engine->definition($parent);
            $allowed = is_array($holder) ? ($holder['valid_children'] ?? null) : null;
            if ('region' === $parent && is_array($allowed) && ! array_is_list($allowed)) {
                $allowed = $allowed['content'] ?? ($allowed['*'] ?? null);
            }
            $where = 'region' === $parent ? 'the top level' : "a {$parent} element";
            if (null === $allowed || [] === $allowed || '' === $allowed) {
                return "A {$parent} element holds no child elements.";
            }
            if ('*' !== $allowed && $child !== $allowed && ! (is_array($allowed) && in_array($child, $allowed, true))) {
                $list = implode(', ', (array) $allowed);
                return "A {$child} element cannot go in {$where}, which takes {$list}.";
            }

            $parent_rule = $definition['valid_parent'] ?? null;
            if (is_string($parent_rule) && '' !== $parent_rule && $parent_rule !== $parent) {
                $home = 'region' === $parent_rule ? 'the top level' : "a {$parent_rule} element";
                return "A {$child} element goes only in {$home}, not in {$where}.";
            }

            return null;
        };
    }
}
