<?php

namespace WPMCP\Integrations;

use WPMCP\Safety\Snapshot;
use WPMCP\Tools\I18n\I18n_Adapter;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Theme and plugin string translation (issue #689), paid-tier ops on the
 * plugin-data pair, merged by I18n_Pack:
 *  - list-string-domains: each string group (Polylang's "context", the
 *    theme or plugin that registered it) with how many strings it holds and
 *    how many are translated and missing per language;
 *  - get-strings: the strings with their translations and the languages
 *    each one is missing in, filtered by domain, language, search or
 *    missing_only, paged;
 *  - update-strings: translations of strings in one language, by the key
 *    get-strings gives, as one snapshotted change (an empty translation
 *    clears one). Off until the site opts in (OPT_IN_FILTER).
 *
 * Polylang keeps each language's string translations in its language
 * term's meta, _pll_strings_translations, as a list of [original,
 * translation] pairs (PLL_MO::export_to_db() since Polylang 3.4). The ops
 * read and write that meta in the same format, so the undo point is a
 * 'term' snapshot of the language term, which restores its meta exactly.
 * A translation is sanitized the way Polylang's strings screen does it: its
 * own pll_sanitize_string_translation filter, then wp_kses_post() for a
 * user without unfiltered_html.
 *
 * Which strings exist comes from PLL_Admin_Strings::get_strings(): the
 * site title, tagline, widget text, options and strings registered through
 * the WPML API, and whatever the theme and plugins registered with
 * pll_register_string() in this request. Polylang only accepts those last
 * ones in wp-admin, so a string registered there alone is listed once it
 * has a stored translation in some language (registered:false), keyed by
 * the same md5 of its text either way.
 *
 * WPML String Translation keeps strings in its own tables and is not
 * covered yet: with WPML the ops answer string_translation_unsupported.
 */
final class I18n_Strings
{
    /** The site's switch for update-strings (off by default). */
    public const OPT_IN_FILTER = 'wpmcp_enable_string_translation_write';

    /** Polylang's term meta holding a language's string translations. */
    public const META_KEY = '_pll_strings_translations';

    /** Polylang's language taxonomy. */
    private const TAXONOMY = 'language';

    private const MAX_PER_PAGE = 200;

    private const MAX_UPDATES = 100;

    /** @var array<int, array{code: string, name: string, is_default: bool, term_id: int}>|null */
    private static ?array $languages_override = null;

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $registered_override = null;

    /** Test seam: the languages, each {code, name, is_default, term_id}, or null for Polylang's. */
    public static function use_languages_for_tests(?array $languages): void
    {
        if (defined('WPMCP_TESTING') && WPMCP_TESTING) {
            self::$languages_override = $languages;
        }
    }

    /** Test seam: the registered strings in PLL_Admin_Strings' shape, or null for Polylang's. */
    public static function use_registered_for_tests(?array $strings): void
    {
        if (defined('WPMCP_TESTING') && WPMCP_TESTING) {
            self::$registered_override = $strings;
        }
    }

    /** @return true|array{code: string, message: string, data?: array} */
    public static function requirement()
    {
        $base = I18n_Pack::requirement();
        if (true !== $base) {
            return $base;
        }
        if ('polylang' !== I18n_Adapter::active_plugin()) {
            return [
                'code'    => 'string_translation_unsupported',
                'message' => 'Theme and plugin string translation works with Polylang. WPML String Translation is not supported yet.',
                'data'    => [],
            ];
        }
        return true;
    }

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        $requires = static fn () => self::requirement();
        $language = [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 20 ];
        $base     = [
            'tier'       => 'pro',
            'capability' => 'manage_options',
            'requires'   => $requires,
        ];

        return [
            'list-string-domains' => $base + [
                'mode'         => 'read',
                'description'  => 'Polylang string groups (the theme or plugin that registered them) with string counts, translated and missing per language.',
                'input_schema' => [ 'type' => 'object', 'properties' => [] ],
                'handler'      => [ self::class, 'list_domains' ],
            ],
            'get-strings'         => $base + [
                'mode'         => 'read',
                'description'  => 'Polylang theme and plugin strings {key, name, domain, string, translations, missing}, filtered by domain, language, search, missing_only, paged.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'domain'       => [ 'type' => 'string', 'maxLength' => 200 ],
                        'language'     => $language,
                        'search'       => [ 'type' => 'string', 'maxLength' => 200 ],
                        'missing_only' => [ 'type' => 'boolean' ],
                        'page'         => [ 'type' => 'integer', 'minimum' => 1, 'default' => 1 ],
                        'per_page'     => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PER_PAGE, 'default' => 50 ],
                    ],
                ],
                'validate'     => static fn (array $args): ?array => isset($args['language']) ? self::language_refusal((string) $args['language']) : null,
                'handler'      => [ self::class, 'get_strings' ],
            ],
            'update-strings'      => $base + [
                'mode'               => 'write',
                'enabled_by_default' => (bool) apply_filters('wpmcp_enable_string_translation_write', false),
                'opt_in_filter'      => self::OPT_IN_FILTER,
                'description'        => 'Set Polylang string translations in one language by key from get-strings; an empty translation clears one. Undo: rollback-operation.',
                'input_schema'       => [
                    'type'       => 'object',
                    'properties' => [
                        'language' => $language,
                        'strings'  => [
                            'type'     => 'array',
                            'minItems' => 1,
                            'maxItems' => self::MAX_UPDATES,
                            'items'    => [
                                'type'       => 'object',
                                'properties' => [
                                    'key'         => [ 'type' => 'string', 'minLength' => 1 ],
                                    'translation' => [ 'type' => 'string' ],
                                ],
                                'required'   => [ 'key', 'translation' ],
                            ],
                        ],
                    ],
                    'required'   => [ 'language', 'strings' ],
                ],
                'validate'           => [ self::class, 'update_refusal' ],
                'snapshot'           => static fn (array $args): array => [
                    'object_type' => 'term',
                    'object_id'   => Snapshot::term_key(self::TAXONOMY, (string) self::language((string) $args['language'])['slug']),
                ],
                'handler'            => [ self::class, 'update_strings' ],
            ],
        ];
    }

    // ---------------------------------------------------------------
    // Data
    // ---------------------------------------------------------------

    /**
     * The site's languages keyed by code.
     *
     * @return array<string, array{code: string, name: string, is_default: bool, term_id: int, slug: string}>
     */
    private static function languages(): array
    {
        $list = self::$languages_override;
        if (null === $list) {
            $list = [];
            if (function_exists('pll_languages_list')) {
                // The full PLL_Language objects rather than the slugs alone.
                foreach ((array) pll_languages_list([ 'fields' => '' ]) as $language) {
                    $list[] = [
                        'code'       => (string) ($language->slug ?? ''),
                        'name'       => (string) ($language->name ?? ''),
                        'is_default' => (bool) ($language->is_default ?? false),
                        'term_id'    => (int) ($language->term_id ?? 0),
                    ];
                }
            }
        }

        $out = [];
        foreach ($list as $language) {
            $code    = (string) $language['code'];
            $term_id = (int) $language['term_id'];
            if ('' === $code || $term_id <= 0) {
                continue;
            }
            $term        = get_term($term_id, self::TAXONOMY);
            $out[ $code ] = [
                'code'       => $code,
                'name'       => (string) $language['name'],
                'is_default' => (bool) $language['is_default'],
                'term_id'    => $term_id,
                'slug'       => $term instanceof \WP_Term ? (string) $term->slug : $code,
            ];
        }
        return $out;
    }

    /** @return array{code: string, name: string, is_default: bool, term_id: int, slug: string}|null */
    private static function language(string $code): ?array
    {
        return self::languages()[ $code ] ?? null;
    }

    /** @return array{code: string, message: string, data: array}|null */
    private static function language_refusal(string $code): ?array
    {
        if (null !== self::language($code)) {
            return null;
        }
        return [
            'code'    => 'unknown_language',
            'message' => sprintf('"%s" is not a language of this site.', $code),
            'data'    => [ 'languages' => array_keys(self::languages()) ],
        ];
    }

    /**
     * A language's stored translations, original => translation, read from
     * the term meta directly (not Polylang's per-request cache), so a read
     * after a write or an undo in the same request sees the stored state.
     *
     * @return array<string, string>
     */
    private static function stored(int $term_id): array
    {
        $raw = get_term_meta($term_id, self::META_KEY, true);
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $pair) {
            if (! is_array($pair) || ! isset($pair[0], $pair[1])) {
                continue;
            }
            $original    = (string) $pair[0];
            $translation = (string) $pair[1];
            if ('' !== $original && '' !== $translation) {
                $out[ $original ] = $translation;
            }
        }
        return $out;
    }

    /** @return array<string, array<string, mixed>> */
    private static function registered(): array
    {
        if (null !== self::$registered_override) {
            return self::$registered_override;
        }
        if (! class_exists('PLL_Admin_Strings') || ! method_exists('PLL_Admin_Strings', 'get_strings')) {
            return [];
        }
        $strings = \PLL_Admin_Strings::get_strings();
        return is_array($strings) ? $strings : [];
    }

    /**
     * Every string, registered ones first in registration order, then the
     * originals with a stored translation that nothing registered in this
     * request, keyed by md5 of the text.
     *
     * @param array<string, array<string, string>> $stored code => original => translation
     * @return array<string, array{key: string, name: string, domain: string, string: string, multiline: bool, registered: bool}>
     */
    private static function catalog(array $stored): array
    {
        $out = [];
        foreach (self::registered() as $entry) {
            $text = is_array($entry) ? (string) ($entry['string'] ?? '') : '';
            $key  = md5($text);
            if ('' === $text || isset($out[ $key ])) {
                continue;
            }
            $out[ $key ] = [
                'key'        => $key,
                'name'       => (string) ($entry['name'] ?? ''),
                'domain'     => (string) ($entry['context'] ?? ''),
                'string'     => $text,
                'multiline'  => (bool) ($entry['multiline'] ?? false),
                'registered' => true,
            ];
        }
        foreach ($stored as $pairs) {
            foreach (array_keys($pairs) as $text) {
                $text = (string) $text;
                $key  = md5($text);
                if (! isset($out[ $key ])) {
                    $out[ $key ] = [
                        'key'        => $key,
                        'name'       => '',
                        'domain'     => '',
                        'string'     => $text,
                        'multiline'  => false,
                        'registered' => false,
                    ];
                }
            }
        }
        return $out;
    }

    /** @return array<string, array<string, string>> code => original => translation */
    private static function all_stored(array $languages): array
    {
        $out = [];
        foreach ($languages as $code => $language) {
            $out[ $code ] = self::stored((int) $language['term_id']);
        }
        return $out;
    }

    /** @return string[] the non-default language codes */
    private static function targets(array $languages): array
    {
        return array_values(array_map('strval', array_keys(array_filter($languages, static fn ($l) => ! $l['is_default']))));
    }

    private static function default_code(array $languages): string
    {
        foreach ($languages as $code => $language) {
            if ($language['is_default']) {
                return (string) $code;
            }
        }
        return '';
    }

    // ---------------------------------------------------------------
    // Handlers
    // ---------------------------------------------------------------

    /** @return array<string, mixed> */
    public static function list_domains(): array
    {
        $languages = self::languages();
        $stored    = self::all_stored($languages);
        $targets   = self::targets($languages);

        $domains = [];
        foreach (self::catalog($stored) as $row) {
            $domain = $row['domain'];
            if (! isset($domains[ $domain ])) {
                $domains[ $domain ] = [
                    'domain'     => $domain,
                    'strings'    => 0,
                    'translated' => array_fill_keys($targets, 0),
                    'missing'    => array_fill_keys($targets, 0),
                ];
            }
            ++$domains[ $domain ]['strings'];
            foreach ($targets as $code) {
                if (isset($stored[ $code ][ $row['string'] ])) {
                    ++$domains[ $domain ]['translated'][ $code ];
                } else {
                    ++$domains[ $domain ]['missing'][ $code ];
                }
            }
        }

        return [
            'default_language' => self::default_code($languages),
            'languages'        => $targets,
            'domains'          => array_values($domains),
            'note'             => 'Domain "" holds translated strings nothing registered in this request (themes often register strings in wp-admin only).',
        ];
    }

    /** @return array<string, mixed> */
    public static function get_strings(array $args): array
    {
        $languages = self::languages();
        $stored    = self::all_stored($languages);
        $language  = isset($args['language']) ? (string) $args['language'] : null;
        $shown     = null === $language ? array_keys($languages) : [ $language ];
        $targets   = null === $language ? self::targets($languages) : [ $language ];
        $domain    = isset($args['domain']) ? (string) $args['domain'] : null;
        $search    = isset($args['search']) ? (string) $args['search'] : '';
        $missing   = true === ($args['missing_only'] ?? false);
        $page      = max(1, (int) ($args['page'] ?? 1));
        $per_page  = min(self::MAX_PER_PAGE, max(1, (int) ($args['per_page'] ?? 50)));

        $rows = [];
        foreach (self::catalog($stored) as $row) {
            if (null !== $domain && $domain !== $row['domain']) {
                continue;
            }
            if ('' !== $search && false === stripos($row['string'], $search) && false === stripos($row['name'], $search)) {
                continue;
            }
            $translations = [];
            foreach ($shown as $code) {
                $translations[ (string) $code ] = $stored[ $code ][ $row['string'] ] ?? null;
            }
            $absent = array_values(array_filter($targets, static fn ($code) => ! isset($stored[ $code ][ $row['string'] ])));
            if ($missing && [] === $absent) {
                continue;
            }
            $rows[] = $row + [ 'translations' => $translations, 'missing' => $absent ];
        }

        return [
            'default_language' => self::default_code($languages),
            'total'            => count($rows),
            'page'             => $page,
            'per_page'         => $per_page,
            'pages'            => (int) ceil(count($rows) / $per_page),
            'strings'          => array_slice($rows, ($page - 1) * $per_page, $per_page),
        ];
    }

    /** @return array{code: string, message: string, data: array}|null */
    public static function update_refusal(array $args): ?array
    {
        $refusal = self::language_refusal((string) $args['language']);
        if (null !== $refusal) {
            return $refusal;
        }
        $keys     = array_map(static fn ($s) => (string) ((array) $s)['key'], (array) $args['strings']);
        $repeated = array_values(array_unique(array_diff_assoc($keys, array_unique($keys))));
        if ([] !== $repeated) {
            return [ 'code' => 'duplicate_string', 'message' => sprintf('Each string may be given once: %s.', implode(', ', $repeated)), 'data' => [ 'duplicate' => $repeated ] ];
        }
        $catalog = self::catalog(self::all_stored(self::languages()));
        $unknown = array_values(array_diff($keys, array_keys($catalog)));
        if ([] !== $unknown) {
            return [ 'code' => 'unknown_string', 'message' => sprintf('Not a string of this site: %s. Read the keys with get-strings.', implode(', ', $unknown)), 'data' => [ 'unknown' => $unknown ] ];
        }
        return null;
    }

    /**
     * Store a language's translations. Through Polylang's own PLL_MO when
     * Polylang runs, which also clears its per-request cache of them, else
     * the same term meta in the same format: a list of slashed [original,
     * translation] pairs.
     *
     * @param array<string, mixed>  $language
     * @param array<string, string> $pairs original => translation
     */
    private static function save(array $language, array $pairs): void
    {
        $pll = null;
        if (null === self::$languages_override && class_exists('PLL_MO') && function_exists('PLL') && isset(PLL()->model) && is_object(PLL()->model) && is_callable([ PLL()->model, 'get_language' ])) {
            $pll = PLL()->model->get_language((string) $language['code']);
        }
        if (is_object($pll) && (int) ($pll->term_id ?? 0) === (int) $language['term_id']) {
            $mo = new \PLL_MO();
            foreach ($pairs as $original => $translation) {
                $mo->add_entry($mo->make_entry((string) $original, (string) $translation));
            }
            $mo->export_to_db($pll);
            return;
        }

        $list = [];
        foreach ($pairs as $original => $translation) {
            if ('' !== (string) $translation) {
                $list[] = wp_slash([ (string) $original, (string) $translation ]);
            }
        }
        update_term_meta((int) $language['term_id'], self::META_KEY, $list);
    }

    /** @return array<string, mixed> */
    public static function update_strings(array $args): array
    {
        $language = (array) self::language((string) $args['language']);
        $catalog  = self::catalog(self::all_stored(self::languages()));
        $pairs    = self::stored((int) $language['term_id']);

        $written = 0;
        $cleared = 0;
        $out     = [];
        foreach ((array) $args['strings'] as $item) {
            $item        = (array) $item;
            $row         = $catalog[ (string) $item['key'] ];
            $original    = $row['string'];
            $translation = trim((string) $item['translation']);
            if ('' === $translation) {
                if (isset($pairs[ $original ])) {
                    ++$cleared;
                }
                unset($pairs[ $original ]);
                $out[] = [ 'key' => $row['key'], 'string' => $original, 'translation' => null ];
                continue;
            }
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Polylang's own filter, applied the way its strings screen applies it.
            $translation = (string) apply_filters('pll_sanitize_string_translation', $translation, $row['name'], $row['domain'], $original, $pairs[ $original ] ?? '');
            if (! current_user_can('unfiltered_html')) {
                $translation = wp_kses_post($translation);
            }
            $pairs[ $original ] = $translation;
            ++$written;
            $out[] = [ 'key' => $row['key'], 'string' => $original, 'translation' => $translation ];
        }

        self::save($language, $pairs);
        do_action('pll_save_strings_translations'); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Polylang's own action, fired after its strings are saved.

        return [
            'language' => (string) $language['code'],
            'written'  => $written,
            'cleared'  => $cleared,
            'strings'  => $out,
        ];
    }
}
