<?php

namespace WPMCP\Tools\WooCommerce\Catalog;

use WPMCP\Tools\Comments\Comment_View;
use WPMCP\Tools\Comments\Create_Comment;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Product review listing, moderation, edits and store replies behind the
 * reviews.* ops (issue #292), in-process through the comments API. A review
 * is a comment on a product; its rating and verified flag are comment meta.
 *
 *  - Every op needs moderate_comments (the row's capability, checked by
 *    Op_Guard) plus edit_product for the review's product, checked here, so
 *    an editor who moderates blog comments cannot touch store reviews.
 *  - Listing never returns the reviewer's email or IP address.
 *  - approve, unapprove, spam, trash and update run inside Safe_Mutation
 *    with a 'comment' snapshot (the full comment row and all its meta), so
 *    rollback restores the review exactly; WooCommerce recomputes the
 *    product's rating caches from it, as it does on any comment change.
 *  - A reply is posted as the current user through the writer create-comment
 *    uses, which records a 'comment_create' row; rolling it back moves the
 *    reply to the trash.
 */
final class Review_Ops
{
    public const DEFAULT_PER_PAGE = 20;
    public const MAX_PER_PAGE     = 50;

    private const KEYS = [
        'review_list'      => ['product_id', 'rating', 'status', 'type', 'page', 'per_page'],
        'review_approve'   => ['id'],
        'review_unapprove' => ['id'],
        'review_spam'      => ['id'],
        'review_trash'     => ['id'],
        'review_update'    => ['id', 'content'],
        'review_reply'     => ['id', 'content'],
    ];

    /** Moderation handlers => the wp_set_comment_status() status. */
    private const STATUS_SET = [
        'review_approve'   => 'approve',
        'review_unapprove' => 'hold',
        'review_spam'      => 'spam',
        'review_trash'     => 'trash',
    ];

    /** List status filter => the WP_Comment_Query status. */
    private const LIST_STATUSES = [
        'all'      => 'all',
        'approved' => 'approve',
        'hold'     => 'hold',
        'spam'     => 'spam',
        'trash'    => 'trash',
    ];

    /**
     * The reviews.list read.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function read(string $handler, array $params): array
    {
        try {
            self::refuse_unknown($params, self::KEYS['review_list']);
            if (! current_user_can('edit_products')) {
                self::refuse('operation_denied', 'Listing reviews also requires the "edit_products" capability.', [ 'reason' => 'edit_product' ]);
            }
            $query = self::list_query($params);
        } catch (Order_Op_Refused $e) {
            return Op_Guard::error($e->code_name, $e->getMessage(), $e->data);
        }

        $count_args = $query;
        unset($count_args['number'], $count_args['offset'], $count_args['orderby'], $count_args['order']);
        $total = (int) get_comments($count_args + [ 'count' => true ]);

        $reviews = array_map([ self::class, 'view_comment' ], get_comments($query));

        return [
            'status' => 200,
            'body'   => [
                'reviews'  => array_values($reviews),
                'total'    => $total,
                'page'     => (int) ($query['offset'] / $query['number']) + 1,
                'per_page' => (int) $query['number'],
            ],
        ];
    }

    /**
     * Validate one review write. Returns a structured error, or
     * ['body' => normalized plan]. Writes nothing.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function prepare(string $handler, array $params): array
    {
        try {
            if (! isset(self::KEYS[ $handler ]) || 'review_list' === $handler) {
                self::refuse('invalid_params', 'Unknown review handler.');
            }
            self::refuse_unknown($params, self::KEYS[ $handler ]);

            $comment = self::review($params['id'] ?? null);
            $product = (int) $comment->comment_post_ID;
            if (! current_user_can('edit_product', $product)) {
                self::refuse('operation_denied', "This review is on product {$product}, which you cannot edit.", [ 'reason' => 'edit_product' ]);
            }

            $plan = [ 'id' => (int) $comment->comment_ID, 'product_id' => $product ];
            if (in_array($handler, [ 'review_update', 'review_reply' ], true)) {
                $content = is_string($params['content'] ?? null) ? trim($params['content']) : '';
                if ('' === $content || strlen($content) > 65525) {
                    self::refuse('invalid_params', 'content must be non-empty text.');
                }
                $plan['content'] = $content;
            }
            if ('review_reply' === $handler && in_array((string) $comment->comment_approved, [ 'spam', 'trash', 'post-trashed' ], true)) {
                self::refuse('invalid_params', 'This review is ' . $comment->comment_approved . ' and cannot be replied to.');
            }
        } catch (Order_Op_Refused $e) {
            return Op_Guard::error($e->code_name, $e->getMessage(), $e->data);
        }

        return [ 'body' => $plan ];
    }

    /**
     * Post a reply as the current user and record its creation row.
     *
     * @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    public static function create(array $plan, string $session_id, string $op): array
    {
        try {
            $made = (new Create_Comment())->insert(
                [ 'content' => $plan['content'], 'status' => 'approved', 'session_id' => $session_id, 'op' => $op ],
                (int) $plan['product_id'],
                (int) $plan['id'],
                'woo-write'
            );
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            return Op_Guard::error('review_reply_failed', $e->getMessage());
        }

        return [ 'status' => 201, 'body' => self::view((int) $made['id']), 'operation_id' => $made['operation_id'] ];
    }

    /**
     * Apply a validated moderation or edit. Runs inside Safe_Mutation, after
     * the comment snapshot is written.
     *
     * @param array<string, mixed> $plan
     * @return array{status: int, body: array<string, mixed>}
     */
    public static function apply(string $handler, int $id, array $plan): array
    {
        if ('review_update' === $handler) {
            $done = wp_update_comment([ 'comment_ID' => $id, 'comment_content' => wp_kses_post($plan['content']) ]);
            if (false === $done || is_wp_error($done)) {
                throw new \RuntimeException('Could not update the review.');
            }
        } elseif (! wp_set_comment_status($id, self::STATUS_SET[ $handler ])) {
            throw new \RuntimeException('Could not change the review status.');
        }

        return [ 'status' => 200, 'body' => self::view($id) ];
    }

    /** @return array<string, mixed> */
    public static function view(int $id): array
    {
        clean_comment_cache($id);
        $comment = get_comment($id);
        return $comment instanceof \WP_Comment ? self::view_comment($comment) : [];
    }

    /**
     * A review as responses show it: no reviewer email and no IP.
     *
     * @return array<string, mixed>
     */
    private static function view_comment(\WP_Comment $comment): array
    {
        $id     = (int) $comment->comment_ID;
        $rating = get_comment_meta($id, 'rating', true);
        return [
            'id'           => $id,
            'product_id'   => (int) $comment->comment_post_ID,
            'product_name' => get_the_title((int) $comment->comment_post_ID),
            'type'         => 0 === (int) $comment->comment_parent ? 'review' : 'reply',
            'parent'       => (int) $comment->comment_parent,
            'status'       => Comment_View::status((string) $comment->comment_approved),
            'rating'       => '' === $rating ? null : (int) $rating,
            'verified'     => (bool) get_comment_meta($id, 'verified', true),
            'reviewer'     => (string) $comment->comment_author,
            'by_store'     => (int) $comment->user_id > 0 && user_can((int) $comment->user_id, 'edit_products'),
            'content'      => (string) $comment->comment_content,
            'date_created' => (string) $comment->comment_date_gmt,
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private static function list_query(array $params): array
    {
        $query = [ 'post_type' => 'product', 'orderby' => 'comment_date_gmt', 'order' => 'DESC' ];

        if (array_key_exists('product_id', $params)) {
            $product = self::positive_int($params['product_id']);
            if (null === $product || 'product' !== get_post_type($product)) {
                self::refuse('invalid_params', 'product_id must be the id of a product.');
            }
            $query['post_id'] = $product;
        }

        if (array_key_exists('rating', $params)) {
            $rating = self::positive_int($params['rating']);
            if (null === $rating || $rating > 5) {
                self::refuse('invalid_params', 'rating must be a whole number from 1 to 5.');
            }
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the rating filter is one indexed meta_key match on product comments, paged at most 50.
            $query['meta_query'] = [ [ 'key' => 'rating', 'value' => $rating, 'compare' => '=', 'type' => 'NUMERIC' ] ];
        }

        $status = $params['status'] ?? 'all';
        if (! is_string($status) || ! isset(self::LIST_STATUSES[ $status ])) {
            self::refuse('invalid_params', 'status must be one of: ' . implode(', ', array_keys(self::LIST_STATUSES)) . '.');
        }
        $query['status'] = self::LIST_STATUSES[ $status ];

        $type = $params['type'] ?? 'all';
        if (! in_array($type, [ 'all', 'review', 'reply' ], true)) {
            self::refuse('invalid_params', 'type must be review, reply or all.');
        }
        if ('review' === $type) {
            $query['parent'] = 0;
        } elseif ('reply' === $type) {
            $query['parent__not_in'] = [ 0 ];
        }

        $per_page = array_key_exists('per_page', $params) ? self::positive_int($params['per_page']) : self::DEFAULT_PER_PAGE;
        if (null === $per_page || $per_page > self::MAX_PER_PAGE) {
            self::refuse('invalid_params', 'per_page must be from 1 to ' . self::MAX_PER_PAGE . '.');
        }
        $page = array_key_exists('page', $params) ? self::positive_int($params['page']) : 1;
        if (null === $page) {
            self::refuse('invalid_params', 'page must be a positive whole number.');
        }
        $query['number'] = $per_page;
        $query['offset'] = ($page - 1) * $per_page;

        return $query;
    }

    /** The product comment an op targets, or a refusal. */
    private static function review($value): \WP_Comment
    {
        $id      = self::positive_int($value);
        $comment = null === $id ? null : get_comment($id);
        if (! $comment instanceof \WP_Comment || 'product' !== get_post_type((int) $comment->comment_post_ID)) {
            self::refuse('unknown_review', 'No product review has that id. List reviews with reviews.list.');
        }
        return $comment;
    }

    private static function positive_int($value): ?int
    {
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return (int) $value > 0 ? (int) $value : null;
        }
        return null;
    }

    /**
     * Stop validation with a structured refusal.
     *
     * @param array<string, mixed> $data
     */
    private static function refuse(string $code, string $message, array $data = []): never
    {
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- caught by the caller and returned as a JSON error, never rendered.
        throw new Order_Op_Refused($code, $message, $data);
    }

    private static function refuse_unknown(array $given, array $allowed): void
    {
        $unknown = array_values(array_diff(array_map('strval', array_keys($given)), $allowed));
        if ([] !== $unknown) {
            self::refuse(
                'invalid_params',
                'Unknown field(s): ' . implode(', ', $unknown) . '. Accepted: ' . implode(', ', $allowed) . '.',
                [ 'unknown' => $unknown ]
            );
        }
    }
}
