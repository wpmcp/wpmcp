<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

require_once __DIR__ . '/../../support/bbpress.php';

/**
 * The bbPress ops through the real bbPress: what they write is what bbPress
 * itself reports (its counts, sticky lists, statuses and reply threading),
 * and every write rolls back to the exact rows it started from: every
 * forum, topic, reply and revision row and its meta, every author's bbPress
 * counts, the topic tags and their counts, and the super sticky list.
 *
 * It runs only in the local gate's live bbPress leg (bin/test-local.sh, or
 * bin/test-local.sh --live-bbpress alone), which sets WPMCP_LIVE_BBPRESS=1,
 * installs bbPress from wordpress.org on a separate WordPress install and
 * loads it in tests/bootstrap.php. Everywhere else bbPress is absent and the
 * test is skipped; the live leg runs with --fail-on-skipped so it cannot
 * pass vacuously.
 *
 * @group bbpress-live
 */
class BbPressLiveTest extends \WP_UnitTestCase
{
    private int $admin;

    private int $member;

    private int $category;

    private int $forum;

    private int $topic;

    /** @var int[] */
    private array $replies = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (! function_exists('bbpress') || ! function_exists('bbp_insert_topic')) {
            $this->markTestSkipped('Needs the real bbPress (bin/test-local.sh --live-bbpress, WPMCP_LIVE_BBPRESS=1).');
        }
        Snapshot_Store::install();
        $this->admin  = self::factory()->user->create([ 'role' => 'administrator' ]);
        $this->member = self::factory()->user->create([ 'role' => 'editor' ]);
        bbp_set_user_role($this->admin, bbp_get_keymaster_role());
        bbp_set_user_role($this->member, bbp_get_participant_role());
        wp_set_current_user($this->member);

        $this->category = (int) bbp_insert_forum([ 'post_title' => 'Community' ], [ 'forum_type' => 'category' ]);
        $this->forum    = (int) bbp_insert_forum([ 'post_title' => 'General', 'post_parent' => $this->category ]);
        $this->topic    = (int) bbp_insert_topic(
            [ 'post_parent' => $this->forum, 'post_title' => 'Hello', 'post_content' => 'First post', 'post_author' => $this->member ],
            [ 'forum_id' => $this->forum ]
        );
        foreach ([ 'One', 'Two' ] as $text) {
            $this->replies[] = (int) bbp_insert_reply(
                [ 'post_parent' => $this->topic, 'post_content' => $text, 'post_author' => $this->member, 'post_status' => 'publish' ],
                [ 'forum_id' => $this->forum, 'topic_id' => $this->topic ]
            );
        }
        wp_set_object_terms($this->topic, [ 'welcome', 'intro' ], bbp_get_topic_tag_tax_id());
        wp_set_current_user($this->admin);
        $this->assertSame(1, (int) bbp_get_forum_topic_count($this->forum, true, true));
        $this->assertSame(2, (int) bbp_get_topic_reply_count($this->topic, true));
    }

    private function read(string $op, array $args): array
    {
        $out = (new Plugin_Data_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out['result'];
    }

    private function write(string $op, array $args, ?string $session = null): array
    {
        $call = [ 'operation' => $op, 'args' => $args ];
        if (null !== $session) {
            $call['session_id'] = $session;
        }
        return (new Plugin_Data_Integration())->handle_write($call);
    }

    private function ok(string $op, array $args, ?string $session = null): array
    {
        $out = $this->write($op, $args, $session);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertTrue($out['recoverable'], $op);
        return $out;
    }

    private function undo(array $out): void
    {
        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']), implode(' ', Rollback_Service::take_warnings()));
        $this->assertSame([], Rollback_Service::take_warnings());
    }

    public function test_reads_agree_with_bbpress(): void
    {
        $forums = array_column($this->read('bbpress-list-forums', [])['forums'], null, 'id');
        $this->assertSame('category', $forums[ $this->category ]['type']);
        $this->assertSame((int) bbp_get_forum_topic_count($this->forum, false, true), $forums[ $this->forum ]['topic_count']);
        $this->assertSame((int) bbp_get_forum_reply_count($this->forum, false, true), $forums[ $this->forum ]['reply_count']);
        $this->assertSame((int) bbp_get_forum_topic_count($this->category, true, true), $forums[ $this->category ]['total_topic_count']);

        $topics = $this->read('bbpress-list-topics', [ 'forum_id' => $this->forum ])['topics'];
        $this->assertSame([ $this->topic ], array_column($topics, 'id'));
        $this->assertSame((int) bbp_get_topic_voice_count($this->topic, true), $topics[0]['voice_count']);

        $topic = $this->read('bbpress-get-topic', [ 'topic_id' => $this->topic ]);
        $this->assertSame($this->replies, array_column($topic['replies'], 'id'));
        $this->assertSame('First post', $topic['topic']['content']);
    }

    public function test_create_topic_goes_through_bbpress_and_rolls_back_exactly(): void
    {
        $before = wpmcp_test_bbpress_dump();
        $count  = (int) bbp_get_user_topic_count_raw($this->admin);
        $out    = $this->ok('bbpress-create-topic', [ 'forum_id' => $this->forum, 'title' => 'Second', 'content' => 'Body' ]);
        $id     = (int) $out['result']['topic_id'];

        $this->assertTrue(bbp_is_topic($id));
        $this->assertSame('publish', $out['result']['status']);
        $this->assertSame($this->forum, (int) bbp_get_topic_forum_id($id));
        $this->assertSame(2, (int) bbp_get_forum_topic_count($this->forum, false, true));
        $this->assertSame(2, (int) bbp_get_forum_topic_count($this->category, true, true), 'the category above counts it too');
        $this->assertSame($id, (int) bbp_get_forum_last_topic_id($this->forum));
        $this->assertSame($count + 1, (int) bbp_get_user_topic_count($this->admin, true));
        $row = Snapshot_Store::get_by_operation((string) $out['operation_id']);
        $this->assertSame("topic_created:{$id}", $row['object_ref'], 'the ledger lists the create under its new id');

        $this->undo($out);
        $this->assertNull(get_post($id));
        $this->assertSame($before, wpmcp_test_bbpress_dump());
    }

    public function test_create_reply_goes_through_bbpress_and_rolls_back_exactly(): void
    {
        $before = wpmcp_test_bbpress_dump();
        $out    = $this->ok('bbpress-create-reply', [ 'topic_id' => $this->topic, 'content' => 'Threaded', 'reply_to' => $this->replies[0] ]);
        $id     = (int) $out['result']['reply_id'];

        $this->assertTrue(bbp_is_reply($id));
        $this->assertSame($this->replies[0], (int) bbp_get_reply_to($id));
        $this->assertSame(3, (int) bbp_get_topic_reply_count($this->topic, true));
        $this->assertSame(3, (int) bbp_get_forum_reply_count($this->forum, false, true));
        $this->assertSame($id, (int) bbp_get_topic_last_reply_id($this->topic));
        $this->assertSame(2, (int) bbp_get_topic_voice_count($this->topic, true), 'the admin joins the conversation');

        $this->undo($out);
        $this->assertNull(get_post($id));
        $this->assertSame($before, wpmcp_test_bbpress_dump());
    }

    public function test_close_rolls_back_exactly(): void
    {
        $before = wpmcp_test_bbpress_dump();
        $out    = $this->ok('bbpress-moderate-topic', [ 'topic_id' => $this->topic, 'action' => 'close' ]);
        $this->assertTrue(bbp_is_topic_closed($this->topic));
        $this->assertSame('already_done', $this->write('bbpress-moderate-topic', [ 'topic_id' => $this->topic, 'action' => 'close' ])['error']['code'] ?? '');

        wp_set_current_user($this->member);
        $this->assertSame('topic_closed', $this->write('bbpress-create-reply', [ 'topic_id' => $this->topic, 'content' => 'Late' ])['error']['code'] ?? '');
        wp_set_current_user($this->admin);

        $this->undo($out);
        $this->assertFalse(bbp_is_topic_closed($this->topic));
        $this->assertSame($before, wpmcp_test_bbpress_dump());
    }

    public function test_stick_rolls_back_exactly_including_the_forums_sticky_list(): void
    {
        $before = wpmcp_test_bbpress_dump();
        $out    = $this->ok('bbpress-moderate-topic', [ 'topic_id' => $this->topic, 'action' => 'stick' ]);
        $this->assertTrue(bbp_is_topic_sticky($this->topic, false));
        $this->assertSame([ $this->topic ], array_map('intval', bbp_get_stickies($this->forum)));
        $this->assertTrue($out['result']['sticky']);

        $this->undo($out);
        $this->assertFalse(bbp_is_topic_sticky($this->topic, false));
        $this->assertSame($before, wpmcp_test_bbpress_dump());
    }

    public function test_spam_rolls_back_exactly_with_its_replies_tags_and_counts(): void
    {
        bbp_stick_topic($this->topic, false);
        $before = wpmcp_test_bbpress_dump();
        $out    = $this->ok('bbpress-moderate-topic', [ 'topic_id' => $this->topic, 'action' => 'spam' ]);

        $this->assertTrue(bbp_is_topic_spam($this->topic));
        foreach ($this->replies as $reply) {
            $this->assertNotSame('publish', get_post_status($reply), 'its public replies are hidden with it');
        }
        $this->assertSame(0, (int) bbp_get_forum_topic_count($this->forum, false, true));
        $this->assertSame(0, (int) bbp_get_forum_reply_count($this->forum, false, true));
        $this->assertSame([], wp_get_object_terms($this->topic, bbp_get_topic_tag_tax_id(), [ 'fields' => 'ids' ]));
        $this->assertSame([], array_map('intval', bbp_get_stickies($this->forum)), 'a spammed topic is unstuck');

        $this->undo($out);
        $this->assertFalse(bbp_is_topic_spam($this->topic));
        $this->assertSame($before, wpmcp_test_bbpress_dump());
    }

    public function test_a_session_that_posts_and_moderates_rolls_back_as_a_whole(): void
    {
        $before  = wpmcp_test_bbpress_dump();
        $session = wp_generate_uuid4();
        $topic   = (int) $this->ok('bbpress-create-topic', [ 'forum_id' => $this->forum, 'title' => 'Thread', 'content' => 'Body' ], $session)['result']['topic_id'];
        $this->ok('bbpress-create-reply', [ 'topic_id' => $topic, 'content' => 'Reply' ], $session);
        $this->ok('bbpress-moderate-topic', [ 'topic_id' => $topic, 'action' => 'close' ], $session);
        $this->ok('bbpress-moderate-topic', [ 'topic_id' => $this->topic, 'action' => 'spam' ], $session);

        $this->assertSame(4, Rollback_Service::restore_session($session), implode(' ', Rollback_Service::take_warnings()));
        $this->assertSame([], Rollback_Service::take_warnings());
        $this->assertSame($before, wpmcp_test_bbpress_dump());
    }

    public function test_undoing_a_topic_someone_has_replied_to_is_refused(): void
    {
        $out   = $this->ok('bbpress-create-topic', [ 'forum_id' => $this->forum, 'title' => 'Ask', 'content' => 'Body' ]);
        $id    = (int) $out['result']['topic_id'];
        $reply = (int) bbp_insert_reply([ 'post_parent' => $id, 'post_content' => 'Answer', 'post_author' => $this->member, 'post_status' => 'publish' ], [ 'forum_id' => $this->forum, 'topic_id' => $id ]);

        $this->assertFalse(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertStringContainsString('has replies posted since', implode(' ', Rollback_Service::take_warnings()));
        $this->assertTrue(bbp_is_topic($id));
        $this->assertTrue(bbp_is_reply($reply));
    }

    public function test_bbpress_capabilities_and_rules_refuse_before_any_snapshot(): void
    {
        $rows = Snapshot_Store::row_count();
        $this->assertSame('forum_is_category', $this->write('bbpress-create-topic', [ 'forum_id' => $this->category, 'title' => 'No', 'content' => 'No' ])['error']['code'] ?? '');
        $this->assertSame('invalid_reply_to', $this->write('bbpress-create-reply', [ 'topic_id' => $this->topic, 'content' => 'No', 'reply_to' => $this->topic ])['error']['code'] ?? '');

        // An editor who is only a forum participant may post but not moderate.
        wp_set_current_user($this->member);
        $this->assertSame('operation_denied', $this->write('bbpress-moderate-topic', [ 'topic_id' => $this->topic, 'action' => 'spam' ])['error']['code'] ?? '');
        $this->assertSame($rows, Snapshot_Store::row_count());

        $out = $this->ok('bbpress-create-reply', [ 'topic_id' => $this->topic, 'content' => 'Mine' ]);
        $this->assertSame($this->member, (int) get_post_field('post_author', (int) $out['result']['reply_id']));
    }
}
