<?php

namespace WPMCP\Tools\Migration;

use WPMCP\Safety\Mutation_Failed;
use WPMCP\Safety\Safe_Mutation;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Backup\Url_Rewriter;
use WPMCP\Tools\Content\Content_Guard;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Site-wide find-and-replace across post content, and optionally titles,
 * excerpts and selected post meta. Preview-only by default.
 *
 * The sibling of rewrite-site-urls, scoped to posts so that, unlike that
 * pass, it can be fully snapshot-backed: every changed post is written
 * through Safe_Mutation under ONE session id handed back to the caller, so
 * rollback-session undoes the whole pass. Because the snapshot ledger keeps
 * only Snapshot_Store::history_limit() rows, a pass that would change more
 * posts than that is refused outright rather than applied with its own
 * earliest undo points already pruned.
 *
 * Replacement inside meta reuses Url_Rewriter::transform(), the same
 * serialization-aware walk the URL rewrite uses: serialized values are
 * decoded, replaced leaf by leaf (never in array keys) and reserialized with
 * correct lengths, and a value holding an object or failing to decode is
 * skipped and reported. JSON meta is replaced as text and kept only if the
 * result still decodes. Block-editor content is kept only if every block
 * comment's attribute JSON still decodes. Posts whose content is generated
 * by a page builder from its own meta (Elementor, Bricks) have their
 * content skipped and reported: editing the rendered copy would be undone
 * on the next builder save.
 *
 * Every refusal (empty search, protected meta, unsafe regex, a truncated
 * scan, too many posts without confirm:true) happens before the first write.
 */
class Find_Replace_Content
{
    /** Posts an apply may change before confirm:true is required. */
    public const CONFIRM_THRESHOLD = 10;

    public const DEFAULT_MAX_MATCHES = 500;
    public const MAX_MATCHES_CEILING = 5000;

    private const MAX_SEARCH_LENGTH = 1000;
    private const MAX_REGEX_LENGTH  = 200;

    /** Backtracking budget while a caller-supplied regex runs. */
    private const REGEX_BACKTRACK_LIMIT = 100000;

    private const BATCH_SIZE   = 200;
    private const SNIPPETS_MAX = 3;
    private const SNIPPET_PAD  = 40;

    private const FIELDS = ['content' => 'post_content', 'title' => 'post_title', 'excerpt' => 'post_excerpt'];

    private const STATUSES = ['publish', 'draft', 'pending', 'private', 'future'];

    /**
     * Builders that regenerate post_content from their own meta. Literals
     * rather than a builder class so this tool stays loadable in every
     * build flavor.
     */
    private const BUILDER_META = [
        '_elementor_edit_mode'   => 'builder',
        '_bricks_page_content_2' => null,
    ];

    private string $pattern = '';
    private bool $regex     = false;
    private string $replace = '';

    public function handle(array $args): array
    {
        $search        = (string) ($args['search'] ?? '');
        $this->replace = (string) ($args['replace'] ?? '');
        $this->regex   = true === ($args['regex'] ?? false);
        $case          = false !== ($args['case_sensitive'] ?? true);
        // Only a literal false turns the dry run off.
        $dry_run     = false !== ($args['dry_run'] ?? true);
        $confirm     = true === ($args['confirm'] ?? null);
        $max_matches = max(1, min(self::MAX_MATCHES_CEILING, (int) ($args['max_matches'] ?? self::DEFAULT_MAX_MATCHES)));

        if ('' === $search) {
            throw new \InvalidArgumentException('search must not be empty.');
        }
        if (strlen($search) > self::MAX_SEARCH_LENGTH) {
            throw new \InvalidArgumentException(sprintf('search is limited to %d bytes.', (int) self::MAX_SEARCH_LENGTH));
        }
        if (! $this->regex && $case && $search === $this->replace) {
            throw new \InvalidArgumentException('search and replace are identical; nothing would change.');
        }

        $this->pattern = $this->build_pattern($search, $case);

        $fields     = $this->fields($args);
        $meta_keys  = $this->meta_keys($args, $fields);
        $post_types = $this->post_types($args);
        $statuses   = $this->statuses($args);
        $post_ids   = array_values(array_filter(array_map('intval', (array) ($args['post_ids'] ?? [])), static fn (int $id): bool => $id > 0));

        $backtrack = ini_get('pcre.backtrack_limit');
        if ($this->regex) {
            // phpcs:ignore WordPress.PHP.IniSet.Risky -- lowered (never raised) for the duration of one caller-supplied regex pass and restored below.
            ini_set('pcre.backtrack_limit', (string) self::REGEX_BACKTRACK_LIMIT);
        }
        try {
            $scan = $this->scan($search, $case, $fields, $meta_keys, $post_types, $statuses, $post_ids, $max_matches);
        } finally {
            if ($this->regex && false !== $backtrack) {
                // phpcs:ignore WordPress.PHP.IniSet.Risky -- restores the value read above.
                ini_set('pcre.backtrack_limit', $backtrack);
            }
        }

        $out = [
            'dry_run'        => $dry_run,
            'regex'          => $this->regex,
            'case_sensitive' => $case,
            'posts_scanned'  => $scan['scanned'],
            'posts_matched'  => count($scan['plans']),
            'total_matches'  => $scan['total'],
            'truncated'      => $scan['truncated'],
            'matches'        => array_map(static fn (array $p): array => $p['report'], array_values($scan['plans'])),
            'skipped'        => $scan['skipped'],
        ];

        if ($dry_run) {
            return $out;
        }

        $this->assert_can_apply($scan, $confirm, $max_matches);

        $session_id = wp_generate_uuid4();
        $applied    = [];
        $failed     = [];
        foreach ($scan['plans'] as $post_id => $plan) {
            if ([] === $plan['writes']) {
                continue;
            }
            $result = $this->apply_post((int) $post_id, $plan['writes'], $session_id, $args);
            if (isset($result['error'])) {
                $failed[] = ['post_id' => (int) $post_id, 'reason' => $result['error']];
                continue;
            }
            $applied[] = ['post_id' => (int) $post_id, 'operation_id' => $result['operation_id']];
        }

        $out['session_id']  = $session_id;
        $out['applied']     = $applied;
        $out['failed']      = $failed;
        $out['recoverable'] = true;
        $out['note']        = 'wpmcp/rollback-session with this session_id restores every changed post.';

        return $out;
    }

    /**
     * Compile the search into one PCRE. A plain search is quoted, so
     * matching and counting share a single engine for both modes. \x01 is
     * the delimiter because no printable character is safe to reserve in a
     * caller-written pattern.
     */
    private function build_pattern(string $search, bool $case): string
    {
        $flags = $case ? '' : 'i';

        if (! $this->regex) {
            return "\x01" . preg_quote($search, "\x01") . "\x01u" . $flags;
        }

        if (strlen($search) > self::MAX_REGEX_LENGTH) {
            throw new \InvalidArgumentException(sprintf('A regex search is limited to %d characters.', (int) self::MAX_REGEX_LENGTH));
        }
        if (false !== strpos($search, "\x01")) {
            throw new \InvalidArgumentException('The regex contains a control character this tool reserves.');
        }
        // A quantified group whose body itself ends in a quantifier, e.g.
        // (a+)+ or (\w+\s?)*, is the classic catastrophic-backtracking
        // shape. Refused up front; the lowered backtrack limit catches the
        // rest at run time.
        if (preg_match('/[+*?}]\)[+*{]/', $search)) {
            throw new \InvalidArgumentException('Nested quantifiers such as (a+)+ are refused: they can backtrack catastrophically.');
        }

        $pattern = "\x01" . $search . "\x01u" . $flags;
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an invalid caller pattern is reported as a refusal below, not as a PHP warning.
        $valid = @preg_match($pattern, '');
        if (false === $valid) {
            throw new \InvalidArgumentException('The regex does not compile.');
        }
        if (1 === $valid) {
            throw new \InvalidArgumentException('The regex matches an empty string, which would insert the replacement everywhere.');
        }

        return $pattern;
    }

    /** @return array<int, string> */
    private function fields(array $args): array
    {
        $fields = array_values(array_unique(array_map('strval', (array) ($args['fields'] ?? ['content']))));
        if ([] === $fields) {
            $fields = ['content'];
        }
        $known = array_merge(array_keys(self::FIELDS), ['meta']);
        foreach ($fields as $field) {
            if (! in_array($field, $known, true)) {
                throw new \InvalidArgumentException(sprintf('Unknown field "%s"; use content, title, excerpt or meta.', esc_html($field)));
            }
        }
        return $fields;
    }

    /** @return array<int, string> */
    private function meta_keys(array $args, array $fields): array
    {
        $keys = array_values(array_unique(array_filter(array_map('strval', (array) ($args['meta_keys'] ?? [])), 'strlen')));
        if ([] !== $keys) {
            $guard = Content_Guard::check_meta(array_flip($keys));
            if (true !== $guard) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Content_Guard escapes the key it interpolates; the rest is plugin literal.
                throw new \InvalidArgumentException($guard);
            }
        }
        if (in_array('meta', $fields, true) && [] === $keys) {
            throw new \InvalidArgumentException('fields includes meta, so meta_keys must name the keys to search.');
        }
        return in_array('meta', $fields, true) ? $keys : [];
    }

    /** @return array<int, string> */
    private function post_types(array $args): array
    {
        $types = array_values(array_unique(array_map('strval', (array) ($args['post_types'] ?? ['post', 'page']))));
        foreach ($types as $type) {
            if (! Content_Guard::is_writable_post_type($type) || ! Content_Guard::is_agent_readable_post_type($type)) {
                throw new \InvalidArgumentException(sprintf('Post type "%s" is unknown or not writable here.', esc_html($type)));
            }
        }
        if ([] === $types) {
            throw new \InvalidArgumentException('post_types must name at least one post type.');
        }
        return $types;
    }

    /** @return array<int, string> */
    private function statuses(array $args): array
    {
        $statuses = array_values(array_unique(array_map('strval', (array) ($args['statuses'] ?? self::STATUSES))));
        foreach ($statuses as $status) {
            if (! in_array($status, self::STATUSES, true)) {
                throw new \InvalidArgumentException(sprintf('Status "%s" is not searchable; use %s.', esc_html($status), esc_html(implode(', ', self::STATUSES))));
            }
        }
        if ([] === $statuses) {
            throw new \InvalidArgumentException('statuses must name at least one status.');
        }
        return $statuses;
    }

    /**
     * Walk the scoped posts by primary key and plan every change in memory.
     *
     * @return array{scanned: int, total: int, truncated: bool, plans: array<int, array{report: array<string, mixed>, writes: array<string, mixed>}>, skipped: array<int, array{post_id: int, field: string, reason: string}>}
     */
    private function scan(string $search, bool $case, array $fields, array $meta_keys, array $post_types, array $statuses, array $post_ids, int $max_matches): array
    {
        global $wpdb;

        $columns = [];
        foreach (self::FIELDS as $field => $column) {
            if (in_array($field, $fields, true)) {
                $columns[ $field ] = $column;
            }
        }

        $where  = [];
        $params = [];
        $where[] = 'post_type IN (' . implode(', ', array_fill(0, count($post_types), '%s')) . ')';
        $params  = array_merge($params, $post_types);
        $where[] = 'post_status IN (' . implode(', ', array_fill(0, count($statuses), '%s')) . ')';
        $params  = array_merge($params, $statuses);
        if ([] !== $post_ids) {
            $where[] = 'ID IN (' . implode(', ', array_fill(0, count($post_ids), '%d')) . ')';
            $params  = array_merge($params, $post_ids);
        }
        // A plain search with no meta in scope can be narrowed in SQL. The
        // prefilter only has to be a superset; the PCRE decides.
        if (! $this->regex && [] === $meta_keys && [] !== $columns) {
            $needle = '%' . $wpdb->esc_like($case ? $search : mb_strtolower($search)) . '%';
            $likes  = [];
            foreach ($columns as $column) {
                $likes[]  = $case ? "`{$column}` LIKE %s" : "LOWER(`{$column}`) LIKE %s";
                $params[] = $needle;
            }
            $where[] = '(' . implode(' OR ', $likes) . ')';
        }
        $where_sql = implode(' AND ', $where);

        $plans     = [];
        $skipped   = [];
        $total     = 0;
        $scanned   = 0;
        $truncated = false;
        $last      = 0;

        while (! $truncated) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where_sql holds only placeholders and column names from the FIELDS const map.
            $sql = "SELECT ID, post_type, post_title, post_content, post_excerpt FROM {$wpdb->posts} WHERE ID > %d AND {$where_sql} ORDER BY ID ASC LIMIT %d";
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- a batched site-wide scan must read the live rows; every value is bound by prepare().
            $rows = $wpdb->get_results($wpdb->prepare($sql, array_merge([$last], $params, [self::BATCH_SIZE])), ARRAY_A);
            if (empty($rows)) {
                break;
            }

            $meta_rows = $this->meta_rows(array_map(static fn (array $r): int => (int) $r['ID'], $rows), $meta_keys);

            foreach ($rows as $row) {
                $post_id = (int) $row['ID'];
                $last    = $post_id;
                $scanned++;

                $plan = $this->plan_post($row, $columns, $meta_rows[ $post_id ] ?? [], $skipped);
                if (null === $plan) {
                    continue;
                }

                $plans[ $post_id ] = $plan;
                $total            += $plan['report']['count'];
                if ($total > $max_matches) {
                    $truncated = true;
                    break;
                }
            }
        }

        return [
            'scanned'   => $scanned,
            'total'     => $total,
            'truncated' => $truncated,
            'plans'     => $plans,
            'skipped'   => $skipped,
        ];
    }

    /**
     * Raw meta rows (serialized strings as stored) for a batch of posts.
     *
     * @param array<int, int>    $post_ids
     * @param array<int, string> $keys
     * @return array<int, array<int, array{id: int, key: string, value: string}>>
     */
    private function meta_rows(array $post_ids, array $keys): array
    {
        if ([] === $keys || [] === $post_ids) {
            return [];
        }

        global $wpdb;
        $sql = sprintf(
            "SELECT meta_id, post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN (%s) AND meta_key IN (%s) ORDER BY meta_id ASC",
            implode(', ', array_fill(0, count($post_ids), '%d')),
            implode(', ', array_fill(0, count($keys), '%s'))
        );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- raw stored values are required so serialized lengths are handled exactly; every value is bound by prepare().
        $rows = $wpdb->get_results($wpdb->prepare($sql, array_merge($post_ids, $keys)), ARRAY_A);

        $out = [];
        foreach ((array) $rows as $r) {
            $out[ (int) $r['post_id'] ][] = [
                'id'    => (int) $r['meta_id'],
                'key'   => (string) $r['meta_key'],
                'value' => (string) $r['meta_value'],
            ];
        }
        return $out;
    }

    /**
     * Plan the replacement for one post, or null when nothing matched.
     *
     * @param array<string, string> $row
     * @param array<string, string> $columns
     * @param array<int, array{id: int, key: string, value: string}> $meta
     * @param array<int, array{post_id: int, field: string, reason: string}> $skipped
     * @return array{report: array<string, mixed>, writes: array<string, mixed>}|null
     */
    private function plan_post(array $row, array $columns, array $meta, array &$skipped): ?array
    {
        $post_id  = (int) $row['ID'];
        $counts   = [];
        $snippets = [];
        $writes   = ['post' => [], 'meta' => []];
        $builder  = null;

        foreach ($columns as $field => $column) {
            $original = (string) $row[ $column ];
            [$new, $count] = $this->replace_text($original, $snippets);
            if (0 === $count) {
                continue;
            }
            $counts[ $field ] = $count;

            if ('content' === $field) {
                $builder = $builder ?? $this->builder($post_id);
                if (null !== $builder) {
                    $skipped[] = $this->skip($post_id, $field, sprintf('Content is generated by the %s builder from its own data; edit it with the builder tools.', $builder));
                    continue;
                }
                if (! $this->block_attributes_intact($original, $new)) {
                    $skipped[] = $this->skip($post_id, $field, 'The replacement would break block attribute JSON.');
                    continue;
                }
            }
            $writes['post'][ $column ] = ['from' => $original, 'to' => $new];
        }

        foreach ($meta as $m) {
            $label = 'meta:' . $m['key'];
            $plan  = $this->plan_meta($m['value'], $snippets);
            if (0 === $plan['count']) {
                continue;
            }
            $counts[ $label ] = ($counts[ $label ] ?? 0) + $plan['count'];
            if (null !== $plan['skip']) {
                $skipped[] = $this->skip($post_id, $label, $plan['skip']);
                continue;
            }
            $writes['meta'][ $m['id'] ] = ['from' => $m['value'], 'to' => $plan['to']];
        }

        if ([] === $counts) {
            return null;
        }

        if ([] === $writes['post'] && [] === $writes['meta']) {
            $writes = [];
        }

        return [
            'report' => [
                'post_id'   => $post_id,
                'post_type' => (string) $row['post_type'],
                'title'     => (string) $row['post_title'],
                'count'     => array_sum($counts),
                'fields'    => $counts,
                'snippets'  => $snippets,
            ],
            'writes' => $writes,
        ];
    }

    /**
     * @param array<int, string> $snippets
     * @return array{count: int, to: string, skip: ?string}
     */
    private function plan_meta(string $value, array &$snippets): array
    {
        if (is_serialized($value)) {
            $rewriter = new Url_Rewriter();
            $count    = 0;
            $to       = $rewriter->transform(
                $value,
                function (string $leaf) use (&$count, &$snippets): string {
                    [$new, $n] = $this->replace_text($leaf, $snippets);
                    $count    += $n;
                    return $new;
                },
                false
            );
            if (0 < $count) {
                return ['count' => $count, 'to' => (string) $to, 'skip' => null];
            }
            // The walk found nothing, but the text might still match inside
            // a value the rewriter declined to decode. Report that.
            $scratch = [];
            [, $raw] = $this->replace_text($value, $scratch);
            if (0 === $raw) {
                return ['count' => 0, 'to' => $value, 'skip' => null];
            }
            $reason = $rewriter->would_rewrite(maybe_unserialize($value))
                ? 'Serialized value does not decode; left untouched.'
                : 'Serialized value holds an object; left untouched rather than risk corrupting it.';
            return ['count' => $raw, 'to' => $value, 'skip' => $reason];
        }

        [$to, $count] = $this->replace_text($value, $snippets);
        if (0 === $count) {
            return ['count' => 0, 'to' => $value, 'skip' => null];
        }
        if ($this->is_json($value) && ! $this->is_json($to)) {
            return ['count' => $count, 'to' => $value, 'skip' => 'The replacement would make this JSON value invalid.'];
        }
        return ['count' => $count, 'to' => $to, 'skip' => null];
    }

    /**
     * Replace every match in one string, collecting context snippets.
     *
     * @param array<int, string> $snippets
     * @return array{0: string, 1: int}
     */
    private function replace_text(string $text, array &$snippets): array
    {
        if ('' === $text) {
            return [$text, 0];
        }

        $pattern = $this->pattern;
        if (! preg_match('//u', $text)) {
            // Not valid UTF-8: the u modifier would reject the whole
            // subject, so fall back to byte matching for this one value.
            $pattern = (string) preg_replace('/\x01u([i]?)$/', "\x01$1", $pattern);
        }

        $count = preg_match_all($pattern, $text, $found, PREG_OFFSET_CAPTURE);
        if (false === $count || PREG_NO_ERROR !== preg_last_error()) {
            throw new \RuntimeException('The search pattern failed while scanning (' . esc_html(preg_last_error_msg()) . '); nothing was written. Simplify the pattern or narrow the scope.');
        }
        if (0 === $count) {
            return [$text, 0];
        }

        foreach ($found[0] as [$match, $offset]) {
            if (count($snippets) >= self::SNIPPETS_MAX) {
                break;
            }
            $start      = max(0, $offset - self::SNIPPET_PAD);
            $length     = strlen($match) + ($offset - $start) + self::SNIPPET_PAD;
            $snippet    = substr($text, $start, $length);
            $snippets[] = function_exists('mb_scrub') ? mb_scrub($snippet, 'UTF-8') : $snippet;
        }

        if ($this->regex) {
            $new = preg_replace($pattern, $this->replace, $text);
        } else {
            $literal = $this->replace;
            $new     = preg_replace_callback($pattern, static fn (): string => $literal, $text);
        }
        if (null === $new) {
            throw new \RuntimeException('The search pattern failed while replacing; nothing was written.');
        }

        return [$new, $count];
    }

    private function is_json(string $value): bool
    {
        $trim = ltrim($value);
        if ('' === $trim || ('{' !== $trim[0] && '[' !== $trim[0])) {
            return false;
        }
        json_decode($value);
        return JSON_ERROR_NONE === json_last_error();
    }

    /**
     * Whether every block comment attribute object that decoded before the
     * replacement still decodes after it.
     */
    private function block_attributes_intact(string $before, string $after): bool
    {
        if (false === strpos($before, '<!-- wp:')) {
            return true;
        }
        $re = '/<!--\s+wp:[a-z0-9_\/-]+\s+(\{.*?\})\s+\/?-->/s';
        preg_match_all($re, $before, $old);
        preg_match_all($re, $after, $new);
        if (count($old[1]) !== count($new[1])) {
            return false;
        }
        foreach ($new[1] as $i => $json) {
            if (null !== json_decode($old[1][ $i ], true) && null === json_decode($json, true)) {
                return false;
            }
        }
        return true;
    }

    private function builder(int $post_id): ?string
    {
        foreach (self::BUILDER_META as $key => $expected) {
            $value = get_post_meta($post_id, $key, true);
            if (null === $expected ? ! empty($value) : $expected === $value) {
                return '_elementor_edit_mode' === $key ? 'Elementor' : 'Bricks';
            }
        }
        return null;
    }

    /** @return array{post_id: int, field: string, reason: string} */
    private function skip(int $post_id, string $field, string $reason): array
    {
        return ['post_id' => $post_id, 'field' => $field, 'reason' => $reason];
    }

    private function assert_can_apply(array $scan, bool $confirm, int $max_matches): void
    {
        if ($scan['truncated']) {
            throw new \InvalidArgumentException(sprintf(
                'More than %d matches (max_matches); a partial pass is not applied. Narrow the scope or raise max_matches.',
                (int) $max_matches
            ));
        }

        $to_write = count(array_filter($scan['plans'], static fn (array $p): bool => [] !== $p['writes']));

        $limit = Snapshot_Store::history_limit();
        if ($to_write > $limit) {
            throw new \InvalidArgumentException(sprintf(
                'This pass would change %d posts but snapshot history keeps %d, so rollback-session could not undo all of it. Narrow the scope (post_ids, post_types) or raise the wpmcp_snapshot_history_limit filter.',
                (int) $to_write,
                (int) $limit
            ));
        }

        if ($to_write > self::CONFIRM_THRESHOLD && ! $confirm) {
            throw new \InvalidArgumentException(sprintf(
                'This pass would change %d posts; applying more than %d requires confirm:true. Review the dry run first.',
                (int) $to_write,
                (int) self::CONFIRM_THRESHOLD
            ));
        }
    }

    /**
     * Write one post's planned changes under its own snapshot.
     *
     * @param array{post: array<string, array{from: string, to: string}>, meta: array<int, array{from: string, to: string}>} $writes
     * @return array{operation_id?: string, error?: string}
     */
    private function apply_post(int $post_id, array $writes, string $session_id, array $args): array
    {
        global $wpdb;

        $operation_id = wp_generate_uuid4();
        try {
            Safe_Mutation::run(
                [
                    'object_type'  => 'post',
                    'object_id'    => $post_id,
                    'session_id'   => $session_id,
                    'tool_name'    => 'find-replace-content',
                    'args'         => $args,
                    'operation_id' => $operation_id,
                ],
                function () use ($post_id, $writes, $wpdb): bool {
                    // Refuse if anything changed since the scan: the plan
                    // was computed from those exact bytes.
                    clean_post_cache($post_id);
                    $current = get_post($post_id, ARRAY_A);
                    foreach ($writes['post'] as $column => $w) {
                        if (! $current || (string) $current[ $column ] !== $w['from']) {
                            throw new Mutation_Failed('The post changed after it was scanned; run the pass again.');
                        }
                    }
                    foreach ($writes['meta'] as $meta_id => $w) {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- compares the raw stored bytes the plan was built from.
                        $raw = $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_id = %d", $meta_id));
                        if ((string) $raw !== $w['from']) {
                            throw new Mutation_Failed('Post meta changed after it was scanned; run the pass again.');
                        }
                    }

                    if ([] !== $writes['post']) {
                        $postarr = ['ID' => $post_id];
                        foreach ($writes['post'] as $column => $w) {
                            $postarr[ $column ] = $w['to'];
                        }
                        // wp_update_post() expects slashed input; without
                        // this every backslash in the content is stripped.
                        $result = wp_update_post(wp_slash($postarr), true);
                        if (is_wp_error($result)) {
                            throw new Mutation_Failed(esc_html($result->get_error_message()));
                        }
                    }

                    foreach ($writes['meta'] as $meta_id => $w) {
                        // Written raw by meta_id: the new value is already
                        // in its stored form (reserialized with correct
                        // lengths), and update_post_meta() would serialize a
                        // serialized string a second time.
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- see above; keyed by the meta_id primary key, and the post_meta cache is cleared right after.
                        if (false === $wpdb->update($wpdb->postmeta, ['meta_value' => $w['to']], ['meta_id' => $meta_id])) {
                            throw new Mutation_Failed('A post meta row could not be written.');
                        }
                    }
                    if ([] !== $writes['meta']) {
                        wp_cache_delete($post_id, 'post_meta');
                    }

                    return true;
                },
                function () use ($post_id, $writes, $wpdb): bool {
                    clean_post_cache($post_id);
                    $stored = get_post($post_id, ARRAY_A);
                    foreach ($writes['post'] as $column => $w) {
                        if (! $stored || (string) $stored[ $column ] !== $w['to']) {
                            return false;
                        }
                    }
                    foreach ($writes['meta'] as $meta_id => $w) {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- verifies the raw bytes just written.
                        $raw = $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_id = %d", $meta_id));
                        if ((string) $raw !== $w['to']) {
                            return false;
                        }
                    }
                    return true;
                }
            );
        } catch (Mutation_Failed $e) {
            // The undo point describes a write that did not stand; leaving
            // it restorable would let a later rollback clobber newer edits.
            Snapshot_Store::delete_operation($operation_id);
            $message = 'Verification failed; change rolled back.' === $e->getMessage()
                ? 'Content filters altered the result on save, so the change was rolled back.'
                : $e->getMessage();
            return ['error' => $message];
        }

        return ['operation_id' => $operation_id];
    }
}
