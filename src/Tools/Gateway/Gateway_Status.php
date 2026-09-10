<?php

namespace WPMCP\Tools\Gateway;

use WPMCP\Auth\OAuth_Config;
use WPMCP\Gateway\Gateway_Credential;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Report whether the site-local gateway credential is provisioned (issue
 * #142). Read-only, and deliberately reports the client_id at most; token
 * material and the client secret never appear here.
 *
 * Reports oauth_enabled alongside it because "provisioned" on its own is
 * misleading: with OAuth_Config::is_enabled() false there is no token
 * endpoint and Bearer_Auth accepts nothing, so a provisioned credential
 * is inert. Two fields, so an operator can see which of the two states
 * they are in without guessing.
 */
class Gateway_Status
{
    public function handle(array $args): array
    {
        $client = Gateway_Credential::current_client();

        return [
            'provisioned'   => null !== $client,
            'client_id'     => null !== $client ? (string) $client['client_id'] : null,
            'oauth_enabled' => OAuth_Config::is_enabled(),
            'usable'        => null !== $client && OAuth_Config::is_enabled(),
        ];
    }
}
