<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Integrations\WpForo_Pack;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

require_once __DIR__ . '/../../support/forum-tables.php';

/**
 * wpForo (issue #671): free-tier ops on the plugin-data dispatcher pair, so
 * they add no top-level tools. The names follow the bbPress ops, so agents
 * see one forum vocabulary.
 *
 * wpForo is not loaded in the shared test core. Presence is driven through
 * wpmcp_wpforo_active, and the storage is built the way wpForo 3.2.5 keeps
 * its main board (tests/support/forum-tables.php). The writes are off until
 * the site opts in (wpmcp_enable_wpforo_write) and each is undone in a test.
 */
class WpForoPackTest extends \WP_UnitTestCase
{
    private const OPS = [
        'wpforo-list-forums'    => 'read',
        'wpforo-list-topics'    => 'read',
        'wpforo-get-topic'      => 'read',
        'wpforo-list-replies'   => 'read',
        'wpforo-moderate-topic' => 'write',
        'wpforo-move-topic'     => 'write',
    ];

    public static function wpSetUpBeforeClass(): void
    {
        wpmcp_test_create_forum_tables();
    }

    public static function wpTearDownAfterClass(): void
    {
        wpmcp_test_drop_forum_tables();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        add_filter('wpmcp_wpforo_active', '__return_true');
        add_filter('wpmcp_enable_wpforo_write', '__return_true');
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_wpforo_active');
        remove_all_filters('wpmcp_enable_wpforo_write');
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

    private function undo(array $out): void
    {
        if (isset($out['session_id'])) {
            $this->assertNotEmpty(Rollback_Service::restore_session((string) $out['session_id']));
            return;
        }
        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
    }

    // ---------------------------------------------------------------
    // Catalog and availability
    // ---------------------------------------------------------------

    public function test_the_catalog_lists_every_op_with_its_mode_and_manage_options(): void
    {
        $ops = array_column((new Plugin_Data_Integration())->catalog()['operations'], null, 'name');
        foreach (self::OPS as $name => $mode) {
            $this->assertArrayHasKey($name, $ops);
            $this->assertSame($mode, $ops[ $name ]['mode'], $name);
            $this->assertTrue($ops[ $name ]['dependency_met'], $name);
            $this->assertSame('manage_options', $ops[ $name ]['capability'], $name);
        }
    }

    public function test_presence_defaults_to_the_plugin_constant(): void
    {
        remove_all_filters('wpmcp_wpforo_active');
        $this->assertSame(defined('WPFORO_VERSION'), WpForo_Pack::active());
    }

    public function test_while_wpforo_is_missing_a_call_answers_plugin_missing_with_the_plugins_link(): void
    {
        remove_all_filters('wpmcp_wpforo_active');
        add_filter('wpmcp_wpforo_active', '__return_false');
        $out = $this->read('wpforo-list-topics');
        $this->assertSame('plugin_missing', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertStringContainsString('Install and activate wpForo', $out['error']['message']);
        $this->assertSame(admin_url('plugins.php'), $out['error']['data']['plugins_url'] ?? null);
        $ops = array_column((new Plugin_Data_Integration())->catalog()['operations'], null, 'name');
        $this->assertFalse($ops['wpforo-get-topic']['dependency_met']);
    }

    public function test_with_the_plugin_files_installed_but_inactive_it_answers_plugin_inactive(): void
    {
        remove_all_filters('wpmcp_wpforo_active');
        add_filter('wpmcp_wpforo_active', '__return_false');
        $dir     = WP_PLUGIN_DIR . '/wpforo';
        $created = ! is_dir($dir);
        if ($created) {
            wp_mkdir_p($dir);
        }
        $file  = $dir . '/wpforo.php';
        $owned = ! file_exists($file);
        if ($owned) {
            file_put_contents($file, "<?php\n// Plugin Name: wpForo\n");
        }
        try {
            $out = $this->read('wpforo-get-topic', [ 'topic_id' => 1 ]);
        } finally {
            if ($owned) {
                unlink($file);
            }
            if ($created) {
                rmdir($dir);
            }
        }
        $this->assertSame('plugin_inactive', $out['error']['code'] ?? null);
        $this->assertStringContainsString('installed but not active', $out['error']['message']);
    }

    public function test_writes_are_off_until_the_site_opts_in(): void
    {
        $forum      = wpmcp_test_wpforo_forum();
        $other      = wpmcp_test_wpforo_forum();
        [ $topic ]  = wpmcp_test_wpforo_topic($forum);
        $before     = wpmcp_test_forum_dump();
        remove_all_filters('wpmcp_enable_wpforo_write');
        foreach ([
            [ 'wpforo-moderate-topic', [ 'topic_id' => $topic, 'action' => 'close' ] ],
            [ 'wpforo-move-topic', [ 'topic_id' => $topic, 'forum_id' => $other ] ],
        ] as [ $op, $args ]) {
            $out = $this->write($op, $args);
            $this->assertSame('opt_in_required', $out['error']['code'] ?? null, $op);
            $this->assertStringContainsString('wpmcp_enable_wpforo_write', $out['error']['message']);
        }
        $this->assertSame($before, wpmcp_test_forum_dump());
    }

    public function test_every_op_takes_manage_options(): void
    {
        $forum     = wpmcp_test_wpforo_forum();
        [ $topic ] = wpmcp_test_wpforo_topic($forum);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        foreach ([ 'wpforo-list-forums' => [], 'wpforo-list-topics' => [], 'wpforo-get-topic' => [ 'topic_id' => $topic ], 'wpforo-list-replies' => [ 'topic_id' => $topic ] ] as $op => $args) {
            $this->assertSame('operation_denied', $this->read($op, $args)['error']['code'] ?? null, $op);
        }
        $this->assertSame('operation_denied', $this->write('wpforo-moderate-topic', [ 'topic_id' => $topic, 'action' => 'close' ])['error']['code'] ?? null);
    }

    // ---------------------------------------------------------------
    // Reads
    // ---------------------------------------------------------------

    public function test_list_forums_returns_forums_and_categories_with_their_counters(): void
    {
        $cat   = wpmcp_test_wpforo_forum([ 'title' => 'General', 'is_cat' => 1, 'order' => 1 ]);
        $forum = wpmcp_test_wpforo_forum([ 'title' => 'Help', 'parentid' => $cat, 'order' => 2 ]);
        wpmcp_test_wpforo_topic($forum, [], 2);

        $out  = $this->ok($this->read('wpforo-list-forums'));
        $rows = array_column($out['forums'], null, 'id');
        $this->assertTrue($rows[ $cat ]['is_category']);
        $this->assertSame($cat, $rows[ $forum ]['parent_id']);
        $this->assertSame(1, $rows[ $forum ]['topics']);
        $this->assertSame(3, $rows[ $forum ]['posts']);
        $this->assertSame([ $forum ], array_column($this->read('wpforo-list-forums', [ 'parent_id' => $cat ])['forums'], 'id'));
    }

    public function test_list_topics_filters_by_forum_and_masks_guest_emails(): void
    {
        $forum = wpmcp_test_wpforo_forum();
        $other = wpmcp_test_wpforo_forum();
        [ $a ] = wpmcp_test_wpforo_topic($forum, [ 'title' => 'Older', 'modified' => '2026-11-01 09:00:00' ]);
        [ $b ] = wpmcp_test_wpforo_topic($forum, [ 'title' => 'Newer', 'modified' => '2026-11-03 09:00:00', 'userid' => 0, 'name' => 'Guest Person', 'email' => 'guest.person@example.org', 'type' => 1, 'closed' => 1 ]);
        wpmcp_test_wpforo_topic($other);

        $out = $this->ok($this->read('wpforo-list-topics', [ 'forum_id' => $forum ]));
        $this->assertSame([ $b, $a ], array_column($out['topics'], 'id'));
        $this->assertSame(2, $out['total']);
        $this->assertTrue($out['topics'][0]['sticky']);
        $this->assertTrue($out['topics'][0]['closed']);
        $this->assertSame('Guest Person', $out['topics'][0]['author_name']);
        $this->assertStringNotContainsString('guest.person@example.org', (string) wp_json_encode($out));
        $this->assertSame([ $b ], array_column($this->read('wpforo-list-topics', [ 'forum_id' => $forum, 'search' => 'Newer' ])['topics'], 'id'));
        $this->assertSame(1, $this->read('wpforo-list-topics', [ 'forum_id' => $forum, 'page_size' => 1 ])['next_offset']);
    }

    public function test_get_topic_returns_the_opening_post_and_the_full_email(): void
    {
        $forum     = wpmcp_test_wpforo_forum();
        [ $topic ] = wpmcp_test_wpforo_topic($forum, [ 'email' => 'starter@example.org', 'name' => 'Starter', 'userid' => 0 ]);

        $out = $this->ok($this->read('wpforo-get-topic', [ 'topic_id' => $topic ]))['topic'];
        $this->assertSame($topic, $out['id']);
        $this->assertSame('Opening post', $out['body']);
        $this->assertSame('starter@example.org', $out['author_email']);
        $this->assertSame(2, $out['posts']);
        $this->assertSame('topic_not_found', $this->read('wpforo-get-topic', [ 'topic_id' => 99999 ])['error']['code'] ?? null);
    }

    public function test_list_replies_leaves_the_opening_post_out_and_masks_emails(): void
    {
        $forum            = wpmcp_test_wpforo_forum();
        [ $topic, $posts ] = wpmcp_test_wpforo_topic($forum, [], 2);

        $out = $this->ok($this->read('wpforo-list-replies', [ 'topic_id' => $topic ]));
        $this->assertSame([ $posts[1], $posts[2] ], array_column($out['replies'], 'id'));
        $this->assertSame(2, $out['total']);
        $this->assertSame('Reply 1', $out['replies'][0]['body']);
        $this->assertSame('Guest Person', $out['replies'][0]['author_name']);
        $this->assertStringNotContainsString('guest.person@example.org', (string) wp_json_encode($out));
        $this->assertNull($this->read('wpforo-list-replies', [ 'topic_id' => $topic, 'page_size' => 1, 'offset' => 1 ])['next_offset']);
    }

    // ---------------------------------------------------------------
    // Writes
    // ---------------------------------------------------------------

    public function test_moderate_topic_closes_and_opens_and_rollback_restores(): void
    {
        $forum     = wpmcp_test_wpforo_forum();
        [ $topic ] = wpmcp_test_wpforo_topic($forum);
        $before    = wpmcp_test_forum_dump();

        $out = $this->ok($this->write('wpforo-moderate-topic', [ 'topic_id' => $topic, 'action' => 'close' ]));
        $this->assertTrue($out['recoverable']);
        $this->assertTrue($out['result']['topic']['closed']);
        $this->assertSame('already_closed', $this->write('wpforo-moderate-topic', [ 'topic_id' => $topic, 'action' => 'close' ])['error']['code'] ?? null);

        $this->undo($out);
        $this->assertSame($before, wpmcp_test_forum_dump());
        $this->assertSame('already_open', $this->write('wpforo-moderate-topic', [ 'topic_id' => $topic, 'action' => 'open' ])['error']['code'] ?? null);
    }

    public function test_move_topic_moves_its_posts_and_counters_and_rollback_restores_every_row(): void
    {
        $from              = wpmcp_test_wpforo_forum();
        $to                = wpmcp_test_wpforo_forum();
        [ $topic, $posts ] = wpmcp_test_wpforo_topic($from, [], 2);
        $before            = wpmcp_test_forum_dump();

        $out = $this->ok($this->write('wpforo-move-topic', [ 'topic_id' => $topic, 'forum_id' => $to ]));
        $this->assertTrue($out['recoverable']);
        $this->assertSame($to, $out['result']['topic']['forum_id']);

        global $wpdb;
        $this->assertSame([ (string) $to ], array_values(array_unique($wpdb->get_col($wpdb->prepare('SELECT forumid FROM %i WHERE topicid = %d', $wpdb->prefix . 'wpforo_posts', $topic)))));
        $this->assertSame([ (string) $to ], array_values(array_unique($wpdb->get_col($wpdb->prepare('SELECT forumid FROM %i WHERE topicid = %d', $wpdb->prefix . 'wpforo_postmeta', $topic)))));
        $forums = array_column($this->read('wpforo-list-forums')['forums'], null, 'id');
        $this->assertSame(0, $forums[ $from ]['topics']);
        $this->assertSame(1, $forums[ $to ]['topics']);
        $this->assertSame(3, $forums[ $to ]['posts']);
        $this->assertSame(end($posts), $forums[ $to ]['last_post_id']);

        $this->undo($out);
        $this->assertSame($before, wpmcp_test_forum_dump());
    }

    public function test_move_topic_refuses_a_category_the_same_forum_and_a_missing_forum(): void
    {
        $forum     = wpmcp_test_wpforo_forum();
        $cat       = wpmcp_test_wpforo_forum([ 'is_cat' => 1 ]);
        [ $topic ] = wpmcp_test_wpforo_topic($forum);
        $before    = wpmcp_test_forum_dump();

        $this->assertSame('forum_is_category', $this->write('wpforo-move-topic', [ 'topic_id' => $topic, 'forum_id' => $cat ])['error']['code'] ?? null);
        $this->assertSame('same_forum', $this->write('wpforo-move-topic', [ 'topic_id' => $topic, 'forum_id' => $forum ])['error']['code'] ?? null);
        $this->assertSame('forum_not_found', $this->write('wpforo-move-topic', [ 'topic_id' => $topic, 'forum_id' => 99999 ])['error']['code'] ?? null);
        $this->assertSame('topic_not_found', $this->write('wpforo-move-topic', [ 'topic_id' => 99999, 'forum_id' => $forum ])['error']['code'] ?? null);
        $this->assertSame($before, wpmcp_test_forum_dump());
    }
}
