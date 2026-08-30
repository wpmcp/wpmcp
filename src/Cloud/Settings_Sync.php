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
     * @return array{applied:string[],skipped:array<int,array{key:string,reason:string}>,operation_ids:string[]}|\WP_Error
     */
    public static function apply(array $payload)
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

            $coerced = self::coerce(self::ALLOWLIST[$option], $value);
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
                    'session_id'  => 'default',
                    'tool_name'   => 'cloud-sync-settings',
                    'args'        => [$option => $value],
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
    private static function coerce(string $type, $value): array
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
                return ['ok' => true, 'value' => self::truthy($value) ? '1' : '0'];

            case 'checkbox_flag':
                if (! is_scalar($value)) {
                    return ['ok' => false, 'reason' => 'invalid flag value'];
                }
                return ['ok' => true, 'value' => self::truthy($value) ? '1' : ''];

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
     * @param mixed $value
     * @return array{ok:bool,reason?:string,value?:mixed}
     */
    private static function coerce_governance($value): array
    {
        if (! is_array($value)) {
            return ['ok' => false, 'reason' => 'governance settings must be a toggle map'];
        }

        $out = ['ability' => [], 'domain' => [], 'operation' => []];
        foreach (array_keys($out) as $dimension) {
            $entries = $value[$dimension] ?? [];
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
