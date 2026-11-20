<?php

// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps -- WP-style snake_case class name is intentional (matches the rest of WPMCP\Tools).
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WP-style snake_case method names are intentional (matches the rest of WPMCP\Tools).
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- cleanup reads and counts core table rows no WP API enumerates (orphans, revisions past N, expired transient rows); nothing here is worth caching, and every delete goes through Db_Cleanup_Snapshot.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- only $wpdb core table names, integer id lists and this class's fixed SQL fragments are concatenated in; every value is a prepare() placeholder, bound through a spread whose count the sniff cannot follow, and every table and column a segment picks is bound with %i (issue #1147).

namespace WPMCP\Tools\Diagnostics;

use WPMCP\Governance\Governance_Audit_Log;
use WPMCP\MCP\Confirmation_Required;
use WPMCP\Safety\Db_Cleanup_Snapshot;
use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Database cleanup behind delete-transient's cleanup list (issue #414).
 *
 * Categories: expired_transients, revisions (all but the keep most recent per
 * post), auto_drafts (older than a week, as core's own purge), trash (posts
 * and comments trashed more than days ago), spam_comments and orphaned_meta
 * (post, comment, term and user meta whose object is gone).
 *
 * A dry run is the default: per category it reports how many items would go,
 * the bytes that frees (an estimate from the main text columns), a sample of
 * ids and how many are skipped. Nothing is written.
 *
 * A real run needs dry_run:false, confirm:true and manage_options, and is
 * recorded in the governance audit log. Every row it deletes, except expired
 * transients (cache data), is first copied verbatim into one db_cleanup
 * snapshot, and only those exact rows are then deleted by primary key, so
 * rollback-operation puts back exactly what went. Core's delete APIs are
 * deliberately not used: their hooks let other plugins delete rows of their
 * own that the snapshot would not hold.
 *
 * So anything whose removal would touch more than the snapshot holds is
 * skipped and reported: attachments (their files), posts with child posts
 * and comments with replies (core would reparent the children). A post goes
 * with its own revisions, meta, term relationships, comments and comment
 * meta.
 *
 * Each call is bounded by an item cap, a snapshot byte cap and a time budget.
 * When one is reached the call stops and returns a cursor; passing it back
 * continues where this call stopped, so large sites are cleaned over several
 * calls, each its own undo point.
 */
class Db_Cleanup
{
    public const ABILITY = 'wpmcp/delete-transient';

    public const CATEGORIES = [ 'expired_transients', 'revisions', 'auto_drafts', 'trash', 'spam_comments', 'orphaned_meta' ];

    /** Work segments in processing order, each mapped to its category. */
    private const SEGMENTS = [
        'expired_transients'   => 'expired_transients',
        'revisions'            => 'revisions',
        'auto_drafts'          => 'auto_drafts',
        'trash_posts'          => 'trash',
        'trash_comments'       => 'trash',
        'spam_comments'        => 'spam_comments',
        'orphaned_postmeta'    => 'orphaned_meta',
        'orphaned_commentmeta' => 'orphaned_meta',
        'orphaned_termmeta'    => 'orphaned_meta',
        'orphaned_usermeta'    => 'orphaned_meta',
    ];

    /** Orphaned meta segment => [meta table, meta pk, owner column, owner table, owner pk]. */
    private const META = [
        'orphaned_postmeta'    => [ 'postmeta', 'meta_id', 'post_id', 'posts', 'ID' ],
        'orphaned_commentmeta' => [ 'commentmeta', 'meta_id', 'comment_id', 'comments', 'comment_ID' ],
        'orphaned_termmeta'    => [ 'termmeta', 'meta_id', 'term_id', 'terms', 'term_id' ],
        'orphaned_usermeta'    => [ 'usermeta', 'umeta_id', 'user_id', 'users', 'ID' ],
    ];

    public const MAX_ITEMS       = 200;
    public const MAX_BYTES       = 4 * MB_IN_BYTES;
    public const TIME_BUDGET     = 10.0;
    public const DEFAULT_KEEP    = 5;
    public const AUTO_DRAFT_DAYS = 7;
    private const SAMPLE         = 5;
    private const BATCH          = 50;
    private const MAX_SKIP_LIST  = 20;

    /** @var callable(): float */
    private $clock;

    private int $max_items;
    private int $max_bytes;
    private float $time_budget;

    private int $keep = self::DEFAULT_KEEP;
    private int $days = 0;

    public function __construct(?callable $clock = null, int $max_items = self::MAX_ITEMS, int $max_bytes = self::MAX_BYTES, float $time_budget = self::TIME_BUDGET)
    {
        $this->clock       = $clock ?? static fn (): float => microtime(true);
        $this->max_items   = max(1, $max_items);
        $this->max_bytes   = max(1, $max_bytes);
        $this->time_budget = $time_budget;
    }

    public function run(array $args): array
    {
        $categories = self::categories($args['cleanup'] ?? null);
        $this->keep = max(0, (int) ($args['keep'] ?? self::DEFAULT_KEEP));
        $this->days = max(0, (int) ($args['days'] ?? 0));
        [ $segment, $after ] = self::parse_cursor($args['cursor'] ?? null);

        if (false !== ($args['dry_run'] ?? true)) {
            return $this->dry_run($categories);
        }

        if (! current_user_can('manage_options')) {
            Governance_Audit_Log::record_quietly(self::ABILITY, false, 'db-cleanup:refused-capability');
            throw new \RuntimeException('Applying a database cleanup requires the manage_options capability.');
        }
        if (true !== ($args['confirm'] ?? null)) {
            throw new Confirmation_Required('Applying a database cleanup deletes rows; it requires confirm:true (run the dry run first).');
        }

        return $this->apply($categories, $segment, $after, (string) ($args['session_id'] ?? 'default'), $args);
    }

    /** @return string[] */
    private static function categories($raw): array
    {
        if (is_string($raw)) {
            $raw = [ $raw ];
        }
        if (! is_array($raw) || [] === $raw) {
            throw new \InvalidArgumentException('cleanup must list at least one of: ' . esc_html(implode(', ', self::CATEGORIES)) . '.');
        }
        $out = [];
        foreach ($raw as $category) {
            $category = (string) $category;
            if (! in_array($category, self::CATEGORIES, true)) {
                throw new \InvalidArgumentException(esc_html(sprintf('Unknown cleanup category "%s"; use: %s.', $category, implode(', ', self::CATEGORIES))));
            }
            $out[ $category ] = true;
        }
        // Report in the fixed category order, whatever order was asked for.
        return array_values(array_filter(self::CATEGORIES, static fn (string $c): bool => isset($out[ $c ])));
    }

    /** @return array{0: ?string, 1: int} */
    private static function parse_cursor($cursor): array
    {
        if (null === $cursor || '' === $cursor) {
            return [ null, -1 ];
        }
        if (! is_string($cursor) || 1 !== preg_match('/^([a-z_]+):(-?\d+)$/', $cursor, $m) || ! isset(self::SEGMENTS[ $m[1] ])) {
            throw new \InvalidArgumentException('Invalid cursor; pass back the cursor a previous cleanup call returned.');
        }
        return [ $m[1], (int) $m[2] ];
    }

    /** @param string[] $categories @return string[] */
    private static function segments(array $categories): array
    {
        return array_keys(array_filter(self::SEGMENTS, static fn (string $c): bool => in_array($c, $categories, true)));
    }

    // ------------------------------------------------------------------
    // Dry run
    // ------------------------------------------------------------------

    private function dry_run(array $categories): array
    {
        $report = [];
        foreach ($categories as $category) {
            $report[ $category ] = [ 'count' => 0, 'bytes' => 0, 'skipped' => 0, 'sample' => [], 'recoverable' => 'expired_transients' !== $category ];
        }
        foreach (self::segments($categories) as $segment) {
            $category = self::SEGMENTS[ $segment ];
            [ $count, $bytes, $skipped ] = $this->measure($segment);
            $report[ $category ]['count']   += $count;
            $report[ $category ]['bytes']   += $bytes;
            $report[ $category ]['skipped'] += $skipped;
            foreach ($this->units($segment, -1) as $unit) {
                if (count($report[ $category ]['sample']) >= self::SAMPLE) {
                    break;
                }
                $report[ $category ]['sample'][] = $unit['id'];
            }
        }

        $notes = [ 'Dry run: nothing was deleted. bytes are estimates. Apply with dry_run:false and confirm:true.' ];
        return [
            'dry_run'    => true,
            'categories' => $report,
            'notes'      => array_merge($notes, self::notes($categories, $report)),
        ];
    }

    /** @return string[] */
    private static function notes(array $categories, array $report): array
    {
        $notes = [];
        if (in_array('expired_transients', $categories, true)) {
            $notes[] = 'Expired transients are deleted without a snapshot: rollback-operation cannot restore them.';
        }
        $skipped = array_sum(array_map(static fn (array $r): int => (int) $r['skipped'], $report));
        if ($skipped > 0) {
            $notes[] = 'Skipped, because the snapshot could not restore everything deleting them changes: attachments (their files), posts with child posts and comments with replies.';
        }
        return $notes;
    }

    /** @return array{0: int, 1: int, 2: int} count, estimated bytes, skipped */
    private function measure(string $segment): array
    {
        global $wpdb;

        if ('expired_transients' === $segment) {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT COUNT(*) AS n, COALESCE(SUM(LENGTH(t.option_name) + LENGTH(t.option_value) + COALESCE(LENGTH(v.option_name) + LENGTH(v.option_value), 0)), 0) AS b
                 FROM {$wpdb->options} t LEFT JOIN {$wpdb->options} v ON v.option_name = " . self::TRANSIENT_VALUE_NAME . '
                 WHERE ' . self::TRANSIENT_WHERE,
                ...self::transient_args()
            ), ARRAY_A);
            return [ (int) ($row['n'] ?? 0), (int) ($row['b'] ?? 0), 0 ];
        }

        if ('revisions' === $segment) {
            $count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(n - %d), 0) FROM (SELECT COUNT(*) AS n FROM {$wpdb->posts} WHERE post_type = 'revision' GROUP BY post_parent HAVING COUNT(*) > %d) AS t",
                $this->keep,
                $this->keep
            ));
            $avg = (float) $wpdb->get_var("SELECT COALESCE(AVG(LENGTH(post_content) + LENGTH(post_title) + LENGTH(post_excerpt)), 0) FROM {$wpdb->posts} WHERE post_type = 'revision'");
            return [ $count, (int) round($count * $avg), 0 ];
        }

        if (isset(self::META[ $segment ])) {
            [ $meta, , $owner_col, $owner, $owner_pk ] = self::META[ $segment ];
            $row = $wpdb->get_row($wpdb->prepare(
                'SELECT COUNT(*) AS n, COALESCE(SUM(LENGTH(m.meta_key) + COALESCE(LENGTH(m.meta_value), 0)), 0) AS b
                 FROM %i m LEFT JOIN %i o ON o.%i = m.%i WHERE o.%i IS NULL',
                $wpdb->$meta,
                $wpdb->$owner,
                $owner_pk,
                $owner_col,
                $owner_pk
            ), ARRAY_A);
            return [ (int) ($row['n'] ?? 0), (int) ($row['b'] ?? 0), 0 ];
        }

        if (in_array($segment, [ 'auto_drafts', 'trash_posts' ], true)) {
            [ $variant, $values ] = $this->post_where($segment);
            $where = (0 === $variant ? self::WHERE_AUTO_DRAFTS : (1 === $variant ? self::WHERE_TRASH_POSTS : self::WHERE_AGED_TRASH_POSTS));
            $all   = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i p WHERE {$where}", ...array_merge([ $wpdb->posts ], $values)));
            $row   = $wpdb->get_row($wpdb->prepare(
                "SELECT COUNT(*) AS n, COALESCE(SUM(LENGTH(p.post_content) + LENGTH(p.post_title) + LENGTH(p.post_excerpt)), 0) AS b
                 FROM %i p WHERE {$where} AND " . self::POST_ELIGIBLE,
                ...array_merge([ $wpdb->posts ], $values, [ $wpdb->posts ])
            ), ARRAY_A);
            $meta = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(LENGTH(m.meta_key) + COALESCE(LENGTH(m.meta_value), 0)), 0)
                 FROM %i m JOIN %i p ON p.ID = m.post_id WHERE {$where} AND " . self::POST_ELIGIBLE,
                ...array_merge([ $wpdb->postmeta, $wpdb->posts ], $values, [ $wpdb->posts ])
            ));
            $n = (int) ($row['n'] ?? 0);
            return [ $n, (int) ($row['b'] ?? 0) + $meta, $all - $n ];
        }

        [ $variant, $values ] = $this->comment_where($segment);
        $where = (0 === $variant ? self::WHERE_SPAM_COMMENTS : (1 === $variant ? self::WHERE_TRASH_COMMENTS : self::WHERE_AGED_TRASH_COMMENTS));
        $all   = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i c WHERE {$where}", ...array_merge([ $wpdb->comments ], $values)));
        $row   = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) AS n, COALESCE(SUM(LENGTH(c.comment_content) + LENGTH(c.comment_author) + LENGTH(c.comment_author_email)), 0) AS b
             FROM %i c WHERE {$where} AND " . self::COMMENT_ELIGIBLE,
            ...array_merge([ $wpdb->comments ], $values, [ $wpdb->comments ])
        ), ARRAY_A);
        $meta = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(LENGTH(m.meta_key) + COALESCE(LENGTH(m.meta_value), 0)), 0)
             FROM %i m JOIN %i c ON c.comment_ID = m.comment_id WHERE {$where} AND " . self::COMMENT_ELIGIBLE,
            ...array_merge([ $wpdb->commentmeta, $wpdb->comments ], $values, [ $wpdb->comments ])
        ));
        $n = (int) ($row['n'] ?? 0);
        return [ $n, (int) ($row['b'] ?? 0) + $meta, $all - $n ];
    }

    // ------------------------------------------------------------------
    // Real run
    // ------------------------------------------------------------------

    private function apply(array $categories, ?string $cursor_segment, int $cursor_after, string $session_id, array $args): array
    {
        $start    = ($this->clock)();
        $segments = self::segments($categories);
        $order    = array_keys(self::SEGMENTS);
        $report   = [];
        foreach ($categories as $category) {
            $report[ $category ] = [ 'deleted' => 0, 'bytes' => 0, 'skipped' => 0, 'sample' => [], 'recoverable' => 'expired_transients' !== $category ];
        }

        $tables      = [];
        $collected   = [];
        $transients  = [];
        $items       = 0;
        $snap_bytes  = 0;
        $skip_list   = [];
        $stopped_at  = null;

        foreach ($segments as $segment) {
            $after = -1;
            if (null !== $cursor_segment) {
                $position = array_search($segment, $order, true);
                $resume   = array_search($cursor_segment, $order, true);
                if ($position < $resume) {
                    continue;
                }
                if ($segment === $cursor_segment) {
                    $after = $cursor_after;
                }
            }
            $category = self::SEGMENTS[ $segment ];
            $report[ $category ]['skipped'] += $this->measure_skipped($segment);

            $last = $after;
            foreach ($this->units($segment, $after) as $unit) {
                $resume_key = min($last, $unit['key'] - 1);
                $size       = (int) $unit['size'];
                if ($items >= $this->max_items || (($this->clock)() - $start) >= $this->time_budget || ($snap_bytes + $size > $this->max_bytes && $size <= $this->max_bytes)) {
                    $stopped_at = $segment . ':' . $resume_key;
                    break 2;
                }
                $last = $unit['key'];
                if (null !== $unit['skip'] || $size > $this->max_bytes) {
                    $report[ $category ]['skipped']++;
                    if (count($skip_list) < self::MAX_SKIP_LIST) {
                        $skip_list[] = [ 'category' => $category, 'id' => $unit['id'], 'reason' => $unit['skip'] ?? 'too large to snapshot in one call' ];
                    }
                    continue;
                }
                $items++;
                $snap_bytes += $size;
                // Two categories can reach the same row in one call (a trashed
                // post's excess revisions); each row is snapshotted once.
                foreach ((array) $unit['rows'] as $table => $rows) {
                    foreach ($rows as $row) {
                        $id = $table . ':' . implode(',', array_map(static fn (string $c): string => (string) $row[ $c ], Db_Cleanup_Snapshot::primary_key($table)));
                        if (! isset($collected[ $id ])) {
                            $collected[ $id ] = true;
                            $tables[ $table ][] = $row;
                        }
                    }
                }
                foreach ((array) ($unit['options'] ?? []) as $option) {
                    $transients[] = $option;
                }
                $report[ $category ]['deleted']++;
                $report[ $category ]['bytes'] += (int) $unit['bytes'];
                if (count($report[ $category ]['sample']) < self::SAMPLE) {
                    $report[ $category ]['sample'][] = $unit['id'];
                }
            }
        }

        $operation_id = null;
        if ([] !== $tables) {
            $out = Safe_Mutation::run(
                [
                    'object_type'         => Db_Cleanup_Snapshot::TYPE,
                    'object_id'           => 'cleanup',
                    'session_id'          => $session_id,
                    'tool_name'           => 'delete-transient',
                    'args'                => $args,
                    'extra_snapshot_data' => [ 'tables' => $tables ],
                ],
                static function () use ($tables): bool {
                    Db_Cleanup_Snapshot::delete_rows($tables);
                    return true;
                }
            );
            $operation_id = (string) $out['operation_id'];
        }
        foreach ($transients as $option) {
            delete_option($option);
        }

        $deleted = array_sum(array_map(static fn (array $r): int => (int) $r['deleted'], $report));
        Governance_Audit_Log::record_quietly(self::ABILITY, true, sprintf('db-cleanup:%s:%d', implode(',', $categories), $deleted));

        $notes = self::notes($categories, $report);
        if (null !== $stopped_at) {
            $notes[] = 'Stopped at this call\'s cap; call again with cursor to continue.';
        }
        if (null !== $operation_id) {
            $notes[] = 'rollback-operation with operation_id restores the deleted rows.';
        }

        return [
            'dry_run'      => false,
            'categories'   => $report,
            'skipped'      => $skip_list,
            'operation_id' => $operation_id,
            'cursor'       => $stopped_at,
            'done'         => null === $stopped_at,
            'notes'        => $notes,
        ];
    }

    /** How many items of a segment the SQL-level rules skip (attachments, parents). */
    private function measure_skipped(string $segment): int
    {
        if (! in_array($segment, [ 'auto_drafts', 'trash_posts', 'trash_comments', 'spam_comments' ], true)) {
            return 0;
        }
        return $this->measure($segment)[2];
    }

    // ------------------------------------------------------------------
    // Units: one deletable item each, with every row it takes along
    // ------------------------------------------------------------------

    /**
     * Each unit: id (reported), key (cursor position), rows (table => rows),
     * options (transient options, not snapshotted), bytes (data freed),
     * size (snapshot bytes) and skip (a reason, or null).
     *
     * @return \Generator<int, array<string, mixed>>
     */
    private function units(string $segment, int $after): \Generator
    {
        if ('expired_transients' === $segment) {
            yield from $this->transient_units($after);
        } elseif ('revisions' === $segment) {
            yield from $this->revision_units($after);
        } elseif (isset(self::META[ $segment ])) {
            yield from $this->meta_units($segment, $after);
        } elseif (in_array($segment, [ 'auto_drafts', 'trash_posts' ], true)) {
            yield from $this->post_units($segment, $after);
        } else {
            yield from $this->comment_units($segment, $after);
        }
    }

    private const TRANSIENT_WHERE = '(t.option_name LIKE %s OR t.option_name LIKE %s) AND CAST(t.option_value AS UNSIGNED) < %d';

    private const TRANSIENT_VALUE_NAME = "IF(LEFT(t.option_name, 6) = '_site_', CONCAT('_site_transient_', SUBSTRING(t.option_name, 25)), CONCAT('_transient_', SUBSTRING(t.option_name, 20)))";

    private static function transient_args(): array
    {
        global $wpdb;
        return [ $wpdb->esc_like('_transient_timeout_') . '%', $wpdb->esc_like('_site_transient_timeout_') . '%', time() ];
    }

    private function transient_units(int $after): \Generator
    {
        global $wpdb;

        while (true) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT t.option_id, t.option_name, LENGTH(t.option_value) AS tl, " . self::TRANSIENT_VALUE_NAME . " AS value_name,
                        (SELECT LENGTH(v.option_name) + LENGTH(v.option_value) FROM {$wpdb->options} v WHERE v.option_name = " . self::TRANSIENT_VALUE_NAME . ") AS vl
                 FROM {$wpdb->options} t WHERE " . self::TRANSIENT_WHERE . ' AND t.option_id > %d ORDER BY t.option_id LIMIT %d',
                ...array_merge(self::transient_args(), [ $after, self::BATCH ])
            ), ARRAY_A);
            if (empty($rows)) {
                return;
            }
            foreach ($rows as $row) {
                $after = (int) $row['option_id'];
                $name  = (string) $row['value_name'];
                yield [
                    'id'      => preg_replace('/^_(site_)?transient_/', '', $name),
                    'key'     => $after,
                    'rows'    => [],
                    'options' => [ $name, (string) $row['option_name'] ],
                    'bytes'   => strlen((string) $row['option_name']) + (int) $row['tl'] + (int) $row['vl'],
                    'size'    => 0,
                    'skip'    => null,
                ];
            }
        }
    }

    private function revision_units(int $after): \Generator
    {
        global $wpdb;

        while (true) {
            $parents = $wpdb->get_col($wpdb->prepare(
                "SELECT post_parent FROM {$wpdb->posts} WHERE post_type = 'revision' AND post_parent > %d GROUP BY post_parent HAVING COUNT(*) > %d ORDER BY post_parent LIMIT %d",
                $after,
                $this->keep,
                self::BATCH
            ));
            if (empty($parents)) {
                return;
            }
            foreach ($parents as $parent) {
                $parent = (int) $parent;
                $after  = $parent;
                $ids    = $wpdb->get_col($wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'revision' AND post_parent = %d ORDER BY post_date DESC, ID DESC LIMIT %d, 18446744073709551615",
                    $parent,
                    $this->keep
                ));
                foreach (array_reverse($ids) as $id) {
                    yield $this->unit((int) $id, $parent, $this->post_rows([ (int) $id ], false));
                }
            }
        }
    }

    private function meta_units(string $segment, int $after): \Generator
    {
        global $wpdb;

        [ $meta, $pk, $owner_col, $owner, $owner_pk ] = self::META[ $segment ];
        while (true) {
            $rows = $wpdb->get_results($wpdb->prepare(
                'SELECT m.* FROM %i m LEFT JOIN %i o ON o.%i = m.%i
                 WHERE o.%i IS NULL AND m.%i > %d ORDER BY m.%i LIMIT %d',
                $wpdb->$meta,
                $wpdb->$owner,
                $owner_pk,
                $owner_col,
                $owner_pk,
                $pk,
                $after,
                $pk,
                self::BATCH
            ), ARRAY_A);
            if (empty($rows)) {
                return;
            }
            foreach ($rows as $row) {
                $after = (int) $row[ $pk ];
                yield $this->unit($after, $after, [ $meta => [ $row ] ]);
            }
        }
    }

    /*
     * The SQL fragments below are fixed literals, so every query that uses
     * them is visibly prepared (issue #1147): a table they name is a %i
     * placeholder, bound by the caller, never interpolated.
     */

    /** Eligibility of a post p for deletion: the snapshot restores everything its delete changes. %i: posts. */
    private const POST_ELIGIBLE = "p.post_type <> 'attachment' AND NOT EXISTS (SELECT 1 FROM %i ch WHERE ch.post_parent = p.ID AND ch.post_type <> 'revision')";

    /** Eligibility of a comment c: no replies would be orphaned. %i: comments. */
    private const COMMENT_ELIGIBLE = 'NOT EXISTS (SELECT 1 FROM %i r WHERE r.comment_parent = c.comment_ID)';

    /** When post p was trashed, or NULL. %i: postmeta. */
    private const POST_TRASHED_AT = "(SELECT MAX(CAST(tm.meta_value AS UNSIGNED)) FROM %i tm WHERE tm.post_id = p.ID AND tm.meta_key = '_wp_trash_meta_time')";

    /** When comment c was trashed, or NULL. %i: commentmeta. */
    private const COMMENT_TRASHED_AT = "(SELECT MAX(CAST(tm.meta_value AS UNSIGNED)) FROM %i tm WHERE tm.comment_id = c.comment_ID AND tm.meta_key = '_wp_trash_meta_time')";

    /*
     * The WHEREs over posts p and comments c, one per variant post_where()
     * and comment_where() pick. Each caller maps the variant to its constant
     * with a ternary, because Plugin Check cannot follow a constant array
     * index into a query.
     */
    private const WHERE_AUTO_DRAFTS         = "p.post_status = 'auto-draft' AND p.post_date < %s";
    private const WHERE_TRASH_POSTS         = "p.post_status = 'trash'";
    private const WHERE_AGED_TRASH_POSTS    = "p.post_status = 'trash' AND IF(" . self::POST_TRASHED_AT . ' IS NULL, p.post_modified_gmt < %s, ' . self::POST_TRASHED_AT . ' < %d)';
    private const WHERE_SPAM_COMMENTS       = "c.comment_approved = 'spam'";
    private const WHERE_TRASH_COMMENTS      = "c.comment_approved = 'trash'";
    private const WHERE_AGED_TRASH_COMMENTS = "c.comment_approved = 'trash' AND IF(" . self::COMMENT_TRASHED_AT . ' IS NULL, c.comment_date_gmt < %s, ' . self::COMMENT_TRASHED_AT . ' < %d)';

    /** @return array{0: int, 1: array} the WHERE variant for a post segment (0 auto-drafts, 1 trash, 2 aged trash), and the values it binds. */
    private function post_where(string $segment): array
    {
        global $wpdb;

        if ('auto_drafts' === $segment) {
            $cutoff = gmdate('Y-m-d H:i:s', (int) strtotime(current_time('mysql')) - self::AUTO_DRAFT_DAYS * DAY_IN_SECONDS);
            return [ 0, [ $cutoff ] ];
        }
        if (0 === $this->days) {
            return [ 1, [] ];
        }
        $cutoff = time() - $this->days * DAY_IN_SECONDS;
        return [ 2, [ $wpdb->postmeta, gmdate('Y-m-d H:i:s', $cutoff), $wpdb->postmeta, $cutoff ] ];
    }

    /** @return array{0: int, 1: array} the WHERE variant for a comment segment (0 spam, 1 trash, 2 aged trash), and the values it binds. */
    private function comment_where(string $segment): array
    {
        global $wpdb;

        if ('spam_comments' === $segment) {
            return [ 0, [] ];
        }
        if (0 === $this->days) {
            return [ 1, [] ];
        }
        $cutoff = time() - $this->days * DAY_IN_SECONDS;
        return [ 2, [ $wpdb->commentmeta, gmdate('Y-m-d H:i:s', $cutoff), $wpdb->commentmeta, $cutoff ] ];
    }

    private function post_units(string $segment, int $after): \Generator
    {
        global $wpdb;

        [ $variant, $values ] = $this->post_where($segment);
        $where = (0 === $variant ? self::WHERE_AUTO_DRAFTS : (1 === $variant ? self::WHERE_TRASH_POSTS : self::WHERE_AGED_TRASH_POSTS));
        while (true) {
            $ids = $wpdb->get_col($wpdb->prepare(
                "SELECT p.ID FROM %i p WHERE {$where} AND " . self::POST_ELIGIBLE . ' AND p.ID > %d ORDER BY p.ID LIMIT %d',
                ...array_merge([ $wpdb->posts ], $values, [ $wpdb->posts, $after, self::BATCH ])
            ));
            if (empty($ids)) {
                return;
            }
            foreach ($ids as $id) {
                $after = (int) $id;
                yield $this->unit($after, $after, $this->post_rows([ $after ], true));
            }
        }
    }

    private function comment_units(string $segment, int $after): \Generator
    {
        global $wpdb;

        [ $variant, $values ] = $this->comment_where($segment);
        $where = (0 === $variant ? self::WHERE_SPAM_COMMENTS : (1 === $variant ? self::WHERE_TRASH_COMMENTS : self::WHERE_AGED_TRASH_COMMENTS));
        while (true) {
            $ids = $wpdb->get_col($wpdb->prepare(
                "SELECT c.comment_ID FROM %i c WHERE {$where} AND " . self::COMMENT_ELIGIBLE . ' AND c.comment_ID > %d ORDER BY c.comment_ID LIMIT %d',
                ...array_merge([ $wpdb->comments ], $values, [ $wpdb->comments, $after, self::BATCH ])
            ));
            if (empty($ids)) {
                return;
            }
            foreach ($ids as $id) {
                $after = (int) $id;
                yield $this->unit($after, $after, $this->comment_rows([ $after ]));
            }
        }
    }

    /**
     * Every row deleting these posts removes: the post rows (and, for a whole
     * post, its revisions), their meta, their term relationships, and their
     * comments with the comments' meta.
     *
     * @param int[] $ids
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function post_rows(array $ids, bool $whole): array
    {
        global $wpdb;

        // One %d per id, bound through prepare() (issue #1147).
        $ids   = array_map('intval', $ids);
        $in    = implode(',', array_fill(0, count($ids), '%d'));
        $posts = $whole
            ? $wpdb->get_results($wpdb->prepare("SELECT * FROM %i WHERE ID IN ({$in}) OR (post_parent IN ({$in}) AND post_type = 'revision') ORDER BY ID", ...array_merge([ $wpdb->posts ], $ids, $ids)), ARRAY_A)
            : $wpdb->get_results($wpdb->prepare("SELECT * FROM %i WHERE ID IN ({$in}) ORDER BY ID", ...array_merge([ $wpdb->posts ], $ids)), ARRAY_A);
        $all      = array_map('intval', array_column((array) $posts, 'ID')) ?: [ 0 ];
        $all_list = implode(',', array_fill(0, count($all), '%d'));
        $rows     = [
            'posts'    => (array) $posts,
            'postmeta' => (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM %i WHERE post_id IN ({$all_list}) ORDER BY meta_id", ...array_merge([ $wpdb->postmeta ], $all)), ARRAY_A),
        ];
        if ($whole) {
            $rows['term_relationships'] = (array) $wpdb->get_results($wpdb->prepare(
                "SELECT r.* FROM %i r JOIN %i tt ON tt.term_taxonomy_id = r.term_taxonomy_id
                 WHERE r.object_id IN ({$all_list}) AND tt.taxonomy <> 'link_category' ORDER BY r.object_id, r.term_taxonomy_id",
                ...array_merge([ $wpdb->term_relationships, $wpdb->term_taxonomy ], $all)
            ), ARRAY_A);
            $comments = $wpdb->get_col($wpdb->prepare("SELECT comment_ID FROM %i WHERE comment_post_ID IN ({$all_list})", ...array_merge([ $wpdb->comments ], $all)));
            if (! empty($comments)) {
                $rows = array_merge($rows, $this->comment_rows(array_map('intval', $comments)));
            }
        }
        return array_filter($rows);
    }

    /**
     * @param int[] $ids
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function comment_rows(array $ids): array
    {
        global $wpdb;

        $ids = array_map('intval', $ids);
        $in  = implode(',', array_fill(0, count($ids), '%d'));
        return array_filter([
            'comments'    => (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM %i WHERE comment_ID IN ({$in}) ORDER BY comment_ID", ...array_merge([ $wpdb->comments ], $ids)), ARRAY_A),
            'commentmeta' => (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM %i WHERE comment_id IN ({$in}) ORDER BY meta_id", ...array_merge([ $wpdb->commentmeta ], $ids)), ARRAY_A),
        ]);
    }

    /** @param array<string, array<int, array<string, mixed>>> $rows */
    private function unit(int $id, int $key, array $rows): array
    {
        // Strict encoding: a value that is not valid UTF-8 would be altered
        // on its way into the snapshot, so the restore would not be exact.
        $json  = json_encode($rows); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- deliberately strict: wp_json_encode() would repair invalid UTF-8, hiding that the restore would not be exact.
        $bytes = 0;
        foreach ($rows as $table_rows) {
            foreach ($table_rows as $row) {
                foreach ($row as $value) {
                    $bytes += strlen((string) $value);
                }
            }
        }
        return [
            'id'    => $id,
            'key'   => $key,
            'rows'  => $rows,
            'bytes' => $bytes,
            'size'  => false === $json ? 0 : strlen($json),
            'skip'  => false === $json ? 'holds bytes that are not valid UTF-8, so the snapshot could not restore it exactly' : null,
        ];
    }
}
