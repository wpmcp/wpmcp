<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Gravity_Forms_Integration;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Rollback_Operation;

require_once __DIR__ . '/../../support/gfapi-stub.php';

/**
 * Issue #674 on Gravity Forms: list-confirmations and the three edits. The
 * notifications and confirmations live in a real gf_form_meta row (the
 * Gravity Forms 2.9 layout), written through GFFormsModel::update_form_meta(),
 * so the db_rows snapshot and its rollback run for real.
 */
class GravityFormsConfigTest extends \WP_UnitTestCase
{
    private Gravity_Forms_Integration $integration;

    public static function set_up_before_class(): void
    {
        parent::set_up_before_class();
        \GFAPI::install_table();
        \GFAPI::install_meta_table();
    }

    public static function tear_down_after_class(): void
    {
        \GFAPI::uninstall_table();
        parent::tear_down_after_class();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        \GFAPI::reset();
        \GFFormsModel::$meta_updates = [];
        \GFAPI::$forms = [ 1 => [ 'id' => 1, 'title' => 'Contact', 'is_active' => true, 'fields' => [ [ 'id' => 2, 'type' => 'email', 'label' => 'Email' ] ] ] ];
        \GFAPI::seed_form_meta(
            1,
            [
                'a1' => [ 'id' => 'a1', 'name' => 'Admin Notification', 'event' => 'form_submission', 'toType' => 'email', 'to' => '{admin_email}', 'subject' => 'New submission', 'message' => '{all_fields}', 'from' => '{admin_email}', 'fromName' => 'Site' ],
                'b2' => [ 'id' => 'b2', 'name' => 'Visitor copy', 'event' => 'form_submission', 'toType' => 'field', 'to' => '2', 'subject' => 'Thanks', 'message' => 'Hi', 'isActive' => false ],
            ],
            [
                'c1' => [ 'id' => 'c1', 'name' => 'Default Confirmation', 'isDefault' => true, 'type' => 'message', 'message' => 'Thanks!', 'url' => '', 'pageId' => '', 'queryString' => '' ],
                'c2' => [ 'id' => 'c2', 'name' => 'Partner', 'type' => 'redirect', 'message' => '', 'url' => 'https://partner.example.test/thanks', 'pageId' => '', 'queryString' => 'email={Email:2}' ],
            ]
        );
        $this->integration = new Gravity_Forms_Integration();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_enable_forms_write');
        remove_all_filters('wpmcp_forms_allow_redirect_smart_tags');
        \GFAPI::reset();
        parent::tearDown();
    }

    private function write(string $op, array $args, bool $confirm = false): array
    {
        $payload = [ 'operation' => $op, 'args' => $args ];
        if ($confirm || 'update-confirmation' === $op) {
            $payload['confirm'] = true;
        }
        return $this->integration->handle_write($payload);
    }

    private function meta(): array
    {
        return \GFAPI::meta_row(1);
    }

    public function test_catalog_lists_the_new_operations(): void
    {
        $names = array_column($this->integration->handle_read([ 'operation' => 'list-operations' ])['result']['operations'], 'name');
        foreach ([ 'list-confirmations', 'update-notification', 'set-notification-recipients', 'update-confirmation' ] as $op) {
            $this->assertContains($op, $names, $op);
        }
    }

    public function test_writes_are_off_until_opted_in(): void
    {
        foreach ([
            [ 'update-notification', [ 'form_id' => 1, 'notification_id' => 'a1', 'subject' => 'Hi' ] ],
            [ 'set-notification-recipients', [ 'form_id' => 1, 'notification_id' => 'a1', 'to' => 'a@example.test' ] ],
            [ 'update-confirmation', [ 'form_id' => 1, 'confirmation_id' => 'c1', 'message' => 'Hi' ] ],
        ] as [ $op, $args ]) {
            $out = $this->write($op, $args, true);
            $this->assertSame('opt_in_required', $out['error']['code'], $op);
            $this->assertStringContainsString('wpmcp_enable_forms_write', $out['error']['message']);
        }
        $this->assertSame('New submission', $this->meta()['notifications']['a1']['subject']);
        $this->assertSame([], \GFFormsModel::$meta_updates);
    }

    public function test_lists_confirmations(): void
    {
        $list = $this->integration->handle_read([ 'operation' => 'list-confirmations', 'args' => [ 'form_id' => 1 ] ])['result']['confirmations'];
        $this->assertSame('c1', $list[0]['id']);
        $this->assertSame('message', $list[0]['type']);
        $this->assertTrue($list[0]['is_default']);
        $this->assertSame('redirect', $list[1]['type']);
        $this->assertSame('https://partner.example.test/thanks', $list[1]['redirect']);
        $this->assertSame('email={Email:2}', $list[1]['query_string']);

        $this->assertNull($this->integration->handle_read([ 'operation' => 'list-confirmations', 'args' => [ 'form_id' => 99 ] ])['result']['confirmations']);
    }

    public function test_update_notification_edits_fields_through_update_form_meta_and_undoes(): void
    {
        add_filter('wpmcp_enable_forms_write', '__return_true');
        $out = $this->write('update-notification', [ 'form_id' => 1, 'notification_id' => 'b2', 'enabled' => true, 'subject' => 'Back on', 'sender_name' => 'Team', 'replyto' => '{Email:2}' ]);

        $this->assertArrayNotHasKey('error', $out);
        $this->assertTrue($out['recoverable']);
        $this->assertTrue($out['result']['changed']);
        $this->assertSame([ 'before' => false, 'after' => true ], $out['result']['changes']['enabled']);
        $this->assertSame([ [ 1, 'notifications' ] ], \GFFormsModel::$meta_updates);
        $n = $this->meta()['notifications'];
        $this->assertTrue($n['b2']['isActive']);
        $this->assertSame('Back on', $n['b2']['subject']);
        $this->assertSame('Team', $n['b2']['fromName']);
        $this->assertSame('{Email:2}', $n['b2']['replyTo']);
        $this->assertSame('2', $n['b2']['to'], 'Recipient untouched');
        $this->assertSame('New submission', $n['a1']['subject'], 'Other notifications untouched');

        ( new Rollback_Operation() )->handle([ 'operation_id' => $out['operation_id'] ]);
        $n = $this->meta()['notifications'];
        $this->assertFalse($n['b2']['isActive']);
        $this->assertSame('Thanks', $n['b2']['subject']);
        $this->assertArrayNotHasKey('fromName', $n['b2']);
    }

    public function test_update_notification_refusals(): void
    {
        add_filter('wpmcp_enable_forms_write', '__return_true');
        $this->assertSame('notification_not_found', $this->write('update-notification', [ 'form_id' => 1, 'notification_id' => 'zz', 'subject' => 'x' ])['error']['code']);
        $this->assertSame('form_not_found', $this->write('update-notification', [ 'form_id' => 99, 'notification_id' => 'a1', 'subject' => 'x' ])['error']['code']);
        $this->assertSame('invalid_notification', $this->write('update-notification', [ 'form_id' => 1, 'notification_id' => 'a1', 'message' => ' ' ])['error']['code']);
        $this->assertSame('invalid_notification', $this->write('update-notification', [ 'form_id' => 1, 'notification_id' => 'a1', 'subject' => "Hi\nBcc: x@example.test" ])['error']['code']);
        $this->assertSame('invalid_notification', $this->write('update-notification', [ 'form_id' => 1, 'notification_id' => 'a1', 'sender_address' => 'nope' ])['error']['code']);
        $same = $this->write('update-notification', [ 'form_id' => 1, 'notification_id' => 'a1', 'subject' => 'New submission' ]);
        $this->assertFalse($same['result']['changed']);
        $this->assertSame([], \GFFormsModel::$meta_updates);
    }

    public function test_update_notification_is_refused_below_manage_options(): void
    {
        add_filter('wpmcp_enable_forms_write', '__return_true');
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        $out = $this->write('update-notification', [ 'form_id' => 1, 'notification_id' => 'a1', 'subject' => 'x' ]);
        $this->assertSame('operation_denied', $out['error']['code']);
        $this->assertSame('New submission', $this->meta()['notifications']['a1']['subject']);
    }

    public function test_recipients_need_confirm_show_before_and_after_and_undo(): void
    {
        add_filter('wpmcp_enable_forms_write', '__return_true');
        $args    = [ 'form_id' => 1, 'notification_id' => 'b2', 'to' => 'boss@example.test, {admin_email}', 'bcc' => 'audit@example.test' ];
        $preview = $this->write('set-notification-recipients', $args);
        $this->assertSame('confirmation_required', $preview['error']['code']);
        $this->assertSame([ 'to_type' => 'field', 'to' => '2', 'bcc' => '' ], $preview['error']['data']['before']);
        $this->assertSame([ 'to_type' => 'email', 'to' => 'boss@example.test, {admin_email}', 'bcc' => 'audit@example.test' ], $preview['error']['data']['after']);
        $this->assertSame('2', $this->meta()['notifications']['b2']['to']);

        $out = $this->write('set-notification-recipients', $args, true);
        $this->assertTrue($out['result']['recipient_changed']);
        $n = $this->meta()['notifications']['b2'];
        $this->assertSame('email', $n['toType']);
        $this->assertSame('boss@example.test, {admin_email}', $n['to']);
        $this->assertSame('audit@example.test', $n['bcc']);

        ( new Rollback_Operation() )->handle([ 'operation_id' => $out['operation_id'] ]);
        $n = $this->meta()['notifications']['b2'];
        $this->assertSame('field', $n['toType']);
        $this->assertSame('2', $n['to']);
        $this->assertArrayNotHasKey('bcc', $n);

        foreach ([ 'nope', '', "a@example.test\nBcc: b@example.test" ] as $bad) {
            $this->assertSame('invalid_recipients', $this->write('set-notification-recipients', [ 'to' => $bad ] + $args, true)['error']['code'], var_export($bad, true));
        }
        $this->assertSame('invalid_recipients', $this->write('set-notification-recipients', [ 'form_id' => 1, 'notification_id' => 'a1', 'bcc' => 'nope' ], true)['error']['code']);
    }

    public function test_update_confirmation_switches_type_with_validation_and_undoes(): void
    {
        add_filter('wpmcp_enable_forms_write', '__return_true');
        $page  = self::factory()->post->create([ 'post_type' => 'page', 'post_status' => 'publish' ]);
        $draft = self::factory()->post->create([ 'post_type' => 'page', 'post_status' => 'draft' ]);

        $this->assertSame('invalid_confirmation', $this->write('update-confirmation', [ 'form_id' => 1, 'confirmation_id' => 'c1', 'type' => 'page' ])['error']['code']);
        $this->assertSame('invalid_confirmation', $this->write('update-confirmation', [ 'form_id' => 1, 'confirmation_id' => 'c1', 'type' => 'page', 'page' => $draft ])['error']['code']);
        foreach ([ 'javascript:alert(1)', 'ftp://example.test/x', 'not a url' ] as $url) {
            $this->assertSame('invalid_confirmation', $this->write('update-confirmation', [ 'form_id' => 1, 'confirmation_id' => 'c1', 'type' => 'redirect', 'redirect' => $url ])['error']['code'], $url);
        }
        $this->assertSame('confirmation_not_found', $this->write('update-confirmation', [ 'form_id' => 1, 'confirmation_id' => 'zz', 'name' => 'x' ])['error']['code']);

        $without = $this->integration->handle_write([ 'operation' => 'update-confirmation', 'args' => [ 'form_id' => 1, 'confirmation_id' => 'c1', 'type' => 'page', 'page' => $page ] ]);
        $this->assertSame('confirmation_required', $without['error']['code']);
        $this->assertSame('message', $without['error']['data']['before']['type']);
        $this->assertSame('page', $without['error']['data']['after']['type']);

        $out = $this->write('update-confirmation', [ 'form_id' => 1, 'confirmation_id' => 'c1', 'type' => 'page', 'page' => $page ]);
        $this->assertSame([ 'before' => 'message', 'after' => 'page' ], $out['result']['changes']['type']);
        $this->assertSame([ [ 1, 'confirmations' ] ], \GFFormsModel::$meta_updates);
        $c = $this->meta()['confirmations']['c1'];
        $this->assertSame('page', $c['type']);
        $this->assertSame((string) $page, (string) $c['pageId']);
        $this->assertTrue($c['isDefault'], 'Keys the edit does not own stay');

        ( new Rollback_Operation() )->handle([ 'operation_id' => $out['operation_id'] ]);
        $this->assertSame('message', $this->meta()['confirmations']['c1']['type']);
    }

    public function test_message_is_kses_filtered(): void
    {
        add_filter('wpmcp_enable_forms_write', '__return_true');
        $out = $this->write('update-confirmation', [ 'form_id' => 1, 'confirmation_id' => 'c1', 'message' => '<p>Hi {Email:2}</p><script>alert(1)</script>' ]);
        $this->assertSame('<p>Hi {Email:2}</p>alert(1)', $this->meta()['confirmations']['c1']['message']);
        $this->assertTrue($out['result']['changed']);
    }

    public function test_a_redirect_whose_query_string_carries_merge_tags_may_not_point_off_site(): void
    {
        add_filter('wpmcp_enable_forms_write', '__return_true');
        // c2's query string sends {Email:2}; moving the url to another host would send it there.
        $out = $this->write('update-confirmation', [ 'form_id' => 1, 'confirmation_id' => 'c2', 'redirect' => 'https://elsewhere.example.test/x' ]);
        $this->assertSame('invalid_confirmation', $out['error']['code']);
        $this->assertSame('https://partner.example.test/thanks', $this->meta()['confirmations']['c2']['url']);

        // An on-site redirect is fine, and a rename of the existing one does not re-judge it.
        $rename = $this->write('update-confirmation', [ 'form_id' => 1, 'confirmation_id' => 'c2', 'name' => 'Partner site' ]);
        $this->assertArrayNotHasKey('error', $rename);
        $onsite = $this->write('update-confirmation', [ 'form_id' => 1, 'confirmation_id' => 'c2', 'redirect' => home_url('/thanks') ]);
        $this->assertArrayNotHasKey('error', $onsite);
        $this->assertSame(home_url('/thanks'), $this->meta()['confirmations']['c2']['url']);

        // A site that allows smart tags to leave may move it.
        add_filter('wpmcp_forms_allow_redirect_smart_tags', '__return_true');
        $this->assertArrayNotHasKey('error', $this->write('update-confirmation', [ 'form_id' => 1, 'confirmation_id' => 'c2', 'redirect' => 'https://elsewhere.example.test/x' ]));
    }
}
