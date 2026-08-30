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
 */
class Gateway_Status
{
    public function handle(array $args): array
    {
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
