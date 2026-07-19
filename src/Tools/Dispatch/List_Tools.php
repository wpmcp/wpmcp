<?php

namespace WPMCP\Tools\Dispatch;

use WPMCP\MCP\Ability;
use WPMCP\Plugin;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only: enumerate every ability this wpmcp install currently registers
 * (the same set the full-mode tools/list advertises), so an agent on the
 * compact surface can discover the long tail before dispatching through
 * call-tool.
 *
 * Curated by default (issue #79: "curated schemas served by default with
 * full schemas behind a flag"): each entry carries the name, a clamped
 * summary, and the domain/operation/tier classification. full:true adds the
 * complete description and the MCP annotation hints; per-tool input schemas
 * stay behind get-tool-schema so a listing never reintroduces the token
 * bloat compact mode exists to cut.
 *
 * The listing reflects the REGISTERED surface: abilities disabled by
 * Governance or gated off by tier never registered, so they never appear.
 * It deliberately does not pre-filter by the caller's per-tool permissions —
 * tool names and descriptions are static product surface, not site data,
 * and the real permission decision is made (and audited) when a tool is
 * actually invoked.
 */
class List_Tools
{
    /** Character clamp for the curated per-tool summary. */
    public const SUMMARY_LENGTH = 160;

    public function handle(array $args): array
    {
        $domain = isset($args['domain']) ? (string) $args['domain'] : '';
        $full   = ! empty($args['full']);

        $abilities = Plugin::instance()->registrar()->all();
        usort($abilities, static fn(Ability $a, Ability $b) => strcmp($a->name, $b->name));

        $tools = [];
        foreach ($abilities as $ability) {
            if ('' !== $domain && $ability->domain !== $domain) {
                continue;
            }

            $entry = [
                'name'      => $ability->name,
                'summary'   => mb_substr($ability->description, 0, self::SUMMARY_LENGTH),
                'domain'    => $ability->domain,
                'operation' => $ability->operation,
                'tier'      => $ability->tier,
            ];

            if ($full) {
                $entry['description'] = $ability->description;
                $entry['annotations'] = [
                    'readOnlyHint'    => $ability->read_only_hint,
                    'destructiveHint' => $ability->destructive_hint,
                    'idempotentHint'  => $ability->idempotent_hint,
                ];
            }

            $tools[] = $entry;
        }

        return [
            'total' => count($tools),
            'tools' => $tools,
        ];
    }
}
