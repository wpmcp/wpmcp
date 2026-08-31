<?php

namespace WPMCP\Tools\Cloud;

use WPMCP\Cloud\Gateway_Credential;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Report the gateway credential's bookkeeping. Never returns secrets: the
 * record does not hold any, and the plaintext existed only in the
 * gateway-provision response.
 *
 * This is one of the two write paths that own Gateway_Credential::prune():
 * bookkeeping naming a client row that Oauth_Gc has since reaped is cleared
 * here, so the cleanup happens on a deliberate maintenance call rather than
 * as a side effect of every permission check.
 */
class Gateway_Status
{
    public function handle(array $args): array
    {
        Gateway_Credential::prune();
        $record = Gateway_Credential::record();

        return [
            'provisioned'    => null !== $record,
            'client_id'      => (string) ($record['client_id'] ?? ''),
            'identity'       => (string) ($record['identity'] ?? ''),
            'user_id'        => (int) ($record['user_id'] ?? 0),
            'provisioned_at' => (int) ($record['provisioned_at'] ?? 0),
            'uploaded_at'    => (int) ($record['uploaded_at'] ?? 0),
        ];
    }
}
