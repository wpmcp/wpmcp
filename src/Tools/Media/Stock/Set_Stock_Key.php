<?php

namespace WPMCP\Tools\Media\Stock;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * set-stock-key (issue #64): store or clear a BYO stock-provider API key.
 * manage_options only; the key is encrypted at rest by Stock_Key_Store and
 * is NEVER echoed back in any tool output.
 */
class Set_Stock_Key
{
    public const KEYED_PROVIDERS   = ['pexels', 'unsplash'];
    public const KEYLESS_PROVIDERS = ['openverse'];

    public function handle(array $args): array
    {
        $provider = sanitize_key((string) ($args['provider'] ?? ''));
        if (in_array($provider, self::KEYLESS_PROVIDERS, true)) {
            throw new \InvalidArgumentException(sprintf('Provider "%s" does not require an API key.', esc_html($provider)));
        }
        if (! in_array($provider, self::KEYED_PROVIDERS, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown stock provider "%s". Keyed providers: %s.',
                esc_html($provider),
                esc_html(implode(', ', self::KEYED_PROVIDERS))
            ));
        }

        $api_key = trim((string) ($args['api_key'] ?? ''));
        if ('' === $api_key) {
            Stock_Key_Store::clear($provider);
        } else {
            Stock_Key_Store::set($provider, $api_key);
        }

        return [
            'provider'   => $provider,
            'configured' => '' !== $api_key,
        ];
    }
}
