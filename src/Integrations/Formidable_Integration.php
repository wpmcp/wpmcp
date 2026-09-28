<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Formidable Forms integration (wpmcp/formidable-read / -write pair), part of
 * the forms adapter pack (issue #66, pro tier). Delegates to Formidable's own
 * models (verified against Formidable 6.x): FrmForm for forms, FrmField for
 * fields, FrmFormAction for email notifications (Formidable models them as
 * "email" form actions), and FrmEntry for entries.
 *
 * Entries are user data, so the entry operations sit behind manage_options on
 * top of the pair's own capability.
 *
 * No entry-status write: Formidable has no spam or trash state for an entry
 * (an entry is either a draft or submitted, and a deleted entry is gone), so
 * there is nothing for update-entry-status to move between. The write half is
 * registered with no operations so the surface stays stable if that changes.
 */
class Formidable_Integration extends Forms_Integration
{
    public function integration(): string
    {
        return 'formidable';
    }

    public function tier(): string
    {
        return 'pro';
    }

    public function is_available(): bool
    {
        return class_exists('FrmForm') && class_exists('FrmEntry');
    }

    protected function summary(): string
    {
        return 'Formidable Forms (forms, fields, notifications, and entries)';
    }

    private static function shape_entry(object $entry, bool $with_values): array
    {
        $out = [
            'id'         => (int) ($entry->id ?? 0),
            'item_key'   => (string) ($entry->item_key ?? ''),
            'form_id'    => (int) ($entry->form_id ?? 0),
            'is_draft'   => ! empty($entry->is_draft),
            'created_at' => (string) ($entry->created_at ?? ''),
        ];
        if ($with_values) {
            $out['values'] = is_array($entry->metas ?? null) ? $entry->metas : [];
            $out['ip']     = (string) ($entry->ip ?? '');
        }
        return $out;
    }

    protected function operations(): array
    {
        $form_only = [
            'type'       => 'object',
            'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
            'required'   => [ 'form_id' ],
        ];

        return [
            'list-forms' => [
                'mode'         => 'read',
                'description'  => 'List Formidable forms with id, name, and form key',
                'input_schema' => [ 'type' => 'object', 'properties' => [] ],
                'handler'      => function (): array {
                    $forms = \FrmForm::getAll();
                    $out   = [];
                    foreach ((array) $forms as $form) {
                        $out[] = [
                            'id'   => (int) ($form->id ?? 0),
                            'name' => (string) ($form->name ?? ''),
                            'key'  => (string) ($form->form_key ?? ''),
                        ];
                    }
                    return [ 'forms' => $out, 'total' => count($out) ];
                },
            ],
            'get-form' => [
                'mode'         => 'read',
                'description'  => 'Read one Formidable form (name, key, description, options)',
                'input_schema' => $form_only,
                'handler'      => function (array $args): array {
                    $form = \FrmForm::getOne((int) $args['form_id']);
                    return [ 'form' => empty($form) ? null : $form ];
                },
            ],
            'list-fields' => [
                'mode'         => 'read',
                'description'  => 'List one form\'s fields: id, key, type, label, whether it is required, and options for choice fields',
                'input_schema' => $form_only,
                'handler'      => function (array $args): array {
                    $form_id = (int) $args['form_id'];
                    if (empty(\FrmForm::getOne($form_id))) {
                        return [ 'form_id' => $form_id, 'fields' => null ];
                    }
                    $fields = [];
                    foreach ((array) \FrmField::get_all_for_form($form_id) as $field) {
                        $options  = is_array($field->options ?? null) ? $field->options : [];
                        $fields[] = [
                            'id'       => (int) ($field->id ?? 0),
                            'key'      => (string) ($field->field_key ?? ''),
                            'type'     => (string) ($field->type ?? ''),
                            'label'    => (string) ($field->name ?? ''),
                            'required' => ! empty($field->required),
                            'options'  => array_values(array_map(static fn ($o) => (string) (is_array($o) ? ($o['label'] ?? ($o['value'] ?? '')) : $o), $options)),
                        ];
                    }
                    return [ 'form_id' => $form_id, 'fields' => $fields ];
                },
            ],
            'list-notifications' => [
                'mode'         => 'read',
                'description'  => 'List one form\'s email notifications (Formidable "email" form actions): id, name, whether it is active, recipient, sender, subject, and message template',
                'input_schema' => $form_only,
                'handler'      => function (array $args): array {
                    $form_id = (int) $args['form_id'];
                    if (empty(\FrmForm::getOne($form_id))) {
                        return [ 'form_id' => $form_id, 'notifications' => null ];
                    }
                    $out = [];
                    foreach ((array) \FrmFormAction::get_action_for_form($form_id, 'email') as $action) {
                        $settings = is_array($action->post_content ?? null) ? $action->post_content : [];
                        $out[]    = [
                            'id'      => (int) ($action->ID ?? 0),
                            'name'    => (string) ($action->post_title ?? ''),
                            'active'  => 'publish' === ($action->post_status ?? ''),
                            'to'      => (string) ($settings['email_to'] ?? ''),
                            'from'    => (string) ($settings['from'] ?? ''),
                            'subject' => (string) ($settings['email_subject'] ?? ''),
                            'message' => (string) ($settings['email_message'] ?? ''),
                        ];
                    }
                    return [ 'form_id' => $form_id, 'notifications' => $out ];
                },
            ],
            'list-entries' => [
                'mode'         => 'read',
                'capability'   => self::ENTRY_CAPABILITY,
                'description'  => 'List a form\'s entries (submissions), newest first, with paging (page_size default 20, max 100, plus offset). Requires manage_options because entries are user data',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'form_id' => [ 'type' => 'integer', 'minimum' => 1 ] ] + self::paging_properties(),
                    'required'   => [ 'form_id' ],
                ],
                'handler'      => function (array $args): array {
                    $form_id                = (int) $args['form_id'];
                    [ $page_size, $offset ] = self::page_window($args);
                    $entries                = \FrmEntry::getAll(
                        [ 'it.form_id' => $form_id ],
                        ' ORDER BY it.created_at DESC, it.id DESC',
                        sprintf(' LIMIT %d,%d', $offset, $page_size)
                    );
                    $out = [];
                    foreach ((array) $entries as $entry) {
                        if (is_object($entry)) {
                            $out[] = self::shape_entry($entry, false);
                        }
                    }
                    return [
                        'form_id' => $form_id,
                        'entries' => $out,
                        'total'   => (int) \FrmEntry::getRecordCount($form_id),
                    ];
                },
            ],
            'get-entry' => [
                'mode'         => 'read',
                'capability'   => self::ENTRY_CAPABILITY,
                'description'  => 'Read a single entry with its field values by entry id. Requires manage_options because entries are user data',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'entry_id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
                    'required'   => [ 'entry_id' ],
                ],
                'handler'      => function (array $args): array {
                    $entry = \FrmEntry::getOne((int) $args['entry_id'], true);
                    return [ 'entry' => is_object($entry) ? self::shape_entry($entry, true) : null ];
                },
            ],
        ];
    }
}
