<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * W3 Total Cache status and purge (issue #300).
 *
 * get-w3tc-status reports which caches are enabled and with which engine.
 * W3 Total Cache keeps its settings in a config file rather than options, so
 * the values come through its public w3tc_config() API, one allowlisted key
 * at a time (the *.enabled and *.engine keys). Server lists, passwords and
 * CDN keys are never read. The values pass through the wpmcp_w3tc_config
 * filter, keyed by W3 Total Cache's own config key names.
 *
 * purge-w3tc-cache empties every cache through w3tc_flush_all(), the
 * plugin's own purge-all. Like clear-cache it is a cache operation, not a
 * content write, so it is not snapshotted (there is no before-image worth
 * restoring and the response says recoverable:false); it is destructive,
 * so it needs confirm:true, and it runs at manage_options. The purge
 * callable is filterable through wpmcp_w3tc_purge_callback.
 */
final class W3TC_Status
{
    /** Report name => [enabled key, engine key or null]. */
    private const CACHES = [
        'page'          => [ 'pgcache.enabled', 'pgcache.engine' ],
        'database'      => [ 'dbcache.enabled', 'dbcache.engine' ],
        'object'        => [ 'objectcache.enabled', 'objectcache.engine' ],
        'minify'        => [ 'minify.enabled', 'minify.engine' ],
        'browser'       => [ 'browsercache.enabled', null ],
        'cdn'           => [ 'cdn.enabled', 'cdn.engine' ],
        'cdn_full_site' => [ 'cdnfsd.enabled', 'cdnfsd.engine' ],
        'varnish'       => [ 'varnish.enabled', null ],
    ];

    /** @return array<string,array<string,mixed>> */
    public static function operations(): array
    {
        $requires = static fn () => Ops_Status_Packs::presence(self::is_active(), 'w3tc_inactive', 'W3 Total Cache');

        return [
            'get-w3tc-status'  => Ops_Status_Packs::read_op(
                'W3 Total Cache status: each cache (page, database, object, minify, browser, CDN, Varnish) enabled and its engine, and whether purge is available',
                $requires,
                static fn (): array => self::status()
            ),
            'purge-w3tc-cache' => [
                'mode'         => 'destructive',
                'capability'   => 'manage_options',
                'description'  => 'Purge every W3 Total Cache cache (its purge-all). A cache operation: not snapshotted, not reversible',
                'input_schema' => [ 'type' => 'object', 'properties' => [] ],
                'requires'     => $requires,
                'validate'     => static fn (): ?array => is_callable(self::purge_callback()) ? null : [
                    'code'    => 'w3tc_purge_unavailable',
                    'message' => 'W3 Total Cache is active but its purge function is not available.',
                ],
                'handler'      => static fn (): array => self::purge(),
            ],
        ];
    }

    /** Whether W3 Total Cache is loaded, filterable through wpmcp_w3tc_active. */
    public static function is_active(): bool
    {
        return (bool) apply_filters('wpmcp_w3tc_active', defined('W3TC_VERSION'));
    }

    /** @return array<string,mixed> */
    private static function status(): array
    {
        $config = self::config();
        $caches = [];
        foreach (self::CACHES as $name => [ $enabled_key, $engine_key ]) {
            $cache = [ 'enabled' => ! empty($config[ $enabled_key ]) ];
            if (null !== $engine_key) {
                $engine          = $config[ $engine_key ] ?? null;
                $cache['engine'] = is_string($engine) && '' !== sanitize_key($engine) ? sanitize_key($engine) : null;
            }
            $caches[ $name ] = $cache;
        }

        return [
            'caches'          => $caches,
            'enabled'         => self::enabled($caches),
            'purge_supported' => is_callable(self::purge_callback()),
        ];
    }

    /** @return array{purged:bool,caches:array<int,string>} */
    private static function purge(): array
    {
        $enabled = self::enabled(self::status()['caches']);
        call_user_func(self::purge_callback());

        return [
            'purged' => true,
            'caches' => $enabled,
        ];
    }

    /** @return array<int,string> the names of the enabled caches. */
    private static function enabled(array $caches): array
    {
        return array_keys(array_filter($caches, static fn (array $cache): bool => $cache['enabled']));
    }

    /** @return mixed the purge-all callable, or null when there is none. */
    private static function purge_callback()
    {
        return apply_filters('wpmcp_w3tc_purge_callback', function_exists('w3tc_flush_all') ? 'w3tc_flush_all' : null);
    }

    /**
     * The allowlisted config values, read through w3tc_config() when W3 Total
     * Cache is loaded.
     *
     * @return array<string,mixed>
     */
    private static function config(): array
    {
        $values = [];
        if (function_exists('w3tc_config')) {
            try {
                $config = call_user_func('w3tc_config');
                if (is_object($config) && method_exists($config, 'get')) {
                    foreach (self::CACHES as [ $enabled_key, $engine_key ]) {
                        foreach (array_filter([ $enabled_key, $engine_key ]) as $key) {
                            $values[ $key ] = $config->get($key);
                        }
                    }
                }
            } catch (\Throwable $e) {
                $values = [];
            }
        }

        $filtered = apply_filters('wpmcp_w3tc_config', $values);
        return is_array($filtered) ? $filtered : $values;
    }
}
