<?php

namespace WPMCP\Tests\Free\Code;

use WPMCP\Governance\Opt_In_Gates;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Tools\Code\Create_Php_Snippet;
use WPMCP\Tools\Code\Deactivate_Php_Snippet;
use WPMCP\Tools\Code\Delete_Php_Snippet;
use WPMCP\Tools\Code\Get_Php_Snippet;
use WPMCP\Tools\Code\List_Php_Snippets;
use WPMCP\Tools\Code\Php_Snippet_Store;
use WPMCP\Tools\Code\Update_Php_Snippet;
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
