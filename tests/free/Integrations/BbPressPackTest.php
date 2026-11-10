<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Safety\BbPress_Posts_Snapshot;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Safe_Mutation;
use WPMCP\Safety\Snapshot_Store;

require_once __DIR__ . '/../../support/bbpress.php';

/**
 * bbPress forums, topics and replies as free ops on the plugin-data pair.
 *
 * bbPress itself is not loaded here (its live leg, BbPressLiveTest, runs the
 * ops through the real plugin). This suite covers what does not need it:
 * the pair's presence and the ops' availability contract (documented with
 * dependency_met:false and answering bbpress_inactive while bbPress is
 * absent, the writes too while only its storage is), the reads over
 * bbPress's storage with its post types registered here, and the
 * 'bbpress_posts' snapshot: a write's whole scope put back exactly, a
 * create deleted with its counts put back, counts moved since kept, and a
 * create later posts depend on refused.
 */
class BbPressPackTest extends \WP_UnitTestCase
{
    private const OTHER_FILTERS = [ 'wpmcp_jetengine_active', 'wpmcp_pods_active', 'wpmcp_translatepress_active', 'wpmcp_buddypress_active' ];

    private const OPS = [
        'bbpress-list-forums'    => 'read',
        'bbpress-list-topics'    => 'read',
        'bbpress-get-topic'      => 'read',
        'bbpress-create-topic'   => 'write',
        'bbpress-create-reply'   => 'write',
        'bbpress-moderate-topic' => 'write',
    ];

    private int $admin;

    private int $forum;

    private int $topic;

    /** @var int[] */
    private array $replies = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (function_exists('bbpress')) {
            $this->markTestSkipped('Runs without bbPress; BbPressLiveTest covers the real plugin.');
        }
        Snapshot_Store::install();
        foreach ([ 'forum', 'topic', 'reply' ] as $type) {
            register_post_type($type, [ 'public' => true, 'hierarchical' => 'forum' === $type ]);
        }
        register_taxonomy('topic-tag', 'topic');
        $this->admin = self::factory()->user->create([ 'role' => 'administrator' ]);
        wp_set_current_user($this->admin);
        foreach (self::OTHER_FILTERS as $filter) {
            add_filter($filter, '__return_false');
        }
        add_filter('wpmcp_bbpress_active', '__return_true');
        $this->seed();
    }

    protected function tearDown(): void
    {
        foreach (array_merge(self::OTHER_FILTERS, [ 'wpmcp_bbpress_active' ]) as $filter) {
            remove_all_filters($filter);
        }
        foreach ([ 'forum', 'topic', 'reply' ] as $type) {
            unregister_post_type($type);
        }
        unregister_taxonomy('topic-tag');
        parent::tearDown();
    }

    /** A category forum holding a forum with one topic and two replies, as bbPress stores them. */
    private function seed(): void
    {
        $category    = self::factory()->post->create([ 'post_type' => 'forum', 'post_title' => 'Community' ]);
        $this->forum = self::factory()->post->create([ 'post_type' => 'forum', 'post_title' => 'General', 'post_parent' => $category ]);
        $this->topic = self::factory()->post->create([ 'post_type' => 'topic', 'post_title' => 'Hello', 'post_parent' => $this->forum, 'post_author' => $this->admin ]);
        foreach ([ 1, 2 ] as $n) {
            $this->replies[] = self::factory()->post->create([ 'post_type' => 'reply', 'post_title' => "Reply {$n}", 'post_parent' => $this->topic, 'post_author' => $this->admin ]);
        }
        update_post_meta($category, '_bbp_forum_type', 'category');
        update_post_meta($category, '_bbp_total_topic_count', 1);
        update_post_meta($category, '_bbp_total_reply_count', 2);
        update_post_meta($this->forum, '_bbp_topic_count', 1);
        update_post_meta($this->forum, '_bbp_reply_count', 2);
        update_post_meta($this->forum, '_bbp_total_topic_count', 1);
        update_post_meta($this->forum, '_bbp_last_active_time', '2026-11-01 10:00:00');
        update_post_meta($this->forum, '_bbp_sticky_topics', []);
        update_post_meta($this->topic, '_bbp_forum_id', $this->forum);
        update_post_meta($this->topic, '_bbp_reply_count', 2);
        update_post_meta($this->topic, '_bbp_voice_count', 1);
        add_post_meta($this->topic, '_bbp_engagement', $this->admin);
        foreach ($this->replies as $reply) {
            update_post_meta($reply, '_bbp_topic_id', $this->topic);
            update_post_meta($reply, '_bbp_forum_id', $this->forum);
        }
        update_user_option($this->admin, '_bbp_topic_count', 1);
        update_user_option($this->admin, '_bbp_reply_count', 2);
        wp_set_object_terms($this->topic, [ 'welcome', 'intro' ], 'topic-tag');
    }

    private function read(string $op, array $args = []): array
    {
        return (new Plugin_Data_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
    }

    private function write(string $op, array $args): array
    {
        return (new Plugin_Data_Integration())->handle_write([ 'operation' => $op, 'args' => $args ]);
    }

    private function catalog(): array
    {
        return array_column((new Plugin_Data_Integration())->catalog()['operations'], null, 'name');
    }

    /** Run $write as one snapshotted bbPress write over $key, the way the pack does. */
    private function snapshotted(string $key, callable $write): string
    {
        $operation_id = wp_generate_uuid4();
        Safe_Mutation::run(
            [
                'operation_id'        => $operation_id,
                'object_type'         => BbPress_Posts_Snapshot::TYPE,
                'object_id'           => $key,
                'session_id'          => 'default',
                'tool_name'           => 'plugin-data-write',
                'args'                => [],
                'extra_snapshot_data' => [ 'restore_capability' => 'manage_options' ],
            ],
            static function () use ($write, $operation_id): void {
                [$created, $new_key] = $write() + [ [], '' ];
                BbPress_Posts_Snapshot::record_after($operation_id, $created, $new_key);
            }
        );
        return $operation_id;
    }

    // ---------------------------------------------------------------
    // Presence and availability
    // ---------------------------------------------------------------

    public function test_pair_registers_while_only_bbpress_is_loaded_and_is_absent_without_it(): void
    {
        $integration = new Plugin_Data_Integration();
        $this->assertTrue($integration->is_available());

        remove_all_filters('wpmcp_bbpress_active');
        add_filter('wpmcp_bbpress_active', '__return_false');
        $this->assertFalse($integration->is_available());
    }

    public function test_presence_defaults_to_bbpress_being_loaded(): void
    {
        remove_all_filters('wpmcp_bbpress_active');
        remove_all_filters('wpmcp_pods_active');
        add_filter('wpmcp_pods_active', '__return_true');
        $catalog = $this->catalog();
        foreach (self::OPS as $op => $mode) {
            $this->assertArrayHasKey($op, $catalog, $op);
            $this->assertSame($mode, $catalog[ $op ]['mode'], $op);
            $this->assertFalse($catalog[ $op ]['dependency_met'], "{$op} is documented but unavailable without bbPress");
        }
    }

    public function test_ops_answer_bbpress_inactive_and_write_nothing_while_bbpress_is_absent(): void
    {
        remove_all_filters('wpmcp_bbpress_active');
        add_filter('wpmcp_bbpress_active', '__return_false');
        remove_all_filters('wpmcp_pods_active');
        add_filter('wpmcp_pods_active', '__return_true');
        $before = wpmcp_test_bbpress_dump();
        $rows   = Snapshot_Store::row_count();

        $this->assertSame('bbpress_inactive', $this->read('bbpress-list-forums')['error']['code'] ?? '');
        $this->assertSame('bbpress_inactive', $this->write('bbpress-moderate-topic', [ 'topic_id' => $this->topic, 'action' => 'close' ])['error']['code'] ?? '');

        $this->assertSame($before, wpmcp_test_bbpress_dump());
        $this->assertSame($rows, Snapshot_Store::row_count(), 'no snapshot is written');
    }

    public function test_writes_need_bbpress_itself_not_only_its_storage(): void
    {
        $rows = Snapshot_Store::row_count();
        foreach ([
            'bbpress-create-topic'   => [ 'forum_id' => $this->forum, 'title' => 'New', 'content' => 'Body' ],
            'bbpress-create-reply'   => [ 'topic_id' => $this->topic, 'content' => 'Body' ],
            'bbpress-moderate-topic' => [ 'topic_id' => $this->topic, 'action' => 'spam' ],
        ] as $op => $args) {
            $this->assertSame('bbpress_inactive', $this->write($op, $args)['error']['code'] ?? '', $op);
        }
        $this->assertSame($rows, Snapshot_Store::row_count());
        $this->assertTrue($this->catalog()['bbpress-list-topics']['dependency_met']);
        $this->assertFalse($this->catalog()['bbpress-create-topic']['dependency_met']);
    }

    // ---------------------------------------------------------------
    // Reads
    // ---------------------------------------------------------------

    public function test_list_forums_reports_counts_and_categories(): void
    {
        $out    = $this->read('bbpress-list-forums');
        $forums = array_column($out['result']['forums'], null, 'title');
        $this->assertSame(2, $out['result']['total']);
        $this->assertSame('category', $forums['Community']['type']);
        $this->assertSame('forum', $forums['General']['type']);
        $this->assertSame(1, $forums['General']['topic_count']);
        $this->assertSame(2, $forums['General']['reply_count']);
        $this->assertSame('2026-11-01 10:00:00', $forums['General']['last_active']);

        $sub = $this->read('bbpress-list-forums', [ 'parent_id' => $forums['Community']['id'] ]);
        $this->assertSame([ 'General' ], array_column($sub['result']['forums'], 'title'));
    }

    public function test_list_topics_flags_sticky_topics_and_refuses_a_non_forum(): void
    {
        update_post_meta($this->forum, '_bbp_sticky_topics', [ $this->topic ]);
        $out = $this->read('bbpress-list-topics', [ 'forum_id' => $this->forum ]);
        $this->assertSame(1, $out['result']['total']);
        $this->assertTrue($out['result']['topics'][0]['sticky']);
        $this->assertSame($this->forum, $out['result']['topics'][0]['forum_id']);
        $this->assertSame(2, $out['result']['topics'][0]['reply_count']);

        $this->assertSame('not_a_forum', $this->read('bbpress-list-topics', [ 'forum_id' => $this->topic ])['error']['code'] ?? '');
    }

    public function test_get_topic_returns_its_replies_in_order_paged(): void
    {
        $out = $this->read('bbpress-get-topic', [ 'topic_id' => $this->topic, 'per_page' => 1 ]);
        $this->assertSame('Hello', $out['result']['topic']['title']);
        $this->assertSame(2, $out['result']['total_replies']);
        $this->assertSame([ $this->replies[0] ], array_column($out['result']['replies'], 'id'));

        $this->assertSame('not_a_topic', $this->read('bbpress-get-topic', [ 'topic_id' => $this->forum ])['error']['code'] ?? '');
    }

    // ---------------------------------------------------------------
    // The bbpress_posts snapshot
    // ---------------------------------------------------------------

    public function test_a_moderation_scope_is_put_back_exactly(): void
    {
        $before = wpmcp_test_bbpress_dump();

        // What bbPress's spam does to this scope: the topic and its replies
        // change status, the topic loses its tags and its stickiness, every
        // forum above it and the author lose counts.
        $operation_id = $this->snapshotted(BbPress_Posts_Snapshot::key('topic', $this->topic), function (): array {
            update_post_meta($this->forum, '_bbp_sticky_topics', [ 99 ]);
            update_option(BbPress_Posts_Snapshot::SUPER_STICKY_OPTION, [ 98 ]);
            add_post_meta($this->topic, '_bbp_spam_meta_status', 'publish');
            delete_post_meta($this->topic, '_bbp_engagement');
            wp_update_post([ 'ID' => $this->topic, 'post_status' => 'draft' ]);
            foreach ($this->replies as $reply) {
                wp_trash_post($reply);
            }
            wp_set_object_terms($this->topic, [], 'topic-tag');
            update_post_meta($this->forum, '_bbp_topic_count', 0);
            update_post_meta($this->forum, '_bbp_reply_count', 0);
            update_user_option($this->admin, '_bbp_topic_count', 0);
            return [];
        });
        $this->assertNotSame($before, wpmcp_test_bbpress_dump());

        $this->assertTrue(Rollback_Service::restore_operation($operation_id));
        $this->assertSame([], Rollback_Service::take_warnings());
        $this->assertSame($before, wpmcp_test_bbpress_dump(), 'every row, meta row, count, tag and option is back');
    }

    public function test_a_create_is_listed_under_its_new_id_and_deleted_with_its_counts_put_back(): void
    {
        $before  = wpmcp_test_bbpress_dump();
        $created = 0;

        $operation_id = $this->snapshotted(BbPress_Posts_Snapshot::key('forum', $this->forum), function () use (&$created): array {
            $created = self::factory()->post->create([ 'post_type' => 'topic', 'post_parent' => $this->forum, 'post_author' => $this->admin ]);
            update_post_meta($created, '_bbp_forum_id', $this->forum);
            update_post_meta($this->forum, '_bbp_topic_count', 2);
            update_post_meta($this->forum, '_bbp_last_topic_id', $created);
            update_user_option($this->admin, '_bbp_topic_count', 2);
            return [ [ $created ], BbPress_Posts_Snapshot::key('topic_created', $created) ];
        });

        $row = Snapshot_Store::get_by_operation($operation_id);
        $this->assertSame("topic_created:{$created}", $row['snapshot']['object_id']);
        $this->assertSame("topic_created:{$created}", $row['object_ref']);

        $this->assertTrue(Rollback_Service::restore_operation($operation_id));
        $this->assertNull(get_post($created));
        $this->assertSame($before, wpmcp_test_bbpress_dump());
    }

    public function test_counts_moved_since_stay_counted_and_other_later_changes_are_kept(): void
    {
        $created      = 0;
        $operation_id = $this->snapshotted(BbPress_Posts_Snapshot::key('forum', $this->forum), function () use (&$created): array {
            $created = self::factory()->post->create([ 'post_type' => 'topic', 'post_parent' => $this->forum ]);
            update_post_meta($this->forum, '_bbp_topic_count', 2);
            update_post_meta($this->forum, '_bbp_last_active_time', '2026-11-02 10:00:00');
            return [ [ $created ], '' ];
        });
        // Someone else posts two more topics afterwards.
        update_post_meta($this->forum, '_bbp_topic_count', 4);
        update_post_meta($this->forum, '_bbp_last_active_time', '2026-11-03 10:00:00');

        $this->assertTrue(Rollback_Service::restore_operation($operation_id));
        $this->assertSame('3', get_post_meta($this->forum, '_bbp_topic_count', true), 'only the undone topic is uncounted');
        $this->assertSame('2026-11-03 10:00:00', get_post_meta($this->forum, '_bbp_last_active_time', true));
        $this->assertStringContainsString('_bbp_last_active_time', implode(' ', Rollback_Service::take_warnings()));
        $this->assertNull(get_post($created));
    }

    public function test_undoing_a_create_that_later_replies_depend_on_is_refused(): void
    {
        $created      = 0;
        $operation_id = $this->snapshotted(BbPress_Posts_Snapshot::key('forum', $this->forum), function () use (&$created): array {
            $created = self::factory()->post->create([ 'post_type' => 'topic', 'post_parent' => $this->forum ]);
            return [ [ $created ], BbPress_Posts_Snapshot::key('topic_created', $created) ];
        });
        $later = self::factory()->post->create([ 'post_type' => 'reply', 'post_parent' => $created ]);

        $this->assertFalse(Rollback_Service::restore_operation($operation_id));
        $this->assertStringContainsString('has replies posted since', implode(' ', Rollback_Service::take_warnings()));
        $this->assertNotNull(get_post($created));
        $this->assertNotNull(get_post($later));
    }

    public function test_restoring_takes_the_capability_the_write_took(): void
    {
        $operation_id = $this->snapshotted(BbPress_Posts_Snapshot::key('topic', $this->topic), function (): array {
            update_post_meta($this->forum, '_bbp_sticky_topics', [ $this->topic ]);
            return [];
        });
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        $this->assertFalse(Rollback_Service::restore_operation($operation_id));
        $this->assertSame([ $this->topic ], get_post_meta($this->forum, '_bbp_sticky_topics', true));

        wp_set_current_user($this->admin);
        $this->assertTrue(Rollback_Service::restore_operation($operation_id));
        $this->assertSame([], get_post_meta($this->forum, '_bbp_sticky_topics', true));
    }
}
