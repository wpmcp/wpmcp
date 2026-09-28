<?php

namespace WPMCP\Integrations;

use WPMCP\Tools\ACF\Get_Fields;
use WPMCP\Tools\ACF\List_Field_Groups;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Reference integration proving the dispatcher framework end-to-end (#65):
 * ACF field read/write behind a single wpmcp/acf-read + wpmcp/acf-write
 * pair.
 *
 * Read operations reuse the existing flat-tool handlers (Get_Fields,
 * List_Field_Groups) unchanged. The update-fields write op mirrors the flat
 * wpmcp/update-fields tool's posture exactly: default-off, opted in through
 * the SAME wpmcp_enable_acf_write filter (a site that has already enabled
 * ACF writes gets the dispatcher write too, with no second switch), and
 * snapshotted on the post target — ACF values are ordinary postmeta, so the
 * standard post snapshot captures them and rollback-operation restores them
 * exactly.
 *
 * Unlike the flat ACF tool group (which skips registration when ACF is
 * absent), the dispatcher pair registers unconditionally: availability is a
 * call-time concern for dispatchers, and a missing host plugin yields a
 * clean integration_unavailable error instead of an absent tool.
 *
 * Schema authoring (issue #291) lives here too rather than in new tools, so
 * the tools/list surface stays at the one pair: field groups and their
 * fields, ACF post types and taxonomies, options page values, field type
 * discovery and value validation (see ACF_Schema
 * and ACF_Options). Every structure write is an upsert by
 * ACF key, needs ACF's own capability setting (manage_options by default),
 * follows the same wpmcp_enable_acf_write opt-in as update-fields, and is
 * snapshotted as an 'acf_structure' (field group, post type, taxonomy) or
 * 'acf_options' target, so rollback-operation restores it exactly.
 */
class ACF_Integration extends Integration_Dispatcher
{
    public function integration(): string
    {
        return 'acf';
    }

    public function is_available(): bool
    {
        return function_exists('acf_get_field_groups')
            && function_exists('get_fields')
            && function_exists('update_field');
    }

    protected function summary(): string
    {
        return 'Advanced Custom Fields (field groups, post types, taxonomies, options and field values)';
    }

    protected function operations(): array
    {
        return [
            'list-field-groups' => [
                'mode'         => 'read',
                'description'  => 'List registered ACF field groups: key, title, a flattened summary of their location rules, and whether each is active',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [],
                ],
                'handler'      => fn (array $args) => (new List_Field_Groups())->handle($args),
            ],
            'get-fields'        => [
                'mode'         => 'read',
                'description'  => 'Read a post\'s ACF field values, keyed by field name, via get_fields()',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'post_id' => [ 'type' => 'integer', 'minimum' => 1 ],
                    ],
                    'required'   => [ 'post_id' ],
                ],
                'handler'      => fn (array $args) => (new Get_Fields())->handle($args),
            ],
            'update-fields'     => [
                'mode'               => 'write',
                'description'        => 'Set one or more ACF field values on a post via update_field(). Snapshotted on the post target; restorable with rollback-operation. Disabled by default (site opts in via the wpmcp_enable_acf_write filter)',
                'enabled_by_default' => (bool) apply_filters('wpmcp_enable_acf_write', false),
                'input_schema'       => [
                    'type'       => 'object',
                    'properties' => [
                        'post_id' => [ 'type' => 'integer', 'minimum' => 1 ],
                        'fields'  => [ 'type' => 'object', 'minProperties' => 1 ],
                    ],
                    'required'   => [ 'post_id', 'fields' ],
                ],
                'handler'            => function (array $args): array {
                    $post_id = (int) $args['post_id'];
                    foreach ((array) $args['fields'] as $selector => $value) {
                        update_field((string) $selector, $value, $post_id);
                    }
                    $fields = get_fields($post_id);
                    return [ 'post_id' => $post_id, 'fields' => is_array($fields) ? $fields : [] ];
                },
                'snapshot'           => fn (array $args) => [
                    'object_type' => 'post',
                    'object_id'   => (int) $args['post_id'],
                ],
            ],
            'batch-update-fields' => ACF_Batch_Update::definition(),
        ] + $this->schema_operations();
    }

    /** Schema authoring ops (issue #291): structure reads, validation, and snapshotted structure writes. */
    private function schema_operations(): array
    {
        $key       = [ 'type' => 'string', 'minLength' => 1 ];
        $keyed     = [
            'type'       => 'object',
            'properties' => [ 'key' => $key ],
            'required'   => [ 'key' ],
        ];
        $writes_on = (bool) apply_filters('wpmcp_enable_acf_write', false);
        $cap       = ACF_Schema::capability();
        $write     = fn (string $kind, string $description, array $properties, array $extra = []) => $extra + [
            'mode'               => 'write',
            'description'        => $description . ' Upsert by key (omit key to create). Snapshotted; rollback-operation restores it exactly. Disabled by default (wpmcp_enable_acf_write filter)',
            'capability'         => $cap,
            'enabled_by_default' => $writes_on,
            'input_schema'       => [
                'type'       => 'object',
                'properties' => [ 'key' => $key ] + $properties,
            ],
            'validate'           => fn (array $args) => 'field_group' === $kind
                ? ACF_Schema::validate_field_group($args)
                : ACF_Schema::validate_internal($kind, $args),
            'snapshot'           => fn (array $args) => ACF_Schema::target($kind, $args),
            'handler'            => fn (array $args, array $context) => 'field_group' === $kind
                ? ACF_Schema::save_field_group($args, $context)
                : ACF_Schema::save_internal($kind, $args, $context),
        ];
        $registration = [ 'requires' => [ ACF_Schema::class, 'registration_requirement' ] ];
        $labels       = [ 'type' => 'object' ];

        return [
            'get-field-group'   => [
                'mode'         => 'read',
                'description'  => 'Read one field group by key with every setting and its full field tree',
                'input_schema' => $keyed,
                'handler'      => fn (array $args) => ACF_Schema::get_field_group((string) $args['key']),
            ],
            'list-field-types'  => [
                'mode'         => 'read',
                'description'  => 'List the field types this ACF install offers (name, label, category)',
                'input_schema' => [ 'type' => 'object', 'properties' => [] ],
                'handler'      => fn (array $args) => ACF_Schema::field_types(),
            ],
            'list-post-types'   => [
                'mode'         => 'read',
                'description'  => 'List ACF-registered post types: key, title, post_type slug, active, local',
                'input_schema' => [ 'type' => 'object', 'properties' => [] ],
                'handler'      => fn (array $args) => ACF_Schema::list_internal('post_type'),
            ] + $registration,
            'get-post-type'     => [
                'mode'         => 'read',
                'description'  => 'Read one ACF post type by key with every setting',
                'input_schema' => $keyed,
                'handler'      => fn (array $args) => ACF_Schema::get_internal('post_type', (string) $args['key']),
            ] + $registration,
            'list-taxonomies'   => [
                'mode'         => 'read',
                'description'  => 'List ACF-registered taxonomies: key, title, taxonomy slug, active, local',
                'input_schema' => [ 'type' => 'object', 'properties' => [] ],
                'handler'      => fn (array $args) => ACF_Schema::list_internal('taxonomy'),
            ] + $registration,
            'get-taxonomy'      => [
                'mode'         => 'read',
                'description'  => 'Read one ACF taxonomy by key with every setting',
                'input_schema' => $keyed,
                'handler'      => fn (array $args) => ACF_Schema::get_internal('taxonomy', (string) $args['key']),
            ] + $registration,
            'validate-fields'   => [
                'mode'         => 'read',
                'description'  => 'Check values against their fields with ACF\'s own validation (required, number range, email, URL, custom rules) without writing. Pass post_id so field names resolve by that post\'s groups',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'post_id' => [ 'type' => 'integer', 'minimum' => 1 ],
                        'fields'  => [ 'type' => 'object', 'minProperties' => 1 ],
                    ],
                    'required'   => [ 'fields' ],
                ],
                'handler'      => fn (array $args) => ACF_Schema::validate_values((array) $args['fields'], isset($args['post_id']) ? (int) $args['post_id'] : false),
            ],
            'get-options'       => [
                'mode'         => 'read',
                'description'  => 'List options pages, or read one page\'s field values when page (its menu slug) is given. Needs ACF Pro',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'page' => [ 'type' => 'string', 'minLength' => 1 ] ],
                ],
                'requires'     => [ ACF_Options::class, 'requirement' ],
                'handler'      => fn (array $args) => ACF_Options::get($args),
            ],
            'update-options'    => [
                'mode'               => 'write',
                'description'        => 'Set field values on an options page via update_field(). Snapshotted; rollback-operation restores the prior rows exactly. Needs ACF Pro. Disabled by default (wpmcp_enable_acf_write filter)',
                'capability'         => $cap,
                'enabled_by_default' => $writes_on,
                'requires'           => [ ACF_Options::class, 'requirement' ],
                'input_schema'       => [
                    'type'       => 'object',
                    'properties' => [
                        'page'   => [ 'type' => 'string', 'minLength' => 1 ],
                        'fields' => [ 'type' => 'object', 'minProperties' => 1 ],
                    ],
                    'required'   => [ 'page', 'fields' ],
                ],
                'validate'           => fn (array $args) => ACF_Options::validate($args),
                'snapshot'           => fn (array $args) => ACF_Options::target($args),
                'handler'            => fn (array $args) => ACF_Options::update($args),
            ],
            'save-field-group'  => $write('field_group', 'Create or update a field group. fields (optional) replaces the field list: keep a field by passing its key, omit it to delete it; each needs label, name and a type from list-field-types, and may nest sub_fields.', [
                'title'    => [ 'type' => 'string' ],
                'fields'   => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ],
                'location' => [ 'type' => 'array' ],
            ]),
            'save-post-type'    => $write('post_type', 'Create or update an ACF post type (registered by ACF on the next request). New ones need post_type (slug, max 20) and title (plural label); other ACF settings pass through.', [
                'post_type' => [ 'type' => 'string' ],
                'title'     => [ 'type' => 'string' ],
                'labels'    => $labels,
            ], $registration),
            'save-taxonomy'     => $write('taxonomy', 'Create or update an ACF taxonomy (registered by ACF on the next request). New ones need taxonomy (slug, max 32) and title (plural label); object_type lists post types.', [
                'taxonomy'    => [ 'type' => 'string' ],
                'title'       => [ 'type' => 'string' ],
                'labels'      => $labels,
                'object_type' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
            ], $registration),
        ];
    }
}
