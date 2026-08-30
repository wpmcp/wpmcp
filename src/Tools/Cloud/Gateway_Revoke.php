<?php

namespace WPMCP\Tools\Cloud;

use WPMCP\Cloud\Gateway_Credential;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Revoke the site-local gateway credential (issue #142): removes the
 * gateway client and evicts every access and refresh token bound to it.
 * Locally-first (works with the cloud unreachable), idempotent (safe to
 * call when nothing is provisioned), and never re-provisions on the way
 * out (teardown resolves the client via a non-creating lookup).
 */
class Gateway_Revoke
{
    public function handle(array $args): array
    {
        $removed = Gateway_Credential::deprovision();

        return [
            'revoked'     => $removed,
            'provisioned' => false,
        ];
    }
}
