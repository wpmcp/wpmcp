<?php

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols -- ABSPATH guard is an intentional side effect.
// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps -- WP-style snake_case class name is intentional (matches brief's public interface).
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WP-style snake_case method names are intentional (matches brief's public interface).

namespace WPMCP\Safety;

if (! defined('ABSPATH')) {
    exit;
}

class Rollback_Service
{
    public static function restore_operation(string $operation_id): bool
    {
        $row = Snapshot_Store::get_by_operation($operation_id);
        if (! $row) {
            return false;
        }
        self::apply_snapshot($row['snapshot']);
        return true;
    }

    public static function restore_session(string $session_id): int
    {
        $rows = Snapshot_Store::list_by_session($session_id); // newest first
        $rows = array_reverse($rows); // oldest first, so we can unwind to the earliest

        // Restore the OLDEST snapshot per object (its pre-session state).
        $seen  = [];
        $count = 0;
        foreach ($rows as $r) {
            $key = $r['object_type'] . ':' . $r['object_id'];
            if (isset($seen[ $key ])) {
                $count++;
                continue;
            }
            $seen[ $key ] = true;
            self::apply_snapshot(Snapshot::unserialize($r['before_blob']));
            $count++;
        }
        return $count;
    }

    /**
     * Restore an object to the exact state captured in $snapshot.
     *
     * For 'post' objects this must be a FULL restore, not an additive merge:
     * any meta key that exists on the object now but was NOT present in the
     * snapshot (i.e. it was added by the mutation being undone) must be
     * deleted. Otherwise a rollback can leave orphan meta behind, violating
     * the safety invariant that a restored object matches its pre-mutation
     * state exactly.
     */
    public static function apply_snapshot(array $snapshot): void
    {
        if ('post' !== $snapshot['object_type']) {
            return;
        }

        if ($snapshot['data']['post']) {
            wp_update_post(array_merge(['ID' => $snapshot['object_id']], $snapshot['data']['post']));
        }

        $snapshotted_meta = (array) $snapshot['data']['meta'];
        $current_meta     = get_post_meta($snapshot['object_id']);

        // Purge any meta key that didn't exist at snapshot time (newly added by the mutation).
        foreach (array_keys(array_diff_key($current_meta, $snapshotted_meta)) as $key) {
            delete_post_meta($snapshot['object_id'], $key);
        }

        // Restore snapshotted keys/values exactly as captured.
        foreach ($snapshotted_meta as $key => $values) {
            delete_post_meta($snapshot['object_id'], $key);
            foreach ((array) $values as $v) {
                add_post_meta($snapshot['object_id'], $key, maybe_unserialize($v));
            }
        }
    }
}
