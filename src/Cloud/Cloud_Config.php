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

    /**
     * A connection needs a cloud URL plus something to authenticate with:
     * either the phase A API key or the phase 2 token bundle. Requiring the
     * API key would make an OAuth-only connection unusable, because
     * Cloud_Client refuses the request before its auth ladder ever runs.
     */
    public static function is_configured(): bool
    {
        if ('' === self::base_url()) {
            return false;
        }
        if ('' !== self::api_key()) {
            return true;
        }
        $bundle = Cloud_Credentials::all();
        return '' !== (string) ($bundle['access_token'] ?? '')
            || '' !== (string) ($bundle['refresh_token'] ?? '');
    }

    /**
     * Point this site at a cloud. Deliberately a REPLACE, not a merge: a
     * re-run of cloud-connect may be pointing at a different cloud or a
     * different account, and keeping the previous connection's access token,
     * refresh token or client id would make Cloud_Client prefer a foreign
     * bearer token over the key just supplied, and hand the old refresh token
     * to the newly supplied URL.
     *
     * Clearing the refresh health state is Cloud_Credentials::replace()'s job,
     * not this facade's, so every caller that stores a credential set (this
     * one today, the phase 2 PKCE connect flow tomorrow) gets it.
     */
    public static function set(string $url, string $key): bool
    {
        return Cloud_Credentials::replace([
            'base_url' => esc_url_raw(rtrim($url, '/')),
            'api_key'  => sanitize_text_field($key),
        ]);
    }
}
