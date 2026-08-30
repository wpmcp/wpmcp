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
     * Insert or replace one snippet record. Callers are responsible for
     * wrapping this in Safe_Mutation (object_type 'php_snippet', object_id
     * the snippet id) so the write is snapshotted and reversible.
     */
    public static function save(array $snippet): void
    {
        $snippets                 = self::all();
        $snippets[$snippet['id']] = $snippet;
        update_option(self::OPTION_NAME, $snippets, false);
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

    /** Remove one snippet record; same Safe_Mutation expectation as save(). */
    public static function delete(string $id): void
    {
        $snippets = self::all();
        unset($snippets[$id]);
        update_option(self::OPTION_NAME, $snippets, false);
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
