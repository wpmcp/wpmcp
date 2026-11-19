<?php

namespace WPMCP\Bench;

/**
 * The surfaces a scenario is replayed on (issue #1130).
 *
 * full: the agent sees every tool's name, description and input schema in
 * tools/list, so the scenario's own steps are the whole sequence.
 *
 * compact (#79): the agent sees five tools. Before it can call anything
 * else it has to find the name (wpmcp/list-tools with a search term) and
 * learn the arguments (wpmcp/get-tool-schema), then every call goes through
 * wpmcp/call-tool. compact() derives that sequence from the full one, so
 * the two never drift apart:
 *
 *   1. one turn of list-tools searches, one call per term the scenario names;
 *   2. one turn of get-tool-schema calls, one per distinct tool, in order of
 *      first use. A tool no search returned fails the run here: the
 *      sequence would not be one an agent could follow;
 *   3. the scenario's steps, each call wrapped as call-tool {name, arguments}.
 *      call-tool returns the target's own result, so the step closures read
 *      the same values as on full.
 *
 * Tools in the compact core are called directly and need no discovery.
 *
 * A later surface (run-plan, #1133) adds its own derivation here.
 */
final class Surface
{
    public const FULL    = 'full';
    public const COMPACT = 'compact';

    /** Kept in step with WPMCP\MCP\Tool_Exposure::COMPACT_CORE, checked by the bench run. */
    public const COMPACT_CORE = [
        'wpmcp/list-tools',
        'wpmcp/get-tool-schema',
        'wpmcp/call-tool',
        'wpmcp/get-connection-info',
        'wpmcp/get-site-context',
    ];

    /**
     * @param string[]                         $searches list-tools search terms an agent would try.
     * @param array<int, array<string, mixed>> $steps    the full-surface steps.
     * @return array<int, array<string, mixed>>
     */
    public static function compact(array $searches, array $steps): array
    {
        $needed = [];
        foreach ($steps as $step) {
            $tool = (string) ($step['tool'] ?? '');
            if ('' !== $tool && ! in_array($tool, self::COMPACT_CORE, true) && ! in_array($tool, $needed, true)) {
                $needed[] = $tool;
            }
        }

        $out = [];
        if ([] !== $searches) {
            $out[] = [
                'tool' => 'wpmcp/list-tools',
                'each' => static fn(): array => array_map(static fn(string $term): array => ['search' => $term], array_values($searches)),
                'as'   => '_compact_search',
            ];
        }

        if ([] !== $needed) {
            $out[] = [
                'tool' => 'wpmcp/get-tool-schema',
                'each' => static function (array $state) use ($needed): array {
                    $found = [];
                    foreach ((array) ($state['_compact_search'] ?? []) as $listing) {
                        foreach ((array) ($listing['tools'] ?? []) as $hit) {
                            if (isset($hit['name'])) {
                                $found[ (string) $hit['name'] ] = true;
                            }
                        }
                    }
                    $args = [];
                    foreach ($needed as $tool) {
                        if (! isset($found[ $tool ])) {
                            throw new \RuntimeException(sprintf('compact: no list-tools search returned %s, so an agent could not find it', $tool));
                        }
                        $args[] = ['name' => $tool];
                    }
                    return $args;
                },
                'as'   => '_compact_schemas',
            ];
        }

        foreach ($steps as $step) {
            $tool = (string) ($step['tool'] ?? '');
            if (in_array($tool, self::COMPACT_CORE, true)) {
                $out[] = $step;
                continue;
            }

            $wrapped = ['tool' => 'wpmcp/call-tool', 'label' => $tool];
            if (isset($step['as'])) {
                $wrapped['as'] = $step['as'];
            }
            if (array_key_exists('each', $step)) {
                $each            = $step['each'];
                $wrapped['each'] = static function (array $state) use ($each, $tool): array {
                    $list = $each instanceof \Closure ? $each($state) : $each;
                    return array_map(static fn($args): array => ['name' => $tool, 'arguments' => $args], (array) $list);
                };
            } else {
                $args            = $step['args'] ?? [];
                $wrapped['args'] = static function (array $state) use ($args, $tool): array {
                    return ['name' => $tool, 'arguments' => $args instanceof \Closure ? $args($state) : $args];
                };
            }
            $out[] = $wrapped;
        }

        return $out;
    }
}
