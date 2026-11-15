<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Integrations\Site_Reviews_Pack;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

require_once __DIR__ . '/../../support/site-reviews-stubs.php';

/**
 * Site Reviews (issue #687): free-tier ops on the plugin-data dispatcher
 * pair, so they add no top-level tools.
 *
 * Site Reviews is not loaded in the shared test core. Presence is driven
 * through wpmcp_site_reviews_active, the site-review post type is registered
 * here the way Commands/RegisterPostType.php does, its ratings and
 * assigned-posts tables are built from its own DDL, and the two behaviours
 * the ops lean on (glsr_update_review() and the transition_post_status hook
 * that syncs the ratings row) are stood in for
 * (tests/support/site-reviews-stubs.php).
 *
 * The writes are off until the site opts in (wpmcp_enable_site_reviews_write)
 * and each is undone in a test, with the ratings row checked after the undo.
 */
class SiteReviewsPackTest extends \WP_UnitTestCase
{
    private const OPS = [
        'sitereviews-list-reviews'     => 'read',
        'sitereviews-get-review'       => 'read',
        'sitereviews-review-summary'   => 'read',
        'sitereviews-approve-review'   => 'write',
        'sitereviews-unapprove-review' => 'write',
        'sitereviews-trash-review'     => 'write',
        'sitereviews-reply-to-review'  => 'write',
    ];

    public static function wpSetUpBeforeClass(): void
    {
        wpmcp_test_create_site_reviews_tables();
    }

    public static function wpTearDownAfterClass(): void
    {
        wpmcp_test_drop_site_reviews_tables();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        register_post_type('site-review', [
            'public'          => false,
            'show_ui'         => true,
            'capability_type' => 'site-review',
            'capabilities'    => [ 'create_posts' => 'create_site-reviews', 'respond_to_posts' => 'respond_to_site-reviews', 'respond_to_others_posts' => 'respond_to_others_site-reviews' ],
            'map_meta_cap'    => true,
            'supports'        => [ 'author', 'title', 'editor', 'revisions' ],
        ]);
        $this->moderator();
        add_filter('wpmcp_site_reviews_active', '__return_true');
        add_filter('wpmcp_enable_site_reviews_write', '__return_true');
        add_action('transition_post_status', 'wpmcp_test_site_reviews_transition', 10, 3);
        Site_Reviews_Pack::use_updater_for_tests('wpmcp_test_site_reviews_update');
    }

    protected function tearDown(): void
    {
        Site_Reviews_Pack::use_updater_for_tests(null);
        remove_action('transition_post_status', 'wpmcp_test_site_reviews_transition', 10);
        remove_all_filters('wpmcp_site_reviews_active');
        remove_all_filters('wpmcp_enable_site_reviews_write');
        unregister_post_type('site-review');
        parent::tearDown();
    }

    /** An administrator holding the capabilities Site Reviews adds to its roles. */
    private function moderator(string $role = 'administrator', bool $caps = true): int
    {
        $id   = self::factory()->user->create([ 'role' => $role ]);
        $user = get_userdata($id);
        foreach ($caps ? array_values((array) get_post_type_object('site-review')->cap) : [] as $cap) {
            if (! str_contains((string) $cap, '_post')) {
                $user->add_cap((string) $cap);
            }
        }
        wp_set_current_user($id);
        return $id;
    }

    private function read(string $op, array $args = []): array
    {
        $out = (new Plugin_Data_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
        return isset($out['result']) && ! isset($out['error']) ? $out['result'] : $out;
    }

    private function write(string $op, array $args): array
    {
        return (new Plugin_Data_Integration())->handle_write([ 'operation' => $op, 'args' => $args ]);
    }

    private function ok(array $out): array
    {
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out;
    }

    /** A review as Site Reviews stores it: the post, its ratings row and the posts it is about. */
    private function review(array $o = []): int
    {
        global $wpdb;
        $o  += [ 'title' => 'Great stay', 'content' => 'We loved the rooms and the breakfast was excellent.', 'status' => 'publish', 'rating' => 5, 'name' => 'Jane Doe', 'email' => 'jane.doe@example.com', 'ip' => '203.0.113.7', 'date' => '2026-11-02 09:00:00', 'assigned' => [], 'author' => 0 ];
        $id = self::factory()->post->create([ 'post_type' => 'site-review', 'post_title' => $o['title'], 'post_content' => $o['content'], 'post_status' => $o['status'], 'post_date' => $o['date'], 'post_date_gmt' => $o['date'], 'post_author' => $o['author'] ]);
        $wpdb->insert($wpdb->get_blog_prefix() . 'glsr_ratings', [ 'review_id' => $id, 'rating' => $o['rating'], 'type' => 'local', 'is_approved' => 'publish' === $o['status'] ? 1 : 0, 'name' => $o['name'], 'email' => $o['email'], 'ip_address' => $o['ip'] ]);
        $rating = (int) $wpdb->insert_id;
        foreach ($o['assigned'] as $post_id) {
            $wpdb->insert($wpdb->get_blog_prefix() . 'glsr_assigned_posts', [ 'rating_id' => $rating, 'post_id' => $post_id, 'is_published' => 1 ]);
        }
        return $id;
    }

    private function approved_flag(int $id): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare('SELECT is_approved FROM %i WHERE review_id = %d', $wpdb->get_blog_prefix() . 'glsr_ratings', $id));
    }

    // ---------------------------------------------------------------
    // Catalog and availability
    // ---------------------------------------------------------------

    public function test_the_catalog_lists_every_op_with_its_mode(): void
    {
        $ops = array_column((new Plugin_Data_Integration())->catalog()['operations'], null, 'name');
        foreach (self::OPS as $name => $mode) {
            $this->assertArrayHasKey($name, $ops);
            $this->assertSame($mode, $ops[ $name ]['mode'], $name);
            $this->assertTrue($ops[ $name ]['dependency_met'], $name);
        }
    }

    public function test_while_site_reviews_is_missing_a_call_answers_plugin_missing_with_the_plugins_link(): void
    {
        remove_all_filters('wpmcp_site_reviews_active');
        add_filter('wpmcp_site_reviews_active', '__return_false');
        $out = $this->read('sitereviews-list-reviews');
        $this->assertSame('plugin_missing', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertStringContainsString('Install and activate Site Reviews', $out['error']['message']);
        $this->assertSame(admin_url('plugins.php'), $out['error']['data']['plugins_url'] ?? null);
    }

    public function test_writes_are_off_until_the_site_opts_in(): void
    {
        $id = $this->review([ 'status' => 'pending' ]);
        remove_all_filters('wpmcp_enable_site_reviews_write');
        foreach ([
            [ 'sitereviews-approve-review', [ 'id' => $id ] ],
            [ 'sitereviews-unapprove-review', [ 'id' => $id ] ],
            [ 'sitereviews-trash-review', [ 'id' => $id ] ],
            [ 'sitereviews-reply-to-review', [ 'id' => $id, 'response' => 'Thanks' ] ],
        ] as [ $op, $args ]) {
            $out = $this->write($op, $args);
            $this->assertSame('opt_in_required', $out['error']['code'] ?? null, $op);
            $this->assertStringContainsString('wpmcp_enable_site_reviews_write', $out['error']['message']);
        }
        $this->assertSame('pending', get_post_status($id));
        $this->assertSame('', (string) get_post_meta($id, '_response', true));
    }

    public function test_a_subscriber_may_neither_read_nor_write(): void
    {
        $id = $this->review();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'subscriber' ]));
        $this->assertSame('operation_denied', $this->read('sitereviews-get-review', [ 'id' => $id ])['error']['code'] ?? null);
        $this->assertSame('operation_denied', $this->read('sitereviews-list-reviews')['error']['code'] ?? null);
        $this->assertSame('operation_denied', $this->write('sitereviews-trash-review', [ 'id' => $id ])['error']['code'] ?? null);
        $this->assertSame('publish', get_post_status($id));
    }

    // ---------------------------------------------------------------
    // Reads
    // ---------------------------------------------------------------

    public function test_list_reviews_masks_the_reviewer_email_and_ip_and_excludes_the_trash(): void
    {
        $a = $this->review([ 'title' => 'Alpha', 'date' => '2026-11-03 09:00:00' ]);
        $b = $this->review([ 'title' => 'Beta', 'status' => 'pending', 'rating' => 2, 'date' => '2026-11-02 09:00:00', 'email' => 'bob@example.org', 'ip' => '2001:db8::1' ]);
        $t = $this->review([ 'title' => 'Gone', 'status' => 'trash' ]);

        $out = $this->ok($this->read('sitereviews-list-reviews'));
        $this->assertSame([ $a, $b ], array_column($out['reviews'], 'id'), 'newest first, no trash');
        $this->assertSame(2, $out['total']);
        $rows = array_column($out['reviews'], null, 'id');
        $this->assertSame('approved', $rows[ $a ]['status']);
        $this->assertSame('unapproved', $rows[ $b ]['status']);
        $this->assertSame(5, $rows[ $a ]['rating']);
        $this->assertSame('j***@example.com', $rows[ $a ]['email']);
        $this->assertSame('203.0.*.*', $rows[ $a ]['ip_address']);
        $this->assertSame('b***@example.org', $rows[ $b ]['email']);
        $this->assertSame('2001:db8:*', $rows[ $b ]['ip_address']);
        $this->assertSame('Jane Doe', $rows[ $a ]['name']);
        $json = (string) wp_json_encode($out);
        $this->assertStringNotContainsString('jane.doe@example.com', $json);
        $this->assertStringNotContainsString('203.0.113.7', $json);
        $this->assertSame([ $t ], array_column($this->read('sitereviews-list-reviews', [ 'status' => 'trashed' ])['reviews'], 'id'));
    }

    public function test_list_reviews_filters_by_status_rating_assigned_post_and_date(): void
    {
        $page = self::factory()->post->create([ 'post_type' => 'page' ]);
        $a    = $this->review([ 'title' => 'Alpha', 'rating' => 5, 'assigned' => [ $page ], 'date' => '2026-11-03 09:00:00' ]);
        $b    = $this->review([ 'title' => 'Beta', 'rating' => 2, 'status' => 'pending', 'date' => '2026-10-01 09:00:00' ]);
        $c    = $this->review([ 'title' => 'Gamma', 'rating' => 5, 'date' => '2026-09-01 09:00:00' ]);

        $ids = fn (array $args): array => array_column($this->read('sitereviews-list-reviews', $args)['reviews'] ?? [], 'id');
        $this->assertSame([ $a, $c ], $ids([ 'status' => 'approved' ]));
        $this->assertSame([ $b ], $ids([ 'status' => 'unapproved' ]));
        $this->assertSame([ $a, $c ], $ids([ 'rating' => 5 ]));
        $this->assertSame([ $b ], $ids([ 'rating' => 2 ]));
        $this->assertSame([ $a ], $ids([ 'assigned_post_id' => $page ]));
        $this->assertSame([ $a, $b ], $ids([ 'date_from' => '2026-10-01' ]));
        $this->assertSame([ $b, $c ], $ids([ 'date_to' => '2026-10-31' ]));
        $this->assertSame([ $c ], $ids([ 'date_to' => '2026-10-31', 'rating' => 5 ]));
        $this->assertSame([ $b ], $ids([ 'search' => 'Beta' ]));
        $this->assertSame([], $ids([ 'rating' => 3 ]));
        $this->assertSame([ $c ], $ids([ 'page' => 2, 'per_page' => 2 ]));
        $this->assertSame(3, $this->read('sitereviews-list-reviews', [ 'per_page' => 1 ])['total']);
    }

    public function test_list_reviews_hides_unreadable_reviews_from_a_user_who_may_not_read_them(): void
    {
        $mine  = $this->review([ 'title' => 'Mine', 'status' => 'pending', 'author' => 0 ]);
        $author = self::factory()->user->create([ 'role' => 'author' ]);
        wp_set_current_user($author);
        $this->assertNotContains($mine, array_column($this->read('sitereviews-list-reviews')['reviews'] ?? [], 'id'), 'an author does not see another user\'s pending review');
    }

    public function test_get_review_returns_the_review_in_full_with_its_reply(): void
    {
        $page = self::factory()->post->create([ 'post_type' => 'page' ]);
        $id   = $this->review([ 'assigned' => [ $page ] ]);
        update_post_meta($id, '_response', 'Thank you!');
        update_post_meta($id, '_response_by', 7);

        $review = $this->ok($this->read('sitereviews-get-review', [ 'id' => $id ]))['review'];
        $this->assertSame('We loved the rooms and the breakfast was excellent.', $review['content']);
        $this->assertSame('jane.doe@example.com', $review['email']);
        $this->assertSame('203.0.113.7', $review['ip_address']);
        $this->assertSame('Thank you!', $review['reply']);
        $this->assertSame(7, $review['reply_by']);
        $this->assertTrue($review['has_reply']);
        $this->assertSame([ $page ], $review['assigned_posts']);
        $this->assertSame('review_not_found', $this->read('sitereviews-get-review', [ 'id' => self::factory()->post->create() ])['error']['code'] ?? null);
    }

    public function test_review_summary_counts_statuses_ratings_and_the_average_of_the_approved(): void
    {
        $page = self::factory()->post->create([ 'post_type' => 'page' ]);
        $this->review([ 'rating' => 5, 'assigned' => [ $page ] ]);
        $this->review([ 'rating' => 4 ]);
        $this->review([ 'rating' => 1, 'status' => 'pending' ]);
        $this->review([ 'rating' => 3, 'status' => 'trash' ]);

        $all = $this->ok($this->read('sitereviews-review-summary'));
        $this->assertSame([ 'approved' => 2, 'unapproved' => 1, 'trashed' => 1 ], $all['by_status']);
        $this->assertSame([ 1 => 0, 2 => 0, 3 => 0, 4 => 1, 5 => 1 ], $all['approved_by_rating']);
        $this->assertSame(4.5, $all['average']);
        $one = $this->read('sitereviews-review-summary', [ 'assigned_post_id' => $page ]);
        $this->assertSame(1, $one['by_status']['approved']);
        $this->assertSame(5.0, $one['average']);
        $this->assertNull($this->read('sitereviews-review-summary', [ 'assigned_post_id' => 999999 ])['average']);
    }

    // ---------------------------------------------------------------
    // Writes
    // ---------------------------------------------------------------

    public function test_approve_publishes_the_review_syncs_the_ratings_row_and_the_undo_puts_both_back(): void
    {
        $id = $this->review([ 'status' => 'pending' ]);
        $this->assertSame(0, $this->approved_flag($id));

        $out = $this->ok($this->write('sitereviews-approve-review', [ 'id' => $id ]));
        $this->assertTrue($out['recoverable']);
        $this->assertSame('publish', get_post_status($id));
        $this->assertSame('approved', $out['result']['review']['status']);
        $this->assertSame(1, $this->approved_flag($id), 'the plugin\'s ratings row follows');

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame('pending', get_post_status($id));
        $this->assertSame(0, $this->approved_flag($id), 'and follows the undo');
    }

    public function test_unapprove_moves_the_review_to_pending_and_the_undo_publishes_it_again(): void
    {
        $id  = $this->review();
        $out = $this->ok($this->write('sitereviews-unapprove-review', [ 'id' => $id ]));
        $this->assertSame('pending', get_post_status($id));
        $this->assertSame(0, $this->approved_flag($id));
        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame('publish', get_post_status($id));
        $this->assertSame(1, $this->approved_flag($id));
    }

    public function test_trash_moves_the_review_to_the_trash_and_the_undo_brings_it_back_with_its_status(): void
    {
        $id  = $this->review();
        $out = $this->ok($this->write('sitereviews-trash-review', [ 'id' => $id ]));
        $this->assertSame('trash', get_post_status($id));
        $this->assertSame('trashed', $out['result']['review']['status']);
        $this->assertSame(0, $this->approved_flag($id));
        $this->assertNotNull(get_post($id), 'never a permanent delete');

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame('publish', get_post_status($id));
        $this->assertSame(1, $this->approved_flag($id));
    }

    public function test_reply_stores_the_response_and_who_wrote_it_and_the_undo_restores_the_earlier_reply(): void
    {
        $id = $this->review();
        update_post_meta($id, '_response', 'First reply');
        update_post_meta($id, '_response_by', 3);

        $out = $this->ok($this->write('sitereviews-reply-to-review', [ 'id' => $id, 'response' => 'Thank you for staying with us.' ]));
        $this->assertSame('Thank you for staying with us.', get_post_meta($id, '_response', true));
        $this->assertSame(get_current_user_id(), (int) get_post_meta($id, '_response_by', true));
        $this->assertSame('Thank you for staying with us.', $out['result']['review']['reply']);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame('First reply', get_post_meta($id, '_response', true));
        $this->assertSame(3, (int) get_post_meta($id, '_response_by', true));
    }

    public function test_a_first_reply_is_removed_again_by_the_undo(): void
    {
        $id  = $this->review();
        $out = $this->ok($this->write('sitereviews-reply-to-review', [ 'id' => $id, 'response' => 'Hello' ]));
        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertFalse(metadata_exists('post', $id, '_response'));
        $this->assertFalse(metadata_exists('post', $id, '_response_by'));
        $this->assertFalse($this->read('sitereviews-get-review', [ 'id' => $id ])['review']['has_reply']);
    }

    public function test_writes_refuse_a_non_review_a_no_op_a_trashed_review_and_a_blank_reply(): void
    {
        $post = self::factory()->post->create();
        $this->assertSame('review_not_found', $this->write('sitereviews-approve-review', [ 'id' => $post ])['error']['code'] ?? null);
        $this->assertSame('review_not_found', $this->write('sitereviews-reply-to-review', [ 'id' => 999999, 'response' => 'x' ])['error']['code'] ?? null);

        $live = $this->review();
        $this->assertSame('no_change', $this->write('sitereviews-approve-review', [ 'id' => $live ])['error']['code'] ?? null);
        $pending = $this->review([ 'status' => 'pending' ]);
        $this->assertSame('no_change', $this->write('sitereviews-unapprove-review', [ 'id' => $pending ])['error']['code'] ?? null);

        $gone = $this->review([ 'status' => 'trash' ]);
        $this->assertSame('review_trashed', $this->write('sitereviews-approve-review', [ 'id' => $gone ])['error']['code'] ?? null);
        $this->assertSame('no_change', $this->write('sitereviews-trash-review', [ 'id' => $gone ])['error']['code'] ?? null);
        $this->assertSame('review_trashed', $this->write('sitereviews-reply-to-review', [ 'id' => $gone, 'response' => 'x' ])['error']['code'] ?? null);

        $this->assertSame('invalid_params', $this->write('sitereviews-reply-to-review', [ 'id' => $live, 'response' => '<p> </p>' ])['error']['code'] ?? null);
        $this->assertFalse(metadata_exists('post', $live, '_response'));
    }

    public function test_the_status_and_reply_writes_need_the_capabilities_site_reviews_registers(): void
    {
        $id = $this->review([ 'status' => 'pending' ]);
        $this->moderator('editor', false);
        $this->assertSame('operation_denied', $this->write('sitereviews-approve-review', [ 'id' => $id ])['error']['code'] ?? null, 'without publish_site-reviews');
        $this->assertSame('operation_denied', $this->write('sitereviews-reply-to-review', [ 'id' => $id, 'response' => 'Hi' ])['error']['code'] ?? null, 'without respond_to_site-reviews');
        $this->assertSame('pending', get_post_status($id));
        $this->assertFalse(metadata_exists('post', $id, '_response'));
    }

    public function test_a_failed_plugin_update_is_reported_and_leaves_the_review_alone(): void
    {
        $id = $this->review([ 'status' => 'pending' ]);
        Site_Reviews_Pack::use_updater_for_tests(static fn () => false);
        $out = $this->write('sitereviews-approve-review', [ 'id' => $id ]);
        $this->assertSame('update_failed', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertSame('pending', get_post_status($id));
    }

    public function test_masking_helpers(): void
    {
        $this->assertSame('', Site_Reviews_Pack::mask_email(''));
        $this->assertSame('***', Site_Reviews_Pack::mask_email('nonsense'));
        $this->assertSame('a***@b.co', Site_Reviews_Pack::mask_email('alice@b.co'));
        $this->assertSame('', Site_Reviews_Pack::mask_ip(''));
        $this->assertSame('***', Site_Reviews_Pack::mask_ip('not an ip'));
        $this->assertSame('10.0.*.*', Site_Reviews_Pack::mask_ip('10.0.4.9'));
    }
}
