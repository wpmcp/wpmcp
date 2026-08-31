<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * SureForms integration exposed as a wpmcp/sureforms-read /
 * wpmcp/sureforms-write dispatcher pair (issue #136 forms breadth).
 *
 * SureForms is a block-based builder (verified against SureForms 2.x): forms
 * are the `sureforms_form` custom post type whose fields are `srfm/*` blocks
 * in post_content, and submissions live in SureForms' own `srfm_entries`
 * table. Field definitions are read by parsing the block tree with core's
 * parse_blocks(); entries are read exclusively through SureForms' own
 * SRFM\Inc\Database\Tables\Entries accessor (get(), get_all(),
 * get_total_entries_by_status(), delete()) rather than raw $wpdb queries, so
 * the integration survives SureForms schema changes.
 *
 * delete-entry is mode=destructive: the dispatcher refuses it without
 * confirm:true, evaluates it through op-granular governance under the
 * synthetic wpmcp/sureforms-delete-entry name, and requires manage_options on
 * top of the pair's own capability because a submission is personal data. It
 * declares NO snapshot target: the entries table is not one of the object
 * types Safe_Mutation can capture and resurrect, so the write runs directly
 * and the response is honestly flagged recoverable:false rather than
 * pretending rollback-operation could undo it.
 */
class SureForms_Integration extends Integration_Dispatcher
{
    private const FORM_CPT = 'sureforms_form';

    /** SureForms' own entries-table accessor; the only path we use for entries. */
    private const ENTRIES = '\SRFM\Inc\Database\Tables\Entries';

    public function integration(): string
    {
        return 'sureforms';
    }

    public function is_available(): bool
    {
        return (defined('SRFM_VER') || post_type_exists(self::FORM_CPT)) && class_exists(self::ENTRIES);
    }

    protected function summary(): string
    {
        return 'SureForms (forms, their block field definitions, and entries)';
    }

    /** The shortcode that embeds a SureForms form on a page. */
    private static function shortcode(int $form_id): string
    {
        return sprintf('[sureforms id="%d"]', $form_id);
    }

    /** Read a column off an entry row, which SureForms may hand back as object or array. */
    private static function column($row, string $key)
    {
        if (is_object($row)) {
            return $row->{$key} ?? null;
        }
        if (is_array($row)) {
            return $row[ $key ] ?? null;
        }
        return null;
    }

    /** The decoded { field slug => value } map stored on an entry row. */
    private static function values($row): array
    {
        $data = self::column($row, 'form_data');
        if (is_string($data)) {
            $data = json_decode($data, true);
        }
        return is_array($data) ? $data : [];
    }

    /** Normalised entry payload shared by list-entries and get-entry. */
    private static function entry_row($row): array
    {
        return [
            'id'         => (int) self::column($row, 'ID'),
            'form_id'    => (int) self::column($row, 'form_id'),
            'status'     => (string) self::column($row, 'status'),
            'created_at' => (string) self::column($row, 'created_at'),
            'values'     => self::values($row),
        ];
    }

    /**
     * Walk a parsed block tree and collect every srfm/* field block with its
     * slug, label, type, and required flag. Recursion is needed because
     * SureForms nests fields inside layout blocks.
     */
    private static function collect_fields(array $blocks, array &$out): void
    {
        foreach ($blocks as $block) {
            $name = is_array($block) ? (string) ($block['blockName'] ?? '') : '';
            if (0 === strpos($name, 'srfm/')) {
                $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
                $slug  = (string) ($attrs['slug'] ?? '');
                if ('' !== $slug || isset($attrs['label'])) {
                    $out[] = [
                        'name'     => $slug,
                        'label'    => (string) ($attrs['label'] ?? ''),
                        'type'     => substr($name, 5),
                        'required' => ! empty($attrs['required']),
                    ];
                }
            }
            if (! empty($block['innerBlocks']) && is_array($block['innerBlocks'])) {
                self::collect_fields($block['innerBlocks'], $out);
            }
        }
    }

    protected function operations(): array
    {
        $entries = self::ENTRIES;

        return [
            'list-forms' => [
                'mode'         => 'read',
                'description'  => 'List SureForms forms with id, title, status, entry count, and the shortcode that embeds each form',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'page_size' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ],
                        'page'      => [ 'type' => 'integer', 'minimum' => 1 ],
                    ],
                ],
                'handler'      => function (array $args) use ($entries): array {
                    $query = new \WP_Query([
                        'post_type'      => self::FORM_CPT,
                        'post_status'    => 'any',
                        'posts_per_page' => (int) ($args['page_size'] ?? 20),
                        'paged'          => (int) ($args['page'] ?? 1),
                        'orderby'        => 'ID',
                        'order'          => 'DESC',
                    ]);
                    $forms = [];
                    foreach ($query->posts as $post) {
                        $id      = (int) $post->ID;
                        $forms[] = [
                            'id'          => $id,
                            'title'       => (string) $post->post_title,
                            'status'      => (string) $post->post_status,
                            'entry_count' => (int) $entries::get_total_entries_by_status('all', $id),
                            'shortcode'   => self::shortcode($id),
                        ];
                    }
                    return [ 'forms' => $forms, 'total' => (int) $query->found_posts ];
                },
            ],
            'get-form' => [
                'mode'         => 'read',
                'description'  => 'Read one SureForms form: title, embed shortcode, and its fields (name, label, type, required) parsed from the form\'s srfm/* blocks',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
                    'required'   => [ 'form_id' ],
                ],
                'handler'      => function (array $args): array {
                    $id   = (int) $args['form_id'];
                    $post = get_post($id);
                    if (! $post || self::FORM_CPT !== $post->post_type) {
                        return [ 'form' => null ];
                    }
                    $fields = [];
                    self::collect_fields(parse_blocks((string) $post->post_content), $fields);
                    return [ 'form' => [
                        'id'        => $id,
                        'title'     => (string) $post->post_title,
                        'status'    => (string) $post->post_status,
                        'shortcode' => self::shortcode($id),
                        'fields'    => $fields,
                    ] ];
                },
            ],
            'list-entries' => [
                'mode'         => 'read',
                'description'  => 'List a SureForms form\'s entries with paging (page_size default 20, max 100), each with status, timestamp, and decoded field values',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'form_id'   => [ 'type' => 'integer', 'minimum' => 1 ],
                        'page_size' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ],
                        'offset'    => [ 'type' => 'integer', 'minimum' => 0 ],
                    ],
                    'required'   => [ 'form_id' ],
                ],
                'handler'      => function (array $args) use ($entries): array {
                    $form_id   = (int) $args['form_id'];
                    $page_size = (int) ($args['page_size'] ?? 20);
                    $offset    = (int) ($args['offset'] ?? 0);
                    $rows      = (array) ($entries::get_all([
                        'where' => [
                            [ 'key' => 'form_id', 'value' => $form_id, 'compare' => '=' ],
                        ],
                    ]) ?: []);
                    $out = [];
                    foreach (array_slice(array_values($rows), $offset, $page_size) as $row) {
                        $out[] = self::entry_row($row);
                    }
                    return [
                        'form_id' => $form_id,
                        'entries' => $out,
                        'total'   => count($rows),
                        'paging'  => [ 'offset' => $offset, 'page_size' => $page_size ],
                    ];
                },
            ],
            'get-entry' => [
                'mode'         => 'read',
                'description'  => 'Read one SureForms entry by entry_id, with its status, timestamp, and decoded field values',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'entry_id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
                    'required'   => [ 'entry_id' ],
                ],
                'handler'      => function (array $args) use ($entries): array {
                    $row = $entries::get((int) $args['entry_id']);
                    if (empty($row) || ! self::column($row, 'ID')) {
                        return [ 'entry' => null ];
                    }
                    return [ 'entry' => self::entry_row($row) ];
                },
            ],
            'delete-entry' => [
                'mode'               => 'destructive',
                // Issue #66: entry deletion is off by default across every
                // forms adapter. A site opts in with the
                // wpmcp_integration_op_enabled filter.
                'enabled_by_default' => false,
                'capability'         => 'manage_options',
                'description'  => 'Permanently delete one SureForms entry through SureForms\' own entries accessor. Default-off (opt in via the wpmcp_integration_op_enabled filter); requires confirm:true. NOT reversible: SureForms entries live in the srfm_entries table, which WP MCP cannot snapshot, so the response carries recoverable:false and rollback-operation cannot bring the entry back',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'entry_id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
                    'required'   => [ 'entry_id' ],
                ],
                'handler'      => function (array $args) use ($entries): array {
                    $entry_id = (int) $args['entry_id'];
                    $row      = $entries::get($entry_id);
                    if (empty($row) || ! self::column($row, 'ID')) {
                        return [ 'entry_id' => $entry_id, 'deleted' => false, 'reason' => 'not_found' ];
                    }
                    return [
                        'entry_id' => $entry_id,
                        'deleted'  => (bool) $entries::delete($entry_id),
                    ];
                },
            ],
        ];
    }
}
