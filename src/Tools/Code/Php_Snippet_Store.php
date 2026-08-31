<?php

namespace WPMCP\Tools\Code;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Option-backed persistence for stored PHP snippets (issue #85). Pure
 * storage layer: no validation, no capability checks, and absolutely no
 * execution happens here. Snippets live in a single wpmcp_php_snippets
 * option (autoload off), but every governed write is snapshotted PER
 * RECORD through the 'php_snippet' object type
 * (Snapshot::capture_php_snippet()), not by copying the whole option, so
 * rolling back one snippet operation cannot destroy unrelated snippets
 * created after it.
 *
 * Record shape (all keys always present):
 *  - id:         wp_generate_uuid4() identifier
 *  - name:       admin-facing label (sanitize_text_field'd by the tools)
 *  - code:       the snippet source (never executed by this class)
 *  - status:     'inactive' | 'active'; every snippet is CREATED inactive
 *  - validation: last Php_Snippet_Validator::validate() report
 *  - created_at / updated_at: gmdate('c') timestamps
 *
 * SINGLE-WRITER ASSUMPTION, stated rather than pretended away: save() and
 * delete() are a read-modify-write over one option row, so two genuinely
 * concurrent snippet writes can lose one of them. The mutating tools keep
 * that window as small as they can by re-reading the record INSIDE the
 * Safe_Mutation closure and touching only the fields they own
 * (set_status(), update_fields()) rather than writing back a record read
 * before the snapshot. A per-row table would remove the window entirely
 * and is the upgrade path if snippet volume ever justifies it.
 *
 * The stored 'status' and 'validation' fields are BOOKKEEPING, not a
 * security boundary. wpmcp_php_snippets is on Option_Guard's denylist so
 * the generic option tools cannot rewrite them, but a manage_options
 * caller has other ways to reach wp_options. Anything that ever executes
 * a stored snippet MUST re-run Php_Snippet_Guard and
 * Php_Snippet_Validator against the code at call time; it must never
 * treat the persisted status or validation report as proof of anything.
 */
class Php_Snippet_Store
{
    public const OPTION_NAME = 'wpmcp_php_snippets';

    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_ACTIVE   = 'active';

    /**
     * Default bounds. Unbounded growth here is not academic: the option is
     * copied into every agent-visible listing and the records are PHP
     * source, so a loop of create calls could grow wp_options without
     * limit. Both are filterable, matching Cli_Job_Store's cap precedent.
     */
    public const DEFAULT_MAX_SNIPPETS   = 200;
    public const DEFAULT_MAX_CODE_BYTES = 65536;

    /**
     * Aggregate cap on the whole option. The per-snippet and per-count caps
     * multiply out to roughly 12.8 MB in ONE option row that is read and
     * rewritten on every CRUD call, which is past the point where a default
     * MySQL max_allowed_packet rejects the write. Bounding the total is what
     * actually keeps the store writable; the other two caps only bound its
     * shape.
     */
    public const DEFAULT_MAX_TOTAL_BYTES = 1048576;

    /** @return array<string, array> All well-formed stored snippets keyed by id. */
    public static function all(): array
    {
        $snippets = get_option(self::OPTION_NAME, []);
        if (! is_array($snippets)) {
            return [];
        }

        // A hand edit, a partial restore or a stray write can leave a
        // non-record in here. Filtering at the boundary means every caller
        // downstream can rely on the record shape instead of guarding it.
        $out = [];
        foreach ($snippets as $key => $snippet) {
            if (is_array($snippet) && isset($snippet['id']) && is_string($snippet['id'])) {
                $out[(string) $key] = $snippet;
            }
        }

        return $out;
    }

    /** A single snippet record by id, or null when unknown or malformed. */
    public static function get(string $id): ?array
    {
        $snippets = self::all();
        $snippet  = $snippets[$id] ?? null;

        return is_array($snippet) ? $snippet : null;
    }

    /**
     * The option exactly as stored, malformed entries included. all() is the
     * READ path and filters; this is the WRITE path's base, and it must not,
     * or an entry all() rejects would be destroyed by the next unrelated
     * write. "Filtered rather than fatalled on" is a promise about reading.
     */
    private static function raw(): array
    {
        $snippets = get_option(self::OPTION_NAME, []);

        return is_array($snippets) ? $snippets : [];
    }

    /**
     * Persist the whole option and REFUSE TO REPORT SUCCESS IF IT DID NOT
     * LAND. update_option() returns false both for a rejected write (past
     * MySQL's max_allowed_packet, for instance) and for a value that did not
     * change, so the no-op case is settled here first and only a genuine
     * change is required to return true. A silently dropped write that still
     * hands back an operation_id is the failure mode
     * Snapshot_Store::save()'s own docblock exists to prevent.
     */
    private static function persist(array $snippets): void
    {
        if (self::raw() === $snippets) {
            return;
        }

        if (! update_option(self::OPTION_NAME, $snippets, false)) {
            throw new \RuntimeException(
                'Refusing to report success: the snippet store write did not persist. The most likely cause is the stored size exceeding the database packet limit; lower wpmcp_php_snippet_max_total_bytes or delete a snippet.'
            );
        }
    }

    /**
     * Insert or replace one snippet record. Callers are responsible for
     * wrapping this in Safe_Mutation (object_type 'php_snippet', object_id
     * the snippet id) so the write is snapshotted and reversible.
     *
     * Mutates ONE key of the raw option rather than writing back the filtered
     * read: a malformed sibling record is tolerated on read and must survive
     * an unrelated write instead of being quietly purged by it.
     */
    public static function save(array $snippet): void
    {
        $snippets                 = self::raw();
        $snippets[$snippet['id']] = $snippet;
        self::persist($snippets);
    }

    /**
     * Merge $fields into an existing record and persist it, re-reading the
     * record first so a concurrent write to OTHER fields is not clobbered
     * by a stale copy. Throws when the record has gone since the caller
     * looked, rather than silently resurrecting a deleted snippet.
     *
     * @param array<string, mixed> $fields
     */
    public static function update_fields(string $id, array $fields): array
    {
        $snippet = self::get($id);
        if (null === $snippet) {
            throw new \RuntimeException("No stored snippet with id \"{$id}\"; it was removed since this operation started.");
        }

        $snippet               = array_merge($snippet, $fields);
        $snippet['id']         = $id;
        $snippet['updated_at'] = gmdate('c');
        self::save($snippet);

        return $snippet;
    }

    /** Flip one snippet's status, re-reading it first. See update_fields(). */
    public static function set_status(string $id, string $status): array
    {
        return self::update_fields($id, ['status' => $status]);
    }

    /**
     * Remove one snippet record; same Safe_Mutation expectation as save().
     * Same raw() base as save(), for the same reason: deleting snippet A must
     * not also delete a malformed sibling B that all() happens to reject.
     */
    public static function delete(string $id): void
    {
        $snippets = self::raw();
        unset($snippets[$id]);
        self::persist($snippets);
    }

    /** Whether a snippet id exists in the store. */
    public static function exists(string $id): bool
    {
        return null !== self::get($id);
    }

    /** Maximum number of stored snippets. */
    public static function max_snippets(): int
    {
        return max(1, (int) apply_filters('wpmcp_php_snippet_max_count', self::DEFAULT_MAX_SNIPPETS));
    }

    /** Maximum stored size, in bytes, of one snippet's code. */
    public static function max_code_bytes(): int
    {
        return max(1, (int) apply_filters('wpmcp_php_snippet_max_code_bytes', self::DEFAULT_MAX_CODE_BYTES));
    }

    /** Maximum serialized size, in bytes, of the whole snippet store option. */
    public static function max_total_bytes(): int
    {
        return max(1, (int) apply_filters('wpmcp_php_snippet_max_total_bytes', self::DEFAULT_MAX_TOTAL_BYTES));
    }

    /**
     * Refuse a write that would push the whole option past the aggregate cap.
     * Called by create and update with the record they are about to store, so
     * the refusal happens BEFORE Safe_Mutation snapshots anything: a write
     * rejected here leaves no operation_id claiming it happened.
     */
    public static function assert_total_within_limit(string $id, array $snippet): void
    {
        $snippets       = self::raw();
        $snippets[$id]  = $snippet;
        $size           = strlen(maybe_serialize($snippets));
        $limit          = self::max_total_bytes();

        if ($size > $limit) {
            throw new \RuntimeException(
                sprintf('Refusing to store snippet: the snippet store would be %d bytes, over the %d byte total limit (filter wpmcp_php_snippet_max_total_bytes). Delete a snippet first.', $size, $limit)
            );
        }
    }

    /**
     * Refuse a code body larger than the cap. Called by create and update
     * before anything is stored.
     */
    public static function assert_code_within_limit(string $code): void
    {
        $limit = self::max_code_bytes();
        if (strlen($code) > $limit) {
            throw new \RuntimeException(
                sprintf('Refusing to store snippet: code is %d bytes, over the %d byte limit (filter wpmcp_php_snippet_max_code_bytes).', strlen($code), $limit)
            );
        }
    }

    /** Refuse a new snippet once the store is full. Create only. */
    public static function assert_has_room(): void
    {
        $limit = self::max_snippets();
        $count = count(self::all());
        if ($count >= $limit) {
            throw new \RuntimeException(
                sprintf('Refusing to store snippet: %d snippets already stored, at the %d snippet limit (filter wpmcp_php_snippet_max_count). Delete one first.', $count, $limit)
            );
        }
    }
}
