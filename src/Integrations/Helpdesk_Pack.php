<?php

namespace WPMCP\Integrations;

use WPMCP\MCP\Registrar;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Support desk tickets (issue #708) on the plugin-data pair, paid-tier, so
 * they add no top-level tools: Fluent Support (Helpdesk_Fluent_Support) and
 * Awesome Support (Helpdesk_Awesome_Support), each op prefixed with the
 * plugin (fluent-support-get-ticket, awesome-support-set-ticket-status,
 * ...). The op catalog, the checks and the snapshot discipline they share
 * are Helpdesk_Adapter.
 *
 * Presence is filterable per plugin (wpmcp_fluent_support_active,
 * wpmcp_awesome_support_active); an op whose plugin is not loaded stays
 * documented in list-operations (dependency_met:false) and answers
 * <plugin>_inactive.
 */
final class Helpdesk_Pack
{
    /** @var Helpdesk_Adapter[]|null adapters standing in for the real ones, for tests */
    private static ?array $adapters = null;

    /** @return Helpdesk_Adapter[] */
    public static function adapters(): array
    {
        return self::$adapters ?? [ new Helpdesk_Fluent_Support(), new Helpdesk_Awesome_Support() ];
    }

    /**
     * Stand-in adapters for the test suite, which has neither plugin
     * installed: they keep the catalog, checks, reads and snapshots and
     * replace only the calls into the plugin's own API. Null restores the
     * real ones.
     *
     * @param Helpdesk_Adapter[]|null $adapters
     */
    public static function use_adapters_for_tests(?array $adapters): void
    {
        if (! defined('WPMCP_TESTING') || ! WPMCP_TESTING) {
            return;
        }
        self::$adapters = $adapters;
    }

    /** Whether a supported plugin is loaded and this install may run the paid tier. */
    public static function active(): bool
    {
        if (! Registrar::tier_permitted('pro')) {
            return false;
        }
        foreach (self::adapters() as $adapter) {
            if ($adapter->active()) {
                return true;
            }
        }
        return false;
    }

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        $ops = [];
        foreach (self::adapters() as $adapter) {
            $ops = array_merge($ops, $adapter->operations());
        }
        return $ops;
    }
}
