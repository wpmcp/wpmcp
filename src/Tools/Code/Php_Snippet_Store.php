<?php

namespace WPMCP\Tools\Code;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Option-backed persistence for stored PHP snippets (issue #85). Pure
 * storage layer: no validation, no capability checks, and absolutely no
 * execution happens here. Snippets live in a single wpmcp_php_snippets
 * option (autoload off) so snippet CRUD can be routed through
 * Safe_Mutation with object_type 'option' and be snapshot-first and
 * reversible like every other governed mutation.
 *
 * Record shape (all keys always present):
 *  - id:         wp_generate_uuid4() identifier
 *  - name:       admin-facing label
 *  - code:       the snippet source (never executed by this class)
 *  - status:     'inactive' | 'active'; every snippet is CREATED inactive
 *  - validation: last Php_Snippet_Validator::validate() report
 *  - created_at / updated_at: gmdate('c') timestamps
 */
class Php_Snippet_Store
{
    public const OPTION_NAME = 'wpmcp_php_snippets';

    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_ACTIVE   = 'active';

    /** @return array<string, array> All stored snippets keyed by id. */
    public static function all(): array
    {
        $snippets = get_option(self::OPTION_NAME, []);

        return is_array($snippets) ? $snippets : [];
    }

    /** A single snippet record by id, or null when unknown. */
    public static function get(string $id): ?array
    {
        $snippets = self::all();

        return $snippets[$id] ?? null;
    }

    /**
     * Insert or replace one snippet record. Callers are responsible for
     * wrapping this in Safe_Mutation (object_type 'option', object_id
     * self::OPTION_NAME) so the write is snapshotted and reversible.
     */
    public static function save(array $snippet): void
    {
        $snippets                    = self::all();
        $snippets[$snippet['id']]    = $snippet;
        update_option(self::OPTION_NAME, $snippets, false);
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
}
