<?php

namespace WPMCP\Tools\Cloud;

use WPMCP\Cloud\Gateway_Credential;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Report whether the site-local gateway credential is provisioned (issue
 * #142). Read-only, and deliberately reports the client_id at most; token
 * material never appears here.
 */
class Gateway_Status
{
    public function handle(array $args): array
    {
        $client = Gateway_Credential::current_client();

        return [
            'provisioned' => null !== $client,
            'client_id'   => null !== $client ? (string) $client['client_id'] : null,
        ];
    }
}
