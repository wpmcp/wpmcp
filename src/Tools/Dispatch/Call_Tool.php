<?php

namespace WPMCP\Tools\Dispatch;

use WPMCP\MCP\Tool_Exposure;
use WPMCP\Plugin;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The compact-surface dispatcher (issue #79): invoke any wpmcp-registered
 * ability by name, so a client on the collapsed tools/list can still reach
 * the entire long tail.
 *
 * SECURITY MODEL — this class must never become a bypass:
 *
 *  - The ONLY invocation path is the registered WP_Ability's execute(),
 *    the exact same entry point the MCP adapter uses for a direct tool
 *    call. WP_Ability::execute() validates the input against the target's
 *    schema and runs the target's permission_callback — i.e.
 *    Registrar::is_permitted(): capability + live Governance + identity
 *    scope + the live pro-license re-check, with the decision audited under
 *    the TARGET ability's name — before the target's wrapped
 *    execute_callback runs (rate limiter, then the tool with its
 *    Safe_Mutation snapshot path). This class never touches an Ability's
 *    raw handler, so a dispatched invocation is permission- and
 *    safety-identical to a direct one by construction.
 *  - Dispatch is allowlisted to abilities in wpmcp's own Registrar. Another
 *    plugin's ability, however permissive, is not reachable through this
 *    tool: wpmcp only proxies the surface it registered itself.
 *  - The meta-tools themselves are not dispatchable (they are always
 *    directly exposed; refusing them keeps recursion out of the surface).
 *  - This shell is itself an ordinary registered ability, so Governance and
 *    identity narrowing apply to it too (AND-of-narrowing, no special
 *    bypass): an identity that should dispatch in compact mode must include
 *    the 'dispatch' domain and 'update' operation in its scope. Note that a
 *    dispatched call passes the rate limiter twice (once for this shell,
 *    once for the target) — the shared per-client budget is spent, never
 *    stretched, by dispatching.
 */
class Call_Tool
{
    public function handle(array $args)
    {
        $name = isset($args['name']) && is_string($args['name']) ? $args['name'] : '';
        if ('' === $name) {
            return new \WP_Error(
                'wpmcp_call_tool_invalid',
                'A tool name is required, e.g. {"name":"wpmcp/get-page","arguments":{"id":1}}. Use wpmcp/list-tools to discover names.'
            );
        }

        $arguments = isset($args['arguments']) && is_array($args['arguments']) ? $args['arguments'] : [];

        if (in_array($name, Tool_Exposure::META_ABILITIES, true)) {
            return new \WP_Error(
                'wpmcp_call_tool_meta',
                sprintf('"%s" is a meta-tool and always directly callable; it cannot be dispatched through call-tool.', $name)
            );
        }

        if (null === Plugin::instance()->registrar()->get($name)) {
            return new \WP_Error(
                'wpmcp_call_tool_unknown',
                sprintf('No wpmcp tool named "%s" is registered on this site. Use wpmcp/list-tools to discover names.', $name)
            );
        }

        if (! function_exists('wp_get_ability') || ! function_exists('wp_has_ability')) {
            return new \WP_Error('wpmcp_call_tool_unavailable', 'The Abilities API is not available on this site.');
        }

        // wp_has_ability() first: wp_get_ability() on an unknown name raises
        // a _doing_it_wrong notice, and "known to the Registrar but missing
        // from the live registry" is a reachable state worth a clean error.
        $ability = wp_has_ability($name) ? wp_get_ability($name) : null;
        if (null === $ability) {
            return new \WP_Error(
                'wpmcp_call_tool_unavailable',
                sprintf('"%s" is not present in the live Abilities registry.', $name)
            );
        }

        // The full gate: input validation, the target's real
        // permission_callback (audited), rate limiting, and the tool's own
        // Safety behavior all run inside execute(). Its result — success
        // payload or WP_Error — is returned to the caller unchanged.
        return $ability->execute($arguments);
    }
}
