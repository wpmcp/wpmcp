<?php

namespace WPMCP\Pro\Chat;

if (! defined('ABSPATH')) {
    exit;
}

class Key_Vault
{
    private const CIPHER = 'aes-256-gcm';
    private const PREFIX = 'wpmcp_v1:';
    private const META_KEY = '_wpmcp_chat_anthropic_key';

    public function __construct(private ?string $salt = null)
    {
        if (! in_array(self::CIPHER, openssl_get_cipher_methods(), true)) {
            throw new \RuntimeException(sprintf('Required cipher %s is not supported by OpenSSL.', self::CIPHER));
        }
    }

    private function get_base_salt(): string
    {
        return $this->salt ?? (function_exists('wp_salt') ? wp_salt('auth') : 'wpmcp_default_fallback_salt');
    }

    private function get_salt_fingerprint(): string
    {
        return substr(hash('sha256', $this->get_base_salt()), 0, 8);
    }

    private function get_encryption_key(int $user_id): string
    {
        return hash_hmac('sha256', (string) $user_id, $this->get_base_salt(), true);
    }

    /**
     * Stores an encrypted API key for the user.
     */
    public function store_key(int $user_id, string $api_key): bool
    {
        $api_key = trim($api_key);
        if ('' === $api_key) {
            $this->delete_key($user_id);
            return true; // Postcondition (no stored key) holds.
        }

        $key = $this->get_encryption_key($user_id);
        $iv_len = openssl_cipher_iv_length(self::CIPHER);
        if (false === $iv_len || $iv_len < 1) {
            return false;
        }

        $iv = random_bytes($iv_len);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $api_key,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16
        );

        if (false === $ciphertext) {
            return false;
        }

        $salt_fp = $this->get_salt_fingerprint();
        $packed = self::PREFIX . $salt_fp . ':' . base64_encode($iv . $tag . $ciphertext);
        return (bool) update_user_meta($user_id, self::META_KEY, $packed);
    }

    /**
     * Retrieves and decrypts the API key for the user.
     *
     * @throws Key_Vault_Corrupted_Exception on bit-flip tampering, authentication tag mismatch, or corrupted format.
     */
    public function get_key(int $user_id): ?string
    {
        $raw = get_user_meta($user_id, self::META_KEY, true);
        if (! is_string($raw) || '' === $raw) {
            return null;
        }

        if (! str_starts_with($raw, self::PREFIX)) {
            throw new Key_Vault_Corrupted_Exception('Ciphertext prefix mismatch or corrupted storage format.');
        }

        $body = substr($raw, strlen(self::PREFIX));
        $colon_pos = strpos($body, ':');
        if (false === $colon_pos) {
            // Legacy v1 without salt fingerprint: entire body is base64
            $salt_fp = '';
            $encoded = $body;
        } else {
            $salt_fp = substr($body, 0, $colon_pos);
            $encoded = substr($body, $colon_pos + 1);
        }

        $decoded = base64_decode($encoded, true);
        if (false === $decoded) {
            throw new Key_Vault_Corrupted_Exception('Base64 decode failure.');
        }

        $iv_len = openssl_cipher_iv_length(self::CIPHER);
        $tag_len = 16;
        if (false === $iv_len || strlen($decoded) < $iv_len + $tag_len + 1) {
            throw new Key_Vault_Corrupted_Exception('Truncated ciphertext payload.');
        }

        $iv = substr($decoded, 0, $iv_len);
        $tag = substr($decoded, $iv_len, $tag_len);
        $ciphertext = substr($decoded, $iv_len + $tag_len);
        $key = $this->get_encryption_key($user_id);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if (false === $plaintext) {
            throw new Key_Vault_Corrupted_Exception('Authentication tag mismatch or bit-flip tampering detected.');
        }

        return $plaintext;
    }

    /**
     * Deletes the user API key.
     */
    public function delete_key(int $user_id): bool
    {
        return (bool) delete_user_meta($user_id, self::META_KEY);
    }

    /**
     * Returns masked key status without revealing plaintext.
     * Distinguishes missing, valid, salt_rotated, and corrupted states.
     */
    public function get_status(int $user_id): array
    {
        $raw = get_user_meta($user_id, self::META_KEY, true);
        if (! is_string($raw) || '' === $raw) {
            return [
                'configured' => false,
                'status' => 'missing',
                'masked' => null,
            ];
        }

        // Check if salt fingerprint indicates rotation before attempting decrypt
        if (str_starts_with($raw, self::PREFIX)) {
            $body = substr($raw, strlen(self::PREFIX));
            $colon_pos = strpos($body, ':');
            if (false !== $colon_pos) {
                $stored_fp = substr($body, 0, $colon_pos);
                if ('' !== $stored_fp && ! hash_equals($stored_fp, $this->get_salt_fingerprint())) {
                    return [
                        'configured' => true,
                        'status' => 'salt_rotated',
                        'masked' => null,
                    ];
                }
            }
        }

        try {
            $key = $this->get_key($user_id);
            if (null === $key) {
                return [
                    'configured' => false,
                    'status' => 'missing',
                    'masked' => null,
                ];
            }

            $masked = strlen($key) >= 4
                ? '...' . substr($key, -4)
                : '****';

            return [
                'configured' => true,
                'status' => 'valid',
                'masked' => $masked,
            ];
        } catch (Key_Vault_Corrupted_Exception) {
            return [
                'configured' => true,
                'status' => 'corrupted',
                'masked' => null,
            ];
        }
    }
}
