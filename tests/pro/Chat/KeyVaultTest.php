<?php

namespace WPMCP\Tests\Pro\Chat;

use WPMCP\Pro\Chat\Key_Vault;
use WPMCP\Pro\Chat\Key_Vault_Corrupted_Exception;

class KeyVaultTest extends \WP_UnitTestCase
{
    private Key_Vault $vault;
    private int $user_id;
    private string $test_salt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->test_salt = 'test_auth_salt_secret_123';
        $this->vault = new Key_Vault($this->test_salt);
        $this->user_id = self::factory()->user->create(['role' => 'administrator']);
    }

    public function test_round_trip_encryption(): void
    {
        $api_key = 'test-mock-anthropic-key-1234567890abcdef';
        $this->assertTrue($this->vault->store_key($this->user_id, $api_key));

        $retrieved = $this->vault->get_key($this->user_id);
        $this->assertSame($api_key, $retrieved);

        $status = $this->vault->get_status($this->user_id);
        $this->assertTrue($status['configured']);
        $this->assertSame('valid', $status['status']);
        $this->assertSame('...cdef', $status['masked']);
    }

    public function test_salt_rotation_detected_without_false_tamper(): void
    {
        $api_key = 'test-mock-anthropic-key';
        $this->vault->store_key($this->user_id, $api_key);

        // Simulate salt rotation on host
        $rotated_vault = new Key_Vault('new_rotated_auth_salt_456');
        $status = $rotated_vault->get_status($this->user_id);

        $this->assertTrue($status['configured']);
        $this->assertSame('salt_rotated', $status['status']);
        $this->assertNull($status['masked']);
    }

    public function test_store_empty_key_returns_true_when_no_prior_key(): void
    {
        // User with no key stored
        $this->assertTrue($this->vault->store_key($this->user_id, ''));
        $this->assertNull($this->vault->get_key($this->user_id));
    }

    public function test_prefix_mismatch_throws_exception(): void
    {
        update_user_meta($this->user_id, '_wpmcp_chat_anthropic_key', 'invalid_prefix_data');

        $this->expectException(Key_Vault_Corrupted_Exception::class);
        $this->expectExceptionMessage('prefix mismatch');
        $this->vault->get_key($this->user_id);
    }

    public function test_wrong_user_isolation(): void
    {
        $other_user = self::factory()->user->create(['role' => 'administrator']);
        $api_key = 'test-mock-anthropic-key-alice';
        $this->vault->store_key($this->user_id, $api_key);

        // Attempting to read Alice's ciphertext with Bob's user_id derived key
        $raw = get_user_meta($this->user_id, '_wpmcp_chat_anthropic_key', true);
        update_user_meta($other_user, '_wpmcp_chat_anthropic_key', $raw);

        $this->expectException(Key_Vault_Corrupted_Exception::class);
        $this->vault->get_key($other_user);
    }

    public function test_bit_flip_tamper_detected(): void
    {
        $api_key = 'test-mock-anthropic-key';
        $this->vault->store_key($this->user_id, $api_key);

        $raw = get_user_meta($this->user_id, '_wpmcp_chat_anthropic_key', true);
        $body = substr($raw, strlen('wpmcp_v1:'));
        $colon_pos = strpos($body, ':');
        $salt_fp = substr($body, 0, $colon_pos);
        $encoded = substr($body, $colon_pos + 1);
        $decoded = base64_decode($encoded);

        // Tamper with one byte in the ciphertext payload
        $tampered = $decoded;
        $tampered[strlen($tampered) - 1] = chr(ord($tampered[strlen($tampered) - 1]) ^ 0xFF);
        update_user_meta($this->user_id, '_wpmcp_chat_anthropic_key', 'wpmcp_v1:' . $salt_fp . ':' . base64_encode($tampered));

        $this->expectException(Key_Vault_Corrupted_Exception::class);
        $this->vault->get_key($this->user_id);
    }

    public function test_truncation_tamper_detected(): void
    {
        $api_key = 'test-mock-anthropic-key';
        $this->vault->store_key($this->user_id, $api_key);

        $body = substr((string) get_user_meta($this->user_id, '_wpmcp_chat_anthropic_key', true), strlen('wpmcp_v1:'));
        $colon_pos = strpos($body, ':');
        $salt_fp = substr($body, 0, $colon_pos);

        // Truncate payload below minimum IV + tag length
        update_user_meta($this->user_id, '_wpmcp_chat_anthropic_key', 'wpmcp_v1:' . $salt_fp . ':' . base64_encode('short'));

        $this->expectException(Key_Vault_Corrupted_Exception::class);
        $this->vault->get_key($this->user_id);
    }

    public function test_delete_key(): void
    {
        $this->vault->store_key($this->user_id, 'test-mock-key');
        $this->assertTrue($this->vault->delete_key($this->user_id));
        $this->assertNull($this->vault->get_key($this->user_id));

        $status = $this->vault->get_status($this->user_id);
        $this->assertFalse($status['configured']);
        $this->assertSame('missing', $status['status']);
    }
}
