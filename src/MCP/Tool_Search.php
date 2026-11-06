<?php

namespace WPMCP\MCP;

use WPMCP\Integrations\Integration_Dispatcher;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The search filter shared by list-tool-catalog and list-tools (issue #732).
 *
 * A term matches a tool when it appears, case-insensitively, in the tool's
 * name, its description, or the name of an operation inside it. The last
 * one is what makes integrations that have no tools of their own findable:
 * Pods and TablePress are ops on plugin-data-read/-write and Redirection is
 * ops on theme-read/-write, so "pods" finds the plugin-data pair through
 * pods-list-fields and friends. Only a dispatcher pair has operations; for
 * every other tool the operation list is empty.
 */
final class Tool_Search
{
    /**
     * Null when the ability does not match $search, otherwise the names of
     * its operations that matched (empty when only the name or description
     * did, or when the tool has no operations).
     *
     * @return string[]|null
     */
    public static function match(Ability $ability, string $search): ?array
    {
        $needle = strtolower(trim($search));
        if ('' === $needle) {
            return [];
        }

        $ops = array_values(array_filter(
            self::operation_names($ability),
            static fn (string $op): bool => str_contains(strtolower($op), $needle)
        ));

        $hit = [] !== $ops
            || str_contains(strtolower($ability->name), $needle)
            || str_contains(strtolower($ability->description), $needle);

        return $hit ? $ops : null;
    }

    /**
     * The operations a dispatcher half answers, read from the integration
     * that owns its handler; empty for any other tool.
     *
     * @return string[]
     */
    public static function operation_names(Ability $ability): array
    {
        $handler = $ability->handler;
        if (! is_array($handler) || ! isset($handler[0], $handler[1]) || ! $handler[0] instanceof Integration_Dispatcher) {
            return [];
        }

        return $handler[0]->operation_names('handle_read' === $handler[1] ? 'read' : 'write');
    }
}
