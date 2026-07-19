<?php

namespace WPMCP\MCP;

use WPMCP\Identity\Identity_Context;
use WPMCP\Identity\Identity_Store;
use WPMCP\Plugin;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The compact tool-surface mode (issue #79): 160+ flat tools bloats or
 * outright breaks real MCP clients, so a site (or a single scoped identity)
 * can collapse the ADVERTISED tools/list to a handful of meta-tools plus
 * connection basics, with the entire long tail still reachable through
 * wpmcp/call-tool.
 *
 * Compact mode is EXPOSURE-ONLY, by design:
 *
 *  - Registration never changes. Every ability registers in every mode, so
 *    the ability-manifest drift guard (tests/support/ability-manifest.php)
 *    pins one stable surface regardless of exposure mode, and a hidden tool
 *    called directly by a client that remembers its name still works.
 *  - Hiding is NOT a permission boundary. A tool absent from tools/list
 *    runs the full permission chain (capability + Governance + identity
 *    scope + pro-license, audited) if invoked anyway; this class only cuts
 *    token cost, it grants and revokes nothing.
 *
 * Mode resolution, least- to most-specific: the site option (default full —
 * compact is opt-in per the issue), then the active identity's stored
 * 'exposure' override (an agent-scoped choice, not only site-wide), then
 * the wpmcp_tool_exposure_mode filter for code-level control. Invalid
 * values at any layer degrade to the surrounding layer's answer (or 'full').
 *
 * tools/list integration: filter_tools_list() is hooked on the MCP
 * Adapter's mcp_adapter_tools_list filter (same duck-typed pattern as the
 * issue #80 initialize-response integration — the adapter is a separate
 * plugin, so its DTO classes are never referenced here). In compact mode it
 * removes exactly the tools that are (a) backed by a wpmcp-registered
 * ability and (b) not in the curated core set; tools belonging to other
 * plugins/servers pass through untouched, as do entries whose name cannot
 * be read.
 */
class Tool_Exposure
{
    public const OPTION = 'wpmcp_tool_exposure_mode';

    public const MODE_FULL    = 'full';
    public const MODE_COMPACT = 'compact';

    /**
     * The dispatcher meta-tools. Always exposed in compact mode — without
     * them the collapsed surface would be unnavigable — so the curation
     * filter below can add to the core set but never remove these.
     */
    public const META_ABILITIES = [
        'wpmcp/list-tools',
        'wpmcp/get-tool-schema',
        'wpmcp/call-tool',
    ];

    /**
     * The full compact-mode surface: the meta-tools plus the connection
     * basics an agent needs to orient itself before discovering the rest.
     */
    public const COMPACT_CORE = [
        'wpmcp/list-tools',
        'wpmcp/get-tool-schema',
        'wpmcp/call-tool',
        'wpmcp/get-connection-info',
        'wpmcp/get-site-context',
    ];

    /**
     * Resolve the exposure mode for the current request.
     */
    public function mode(): string
    {
        $mode = $this->valid((string) get_option(self::OPTION, self::MODE_FULL)) ?? self::MODE_FULL;

        $identity_mode = $this->identity_mode();
        if (null !== $identity_mode) {
            $mode = $identity_mode;
        }

        /**
         * Filters the resolved tool-exposure mode ('full' or 'compact').
         * Anything other than those two exact strings degrades to 'full'.
         *
         * @param string $mode The mode resolved from the option and the
         *                     active identity's override.
         */
        $filtered = apply_filters('wpmcp_tool_exposure_mode', $mode);

        return $this->valid(is_string($filtered) ? $filtered : '') ?? self::MODE_FULL;
    }

    public function is_compact(): bool
    {
        return self::MODE_COMPACT === $this->mode();
    }

    /**
     * The curated set of ability names advertised in compact mode. The
     * wpmcp_compact_exposed_abilities filter can widen (or trim the
     * connection basics from) this set, but the meta-tools are always
     * force-included: exposure without a dispatcher is a dead surface.
     *
     * This is an exposure list, not a permission decision — see the class
     * docblock.
     *
     * @return string[] ability names (e.g. 'wpmcp/call-tool').
     */
    public function compact_exposed(): array
    {
        $curated = apply_filters('wpmcp_compact_exposed_abilities', self::COMPACT_CORE);
        $curated = is_array($curated) ? array_filter($curated, 'is_string') : [];

        return array_values(array_unique(array_merge(self::META_ABILITIES, $curated)));
    }

    /**
     * Callback for the MCP Adapter's mcp_adapter_tools_list filter. In full
     * mode (the default) the list passes through untouched. In compact mode,
     * a tool is removed only when its name maps back to a wpmcp-registered
     * ability outside the compact core; every other entry — other plugins'
     * tools, and entries whose name cannot be read — survives, because this
     * site-wide filter fires for every adapter server, not only wpmcp's.
     *
     * Duck-typed against the adapter's Tool DTO (getName()), plus plain
     * 'name' properties and array shapes, so the adapter remains an optional
     * runtime dependency.
     *
     * @param mixed $tools  Array of tool DTOs (or anything else, passed through).
     * @param mixed $server The McpServer instance (unused).
     * @return mixed
     */
    public function filter_tools_list($tools, $server = null)
    {
        if (! is_array($tools) || ! $this->is_compact()) {
            return $tools;
        }

        $ours    = $this->registered_tool_names();
        $exposed = array_map([self::class, 'tool_name'], $this->compact_exposed());

        $kept = [];
        foreach ($tools as $tool) {
            $name = $this->read_name($tool);
            if (null !== $name && isset($ours[ $name ]) && ! in_array($name, $exposed, true)) {
                continue;
            }
            $kept[] = $tool;
        }

        return array_values($kept);
    }

    /**
     * An ability name as the adapter advertises it: MCP tool names cannot
     * contain '/', which the adapter's sanitizer replaces with '-'.
     */
    public static function tool_name(string $ability_name): string
    {
        return str_replace('/', '-', $ability_name);
    }

    /** @return array<string, true> sanitized tool names of every wpmcp-registered ability. */
    private function registered_tool_names(): array
    {
        $names = [];
        foreach (Plugin::instance()->registrar()->all() as $ability) {
            $names[ self::tool_name($ability->name) ] = true;
        }
        return $names;
    }

    /** Read a tool entry's name, duck-typing the adapter's DTO shapes. */
    private function read_name($tool): ?string
    {
        if (is_array($tool) && isset($tool['name']) && is_string($tool['name'])) {
            return $tool['name'];
        }
        if (is_object($tool)) {
            if (method_exists($tool, 'getName')) {
                $name = $tool->getName();
                return is_string($name) ? $name : null;
            }
            if (isset($tool->name) && is_string($tool->name)) {
                return $tool->name;
            }
        }
        return null;
    }

    /** The active identity's exposure override, or null to inherit. */
    private function identity_mode(): ?string
    {
        $current = Identity_Context::current();
        if (null === $current) {
            return null;
        }

        $identity = Identity_Store::get($current);
        if (null === $identity) {
            return null;
        }

        return $this->valid((string) ($identity['exposure'] ?? ''));
    }

    private function valid(string $mode): ?string
    {
        return in_array($mode, [self::MODE_FULL, self::MODE_COMPACT], true) ? $mode : null;
    }
}
