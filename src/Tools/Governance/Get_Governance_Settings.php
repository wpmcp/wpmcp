<?php

namespace WPMCP\Tools\Governance;

use WPMCP\Governance\Governance;
use WPMCP\Governance\New_Tools_Policy;
use WPMCP\Governance\Site_Wide_Governance;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only: returns the three stored governance toggle maps (ability,
 * domain, operation) exactly as Governance stores them, plus the site_wide
 * mode for calls outside the MCP endpoint (issue #412) and whether an
 * update switches its new tools on (auto_enable_new_tools, issue #853). Never touches
 * Safe_Mutation; reads have nothing to roll back.
 */
class Get_Governance_Settings
{
    public function handle(array $args): array
    {
        return [
            'ability'   => Governance::ability_toggles(),
            'domain'    => Governance::domain_toggles(),
            'operation' => Governance::operation_toggles(),
            'site_wide' => Site_Wide_Governance::mode(),
            // Issue #853: whether an update switches its new tools on.
            'auto_enable_new_tools' => New_Tools_Policy::is_on(),
        ];
    }
}
