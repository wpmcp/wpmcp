<?php

namespace WPMCP\Tools\Bridge;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only: enumerate every ability registered on the site that is NOT
 * ours (issue #194) — the surface other Abilities-API plugins (Yoast,
 * FluentCart, Ameliabooking, BetterLinks, ...) expose — so an agent can
 * discover it before dispatching through execute-site-ability.
 *
 * Bridged abilities are deliberately absent from tools/list (the payload is
 * already large at our own 302 tools); this listing IS their discovery
 * path, mirroring the list-tools / call-tool compact-mode pattern.
 *
 * Every entry carries reversible:false — we cannot know how a third-party
 * ability mutates, so nothing bridged is ever claimed by the snapshot /
 * rollback guarantee.
 */
class List_Site_Abilities
{
    /** Character clamp for the per-ability summary, matching List_Tools. */
    public const SUMMARY_LENGTH = 160;

    public function handle(array $args)
    {
        if (! Bridge_Guard::is_enabled()) {
            return Bridge_Guard::disabled_error();
        }

        if (! function_exists('wp_get_abilities')) {
            return new \WP_Error('wpmcp_bridge_unavailable', 'The Abilities API is not available on this site.');
        }

        $plugin = isset($args['plugin']) ? (string) $args['plugin'] : '';

        $abilities = [];
        foreach (wp_get_abilities() as $key => $ability) {
            $name = is_object($ability) && method_exists($ability, 'get_name')
                ? $ability->get_name()
                : (string) $key;

            if (! Bridge_Guard::is_foreign($name)) {
                continue;
            }

            $owner = Bridge_Guard::owner_of($name);
            if ('' !== $plugin && $owner !== $plugin) {
                continue;
            }

            $description = is_object($ability) && method_exists($ability, 'get_description')
                ? (string) $ability->get_description()
                : '';
            $has_schema  = is_object($ability) && method_exists($ability, 'get_input_schema')
                && ! empty($ability->get_input_schema());

            $abilities[] = [
                'name'             => $name,
                'summary'          => mb_substr($description, 0, self::SUMMARY_LENGTH),
                'plugin'           => $owner,
                'has_input_schema' => $has_schema,
                // Honesty-critical: no snapshot promise for foreign code.
                'reversible'       => false,
            ];
        }

        usort($abilities, static fn(array $a, array $b) => strcmp($a['name'], $b['name']));

        return [
            'total'     => count($abilities),
            'abilities' => $abilities,
            'note'      => 'Bridged abilities are outside the wpmcp rollback guarantee: results marked reversible:false cannot be undone by wpmcp/rollback.',
        ];
    }
}
