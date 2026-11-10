<?php

namespace WPMCP\Tools\Dispatch;

use WPMCP\Plugin;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only: return one registered tool's full contract - the exact
 * input_schema it was registered with (issue #79 acceptance: identical to
 * direct registration, byte for byte), its complete description, MCP
 * annotation hints, and classification - so an agent on the compact surface
 * can fetch a schema on demand instead of paying for 160+ schemas in every
 * tools/list.
 */
class Get_Tool_Schema
{
    public function handle(array $args)
    {
        $name = isset($args['name']) ? (string) $args['name'] : '';
        if ('' === $name) {
            return new \WP_Error(
                'wpmcp_get_tool_schema_invalid',
                'A tool name is required, e.g. "wpmcp/get-page". Use wpmcp/list-tools to discover names.'
            );
        }

        $registrar = Plugin::instance()->registrar();
        $ability   = $registrar->get($name);
        if (null === $ability) {
            // A tool governance switched off says so (issue #772).
            // A Pro tool on an unlicensed install says so (issue #631),
            // and a tool whose plugin is not active names it (issue #790).
            return $registrar->pro_required($name) ?? $registrar->governance_disabled($name) ?? $registrar->plugin_inactive($name) ?? new \WP_Error(
                'wpmcp_get_tool_schema_unknown',
                sprintf('No wpmcp tool named "%s" is registered on this site. Use wpmcp/list-tools to discover names.', $name)
            );
        }

        return [
            'name'         => $ability->name,
            'description'  => $ability->description,
            'input_schema' => $ability->input_schema,
            'annotations'  => [
                'readOnlyHint'    => $ability->read_only_hint,
                'destructiveHint' => $ability->destructive_hint,
                'idempotentHint'  => $ability->idempotent_hint,
            ],
            'domain'       => $ability->domain,
            'operation'    => $ability->operation,
            'tier'         => $ability->tier,
        ];
    }
}
