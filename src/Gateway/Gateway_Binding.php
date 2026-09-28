<?php

namespace WPMCP\Gateway;

use WPMCP\Auth\Client_Store;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Ties the site's one gateway credential (Gateway_Credential, #142) to a
 * named scoped Identity (issue #130).
 *
 * The credential itself stays #142's: one protected gateway client, one
 * set of token rules. This class only records WHICH identity the current
 * credential acts as, so Gateway_Guard can narrow every gateway call to
 * that identity's allowlist through Registrar::is_permitted().
 *
 * A binding belongs to exactly one issuance of the credential. It stores a
 * tag derived from the client's current secret hash, and Gateway_Credential
 * re-mints the secret on every issue_for_user(), so a credential that was
 * re-provisioned by any other path (the free gateway-provision tool, which
 * mints an unscoped credential and says so) no longer matches the binding
 * and is treated as that unscoped credential, never as the identity an
 * older credential was bound to. The tag is a digest of a hash of a
 * random secret, not secret material.
 *
 * Record: { client_id, secret_tag, identity, user_id, generation,
 * provisioned_at, uploaded_at }. No plaintext, ever. `generation` is a
 * random, non-secret stamp that lets an upload prove it carries the
 * credential this binding describes.
 */
class Gateway_Binding
{
    public const OPTION = 'wpmcp_gateway_binding';

    /**
     * Bind the credential Gateway_Credential currently holds to $identity.
     * Call straight after Gateway_Credential::issue_for_user().
     *
     * @return array The stored record.
     * @throws \RuntimeException When no gateway client is provisioned.
     */
    public static function bind(string $identity, int $user_id): array
    {
        $client = Gateway_Credential::current_client();
        if (null === $client) {
            throw new \RuntimeException('There is no gateway credential to bind.');
        }

        $record = [
            'client_id'      => (string) $client['client_id'],
            'secret_tag'     => self::secret_tag($client),
            'identity'       => $identity,
            'user_id'        => $user_id,
            'generation'     => bin2hex(random_bytes(16)),
            'provisioned_at' => time(),
            'uploaded_at'    => 0,
        ];
        update_option(self::OPTION, $record, false);

        return $record;
    }

    /** The stored record exactly as written, with no liveness judgement. */
    public static function raw(): ?array
    {
        $record = get_option(self::OPTION, null);

        return is_array($record) && ! empty($record['client_id']) ? $record : null;
    }

    /**
     * The binding, when it still describes the live credential: the client
     * row exists, is the protected gateway client, and holds the secret this
     * binding was made for. Null otherwise.
     */
    public static function current(): ?array
    {
        $record = self::raw();
        if (null === $record) {
            return null;
        }

        $client_id = (string) $record['client_id'];
        $client    = Client_Store::get($client_id);
        if (null === $client || ! Client_Store::is_protected($client_id)) {
            return null;
        }

        return hash_equals((string) ($record['secret_tag'] ?? ''), self::secret_tag($client)) ? $record : null;
    }

    /**
     * The identity a validated token from $client_id acts as, or null when
     * the credential is not identity-bound.
     */
    public static function identity_for_client(string $client_id): ?string
    {
        $record = self::current();
        if (null === $record || (string) $record['client_id'] !== $client_id) {
            return null;
        }

        $identity = (string) ($record['identity'] ?? '');

        return '' !== $identity ? $identity : null;
    }

    /** Stamp the live binding as uploaded to the cloud. */
    public static function mark_uploaded(): void
    {
        $record = self::current();
        if (null === $record) {
            return;
        }

        $record['uploaded_at'] = time();
        update_option(self::OPTION, $record, false);
    }

    /** Whether the cloud holds a copy of the live credential. */
    public static function was_uploaded(): bool
    {
        $record = self::current();

        return null !== $record && (int) ($record['uploaded_at'] ?? 0) > 0;
    }

    public static function clear(): void
    {
        delete_option(self::OPTION);
    }

    private static function secret_tag(array $client): string
    {
        return hash('sha256', 'wpmcp-gateway-binding|' . (string) ($client['client_secret_hash'] ?? ''));
    }
}
