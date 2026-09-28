<?php

namespace WPMCP\Tools\Analysis\SeoData;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * set-seo-data-key (issue #304): store or clear a bring-your-own SEO data
 * provider credential. manage_options only; the credential is encrypted at
 * rest by Seo_Data_Key_Store and is never echoed back in any tool output,
 * including the refusal for a malformed one. Saving or clearing a credential
 * also drops the lookup cache and any rate-limit cooldown, so a fixed or
 * upgraded account takes effect on the next lookup.
 */
class Set_Seo_Data_Key
{
    public function handle(array $args): array
    {
        $provider = Seo_Data_Lookup::provider((string) ($args['provider'] ?? ''));

        $api_key = trim((string) ($args['api_key'] ?? ''));
        if ('' === $api_key) {
            Seo_Data_Key_Store::clear($provider->slug());
        } else {
            $provider->validate_credential($api_key);
            Seo_Data_Key_Store::set($provider->slug(), $api_key);
        }
        Seo_Data_Lookup::flush_cache();

        return [
            'provider'   => $provider->slug(),
            'configured' => '' !== $api_key,
        ];
    }
}
