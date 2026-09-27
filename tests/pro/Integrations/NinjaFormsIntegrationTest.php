<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Ninja_Forms_Integration;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Rollback_Operation;

require_once __DIR__ . '/../../support/ninjaforms-stubs.php';

/**
 * The Ninja Forms dispatcher (forms adapter pack, pro tier), exercised against
 * a faithful double of the Ninja_Forms()->form() model factory with
 * submissions stored as real nf_sub posts (tests/support/ninjaforms-stubs.php).
 * Live Ninja Forms stays production-verified.
 */
class NinjaFormsIntegrationTest extends \WP_UnitTestCase
{
    private int $old;
    private int $new;

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        \NF_Test_Sub::register();
        \NF_Test_FormHandler::$forms = [
            8 => new \NF_Test_Form(8, ['title' => 'Contact'], [
                new \NF_Test_Field(80, ['type' => 'email', 'label' => 'Your email', 'key' => 'email', 'required' => 1]),
                new \NF_Test_Field(81, ['type' => 'textarea', 'label' => 'Message', 'key' => 'message']),
            ]),
        ];
        \NF_Test_FormHandler::$actions = [
            8 => [
                new \NF_Test_Action(90, ['type' => 'email', 'label' => 'Admin', 'to' => 'a@example.test', 'email_subject' => 'New', 'active' => '0']),
                new \NF_Test_Action(91, ['type' => 'save', 'label' => 'Store Submission']),
            ],
        ];
        $this->old = \NF_Test_Sub::seed(8, 1, [80 => 'old@example.test'], '2026-04-01 10:00:00');
        $this->new = \NF_Test_Sub::seed(8, 2, [80 => 'new@example.test'], '2026-04-02 10:00:00');
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    protected function tearDown(): void
    {
        unregister_post_type('nf_sub');
        parent::tearDown();
    }

    public function test_is_available(): void
    {
        $this->assertTrue((new Ninja_Forms_Integration())->is_available());
    }

    public function test_list_forms(): void
    {
        $out = (new Ninja_Forms_Integration())->handle_read(['operation' => 'list-forms']);
        $this->assertSame(1, $out['result']['total']);
        $this->assertSame('Contact', $out['result']['forms'][0]['title']);
        $this->assertSame(8, $out['result']['forms'][0]['id']);
    }

    public function test_get_form_with_fields(): void
    {
        $out = (new Ninja_Forms_Integration())->handle_read([
            'operation' => 'get-form',
            'args'      => ['form_id' => 8],
        ]);
        $form = $out['result']['form'];
        $this->assertSame('Contact', $form['title']);
        $this->assertSame('email', $form['fields'][0]['type']);
        $this->assertSame('Your email', $form['fields'][0]['label']);
    }

    public function test_get_missing_form_returns_null(): void
    {
        $out = (new Ninja_Forms_Integration())->handle_read([
            'operation' => 'get-form',
            'args'      => ['form_id' => 999],
        ]);
        $this->assertNull($out['result']['form']);
    }

    public function test_list_fields_and_email_notifications(): void
    {
        $i      = new Ninja_Forms_Integration();
        $fields = $i->handle_read(['operation' => 'list-fields', 'args' => ['form_id' => 8]])['result']['fields'];
        $this->assertSame(['id' => 80, 'key' => 'email', 'type' => 'email', 'label' => 'Your email', 'required' => true], $fields[0]);
        $this->assertFalse($fields[1]['required']);

        $notes = $i->handle_read(['operation' => 'list-notifications', 'args' => ['form_id' => 8]])['result']['notifications'];
        $this->assertCount(1, $notes, 'Only email actions are notifications');
        $this->assertSame(90, $notes[0]['id']);
        $this->assertFalse($notes[0]['active']);
    }

    public function test_list_entries_pages_newest_first_with_the_full_total(): void
    {
        $i    = new Ninja_Forms_Integration();
        $page = $i->handle_read(['operation' => 'list-entries', 'args' => ['form_id' => 8, 'page_size' => 1]])['result'];

        $this->assertSame(2, $page['total']);
        $this->assertSame($this->new, $page['entries'][0]['id']);
        $this->assertSame(2, $page['entries'][0]['seq_num']);
        $this->assertSame('active', $page['entries'][0]['status']);

        $next = $i->handle_read(['operation' => 'list-entries', 'args' => ['form_id' => 8, 'page_size' => 1, 'offset' => 1]])['result'];
        $this->assertSame($this->old, $next['entries'][0]['id']);
    }

    public function test_get_entry_returns_values_and_refuses_other_post_types(): void
    {
        $i     = new Ninja_Forms_Integration();
        $entry = $i->handle_read(['operation' => 'get-entry', 'args' => ['entry_id' => $this->old]])['result']['entry'];
        $this->assertSame(8, $entry['form_id']);
        $this->assertSame('old@example.test', $entry['values']['_field_80']);

        $post = self::factory()->post->create();
        $this->assertNull($i->handle_read(['operation' => 'get-entry', 'args' => ['entry_id' => $post]])['result']['entry']);
    }

    public function test_trashing_a_submission_is_snapshotted_and_restored_to_active(): void
    {
        $i   = new Ninja_Forms_Integration();
        $out = $i->handle_write(['operation' => 'update-entry-status', 'args' => ['entry_id' => $this->old, 'status' => 'trash']]);
        $this->assertTrue($out['recoverable']);
        $this->assertSame('trash', get_post_status($this->old));

        $trashed = $i->handle_read(['operation' => 'list-entries', 'args' => ['form_id' => 8, 'status' => 'trash']])['result'];
        $this->assertSame([$this->old], array_column($trashed['entries'], 'id'));

        $back = $i->handle_write(['operation' => 'update-entry-status', 'args' => ['entry_id' => $this->old, 'status' => 'active']]);
        $this->assertTrue($back['result']['changed']);
        $this->assertSame('publish', get_post_status($this->old), 'Restored to where Ninja Forms lists it, not core\'s default draft');

        (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertSame('publish', get_post_status($this->old));
    }

    public function test_entry_ops_are_refused_below_manage_options(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        $i = new Ninja_Forms_Integration();

        $this->assertSame('operation_denied', $i->handle_read(['operation' => 'list-entries', 'args' => ['form_id' => 8]])['error']['code']);
        $this->assertSame('operation_denied', $i->handle_write(['operation' => 'update-entry-status', 'args' => ['entry_id' => $this->old, 'status' => 'trash']])['error']['code']);
        $this->assertSame('publish', get_post_status($this->old));
    }
}
