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
 *
 * confirm=true is required, for the same reason delete-post and
 * delete-redirect require it: the write is irrecoverable. Nothing on the
 * site can reissue the killed secret, and the gateway stops working the
 * instant this runs, so an agent must not be able to trip it while
 * exploring. Gateway_Credential::revoke() re-checks manage_options rather
 * than trusting this layer.
 *
 * There is a second, licence-independent way to run the same kill:
 * `wp wpmcp gateway-revoke`. This ability is pro-tier and disappears with a
 * lapsed licence, and a ten-year credential whose only off switch expires
 * with the subscription would not be an off switch.
 */
class Gateway_Revoke
{
    public function handle(array $args)
    {
        if (empty($args['confirm'])) {
            return new \WP_Error(
                'gateway_confirm_required',
                'Revoking the gateway credential is irreversible: the secret cannot be reissued and the gateway stops working immediately. Pass confirm=true to proceed.'
            );
        }

        $was    = Gateway_Credential::is_provisioned();
        $killed = Gateway_Credential::revoke();

        if (is_wp_error($killed)) {
            return $killed;
        }

        return [
            'was_provisioned' => $was,
            // How many token records the switch actually removed, which is
            // not the same question as whether bookkeeping existed: a
            // credential whose client row was reaped still has live tokens.
            'killed'          => (int) $killed,
        ];
    }
}
