<?php

namespace WPMCP\Tests\Pro\WooCommerce;

use WPMCP\Governance\Governance;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Mutation_Failed;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Rollback_Operation;
use WPMCP\Tools\Rollback_Session;
use WPMCP\Tools\WooCommerce\Catalog\Op_Catalog;
use WPMCP\Tools\WooCommerce\Catalog\Woo_Read;
use WPMCP\Tools\WooCommerce\Catalog\Woo_Write;

/**
 * Review moderation (issue #292, third slice) as ops on the existing woo-read
 * and woo-write dispatchers. Reviews are product comments: listing filters by
 * product, rating and status and never returns the reviewer's email or IP;
 * approve, unapprove, spam, trash and text edits snapshot the comment row and
 * its meta (rating, verified) so rollback restores it exactly, product rating
 * caches included; a reply is posted as the current (store) user through the
 * same writer create-comment uses, and its rollback moves it to the trash.
 * Every op needs moderate_comments plus edit_product for the review's product.
 */
class WooReviewsTest extends \WP_UnitTestCase
{
    private int $product = 0;

    protected function setUp(): void
    {
        parent::setUp();
        if (! class_exists('WooCommerce')) {
            $this->markTestSkipped('WooCommerce is not active in this environment.');
        }

        Gate::set_pro_for_tests(true);
        Snapshot_Store::install();
        Governance::reset_for_tests();

        \WC_Install::create_roles();
        $GLOBALS['wp_roles'] = null;
        wp_roles();

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator', 'display_name' => 'The Store']));

        $this->product = $this->product('Mug');

        global $wp_rest_server;
        $wp_rest_server = null;
        rest_get_server();
    }

    protected function tearDown(): void
    {
        global $wp_rest_server;
        $wp_rest_server = null;

        remove_all_filters('wpmcp_woo_op_enabled');
        Governance::reset_for_tests();
        wp_set_current_user(0);
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    // ------------------------------------------------------------ fixtures

    private function product(string $name): int
    {
        $product = new \WC_Product_Simple();
        $product->set_name($name);
        $product->set_regular_price('10.00');
        $product->set_status('publish');
        return (int) $product->save();
    }

    private function review(int $product, int $rating, $approved = 1, string $content = 'Great mug', bool $verified = true): int
    {
        $id = (int) wp_insert_comment([
            'comment_post_ID'      => $product,
            'comment_type'         => 'review',
            'comment_approved'     => $approved,
            'comment_author'       => 'Rita Reviewer',
            'comment_author_email' => 'rita@example.com',
            'comment_author_IP'    => '203.0.113.9',
            'comment_content'      => $content,
            'comment_date'         => '2026-03-01 10:00:00',
            'comment_date_gmt'     => '2026-03-01 10:00:00',
        ]);
        add_comment_meta($id, 'rating', $rating);
        add_comment_meta($id, 'verified', $verified ? 1 : 0);
        \WC_Comments::clear_transients($product);
        return $id;
    }

    private function read(string $op, array $params = []): array
    {
        return (new Woo_Read())->handle(['op' => $op, 'params' => $params]);
    }

    private function write(string $op, array $params, array $extra = []): array
    {
        return (new Woo_Write())->handle(['op' => $op, 'params' => $params] + $extra);
    }

    /** The comment row, its meta and the product's rating caches. */
    private function state(int $comment_id): array
    {
        clean_comment_cache($comment_id);
        $comment = get_comment($comment_id, ARRAY_A);
        $meta    = get_comment_meta($comment_id);
        ksort($meta);
        $post = $comment ? (int) $comment['comment_post_ID'] : $this->product;
        wp_cache_delete($post, 'post_meta');
        return [
            'comment' => $comment,
            'meta'    => $meta,
            'product' => [
                get_post_meta($post, '_wc_average_rating', true),
                get_post_meta($post, '_wc_rating_count', true),
                get_post_meta($post, '_wc_review_count', true),
            ],
        ];
    }

    private function snapshot_count(): int
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- counts ledger rows to prove a refusal wrote nothing.
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wpmcp_snapshots");
    }

    // ------------------------------------------------------------- catalog

    public function test_the_catalog_carries_review_ops_on_the_existing_dispatchers(): void
    {
        $ops    = Op_Catalog::ops();
        $expect = [
            'reviews.list'      => ['read', 'GET', null],
            'reviews.approve'   => ['write', 'PUT', 'comment'],
            'reviews.unapprove' => ['write', 'PUT', 'comment'],
            'reviews.spam'      => ['write', 'PUT', 'comment'],
            'reviews.trash'     => ['destructive', 'DELETE', 'comment'],
            'reviews.update'    => ['write', 'PUT', 'comment'],
            'reviews.reply'     => ['write', 'POST', 'comment_create'],
        ];
        foreach ($expect as $op => [$mode, $method, $snapshot]) {
            $this->assertArrayHasKey($op, $ops);
            $this->assertSame('reviews', $ops[ $op ]['domain']);
            $this->assertSame($mode, $ops[ $op ]['mode'], $op);
            $this->assertSame($method, $ops[ $op ]['method'], $op);
            $this->assertSame($snapshot, $ops[ $op ]['snapshot']['type'] ?? null, $op);
            $this->assertSame('moderate_comments', $ops[ $op ]['capability'], $op);
            if ('read' !== $mode) {
                $this->assertTrue($ops[ $op ]['recoverable'], $op);
            }
        }

        foreach (['comment', 'comment_create'] as $type) {
            $this->assertContains($type, Rollback_Service::restorable_object_types());
        }
    }

    // ---------------------------------------------------------------- list

    public function test_list_filters_by_product_rating_and_status_without_personal_data(): void
    {
        $other   = $this->product('Lamp');
        $five    = $this->review($this->product, 5);
        $two     = $this->review($this->product, 2, 0, 'Chipped');
        $spam    = $this->review($this->product, 1, 'spam', 'Buy pills');
        $lamp    = $this->review($other, 4);
        $post    = self::factory()->post->create();
        self::factory()->comment->create(['comment_post_ID' => $post]);

        $all = $this->read('reviews.list');
        $this->assertSame(200, $all['status'], wp_json_encode($all));
        $ids = array_column($all['body']['reviews'], 'id');
        sort($ids);
        $this->assertSame([$five, $two, $lamp], $ids, 'Default lists approved and held reviews of every product, and no blog comments');
        $this->assertSame(3, $all['body']['total']);

        $by_product = $this->read('reviews.list', ['product_id' => $this->product]);
        $this->assertEqualsCanonicalizing([$five, $two], array_column($by_product['body']['reviews'], 'id'));

        $by_rating = $this->read('reviews.list', ['rating' => 5]);
        $this->assertSame([$five], array_column($by_rating['body']['reviews'], 'id'));

        $held = $this->read('reviews.list', ['status' => 'hold']);
        $this->assertSame([$two], array_column($held['body']['reviews'], 'id'));

        $spammed = $this->read('reviews.list', ['status' => 'spam', 'product_id' => $this->product]);
        $this->assertSame([$spam], array_column($spammed['body']['reviews'], 'id'));

        $row = $by_rating['body']['reviews'][0];
        $this->assertSame($this->product, $row['product_id']);
        $this->assertSame('Mug', $row['product_name']);
        $this->assertSame(5, $row['rating']);
        $this->assertTrue($row['verified']);
        $this->assertSame('approved', $row['status']);
        $this->assertSame('review', $row['type']);
        $this->assertSame('Rita Reviewer', $row['reviewer']);
        $this->assertSame('Great mug', $row['content']);

        $json = (string) wp_json_encode($all);
        $this->assertStringNotContainsString('rita@example.com', $json, 'The reviewer email is never returned');
        $this->assertStringNotContainsString('203.0.113.9', $json, 'The reviewer IP is never returned');
    }

    public function test_list_pages_and_refuses_bad_filters(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->review($this->product, 4);
        }
        $page = $this->read('reviews.list', ['per_page' => 2, 'page' => 2]);
        $this->assertCount(1, $page['body']['reviews']);
        $this->assertSame(3, $page['body']['total']);

        foreach ([['rating' => 9], ['status' => 'weird'], ['product_id' => 'x'], ['color' => 'red'], ['per_page' => 500]] as $params) {
            $out = $this->read('reviews.list', $params);
            $this->assertSame('invalid_params', $out['error']['code'] ?? null, wp_json_encode($params) . ' => ' . wp_json_encode($out));
        }
    }

    // ---------------------------------------------------------- moderation

    public function test_approve_unapprove_and_spam_roll_back_exactly_rating_caches_included(): void
    {
        $held   = $this->review($this->product, 1, 0, 'Meh');
        $shown  = $this->review($this->product, 5);
        $cases  = [
            ['reviews.approve', $held, 'approved'],
            ['reviews.unapprove', $shown, 'unapproved'],
            ['reviews.spam', $shown, 'spam'],
        ];
        foreach ($cases as [$op, $id, $status]) {
            $before = $this->state($id);

            $out = $this->write($op, ['id' => $id]);
            $this->assertSame(200, $out['status'], $op . ' ' . wp_json_encode($out));
            $this->assertTrue($out['recoverable']);
            $this->assertSame($status, $out['body']['status'], $op);
            $this->assertNotEquals($before, $this->state($id), $op . ' changed something');
            $this->assertStringNotContainsString('rita@example.com', (string) wp_json_encode($out));

            $rolled = (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
            $this->assertTrue($rolled['restored'], wp_json_encode($rolled));
            $this->assertSame($before, $this->state($id), $op . ' rolls back exactly');
        }
    }

    public function test_trash_is_destructive_and_rolls_back_exactly(): void
    {
        $id     = $this->review($this->product, 3);
        $before = $this->state($id);

        $off = $this->write('reviews.trash', ['id' => $id], ['confirm' => true]);
        $this->assertSame('operation_disabled', $off['error']['code'] ?? null);

        add_filter('wpmcp_woo_op_enabled', static fn ($enabled, $op) => 'reviews.trash' === $op ? true : $enabled, 10, 2);
        $unconfirmed = $this->write('reviews.trash', ['id' => $id]);
        $this->assertSame('confirmation_required', $unconfirmed['error']['code'] ?? null);
        $this->assertSame($before, $this->state($id));

        $out = $this->write('reviews.trash', ['id' => $id], ['confirm' => true]);
        $this->assertSame(200, $out['status'], wp_json_encode($out));
        $this->assertSame('trash', $out['body']['status']);
        $this->assertSame('trash', get_comment($id)->comment_approved);

        (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertSame($before, $this->state($id), 'The trash meta is gone and the rating counts are back');
    }

    public function test_update_edits_the_text_only_and_rolls_back_exactly(): void
    {
        $id     = $this->review($this->product, 4, 1, 'Nice mug, <b>sturdy</b>');
        $before = $this->state($id);

        $out = $this->write('reviews.update', ['id' => $id, 'content' => 'Nice mug. Edited by the store.<script>x</script>']);
        $this->assertSame(200, $out['status'], wp_json_encode($out));
        $this->assertSame('Nice mug. Edited by the store.x', get_comment($id)->comment_content);
        $this->assertSame($before['meta'], $this->state($id)['meta'], 'Rating and verified are untouched');

        (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertSame($before, $this->state($id));
    }

    // --------------------------------------------------------------- reply

    public function test_reply_posts_as_the_store_and_rollback_trashes_it(): void
    {
        $review = $this->review($this->product, 5);
        $before = $this->state($review);

        $out = $this->write('reviews.reply', ['id' => $review, 'content' => 'Thank you, Rita!']);
        $this->assertSame(201, $out['status'], wp_json_encode($out));
        $this->assertTrue($out['recoverable']);
        $this->assertNotEmpty($out['operation_id']);

        $reply = get_comment((int) $out['body']['id']);
        $this->assertSame($review, (int) $reply->comment_parent);
        $this->assertSame($this->product, (int) $reply->comment_post_ID);
        $this->assertSame(get_current_user_id(), (int) $reply->user_id);
        $this->assertSame('The Store', $reply->comment_author);
        $this->assertSame('1', $reply->comment_approved);
        $this->assertSame('reply', $out['body']['type']);
        $this->assertSame([], get_comment_meta((int) $reply->comment_ID, 'rating'), 'A reply carries no rating');
        $this->assertSame($before, $this->state($review), 'The review itself is untouched');

        $rolled = (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertTrue($rolled['restored']);
        $this->assertSame('trash', get_comment((int) $reply->comment_ID)->comment_approved, 'Rolling back a reply moves it to the trash');
    }

    public function test_a_session_of_reply_and_approve_unwinds_together(): void
    {
        $review  = $this->review($this->product, 2, 0);
        $before  = $this->state($review);
        $session = wp_generate_uuid4();

        $approve = $this->write('reviews.approve', ['id' => $review], ['session_id' => $session]);
        $reply   = $this->write('reviews.reply', ['id' => $review, 'content' => 'Sorry to hear that.'], ['session_id' => $session]);
        $this->assertSame(200, $approve['status']);
        $this->assertSame(201, $reply['status']);

        (new Rollback_Session())->handle(['session_id' => $session]);

        $this->assertSame('trash', get_comment((int) $reply['body']['id'])->comment_approved);
        $this->assertSame($before, $this->state($review));
    }

    // ------------------------------------------------------------ refusals

    public function test_refusals_write_nothing(): void
    {
        $review = $this->review($this->product, 4);
        $spam   = $this->review($this->product, 1, 'spam');
        $post   = self::factory()->post->create();
        $blog   = (int) self::factory()->comment->create(['comment_post_ID' => $post]);
        $before = $this->state($review);
        $ledger = $this->snapshot_count();
        $count  = (int) get_comments(['count' => true, 'status' => 'all']);

        $cases = [
            ['reviews.approve', ['id' => $blog], 'unknown_review'],
            ['reviews.approve', ['id' => 999999], 'unknown_review'],
            ['reviews.approve', ['id' => $review, 'author_email' => 'x@example.com'], 'invalid_params'],
            ['reviews.update', ['id' => $review], 'invalid_params'],
            ['reviews.update', ['id' => $review, 'content' => '   '], 'invalid_params'],
            ['reviews.update', ['id' => $review, 'content' => 'x', 'rating' => 1], 'invalid_params'],
            ['reviews.reply', ['id' => $review], 'invalid_params'],
            ['reviews.reply', ['id' => $spam, 'content' => 'Hi'], 'invalid_params'],
            ['reviews.reply', ['id' => $blog, 'content' => 'Hi'], 'unknown_review'],
        ];
        foreach ($cases as [$op, $params, $code]) {
            $out = $this->write($op, $params);
            $this->assertSame($code, $out['error']['code'] ?? null, $op . ' ' . wp_json_encode($params) . ' => ' . wp_json_encode($out));
        }

        $this->assertSame($before, $this->state($review));
        $this->assertSame($ledger, $this->snapshot_count(), 'A refusal writes no snapshot');
        $this->assertSame($count, (int) get_comments(['count' => true, 'status' => 'all']));
    }

    public function test_review_ops_need_moderate_comments_and_edit_product(): void
    {
        $review = $this->review($this->product, 4);
        $before = $this->state($review);

        // An author can neither moderate comments nor edit products.
        wp_set_current_user(self::factory()->user->create(['role' => 'author']));
        $out = $this->write('reviews.approve', ['id' => $review]);
        $this->assertSame('operation_denied', $out['error']['code'] ?? null);
        $this->assertSame('capability', $out['error']['data']['reason'] ?? null);

        // An editor moderates comments but cannot edit products.
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        foreach (['reviews.unapprove' => ['id' => $review], 'reviews.reply' => ['id' => $review, 'content' => 'Hi'], 'reviews.update' => ['id' => $review, 'content' => 'x']] as $op => $params) {
            $out = $this->write($op, $params);
            $this->assertSame('operation_denied', $out['error']['code'] ?? null, $op . ' ' . wp_json_encode($out));
            $this->assertSame('edit_product', $out['error']['data']['reason'] ?? null, $op);
        }
        $list = $this->read('reviews.list');
        $this->assertSame('operation_denied', $list['error']['code'] ?? null);
        $this->assertSame($before, $this->state($review));

        // A shop manager has both.
        wp_set_current_user(self::factory()->user->create(['role' => 'shop_manager']));
        $out = $this->write('reviews.unapprove', ['id' => $review]);
        $this->assertSame(200, $out['status'], wp_json_encode($out));
    }

    /**
     * Issue #348: a review snapshot holds the reviewer's email and IP, and
     * rollback-operation runs at edit_posts, so undoing a review write takes
     * what the review ops take: moderate_comments plus edit_product for the
     * review's product. The refusal never echoes the stored email or IP.
     */
    public function test_review_rollback_needs_moderate_comments_and_edit_product(): void
    {
        $review = $this->review($this->product, 4);
        $before = $this->state($review);

        $out = $this->write('reviews.unapprove', ['id' => $review], ['session_id' => 'review-348']);
        $this->assertSame(200, $out['status'], wp_json_encode($out));
        $after = $this->state($review);

        // An author has edit_posts but neither moderate_comments nor edit_product.
        wp_set_current_user(self::factory()->user->create(['role' => 'author']));
        $rolled = (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertFalse($rolled['restored'], wp_json_encode($rolled));
        $this->assertStringContainsString('moderate_comments', wp_json_encode($rolled['warnings']));
        $this->assertStringNotContainsString('rita@example.com', (string) wp_json_encode($rolled));
        $this->assertStringNotContainsString('203.0.113.9', (string) wp_json_encode($rolled));
        $this->assertSame($after, $this->state($review));

        // An editor moderates comments but cannot edit products.
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        $rolled = (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertFalse($rolled['restored'], wp_json_encode($rolled));
        $this->assertStringContainsString('edit_product', wp_json_encode($rolled['warnings']));
        // The session is not the editor's own, so it is refused outright
        // (issue #450), and nothing is restored.
        try {
            (new Rollback_Session())->handle(['session_id' => 'review-348']);
            $this->fail('Another user\'s session was rolled back');
        } catch (Mutation_Failed $e) {
            $this->assertStringContainsString('another user', $e->getMessage());
        }
        $this->assertSame($after, $this->state($review));

        // A shop manager has both, and the undo still restores exactly.
        wp_set_current_user(self::factory()->user->create(['role' => 'shop_manager']));
        $rolled = (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertTrue($rolled['restored'], wp_json_encode($rolled));
        $this->assertSame($before, $this->state($review));
    }
}
