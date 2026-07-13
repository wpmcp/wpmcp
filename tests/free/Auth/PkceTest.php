<?php

namespace WPMCP\Tests\Free\Auth;

use WPMCP\Auth\PKCE;

/**
 * PKCE::verify() is the single place that decides whether a presented
 * code_verifier satisfies a stored code_challenge. OAuth 2.1 mandates PKCE
 * with S256 for every authorization-code client; this plugin goes further
 * and refuses "plain" entirely (only 'S256' is ever accepted as a method),
 * per issue #43's hard security constraint.
 */
class PkceTest extends \WP_UnitTestCase
{
    /** RFC 7636 Appendix B's worked example: verifier -> S256 challenge. */
    private const VERIFIER  = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
    private const CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

    public function test_matching_s256_verifier_is_accepted(): void
    {
        $this->assertTrue(PKCE::verify(self::VERIFIER, self::CHALLENGE, 'S256'));
    }

    public function test_wrong_verifier_is_rejected(): void
    {
        $this->assertFalse(PKCE::verify('not-the-right-verifier', self::CHALLENGE, 'S256'));
    }

    public function test_plain_method_is_always_rejected(): void
    {
        // Even when verifier === challenge (what "plain" would accept), the
        // plugin must never honor the plain method.
        $this->assertFalse(PKCE::verify(self::CHALLENGE, self::CHALLENGE, 'plain'));
    }

    public function test_missing_method_is_rejected(): void
    {
        $this->assertFalse(PKCE::verify(self::VERIFIER, self::CHALLENGE, ''));
    }

    public function test_empty_verifier_is_rejected(): void
    {
        $this->assertFalse(PKCE::verify('', self::CHALLENGE, 'S256'));
    }

    public function test_empty_challenge_is_rejected(): void
    {
        $this->assertFalse(PKCE::verify(self::VERIFIER, '', 'S256'));
    }
}
