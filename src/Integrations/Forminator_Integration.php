<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Forminator integration exposed as a wpmcp/forminator-read /
 * wpmcp/forminator-write dispatcher pair (issue #136 forms breadth).
 *
 * Every operation delegates to Forminator's own public Forminator_API
 * (verified against Forminator 1.5x): get_forms(), get_form(),
 * get_form_entries(), get_entry(), delete_entry(). Forminator keeps forms in
 * its own custom post type and submissions in its own custom tables, so
 * reading through the public API rather than hand-rolled SQL keeps this
 * upgrade-safe across Forminator schema changes.
 *
 * delete-entry is mode=destructive: the dispatcher refuses it without
 * confirm:true, routes the decision through op-granular governance under the
 * synthetic wpmcp/forminator-delete-entry name, and demands manage_options on
 * top of the pair's own capability because deleting a submission destroys
 * personal data. It declares NO snapshot target: Forminator entries live in
 * forminator_form_entry / forminator_form_entry_meta, which Safe_Mutation does
 * not know how to capture or resurrect, so the dispatcher runs the delete
 * directly and honestly flags recoverable:false in the response instead of
 * implying a rollback that does not exist.
 */
class Forminator_Integration extends Integration_Dispatcher
{
    public function integration(): string
    {
        return 'forminator';
    }

    public function is_available(): bool
    {
        return class_exists('Forminator_API');
    }

    protected function summary(): string
    {
        return 'Forminator (forms, their field definitions, and submissions)';
    }

    /** The shortcode that embeds a Forminator form on a page. */
    private static function shortcode(int $form_id): string
    {
        return sprintf('[forminator_form id="%d"]', $form_id);
    }

    /** Display name from settings.formName, falling back to the model slug. */
    private static function form_name($form): string
    {
        if (! is_object($form)) {
            return '';
        }
        $settings = is_array($form->settings ?? null) ? $form->settings : [];
        $name     = (string) ($settings['formName'] ?? '');
        if ('' !== $name) {
            return $name;
        }
        return (string) ($form->name ?? '');
    }

    /**
     * Field rows from a Forminator_Form_Model. Forminator's field model has a
     * magic __get with no matching __isset, so ->raw is read directly rather
     * than probed with isset().
     */
    private static function form_fields($form): array
    {
        $fields = [];
        if (! is_object($form) || ! method_exists($form, 'get_fields')) {
            return $fields;
        }
        foreach ((array) $form->get_fields() as $field) {
            $raw      = is_object($field) ? (array) ($field->raw ?? []) : (array) $field;
            $fields[] = [
                'id'       => (string) ($raw['element_id'] ?? ''),
                'type'     => (string) ($raw['type'] ?? ''),
                'label'    => (string) ($raw['field_label'] ?? ''),
                'required' => ! empty($raw['required']),
            ];
        }
        return $fields;
    }

    /** Compact row from a Forminator_Form_Entry_Model. */
    private static function entry_row($entry): array
    {
        $values = [];
        $meta   = is_object($entry) && is_array($entry->meta_data ?? null) ? $entry->meta_data : [];
        foreach ($meta as $field_id => $cell) {
            $values[ (string) $field_id ] = (is_array($cell) && array_key_exists('value', $cell)) ? $cell['value'] : $cell;
        }
        return [
            'id'         => is_object($entry) ? (int) ($entry->entry_id ?? 0) : 0,
            'created_at' => is_object($entry) ? (string) ($entry->date_created_sql ?? '') : '',
            'values'     => $values,
        ];
    }

    protected function operations(): array
    {
        return [
            'list-forms' => [
                'mode'         => 'read',
                'description'  => 'List Forminator forms with id, display name, status, and the shortcode that embeds each form',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'page_size' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ],
                        'page'      => [ 'type' => 'integer', 'minimum' => 1 ],
                    ],
                ],
                'handler'      => function (array $args): array {
                    $page_size = (int) ($args['page_size'] ?? 100);
                    $page      = (int) ($args['page'] ?? 1);
                    $forms     = \Forminator_API::get_forms(null, $page, $page_size);
                    $out       = [];
                    foreach ((array) ($forms ?: []) as $form) {
                        $id    = is_object($form) ? (int) ($form->id ?? 0) : 0;
                        $out[] = [
                            'id'        => $id,
                            'name'      => self::form_name($form),
                            'status'    => is_object($form) ? (string) ($form->status ?? '') : '',
                            'shortcode' => self::shortcode($id),
                        ];
                    }
                    return [ 'forms' => $out, 'total' => count($out) ];
                },
            ],
            'get-form' => [
                'mode'         => 'read',
                'description'  => 'Read one Forminator form: display name, embed shortcode, and its fields (id, type, label, required)',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
                    'required'   => [ 'form_id' ],
                ],
                'handler'      => function (array $args): array {
                    $id   = (int) $args['form_id'];
                    $form = \Forminator_API::get_form($id);
                    if (! $form || is_wp_error($form)) {
                        return [ 'form' => null ];
                    }
                    return [ 'form' => [
                        'id'        => $id,
                        'name'      => self::form_name($form),
                        'status'    => is_object($form) ? (string) ($form->status ?? '') : '',
                        'shortcode' => self::shortcode($id),
                        'fields'    => self::form_fields($form),
                    ] ];
                },
            ],
            'list-entries' => [
                'mode'         => 'read',
                'description'  => 'List a Forminator form\'s submissions with paging (page_size default 20, max 100) and their decoded field values',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'form_id'   => [ 'type' => 'integer', 'minimum' => 1 ],
                        'page_size' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ],
                        'offset'    => [ 'type' => 'integer', 'minimum' => 0 ],
                    ],
                    'required'   => [ 'form_id' ],
                ],
                'handler'      => function (array $args): array {
                    $form_id   = (int) $args['form_id'];
                    $page_size = (int) ($args['page_size'] ?? 20);
                    $offset    = (int) ($args['offset'] ?? 0);
                    $entries   = (array) (\Forminator_API::get_form_entries($form_id) ?: []);
                    $out       = [];
                    foreach (array_slice(array_values($entries), $offset, $page_size) as $entry) {
                        $out[] = self::entry_row($entry);
                    }
                    return [
                        'form_id' => $form_id,
                        'entries' => $out,
                        'total'   => count($entries),
                        'paging'  => [ 'offset' => $offset, 'page_size' => $page_size ],
                    ];
                },
            ],
            'get-entry' => [
                'mode'         => 'read',
                'description'  => 'Read one Forminator submission by form_id plus entry_id, with its decoded field values',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'form_id'  => [ 'type' => 'integer', 'minimum' => 1 ],
                        'entry_id' => [ 'type' => 'integer', 'minimum' => 1 ],
                    ],
                    'required'   => [ 'form_id', 'entry_id' ],
                ],
                'handler'      => function (array $args): array {
                    $entry = \Forminator_API::get_entry((int) $args['form_id'], (int) $args['entry_id']);
                    if (! $entry || is_wp_error($entry) || empty($entry->entry_id)) {
                        return [ 'entry' => null ];
                    }
                    return [ 'entry' => self::entry_row($entry) ];
                },
            ],
            'delete-entry' => [
                'mode'               => 'destructive',
                // Issue #66: entry deletion is off by default across every
                // forms adapter. A site opts in with the
                // wpmcp_integration_op_enabled filter.
                'enabled_by_default' => false,
                'capability'         => 'manage_options',
                'description'  => 'Permanently delete one Forminator submission via Forminator_API::delete_entry. Default-off (opt in via the wpmcp_integration_op_enabled filter); requires confirm:true. NOT reversible: Forminator entries live in Forminator\'s own tables, which WP MCP cannot snapshot, so the response carries recoverable:false and rollback-operation cannot bring the submission back',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'form_id'  => [ 'type' => 'integer', 'minimum' => 1 ],
                        'entry_id' => [ 'type' => 'integer', 'minimum' => 1 ],
                    ],
                    'required'   => [ 'form_id', 'entry_id' ],
                ],
                'handler'      => function (array $args): array {
                    $form_id  = (int) $args['form_id'];
                    $entry_id = (int) $args['entry_id'];
                    $result   = \Forminator_API::delete_entry($form_id, $entry_id);
                    return [
                        'form_id'  => $form_id,
                        'entry_id' => $entry_id,
                        'deleted'  => ! is_wp_error($result) && false !== $result,
                    ];
                },
            ],
        ];
    }
}
