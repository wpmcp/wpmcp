<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\BbPress_Integration;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Rollback_Operation;

/**
 * bbPress forums integration, exercised against the real forum / topic /
 * reply post types with bbPress's `_bbp_*` postmeta (its actual storage) but
 * WITHOUT the bbPress plugin loaded: the integration falls back to WP_Query
 * and wp_insert_post / wp_update_post when the bbp_* functions are absent,
 * so every operation is covered here, and the live plugin only swaps in its
 * own functions behind function_exists guards.
 */
class BbPressIntegrationTest extends \WP_UnitTestCase
{
    private BbPress_Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();
        register_post_type('forum', [ 'public' => true, 'label' => 'Forums', 'hierarchical' => true ]);
        register_post_type('topic', [ 'public' => true, 'label' => 'Topics' ]);
        register_post_type('reply', [ 'public' => true, 'label' => 'Replies' ]);
        register_post_status('closed', [ 'label' => 'Closed', 'public' => true, 'show_in_admin_all_list' => true ]);
        register_post_status('spam', [ 'label' => 'Spam', 'public' => false, 'exclude_from_search' => true ]);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        $this->integration = new BbPress_Integration();
    }

    protected function tearDown(): void
    {
        unregister_post_type('forum');
        unregister_post_type('topic');
        unregister_post_type('reply');
        unset($GLOBALS['wp_post_statuses']['closed'], $GLOBALS['wp_post_statuses']['spam']);
        parent::tearDown();
    }

    private function make_forum(string $title, int $topics = 0, int $replies = 0): int
    {
        $id = self::factory()->post->create([ 'post_type' => 'forum', 'post_title' => $title ]);
        update_post_meta($id, '_bbp_topic_count', $topics);
        update_post_meta($id, '_bbp_reply_count', $replies);
        return $id;
    }

    private function make_topic(int $forum_id, string $title, string $status = 'publish'): int
    {
        $id = self::factory()->post->create([
            'post_type'   => 'topic',
            'post_title'  => $title,
            'post_status' => $status,
            'post_parent' => $forum_id,
        ]);
        update_post_meta($id, '_bbp_forum_id', $forum_id);
        update_post_meta($id, '_bbp_reply_count', 0);
        return $id;
    }

    private function make_reply(int $topic_id, string $content): int
    {
        $id = self::factory()->post->create([
            'post_type'    => 'reply',
            'post_content' => $content,
            'post_parent'  => $topic_id,
        ]);
        update_post_meta($id, '_bbp_topic_id', $topic_id);
        return $id;
    }

    private function read(string $op, array $args = []): array
    {
        return $this->integration->handle_read([ 'operation' => $op, 'args' => $args ]);
    }

    private function write(string $op, array $args = []): array
    {
        return $this->integration->handle_write([ 'operation' => $op, 'args' => $args ]);
    }

    public function test_available_when_bbpress_post_types_registered(): void
    {
        $this->assertTrue($this->integration->is_available());
        $this->assertSame('bbpress', $this->integration->integration());
        $this->assertSame('free', $this->integration->tier());
    }

    public function test_list_forums_with_counts(): void
    {
        $this->make_forum('General', 12, 40);
        $this->make_forum('Support', 3, 7);

        $out = $this->read('list-forums');
        $this->assertArrayNotHasKey('error', $out);
        $this->assertSame(2, $out['result']['total']);
        $by_title = array_column($out['result']['forums'], null, 'title');
        $this->assertSame(12, $by_title['General']['topic_count']);
        $this->assertSame(40, $by_title['General']['reply_count']);
        $this->assertSame(3, $by_title['Support']['topic_count']);
    }

    public function test_list_topics_in_forum_with_status(): void
    {
        $forum = $this->make_forum('General');
        $other = $this->make_forum('Other');
        $this->make_topic($forum, 'Open thread');
        $this->make_topic($forum, 'Closed thread', 'closed');
        $this->make_topic($other, 'Elsewhere');

        $out = $this->read('list-topics', [ 'forum_id' => $forum ]);
        $this->assertArrayNotHasKey('error', $out);
        $this->assertSame(2, $out['result']['total']);
        $statuses = array_column($out['result']['topics'], 'status', 'title');
        $this->assertSame('publish', $statuses['Open thread']);
        $this->assertSame('closed', $statuses['Closed thread']);
        $this->assertArrayNotHasKey('Elsewhere', $statuses);
        $this->assertSame($forum, $out['result']['topics'][0]['forum_id']);
    }

    public function test_get_topic_with_replies(): void
    {
        $forum = $this->make_forum('General');
        $topic = $this->make_topic($forum, 'Open thread');
        $this->make_reply($topic, 'First answer');
        $this->make_reply($topic, 'Second answer');

        $out = $this->read('get-topic', [ 'topic_id' => $topic ]);
        $this->assertArrayNotHasKey('error', $out);
        $this->assertSame('Open thread', $out['result']['topic']['title']);
        $this->assertSame($forum, $out['result']['topic']['forum_id']);
        $this->assertCount(2, $out['result']['replies']);
        $this->assertSame('First answer', $out['result']['replies'][0]['content']);
        $this->assertSame($topic, $out['result']['replies'][0]['topic_id']);
    }

    public function test_get_topic_null_for_non_topic_post(): void
    {
        $page = self::factory()->post->create([ 'post_type' => 'page' ]);
        $out  = $this->read('get-topic', [ 'topic_id' => $page ]);
        $this->assertNull($out['result']['topic']);
        $this->assertSame([], $out['result']['replies']);
    }

    public function test_create_topic_in_forum(): void
    {
        $forum = $this->make_forum('General');

        $out = $this->write('create-topic', [ 'forum_id' => $forum, 'title' => 'Hello', 'content' => 'Body text' ]);
        $this->assertArrayNotHasKey('error', $out);
        $id = $out['result']['topic_id'];
        $this->assertGreaterThan(0, $id);
        $post = get_post($id);
        $this->assertSame('topic', $post->post_type);
        $this->assertSame('Hello', $post->post_title);
        $this->assertSame($forum, (int) $post->post_parent);
        $this->assertSame($forum, (int) get_post_meta($id, '_bbp_forum_id', true));
        $this->assertNotEmpty($out['operation_id'], 'The create is snapshot-wrapped (forum before-image)');
        $this->assertFalse($out['recoverable'], 'A create cannot be undone by restoring the forum');
    }

    public function test_create_topic_refuses_non_forum_parent(): void
    {
        $page = self::factory()->post->create([ 'post_type' => 'page' ]);
        $out  = $this->write('create-topic', [ 'forum_id' => $page, 'title' => 'Hello', 'content' => 'x' ]);
        $this->assertSame('not_a_forum', $out['error']['code']);
        $this->assertSame(0, (int) wp_count_posts('topic')->publish);
    }

    public function test_create_reply_in_topic(): void
    {
        $forum = $this->make_forum('General');
        $topic = $this->make_topic($forum, 'Open thread');

        $out = $this->write('create-reply', [ 'topic_id' => $topic, 'content' => 'An answer' ]);
        $this->assertArrayNotHasKey('error', $out);
        $id   = $out['result']['reply_id'];
        $post = get_post($id);
        $this->assertSame('reply', $post->post_type);
        $this->assertSame($topic, (int) $post->post_parent);
        $this->assertSame($topic, (int) get_post_meta($id, '_bbp_topic_id', true));
        $this->assertSame($forum, (int) get_post_meta($id, '_bbp_forum_id', true));
        $this->assertNotEmpty($out['operation_id']);
    }

    public function test_close_topic_is_snapshot_wrapped_and_undoable(): void
    {
        $forum = $this->make_forum('General');
        $topic = $this->make_topic($forum, 'Open thread');

        $out = $this->write('moderate-topic', [ 'topic_id' => $topic, 'action' => 'close' ]);
        $this->assertArrayNotHasKey('error', $out);
        $this->assertSame('closed', get_post_status($topic));
        $this->assertTrue($out['recoverable']);
        $row = Snapshot_Store::get_by_operation($out['operation_id']);
        $this->assertSame('bbpress-write', $row['tool_name']);
        $this->assertSame('publish', $row['snapshot']['data']['post']['post_status']);

        (new Rollback_Operation())->handle([ 'operation_id' => $out['operation_id'] ]);
        $this->assertSame('publish', get_post_status($topic));
    }

    public function test_spam_topic(): void
    {
        $forum = $this->make_forum('General');
        $topic = $this->make_topic($forum, 'Buy now');

        $out = $this->write('moderate-topic', [ 'topic_id' => $topic, 'action' => 'spam' ]);
        $this->assertArrayNotHasKey('error', $out);
        $this->assertSame('spam', get_post_status($topic));
        $this->assertSame('spam', $out['result']['status']);
    }

    public function test_stick_topic_records_it_on_the_forum(): void
    {
        $forum = $this->make_forum('General');
        $topic = $this->make_topic($forum, 'Read me first');

        $out = $this->write('moderate-topic', [ 'topic_id' => $topic, 'action' => 'stick' ]);
        $this->assertArrayNotHasKey('error', $out);
        $this->assertTrue($out['result']['sticky']);
        $this->assertContains($topic, array_map('intval', (array) get_post_meta($forum, '_bbp_sticky_topics', true)));

        $listed = $this->read('list-topics', [ 'forum_id' => $forum ]);
        $this->assertTrue($listed['result']['topics'][0]['sticky']);
    }

    public function test_moderate_rejects_unknown_action(): void
    {
        $forum = $this->make_forum('General');
        $topic = $this->make_topic($forum, 'Thread');
        $out   = $this->write('moderate-topic', [ 'topic_id' => $topic, 'action' => 'delete' ]);
        $this->assertSame('invalid_args', $out['error']['code']);
    }

    public function test_moderate_refuses_non_topic(): void
    {
        $page = self::factory()->post->create([ 'post_type' => 'page' ]);
        $out  = $this->write('moderate-topic', [ 'topic_id' => $page, 'action' => 'close' ]);
        $this->assertSame('not_a_topic', $out['error']['code']);
        $this->assertSame('publish', get_post_status($page));
    }
}
