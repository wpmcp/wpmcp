<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * Issue #687: the Site Reviews ops against the real free plugin.
 *
 * It runs only in the local gate's live Site Reviews leg (bin/test-local.sh,
 * or bin/test-local.sh --live-site-reviews alone), which sets
 * WPMCP_LIVE_SITE_REVIEWS=1, installs the plugin from wordpress.org on a
 * separate WordPress install and loads it in tests/bootstrap.php.
 *
 * A review made by the plugin must read through the ops, a status change or
 * reply must show in the plugin's own model, its ratings table and its
 * counters, and every write must roll back with those in step.
 *
 * Everywhere else the plugin is absent and the test is skipped; the live leg
 * runs with --fail-on-skipped so it cannot pass vacuously.
 *
 * @group site-reviews-live
 */
class SiteReviewsLiveTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! function_exists('glsr') || ! post_type_exists('site-review')) {
            $this->markTestSkipped('Needs the real Site Reviews (bin/test-local.sh --live-site-reviews, WPMCP_LIVE_SITE_REVIEWS=1).');
        }
        Snapshot_Store::install();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        add_filter('wpmcp_enable_site_reviews_write', '__return_true');
        // Site Reviews probes the reviewer's Gravatar as a review is created.
        add_filter('pre_http_request', static fn ($pre, $args, $url) => str_contains((string) $url, 'gravatar.com') ? [ 'headers' => [], 'body' => '', 'response' => [ 'code' => 404, 'message' => 'Not Found' ], 'cookies' => [], 'filename' => null ] : $pre, 10, 3);
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_enable_site_reviews_write');
        remove_all_filters('pre_http_request');
        parent::tearDown();
    }

    private function call(string $half, string $op, array $args): array
    {
        $call = [ 'operation' => $op, 'args' => $args ];
        $out  = 'read' === $half ? (new Plugin_Data_Integration())->handle_read($call) : (new Plugin_Data_Integration())->handle_write($call);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out;
    }

    private function make(array $values = [])
    {
        $page = self::factory()->post->create([ 'post_type' => 'page', 'post_status' => 'publish' ]);
        $review = glsr_create_review($values + [
            'rating'         => 4,
            'title'          => 'Live review',
            'content'        => 'Made by the plugin itself.',
            'name'           => 'Live Reviewer',
            'email'          => 'live.reviewer@example.com',
            'ip_address'     => '203.0.113.9',
            'assigned_posts' => (string) $page,
            'is_approved'    => false,
        ]);
        $this->assertNotFalse($review, 'Site Reviews created the review');
        return [ $review, $page ];
    }

    private function rated(int $page): int
    {
        return (int) \GeminiLabs\SiteReviews\Helpers\Cast::toInt(get_post_meta($page, \GeminiLabs\SiteReviews\Database\CountManager::META_REVIEWS, true));
    }

    public function test_reads_agree_with_the_plugins_own_model_and_mask_the_reviewer(): void
    {
        [ $review, $page ] = $this->make();

        $list = $this->call('read', 'sitereviews-list-reviews', [ 'status' => 'unapproved', 'rating' => 4, 'assigned_post_id' => $page ])['result'];
        $this->assertSame([ $review->ID ], array_column($list['reviews'], 'id'));
        $this->assertSame('l***@example.com', $list['reviews'][0]['email']);
        $this->assertSame('203.0.*.*', $list['reviews'][0]['ip_address']);
        $this->assertStringNotContainsString('live.reviewer@example.com', (string) wp_json_encode($list));

        $one = $this->call('read', 'sitereviews-get-review', [ 'id' => $review->ID ])['result']['review'];
        $this->assertSame('live.reviewer@example.com', $one['email']);
        $this->assertSame('Made by the plugin itself.', $one['content']);
        $this->assertSame([ $page ], $one['assigned_posts']);

        $sum = $this->call('read', 'sitereviews-review-summary', [ 'assigned_post_id' => $page ])['result'];
        $this->assertSame(1, $sum['by_status']['unapproved']);
    }

    public function test_approve_and_its_undo_keep_the_ratings_table_and_counters_in_step(): void
    {
        [ $review, $page ] = $this->make();
        $this->assertFalse(glsr_get_review($review->ID)->is_approved);
        $this->assertSame(0, $this->rated($page));

        $out = $this->call('write', 'sitereviews-approve-review', [ 'id' => $review->ID ]);
        $this->assertTrue(glsr_get_review($review->ID)->is_approved);
        $this->assertSame('publish', get_post_status($review->ID));
        $this->assertSame(1, $this->rated($page), 'the plugin recounted');

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertFalse(glsr_get_review($review->ID, true)->is_approved ?? glsr_get_review($review->ID)->is_approved);
        $this->assertSame(0, $this->rated($page), 'and recounted after the undo');
    }

    public function test_unapprove_trash_and_their_undos_keep_the_counters_in_step(): void
    {
        [ $review, $page ] = $this->make([ 'is_approved' => true ]);
        $this->assertSame(1, $this->rated($page));

        $un = $this->call('write', 'sitereviews-unapprove-review', [ 'id' => $review->ID ]);
        $this->assertSame(0, $this->rated($page));
        $this->assertTrue(Rollback_Service::restore_operation($un['operation_id']));
        $this->assertSame(1, $this->rated($page));

        $tr = $this->call('write', 'sitereviews-trash-review', [ 'id' => $review->ID ]);
        $this->assertSame('trash', get_post_status($review->ID));
        $this->assertSame(0, $this->rated($page));
        $this->assertTrue(Rollback_Service::restore_operation($tr['operation_id']));
        $this->assertSame('publish', get_post_status($review->ID));
        $this->assertSame(1, $this->rated($page));
    }

    public function test_reply_reads_back_through_the_plugin_and_the_undo_clears_it(): void
    {
        [ $review ] = $this->make([ 'is_approved' => true ]);

        $out = $this->call('write', 'sitereviews-reply-to-review', [ 'id' => $review->ID, 'response' => 'Thank you for the kind words.' ]);
        $this->assertSame('Thank you for the kind words.', glsr_get_review($review->ID)->response);
        $this->assertSame(get_current_user_id(), (int) get_post_meta($review->ID, '_response_by', true));

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertFalse(metadata_exists('post', $review->ID, '_response'));
    }
}
