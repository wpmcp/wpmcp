<?php

namespace WPMCP\Tools\Linking;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only: given a post ID, suggest related published posts to link TO,
 * ranked by shared taxonomy terms (categories/tags) and title keyword overlap.
 *
 * Posts already linked from the source (per the internal-link graph) and the
 * source itself are excluded. Each suggestion carries the reason it surfaced
 * ('shared_terms' when it shares a taxonomy term, else 'keyword'). Reads have
 * nothing to roll back, so this never touches Safe_Mutation.
 */
class Suggest_Internal_Links
{
    private const DEFAULT_SCAN = 200;
    private const DEFAULT_CAP  = 10;

    /** Short function words ignored when comparing titles. */
    private const STOPWORDS = [
        'the', 'and', 'for', 'with', 'from', 'your', 'you', 'our', 'are', 'was',
        'this', 'that', 'best', 'how', 'why', 'what', 'when', 'top', 'get',
    ];

    public function handle(array $args): array
    {
        $post_id = isset($args['post_id']) ? (int) $args['post_id'] : 0;
        $post    = $post_id > 0 ? get_post($post_id) : null;
        if (null === $post || 'publish' !== $post->post_status) {
            return ['error' => 'Post not found or not published.'];
        }

        $cap        = Args::cap($args['cap'] ?? self::DEFAULT_CAP, self::DEFAULT_CAP);
        $post_types = Args::post_types($args['post_type'] ?? $post->post_type);
        $scan       = Args::scan_limit($args['limit'] ?? self::DEFAULT_SCAN, self::DEFAULT_SCAN);

        $graph          = Link_Graph::build($post_types, $scan);
        $already_linked = isset($graph[$post_id]) ? $graph[$post_id]['outgoing'] : [];
        $exclude        = array_fill_keys(array_merge($already_linked, [$post_id]), true);

        $source_terms = $this->term_ids($post_id);
        $source_words = $this->keywords((string) $post->post_title);

        $scored = [];
        foreach ($graph as $id => $node) {
            if (isset($exclude[$id])) {
                continue;
            }

            $shared_terms = count(array_intersect($source_terms, $this->term_ids($id)));
            $shared_words = count(array_intersect($source_words, $this->keywords($node['title'])));
            if (0 === $shared_terms && 0 === $shared_words) {
                continue;
            }

            $scored[] = [
                'id'     => $id,
                'title'  => $node['title'],
                'reason' => $shared_terms > 0 ? 'shared_terms' : 'keyword',
                'score'  => ($shared_terms * 10) + $shared_words,
            ];
        }

        usort($scored, static fn ($a, $b) => $b['score'] <=> $a['score']);

        return [
            'post_id'     => $post_id,
            'suggestions' => array_slice($scored, 0, $cap),
        ];
    }

    /**
     * @return int[] Category and tag term IDs assigned to the post, excluding
     * the site default category, which carries no real topical signal.
     */
    private function term_ids(int $post_id): array
    {
        $terms = wp_get_object_terms($post_id, ['category', 'post_tag'], ['fields' => 'ids']);
        if (is_wp_error($terms)) {
            return [];
        }

        $default = (int) get_option('default_category');

        return array_values(array_filter(
            array_map('intval', $terms),
            static fn (int $id): bool => $id !== $default
        ));
    }

    /** @return string[] Lowercased title tokens over 2 chars, stopwords removed. */
    private function keywords(string $title): array
    {
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', strtolower($title), -1, PREG_SPLIT_NO_EMPTY);
        if (! is_array($tokens)) {
            return [];
        }

        $words = [];
        foreach ($tokens as $token) {
            if (strlen($token) > 2 && ! in_array($token, self::STOPWORDS, true)) {
                $words[$token] = true;
            }
        }

        return array_keys($words);
    }
}
