<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Integrations\PublishPress_Pack;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

require_once __DIR__ . '/../../support/publishpress-stubs.php';

/**
 * Issue #709: the PublishPress editorial workflow plugins (Planner,
 * Checklists and Future) on the plugin-data pair, free tier.
 *
 * The plugins are not loaded in the suite: their presence is faked through
 * wpmcp_publishpress_planner_active, wpmcp_publishpress_checklists_active and
 * wpmcp_publishpress_future_active, their options are stored the way the
 * plugins store them (Planner 4.9.0, Checklists 2.31.0, Future 4.11.2), and
 * Future's schedule and unschedule actions are stood in for by
 * tests/support/publishpress-stubs.php against its ppfuture_actions_args
 * table.
 *
 * The writes are off until the site opts in (wpmcp_enable_publishpress_write)
 * and each is undone in a test.
 */
class PublishPressPackTest extends \WP_UnitTestCase
{
    private const OPS = [
        'publishpress-get-editorial-calendar' => 'read',
        'publishpress-set-post-status'        => 'write',
        'checklists-list-requirements'        => 'read',
        'future-list-scheduled-changes'       => 'read',
        'future-schedule-post-change'         => 'write',
        'future-cancel-post-change'           => 'write',
    ];

    public static function wpSetUpBeforeClass(): void
    {
        wpmcp_test_create_publishpress_tables();
    }

    public static function wpTearDownAfterClass(): void
    {
        wpmcp_test_drop_publishpress_tables();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        register_post_status('pitch', [ 'label' => 'Pitch', 'public' => false, 'protected' => true, 'show_in_admin_status_list' => true ]);
        register_post_status('assigned', [ 'label' => 'Assigned', 'public' => false, 'protected' => true ]);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        add_filter('wpmcp_publishpress_planner_active', '__return_true');
        add_filter('wpmcp_publishpress_checklists_active', '__return_true');
        add_filter('wpmcp_publishpress_future_active', '__return_true');
        add_filter('wpmcp_enable_publishpress_write', '__return_true');
        add_action(PublishPress_Pack::SCHEDULE_HOOK, 'wpmcp_test_publishpress_schedule', 10, 3);
        add_action(PublishPress_Pack::UNSCHEDULE_HOOK, 'wpmcp_test_publishpress_unschedule', 10, 1);
    }

    protected function tearDown(): void
    {
        remove_all_actions(PublishPress_Pack::SCHEDULE_HOOK);
        remove_all_actions(PublishPress_Pack::UNSCHEDULE_HOOK);
        foreach ([ 'wpmcp_publishpress_planner_active', 'wpmcp_publishpress_checklists_active', 'wpmcp_publishpress_future_active', 'wpmcp_enable_publishpress_write' ] as $filter) {
            remove_all_filters($filter);
        }
        global $wp_post_statuses;
        unset($wp_post_statuses['pitch'], $wp_post_statuses['assigned']);
        parent::tearDown();
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

    private function post(array $o = []): int
    {
        $o += [ 'title' => 'Story', 'status' => 'draft', 'date' => '2030-01-10 09:00:00', 'type' => 'post' ];
        return self::factory()->post->create([ 'post_title' => $o['title'], 'post_status' => $o['status'], 'post_date' => $o['date'], 'post_type' => $o['type'] ]);
    }

    private function enabled_rows(int $post_id): array
    {
        global $wpdb;
        return (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE post_id = %d AND enabled = 1', $wpdb->get_blog_prefix() . 'ppfuture_actions_args', $post_id), ARRAY_A);
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

    public function test_each_plugins_ops_need_that_plugin_and_name_it(): void
    {
        foreach ([
            [ 'wpmcp_publishpress_planner_active', 'publishpress-get-editorial-calendar', 'PublishPress Planner' ],
            [ 'wpmcp_publishpress_checklists_active', 'checklists-list-requirements', 'PublishPress Checklists' ],
            [ 'wpmcp_publishpress_future_active', 'future-list-scheduled-changes', 'PublishPress Future' ],
        ] as [ $filter, $op, $name ]) {
            remove_all_filters($filter);
            add_filter($filter, '__return_false');
            $out = $this->read($op);
            $this->assertSame('plugin_missing', $out['error']['code'] ?? null, (string) wp_json_encode($out));
            $this->assertStringContainsString('Install and activate ' . $name, $out['error']['message']);
            $this->assertSame(admin_url('plugins.php'), $out['error']['data']['plugins_url'] ?? null);
            remove_all_filters($filter);
            add_filter($filter, '__return_true');
        }
    }

    public function test_writes_are_off_until_the_site_opts_in(): void
    {
        $id = $this->post();
        remove_all_filters('wpmcp_enable_publishpress_write');
        foreach ([
            [ 'publishpress-set-post-status', [ 'id' => $id, 'status' => 'pitch' ] ],
            [ 'future-schedule-post-change', [ 'id' => $id, 'date' => '2031-01-01 10:00', 'action' => 'stick' ] ],
            [ 'future-cancel-post-change', [ 'id' => $id ] ],
        ] as [ $op, $args ]) {
            $out = $this->write($op, $args);
            $this->assertSame('opt_in_required', $out['error']['code'] ?? null, $op);
            $this->assertStringContainsString('wpmcp_enable_publishpress_write', $out['error']['message']);
        }
        $this->assertSame('draft', get_post_status($id));
        $this->assertSame([], $this->enabled_rows($id));
    }

    public function test_a_subscriber_may_neither_read_nor_write(): void
    {
        $id = $this->post();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'subscriber' ]));
        $this->assertSame('operation_denied', $this->read('publishpress-get-editorial-calendar')['error']['code'] ?? null);
        $this->assertSame('operation_denied', $this->read('future-list-scheduled-changes')['error']['code'] ?? null);
        $this->assertSame('operation_denied', $this->write('publishpress-set-post-status', [ 'id' => $id, 'status' => 'pitch' ])['error']['code'] ?? null);
        $this->assertSame('draft', get_post_status($id));
    }

    // ---------------------------------------------------------------
    // Planner
    // ---------------------------------------------------------------

    public function test_the_calendar_groups_the_calendar_post_types_by_day_and_keeps_to_readable_posts(): void
    {
        update_option('publishpress_calendar_options', (object) [ 'enabled' => 'on', 'post_types' => [ 'post' => 'on', 'page' => 'off' ] ]);
        $a     = $this->post([ 'title' => 'Alpha', 'date' => '2030-01-10 09:00:00', 'status' => 'pitch' ]);
        $b     = $this->post([ 'title' => 'Beta', 'date' => '2030-01-10 15:00:00', 'status' => 'future' ]);
        $c     = $this->post([ 'title' => 'Gamma', 'date' => '2030-01-12 08:00:00', 'status' => 'draft' ]);
        $page  = $this->post([ 'title' => 'A page', 'date' => '2030-01-11 08:00:00', 'type' => 'page' ]);
        $late  = $this->post([ 'title' => 'Later', 'date' => '2030-02-20 08:00:00' ]);
        $trash = $this->post([ 'title' => 'Gone', 'date' => '2030-01-11 08:00:00', 'status' => 'trash' ]);

        $out = $this->ok($this->read('publishpress-get-editorial-calendar', [ 'date_from' => '2030-01-09', 'date_to' => '2030-01-15' ]));
        $this->assertSame([ 'post' ], $out['post_types']);
        $this->assertSame([ '2030-01-10', '2030-01-12' ], array_column($out['days'], 'date'));
        $this->assertSame([ $a, $b ], array_column($out['days'][0]['posts'], 'id'));
        $this->assertSame([ $c ], array_column($out['days'][1]['posts'], 'id'));
        $this->assertSame('pitch', $out['days'][0]['posts'][0]['status']);
        $this->assertSame('Pitch', $out['days'][0]['posts'][0]['status_label']);
        $this->assertSame('15:00', $out['days'][0]['posts'][1]['time']);
        $this->assertSame(3, $out['total']);
        $this->assertContains('pitch', array_column($out['statuses'], 'slug'));
        $all = array_merge(...array_map(static fn ($d) => array_column($d['posts'], 'id'), $out['days']));
        $this->assertNotContains($page, $all);
        $this->assertNotContains($late, $all);
        $this->assertNotContains($trash, $all);

        // A contributor sees only their own drafts.
        $other = self::factory()->user->create([ 'role' => 'contributor' ]);
        wp_set_current_user($other);
        $out = $this->ok($this->read('publishpress-get-editorial-calendar', [ 'date_from' => '2030-01-09', 'date_to' => '2030-01-15' ]));
        $this->assertSame(0, $out['total'], (string) wp_json_encode($out));
    }

    public function test_the_calendar_refuses_a_range_over_three_months(): void
    {
        $out = $this->read('publishpress-get-editorial-calendar', [ 'date_from' => '2030-01-01', 'date_to' => '2030-06-01' ]);
        $this->assertSame('invalid_params', $out['error']['code'] ?? null, (string) wp_json_encode($out));
    }

    public function test_set_post_status_moves_a_draft_to_a_custom_status_and_undoes(): void
    {
        $id  = $this->post([ 'status' => 'draft' ]);
        $out = $this->ok($this->write('publishpress-set-post-status', [ 'id' => $id, 'status' => 'pitch' ]));
        $this->assertSame('pitch', get_post_status($id));
        $this->assertSame('pitch', $out['result']['post']['status']);
        $this->assertNotEmpty($out['operation_id']);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        clean_post_cache($id);
        $this->assertSame('draft', get_post_status($id));
    }

    public function test_set_post_status_refuses_published_posts_public_statuses_and_unknown_ones(): void
    {
        $draft     = $this->post();
        $published = $this->post([ 'status' => 'publish', 'date' => '2020-01-01 00:00:00' ]);
        $this->assertSame('status_not_allowed', $this->write('publishpress-set-post-status', [ 'id' => $draft, 'status' => 'publish' ])['error']['code'] ?? null);
        $this->assertSame('status_not_allowed', $this->write('publishpress-set-post-status', [ 'id' => $draft, 'status' => 'private' ])['error']['code'] ?? null);
        $this->assertSame('status_not_allowed', $this->write('publishpress-set-post-status', [ 'id' => $draft, 'status' => 'trash' ])['error']['code'] ?? null);
        $this->assertSame('unknown_status', $this->write('publishpress-set-post-status', [ 'id' => $draft, 'status' => 'nope' ])['error']['code'] ?? null);
        $this->assertSame('post_published', $this->write('publishpress-set-post-status', [ 'id' => $published, 'status' => 'pitch' ])['error']['code'] ?? null);
        $this->assertSame('no_change', $this->write('publishpress-set-post-status', [ 'id' => $draft, 'status' => 'draft' ])['error']['code'] ?? null);
        $this->assertSame('draft', get_post_status($draft));
        $this->assertSame('publish', get_post_status($published));
    }

    // ---------------------------------------------------------------
    // Checklists
    // ---------------------------------------------------------------

    public function test_list_requirements_reads_the_rules_per_post_type(): void
    {
        update_option('publishpress_checklists_checklists_options', (object) [
            'enabled'             => 'on',
            'custom_items'        => [ 'ck1' ],
            'title_count_rule'    => [ 'post' => 'block', 'page' => 'off' ],
            'title_count_min'     => [ 'post' => '10' ],
            'title_count_max'     => [ 'post' => '60' ],
            'featured_image_rule' => [ 'post' => 'warning', 'page' => 'only_display' ],
            'ck1_rule'            => [ 'post' => 'block' ],
            'ck1_title'           => [ 'post' => 'Legal reviewed' ],
        ]);

        $out  = $this->ok($this->read('checklists-list-requirements'));
        $post = array_column($out['post_types']['post'], null, 'requirement');
        $this->assertSame('required', $post['title_count']['level']);
        $this->assertSame('block', $post['title_count']['rule']);
        $this->assertSame(10, $post['title_count']['min']);
        $this->assertSame(60, $post['title_count']['max']);
        $this->assertSame('recommended', $post['featured_image']['level']);
        $this->assertSame('Legal reviewed', $post['ck1']['title']);
        $this->assertTrue($post['ck1']['custom']);
        $page = array_column($out['post_types']['page'], null, 'requirement');
        $this->assertArrayNotHasKey('title_count', $page, 'disabled rules are left out by default');
        $this->assertSame('recommended', $page['featured_image']['level']);

        $out = $this->ok($this->read('checklists-list-requirements', [ 'post_type' => 'page', 'include_disabled' => true ]));
        $this->assertSame([ 'page' ], array_keys($out['post_types']));
        $page = array_column($out['post_types']['page'], null, 'requirement');
        $this->assertSame('disabled', $page['title_count']['level']);
    }

    // ---------------------------------------------------------------
    // Future
    // ---------------------------------------------------------------

    public function test_schedule_a_change_lists_it_and_undo_unschedules_it(): void
    {
        $id  = $this->post([ 'status' => 'publish', 'date' => '2020-01-01 00:00:00' ]);
        $out = $this->ok($this->write('future-schedule-post-change', [ 'id' => $id, 'date' => '2031-03-04 10:30', 'action' => 'change-status', 'new_status' => 'draft' ]));
        $this->assertNotEmpty($out['operation_id']);
        $this->assertSame('change-status', $out['result']['change']['action']);
        $this->assertSame('draft', $out['result']['change']['new_status']);
        $this->assertSame('2031-03-04 10:30:00', $out['result']['change']['scheduled_date']);
        $this->assertCount(1, $this->enabled_rows($id));

        $list = $this->ok($this->read('future-list-scheduled-changes'));
        $this->assertSame([ $id ], array_column($list['changes'], 'post_id'));
        $this->assertSame(1, $list['total']);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame([], $this->enabled_rows($id));
        $this->assertSame('', (string) get_post_meta($id, '_expiration-date-status', true), 'the meta is back as it was');
        $this->assertSame(0, $this->ok($this->read('future-list-scheduled-changes'))['total']);
    }

    public function test_cancel_a_change_and_undo_schedules_it_again(): void
    {
        $id = $this->post([ 'status' => 'publish', 'date' => '2020-01-01 00:00:00' ]);
        $t  = get_term_by('slug', 'uncategorized', 'category');
        $this->ok($this->write('future-schedule-post-change', [ 'id' => $id, 'date' => '2031-05-01 08:00', 'action' => 'category-add', 'taxonomy' => 'category', 'terms' => [ (int) $t->term_id ] ]));
        $before = $this->enabled_rows($id)[0];

        $out = $this->ok($this->write('future-cancel-post-change', [ 'id' => $id ]));
        $this->assertSame([], $this->enabled_rows($id));

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $rows = $this->enabled_rows($id);
        $this->assertCount(1, $rows);
        $this->assertSame($before['scheduled_date'], $rows[0]['scheduled_date']);
        $args = json_decode($rows[0]['args'], true);
        $this->assertSame('category-add', $args['expireType']);
        $this->assertSame('category', $args['categoryTaxonomy']);
        $this->assertSame([ (int) $t->term_id ], array_map('intval', (array) $args['category']));
        $this->assertSame('saved', get_post_meta($id, '_expiration-date-status', true));
    }

    public function test_schedule_refuses_a_past_date_delete_and_a_missing_status(): void
    {
        $id = $this->post([ 'status' => 'publish', 'date' => '2020-01-01 00:00:00' ]);
        $this->assertSame('invalid_params', $this->write('future-schedule-post-change', [ 'id' => $id, 'date' => '2001-01-01 10:00', 'action' => 'stick' ])['error']['code'] ?? null);
        $this->assertSame('invalid_params', $this->write('future-schedule-post-change', [ 'id' => $id, 'date' => '2031-01-01 10:00', 'action' => 'delete' ])['error']['code'] ?? null);
        $this->assertSame('invalid_params', $this->write('future-schedule-post-change', [ 'id' => $id, 'date' => '2031-01-01 10:00', 'action' => 'change-status' ])['error']['code'] ?? null);
        $this->assertSame('invalid_params', $this->write('future-schedule-post-change', [ 'id' => $id, 'date' => '2031-01-01 10:00', 'action' => 'category', 'taxonomy' => 'post_tag', 'terms' => [ 999999 ] ])['error']['code'] ?? null);
        $this->assertSame('no_change', $this->write('future-cancel-post-change', [ 'id' => $id ])['error']['code'] ?? null);
        $this->assertSame([], $this->enabled_rows($id));
    }

    public function test_a_schedule_the_plugin_refuses_is_reported(): void
    {
        remove_all_actions(PublishPress_Pack::SCHEDULE_HOOK);
        $id  = $this->post([ 'status' => 'publish', 'date' => '2020-01-01 00:00:00' ]);
        $out = $this->write('future-schedule-post-change', [ 'id' => $id, 'date' => '2031-01-01 10:00', 'action' => 'stick' ]);
        $this->assertSame('schedule_failed', $out['error']['code'] ?? null, (string) wp_json_encode($out));
    }

    public function test_the_scheduled_list_keeps_to_readable_posts(): void
    {
        $mine = $this->post([ 'status' => 'publish', 'date' => '2020-01-01 00:00:00' ]);
        $this->ok($this->write('future-schedule-post-change', [ 'id' => $mine, 'date' => '2031-01-01 10:00', 'action' => 'unstick' ]));
        $draft = $this->post([ 'status' => 'draft' ]);
        $this->ok($this->write('future-schedule-post-change', [ 'id' => $draft, 'date' => '2031-01-02 10:00', 'action' => 'stick' ]));

        wp_set_current_user(self::factory()->user->create([ 'role' => 'contributor' ]));
        $out = $this->ok($this->read('future-list-scheduled-changes'));
        $this->assertSame([ $mine ], array_column($out['changes'], 'post_id'));
    }
}
