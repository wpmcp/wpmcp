<?php

namespace WPMCP\Cloud;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Where WP MCP Cloud lives and how this site authenticates to it. The base URL
 * and API key are stored as options; everything else about the cloud is behind
 * the versioned REST contract (see Cloud_Client), so the backend can be
 * reimplemented (WordPress now, a scalable service later) without touching the
 * plugin.
 */
class Cloud_Config
{
    private const URL_OPTION = 'wpmcp_cloud_url';
    private const KEY_OPTION = 'wpmcp_cloud_key';

    public static function base_url(): string
    {
        return rtrim((string) get_option(self::URL_OPTION, ''), '/');
    }

    public static function api_key(): string
    {
        return (string) get_option(self::KEY_OPTION, '');
    }

    public static function is_configured(): bool
    {
        return '' !== self::base_url() && '' !== self::api_key();
    }

    public static function set(string $url, string $key): void
    {
        update_option(self::URL_OPTION, esc_url_raw(rtrim($url, '/')));
        update_option(self::KEY_OPTION, sanitize_text_field($key));
    }
}
