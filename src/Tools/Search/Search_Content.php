<?php

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols -- ABSPATH guard is an intentional side effect.
// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps -- WP-style snake_case class name is intentional.
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WP-style snake_case method names are intentional.

namespace WPMCP\Tools\Search;

use WPMCP\Tools\Builders\Builder_Detector;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * `wpmcp/search-content`: cross-content lexical search over the materialized
 * index (issue #83). Read-only, so it never touches Safe_Mutation.
 *
 * Answers "where does this text live?" for content `post_content` search
 * cannot see: builder element settings, template parts, reusable blocks, and
 * nav menus. Every result carries an addressable location path, so the next
 * call (update-element / update-block / update-menu-item) can act on the hit
 * without a second discovery round trip.
 *
 * Per-result visibility is re-checked at read time, not trusted from the
 * index: a fragment belonging to a non-published post is only returned when
 * the CURRENT user can read that specific post (`read_post`). The index is a
 * performance structure, never an authorization bypass. An editor-capable
 * caller must not be able to read another author's private draft through it.
 */
class Search_Content
{
    public const DEFAULT_LIMIT     = 10;
    public const MAX_LIMIT         = 50;
    public const DEFAULT_HITS      = 3;
    public const MAX_HITS          = 10;
    public const MAX_CANDIDATES    = 2000;
    /** Most objects we will resolve (one get_post + capability check each). */
    public const MAX_RESOLVED      = 200;

    public function handle(array $args): array
    {
        $query = trim((string) ($args['query'] ?? ''));
        if ('' === $query) {
            throw new \InvalidArgumentException('A search query is required.');
        }

        $terms = Search_Ranker::tokenize($query);
        if ([] === $terms) {
            throw new \InvalidArgumentException(
                'The query contains no searchable term (terms must be at least '
                . (int) Search_Ranker::MIN_TERM_LENGTH . ' characters).'
            );
        }

        $limit  = max(1, min(self::MAX_LIMIT, (int) ($args['limit'] ?? self::DEFAULT_LIMIT)));
        $offset = max(0, (int) ($args['offset'] ?? 0));
        $hits   = max(1, min(self::MAX_HITS, (int) ($args['hits_per_result'] ?? self::DEFAULT_HITS)));

        $stats = Search_Index_Store::stats();

        $rows = Search_Index_Store::candidates($terms, [
            'object_types' => $this->string_list($args['object_types'] ?? []),
            'sources'      => $this->string_list($args['sources'] ?? []),
            'subtypes'     => $this->string_list($args['post_types'] ?? ($args['post_type'] ?? [])),
            'max_rows'     => self::MAX_CANDIDATES,
        ]);

        $grouped = $this->group_and_score($rows, $terms, $query);
        $ranked  = $this->rank($grouped);

        // Resolving a group costs a get_post plus a capability check, so the
        // number of objects we touch is bounded independently of how many
        // fragments matched. `total` is therefore honest about its window:
        // it counts the visible matches within the resolved set, and
        // `truncated` says when there were more.
        $resolved  = array_slice($ranked, 0, self::MAX_RESOLVED);
        $truncated = count($rows) >= self::MAX_CANDIDATES || count($ranked) > self::MAX_RESOLVED;

        $results = [];
        $skipped = 0;
        $matched = 0;
        foreach ($resolved as $group) {
            $subject = $this->subject($group);
            if (null === $subject) {
                ++$skipped;
                continue;
            }
            ++$matched;
            if ($matched <= $offset) {
                continue;
            }
            if (count($results) >= $limit) {
                continue;
            }
            $results[] = $this->format($group, $subject, $terms, $hits);
        }

        return [
            'query'     => $query,
            'terms'     => $terms,
            'results'   => $results,
            'count'     => count($results),
            'total'     => $matched,
            'truncated' => $truncated,
            'index'     => [
                'documents'       => $stats['documents'],
                'objects'         => $stats['objects'],
                'last_indexed_at' => $stats['last_indexed_at'],
                'by_source'       => $stats['by_source'],
                'empty'           => 0 === $stats['documents'],
            ],
            'hint'      => 0 === $stats['documents']
                ? 'The search index is empty. Run wpmcp/reindex-search once to build it; after that it updates incrementally on every save.'
                : null,
            'hidden'    => $skipped,
        ];
    }

    /** @return string[] */
    private function string_list(mixed $value): array
    {
        if (is_string($value)) {
            $value = '' === trim($value) ? [] : [$value];
        }
        if (! is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $entry) {
            if (is_scalar($entry) && '' !== trim((string) $entry)) {
                $out[] = trim((string) $entry);
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Score every candidate fragment and bucket the scoring ones by object.
     *
     * @param  array<int,array<string,mixed>> $rows
     * @param  string[]                       $terms
     * @return array<string,array<string,mixed>>
     */
    private function group_and_score(array $rows, array $terms, string $phrase): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $score = Search_Ranker::score_fragment(
                (string) $row['content'],
                $terms,
                $phrase,
                (int) $row['weight']
            );
            if ($score <= 0.0) {
                continue;
            }
            $key = $row['object_type'] . ':' . (int) $row['object_id'];
            if (! isset($groups[ $key ])) {
                $groups[ $key ] = [
                    'object_type' => (string) $row['object_type'],
                    'object_id'   => (int) $row['object_id'],
                    'subtype'     => (string) $row['subtype'],
                    'score'       => 0.0,
                    'best'        => 0.0,
                    'fragments'   => [],
                ];
            }
            $groups[ $key ]['fragments'][] = $row + ['score' => $score];
            $groups[ $key ]['best']        = max($groups[ $key ]['best'], $score);
        }

        // Object score = best fragment, plus a damped contribution from the
        // rest, so "the term appears all over this page" outranks a lone hit
        // without letting a long page drown out an exact title match.
        foreach ($groups as $key => $group) {
            $total = 0.0;
            foreach ($group['fragments'] as $fragment) {
                $total += (float) $fragment['score'];
            }
            $groups[ $key ]['score'] = round(
                $group['best'] + 0.25 * ($total - $group['best']),
                4
            );
        }

        return $groups;
    }

    /**
     * @param  array<string,array<string,mixed>> $groups
     * @return array<int,array<string,mixed>>
     */
    private function rank(array $groups): array
    {
        $ranked = array_values($groups);
        usort($ranked, static function (array $a, array $b): int {
            // Deterministic ordering: score desc, then object id asc so equal
            // scores never shuffle between calls.
            return [$b['score'], $a['object_id']] <=> [$a['score'], $b['object_id']];
        });
        return $ranked;
    }

    /**
     * Resolve the object behind a group and enforce read access. Returns null
     * when the object is gone (a stale index row) or the caller may not read it.
     *
     * @param  array<string,mixed> $group
     * @return array<string,mixed>|null
     */
    private function subject(array $group): ?array
    {
        if ('menu' === $group['object_type']) {
            $menu = wp_get_nav_menu_object((int) $group['object_id']);
            if (! $menu) {
                return null;
            }
            return [
                'title'     => (string) $menu->name,
                'status'    => 'publish',
                'subtype'   => 'nav_menu',
                'permalink' => null,
                'builder'   => null,
            ];
        }

        $post = get_post((int) $group['object_id']);
        if (! $post instanceof \WP_Post) {
            return null;
        }
        if ('publish' !== $post->post_status && ! current_user_can('read_post', (int) $post->ID)) {
            return null;
        }

        return [
            'title'     => (string) $post->post_title,
            'status'    => (string) $post->post_status,
            'subtype'   => (string) $post->post_type,
            'permalink' => (string) get_permalink($post),
            'builder'   => Builder_Detector::detect((int) $post->ID),
        ];
    }

    /**
     * @param  array<string,mixed> $group
     * @param  array<string,mixed> $subject
     * @param  string[]            $terms
     * @return array<string,mixed>
     */
    private function format(array $group, array $subject, array $terms, int $hits_per_result): array
    {
        $fragments = $group['fragments'];
        usort($fragments, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        $top = array_slice($fragments, 0, $hits_per_result);

        $hits = [];
        foreach ($top as $fragment) {
            $hits[] = [
                'source'   => (string) $fragment['source'],
                'node'     => (string) $fragment['node'],
                'location' => (string) $fragment['location'],
                'field'    => (string) $fragment['field'],
                'snippet'  => Search_Ranker::snippet((string) $fragment['content'], $terms),
                'score'    => (float) $fragment['score'],
            ];
        }

        return [
            'object_type' => (string) $group['object_type'],
            'object_id'   => (int) $group['object_id'],
            'post_id'     => 'post' === $group['object_type'] ? (int) $group['object_id'] : null,
            'post_type'   => (string) $subject['subtype'],
            'title'       => (string) $subject['title'],
            'status'      => (string) $subject['status'],
            'permalink'   => $subject['permalink'],
            'builder'     => $subject['builder'],
            'score'       => (float) $group['score'],
            'location'    => $hits[0]['location'] ?? '',
            'snippet'     => $hits[0]['snippet'] ?? '',
            'match_count' => count($fragments),
            'hits'        => $hits,
        ];
    }
}
