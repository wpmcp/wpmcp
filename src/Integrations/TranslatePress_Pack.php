<?php

namespace WPMCP\Integrations;

use WPMCP\Safety\Plugin_Table_Rows_Snapshot;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * TranslatePress ops on the plugin-data pair (issue #299): its languages and
 * the string translations in its per-language dictionary tables,
 * trp_dictionary_<default>_<language> (TRP_Query::get_table_name(), whose
 * trp_table_name_dictionary filter is honoured).
 *
 * Reads page through a language's strings, filtered by a search on the
 * original and by status (0 not translated, 1 machine translated, 2 human
 * reviewed). The write sets the translation of existing dictionary rows by
 * id, sanitized the way TranslatePress's own editor sanitizes it, marks a
 * non-empty translation human reviewed and an empty one not translated, and
 * drops the plugin's cached lookups. Rows are created by TranslatePress
 * itself when it meets a string, so the write never inserts one.
 *
 * Every write is snapshotted as a 'plugin_table_rows' image of the rows it
 * names, so rollback-operation restores them exactly. Reads and writes need
 * the capability TranslatePress gates its translation editor behind
 * (trp_translating_capability, manage_options by default).
 */
final class TranslatePress_Pack
{
    private const NOT_TRANSLATED = 0;

    private const HUMAN_REVIEWED = 2;

    private const MAX_PER_PAGE = 100;

    public static function active(): bool
    {
        return (bool) apply_filters('wpmcp_translatepress_active', class_exists('TRP_Translate_Press'));
    }

    /** @return true|array{code: string, message: string} */
    public static function requirement()
    {
        return self::active() ? true : [
            'code'    => 'translatepress_inactive',
            'message' => 'TranslatePress is not active on this site.',
        ];
    }

    /** @return array{default: string, languages: string[]} the default language and the translation languages. */
    public static function languages(): array
    {
        $settings  = (array) get_option('trp_settings', []);
        $default   = (string) ($settings['default-language'] ?? 'en_US');
        $languages = array_values(array_filter(
            array_map('strval', (array) ($settings['translation-languages'] ?? [])),
            static fn (string $code): bool => $code !== $default && 1 === preg_match('/^[A-Za-z0-9_-]+$/', $code)
        ));

        return [ 'default' => $default, 'languages' => $languages ];
    }

    /** The dictionary table of a translation language. */
    public static function table(string $language): string
    {
        global $wpdb;

        $default = self::languages()['default'];
        $table   = $wpdb->prefix . 'trp_dictionary_' . strtolower($default) . '_' . strtolower($language);

        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- TranslatePress's own filter, honoured so this reads the same table the plugin does.
        return (string) apply_filters('trp_table_name_dictionary', $table, $wpdb->prefix, $language, $default);
    }

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        $requires   = static fn () => self::requirement();
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- TranslatePress's own filter, honoured so the ops need what its translation editor needs.
        $capability = (string) apply_filters('trp_translating_capability', 'manage_options');
        $language   = [ 'type' => 'string', 'minLength' => 2 ];

        return [
            'translatepress-list-languages' => [
                'mode'         => 'read',
                'capability'   => $capability,
                'description'  => 'List TranslatePress\'s default language and translation languages',
                'input_schema' => [ 'type' => 'object', 'properties' => [] ],
                'requires'     => $requires,
                'handler'      => static function (): array {
                    $languages = self::languages();
                    return [ 'default_language' => $languages['default'], 'languages' => $languages['languages'] ];
                },
            ],
            'translatepress-get-strings'    => [
                'mode'         => 'read',
                'capability'   => $capability,
                'description'  => 'Page through a language\'s TranslatePress strings (id, original, translated, status 0 none, 1 machine, 2 reviewed), optionally filtered by a search on the original and by status',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'language' => $language,
                        'search'   => [ 'type' => 'string', 'maxLength' => 200 ],
                        'status'   => [ 'type' => 'integer', 'enum' => [ 0, 1, 2 ] ],
                        'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PER_PAGE ],
                        'page'     => [ 'type' => 'integer', 'minimum' => 1 ],
                    ],
                    'required'   => [ 'language' ],
                ],
                'requires'     => $requires,
                'validate'     => static fn (array $args): ?array => self::language_refusal((string) $args['language']),
                'handler'      => static fn (array $args): array => self::strings($args),
            ],
            'translatepress-update-strings' => [
                'mode'         => 'write',
                'capability'   => $capability,
                'description'  => 'Set translations of existing TranslatePress strings by id in one language (an empty translation clears it). Snapshotted; restorable with rollback-operation',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'language'     => $language,
                        'translations' => [
                            'type'     => 'array',
                            'minItems' => 1,
                            'maxItems' => self::MAX_PER_PAGE,
                            'items'    => [
                                'type'       => 'object',
                                'properties' => [
                                    'id'         => [ 'type' => 'integer', 'minimum' => 1 ],
                                    'translated' => [ 'type' => 'string' ],
                                ],
                                'required'   => [ 'id', 'translated' ],
                            ],
                        ],
                    ],
                    'required'   => [ 'language', 'translations' ],
                ],
                'requires'     => $requires,
                'validate'     => static fn (array $args): ?array => self::update_refusal($args),
                'snapshot'     => static function (array $args): array {
                    $table = self::table((string) $args['language']);
                    return [
                        'object_type' => Plugin_Table_Rows_Snapshot::TYPE,
                        'object_id'   => Plugin_Table_Rows_Snapshot::key('trp_dictionary', (string) Plugin_Table_Rows_Snapshot::suffix_of('trp_dictionary', $table), self::ids($args)),
                    ];
                },
                'handler'      => static fn (array $args): array => self::update($args),
            ],
        ];
    }

    /** @return int[] */
    private static function ids(array $args): array
    {
        return array_map(static fn ($t): int => (int) ((array) $t)['id'], (array) $args['translations']);
    }

    /** @return array{code: string, message: string, data: array}|null */
    private static function language_refusal(string $language): ?array
    {
        $languages = self::languages();
        if (! in_array($language, $languages['languages'], true)) {
            return [
                'code'    => 'unknown_language',
                'message' => sprintf('"%s" is not a TranslatePress translation language on this site (the default language has no dictionary).', $language),
                'data'    => [ 'languages' => $languages['languages'] ],
            ];
        }
        $table = self::table($language);
        if (null === Plugin_Table_Rows_Snapshot::suffix_of('trp_dictionary', $table) || ! Plugin_Table_Rows_Snapshot::table_exists($table)) {
            return [
                'code'    => 'dictionary_missing',
                'message' => sprintf('The TranslatePress dictionary for "%s" does not exist yet; TranslatePress creates it when the language is first visited.', $language),
                'data'    => [ 'language' => $language ],
            ];
        }
        return null;
    }

    /** @return array{code: string, message: string, data: array}|null */
    private static function update_refusal(array $args): ?array
    {
        $refusal = self::language_refusal((string) $args['language']);
        if (null !== $refusal) {
            return $refusal;
        }

        $ids = self::ids($args);
        if (count($ids) !== count(array_unique($ids))) {
            return [ 'code' => 'duplicate_string', 'message' => 'Each string id may appear once per call.', 'data' => [] ];
        }
        $found   = array_map('intval', array_column(Plugin_Table_Rows_Snapshot::rows(self::table((string) $args['language']), $ids), 'id'));
        $missing = array_values(array_diff($ids, $found));
        if ([] !== $missing) {
            return [
                'code'    => 'unknown_string',
                'message' => sprintf('No TranslatePress string with id %s in this language.', implode(', ', $missing)),
                'data'    => [ 'missing' => $missing ],
            ];
        }
        return null;
    }

    private static function strings(array $args): array
    {
        global $wpdb;

        $table    = self::table((string) $args['language']);
        $per_page = (int) ($args['per_page'] ?? 20);
        $page     = (int) ($args['page'] ?? 1);
        $where    = 'WHERE 1=1';
        $params   = [ $table ];
        if (isset($args['search']) && '' !== (string) $args['search']) {
            $where   .= ' AND original LIKE %s';
            $params[] = '%' . $wpdb->esc_like((string) $args['search']) . '%';
        }
        if (isset($args['status'])) {
            $where   .= ' AND status = %d';
            $params[] = (int) $args['status'];
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- TranslatePress's own table has no WP API; $where holds only placeholders.
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i {$where}", ...$params));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- TranslatePress's own table has no WP API; $where holds only placeholders.
        $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT id, original, translated, status, block_type FROM %i {$where} ORDER BY id ASC LIMIT %d OFFSET %d", ...array_merge($params, [ $per_page, ($page - 1) * $per_page ])), ARRAY_A);

        return [
            'language' => (string) $args['language'],
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
            'strings'  => array_map([ self::class, 'shape' ], $rows),
        ];
    }

    private static function update(array $args): array
    {
        global $wpdb;

        $table = self::table((string) $args['language']);
        foreach ((array) $args['translations'] as $entry) {
            $entry      = (array) $entry;
            $translated = self::sanitize((string) $entry['translated']);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- TranslatePress's own table has no WP API; its cache group is flushed below.
            $ok = $wpdb->update(
                $table,
                [ 'translated' => $translated, 'status' => '' === $translated ? self::NOT_TRANSLATED : self::HUMAN_REVIEWED ],
                [ 'id' => (int) $entry['id'] ],
                [ '%s', '%d' ],
                [ '%d' ]
            );
            if (false === $ok) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.
                throw new Operation_Refused('translatepress_write_failed', 'Could not write the TranslatePress translation: ' . $wpdb->last_error);
            }
        }
        Plugin_Table_Rows_Snapshot::flush_translatepress_cache();

        return [
            'language' => (string) $args['language'],
            'strings'  => array_map([ self::class, 'shape' ], Plugin_Table_Rows_Snapshot::rows($table, self::ids($args))),
        ];
    }

    /** Sanitize a translation the way TranslatePress's editor does before saving it. */
    private static function sanitize(string $translated): string
    {
        if (false !== filter_var($translated, FILTER_VALIDATE_URL)) {
            return esc_url_raw($translated);
        }
        if (function_exists('trp_sanitize_string')) {
            return (string) trp_sanitize_string($translated);
        }
        $translated = (string) preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $translated);
        return wp_kses_post(trim($translated));
    }

    /** @return array{id: int, original: string, translated: ?string, status: int, block_type: int} */
    private static function shape(array $row): array
    {
        return [
            'id'         => (int) $row['id'],
            'original'   => (string) $row['original'],
            'translated' => null === $row['translated'] ? null : (string) $row['translated'],
            'status'     => (int) $row['status'],
            'block_type' => (int) $row['block_type'],
        ];
    }
}
