<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * Issue #477: the FluentCRM and MailPoet ops against the real plugins.
 *
 * It runs only in the local gate's live CRM leg (bin/test-local.sh, or
 * bin/test-local.sh --live-crm alone), which sets WPMCP_LIVE_CRM=1, installs
 * FluentCRM and MailPoet from wordpress.org on a separate WordPress install,
 * loads them in tests/bootstrap.php and builds their tables with their own
 * activation routines.
 *
 * Every write runs through the plugin's own API, so its hooks fire and its
 * opt-in rules apply, and the result is read back through that API, not the
 * tables: a FluentCRM contact through FluentCrmApi('contacts') and its
 * models, a MailPoet subscriber through \MailPoet\API\API::MP('v1').
 * rollback-operation must then put the plugin's own view back, and never set
 * a contact who unsubscribed back to subscribed.
 *
 * Everywhere else the plugins are absent and the test is skipped; the live
 * leg runs with --fail-on-skipped so it cannot pass vacuously.
 *
 * @group crm-live
 */
class CrmLiveTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! defined('FLUENTCRM') || ! function_exists('FluentCrmApi') || ! defined('MAILPOET_VERSION') || ! class_exists('\\MailPoet\\API\\API')) {
            $this->markTestSkipped('Needs the real FluentCRM and MailPoet (bin/test-local.sh --live-crm, WPMCP_LIVE_CRM=1).');
        }
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        reset_phpmailer_instance();
    }

    protected function tearDown(): void
    {
        Gate::set_pro_for_tests(null);
        foreach ([ 'wpmcp_enable_crm_send', 'wpmcp_crm_direct_subscribe', 'pre_wp_mail', 'pre_http_request', 'mailpoet_mailer_smtp_instance' ] as $filter) {
            remove_all_filters($filter);
        }
        parent::tearDown();
    }

    /**
     * Every email wp_mail() is asked to send, captured instead of sent.
     *
     * @return \ArrayObject<int, array{to: string, subject: string}>
     */
    private function capture_wp_mail(): \ArrayObject
    {
        $mail = new \ArrayObject();
        add_filter('pre_wp_mail', static function ($pre, array $atts) use ($mail) {
            foreach ((array) $atts['to'] as $to) {
                $mail[] = [ 'to' => (string) $to, 'subject' => (string) $atts['subject'] ];
            }
            return true;
        }, 10, 2);
        return $mail;
    }

    /** No request leaves the test, a plugin's own background ping included. */
    private function block_http(): void
    {
        add_filter('pre_http_request', static fn () => [ 'headers' => [], 'body' => '', 'response' => [ 'code' => 200, 'message' => 'OK' ], 'cookies' => [], 'filename' => null ], 10, 0);
    }

    /** The warnings of the last restore, as one string. */
    private static function warnings(): string
    {
        return implode(' ', Rollback_Service::take_warnings());
    }

    private function call(string $half, string $op, array $args = [], bool $confirm = false): array
    {
        $call = [ 'operation' => $op, 'args' => $args ] + ($confirm ? [ 'confirm' => true ] : []);
        $out  = 'read' === $half ? (new Plugin_Data_Integration())->handle_read($call) : (new Plugin_Data_Integration())->handle_write($call);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out;
    }

    private function refused(string $op, array $args): string
    {
        $out = (new Plugin_Data_Integration())->handle_write([ 'operation' => $op, 'args' => $args ]);
        return (string) ($out['error']['code'] ?? '');
    }

    /** The contact as FluentCRM's own model loads it. */
    private function fluentcrm(int $id): ?\FluentCrm\App\Models\Subscriber
    {
        return \FluentCrm\App\Models\Subscriber::with([ 'lists', 'tags' ])->find($id);
    }

    public function test_fluentcrm_ops_run_through_fluentcrm_and_roll_back(): void
    {
        $this->assertTrue((new Plugin_Data_Integration())->is_available());
        fluentcrm_update_option('contact_custom_fields', [ [ 'slug' => 'nickname', 'label' => 'Nickname', 'type' => 'text' ] ]);

        $list = $this->call('write', 'fluentcrm-create-list', [ 'title' => 'Live newsletter' ])['result']['list']['id'];
        $tag  = $this->call('write', 'fluentcrm-create-tag', [ 'title' => 'Live VIP' ])['result']['tag']['id'];
        $this->assertSame('Live newsletter', \FluentCrm\App\Models\Lists::find($list)->title);

        $created  = did_action('fluent_crm/contact_created');
        $to_lists = did_action('fluent_crm/contact_added_to_lists');
        $to_tags  = did_action('fluent_crm/contact_added_to_tags');
        $out      = $this->call('write', 'fluentcrm-create-contact', [ 'email' => 'live-fc@example.com', 'first_name' => 'Live', 'lists' => [ $list ], 'tags' => [ $tag ], 'custom_fields' => [ 'nickname' => 'Lively' ] ]);
        $id       = $out['result']['contact']['id'];
        $this->assertSame($created + 1, did_action('fluent_crm/contact_created'), 'FluentCRM fired its own create hook');
        $this->assertSame($to_lists + 1, did_action('fluent_crm/contact_added_to_lists'), 'and its list trigger');
        $this->assertSame($to_tags + 1, did_action('fluent_crm/contact_added_to_tags'), 'and its tag trigger');

        $contact = FluentCrmApi('contacts')->getContact('live-fc@example.com');
        $this->assertSame($id, (int) $contact->id);
        $this->assertSame('pending', $contact->status, 'a new contact waits for double opt-in');
        $this->assertSame([ $list ], array_map('intval', $contact->lists->pluck('id')->toArray()));
        $this->assertSame([ $tag ], array_map('intval', $contact->tags->pluck('id')->toArray()));
        $this->assertSame('Lively', $contact->custom_fields()['nickname'] ?? null);
        $this->assertNotEmpty(fluentcrm_get_subscriber_meta($id, '_last_double_optin_timestamp'), 'FluentCRM sent its double opt-in email');

        $read = $this->call('read', 'fluentcrm-list-contacts', [ 'list_id' => $list ])['result'];
        $this->assertSame([ $id ], array_column($read['contacts'], 'id'));
        $this->assertSame(FLUENTCRM_PLUGIN_VERSION, $this->call('read', 'fluentcrm-summary')['result']['version']);

        // Unsubscribe, then undo: FluentCRM keeps the contact unsubscribed.
        $changed = did_action('fluent_crm/subscriber_status_changed');
        $unsub   = $this->call('write', 'fluentcrm-update-contact', [ 'id' => $id, 'status' => 'unsubscribed', 'first_name' => 'Renamed' ]);
        $this->assertSame($changed + 1, did_action('fluent_crm/subscriber_status_changed'));
        $this->assertSame('unsubscribed', $this->fluentcrm($id)->status);
        $this->assertSame('contact_suppressed', $this->refused('fluentcrm-update-contact-lists', [ 'id' => $id, 'add' => [ $list ] ]));
        $this->assertTrue(Rollback_Service::restore_operation((string) $unsub['operation_id']));
        $this->assertSame('unsubscribed', $this->fluentcrm($id)->status, 'never back to a mailable status');
        $this->assertSame('Live', $this->fluentcrm($id)->first_name, 'the rest of the write is undone');

        // Untag, then undo: the tag is back.
        $untag = $this->call('write', 'fluentcrm-update-contact-tags', [ 'id' => $id, 'remove' => [ $tag ] ]);
        $this->assertSame([], $this->fluentcrm($id)->tags->pluck('id')->toArray());
        $this->assertTrue(Rollback_Service::restore_operation((string) $untag['operation_id']));
        $this->assertSame([ $tag ], array_map('intval', $this->fluentcrm($id)->tags->pluck('id')->toArray()));

        // Delete through FluentCRM, then undo: the contact, its lists, tags and fields are back.
        $deleted = $this->call('write', 'fluentcrm-delete-contact', [ 'id' => $id ], true);
        $this->assertNull($this->fluentcrm($id));
        $this->assertTrue(Rollback_Service::restore_operation((string) $deleted['operation_id']));
        $back = $this->fluentcrm($id);
        $this->assertSame('live-fc@example.com', $back->email);
        $this->assertSame([ $list ], array_map('intval', $back->lists->pluck('id')->toArray()));
        $this->assertSame([ $tag ], array_map('intval', $back->tags->pluck('id')->toArray()));
        $this->assertSame('Lively', $back->custom_fields()['nickname'] ?? null);

        // Delete the list through FluentCRM, then undo: list and membership are back.
        $gone = $this->call('write', 'fluentcrm-delete-list', [ 'id' => $list ], true);
        $this->assertNull(\FluentCrm\App\Models\Lists::find($list));
        $this->assertSame([], $this->fluentcrm($id)->lists->pluck('id')->toArray(), 'FluentCRM dropped the memberships with it');
        $this->assertTrue(Rollback_Service::restore_operation((string) $gone['operation_id']));
        $this->assertSame([ $list ], array_map('intval', $this->fluentcrm($id)->lists->pluck('id')->toArray()));

        // Undo a create: the contact is gone again.
        $other = $this->call('write', 'fluentcrm-create-contact', [ 'email' => 'live-fc-2@example.com' ]);
        $this->assertTrue(Rollback_Service::restore_operation((string) $other['operation_id']));
        $this->assertFalse((bool) FluentCrmApi('contacts')->getContact('live-fc-2@example.com'));
    }

    public function test_mailpoet_ops_run_through_mailpoet_and_roll_back(): void
    {
        $mp   = \MailPoet\API\API::MP('v1');
        $list = $this->call('write', 'mailpoet-create-list', [ 'title' => 'Live list' ])['result']['list']['id'];
        $tag  = $this->call('write', 'mailpoet-create-tag', [ 'title' => 'Live tag' ])['result']['tag']['id'];
        $this->assertContains((string) $list, array_map('strval', array_column($mp->getLists(), 'id')));

        $out = $this->call('write', 'mailpoet-create-contact', [ 'email' => 'live-mp@example.com', 'first_name' => 'Live', 'lists' => [ $list ], 'tags' => [ $tag ] ]);
        $id  = $out['result']['contact']['id'];
        $sub = $mp->getSubscriber('live-mp@example.com');
        $this->assertSame((string) $id, (string) $sub['id']);
        $this->assertSame('unconfirmed', $sub['status'], 'MailPoet sign-up confirmation is on by default');
        $this->assertSame([ (string) $list ], array_map('strval', array_column($sub['subscriptions'], 'segment_id')));
        $this->assertSame([ 'Live tag' ], array_column($sub['tags'], 'name'));
        $this->assertSame(true, $this->call('read', 'mailpoet-summary')['result']['opt_in']['signup_confirmation']);

        // A tag, then the person unsubscribes through MailPoet, then the tag is undone.
        $other = $this->call('write', 'mailpoet-create-tag', [ 'title' => 'Second tag' ])['result']['tag']['id'];
        $tagged = $this->call('write', 'mailpoet-update-contact-tags', [ 'id' => $id, 'add' => [ $other ] ]);
        $mp->unsubscribe($id);
        $this->assertTrue(Rollback_Service::restore_operation((string) $tagged['operation_id']));
        $after = $mp->getSubscriber($id);
        $this->assertSame('unsubscribed', $after['status'], 'the person\'s unsubscribe stands');
        $this->assertSame([ 'Live tag' ], array_column($after['tags'], 'name'), 'the tag write is undone');
        $this->assertSame('contact_suppressed', $this->refused('mailpoet-update-contact-lists', [ 'id' => $id, 'add' => [ $list ] ]), 'MailPoet would have resubscribed them');

        // Trash through MailPoet, then undo.
        $trashed = $this->call('write', 'mailpoet-delete-contact', [ 'id' => $id ], true);
        $this->assertNotNull($mp->getSubscriber($id)['deleted_at']);
        $this->assertTrue(Rollback_Service::restore_operation((string) $trashed['operation_id']));
        $this->assertNull($mp->getSubscriber($id)['deleted_at']);

        // Delete the list through MailPoet, then undo: the list and the membership are back.
        $gone = $this->call('write', 'mailpoet-delete-list', [ 'id' => $list ], true);
        $this->assertNotContains((string) $list, array_map('strval', array_column($mp->getLists(), 'id')));
        $this->assertTrue(Rollback_Service::restore_operation((string) $gone['operation_id']));
        $this->assertContains((string) $list, array_map('strval', array_column($mp->getLists(), 'id')));
        $this->assertSame([ (string) $list ], array_map('strval', array_column($mp->getSubscriber($id)['subscriptions'], 'segment_id')));

        // A fresh subscriber added to the list, removed, and the removal undone.
        $create  = $this->call('write', 'mailpoet-create-contact', [ 'email' => 'live-mp-2@example.com', 'lists' => [ $list ] ]);
        $fresh   = $create['result']['contact']['id'];
        $removed = $this->call('write', 'mailpoet-update-contact-lists', [ 'id' => $fresh, 'remove' => [ $list ] ]);
        $this->assertSame('unsubscribed', $mp->getSubscriber($fresh)['subscriptions'][0]['status']);
        $this->assertTrue(Rollback_Service::restore_operation((string) $removed['operation_id']));
        $this->assertSame('subscribed', $mp->getSubscriber($fresh)['subscriptions'][0]['status']);

        // Undo the fresh create: MailPoet no longer knows the address.
        Rollback_Service::take_warnings();
        $this->assertTrue(Rollback_Service::restore_operation((string) $create['operation_id']));
        $this->assertSame([], Rollback_Service::take_warnings());
        try {
            $mp->getSubscriber('live-mp-2@example.com');
            $this->fail('the created subscriber is gone');
        } catch (\MailPoet\API\MP\v1\APIException $e) {
            $this->assertSame(\MailPoet\API\MP\v1\APIException::SUBSCRIBER_NOT_EXISTS, $e->getCode());
        }

        // Undo the first create: that subscriber unsubscribed since, so it stays.
        Rollback_Service::restore_operation((string) $out['operation_id']);
        $this->assertStringContainsString('left in place', implode(' ', Rollback_Service::take_warnings()));
        $this->assertSame('unsubscribed', $mp->getSubscriber($id)['status']);
    }
    public function test_fluentcrm_campaigns_imports_and_automations_run_through_fluentcrm(): void
    {
        $mail = $this->capture_wp_mail();
        $this->block_http();
        add_filter('wpmcp_crm_direct_subscribe', '__return_true');
        $admin = wp_get_current_user()->user_email;

        $list = $this->call('write', 'fluentcrm-create-list', [ 'title' => 'Live campaign list' ])['result']['list']['id'];
        $this->call('write', 'fluentcrm-create-contact', [ 'email' => 'live-ada@example.com', 'first_name' => 'Ada', 'status' => 'subscribed', 'lists' => [ $list ] ])['result']['contact']['id'];
        $this->call('write', 'fluentcrm-create-contact', [ 'email' => 'live-bob@example.com', 'lists' => [ $list ] ]);
        FluentCrmApi('contacts')->createOrUpdate([ 'email' => 'live-gone@example.com', 'status' => 'unsubscribed' ]);

        // Bulk import: a new contact waits for double opt-in, an unsubscribed one is left alone.
        $import = $this->call('write', 'fluentcrm-import-contacts', [ 'contacts' => [ [ 'email' => 'live-new@example.com', 'first_name' => 'New' ], [ 'email' => 'live-gone@example.com' ] ], 'lists' => [ $list ] ])['result'];
        $this->assertSame([ 'live-new@example.com' ], array_column($import['created'], 'email'));
        $this->assertSame([ 'live-gone@example.com' => 'contact_suppressed' ], array_column($import['skipped'], 'reason', 'email'));
        $new = FluentCrmApi('contacts')->getContact('live-new@example.com');
        $this->assertSame([ 'pending', [ $list ] ], [ $new->status, array_map('intval', $new->lists->pluck('id')->toArray()) ]);
        $this->assertNotEmpty(fluentcrm_get_subscriber_meta((int) $new->id, '_last_double_optin_timestamp'), 'FluentCRM sent its double opt-in email');
        $gone = FluentCrmApi('contacts')->getContact('live-gone@example.com');
        $this->assertSame([ 'unsubscribed', [] ], [ $gone->status, $gone->lists->pluck('id')->toArray() ]);

        // Drafts through FluentCRM: create, edit and duplicate, each undone.
        $created  = $this->call('write', 'fluentcrm-create-campaign', [ 'title' => 'Live October', 'subject' => 'Live hello', 'body' => '<p>Hello</p>', 'lists' => [ $list ] ]);
        $id       = $created['result']['campaign']['id'];
        $campaign = \FluentCrm\App\Models\Campaign::find($id);
        $this->assertSame([ 'draft', 'Live hello' ], [ $campaign->status, $campaign->email_subject ]);
        $this->assertSame(1, $campaign->getSubscribersModel()->count(), 'FluentCRM counts the one subscribed contact');
        $this->assertSame(1, $created['result']['campaign']['recipients']);

        $edit = $this->call('write', 'fluentcrm-update-campaign', [ 'id' => $id, 'subject' => 'Live changed' ]);
        $this->assertSame('Live changed', \FluentCrm\App\Models\Campaign::find($id)->email_subject);
        $this->assertTrue(Rollback_Service::restore_operation((string) $edit['operation_id']));
        $this->assertSame('Live hello', \FluentCrm\App\Models\Campaign::find($id)->email_subject);

        $copy = $this->call('write', 'fluentcrm-duplicate-campaign', [ 'id' => $id ]);
        $dup  = \FluentCrm\App\Models\Campaign::find($copy['result']['campaign']['id']);
        $this->assertSame('draft', $dup->status);
        $this->assertTrue(Rollback_Service::restore_operation((string) $copy['operation_id']));
        $this->assertNull(\FluentCrm\App\Models\Campaign::find($dup->id));

        // A test email goes to the current user only, through FluentCRM's mailer.
        $test = $this->call('write', 'fluentcrm-send-test-campaign', [ 'id' => $id ]);
        $this->assertSame([ $admin ], array_column((array) $mail, 'to'));
        $this->assertStringContainsString('Live hello', $mail[0]['subject']);
        $this->assertFalse(Rollback_Service::restore_operation((string) $test['operation_id']));
        $this->assertStringContainsString('cannot be unsent', self::warnings());

        // Sending is off until the site enables it, then asks with the count.
        $this->assertSame('operation_disabled', $this->refused('fluentcrm-send-campaign', [ 'id' => $id ]));
        add_filter('wpmcp_enable_crm_send', '__return_true');
        $ask = (new Plugin_Data_Integration())->handle_write([ 'operation' => 'fluentcrm-send-campaign', 'args' => [ 'id' => $id ] ]);
        $this->assertSame([ 'confirmation_required', 1 ], [ $ask['error']['code'], $ask['error']['data']['recipients'] ]);
        $sent = $this->call('write', 'fluentcrm-send-campaign', [ 'id' => $id ], true);
        $this->assertSame('processing', \FluentCrm\App\Models\Campaign::find($id)->status);

        // FluentCRM's own processor and mailer deliver it, to the subscribed contact only.
        $mail->exchangeArray([]);
        (new \FluentCrm\App\Services\CampaignProcessor($id))->processEmails(50, 20);
        (new \FluentCrm\App\Services\Libs\Mailer\Handler())->handle();
        $this->assertSame([ 'live-ada@example.com' ], array_column((array) $mail, 'to'));
        $this->assertFalse(Rollback_Service::restore_operation((string) $sent['operation_id']));
        $this->assertStringContainsString('cannot be unsent', self::warnings());

        // An automation published, then undone.
        $funnel = \FluentCrm\App\Models\Funnel::create([ 'title' => 'Live welcome', 'trigger_name' => 'fluentcrm_contact_added_to_lists', 'status' => 'draft', 'settings' => [], 'conditions' => [] ]);
        $on     = $this->call('write', 'fluentcrm-set-automation-status', [ 'id' => (int) $funnel->id, 'status' => 'published' ]);
        $this->assertSame('published', \FluentCrm\App\Models\Funnel::find($funnel->id)->status);
        $this->assertTrue(Rollback_Service::restore_operation((string) $on['operation_id']));
        $this->assertSame('draft', \FluentCrm\App\Models\Funnel::find($funnel->id)->status);
    }

    public function test_mailpoet_newsletters_imports_and_automations_run_through_mailpoet(): void
    {
        $this->block_http();
        $settings = \MailPoet\Settings\SettingsController::getInstance();
        $settings->set('sender', [ 'name' => 'Live', 'address' => 'sender@example.com' ]);
        $settings->set('mta_group', 'smtp');
        $settings->set('mta', [ 'method' => 'SMTP', 'host' => '127.0.0.1', 'port' => 2525, 'authentication' => '-1', 'encryption' => '', 'frequency' => [ 'emails' => 100, 'interval' => 5 ] ]);
        Capturing_PHPMailer::$sent = [];
        add_filter('mailpoet_mailer_smtp_instance', static fn () => new Capturing_PHPMailer(true));
        $mp   = \MailPoet\API\API::MP('v1');
        $list = $this->call('write', 'mailpoet-create-list', [ 'title' => 'Live newsletter list' ])['result']['list']['id'];

        // Ada subscribes with sign-up confirmation off; the import runs with it on.
        $settings->set('signup_confirmation.enabled', false);
        $ada = $this->call('write', 'mailpoet-create-contact', [ 'email' => 'live-mp-ada@example.com', 'lists' => [ $list ] ])['result']['contact']['id'];
        $this->assertSame('subscribed', $mp->getSubscriber($ada)['status']);
        $settings->set('signup_confirmation.enabled', true);
        $left = $mp->addSubscriber([ 'email' => 'live-mp-left@example.com' ], [ $list ]);
        $mp->unsubscribeFromList($left['id'], $list);

        $import = $this->call('write', 'mailpoet-import-contacts', [ 'contacts' => [ [ 'email' => 'live-mp-new@example.com' ], [ 'email' => 'live-mp-left@example.com' ] ], 'lists' => [ $list ], 'update_existing' => true ])['result'];
        $this->assertSame([ 'live-mp-new@example.com' ], array_column($import['created'], 'email'));
        $this->assertSame([ 'live-mp-left@example.com' => 'contact_suppressed' ], array_column($import['skipped'], 'reason', 'email'));
        $this->assertSame('unconfirmed', $mp->getSubscriber('live-mp-new@example.com')['status']);
        $this->assertSame('unsubscribed', $mp->getSubscriber('live-mp-left@example.com')['subscriptions'][0]['status'], 'MailPoet keeps them off the list');

        // Drafts through MailPoet: create, edit and duplicate, each undone.
        $created    = $this->call('write', 'mailpoet-create-campaign', [ 'subject' => 'Live hello', 'body' => '<p>Hello</p>', 'lists' => [ $list ] ]);
        $id          = $created['result']['campaign']['id'];
        $newsletters = \MailPoet\DI\ContainerWrapper::getInstance()->get(\MailPoet\Newsletter\NewslettersRepository::class);
        $entity      = $newsletters->findOneById($id);
        $this->assertSame([ 'draft', 'Live hello', [ $list ] ], [ $entity->getStatus(), $entity->getSubject(), array_map('intval', $entity->getSegmentIds()) ]);
        $this->assertSame(1, $created['result']['campaign']['recipients']);

        $edit = $this->call('write', 'mailpoet-update-campaign', [ 'id' => $id, 'subject' => 'Live changed' ]);
        \WPMCP\Safety\Crm_Rows_Snapshot::flush('mailpoet');
        $this->assertSame('Live changed', $newsletters->findOneById($id)->getSubject());
        $this->assertTrue(Rollback_Service::restore_operation((string) $edit['operation_id']));
        $this->assertSame('Live hello', $newsletters->findOneById($id)->getSubject());

        $copy = $this->call('write', 'mailpoet-duplicate-campaign', [ 'id' => $id ]);
        $dup  = $copy['result']['campaign']['id'];
        $this->assertSame('draft', $newsletters->findOneById($dup)->getStatus());
        $this->assertTrue(Rollback_Service::restore_operation((string) $copy['operation_id']));
        $this->assertNull($newsletters->findOneById($dup));

        // A test email goes to the current user only, through MailPoet's mailer.
        $test = $this->call('write', 'mailpoet-send-test-campaign', [ 'id' => $id ]);
        $this->assertSame([ wp_get_current_user()->user_email ], array_column(Capturing_PHPMailer::$sent, 'to'));
        $this->assertFalse(Rollback_Service::restore_operation((string) $test['operation_id']));
        $this->assertStringContainsString('cannot be unsent', self::warnings());

        // Sending: off, then asked with the count, then queued by MailPoet and sent by its worker.
        $this->assertSame('operation_disabled', $this->refused('mailpoet-send-campaign', [ 'id' => $id ]));
        add_filter('wpmcp_enable_crm_send', '__return_true');
        $ask = (new Plugin_Data_Integration())->handle_write([ 'operation' => 'mailpoet-send-campaign', 'args' => [ 'id' => $id ] ]);
        $this->assertSame([ 'confirmation_required', 1 ], [ $ask['error']['code'], $ask['error']['data']['recipients'] ]);
        $sent = $this->call('write', 'mailpoet-send-campaign', [ 'id' => $id ], true);
        $this->assertSame('sending', $sent['result']['campaign']['status']);
        Capturing_PHPMailer::$sent = [];
        \MailPoet\DI\ContainerWrapper::getInstance()->get(\MailPoet\Cron\Workers\SendingQueue\SendingQueue::class)->process();
        $this->assertSame([ 'live-mp-ada@example.com' ], array_column(Capturing_PHPMailer::$sent, 'to'), 'only the subscribed member is mailed');
        $this->assertFalse(Rollback_Service::restore_operation((string) $sent['operation_id']));
        $this->assertStringContainsString('cannot be unsent', self::warnings());

        // An automation deactivated, then undone.
        $container  = \MailPoet\DI\ContainerWrapper::getInstance();
        $automation = $container->get(\MailPoet\Automation\Engine\Builder\CreateAutomationFromTemplateController::class)->createAutomation('subscriber-welcome-email');
        $automation->setStatus('active');
        $container->get(\MailPoet\Automation\Engine\Storage\AutomationStorage::class)->updateAutomation($automation);
        $off = $this->call('write', 'mailpoet-set-automation-status', [ 'id' => $automation->getId(), 'status' => 'deactivating' ]);
        $storage = $container->get(\MailPoet\Automation\Engine\Storage\AutomationStorage::class);
        $this->assertSame('deactivating', $storage->getAutomation($automation->getId())->getStatus());
        $this->assertTrue(Rollback_Service::restore_operation((string) $off['operation_id']));
        $this->assertSame('active', $storage->getAutomation($automation->getId())->getStatus());
    }
}

if (! class_exists(Capturing_PHPMailer::class) && class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
    /** A PHPMailer that records each message instead of sending it. */
    class Capturing_PHPMailer extends \PHPMailer\PHPMailer\PHPMailer
    {
        /** @var array<int, array{to: string, subject: string}> */
        public static array $sent = [];

        public function send()
        {
            foreach ($this->getToAddresses() as $to) {
                self::$sent[] = [ 'to' => (string) $to[0], 'subject' => (string) $this->Subject ];
            }
            return true;
        }
    }
}
