<?php

namespace WPMCP\Tools\WooCommerce\Catalog;

use WPMCP\Governance\Governance;
use WPMCP\Governance\Governance_Audit_Log;
use WPMCP\Identity\Identity_Context;
use WPMCP\MCP\Ability;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Gates shared by woo-read and woo-write (issue #68), mirroring the guard
 * chain Integration_Dispatcher runs for its dispatcher pairs. That class
 * cannot be reused directly: the vertical wpmcp-for-woocommerce build prunes
 * src/Integrations while still registering the woocommerce group.
 *
 * The platform gates (the dispatcher ability's own capability, Governance,
 * identity scope, tier) have already run through Registrar::is_permitted()
 * before either dispatcher executes. What this adds is op granularity:
 *  - governance per op, by evaluating a synthetic Ability named
 *    "wpmcp/woo-{op}" (dots become dashes, e.g. wpmcp/woo-products-delete)
 *    through the full six-layer model, so a stored toggle or filter can
 *    switch off one op, or every op of one operation kind (the 'delete'
 *    operation toggle turns off every destructive op), without touching the
 *    pair. Identity scope is deliberately not re-evaluated against the
 *    synthetic names, for the reason Integration_Dispatcher gives: an
 *    identity scoped to wpmcp/woo-write must keep working;
 *  - the per-op capability each catalog row carries;
 *  - WooCommerce availability, and the taxonomy a row names (brand ops need
 *    product_brand, which older WooCommerce releases do not register).
 */
final class Op_Guard
{
    public const DOMAIN = 'woocommerce';

    /** Whether the host plugin is loaded, mirroring Integration_Dispatcher. */
    public static function is_available(): bool
    {
        return class_exists('WooCommerce');
    }

    /**
     * Whether the taxonomy a row depends on (if any) is registered.
     *
     * @param array{taxonomy?: ?string} $def
     */
    public static function taxonomy_available(array $def): bool
    {
        $taxonomy = $def['taxonomy'] ?? null;
        return null === $taxonomy || taxonomy_exists($taxonomy);
    }

    /** The synthetic per-op ability name governance evaluates. */
    public static function synthetic_name(string $op): string
    {
        return 'wpmcp/woo-' . str_replace('.', '-', $op);
    }

    /**
     * Op-granular governance. The decision is audited like any other
     * governance decision; an audit failure never breaks the check itself.
     *
     * @param array{method: string, mode: string, capability: string, summary: string} $def
     */
    public static function governance_allows(string $op, array $def): bool
    {
        $synthetic = new Ability(
            self::synthetic_name($op),
            'pro',
            $def['summary'],
            [ 'type' => 'object' ],
            static fn () => null,
            $def['capability'],
            self::DOMAIN,
            Op_Catalog::governance_operation($def)
        );

        $allowed = Governance::is_ability_enabled($synthetic);

        try {
            Governance_Audit_Log::record($synthetic->name, Identity_Context::current() ?? 'none', $allowed);
        } catch (\Throwable $e) {
            // Auditing must never break the permission check it is observing.
        }

        return $allowed;
    }

    /**
     * Run the gates every op shares, in order: availability (WooCommerce, then
     * the row's taxonomy), governance, capability. Returns null when all pass, else a structured error.
     *
     * @param array{method: string, mode: string, capability: string, summary: string} $def
     * @return array<string, mixed>|null
     */
    public static function check(string $op, array $def): ?array
    {
        if (! self::is_available()) {
            return self::error(
                'integration_unavailable',
                'WooCommerce is not active on this site, so no store op can be dispatched.'
            );
        }

        if (! self::taxonomy_available($def)) {
            return self::error(
                'integration_unavailable',
                "Op \"{$op}\" needs the \"{$def['taxonomy']}\" taxonomy, which this WooCommerce does not register.",
                [ 'reason' => 'taxonomy' ]
            );
        }

        if (! self::governance_allows($op, $def)) {
            return self::error(
                'operation_denied',
                "Op \"{$op}\" has been disabled by governance policy.",
                [ 'reason' => 'governance' ]
            );
        }

        if (! current_user_can($def['capability'])) {
            return self::error(
                'operation_denied',
                "Op \"{$op}\" requires the \"{$def['capability']}\" capability.",
                [ 'reason' => 'capability' ]
            );
        }

        return null;
    }

    /**
     * The error shape both dispatchers return, matching
     * Integration_Dispatcher's.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function error(string $code, string $message, array $data = []): array
    {
        return [
            'integration' => self::DOMAIN,
            'error'       => [
                'code'    => $code,
                'message' => $message,
                'data'    => $data,
            ],
        ];
    }
}
