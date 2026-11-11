<?php

namespace WPMCP\Tools\Builders;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * BeTheme (BeBuilder) presence, its layout row codec, and the one place
 * wpmcp rebuilds what BeTheme derives from a page's layout (issue #643).
 *
 * Lives apart from the content helpers because the always-loaded rollback
 * path calls it, and every build flavor ships this namespace, so it names
 * no other class of this namespace.
 *
 * Recorded from the BeTheme 29.0.5 source:
 *
 * - The layout is the `mfn-page-items` postmeta: a list of sections, each
 *   holding `wraps`, each wrap holding `items`; an item with `item_is_wrap`
 *   is a nested wrap holding `items` of its own. Every element carries a
 *   9 character hex `uid` (Mfn_Builder_Helper::unique_ID()). The row is
 *   stored either as the array itself (the default "Serialized" storage) or
 *   as base64(serialize(array)) when the theme option `builder-storage` is
 *   'encode'; every reader in the theme accepts both, and unserializes the
 *   encoded form with allowed_classes false, as this class does.
 * - On save the theme also writes a plain-text copy of the layout for SEO
 *   plugins (`mfn-page-items-seo`, Mfn_Builder_Admin::rankMath()) and the
 *   page's local styles (Mfn_Helper::preparePostUpdate(): the
 *   `mfn-page-local-style` row, a few rows it derives such as
 *   `mfn-page-fonts`, and the file uploads/betheme/css/post-<id>.css). Both
 *   classes are only loaded in wp-admin, so they are loaded here from the
 *   theme's own files when needed.
 *
 * refresh() rebuilds both from the stored layout. The rows are postmeta, so
 * a post snapshot restores them, but the CSS file is not, so a rollback
 * calls refresh() too. A no-op when BeTheme is not the active theme.
 */
final class BeTheme_Cache
{
    public const META_KEY = 'mfn-page-items';

    public const SEO_META_KEY = 'mfn-page-items-seo';

    /** Whether BeTheme (with its builder) is the active theme on this request. */
    public static function plugin_active(): bool
    {
        $loaded = 'betheme' === get_template() && (class_exists('Mfn_Builder_Items', false) || class_exists('Mfn_Builder', false));

        return (bool) apply_filters('wpmcp_betheme_active', $loaded);
    }

    /**
     * The stored layout, decoded, or null when the page has none or the row
     * cannot be read as a layout.
     *
     * @return array<int|string,mixed>|null
     */
    public static function decode(int $post_id): ?array
    {
        $raw = get_post_meta($post_id, self::META_KEY, true);
        if (is_array($raw)) {
            return $raw;
        }
        if (! is_string($raw) || '' === trim($raw)) {
            return null;
        }

        $bytes = base64_decode($raw, true); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- the theme's own 'encode' storage format.
        if (false === $bytes || '' === $bytes || ! is_serialized($bytes)) {
            return null;
        }
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- the theme's own encoded storage, read the way the theme reads it: no objects.
        $data = @unserialize($bytes, ['allowed_classes' => false]);

        return is_array($data) ? $data : null;
    }

    /** 'encoded' (base64 of a serialized array), 'serialized' (the array), or 'none'. */
    public static function storage(int $post_id): string
    {
        $raw = get_post_meta($post_id, self::META_KEY, true);
        if (is_array($raw)) {
            return 'serialized';
        }

        return is_string($raw) && '' !== trim($raw) ? 'encoded' : 'none';
    }

    /**
     * Rebuild the SEO copy and the local styles from the stored layout, the
     * way the theme's own save does. Never fails the write it follows.
     */
    public static function refresh(int $post_id): void
    {
        if ($post_id <= 0 || ! self::plugin_active()) {
            return;
        }

        $sections = self::decode($post_id);
        try {
            self::load_theme_class('Mfn_Builder_Admin', '/functions/builder/class-mfn-builder-admin.php');
            if (class_exists('Mfn_Builder_Admin', false)) {
                $seo = (new \Mfn_Builder_Admin(true))->rankMath(false, $sections ?? []);
                if (is_string($seo) && '' !== trim($seo)) {
                    update_post_meta($post_id, self::SEO_META_KEY, wp_slash($seo));
                } else {
                    delete_post_meta($post_id, self::SEO_META_KEY);
                }
            }

            if (null !== $sections && [] !== $sections) {
                self::load_theme_class('Mfn_Helper', '/functions/admin/class-mfn-helper.php');
                if (class_exists('Mfn_Helper', false)) {
                    \Mfn_Helper::preparePostUpdate(self::flatten($sections), $post_id);
                }
            }
        } catch (\Throwable $e) {
            // Derived rows are rebuilt on the next save in BeBuilder; they
            // must never fail the write or the rollback.
            unset($e);
        }
    }

    /**
     * Every section, wrap and item as a flat list without its children,
     * tagged with the `jsclass` the builder gives it: what the theme's local
     * style builder walks (as visual-builder-class.php's flatten does).
     *
     * @param array<int|string,mixed> $sections
     * @return array<int,array<string,mixed>>
     */
    public static function flatten(array $sections): array
    {
        $flat = [];
        foreach ($sections as $section) {
            if (! is_array($section)) {
                continue;
            }
            foreach ((array) ($section['wraps'] ?? []) as $wrap) {
                if (is_array($wrap)) {
                    self::flatten_wrap($wrap, $flat);
                }
            }
            unset($section['wraps']);
            $section['jsclass'] = $section['jsclass'] ?? 'section';
            $flat[]             = $section;
        }

        return $flat;
    }

    /** @param array<int,array<string,mixed>> $flat */
    private static function flatten_wrap(array $wrap, array &$flat): void
    {
        foreach ((array) ($wrap['items'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }
            if (! empty($item['item_is_wrap'])) {
                self::flatten_wrap($item, $flat);
                continue;
            }
            $item['jsclass'] = $item['jsclass'] ?? (string) ($item['type'] ?? '');
            $flat[]          = $item;
        }
        unset($wrap['items']);
        $wrap['jsclass'] = 'wrap';
        $flat[]          = $wrap;
    }

    /**
     * Load one of the theme's wp-admin-only classes from its own file, the
     * path the theme itself requires it from, so a later require of the
     * theme's is a no-op.
     */
    public static function load_theme_class(string $class, string $file): void
    {
        if (class_exists($class, false) || 'betheme' !== get_template()) {
            return;
        }
        $path = get_theme_file_path($file);
        if (is_readable($path)) {
            require_once $path;
        }
    }
}
