<?php

namespace WPMCP\Tools\Portable;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * One store's side of the portable bundle (issue #297). Each store supplies
 * its own adapter, next to its own code, so the bundle core names no store
 * and a build that leaves a store out simply has no adapter for it.
 */
interface Bundle_Kind
{
    /** The item `type` this kind reads and writes, e.g. "snippet". */
    public function type(): string;

    /** Whether this site may export and import this kind right now. */
    public function available(): bool;

    /**
     * Bundle items for this store, all of them or only those whose store id
     * is in $ids (compared as strings).
     *
     * @param string[]|null $ids
     * @return array<int,array>
     */
    public function export(?array $ids): array;

    /**
     * Validate one item with the store's own validator and create it
     * INACTIVE, recording the creation under $session_id so rollback-session
     * undoes it. $rename picks a free name on a collision instead of refusing.
     *
     * @return array{id:int|string,name:string,renamed_from?:string}
     * @throws Bundle_Item_Refused when the item is invalid, collides, or the
     *                             store refuses it; nothing is left behind.
     */
    public function import(array $item, bool $rename, string $session_id): array;
}
