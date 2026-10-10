<?php

namespace WPMCP\Tests\Support;

use WPMCP\Tools\I18n\I18n_Adapter;

/**
 * A stand-in for WPML's public hook API (issue #498), for the suite, which
 * cannot install WPML (a commercial plugin). It answers the filters and
 * actions I18n_Adapter calls with WPML's own signatures, over an in-memory
 * icl_translations table: element (post or term) => language, trid and
 * source language. Nothing here runs WPML code, so it proves the adapter
 * speaks WPML's API as documented, not that WPML answers the same way.
 *
 * Install it with WPML_Double::install(), which also points I18n_Adapter
 * at WPML; uninstall() removes every hook and the override.
 */
final class WPML_Double
{
    /** @var array<string, array{lang: string, trid: int, source: ?string}> "post_page:12" => row */
    public static array $rows = [];

    /** Translated post types and taxonomies. */
    public static array $translated = [ 'post', 'page', 'category', 'post_tag', 'attachment' ];

    private static int $next_trid = 1;

    private const FILTERS = [
        'wpml_active_languages'          => [ 'active_languages', 2 ],
        'wpml_default_language'          => [ 'default_language', 1 ],
        'wpml_element_language_code'     => [ 'language_code', 2 ],
        'wpml_element_trid'              => [ 'trid', 3 ],
        'wpml_get_element_translations'  => [ 'translations', 3 ],
        'wpml_object_id'                 => [ 'object_id', 4 ],
        'wpml_is_translated_post_type'   => [ 'is_translated', 2 ],
        'wpml_is_translated_taxonomy'    => [ 'is_translated', 2 ],
    ];

    public static function install(): void
    {
        self::$rows      = [];
        self::$next_trid = 1;
        foreach (self::FILTERS as $hook => [ $method, $args ]) {
            add_filter($hook, [ self::class, $method ], 10, $args);
        }
        add_action('wpml_set_element_language_details', [ self::class, 'set_details' ]);
        I18n_Adapter::use_plugin_for_tests('wpml');
    }

    public static function uninstall(): void
    {
        foreach (self::FILTERS as $hook => [ $method, $args ]) {
            remove_filter($hook, [ self::class, $method ], 10);
        }
        remove_action('wpml_set_element_language_details', [ self::class, 'set_details' ]);
        I18n_Adapter::use_plugin_for_tests(null);
        self::$rows = [];
    }

    /** Assign an element to a language in a fresh group, or into $trid. */
    public static function put(int $id, string $type, string $lang, ?int $trid = null, ?string $source = null): int
    {
        $trid                               = $trid ?? self::$next_trid++;
        self::$rows[ $type . ':' . $id ] = [ 'lang' => $lang, 'trid' => $trid, 'source' => $source ];
        return $trid;
    }

    public static function active_languages($value, $args = null): array
    {
        return [
            'en' => [ 'code' => 'en', 'native_name' => 'English' ],
            'de' => [ 'code' => 'de', 'native_name' => 'Deutsch' ],
            'fr' => [ 'code' => 'fr', 'native_name' => 'Francais' ],
        ];
    }

    public static function default_language($value): string
    {
        return 'en';
    }

    public static function language_code($value, $args)
    {
        $args = (array) $args;
        $row  = self::$rows[ ($args['element_type'] ?? '') . ':' . (int) ($args['element_id'] ?? 0) ] ?? null;
        return null === $row ? $value : $row['lang'];
    }

    public static function trid($value, $id, $type = 'post_post')
    {
        $row = self::$rows[ $type . ':' . (int) $id ] ?? null;
        return null === $row ? $value : $row['trid'];
    }

    public static function translations($value, $trid, $type = 'post_post')
    {
        if (! $trid) {
            return $value;
        }
        $out = [];
        foreach (self::$rows as $key => $row) {
            [ $row_type, $id ] = explode(':', $key);
            if ($row_type === $type && (int) $row['trid'] === (int) $trid) {
                $out[ $row['lang'] ] = (object) [
                    'element_id'           => (int) $id,
                    'language_code'        => $row['lang'],
                    'trid'                 => $row['trid'],
                    'source_language_code' => $row['source'],
                ];
            }
        }
        return $out;
    }

    /** wpml_object_id: the element's translation into $lang, or null (or the element itself with $original). */
    public static function object_id($id, $type = 'post', $original = false, $lang = null)
    {
        $type = (string) $type;
        if (! in_array($type, self::$translated, true)) {
            return $id;
        }
        $element = (taxonomy_exists($type) ? 'tax_' : 'post_') . $type;
        $row     = self::$rows[ $element . ':' . (int) $id ] ?? null;
        if (null !== $row) {
            foreach (self::translations(null, $row['trid'], $element) as $code => $item) {
                if ($code === $lang) {
                    return $item->element_id;
                }
            }
        }
        return $original ? $id : null;
    }

    public static function is_translated($value, $type): bool
    {
        return in_array((string) $type, self::$translated, true);
    }

    /** wpml_set_element_language_details: a null trid starts a new group. */
    public static function set_details($args): void
    {
        $args = (array) $args;
        self::put(
            (int) $args['element_id'],
            (string) $args['element_type'],
            (string) $args['language_code'],
            empty($args['trid']) ? null : (int) $args['trid'],
            isset($args['source_language_code']) ? (string) $args['source_language_code'] : null
        );
    }
}
