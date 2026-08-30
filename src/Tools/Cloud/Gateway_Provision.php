<?php

namespace WPMCP\Tools\Cloud;

use WPMCP\Cloud\Gateway_Credential;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Provision (or rotate) the site-local gateway credential (issue #142).
 *
 * Requires confirm: true because the response contains the refresh token
 * plaintext, shown exactly once and never persisted, and because
 * provisioning rotates: any previously issued gateway credential stops
 * working the moment this succeeds.
 */
class Gateway_Provision
{
    public function handle(array $args)
    {
        if (true !== ($args['confirm'] ?? false)) {
            return new \WP_Error(
                'confirmation_required',
                'gateway-provision mints a credential shown exactly once and invalidates any previous one. Pass confirm: true to proceed.'
            );
        }

        $user_id = get_current_user_id();
        if ($user_id <= 0) {
            return new \WP_Error('no_user', 'gateway-provision requires an authenticated user to bind the credential to.');
        }

        $credential = Gateway_Credential::issue_for_user($user_id);

        return [
            'provisioned'   => true,
            'client_id'     => $credential['client_id'],
            'refresh_token' => $credential['refresh_token'],
            'note'          => 'Store the refresh_token now; it is shown exactly once and cannot be recovered.',
        ];
    }
}
