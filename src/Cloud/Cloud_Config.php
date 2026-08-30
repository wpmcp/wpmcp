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

    /**
     * The credential Cloud_Client should present: the OAuth access token from
     * the encrypted vault (issue #135 phase B), falling back to the phase A
     * API key.
     *
     * The vault token is used only when it is BOTH unexpired and issued by
     * the cloud we are currently pointed at. Without the issuer check, running
     * cloud-connect against a different (possibly operator-mistyped, possibly
     * hostile) URL would ship the previous cloud's bearer token to the new
     * host; without the expiry check, a dead bundle would permanently shadow a
     * working API key. Refreshing an expired bundle is Cloud_Client's job
     * (retry-once on 401), not this getter's: no network happens here.
     */
    public static function bearer_token(): string
    {
        $bundle = self::live_bundle();
        if (null !== $bundle) {
            return $bundle['access_token'];
        }

        return self::api_key();
    }

    /**
     * The vault bundle when it is usable against the configured cloud, else
     * null. A bundle that is merely expired is still "ours" and is returned by
     * Token_Vault::read() for refresh purposes; this narrower view is what may
     * go on the wire as-is.
     *
     * @return array{access_token:string,refresh_token:string,expires_at:int,issuer:string}|null
     */
    public static function live_bundle(): ?array
    {
        $bundle = Token_Vault::read();
        if (null === $bundle || '' === $bundle['access_token']) {
            return null;
        }
        if ('' !== $bundle['issuer'] && rtrim($bundle['issuer'], '/') !== self::base_url()) {
            return null;
        }
        if ($bundle['expires_at'] > 0 && $bundle['expires_at'] <= time()) {
            return null;
        }

        return $bundle;
    }

    public static function is_configured(): bool
    {
        return '' !== self::base_url() && ('' !== self::api_key() || Token_Vault::has_bundle());
    }

    /**
     * Point the site at a cloud. Any sealed token bundle is dropped: it was
     * minted by the previous connect (possibly by a different cloud entirely)
     * and must never outlive it.
     */
    public static function set(string $url, string $key): void
    {
        Token_Vault::clear();
        update_option(self::URL_OPTION, esc_url_raw(rtrim($url, '/')));
        update_option(self::KEY_OPTION, sanitize_text_field($key));
    }
}
