<?php

namespace WPMCP\Integrations;

use WPMCP\Safety\Snapshot_Store;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Redux Framework options panels (issue #897) as free ops on the theme
 * dispatcher pair, so they add no top-level tool.
 *
 * Many themes keep their whole design configuration in one Redux panel: an
 * array stored under the panel's opt_name (Redux::set_args). Where it is
 * stored follows the panel's 'database' argument: a site option (the
 * default), the theme mod "<opt_name>-mods", every theme mod
 * ('theme_mods_expanded'), a network option or a transient.
 *
 * Reads: list-redux-panels (every registered opt_name with its storage and
 * size) and get-redux-options (fields with type, section, default and the
 * stored value, an unsaved field reading as its default). Display-only
 * fields and fields whose 'permissions' the user lacks are left out. A
 * password field and a field named like a licence key, API key, token or
 * secret is never read out: its value is HIDDEN.
 *
 * Write, off until the site opts in (wpmcp_enable_redux_write):
 * set-redux-option changes ONE existing field. It refuses unknown field
 * ids rather than creating them, refuses secret fields and field types it
 * cannot validate (typography, media, repeaters, options filled from
 * WordPress data and the like), and validates the value for the field's
 * type first: colors, choices among the field's own options, switches,
 * numbers inside the slider's range, and text. The value then goes through
 * Redux's own save path, exactly as the panel's save does:
 * validate_options() (the field's own validate and sanitize rules and
 * the panel's hooks), set() into the panel's storage, and, for a field
 * flagged 'compiler', the panel's compiler hooks that rebuild a theme's
 * generated CSS. When Redux's validation rejects the value, the old array
 * is put back and the undo point is voided.
 *
 * Every write is one 'option' snapshot of the row Redux stores the panel in
 * (theme_mods_<stylesheet> for the theme mod storages), so
 * rollback-operation puts the whole old array back; the undo then runs the
 * compiler hooks again (after_options_restored). Network and transient
 * storage are read only here.
 *
 * The pack never names a Redux class directly in a way that fails without
 * Redux: panels come from Redux_Instances::get_all_instances(), filterable
 * through wpmcp_redux_instances, and presence through wpmcp_redux_active.
 */
final class Redux_Pack
{
    public const ENABLE_FILTER = 'wpmcp_enable_redux_write';

    /** What a hidden secret reads as. */
    public const HIDDEN = '[redacted]';

    /** Field types that only render text or layout and hold no value. */
    private const DISPLAY_ONLY = [ 'info', 'section', 'divide', 'raw', 'callback', 'import_export' ];

    /** Field types whose value must be one of the field's own options. */
    private const CHOICE = [ 'select', 'radio', 'button_set', 'image_select', 'palette', 'select_image' ];

    /** Field types the write accepts. */
    private const WRITABLE_TYPES = [ 'text', 'textarea', 'editor', 'color', 'switch', 'checkbox', 'select', 'radio', 'button_set', 'image_select', 'palette', 'select_image', 'slider', 'spinner' ];

    /** Words in a field id or title that mark its value as a secret. */
    private const SECRET_WORDS = '/(secret|token|passw|pwd|api_?key|apikey|licen[cs]e|private_?key|credential|access_?key|purchase_?code|client_?secret)/';

    /** At most this many options of a choice field are listed. */
    private const MAX_OPTIONS = 100;

    /** Longest text value accepted. */
    private const MAX_TEXT = 65535;

    /** @return array<string, array<string, mixed>> op name => definition for the theme pair. */
    public static function operations(): array
    {
        $requires = static fn () => self::requirement();
        $panel    = [ 'type' => 'string', 'minLength' => 1, 'description' => 'The panel\'s opt_name; needed when the site has more than one panel' ];

        return [
            'list-redux-panels' => [
                'mode'         => 'read',
                'capability'   => 'manage_options',
                'description'  => 'List the Redux Framework options panels on this site: opt_name, title, where the values are stored (option, theme_mod, theme_mods, network, transient), section and field counts, and whether set-redux-option can write it',
                'input_schema' => [ 'type' => 'object', 'properties' => [] ],
                'requires'     => $requires,
                'handler'      => static fn (): array => self::list_panels(),
            ],
            'get-redux-options' => [
                'mode'         => 'read',
                'capability'   => 'manage_options',
                'description'  => 'Read a Redux panel\'s fields: id, type, title, section, default, the stored value (an unsaved field reads as its default, is_default:true), the options of a choice field, and whether set-redux-option can write it. Licence keys, API keys, tokens and password fields are never read out. Narrow with section or fields (field ids)',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'opt_name' => $panel,
                        'section'  => [ 'type' => 'string', 'minLength' => 1 ],
                        'fields'   => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'maxItems' => 200 ],
                    ],
                ],
                'requires'     => $requires,
                'validate'     => static fn (array $args): ?array => self::read_refusal($args),
                'handler'      => static fn (array $args): array => self::get_options($args),
            ],
            'set-redux-option'  => [
                'mode'               => 'write',
                'capability'         => 'manage_options',
                'description'        => 'Set ONE existing field of a Redux panel through Redux\'s own save (its validation, storage and compiler hooks, so generated CSS is rebuilt). The value is checked for the field\'s type first: color (hex or transparent), switch (true/false), select, radio, button set and image select (one of the field\'s options; a multi select takes a list), slider and spinner (a number in range), text, textarea and editor (a string). Unknown field ids, secret fields and other field types are refused. Snapshotted; undo: rollback-operation. Disabled by default (wpmcp_enable_redux_write filter)',
                'enabled_by_default' => (bool) apply_filters('wpmcp_enable_redux_write', false),
                'opt_in_filter'      => self::ENABLE_FILTER,
                'input_schema'       => [
                    'type'       => 'object',
                    'properties' => [
                        'opt_name' => $panel,
                        'field'    => [ 'type' => 'string', 'minLength' => 1 ],
                        'value'    => [ 'type' => [ 'string', 'number', 'integer', 'boolean', 'array' ] ],
                    ],
                    'required'   => [ 'field', 'value' ],
                ],
                'requires'           => $requires,
                'validate'           => static fn (array $args): ?array => self::write_refusal($args),
                'snapshot'           => static fn (array $args): ?array => self::target($args),
                'handler'            => static fn (array $args, array $context = []): array => self::set_option($args, $context),
            ],
        ];
    }

    // ---------------------------------------------------------------
    // Presence and panels
    // ---------------------------------------------------------------

    /** Whether Redux Framework is loaded, as a plugin or bundled in a theme. Filterable through wpmcp_redux_active. */
    public static function active(): bool
    {
        return (bool) apply_filters('wpmcp_redux_active', class_exists('Redux_Instances') || class_exists('ReduxFrameworkInstances'));
    }

    /** @return true|array{code: string, message: string, data: array} */
    private static function requirement()
    {
        return self::active() ? true : Plugin_Refusal::make('redux_inactive', 'Redux Framework');
    }

    /**
     * The registered panels, keyed by opt_name.
     *
     * @return array<string, object>
     */
    private static function panels(): array
    {
        $all = [];
        if (class_exists('Redux_Instances') && is_callable([ 'Redux_Instances', 'get_all_instances' ])) {
            $all = (array) \Redux_Instances::get_all_instances();
        }
        /** Filter: the Redux panels (ReduxFramework instances) keyed by opt_name. */
        $all = (array) apply_filters('wpmcp_redux_instances', $all);

        $out = [];
        foreach ($all as $panel) {
            $opt = is_object($panel) && isset($panel->args) && is_array($panel->args) ? (string) ($panel->args['opt_name'] ?? '') : '';
            if ('' !== $opt) {
                $out[ $opt ] = $panel;
            }
        }
        return $out;
    }

    /**
     * The panel the call names, or a refusal.
     *
     * @return array{0: ?object, 1: ?array}
     */
    private static function resolve(array $args): array
    {
        $panels = self::panels();
        $named  = (string) ($args['opt_name'] ?? '');
        if ('' !== $named) {
            if (! isset($panels[ $named ])) {
                return [ null, self::refusal('redux_panel_not_found', sprintf('No Redux panel with opt_name "%s" is registered on this site.', $named), [ 'panels' => array_keys($panels) ]) ];
            }
            $panel = $panels[ $named ];
        } elseif (1 === count($panels)) {
            $panel = reset($panels);
        } elseif ([] === $panels) {
            return [ null, self::refusal('redux_panel_not_found', 'Redux is loaded but no options panel is registered on this site.') ];
        } else {
            return [ null, self::refusal('opt_name_required', 'This site has more than one Redux panel; name one with opt_name.', [ 'panels' => array_keys($panels) ]) ];
        }

        $cap = (string) ($panel->args['page_permissions'] ?? 'manage_options');
        if ('' !== $cap && ! current_user_can($cap)) {
            return [ null, self::refusal('operation_denied', sprintf('The Redux panel "%s" requires the "%s" capability.', (string) $panel->args['opt_name'], $cap), [ 'reason' => 'capability' ]) ];
        }
        return [ $panel, null ];
    }

    /** Where the panel stores its values: option, theme_mod, theme_mods, network or transient. */
    private static function storage(object $panel): string
    {
        $map = [ 'theme_mods' => 'theme_mod', 'theme_mods_expanded' => 'theme_mods', 'network' => 'network', 'transient' => 'transient' ];
        return $map[ (string) ($panel->args['database'] ?? '') ] ?? 'option';
    }

    /** The options row the panel's values live in, for the storages this pack writes; null otherwise. */
    private static function storage_option(object $panel): ?string
    {
        $storage = self::storage($panel);
        if ('option' === $storage) {
            return (string) $panel->args['opt_name'];
        }
        if ('theme_mod' === $storage || 'theme_mods' === $storage) {
            return 'theme_mods_' . get_option('stylesheet');
        }
        return null;
    }

    /**
     * The stored values, read the way Redux's own get() reads them.
     *
     * @return array<string, mixed>
     */
    private static function stored(object $panel): array
    {
        $opt = (string) $panel->args['opt_name'];
        switch (self::storage($panel)) {
            case 'theme_mod':
                $value = get_theme_mod($opt . '-mods');
                break;
            case 'theme_mods':
                $value = get_theme_mods();
                break;
            case 'network':
                $value = get_site_option($opt, []);
                break;
            case 'transient':
                $value = get_transient($opt . '-transient');
                break;
            default:
                $value = get_option($opt, []);
        }
        return is_array($value) ? $value : [];
    }

    /**
     * The panel's defaults, as Redux computes them.
     *
     * @return array<string, mixed>
     */
    private static function defaults(object $panel): array
    {
        if (isset($panel->options_defaults) && is_array($panel->options_defaults)) {
            return $panel->options_defaults;
        }
        if (isset($panel->options_class) && is_object($panel->options_class) && method_exists($panel->options_class, 'default_values')) {
            try {
                return (array) $panel->options_class->default_values();
            } catch (\Throwable $e) {
                return [];
            }
        }
        return [];
    }

    /**
     * Every value field of the panel the current user may see, keyed by id,
     * each with its section id under '_section'. Fields inside a tabbed
     * field are top level values in Redux, so they are listed too.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function fields(object $panel): array
    {
        $out = [];
        foreach ((array) ($panel->sections ?? []) as $key => $section) {
            if (! is_array($section) || 'divide' === ($section['type'] ?? '')) {
                continue;
            }
            if (isset($section['permissions']) && ! self::user_has((string) $section['permissions'])) {
                continue;
            }
            $section_id = (string) ($section['id'] ?? $key);
            foreach ((array) ($section['fields'] ?? []) as $field) {
                if (! is_array($field)) {
                    continue;
                }
                $nested = [];
                if ('tabbed' === ($field['type'] ?? '')) {
                    foreach ((array) ($field['tabs'] ?? []) as $tab) {
                        $nested = array_merge($nested, (array) ($tab['fields'] ?? []));
                    }
                } else {
                    $nested = [ $field ];
                }
                foreach ($nested as $one) {
                    $id = is_array($one) ? (string) ($one['id'] ?? '') : '';
                    if ('' === $id || in_array((string) ($one['type'] ?? ''), self::DISPLAY_ONLY, true)) {
                        continue;
                    }
                    if (isset($one['permissions']) && ! self::user_has((string) $one['permissions'])) {
                        continue;
                    }
                    $out[ $id ] = $one + [ '_section' => $section_id ];
                }
            }
        }
        return $out;
    }

    /** Whether the user holds a Redux 'permissions' value (a capability, or several separated by commas). */
    private static function user_has(string $permissions): bool
    {
        if ('' === $permissions) {
            return true;
        }
        foreach (array_filter(array_map('trim', explode(',', $permissions)), 'strlen') as $cap) {
            if (current_user_can($cap)) {
                return true;
            }
        }
        return false;
    }

    /** Whether a field's value is a secret: a password field, or one named like a key, token or licence. */
    private static function is_secret(array $field): bool
    {
        if ('password' === ($field['type'] ?? '')) {
            return true;
        }
        foreach ([ (string) ($field['id'] ?? ''), is_string($field['title'] ?? null) ? $field['title'] : '' ] as $name) {
            $spaced = (string) preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $name);
            $norm   = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '_', $spaced));
            if (1 === preg_match(self::SECRET_WORDS, $norm)) {
                return true;
            }
        }
        return false;
    }

    /** Why a field cannot be written, or null when it can. */
    private static function not_writable_reason(array $field): ?string
    {
        $type = (string) ($field['type'] ?? '');
        if (self::is_secret($field)) {
            return 'it holds a secret (a password, key, token or licence)';
        }
        if (! in_array($type, self::WRITABLE_TYPES, true)) {
            return sprintf('its type "%s" is not one this op can validate', $type);
        }
        if (isset($field['data']) && '' !== $field['data']) {
            return 'its options are filled from WordPress data';
        }
        if (in_array($type, self::CHOICE, true) && ([] === self::choices($field))) {
            return 'it has no fixed options to check the value against';
        }
        if ('checkbox' === $type && ! empty($field['options'])) {
            return 'a checkbox list is not supported';
        }
        if ('slider' === $type && (int) ($field['handles'] ?? 1) > 1) {
            return 'a two handle slider is not supported';
        }
        return null;
    }

    /** @return array<string, string> a choice field's option keys and labels. */
    private static function choices(array $field): array
    {
        $out = [];
        foreach ((array) ($field['options'] ?? []) as $key => $label) {
            if (is_array($label)) {
                $label = (string) ($label['title'] ?? $label['alt'] ?? $key);
            }
            $out[ (string) $key ] = is_scalar($label) ? wp_strip_all_tags((string) $label) : (string) $key;
        }
        return $out;
    }

    // ---------------------------------------------------------------
    // Reads
    // ---------------------------------------------------------------

    private static function list_panels(): array
    {
        $panels = [];
        foreach (self::panels() as $opt => $panel) {
            $cap = (string) ($panel->args['page_permissions'] ?? 'manage_options');
            if ('' !== $cap && ! current_user_can($cap)) {
                continue;
            }
            $sections = array_filter((array) ($panel->sections ?? []), static fn ($s): bool => is_array($s) && 'divide' !== ($s['type'] ?? ''));
            $panels[] = [
                'opt_name'      => (string) $opt,
                'title'         => wp_strip_all_tags((string) ($panel->args['display_name'] ?? '')),
                'page_slug'     => (string) ($panel->args['page_slug'] ?? ''),
                'storage'       => self::storage($panel),
                'section_count' => count($sections),
                'field_count'   => count(self::fields($panel)),
                'writable'      => null !== self::storage_option($panel),
            ];
        }
        return [ 'panels' => $panels, 'count' => count($panels) ];
    }

    private static function read_refusal(array $args): ?array
    {
        [ $panel, $refusal ] = self::resolve($args);
        if (null !== $refusal) {
            return $refusal;
        }
        $fields  = self::fields($panel);
        $section = (string) ($args['section'] ?? '');
        if ('' !== $section && ! in_array($section, array_column($fields, '_section'), true)) {
            $known = array_values(array_unique(array_column($fields, '_section')));
            return self::refusal('unknown_section', sprintf('The Redux panel has no section "%s" with fields you can read.', $section), [ 'sections' => $known ]);
        }
        $unknown = array_values(array_diff(array_map('strval', (array) ($args['fields'] ?? [])), array_keys($fields)));
        if ([] !== $unknown) {
            return self::refusal('unknown_field', 'Not a field of this Redux panel: ' . implode(', ', $unknown) . '.', [ 'unknown' => $unknown ]);
        }
        return null;
    }

    private static function get_options(array $args): array
    {
        [ $panel ] = self::resolve($args);
        $stored    = self::stored($panel);
        $defaults  = self::defaults($panel);
        $section   = (string) ($args['section'] ?? '');
        $only      = array_map('strval', (array) ($args['fields'] ?? []));
        $detailed  = '' !== $section || [] !== $only;

        $rows = [];
        foreach (self::fields($panel) as $id => $field) {
            if (('' !== $section && $field['_section'] !== $section) || ([] !== $only && ! in_array((string) $id, $only, true))) {
                continue;
            }
            $secret = self::is_secret($field);
            $saved  = array_key_exists($id, $stored);
            $row    = [
                'id'         => (string) $id,
                'type'       => (string) ($field['type'] ?? ''),
                'title'      => is_string($field['title'] ?? null) ? wp_strip_all_tags($field['title']) : '',
                'section'    => (string) $field['_section'],
                'value'      => $secret ? ($saved && '' !== $stored[ $id ] ? self::HIDDEN : '') : ($saved ? $stored[ $id ] : ($defaults[ $id ] ?? null)),
                'default'    => $secret ? null : ($defaults[ $id ] ?? ($field['default'] ?? null)),
                'is_default' => ! $saved,
                'writable'   => null === self::not_writable_reason($field),
            ];
            if ($secret) {
                $row['secret'] = true;
            }
            if (in_array($row['type'], self::CHOICE, true) && ($detailed || count((array) ($field['options'] ?? [])) <= 20)) {
                $row['options'] = array_slice(self::choices($field), 0, self::MAX_OPTIONS, true);
            }
            if (! empty($field['multi'])) {
                $row['multi'] = true;
            }
            if (in_array($row['type'], [ 'slider', 'spinner' ], true)) {
                $row['min'] = $field['min'] ?? null;
                $row['max'] = $field['max'] ?? null;
            }
            $rows[] = $row;
        }

        return [
            'opt_name' => (string) $panel->args['opt_name'],
            'storage'  => self::storage($panel),
            'fields'   => $rows,
            'count'    => count($rows),
        ];
    }

    // ---------------------------------------------------------------
    // Write
    // ---------------------------------------------------------------

    /**
     * The panel, field and clean value of a write, or a refusal.
     *
     * @return array{0: ?object, 1: ?array, 2: mixed, 3: ?array}
     */
    private static function prepare(array $args): array
    {
        [ $panel, $refusal ] = self::resolve($args);
        if (null !== $refusal) {
            return [ null, null, null, $refusal ];
        }
        if (null === self::storage_option($panel)) {
            return [ null, null, null, self::refusal('redux_storage_not_writable', sprintf('The Redux panel "%s" stores its values in "%s" storage, which this op does not write (no exact undo). Change it in the panel itself.', (string) $panel->args['opt_name'], self::storage($panel))) ];
        }
        $id     = (string) ($args['field'] ?? '');
        $fields = self::fields($panel);
        if (! isset($fields[ $id ])) {
            return [ null, null, null, self::refusal('unknown_field', sprintf('"%s" is not a field of the Redux panel "%s"; fields are never created.', $id, (string) $panel->args['opt_name']), [ 'fields' => array_keys($fields) ]) ];
        }
        $field  = $fields[ $id ];
        $reason = self::not_writable_reason($field);
        if (null !== $reason) {
            return [ null, null, null, self::refusal('field_not_writable', sprintf('The Redux field "%s" cannot be written here: %s.', $id, $reason), [ 'field' => $id, 'type' => (string) ($field['type'] ?? '') ]) ];
        }
        $clean = self::clean($field, $args['value'] ?? null);
        if (null === $clean) {
            return [ null, null, null, self::refusal('invalid_value', sprintf('The value is not valid for the Redux %s field "%s".', (string) $field['type'], $id), self::value_hint($field)) ];
        }
        $stored = self::stored($panel);
        if (array_key_exists($id, $stored) && self::same($stored[ $id ], $clean)) {
            return [ null, null, null, self::refusal('no_change', sprintf('The Redux field "%s" already has that value. Nothing was written and no undo point was taken.', $id)) ];
        }
        return [ $panel, $field, $clean, null ];
    }

    private static function write_refusal(array $args): ?array
    {
        return self::prepare($args)[3];
    }

    private static function target(array $args): ?array
    {
        [ $panel ] = self::prepare($args);
        $option    = null === $panel ? null : self::storage_option($panel);
        return null === $option ? null : [ 'object_type' => 'option', 'object_id' => $option ];
    }

    /**
     * The value as the panel's own form posts it, or null when it is not
     * valid for the field's type.
     *
     * @param mixed $value
     * @return mixed
     */
    private static function clean(array $field, $value)
    {
        $type = (string) ($field['type'] ?? '');
        if (in_array($type, [ 'text', 'textarea', 'editor' ], true)) {
            if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
                return null;
            }
            $value = (string) $value;
            return strlen($value) <= self::MAX_TEXT ? $value : null;
        }
        if ('color' === $type) {
            if (! is_string($value)) {
                return null;
            }
            $value = trim($value);
            return ('' === $value || 'transparent' === $value || 1 === preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $value)) ? $value : null;
        }
        if ('switch' === $type || 'checkbox' === $type) {
            if (true === $value || 1 === $value || '1' === $value || 'true' === $value) {
                return '1';
            }
            if (false === $value || 0 === $value || '0' === $value || 'false' === $value) {
                return '0';
            }
            return null;
        }
        if (in_array($type, self::CHOICE, true)) {
            $keys = array_map('strval', array_keys(self::choices($field)));
            if (! empty($field['multi'])) {
                if (! is_array($value) || array_values($value) !== $value) {
                    return null;
                }
                $list = [];
                foreach ($value as $item) {
                    if (! is_scalar($item) || ! in_array((string) $item, $keys, true)) {
                        return null;
                    }
                    $list[] = (string) $item;
                }
                return $list;
            }
            return is_scalar($value) && ! is_bool($value) && in_array((string) $value, $keys, true) ? (string) $value : null;
        }
        if ('slider' === $type || 'spinner' === $type) {
            if (is_bool($value) || ! is_numeric($value)) {
                return null;
            }
            $number = 0 + $value;
            if ((isset($field['min']) && is_numeric($field['min']) && $number < 0 + $field['min']) || (isset($field['max']) && is_numeric($field['max']) && $number > 0 + $field['max'])) {
                return null;
            }
            return (string) $number;
        }
        return null;
    }

    /** @return array<string, mixed> what a valid value looks like for a field. */
    private static function value_hint(array $field): array
    {
        $type = (string) ($field['type'] ?? '');
        $hint = [ 'field' => (string) ($field['id'] ?? ''), 'type' => $type ];
        if (in_array($type, self::CHOICE, true)) {
            $hint['options'] = array_slice(array_keys(self::choices($field)), 0, self::MAX_OPTIONS);
        }
        if ('slider' === $type || 'spinner' === $type) {
            $hint['min'] = $field['min'] ?? null;
            $hint['max'] = $field['max'] ?? null;
        }
        return $hint;
    }

    /**
     * @param mixed $a
     * @param mixed $b
     */
    private static function same($a, $b): bool
    {
        if (is_scalar($a) && is_scalar($b)) {
            return (string) $a === (string) $b;
        }
        return $a === $b;
    }

    private static function set_option(array $args, array $context): array
    {
        [ $panel, $field, $clean, $refusal ] = self::prepare($args);
        if (null !== $refusal) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- surfaced as a JSON tool error by Integration_Dispatcher, never rendered as HTML.
            throw new Operation_Refused((string) $refusal['code'], (string) $refusal['message'], (array) $refusal['data']);
        }
        $id       = (string) $field['id'];
        $prior    = self::stored($panel);
        $previous = array_key_exists($id, $prior) ? $prior[ $id ] : null;

        // Redux compares the posted array with the loaded one; load the
        // stored values first, as its own get() does. The panel's form posts
        // every field, so a field never saved goes in with its default.
        $panel->options = $prior;
        $values         = $prior + self::defaults($panel);
        $values[ $id ]  = $clean;
        if (! empty($field['compiler'])) {
            // The panel's form posts this key when a compiler field changed.
            $values['compiler'] = '1';
        }
        if (property_exists($panel, 'errors')) {
            $panel->errors = [];
        }

        self::save($panel, $values);

        $after  = self::stored($panel);
        $errors = array_values(array_filter(
            isset($panel->errors) && is_array($panel->errors) ? $panel->errors : [],
            static fn ($error): bool => is_array($error) && $id === (string) ($error['id'] ?? '')
        ));
        $kept   = array_key_exists($id, $after) && ! self::same($after[ $id ], $previous);
        if ([] !== $errors || ! $kept) {
            self::save($panel, $prior, false);
            if ([] === $prior) {
                self::forget($panel);
            }
            if (! empty($context['operation_id'])) {
                Snapshot_Store::delete_operation((string) $context['operation_id']);
            }
            $messages = array_values(array_filter(array_map(static fn (array $error): string => wp_strip_all_tags((string) ($error['msg'] ?? '')), $errors), 'strlen'));
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- surfaced as a JSON tool error by Integration_Dispatcher, never rendered as HTML.
            throw new Operation_Refused('redux_rejected_value', sprintf('Redux\'s own validation for the field "%s" did not keep the value, so the panel was put back and nothing changed.%s', $id, [] === $messages ? '' : ' ' . implode(' ', $messages)), [ 'field' => $id, 'errors' => $messages ]);
        }

        $compiled = self::run_compiler($panel);

        return [
            'opt_name'     => (string) $panel->args['opt_name'],
            'field'        => $id,
            'previous'     => self::is_secret($field) ? self::HIDDEN : $previous,
            'value'        => self::is_secret($field) ? self::HIDDEN : $after[ $id ],
            'compiler_ran' => $compiled,
            'undo'         => 'rollback-operation puts the whole panel back as it was before this write.',
        ];
    }

    /**
     * Store a panel's values through Redux: validate_options() then set(),
     * as the panel's own save does. With $validate false (putting the old
     * values back) only set() runs.
     *
     * @param array<string, mixed> $values
     */
    private static function save(object $panel, array $values, bool $validate = true): void
    {
        $class = $panel->options_class ?? null;
        if (! is_object($class) || ! method_exists($class, 'set')) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- surfaced as a JSON tool error by Integration_Dispatcher, never rendered as HTML.
            throw new Operation_Refused('redux_unsupported', 'This Redux version has no options class to save through; change the value in the panel itself.');
        }
        if ($validate && method_exists($class, 'validate_options')) {
            if (class_exists('Redux_Core') && property_exists('Redux_Core', 'validation_ran')) {
                \Redux_Core::$validation_ran = false;
            }
            $values = $class->validate_options($values);
            if (! is_array($values)) {
                // The theme mod storages save inside validate_options().
                return;
            }
        }
        $class->set($values);
    }

    /**
     * Remove a panel's storage that did not exist before a refused write,
     * so a refusal leaves no row behind. Only the plain option storage can
     * be missing as a whole; a theme mod storage keeps its other mods.
     */
    private static function forget(object $panel): void
    {
        if ('option' === self::storage($panel)) {
            delete_option((string) $panel->args['opt_name']);
        } elseif ('theme_mod' === self::storage($panel)) {
            remove_theme_mod((string) $panel->args['opt_name'] . '-mods');
        }
    }

    /**
     * Run the panel's compiler hooks when the save asked for them, the way
     * Redux's own panel save does, so a theme that writes its CSS on the
     * compiler hook rebuilds it.
     */
    private static function run_compiler(object $panel): bool
    {
        if (! isset($panel->transients) || ! is_array($panel->transients) || empty($panel->transients['run_compiler'])) {
            return false;
        }
        $opt = (string) $panel->args['opt_name'];
        try {
            if (class_exists('Redux_Core') && property_exists('Redux_Core', 'no_output')) {
                \Redux_Core::$no_output = true;
            }
            if (isset($panel->output_class) && is_object($panel->output_class) && method_exists($panel->output_class, 'enqueue')) {
                $panel->output_class->enqueue();
            }
            $css = isset($panel->compilerCSS) ? (string) $panel->compilerCSS : '';
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.ValidHookName.UseUnderscores -- Redux's own compiler hook, fired as its panel save fires it.
            do_action('redux/options/' . $opt . '/compiler', (array) $panel->options, $css, (array) ($panel->transients['changed_values'] ?? []), (array) ($panel->output_variables ?? []));
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.ValidHookName.UseUnderscores -- Redux's own compiler hook, fired as its panel save fires it.
            do_action('redux/options/' . $opt . '/compiler/advanced', $panel);
        } finally {
            unset($panel->transients['run_compiler']);
            if (isset($panel->transient_class) && is_object($panel->transient_class) && method_exists($panel->transient_class, 'set')) {
                $panel->transient_class->set();
            }
        }
        return true;
    }

    /**
     * After a rollback put options back (wpmcp_rollback_options_restored),
     * reload each panel stored in one of them and run its compiler hooks when
     * it has compiler fields, as the forward write did.
     *
     * @param string[] $names
     */
    public static function after_options_restored(array $names): void
    {
        if (! self::active()) {
            return;
        }
        foreach (self::panels() as $panel) {
            $option = self::storage_option($panel);
            if (null === $option || ! in_array($option, $names, true)) {
                continue;
            }
            $panel->options = self::stored($panel);
            $compiler       = array_filter(self::fields($panel), static fn (array $f): bool => ! empty($f['compiler']));
            if ([] === $compiler) {
                continue;
            }
            if (! isset($panel->transients) || ! is_array($panel->transients)) {
                $panel->transients = [];
            }
            $panel->transients['run_compiler'] = 1;
            try {
                self::run_compiler($panel);
            } catch (\Throwable $e) {
                // The restore itself succeeded; a theme compiler error must not undo it.
                unset($e);
            }
        }
    }

    /** @return array{code: string, message: string, data: array} */
    private static function refusal(string $code, string $message, array $data = []): array
    {
        return [ 'code' => $code, 'message' => $message, 'data' => $data ];
    }
}
