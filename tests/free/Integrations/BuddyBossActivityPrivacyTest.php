<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Safety\Snapshot_Store;

require_once __DIR__ . '/../../support/buddypress-tables.php';

/**
 * Issue #670: BuddyBoss Platform adds per-activity privacy, a scheduled
 * status and bp_suspend moderation to the BuddyPress activity table. The
 * pack reads that table with its own SQL, so a non-moderator must only get
 * what BuddyBoss's own queries would show them: public, loggedin, their own
 * onlyme, friends only for friends, published status, nothing suspended.
 * The fixture is BuddyBoss shaped (privacy and status columns plus the
 * bp_suspend table); the stock BuddyPress tests cover the plain schema.
 */
class BuddyBossActivityPrivacyTest extends \WP_UnitTestCase
{
    private const OTHER_FILTERS = [ 'wpmcp_jetengine_active', 'wpmcp_pods_active', 'wpmcp_translatepress_active' ];

    public static function wpSetUpBeforeClass(): void
    {
        wpmcp_test_create_buddypress_tables();
        wpmcp_test_make_buddyboss_schema();
    }

    public static function wpTearDownAfterClass(): void
    {
        wpmcp_test_drop_buddyboss_schema();
        wpmcp_test_drop_buddypress_tables();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        foreach (self::OTHER_FILTERS as $filter) {
            add_filter($filter, '__return_false');
        }
        add_filter('wpmcp_buddypress_active', '__return_true');
        add_filter('wpmcp_buddyboss_active', '__return_true');
        $GLOBALS['wpmcp_test_bp_friends'] = [];
    }

    protected function tearDown(): void
    {
        foreach (self::OTHER_FILTERS as $filter) {
            remove_all_filters($filter);
        }
        remove_all_filters('wpmcp_buddypress_active');
        remove_all_filters('wpmcp_buddyboss_active');
        remove_all_filters('query');
        $this->reset_schema_cache();
        unset($GLOBALS['wpmcp_test_bp_friends']);
        wp_set_current_user(0);
        parent::tearDown();
    }

    /** @return string[] contents the caller sees, sorted */
    private function seen(array $args = []): array
    {
        $out = (new Plugin_Data_Integration())->handle_read([ 'operation' => 'buddypress-list-activity', 'args' => $args ]);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $contents = array_column($out['result']['activity'], 'content');
        $this->assertSame(count($contents), $out['result']['total'], 'total counts only visible rows');
        sort($contents);
        return $contents;
    }

    /** @return array<string, int> */
    private function stream(int $author, int $other): array
    {
        $a = static fn (string $content, array $cols = []): int => wpmcp_test_bp_activity($author, $content, $cols);
        return [
            'public'    => $a('public post', [ 'privacy' => 'public' ]),
            'loggedin'  => $a('loggedin post', [ 'privacy' => 'loggedin' ]),
            'onlyme'    => $a('onlyme post', [ 'privacy' => 'onlyme' ]),
            'friends'   => $a('friends post', [ 'privacy' => 'friends' ]),
            'media'     => $a('media post', [ 'privacy' => 'media' ]),
            'scheduled' => $a('scheduled post', [ 'status' => 'scheduled' ]),
            'suspended' => $a('suspended post'),
            'hidden'    => $a('hidden by moderation post'),
            'userban'   => $a('suspended user post'),
            'mine'      => wpmcp_test_bp_activity($other, 'my onlyme post', [ 'privacy' => 'onlyme' ]),
        ];
    }

    private function contributor(): int
    {
        return self::factory()->user->create([ 'role' => 'contributor' ]);
    }

    private function moderate(array $ids): void
    {
        wpmcp_test_bp_suspend($ids['suspended'], [ 'hide_sitewide' => 1 ]);
        wpmcp_test_bp_suspend($ids['hidden'], [ 'hide_parent' => 1 ]);
        wpmcp_test_bp_suspend($ids['userban'], [ 'user_suspended' => 1 ]);
    }

    public function test_a_stranger_sees_only_public_and_loggedin_published_unsuspended_items(): void
    {
        $author   = $this->contributor();
        $stranger = $this->contributor();
        $ids      = $this->stream($author, $stranger);
        $this->moderate($ids);

        wp_set_current_user($stranger);
        $this->assertSame([ 'loggedin post', 'my onlyme post', 'public post' ], $this->seen());
    }

    public function test_a_friend_also_sees_friends_only_items_but_never_onlyme(): void
    {
        $author = $this->contributor();
        $friend = $this->contributor();
        $this->stream($author, $friend);
        $GLOBALS['wpmcp_test_bp_friends'][ $friend ] = [ $author ];

        wp_set_current_user($friend);
        $this->assertSame(
            [ 'friends post', 'hidden by moderation post', 'loggedin post', 'my onlyme post', 'public post', 'suspended post', 'suspended user post' ],
            $this->seen()
        );
    }

    public function test_the_author_sees_their_own_onlyme_and_friends_items_but_not_scheduled_or_suspended(): void
    {
        $author = $this->contributor();
        $ids    = $this->stream($author, $this->contributor());
        $this->moderate($ids);

        wp_set_current_user($author);
        $this->assertSame([ 'friends post', 'loggedin post', 'onlyme post', 'public post' ], $this->seen());
    }

    public function test_a_moderator_still_sees_everything(): void
    {
        $author = $this->contributor();
        $ids    = $this->stream($author, $this->contributor());
        $this->moderate($ids);

        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        $this->assertCount(10, $this->seen());
    }

    public function test_replies_under_a_private_or_suspended_item_follow_their_parent(): void
    {
        $author   = $this->contributor();
        $stranger = $this->contributor();
        $ids      = $this->stream($author, $stranger);
        $this->moderate($ids);
        foreach ([ 'onlyme', 'suspended', 'public' ] as $parent) {
            wpmcp_test_bp_activity($author, "reply under {$parent}", [ 'type' => 'activity_comment', 'item_id' => $ids[ $parent ], 'secondary_item_id' => $ids[ $parent ] ]);
        }
        $loose = wpmcp_test_bp_activity($author, 'suspended reply under public', [ 'type' => 'activity_comment', 'item_id' => $ids['public'] ]);
        wpmcp_test_bp_suspend($loose, [ 'hide_sitewide' => 1 ], 'activity_comment');

        wp_set_current_user($stranger);
        $seen = $this->seen();
        $this->assertContains('reply under public', $seen);
        $this->assertNotContains('reply under onlyme', $seen);
        $this->assertNotContains('reply under suspended', $seen);
        $this->assertNotContains('suspended reply under public', $seen);
    }

    public function test_an_item_suspended_as_a_comment_type_does_not_hide_an_activity_with_the_same_id(): void
    {
        $author   = $this->contributor();
        $stranger = $this->contributor();
        $post     = wpmcp_test_bp_activity($author, 'plain post');
        wpmcp_test_bp_suspend($post, [ 'hide_sitewide' => 1 ], 'activity_comment');

        wp_set_current_user($stranger);
        $this->assertSame([ 'plain post' ], $this->seen());
    }

    private function reset_schema_cache(): void
    {
        $prop = new \ReflectionProperty(\WPMCP\Integrations\BuddyPress_Pack::class, 'schema_seen');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
    }

    public function test_a_failing_schema_probe_never_opens_the_filters_on_buddyboss(): void
    {
        $author   = $this->contributor();
        $stranger = $this->contributor();
        $ids      = $this->stream($author, $stranger);
        $this->moderate($ids);
        $this->reset_schema_cache();
        $probes = 0;
        add_filter('query', static function (string $sql) use (&$probes): string {
            if (str_starts_with($sql, 'SHOW COLUMNS') && str_contains($sql, 'bp_suspend')) {
                ++$probes;
                return 'SHOW COLUMNS FROM wpmcp_no_such_table_for_test';
            }
            return $sql;
        });

        wp_set_current_user($stranger);
        $this->assertSame([ 'loggedin post', 'my onlyme post', 'public post' ], $this->seen());
        $this->assertSame(0, $probes, 'BuddyBoss does not depend on the probe');
    }

    public function test_when_not_detected_a_present_privacy_column_is_still_applied_and_the_probe_is_cached(): void
    {
        $author   = $this->contributor();
        $stranger = $this->contributor();
        $ids      = $this->stream($author, $stranger);
        $this->moderate($ids);
        remove_all_filters('wpmcp_buddyboss_active');
        add_filter('wpmcp_buddyboss_active', '__return_false');
        $this->reset_schema_cache();
        $probes = 0;
        add_filter('query', static function (string $sql) use (&$probes): string {
            $probes += str_starts_with($sql, 'SHOW COLUMNS') && str_contains($sql, 'bp_suspend') ? 1 : 0;
            return $sql;
        });

        wp_set_current_user($stranger);
        $this->assertSame([ 'loggedin post', 'my onlyme post', 'public post' ], $this->seen());
        $this->seen();
        $this->assertSame(1, $probes, 'the suspend table is probed once, then served from the cache');
    }

    public function test_group_only_items_need_the_group_component_and_membership(): void
    {
        $author   = $this->contributor();
        $stranger = $this->contributor();
        $member   = $this->contributor();
        $group    = wpmcp_test_bp_group('Closed', 'private', $author);
        wpmcp_test_bp_member($group, $author, [ 'is_admin' => 1 ]);
        wpmcp_test_bp_member($group, $member);
        wpmcp_test_bp_activity($author, 'group only post', [ 'privacy' => 'grouponly', 'component' => 'groups', 'item_id' => $group ]);
        wpmcp_test_bp_activity($author, 'stray grouponly post', [ 'privacy' => 'grouponly' ]);

        wp_set_current_user($stranger);
        $this->assertSame([], $this->seen());
        wp_set_current_user($member);
        $this->assertSame([ 'group only post' ], $this->seen());
    }
}
