<?php

namespace WPMCP\Tests\Free\Code;

use WPMCP\Governance\Opt_In_Gates;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Tools\Code\Php_Snippet_Guard;
use WPMCP\Tools\Code\Create_Php_Snippet;
use WPMCP\Tools\Code\Deactivate_Php_Snippet;
use WPMCP\Tools\Code\Delete_Php_Snippet;
use WPMCP\Tools\Code\Get_Php_Snippet;
use WPMCP\Tools\Code\List_Php_Snippets;
use WPMCP\Tools\Code\Php_Snippet_Store;
use WPMCP\Tools\Code\Update_Php_Snippet;
use WPMCP\Tools\List_Operations;
use WPMCP\Tools\Meta\Option_Guard;

/**
 * The PHP snippet store CRUD tools (issue #85).
 *
 * The invariants these lock down, in order of how much damage their absence
 * does: a stored snippet is never born active, a stored snippet is never
 * executed by anything here, and rolling back ONE snippet write touches
 * exactly that snippet. The last one is the reason the store does not
 * snapshot its own option: a whole-collection snapshot would make "undo the
 * creation of snippet A" delete every snippet created after A.
 */
class PhpSnippetStoreToolsTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        delete_option(Php_Snippet_Store::OPTION_NAME);
        // Restoring a stored snippet is a site-administration action, so the
        // rollback path requires manage_options exactly like the redirect one.
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    protected function tearDown(): void
    {
        delete_option(Php_Snippet_Store::OPTION_NAME);
        parent::tearDown();
    }

    private function create(string $name = 'hello', string $code = '<?php return 1;'): array
    {
        return (new Create_Php_Snippet())->handle(['name' => $name, 'code' => $code]);
    }

    // -----------------------------------------------------------------
    // create-php-snippet
    // -----------------------------------------------------------------

    public function test_a_created_snippet_is_always_inactive(): void
    {
        $out = $this->create();

        $this->assertSame(Php_Snippet_Store::STATUS_INACTIVE, $out['snippet']['status']);
        $this->assertSame(
            Php_Snippet_Store::STATUS_INACTIVE,
            Php_Snippet_Store::get($out['snippet']['id'])['status']
        );
    }

    public function test_create_ignores_a_status_argument_and_still_stores_inactive(): void
    {
        $out = (new Create_Php_Snippet())->handle([
            'name'   => 'sneaky',
            'code'   => '<?php return 1;',
            'status' => Php_Snippet_Store::STATUS_ACTIVE,
        ]);

        $this->assertSame(Php_Snippet_Store::STATUS_INACTIVE, $out['snippet']['status']);
    }

    public function test_create_refuses_code_that_does_not_parse(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not parse');

        (new Create_Php_Snippet())->handle(['name' => 'broken', 'code' => '<?php function {']);
    }

    public function test_create_refuses_code_the_static_check_flags(): void
    {
        $this->expectException(\RuntimeException::class);

        (new Create_Php_Snippet())->handle([
            'name' => 'nasty',
            'code' => '<?php eval($_GET["x"]);',
        ]);
    }

    public function test_create_sanitizes_the_name(): void
    {
        $out = $this->create('<script>alert(1)</script>Cleanup');

        $this->assertStringNotContainsString('<script>', $out['snippet']['name']);
    }

    public function test_create_refuses_code_over_the_size_cap(): void
    {
        add_filter('wpmcp_php_snippet_max_code_bytes', fn () => 32);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('over the 32 byte limit');

        $this->create('big', '<?php return "' . str_repeat('a', 200) . '";');
    }

    public function test_create_refuses_once_the_store_is_full(): void
    {
        add_filter('wpmcp_php_snippet_max_count', fn () => 1);

        $this->create('first');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('snippet limit');

        $this->create('second');
    }

    // -----------------------------------------------------------------
    // read tools
    // -----------------------------------------------------------------

    public function test_get_returns_code_status_and_the_validation_report(): void
    {
        $id = $this->create()['snippet']['id'];

        $snippet = (new Get_Php_Snippet())->handle(['id' => $id])['snippet'];

        $this->assertSame('<?php return 1;', $snippet['code']);
        $this->assertSame(Php_Snippet_Store::STATUS_INACTIVE, $snippet['status']);
        $this->assertArrayHasKey('safe', $snippet['validation']);
    }

    public function test_list_omits_code_bodies(): void
    {
        $this->create();

        $out = (new List_Php_Snippets())->handle([]);

        $this->assertSame(1, $out['total']);
        $this->assertArrayNotHasKey('code', $out['snippets'][0]);
    }

    public function test_list_survives_a_record_missing_fields(): void
    {
        // A hand edit, a stray write or a restore from an older snapshot can
        // leave a partial record. A listing that fatals is a worse answer
        // than a listing with a blank column.
        update_option(Php_Snippet_Store::OPTION_NAME, [
            'abc' => ['id' => 'abc'],
            'xyz' => 'not-a-record',
        ], false);

        $out = (new List_Php_Snippets())->handle([]);

        $this->assertSame(1, $out['total'], 'The non-record must be filtered out, not listed.');
        $this->assertSame('abc', $out['snippets'][0]['id']);
        $this->assertSame('', $out['snippets'][0]['name']);
    }

    public function test_get_reports_a_malformed_record_as_unknown_rather_than_fatalling(): void
    {
        update_option(Php_Snippet_Store::OPTION_NAME, ['abc' => 'not-a-record'], false);

        $this->assertNull(Php_Snippet_Store::get('abc'));
    }

    // -----------------------------------------------------------------
    // update-php-snippet
    // -----------------------------------------------------------------

    public function test_update_of_code_forces_the_snippet_back_to_inactive(): void
    {
        $id = $this->create()['snippet']['id'];
        Php_Snippet_Store::set_status($id, Php_Snippet_Store::STATUS_ACTIVE);

        $out = (new Update_Php_Snippet())->handle(['id' => $id, 'code' => '<?php return 2;']);

        $this->assertSame(Php_Snippet_Store::STATUS_INACTIVE, $out['snippet']['status']);
    }

    public function test_update_cannot_set_the_status_directly(): void
    {
        $id = $this->create()['snippet']['id'];

        $out = (new Update_Php_Snippet())->handle([
            'id'     => $id,
            'name'   => 'renamed',
            'status' => Php_Snippet_Store::STATUS_ACTIVE,
        ]);

        $this->assertSame('renamed', $out['snippet']['name']);
        $this->assertSame(Php_Snippet_Store::STATUS_INACTIVE, $out['snippet']['status']);
    }

    public function test_update_refuses_code_the_static_check_flags(): void
    {
        $id = $this->create()['snippet']['id'];

        $this->expectException(\RuntimeException::class);

        (new Update_Php_Snippet())->handle(['id' => $id, 'code' => '<?php eval($_POST["x"]);']);
    }

    // -----------------------------------------------------------------
    // deactivate-php-snippet
    // -----------------------------------------------------------------

    public function test_deactivate_flips_an_active_snippet_back_without_the_exec_gate(): void
    {
        $id = $this->create()['snippet']['id'];
        Php_Snippet_Store::set_status($id, Php_Snippet_Store::STATUS_ACTIVE);

        // No wpmcp_allow_php_exec filter here on purpose: revoking must work
        // even after the execution gate has been closed again.
        $out = (new Deactivate_Php_Snippet())->handle(['id' => $id]);

        $this->assertSame(Php_Snippet_Store::STATUS_INACTIVE, $out['snippet']['status']);
    }

    // -----------------------------------------------------------------
    // per-snippet reversibility
    // -----------------------------------------------------------------

    public function test_rolling_back_one_creation_leaves_snippets_created_after_it_alone(): void
    {
        $first  = $this->create('first');
        $second = $this->create('second');

        Rollback_Service::restore_operation($first['operation_id']);

        $this->assertNull(Php_Snippet_Store::get($first['snippet']['id']), 'The rolled-back snippet must be gone.');
        $this->assertNotNull(
            Php_Snippet_Store::get($second['snippet']['id']),
            'A snippet created AFTER the rolled-back operation must survive: the snapshot is per record, not per collection.'
        );
    }

    public function test_rolling_back_a_deletion_restores_that_snippet_only(): void
    {
        $kept    = $this->create('kept');
        $doomed  = $this->create('doomed');
        $deleted = (new Delete_Php_Snippet())->handle(['id' => $doomed['snippet']['id']]);

        $later = $this->create('later');

        Rollback_Service::restore_operation($deleted['operation_id']);

        $this->assertNotNull(Php_Snippet_Store::get($doomed['snippet']['id']));
        $this->assertSame('doomed', Php_Snippet_Store::get($doomed['snippet']['id'])['name']);
        $this->assertNotNull(Php_Snippet_Store::get($kept['snippet']['id']));
        $this->assertNotNull(
            Php_Snippet_Store::get($later['snippet']['id']),
            'A snippet created after the deletion must not be destroyed by undoing that deletion.'
        );
    }

    public function test_rolling_back_an_update_restores_the_prior_code_of_that_snippet_only(): void
    {
        $target = $this->create('target', '<?php return 1;');
        $other  = $this->create('other', '<?php return 9;');

        $updated = (new Update_Php_Snippet())->handle([
            'id'   => $target['snippet']['id'],
            'code' => '<?php return 2;',
        ]);

        Rollback_Service::restore_operation($updated['operation_id']);

        $this->assertSame('<?php return 1;', Php_Snippet_Store::get($target['snippet']['id'])['code']);
        $this->assertSame('<?php return 9;', Php_Snippet_Store::get($other['snippet']['id'])['code']);
    }

    // -----------------------------------------------------------------
    // the store is not reachable through the generic option tools
    // -----------------------------------------------------------------

    // -----------------------------------------------------------------
    // the write path does not destroy what the read path merely filters
    // -----------------------------------------------------------------

    public function test_an_unrelated_write_leaves_a_malformed_sibling_record_in_place(): void
    {
        $id = $this->create('real')['snippet']['id'];

        // The shape all() rejects: a hand edit, a partial restore, a stray
        // write. It is TOLERATED on read, so it must survive a write to a
        // different record rather than being purged by it.
        $raw             = get_option(Php_Snippet_Store::OPTION_NAME);
        $raw['hand-fix'] = 'not a record at all';
        update_option(Php_Snippet_Store::OPTION_NAME, $raw, false);

        (new Update_Php_Snippet())->handle(['id' => $id, 'name' => 'renamed']);

        $this->assertArrayHasKey(
            'hand-fix',
            get_option(Php_Snippet_Store::OPTION_NAME),
            'An entry all() filters out on read must not be destroyed by the next unrelated write.'
        );
    }

    public function test_deleting_one_snippet_leaves_a_malformed_sibling_record_in_place(): void
    {
        $id = $this->create('real')['snippet']['id'];

        $raw             = get_option(Php_Snippet_Store::OPTION_NAME);
        $raw['hand-fix'] = ['no' => 'id here'];
        update_option(Php_Snippet_Store::OPTION_NAME, $raw, false);

        (new Delete_Php_Snippet())->handle(['id' => $id]);

        $this->assertArrayHasKey('hand-fix', get_option(Php_Snippet_Store::OPTION_NAME));
    }

    // -----------------------------------------------------------------
    // bounds
    // -----------------------------------------------------------------

    public function test_create_refuses_once_the_store_would_pass_the_total_size_cap(): void
    {
        $this->create('first');

        // The per-snippet and per-count caps multiply out past what a default
        // MySQL packet accepts; the aggregate cap is the one that keeps the
        // option writable at all.
        add_filter('wpmcp_php_snippet_max_total_bytes', fn () => 64);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('total limit');

        $this->create('second');
    }

    public function test_a_store_write_that_does_not_persist_is_reported_as_a_failure(): void
    {
        $id = $this->create()['snippet']['id'];

        // Stand in for the database refusing the row (oversized packet, for
        // instance). A dropped write must never come back as a success with
        // an operation_id attached.
        add_filter('pre_update_option_' . Php_Snippet_Store::OPTION_NAME, fn ($value, $old) => $old, 10, 2);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('did not persist');

        (new Update_Php_Snippet())->handle(['id' => $id, 'name' => 'renamed']);
    }

    // -----------------------------------------------------------------
    // blank arguments are bad arguments, not absent ones
    // -----------------------------------------------------------------

    public function test_update_refuses_a_blank_code_rather_than_renaming_and_reporting_success(): void
    {
        $id = $this->create('before', '<?php return 1;')['snippet']['id'];

        try {
            (new Update_Php_Snippet())->handle(['id' => $id, 'name' => 'after', 'code' => '']);
            $this->fail('A blank code argument must be refused, not silently treated as "no code supplied".');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('cannot be blank', $e->getMessage());
        }

        $this->assertSame('before', Php_Snippet_Store::get($id)['name'], 'The refused update must not have renamed anything.');
        $this->assertSame('<?php return 1;', Php_Snippet_Store::get($id)['code']);
    }

    public function test_update_refuses_a_blank_name_with_a_message_about_the_name(): void
    {
        $id = $this->create()['snippet']['id'];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('name cannot be blank');

        (new Update_Php_Snippet())->handle(['id' => $id, 'name' => '   ']);
    }

    // -----------------------------------------------------------------
    // the listing reports the id the other tools can resolve
    // -----------------------------------------------------------------

    public function test_list_reports_the_id_get_php_snippet_actually_resolves(): void
    {
        $id = $this->create('drifted')['snippet']['id'];

        // A record whose own 'id' field has drifted from its array key: the
        // key is what get/update/delete look a snippet up by, so that is the
        // id the listing must publish.
        $raw                   = get_option(Php_Snippet_Store::OPTION_NAME);
        $raw[$id]['id']        = 'some-other-id';
        update_option(Php_Snippet_Store::OPTION_NAME, $raw, false);

        $listed = (new List_Php_Snippets())->handle([])['snippets'][0]['id'];

        $this->assertSame($id, $listed);
        $this->assertSame('drifted', (new Get_Php_Snippet())->handle(['id' => $listed])['snippet']['name']);
    }

    // -----------------------------------------------------------------
    // the undo path cannot re-arm a snippet the exec gate would refuse
    // -----------------------------------------------------------------

    public function test_rolling_back_a_deactivation_does_not_re_activate_while_the_exec_gate_is_closed(): void
    {
        $id = $this->create()['snippet']['id'];
        Php_Snippet_Store::set_status($id, Php_Snippet_Store::STATUS_ACTIVE);

        $out = (new Deactivate_Php_Snippet())->handle(['id' => $id]);

        // rollback-operation is free, is not in Opt_In_Gates, and never asks
        // the exec gate. Restoring status='active' verbatim would hand any
        // manage_options caller the activation that activate-php-snippet
        // exists to govern.
        Rollback_Service::restore_operation($out['operation_id']);

        $this->assertSame(
            Php_Snippet_Store::STATUS_INACTIVE,
            Php_Snippet_Store::get($id)['status'],
            'An undo must not re-arm an exec-adjacent flag the execution gate would currently refuse.'
        );
    }

    public function test_rolling_back_a_deactivation_restores_active_when_the_exec_gate_is_open(): void
    {
        $id = $this->create()['snippet']['id'];
        Php_Snippet_Store::set_status($id, Php_Snippet_Store::STATUS_ACTIVE);

        $out = (new Deactivate_Php_Snippet())->handle(['id' => $id]);

        add_filter('wpmcp_allow_php_exec', '__return_true');
        Php_Snippet_Guard::set_environment_override('development');

        try {
            Rollback_Service::restore_operation($out['operation_id']);
            $this->assertSame(
                Php_Snippet_Store::STATUS_ACTIVE,
                Php_Snippet_Store::get($id)['status'],
                'With the gate open the undo is exact: the clamp is a refusal, not a policy of always deactivating.'
            );
        } finally {
            Php_Snippet_Guard::set_environment_override(null);
            remove_filter('wpmcp_allow_php_exec', '__return_true');
        }
    }

    public function test_rolling_back_an_update_still_restores_every_other_field_exactly(): void
    {
        $created = $this->create('original', '<?php return 1;');
        $id      = $created['snippet']['id'];

        $updated = (new Update_Php_Snippet())->handle(['id' => $id, 'name' => 'renamed', 'code' => '<?php return 2;']);

        Rollback_Service::restore_operation($updated['operation_id']);

        $restored = Php_Snippet_Store::get($id);
        $this->assertSame('original', $restored['name']);
        $this->assertSame('<?php return 1;', $restored['code']);
        $this->assertSame($created['snippet']['created_at'], $restored['created_at']);
    }

    // -----------------------------------------------------------------
    // list-operations agrees with the handlers about reversibility
    // -----------------------------------------------------------------

    public function test_list_operations_reports_snippet_writes_as_rollback_available(): void
    {
        $out = $this->create();
        $this->assertTrue($out['recoverable']);

        $ops = (new List_Operations())->handle(['limit' => 20])['operations'];
        $row = null;
        foreach ($ops as $op) {
            if ($op['operation_id'] === $out['operation_id']) {
                $row = $op;
                break;
            }
        }

        $this->assertNotNull($row, 'The create must be in the audit log.');
        $this->assertTrue(
            $row['rollback_available'],
            'The handler promises recoverable: true, so the tool agents use to find undo points must not say otherwise.'
        );
    }

    public function test_every_object_type_rollback_service_dispatches_is_listed_as_restorable(): void
    {
        $source     = file_get_contents(dirname(__DIR__, 3) . '/src/Safety/Rollback_Service.php');
        $dispatched = [];
        preg_match_all("/if \('([a-z_]+)' [!=]== \\\$snapshot\['object_type'\]\)/", (string) $source, $m);
        foreach ($m[1] as $type) {
            $dispatched[] = $type;
        }

        $restorable = Rollback_Service::restorable_object_types();
        sort($dispatched);
        $missing = array_values(array_diff($dispatched, $restorable));

        $this->assertSame(
            [],
            $missing,
            'apply_snapshot() dispatches an object_type that restorable_object_types() does not list, so list-operations reports it as un-undoable: ' . implode(', ', $missing)
        );
    }

    // -----------------------------------------------------------------
    // deactivation is audited on the paths that fail, not only the one that works
    // -----------------------------------------------------------------

    public function test_a_refused_deactivation_is_still_written_to_the_governance_trail(): void
    {
        $before = count($this->audit_entries());

        try {
            (new Deactivate_Php_Snippet())->handle(['id' => 'no-such-snippet']);
            $this->fail('An unknown id must be refused.');
        } catch (\RuntimeException $e) {
            // expected
        }

        $entries = $this->audit_entries();
        $this->assertGreaterThan($before, count($entries), 'A refused deactivation must reach the audit trail.');

        $newest = $entries[0];
        $this->assertSame('wpmcp/deactivate-php-snippet', $newest['ability']);
        $this->assertFalse((bool) $newest['allowed'], 'A refusal must be recorded as a denial, not an allow.');
    }

    /** Newest-first governance trail entries. */
    private function audit_entries(): array
    {
        return \WPMCP\Governance\Governance_Audit_Log::list(200);
    }

    public function test_the_snippet_option_is_denylisted_for_the_generic_option_tools(): void
    {
        $this->assertTrue(Option_Guard::is_denylisted(Php_Snippet_Store::OPTION_NAME));
    }

    public function test_activation_is_listed_as_an_rce_class_gated_ability(): void
    {
        $this->assertTrue(Opt_In_Gates::is_gated('wpmcp/activate-php-snippet'));
        $this->assertSame('wpmcp_allow_php_exec', Opt_In_Gates::filter_for('wpmcp/activate-php-snippet'));
    }

    public function test_the_free_crud_abilities_are_not_marked_gated(): void
    {
        foreach (['create', 'update', 'delete', 'deactivate'] as $verb) {
            $this->assertFalse(
                Opt_In_Gates::is_gated("wpmcp/{$verb}-php-snippet"),
                "wpmcp/{$verb}-php-snippet stores or reduces; marking it RCE-class would blunt the warning."
            );
        }
    }
}
