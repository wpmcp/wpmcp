<?php

namespace WPMCP\Tools\Analysis;

use WPMCP\Tools\Search\Content_Indexer;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Ranked keywords and phrases from a post's readable copy (issue #295).
 *
 * The copy comes from Content_Indexer::documents_for_post(), the same
 * builder-aware reader site search uses: block markup, shortcodes and
 * Elementor/Bricks element trees are already reduced to plain text there,
 * and every fragment carries a weight that says whether it is a title, a
 * heading or body copy. Classic and shortcode-builder content arrives as one
 * fragment, so its headings are split back out of the raw HTML here.
 *
 * Scoring is deliberately simple and deterministic:
 *   - each occurrence scores its segment weight: title 3, heading 2,
 *     excerpt 1.5, body 1;
 *   - single terms are Unicode letter/number runs (inner hyphens and
 *     apostrophes kept), lowercased, at least two characters, not purely
 *     numeric and not stopwords;
 *   - phrases are 2 or 3 consecutive tokens inside one sentence, starting
 *     and ending on a real term; their summed weight is multiplied by 1.5
 *     (two words) or 2 (three), and a phrase must occur at least twice;
 *   - a two word phrase is dropped when a three word phrase containing it
 *     occurs exactly as often, since it adds nothing;
 *   - ties break on count, then on the term's byte order.
 *
 * Read-only: nothing here writes, so no snapshot is taken.
 */
class Keyword_Extractor
{
    public const MAX_LIMIT = 50;

    private const WEIGHT_TITLE   = 3.0;
    private const WEIGHT_HEADING = 2.0;
    private const WEIGHT_EXCERPT = 1.5;
    private const WEIGHT_BODY    = 1.0;

    /** Content_Indexer weights at or above this mark a heading-like fragment. */
    private const INDEXER_HEADING_WEIGHT = 25;

    private const MIN_PHRASE_COUNT = 2;

    /** Block attributes that hold visible copy; every other attribute is config. */
    private const COPY_ATTRS = ['alt', 'caption', 'citation', 'content', 'label', 'text', 'title', 'value'];

    /**
     * @return array<int,array{term:string,words:int,count:int,score:float}>
     */
    public static function extract(\WP_Post $post, int $limit): array
    {
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $stop  = Keyword_Stopwords::for_language(Keyword_Stopwords::site_language());

        $stats = [];
        foreach (self::segments($post) as [$text, $weight]) {
            self::count_segment($text, $weight, $stop, $stats);
        }

        return array_slice(self::rank($stats), 0, $limit);
    }

    /**
     * The post's copy as [text, weight] pairs.
     *
     * @return array<int,array{0:string,1:float}>
     */
    private static function segments(\WP_Post $post): array
    {
        $docs = Content_Indexer::documents_for_post($post);

        // An Elementor or Bricks page keeps a rendered copy of the same text
        // in post_content; reading both would count every word twice.
        $has_builder_docs = false;
        foreach ($docs as $doc) {
            if (in_array($doc['source'], ['elementor', 'bricks'], true)) {
                $has_builder_docs = true;
                break;
            }
        }

        $out = [];
        foreach ($docs as $doc) {
            $location = (string) $doc['location'];
            $field    = (string) $doc['field'];

            if ('post/title' === $location) {
                $out[] = [(string) $doc['content'], self::WEIGHT_TITLE];
                continue;
            }
            if ('post/excerpt' === $location) {
                $out[] = [(string) $doc['content'], self::WEIGHT_EXCERPT];
                continue;
            }
            if ('content/text' === $location) {
                if (! $has_builder_docs) {
                    $out = array_merge($out, self::html_segments((string) $post->post_content));
                }
                continue;
            }
            if (! self::is_copy_field($field, (string) $doc['source'], (string) $doc['content'])) {
                continue;
            }
            $weight = (int) $doc['weight'] >= self::INDEXER_HEADING_WEIGHT ? self::WEIGHT_HEADING : self::WEIGHT_BODY;
            $out[]  = [(string) $doc['content'], $weight];
        }
        return $out;
    }

    /**
     * Search indexes links and style-ish values on purpose (an agent may look
     * for a URL); a topic summary must not, so drop them here.
     */
    private static function is_copy_field(string $field, string $source, string $content): bool
    {
        if (preg_match('#(^|\.)(url|link|href)($|\.)#i', $field)) {
            return false;
        }
        if (0 === strpos($field, 'attrs.')) {
            return in_array(substr($field, 6), self::COPY_ATTRS, true);
        }
        if (in_array($source, ['elementor', 'bricks'], true)) {
            if (preg_match('/(icon|css|class|color|typography|animation|_tag|size)/i', $field)) {
                return false;
            }
            // A lone lowercase slug ('custom', 'h2', 'fa-star') is a setting value, not copy.
            if (preg_match('/^[a-z0-9_\-]+$/', $content)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Classic or shortcode-builder HTML: headings become their own weighted
     * segments, and block-level closes end a sentence so a phrase never spans
     * two paragraphs.
     *
     * @return array<int,array{0:string,1:float}>
     */
    private static function html_segments(string $html): array
    {
        $out  = [];
        $body = preg_replace_callback(
            '#<h([1-6])\b[^>]*>(.*?)</h\1\s*>#is',
            static function (array $m) use (&$out): string {
                $text = Content_Indexer::normalize($m[2]);
                if ('' !== $text) {
                    $out[] = [$text, self::WEIGHT_HEADING];
                }
                return ' . ';
            },
            $html
        );
        $body = preg_replace('#</(p|div|li|td|th|dd|dt|blockquote|figcaption|section|article)\s*>|<br\s*/?>#i', ' . ', (string) $body);
        $text = Content_Indexer::normalize((string) $body);
        if ('' !== $text) {
            $out[] = [$text, self::WEIGHT_BODY];
        }
        return $out;
    }

    /**
     * @param array<string,true>                                   $stop
     * @param array<string,array{words:int,count:int,weight:float}> $stats
     */
    private static function count_segment(string $text, float $weight, array $stop, array &$stats): void
    {
        $text = mb_strtolower(str_replace(["\u{2018}", "\u{2019}"], "'", $text), 'UTF-8');
        // A spaced dash separates clauses the way punctuation does.
        $text = (string) preg_replace('/\s[\-\x{2013}\x{2014}]+\s/u', ' . ', $text); // dash-guard-ignore: matches dashes in user content

        $sentences = preg_split('/[^\p{L}\p{N}\p{M}\s\'\-]+/u', $text) ?: [];
        foreach ($sentences as $sentence) {
            if (! preg_match_all('/[\p{L}\p{N}\p{M}]+(?:[\'\-][\p{L}\p{N}\p{M}]+)*/u', $sentence, $m)) {
                continue;
            }
            $tokens = array_map(static fn(string $t): string => (string) preg_replace("/'s$/u", '', $t), $m[0]);
            $total  = count($tokens);
            $terms  = array_map(static fn(string $t): bool => self::is_term($t, $stop), $tokens);

            for ($i = 0; $i < $total; $i++) {
                if (! $terms[ $i ]) {
                    continue;
                }
                self::add($stats, $tokens[ $i ], 1, $weight);
                for ($len = 2; $len <= 3 && $i + $len <= $total; $len++) {
                    if ($terms[ $i + $len - 1 ]) {
                        self::add($stats, implode(' ', array_slice($tokens, $i, $len)), $len, $weight);
                    }
                }
            }
        }
    }

    /** @param array<string,true> $stop */
    private static function is_term(string $token, array $stop): bool
    {
        return mb_strlen($token, 'UTF-8') >= 2
            && ! preg_match('/^[\p{N}\'\-]+$/u', $token)
            && ! isset($stop[ $token ]);
    }

    /** @param array<string,array{words:int,count:int,weight:float}> $stats */
    private static function add(array &$stats, string $term, int $words, float $weight): void
    {
        if (! isset($stats[ $term ])) {
            $stats[ $term ] = ['words' => $words, 'count' => 0, 'weight' => 0.0];
        }
        $stats[ $term ]['count']++;
        $stats[ $term ]['weight'] += $weight;
    }

    /**
     * @param  array<string,array{words:int,count:int,weight:float}> $stats
     * @return array<int,array{term:string,words:int,count:int,score:float}>
     */
    private static function rank(array $stats): array
    {
        $stats = array_filter(
            $stats,
            static fn(array $s): bool => 1 === $s['words'] || $s['count'] >= self::MIN_PHRASE_COUNT
        );

        foreach ($stats as $term => $s) {
            if (2 !== $s['words']) {
                continue;
            }
            foreach ($stats as $longer => $l) {
                $term_s   = (string) $term;
                $longer_s = (string) $longer;
                if (3 === $l['words'] && $l['count'] === $s['count'] && false !== strpos(' ' . $longer_s . ' ', ' ' . $term_s . ' ')) {
                    unset($stats[ $term ]);
                    break;
                }
            }
        }

        $rows = [];
        foreach ($stats as $term => $s) {
            $rows[] = [
                'term'  => (string) $term,
                'words' => $s['words'],
                'count' => $s['count'],
                'score' => round($s['weight'] * (1 + 0.5 * ($s['words'] - 1)), 2),
            ];
        }

        usort(
            $rows,
            static function (array $a, array $b): int {
                return [$b['score'], $b['count']] <=> [$a['score'], $a['count']]
                    ?: strcmp($a['term'], $b['term']);
            }
        );
        return $rows;
    }
}
