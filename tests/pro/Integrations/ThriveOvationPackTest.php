<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Integrations\Thrive_Ovation_Pack;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

require_once __DIR__ . '/../../support/thrive-ovation-stubs.php';

/**
 * Thrive Ovation testimonials (issue #829) as paid-tier ops on the
 * plugin-data pair, so they add no top-level tools.
 *
 * Thrive Ovation is not loaded in the shared test core: its post type and
 * tag taxonomy are registered from its own definitions
 * (tests/support/thrive-ovation-stubs.php) and presence is driven through
 * wpmcp_thrive_ovation_active.
 *
 * Testimonials carry personal data: lists mask the author's name and email,
 * a single read shows them in full to manage_options, and every write is
 * off until wpmcp_enable_thrive_ovation_write and undoable.
 */
class ThriveOvationPackTest extends \WP_UnitTestCase
{
    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        wpmcp_test_register_thrive_ovation_types();
        $this->admin = self::factory()->user->create([ 'role' => 'administrator' ]);
        get_userdata($this->admin)->add_cap('tve-use-tvo');
        wp_set_current_user($this->admin);
        add_filter('wpmcp_thrive_ovation_active', '__return_true');
        add_filter(Thrive_Ovation_Pack::OPT_IN_FILTER, '__return_true');
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_thrive_ovation_active');
        remove_all_filters(Thrive_Ovation_Pack::OPT_IN_FILTER);
        wpmcp_test_unregister_thrive_ovation_types();
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function read(string $op, array $args = []): array
    {
        return (new Plugin_Data_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
    }

    private function write(string $op, array $args): array
    {
        return (new Plugin_Data_Integration())->handle_write([ 'operation' => $op, 'args' => $args ]);
    }

    private function ok(array $out): array
    {
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out['result'];
    }

    private function attributes(int $id): array
    {
        return (array) get_post_meta($id, '_tvo_testimonial_attributes', true);
    }

    // ---------------------------------------------------------------
    // Catalog and gating
    // ---------------------------------------------------------------

    public function test_the_catalog_lists_every_op_with_its_mode_on_the_plugin_data_pair(): void
    {
        $ops      = array_column((new Plugin_Data_Integration())->catalog()['operations'], null, 'name');
        $expected = [
            'thrive-ovation-list-testimonials'      => 'read',
            'thrive-ovation-get-testimonial'        => 'read',
            'thrive-ovation-list-tags'              => 'read',
            'thrive-ovation-create-testimonial'     => 'write',
            'thrive-ovation-update-testimonial'     => 'write',
            'thrive-ovation-set-testimonial-status' => 'write',
            'thrive-ovation-tag-testimonial'        => 'write',
        ];
        foreach ($expected as $op => $mode) {
            $this->assertArrayHasKey($op, $ops, $op);
            $this->assertSame($mode, $ops[ $op ]['mode'], $op);
        }
    }

    public function test_without_the_paid_tier_no_op_is_offered(): void
    {
        Gate::set_pro_for_tests(false);
        $names = array_column((new Plugin_Data_Integration())->catalog()['operations'], 'name');
        $this->assertSame([], preg_grep('/^thrive-ovation-/', $names));
    }

    public function test_an_inactive_plugin_answers_with_its_own_name(): void
    {
        remove_all_filters('wpmcp_thrive_ovation_active');
        add_filter('wpmcp_thrive_ovation_active', '__return_false');
        $out = $this->read('thrive-ovation-list-testimonials');
        $this->assertSame('thrive_ovation_inactive', $out['error']['code'] ?? null);
        $this->assertStringContainsString('Thrive Ovation', $out['error']['message']);
    }

    public function test_writes_are_off_until_the_site_opts_in(): void
    {
        remove_all_filters(Thrive_Ovation_Pack::OPT_IN_FILTER);
        $id  = wpmcp_test_ovation_testimonial([ 'status' => '2' ]);
        $out = $this->write('thrive-ovation-set-testimonial-status', [ 'id' => $id, 'status' => 'ready_for_display' ]);
        $this->assertSame('opt_in_required', $out['error']['code'] ?? null);
        $this->assertStringContainsString(Thrive_Ovation_Pack::OPT_IN_FILTER, $out['error']['message']);
        $this->assertSame('2', get_post_meta($id, '_tvo_testimonial_status', true));
    }

    public function test_a_user_without_the_ovation_capability_is_refused(): void
    {
        $editor = self::factory()->user->create([ 'role' => 'editor' ]);
        wp_set_current_user($editor);
        $out = $this->read('thrive-ovation-list-testimonials');
        $this->assertArrayHasKey('error', $out);
    }

    // ---------------------------------------------------------------
    // Reads
    // ---------------------------------------------------------------

    public function test_list_masks_name_and_email_and_filters_by_status_and_tag(): void
    {
        $tag   = self::factory()->term->create([ 'taxonomy' => 'tvo_tags', 'name' => 'Homepage' ]);
        $ready = wpmcp_test_ovation_testimonial([ 'status' => '0' ]);
        $held  = wpmcp_test_ovation_testimonial([ 'status' => '2', 'name' => 'Bob Roe', 'email' => 'bob@example.org' ]);
        wp_set_object_terms($ready, [ $tag ], 'tvo_tags');
        $gone = wpmcp_test_ovation_testimonial();
        wp_trash_post($gone);

        $all = $this->ok($this->read('thrive-ovation-list-testimonials'));
        $this->assertTrue($all['masked']);
        $this->assertSame(2, $all['total']);
        $this->assertEqualsCanonicalizing([ $ready, $held ], array_column($all['testimonials'], 'id'), 'a trashed testimonial is not listed');
        $row = array_column($all['testimonials'], null, 'id')[ $ready ];
        $this->assertSame('J***', $row['name']);
        $this->assertSame('j***@example.com', $row['email']);
        $this->assertSame('ready_for_display', $row['status']);
        $this->assertSame('manually_added', $row['source']);
        $this->assertSame('Homepage', $row['tags'][0]['name']);
        $this->assertStringNotContainsString('jane.doe', (string) wp_json_encode($all));

        $held_only = $this->ok($this->read('thrive-ovation-list-testimonials', [ 'status' => 'awaiting_review' ]));
        $this->assertSame([ $held ], array_column($held_only['testimonials'], 'id'));

        $tagged = $this->ok($this->read('thrive-ovation-list-testimonials', [ 'tag' => $tag ]));
        $this->assertSame([ $ready ], array_column($tagged['testimonials'], 'id'));
    }

    public function test_get_returns_full_details_to_an_administrator_and_masks_them_for_others(): void
    {
        $id  = wpmcp_test_ovation_testimonial();
        $got = $this->ok($this->read('thrive-ovation-get-testimonial', [ 'id' => $id ]))['testimonial'];
        $this->assertSame('Jane Doe', $got['name']);
        $this->assertSame('jane.doe@example.com', $got['email']);
        $this->assertSame('They fixed everything in a day.', $got['content']);
        $this->assertSame('CEO', $got['role']);
        $this->assertSame('https://example.com', $got['website_url']);
        $this->assertFalse($got['masked']);

        $editor = self::factory()->user->create([ 'role' => 'editor' ]);
        get_userdata($editor)->add_cap('tve-use-tvo');
        wp_set_current_user($editor);
        $masked = $this->ok($this->read('thrive-ovation-get-testimonial', [ 'id' => $id ]))['testimonial'];
        $this->assertSame('j***@example.com', $masked['email']);
        $this->assertSame('J***', $masked['name']);
        $this->assertTrue($masked['masked']);
    }

    public function test_get_refuses_a_post_that_is_not_a_testimonial_and_a_trashed_one(): void
    {
        $post = self::factory()->post->create();
        $this->assertSame('testimonial_not_found', $this->read('thrive-ovation-get-testimonial', [ 'id' => $post ])['error']['code'] ?? null);
        $this->assertSame('testimonial_not_found', $this->read('thrive-ovation-get-testimonial', [ 'id' => 999999 ])['error']['code'] ?? null);

        $id = wpmcp_test_ovation_testimonial();
        wp_trash_post($id);
        $this->assertSame('testimonial_trashed', $this->read('thrive-ovation-get-testimonial', [ 'id' => $id ])['error']['code'] ?? null);
        $this->assertSame('testimonial_trashed', $this->write('thrive-ovation-update-testimonial', [ 'id' => $id, 'title' => 'X' ])['error']['code'] ?? null);
    }

    public function test_list_tags_counts_testimonials(): void
    {
        $tag = self::factory()->term->create([ 'taxonomy' => 'tvo_tags', 'name' => 'Pricing' ]);
        wp_set_object_terms(wpmcp_test_ovation_testimonial(), [ $tag ], 'tvo_tags');
        $tags = $this->ok($this->read('thrive-ovation-list-tags'))['tags'];
        $this->assertSame([ [ 'id' => $tag, 'name' => 'Pricing', 'slug' => 'pricing', 'count' => 1 ] ], $tags);
    }

    // ---------------------------------------------------------------
    // Writes
    // ---------------------------------------------------------------

    public function test_create_stores_it_as_ovation_does_and_rollback_trashes_it(): void
    {
        $out = $this->write('thrive-ovation-create-testimonial', [
            'title'   => 'Loved it',
            'content' => 'Five stars.',
            'name'    => 'Ann Lee',
            'email'   => 'ann@example.com',
            'role'    => 'Founder',
            'tags'    => [ 'Homepage' ],
        ]);
        $created = $this->ok($out)['testimonial'];
        $id      = (int) $created['id'];
        $post    = get_post($id);
        $this->assertSame('tvo_testimonials', $post->post_type);
        $this->assertSame('publish', $post->post_status, 'Ovation only reads published testimonial posts');
        $this->assertSame('Five stars.', $post->post_content);
        $this->assertSame('2', get_post_meta($id, '_tvo_testimonial_status', true), 'a new testimonial waits for review');
        $this->assertSame('3', get_post_meta($id, '_tvo_testimonial_source', true));
        $attrs = $this->attributes($id);
        $this->assertSame('Ann Lee', $attrs['name']);
        $this->assertSame('ann@example.com', $attrs['email']);
        $this->assertSame('Founder', $attrs['role']);
        $this->assertSame([ 'Homepage' ], wp_get_object_terms($id, 'tvo_tags', [ 'fields' => 'names' ]));

        $this->assertTrue(Rollback_Service::restore_session((string) $out['session_id']) > 0);
        $this->assertSame('trash', get_post_status($id));
    }

    public function test_create_with_a_status_and_without_email(): void
    {
        $id = (int) $this->ok($this->write('thrive-ovation-create-testimonial', [ 'content' => 'Nice.', 'name' => 'Kim', 'status' => 'ready_for_display' ]))['testimonial']['id'];
        $this->assertSame('0', get_post_meta($id, '_tvo_testimonial_status', true));
        $this->assertSame('', $this->attributes($id)['email']);
    }

    public function test_update_merges_the_author_details_and_rollback_puts_them_back(): void
    {
        $id = wpmcp_test_ovation_testimonial();
        $attrs              = $this->attributes($id);
        $attrs['media_url'] = 'https://social.example/post/1';
        update_post_meta($id, '_tvo_testimonial_attributes', $attrs);

        $out = $this->write('thrive-ovation-update-testimonial', [ 'id' => $id, 'title' => 'Changed', 'role' => 'CTO' ]);
        $this->ok($out);
        $this->assertSame('Changed', get_post($id)->post_title);
        $this->assertSame('They fixed everything in a day.', get_post($id)->post_content, 'content not given stays');
        $now = $this->attributes($id);
        $this->assertSame('CTO', $now['role']);
        $this->assertSame('Jane Doe', $now['name']);
        $this->assertSame('https://social.example/post/1', $now['media_url'], 'keys the op does not know stay');

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame('Great service', get_post($id)->post_title);
        $this->assertSame('CEO', $this->attributes($id)['role']);
    }

    public function test_update_refuses_an_invalid_email(): void
    {
        $id = wpmcp_test_ovation_testimonial();
        $this->assertSame('invalid_email', $this->write('thrive-ovation-update-testimonial', [ 'id' => $id, 'email' => 'not an email' ])['error']['code'] ?? null);
        $this->assertSame('jane.doe@example.com', $this->attributes($id)['email']);
    }

    public function test_set_status_and_rollback(): void
    {
        $id  = wpmcp_test_ovation_testimonial([ 'status' => '2' ]);
        $out = $this->write('thrive-ovation-set-testimonial-status', [ 'id' => $id, 'status' => 'rejected' ]);
        $this->assertSame('rejected', $this->ok($out)['testimonial']['status']);
        $this->assertSame('3', get_post_meta($id, '_tvo_testimonial_status', true));

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame('2', get_post_meta($id, '_tvo_testimonial_status', true));
    }

    public function test_tag_adds_and_removes_tags_and_rollback_puts_the_set_back(): void
    {
        $id   = wpmcp_test_ovation_testimonial();
        $keep = self::factory()->term->create([ 'taxonomy' => 'tvo_tags', 'name' => 'Keep' ]);
        $drop = self::factory()->term->create([ 'taxonomy' => 'tvo_tags', 'name' => 'Drop' ]);
        wp_set_object_terms($id, [ $keep, $drop ], 'tvo_tags');

        $out = $this->write('thrive-ovation-tag-testimonial', [ 'id' => $id, 'add' => [ 'Fresh' ], 'remove' => [ 'Drop' ] ]);
        $this->ok($out);
        $this->assertEqualsCanonicalizing([ 'Keep', 'Fresh' ], wp_get_object_terms($id, 'tvo_tags', [ 'fields' => 'names' ]));

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertEqualsCanonicalizing([ $keep, $drop ], wp_get_object_terms($id, 'tvo_tags', [ 'fields' => 'ids' ]));
    }

    public function test_tag_refuses_a_new_tag_for_a_user_who_may_not_create_tags(): void
    {
        $id     = wpmcp_test_ovation_testimonial();
        $author = self::factory()->user->create([ 'role' => 'editor' ]);
        get_userdata($author)->add_cap('tve-use-tvo');
        // Editors hold manage_categories through their role; take it away for this user.
        add_filter('user_has_cap', $deny = static function (array $caps) {
            unset($caps['manage_categories']);
            return $caps;
        });
        wp_set_current_user($author);
        try {
            $out = $this->write('thrive-ovation-tag-testimonial', [ 'id' => $id, 'add' => [ 'Brand new' ] ]);
            $this->assertSame('unknown_tag', $out['error']['code'] ?? null);
            $this->assertSame([], wp_get_object_terms($id, 'tvo_tags', [ 'fields' => 'ids' ]));
        } finally {
            remove_filter('user_has_cap', $deny);
        }
    }
}
