<?php

namespace WPMCP\Pro\Chat;

use WPMCP\Identity\Identity_Store;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The scoped identity every chat-originated tool call runs under (issue #73).
 *
 * The chat is just another MCP client, so it gets what any named client gets:
 * an entry in the scoped-identity store that governance narrows against
 * (Governance::is_within_identity_scope()) and a name that lands in the
 * governance audit log next to every decision, so chat traffic is
 * distinguishable from external MCP traffic.
 *
 * The identity record is seeded on first use with no restriction, which is
 * exactly what "no identity" would mean, so seeding widens nothing. An admin
 * narrows the chat by editing this identity with the same tools and screens
 * used for any other identity (for example operations: ["read"] turns the
 * chat into a read-only assistant). Seeding matters because an identity name
 * without a stored record is denied everything, which is the right answer
 * for an unknown external identity and the wrong one for the chat.
 */
final class Chat_Identity
{
    public const NAME = 'wpmcp-chat';

    /** Creates the chat identity record if it does not exist yet. */
    public static function ensure(): void
    {
        if (null === Identity_Store::get(self::NAME)) {
            Identity_Store::create(self::NAME, []);
        }
    }

    /**
     * Runs $fn with the chat identity active for the duration of the call.
     *
     * Registered at PHP_INT_MAX so nothing else resolving an identity later
     * in the chain can relabel a chat call, and removed in a finally block so
     * the identity never leaks past the call it was set for.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public static function run(callable $fn)
    {
        self::ensure();

        $filter = static fn () => self::NAME;
        add_filter('wpmcp_current_identity', $filter, PHP_INT_MAX);
        try {
            return $fn();
        } finally {
            remove_filter('wpmcp_current_identity', $filter, PHP_INT_MAX);
        }
    }
}
