<?php

namespace WPMCP\Tests\Free\Safety;

use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Content\Create_Post;
use WPMCP\Tools\Content\Update_Post;
use WPMCP\Tools\Rollback_Operation;

/**
 * Issue #758: rollback-operation with an operation id that has no undo
 * point returned restored:false with no warning, so a mistyped or truncated
 * id read as "nothing to undo". It is now refused as an error naming the id.
 */
class RollbackOperationUnknownIdTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    private function undo(string $operation_id)
    {
        return (new Rollback_Operation())->handle(['operation_id' => $operation_id]);
    }

    public function test_an_empty_id_is_refused_as_an_error(): void
    {
        $out = $this->undo('');

        $this->assertWPError($out);
        $this->assertSame('operation_not_found', $out->get_error_code());
        $this->assertStringContainsString('list-operations', $out->get_error_message());
    }

    public function test_an_id_that_is_not_a_uuid_is_refused_as_an_error_naming_it(): void
    {
        $out = $this->undo('not-a-uuid');

        $this->assertWPError($out);
        $this->assertSame('operation_not_found', $out->get_error_code());
        $this->assertStringContainsString('not-a-uuid', $out->get_error_message());
        $this->assertStringContainsString('UUID', $out->get_error_message());
    }

    public function test_a_well_formed_id_with_no_undo_point_is_refused_as_an_error_naming_it(): void
    {
        $id  = '00000000-0000-0000-0000-000000000000';
        $out = $this->undo($id);

        $this->assertWPError($out);
        $this->assertSame('operation_not_found', $out->get_error_code());
        $this->assertSame(
            "No undo point exists for operation {$id}: check the operation_id (list-operations lists them).",
            $out->get_error_message()
        );
    }

    public function test_the_refusal_is_plain_text(): void
    {
        $out = $this->undo('<b>"x"</b>');

        $this->assertWPError($out);
        $this->assertStringContainsString('<b>"x"</b>', $out->get_error_message());
    }

    public function test_an_already_undone_operation_keeps_its_warning(): void
    {
        $post = self::factory()->post->create(['post_title' => 'Before']);
        $op   = (new Update_Post())->handle(['post_id' => $post, 'title' => 'After']);
        $this->assertTrue($this->undo($op['operation_id'])['restored']);

        $again = $this->undo($op['operation_id']);

        $this->assertIsArray($again);
        $this->assertFalse($again['restored']);
        $this->assertCount(1, $again['warnings']);
        $this->assertStringContainsString('already undone', $again['warnings'][0]);
    }

    public function test_a_real_operation_still_restores(): void
    {
        $out = (new Create_Post())->handle(['title' => 'Real']);

        $this->assertTrue($this->undo($out['operation_id'])['restored']);
    }
}
