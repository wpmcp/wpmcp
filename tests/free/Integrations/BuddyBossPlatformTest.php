<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Safety\Snapshot_Store;

require_once __DIR__ . '/../../support/buddypress-tables.php';

/**
 * Issue #670: BuddyBoss Platform forks BuddyPress and bundles its own copy
 * of bbPress, so the buddypress-* and bbpress-* ops serve it unchanged
 * (BuddyBoss defines buddypress() and bbpress()). What it adds here:
 *
 * - buddypress-get-group lists the forums linked to a group (the group's
 *   forum_id meta, as BuddyBoss and bbPress's group extension store it),
 *   kept to the forums the caller may read, so an agent can go straight to
 *   bbpress-list-topics.
 * - Refusals name BuddyBoss Platform: when it is installed but not active,
 *   when nothing is installed (BuddyPress or BuddyBoss Platform), and when
 *   BuddyBoss runs with its Forums component off.
 * - The pair's "no plugin active" refusals list BuddyBoss Platform for the
 *   ops it serves.
 */
class BuddyBossPlatformTest extends \WP_UnitTestCase
{
    private const OTHER_FILTERS = [ 'wpmcp_jetengine_active', 'wpmcp_pods_active', 'wpmcp_translatepress_active', 'wpmcp_action_scheduler_active' ];

    private const FIXTURE_DIR = 'buddyboss-platform';

    private int $admin;

    private bool $made_fixture = false;

    public static function wpSetUpBeforeClass(): void
    {
        wpmcp_test_create_buddypress_tables();
    }

    public static function wpTearDownAfterClass(): void
    {
        wpmcp_test_drop_buddypress_tables();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        $this->admin = self::factory()->user->create([ 'role' => 'administrator' ]);
        wp_set_current_user($this->admin);
        foreach (self::OTHER_FILTERS as $filter) {
            add_filter($filter, '__return_false');
        }
        add_filter('wpmcp_buddypress_active', '__return_true');
        if (! post_type_exists('forum')) {
            register_post_type('forum', [ 'public' => true, 'hierarchical' => true ]);
        }
    }

    protected function tearDown(): void
    {
        foreach (self::OTHER_FILTERS as $filter) {
            remove_all_filters($filter);
        }
        remove_all_filters('wpmcp_buddypress_active');
        remove_all_filters('wpmcp_buddyboss_active');
        remove_all_filters('wpmcp_bbpress_active');
        if ($this->made_fixture) {
            $dir = WP_PLUGIN_DIR . '/' . self::FIXTURE_DIR;
            @unlink($dir . '/bp-loader.php');
            @rmdir($dir);
        }
        unregister_post_type('forum');
        wp_set_current_user(0);
        parent::tearDown();
    }

    private function read(string $op, array $args = []): array
    {
        return (new Plugin_Data_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
    }

    private function forum(string $title, string $status = 'publish'): int
    {
        return self::factory()->post->create([ 'post_type' => 'forum', 'post_title' => $title, 'post_status' => $status, 'post_author' => $this->admin ]);
    }

    /** A BuddyBoss Platform main file in the plugins directory, as an installed but inactive copy. */
    private function install_inactive_buddyboss(): void
    {
        $dir = WP_PLUGIN_DIR . '/' . self::FIXTURE_DIR;
        if (file_exists($dir . '/bp-loader.php')) {
            return;
        }
        wp_mkdir_p($dir);
        file_put_contents($dir . '/bp-loader.php', "<?php\n");
        $this->made_fixture = true;
    }

    public function test_get_group_lists_the_linked_forums_the_caller_may_read(): void
    {
        add_filter('wpmcp_buddyboss_active', '__return_true');
        $open    = $this->forum('Open forum');
        $private = $this->forum('Private forum', 'private');
        $page    = self::factory()->post->create([ 'post_type' => 'page' ]);
        $group   = wpmcp_test_bp_group('Hikers', 'public', $this->admin, [ 'enable_forum' => 1 ]);
        wpmcp_test_bp_insert('bp_groups_groupmeta', [ 'group_id' => $group, 'meta_key' => 'forum_id', 'meta_value' => maybe_serialize([ $open, $private, $page, 999999 ]) ]);

        $out = $this->read('buddypress-get-group', [ 'id' => $group ]);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertSame([ $open, $private ], $out['result']['group']['forum_ids'], 'only real forums, in link order');

        wp_set_current_user(self::factory()->user->create([ 'role' => 'subscriber' ]));
        $out = $this->read('buddypress-get-group', [ 'id' => $group ]);
        $this->assertSame([ $open ], $out['result']['group']['forum_ids'], 'a private forum stays hidden from a caller who cannot read it');
    }

    public function test_get_group_reads_a_single_forum_id_and_an_unlinked_group(): void
    {
        $open  = $this->forum('Open forum');
        $one   = wpmcp_test_bp_group('One', 'public', $this->admin);
        $none  = wpmcp_test_bp_group('None', 'public', $this->admin);
        wpmcp_test_bp_insert('bp_groups_groupmeta', [ 'group_id' => $one, 'meta_key' => 'forum_id', 'meta_value' => (string) $open ]);

        $this->assertSame([ $open ], $this->read('buddypress-get-group', [ 'id' => $one ])['result']['group']['forum_ids']);
        $this->assertSame([], $this->read('buddypress-get-group', [ 'id' => $none ])['result']['group']['forum_ids']);
    }

    public function test_list_groups_stays_without_forum_ids(): void
    {
        wpmcp_test_bp_group('Hikers', 'public', $this->admin);
        $out = $this->read('buddypress-list-groups');
        $this->assertArrayNotHasKey('forum_ids', $out['result']['groups'][0]);
    }

    public function test_an_inactive_buddyboss_install_is_named_in_the_refusal(): void
    {
        remove_all_filters('wpmcp_buddypress_active');
        add_filter('wpmcp_buddypress_active', '__return_false');
        add_filter('wpmcp_pods_active', '__return_true', 20);
        $this->install_inactive_buddyboss();

        $out = $this->read('buddypress-list-groups');
        $this->assertSame('buddypress_inactive', $out['error']['code']);
        $this->assertStringStartsWith('BuddyBoss Platform is installed but not active on this site.', $out['error']['message']);
        $this->assertSame('buddyboss-platform/bp-loader.php', $out['error']['data']['required_plugin']);
    }

    public function test_with_nothing_installed_the_refusal_offers_both_plugins(): void
    {
        if (file_exists(WP_PLUGIN_DIR . '/buddyboss-platform/bp-loader.php') || file_exists(WP_PLUGIN_DIR . '/buddypress/bp-loader.php')) {
            $this->markTestSkipped('a BuddyPress or BuddyBoss copy is installed in this test core');
        }
        remove_all_filters('wpmcp_buddypress_active');
        add_filter('wpmcp_buddypress_active', '__return_false');
        add_filter('wpmcp_pods_active', '__return_true', 20);

        $out = $this->read('buddypress-list-groups');
        $this->assertSame('buddypress_inactive', $out['error']['code']);
        $this->assertStringStartsWith('BuddyPress is not installed on this site.', $out['error']['message']);
        $this->assertStringContainsString('BuddyBoss Platform works too', $out['error']['message']);
    }

    public function test_buddyboss_with_forums_off_names_the_component(): void
    {
        add_filter('wpmcp_buddyboss_active', '__return_true');
        add_filter('wpmcp_bbpress_active', '__return_false');

        $out = $this->read('bbpress-list-forums');
        $this->assertSame('bbpress_inactive', $out['error']['code']);
        $this->assertStringContainsString('BuddyBoss Platform Forums component is not active', $out['error']['message']);
        $this->assertStringContainsString(admin_url('admin.php?page=bp-components'), $out['error']['message']);
    }

    public function test_the_pair_names_buddyboss_for_the_ops_it_serves(): void
    {
        $hosts = (new Plugin_Data_Integration())->operation_hosts();
        $this->assertSame([ 'name' => 'BuddyPress, BuddyBoss Platform', 'several' => true ], $hosts['buddypress-get-group']);
        $this->assertSame([ 'name' => 'bbPress, BuddyBoss Platform', 'several' => true ], $hosts['bbpress-list-forums']);
    }
}
