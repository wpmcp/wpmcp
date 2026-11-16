<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * LiteSpeed Cache settings (issue #933, follow-up to #692). LiteSpeed Cache
 * 7.9 keeps each setting in an option of its own, litespeed.conf.<id>
 * (Base::name()), switches as booleans and lists as arrays; the JS defer
 * mode optm-js_defer is 0 (off), 1 (defer) or 2 (delay). Keys here are the
 * plugin's own ids ("optm-css_min").
 *
 * A write goes through LiteSpeed's litespeed_save_conf action when the
 * plugin is loaded (Conf::update_confs, which purges what the change needs)
 * and is snapshotted as one option_set over every writable option. Only
 * front-end optimization switches are writable; the page cache, browser
 * cache, CDN, guest mode, object cache and crawler switches touch .htaccess
 * or QUIC.cloud services and stay read-only. The QUIC.cloud API key,
 * Cloudflare key and email, and object cache credentials are reported only
 * as set or not.
 *
 * LiteSpeed exposes targeted purges (litespeed_purge_post,
 * litespeed_purge_url), offered as purge-litespeed-cache.
 */
final class Cache_Settings_LiteSpeed extends Cache_Settings_Adapter
{
    public const PREFIX = 'litespeed.conf.';

    private const BOOL = [ 'type' => 'bool' ];
    private const LIST = [ 'type' => 'list' ];

    public function slug(): string
    {
        return 'litespeed';
    }

    public function label(): string
    {
        return 'LiteSpeed Cache';
    }

    public function option(): string
    {
        return self::PREFIX . '*';
    }

    public function plugin_file(): string
    {
        return 'litespeed-cache/litespeed-cache.php';
    }

    protected function loaded(): bool
    {
        return defined('LSCWP_V');
    }

    public function version(): string
    {
        return defined('LSCWP_V') ? (string) constant('LSCWP_V') : '';
    }

    public function rules(): array
    {
        return [
            'optm-css_min'      => self::BOOL,
            'optm-js_min'       => self::BOOL,
            'optm-html_min'     => self::BOOL,
            'optm-js_defer'     => [ 'type' => 'int', 'min' => 0, 'max' => 2 ],
            'optm-emoji_rm'     => self::BOOL,
            'optm-qs_rm'        => self::BOOL,
            'optm-js_defer_exc' => self::LIST,
            'media-lazy'        => self::BOOL,
            'media-iframe_lazy' => self::BOOL,
            'media-lazy_exc'    => self::LIST,
        ];
    }

    public function read_only(): array
    {
        return [
            'cache'         => self::BOOL,
            'cache-priv'    => self::BOOL,
            'cache-mobile'  => self::BOOL,
            'cache-browser' => self::BOOL,
            'cache-ttl_pub' => [ 'type' => 'int' ],
            'cache-exc'     => self::LIST,
            'guest'         => self::BOOL,
            'optm-ucss'     => self::BOOL,
            'optm-css_async' => self::BOOL,
            'cdn'           => self::BOOL,
            'crawler'       => self::BOOL,
            'object'        => self::BOOL,
            'debug'         => [ 'type' => 'raw' ],
        ];
    }

    public function secret_keys(): array
    {
        return [ 'api_key', 'cdn-cloudflare_key', 'cdn-cloudflare_email', 'object-user', 'object-pswd' ];
    }

    public function summary_keys(): array
    {
        return [
            'lazy_load'  => 'media-lazy',
            'minify_css' => 'optm-css_min',
            'minify_js'  => 'optm-js_min',
            'delay_js'   => null,
            'cdn'        => 'cdn',
            'preload'    => 'crawler',
        ];
    }

    protected function store_bool(bool $on)
    {
        return $on;
    }

    /** @return array<string, mixed> every known setting, read from its own option. */
    public function stored(): array
    {
        $out = [];
        foreach (array_merge(array_keys($this->rules() + $this->read_only()), $this->secret_keys()) as $id) {
            $value = get_option(self::PREFIX . $id, null);
            if (null !== $value) {
                $out[ $id ] = $value;
            }
        }
        return $out;
    }

    /** @return string[] the option names a write can change. */
    public function writable_options(): array
    {
        return array_map(static fn (string $id): string => self::PREFIX . $id, array_keys($this->rules()));
    }

    public function snapshot_target(): array
    {
        return [ 'object_type' => 'option_set', 'object_id' => \WPMCP\Safety\Snapshot::option_set_id($this->writable_options()) ];
    }

    public function owns_options(array $names): bool
    {
        return [] !== array_intersect($this->writable_options(), $names);
    }

    /**
     * Through LiteSpeed's own save (Conf::update_confs: type casting, purge
     * on change) when it is loaded, otherwise option by option.
     */
    public function apply(array $changes): void
    {
        $rules  = $this->rules();
        $matrix = [];
        foreach ($changes as $id => $value) {
            $matrix[ (string) $id ] = $this->to_stored($rules[ $id ], $value);
        }
        if ($this->loaded() && has_action('litespeed_save_conf')) {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- invoking LiteSpeed Cache's own save hook, not defining one.
            do_action('litespeed_save_conf', $matrix);
            return;
        }
        foreach ($matrix as $id => $value) {
            update_option(self::PREFIX . $id, $value);
        }
    }

    public function supports_targeted_purge(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function purge_post(int $post_id): array
    {
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- invoking LiteSpeed Cache's own purge hook, not defining one.
        do_action('litespeed_purge_post', $post_id);
        return [ 'purged' => 'post', 'post_id' => $post_id ];
    }

    /** @return array<string, mixed> */
    public function purge_url(string $url): array
    {
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- invoking LiteSpeed Cache's own purge hook, not defining one.
        do_action('litespeed_purge_url', $url);
        return [ 'purged' => 'url', 'url' => $url ];
    }
}
