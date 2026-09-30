<?php

namespace WPMCP\Tools\Governance;

use WPMCP\Governance\Governance;
use WPMCP\Governance\Site_Wide_Governance;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Batch-apply stored governance toggles across the three dimensions
 * (ability, domain, operation). Accepts a partial payload shaped like
 * { ability: {name: bool}, domain: {name: bool}, operation: {name: bool} },
 * applying whichever of the three top-level keys are present. Mirrors
 * Update_Settings' skip-reporting behavior: an individual bad entry (wrong
 * dimension name, non-boolean value) is skipped and reported, never thrown
 * for; only entirely missing/empty top-level input throws. Plain option
 * writes via Governance, no Safe_Mutation/rollback semantics.
 *
 * A top-level site_wide key (issue #412) sets Site_Wide_Governance's mode
 * (off, audit or enforce); any other value is skipped and reported, and the
 * stored mode is kept.
 */
class Update_Governance_Settings
{
    private const DIMENSIONS = ['ability', 'domain', 'operation'];

    public function handle(array $args): array
    {
        if ([] === $args) {
            throw new \InvalidArgumentException('No governance settings provided to update.');
        }

        $updated = ['ability' => [], 'domain' => [], 'operation' => []];
        $skipped = [];

        foreach ($args as $dimension => $entries) {
            if ('site_wide' === $dimension) {
                if (! is_string($entries) || ! in_array($entries, Site_Wide_Governance::MODES, true)) {
                    $skipped[] = ['key' => 'site_wide', 'reason' => 'expected off, audit or enforce'];
                    continue;
                }
                Site_Wide_Governance::set_mode($entries);
                $updated['site_wide'] = $entries;
                continue;
            }
            if (! in_array($dimension, self::DIMENSIONS, true)) {
                $skipped[] = ['key' => (string) $dimension, 'reason' => 'unknown dimension'];
                continue;
            }
            if (! is_array($entries)) {
                $skipped[] = ['key' => $dimension, 'reason' => 'expected a map of name => bool'];
                continue;
            }

            foreach ($entries as $name => $enabled) {
                $name = (string) $name;
                if (! is_bool($enabled)) {
                    $skipped[] = ['key' => $name, 'reason' => 'not a boolean'];
                    continue;
                }

                $this->apply($dimension, $name, $enabled);
                $updated[ $dimension ][ $name ] = $enabled;
            }
        }

        return ['updated' => $updated, 'skipped' => $skipped];
    }

    private function apply(string $dimension, string $name, bool $enabled): void
    {
        switch ($dimension) {
            case 'ability':
                Governance::set_ability_toggle($name, $enabled);
                break;
            case 'domain':
                Governance::set_domain_toggle($name, $enabled);
                break;
            case 'operation':
                Governance::set_operation_toggle($name, $enabled);
                break;
        }
    }
}
