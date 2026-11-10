<?php

namespace WPMCP\Safety;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Per-request note of the snapshot operation ids Safe_Mutation created, so an
 * observer wrapped around a tool call (MCP\Registrar's execute wrapper, issue
 * #134) can link that call's outcome row to the undo point it produced without
 * every tool having to report it.
 *
 * Safe_Mutation::run() appends an id here the moment the snapshot is safely
 * persisted, never before: a row may only advertise an undo point that really
 * exists.
 *
 * Observers bracket a call with mark() ... since($mark) rather than resetting
 * the list, so a tool that dispatches another tool (Tools\Dispatch\Call_Tool)
 * cannot clear the outer observer's view. since() returns the FIRST id noted
 * after the mark, which is the earliest undo point for that call: restoring it
 * rewinds the furthest back, which is what an admin chasing a suspect write
 * wants.
 */
class Operation_Context
{
    /** @var string[] Operation ids noted during this request, in creation order. */
    private static array $operation_ids = [];

    /** Record one persisted snapshot's operation id. Empty ids are ignored. */
    public static function note(string $operation_id): void
    {
        if ('' === $operation_id) {
            return;
        }
        self::$operation_ids[] = $operation_id;
    }

    /**
     * Withdraw an id whose undo point was voided again (Safe_Mutation, issue
     * #728: a write that failed without changing anything), so no observer
     * links a call to an undo point that no longer exists.
     */
    public static function forget(string $operation_id): void
    {
        self::$operation_ids = array_values(array_filter(
            self::$operation_ids,
            static fn (string $id): bool => $id !== $operation_id
        ));
    }

    /** A cursor into the current list, to be handed back to since() later. */
    public static function mark(): int
    {
        return count(self::$operation_ids);
    }

    /** The first operation id noted after $mark, or null if there was none. */
    public static function since(int $mark): ?string
    {
        return self::$operation_ids[ $mark ] ?? null;
    }

    /** @return string[] Every operation id noted so far this request. */
    public static function all(): array
    {
        return self::$operation_ids;
    }

    /** Clear the list. Production never needs this (state dies with the request); tests do. */
    public static function reset(): void
    {
        self::$operation_ids = [];
    }
}
