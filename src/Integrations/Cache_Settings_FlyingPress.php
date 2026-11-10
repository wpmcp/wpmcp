<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * FlyingPress settings (issue #692). FlyingPress keeps one flat config in
 * the FLYING_PRESS_CONFIG option and loads it into FlyingPress\Config at
 * start-up. A write goes through Config::update_config(), as its own REST
 * save does, so its flying_press_update_config:after listeners (.htaccess,
 * advanced-cache.php, purge) run; a rollback reloads the restored option
 * into Config and fires the same action.
 *
 * The license key, the FlyingCDN API key and the Cloudflare credentials
 * are only reported masked. The CDN type, image optimizer, object cache
 * and Cloudflare page caching stay read-only: switching them reaches
 * external services or writes drop-ins.
 *
 * The preload op runs Preload::preload_cache(), FlyingPress's own
 * "Preload cache" action, which queues every public URL and warms the
 * cache in the background.
 */
final class Cache_Settings_FlyingPress extends Cache_Settings_Adapter
{
    private const BOOL = [ 'type' => 'bool' ];
    private const LIST = [ 'type' => 'list' ];

    private const CONFIG_CLASS  = 'FlyingPress\\Config';
    private const PRELOAD_CLASS = 'FlyingPress\\Preload';

    public function slug(): string
    {
        return 'flyingpress';
    }

    public function label(): string
    {
        return 'FlyingPress';
    }

    public function option(): string
    {
        return 'FLYING_PRESS_CONFIG';
    }

    public function plugin_file(): string
    {
        return 'flying-press/flying-press.php';
    }

    protected function loaded(): bool
    {
        return defined('FLYING_PRESS_VERSION');
    }

    public function version(): string
    {
        return defined('FLYING_PRESS_VERSION') ? (string) constant('FLYING_PRESS_VERSION') : '';
    }

    public function rules(): array
    {
        return [
            // CSS and JavaScript.
            'css_js_minify'                 => self::BOOL,
            'css_rucss'                     => self::BOOL,
            'css_rucss_include_selectors'   => self::LIST,
            'js_delay'                      => self::BOOL,
            'js_delay_method'               => [ 'type' => 'enum', 'values' => [ 'defer', 'background', 'user-interaction' ] ],
            'js_delay_excludes'             => self::LIST,
            'js_delay_third_party'          => self::BOOL,
            'js_delay_third_party_excludes' => self::LIST,
            'js_delay_selected'             => self::BOOL,
            'js_delay_selected_includes'    => self::LIST,
            'css_js_self_host_third_party'  => self::BOOL,
            // Images, video and iframes.
            'lazy_load'                     => self::BOOL,
            'lazy_load_exclusions'          => self::LIST,
            'properly_size_images'          => self::BOOL,
            'youtube_placeholder'           => self::BOOL,
            'self_host_gravatars'           => self::BOOL,
            // Fonts.
            'fonts_preload'                 => self::BOOL,
            'fonts_optimize_google'         => self::BOOL,
            'fonts_display_swap'            => self::BOOL,
            // Rendering.
            'lazy_render'                   => self::BOOL,
            'lazy_render_excludes'          => self::LIST,
            // Caching.
            'cache_link_prefetch'           => self::BOOL,
            'cache_mobile'                  => self::BOOL,
            'cache_logged_in'               => self::BOOL,
            'cache_refresh'                 => self::BOOL,
            'cache_refresh_interval'        => [ 'type' => 'enum', 'values' => [ '2hours', '6hours', 'twicedaily', 'daily' ] ],
            'cache_bypass_urls'             => self::LIST,
            'cache_include_queries'         => self::LIST,
            'cache_bypass_cookies'          => self::LIST,
            // CDN (custom URL).
            'cdn'                           => self::BOOL,
            'cdn_url'                       => [ 'type' => 'url' ],
            'cdn_file_types'                => [ 'type' => 'enum', 'values' => [ 'all', 'css_js_font', 'image' ] ],
            // Bloat.
            'bloat_disable_block_css'       => self::BOOL,
            'bloat_disable_dashicons'       => self::BOOL,
            'bloat_disable_emojis'          => self::BOOL,
            'bloat_disable_jquery_migrate'  => self::BOOL,
            'bloat_disable_oembeds'         => self::BOOL,
        ];
    }

    public function read_only(): array
    {
        return [
            'cdn_type'         => [ 'type' => 'text' ],
            'cf_page_caching'  => self::BOOL,
            'cf_status'        => [ 'type' => 'text' ],
            'object_cache'     => self::BOOL,
            'image_format'     => [ 'type' => 'text' ],
        ];
    }

    public function secret_keys(): array
    {
        return [ 'license_key', 'flying_cdn_api_key', 'cf_api_key', 'cf_email', 'cf_zone_id', 'cf_host_signature' ];
    }

    public function summary_keys(): array
    {
        return [
            'lazy_load'  => 'lazy_load',
            'minify_css' => 'css_js_minify',
            'minify_js'  => 'css_js_minify',
            'delay_js'   => 'js_delay',
            'cdn'        => 'cdn',
            // FlyingPress always warms the cache after a purge; there is no switch.
            'preload'    => null,
        ];
    }

    protected function store_bool(bool $on)
    {
        return $on;
    }

    public function apply(array $changes): void
    {
        $class = self::CONFIG_CLASS;
        if (! class_exists($class) || ! method_exists($class, 'update_config')) {
            parent::apply($changes);
            return;
        }
        $rules = $this->rules();
        $new   = [];
        foreach ($changes as $key => $value) {
            $new[ $key ] = $this->to_stored($rules[ $key ], $value);
        }
        // Config holds what it loaded at start-up; start from the stored
        // option so a value changed since (a rollback) is not written back.
        $class::$config = $this->stored();
        $class::update_config($new);
    }

    public function after_restore(): void
    {
        $class = self::CONFIG_CLASS;
        if (! class_exists($class) || ! property_exists($class, 'config')) {
            return;
        }
        $previous       = $class::$config;
        $class::$config = $this->stored();
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- FlyingPress's own action after its config changes.
        do_action('flying_press_update_config:after', $class::$config, $previous);
    }

    public function supports_preload(): bool
    {
        return true;
    }

    public function preload_refusal(): ?array
    {
        if (! is_callable([ self::PRELOAD_CLASS, 'preload_cache' ])) {
            return [
                'code'    => 'flyingpress_preload_unavailable',
                'message' => 'FlyingPress is active but its preload is not available.',
            ];
        }
        return null;
    }

    public function preload(): array
    {
        call_user_func([ self::PRELOAD_CLASS, 'preload_cache' ]);

        return [
            'started' => true,
            'method'  => 'FlyingPress\\Preload::preload_cache',
            'note'    => 'FlyingPress queues the home page and every public post, page and term and warms the cache in the background.',
        ];
    }
}
