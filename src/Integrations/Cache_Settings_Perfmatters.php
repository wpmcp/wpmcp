<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Perfmatters settings (issue #692). Perfmatters keeps its settings in the
 * perfmatters_options option, grouped in sections (lazyload, assets,
 * preload, fonts, cdn) with a few top-level switches, and stores a switch
 * that is on as "1" and one that is off as an absent key, the way its
 * settings form saves. Keys here are section.key ("lazyload.lazy_loading").
 *
 * Perfmatters has no page cache, so there is no preload op. Its code boxes
 * (header, body and footer code) and the custom login URL are never read or
 * written here, and its license key lives in an option of its own that is
 * never read.
 */
final class Cache_Settings_Perfmatters extends Cache_Settings_Adapter
{
    private const BOOL   = [ 'type' => 'bool' ];
    private const LIST   = [ 'type' => 'list' ];
    private const COUNT  = [ 'type' => 'enum', 'values' => [ '', '1', '2', '3', '4', '5' ] ];

    public function slug(): string
    {
        return 'perfmatters';
    }

    public function label(): string
    {
        return 'Perfmatters';
    }

    public function option(): string
    {
        return 'perfmatters_options';
    }

    public function plugin_file(): string
    {
        return 'perfmatters/perfmatters.php';
    }

    protected function loaded(): bool
    {
        return defined('PERFMATTERS_VERSION');
    }

    public function version(): string
    {
        return defined('PERFMATTERS_VERSION') ? (string) constant('PERFMATTERS_VERSION') : '';
    }

    public function rules(): array
    {
        return [
            // General.
            'disable_emojis'                     => self::BOOL,
            'disable_embeds'                     => self::BOOL,
            'disable_dashicons'                  => self::BOOL,
            'remove_jquery_migrate'              => self::BOOL,
            // Lazy loading.
            'lazyload.lazy_loading'              => self::BOOL,
            'lazyload.lazy_loading_iframes'      => self::BOOL,
            'lazyload.youtube_preview_thumbnails' => self::BOOL,
            'lazyload.image_dimensions'          => self::BOOL,
            'lazyload.css_background_images'     => self::BOOL,
            'lazyload.exclude_leading_images'    => self::COUNT,
            'lazyload.lazy_loading_exclusions'   => self::LIST,
            // JavaScript.
            'assets.defer_js'                    => self::BOOL,
            'assets.js_exclusions'               => self::LIST,
            'assets.delay_js'                    => self::BOOL,
            'assets.delay_js_behavior'           => [ 'type' => 'enum', 'values' => [ '', 'all' ] ],
            'assets.delay_js_inclusions'         => self::LIST,
            'assets.delay_js_exclusions'         => self::LIST,
            'assets.minify_js'                   => self::BOOL,
            'assets.minify_js_exclusions'        => self::LIST,
            // CSS.
            'assets.remove_unused_css'           => self::BOOL,
            'assets.rucss_excluded_stylesheets'  => self::LIST,
            'assets.rucss_excluded_selectors'    => self::LIST,
            'assets.minify_css'                  => self::BOOL,
            'assets.minify_css_exclusions'       => self::LIST,
            // Preloading.
            'preload.instant_page'               => self::BOOL,
            'preload.critical_images'            => self::COUNT,
            'preload.dns_prefetch'               => self::LIST,
            // Fonts.
            'fonts.disable_google_fonts'         => self::BOOL,
            'fonts.local_google_fonts'           => self::BOOL,
            'fonts.display_swap'                 => self::BOOL,
            // CDN.
            'cdn.enable_cdn'                     => self::BOOL,
            'cdn.cdn_url'                        => [ 'type' => 'url' ],
            'cdn.cdn_directories'                => [ 'type' => 'text' ],
            'cdn.cdn_exclusions'                 => [ 'type' => 'text' ],
        ];
    }

    public function read_only(): array
    {
        return [
            'disable_xmlrpc'        => self::BOOL,
            'disable_heartbeat'     => [ 'type' => 'text' ],
            'limit_post_revisions'  => [ 'type' => 'text' ],
            'assets.rucss_method'   => [ 'type' => 'text' ],
            'preload.preload'       => [ 'type' => 'raw' ],
            'preload.preconnect'    => [ 'type' => 'raw' ],
        ];
    }

    public function secret_keys(): array
    {
        return [];
    }

    public function summary_keys(): array
    {
        return [
            'lazy_load'  => 'lazyload.lazy_loading',
            'minify_css' => 'assets.minify_css',
            'minify_js'  => 'assets.minify_js',
            'delay_js'   => 'assets.delay_js',
            'cdn'        => 'cdn.enable_cdn',
            'preload'    => null,
        ];
    }

    protected function store_bool(bool $on)
    {
        return $on ? '1' : null;
    }

    /** An empty select or text is stored as an absent key, as the settings form leaves it. */
    protected function to_stored(array $rule, $value)
    {
        if (in_array($rule['type'], [ 'enum', 'text', 'url' ], true) && '' === (string) $value) {
            return null;
        }
        if ('list' === $rule['type'] && [] === $value) {
            return null;
        }
        return parent::to_stored($rule, $value);
    }
}
