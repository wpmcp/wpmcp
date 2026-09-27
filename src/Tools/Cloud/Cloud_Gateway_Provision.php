<?php

namespace WPMCP\Tools\Cloud;

use WPMCP\Cloud\Cloud_Client;
use WPMCP\Cloud\Gateway_Cloud;
use WPMCP\Gateway\Gateway_Binding;
use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * cloud-gateway-provision (issue #130): mint the site's one gateway
 * credential (#142's Gateway_Credential) bound to a scoped Identity, and
 * upload it to the connected cloud unless asked not to.
 *
 * Gates, all default off: `consent` (the call states the site owner agreed
 * to a credential the gateway can act with), `replace` (a live credential
 * is killed irreversibly), and the cloud-connect gateway consent for the
 * upload itself. Plaintext is returned exactly once, here.
 *
 * Runs through Safe_Mutation like every mutating ability. As with #142's
 * gateway-provision, the snapshot is the non-secret binding option only:
 * snapshotting the client or token stores would copy secret hashes into the
 * snapshot table and a restore could resurrect killed tokens. The recovery
 * path for a credential is re-provisioning or gateway-revoke, never an undo.
 */
class Cloud_Gateway_Provision
{
    public function handle(array $args)
    {
        $identity = isset($args['identity']) ? (string) $args['identity'] : '';
        $consent  = true === ($args['consent'] ?? false);
        $replace  = true === ($args['replace'] ?? false);
        $upload   = ! isset($args['upload']) || true === $args['upload'];
        $user_id  = get_current_user_id();

        $refusal = Gateway_Cloud::refusal($user_id, $identity, $consent, $replace);
        if (null !== $refusal) {
            return $refusal;
        }

        try {
            $out = Safe_Mutation::run(
                [
                    'object_type' => 'option',
                    'object_id'   => Gateway_Binding::OPTION,
                    'session_id'  => (string) ($args['session_id'] ?? 'default'),
                    'tool_name'   => 'cloud-gateway-provision',
                    'args'        => [ 'identity' => $identity, 'replace' => $replace ],
                ],
                static fn (): array => Gateway_Cloud::mint($user_id, $identity)
            );
        } catch (\RuntimeException $e) {
            return new \WP_Error('gateway_provision_failed', 'The gateway credential could not be provisioned: ' . $e->getMessage());
        }
        $credential = $out['result'];

        // 'skipped', 'consent_required' and 'failed' are different outcomes:
        // the caller has to know whether the cloud has the credential.
        $status  = 'skipped';
        $warning = '';
        if ($upload) {
            $result = Gateway_Cloud::upload(new Cloud_Client(), $credential);
            if (is_wp_error($result)) {
                $status  = 'gateway_cloud_consent_required' === $result->get_error_code() ? 'consent_required' : 'failed';
                $warning = $result->get_error_message();
            } else {
                $status = 'ok';
            }
        }

        return [
            'operation_id'  => $out['operation_id'],
            'provisioned'   => true,
            'uploaded'      => 'ok' === $status,
            'upload_status' => $status,
            'warning'       => $warning,
            'identity'      => $credential['identity'],
            'client_id'     => $credential['client_id'],
            'client_secret' => $credential['client_secret'],
            'refresh_token' => $credential['refresh_token'],
            'scope'         => $credential['scope'],
            'notice'        => 'Store the client secret and refresh token now: they are shown exactly once and cannot be recovered. The credential works only on this site\'s MCP connection, as the identity above.',
        ];
    }
}
