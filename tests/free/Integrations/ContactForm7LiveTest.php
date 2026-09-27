<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Contact_Form_7_Integration;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Rollback_Operation;

/**
 * Issue #66: "list-forms/get-form/list-entries/get-entry work against a real
 * install of the free-tier target in CI." This is that test.
 *
 * It runs only in the local gate's live forms leg (bin/test-local.sh, or
 * bin/test-local.sh --live-forms alone), which sets WPMCP_LIVE_FORMS=1,
 * installs the real Contact Form 7 and Flamingo from wordpress.org on a
 * separate WordPress install and loads them in tests/bootstrap.php. Nothing
 * here touches a harness double: the form is created with Contact Form 7's
 * own template and saved through its model, the entries are real submissions pushed through WPCF7_ContactForm::submit(), so
 * Flamingo stores them and Contact Form 7 writes the form's channel binding
 * itself, exactly as on a live site. The adapter then has to find them.
 *
 * Everywhere else (the main suite) the plugins are absent and the test is
 * skipped; the live leg runs with --fail-on-skipped so it cannot pass vacuously.
 *
 * @group forms-live
 */
class ContactForm7LiveTest extends \WP_UnitTestCase
{
    private Contact_Form_7_Integration $integration;
    private int $form_id;
    /** @var int[] */
    private array $entry_ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (! defined('WPCF7_VERSION') || ! defined('FLAMINGO_VERSION')) {
            $this->markTestSkipped('Needs the real Contact Form 7 and Flamingo (bin/test-local.sh --live-forms, WPMCP_LIVE_FORMS=1).');
        }
        Snapshot_Store::install();
        $this->integration = new Contact_Form_7_Integration();

        $form = \WPCF7_ContactForm::get_template([ 'title' => 'Live contact' ]);
        $this->form_id = (int) $form->save();
        $this->assertGreaterThan(0, $this->form_id);

        foreach ([ 'Ada', 'Grace' ] as $who) {
            $this->submit($who);
        }
        $this->entry_ids = get_posts([
            'post_type'   => \Flamingo_Inbound_Message::post_type,
            'post_status' => 'any',
            'fields'      => 'ids',
            'numberposts' => -1,
        ]);
        sort($this->entry_ids);
        $this->assertCount(2, $this->entry_ids, 'Flamingo stored both real submissions');

        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
    }

    protected function tearDown(): void
    {
        $_POST = [];
        parent::tearDown();
    }

    /**
     * One submission through Contact Form 7's own path. Anonymous, like a
     * site visitor (CF7 verifies its nonce only for logged-in users), with a
     * user agent (CF7 flags a missing one as spam) and mail skipped.
     */
    private function submit(string $who): void
    {
        wp_set_current_user(0);
        $_SERVER['HTTP_USER_AGENT'] = 'wpmcp-forms-live';
        $_POST = [
            'your-name'    => $who,
            'your-email'   => strtolower($who) . '@example.test',
            'your-subject' => 'Hello from ' . $who,
            'your-message' => 'A live submission.',
        ];

        // WPCF7_Submission is a per-request singleton; a second submission in
        // the same process needs a fresh one, as a second request would get.
        $instance = new \ReflectionProperty(\WPCF7_Submission::class, 'instance');
        $instance->setValue(null, null);

        $result = \WPCF7_ContactForm::get_instance($this->form_id)->submit([ 'skip_mail' => true ]);
        $this->assertSame('mail_sent', $result['status'] ?? null, wp_json_encode($result));
    }

    private function read(string $op, array $args = []): array
    {
        $out = $this->integration->handle_read([ 'operation' => $op, 'args' => $args ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        return $out['result'];
    }

    public function test_list_forms_and_get_form_read_the_real_form(): void
    {
        $this->assertTrue($this->integration->is_available());

        $forms = $this->read('list-forms');
        $this->assertContains($this->form_id, array_column($forms['forms'], 'id'));

        $form = $this->read('get-form', [ 'form_id' => $this->form_id ])['form'];
        $this->assertSame('Live contact', $form['title']);
        $this->assertStringContainsString('your-email', $form['form_markup']);
    }

    public function test_list_fields_and_notifications_come_from_the_real_template(): void
    {
        $fields = array_column($this->read('list-fields', [ 'form_id' => $this->form_id ])['fields'], null, 'name');
        $this->assertTrue($fields['your-name']['required']);
        $this->assertSame('email', $fields['your-email']['type']);

        $notes = $this->read('list-notifications', [ 'form_id' => $this->form_id ])['notifications'];
        $this->assertSame('mail', $notes[0]['id']);
        $this->assertTrue($notes[0]['active']);
    }

    public function test_list_entries_finds_the_submissions_through_the_channel_cf7_wrote(): void
    {
        $binding = get_post_meta($this->form_id, '_flamingo', true);
        $this->assertGreaterThan(0, (int) ($binding['channel'] ?? 0), 'Contact Form 7 itself bound the form to a Flamingo channel');

        $list = $this->read('list-entries', [ 'form_id' => $this->form_id ]);
        $ids  = array_column($list['entries'], 'id');
        sort($ids);
        $this->assertSame($this->entry_ids, $ids);
        $this->assertSame(2, $list['total']);

        $page = $this->read('list-entries', [ 'form_id' => $this->form_id, 'page_size' => 1, 'offset' => 1 ]);
        $this->assertCount(1, $page['entries']);
        $this->assertSame(2, $page['total']);
    }

    public function test_get_entry_returns_the_submitted_values(): void
    {
        $names = [];
        foreach ($this->entry_ids as $id) {
            $entry = $this->read('get-entry', [ 'entry_id' => $id ])['entry'];
            $this->assertSame($id, $entry['id']);
            $names[] = $entry['fields']['your-name'] ?? null;
        }
        sort($names);
        $this->assertSame([ 'Ada', 'Grace' ], $names);
    }

    public function test_entry_status_and_deletion_round_trip_on_real_flamingo_posts(): void
    {
        $id = $this->entry_ids[0];

        $trash = $this->integration->handle_write([ 'operation' => 'update-entry-status', 'args' => [ 'entry_id' => $id, 'status' => 'trash' ] ]);
        $this->assertTrue($trash['recoverable'], wp_json_encode($trash));
        $this->assertSame(1, $this->read('list-entries', [ 'form_id' => $this->form_id ])['total']);

        $this->integration->handle_write([ 'operation' => 'update-entry-status', 'args' => [ 'entry_id' => $id, 'status' => 'inbox' ] ]);
        $this->assertSame('publish', get_post_status($id), 'Real Flamingo restores the message to its inbox');

        $name = $this->read('get-entry', [ 'entry_id' => $id ])['entry']['fields']['your-name'];
        add_filter('wpmcp_integration_op_enabled', static fn ($on, $slug, $op) => ('contactform7' === $slug && 'delete-entry' === $op) ? true : $on, 10, 3);
        $deleted = $this->integration->handle_write([ 'operation' => 'delete-entry', 'confirm' => true, 'args' => [ 'entry_id' => $id ] ]);
        $this->assertTrue($deleted['result']['deleted']);
        $this->assertNull(get_post($id));

        ( new Rollback_Operation() )->handle([ 'operation_id' => $deleted['operation_id'] ]);
        $this->assertSame(\Flamingo_Inbound_Message::post_type, get_post_type($id));
        $this->assertSame($name, $this->read('get-entry', [ 'entry_id' => $id ])['entry']['fields']['your-name'], 'Rollback resurrects the submission with its values');
    }
}
