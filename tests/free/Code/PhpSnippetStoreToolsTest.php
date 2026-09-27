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
        $snapshots_before = $this->snapshot_count();

        try {
            (new Create_Php_Snippet())->handle([
                'name' => 'nasty',
                'code' => '<?php eval($_GET["x"]);',
            ]);
            $this->fail('A critical static finding must refuse creation.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('static validation flagged', $e->getMessage());
        }

        $this->assertSame([], Php_Snippet_Store::all(), 'A refused create must store nothing.');
        $this->assertSame($snapshots_before, $this->snapshot_count(), 'A refused create must be refused before anything is snapshotted.');
    }

    public function test_create_allows_code_with_only_non_critical_findings_and_keeps_the_report(): void
    {
        $out = $this->create('fetcher', '<?php return wp_remote_get("https://example.com");');
        $id  = $out['snippet']['id'];

        $report = (new Get_Php_Snippet())->handle(['id' => $id])['snippet']['validation'];

        $this->assertTrue($report['safe']);
        $this->assertNotEmpty($report['warnings'], 'The warning must be kept in the stored report, not only allowed through.');
        $this->assertSame('warning', $report['warnings'][0]['severity']);
    }

    /** Rows in the snapshot ledger. */
    private function snapshot_count(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . \WPMCP\Safety\Snapshot_Store::table_name());
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
        $id = $this->create('keep', '<?php return 1;')['snippet']['id'];
        Php_Snippet_Store::set_status($id, Php_Snippet_Store::STATUS_ACTIVE);
        $snapshots_before = $this->snapshot_count();

        try {
            (new Update_Php_Snippet())->handle(['id' => $id, 'code' => '<?php eval($_POST["x"]);']);
            $this->fail('A critical static finding must refuse the update.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('static validation flagged', $e->getMessage());
        }

        $stored = Php_Snippet_Store::get($id);
        $this->assertSame('<?php return 1;', $stored['code'], 'A refused update must leave the old code in place.');
        $this->assertSame(Php_Snippet_Store::STATUS_ACTIVE, $stored['status'], 'A refused update must not touch the status.');
        $this->assertSame($snapshots_before, $this->snapshot_count());
    }

    public function test_update_of_code_refreshes_the_stored_validation_report(): void
    {
        $id = $this->create('plain', '<?php return 1;')['snippet']['id'];
        $this->assertSame([], Php_Snippet_Store::get($id)['validation']['warnings']);

        (new Update_Php_Snippet())->handle(['id' => $id, 'code' => '<?php return wp_remote_get("https://example.com");']);

        $report = (new Get_Php_Snippet())->handle(['id' => $id])['snippet']['validation'];
        $this->assertNotEmpty($report['warnings'], 'get must return the report for the NEW code.');
    }

    public function test_update_refuses_a_name_that_sanitizes_to_blank(): void
    {
        $id = $this->create('keep-me')['snippet']['id'];

        try {
            (new Update_Php_Snippet())->handle(['id' => $id, 'name' => '<b></b>']);
            $this->fail('A name that sanitizes to nothing must be refused, as create refuses it.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('name cannot be blank', $e->getMessage());
        }

        $this->assertSame('keep-me', Php_Snippet_Store::get($id)['name']);
    }

    public function test_an_update_that_changes_nothing_is_refused_without_a_write(): void
    {
        $id      = $this->create('same', '<?php return 1;')['snippet']['id'];
        $stamp   = Php_Snippet_Store::get($id)['updated_at'];
        $before  = $this->snapshot_count();

        try {
            (new Update_Php_Snippet())->handle(['id' => $id, 'code' => '<?php return 1;']);
            $this->fail('Resubmitting the stored code alone is not an update.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('nothing to update', strtolower($e->getMessage()));
        }

        $this->assertSame($before, $this->snapshot_count());
        $this->assertSame($stamp, Php_Snippet_Store::get($id)['updated_at']);
    }

    public function test_an_update_with_identical_code_does_not_deactivate(): void
    {
        $id = $this->create('same', '<?php return 1;')['snippet']['id'];
        Php_Snippet_Store::set_status($id, Php_Snippet_Store::STATUS_ACTIVE);

        $out = (new Update_Php_Snippet())->handle(['id' => $id, 'name' => 'renamed', 'code' => '<?php return 1;']);

        $this->assertSame('renamed', $out['snippet']['name']);
        $this->assertSame(
            Php_Snippet_Store::STATUS_ACTIVE,
            $out['snippet']['status'],
            'Unchanged code has not left the activation it was approved under, so it is not silently revoked.'
        );
    }

    public function test_update_and_delete_are_recorded_in_the_snapshot_ledger(): void
    {
        $id     = $this->create()['snippet']['id'];
        $before = $this->snapshot_count();

        $update = (new Update_Php_Snippet())->handle(['id' => $id, 'name' => 'renamed']);
        $delete = (new Delete_Php_Snippet())->handle(['id' => $id]);

        $this->assertNotEmpty($update['operation_id']);
        $this->assertNotEmpty($delete['operation_id']);
        $this->assertSame($before + 2, $this->snapshot_count());
    }

    public function test_rolling_back_an_update_of_a_drifted_record_restores_it_under_its_key(): void
    {
        $id = $this->create('drift', '<?php return 1;')['snippet']['id'];

        // A hand edit or partial restore can leave the record's own id field
        // disagreeing with the key every tool resolves it by.
        $raw               = get_option(Php_Snippet_Store::OPTION_NAME);
        $raw[$id]['id']    = 'something-else';
        update_option(Php_Snippet_Store::OPTION_NAME, $raw, false);

        $out = (new Update_Php_Snippet())->handle(['id' => $id, 'code' => '<?php return 2;']);
        Rollback_Service::restore_operation($out['operation_id']);

        $stored = get_option(Php_Snippet_Store::OPTION_NAME);
        $this->assertCount(1, $stored, 'The undo must not leave a second, ghost record behind.');
        $this->assertSame('<?php return 1;', $stored[$id]['code'], 'The undo must restore the record at the key it was captured under.');
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

    public function test_update_fields_refuses_when_the_code_is_not_the_code_the_caller_checked(): void
    {
        $id = $this->create('guarded', '<?php return 1;')['snippet']['id'];

        try {
            Php_Snippet_Store::update_fields(
                $id,
                ['status' => Php_Snippet_Store::STATUS_ACTIVE],
                hash('sha256', '<?php return 2;')
            );
            $this->fail('A code-hash mismatch on the re-read record must abort the write.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('code changed', $e->getMessage());
        }

        $this->assertSame(Php_Snippet_Store::STATUS_INACTIVE, Php_Snippet_Store::get($id)['status']);

        $out = Php_Snippet_Store::update_fields(
            $id,
            ['status' => Php_Snippet_Store::STATUS_ACTIVE],
            hash('sha256', '<?php return 1;')
        );
        $this->assertSame(Php_Snippet_Store::STATUS_ACTIVE, $out['status']);
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

    public function test_rolling_back_a_deactivation_never_re_activates_even_with_the_exec_gate_open(): void
    {
        $id = $this->create()['snippet']['id'];
        Php_Snippet_Store::set_status($id, Php_Snippet_Store::STATUS_ACTIVE);

        $out = (new Deactivate_Php_Snippet())->handle(['id' => $id]);

        add_filter('wpmcp_allow_php_exec', '__return_true');
        Php_Snippet_Guard::set_environment_override('development');

        try {
            // An open exec gate is not the whole of the activation contract:
            // activate-php-snippet is also pro, governance-toggled,
            // identity-scoped and audited, and rollback-operation is none of
            // those. So an undo never re-arms; re-activation goes back through
            // the governed path.
            Rollback_Service::restore_operation($out['operation_id']);
            $this->assertSame(
                Php_Snippet_Store::STATUS_INACTIVE,
                Php_Snippet_Store::get($id)['status'],
                'rollback-operation must never be a second door to activation.'
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
        $phantom = array_values(array_diff($restorable, $dispatched));

        $this->assertSame(
            [],
            $missing,
            'apply_snapshot() dispatches an object_type that restorable_object_types() does not list, so list-operations reports it as un-undoable: ' . implode(', ', $missing)
        );
        $this->assertSame(
            [],
            $phantom,
            'restorable_object_types() lists an object_type apply_snapshot() has no branch for, so list-operations offers a Restore that cannot work: ' . implode(', ', $phantom)
        );
    }

    // -----------------------------------------------------------------
    // deactivation is audited on the paths that fail, not only the one that works
    // -----------------------------------------------------------------

    public function test_a_deactivation_of_an_unknown_id_does_not_write_to_the_governance_trail(): void
    {
        // A free, ungated tool must not be able to flush the 500-entry
        // exec-gate trail with a loop of bogus ids.
        $before = count($this->audit_entries());

        try {
            (new Deactivate_Php_Snippet())->handle(['id' => 'no-such-snippet']);
            $this->fail('An unknown id must be refused.');
        } catch (\RuntimeException $e) {
            // expected
        }

        $this->assertSame($before, count($this->audit_entries()));
    }

    public function test_a_deactivation_that_fails_on_a_stored_record_is_audited_as_a_denial(): void
    {
        $id = $this->create()['snippet']['id'];
        Php_Snippet_Store::set_status($id, Php_Snippet_Store::STATUS_ACTIVE);

        $fail = static function () {
            throw new \RuntimeException('write refused');
        };
        add_filter('pre_update_option_' . Php_Snippet_Store::OPTION_NAME, $fail);

        try {
            (new Deactivate_Php_Snippet())->handle(['id' => $id]);
            $this->fail('A failed store write must surface.');
        } catch (\RuntimeException $e) {
            // expected
        } finally {
            remove_filter('pre_update_option_' . Php_Snippet_Store::OPTION_NAME, $fail);
        }

        $newest = $this->audit_entries()[0];
        $this->assertSame('wpmcp/deactivate-php-snippet', $newest['ability']);
        $this->assertFalse((bool) $newest['allowed']);
        $this->assertNotSame('', (string) $newest['reason'], 'A denial carries its refusal class.');
    }

    public function test_deactivating_an_already_inactive_snippet_writes_nothing(): void
    {
        $id     = $this->create()['snippet']['id'];
        $before = $this->snapshot_count();

        $out = (new Deactivate_Php_Snippet())->handle(['id' => $id]);

        $this->assertFalse($out['changed']);
        $this->assertNull($out['operation_id']);
        $this->assertSame($before, $this->snapshot_count(), 'A no-op must not burn a history slot.');
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

    /**
     * option_name is matched by MySQL under a case-insensitive collation and
     * WordPress trims it, so a case or whitespace variant reaches the very
     * same row. The denylist must fold the name the way the database does.
     */
    public function test_a_case_or_whitespace_variant_of_the_snippet_option_is_denylisted_too(): void
    {
        $this->assertTrue(Option_Guard::is_denylisted('WPMCP_PHP_SNIPPETS'));
        $this->assertTrue(Option_Guard::is_denylisted('  Wpmcp_Php_Snippets '));
        $this->assertTrue(Option_Guard::is_denylisted("wpmcp_php_snipp\u{00E9}ts"), 'An accent variant matches the same row under the default collation.');
        $this->assertTrue(Option_Guard::is_denylisted('SITEURL'), 'The fold applies to every exact-name entry, not only the snippet store.');
        $this->assertFalse(Option_Guard::is_denylisted('blogname'));
    }

    /**
     * Collation-ignorable code points (zero-width space, soft hyphen) survive
     * remove_accents() but are skipped by utf8mb4_unicode_ci, so such a name
     * lands on the real row. Option names are ASCII in practice: anything
     * outside printable ASCII is refused outright by the generic option tools.
     */
    public function test_names_with_invisible_or_non_ascii_characters_are_refused(): void
    {
        foreach (["wpmcp_php_snippets\u{200B}", "wpmcp_php_\u{00AD}snippets", "blog\u{00E9}name", "blogname\x07"] as $name) {
            $this->assertFalse(Option_Guard::is_plain_name($name), 'Not a plain ASCII option name: ' . bin2hex($name));
            $this->assertTrue(Option_Guard::is_denylisted($name), 'A non-plain name must be treated as denied: ' . bin2hex($name));
        }

        $this->assertTrue(Option_Guard::is_plain_name('blogname'));
        $this->assertTrue(Option_Guard::is_plain_name('_transient_wc-foo.bar:1'));
    }

    public function test_update_option_refuses_a_zero_width_variant_of_the_snippet_store(): void
    {
        add_filter('wpmcp_enable_option_write', '__return_true');
        $this->create('keep', '<?php return 1;');

        try {
            (new \WPMCP\Tools\Meta\Update_Option())->handle(['name' => "wpmcp_php_snippets\u{200B}", 'value' => []]);
            $this->fail('A zero-width variant of the store name must be refused.');
        } catch (\RuntimeException $e) {
            $this->assertNotEmpty($e->getMessage());
        } finally {
            remove_all_filters('wpmcp_enable_option_write');
        }

        $this->assertCount(1, Php_Snippet_Store::all());
    }

    public function test_filter_supplied_patterns_are_folded_like_the_name(): void
    {
        $add = static function (array $patterns): array {
            $patterns[] = '  MyVendor_Token ';
            return $patterns;
        };
        add_filter('wpmcp_option_denylist_patterns', $add);

        try {
            $this->assertTrue(Option_Guard::is_denylisted('myvendor_token_cache'));
        } finally {
            remove_filter('wpmcp_option_denylist_patterns', $add);
        }
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
