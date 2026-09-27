<?php

namespace WPMCP\Cloud;

use WPMCP\Gateway\Gateway_Binding;
use WPMCP\Gateway\Gateway_Guard;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The site owner's consent to the hosted WP MCP Gateway (issue #130): the
 * disclosed checkbox on cloud connect, DEFAULT OFF.
 *
 * Consent is recorded by cloud-connect's `gateway_consent` input and nowhere
 * else. It gates the cloud half of the gateway only: Gateway_Cloud::upload()
 * refuses to hand a credential to WP MCP Cloud without it. Minting a
 * credential for a self-hosted proxy does not need the cloud's consent
 * (gateway-provision has its own confirm gate), and a credential the cloud
 * never received is not touched by withdrawing this one.
 *
 * The checkbox value is authoritative on every connect: leaving it unticked
 * records no consent, and withdrawing it while the cloud holds a copy of the
 * credential kills that credential locally on the spot, so "I no longer
 * consent" never leaves the gateway able to act on the site.
 */
class Gateway_Consent
{
    public const OPTION = 'wpmcp_gateway_consent';

    public static function granted(): bool
    {
        $stored = get_option(self::OPTION, []);

        return is_array($stored) && true === ($stored['granted'] ?? false);
    }

    /** @return array{granted: bool, user_id: int, at: int} */
    public static function state(): array
    {
        $stored = get_option(self::OPTION, []);
        $stored = is_array($stored) ? $stored : [];

        return [
            'granted' => true === ($stored['granted'] ?? false),
            'user_id' => (int) ($stored['user_id'] ?? 0),
            'at'      => (int) ($stored['at'] ?? 0),
        ];
    }

    /**
     * Record the checkbox. Withdrawing consent kills a credential the cloud
     * already holds (uploaded_at set), locally and offline, through the same
     * kill switch as gateway-revoke.
     *
     * @return array{granted: bool, revoked: bool} revoked = a live credential was killed.
     */
    public static function record(bool $granted, int $user_id): array
    {
        update_option(
            self::OPTION,
            [
                'granted' => $granted,
                'user_id' => $user_id,
                'at'      => time(),
            ],
            false
        );

        $revoked = false;
        if (! $granted && Gateway_Binding::was_uploaded()) {
            // Unconditional: a kill only ever removes access, and a
            // withdrawal that could be refused would leave the gateway
            // acting on a site whose owner just said no.
            $revoked = Gateway_Guard::kill('cloud_consent_withdrawn');
        }

        return [ 'granted' => $granted, 'revoked' => $revoked ];
    }
}
