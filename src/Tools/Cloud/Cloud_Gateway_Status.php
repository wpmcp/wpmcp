<?php

namespace WPMCP\Tools\Cloud;

use WPMCP\Cloud\Gateway_Consent;
use WPMCP\Gateway\Gateway_Binding;
use WPMCP\Gateway\Gateway_Credential;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * cloud-gateway-status (issue #130): the cloud side of the gateway
 * credential. Which identity the live credential is bound to, whether the
 * cloud holds it, and whether the site owner consented. Never secrets: the
 * binding holds none, and the plaintext existed only in the provision
 * response. The credential itself is reported by the free gateway-status.
 */
class Cloud_Gateway_Status
{
    public function handle(array $args): array
    {
        $binding = Gateway_Binding::current();

        return [
            'provisioned'    => Gateway_Credential::is_provisioned(),
            'identity_bound' => null !== $binding && '' !== (string) ($binding['identity'] ?? ''),
            'identity'       => (string) ($binding['identity'] ?? ''),
            'user_id'        => (int) ($binding['user_id'] ?? 0),
            'provisioned_at' => (int) ($binding['provisioned_at'] ?? 0),
            'uploaded_at'    => (int) ($binding['uploaded_at'] ?? 0),
            'cloud_consent'  => Gateway_Consent::granted(),
        ];
    }
}
