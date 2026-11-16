<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Ninja_Forms_Integration;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Rollback_Operation;

/**
 * Issue #675 against the real Ninja Forms (bin/test-local.sh --live-forms,
 * WPMCP_LIVE_FORMS=1): a submission is an nf_sub post of the post type Ninja
 * Forms registers itself, so the delete, its snapshot and its undo run on the
 * real post type and its capabilities. Skipped elsewhere; the live leg runs
 * with --fail-on-skipped so it cannot pass vacuously.
 *
 * @group forms-live
 */
class NinjaFormsLiveTest extends \WP_UnitTestCase
{
    private int $entry;

    protected function setUp(): void
    {
        parent::setUp();
        if (! function_exists('Ninja_Forms') || ! post_type_exists('nf_sub')) {
            $this->markTestSkipped('Needs the real Ninja Forms (bin/test-local.sh --live-forms, WPMCP_LIVE_FORMS=1).');
        }
        Snapshot_Store::install();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        add_filter('wpmcp_enable_forms_write', '__return_true');

        $this->entry = (int) wp_insert_post([ 'post_type' => 'nf_sub', 'post_status' => 'publish', 'post_title' => '', 'meta_input' => [ '_form_id' => 8, '_seq_num' => 1, '_field_80' => 'ada@example.test' ] ]);
        $this->assertGreaterThan(0, $this->entry);
    }

    public function test_delete_entry_runs_on_the_real_post_type_and_rolls_back(): void
    {
        $integration = new Ninja_Forms_Integration();
        $this->assertTrue($integration->is_available());

        $preview = $integration->handle_write([ 'operation' => 'delete-entry', 'args' => [ 'entry_id' => $this->entry ] ]);
        $this->assertSame('confirmation_required', $preview['error']['code']);
        $this->assertStringNotContainsString('ada@example.test', wp_json_encode($preview));

        $out = $integration->handle_write([ 'operation' => 'delete-entry', 'confirm' => true, 'args' => [ 'entry_id' => $this->entry ] ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $this->assertTrue($out['recoverable']);
        $this->assertNull(get_post($this->entry));

        $undone = ( new Rollback_Operation() )->handle([ 'operation_id' => $out['operation_id'] ]);
        $this->assertTrue($undone['restored'], wp_json_encode($undone));
        $this->assertSame('nf_sub', get_post_type($this->entry));
        $this->assertSame('ada@example.test', get_post_meta($this->entry, '_field_80', true));
    }
}
