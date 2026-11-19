<?php

namespace WPMCP\Integrations;

use WPMCP\MCP\Registrar;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Checkout Field Editor for WooCommerce (ThemeHigh, wordpress.org slug
 * woo-checkout-field-editor-pro) classic checkout fields (issue #660),
 * paid-tier ops on the plugin-data pair, so they add no top-level tool:
 *
 *  - list-checkout-fields: the billing, shipping and additional sections
 *    (or one of them), each field with its label, placeholder, type,
 *    required, enabled, custom, priority, classes, validations and whether
 *    it shows in emails and on order pages, in checkout order. A section the
 *    plugin has never saved says stored:false; WooCommerce's own defaults
 *    apply there.
 *  - update-checkout-fields: change properties of existing fields of one
 *    section (label, placeholder, default, required, enabled, priority,
 *    class, validate, show_in_email, show_in_order), sanitized the way the
 *    plugin's settings screen sanitizes them. Field names and types are not
 *    writable and fields are never added or removed here: a disabled field
 *    is how the plugin hides one. Snapshotted on the section's option
 *    (wc_fields_<section>), so rollback-operation puts it back exactly. Off
 *    until the site opts in through wpmcp_enable_checkout_field_editor_write.
 *
 * The plugin stores each section as a name => field map in its own option,
 * written by THWCFD_Utils::update_fields(); this pack reads and writes the
 * same options (as of 2.2.1). The block checkout's separate field sections
 * are not covered.
 *
 * Both ops run at manage_woocommerce, the capability the plugin's own
 * settings screen needs by default. Without the plugin the ops answer
 * plugin_missing or plugin_inactive naming it. Presence is filterable
 * (wpmcp_checkout_field_editor_active).
 */
final class Checkout_Field_Editor_Pack
{
    public const ENABLE_FILTER = 'wpmcp_enable_checkout_field_editor_write';

    public const PLUGIN = 'Checkout Field Editor for WooCommerce';

    /** The plugin's option name prefix, one option per section. */
    private const OPTION_PREFIX = 'wc_fields_';

    private const SECTIONS = [ 'billing', 'shipping', 'additional' ];

    private const CAPABILITY = 'manage_woocommerce';

    /** The validations the plugin's field form offers (2.2.1). */
    private const VALIDATIONS = [ 'email', 'phone', 'postcode', 'state', 'number', 'url' ];

    private const FLAGS = [ 'required', 'enabled', 'show_in_email', 'show_in_order' ];

    private const CHECK_NOTE = 'Open the checkout page and check the fields look the way you expect; undo with rollback-operation if not.';

    public static function plugin_active(): bool
    {
        return (bool) apply_filters('wpmcp_checkout_field_editor_active', defined('THWCFD_VERSION') && class_exists('THWCFD'));
    }

    /** Whether the plugin is usable and this install may run the paid tier. */
    public static function active(): bool
    {
        return Registrar::tier_permitted('pro') && self::plugin_active();
    }

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        // The literal name, not ENABLE_FILTER, so the hook stays greppable.
        $enabled = (bool) apply_filters('wpmcp_enable_checkout_field_editor_write', false);
        $common  = [
            'tier'       => 'pro',
            'capability' => self::CAPABILITY,
            'requires'   => static fn () => self::plugin_active() ? true : self::refusal(),
        ];
        $flag    = [ 'type' => 'boolean' ];
        $text    = [ 'type' => 'string', 'maxLength' => 500 ];
        $field   = [
            'type'                 => 'object',
            'minProperties'        => 1,
            'additionalProperties' => false,
            'properties'           => [
                'label'         => $text,
                'placeholder'   => $text,
                'default'       => $text,
                'required'      => $flag,
                'enabled'       => $flag,
                'show_in_email' => $flag,
                'show_in_order' => $flag,
                'priority'      => [ 'type' => 'integer', 'minimum' => 0, 'maximum' => 100000 ],
                'class'         => [ 'type' => 'array', 'maxItems' => 20, 'items' => [ 'type' => 'string', 'maxLength' => 100 ] ],
                'validate'      => [ 'type' => 'array', 'maxItems' => 6, 'items' => [ 'type' => 'string' ] ],
            ],
        ];

        return [
            'list-checkout-fields'   => $common + [
                'mode'         => 'read',
                'description'  => 'Checkout Field Editor billing, shipping and additional checkout fields (label, type, required, enabled, priority, classes, validations)',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'section' => [ 'type' => 'string', 'enum' => self::SECTIONS ] ],
                ],
                'handler'      => static fn (array $args): array => self::list_fields($args, $enabled),
            ],
            'update-checkout-fields' => $common + [
                'mode'               => 'write',
                'enabled_by_default' => $enabled,
                'opt_in_filter'      => self::ENABLE_FILTER,
                'description'        => 'Change properties of existing checkout fields in one section (fields: {name: {label, placeholder, required, enabled, priority, class, validate...}}). Snapshotted; undo: rollback-operation. Disabled by default (wpmcp_enable_checkout_field_editor_write filter)',
                'input_schema'       => [
                    'type'       => 'object',
                    'properties' => [
                        'section' => [ 'type' => 'string', 'enum' => self::SECTIONS ],
                        'fields'  => [ 'type' => 'object', 'minProperties' => 1, 'maxProperties' => 50, 'additionalProperties' => $field ],
                    ],
                    'required'   => [ 'section', 'fields' ],
                ],
                'validate'           => static fn (array $args): ?array => self::update_refusal($args),
                'snapshot'           => static fn (array $args): array => [ 'object_type' => 'option', 'object_id' => self::OPTION_PREFIX . (string) $args['section'] ],
                'handler'            => static fn (array $args): array => self::update_fields($args),
            ],
        ];
    }

    /**
     * plugin_missing or plugin_inactive naming the plugin and the plugins screen.
     *
     * @return array{code: string, message: string, data: array<string, string>}
     */
    private static function refusal(): array
    {
        $refusal         = Plugin_Refusal::make('plugin_missing', self::PLUGIN);
        $refusal['code'] = 'inactive' === $refusal['data']['state'] ? 'plugin_inactive' : 'plugin_missing';
        return $refusal;
    }

    /**
     * The stored field map of one section, or null when the plugin has
     * never saved it.
     *
     * @return array<string, array<string, mixed>>|null
     */
    private static function stored(string $section): ?array
    {
        $fields = get_option(self::OPTION_PREFIX . $section, null);
        if (! is_array($fields)) {
            return null;
        }
        return array_filter($fields, 'is_array');
    }

    /**
     * The section's fields as the plugin builds them when nothing is saved
     * (WooCommerce's address fields or the order notes field), when the
     * plugin and WooCommerce are loaded; otherwise none.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function defaults(string $section): array
    {
        if (! class_exists('THWCFD_Utils') || ! method_exists('THWCFD_Utils', 'get_fields') || ! function_exists('WC')) {
            return [];
        }
        try {
            $fields = \THWCFD_Utils::get_fields($section);
        } catch (\Throwable $e) {
            return [];
        }
        return is_array($fields) ? array_filter($fields, 'is_array') : [];
    }

    /** @return array<string, mixed> */
    private static function list_fields(array $args, bool $enabled): array
    {
        $sections = isset($args['section']) ? [ (string) $args['section'] ] : self::SECTIONS;
        $out      = [];
        foreach ($sections as $section) {
            $stored = self::stored($section);
            $out[]  = [
                'section' => $section,
                'stored'  => null !== $stored,
                'fields'  => self::shape_all(null !== $stored ? $stored : self::defaults($section)),
            ];
        }

        return [
            'sections'       => $out,
            'writes_enabled' => $enabled,
            'note'           => 'A section with stored:false has never been saved in Checkout Field Editor, so WooCommerce\'s default fields apply there.',
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $fields
     * @return array<int, array<string, mixed>>
     */
    private static function shape_all(array $fields): array
    {
        $rows = [];
        foreach (self::sorted($fields) as $name => $field) {
            $rows[] = self::shape((string) $name, $field);
        }
        return $rows;
    }

    /** @return array<string, mixed> */
    private static function shape(string $name, array $field): array
    {
        $validate = $field['validate'] ?? [];
        return [
            'name'          => $name,
            'type'          => (string) ($field['type'] ?? 'text'),
            'label'         => (string) ($field['label'] ?? ''),
            'placeholder'   => (string) ($field['placeholder'] ?? ''),
            'default'       => (string) ($field['default'] ?? ''),
            'required'      => ! empty($field['required']),
            'enabled'       => ! isset($field['enabled']) || ! empty($field['enabled']),
            'custom'        => ! empty($field['custom']),
            'priority'      => isset($field['priority']) && '' !== $field['priority'] ? (int) $field['priority'] : null,
            'class'         => array_values(array_map('strval', (array) ($field['class'] ?? []))),
            'validate'      => is_array($validate) ? array_values(array_map('strval', $validate)) : ('' === $validate ? [] : [ (string) $validate ]),
            'options'       => is_array($field['options'] ?? null) ? $field['options'] : [],
            'show_in_email' => ! isset($field['show_in_email']) || ! empty($field['show_in_email']),
            'show_in_order' => ! isset($field['show_in_order']) || ! empty($field['show_in_order']),
        ];
    }

    /**
     * Fields in checkout order: by priority, unset priorities last, ties
     * kept in stored order (uasort is stable).
     *
     * @param array<string, array<string, mixed>> $fields
     * @return array<string, array<string, mixed>>
     */
    private static function sorted(array $fields): array
    {
        uasort($fields, static function (array $a, array $b): int {
            $pa = isset($a['priority']) && '' !== $a['priority'] ? (int) $a['priority'] : PHP_INT_MAX;
            $pb = isset($b['priority']) && '' !== $b['priority'] ? (int) $b['priority'] : PHP_INT_MAX;
            return $pa <=> $pb;
        });
        return $fields;
    }

    /** The section's current field map: stored, else the plugin's defaults. */
    private static function base(string $section): array
    {
        $stored = self::stored($section);
        return null !== $stored ? $stored : self::defaults($section);
    }

    /**
     * One field's properties sanitized the way the plugin's field form
     * saves them: checkboxes as 1 or 0, label through post kses, classes
     * and validations as keys.
     *
     * @param array<string, mixed> $props
     * @return array<string, mixed>
     */
    private static function sanitize(array $props): array
    {
        $clean = [];
        foreach ($props as $key => $value) {
            if (in_array($key, self::FLAGS, true)) {
                $clean[ $key ] = $value ? 1 : 0;
            } elseif ('label' === $key) {
                $clean[ $key ] = wp_kses_post((string) $value);
            } elseif ('priority' === $key) {
                $clean[ $key ] = absint($value);
            } elseif ('class' === $key || 'validate' === $key) {
                $clean[ $key ] = array_values(array_filter(array_map('sanitize_key', array_map('strval', (array) $value)), 'strlen'));
            } else {
                $clean[ $key ] = sanitize_text_field((string) $value);
            }
        }
        return $clean;
    }

    /** @return array{code: string, message: string, data?: array}|null */
    private static function update_refusal(array $args): ?array
    {
        $section = (string) $args['section'];
        $fields  = self::base($section);
        if ([] === $fields) {
            return [
                'code'    => 'section_not_stored',
                'message' => sprintf('The %s section has no fields to change yet. Save it once on the Checkout Field Editor screen (WooCommerce > Checkout Form), then try again.', $section),
            ];
        }
        $named   = array_map('strval', array_keys((array) $args['fields']));
        $unknown = array_values(array_diff($named, array_map('strval', array_keys($fields))));
        if ([] !== $unknown) {
            return [
                'code'    => 'unknown_field',
                'message' => sprintf('Not a field of the %s section: %s.', $section, implode(', ', $unknown)),
                'data'    => [ 'unknown' => $unknown, 'fields' => array_keys($fields) ],
            ];
        }
        $changed = false;
        foreach ((array) $args['fields'] as $name => $props) {
            $bad = array_values(array_diff(array_map('strval', (array) ($props['validate'] ?? [])), self::VALIDATIONS));
            if ([] !== $bad) {
                return [
                    'code'    => 'invalid_property',
                    'message' => sprintf('Field %s: not a Checkout Field Editor validation: %s.', $name, implode(', ', $bad)),
                    'data'    => [ 'field' => $name, 'unknown' => $bad, 'validations' => self::VALIDATIONS ],
                ];
            }
            $current = $fields[ $name ];
            foreach (self::sanitize((array) $props) as $key => $value) {
                $changed = $changed || ! array_key_exists($key, $current) || self::normal($key, $current[ $key ]) !== $value;
            }
        }
        if (! $changed) {
            return [ 'code' => 'no_change', 'message' => 'Those fields already have those values; nothing to change.' ];
        }
        return null;
    }

    /** A stored value in the form sanitize() produces, for the no-change check. */
    private static function normal(string $key, $value)
    {
        if (in_array($key, self::FLAGS, true)) {
            return $value ? 1 : 0;
        }
        if ('priority' === $key) {
            return absint($value);
        }
        if ('class' === $key || 'validate' === $key) {
            return is_array($value) ? array_values(array_map('strval', $value)) : ('' === $value ? [] : [ (string) $value ]);
        }
        return (string) $value;
    }

    /** @return array<string, mixed> */
    private static function update_fields(array $args): array
    {
        $section = (string) $args['section'];
        $fields  = self::base($section);
        $changed = [];
        $resort  = false;
        foreach ((array) $args['fields'] as $name => $props) {
            $clean = self::sanitize((array) $props);
            foreach ($clean as $key => $value) {
                if (! array_key_exists($key, $fields[ $name ]) || self::normal($key, $fields[ $name ][ $key ]) !== $value) {
                    $changed[ (string) $name ] = true;
                }
            }
            $resort           = $resort || array_key_exists('priority', $clean);
            $fields[ $name ] = array_merge($fields[ $name ], $clean);
        }
        if ($resort) {
            $fields = self::sorted($fields);
        }
        update_option(self::OPTION_PREFIX . $section, $fields, 'no');

        return [
            'section' => $section,
            'changed' => array_keys($changed),
            'fields'  => self::shape_all($fields),
            'check'   => self::CHECK_NOTE,
        ];
    }
}
