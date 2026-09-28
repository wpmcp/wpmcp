<?php

namespace WPMCP\Integrations;

use WPMCP\Safety\Snapshot;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * ACF schema authoring for the acf dispatcher pair (issue #291): field
 * groups with their fields, ACF-registered post types and taxonomies, field
 * type discovery and value validation.
 *
 * Everything is written through ACF's own import/update APIs
 * (acf_import_field_group(), acf_update_field_group(),
 * acf_import_internal_post_type()), never by writing ACF's posts directly,
 * so ACF's validation, defaults, hooks and caches all apply. Every write is
 * an upsert keyed by the ACF key: the dispatcher's snapshot callable picks
 * the key (the caller's, or a fresh one for a create), captures the
 * 'acf_structure' snapshot under it, and hands it to the handler, so a
 * create and its undo agree on what was created.
 */
class ACF_Schema
{
    /** kind => [ACF post type, key prefix, slug setting, slug max length]. */
    public const KINDS = [
        'field_group' => [ 'acf-field-group', 'group_', '', 0 ],
        'post_type'   => [ 'acf-post-type', 'post_type_', 'post_type', 20 ],
        'taxonomy'    => [ 'acf-taxonomy', 'taxonomy_', 'taxonomy', 32 ],
    ];

    /** Settings a caller may never set: ACF derives them or they would retarget the write. */
    private const RESERVED = [ 'ID', 'key', 'fields', 'local', 'local_file', 'modified', '_valid' ];

    /** Whether ACF's post type and taxonomy registration (ACF 6.1+) is loaded. */
    public static function supports_registration(): bool
    {
        return function_exists('acf_get_acf_post_types')
            && function_exists('acf_import_internal_post_type')
            && function_exists('acf_get_wp_reserved_terms');
    }

    /** 'requires' check for the post type and taxonomy ops. */
    public static function registration_requirement()
    {
        return self::supports_registration() ? true : [
            'code'    => 'acf_registration_unavailable',
            'message' => 'Registering post types and taxonomies needs ACF 6.1 or later.',
        ];
    }

    /** The capability ACF itself demands for editing its structure. */
    public static function capability(): string
    {
        $cap = function_exists('acf_get_setting') ? (string) acf_get_setting('capability') : '';
        return '' === $cap ? 'manage_options' : $cap;
    }

    /** A fresh ACF key with the given prefix, in ACF's own 13-character style. */
    public static function new_key(string $prefix): string
    {
        return $prefix . substr(str_replace('-', '', wp_generate_uuid4()), 0, 13);
    }

    /** The snapshot target for a structure write: the caller's key, or a fresh one for a create. */
    public static function target(string $kind, array $args): array
    {
        $key = (string) ($args['key'] ?? '');
        return [
            'object_type' => 'acf_structure',
            'object_id'   => '' !== $key ? $key : self::new_key(self::KINDS[ $kind ][1]),
        ];
    }

    /** The key the dispatcher captured for this write. */
    private static function key_from(array $context): string
    {
        return (string) ($context['target']['object_id'] ?? '');
    }

    // ------------------------------------------------------------ reads

    /** One field group with its full field tree, or an Operation_Error. */
    public static function get_field_group(string $key): array
    {
        $group = acf_get_field_group($key);
        if (! is_array($group)) {
            throw new Operation_Error('not_found', esc_html(sprintf('No ACF field group has the key "%s".', $key)));
        }
        $group['fields'] = acf_get_fields($group) ?: [];
        return self::present($group);
    }

    /** ACF post types or taxonomies: the database ones plus any registered in code or JSON. */
    public static function list_internal(string $kind): array
    {
        [ $post_type, , $slug_setting ] = self::KINDS[ $kind ];
        $out = [];
        foreach ((array) acf_get_internal_post_type_posts($post_type) as $item) {
            $out[] = [
                'key'         => (string) ($item['key'] ?? ''),
                'title'       => (string) ($item['title'] ?? ''),
                $slug_setting => (string) ($item[ $slug_setting ] ?? ''),
                'active'      => ! empty($item['active']),
                'local'       => ! empty($item['local']),
            ];
        }
        return [ 'items' => $out ];
    }

    /** One ACF post type or taxonomy, with every setting, or an Operation_Error. */
    public static function get_internal(string $kind, string $key): array
    {
        $item = acf_get_internal_post_type($key, self::KINDS[ $kind ][0]);
        if (! is_array($item)) {
            throw new Operation_Error('not_found', esc_html(sprintf('No ACF %s has the key "%s".', str_replace('_', ' ', $kind), $key)));
        }
        return self::present($item);
    }

    /** Every field type this ACF install offers: name, label and category. */
    public static function field_types(): array
    {
        $types = [];
        foreach ((array) acf_get_field_types() as $name => $type) {
            $types[] = [
                'name'     => (string) $name,
                'label'    => (string) ($type->label ?? $name),
                'category' => (string) ($type->category ?? ''),
            ];
        }
        return [ 'field_types' => $types ];
    }

    /**
     * Validate values against their fields with acf_validate_value(), the
     * same check ACF runs on a form submit, without writing anything.
     *
     * @return array{valid: bool, errors: array<int, array{field: string, message: string}>}
     */
    public static function validate_values(array $fields, $post_id = false): array
    {
        $errors = [];
        foreach ($fields as $selector => $value) {
            $field = self::resolve_field((string) $selector, $post_id);
            if (! is_array($field)) {
                $errors[] = [ 'field' => (string) $selector, 'message' => 'No ACF field matches this name or key.' ];
                continue;
            }
            acf_reset_validation_errors();
            $valid = acf_validate_value($value, $field, 'acf[' . $field['key'] . ']');
            if (! $valid) {
                foreach ((array) acf_get_validation_errors() as $error) {
                    $errors[] = [ 'field' => (string) $selector, 'message' => wp_strip_all_tags((string) ($error['message'] ?? '')) ];
                }
            }
        }
        acf_reset_validation_errors();

        return [ 'valid' => [] === $errors, 'errors' => $errors ];
    }

    /**
     * The field a selector means for $post_id: a field key directly, else the
     * name through the value's stored reference, else the name among the
     * field groups whose location rules match that post (or options page,
     * via $screen), else ACF's loose name lookup, the same one update_field()
     * falls back to. Returns null when nothing matches.
     *
     * @param int|string|false $post_id
     */
    public static function resolve_field(string $selector, $post_id = false, array $screen = []): ?array
    {
        $field = acf_maybe_get_field($selector, $post_id);
        if (is_array($field)) {
            return $field;
        }
        if ([] === $screen && is_int($post_id)) {
            $screen = [ 'post_id' => $post_id ];
        }
        if ([] !== $screen) {
            foreach ((array) acf_get_field_groups($screen) as $group) {
                foreach ((array) acf_get_fields($group) as $candidate) {
                    if (($candidate['name'] ?? null) === $selector) {
                        return $candidate;
                    }
                }
            }
        }
        $field = acf_get_field($selector);
        return is_array($field) ? $field : null;
    }

    // ------------------------------------------------------------ writes

    /**
     * Pre-write checks for save-field-group: unknown field types, missing
     * names or labels, malformed keys and a code-registered group are all
     * refused before any snapshot or write.
     */
    public static function validate_field_group(array $args): ?array
    {
        $key = (string) ($args['key'] ?? '');
        if ('' !== $key && ! str_starts_with($key, 'group_')) {
            return self::refuse('invalid_key', 'A field group key must start with "group_".');
        }
        $refusal = self::refuse_local('field_group', $key);
        if ($refusal) {
            return $refusal;
        }
        if (null === self::db_id($key) && '' === trim((string) ($args['title'] ?? ''))) {
            return self::refuse('invalid_field_group', 'A new field group needs a title.');
        }
        return isset($args['fields']) ? self::validate_fields((array) $args['fields'], 'fields') : null;
    }

    /** Create or update a field group, and its fields when 'fields' is given. */
    public static function save_field_group(array $args, array $context): array
    {
        $key      = self::key_from($context);
        $id       = self::db_id($key);
        $settings = self::settings($args);

        if (null === $id) {
            $group = array_merge([ 'location' => [] ], $settings, [ 'key' => $key, 'fields' => [] ]);
        } else {
            $group = array_merge((array) acf_get_raw_field_group($id), $settings, [ 'ID' => $id, 'key' => $key ]);
        }

        if (array_key_exists('fields', $args) || null === $id) {
            $group['fields'] = self::with_keys((array) ($args['fields'] ?? []));
            acf_import_field_group($group);
        } else {
            unset($group['fields']);
            acf_update_field_group($group);
        }

        return self::get_field_group($key);
    }

    /** Pre-write checks for save-post-type / save-taxonomy. */
    public static function validate_internal(string $kind, array $args): ?array
    {
        [ $post_type, $prefix, $slug_setting, $max ] = self::KINDS[ $kind ];
        $label = str_replace('_', ' ', $kind);
        $key   = (string) ($args['key'] ?? '');
        if ('' !== $key && ! str_starts_with($key, $prefix)) {
            return self::refuse('invalid_key', sprintf('An ACF %s key must start with "%s".', $label, $prefix));
        }
        $refusal = self::refuse_local($kind, $key);
        if ($refusal) {
            return $refusal;
        }

        $existing = null === self::db_id($key) ? null : acf_get_internal_post_type($key, $post_type);
        $slug     = (string) ($args[ $slug_setting ] ?? ($existing[ $slug_setting ] ?? ''));
        if (null === $existing && '' === trim((string) ($args['title'] ?? ''))) {
            return self::refuse('invalid_' . $kind, sprintf('A new %s needs a title (its plural label).', $label));
        }
        if (1 !== preg_match('/^[a-z0-9_-]{1,' . $max . '}$/', $slug)) {
            return self::refuse('invalid_' . $kind, sprintf('The %s "%s" must be 1 to %d lowercase letters, digits, underscores or dashes.', $slug_setting, $slug, $max));
        }
        if (in_array($slug, (array) acf_get_wp_reserved_terms(), true)) {
            return self::refuse('invalid_' . $kind, sprintf('"%s" is a WordPress reserved term.', $slug));
        }

        $owned = false;
        foreach ((array) acf_get_internal_post_type_posts($post_type) as $item) {
            if (($item[ $slug_setting ] ?? '') !== $slug) {
                continue;
            }
            if (($item['key'] ?? '') !== $key) {
                return self::refuse($kind . '_exists', sprintf('Another ACF %s (%s) already uses "%s".', $label, (string) $item['key'], $slug));
            }
            $owned = true;
        }
        $registered = 'post_type' === $kind ? post_type_exists($slug) : taxonomy_exists($slug);
        if ($registered && ! $owned) {
            return self::refuse($kind . '_exists', sprintf('"%s" is already registered outside ACF.', $slug));
        }
        return null;
    }

    /** Create or update an ACF post type or taxonomy through ACF's import API. */
    public static function save_internal(string $kind, array $args, array $context): array
    {
        $key       = self::key_from($context);
        $post_type = self::KINDS[ $kind ][0];
        $id        = self::db_id($key);
        $base      = null === $id ? [] : (array) acf_get_internal_post_type($id, $post_type);
        $item      = array_merge($base, self::settings($args), [ 'key' => $key ]);
        if (null !== $id) {
            $item['ID'] = $id;
        }
        if (isset($args['labels']) && is_array($args['labels'])) {
            $item['labels'] = array_merge((array) ($base['labels'] ?? []), $args['labels']);
        }

        acf_import_internal_post_type($item, $post_type);

        return self::get_internal($kind, $key);
    }

    // ------------------------------------------------------------ helpers

    /** The database post ID holding $key, or null when none does. */
    private static function db_id(string $key): ?int
    {
        return '' === $key ? null : Snapshot::acf_structure_root_id($key);
    }

    /** Refuse a key ACF knows only from PHP or JSON: there is no database row to write or snapshot. */
    private static function refuse_local(string $kind, string $key): ?array
    {
        if ('' === $key || null !== self::db_id($key)) {
            return null;
        }
        $local = 'field_group' === $kind
            ? acf_is_local_field_group($key)
            : (function_exists('acf_is_local_internal_post_type') && acf_is_local_internal_post_type($key, self::KINDS[ $kind ][0]));
        return $local
            ? self::refuse('local_definition', sprintf('"%s" is defined in code or local JSON, not in the database, so it cannot be edited here.', $key))
            : null;
    }

    /** Caller settings with the ACF-derived keys removed. */
    private static function settings(array $args): array
    {
        return array_diff_key($args, array_flip(self::RESERVED));
    }

    /** Check a field list recursively: known type, name, label and key shape. */
    private static function validate_fields(array $fields, string $path): ?array
    {
        foreach (array_values($fields) as $i => $field) {
            $at = sprintf('%s[%d]', $path, $i);
            if (! is_array($field)) {
                return self::refuse('invalid_field', sprintf('%s must be an object.', $at));
            }
            $type = (string) ($field['type'] ?? '');
            if (! acf_get_field_type($type)) {
                return self::refuse('invalid_field_type', sprintf('%s has unknown field type "%s"; list-field-types shows the available ones.', $at, $type), [ 'field_type' => $type ]);
            }
            if ('' === trim((string) ($field['label'] ?? '')) || '' === trim((string) ($field['name'] ?? ''))) {
                return self::refuse('invalid_field', sprintf('%s needs a label and a name.', $at));
            }
            $key = (string) ($field['key'] ?? '');
            if ('' !== $key && ! str_starts_with($key, 'field_')) {
                return self::refuse('invalid_field', sprintf('%s key must start with "field_".', $at));
            }
            foreach ([ 'sub_fields' => null, 'layouts' => 'sub_fields' ] as $child => $nested) {
                foreach (null === $nested ? [ (array) ($field[ $child ] ?? []) ] : array_column((array) ($field[ $child ] ?? []), $nested) as $j => $sub) {
                    $refusal = self::validate_fields((array) $sub, $at . '.' . $child . (null === $nested ? '' : "[$j]"));
                    if ($refusal) {
                        return $refusal;
                    }
                }
            }
        }
        return null;
    }

    /** Give every field (and sub field, and layout) without a key a fresh one, as ACF's editor would. */
    private static function with_keys(array $fields): array
    {
        foreach ($fields as $i => $field) {
            if ('' === (string) ($field['key'] ?? '')) {
                $field['key'] = self::new_key('field_');
            }
            if (isset($field['sub_fields']) && is_array($field['sub_fields'])) {
                $field['sub_fields'] = self::with_keys($field['sub_fields']);
            }
            if (isset($field['layouts']) && is_array($field['layouts'])) {
                foreach ($field['layouts'] as $j => $layout) {
                    if ('' === (string) ($layout['key'] ?? '')) {
                        $layout['key'] = self::new_key('layout_');
                    }
                    if (isset($layout['sub_fields']) && is_array($layout['sub_fields'])) {
                        $layout['sub_fields'] = self::with_keys($layout['sub_fields']);
                    }
                    $field['layouts'][ $j ] = $layout;
                }
            }
            $fields[ $i ] = $field;
        }
        return array_values($fields);
    }

    /** Drop ACF's internal bookkeeping keys from an item returned to the caller. */
    private static function present(array $item): array
    {
        unset($item['_valid']);
        if (isset($item['fields']) && is_array($item['fields'])) {
            $item['fields'] = array_map(static function ($field) {
                return is_array($field) ? self::present($field) : $field;
            }, $item['fields']);
        }
        if (isset($item['sub_fields']) && is_array($item['sub_fields'])) {
            $item['sub_fields'] = array_map(static fn ($f) => is_array($f) ? self::present($f) : $f, $item['sub_fields']);
        }
        return $item;
    }

    private static function refuse(string $code, string $message, array $data = []): array
    {
        return [ 'code' => $code, 'message' => $message, 'data' => $data ];
    }
}
