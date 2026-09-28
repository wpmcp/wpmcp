<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Theme_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot;
use WPMCP\Safety\Snapshot_Store;

require_once __DIR__ . '/../../support/redirection-tables.php';

/**
 * The Redirection plugin adapter (issue #300, first slice): reads and writes
 * the plugin's own redirects as free ops on the theme dispatcher pair, so the
 * adapter adds no top-level tools.
 *
 * Presence is driven through the wpmcp_redirection_active filter and the
 * plugin's two tables are created from its own schema
 * (tests/support/redirection-tables.php), so the plugin never has to be
 * installed into the shared test core.
 *
 * Every write is snapshotted as a 'redirection_item' row image, so
 * rollback-operation restores exactly: an edit goes back in place, a deleted
 * redirect returns at its own id and a created one is removed again.
 */
class RedirectionPackTest extends \WP_UnitTestCase
{
    private const OPS = [
        'list-redirection-groups',
        'list-redirection-redirects',
        'get-redirection-redirect',
        'create-redirection-redirect',
        'update-redirection-redirect',
        'enable-redirection-redirect',
        'disable-redirection-redirect',
        'delete-redirection-redirect',
    ];

    private int $group;

    public static function wpSetUpBeforeClass(): void
    {
        wpmcp_test_create_redirection_tables();
    }

    public static function wpTearDownAfterClass(): void
    {
        wpmcp_test_drop_redirection_tables();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        add_filter('wpmcp_redirection_active', '__return_true');
        $this->group = wpmcp_test_redirection_group('Redirections');
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_redirection_active');
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function catalog(): array
    {
        return array_column((new Theme_Integration())->catalog()['operations'], null, 'name');
    }

    private function read(string $op, array $args = []): array
    {
        return (new Theme_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
    }

    private function write(string $op, array $args, bool $confirm = false): array
    {
        $call = [ 'operation' => $op, 'args' => $args ];
        if ($confirm) {
            $call['confirm'] = true;
        }
        return (new Theme_Integration())->handle_write($call);
    }

    private function item_count(): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $wpdb->prefix . 'redirection_items'));
    }

    // ---------------------------------------------------------------
    // Catalog and presence
    // ---------------------------------------------------------------

    public function test_every_op_is_a_free_op_on_the_theme_pair(): void
    {
        Gate::set_pro_for_tests(false);
        $ops = $this->catalog();

        foreach (self::OPS as $name) {
            $this->assertArrayHasKey($name, $ops, "{$name} must be on the theme pair without a license");
            $this->assertSame('manage_options', $ops[ $name ]['capability'], "{$name} runs at Redirection's own admin capability");
            $this->assertTrue($ops[ $name ]['enabled']);
        }
        $this->assertSame('read', $ops['list-redirection-redirects']['mode']);
        $this->assertSame('write', $ops['create-redirection-redirect']['mode']);
        $this->assertSame('destructive', $ops['delete-redirection-redirect']['mode']);
    }

    public function test_ops_are_skipped_cleanly_while_redirection_is_inactive(): void
    {
        remove_all_filters('wpmcp_redirection_active');
        add_filter('wpmcp_redirection_active', '__return_false');

        $ops = $this->catalog();
        $this->assertFalse($ops['list-redirection-redirects']['dependency_met']);

        $before_items = $this->item_count();
        $before_snaps = Snapshot_Store::row_count();

        $read = $this->read('list-redirection-redirects');
        $this->assertSame('redirection_inactive', $read['error']['code']);

        $write = $this->write('create-redirection-redirect', [ 'source' => '/old', 'target' => '/new' ]);
        $this->assertSame('redirection_inactive', $write['error']['code']);

        $this->assertSame($before_items, $this->item_count());
        $this->assertSame($before_snaps, Snapshot_Store::row_count());
    }

    public function test_presence_defaults_to_the_plugin_constant(): void
    {
        remove_all_filters('wpmcp_redirection_active');

        $expected = defined('REDIRECTION_VERSION');
        $this->assertSame($expected, $this->catalog()['list-redirection-groups']['dependency_met']);
    }

    // ---------------------------------------------------------------
    // Reads
    // ---------------------------------------------------------------

    public function test_list_groups_reports_each_group_with_its_item_count(): void
    {
        $other = wpmcp_test_redirection_group('Apache', 2, 'disabled');
        wpmcp_test_redirection_item('/a', '/b', $this->group);
        wpmcp_test_redirection_item('/c', '/d', $this->group);

        $out = $this->read('list-redirection-groups');
        $this->assertArrayNotHasKey('error', $out);
        $groups = array_column($out['result']['groups'], null, 'id');

        $this->assertSame('Redirections', $groups[ $this->group ]['name']);
        $this->assertSame(1, $groups[ $this->group ]['module_id']);
        $this->assertTrue($groups[ $this->group ]['enabled']);
        $this->assertSame(2, $groups[ $this->group ]['items']);
        $this->assertFalse($groups[ $other ]['enabled']);
        $this->assertSame(0, $groups[ $other ]['items']);
    }

    public function test_list_redirects_filters_and_pages(): void
    {
        $second = wpmcp_test_redirection_group('Second');
        $a      = wpmcp_test_redirection_item('/alpha', '/alpha-new', $this->group, [ 'title' => 'Alpha move', 'last_count' => 7 ]);
        wpmcp_test_redirection_item('/beta', 'https://example.org/beta', $this->group, [ 'status' => 'disabled' ]);
        wpmcp_test_redirection_item('/gamma', '/gamma-new', $second);

        $all = $this->read('list-redirection-redirects');
        $this->assertSame(3, $all['result']['total']);

        $row = array_column($all['result']['redirects'], null, 'id')[ $a ];
        $this->assertSame('/alpha', $row['source']);
        $this->assertSame('/alpha-new', $row['target']);
        $this->assertSame('url', $row['action_type']);
        $this->assertSame(301, $row['action_code']);
        $this->assertSame('url', $row['match_type']);
        $this->assertFalse($row['regex']);
        $this->assertTrue($row['enabled']);
        $this->assertSame($this->group, $row['group_id']);
        $this->assertSame('Alpha move', $row['title']);
        $this->assertSame(7, $row['hits']);

        $by_group = $this->read('list-redirection-redirects', [ 'group_id' => $second ]);
        $this->assertSame([ '/gamma' ], array_column($by_group['result']['redirects'], 'source'));

        $disabled = $this->read('list-redirection-redirects', [ 'status' => 'disabled' ]);
        $this->assertSame([ '/beta' ], array_column($disabled['result']['redirects'], 'source'));

        $search = $this->read('list-redirection-redirects', [ 'search' => 'example.org' ]);
        $this->assertSame([ '/beta' ], array_column($search['result']['redirects'], 'source'));

        $page = $this->read('list-redirection-redirects', [ 'per_page' => 2, 'page' => 2 ]);
        $this->assertSame(3, $page['result']['total']);
        $this->assertCount(1, $page['result']['redirects']);
    }

    public function test_a_conditional_redirect_is_listed_without_unserializing_its_data(): void
    {
        $id = wpmcp_test_redirection_item('/members', '', $this->group, [
            'match_type'  => 'login',
            'action_data' => serialize([ 'logged_in' => '/in', 'logged_out' => '/out' ]),
        ]);

        $out = $this->read('get-redirection-redirect', [ 'id' => $id ]);
        $this->assertSame('login', $out['result']['redirect']['match_type']);
        $this->assertNull($out['result']['redirect']['target'], 'Serialized match data is never unserialized or echoed');
    }

    public function test_get_unknown_redirect_is_a_top_level_error(): void
    {
        $out = $this->read('get-redirection-redirect', [ 'id' => 999999 ]);
        $this->assertSame('redirect_not_found', $out['error']['code']);
    }

    // ---------------------------------------------------------------
    // Create
    // ---------------------------------------------------------------

    public function test_create_writes_a_redirect_redirection_can_match_and_rollback_removes_it(): void
    {
        $out = $this->write('create-redirection-redirect', [
            'source' => '/Old-Page/',
            'target' => '/new-page',
            'title'  => 'Moved',
        ]);

        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertTrue($out['recoverable']);
        $id  = (int) $out['result']['redirect']['id'];
        $row = wpmcp_test_redirection_row($id);

        $this->assertSame('/Old-Page/', $row['url']);
        $this->assertSame('/old-page', $row['match_url'], 'match_url is what Redirection looks a request up by');
        $this->assertSame('/new-page', $row['action_data']);
        $this->assertSame('url', $row['action_type']);
        $this->assertSame('url', $row['match_type']);
        $this->assertSame('301', (string) $row['action_code']);
        $this->assertSame('0', (string) $row['regex']);
        $this->assertSame('enabled', $row['status']);
        $this->assertSame((string) $this->group, (string) $row['group_id'], 'defaults to the first WordPress-module group');
        $this->assertSame('Moved', $row['title']);

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertNull(wpmcp_test_redirection_row($id));
    }

    public function test_create_an_error_redirect_needs_no_target(): void
    {
        $out = $this->write('create-redirection-redirect', [
            'source'      => '/gone',
            'action_type' => 'error',
            'action_code' => 410,
        ]);

        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $row = wpmcp_test_redirection_row((int) $out['result']['redirect']['id']);
        $this->assertSame('error', $row['action_type']);
        $this->assertSame('410', (string) $row['action_code']);
        $this->assertNull($row['action_data']);
    }

    public function test_create_honors_a_disabled_start_and_an_explicit_group(): void
    {
        $second = wpmcp_test_redirection_group('Second');
        $out    = $this->write('create-redirection-redirect', [
            'source'      => '/later',
            'target'      => '/soon',
            'group_id'    => $second,
            'enabled'     => false,
            'action_code' => 302,
        ]);

        $row = wpmcp_test_redirection_row((int) $out['result']['redirect']['id']);
        $this->assertSame('disabled', $row['status']);
        $this->assertSame((string) $second, (string) $row['group_id']);
        $this->assertSame('302', (string) $row['action_code']);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function refused_creates(): array
    {
        return [
            'javascript target'      => [ [ 'source' => '/x', 'target' => 'javascript:alert(1)' ], 'invalid_target' ],
            'data target'            => [ [ 'source' => '/x', 'target' => 'data:text/html,hi' ], 'invalid_target' ],
            'protocol relative'      => [ [ 'source' => '/x', 'target' => '//evil.example/' ], 'invalid_target' ],
            'bare word target'       => [ [ 'source' => '/x', 'target' => 'new-page' ], 'invalid_target' ],
            'target with newline'    => [ [ 'source' => '/x', 'target' => "/a\nLocation: /b" ], 'invalid_target' ],
            'url action no target'   => [ [ 'source' => '/x' ], 'invalid_target' ],
            'site root source'       => [ [ 'source' => '/', 'target' => '/y' ], 'invalid_source' ],
            'absolute source'        => [ [ 'source' => 'https://other.example/x', 'target' => '/y' ], 'invalid_source' ],
            'source with newline'    => [ [ 'source' => "/x\n", 'target' => '/y' ], 'invalid_source' ],
            'self loop'              => [ [ 'source' => '/loop', 'target' => '/loop/' ], 'redirect_loop' ],
            'self loop absolute'     => [ [ 'source' => '/loop', 'target' => 'HOME/loop' ], 'redirect_loop' ],
            'bad redirect code'      => [ [ 'source' => '/x', 'target' => '/y', 'action_code' => 200 ], 'invalid_action_code' ],
            'bad error code'         => [ [ 'source' => '/x', 'action_type' => 'error', 'action_code' => 301 ], 'invalid_action_code' ],
            'unknown group'          => [ [ 'source' => '/x', 'target' => '/y', 'group_id' => 999999 ], 'group_not_found' ],
        ];
    }

    /** @dataProvider refused_creates */
    public function test_create_refuses_bad_input_without_side_effects(array $args, string $code): void
    {
        if (isset($args['target'])) {
            $args['target'] = str_replace('HOME', untrailingslashit(home_url()), (string) $args['target']);
        }
        $items = $this->item_count();
        $snaps = Snapshot_Store::row_count();

        $out = $this->write('create-redirection-redirect', $args);

        $this->assertSame($code, $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertSame($items, $this->item_count());
        $this->assertSame($snaps, Snapshot_Store::row_count(), 'A refused write takes no snapshot');
    }

    public function test_create_refuses_a_loop_through_existing_redirects(): void
    {
        wpmcp_test_redirection_item('/b', '/c', $this->group);
        wpmcp_test_redirection_item('/c', home_url('/a'), $this->group);
        $items = $this->item_count();

        $out = $this->write('create-redirection-redirect', [ 'source' => '/a', 'target' => '/b' ]);

        $this->assertSame('redirect_loop', $out['error']['code']);
        $this->assertSame([ '/a', '/b', '/c', '/a' ], $out['error']['data']['chain']);
        $this->assertSame($items, $this->item_count());
    }

    public function test_a_disabled_hop_does_not_count_as_a_loop(): void
    {
        wpmcp_test_redirection_item('/b', '/a', $this->group, [ 'status' => 'disabled' ]);

        $out = $this->write('create-redirection-redirect', [ 'source' => '/a', 'target' => '/b' ]);

        $this->assertArrayNotHasKey('error', $out);
    }

    public function test_an_external_target_is_accepted(): void
    {
        $out = $this->write('create-redirection-redirect', [ 'source' => '/out', 'target' => 'https://example.org/landing?x=1' ]);

        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertSame('https://example.org/landing?x=1', wpmcp_test_redirection_row((int) $out['result']['redirect']['id'])['action_data']);
    }

    public function test_create_refuses_a_source_already_redirected(): void
    {
        $existing = wpmcp_test_redirection_item('/taken', '/somewhere', $this->group);

        $out = $this->write('create-redirection-redirect', [ 'source' => '/Taken/', 'target' => '/elsewhere' ]);

        $this->assertSame('duplicate_source', $out['error']['code']);
        $this->assertSame($existing, $out['error']['data']['redirect_id']);
    }

    // ---------------------------------------------------------------
    // Update, enable, disable
    // ---------------------------------------------------------------

    public function test_update_changes_only_passed_fields_and_rollback_restores_the_row_exactly(): void
    {
        $id     = wpmcp_test_redirection_item('/from', '/to', $this->group, [ 'title' => 'Keep me', 'last_count' => 3 ]);
        $before = wpmcp_test_redirection_row($id);

        $out = $this->write('update-redirection-redirect', [ 'id' => $id, 'target' => '/to-v2', 'action_code' => 308 ]);

        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $after = wpmcp_test_redirection_row($id);
        $this->assertSame('/to-v2', $after['action_data']);
        $this->assertSame('308', (string) $after['action_code']);
        $this->assertSame('Keep me', $after['title']);
        $this->assertSame('/from', $after['url']);
        $this->assertSame('3', (string) $after['last_count']);

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame($before, wpmcp_test_redirection_row($id));
    }

    public function test_update_source_recomputes_the_match_url(): void
    {
        $id = wpmcp_test_redirection_item('/from', '/to', $this->group);

        $this->write('update-redirection-redirect', [ 'id' => $id, 'source' => '/Renamed/' ]);

        $row = wpmcp_test_redirection_row($id);
        $this->assertSame('/Renamed/', $row['url']);
        $this->assertSame('/renamed', $row['match_url']);
    }

    public function test_update_refuses_a_loop_it_would_close(): void
    {
        $a = wpmcp_test_redirection_item('/a', '/b', $this->group);
        wpmcp_test_redirection_item('/b', '/c', $this->group);
        $before = wpmcp_test_redirection_row($a);
        $snaps  = Snapshot_Store::row_count();

        $out = $this->write('update-redirection-redirect', [ 'id' => $a, 'target' => '/b', 'source' => '/c' ]);

        $this->assertSame('redirect_loop', $out['error']['code']);
        $this->assertSame($before, wpmcp_test_redirection_row($a));
        $this->assertSame($snaps, Snapshot_Store::row_count());
    }

    public function test_retargeting_a_redirect_is_not_a_loop_with_its_own_old_self(): void
    {
        $a = wpmcp_test_redirection_item('/a', '/b', $this->group);

        $out = $this->write('update-redirection-redirect', [ 'id' => $a, 'target' => '/b2' ]);

        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
    }

    public function test_update_refuses_a_conditional_redirect_it_cannot_represent(): void
    {
        $id     = wpmcp_test_redirection_item('/members', '', $this->group, [
            'match_type'  => 'login',
            'action_data' => serialize([ 'logged_in' => '/in', 'logged_out' => '/out' ]),
        ]);
        $before = wpmcp_test_redirection_row($id);

        $out = $this->write('update-redirection-redirect', [ 'id' => $id, 'target' => '/x' ]);

        $this->assertSame('unsupported_redirect', $out['error']['code']);
        $this->assertSame($before, wpmcp_test_redirection_row($id));
    }

    public function test_update_unknown_redirect_is_refused(): void
    {
        $out = $this->write('update-redirection-redirect', [ 'id' => 999999, 'target' => '/x' ]);
        $this->assertSame('redirect_not_found', $out['error']['code']);
    }

    public function test_disable_and_enable_flip_status_and_roll_back(): void
    {
        $id = wpmcp_test_redirection_item('/toggle', '/there', $this->group);

        $off = $this->write('disable-redirection-redirect', [ 'id' => $id ]);
        $this->assertArrayNotHasKey('error', $off, (string) wp_json_encode($off));
        $this->assertSame('disabled', wpmcp_test_redirection_row($id)['status']);
        $this->assertFalse($off['result']['redirect']['enabled']);

        $on = $this->write('enable-redirection-redirect', [ 'id' => $id ]);
        $this->assertSame('enabled', wpmcp_test_redirection_row($id)['status']);

        $this->assertTrue(Rollback_Service::restore_operation((string) $on['operation_id']));
        $this->assertSame('disabled', wpmcp_test_redirection_row($id)['status']);
        $this->assertTrue(Rollback_Service::restore_operation((string) $off['operation_id']));
        $this->assertSame('enabled', wpmcp_test_redirection_row($id)['status']);
    }

    public function test_enabling_a_redirect_that_would_close_a_loop_is_refused(): void
    {
        wpmcp_test_redirection_item('/a', '/b', $this->group);
        $back = wpmcp_test_redirection_item('/b', '/a', $this->group, [ 'status' => 'disabled' ]);

        $out = $this->write('enable-redirection-redirect', [ 'id' => $back ]);

        $this->assertSame('redirect_loop', $out['error']['code']);
        $this->assertSame('disabled', wpmcp_test_redirection_row($back)['status']);
    }

    // ---------------------------------------------------------------
    // Delete
    // ---------------------------------------------------------------

    public function test_delete_needs_confirm(): void
    {
        $id = wpmcp_test_redirection_item('/keep', '/there', $this->group);

        $out = $this->write('delete-redirection-redirect', [ 'id' => $id ]);

        $this->assertSame('confirmation_required', $out['error']['code']);
        $this->assertNotNull(wpmcp_test_redirection_row($id));
    }

    public function test_delete_then_rollback_resurrects_the_same_row_at_its_id(): void
    {
        $id     = wpmcp_test_redirection_item('/bye', '/there', $this->group, [ 'title' => null, 'last_count' => 12, 'last_access' => '2026-02-03 04:05:06' ]);
        $before = wpmcp_test_redirection_row($id);

        $out = $this->write('delete-redirection-redirect', [ 'id' => $id ], true);

        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertNull(wpmcp_test_redirection_row($id));

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame($before, wpmcp_test_redirection_row($id), 'same id, same columns, NULL kept NULL');
    }

    // ---------------------------------------------------------------
    // Capability and snapshot type
    // ---------------------------------------------------------------

    public function test_a_theme_editor_without_manage_options_is_refused(): void
    {
        $user = self::factory()->user->create([ 'role' => 'editor' ]);
        get_userdata($user)->add_cap('edit_theme_options');
        wp_set_current_user($user);

        $out = $this->read('list-redirection-redirects');

        $this->assertSame('operation_denied', $out['error']['code']);
    }

    public function test_redirection_item_is_a_restorable_type_that_needs_manage_options(): void
    {
        $this->assertContains('redirection_item', Rollback_Service::restorable_object_types());

        $id       = wpmcp_test_redirection_item('/guarded', '/there', $this->group);
        $snapshot = Snapshot::capture('redirection_item', (string) $id);
        $this->assertSame('redirection_item', $snapshot['object_type']);
        $this->assertSame(wpmcp_test_redirection_row($id), $snapshot['data']['row']);

        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        $this->expectException(\WPMCP\Safety\Mutation_Failed::class);
        Rollback_Service::apply_snapshot($snapshot);
    }

    public function test_restore_leaves_other_redirects_alone(): void
    {
        $a = wpmcp_test_redirection_item('/one', '/x', $this->group);
        $b = wpmcp_test_redirection_item('/two', '/y', $this->group);

        $out = $this->write('update-redirection-redirect', [ 'id' => $a, 'target' => '/x2' ]);
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'redirection_items', [ 'action_data' => '/y-later' ], [ 'id' => $b ]);

        Rollback_Service::restore_operation((string) $out['operation_id']);

        $this->assertSame('/x', wpmcp_test_redirection_row($a)['action_data']);
        $this->assertSame('/y-later', wpmcp_test_redirection_row($b)['action_data']);
    }
}
