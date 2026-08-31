<?php

namespace WPMCP\Tools\Cache;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Detects active page-cache plugins by their signature functions/constants and
 * (when asked) clears each detected plugin through its own public API.
 *
 * Every plugin integration is guarded by function_exists()/defined() so the
 * tool never fatals when a plugin is absent, and the clear callbacks are only
 * invoked for plugins that were actually detected.
 *
 * The signature map is injectable so tests can drive detection deterministically
 * without loading real caching plugins; production uses the default map.
 */
class Page_Cache_Detector
{
    /**
     * @var array<string, array{functions: string[], constants: string[], clear?: callable}>
     */
    private array $signatures;

    /**
     * @param array<string, array{functions: string[], constants: string[], clear?: callable}>|null $signatures
     */
    public function __construct(?array $signatures = null)
    {
        $this->signatures = $signatures ?? self::default_signatures();
    }

    /**
     * @return array{detected: bool, plugins: string[]}
     */
    public function detect(): array
    {
        $plugins = [];

        foreach ($this->signatures as $name => $sig) {
            if ($this->is_present($sig)) {
                $plugins[] = $name;
            }
        }

        return [
            'detected' => [] !== $plugins,
            'plugins'  => $plugins,
        ];
    }

    /**
     * Clear every detected page-cache plugin via its own API.
     *
     * @return array<string, string> Map of plugin name to 'cleared' | 'no_clear_api'.
     */
    public function clear_detected(): array
    {
        $results = [];

        foreach ($this->signatures as $name => $sig) {
            if (! $this->is_present($sig)) {
                continue;
            }

            $clear = $sig['clear'] ?? null;
            if (is_callable($clear)) {
                $clear();
                $results[ $name ] = 'cleared';
            } else {
                $results[ $name ] = 'no_clear_api';
            }
        }

        return $results;
    }

    /**
     * @param array{functions: string[], constants: string[]} $sig
     */
    private function is_present(array $sig): bool
    {
        foreach ($sig['functions'] as $fn) {
            if (function_exists($fn)) {
                return true;
            }
        }
        foreach ($sig['constants'] as $const) {
            if (defined($const)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Signature functions/constants and clear callbacks for the supported
     * page-cache plugins. Each clear callback re-guards its own API so it is
     * safe to call even if only a constant (not the clear function) is present.
     *
     * @return array<string, array{functions: string[], constants: string[], clear: callable}>
     */
    private static function default_signatures(): array
    {
        return [
            'WP Rocket' => [
                'functions' => ['rocket_clean_domain'],
                'constants' => ['WP_ROCKET_VERSION'],
                'clear'     => static function (): void {
                    if (function_exists('rocket_clean_domain')) {
                        rocket_clean_domain();
                    }
                },
            ],
            'W3 Total Cache' => [
                'functions' => ['w3tc_flush_all'],
                'constants' => ['W3TC'],
                'clear'     => static function (): void {
                    if (function_exists('w3tc_flush_all')) {
                        w3tc_flush_all();
                    }
                },
            ],
            'WP Super Cache' => [
                'functions' => ['wp_cache_clear_cache'],
                'constants' => ['WPCACHEHOME'],
                'clear'     => static function (): void {
                    if (function_exists('wp_cache_clear_cache')) {
                        wp_cache_clear_cache();
                    }
                },
            ],
            'LiteSpeed Cache' => [
                'functions' => ['litespeed_purge_all'],
                'constants' => ['LSCWP_V'],
                'clear'     => static function (): void {
                    if (function_exists('do_action')) {
                        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party plugin (LiteSpeed Cache) public hook.
                        do_action('litespeed_purge_all');
                    }
                },
            ],
            'WP Fastest Cache' => [
                'functions' => ['wpfc_clear_all_cache'],
                'constants' => ['WPFC_MAIN_PATH'],
                'clear'     => static function (): void {
                    if (function_exists('wpfc_clear_all_cache')) {
                        wpfc_clear_all_cache();
                    }
                },
            ],
        ];
    }
}
