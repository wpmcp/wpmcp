<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Cache and performance plugin settings (issue #692) as paid-tier ops on the
 * theme dispatcher pair, next to the cache status and purge ops, so they add
 * no top-level tools. One adapter per plugin (Cache_Settings_WP_Rocket,
 * Cache_Settings_Perfmatters, Cache_Settings_FlyingPress) names its option,
 * its readable and writable settings and its credentials; this pack builds
 * the same ops for each:
 *
 *  - get-<plugin>-settings: a summary (lazy load, minify CSS and JS, delay
 *    JS, CDN, preload), every allowlisted setting, the credentials by name
 *    with only whether each is set (never a value), the writable keys and
 *    whether writes are on. Built from allowlisted keys only; any stored
 *    credential value is scrubbed from the whole answer and redact() is the
 *    second line.
 *  - set-<plugin>-settings: change allowlisted settings, values typed by
 *    the op's schema (a list replaces the stored list). Refuses a call that
 *    changes nothing and one the plugin treats as conflicting. Snapshotted
 *    on the plugin's option, so rollback-operation puts every setting back
 *    exactly; the write and the rollback both go through the plugin's own
 *    save path so it rebuilds what it derives. Settings can change how the
 *    site looks, so the reply asks for a front-end check.
 *  - preload-<plugin>-cache (WP Rocket, FlyingPress): start the plugin's
 *    own cache preload. Not snapshotted: warming the cache destroys
 *    nothing, and the reply says recoverable:false.
 *
 * Writes are off until the site opts in through the
 * wpmcp_enable_cache_settings_write filter (or per op through
 * wpmcp_integration_op_enabled). Every op runs at manage_options, the
 * capability each plugin's settings screen needs. Without the plugin the
 * ops stay documented (dependency_met:false) and answer plugin_missing or
 * plugin_inactive with the plugins screen. Presence is filterable per
 * plugin (wpmcp_wp_rocket_active, wpmcp_perfmatters_active,
 * wpmcp_flyingpress_active).
 */
final class Cache_Settings_Pack
{
    public const ENABLE_FILTER = 'wpmcp_enable_cache_settings_write';


    private const CHECK_NOTE = 'Cache and optimization settings can change how pages look and behave. Load a few pages on the front end while logged out (layout, menus, sliders, forms, scripts) and undo with rollback-operation if anything broke.';

    /** Shortest credential value scrubbed from the answer; shorter ones would mask unrelated words. */
    private const SCRUB_MIN = 6;

    /** @return Cache_Settings_Adapter[] */
    public static function adapters(): array
    {
        return [ new Cache_Settings_WP_Rocket(), new Cache_Settings_Perfmatters(), new Cache_Settings_FlyingPress() ];
    }

    /** @return array<string, array<string, mixed>> op name => definition for the theme pair. */
    public static function operations(): array
    {
        // The literal name, not ENABLE_FILTER, so the hook stays greppable.
        $enabled = (bool) apply_filters('wpmcp_enable_cache_settings_write', false);
        $ops     = [];

        foreach (self::adapters() as $adapter) {
            $slug  = $adapter->slug();
            $label = $adapter->label();
            $base  = [
                'tier'       => 'pro',
                'capability' => 'manage_options',
                'requires'   => static fn () => self::presence($adapter),
            ];
            $write = $base + [
                'enabled_by_default' => $enabled,
                'opt_in_filter'      => self::ENABLE_FILTER,
            ];

            $ops[ "get-{$slug}-settings" ] = $base + [
                'mode'         => 'read',
                'description'  => sprintf('%s settings: a summary (lazy load, minify, delay JS, CDN, preload), every allowlisted setting with credentials masked, the keys set-%s-settings accepts, and whether writes are on', $label, $slug),
                'input_schema' => [ 'type' => 'object', 'properties' => [] ],
                'handler'      => static fn (): array => self::read($adapter, $enabled),
            ];

            $ops[ "set-{$slug}-settings" ] = $write + [
                'mode'         => 'write',
                'description'  => sprintf('Change %s settings: values maps setting keys (get-%s-settings lists them) to new values; a list replaces the stored list. Check the front end afterwards. Snapshotted on the %s option; undo: rollback-operation. Disabled by default (wpmcp_enable_cache_settings_write filter)', $label, $slug, $adapter->option()),
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'values' => self::values_schema($adapter) ],
                    'required'   => [ 'values' ],
                ],
                'validate'     => static fn (array $args): ?array => self::refusal($adapter, (array) $args['values']),
                'snapshot'     => static fn (): array => [ 'object_type' => 'option', 'object_id' => $adapter->option() ],
                'handler'      => static fn (array $args): array => self::write($adapter, (array) $args['values']),
            ];

            if ($adapter->supports_preload()) {
                $ops[ "preload-{$slug}-cache" ] = $write + [
                    'mode'         => 'write',
                    'description'  => sprintf('Start %s\'s own cache preload, which warms the page cache in the background. Not snapshotted: warming the cache destroys nothing. Disabled by default (wpmcp_enable_cache_settings_write filter)', $label),
                    'input_schema' => [ 'type' => 'object', 'properties' => [] ],
                    'validate'     => static fn (): ?array => $adapter->preload_refusal(),
                    'handler'      => static fn (): array => [ 'plugin' => $adapter->slug() ] + $adapter->preload(),
                ];
            }
        }

        return $ops;
    }

    /**
     * true when the plugin is loaded, otherwise plugin_inactive (its files
     * are installed) or plugin_missing, naming the plugins screen.
     *
     * @return true|array{code: string, message: string, data: array}
     */
    public static function presence(Cache_Settings_Adapter $adapter)
    {
        if ($adapter->active()) {
            return true;
        }
        $installed = $adapter->installed();
        $url       = admin_url('plugins.php');

        return [
            'code'    => $installed ? 'plugin_inactive' : 'plugin_missing',
            'message' => sprintf(
                '%1$s is %2$s on this site. Install and activate %1$s (%3$s), then try again.',
                $adapter->label(),
                $installed ? 'installed but not active' : 'not installed',
                $url
            ),
            'data'    => [ 'plugin' => dirname($adapter->plugin_file()), 'plugins_url' => $url ],
        ];
    }

    /**
     * After a rollback put options back (wpmcp_rollback_options_restored):
     * let each plugin whose option came back rebuild what it derives from it.
     *
     * @param string[] $names
     */
    public static function after_options_restored(array $names): void
    {
        $names = array_map('strval', $names);
        foreach (self::adapters() as $adapter) {
            if (in_array($adapter->option(), $names, true) && $adapter->active()) {
                $adapter->after_restore();
            }
        }
    }

    /** @return array<string, mixed> */
    private static function read(Cache_Settings_Adapter $adapter, bool $writes_enabled): array
    {
        $current = $adapter->current();
        $stored  = $adapter->stored();

        $credentials = [];
        foreach ($adapter->secret_keys() as $key) {
            $value               = Cache_Settings_Adapter::path_get($stored, $key);
            // A list of names, not a map keyed by them: redact() masks the
            // value under any secret-shaped key, which would hide whether
            // the credential is set at all.
            $credentials[] = [ 'name' => $key, 'set' => ! (null === $value || '' === $value || false === $value) ];
        }

        $out = [
            'plugin'            => $adapter->slug(),
            'name'              => $adapter->label(),
            'version'           => $adapter->version(),
            'option'            => $adapter->option(),
            'summary'           => self::summary($adapter, $current),
            'settings'          => $current,
            'masked'            => $credentials,
            'writable'          => array_keys($adapter->rules()),
            'writes_enabled'    => $writes_enabled,
            'preload_supported' => $adapter->supports_preload(),
        ];

        return Ops_Status_Packs::redact(self::scrub($adapter, $out));
    }

    /**
     * The canonical changes a set call asks for, after the plugin's implied
     * changes, limited to keys whose value really changes.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private static function changes(Cache_Settings_Adapter $adapter, array $values): array
    {
        $rules   = $adapter->rules();
        $current = $adapter->current();
        $wanted  = [];
        foreach ($values as $key => $value) {
            $wanted[ (string) $key ] = self::sanitize($rules[ (string) $key ], $value);
        }
        $wanted = $adapter->implied($wanted);

        $changes = [];
        foreach ($wanted as $key => $value) {
            if ($current[ $key ] !== $value) {
                $changes[ $key ] = $value;
            }
        }
        return $changes;
    }

    /** @return array{code: string, message: string, data?: array}|null */
    private static function refusal(Cache_Settings_Adapter $adapter, array $values): ?array
    {
        $rules = $adapter->rules();
        foreach ($values as $key => $value) {
            $key = (string) $key;
            if (! isset($rules[ $key ])) {
                return [
                    'code'    => 'cache_setting_not_writable',
                    'message' => sprintf('%s is not a setting set-%s-settings changes.', $key, $adapter->slug()),
                    'data'    => [ 'writable' => array_keys($rules) ],
                ];
            }
            if ('url' === $rules[ $key ]['type'] && '' !== trim((string) $value) && '' === self::sanitize($rules[ $key ], $value)) {
                return [
                    'code'    => 'invalid_args',
                    'message' => sprintf('%s must be an http or https URL, or empty.', $key),
                ];
            }
        }

        $changes = self::changes($adapter, $values);
        if ([] === $changes) {
            return [
                'code'    => 'no_change',
                'message' => sprintf('Every value is already set in %s. Nothing was written and no undo point was taken.', $adapter->label()),
            ];
        }
        return $adapter->conflict(array_merge($adapter->current(), $changes));
    }

    /** @return array<string, mixed> */
    private static function write(Cache_Settings_Adapter $adapter, array $values): array
    {
        $before  = $adapter->current();
        $changes = self::changes($adapter, $values);
        $adapter->apply($changes);
        $after = $adapter->current();

        $changed = [];
        foreach (array_keys($changes) as $key) {
            $changed[ $key ] = [ 'before' => $before[ $key ], 'after' => $after[ $key ] ];
        }

        return Ops_Status_Packs::redact(self::scrub($adapter, [
            'plugin'  => $adapter->slug(),
            'changed' => $changed,
            'summary' => self::summary($adapter, $after),
            'check'   => self::CHECK_NOTE,
        ]));
    }

    /**
     * @param array<string, mixed> $current
     * @return array<string, bool|null>
     */
    private static function summary(Cache_Settings_Adapter $adapter, array $current): array
    {
        $out = [];
        foreach ($adapter->summary_keys() as $name => $key) {
            $out[ $name ] = null === $key ? null : (bool) $current[ $key ];
        }
        return $out;
    }

    /**
     * A schema-valid value in canonical form: lists trimmed, de-duplicated
     * and stripped of tags, text sanitized, a URL kept only when it is http
     * or https.
     *
     * @param array<string, mixed> $rule
     * @param mixed                $value
     * @return mixed
     */
    private static function sanitize(array $rule, $value)
    {
        switch ($rule['type']) {
            case 'bool':
                return (bool) $value;
            case 'int':
                return (int) $value;
            case 'list':
                $items = [];
                foreach ((array) $value as $item) {
                    $item = sanitize_text_field((string) $item);
                    if ('' !== $item && ! in_array($item, $items, true)) {
                        $items[] = $item;
                    }
                }
                // A zone list pairs with the CNAMEs one to one, so keep repeats.
                return isset($rule['values']) ? array_values(array_map('strval', (array) $value)) : $items;
            case 'url':
                $url = esc_url_raw(trim((string) $value), [ 'http', 'https' ]);
                return '' === trim((string) $value) ? '' : untrailingslashit($url);
            default:
                return sanitize_text_field((string) $value);
        }
    }

    /** @return array<string, mixed> the JSON schema of a set op's values. */
    private static function values_schema(Cache_Settings_Adapter $adapter): array
    {
        $properties = [];
        foreach ($adapter->rules() as $key => $rule) {
            $properties[ $key ] = self::rule_schema($rule);
        }
        return [
            'type'                 => 'object',
            'properties'           => $properties,
            'additionalProperties' => false,
            'minProperties'        => 1,
        ];
    }

    /** @return array<string, mixed> */
    private static function rule_schema(array $rule): array
    {
        $string = [ 'type' => 'string', 'maxLength' => Cache_Settings_Adapter::MAX_LENGTH ];
        switch ($rule['type']) {
            case 'bool':
                return [ 'type' => 'boolean' ];
            case 'int':
                return [ 'type' => 'integer', 'minimum' => $rule['min'], 'maximum' => $rule['max'] ];
            case 'enum':
                return [ 'type' => 'string', 'enum' => $rule['values'] ];
            case 'list':
                return [
                    'type'     => 'array',
                    'items'    => isset($rule['values']) ? [ 'type' => 'string', 'enum' => $rule['values'] ] : $string,
                    'maxItems' => Cache_Settings_Adapter::MAX_ITEMS,
                ];
            default:
                return $string;
        }
    }

    /**
     * Scrub by value: every credential the plugin stores is replaced wherever
     * it appears in $data, so a secret cannot leak through another field.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function scrub(Cache_Settings_Adapter $adapter, array $data): array
    {
        $stored  = $adapter->stored();
        $secrets = [];
        foreach ($adapter->secret_keys() as $key) {
            $value = Cache_Settings_Adapter::path_get($stored, $key);
            if (is_string($value) && strlen($value) >= self::SCRUB_MIN) {
                $secrets[] = $value;
            }
        }
        if ([] === $secrets) {
            return $data;
        }
        usort($secrets, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        array_walk_recursive($data, static function (&$item) use ($secrets): void {
            if (is_string($item)) {
                $item = str_replace($secrets, Ops_Status_Packs::REDACTED, $item);
            }
        });
        return $data;
    }
}
