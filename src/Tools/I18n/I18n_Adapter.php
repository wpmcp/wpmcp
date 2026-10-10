<?php

namespace WPMCP\Tools\I18n;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Maps a neutral multilingual field set (languages, per-post translations,
 * a post's language) to the active i18n plugin's own API, so the i18n tools
 * work identically against either Polylang or WPML without the tool classes
 * themselves knowing which plugin is installed.
 *
 * Only one plugin is expected to be active on a given site. When detection
 * finds both (not expected, but not impossible), Polylang wins, matching
 * wpmcp_i18n_plugin()'s test-harness precedence.
 *
 * The neutral shapes are:
 *  - a language: ['code' => string, 'name' => string, 'is_default' => bool]
 *  - a post's translations: [lang_code => ['post_id' => int, 'title' => string]]
 *
 * The fetch-and-write methods delegate straight to the active plugin's
 * functions. The normalization from each plugin's raw shape to the neutral
 * shape lives in separate pure static helpers (normalize_polylang_languages,
 * normalize_wpml_languages, etc.) so that mapping logic is unit-testable with
 * fake data, without booting the real plugin. This matters for Polylang in
 * particular: in the unit-test harness its POLYLANG_VERSION constant is
 * defined (so detection reports 'polylang') but its pll_* API functions and
 * $GLOBALS['polylang'] context are never booted (that only happens on a real
 * plugins_loaded/pll_init cycle), so the live fetch paths cannot be exercised
 * there. The pure normalizers let the load-bearing mapping still be tested.
 *
 * WPML support is written best-effort against WPML's public API. WPML is a
 * paid plugin not available from wordpress.org, so it is NOT installed in the
 * test harness and the WPML paths are untested against a real WPML install.
 */
class I18n_Adapter
{
    /** Polylang's own taxonomies on a post: its language and its translation group. */
    public const POLYLANG_TAXONOMIES = [ 'language', 'post_translations' ];

    /** Test seam: the plugin active_plugin() reports, or null to detect it. */
    private static ?string $plugin_override = null;

    /**
     * Force the reported plugin ('polylang', 'wpml' or ''), so the suite can
     * run the WPML paths against a stand-in for WPML's hook API. Null
     * restores detection. Guarded by WPMCP_TESTING.
     */
    public static function use_plugin_for_tests(?string $plugin): void
    {
        if (defined('WPMCP_TESTING') && WPMCP_TESTING) {
            self::$plugin_override = $plugin;
        }
    }

    /**
     * Which i18n plugin is active: 'polylang', 'wpml', or '' when neither is.
     * Polylang is checked first, so it wins if both somehow report active.
     */
    public static function active_plugin(): string
    {
        if (null !== self::$plugin_override) {
            return self::$plugin_override;
        }

        if (function_exists('pll_the_languages') || defined('POLYLANG_VERSION')) {
            return 'polylang';
        }

        if (defined('ICL_SITEPRESS_VERSION')) {
            return 'wpml';
        }

        return '';
    }

    /**
     * The site's configured languages in the neutral shape, from the active
     * plugin. Each entry is ['code' => string, 'name' => string,
     * 'is_default' => bool]. Returns an empty array when no i18n plugin is
     * active.
     */
    public static function list_languages(): array
    {
        $active = self::active_plugin();

        if ('polylang' === $active && function_exists('pll_languages_list')) {
            // pll_languages_list(['fields' => '']) returns the full
            // PLL_Language objects (slug, name, is_default), rather than the
            // default slug-only list.
            $languages = pll_languages_list(['fields' => '']);
            return self::normalize_polylang_languages(is_array($languages) ? $languages : []);
        }

        if ('wpml' === $active) {
            // WPML's documented hook API (wpml_active_languages,
            // wpml_default_language) rather than its icl_* functions.
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- invoking WPML's own filter API, not defining a hook.
            $languages = apply_filters('wpml_active_languages', null, [ 'skip_missing' => 0 ]);
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- invoking WPML's own filter API, not defining a hook.
            $default = (string) apply_filters('wpml_default_language', null);
            return self::normalize_wpml_languages(is_array($languages) ? $languages : [], $default);
        }

        return [];
    }

    /**
     * A post's translations in the neutral shape, from the active plugin:
     * [lang_code => ['post_id' => int, 'title' => string]]. Returns an empty
     * array when no i18n plugin is active or the post has no translations.
     */
    public static function get_post_translations(int $post_id): array
    {
        $active = self::active_plugin();

        if ('polylang' === $active && function_exists('pll_get_post_translations')) {
            $translations = pll_get_post_translations($post_id);
            return self::normalize_translations(is_array($translations) ? $translations : []);
        }

        if ('wpml' === $active) {
            // WPML's modern API returns a group's translation elements keyed
            // by language code, each exposing an ->element_id (the translated
            // post id). The group is named by its trid, not by a post id.
            $element_type = 'post_' . get_post_type($post_id);
            $trid         = self::wpml_trid($post_id, $element_type);
            if (null === $trid) {
                return [];
            }
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- invoking WPML's own filter API, not defining a hook.
            $elements     = apply_filters('wpml_get_element_translations', null, $trid, $element_type);
            $map          = [];
            foreach ((array) $elements as $code => $element) {
                if (isset($element->element_id)) {
                    $map[(string) $code] = (int) $element->element_id;
                }
            }
            return self::normalize_translations($map);
        }

        return [];
    }

    /**
     * Enrich a [lang_code => post_id] map into the neutral translations shape
     * [lang_code => ['post_id' => int, 'title' => string]] by looking up each
     * post's title. Pure aside from the get_the_title() WordPress lookups, so
     * it is testable with real factory-created posts without booting an i18n
     * plugin.
     */
    public static function normalize_translations(array $lang_to_post_id): array
    {
        $out = [];

        foreach ($lang_to_post_id as $code => $post_id) {
            $post_id = (int) $post_id;
            $out[(string) $code] = [
                'post_id' => $post_id,
                'title'   => (string) get_the_title($post_id),
            ];
        }

        return $out;
    }

    /**
     * Assign a post to a language by its code, via the active plugin. This is
     * a raw write: callers are expected to route it through Safe_Mutation
     * themselves so the change is snapshotted and undoable. A no-op when no
     * i18n plugin's API is available.
     *
     * For Polylang a post's language IS a term in the 'language' taxonomy, so
     * the existing post snapshot (which captures every taxonomy assigned to
     * the post's type, including 'language') records and restores it exactly.
     */
    public static function set_post_language(int $post_id, string $lang_code): void
    {
        $active = self::active_plugin();

        if ('polylang' === $active && function_exists('pll_set_post_language')) {
            pll_set_post_language($post_id, $lang_code);
            return;
        }

        if ('wpml' === $active) {
            // WPML stores element language details via this action. Best-effort
            // and untested against a real WPML install (WPML is a paid plugin,
            // not installable from wordpress.org, so it is absent here).
            do_action(
                // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- invoking WPML's own action API, not defining a hook.
                'wpml_set_element_language_details',
                [
                    'element_id'   => $post_id,
                    'element_type' => 'post_' . get_post_type($post_id),
                    'language_code' => $lang_code,
                ]
            );
        }
    }

    /**
     * Link a set of posts as translations of one another, given a
     * [lang_code => post_id] map, via the active plugin. This is a raw write:
     * callers are expected to route it through Safe_Mutation themselves. A
     * no-op when no i18n plugin's API is available.
     *
     * Unlike set_post_language(), this relationship spans multiple posts. On
     * Polylang the group membership is stored via the 'post_translations'
     * taxonomy across all the involved posts, so a snapshot of any single post
     * cannot capture (or restore) the whole group's state.
     */
    public static function link_post_translations(array $lang_to_post_id): void
    {
        $active = self::active_plugin();

        if ('polylang' === $active && function_exists('pll_save_post_translations')) {
            pll_save_post_translations($lang_to_post_id);
            return;
        }

        if ('wpml' === $active) {
            // WPML links translations by assigning each post the same trid.
            // Best-effort and untested against a real WPML install (WPML is a
            // paid plugin, absent from wordpress.org and from this harness).
            $trid = null;
            foreach ($lang_to_post_id as $lang_code => $post_id) {
                $post_id      = (int) $post_id;
                $element_type = 'post_' . get_post_type($post_id);
                do_action(
                    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- invoking WPML's own action API, not defining a hook.
                    'wpml_set_element_language_details',
                    [
                        'element_id'    => $post_id,
                        'element_type'  => $element_type,
                        'trid'          => $trid,
                        'language_code' => (string) $lang_code,
                    ]
                );
                if (null === $trid) {
                    $trid = self::wpml_trid($post_id, $element_type);
                }
            }
        }
    }

    /**
     * Whether the active plugin's API is usable in this request: Polylang
     * loads its pll_* API only once it has booted on a site with languages,
     * WPML answers through hooks once it is loaded at all.
     */
    public static function api_ready(): bool
    {
        $active = self::active_plugin();

        if ('polylang' === $active) {
            return function_exists('pll_save_post_translations') && function_exists('pll_get_post_language');
        }

        return 'wpml' === $active;
    }

    /** The language code of a post, or '' when it has none. */
    public static function get_post_language(int $post_id): string
    {
        $active = self::active_plugin();

        if ('polylang' === $active && function_exists('pll_get_post_language')) {
            return (string) pll_get_post_language($post_id);
        }

        if ('wpml' === $active) {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- invoking WPML's own filter API, not defining a hook.
            $code = apply_filters('wpml_element_language_code', null, [ 'element_id' => $post_id, 'element_type' => 'post_' . get_post_type($post_id) ]);
            return is_string($code) ? $code : '';
        }

        return '';
    }

    /** Whether the active plugin translates posts of this type. */
    public static function is_translated_post_type(string $post_type): bool
    {
        $active = self::active_plugin();

        if ('polylang' === $active && function_exists('pll_is_translated_post_type')) {
            return (bool) pll_is_translated_post_type($post_type);
        }

        if ('wpml' === $active) {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- invoking WPML's own filter API, not defining a hook.
            return (bool) apply_filters('wpml_is_translated_post_type', false, $post_type);
        }

        return false;
    }

    /**
     * The term standing for $term_id in $lang: the term itself when the
     * taxonomy is not translated, its translation when it has one, 0 when
     * it has none.
     */
    public static function get_term_translation(int $term_id, string $taxonomy, string $lang): int
    {
        $active = self::active_plugin();

        if ('polylang' === $active && function_exists('pll_get_term')) {
            if (function_exists('pll_is_translated_taxonomy') && ! pll_is_translated_taxonomy($taxonomy)) {
                return $term_id;
            }
            return (int) pll_get_term($term_id, $lang);
        }

        if ('wpml' === $active) {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- invoking WPML's own filter API, not defining a hook.
            return (int) apply_filters('wpml_object_id', $term_id, $taxonomy, false, $lang);
        }

        return $term_id;
    }

    /** The translation of a media item into $lang, or the item itself when it has none. */
    public static function get_attachment_translation(int $attachment_id, string $lang): int
    {
        $active = self::active_plugin();

        if ('polylang' === $active && function_exists('pll_get_post')) {
            return (int) pll_get_post($attachment_id, $lang) ?: $attachment_id;
        }

        if ('wpml' === $active) {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- invoking WPML's own filter API, not defining a hook.
            return (int) apply_filters('wpml_object_id', $attachment_id, 'attachment', true, $lang) ?: $attachment_id;
        }

        return $attachment_id;
    }

    /**
     * Add $new_id to $source_id's translation group as its $lang version.
     * A raw write like link_post_translations(); the caller captures the
     * group first (capture_translation_group()) so the link can be undone.
     */
    public static function add_translation(int $source_id, int $new_id, string $lang): void
    {
        $active = self::active_plugin();

        if ('polylang' === $active && function_exists('pll_save_post_translations')) {
            $group          = (array) pll_get_post_translations($source_id);
            $group[ $lang ] = $new_id;
            pll_save_post_translations($group);
            return;
        }

        if ('wpml' === $active) {
            $element_type = 'post_' . get_post_type($source_id);
            do_action(
                // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- invoking WPML's own action API, not defining a hook.
                'wpml_set_element_language_details',
                [
                    'element_id'           => $new_id,
                    'element_type'         => $element_type,
                    'trid'                 => self::wpml_trid($source_id, $element_type),
                    'language_code'        => $lang,
                    'source_language_code' => self::get_post_language($source_id),
                ]
            );
        }
    }

    /**
     * What undoing add_translation() on $source_id needs: for Polylang the
     * group term and its stored map as they are now (term_id 0 when the
     * source has no group yet), for WPML the source's trid.
     *
     * @return array<string, mixed>
     */
    public static function capture_translation_group(int $source_id): array
    {
        $active = self::active_plugin();

        if ('polylang' === $active) {
            $terms = wp_get_object_terms($source_id, 'post_translations');
            $term  = is_array($terms) && [] !== $terms ? reset($terms) : null;
            return [
                'plugin'      => 'polylang',
                'source'      => $source_id,
                'term_id'     => $term instanceof \WP_Term ? (int) $term->term_id : 0,
                'description' => $term instanceof \WP_Term ? (string) $term->description : '',
            ];
        }

        if ('wpml' === $active) {
            return [
                'plugin' => 'wpml',
                'source' => $source_id,
                'trid'   => self::wpml_trid($source_id, 'post_' . get_post_type($source_id)),
            ];
        }

        return [];
    }

    /**
     * Take $new_id back out of the group captured before it was added, so
     * the source's group is exactly what it was. On Polylang the captured
     * group term gets its stored map back verbatim and loses $new_id, or,
     * when the link made the group, the group term goes (unless other posts
     * have joined it since, when only $new_id leaves). On WPML $new_id is
     * moved into a group of its own (best-effort, untested against a real
     * WPML install).
     *
     * Rollback_Service calls it through the
     * wpmcp_rollback_translation_group action with one argument, the
     * captured group, which then names the translation under 'translation'.
     *
     * @param array<string, mixed> $captured capture_translation_group()'s answer.
     */
    public static function restore_translation_group(array $captured, int $new_id = 0): void
    {
        $plugin = (string) ($captured['plugin'] ?? '');
        $new_id = $new_id > 0 ? $new_id : (int) ($captured['translation'] ?? 0);
        if ($new_id <= 0) {
            return;
        }

        if ('polylang' === $plugin && taxonomy_exists('post_translations')) {
            self::restore_polylang_group($captured, $new_id);
            return;
        }

        if ('wpml' === $plugin) {
            $element_type = 'post_' . get_post_type($new_id);
            do_action(
                // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- invoking WPML's own action API, not defining a hook.
                'wpml_set_element_language_details',
                [
                    'element_id'    => $new_id,
                    'element_type'  => $element_type,
                    'trid'          => null,
                    'language_code' => self::get_post_language($new_id),
                ]
            );
        }
    }

    /** @param array<string, mixed> $captured */
    private static function restore_polylang_group(array $captured, int $new_id): void
    {
        global $wpdb;

        $term_id = (int) ($captured['term_id'] ?? 0);
        $source  = (int) ($captured['source'] ?? 0);

        if ($term_id > 0 && get_term($term_id, 'post_translations') instanceof \WP_Term) {
            // The stored map goes back as captured, not through
            // wp_update_term()'s description filters.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- restoring a captured column verbatim; clean_term_cache() below invalidates.
            $wpdb->update($wpdb->term_taxonomy, [ 'description' => (string) ($captured['description'] ?? '') ], [ 'term_id' => $term_id, 'taxonomy' => 'post_translations' ]);
            clean_term_cache([ $term_id ], 'post_translations');
            wp_remove_object_terms($new_id, $term_id, 'post_translations');
            clean_object_term_cache([ $new_id, $source ], (string) get_post_type($source));
            return;
        }

        $terms = wp_get_object_terms($new_id, 'post_translations');
        $term  = is_array($terms) && [] !== $terms ? reset($terms) : null;
        if (! $term instanceof \WP_Term) {
            return;
        }
        $members = maybe_unserialize($term->description);
        $others  = array_diff(array_map('intval', is_array($members) ? $members : []), [ $source, $new_id ]);
        if ([] === $others) {
            wp_delete_term((int) $term->term_id, 'post_translations');
        } elseif (function_exists('PLL')) {
            PLL()->model->post->delete_translation($new_id);
        }
        clean_object_term_cache([ $new_id, $source ], (string) get_post_type($source));
    }

    /** @var array<int, mixed> the sync option each open hold_sync() saved, innermost last */
    private static array $held = [];

    /** @var \Closure|null the answer to Polylang's copy filters while the sync is held */
    private static ?\Closure $no_copies = null;

    /**
     * Run $write with the multilingual plugin's own sync between
     * translations held off, so a write to one translation never reaches the
     * others. Polylang copies post fields, custom fields and terms across a
     * group according to its sync option; that option reads as empty for
     * the duration, and its copy filters answer nothing. WPML copies from an
     * original to its translations when the original is saved, never back,
     * so there is nothing to hold for it.
     *
     * @template T
     * @param callable(): T $write
     * @return T
     */
    public static function without_sync(callable $write)
    {
        self::hold_sync();
        try {
            return $write();
        } finally {
            self::release_sync();
        }
    }

    /**
     * Start holding the sync off (see without_sync()). Every call needs its
     * release_sync(); a rollback of a held write is wrapped the same way,
     * through the wpmcp_rollback_hold_translation_sync and
     * wpmcp_rollback_release_translation_sync actions.
     */
    public static function hold_sync(): void
    {
        $options        = self::polylang_options();
        self::$held[]   = null === $options ? null : $options['sync'];
        if (1 === count(self::$held)) {
            self::$no_copies = self::$no_copies ?? static fn (): array => [];
            // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Polylang's own filters, answered while the write runs.
            add_filter('pll_copy_post_metas', self::$no_copies, PHP_INT_MAX);
            add_filter('pll_copy_taxonomies', self::$no_copies, PHP_INT_MAX);
            // phpcs:enable
        }
        if (null !== $options) {
            $options['sync'] = [];
        }
    }

    /** Stop the innermost hold_sync(), putting the sync option back as it found it. */
    public static function release_sync(): void
    {
        if ([] === self::$held) {
            return;
        }
        $saved   = array_pop(self::$held);
        $options = self::polylang_options();
        if (null !== $options && null !== $saved) {
            $options['sync'] = $saved;
        }
        if ([] === self::$held && null !== self::$no_copies) {
            remove_filter('pll_copy_post_metas', self::$no_copies, PHP_INT_MAX);
            remove_filter('pll_copy_taxonomies', self::$no_copies, PHP_INT_MAX);
        }
    }

    /** Polylang's options store, or null when Polylang is not the active, booted plugin. */
    private static function polylang_options(): ?\ArrayAccess
    {
        if ('polylang' !== self::active_plugin() || ! function_exists('PLL')) {
            return null;
        }
        $options = PLL()->options ?? null;
        return $options instanceof \ArrayAccess ? $options : null;
    }

    /** WPML's translation group id for an element, or null when it has none. */
    private static function wpml_trid(int $element_id, string $element_type): ?int
    {
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- reading WPML's own trid filter, not defining a hook.
        $trid = apply_filters('wpml_element_trid', null, $element_id, $element_type);
        return is_numeric($trid) && (int) $trid > 0 ? (int) $trid : null;
    }

    /**
     * Normalize Polylang's PLL_Language objects to the neutral language shape.
     * Pure: takes already-fetched objects so it is testable without booting
     * Polylang. Each object is expected to expose ->slug, ->name, and
     * ->is_default.
     */
    public static function normalize_polylang_languages(array $languages): array
    {
        $out = [];

        foreach ($languages as $language) {
            $out[] = [
                'code'       => (string) ($language->slug ?? ''),
                'name'       => (string) ($language->name ?? ''),
                'is_default' => (bool) ($language->is_default ?? false),
            ];
        }

        return $out;
    }

    /**
     * Normalize WPML's icl_get_languages() array (keyed by code, with a
     * native_name per entry) to the neutral language shape, marking the
     * entry whose code matches $default_code as the default. Pure, so it is
     * testable with fake data even though WPML itself is not installed here.
     */
    public static function normalize_wpml_languages(array $languages, string $default_code): array
    {
        $out = [];

        foreach ($languages as $code => $language) {
            $code = (string) ($language['code'] ?? $code);
            $out[] = [
                'code'       => $code,
                'name'       => (string) ($language['native_name'] ?? ''),
                'is_default' => $code === $default_code,
            ];
        }

        return $out;
    }
}
