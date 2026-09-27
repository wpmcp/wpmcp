<?php

namespace WPMCP\Tools\Bridge;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only: one foreign ability's full contract (issue #194), complete
 * description, exact input schema, output schema and meta where the
 * registering plugin provided them, so an agent can construct a valid
 * execute-site-ability call. Refuses anything in our own namespace: our
 * tools' schemas live behind wpmcp/get-tool-schema. Only abilities the
 * owner exposed with show_in_rest are readable; anything else answers
 * exactly like an unregistered name (Bridge_Guard::lookup()).
 */
class Get_Site_Ability
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
                'An ability name is required, e.g. {"name":"yoast/analyze-page"}. Use wpmcp/list-site-abilities to discover names.'
            );
        }

        if (! Bridge_Guard::is_foreign($name)) {
            return new \WP_Error(
                'wpmcp_bridge_not_foreign',
                sprintf('"%s" is a wpmcp ability; use wpmcp/get-tool-schema for our own tools.', $name)
            );
        }

        $ability = Bridge_Guard::lookup($name);
        if (is_wp_error($ability)) {
            return $ability;
        }

        $result = [
            'name'        => $name,
            'plugin'      => Bridge_Guard::owner_of($name),
            'description' => method_exists($ability, 'get_description') ? (string) $ability->get_description() : '',
            // Honesty-critical: no snapshot promise for foreign code.
            'reversible'  => false,
        ];

        if (method_exists($ability, 'get_input_schema')) {
            $result['input_schema'] = $ability->get_input_schema();
        }
        if (method_exists($ability, 'get_output_schema')) {
            $result['output_schema'] = $ability->get_output_schema();
        }
        if (method_exists($ability, 'get_meta')) {
            $result['meta'] = $ability->get_meta();
        }

        return $result;
    }
}
