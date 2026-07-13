<?php

namespace WPMCP\Auth;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * RFC 7636 Proof Key for Code Exchange, S256-only. This plugin implements
 * OAuth 2.1, which makes PKCE mandatory for authorization-code clients and
 * drops the 'plain' transformation entirely; verify() enforces both: a
 * request whose code_challenge_method is anything other than the literal
 * 'S256' is rejected outright, even if verifier === challenge (which is what
 * 'plain' would otherwise accept).
 */
class PKCE
{
    /**
     * True iff $method is exactly 'S256' and BASE64URL-ENCODE(SHA256($verifier))
     * (RFC 7636 section 4.6), with no trailing '=' padding, equals $challenge.
     * hash_equals() guards the comparison against timing attacks.
     */
    public static function verify(string $verifier, string $challenge, string $method): bool
    {
        if ('S256' !== $method) {
            return false;
        }
        if ('' === $verifier || '' === $challenge) {
            return false;
        }

        $computed = self::challenge_from_verifier($verifier);

        return hash_equals($challenge, $computed);
    }

    /** BASE64URL-ENCODE(SHA256($verifier)) with '=' padding stripped. */
    public static function challenge_from_verifier(string $verifier): string
    {
        $hash = hash('sha256', $verifier, true);

        return rtrim(strtr(base64_encode($hash), '+/', '-_'), '=');
    }
}
