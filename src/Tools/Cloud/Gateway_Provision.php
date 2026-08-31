<?php

namespace WPMCP\Tools\Cloud;

use WPMCP\Cloud\Cloud_Client;
use WPMCP\Cloud\Gateway_Credential;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Provision this site's WP MCP Gateway credential (issue #130) and, unless
 * asked not to, upload it to the connected cloud in the same call.
 *
 * The plaintext material is returned exactly once, here. Nothing on the site
 * can print it again, which is the point: after this response the site holds
 * only hashes.
 *
 * 'consent' is required and defaults to false. It is not decoration: the
 * credential lets the gateway act on this site as an administrator for
 * years, so provisioning is refused outright until the caller states, in the
 * call itself, that the site owner agreed to that. Gateway_Credential
 * re-checks it rather than trusting this layer.
 *
 * 'replace' is the second gate and also defaults to false: re-provisioning
 * destroys a live credential irreversibly, which is the same class of write
 * as delete-post, so it is refused unless the caller says so explicitly.
 */
class Gateway_Provision
{
    public function handle(array $args)
    {
        $identity = isset($args['identity']) ? (string) $args['identity'] : '';
        $consent  = ! empty($args['consent']);
        $replace  = ! empty($args['replace']);
        $upload   = ! isset($args['upload']) || ! empty($args['upload']);

        $credential = Gateway_Credential::provision(get_current_user_id(), $identity, $consent, $replace);
        if (is_wp_error($credential)) {
            return $credential;
        }

        // 'skipped' and 'failed' are different outcomes and a bare boolean
        // plus an empty warning string cannot tell them apart, which leaves
        // the caller unable to know whether the cloud has the credential.
        $status  = 'skipped';
        $warning = '';
        if ($upload) {
            $result = Gateway_Credential::upload(new Cloud_Client(), $credential);
            if (is_wp_error($result)) {
                // The credential is already live locally; a failed upload is
                // reported, not fatal, so the operator still gets the
                // once-only plaintext and can hand it over another way.
                $status  = 'failed';
                $warning = $result->get_error_message();
            } else {
                $status = 'ok';
            }
        }

        return [
            'provisioned'   => true,
            'uploaded'      => 'ok' === $status,
            'upload_status' => $status,
            'warning'       => $warning,
            'identity'      => $identity,
            'client_id'     => $credential['client_id'],
            'client_secret' => $credential['client_secret'],
            'refresh_token' => $credential['refresh_token'],
            'scope'         => $credential['scope'],
            'notice'        => 'Store the client secret and refresh token now: they are shown exactly once and cannot be recovered.',
        ];
    }
}
