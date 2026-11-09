<?php

namespace WPMCP\Tools\Governance;

use WPMCP\Governance\Governance;
use WPMCP\Governance\Site_Wide_Governance;
use WPMCP\MCP\Ability;
use WPMCP\MCP\Registrar;
use WPMCP\Plugin;
use WPMCP\Safety\Safe_Mutation;
use WPMCP\Safety\Snapshot;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Batch-apply stored governance toggles across the three dimensions
 * (ability, domain, operation). Accepts a partial payload shaped like
 * { ability: {name: bool}, domain: {name: bool}, operation: {name: bool} },
 * applying whichever of the three top-level keys are present. Mirrors
 * Update_Settings' skip-reporting behavior for a malformed entry (wrong
 * dimension name, non-boolean value): it is skipped and reported, never
 * thrown for; only entirely missing/empty top-level input throws.
 *
 * Two things refuse the whole call instead, with nothing written:
 *
 *  - A name no declared ability carries (issue #774): a typo in an ability,
 *    domain or operation name used to be stored and reported as updated, so
 *    the admin was told the real tool was off while it stayed on. Every
 *    unknown name is listed in the refusal.
 *  - A toggle that would switch off this tool, get-governance-settings or
 *    rollback-operation, by ability, domain or operation (issue #773). Once
 *    any of them is off no MCP client can switch it back on, so the only way
 *    back is wp-admin; the refusal says so. Switching them ON is always
 *    allowed.
 *
 * The write is one Safe_Mutation over the two governance options (the
 * toggle maps and the site_wide mode), so the call returns an operation_id
 * and rollback-operation restores the previous toggles exactly (issue
 * #773). A call that changes nothing takes no undo point: operation_id is
 * null.
 *
 * A top-level site_wide key (issue #412) sets Site_Wide_Governance's mode
 * (off, audit or enforce); any other value is skipped and reported, and the
 * stored mode is kept.
 */
class Update_Governance_Settings
{
    private const DIMENSIONS = ['ability', 'domain', 'operation'];

    /**
     * The abilities an MCP client needs to see, change and undo governance.
     * Disabling any of them over MCP is refused (issue #773).
     */
    public const PROTECTED = [
        'wpmcp/update-governance-settings',
        'wpmcp/get-governance-settings',
        'wpmcp/rollback-operation',
    ];

    /**
     * The governance domain of a foreign ability reached through the bridge,
     * the value of Bridge_Guard::DOMAIN. Spelled out here because a build
     * without the bridge tools must not load that class.
     */
    private const BRIDGE_DOMAIN = 'bridge';

    /** @return array|\WP_Error */
    public function handle(array $args)
    {
        if ([] === $args) {
            throw new \InvalidArgumentException('No governance settings provided to update.');
        }

        $registrar = Plugin::instance()->registrar();
        $planned   = ['ability' => [], 'domain' => [], 'operation' => []];
        $site_wide = null;
        $skipped   = [];
        $unknown   = [];
        $lockout   = [];

        foreach ($args as $dimension => $entries) {
            if ('site_wide' === $dimension) {
                if (! is_string($entries) || ! in_array($entries, Site_Wide_Governance::MODES, true)) {
                    $skipped[] = ['key' => 'site_wide', 'reason' => 'expected off, audit or enforce'];
                    continue;
                }
                $site_wide = $entries;
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
                if (! self::is_known($registrar, $dimension, $name)) {
                    $unknown[] = sprintf('%s "%s"', $dimension, $name);
                    continue;
                }
                $locked_out = $enabled ? [] : self::locked_out($registrar, $dimension, $name);
                if ([] !== $locked_out) {
                    $lockout[] = sprintf('%s "%s" (%s)', $dimension, $name, implode(', ', $locked_out));
                    continue;
                }

                $planned[ $dimension ][ $name ] = $enabled;
            }
        }

        if ([] !== $unknown) {
            return new \WP_Error(
                'wpmcp_governance_unknown_name',
                sprintf(
                    'No registered ability matches %s. Nothing was written. Check the name with wpmcp/list-tools (abilities carry their domain and operation) and send the call again.',
                    implode(', ', $unknown)
                ),
                [ 'unknown' => $unknown ]
            );
        }
        if ([] !== $lockout) {
            return new \WP_Error(
                'wpmcp_governance_lockout',
                sprintf(
                    'Refusing to switch off %s: that would disable the tools an MCP client needs to read, change and undo governance, and no MCP client could switch them back on. Nothing was written. An administrator can change these toggles under WP MCP > Abilities in wp-admin.',
                    implode('; ', $lockout)
                ),
                [ 'refused' => $lockout ]
            );
        }

        $operation_id = $this->write($planned, $site_wide, $args);

        $updated = $planned;
        if (null !== $site_wide) {
            $updated['site_wide'] = $site_wide;
        }

        return ['updated' => $updated, 'skipped' => $skipped, 'operation_id' => $operation_id];
    }

    /**
     * Apply the planned toggles and mode as one undo point, or nothing when
     * every entry already holds its value. Returns the operation_id, or null
     * when nothing changed.
     *
     * @param array{ability: array<string,bool>, domain: array<string,bool>, operation: array<string,bool>} $planned
     */
    private function write(array $planned, ?string $site_wide, array $args): ?string
    {
        $changed = null !== $site_wide && Site_Wide_Governance::mode() !== $site_wide;
        foreach (self::DIMENSIONS as $dimension) {
            $stored = self::stored($dimension);
            foreach ($planned[ $dimension ] as $name => $enabled) {
                if (! array_key_exists($name, $stored) || $stored[ $name ] !== $enabled) {
                    $changed = true;
                }
            }
        }
        if (! $changed) {
            return null;
        }

        $out = Safe_Mutation::run(
            [
                'object_type' => 'option_set',
                'object_id'   => Snapshot::option_set_id([ Governance::OPTION, Site_Wide_Governance::OPTION ]),
                'object_ref'  => 'governance settings',
                'session_id'  => 'default',
                'tool_name'   => 'update-governance-settings',
                'args'        => $args,
            ],
            static function () use ($planned, $site_wide): void {
                foreach ($planned['ability'] as $name => $enabled) {
                    Governance::set_ability_toggle($name, $enabled);
                }
                foreach ($planned['domain'] as $name => $enabled) {
                    Governance::set_domain_toggle($name, $enabled);
                }
                foreach ($planned['operation'] as $name => $enabled) {
                    Governance::set_operation_toggle($name, $enabled);
                }
                if (null !== $site_wide) {
                    Site_Wide_Governance::set_mode($site_wide);
                }
            }
        );

        return $out['operation_id'];
    }

    /** @return array<string, bool> */
    private static function stored(string $dimension): array
    {
        switch ($dimension) {
            case 'ability':
                return Governance::ability_toggles();
            case 'domain':
                return Governance::domain_toggles();
            default:
                return Governance::operation_toggles();
        }
    }

    /**
     * Whether some declared ability carries this name, domain or operation.
     * Declared, not registered: an ability governance already switched off
     * (or one whose tier this site cannot run) is still a real name, and the
     * one an admin most needs to be able to toggle back on. A foreign
     * ability in the live Abilities registry is governed through the bridge
     * by its own name and the bridge domain (see Bridge_Guard), so those
     * are real names too.
     */
    private static function is_known(Registrar $registrar, string $dimension, string $name): bool
    {
        if ('ability' === $dimension && function_exists('wp_has_ability') && wp_has_ability($name)) {
            return true;
        }
        if ('domain' === $dimension && self::BRIDGE_DOMAIN === $name) {
            return true;
        }
        foreach ($registrar->declared() as $ability) {
            if (self::ability_matches($ability, $dimension, $name)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The protected abilities a disable of this name would switch off: the
     * name itself for the ability dimension, every protected ability in the
     * domain or with the operation otherwise.
     *
     * @return string[]
     */
    private static function locked_out(Registrar $registrar, string $dimension, string $name): array
    {
        $hit = [];
        foreach ($registrar->declared() as $ability) {
            if (in_array($ability->name, self::PROTECTED, true) && self::ability_matches($ability, $dimension, $name)) {
                $hit[] = $ability->name;
            }
        }
        return $hit;
    }

    private static function ability_matches(Ability $ability, string $dimension, string $name): bool
    {
        switch ($dimension) {
            case 'ability':
                return $ability->name === $name;
            case 'domain':
                return $ability->domain === $name;
            default:
                return $ability->operation === $name;
        }
    }
}
