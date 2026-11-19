<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Asgaros_Forum_Pack;
use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

require_once __DIR__ . '/../../support/forum-tables.php';

/**
 * Asgaros Forum (issue #671): free-tier ops on the plugin-data dispatcher
 * pair, named like the bbPress ops so agents see one forum vocabulary.
 *
 * Asgaros Forum is not loaded in the shared test core. Presence is driven
 * through wpmcp_asgaros_active, and the storage is built the way Asgaros
 * Forum keeps it (tests/support/forum-tables.php). The writes are off until
 * the site opts in (wpmcp_enable_asgaros_write) and each is undone here.
 */
class AsgarosForumPackTest extends \WP_UnitTestCase
{
    private const OPS = [
        'asgaros-list-forums'    => 'read',
        'asgaros-list-topics'    => 'read',
        'asgaros-get-topic'      => 'read',
        'asgaros-list-replies'   => 'read',
        'asgaros-create-topic'   => 'write',
        'asgaros-create-reply'   => 'write',
        'asgaros-moderate-topic' => 'write',
        'asgaros-move-topic'     => 'write',
        'asgaros-delete-reply'   => 'destructive',
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
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator', 'display_name' => 'Ada Admin' ]));
        add_filter('wpmcp_asgaros_active', '__return_true');
        add_filter('wpmcp_enable_asgaros_write', '__return_true');
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_asgaros_active');
        remove_all_filters('wpmcp_enable_asgaros_write');
        parent::tearDown();
    }

    private function read(string $op, array $args = []): array
    {
        $out = (new Plugin_Data_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
        return isset($out['result']) && ! isset($out['error']) ? $out['result'] : $out;
    }

    /** A write; a 'confirm' in $args is sent beside them, where the dispatcher reads it. */
    private function write(string $op, array $args): array
    {
        $call = [ 'operation' => $op ];
        if (array_key_exists('confirm', $args)) {
            $call['confirm'] = $args['confirm'];
            unset($args['confirm']);
        }
        return (new Plugin_Data_Integration())->handle_write($call + [ 'args' => $args ]);
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

    public function test_presence_defaults_to_the_plugin_class(): void
    {
        remove_all_filters('wpmcp_asgaros_active');
        $this->assertSame(class_exists('AsgarosForum', false), Asgaros_Forum_Pack::active());
    }

    public function test_while_asgaros_is_missing_a_call_answers_plugin_missing(): void
    {
        remove_all_filters('wpmcp_asgaros_active');
        add_filter('wpmcp_asgaros_active', '__return_false');
        $out = $this->read('asgaros-list-forums');
        $this->assertSame('plugin_missing', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertStringContainsString('Install and activate Asgaros Forum', $out['error']['message']);
        $this->assertSame(admin_url('plugins.php'), $out['error']['data']['plugins_url'] ?? null);
    }

    public function test_writes_are_off_until_the_site_opts_in(): void
    {
        $forum            = wpmcp_test_asgaros_forum();
        [ $topic, $posts ] = wpmcp_test_asgaros_topic($forum);
        $before           = wpmcp_test_forum_dump();
        remove_all_filters('wpmcp_enable_asgaros_write');
        foreach ([
            [ 'asgaros-create-topic', [ 'forum_id' => $forum, 'title' => 'T', 'content' => 'C' ] ],
            [ 'asgaros-create-reply', [ 'topic_id' => $topic, 'content' => 'C' ] ],
            [ 'asgaros-moderate-topic', [ 'topic_id' => $topic, 'action' => 'close' ] ],
            [ 'asgaros-move-topic', [ 'topic_id' => $topic, 'forum_id' => $forum ] ],
            [ 'asgaros-delete-reply', [ 'reply_id' => $posts[1], 'confirm' => true ] ],
        ] as [ $op, $args ]) {
            $out = $this->write($op, $args);
            $this->assertSame('opt_in_required', $out['error']['code'] ?? null, $op);
            $this->assertStringContainsString('wpmcp_enable_asgaros_write', $out['error']['message']);
        }
        $this->assertSame($before, wpmcp_test_forum_dump());
    }

    public function test_every_op_takes_manage_options(): void
    {
        $forum     = wpmcp_test_asgaros_forum();
        [ $topic ] = wpmcp_test_asgaros_topic($forum);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        foreach ([ 'asgaros-list-forums' => [], 'asgaros-list-topics' => [], 'asgaros-get-topic' => [ 'topic_id' => $topic ], 'asgaros-list-replies' => [ 'topic_id' => $topic ] ] as $op => $args) {
            $this->assertSame('operation_denied', $this->read($op, $args)['error']['code'] ?? null, $op);
        }
        $this->assertSame('operation_denied', $this->write('asgaros-create-reply', [ 'topic_id' => $topic, 'content' => 'x' ])['error']['code'] ?? null);
    }

    // ---------------------------------------------------------------
    // Reads
    // ---------------------------------------------------------------

    public function test_list_forums_counts_topics_and_posts(): void
    {
        $forum = wpmcp_test_asgaros_forum([ 'name' => 'Help', 'sort' => 1 ]);
        $sub   = wpmcp_test_asgaros_forum([ 'name' => 'Sub', 'parent_forum' => $forum, 'sort' => 2 ]);
        wpmcp_test_asgaros_topic($forum, [], 2);

        $rows = array_column($this->ok($this->read('asgaros-list-forums'))['forums'], null, 'id');
        $this->assertSame('Help', $rows[ $forum ]['name']);
        $this->assertSame(1, $rows[ $forum ]['topics']);
        $this->assertSame(3, $rows[ $forum ]['posts']);
        $this->assertSame($forum, $rows[ $sub ]['parent_forum_id']);
        $this->assertSame('normal', $rows[ $sub ]['status']);
    }

    public function test_list_topics_newest_activity_first_with_flags(): void
    {
        global $wpdb;
        $forum = wpmcp_test_asgaros_forum();
        [ $a ] = wpmcp_test_asgaros_topic($forum, [ 'name' => 'Older' ]);
        [ $b ] = wpmcp_test_asgaros_topic($forum, [ 'name' => 'Newer', 'sticky' => 2, 'closed' => 1 ]);
        $wpdb->update($wpdb->prefix . 'forum_posts', [ 'date' => '2026-11-05 10:00:00' ], [ 'parent_id' => $b ]);

        $out = $this->ok($this->read('asgaros-list-topics', [ 'forum_id' => $forum ]));
        $this->assertSame([ $b, $a ], array_column($out['topics'], 'id'));
        $this->assertSame('global', $out['topics'][0]['sticky']);
        $this->assertTrue($out['topics'][0]['closed']);
        $this->assertSame(1, $out['topics'][0]['replies']);
        $this->assertSame(1, $this->read('asgaros-list-topics', [ 'forum_id' => $forum, 'page_size' => 1 ])['next_offset']);
    }

    public function test_get_topic_and_list_replies(): void
    {
        $forum            = wpmcp_test_asgaros_forum();
        [ $topic, $posts ] = wpmcp_test_asgaros_topic($forum, [], 2);

        $got = $this->ok($this->read('asgaros-get-topic', [ 'topic_id' => $topic ]))['topic'];
        $this->assertSame('Opening post', $got['body']);
        $this->assertSame($posts[0], $got['first_post_id']);
        $this->assertSame('topic_not_found', $this->read('asgaros-get-topic', [ 'topic_id' => 99999 ])['error']['code'] ?? null);

        $out = $this->ok($this->read('asgaros-list-replies', [ 'topic_id' => $topic ]));
        $this->assertSame([ $posts[1], $posts[2] ], array_column($out['replies'], 'id'));
        $this->assertSame('Reply 2', $out['replies'][1]['body']);
    }

    // ---------------------------------------------------------------
    // Writes
    // ---------------------------------------------------------------

    public function test_create_topic_writes_the_topic_and_its_first_post_and_rollback_removes_both(): void
    {
        $forum  = wpmcp_test_asgaros_forum();
        wpmcp_test_asgaros_topic($forum);
        $before = wpmcp_test_forum_dump();

        $out   = $this->ok($this->write('asgaros-create-topic', [ 'forum_id' => $forum, 'title' => 'Hello World', 'content' => 'First words' ]));
        $this->assertTrue($out['recoverable']);
        $topic = $out['result']['topic'];
        $this->assertSame('Hello World', $topic['title']);
        $this->assertSame('First words', $topic['body']);
        $this->assertSame(get_current_user_id(), $topic['author_id']);
        $this->assertSame('hello-world', $topic['slug']);

        $this->undo($out);
        $this->assertSame($before, wpmcp_test_forum_dump());
    }

    public function test_create_topic_refuses_a_missing_forum(): void
    {
        $this->assertSame('forum_not_found', $this->write('asgaros-create-topic', [ 'forum_id' => 99999, 'title' => 'T', 'content' => 'C' ])['error']['code'] ?? null);
    }

    public function test_create_reply_appends_a_post_and_rollback_removes_it(): void
    {
        $forum     = wpmcp_test_asgaros_forum();
        [ $topic ] = wpmcp_test_asgaros_topic($forum);
        $before    = wpmcp_test_forum_dump();

        $out = $this->ok($this->write('asgaros-create-reply', [ 'topic_id' => $topic, 'content' => 'Thanks!' ]));
        $this->assertTrue($out['recoverable']);
        $this->assertSame('Thanks!', $out['result']['reply']['body']);
        $this->assertSame(2, $this->read('asgaros-list-replies', [ 'topic_id' => $topic ])['total']);

        $this->undo($out);
        $this->assertSame($before, wpmcp_test_forum_dump());
    }

    public function test_create_reply_refuses_a_closed_topic(): void
    {
        $forum     = wpmcp_test_asgaros_forum();
        [ $topic ] = wpmcp_test_asgaros_topic($forum, [ 'closed' => 1 ]);
        $this->assertSame('topic_closed', $this->write('asgaros-create-reply', [ 'topic_id' => $topic, 'content' => 'x' ])['error']['code'] ?? null);
    }

    public function test_moderate_topic_closes_sticks_and_rollback_restores(): void
    {
        $forum     = wpmcp_test_asgaros_forum();
        [ $topic ] = wpmcp_test_asgaros_topic($forum);
        $before    = wpmcp_test_forum_dump();

        $close = $this->ok($this->write('asgaros-moderate-topic', [ 'topic_id' => $topic, 'action' => 'close' ]));
        $this->assertTrue($close['result']['topic']['closed']);
        $stick = $this->ok($this->write('asgaros-moderate-topic', [ 'topic_id' => $topic, 'action' => 'stick' ]));
        $this->assertSame('forum', $stick['result']['topic']['sticky']);
        $this->assertSame('already_closed', $this->write('asgaros-moderate-topic', [ 'topic_id' => $topic, 'action' => 'close' ])['error']['code'] ?? null);

        $this->undo($stick);
        $this->undo($close);
        $this->assertSame($before, wpmcp_test_forum_dump());
    }

    public function test_move_topic_moves_its_posts_and_rollback_restores(): void
    {
        global $wpdb;
        $from      = wpmcp_test_asgaros_forum();
        $to        = wpmcp_test_asgaros_forum();
        [ $topic ] = wpmcp_test_asgaros_topic($from, [], 2);
        $before    = wpmcp_test_forum_dump();

        $out = $this->ok($this->write('asgaros-move-topic', [ 'topic_id' => $topic, 'forum_id' => $to ]));
        $this->assertSame($to, $out['result']['topic']['forum_id']);
        $this->assertSame([ (string) $to ], array_values(array_unique($wpdb->get_col($wpdb->prepare('SELECT forum_id FROM %i WHERE parent_id = %d', $wpdb->prefix . 'forum_posts', $topic)))));
        $this->assertSame('same_forum', $this->write('asgaros-move-topic', [ 'topic_id' => $topic, 'forum_id' => $to ])['error']['code'] ?? null);

        $this->undo($out);
        $this->assertSame($before, wpmcp_test_forum_dump());
    }

    public function test_delete_reply_needs_confirm_refuses_the_first_post_and_rollback_restores(): void
    {
        $forum             = wpmcp_test_asgaros_forum();
        [ $topic, $posts ] = wpmcp_test_asgaros_topic($forum, [], 2);
        $before            = wpmcp_test_forum_dump();

        $this->assertSame('confirmation_required', $this->write('asgaros-delete-reply', [ 'reply_id' => $posts[1] ])['error']['code'] ?? null);
        $this->assertSame('first_post', $this->write('asgaros-delete-reply', [ 'reply_id' => $posts[0], 'confirm' => true ])['error']['code'] ?? null);
        $this->assertSame($before, wpmcp_test_forum_dump());

        $out = $this->ok($this->write('asgaros-delete-reply', [ 'reply_id' => $posts[1], 'confirm' => true ]));
        $this->assertTrue($out['recoverable']);
        $this->assertSame([ $posts[2] ], array_column($this->read('asgaros-list-replies', [ 'topic_id' => $topic ])['replies'], 'id'));

        $this->undo($out);
        $this->assertSame($before, wpmcp_test_forum_dump());
    }
}
