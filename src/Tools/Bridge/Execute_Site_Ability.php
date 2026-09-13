<?php

namespace WPMCP\Tools\Bridge;

use WPMCP\Governance\Governance_Audit_Log;
use WPMCP\Identity\Identity_Context;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Invoke one foreign (non-wpmcp) ability by name under the governed
 * endpoint (issue #194), the bridge analogue of Call_Tool.
 *
 * SECURITY MODEL: this class must never widen access.
 *
 *  - The ONLY invocation path is WP_Ability::execute(), which validates the
 *    input against the target's schema and runs the TARGET ability's own
 *    permission_callback before its handler. There is no bypass option, no
 *    filter to disable that check, and no code path that touches the raw
 *    handler; the anti-feature some pass-through bridges ship is exactly
 *    what this class exists to be the opposite of.
 *  - Only abilities the owning plugin exposed with meta.show_in_rest are
 *    reachable (Bridge_Guard::is_bridgeable()). Anything else answers
 *    exactly like an unregistered name.
 *  - This shell is itself an ordinary registered wpmcp ability, so our
 *    governance layer (AND-of-narrowing), identity scoping and rate limiter
 *    apply to every bridged call on top of the target's own gate.
 *  - Our own abilities are refused (that is call-tool's job), which also
 *    keeps the meta-tools and the bridge tools themselves unreachable here.
 *  - The whole bridge surface sits behind Bridge_Guard's default-off
 *    opt-in.
 *  - Every bridged execution, denials included, lands in the
 *    Governance_Audit_Log under the FOREIGN ability's name with a
 *    "bridge:<owner>" reason, so the log attributes the call to the plugin
 *    that owns the code that ran (or refused to).
 *  - No snapshot promise is made for foreign abilities: we cannot know how
 *    third-party code mutates, so every result is wrapped with
 *    reversible:false rather than silently appearing to carry the rollback
 *    guarantee.
 *
 * TODO(#194): per-ability governance toggles for bridged names so a single
 * foreign ability can be disabled per identity/role/environment like ours.
 */
class Execute_Site_Ability
{
    public function handle(array $args)
    {
        if (! Bridge_Guard::is_enabled()) {
            return Bridge_Guard::disabled_error();
        }

        $name = isset($args['name']) && is_string($args['name']) ? $args['name'] : '';
        if ('' === $name) {
            return new \WP_Error(
                'wpmcp_bridge_invalid',
                'An ability name is required, e.g. {"name":"yoast/analyze-page","arguments":{}}. Use wpmcp/list-site-abilities to discover names.'
            );
        }

        if (! Bridge_Guard::is_foreign($name)) {
            return new \WP_Error(
                'wpmcp_bridge_not_foreign',
                sprintf('"%s" is a wpmcp ability; invoke it directly or through wpmcp/call-tool, not the bridge.', $name)
            );
        }

        $ability = Bridge_Guard::lookup($name);
        if (is_wp_error($ability)) {
            return $ability;
        }

        $owner     = Bridge_Guard::owner_of($name);
        $arguments = isset($args['arguments']) && is_array($args['arguments']) ? $args['arguments'] : [];

        // Core's contract for an ability that declares no input schema is
        // "no input at all": WP_Ability::validate_input() refuses anything
        // but null there, including an empty array. An omitted or empty
        // arguments object therefore becomes null for schema-less targets;
        // non-empty arguments are passed through so core's own refusal
        // (ability_missing_input_schema) reaches the caller unchanged.
        if ([] === $arguments && empty($ability->get_input_schema())) {
            $arguments = null;
        }

        // The target's real gate: input validation and its own
        // permission_callback run inside execute(). A denial comes back as
        // a WP_Error and is returned unchanged; a denied ability stays
        // denied.
        $result = $ability->execute($arguments);

        if (is_wp_error($result)) {
            $this->audit($name, false, 'bridge:' . $owner . ':' . $result->get_error_code());
            return $result;
        }

        $this->audit($name, true, 'bridge:' . $owner);

        return [
            'ability'    => $name,
            'plugin'     => $owner,
            // Honesty-critical: this result is outside the wpmcp snapshot /
            // rollback guarantee.
            'reversible' => false,
            'result'     => $result,
        ];
    }

    /**
     * Record the bridged outcome under the foreign ability's own name, the
     * same try/catch discipline as Registrar::record_audit(): auditing must
     * never turn a working (or correctly refused) bridged call into a
     * failure.
     */
    private function audit(string $name, bool $allowed, string $reason): void
    {
        try {
            $identity = Identity_Context::current() ?? 'none';
            Governance_Audit_Log::record($name, $identity, $allowed, $reason);
        } catch (\Throwable $e) {
            // Observability must never break the call it is observing.
        }
    }
}
