<?php

namespace WPMCP\Integrations;

use WPMCP\Safety\Snapshot;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Relevanssi settings, index status, top searches, index rebuild and
 * settings writes (issue #700), as free ops on the theme dispatcher pair
 * through Ops_Status_Packs, so they add no top-level tools:
 *  - get-relevanssi-settings: the search and indexing settings, one
 *    relevanssi_* option each, from an allowlist (SETTINGS).
 *  - get-relevanssi-index-status: whether the index is built, the counts
 *    Relevanssi keeps in its options, and the documents and rows counted
 *    live from its wp_relevanssi table.
 *  - list-relevanssi-top-searches: the most searched queries in its
 *    wp_relevanssi_log table, aggregated and masked (Search_Plugin_Support).
 *  - rebuild-relevanssi-index: Relevanssi's own relevanssi_build_index(),
 *    the "Build the index" button. The index is derived data rebuilt from
 *    the posts, so the op is not snapshotted (recoverable:false) and needs
 *    confirm:true. The callable is filterable through
 *    wpmcp_relevanssi_build_index_callback.
 *  - update-relevanssi-settings: the WRITABLE settings, validated by kind,
 *    snapshot-wrapped on the touched options as one undo point, off until
 *    the site opts in (wpmcp_enable_search_write). Logging visitor IP
 *    addresses is deliberately not writable.
 *
 * Every op runs at manage_options, the capability Relevanssi's settings
 * screen takes. Presence is the RELEVANSSI_PREMIUM constant both editions
 * define, filterable through wpmcp_relevanssi_active.
 */
final class Relevanssi_Status
{
    private const PREFIX = 'relevanssi_';

    /** Settings the read reports, by short name (option relevanssi_<name>). */
    private const SETTINGS = [
        'admin_search', 'implicit_operator', 'disable_or_fallback', 'default_orderby', 'fuzzy', 'exact_match_bonus',
        'min_word_length', 'throttle', 'throttle_limit', 'respect_exclude', 'exclude_posts', 'cat', 'excat', 'extag',
        'title_boost', 'content_boost', 'comment_boost', 'post_type_weights',
        'index_post_types', 'index_taxonomies_list', 'index_comments', 'index_author', 'index_excerpt', 'index_fields',
        'index_image_files', 'expand_shortcodes', 'punctuation',
        'excerpts', 'excerpt_length', 'excerpt_type', 'excerpt_custom_fields', 'highlight', 'highlight_docs', 'highlight_comments',
        'hilite_title', 'show_matches', 'log_queries', 'log_queries_with_ip', 'omit_from_logs', 'seo_noindex',
    ];

    /** Writable settings: name => [kind, rule]. */
    private const WRITABLE = [
        'admin_search'        => [ 'toggle', null ],
        'disable_or_fallback' => [ 'toggle', null ],
        'exact_match_bonus'   => [ 'toggle', null ],
        'throttle'            => [ 'toggle', null ],
        'respect_exclude'     => [ 'toggle', null ],
        'index_excerpt'       => [ 'toggle', null ],
        'index_image_files'   => [ 'toggle', null ],
        'expand_shortcodes'   => [ 'toggle', null ],
        'excerpts'            => [ 'toggle', null ],
        'highlight_docs'      => [ 'toggle', null ],
        'highlight_comments'  => [ 'toggle', null ],
        'log_queries'         => [ 'toggle', null ],
        'seo_noindex'         => [ 'toggle', null ],
        'implicit_operator'   => [ 'enum', [ 'AND', 'OR' ] ],
        'default_orderby'     => [ 'enum', [ 'relevance', 'post_date' ] ],
        'fuzzy'               => [ 'enum', [ 'always', 'sometimes', 'never' ] ],
        'index_comments'      => [ 'enum', [ 'none', 'normal', 'all' ] ],
        'excerpt_type'        => [ 'enum', [ 'chars', 'words' ] ],
        'highlight'           => [ 'enum', [ 'no', 'mark', 'em', 'strong', 'col', 'bgcol', 'css', 'class' ] ],
        'min_word_length'     => [ 'int', [ 1, 20 ] ],
        'throttle_limit'      => [ 'int', [ 1, 100000 ] ],
        'excerpt_length'      => [ 'int', [ 1, 5000 ] ],
        'title_boost'         => [ 'number', [ 0, 1000 ] ],
        'content_boost'       => [ 'number', [ 0, 1000 ] ],
        'comment_boost'       => [ 'number', [ 0, 1000 ] ],
        'index_post_types'    => [ 'post_types', null ],
        'exclude_posts'       => [ 'id_list', null ],
    ];

    /** Settings that change what is indexed: changing one means a rebuild. */
    private const INDEXING = [ 'min_word_length', 'index_post_types', 'index_comments', 'index_excerpt', 'index_image_files', 'expand_shortcodes', 'respect_exclude' ];

    /** @return array<string,array<string,mixed>> */
    public static function operations(): array
    {
        $requires = static fn () => Ops_Status_Packs::presence(self::is_active(), 'relevanssi_inactive', 'Relevanssi');

        return [
            'get-relevanssi-settings'      => Ops_Status_Packs::read_op(
                'Relevanssi search and indexing settings: operator, fuzzy matching, weights, indexed post types, excerpts, highlighting, logging',
                $requires,
                static fn (): array => [ 'settings' => self::settings() ]
            ),
            'get-relevanssi-index-status'  => Ops_Status_Packs::read_op(
                'Relevanssi index status: built or not, document and term counts, indexed post types, logging on or off',
                $requires,
                static fn (): array => self::index_status()
            ),
            'list-relevanssi-top-searches' => Ops_Status_Packs::read_op(
                'Relevanssi top searches from its search log, aggregated per query (count, most results, last time), personal data masked; zero_hits:true for searches that found nothing',
                $requires,
                static fn (array $args): array => [ 'logging_enabled' => 'on' === get_option('relevanssi_log_queries') ] + Search_Plugin_Support::top_searches(self::table('relevanssi_log'), 'query', 'hits', 'time', $args),
                Search_Plugin_Support::top_searches_schema()
            ),
            'rebuild-relevanssi-index'     => [
                'mode'            => 'destructive',
                'capability'      => 'manage_options',
                'description'     => 'Rebuild the Relevanssi search index with its own indexer (empties and rebuilds it; slow on large sites). Derived data: not snapshotted, not reversible',
                'input_schema'    => [ 'type' => 'object', 'properties' => [] ],
                'requires'        => $requires,
                'validate'        => static fn (): ?array => is_callable(self::build_callback()) ? null : [
                    'code'    => 'relevanssi_index_unavailable',
                    'message' => 'Relevanssi is active but its indexer (relevanssi_build_index) is not available.',
                ],
                'confirm_preview' => static fn (): array => [
                    'message' => sprintf('This empties the Relevanssi index (%d documents) and rebuilds it from the posts.', (int) get_option('relevanssi_doc_count', 0)),
                    'data'    => [ 'doc_count' => (int) get_option('relevanssi_doc_count', 0) ],
                ],
                'handler'         => static fn (): array => self::rebuild(),
            ],
            'update-relevanssi-settings'   => [
                'mode'               => 'write',
                'capability'         => 'manage_options',
                'description'        => 'Change Relevanssi settings (settings: {name: value}, names as get-relevanssi-settings reports them). Snapshotted as one undo point; rollback-operation restores them. Disabled by default (wpmcp_enable_search_write filter)',
                'enabled_by_default' => Search_Plugin_Support::writes_enabled(),
                'opt_in_filter'      => Search_Plugin_Support::ENABLE_FILTER,
                'input_schema'       => Search_Plugin_Support::settings_schema(),
                'requires'           => $requires,
                'validate'           => static fn (array $args): ?array => self::refusal((array) $args['settings']),
                'snapshot'           => static fn (array $args): array => [
                    'object_type' => 'option_set',
                    'object_id'   => Snapshot::option_set_id(array_map(static fn ($name): string => self::PREFIX . $name, array_keys((array) $args['settings']))),
                ],
                'handler'            => static fn (array $args): array => self::update((array) $args['settings']),
            ],
        ];
    }

    /** Whether Relevanssi is loaded, filterable through wpmcp_relevanssi_active. */
    public static function is_active(): bool
    {
        return (bool) apply_filters('wpmcp_relevanssi_active', defined('RELEVANSSI_PREMIUM'));
    }

    /** @return array<string,mixed> */
    private static function settings(): array
    {
        $out = [];
        foreach (self::SETTINGS as $name) {
            $value = get_option(self::PREFIX . $name, null);
            if (null !== $value) {
                $out[ $name ] = $value;
            }
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private static function index_status(): array
    {
        global $wpdb;

        $table  = self::table('relevanssi');
        $exists = Ops_Status_Packs::table_exists($table);
        $docs   = null;
        $rows   = null;
        if ($exists) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- live counts of a third-party index table.
            $docs = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(DISTINCT doc) FROM %i WHERE doc > 0', $table));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- live counts of a third-party index table.
            $rows = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $table));
        }

        return [
            'indexed'           => 'done' === get_option('relevanssi_indexed'),
            'index_table'       => $exists,
            'doc_count'         => (int) get_option('relevanssi_doc_count', 0),
            'terms_count'       => (int) get_option('relevanssi_terms_count', 0),
            'indexed_documents' => $docs,
            'index_rows'        => $rows,
            'post_types'        => array_values((array) get_option('relevanssi_index_post_types', [])),
            'logging_enabled'   => 'on' === get_option('relevanssi_log_queries'),
            'rebuild_supported' => is_callable(self::build_callback()),
        ];
    }

    /** @return array{complete:bool,indexed:int} */
    private static function rebuild(): array
    {
        $result = call_user_func(self::build_callback(), false, false);
        $result = is_array($result) ? array_values($result) : [];

        return [
            'complete' => (bool) ($result[0] ?? false),
            'indexed'  => (int) ($result[1] ?? 0),
        ];
    }

    /** @return mixed the indexer callable, or null when there is none. */
    private static function build_callback()
    {
        return apply_filters('wpmcp_relevanssi_build_index_callback', function_exists('relevanssi_build_index') ? 'relevanssi_build_index' : null);
    }

    /**
     * Why a settings change is refused, or null.
     *
     * @param array<string,mixed> $settings
     */
    private static function refusal(array $settings): ?array
    {
        foreach ($settings as $name => $value) {
            $name = (string) $name;
            if (! isset(self::WRITABLE[ $name ])) {
                return Search_Plugin_Support::invalid(sprintf('"%s" is not a Relevanssi setting this op can change. Writable: %s.', $name, implode(', ', array_keys(self::WRITABLE))), $name);
            }
            if (null === self::normalize($name, $value)) {
                return Search_Plugin_Support::invalid(sprintf('"%s" has an invalid value for Relevanssi.', $name), $name);
            }
        }
        return null;
    }

    /**
     * The value as Relevanssi stores it, or null when it is invalid.
     *
     * @param mixed $value
     * @return mixed
     */
    private static function normalize(string $name, $value)
    {
        [ $kind, $rule ] = self::WRITABLE[ $name ];
        switch ($kind) {
            case 'toggle':
                if (is_bool($value)) {
                    return $value ? 'on' : 'off';
                }
                return in_array($value, [ 'on', 'off' ], true) ? $value : null;
            case 'enum':
                return is_string($value) && in_array($value, (array) $rule, true) ? $value : null;
            case 'int':
                if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
                    return null;
                }
                return (int) $value >= $rule[0] && (int) $value <= $rule[1] ? (string) (int) $value : null;
            case 'number':
                if (! is_int($value) && ! is_float($value) && ! (is_string($value) && is_numeric($value))) {
                    return null;
                }
                return (float) $value >= $rule[0] && (float) $value <= $rule[1] ? 0 + $value : null;
            case 'post_types':
                if (! is_array($value) || [] === $value) {
                    return null;
                }
                foreach ($value as $type) {
                    if (! is_string($type) || ! post_type_exists($type)) {
                        return null;
                    }
                }
                return array_values(array_unique($value));
            case 'id_list':
                return is_string($value) && 1 === preg_match('/^[\d,\s]*$/', $value) ? implode(',', array_filter(array_map('absint', explode(',', $value)))) : null;
        }
        return null;
    }

    /**
     * Save the settings. The validate step already refused anything invalid.
     *
     * @param array<string,mixed> $settings
     * @return array{changed:list<string>,rebuild_needed:bool}
     */
    private static function update(array $settings): array
    {
        $changed = [];
        foreach ($settings as $name => $value) {
            $name = (string) $name;
            update_option(self::PREFIX . $name, self::normalize($name, $value));
            $changed[] = $name;
        }

        return [
            'changed'        => $changed,
            'rebuild_needed' => [] !== array_intersect($changed, self::INDEXING),
        ];
    }

    private static function table(string $name): string
    {
        global $wpdb;
        return $wpdb->prefix . $name;
    }
}
