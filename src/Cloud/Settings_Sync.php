<?php

namespace WPMCP\Cloud;

use WPMCP\Connect\Exposure;
use WPMCP\Governance\Governance;
use WPMCP\Identity\Identity_Store;
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
 * operation toggle maps, the MCP exposure switch, the tool-exposure mode, the
 * skills switch, and the scoped identities minus secrets. Every entry is the
 * ::OPTION constant of the class that owns the state, so export() reads
 * something real and apply() writes something a subsequent request actually
 * consults.
 *
 * What deliberately cannot sync:
 *  - The code-level safety gates (wpmcp_enable_db_writes, wpmcp_allow_php_exec,
 *    wpmcp_wp_cli_allowlist, wpmcp_remote_media_allowed_hosts, ...). Those are
 *    apply_filters() hooks with no stored option behind them: they live in a
 *    mu-plugin or wp-config on each site by design, and a cloud payload has no
 *    way to set them. Replicating them is a deployment concern, not a sync one.
 *  - Anything secret-bearing: connection passwords, OAuth tokens, stock/API
 *    keys. They are absent from the allowlist by construction, and
 *    Option_Guard::is_denylisted() is re-checked on write as a second fence.
 *    Identities DO sync, but only as their scope records: every record is
 *    projected onto exactly the fields Identity_Store::create() writes (name,
 *    domains, operations, abilities, mode, exposure) on both export and apply,
 *    so a field someone stuffed into the option (a password, a token) never
 *    leaves the source site and never lands on the target. Which credential
 *    maps to which identity is the wpmcp_current_identity filter's business,
 *    per site, and is not part of the posture.
 *
 * apply() MERGES rather than replaces. The governance toggle map merges per
 * dimension (see coerce_governance()) and identities merge per name (see
 * coerce_identities()), so nothing the payload does not mention is reset. A
 * name the payload DOES mention wins for that name, which can re-enable an
 * ability or loosen an identity: that is what replicating a posture means,
 * and it is why apply() is an explicit, manage_options, snapshot-per-option
 * operation rather than something the cloud can push unasked.
 *
 * What apply() never changes, so that every write it makes stays undoable
 * with rollback-operation over MCP: the wpmcp_mcp_exposure kill switch (in
 * either direction; off would block every ability over MCP, rollback
 * included) and any governance toggle that would switch off
 * wpmcp/rollback-operation itself (ROLLBACK_PATH). Both are reported in
 * skipped with the reason.
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
        Identity_Store::OPTION => 'identities',
    ];

    /**
     * The governance names that, switched off, would disable
     * wpmcp/rollback-operation (ability, domain core, operation update). Sync
     * drops an incoming "off" for any of them; see coerce_governance().
     */
    private const ROLLBACK_PATH = [
        'ability'   => ['wpmcp/rollback-operation'],
        'domain'    => ['core'],
        'operation' => ['update'],
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
            if ($value === $sentinel) {
                continue;
            }
            if (Identity_Store::OPTION === $option) {
                $value = self::project_identities($value);
            }
            $payload[$option] = $value;
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
     * @return array{applied:string[],operation_ids:string[],unchanged:string[],skipped:array<int,array{key:string,reason:string}>}|\WP_Error
     *         applied and operation_ids are parallel: applied[i] was written
     *         under the snapshot operation_ids[i]. unchanged lists allowlisted
     *         options whose coerced value already matched, which are not
     *         written and so carry no snapshot.
     */
    public static function apply(array $payload, string $session_id = 'default')
    {
        $denied = self::entitlement_error();
        if (null !== $denied) {
            return $denied;
        }

        $applied       = [];
        $operation_ids = [];
        $unchanged     = [];
        $skipped       = [];

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
            foreach ($coerced['dropped'] ?? [] as $dropped) {
                $skipped[] = $dropped;
            }
            if (! $coerced['ok']) {
                $skipped[] = ['key' => $option, 'reason' => $coerced['reason']];
                continue;
            }

            $next = $coerced['value'];
            if (! empty($coerced['unchanged']) || self::canonical($next) === self::canonical(get_option($option))) {
                // No-op: nothing is written, so no snapshot is taken, and the
                // option is reported apart from the writes that have one.
                $unchanged[] = $option;
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
            'operation_ids' => $operation_ids,
            'unchanged'     => $unchanged,
            'skipped'       => $skipped,
        ];
    }

    /**
     * The paid-cloud entitlement plus the capability, checked before any sync
     * work (push, pull or apply) so a site without it never even talks to the
     * cloud about settings.
     */
    public static function entitlement_error(): ?\WP_Error
    {
        if (! Gate::is_pro()) {
            return new \WP_Error(
                'cloud_settings_sync_pro_only',
                'Settings sync is a paid WP MCP Cloud feature.'
            );
        }
        if (! current_user_can('manage_options')) {
            return new \WP_Error(
                'cloud_settings_sync_forbidden',
                'Settings sync requires the manage_options capability.'
            );
        }

        return null;
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
                // The MCP kill switch is reported by export() but never
                // changed by sync, in either direction. Back ON: an operator
                // who killed agent access at the site must not have it
                // restored by a stale or tampered blob. OFF: it denies every
                // ability over MCP, rollback-operation and cloud-apply-settings
                // included, so a synced "off" could not be undone the way
                // every other synced write can. Flip it in wp-admin instead.
                if ($on === Exposure::is_enabled()) {
                    return ['ok' => true, 'value' => $on ? '1' : '0', 'unchanged' => true];
                }
                return [
                    'ok'     => false,
                    'reason' => $on
                        ? 'settings sync never switches MCP exposure back on; do it on the Connection screen'
                        : 'settings sync never switches MCP exposure off, because that would also block rollback-operation over MCP; do it on the Connection screen',
                ];

            case 'checkbox_flag':
                if (! is_scalar($value)) {
                    return ['ok' => false, 'reason' => 'invalid flag value'];
                }
                // Delegate to the owner's own normalizer rather than restating
                // its truthiness table, so the two cannot drift.
                return ['ok' => true, 'value' => Skills_Module::sanitize($value)];

            case 'governance_toggles':
                return self::coerce_governance($value);

            case 'identities':
                return self::coerce_identities($value);
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

        $out     = ['ability' => [], 'domain' => [], 'operation' => []];
        $dropped = [];
        foreach (array_keys($out) as $dimension) {
            $existing = isset($stored[$dimension]) && is_array($stored[$dimension]) ? $stored[$dimension] : [];
            foreach ($existing as $name => $enabled) {
                if (self::is_name($name) && is_scalar($enabled)) {
                    $out[$dimension][(string) $name] = self::truthy($enabled);
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
                if (! self::is_name($name) || ! is_scalar($enabled)) {
                    return ['ok' => false, 'reason' => "invalid governance {$dimension} toggle"];
                }
                $name    = (string) $name;
                $enabled = self::truthy($enabled);
                if (! $enabled && in_array($name, self::ROLLBACK_PATH[ $dimension ], true)) {
                    // Same reason the MCP kill switch is not synced: this
                    // toggle would disable rollback-operation itself, and
                    // with it the undo for everything else in the payload.
                    $dropped[] = [
                        'key'    => Governance::OPTION . ".{$dimension}.{$name}",
                        'reason' => 'settings sync never disables rollback-operation; toggle it on the site',
                    ];
                    continue;
                }
                $out[$dimension][$name] = $enabled;
            }
        }

        return ['ok' => true, 'value' => $out, 'dropped' => $dropped];
    }

    /**
     * Identities merge by name: a synced name replaces that one record, and
     * every identity the target defines locally survives. Each incoming record
     * goes through Identity_Store::normalize(), the normalization create()
     * itself uses, so the stored shape is exactly what Governance and
     * Tool_Exposure read and no field outside it is kept. A
     * malformed entry refuses the whole option rather than applying half a
     * map, matching coerce_governance().
     *
     * @param mixed $value
     * @return array{ok:bool,reason?:string,value?:mixed}
     */
    private static function coerce_identities($value): array
    {
        if (! is_array($value)) {
            return ['ok' => false, 'reason' => 'identities must be a map of name => record'];
        }

        $incoming = [];
        foreach ($value as $name => $record) {
            if (! self::is_name($name) || ! is_array($record)) {
                return ['ok' => false, 'reason' => 'invalid identity record'];
            }
            foreach (['domains', 'operations', 'abilities'] as $list) {
                if (isset($record[ $list ]) && ! is_array($record[ $list ])) {
                    return ['ok' => false, 'reason' => "identity {$list} must be a list"];
                }
            }
            $incoming[ (string) $name ] = Identity_Store::normalize($name, $record);
        }

        // Key-by-key rather than array_merge(), which renumbers the int keys
        // PHP gives digits-only names and would duplicate those identities.
        $merged = self::project_identities(get_option(Identity_Store::OPTION, []));
        foreach ($incoming as $name => $record) {
            $merged[ $name ] = $record;
        }

        return ['ok' => true, 'value' => $merged];
    }

    /**
     * @param mixed $value
     * @return array<string,array>
     */
    private static function project_identities($value): array
    {
        $out = [];
        if (! is_array($value)) {
            return $out;
        }
        foreach ($value as $name => $record) {
            if (self::is_name($name) && is_array($record)) {
                $out[ (string) $name ] = Identity_Store::normalize($name, $record);
            }
        }
        return $out;
    }

    /**
     * A usable map key. Accepts ints because PHP stores a digits-only string
     * key ("2024") as an int, both in a stored option and after json_decode(),
     * and such a name is as valid as any other.
     *
     * @param mixed $name
     */
    private static function is_name($name): bool
    {
        return is_int($name) || (is_string($name) && '' !== $name);
    }

    /**
     * An order-insensitive form for the no-op check, so a map whose keys
     * merely come back in a different order is recognized as unchanged. List
     * order still counts: in a scope list it is the stored value.
     *
     * @param mixed $value
     * @return mixed
     */
    private static function canonical($value)
    {
        if (! is_array($value)) {
            return $value;
        }
        $value = array_map([self::class, 'canonical'], $value);
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        return $value;
    }

    /**
     * Truthiness as the plugin's option normalizer defines it, expressed
     * through that owner (Skills_Module::sanitize()) rather than restated.
     *
     * @param scalar $value
     */
    private static function truthy($value): bool
    {
        return '1' === Skills_Module::sanitize($value);
    }
}
