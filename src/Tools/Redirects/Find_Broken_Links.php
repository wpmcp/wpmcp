<?php

namespace WPMCP\Tools\Redirects;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Report internal links that are dead, not yet public, or pointing through a
 * redirect (issue #128). Read-only: it proposes fixes and changes nothing.
 *
 * One tool covers three call shapes, so the tools/list surface (which every
 * MCP client pays for on connect) grows by one entry rather than three:
 *  - no arguments: scan inline and return the findings. Good for a handful of
 *    posts, and the only mode that needs no follow-up call.
 *  - background:true: queue a batched scan and return its scan_id
 *    immediately. Long scans then make durable progress through WP-Cron
 *    instead of dying against a request timeout.
 *  - scan_id:<n>: read a queued/running/completed scan's progress and
 *    findings, including a percent so a caller can report it.
 *
 * The inline mode is capped hard (MAX_INLINE_POSTS) rather than left to run
 * as long as it likes: a tool call that hangs is worse than one that tells
 * you to use background mode.
 */
class Find_Broken_Links
{
    /** Posts the inline (non-background) mode will scan in one call. */
    public const MAX_INLINE_POSTS = 100;

    /** Posts a background scan will cover unless a smaller limit is given. */
    public const DEFAULT_LIMIT = 500;

    /** Posts processed per background batch. */
    public const DEFAULT_BATCH_SIZE = 25;

    public function handle(array $args): array
    {
        if (isset($args['scan_id'])) {
            return $this->status((int) $args['scan_id']);
        }

        $post_types = $this->post_types($args);

        if (! empty($args['background'])) {
            return $this->queue($args, $post_types);
        }

        $limit = min(
            self::MAX_INLINE_POSTS,
            max(1, (int) ($args['limit'] ?? self::MAX_INLINE_POSTS))
        );
        $total = Broken_Link_Scanner::scannable_total($post_types);
        $ids   = Broken_Link_Scanner::scannable_ids($post_types, $limit);

        return [
            'mode'      => 'inline',
            'scanned'   => count($ids),
            'total'     => $total,
            'partial'   => $total > count($ids),
            'findings'  => Broken_Link_Scanner::scan_posts($ids),
        ];
    }

    /** @param string[] $post_types */
    private function queue(array $args, array $post_types): array
    {
        $limit      = max(1, (int) ($args['limit'] ?? self::DEFAULT_LIMIT));
        $batch_size = max(1, min(200, (int) ($args['batch_size'] ?? self::DEFAULT_BATCH_SIZE)));
        $total      = min($limit, Broken_Link_Scanner::scannable_total($post_types));

        $scan = Broken_Link_Scan_Store::create($post_types, $limit, $batch_size, $total);
        wp_schedule_single_event(time(), Run_Broken_Link_Scan::HOOK, [$scan['id']]);

        return [
            'mode'    => 'background',
            'scan_id' => $scan['id'],
            'status'  => $scan['status'],
            'total'   => $total,
        ];
    }

    private function status(int $scan_id): array
    {
        $scan = Broken_Link_Scan_Store::get($scan_id);
        if (null === $scan) {
            throw new \InvalidArgumentException('No broken-link scan found with id "' . (int) $scan_id . '".');
        }

        $total = (int) $scan['total'];

        return [
            'mode'      => 'background',
            'scan_id'   => $scan['id'],
            'status'    => $scan['status'],
            'scanned'   => (int) $scan['scanned'],
            'total'     => $total,
            'percent'   => $total > 0 ? (int) floor(((int) $scan['scanned'] / $total) * 100) : 100,
            'truncated' => (bool) $scan['truncated'],
            'error'     => $scan['error'],
            'findings'  => (array) $scan['findings'],
        ];
    }

    /** @return string[] */
    private function post_types(array $args): array
    {
        $requested = $args['post_types'] ?? ($args['post_type'] ?? null);
        if (null === $requested || '' === $requested || [] === $requested) {
            return ['post', 'page'];
        }

        $types = array_values(array_filter(array_map(
            static fn ($type): string => sanitize_key((string) $type),
            (array) $requested
        ), 'strlen'));

        return [] === $types ? ['post', 'page'] : $types;
    }
}
