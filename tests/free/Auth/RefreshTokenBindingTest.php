<?php

namespace WPMCP\Tests\Free\Auth;

use WPMCP\Auth\Refresh_Token_Store;
use WPMCP\Auth\Token_Store;

/**
 * Credential binding on refresh tokens (issue #142). A refresh token lives
 * 30 days and mints fresh access tokens for that whole window, so without
 * this a password change would not end the session it is supposed to end.
 *
 * The three properties that are easy to get wrong and are pinned here:
 *  - a bound token dies on a password change and on account deletion;
 *  - a token minted BEFORE this change is not exempt forever: it adopts
 *    the current fingerprint on its first redeem, so the upgrade does not
 *    log everyone out and does not leave a permanent hole either;
 *  - reuse detection still wins over the binding check, so the dedicated
 *    oauth/refresh-reuse alarm (#133) stays findable in the audit log in
 *    the leaked-token-then-password-change case it exists for.
 */
class RefreshTokenBindingTest extends \WP_UnitTestCase
{
    private int $clock = 2000000;

    protected function setUp(): void
    {
        parent::setUp();
        delete_option(Refresh_Token_Store::OPTION);
        delete_option(Token_Store::OPTION);
        $this->clock = 2000000;
        Refresh_Token_Store::set_clock_override(fn () => $this->clock);
        Token_Store::set_clock_override(fn () => $this->clock);
    }

    protected function tearDown(): void
    {
        Refresh_Token_Store::set_clock_override(null);
        Token_Store::set_clock_override(null);
        delete_option(Refresh_Token_Store::OPTION);
        delete_option(Token_Store::OPTION);
        parent::tearDown();
    }

    public function test_a_password_change_kills_the_refresh_token(): void
    {
        $user_id = self::factory()->user->create(['role' => 'administrator']);
        $token   = Refresh_Token_Store::issue('client_a', $user_id, 'gateway');

        wp_set_password('a-brand-new-password', $user_id);

        $this->assertSame('credential_changed', Refresh_Token_Store::redeem($token)['status']);
        $this->assertSame(
            'unknown',
            Refresh_Token_Store::redeem($token)['status'],
            'the whole chain is revoked, not just this token'
        );
    }

    public function test_deleting_the_user_kills_the_refresh_token(): void
    {
        $user_id = self::factory()->user->create(['role' => 'administrator']);
        $token   = Refresh_Token_Store::issue('client_a', $user_id, 'gateway');

        wp_delete_user($user_id);

        $this->assertSame('credential_changed', Refresh_Token_Store::redeem($token)['status']);
    }

    public function test_a_pre_142_record_adopts_the_fingerprint_on_first_redeem(): void
    {
        // The upgrade window this closes: without adoption, every token
        // minted before #142 keeps minting access tokens straight through a
        // password change for the rest of its 30 days.
        $user_id = self::factory()->user->create(['role' => 'administrator']);
        $token   = Refresh_Token_Store::issue('client_a', $user_id, 'gateway');

        $stored = get_option(Refresh_Token_Store::OPTION);
        $key    = array_key_first($stored);
        unset($stored[ $key ]['pass_fingerprint']);
        update_option(Refresh_Token_Store::OPTION, $stored);

        // The in-flight session survives the upgrade.
        $this->assertSame('ok', Refresh_Token_Store::redeem($token)['status']);

        $adopted = get_option(Refresh_Token_Store::OPTION);
        $this->assertArrayHasKey('pass_fingerprint', $adopted[ $key ]);
        $this->assertIsString($adopted[ $key ]['pass_fingerprint']);

        // ... and every password change after that point kills it.
        wp_set_password('rotated-after-the-upgrade', $user_id);
        $this->clock += 1;
        $this->assertSame('credential_changed', Refresh_Token_Store::redeem($token)['status']);
    }

    public function test_reuse_detection_wins_over_the_credential_check(): void
    {
        // A leaked token is replayed after the owner reacts by changing
        // their password. Both conditions hold; the reuse alarm is the one
        // a site owner needs to see, and Token_Grant only records the
        // oauth/refresh-reuse audit row for that status.
        $user_id = self::factory()->user->create(['role' => 'administrator']);
        $token   = Refresh_Token_Store::issue('client_a', $user_id, 'gateway');

        $this->assertSame('ok', Refresh_Token_Store::redeem($token)['status']);
        $this->clock += Refresh_Token_Store::GRACE_SECONDS + 1;
        wp_set_password('reacting-to-the-leak', $user_id);

        $this->assertSame('reuse_detected', Refresh_Token_Store::redeem($token)['status']);
    }
}
