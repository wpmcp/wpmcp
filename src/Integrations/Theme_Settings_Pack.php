<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Builds a framework settings pack (issue #288) from a declarative spec, for
 * the families whose settings are spread over nested theme storage rather
 * than one flat option the way Astra's are.
 *
 * A spec names the storage the theme itself reads (its theme_mods, a plain
 * array option, or a JSON-encoded option) and a flat vocabulary of setting
 * keys, each mapped to a path inside one store with a sanitizer rule. The
 * pack then exposes the same get/set pair shape as the Astra pack:
 *
 *  - get-{family}-settings reads every allowlisted key as stored (null when
 *    unset, so the theme's own default applies).
 *  - set-{family}-settings validates the WHOLE batch first (allowlist, value
 *    rule, and that the path can be written) and refuses before any write or
 *    snapshot; then snapshots exactly the stores the batch touches (one
 *    'option' snapshot, or one 'option_set' when it spans two) so
 *    rollback-operation restores them byte for byte.
 *
 * Spec shape:
 *   family   string  slug, as detected from the template
 *   label    string  display name used in messages
 *   tier     string  op tier ('free' unless the spec says otherwise)
 *   stores   array   id => ['type' => 'theme_mods'|'option'|'json_option', 'option' => name,
 *                   'seed' => the theme's default for the whole store, used when it is not stored]
 *   settings array   key => ['store' => id, 'path' => [...], 'rule' => rule, 'seed' => array]
 *   refresh  callable|null  the theme's own CSS cache refresh after a write
 *
 * A path element is a key (string or int), ['key_from' => field, 'default'
 * => key] (the key is read from a sibling field, e.g. the active palette), or
 * ['match' => [field => value], 'create' => bool|array, 'first' => bool] (an
 * item of a list found by its fields; when none matches, the 'create' array
 * (or the match itself for true) is appended, or the first item is used
 * when 'first'). 'seed' is the theme's documented default
 * for the path's top-level key, written first when that key is not stored,
 * so a sub-field write never leaves the theme reading a half-empty array.
 *
 * A rule is a built-in name ('color', 'width', 'int', 'px', 'font', 'html',
 * 'bool'), with 'min'/'max' for the numeric ones and 'var' (a regex) for the
 * palette references a color may be, or a list of literal allowed values.
 */
final class Theme_Settings_Pack
{
    /**
     * The get/set operation pair for one spec.
     *
     * @param array<string,mixed> $spec
     * @return array<string,array<string,mixed>>
     */
    public static function operations(array $spec): array
    {
        $family = (string) $spec['family'];
        $label  = (string) $spec['label'];
        $tier   = (string) ($spec['tier'] ?? 'free');

        return [
            "get-{$family}-settings" => [
                'mode'         => 'read',
                'tier'         => $tier,
                'capability'   => 'edit_theme_options',
                'description'  => sprintf('Read the writable %s settings (global colors, typography, container width, header/footer basics) as stored, with the allowlist', $label),
                'input_schema' => [ 'type' => 'object', 'properties' => [] ],
                'handler'      => static fn (): array => self::read($spec),
            ],
            "set-{$family}-settings" => [
                'mode'               => 'write',
                'tier'               => $tier,
                'capability'         => 'edit_theme_options',
                'enabled_by_default' => (bool) apply_filters('wpmcp_enable_theme_write', false),
                'description'        => sprintf('Set allowlisted %s settings. One invalid value refuses the whole batch before any write. Snapshotted for rollback-operation. Disabled by default (wpmcp_enable_theme_write filter)', $label),
                'input_schema'       => [
                    'type'       => 'object',
                    'properties' => [
                        'settings' => [
                            'type'                 => 'object',
                            'minProperties'        => 1,
                            'additionalProperties' => [ 'type' => [ 'string', 'integer', 'number', 'boolean' ] ],
                        ],
                    ],
                    'required'   => [ 'settings' ],
                ],
                'validate'           => static function (array $args) use ($spec): ?array {
                    $plan = self::plan($spec, (array) ($args['settings'] ?? []));
                    return $plan['error'] ?? null;
                },
                'handler'            => static fn (array $args): array => self::write($spec, (array) $args['settings']),
                'snapshot'           => static fn (array $args): ?array => self::snapshot_target($spec, (array) ($args['settings'] ?? [])),
            ],
        ];
    }

    /**
     * The effective setting map. wpmcp_theme_framework_pack_allowlist can
     * only NARROW a spec pack: a key it adds has no storage path, so it is
     * dropped rather than trusted.
     *
     * @return array<string,array<string,mixed>>
     */
    private static function settings(array $spec): array
    {
        $all      = (array) $spec['settings'];
        $filtered = (array) apply_filters('wpmcp_theme_framework_pack_allowlist', $all, (string) $spec['family']);
        return array_intersect_key($all, $filtered);
    }

    /** @return array<string,mixed> */
    private static function read(array $spec): array
    {
        $settings = self::settings($spec);
        $data     = [];
        $out      = [];
        foreach ($settings as $key => $def) {
            $store = (string) $def['store'];
            if (! isset($data[ $store ])) {
                $data[ $store ] = self::load($spec, $store);
            }
            $out[ (string) $key ] = self::path_get($data[ $store ], (array) $def['path']);
        }
        return [ 'framework' => (string) $spec['family'], 'settings' => $out, 'allowlist' => array_keys($settings) ];
    }

    /**
     * Dry-run the batch against the stored data: every key allowlisted,
     * every value valid, every path writable. Returns the new store data on
     * success, or a structured error and no data.
     *
     * @param array<string,mixed> $values
     * @return array{error?: array<string,mixed>, stores?: array<string,array>, written?: array<string,mixed>}
     */
    private static function plan(array $spec, array $values): array
    {
        $settings = self::settings($spec);
        $label    = (string) $spec['label'];
        $stores   = [];
        $written  = [];

        foreach ($values as $key => $value) {
            $key = (string) $key;
            if (! isset($settings[ $key ])) {
                return [ 'error' => [
                    'code'    => 'key_not_allowlisted',
                    'message' => sprintf('%s setting "%s" is not on the write allowlist.', $label, $key),
                    'data'    => [ 'key' => $key, 'allowlist' => array_keys($settings) ],
                ] ];
            }
            $def   = $settings[ $key ];
            $clean = self::sanitize($def['rule'], $value);
            if (null === $clean) {
                return [ 'error' => [
                    'code'    => 'invalid_setting_value',
                    'message' => sprintf('Value for %s setting "%s" is not valid for that key.', $label, $key),
                    'data'    => [ 'key' => $key ],
                ] ];
            }

            $store = (string) $def['store'];
            if (! isset($stores[ $store ])) {
                $stores[ $store ] = self::load($spec, $store);
                $seed             = $spec['stores'][ $store ]['seed'] ?? null;
                if ([] === $stores[ $store ] && is_array($seed)) {
                    $stores[ $store ] = $seed;
                }
            }
            $path = (array) $def['path'];
            $head = $path[0];
            if (is_string($head) && ! array_key_exists($head, $stores[ $store ]) && isset($def['seed'])) {
                $stores[ $store ][ $head ] = $def['seed'];
            }
            $next = self::path_set($stores[ $store ], $path, $clean);
            if (null === $next) {
                return [ 'error' => [
                    'code'    => 'setting_unavailable',
                    'message' => sprintf('%s setting "%s" has no stored structure to write into yet. Save it once in the Customizer first.', $label, $key),
                    'data'    => [ 'key' => $key ],
                ] ];
            }
            $stores[ $store ] = $next;
            $written[ $key ]  = $clean;
        }

        return [ 'stores' => $stores, 'written' => $written ];
    }

    /** @return array<string,mixed> */
    private static function write(array $spec, array $values): array
    {
        $plan = self::plan($spec, $values);
        if (isset($plan['error'])) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- surfaced as a JSON tool error by Integration_Dispatcher, never rendered as HTML.
            throw new Operation_Refused((string) $plan['error']['code'], (string) $plan['error']['message'], (array) ($plan['error']['data'] ?? []));
        }
        foreach ((array) $plan['stores'] as $store => $data) {
            $def  = (array) $spec['stores'][ $store ];
            $name = self::option_name($def);
            update_option($name, 'json_option' === $def['type'] ? wp_json_encode($data) : $data);
        }

        $family = (string) $spec['family'];
        if (isset($spec['refresh']) && is_callable($spec['refresh'])) {
            ($spec['refresh'])();
        }
        /** This action is documented in Theme_Framework_Pack::refresh_framework_cache(). */
        do_action('wpmcp_theme_framework_cache_refresh', $family);

        return [ 'framework' => $family, 'settings' => (array) $plan['written'] ];
    }

    /**
     * The options this batch writes: one 'option' target, or an 'option_set'
     * when it spans two stores, so the undo point covers exactly what the
     * write touches. A batch naming no known key writes nothing and gets no
     * snapshot (validate() has refused it by then anyway).
     */
    private static function snapshot_target(array $spec, array $values): ?array
    {
        $settings = self::settings($spec);
        $names    = [];
        foreach (array_keys($values) as $key) {
            $def = $settings[ (string) $key ] ?? null;
            if (null !== $def) {
                $names[] = self::option_name((array) $spec['stores'][ $def['store'] ]);
            }
        }
        $names = array_values(array_unique($names));
        if ([] === $names) {
            return null;
        }
        if (1 === count($names)) {
            return [ 'object_type' => 'option', 'object_id' => $names[0] ];
        }
        return [ 'object_type' => 'option_set', 'object_id' => \WPMCP\Safety\Snapshot::option_set_id($names) ];
    }

    /**
     * The option a store lives in. theme_mods are keyed off the UNFILTERED
     * stylesheet option, exactly as core's set_theme_mod() does, so the
     * snapshot and the write always name the same row.
     */
    private static function option_name(array $store): string
    {
        return 'theme_mods' === $store['type']
            ? 'theme_mods_' . get_option('stylesheet')
            : (string) $store['option'];
    }

    /** @return array<mixed> the decoded store, [] when absent or unreadable. */
    private static function load(array $spec, string $store): array
    {
        $def = (array) $spec['stores'][ $store ];
        $raw = get_option(self::option_name($def), []);
        if ('json_option' === $def['type']) {
            $raw = is_string($raw) ? json_decode($raw, true) : $raw;
        }
        return is_array($raw) ? $raw : [];
    }

    /**
     * @param array<mixed> $data
     * @return mixed
     */
    private static function path_get(array $data, array $path)
    {
        $node = $data;
        foreach ($path as $step) {
            if (! is_array($node)) {
                return null;
            }
            $key = self::resolve($node, $step);
            if (null === $key || ! array_key_exists($key, $node)) {
                return null;
            }
            $node = $node[ $key ];
        }
        return $node;
    }

    /**
     * $data with $value written at $path, or null when the path cannot be
     * written (a list item that must already exist does not).
     *
     * @param array<mixed> $data
     * @return array<mixed>|null
     */
    private static function path_set(array $data, array $path, $value): ?array
    {
        $step = array_shift($path);
        $key  = self::resolve($data, $step);
        if (null === $key && is_array($step) && ! empty($step['create'])) {
            $data[] = is_array($step['create']) ? $step['create'] : (array) $step['match'];
            $key    = array_key_last($data);
        }
        if (null === $key) {
            return null;
        }
        if ([] === $path) {
            $data[ $key ] = $value;
            return $data;
        }
        $child = $data[ $key ] ?? [];
        $child = self::path_set(is_array($child) ? $child : [], $path, $value);
        if (null === $child) {
            return null;
        }
        $data[ $key ] = $child;
        return $data;
    }

    /**
     * The concrete key one path element names inside $node, or null.
     *
     * @param array<mixed> $node
     * @param mixed        $step
     * @return int|string|null
     */
    private static function resolve(array $node, $step)
    {
        if (! is_array($step)) {
            return $step;
        }
        if (isset($step['key_from'])) {
            $key = $node[ $step['key_from'] ] ?? null;
            return is_string($key) && '' !== $key ? $key : $step['default'];
        }
        foreach ($node as $index => $item) {
            if (is_array($item) && self::matches($item, (array) $step['match'])) {
                return $index;
            }
        }
        return ! empty($step['first']) && isset($node[0]) ? 0 : null;
    }

    /** Whether every field in $match is present in $item with exactly that value. */
    private static function matches(array $item, array $match): bool
    {
        foreach ($match as $field => $want) {
            if (! array_key_exists($field, $item) || $item[ $field ] !== $want) {
                return false;
            }
        }
        return true;
    }

    /**
     * Sanitize a value for a rule, or null to refuse it.
     *
     * @param  mixed $rule
     * @param  mixed $value
     * @return mixed|null
     */
    private static function sanitize($rule, $value)
    {
        if (is_array($rule) && isset($rule[0])) {
            $candidate = is_int($value) ? (string) $value : $value;
            return in_array($candidate, $rule, true) ? $candidate : null;
        }
        $rule = (array) $rule;
        $name = (string) ($rule['rule'] ?? '');

        if ('bool' === $name) {
            return is_bool($value) ? $value : null;
        }
        if (is_array($value) || is_object($value) || is_bool($value)) {
            return null;
        }
        switch ($name) {
            case 'color':
                $raw = trim((string) $value);
                $hex = sanitize_hex_color($raw);
                if (is_string($hex) && '' !== $hex) {
                    return $hex;
                }
                if (isset($rule['var']) && 1 === preg_match((string) $rule['var'], $raw)) {
                    return $raw;
                }
                return 1 === preg_match('/^rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*(,\s*(0|1|0?\.\d+)\s*)?\)$/', $raw)
                    ? $raw
                    : null;
            case 'width':
            case 'int':
            case 'px':
                $raw = is_string($value) ? preg_replace('/px$/', '', trim($value)) : $value;
                if (! is_numeric($raw) || (float) $raw !== (float) (int) $raw) {
                    return null;
                }
                $n = (int) $raw;
                if ($n < (int) ($rule['min'] ?? 300) || $n > (int) ($rule['max'] ?? 3000)) {
                    return null;
                }
                return 'px' === $name ? $n . 'px' : $n;
            case 'font':
                $raw = trim((string) $value);
                return 1 === preg_match('/^[A-Za-z0-9 ,"\'\-]{1,200}$/', $raw) ? $raw : null;
            case 'html':
                $raw = (string) $value;
                return strlen($raw) <= 5000 ? wp_kses_post($raw) : null;
        }
        return null;
    }
}
