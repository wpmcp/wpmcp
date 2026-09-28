<?php

namespace WPMCP\Tools\Elementor;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Curated Elementor widget catalog (issue #59): pure data served behind a
 * small, fixed set of generic tools, instead of dozens of per-widget tools.
 *
 * Each definition lives in Catalog/{type}.php and returns a plain array:
 * widget type, title, category, one-line purpose, keywords, the plugin the
 * widget needs ('elementor' for free core, 'elementor-pro' for the page
 * builder's own Pro tier), and a hand-distilled params map. A param spec is:
 *
 *  - type: string|html|number|integer|bool|choice|link|media|icons|slider|
 *          gallery|repeater
 *  - control: the REAL Elementor control name (defaults to the param name)
 *  - required, default, description, enum (choice), fields (repeater),
 *    responsive (accepts {name}_tablet / {name}_mobile variants),
 *    unit (slider default unit), on/off (switcher stored values)
 *
 * The catalog cannot drift from reality: tests/free/Elementor/
 * WidgetCatalogDriftTest.php validates every satisfiable entry against the
 * live install - the type must resolve to a real registered widget and every
 * curated control name (plus responsive variants and repeater fields) must
 * exist on the widget's own control stack.
 *
 * This class is the single validate/build core shared by add-widget,
 * update-widget, and generate-widget: validate() type-checks curated params
 * against the spec, build_settings() maps them onto Elementor's real control
 * value shapes ('yes'/'' switchers, {url,is_external,nofollow} links,
 * {id,url} media, {size,unit} sliders, repeater items with generated _id).
 */
class Widget_Catalog
{
    /** @var array<string, array>|null */
    private static ?array $catalog = null;

    /** @return array<string, array> every definition, keyed by widget type. */
    public static function all(): array
    {
        if (null !== self::$catalog) {
            return self::$catalog;
        }

        $catalog = [];
        foreach (glob(__DIR__ . '/Catalog/*.php') ?: [] as $file) {
            $entry = require $file;
            if (is_array($entry) && isset($entry['type'])) {
                $catalog[ (string) $entry['type'] ] = $entry;
            }
        }

        return self::$catalog = $catalog;
    }

    public static function has(string $type): bool
    {
        return isset(self::all()[ $type ]);
    }

    public static function get(string $type): ?array
    {
        return self::all()[ $type ] ?? null;
    }

    /** @return array<string, array{type:string,title:string,category:string,purpose:string,requires:string}> */
    public static function summaries(): array
    {
        $out = [];
        foreach (self::all() as $type => $entry) {
            $out[ $type ] = [
                'type'     => $entry['type'],
                'title'    => $entry['title'],
                'category' => $entry['category'],
                'purpose'  => $entry['purpose'],
                'requires' => $entry['requires'],
            ];
        }
        return $out;
    }

    /**
     * The curated schema served by get-widget-schema: the definition with
     * every param spec normalized (control name, required and responsive
     * flags always present).
     */
    public static function curated_schema(string $type): ?array
    {
        $entry = self::get($type);
        if (null === $entry) {
            return null;
        }

        $params = [];
        foreach ($entry['params'] as $name => $spec) {
            $params[ $name ] = self::normalize_spec((string) $name, $spec);
        }

        return [
            'type'     => $entry['type'],
            'title'    => $entry['title'],
            'category' => $entry['category'],
            'purpose'  => $entry['purpose'],
            'keywords' => $entry['keywords'],
            'requires' => $entry['requires'],
            'params'   => $params,
        ];
    }

    private static function normalize_spec(string $name, array $spec): array
    {
        $out = [
            'type'        => $spec['type'],
            'control'     => (string) ($spec['control'] ?? $name),
            'required'    => (bool) ($spec['required'] ?? false),
            'responsive'  => (bool) ($spec['responsive'] ?? false),
            'description' => (string) ($spec['description'] ?? ''),
        ];
        if (array_key_exists('default', $spec)) {
            $out['default'] = $spec['default'];
        }
        if (isset($spec['enum'])) {
            $out['enum'] = $spec['enum'];
        }
        if (isset($spec['fields'])) {
            $fields = [];
            foreach ($spec['fields'] as $field_name => $field_spec) {
                $fields[ $field_name ] = self::normalize_spec((string) $field_name, $field_spec);
            }
            $out['fields'] = $fields;
        }
        return $out;
    }

    /**
     * The REAL installed Elementor widget for a type, or null. Free
     * Elementor registers promotion placeholders under Pro widget names
     * (Elementor\Modules\Promotions\...); those are ads, not widgets, and
     * must never be inserted into a page, so they resolve to null here.
     */
    public static function installed_widget(string $type): ?\Elementor\Widget_Base
    {
        if (! class_exists('\\Elementor\\Plugin')) {
            return null;
        }

        $widget = \Elementor\Plugin::instance()->widgets_manager->get_widget_types($type);

        if (null === $widget || 0 === strpos(get_class($widget), 'Elementor\\Modules\\Promotions\\')) {
            return null;
        }

        return $widget;
    }

    /**
     * Validate curated params for a cataloged type.
     *
     * @param bool $enforce_required false when patching (update-widget):
     *                               a patch touches only what it names.
     * @return \WP_Error|null null when valid.
     */
    public static function validate(string $type, array $params, bool $enforce_required = true): ?\WP_Error
    {
        $entry = self::get($type);
        if (null === $entry) {
            return new \WP_Error('not_cataloged', "Widget type '{$type}' is not in the curated catalog.");
        }

        $specs = $entry['params'];

        foreach ($params as $name => $value) {
            $resolved = self::resolve_param($specs, (string) $name);
            if (null === $resolved) {
                return new \WP_Error(
                    'unknown_param',
                    sprintf(
                        "Unknown param '%s' for widget type '%s'. Curated params: %s.",
                        $name,
                        $type,
                        implode(', ', array_keys($specs))
                    )
                );
            }

            $error = self::check_value($type, (string) $name, $resolved, $value);
            if (null !== $error) {
                return $error;
            }
        }

        if ($enforce_required) {
            foreach ($specs as $name => $spec) {
                if (! empty($spec['required']) && ! array_key_exists($name, $params)) {
                    return new \WP_Error(
                        'missing_required_param',
                        "Widget type '{$type}' requires param '{$name}'."
                    );
                }
            }
        }

        return null;
    }

    /**
     * Resolve a caller-supplied param name to its spec, accepting
     * {name}_tablet / {name}_mobile variants of responsive params.
     */
    private static function resolve_param(array $specs, string $name): ?array
    {
        if (isset($specs[ $name ])) {
            return $specs[ $name ];
        }

        foreach (['_tablet', '_mobile'] as $suffix) {
            if (str_ends_with($name, $suffix)) {
                $base = substr($name, 0, -strlen($suffix));
                if (isset($specs[ $base ]) && ! empty($specs[ $base ]['responsive'])) {
                    return $specs[ $base ];
                }
            }
        }

        return null;
    }

    /** @param mixed $value */
    private static function check_value(string $type, string $name, array $spec, $value): ?\WP_Error
    {
        $fail = static fn (string $expected) => new \WP_Error(
            'invalid_param',
            "Param '{$name}' of widget type '{$type}' must be {$expected}."
        );

        switch ($spec['type']) {
            case 'string':
            case 'html':
                return is_string($value) ? null : $fail('a string');

            case 'number':
                return is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))
                    ? null : $fail('a number');

            case 'integer':
                return is_int($value) || (is_string($value) && ctype_digit($value))
                    ? null : $fail('an integer');

            case 'bool':
                return is_bool($value) ? null : $fail('a boolean');

            case 'choice':
                return in_array($value, $spec['enum'] ?? [], true)
                    ? null : $fail('one of: ' . implode(', ', $spec['enum'] ?? []));

            case 'link':
                return is_array($value) && is_string($value['url'] ?? null)
                    ? null : $fail('an object with a string url');

            case 'media':
                return is_array($value) && is_string($value['url'] ?? null) && '' !== $value['url']
                    ? null : $fail('an object with a non-empty url (and optional attachment id)');

            case 'icons':
                return is_array($value) && is_string($value['value'] ?? null)
                    ? null : $fail('an object with a string value (icon class) and optional library');

            case 'slider':
                if (is_int($value) || is_float($value)) {
                    return null;
                }
                return is_array($value) && is_numeric($value['size'] ?? null)
                    ? null : $fail('a number or an object with a numeric size (and optional unit)');

            case 'gallery':
                if (! is_array($value) || [] === $value) {
                    return is_array($value) ? null : $fail('an array of media objects');
                }
                foreach ($value as $item) {
                    if (! is_array($item) || ! is_string($item['url'] ?? null) || '' === $item['url']) {
                        return $fail('an array of media objects, each with a non-empty url');
                    }
                }
                return null;

            case 'repeater':
                if (! is_array($value)) {
                    return $fail('an array of items');
                }
                foreach ($value as $item) {
                    if (! is_array($item)) {
                        return $fail('an array of item objects');
                    }
                    foreach ($item as $field_name => $field_value) {
                        $field_spec = $spec['fields'][ $field_name ] ?? null;
                        if (null === $field_spec) {
                            return new \WP_Error(
                                'unknown_param',
                                sprintf(
                                    "Unknown repeater field '%s' in param '%s' of widget type '%s'. Fields: %s.",
                                    $field_name,
                                    $name,
                                    $type,
                                    implode(', ', array_keys($spec['fields'] ?? []))
                                )
                            );
                        }
                        $error = self::check_value($type, "{$name}.{$field_name}", $field_spec, $field_value);
                        if (null !== $error) {
                            return $error;
                        }
                    }
                    foreach ($spec['fields'] ?? [] as $field_name => $field_spec) {
                        if (! empty($field_spec['required']) && ! array_key_exists($field_name, $item)) {
                            return new \WP_Error(
                                'missing_required_param',
                                "Each '{$name}' item of widget type '{$type}' requires field '{$field_name}'."
                            );
                        }
                    }
                }
                return null;
        }

        return $fail('a supported value');
    }

    /**
     * Map validated curated params onto Elementor's real settings array.
     * Assumes validate() has already passed.
     */
    public static function build_settings(string $type, array $params): array
    {
        $entry = self::get($type);
        if (null === $entry) {
            return [];
        }

        $settings = [];
        foreach ($params as $name => $value) {
            $suffix = '';
            $base   = (string) $name;
            foreach (['_tablet', '_mobile'] as $candidate) {
                if (str_ends_with($base, $candidate) && ! isset($entry['params'][ $base ])) {
                    $suffix = $candidate;
                    $base   = substr($base, 0, -strlen($candidate));
                    break;
                }
            }

            $spec = $entry['params'][ $base ] ?? null;
            if (null === $spec) {
                continue;
            }

            $control = (string) ($spec['control'] ?? $base) . $suffix;

            $settings[ $control ] = self::build_value($spec, $value);
        }

        return $settings;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function build_value(array $spec, $value)
    {
        switch ($spec['type']) {
            case 'bool':
                return $value ? (string) ($spec['on'] ?? 'yes') : (string) ($spec['off'] ?? '');

            case 'link':
                return [
                    'url'         => (string) $value['url'],
                    'is_external' => ! empty($value['is_external']) ? 'on' : '',
                    'nofollow'    => ! empty($value['nofollow']) ? 'on' : '',
                ];

            case 'media':
                return [
                    'id'  => (int) ($value['id'] ?? 0),
                    'url' => (string) $value['url'],
                ];

            case 'icons':
                return [
                    'value'   => (string) $value['value'],
                    'library' => (string) ($value['library'] ?? ''),
                ];

            case 'slider':
                $size = is_array($value) ? $value['size'] : $value;
                $unit = is_array($value) && isset($value['unit'])
                    ? (string) $value['unit']
                    : (string) ($spec['unit'] ?? 'px');
                return ['unit' => $unit, 'size' => (float) $size, 'sizes' => []];

            case 'gallery':
                $items = [];
                foreach ($value as $item) {
                    $items[] = [
                        'id'  => (int) ($item['id'] ?? 0),
                        'url' => (string) $item['url'],
                    ];
                }
                return $items;

            case 'repeater':
                $items = [];
                foreach ($value as $item) {
                    $built = ['_id' => Element_Id::generate()];
                    foreach ($item as $field_name => $field_value) {
                        $field_spec = $spec['fields'][ $field_name ] ?? null;
                        if (null === $field_spec) {
                            continue;
                        }
                        $field_control           = (string) ($field_spec['control'] ?? $field_name);
                        $built[ $field_control ] = self::build_value($field_spec, $field_value);
                    }
                    $items[] = $built;
                }
                return $items;

            case 'number':
                return is_string($value) ? (float) $value + 0 : $value;

            case 'integer':
                return (int) $value;

            default:
                return $value;
        }
    }
}
