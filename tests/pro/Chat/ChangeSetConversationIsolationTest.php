<?php

namespace WPMCP\Tests\Pro\Chat;

use WPMCP\Pro\Chat\Conversation_Store;
use WPMCP\Pro\Gate;
use WPMCP\RateLimit\Rate_Limiter;
use WPMCP\Safety\Safe_Mutation;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Sync\Change_Set_Applier;
use WPMCP\Tools\Sync\Change_Set_Builder;
use WPMCP\Tools\Sync\Change_Set_Format;

/**
 * Issue #506: local-live sync must never read or write a chat conversation.
 *
 * Conversations are one administrator's private exchange with their own
 * model. Every other ability refuses them; a change set is just another
 * read path (build + get) and write path (apply), so it has to refuse them
 * too, by id, by session, and on a crafted artifact, with or without force.
 */
class ChangeSetConversationIsolationTest extends \WP_UnitTestCase
{
    private const SECRET = 'TOP-SECRET-OWNER-MESSAGE';

    private Conversation_Store $store;
    private int $owner_id;
    private int $other_admin;
    /** @var string[] */
    private array $files = [];

    public static function wpSetUpBeforeClass(): void
    {
        if (0 === did_action('wp_abilities_api_init')) {
            do_action('wp_abilities_api_init');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        Conversation_Store::register_post_type();
        Gate::set_pro_for_tests(true);
        Rate_Limiter::set_clock_override(fn () => 1_700_075_000);
        $this->store       = new Conversation_Store();
        $this->owner_id    = self::factory()->user->create(['role' => 'administrator']);
        $this->other_admin = self::factory()->user->create(['role' => 'administrator']);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        Rate_Limiter::set_clock_override(null);
        Gate::set_pro_for_tests(null);
        wp_set_current_user(0);
        parent::tearDown();
    }

    private function conversation(): int
    {
        $id = $this->store->create($this->owner_id, 'private exchange');
        $this->store->append_message($id, $this->owner_id, ['role' => 'user', 'content' => self::SECRET]);
        return $id;
    }

    private function keys(array $set): array
    {
        return array_values(array_map(static fn ($o) => (string) $o['key'], (array) $set['objects']));
    }

    public function test_build_change_set_excludes_a_conversation_selected_by_id(): void
    {
        $id   = $this->conversation();
        $page = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'ordinary page']);
        wp_set_current_user($this->other_admin);

        $built = wp_get_ability('wpmcp/build-change-set')->execute(['objects' => ['post:' . $id, 'post:' . $page]]);
        $this->assertIsArray($built, 'naming a conversation in a selection is not an error, the conversation is excluded');
        $this->assertArrayHasKey('file', $built, 'the ordinary page still gets an artifact');
        $this->files[] = (string) $built['file'];

        $read = wp_get_ability('wpmcp/get-change-set')->execute(['path' => $built['file'], 'raw' => true]);
        $this->assertIsArray($read);
        $set = $read['change_set'];

        $this->assertSame(['post:' . $page], $this->keys($set));
        $this->assertContains($id, array_map('intval', wp_list_pluck((array) $set['excluded'], 'object_id')), 'the exclusion is reported, not silent');
        $this->assertStringNotContainsString(self::SECRET, (string) wp_json_encode($read));
        $this->assertStringNotContainsString('_wpmcp_chat_messages', (string) wp_json_encode($read));
    }

    public function test_build_change_set_of_only_a_conversation_writes_no_artifact(): void
    {
        $id = $this->conversation();
        wp_set_current_user($this->other_admin);

        $built = wp_get_ability('wpmcp/build-change-set')->execute(['objects' => ['post:' . $id]]);
        $this->assertIsArray($built);
        $this->assertArrayNotHasKey('file', $built);
        $this->assertContains($id, array_map('intval', wp_list_pluck((array) $built['excluded_rows'], 'object_id')));
        $this->assertStringNotContainsString(self::SECRET, (string) wp_json_encode($built));
    }

    public function test_build_change_set_excludes_a_conversation_touched_in_the_session(): void
    {
        $id = $this->conversation();
        wp_set_current_user($this->other_admin);

        // A ledger row for the conversation, the way any snapshotted write would record one.
        Safe_Mutation::run(
            ['object_type' => 'post', 'object_id' => $id, 'session_id' => 'sync-506', 'tool_name' => 'update-post', 'args' => []],
            static fn () => wp_update_post(['ID' => $id, 'post_title' => 'private exchange'])
        );

        $set = (new Change_Set_Builder())->build(['session_id' => 'sync-506']);

        $this->assertNotContains('post:' . $id, $this->keys($set));
        $this->assertStringNotContainsString(self::SECRET, (string) wp_json_encode($set));
    }

    public function test_the_non_syncable_filter_cannot_make_a_conversation_syncable(): void
    {
        $empty = static fn () => [];
        add_filter('wpmcp_sync_non_syncable_post_types', $empty);
        try {
            $this->assertFalse(Change_Set_Format::is_syncable_post_type(Conversation_Store::POST_TYPE));
        } finally {
            remove_filter('wpmcp_sync_non_syncable_post_types', $empty);
        }
    }

    /**
     * A change set crafted to target a live conversation: built from an
     * ordinary post, then retargeted at the conversation's id and identity,
     * resealed so it passes the checksum.
     */
    private function crafted_set_targeting(int $id): array
    {
        $carrier = self::factory()->post->create(['post_title' => 'carrier']);
        $set     = (new Change_Set_Builder())->build(['objects' => ['post:' . $carrier]]);
        $convo   = get_post($id);

        $entry                                  = $set['objects'][0];
        $entry['key']                           = 'post:' . $id;
        $entry['object_id']                     = $id;
        $entry['data']['post_type']             = Conversation_Store::POST_TYPE;
        $entry['data']['post_title']            = 'overwritten by another admin';
        $entry['data']['post_status']           = $convo->post_status;
        $entry['data']['post_name']             = $convo->post_name;
        $entry['data']['post_date']             = $convo->post_date;
        $entry['data']['post_date_gmt']         = $convo->post_date_gmt;
        $entry['meta']['_wpmcp_chat_messages']  = [serialize([['role' => 'user', 'content' => 'INJECTED-BY-OTHER-ADMIN', 'time' => 1]])];
        $entry['hash_scope']['meta_keys'][]     = '_wpmcp_chat_messages';
        $entry['hash']                          = Change_Set_Format::post_hash(
            $entry['data'],
            $entry['meta'],
            $entry['terms'],
            $entry['hash_scope']['meta_keys'],
            $entry['hash_scope']['taxonomies'],
            Change_Set_Format::url_tokens(home_url(), site_url())
        );
        $set['objects']  = [$entry];
        $set['checksum'] = Change_Set_Format::checksum($set);
        return $set;
    }

    /** @return array<string, array{bool}> */
    public static function force_cases(): array
    {
        return ['without force' => [false], 'with force' => [true]];
    }

    /** @dataProvider force_cases */
    public function test_apply_change_set_refuses_a_crafted_entry_for_a_conversation(bool $force): void
    {
        $id = $this->conversation();
        wp_set_current_user($this->other_admin);
        $set = $this->crafted_set_targeting($id);

        $report = (new Change_Set_Applier())->apply($set, [
            'dry_run'    => false,
            'session_id' => 'sync-506',
            'force'      => $force ? ['post:' . $id] : [],
        ]);

        $row = null;
        foreach ($report['objects'] as $r) {
            if ('post:' . $id === $r['key']) {
                $row = $r;
            }
        }
        $this->assertNotNull($row, 'the refusal is reported');
        $this->assertSame('skipped', $row['outcome'], wp_json_encode($row));
        $this->assertStringContainsString('never synced', (string) $row['reason']);

        $this->assertSame('private exchange', get_post($id)->post_title);
        $messages = $this->store->get_messages($id, $this->owner_id);
        $this->assertStringNotContainsString('INJECTED-BY-OTHER-ADMIN', (string) wp_json_encode($messages));
        $this->assertStringContainsString(self::SECRET, (string) wp_json_encode($messages));
    }

    public function test_apply_change_set_ability_refuses_a_conversation_even_with_force(): void
    {
        $id = $this->conversation();
        wp_set_current_user($this->other_admin);
        $set = $this->crafted_set_targeting($id);

        wp_get_ability('wpmcp/apply-change-set')->execute(['change_set' => $set, 'dry_run' => false, 'force' => ['post:' . $id]]);

        $this->assertSame('private exchange', get_post($id)->post_title);
        $messages = $this->store->get_messages($id, $this->owner_id);
        $this->assertStringNotContainsString('INJECTED-BY-OTHER-ADMIN', (string) wp_json_encode($messages));
    }
}
