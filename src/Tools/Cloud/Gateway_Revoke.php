<?php

namespace WPMCP\Tools\Cloud;

use WPMCP\Cloud\Gateway_Credential;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Kill this site's gateway credential. Purely local and therefore
 * offline-proof: the refresh chain and every access token issued along it
 * are gone when this returns, whether or not the cloud can be reached.
 */
class Gateway_Revoke
{
    public function handle(array $args): array
    {
        $was = Gateway_Credential::is_provisioned();
        Gateway_Credential::revoke();

        return [
            'revoked'          => $was,
            'was_provisioned'  => $was,
        ];
    }
}
