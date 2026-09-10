<?php

namespace WPMCP\Tools\Gateway;

use WPMCP\Gateway\Gateway_Credential;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Revoke the site-local gateway credential (issue #142): removes every
 * gateway client row and evicts every access and refresh token bound to
 * it. Locally-first (works with the cloud unreachable), idempotent (safe
 * to call when nothing is provisioned), and never re-provisions on the way
 * out (teardown resolves the client via a non-creating lookup).
 *
 * Requires confirm: true, like every other destructive tool in the repo:
 * this permanently kills a credential whose plaintext cannot be recovered,
 * so recovering from an accidental call means re-provisioning and
 * reconfiguring the proxy. The gate throws \InvalidArgumentException to
 * match that same convention (Delete_Post, Delete_Plugin, Delete_File).
 *
 * Deliberately NOT gated on OAuth_Config::is_enabled(), unlike
 * gateway-provision. Turning OAuth off does not delete the rows a previous
 * provision wrote, and "you cannot revoke because the subsystem is
 * disabled" is the last answer a site owner chasing a leaked credential
 * should get. Revocation must always be reachable.
 *
 * See Gateway_Provision's docblock for why credential teardown is a
 * deliberate exemption from the Safe_Mutation snapshot layer.
 */
class Gateway_Revoke
{
    public function handle(array $args)
    {
        if (true !== ($args['confirm'] ?? false)) {
            throw new \InvalidArgumentException(
                'gateway-revoke permanently kills the gateway credential and every token bound to it. Pass confirm:true to proceed.'
            );
        }

        $removed = Gateway_Credential::deprovision();

        return [
            'revoked' => $removed,
            // Re-evaluated, not assumed: the only honest way to report the
            // end state when a store can hold more than one matching row.
            'provisioned' => Gateway_Credential::is_provisioned(),
        ];
    }
}
