<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Contact_Form_7_Integration;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Rollback_Operation;

require_once __DIR__ . '/../../support/forms-stubs.php';

/**
 * The Contact Form 7 entry operations, which read submissions out of Flamingo.
 * The Flamingo double in tests/support/forms-stubs.php mirrors the real
 * plugin's visibility exactly (private static $found_items, no get_instance())
 * and stores entries as real flamingo_inbound posts queried through WP_Query,
 * so paging, channel scoping, status filtering, and the snapshot-backed delete
 * are genuinely exercised rather than mocked. Live CF7 + Flamingo stay
 * production-verified.
 */
class ContactForm7EntriesTest extends \WP_UnitTestCase
{
    private Contact_Form_7_Integration $integration;
    private int $channel_a;
    private int $channel_b;
    private int $form_a;
    private int $form_b;
    /** @var int[] Newest first. */
    private array $entries_a = [];
    private int $spam_id;

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        \Flamingo_Inbound_Message::register();
        \WPCF7_ContactForm::$registry = [];
        $this->integration = new Contact_Form_7_Integration();

        $a = wp_insert_term('Contact Us', \Flamingo_Inbound_Message::channel_taxonomy);
        $b = wp_insert_term('Newsletter', \Flamingo_Inbound_Message::channel_taxonomy);
        $this->channel_a = (int) $a['term_id'];
        $this->channel_b = (int) $b['term_id'];

        // Form A binds to its channel by TERM ID and its own slug deliberately
        // diverges from the channel slug ("contact-us-2" vs "contact-us"),
        // which is what wp_insert_term's dedupe suffix produces on a real site.
        $this->form_a = self::factory()->post->create([ 'post_title' => 'Contact' ]);
        \WPCF7_ContactForm::seed($this->form_a, 'Contact', 'contact-us-2', [ 'form' => '[text your-name]' ]);
        update_post_meta($this->form_a, '_flamingo', [ 'channel' => $this->channel_a ]);

        // Form B has no _flamingo binding and no slug at all, so its channel
        // cannot be resolved by either route.
        $this->form_b = self::factory()->post->create([ 'post_title' => 'Orphan' ]);
        \WPCF7_ContactForm::seed($this->form_b, 'Orphan', '', [ 'form' => '' ]);

        foreach ([ 'ada', 'grace', 'edsger' ] as $i => $who) {
            $this->entries_a[] = \Flamingo_Inbound_Message::seed([
                'subject'    => 'Hello from ' . $who,
                'from_name'  => $who,
                'from_email' => $who . '@example.test',
                'fields'     => [ 'your-name' => $who, 'your-message' => 'hi' ],
                'meta'       => [ 'remote_ip' => '203.0.113.' . $i ],
                'channel'    => $this->channel_a,
                'post_date'  => sprintf('2026-08-%02d 10:00:00', 10 + $i),
            ]);
        }
        $this->entries_a = array_reverse($this->entries_a); // newest first

        \Flamingo_Inbound_Message::seed([
            'subject' => 'Other channel',
            'channel' => $this->channel_b,
        ]);
        $this->spam_id = \Flamingo_Inbound_Message::seed([
            'subject'     => 'Buy pills',
            'channel'     => $this->channel_a,
            'post_status' => \Flamingo_Inbound_Message::spam_status,
        ]);

        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
    }

    protected function tearDown(): void
    {
        \Flamingo_Inbound_Message::unregister();
        parent::tearDown();
    }

    private function read(string $op, array $args = []): array
    {
        return $this->integration->handle_read([ 'operation' => $op, 'args' => $args ]);
    }

    /** Run a write with the default-off delete-entry op opted in. */
    private function write_with_optin(array $payload): array
    {
        $enable = fn ($enabled, $integration, $op) => ('contactform7' === $integration && 'delete-entry' === $op) ? true : $enabled;
        add_filter('wpmcp_integration_op_enabled', $enable, 10, 3);
        try {
            return $this->integration->handle_write($payload);
        } finally {
            remove_filter('wpmcp_integration_op_enabled', $enable);
        }
    }

    // ---- list-entries ------------------------------------------------------

    public function test_list_entries_lists_inbox_messages_newest_first(): void
    {
        $out = $this->read('list-entries');

        $this->assertArrayNotHasKey('error', $out);
        $this->assertSame(4, $out['result']['total'], 'Three form A entries plus one form B entry; spam is not in the inbox');
        $ids = array_column($out['result']['entries'], 'id');
        $this->assertSame($this->entries_a[0], $ids[1], 'Newest first, after the form B entry');
        $this->assertFalse($out['result']['entries'][1]['spam']);
    }

    public function test_list_entries_pages_with_offset_so_page_two_differs(): void
    {
        $page1 = $this->read('list-entries', [ 'form_id' => $this->form_a, 'page' => 1, 'page_size' => 2 ]);
        $page2 = $this->read('list-entries', [ 'form_id' => $this->form_a, 'page' => 2, 'page_size' => 2 ]);

        $this->assertCount(2, $page1['result']['entries']);
        $this->assertCount(1, $page2['result']['entries']);
        $this->assertSame(
            [ $this->entries_a[0], $this->entries_a[1] ],
            array_column($page1['result']['entries'], 'id')
        );
        $this->assertSame(
            [ $this->entries_a[2] ],
            array_column($page2['result']['entries'], 'id'),
            'Page 2 must not repeat page 1: Flamingo find() defaults offset to 0 and WP_Query prefers offset over paged'
        );
    }

    public function test_list_entries_total_is_the_full_count_not_the_page_size(): void
    {
        $out = $this->read('list-entries', [ 'form_id' => $this->form_a, 'page_size' => 1 ]);

        $this->assertCount(1, $out['result']['entries']);
        $this->assertSame(3, $out['result']['total'], 'A caller must be able to tell there are more pages');
    }

    public function test_list_entries_scopes_by_the_flamingo_channel_term_not_the_form_slug(): void
    {
        $out = $this->read('list-entries', [ 'form_id' => $this->form_a ]);

        $this->assertSame(3, $out['result']['total']);
        $this->assertSame($this->entries_a, array_column($out['result']['entries'], 'id'));
        foreach ($out['result']['entries'] as $entry) {
            $this->assertSame('contact-us', $entry['channel']);
        }
    }

    public function test_list_entries_refuses_to_run_unscoped_when_the_channel_cannot_be_resolved(): void
    {
        $out = $this->read('list-entries', [ 'form_id' => $this->form_b ]);

        $this->assertSame([], $out['result']['entries'], 'Scoping must fail closed, never return every form\'s submissions');
        $this->assertSame(0, $out['result']['total']);
        $this->assertSame('unresolved_form_channel', $out['result']['reason']);
    }

    public function test_list_entries_returns_nothing_for_an_unknown_form(): void
    {
        $out = $this->read('list-entries', [ 'form_id' => 999999 ]);

        $this->assertSame([], $out['result']['entries']);
        $this->assertSame(0, $out['result']['total']);
    }

    public function test_list_entries_status_filter_reaches_spam(): void
    {
        $inbox = $this->read('list-entries', [ 'form_id' => $this->form_a ]);
        $this->assertNotContains($this->spam_id, array_column($inbox['result']['entries'], 'id'));

        $spam = $this->read('list-entries', [ 'form_id' => $this->form_a, 'status' => 'spam' ]);
        $this->assertSame([ $this->spam_id ], array_column($spam['result']['entries'], 'id'));
        $this->assertSame(1, $spam['result']['total']);
        $this->assertTrue($spam['result']['entries'][0]['spam']);
    }

    public function test_list_entries_requires_manage_options(): void
    {
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));

        $out = $this->read('list-entries');

        $this->assertSame('operation_denied', $out['error']['code']);
        $this->assertSame('capability', $out['error']['data']['reason']);
    }

    // ---- get-entry ---------------------------------------------------------

    public function test_get_entry_returns_the_full_submission(): void
    {
        $out = $this->read('get-entry', [ 'entry_id' => $this->entries_a[0] ]);

        $this->assertArrayNotHasKey('error', $out);
        $entry = $out['result']['entry'];
        $this->assertSame($this->entries_a[0], $entry['id']);
        $this->assertSame('Hello from edsger', $entry['subject']);
        $this->assertSame('edsger@example.test', $entry['from_email']);
        $this->assertSame('contact-us', $entry['channel']);
        $this->assertSame('edsger', $entry['fields']['your-name']);
        $this->assertSame('203.0.113.2', $entry['meta']['remote_ip']);
    }

    public function test_get_entry_rejects_a_post_of_another_type(): void
    {
        // Flamingo's constructor does not validate the post type, so an
        // ordinary post must be rejected by the integration itself.
        $out = $this->read('get-entry', [ 'entry_id' => self::factory()->post->create() ]);

        $this->assertNull($out['result']['entry']);
    }

    public function test_get_entry_returns_null_for_a_missing_id(): void
    {
        $out = $this->read('get-entry', [ 'entry_id' => 987654 ]);

        $this->assertNull($out['result']['entry']);
    }

    public function test_get_entry_requires_manage_options(): void
    {
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));

        $out = $this->read('get-entry', [ 'entry_id' => $this->entries_a[0] ]);

        $this->assertSame('operation_denied', $out['error']['code']);
    }

    // ---- delete-entry ------------------------------------------------------

    public function test_delete_entry_is_off_by_default(): void
    {
        $out = $this->integration->handle_write([
            'operation' => 'delete-entry',
            'args'      => [ 'entry_id' => $this->entries_a[0] ],
            'confirm'   => true,
        ]);

        $this->assertSame('operation_disabled', $out['error']['code']);
        $this->assertNotNull(get_post($this->entries_a[0]));
    }

    public function test_delete_entry_requires_confirm(): void
    {
        $out = $this->write_with_optin([
            'operation' => 'delete-entry',
            'args'      => [ 'entry_id' => $this->entries_a[0] ],
        ]);

        $this->assertSame('confirmation_required', $out['error']['code']);
        $this->assertNotNull(get_post($this->entries_a[0]));
    }

    public function test_delete_entry_requires_manage_options(): void
    {
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));

        $out = $this->write_with_optin([
            'operation' => 'delete-entry',
            'args'      => [ 'entry_id' => $this->entries_a[0] ],
            'confirm'   => true,
        ]);

        $this->assertSame('operation_denied', $out['error']['code']);
        $this->assertSame('capability', $out['error']['data']['reason']);
        $this->assertNotNull(get_post($this->entries_a[0]));
    }

    public function test_delete_entry_snapshots_before_deleting(): void
    {
        $id  = $this->entries_a[0];
        $out = $this->write_with_optin([
            'operation'  => 'delete-entry',
            'args'       => [ 'entry_id' => $id ],
            'confirm'    => true,
            'session_id' => 's-66',
        ]);

        $this->assertArrayNotHasKey('error', $out);
        $this->assertTrue($out['result']['deleted']);
        $this->assertTrue($out['recoverable'], 'A flamingo_inbound post is snapshotable, so the delete is reversible');
        $this->assertNotEmpty($out['operation_id']);
        $this->assertNull(get_post($id));

        $row = Snapshot_Store::get_by_operation($out['operation_id']);
        $this->assertNotNull($row);
        $this->assertSame('contactform7-write', $row['tool_name']);
        $this->assertSame('flamingo_inbound', $row['snapshot']['data']['post']['post_type']);
    }

    public function test_deleted_entry_is_resurrected_by_rollback_operation(): void
    {
        $id  = $this->entries_a[0];
        $out = $this->write_with_optin([
            'operation' => 'delete-entry',
            'args'      => [ 'entry_id' => $id ],
            'confirm'   => true,
        ]);
        $this->assertNull(get_post($id));

        (new Rollback_Operation())->handle([ 'operation_id' => $out['operation_id'] ]);

        $restored = get_post($id);
        $this->assertNotNull($restored);
        $this->assertSame('flamingo_inbound', $restored->post_type);
        $this->assertSame('edsger@example.test', get_post_meta($id, '_from_email', true));

        $entry = $this->read('get-entry', [ 'entry_id' => $id ]);
        $this->assertSame('edsger', $entry['result']['entry']['fields']['your-name']);
    }

    public function test_delete_entry_rejects_a_post_of_another_type(): void
    {
        $other = self::factory()->post->create();

        $out = $this->write_with_optin([
            'operation' => 'delete-entry',
            'args'      => [ 'entry_id' => $other ],
            'confirm'   => true,
        ]);

        $this->assertFalse($out['result']['deleted']);
        $this->assertSame('not_found', $out['result']['reason']);
        $this->assertNotNull(get_post($other));
    }

    // ---- catalog -----------------------------------------------------------

    public function test_catalog_reports_the_entry_ops_guarded_and_available(): void
    {
        $catalog = $this->integration->catalog();
        $ops     = array_column($catalog['operations'], null, 'name');

        $this->assertSame('contactform7', $catalog['integration']);
        $this->assertSame(
            [ 'list-forms', 'get-form', 'list-entries', 'get-entry', 'delete-entry' ],
            array_keys($ops)
        );
        $this->assertSame('manage_options', $ops['list-entries']['capability']);
        $this->assertSame('manage_options', $ops['get-entry']['capability']);
        $this->assertSame('manage_options', $ops['delete-entry']['capability']);
        $this->assertTrue($ops['delete-entry']['requires_confirm']);
        $this->assertFalse($ops['delete-entry']['enabled'], 'Entry deletion is default-off');
        $this->assertTrue($ops['list-entries']['available'], 'Flamingo is present in this test');
        $this->assertTrue($ops['list-forms']['available']);
    }
}
