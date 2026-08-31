<?php

namespace WPMCP\Cloud;

use WPMCP\Connect\Exposure;
use WPMCP\Governance\Governance;
use WPMCP\MCP\Tool_Exposure;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Safe_Mutation;
use WPMCP\Skills\Skills_Module;
use WPMCP\Tools\Meta\Option_Guard;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Settings sync over a curated allowlist (issue #135, phase B step 2).
 *
 * The payload is the site's PERSISTED governance posture: the ability/domain/
 * operation toggle maps, the MCP exposure switch, the tool-exposure mode, and
 * the skills switch. Every entry is the ::OPTION constant of the class that
 * owns the state, so export() reads something real and apply() writes
 * something a subsequent request actually consults.
 *
 * What deliberately cannot sync:
 *  - The code-level safety gates (wpmcp_enable_db_writes, wpmcp_allow_php_exec,
 *    wpmcp_wp_cli_allowlist, wpmcp_remote_media_allowed_hosts, ...). Those are
 *    apply_filters() hooks with no stored option behind them: they live in a
 *    mu-plugin or wp-config on each site by design, and a cloud payload has no
 *    way to set them. Replicating them is a deployment concern, not a sync one.
 *  - Anything secret-bearing: identities, connection passwords, OAuth tokens,
 *    stock/API keys. They are absent from the allowlist by construction, and
 *    Option_Guard::is_denylisted() is re-checked on write as a second fence.
 *
 * apply() MERGES rather than replaces, and it narrows rather than widens: the
 * governance toggle map is merged per dimension (see coerce_governance()) and a
 * payload that would switch wpmcp_mcp_exposure back ON against an operator who
 * turned it off is refused outright. Both follow from the same rule the whole
 * governance layer is built on -- a remote party may take agent access away,
 * never hand it back.
 *
 * apply() is the paid-cloud entitlement (Pro\Gate), requires manage_options,
 * re-filters the incoming blob against the same allowlist, and coerces every
 * value to the exact shape its owner expects, so a tampered or stale cloud
 * payload can neither smuggle an unknown option nor poison a known one with a
 * value of the wrong type. Each accepted write goes through Safe_Mutation, so
 * a synced posture is undoable with rollback-operation like every other option
 * write in the plugin.
 */
class Settings_Sync
{
    /**
     * Options that may sync, each with the validator its owner's reader
     * expects. Add here only after checking the value carries no secret
     * material AND that a wrong-shaped value cannot break a reader.
     */
    private const ALLOWLIST = [
        Governance::OPTION    => 'governance_toggles',
        Tool_Exposure::OPTION => 'exposure_mode',
        Exposure::OPTION      => 'onoff_flag',
        Skills_Module::OPTION => 'checkbox_flag',
    ];

    /**
     * Export the current governance posture as an allowlisted key => value map.
     * Options that are unset on this site are omitted rather than defaulted,
     * so applying an export never silently resets a target site's choices.
     *
     * @return array<string,mixed>
     */
    public static function export(): array
    {
        $payload  = [];
        $sentinel = new \stdClass();
        foreach (array_keys(self::ALLOWLIST) as $option) {
            $value = get_option($option, $sentinel);
            if ($value !== $sentinel) {
                $payload[$option] = $value;
            }
        }
        return $payload;
    }

    /**
     * Apply a synced payload.
     *
     * @param array<string,mixed> $payload
     * @param string               $session_id Threaded into the Safe_Mutation
     *                                         snapshot so the audit trail
     *                                         attributes the write to the
     *                                         session that asked for it, like
     *                                         every other option writer.
     * @return array{applied:string[],skipped:array<int,array{key:string,reason:string}>,operation_ids:string[]}|\WP_Error
     */
    public static function apply(array $payload, string $session_id = 'default')
    {
        if (! Gate::is_pro()) {
            return new \WP_Error(
                'cloud_settings_sync_pro_only',
                'Applying a synced settings posture is a paid WP MCP Cloud feature.'
            );
        }
        if (! current_user_can('manage_options')) {
            return new \WP_Error(
                'cloud_settings_sync_forbidden',
                'Applying a synced settings posture requires the manage_options capability.'
            );
        }

        $applied       = [];
        $skipped       = [];
        $operation_ids = [];

        foreach ($payload as $option => $value) {
            $option = (string) $option;

            if (! isset(self::ALLOWLIST[$option])) {
                $skipped[] = ['key' => $option, 'reason' => 'not allowlisted'];
                continue;
            }
            if (Option_Guard::is_denylisted($option)) {
                // Unreachable with the current allowlist; kept as a second
                // fence so widening the list can never outrun the guard.
                $skipped[] = ['key' => $option, 'reason' => 'denylisted option name'];
                continue;
            }

            $coerced = self::coerce(self::ALLOWLIST[$option], $value, $option);
            if (! $coerced['ok']) {
                $skipped[] = ['key' => $option, 'reason' => $coerced['reason']];
                continue;
            }

            $next = $coerced['value'];
            if ($next === get_option($option)) {
                // No-op write: report it as applied without burning a snapshot.
                $applied[] = $option;
                continue;
            }

            $out = Safe_Mutation::run(
                [
                    'object_type' => 'option',
                    'object_id'   => $option,
                    'session_id'  => $session_id,
                    // The APPLIER, not the read-only preview tool. History and
                    // the audit surfaces read this string; naming
                    // cloud-sync-settings here would report an option write as
                    // coming from a tool that never writes anything.
                    'tool_name'   => 'cloud-apply-settings',
                    // The coerced value, which is what update_option() below
                    // actually stores. Hashing the raw payload would make the
                    // recorded args describe something that was never written.
                    'args'        => [$option => $next],
                ],
                static function () use ($option, $next): void {
                    update_option($option, $next);
                }
            );

            $applied[]       = $option;
            $operation_ids[] = $out['operation_id'];
        }

        return [
            'applied'       => $applied,
            'skipped'       => $skipped,
            'operation_ids' => $operation_ids,
        ];
    }

    /** @return string[] */
    public static function allowlist(): array
    {
        return array_keys(self::ALLOWLIST);
    }

    /**
     * @param mixed $value
     * @return array{ok:bool,reason?:string,value?:mixed}
     */
    private static function coerce(string $type, $value, string $option = ''): array
    {
        switch ($type) {
            case 'exposure_mode':
                if (! is_string($value) || ! in_array($value, [Tool_Exposure::MODE_FULL, Tool_Exposure::MODE_COMPACT], true)) {
                    return ['ok' => false, 'reason' => 'invalid exposure mode'];
                }
                return ['ok' => true, 'value' => $value];

            case 'onoff_flag':
                if (! is_scalar($value)) {
                    return ['ok' => false, 'reason' => 'invalid flag value'];
                }
                $on = self::truthy($value);
                // Narrowing only, matching what Exposure itself is: a kill
                // switch that other governance layers AND with. A cloud payload
                // may turn the MCP surface OFF on a fleet, never back on -- an
                // operator who killed agent access at the site must not have it
                // silently restored by a stale or tampered blob.
                if (Exposure::OPTION === $option && $on && ! Exposure::is_enabled()) {
                    return ['ok' => false, 'reason' => 'settings sync may switch MCP exposure off, never back on'];
                }
                return ['ok' => true, 'value' => $on ? '1' : '0'];

            case 'checkbox_flag':
                if (! is_scalar($value)) {
                    return ['ok' => false, 'reason' => 'invalid flag value'];
                }
                // Delegate to the owner's own normalizer rather than restating
                // its truthiness table, so the two cannot drift.
                return ['ok' => true, 'value' => Skills_Module::sanitize($value)];

            case 'governance_toggles':
                return self::coerce_governance($value);
        }

        return ['ok' => false, 'reason' => 'no validator'];
    }

    /**
     * Governance stores exactly three dimensions of name => bool. Anything
     * else in the blob is dropped, and a non-bool decision is normalized, so
     * Governance::explain()'s strict `false ===` checks always see real bools.
     *
     * MERGE, not replace, and the choice is deliberate. coerce_governance()
     * always emits all three dimensions, so a straight overwrite would let a
     * payload carrying only domain toggles silently wipe the target's ability-
     * and operation-level disables. That contradicts export()'s own promise
     * that applying a payload "never silently resets a target site's choices",
     * and it re-enables abilities an operator turned off at the site, which is
     * the one direction the narrowing model does not allow anything to move
     * on its own. A dimension absent from the payload is therefore left
     * untouched; a name present in the payload wins for that name only.
     *
     * @param mixed $value
     * @return array{ok:bool,reason?:string,value?:mixed}
     */
    private static function coerce_governance($value): array
    {
        if (! is_array($value)) {
            return ['ok' => false, 'reason' => 'governance settings must be a toggle map'];
        }

        $stored = get_option(Governance::OPTION, []);
        $stored = is_array($stored) ? $stored : [];

        $out = ['ability' => [], 'domain' => [], 'operation' => []];
        foreach (array_keys($out) as $dimension) {
            $existing = isset($stored[$dimension]) && is_array($stored[$dimension]) ? $stored[$dimension] : [];
            foreach ($existing as $name => $enabled) {
                if (is_string($name) && '' !== $name && is_scalar($enabled)) {
                    $out[$dimension][$name] = self::truthy($enabled);
                }
            }

            if (! array_key_exists($dimension, $value)) {
                continue;
            }
            $entries = $value[$dimension];
            if (! is_array($entries)) {
                return ['ok' => false, 'reason' => "governance {$dimension} toggles must be a map"];
            }
            foreach ($entries as $name => $enabled) {
                if (! is_string($name) || '' === $name || ! is_scalar($enabled)) {
                    return ['ok' => false, 'reason' => "invalid governance {$dimension} toggle"];
                }
                $out[$dimension][$name] = self::truthy($enabled);
            }
        }

        return ['ok' => true, 'value' => $out];
    }

    /** @param scalar $value */
    private static function truthy($value): bool
    {
        if (is_string($value)) {
            return ! in_array($value, ['', '0', 'false'], true);
        }
        return (bool) $value;
    }
}
