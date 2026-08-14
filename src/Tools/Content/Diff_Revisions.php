<?php

namespace WPMCP\Tools\Content;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Diff two revisions of a post, or a revision against the post's current
 * state, field by field.
 *
 * Returned as a unified diff per changed field rather than as two full
 * documents. An agent asked "what changed in this post" that receives both
 * versions in full has to diff them itself, badly, and pays for both copies
 * in context; a post of any size makes that the dominant cost of the call.
 *
 * Unchanged fields are omitted entirely, so an empty changes list is a
 * meaningful and cheap "these are identical" answer.
 */
class Diff_Revisions
{
    /** Fields worth diffing; the rest of the row is bookkeeping. */
    public const FIELDS = ['post_title', 'post_content', 'post_excerpt'];

    /** Cap on returned diff lines per field, so one huge rewrite cannot blow the response budget. */
    public const MAX_LINES = 400;

    public function handle(array $args): array
    {
        $from_id = (int) ($args['from_revision_id'] ?? 0);
        $from    = $from_id > 0 ? wp_get_post_revision($from_id) : null;

        if (! $from instanceof \WP_Post) {
            throw new \InvalidArgumentException('from_revision_id must be a valid revision id.');
        }

        $parent_id = (int) $from->post_parent;

        // The comparison target is either another revision of the SAME post
        // or the post's current state. Revisions of a different post are
        // refused: diffing across posts produces a plausible-looking result
        // that means nothing.
        $to_id = (int) ($args['to_revision_id'] ?? 0);
        if ($to_id > 0) {
            $to = wp_get_post_revision($to_id);
            if (! $to instanceof \WP_Post) {
                throw new \InvalidArgumentException('to_revision_id must be a valid revision id.');
            }
            if ((int) $to->post_parent !== $parent_id) {
                throw new \InvalidArgumentException('Both revisions must belong to the same post.');
            }
        } else {
            $to = get_post($parent_id);
            if (! $to instanceof \WP_Post) {
                throw new \InvalidArgumentException('The parent post no longer exists.');
            }
        }

        $changes = [];
        foreach (self::FIELDS as $field) {
            $before = (string) $from->{$field};
            $after  = (string) $to->{$field};

            if ($before === $after) {
                continue;
            }

            $changes[ $field ] = [
                'diff'          => $this->diff($before, $after),
                'chars_before'  => strlen($before),
                'chars_after'   => strlen($after),
            ];
        }

        return [
            'post_id'   => $parent_id,
            'from'      => $this->describe($from),
            'to'        => $this->describe($to),
            'identical' => [] === $changes,
            'changes'   => $changes,
        ];
    }

    /** @return array{id: int, is_revision: bool, modified: string, author: int} */
    private function describe(\WP_Post $post): array
    {
        return [
            'id'          => (int) $post->ID,
            'is_revision' => 'revision' === $post->post_type,
            'modified'    => (string) $post->post_modified_gmt,
            'author'      => (int) $post->post_author,
        ];
    }

    /**
     * A unified-style line diff.
     *
     * Uses WordPress core's Text_Diff engine (the same one the revisions
     * screen renders) rather than a hand-rolled comparison, so the output
     * agrees with what an editor sees in wp-admin for the same two revisions.
     *
     * @return string[]
     */
    private function diff(string $before, string $after): array
    {
        if (! class_exists('Text_Diff')) {
            require_once ABSPATH . WPINC . '/wp-diff.php';
        }

        $diff  = new \Text_Diff('auto', [explode("\n", $before), explode("\n", $after)]);
        $lines = [];

        foreach ($diff->getDiff() as $op) {
            $type = $op instanceof \Text_Diff_Op_add ? '+'
                : ($op instanceof \Text_Diff_Op_delete ? '-'
                : ($op instanceof \Text_Diff_Op_change ? '~' : ' '));

            if (' ' === $type) {
                continue;
            }

            foreach ((array) ($op->orig ?? []) as $line) {
                if ('-' === $type || '~' === $type) {
                    $lines[] = '-' . $line;
                }
            }
            foreach ((array) ($op->final ?? []) as $line) {
                if ('+' === $type || '~' === $type) {
                    $lines[] = '+' . $line;
                }
            }

            if (count($lines) >= self::MAX_LINES) {
                $lines   = array_slice($lines, 0, self::MAX_LINES);
                $lines[] = '... diff truncated at ' . self::MAX_LINES . ' lines';
                break;
            }
        }

        return $lines;
    }
}
