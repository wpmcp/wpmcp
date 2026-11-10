<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * WP Rocket settings (issue #692). WP Rocket keeps every setting in the
 * wp_rocket_settings option, a switch as 1 or 0 and a textarea as a list of
 * lines, and reacts to a save through its update_option_wp_rocket_settings
 * listeners (config file, .htaccess rules, cache clear), which a write here
 * and its rollback both trigger. The writable keys follow the ones WP Rocket
 * itself exposes to agents (its AllowedOptions list), narrowed to file
 * optimization, media, preload, CDN and cache rules.
 *
 * The license (consumer_key, consumer_email, secret_key), the cache-busting
 * keys and the Cloudflare and Sucuri credentials are only reported masked.
 *
 * The preload op resets WP Rocket's preload queue through its own
 * rocket_reset_preload action, which rebuilds the URL list from the sitemap
 * and lets WP Rocket's cron warm the cache. It needs WP Rocket's preload
 * (manual_preload) on.
 */
final class Cache_Settings_WP_Rocket extends Cache_Settings_Adapter
{
    private const BOOL = [ 'type' => 'bool' ];
    private const LIST = [ 'type' => 'list' ];

    /** The CDN zones WP Rocket offers for each CNAME. */
    private const CDN_ZONES = [ 'all', 'images', 'css_and_js', 'css', 'js' ];

    public function slug(): string
    {
        return 'wp-rocket';
    }

    public function label(): string
    {
        return 'WP Rocket';
    }

    public function option(): string
    {
        return 'wp_rocket_settings';
    }

    public function plugin_file(): string
    {
        return 'wp-rocket/wp-rocket.php';
    }

    protected function loaded(): bool
    {
        return defined('WP_ROCKET_VERSION');
    }

    public function version(): string
    {
        return defined('WP_ROCKET_VERSION') ? (string) constant('WP_ROCKET_VERSION') : '';
    }

    public function rules(): array
    {
        return [
            // Media.
            'lazyload'                   => self::BOOL,
            'lazyload_iframes'           => self::BOOL,
            'lazyload_youtube'           => self::BOOL,
            'lazyload_css_bg_img'        => self::BOOL,
            'image_dimensions'           => self::BOOL,
            'exclude_lazyload'           => self::LIST,
            // CSS.
            'minify_css'                 => self::BOOL,
            'minify_google_fonts'        => self::BOOL,
            'remove_unused_css'          => self::BOOL,
            'async_css'                  => self::BOOL,
            'exclude_css'                => self::LIST,
            'remove_unused_css_safelist' => self::LIST,
            // JavaScript.
            'minify_js'                  => self::BOOL,
            'defer_all_js'               => self::BOOL,
            'delay_js'                   => self::BOOL,
            'exclude_js'                 => self::LIST,
            'exclude_inline_js'          => self::LIST,
            'exclude_defer_js'           => self::LIST,
            'delay_js_exclusions'        => self::LIST,
            // Fonts.
            'host_fonts_locally'         => self::BOOL,
            'auto_preload_fonts'         => self::BOOL,
            'preload_fonts'              => self::LIST,
            // Preload.
            'manual_preload'             => self::BOOL,
            'preload_links'              => self::BOOL,
            'preload_excluded_uri'       => self::LIST,
            'dns_prefetch'               => self::LIST,
            // CDN.
            'cdn'                        => self::BOOL,
            'cdn_cnames'                 => self::LIST,
            'cdn_zone'                   => [ 'type' => 'list', 'values' => self::CDN_ZONES ],
            'cdn_reject_files'           => self::LIST,
            // Cache.
            'cache_mobile'               => self::BOOL,
            'do_caching_mobile_files'    => self::BOOL,
            'cache_logged_user'          => self::BOOL,
            'cache_reject_uri'           => self::LIST,
            'cache_reject_cookies'       => self::LIST,
            'cache_reject_ua'            => self::LIST,
            'cache_query_strings'        => self::LIST,
            'purge_cron_interval'        => [ 'type' => 'int', 'min' => 0, 'max' => 100000 ],
            'purge_cron_unit'            => [ 'type' => 'enum', 'values' => [ 'MINUTE_IN_SECONDS', 'HOUR_IN_SECONDS', 'DAY_IN_SECONDS' ] ],
            'emoji'                      => self::BOOL,
        ];
    }

    public function read_only(): array
    {
        return [
            'minify_concatenate_js'     => self::BOOL,
            'do_cloudflare'             => self::BOOL,
            'cloudflare_devmode'        => self::BOOL,
            'control_heartbeat'         => self::BOOL,
            'varnish_auto_purge'        => self::BOOL,
            'sucury_waf_cache_sync'     => self::BOOL,
            'schedule_automatic_cleanup' => self::BOOL,
        ];
    }

    public function secret_keys(): array
    {
        return [
            'consumer_key', 'consumer_email', 'secret_key', 'license', 'secret_cache_key',
            'minify_css_key', 'minify_js_key', 'cloudflare_api_key', 'cloudflare_email',
            'cloudflare_zone_id', 'sucury_waf_api_key',
        ];
    }

    public function summary_keys(): array
    {
        return [
            'lazy_load'  => 'lazyload',
            'minify_css' => 'minify_css',
            'minify_js'  => 'minify_js',
            'delay_js'   => 'delay_js',
            'cdn'        => 'cdn',
            'preload'    => 'manual_preload',
        ];
    }

    protected function store_bool(bool $on)
    {
        return $on ? 1 : 0;
    }

    /**
     * WP Rocket pairs every CDN CNAME with a zone. A new CNAME list without
     * zones keeps the stored zones when the count still matches, and
     * otherwise serves every file type from each CNAME ("all").
     */
    public function implied(array $changes): array
    {
        if (array_key_exists('cdn_cnames', $changes) && ! array_key_exists('cdn_zone', $changes)) {
            $zones = $this->current()['cdn_zone'];
            $count = count($changes['cdn_cnames']);
            if (count($zones) !== $count) {
                $changes['cdn_zone'] = array_fill(0, $count, 'all');
            }
        }
        return $changes;
    }

    public function conflict(array $after): ?array
    {
        if (count($after['cdn_cnames']) !== count($after['cdn_zone'])) {
            return [
                'code'    => 'wp_rocket_cdn_zone_mismatch',
                'message' => 'WP Rocket pairs every CDN CNAME with one zone: cdn_cnames and cdn_zone must have the same number of entries.',
            ];
        }
        if ($after['remove_unused_css'] && $after['async_css']) {
            return [
                'code'    => 'wp_rocket_css_conflict',
                'message' => 'WP Rocket uses either Remove Unused CSS (remove_unused_css) or Load CSS Asynchronously (async_css), not both. Turn one off in the same call.',
            ];
        }
        return null;
    }

    public function supports_preload(): bool
    {
        return true;
    }

    public function preload_refusal(): ?array
    {
        if (! $this->current()['manual_preload']) {
            return [
                'code'    => 'wp_rocket_preload_off',
                'message' => 'WP Rocket preload is off. Turn it on with set-wp-rocket-settings (manual_preload: true), which also starts a preload.',
            ];
        }
        return null;
    }

    public function preload(): array
    {
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WP Rocket's own action that restarts its preload.
        do_action('rocket_reset_preload');

        return [
            'started' => true,
            'method'  => 'rocket_reset_preload',
            'note'    => 'WP Rocket rebuilds its preload list from the sitemap and warms the cache in the background through its own cron.',
        ];
    }
}
