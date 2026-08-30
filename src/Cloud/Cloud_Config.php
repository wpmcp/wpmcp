<?php

namespace WPMCP\Cloud;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Where WP MCP Cloud lives and how this site authenticates to it. Since
 * issue #141 this is a thin facade over the encrypted Cloud_Credentials
 * vault, so cloud-connect and the other phase A tools keep their existing
 * call sites while no secret is stored unencrypted. Everything else about
 * the cloud is behind the versioned REST contract (see Cloud_Client), so
 * the backend can be reimplemented without touching the plugin.
 */
class Cloud_Config
{
    public static function base_url(): string
    {
        return rtrim((string) (Cloud_Credentials::get('base_url') ?? ''), '/');
    }

    public static function api_key(): string
    {
        return (string) (Cloud_Credentials::get('api_key') ?? '');
    }

    public static function is_configured(): bool
    {
        return '' !== self::base_url() && '' !== self::api_key();
    }

    public static function set(string $url, string $key): void
    {
        Cloud_Credentials::merge([
            'base_url' => esc_url_raw(rtrim($url, '/')),
            'api_key'  => sanitize_text_field($key),
        ]);
    }
}
