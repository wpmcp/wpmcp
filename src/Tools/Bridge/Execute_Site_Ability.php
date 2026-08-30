<?php

namespace WPMCP\Tools\Bridge;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Invoke one foreign (non-wpmcp) ability by name under the governed
 * endpoint (issue #194), the bridge analogue of Call_Tool.
 *
 * SECURITY MODEL — this class must never widen access:
 *
 *  - The ONLY invocation path is WP_Ability::execute(), which validates the
 *    input against the target's schema and runs the TARGET ability's own
 *    permission_callback before its handler. There is no bypass option, no
 *    filter to disable that check, and no code path that touches the raw
 *    handler — the anti-feature some pass-through bridges ship is exactly
 *    what this class exists to be the opposite of.
 *  - This shell is itself an ordinary registered wpmcp ability, so our
 *    governance layer (AND-of-narrowing), identity scoping and rate limiter
 *    apply to every bridged call on top of the target's own gate.
 *  - Our own abilities are refused (that is call-tool's job), which also
 *    keeps the meta-tools and the bridge tools themselves unreachable here.
 *  - The whole bridge surface sits behind Bridge_Guard's default-off
 *    opt-in.
 *  - No snapshot promise is made for foreign abilities: we cannot know how
 *    third-party code mutates, so every result is wrapped with
 *    reversible:false rather than silently appearing to carry the rollback
 *    guarantee.
 *
 * TODO(#194): record every bridged call (including denials) in the
 * Governance_Audit_Log attributed to the owning plugin, once the audit-log
 * write path is factored for non-wpmcp ability names.
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

        if (! function_exists('wp_get_ability') || ! function_exists('wp_has_ability')) {
            return new \WP_Error('wpmcp_bridge_unavailable', 'The Abilities API is not available on this site.');
        }

        $ability = wp_has_ability($name) ? wp_get_ability($name) : null;
        if (null === $ability) {
            return new \WP_Error(
                'wpmcp_bridge_unknown',
                sprintf('No ability named "%s" is registered on this site. Use wpmcp/list-site-abilities to discover names.', $name)
            );
        }

        $arguments = isset($args['arguments']) && is_array($args['arguments']) ? $args['arguments'] : [];

        // The target's real gate: input validation and its own
        // permission_callback run inside execute(). A denial comes back as
        // a WP_Error and is returned unchanged — a denied ability stays
        // denied.
        $result = $ability->execute($arguments);

        if (is_wp_error($result)) {
            return $result;
        }

        return [
            'ability'    => $name,
            'plugin'     => Bridge_Guard::owner_of($name),
            // Honesty-critical: this result is outside the wpmcp snapshot /
            // rollback guarantee.
            'reversible' => false,
            'result'     => $result,
        ];
    }
}
