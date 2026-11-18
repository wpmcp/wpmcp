<?php

namespace WPMCP\Integrations;

use WPMCP\Privacy\Masking;

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
 * personal data. Since issue #675 it is undoable: the entry and its meta rows
 * (frmt_form_entry, frmt_form_entry_meta) are each snapshotted as a db_rows
 * before-image in one session and removed directly (the WS Form pattern), so
 * rollback-session reinserts them. Forminator's own delete hooks and file
 * cleanup do not run: uploaded files stay on disk.
 */
class Forminator_Integration extends Forms_Integration
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
    private static function entry_row($entry, bool $masked = false): array
    {
        $values = [];
        $meta   = is_object($entry) && is_array($entry->meta_data ?? null) ? $entry->meta_data : [];
        foreach ($meta as $field_id => $cell) {
            $values[ (string) $field_id ] = (is_array($cell) && array_key_exists('value', $cell)) ? $cell['value'] : $cell;
        }
        return [
            'id'         => is_object($entry) ? (int) ($entry->entry_id ?? 0) : 0,
            'created_at' => is_object($entry) ? (string) ($entry->date_created_sql ?? '') : '',
            'values'     => $masked ? Masking::value($values) : $values,
        ];
    }

    /** @return array<string, mixed>|null a refusal when Forminator has no such entry on that form */
    private static function entry_refusal(array $args): ?array
    {
        $entry = \Forminator_API::get_entry((int) $args['form_id'], (int) $args['entry_id']);
        // Forminator_API::get_entry() hands back a model for any id, whatever form
        // it belongs to, so the form is matched here.
        if ($entry && ! is_wp_error($entry) && ! empty($entry->entry_id) && (int) $entry->form_id === (int) $args['form_id']) {
            return null;
        }

        return [ 'code' => 'entry_not_found', 'message' => 'Forminator has no submission with that id on that form.', 'data' => [ 'form_id' => (int) $args['form_id'], 'entry_id' => (int) $args['entry_id'] ] ];
    }

    /**
     * Forminator keeps a Stripe or PayPal payment as entry meta (stripe-1,
     * paypal-1 and so on), so an entry with such a row has a payment record.
     *
     * @return array<string, mixed>|null
     */
    private static function forminator_payment_refusal(int $entry_id): ?array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'frmt_form_entry_meta';
        if (! \WPMCP\Safety\Plugin_Table_Rows_Snapshot::table_exists($table)) {
            return null;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reads the host plugin's own entry meta fresh, so a payment is never missed.
        $found = $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE entry_id = %d AND (meta_key LIKE %s OR meta_key LIKE %s)', $table, $entry_id, $wpdb->esc_like('stripe-') . '%', $wpdb->esc_like('paypal-') . '%'));

        return (int) $found > 0
            ? [ 'code' => 'entry_has_payments', 'message' => 'This entry has a payment record (Stripe or PayPal). Financial records are not deleted here.', 'data' => [ 'entry_id' => $entry_id ] ]
            : null;
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
                'objects'      => [ 'form_id' => [ 'type' => 'post', 'own_type' => true ] ],
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
                'objects'      => [ 'form_id' => [ 'type' => 'post', 'own_type' => true ] ],
                'capability'   => self::ENTRY_CAPABILITY,
                'description'  => 'List a Forminator form\'s submissions with paging (page_size default 20, max 100) and their decoded field values MASKED (get-entry returns one in full)',
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
                        $out[] = self::entry_row($entry, true);
                    }
                    return [
                        'form_id' => $form_id,
                        'entries' => $out,
                        'masked'  => true,
                        'total'   => count($entries),
                        'paging'  => [ 'offset' => $offset, 'page_size' => $page_size ],
                    ];
                },
            ],
            'get-entry' => [
                'mode'         => 'read',
                'objects'      => [ 'form_id' => [ 'type' => 'post', 'own_type' => true ], 'entry_id' => 'entry' ],
                'capability'   => self::ENTRY_CAPABILITY,
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
                'mode'              => 'destructive',
                'self_snapshotting' => true,
                'objects'           => [ 'form_id' => [ 'type' => 'post', 'own_type' => true, 'access' => 'read' ], 'entry_id' => 'entry' ],
                'capability'        => 'manage_options',
                'description'       => 'Permanently delete one Forminator submission (refused with entry_has_payments when it has a Stripe or PayPal record) with its values (the rows of the frmt_form_entry and frmt_form_entry_meta tables, removed directly: Forminator\'s own delete hooks do not run and uploaded files stay on disk). Off by default (opt in with wpmcp_enable_forms_write); needs confirm:true. Reversible: every row is snapshotted first and rollback-session puts them back. ' . self::NOT_ERASURE,
                'input_schema'      => [
                    'type'       => 'object',
                    'properties' => [
                        'form_id'  => [ 'type' => 'integer', 'minimum' => 1 ],
                        'entry_id' => [ 'type' => 'integer', 'minimum' => 1 ],
                    ],
                    'required'   => [ 'form_id', 'entry_id' ],
                ],
                'validate'          => static fn (array $args): ?array => self::entry_refusal($args) ?? self::forminator_payment_refusal((int) $args['entry_id']),
                'confirm_preview'   => static function (array $args): array {
                    $entry = \Forminator_API::get_entry((int) $args['form_id'], (int) $args['entry_id']);
                    $meta  = self::count_rows('frmt_form_entry_meta', 'entry_id', (int) $args['entry_id']);
                    return self::delete_entry_preview(
                        sprintf('Forminator submission %d of form %d', (int) $args['entry_id'], (int) $args['form_id']),
                        (int) $args['entry_id'],
                        $meta,
                        'rollback-session',
                        [ 'form_id' => (int) $args['form_id'], 'date' => (string) ($entry->date_created_sql ?? '') ]
                    );
                },
                'handler'           => static function (array $args, array $context): array {
                    $form_id  = (int) $args['form_id'];
                    $entry_id = (int) $args['entry_id'];
                    $done     = self::delete_rows_in_session([
                        [ 'table' => 'frmt_form_entry_meta', 'primary_key' => [ 'meta_id' ], 'where' => [ 'entry_id' => $entry_id ], 'optional' => true ],
                        [ 'table' => 'frmt_form_entry', 'primary_key' => [ 'entry_id' ], 'where' => [ 'entry_id' => $entry_id ] ],
                    ], $args, $context);
                    if (class_exists('Forminator_Form_Entry_Model')) {
                        wp_cache_delete($entry_id, \Forminator_Form_Entry_Model::FORM_ENTRY_CACHE_GROUP);
                        if (is_callable([ 'Forminator_Form_Entry_Model', 'delete_form_entry_cache' ])) {
                            \Forminator_Form_Entry_Model::delete_form_entry_cache($form_id);
                        }
                    }
                    return [
                        'result'        => [ 'form_id' => $form_id, 'entry_id' => $entry_id, 'deleted' => ($done['result']['frmt_form_entry'] ?? 0) > 0, 'rows' => $done['result'] ],
                        'operation_ids' => $done['operation_ids'],
                    ];
                },
            ] + self::write_flags(),
        ];
    }
}
