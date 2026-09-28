<?php

namespace WPMCP\Tests\Free\Crypto;

use WPMCP\Crypto\Secret_Box;

/**
 * Issue #141: the one sealing primitive behind the cloud credential vault and
 * the stock-provider key store.
 */
class SecretBoxTest extends \WP_UnitTestCase
{
    public function test_a_sealed_value_opens_under_its_own_label_only(): void
    {
        $blob = (string) Secret_Box::seal('s3cret', 'label-a');

        $this->assertNotSame('', $blob);
        $this->assertStringNotContainsString('s3cret', $blob);
        $this->assertStringNotContainsString('s3cret', (string) base64_decode($blob, true));
        $this->assertSame('s3cret', Secret_Box::open($blob, 'label-a'));
        $this->assertNull(Secret_Box::open($blob, 'label-b'), 'a label is a domain separator: one store must not open another store\'s blob');
    }

    public function test_every_seal_uses_a_fresh_nonce(): void
    {
        $this->assertNotSame(Secret_Box::seal('same', 'label-a'), Secret_Box::seal('same', 'label-a'));
    }

    public function test_tampered_truncated_and_garbage_blobs_do_not_open(): void
    {
        $raw = (string) base64_decode((string) Secret_Box::seal('s3cret', 'label-a'), true);
        $raw[ strlen($raw) - 1 ] = chr(ord($raw[ strlen($raw) - 1 ]) ^ 1);

        $this->assertNull(Secret_Box::open(base64_encode($raw), 'label-a'));
        $this->assertNull(Secret_Box::open(base64_encode(substr($raw, 0, 30)), 'label-a'));
        $this->assertNull(Secret_Box::open('not base64 at all!', 'label-a'));
        $this->assertNull(Secret_Box::open('', 'label-a'));
    }

    public function test_a_rotated_auth_salt_stops_old_blobs_opening(): void
    {
        $blob    = (string) Secret_Box::seal('s3cret', 'label-a');
        $rotated = static fn () => 'a-freshly-generated-auth-salt';
        add_filter('salt', $rotated);
        try {
            $this->assertNull(Secret_Box::open($blob, 'label-a'));
        } finally {
            remove_filter('salt', $rotated);
        }
        $this->assertSame('s3cret', Secret_Box::open($blob, 'label-a'));
    }

    public function test_the_stock_key_store_derivation_is_unchanged(): void
    {
        // Keys sealed before the extraction must keep opening: the historic
        // Stock_Key_Store key was BLAKE2b-256 over 'wpmcp-stock-keys|' . salt.
        $legacy_key = sodium_crypto_generichash('wpmcp-stock-keys|' . wp_salt('auth'), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $this->assertSame($legacy_key, Secret_Box::key('wpmcp-stock-keys'));
    }
}
