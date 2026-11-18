<?php

namespace WPMCP\MCP;

use WPMCP\Plugin;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * What this site cannot run, and why (issue #629), without a new tool.
 *
 * One report, read by list-tools (include_unavailable and its closing
 * summary line), get-tool-schema (the availability block), list-tool-catalog,
 * get-connection-info and the handshake line, so they cannot disagree. Each
 * reason comes from the Registrar's own refusal predicates (tier, held for a
 * plugin, governance, a domain's plugin not loaded), so the report says what
 * a call would be told.
 *
 * Tools of a tier this install lacks are a count per domain, never a list
 * of names: a site would otherwise pay for a hundred stubs. Plugin and governance groups name
 * their tools, since those are the ones an administrator can bring back one
 * plugin or toggle at a time.
 *
 * Compact mode hides tools from tools/list but leaves them callable through
 * call-tool, so a hidden tool is marked hidden_compact and is not counted as
 * unavailable. Reading the report grants nothing.
 */
final class Tool_Availability
{
    public const HANDSHAKE_LINE = 'Some tools are unavailable on this site. Call list-tools with include_unavailable to see which and why. A refused call returns a reason code and the human action needed.';

    /**
     * Every declared tool this site cannot run, grouped by reason.
     *
     * @return array{total: int, groups: list<array<string, mixed>>}
     */
    public static function report(?Registrar $registrar = null): array
    {
        $registrar   = $registrar ?? Plugin::instance()->registrar();
        $pro         = [];
        $plugins     = [];
        $governance  = [];
        $candidates  = [];

        foreach ($registrar->declared() as $ability) {
            $candidates[ $ability->name ] = [ $ability, null ];
        }
        foreach ($registrar->held() as $name => $held) {
            $candidates[ $name ] = $candidates[ $name ] ?? [ $held['ability'], $held ];
        }
        ksort($candidates);

        foreach ($candidates as $name => [ $ability, $held ]) {
            if (null !== $registrar->get($name)) {
                $plugin = Registrar::missing_domain_plugin($ability->domain);
                if (null !== $plugin) {
                    $plugins[ $plugin ]['tools'][] = $name;
                    $plugins[ $plugin ]['several'] = false;
                }
                continue;
            }
            if (! Registrar::tier_permitted($ability->tier)) {
                $pro[ $ability->domain ] = ( $pro[ $ability->domain ] ?? 0 ) + 1;
                continue;
            }
            if (null !== $held) {
                $plugins[ $held['plugin'] ]['tools'][] = $name;
                $plugins[ $held['plugin'] ]['several'] = $held['several'];
                continue;
            }
            if (null !== $registrar->governance_disabled($name)) {
                $governance[] = $name;
            }
        }

        $groups = [];
        if ([] !== $pro) {
            ksort($pro);
            $count    = array_sum($pro);
            $groups[] = [
                'reason'  => 'pro_required',
                'label'   => sprintf('%d Pro tools: needs a WP MCP Pro license', $count),
                'count'   => $count,
                'domains' => $pro,
                'action'  => 'Activate a WP MCP Pro license on this site.' . Pro_Refusal::upgrade_sentence(),
            ];
        }
        ksort($plugins);
        $plugins_url = admin_url('plugins.php');
        foreach ($plugins as $plugin => $entry) {
            $count    = count($entry['tools']);
            $groups[] = [
                'reason' => 'plugin_missing',
                'label'  => $entry['several']
                    ? sprintf('%d tools: none of %s is active', $count, $plugin)
                    : sprintf('%s tools (%d): %s is not active', $plugin, $count, $plugin),
                'plugin' => $plugin,
                'count'  => $count,
                'tools'  => $entry['tools'],
                'action' => $entry['several']
                    ? sprintf('Install and activate one of %s (%s), then reconnect the MCP client.', $plugin, $plugins_url)
                    : sprintf('Install and activate %s (%s), then reconnect the MCP client.', $plugin, $plugins_url),
            ];
        }
        if ([] !== $governance) {
            $groups[] = [
                'reason' => 'governance_disabled',
                'label'  => sprintf('%d tools switched off by governance', count($governance)),
                'count'  => count($governance),
                'tools'  => $governance,
                'action' => 'An administrator can switch them back on under WP MCP > Abilities in wp-admin, or with wpmcp/update-governance-settings.',
            ];
        }

        return [
            'total'  => (int) array_sum(array_column($groups, 'count')),
            'groups' => $groups,
        ];
    }

    /** The report as one line, for the end of every list-tools reply. */
    public static function summary(array $report): string
    {
        if (0 === ( $report['total'] ?? 0 )) {
            return 'Every declared wpmcp tool is available on this site.';
        }

        return 'Unavailable here: ' . implode('; ', array_column($report['groups'], 'label'))
            . '. Call list-tools with include_unavailable:true to see which and why.';
    }

    /**
     * Whether anything is unavailable, cheaply, for the handshake: a name
     * declared or held but not registered, or a registered tool whose
     * domain plugin is not loaded.
     */
    public static function any(?Registrar $registrar = null): bool
    {
        $registrar = $registrar ?? Plugin::instance()->registrar();
        foreach ($registrar->declared() as $ability) {
            if (null === $registrar->get($ability->name)) {
                return true;
            }
        }
        if ([] !== $registrar->held()) {
            return true;
        }
        foreach ($registrar->all() as $ability) {
            if (null !== Registrar::missing_domain_plugin($ability->domain)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The availability block for one registered tool: available, or the
     * plugin its domain needs; plus hidden_compact when compact mode keeps
     * it out of tools/list (still callable through call-tool).
     *
     * @return array<string, mixed>
     */
    public static function for_registered(Ability $ability): array
    {
        $plugin = Registrar::missing_domain_plugin($ability->domain);
        $block  = null === $plugin
            ? [ 'available' => true ]
            : [ 'available' => false, 'reason' => 'plugin_missing', 'plugin' => $plugin ];

        if (self::hidden_compact($ability->name)) {
            $block['hidden_compact'] = true;
        }

        return $block;
    }

    /** The availability block for a refusal a meta-tool returns for an unregistered name. */
    public static function for_refusal(\WP_Error $refusal): array
    {
        $data  = $refusal->get_error_data();
        $block = [ 'available' => false, 'reason' => $refusal->get_error_code() ];
        if (is_array($data) && isset($data['plugin'])) {
            $block['plugin'] = $data['plugin'];
        }
        return $block;
    }

    /** Whether compact mode keeps this registered tool out of tools/list. */
    public static function hidden_compact(string $name, ?Tool_Exposure $exposure = null): bool
    {
        $exposure = $exposure ?? new Tool_Exposure();
        return $exposure->is_compact() && ! in_array($name, $exposure->compact_exposed(), true);
    }
}
