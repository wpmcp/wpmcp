<?php

namespace WPMCP\Tests\Free\Safety;

use WPMCP\Safety\Plugin_Table_Rows_Snapshot;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot;

require_once __DIR__ . '/../../support/plugin-data-stubs.php';
require_once __DIR__ . '/../../support/memberpress-stubs.php';

/**
 * The 'plugin_table_rows' snapshot type (issue #299): rows of a Pods
 * table-storage table or a TranslatePress dictionary table, keyed by id,
 * captured verbatim and restored exactly (an inserted row removed, a
 * changed row put back, other rows left alone). The table is always rebuilt
 * from an allowed kind and a plain suffix, so a key can never reach any
 * other table.
 */
class PluginTableRowsSnapshotTest extends \WP_UnitTestCase
{
    public static function wpSetUpBeforeClass(): void
    {
        wpmcp_test_create_trp_table('en_US', 'fr_FR');
        wpmcp_test_create_memberpress_tables();
    }

    public static function wpTearDownAfterClass(): void
    {
        wpmcp_test_drop_trp_table('en_US', 'fr_FR');
        wpmcp_test_drop_memberpress_tables();
    }

    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
    }

    public function test_restore_puts_rows_back_and_leaves_others_alone(): void
    {
        global $wpdb;
        $table  = wpmcp_test_trp_table('en_US', 'fr_FR');
        $a      = wpmcp_test_trp_insert('en_US', 'fr_FR', 'One', null, 0);
        $b      = wpmcp_test_trp_insert('en_US', 'fr_FR', 'Two', 'Deux', 2);
        $key    = Plugin_Table_Rows_Snapshot::key('trp_dictionary', 'en_us_fr_fr', [ $a ]);
        $before = wpmcp_test_trp_rows('en_US', 'fr_FR');

        $snapshot = Snapshot::capture(Plugin_Table_Rows_Snapshot::TYPE, $key);
        $this->assertTrue($snapshot['data']['table_exists']);
        $this->assertCount(1, $snapshot['data']['rows']);

        $wpdb->update($table, [ 'translated' => 'Un', 'status' => 2 ], [ 'id' => $a ]);
        Rollback_Service::apply_snapshot($snapshot);
        $this->assertSame($before, wpmcp_test_trp_rows('en_US', 'fr_FR'));

        $wpdb->update($table, [ 'translated' => 'Deux!' ], [ 'id' => $b ]);
        Rollback_Service::apply_snapshot($snapshot);
        $this->assertSame('Deux!', wpmcp_test_trp_rows('en_US', 'fr_FR')[1]['translated'], 'rows outside the key are untouched');
    }

    public function test_keys_never_reach_another_table(): void
    {
        global $wpdb;
        $this->assertNull(Plugin_Table_Rows_Snapshot::table('users', 'x'));
        $this->assertNull(Plugin_Table_Rows_Snapshot::table('pods', '../users'));
        $this->assertNull(Plugin_Table_Rows_Snapshot::table('pods', 'a` b'));
        $this->assertSame($wpdb->prefix . 'pods_book', Plugin_Table_Rows_Snapshot::table('pods', 'book'));
        $this->assertSame('book', Plugin_Table_Rows_Snapshot::suffix_of('pods', $wpdb->prefix . 'pods_book'));
        $this->assertNull(Plugin_Table_Rows_Snapshot::suffix_of('pods', $wpdb->prefix . 'posts'));

        $snapshot = Snapshot::capture(Plugin_Table_Rows_Snapshot::TYPE, 'users:x:1');
        $this->assertFalse($snapshot['data']['table_exists']);
        $this->assertSame([], $snapshot['data']['rows']);
    }

    public function test_dictionary_restore_needs_manage_options(): void
    {
        $a        = wpmcp_test_trp_insert('en_US', 'fr_FR', 'Three');
        $snapshot = Snapshot::capture(Plugin_Table_Rows_Snapshot::TYPE, Plugin_Table_Rows_Snapshot::key('trp_dictionary', 'en_us_fr_fr', [ $a ]));

        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        $this->expectException(\WPMCP\Safety\Mutation_Failed::class);
        Plugin_Table_Rows_Snapshot::restore($snapshot);
    }

    /**
     * Issue #668: MemberPress rule access conditions are keyed by the rule
     * (rule_id), not by their own id, so a snapshot taken before a write
     * covers the rows the write inserts too: the restore removes them and
     * puts back the rows that were there.
     */
    public function test_memberpress_rule_access_rows_are_keyed_by_rule(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'mepr_rule_access_conditions';
        $rule  = self::factory()->post->create([ 'post_type' => 'post' ]);
        $other = self::factory()->post->create([ 'post_type' => 'post' ]);
        $wpdb->insert($table, [ 'rule_id' => $rule, 'access_type' => 'membership', 'access_operator' => 'is', 'access_condition' => '12' ]);
        $wpdb->insert($table, [ 'rule_id' => $other, 'access_type' => 'role', 'access_operator' => 'is', 'access_condition' => 'editor' ]);
        $before = wpmcp_test_memberpress_conditions($rule);

        $this->assertSame($table, Plugin_Table_Rows_Snapshot::table('mepr_rule_access', 'conditions'));
        $this->assertNull(Plugin_Table_Rows_Snapshot::table('mepr_rule_access', 'transactions'), 'only the access conditions table');

        $snapshot = Snapshot::capture(Plugin_Table_Rows_Snapshot::TYPE, Plugin_Table_Rows_Snapshot::key('mepr_rule_access', 'conditions', [ $rule ]));
        $this->assertCount(1, $snapshot['data']['rows']);

        $wpdb->delete($table, [ 'rule_id' => $rule ]);
        $wpdb->insert($table, [ 'rule_id' => $rule, 'access_type' => 'login_state', 'access_operator' => 'is', 'access_condition' => 'guest' ]);
        $wpdb->insert($table, [ 'rule_id' => $rule, 'access_type' => 'role', 'access_operator' => 'is', 'access_condition' => 'author' ]);
        Rollback_Service::apply_snapshot($snapshot);

        $this->assertSame($before, wpmcp_test_memberpress_conditions($rule));
        $this->assertCount(1, wpmcp_test_memberpress_conditions($other), 'another rule\'s rows are untouched');

        wp_set_current_user(self::factory()->user->create([ 'role' => 'subscriber' ]));
        $this->expectException(\WPMCP\Safety\Mutation_Failed::class);
        Plugin_Table_Rows_Snapshot::restore($snapshot);
    }

    public function test_is_a_restorable_type(): void
    {
        $this->assertContains(Plugin_Table_Rows_Snapshot::TYPE, Rollback_Service::restorable_object_types());
    }
}
