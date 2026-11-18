<?php

namespace WPMCP\Tools\Comments;

use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Change a comment's moderation status: approve, unapprove, spam, trash or
 * untrash.
 *
 * Routed through Safe_Mutation with object_type 'comment', so the comment's
 * full row and commentmeta are snapshotted before the write and the status
 * change can be undone via rollback-operation. trash is WordPress's own
 * reversible trash, but it is still snapshotted so a single rollback path
 * covers every status transition uniformly.
 *
 * ids[] (up to 100) moderates several comments in one call, each with its own
 * snapshot and operation id, and keeps going past failures. status 'recheck'
 * asks Akismet to check pending comments again (the ids given, or the oldest
 * 100 in the queue); it sends them to Akismet's service, so it needs
 * confirm:true, and only comments still pending are sent (#718).
 */
class Moderate_Comment
{
    /** Accepted actions mapped to the wp_set_comment_status() status string. */
    private const ACTIONS = [
        'approve'   => 'approve',
        'unapprove' => 'hold',
        'spam'      => 'spam',
        'trash'     => 'trash',
        'untrash'   => 'approve',
        'not_spam'  => 'unspam',
    ];

    /** Most comments one bulk or recheck call touches. */
    public const MAX_BATCH = 100;

    public function handle(array $args): array
    {
        $action = (string) ($args['status'] ?? '');
        if ('recheck' === $action) {
            return $this->recheck($args);
        }
        if (isset($args['ids'])) {
            if (! isset(self::ACTIONS[ $action ])) {
                throw new \InvalidArgumentException('Unknown status. Use approve, unapprove, spam, not_spam, trash, untrash or recheck.');
            }
            return $this->bulk($args, $action);
        }

        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            throw new \InvalidArgumentException('A comment id is required.');
        }

        if (! isset(self::ACTIONS[ $action ])) {
            throw new \InvalidArgumentException('Unknown status. Use approve, unapprove, spam, not_spam, trash, untrash or recheck.');
        }

        if (! get_comment($id)) {
            throw new \RuntimeException('Comment not found.');
        }

        $out = $this->apply($id, $action, (string) ($args['session_id'] ?? 'default'), $args);
        $comment = get_comment($id);

        return [
            'id'           => $id,
            'status'       => $comment ? Comment_View::status((string) $comment->comment_approved) : null,
            'operation_id' => $out['operation_id'],
        ];
    }

    /** One snapshotted status change. */
    private function apply(int $id, string $action, string $session, array $args): array
    {
        return Safe_Mutation::run(
            [
                'object_type' => 'comment',
                'object_id'   => $id,
                'session_id'  => $session,
                'tool_name'   => 'moderate-comment',
                'args'        => $args,
            ],
            function () use ($id, $action): void {
                // 'untrash' has a dedicated core function; every other action
                // is a plain status set. Both restore the comment to a normal
                // state where needed and return false on failure.
                // spam and not_spam go through wp_spam_comment() and
                // wp_unspam_comment(), which fire spam_comment / unspam_comment;
                // Akismet listens there to learn from the correction (#718).
                if ('untrash' === $action) {
                    $result = wp_untrash_comment($id);
                } elseif ('spam' === $action) {
                    $result = wp_spam_comment($id);
                } elseif ('not_spam' === $action) {
                    $result = wp_unspam_comment($id);
                } else {
                    $result = wp_set_comment_status($id, self::ACTIONS[ $action ]);
                }
                if (! $result) {
                    throw new \RuntimeException('Could not change the comment status.');
                }
            }
        );
    }

    /** @return int[] the validated, de-duplicated ids (1 to MAX_BATCH). */
    private static function ids(array $args): array
    {
        $raw = $args['ids'];
        if (! is_array($raw) || [] === $raw) {
            throw new \InvalidArgumentException('ids must be a non-empty list of comment ids.');
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $raw), static fn (int $i): bool => $i > 0)));
        if ([] === $ids) {
            throw new \InvalidArgumentException('ids must be a non-empty list of comment ids.');
        }
        if (count($ids) > self::MAX_BATCH) {
            throw new \InvalidArgumentException('At most 100 comment ids per call.');
        }
        return $ids;
    }

    private function bulk(array $args, string $action): array
    {
        $ids     = self::ids($args);
        $session = (string) ($args['session_id'] ?? 'default');
        $results = [];
        $ops     = [];
        foreach ($ids as $id) {
            if (! get_comment($id)) {
                $results[] = [ 'id' => $id, 'error' => 'Comment not found.' ];
                continue;
            }
            try {
                $out = $this->apply($id, $action, $session, [ 'id' => $id, 'status' => $action ]);
            } catch (\Throwable $e) {
                $results[] = [ 'id' => $id, 'error' => $e->getMessage() ];
                continue;
            }
            $comment   = get_comment($id);
            $ops[]     = $out['operation_id'];
            $results[] = [
                'id'           => $id,
                'status'       => $comment ? Comment_View::status((string) $comment->comment_approved) : null,
                'operation_id' => $out['operation_id'],
            ];
        }

        return [
            'results'       => $results,
            'operation_ids' => $ops,
            'counts'        => [ 'done' => count($ops), 'failed' => count($results) - count($ops) ],
        ];
    }

    /**
     * Akismet's own per-comment recheck, or null when Akismet is not loaded.
     * The filter lets tests stand in for the service.
     */
    private static function recheck_callback(): ?callable
    {
        $default = (class_exists('Akismet') && method_exists('Akismet', 'recheck_comment'))
            ? static fn (int $id) => call_user_func([ 'Akismet', 'recheck_comment' ], $id, 'recheck_queue')
            : null;
        $cb = apply_filters('wpmcp_akismet_recheck_callback', $default);
        return is_callable($cb) ? $cb : null;
    }

    private function recheck(array $args): array
    {
        $cb = self::recheck_callback();
        if (null === $cb) {
            throw new \RuntimeException('Akismet is not active, so there is nothing to recheck with.');
        }
        if (isset($args['id']) && ! isset($args['ids'])) {
            $args['ids'] = [ $args['id'] ];
        }
        $ids = isset($args['ids'])
            ? self::ids($args)
            : array_map('intval', get_comments([
                'status'  => 'hold',
                'fields'  => 'ids',
                'number'  => self::MAX_BATCH,
                'orderby' => 'comment_date_gmt',
                'order'   => 'ASC',
            ]));

        if (true !== ($args['confirm'] ?? null)) {
            throw new \WPMCP\MCP\Confirmation_Required('Recheck sends ' . count($ids) . ' pending comment(s) to the Akismet service, which may move them to spam. Pass confirm:true to proceed.');
        }

        $session = (string) ($args['session_id'] ?? 'default');
        $counts  = [ 'processed' => 0, 'spam' => 0, 'ham' => 0, 'error' => 0, 'skipped' => 0 ];
        $results = [];
        $ops     = [];
        foreach ($ids as $id) {
            $comment = get_comment($id);
            if (! $comment || '0' !== (string) $comment->comment_approved) {
                ++$counts['skipped'];
                $results[] = [ 'id' => $id, 'skipped' => $comment ? 'not pending' : 'not found' ];
                continue;
            }
            $verdict = '';
            try {
                $out = Safe_Mutation::run(
                    [
                        'object_type' => 'comment',
                        'object_id'   => $id,
                        'session_id'  => $session,
                        'tool_name'   => 'moderate-comment',
                        'args'        => [ 'id' => $id, 'status' => 'recheck', 'confirm' => true ],
                    ],
                    function () use ($cb, $id, &$verdict): void {
                        $r       = $cb($id);
                        $verdict = is_string($r) ? $r : '';
                    }
                );
            } catch (\Throwable $e) {
                ++$counts['error'];
                $results[] = [ 'id' => $id, 'error' => $e->getMessage() ];
                continue;
            }
            ++$counts['processed'];
            $key = 'true' === $verdict ? 'spam' : ('false' === $verdict ? 'ham' : 'error');
            ++$counts[ $key ];
            $ops[]     = $out['operation_id'];
            $after     = get_comment($id);
            $results[] = [
                'id'           => $id,
                'akismet'      => $key,
                'status'       => $after ? Comment_View::status((string) $after->comment_approved) : null,
                'operation_id' => $out['operation_id'],
            ];
        }

        return [
            'results'       => $results,
            'operation_ids' => $ops,
            'counts'        => $counts,
            'remaining'     => (int) get_comments([ 'status' => 'hold', 'count' => true ]),
        ];
    }
}
