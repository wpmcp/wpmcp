<?php

namespace WPMCP\Tools\Analysis\SeoData;

use WPMCP\Crypto\Secret_Box;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Encrypted-at-rest storage for bring-your-own SEO data provider credentials
 * (issue #304), built exactly like Media\Stock\Stock_Key_Store: sealed with
 * Crypto\Secret_Box under its own domain label, so a copied database without
 * this site's salts cannot recover a credential and a blob sealed for the
 * stock store never opens here. A blob that fails to open reads as "not
 * configured". When no sodium implementation is usable, set() stores nothing
 * rather than a plaintext credential.
 */
class Seo_Data_Key_Store
{
    public const OPTION = 'wpmcp_seo_data_keys';

    /** Secret_Box domain label; changing it would orphan every stored key. */
    private const LABEL = 'wpmcp-seo-data-keys';

    public static function set(string $provider, string $key): void
    {
        $sealed = Secret_Box::seal($key, self::LABEL);
        if (null === $sealed) {
            // Fail closed: never store a provider key unsealed.
            return;
        }
        $keys = self::all();
        $keys[ sanitize_key($provider) ] = $sealed;
        update_option(self::OPTION, $keys, false);
    }

    public static function get(string $provider): ?string
    {
        $blob = self::all()[ sanitize_key($provider) ] ?? null;
        return is_string($blob) ? Secret_Box::open($blob, self::LABEL) : null;
    }

    public static function clear(string $provider): void
    {
        $keys = self::all();
        unset($keys[ sanitize_key($provider) ]);
        if (empty($keys)) {
            delete_option(self::OPTION);
            return;
        }
        update_option(self::OPTION, $keys, false);
    }

    /** @return string[] provider slugs with a stored key, sorted. */
    public static function configured(): array
    {
        $providers = array_keys(self::all());
        sort($providers);
        return $providers;
    }

    /** @return array<string, string> */
    private static function all(): array
    {
        $keys = get_option(self::OPTION, []);
        return is_array($keys) ? $keys : [];
    }
}
