<?php
/**
 * A stand-in for FluentSMTP for the FluentSMTP ops on the theme pair
 * (Fluent_SMTP_Status), for tests that run without the plugin loaded.
 *
 * Kept the way FluentSMTP 2.4 keeps its settings (read from the wordpress.org
 * release): one `fluentmail-settings` option holding `connections` (each with
 * `provider_settings`), `mappings` (sender address to connection key), `misc`,
 * `use_encrypt`, `encrypt_version` and `test`. Like the plugin:
 *  - fluentMailGetSettings($defaults, $cached) returns the settings with the
 *    secret fields of every connection decrypted when use_encrypt is set;
 *  - fluentMailSetSettings($settings) encrypts those fields again, stamps the
 *    encryption version and saves the option;
 *  - the secret fields per provider are SecretMasker::ENCRYPTED_FIELDS.
 * Encryption here is a reversible encoding, so a stored value never equals the
 * secret it hides. The log table is created by the test, as the plugin's
 * migration does (columns as in database/migrations/EmailLogs.php).
 *
 * Every definition is guarded so the real plugin wins wherever it is loaded.
 * FLUENTMAIL is never defined here, so the ops count FluentSMTP as active only
 * where a test turns wpmcp_fluent_smtp_active on.
 */

// phpcs:disable

namespace FluentMail\App\Services {
    if (! class_exists(SecretMasker::class)) {
        class SecretMasker
        {
            const MASK = '__MASKED_KEY_ENCRYPTED__';
            const SECRET_FIELDS = [ 'password', 'api_key', 'secret_key', 'access_key', 'client_secret' ];
            const WITHHELD_FIELDS = [ 'access_token', 'refresh_token' ];
            const ENCRYPT_VERSION = 2;
            const ENCRYPTED_FIELDS = [
                'smtp'     => [ 'password' => 1 ],
                'ses'      => [ 'secret_key' => 1, 'access_key' => 2 ],
                'mailgun'  => [ 'api_key' => 1 ],
                'sendgrid' => [ 'api_key' => 1 ],
                'gmail'    => [ 'client_secret' => 1, 'access_token' => 2, 'refresh_token' => 2 ],
                'outlook'  => [ 'client_secret' => 1, 'access_token' => 2, 'refresh_token' => 2 ],
            ];
        }
    }
}

namespace {
    if (! function_exists('fluentMailEncryptDecrypt')) {
        function fluentMailEncryptDecrypt($value, $mode = 'e')
        {
            return 'e' === $mode ? 'enc::' . strrev(base64_encode((string) $value)) : base64_decode(strrev(substr((string) $value, 5)));
        }
    }

    if (! function_exists('fluentMailGetSettings')) {
        function fluentMailGetSettings($defaults = [], $cached = true)
        {
            $settings = get_option('fluentmail-settings');
            if (! $settings) {
                return $defaults;
            }
            if (! empty($settings['use_encrypt'])) {
                $stored = isset($settings['encrypt_version']) ? (int) $settings['encrypt_version'] : 1;
                foreach ((array) ($settings['connections'] ?? []) as $key => $connection) {
                    $provider = $connection['provider_settings']['provider'];
                    foreach (\FluentMail\App\Services\SecretMasker::ENCRYPTED_FIELDS[ $provider ] ?? [] as $field => $since) {
                        if ($since > $stored || empty($connection['provider_settings'][ $field ])) {
                            continue;
                        }
                        $settings['connections'][ $key ]['provider_settings'][ $field ] = fluentMailEncryptDecrypt($connection['provider_settings'][ $field ], 'd');
                    }
                }
            }
            return $settings;
        }
    }

    if (! function_exists('fluentMailSetSettings')) {
        function fluentMailSetSettings($settings)
        {
            $has = false;
            foreach ((array) ($settings['connections'] ?? []) as $key => $connection) {
                $provider = $connection['provider_settings']['provider'];
                foreach (array_keys(\FluentMail\App\Services\SecretMasker::ENCRYPTED_FIELDS[ $provider ] ?? []) as $field) {
                    if (empty($connection['provider_settings'][ $field ])) {
                        continue;
                    }
                    $has = true;
                    $settings['connections'][ $key ]['provider_settings'][ $field ] = fluentMailEncryptDecrypt($connection['provider_settings'][ $field ], 'e');
                }
            }
            $settings['encrypt_version'] = \FluentMail\App\Services\SecretMasker::ENCRYPT_VERSION;
            $settings['use_encrypt']     = $has ? 'yes' : '';
            $settings['test']            = $has ? fluentMailEncryptDecrypt('test', 'e') : '';
            return update_option('fluentmail-settings', $settings);
        }
    }
}
