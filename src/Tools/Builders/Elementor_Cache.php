<?php

namespace WPMCP\Tools\Builders;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The one place wpmcp invalidates Elementor's derived caches.
 *
 * Lives with the builder storage helpers rather than the Elementor tools
 * because the always-loaded rollback path and the content tools call it,
 * and every build flavor ships this namespace.
 *
 * Elementor keeps derived data next to a document's `_elementor_data`: the
 * element render cache (Document::CACHE_META_KEY, `_elementor_element_cache`),
 * the generated post CSS (Post_CSS: `_elementor_css` meta plus, in external
 * mode, uploads/elementor/css/post-{id}.css) and, where the module exists,
 * the markdown render cache. Elementor's own Document::save() clears these
 * for the saved document; every other way of changing that data (a raw meta
 * write, a snapshot restore, a meta copy onto a new post) has to clear them
 * itself or the front end keeps serving what the page used to be.
 *
 * invalidate_document() is per document, which is what a builder save does.
 * clear_all() is Elementor's own site-wide purge, for data every document's
 * CSS depends on (the kit, v4 global classes).
 *
 * Both are no-ops when Elementor is not loaded: nothing will read these
 * caches, and Elementor purges everything itself when it is activated again.
 */
final class Elementor_Cache
{
    /** Element render cache key, for when the Document class is not loadable. */
    private const RENDER_CACHE_META_KEY = '_elementor_element_cache';

    /** Post CSS meta key, for when Post_CSS cannot be built. */
    private const CSS_META_KEY = '_elementor_css';

    /** Test-only override of the availability probe. */
    private static ?bool $available_for_tests = null;

    public static function set_available_for_tests(?bool $available): void
    {
        self::$available_for_tests = $available;
    }

    public static function available(): bool
    {
        if (defined('WPMCP_TESTING') && WPMCP_TESTING && null !== self::$available_for_tests) {
            return self::$available_for_tests;
        }

        return class_exists('\\Elementor\\Plugin') && null !== \Elementor\Plugin::$instance;
    }

    /** Drop one document's render cache and generated CSS so the next view rebuilds both from stored data. */
    public static function invalidate_document(int $post_id): void
    {
        if ($post_id <= 0 || ! self::available()) {
            return;
        }

        $render_key = class_exists('\\Elementor\\Core\\Base\\Document')
            ? \Elementor\Core\Base\Document::CACHE_META_KEY
            : self::RENDER_CACHE_META_KEY;
        delete_post_meta($post_id, $render_key);

        if (class_exists('\\Elementor\\Modules\\MarkdownRender\\Module')) {
            delete_post_meta($post_id, \Elementor\Modules\MarkdownRender\Module::CACHE_META_KEY);
        }

        try {
            // Deletes the CSS file (external mode) and the `_elementor_css` meta.
            \Elementor\Core\Files\CSS\Post::create($post_id)->delete();
        } catch (\Throwable $e) {
            // Cache invalidation must never fail the write it follows.
            unset($e);
        }
        delete_post_meta($post_id, self::CSS_META_KEY);
    }

    /** Elementor's site-wide purge: every generated CSS file, render cache and asset cache. */
    public static function clear_all(): void
    {
        if (! self::available() || ! isset(\Elementor\Plugin::$instance->files_manager)) {
            return;
        }

        \Elementor\Plugin::$instance->files_manager->clear_cache();
    }

    /**
     * Invalidate one document, then build its CSS again now, so the result can
     * be reported and the next view does not pay for it.
     *
     * @return array{status: string} Elementor's CSS status, or 'deferred' when
     *                               the rebuild failed and is left to the next view.
     */
    public static function regenerate_document(int $post_id): array
    {
        self::invalidate_document($post_id);

        try {
            $css = \Elementor\Core\Files\CSS\Post::create($post_id);
            $css->update();
        } catch (\Throwable $e) {
            // Already invalidated: Elementor rebuilds it on the next view.
            return ['status' => 'deferred'];
        }

        return ['status' => (string) $css->get_meta('status')];
    }
}
