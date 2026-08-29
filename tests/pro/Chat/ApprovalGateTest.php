<?php

namespace WPMCP\Tests\Pro\Chat;

use WPMCP\Pro\Chat\Approval_Gate;

class ApprovalGateTest extends \WP_UnitTestCase
{
    private Approval_Gate $gate;
    private int $user_id;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gate = new Approval_Gate('test_approval_salt_secret_123');
        $this->user_id = self::factory()->user->create(['role' => 'administrator']);
    }

    public function test_issue_and_validate_token_success(): void
    {
        $ability = 'core_options_update';
        $args = ['option' => 'blogname', 'value' => 'New Title'];

        $token = $this->gate->issue_token($this->user_id, $ability, $args, 300);
        $this->assertNotEmpty($token);

        $consumed = $this->gate->validate_and_consume($token, $this->user_id, $ability, $args);
        $this->assertTrue($consumed);

        // Atomic replay prevention: second consumption fails
        $replayed = $this->gate->validate_and_consume($token, $this->user_id, $ability, $args);
        $this->assertFalse($replayed);
    }

    public function test_forged_signature_rejected(): void
    {
        $ability = 'core_posts_delete';
        $args = ['post_id' => 42];

        $token = $this->gate->issue_token($this->user_id, $ability, $args, 300);
        $raw = base64_decode($token);
        $parts = explode('|', $raw);
        // Replace signature with a forged 64-char hex string
        $parts[4] = str_repeat('a', 64);
        $tampered_token = base64_encode(implode('|', $parts));

        $this->assertFalse($this->gate->validate_and_consume($tampered_token, $this->user_id, $ability, $args));
    }

    public function test_truncated_signature_rejected(): void
    {
        $ability = 'core_posts_delete';
        $args = ['post_id' => 42];

        $token = $this->gate->issue_token($this->user_id, $ability, $args, 300);
        $raw = base64_decode($token);
        $parts = explode('|', $raw);
        // Truncate signature
        $parts[4] = substr($parts[4], 0, 16);
        $tampered_token = base64_encode(implode('|', $parts));

        $this->assertFalse($this->gate->validate_and_consume($tampered_token, $this->user_id, $ability, $args));
    }

    public function test_different_salt_signature_rejected(): void
    {
        $ability = 'core_posts_delete';
        $args = ['post_id' => 42];

        $gate_a = new Approval_Gate('salt_alpha');
        $gate_b = new Approval_Gate('salt_beta');

        $token = $gate_a->issue_token($this->user_id, $ability, $args, 300);
        $this->assertFalse($gate_b->validate_and_consume($token, $this->user_id, $ability, $args));
    }

    public function test_extended_expiry_body_rejected(): void
    {
        $ability = 'core_posts_delete';
        $args = ['post_id' => 42];

        $token = $this->gate->issue_token($this->user_id, $ability, $args, 300);
        $raw = base64_decode($token);
        $parts = explode('|', $raw);
        // Attacker extends expiry in token body to year 2099
        $parts[3] = (string) (time() + 9999999);
        $tampered_token = base64_encode(implode('|', $parts));

        $this->assertFalse($this->gate->validate_and_consume($tampered_token, $this->user_id, $ability, $args));
    }

    public function test_pipe_in_ability_name_supported(): void
    {
        $ability = 'custom|namespace|tool_delete';
        $args = ['target' => 'item_1'];

        $token = $this->gate->issue_token($this->user_id, $ability, $args, 300);
        $this->assertTrue($this->gate->validate_and_consume($token, $this->user_id, $ability, $args));
    }

    public function test_nested_reordered_arguments_pass(): void
    {
        $ability = 'custom_complex_mutation';
        $args_a = [
            'meta' => ['b' => 2, 'a' => 1],
            'tags' => ['beta', 'alpha'],
        ];
        $args_b = [
            'tags' => ['beta', 'alpha'],
            'meta' => ['a' => 1, 'b' => 2],
        ];

        $token = $this->gate->issue_token($this->user_id, $ability, $args_a, 300);
        // Consuming with differently-ordered nested keys evaluates to identical canonical hash
        $this->assertTrue($this->gate->validate_and_consume($token, $this->user_id, $ability, $args_b));
    }

    public function test_tampered_argument_rejected(): void
    {
        $ability = 'core_options_update';
        $token = $this->gate->issue_token($this->user_id, $ability, ['option' => 'blogname', 'value' => 'New Title'], 300);

        // Attempting to consume with different argument value
        $tampered = $this->gate->validate_and_consume(
            $token,
            $this->user_id,
            $ability,
            ['option' => 'blogname', 'value' => 'Hacked Title']
        );
        $this->assertFalse($tampered);
    }

    public function test_wrong_user_rejected(): void
    {
        $other_user = self::factory()->user->create(['role' => 'administrator']);
        $ability = 'core_posts_delete';
        $args = ['post_id' => 10];

        $token = $this->gate->issue_token($this->user_id, $ability, $args, 300);
        $this->assertFalse($this->gate->validate_and_consume($token, $other_user, $ability, $args));
    }

    public function test_wrong_ability_rejected(): void
    {
        $token = $this->gate->issue_token($this->user_id, 'core_posts_delete', ['post_id' => 10], 300);
        $this->assertFalse($this->gate->validate_and_consume($token, $this->user_id, 'core_users_delete', ['post_id' => 10]));
    }

    public function test_missing_transient_rejected(): void
    {
        $ability = 'core_posts_delete';
        $args = ['post_id' => 10];
        $token = $this->gate->issue_token($this->user_id, $ability, $args, 300);

        // Manually delete transient before consumption
        $token_hash = hash('sha256', $token);
        delete_transient('wpmcp_chat_appr_' . $token_hash);

        $this->assertFalse($this->gate->validate_and_consume($token, $this->user_id, $ability, $args));
    }
}
