<?php

namespace WPMCP\Integrations;

use WPMCP\Safety\Snapshot;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * ACF options page values for the acf dispatcher pair (issue #291).
 *
 * Options pages are an ACF Pro feature, so both ops declare a 'requires'
 * check: on free ACF they stay in the catalog flagged dependency_met:false
 * and refuse cleanly instead of reaching an undefined function. Values are
 * read and written with get_fields() / update_field() against the page's
 * own post_id (usually "options"), and a write is snapshotted as an
 * 'acf_options' target covering every option row under the named fields.
 */
class ACF_Options
{
    /** 'requires' check: the options page API is loaded. */
    public static function requirement()
    {
        return function_exists('acf_get_options_pages') && function_exists('acf_get_options_page') ? true : [
            'code'    => 'acf_options_unavailable',
            'message' => 'Options pages need ACF Pro, which is not active on this site.',
        ];
    }

    /** Every options page, or one page's values when 'page' names its menu slug. */
    public static function get(array $args): array
    {
        if (! isset($args['page'])) {
            $pages = [];
            foreach ((array) acf_get_options_pages() as $slug => $page) {
                $pages[] = [
                    'page'       => (string) $slug,
                    'page_title' => (string) ($page['page_title'] ?? ''),
                    'post_id'    => (string) ($page['post_id'] ?? 'options'),
                ];
            }
            return [ 'pages' => $pages ];
        }

        $post_id = self::post_id((string) $args['page']);
        $fields  = get_fields($post_id);
        return [
            'page'    => (string) $args['page'],
            'post_id' => $post_id,
            'fields'  => is_array($fields) ? $fields : [],
        ];
    }

    /** Refuse an unknown page before any snapshot is written. */
    public static function validate(array $args): ?array
    {
        $page = acf_get_options_page((string) ($args['page'] ?? ''));
        return is_array($page) ? null : [
            'code'    => 'not_found',
            'message' => sprintf('No ACF options page has the menu slug "%s".', (string) ($args['page'] ?? '')),
        ];
    }

    /** The 'acf_options' snapshot target: the page's post_id and the field names the write touches. */
    public static function target(array $args): array
    {
        $post_id = self::post_id((string) $args['page']);
        $names   = [];
        foreach (array_keys((array) $args['fields']) as $selector) {
            $field   = ACF_Schema::resolve_field((string) $selector, $post_id, [ 'options_page' => (string) $args['page'] ]);
            $names[] = is_array($field) && '' !== (string) ($field['name'] ?? '') ? (string) $field['name'] : (string) $selector;
        }
        return [
            'object_type' => 'acf_options',
            'object_id'   => Snapshot::acf_options_id($post_id, $names),
        ];
    }

    /** Write the values with update_field() and return the page's values after the write. */
    public static function update(array $args): array
    {
        $post_id = self::post_id((string) $args['page']);
        foreach ((array) $args['fields'] as $selector => $value) {
            update_field((string) $selector, $value, $post_id);
        }
        return self::get([ 'page' => $args['page'] ]);
    }

    /** The ACF post_id an options page stores its values under. */
    private static function post_id(string $page): string
    {
        $found = acf_get_options_page($page);
        return is_array($found) && '' !== (string) ($found['post_id'] ?? '') ? (string) $found['post_id'] : 'options';
    }
}
