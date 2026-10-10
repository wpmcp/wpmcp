<?php

namespace WPMCP\Integrations;

use WPMCP\MCP\Registrar;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Email marketing and CRM contacts, lists, tags, campaigns and automations
 * (issue #477) on the plugin-data pair, paid-tier, so they add no top-level
 * tools: for FluentCRM (CRM_FluentCRM) and MailPoet (CRM_MailPoet), each op
 * prefixed with the plugin (fluentcrm-get-contact, mailpoet-tag-contact,
 * ...). What both share, the op catalog, the consent checks and the
 * snapshot discipline, is CRM_Adapter.
 *
 * Presence is filterable per plugin (wpmcp_fluentcrm_active,
 * wpmcp_mailpoet_active); an op whose plugin is not loaded stays documented
 * in list-operations (dependency_met:false) and answers <plugin>_inactive.
 */
final class CRM_Pack
{
    /** @var CRM_Adapter[]|null adapters standing in for the real ones, for tests */
    private static ?array $adapters = null;

    /** @return CRM_Adapter[] */
    public static function adapters(): array
    {
        return self::$adapters ?? [ new CRM_FluentCRM(), new CRM_MailPoet() ];
    }

    /**
     * Stand-in adapters for the test suite, which has neither plugin
     * installed: they keep the catalog, checks, reads and snapshots and
     * replace only the calls into the plugin's own API. Null restores the
     * real ones.
     *
     * @param CRM_Adapter[]|null $adapters
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
