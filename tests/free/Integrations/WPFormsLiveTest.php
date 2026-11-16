<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\WPForms_Integration;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Rollback_Operation;

/**
 * Issue #674 against the real WPForms Lite (bin/test-local.sh --live-forms,
 * WPMCP_LIVE_FORMS=1): the form is created and read by WPForms' own form
 * handler, so the notification and confirmation edits are proven against the
 * JSON WPForms itself stores. Skipped elsewhere; the live leg runs with
 * --fail-on-skipped so it cannot pass vacuously.
 *
 * @group forms-live
 */
class WPFormsLiveTest extends \WP_UnitTestCase
{
    private WPForms_Integration $integration;
    private int $form_id;

    protected function setUp(): void
    {
        parent::setUp();
        if (! function_exists('wpforms') || ! defined('WPFORMS_VERSION') || ! class_exists('WPForms\WPForms')) {
            $this->markTestSkipped('Needs the real WPForms Lite (bin/test-local.sh --live-forms, WPMCP_LIVE_FORMS=1).');
        }
        Snapshot_Store::install();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        add_filter('wpmcp_enable_forms_write', '__return_true');

        $this->form_id = (int) wpforms()->obj('form')->add('Live WPForms', [], [ 'template' => 'simple-contact-form-template' ]);
        $this->assertGreaterThan(0, $this->form_id, 'WPForms created the form from its own template');
        $this->integration = new WPForms_Integration();
    }

    private function settings(): array
    {
        clean_post_cache($this->form_id);
        return (array) wpforms_decode(get_post($this->form_id)->post_content)['settings'];
    }

    private function ok(array $out): array
    {
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        return $out;
    }

    public function test_the_real_form_is_read_and_its_notification_and_confirmation_are_edited_and_undone(): void
    {
        $this->assertTrue($this->integration->is_available());

        $notes = $this->ok($this->integration->handle_read([ 'operation' => 'list-notifications', 'args' => [ 'form_id' => $this->form_id ] ]))['result']['notifications'];
        $this->assertNotEmpty($notes, 'The real template ships a notification');
        $nid  = $notes[0]['id'];
        $old  = $notes[0]['subject'];

        $edit = $this->ok($this->integration->handle_write([ 'operation' => 'update-notification', 'args' => [ 'form_id' => $this->form_id, 'notification_id' => $nid, 'subject' => 'Live subject' ] ]));
        $this->assertTrue($edit['recoverable']);
        $this->assertSame('Live subject', $this->settings()['notifications'][ $nid ]['subject']);
        $this->assertSame('Live subject', $this->ok($this->integration->handle_read([ 'operation' => 'list-notifications', 'args' => [ 'form_id' => $this->form_id ] ]))['result']['notifications'][0]['subject'], 'WPForms\' own handler reads the edit');

        $to = $this->ok($this->integration->handle_write([ 'operation' => 'set-notification-recipients', 'confirm' => true, 'args' => [ 'form_id' => $this->form_id, 'notification_id' => $nid, 'to' => 'boss@example.test' ] ]));
        $this->assertSame('boss@example.test', $this->settings()['notifications'][ $nid ]['email']);

        $confirmations = $this->ok($this->integration->handle_read([ 'operation' => 'list-confirmations', 'args' => [ 'form_id' => $this->form_id ] ]))['result']['confirmations'];
        $cid           = $confirmations[0]['id'];
        $page          = self::factory()->post->create([ 'post_type' => 'page', 'post_status' => 'publish' ]);
        $conf          = $this->ok($this->integration->handle_write([ 'operation' => 'update-confirmation', 'confirm' => true, 'args' => [ 'form_id' => $this->form_id, 'confirmation_id' => $cid, 'type' => 'page', 'page' => $page ] ]));
        $this->assertSame('page', $this->settings()['confirmations'][ $cid ]['type']);

        foreach ([ $conf, $to, $edit ] as $out) {
            $undone = ( new Rollback_Operation() )->handle([ 'operation_id' => $out['operation_id'] ]);
            $this->assertTrue($undone['restored'], wp_json_encode($undone));
        }
        $this->assertSame($old, $this->settings()['notifications'][ $nid ]['subject'] ?? '');
        $this->assertNotSame('boss@example.test', $this->settings()['notifications'][ $nid ]['email'] ?? '');
        $this->assertSame('message', $this->settings()['confirmations'][ $cid ]['type']);
    }
}
