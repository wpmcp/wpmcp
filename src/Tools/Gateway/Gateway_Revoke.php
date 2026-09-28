<?php

namespace WPMCP\Tools\Gateway;

use WPMCP\Gateway\Gateway_Credential;
use WPMCP\Safety\Mutation_Failed;
use WPMCP\Safety\Safe_Mutation;

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
 * Runs through Safe_Mutation like every mutating ability. The snapshot is
 * only the gateway bookkeeping pointer, so rolling this operation back
 * cannot resurrect the client or its tokens; see Gateway_Provision's
 * docblock for why that is the intended shape.
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

        try {
            $out = Safe_Mutation::run(
                [
                    'object_type' => 'option',
                    'object_id'   => Gateway_Credential::OPTION,
                    'session_id'  => (string) ($args['session_id'] ?? 'default'),
                    'tool_name'   => 'gateway-revoke',
                    'args'        => ['confirm' => true],
                ],
                static fn (): bool => Gateway_Credential::deprovision()
            );
        } catch (Mutation_Failed $e) {
            // Safe_Mutation throws this only when the undo point could not be
            // written, BEFORE the mutation runs (there is no verify step
            // here). Everywhere else that correctly means "do not write".
            // Here it would mean "a leaked credential stays live because the
            // snapshot table is unwritable", to protect an undo point that is
            // inert by design. The kill switch wins, and the response says
            // plainly that no undo point exists for this call.
            $out = [
                'operation_id' => null,
                'result'       => Gateway_Credential::deprovision(),
                'undo_point'   => 'none',
            ];
        }
        $removed = (bool) $out['result'];

        return [
            'operation_id' => $out['operation_id'],
            // Never a restore point for the credential itself: at most the
            // bookkeeping pointer was snapshotted, and restoring it cannot
            // bring the client or its tokens back. Reported as such so an
            // agent does not offer "undo" as a way to un-revoke.
            'undo_point'   => $out['undo_point'] ?? 'pointer_only',
            'revoked' => $removed,
            // Re-evaluated, not assumed: the only honest way to report the
            // end state when a store can hold more than one matching row.
            'provisioned' => Gateway_Credential::is_provisioned(),
        ];
    }
}
