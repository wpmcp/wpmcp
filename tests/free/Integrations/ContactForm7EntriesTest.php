<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Contact_Form_7_Integration;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Rollback_Operation;

require_once __DIR__ . '/../../support/forms-stubs.php';

/**
 * The Contact Form 7 entry operations, which read submissions out of Flamingo.
 * The Flamingo double in tests/support/forms-stubs.php matches the real plugin
 * on every surface this adapter touches, including the ones that BITE: private
 * static $found_items, no get_instance(), a private $id behind a __get() shim,
 * find() defaulting to ID/ASC, and a count() that neither resets
 * posts_per_page nor strips offset. Entries are real flamingo_inbound posts
 * queried through WP_Query, so paging, channel scoping, status filtering, and
 * the snapshot-backed delete are genuinely exercised rather than mocked. The
 * double's remaining divergences from Flamingo 2.x are listed at the top of
 * forms-stubs.php. Live CF7 + Flamingo stay production-verified.
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

        // Form B has no _flamingo binding, which on a real site means CF7 has
        // never recorded a submission for it. Its slug is deliberately EXACTLY
        // form A's channel slug, which is what a rename or a wp_insert_term
        // dedupe produces, so a slug fallback here would hand back form A's
        // submissions to a caller who asked about form B.
        $this->form_b = self::factory()->post->create([ 'post_title' => 'Orphan' ]);
        \WPCF7_ContactForm::seed($this->form_b, 'Orphan', 'contact-us', [ 'form' => '' ]);

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

    public function test_list_entries_requires_a_form_id(): void
    {
        // Every other forms adapter requires form_id. An optional one means an
        // omitted one dumps every form's submissions site-wide, which is not a
        // thing an agent should be able to ask for by accident.
        $out = $this->read('list-entries');

        $this->assertArrayNotHasKey('result', $out);
        $this->assertSame('invalid_args', $out['error']['code']);
    }

    public function test_list_entries_lists_a_form_inbox_newest_first(): void
    {
        $out = $this->read('list-entries', [ 'form_id' => $this->form_a ]);

        $this->assertArrayNotHasKey('error', $out);
        $this->assertSame(3, $out['result']['total'], 'Form A\'s three inbox entries; spam is not in the inbox');
        $this->assertSame($this->entries_a, array_column($out['result']['entries'], 'id'));
        $this->assertFalse($out['result']['entries'][0]['spam']);
    }

    public function test_list_entries_pages_with_offset_so_page_two_differs(): void
    {
        $page1 = $this->read('list-entries', [ 'form_id' => $this->form_a, 'offset' => 0, 'page_size' => 2 ]);
        $page2 = $this->read('list-entries', [ 'form_id' => $this->form_a, 'offset' => 2, 'page_size' => 2 ]);

        $this->assertCount(2, $page1['result']['entries']);
        $this->assertCount(1, $page2['result']['entries']);
        $this->assertSame(
            [ $this->entries_a[0], $this->entries_a[1] ],
            array_column($page1['result']['entries'], 'id')
        );
        $this->assertSame(
            [ $this->entries_a[2] ],
            array_column($page2['result']['entries'], 'id'),
            'The second page must not repeat the first: Flamingo find() defaults offset to 0 and WP_Query prefers offset over paged'
        );
    }

    public function test_list_entries_uses_the_same_paging_vocabulary_as_the_other_adapters(): void
    {
        $ops   = array_column($this->integration->catalog()['operations'], null, 'name');
        $props = $ops['list-entries']['input_schema']['properties'];

        $this->assertArrayHasKey('offset', $props);
        $this->assertArrayHasKey('page_size', $props);
        $this->assertArrayNotHasKey('page', $props, 'A second paging idiom makes an agent guess');
    }

    public function test_list_entries_total_is_the_full_count_not_the_page_size(): void
    {
        $out = $this->read('list-entries', [ 'form_id' => $this->form_a, 'page_size' => 1 ]);

        $this->assertCount(1, $out['result']['entries']);
        $this->assertSame(3, $out['result']['total'], 'A caller must be able to tell there are more pages');
    }

    public function test_list_entries_total_survives_an_offset_past_the_last_entry(): void
    {
        // The trap: Flamingo's count() forwards its args straight to WP_Query,
        // and WP_Query::set_found_posts() returns early on an empty result set,
        // so counting WITH the page window reports total 0 for any page past
        // the last one. An agent paging forward would read that as "the form
        // has no submissions" rather than "you have walked off the end".
        $out = $this->read('list-entries', [ 'form_id' => $this->form_a, 'offset' => 10, 'page_size' => 2 ]);

        $this->assertArrayNotHasKey('error', $out);
        $this->assertSame([], $out['result']['entries'], 'Nothing on a page past the last one');
        $this->assertSame(3, $out['result']['total'], 'The total is the form\'s total, not the empty window\'s');
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

    public function test_list_entries_fails_closed_rather_than_matching_a_foreign_channel_by_slug(): void
    {
        // Form B carries no _flamingo term id and its slug happens to be form
        // A's channel slug. Resolving by slug would return form A's PII to a
        // caller who asked about form B, so the adapter refuses outright.
        $out = $this->read('list-entries', [ 'form_id' => $this->form_b ]);

        $this->assertArrayNotHasKey('result', $out, 'A refusal must never arrive inside a success envelope');
        $this->assertSame('unresolved_form_channel', $out['error']['code']);
        $this->assertSame($this->form_b, $out['error']['data']['form_id']);
        $this->assertSame('list-entries', $out['error']['data']['operation']);
    }

    public function test_list_entries_refuses_an_unknown_form_rather_than_answering_empty(): void
    {
        $out = $this->read('list-entries', [ 'form_id' => 999999 ]);

        $this->assertArrayNotHasKey('result', $out);
        $this->assertSame('unresolved_form_channel', $out['error']['code']);
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

    public function test_list_entries_requires_the_capability_flamingo_itself_gates_entries_behind(): void
    {
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));

        $out = $this->read('list-entries', [ 'form_id' => $this->form_a ]);

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

    public function test_get_entry_requires_the_entry_capability(): void
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

    public function test_delete_entry_requires_the_entry_capability(): void
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
        $other             = self::factory()->post->create();
        $snapshots_before  = $this->snapshot_count();

        $out = $this->write_with_optin([
            'operation' => 'delete-entry',
            'args'      => [ 'entry_id' => $other ],
            'confirm'   => true,
        ]);

        $this->assertFalse($out['result']['deleted']);
        $this->assertSame('not_found', $out['result']['reason']);
        $this->assertNotNull(get_post($other));

        // And it must not have snapshotted that post on the way to refusing.
        // The snapshot callable runs BEFORE the handler's post-type guard, so a
        // target named unconditionally would persist a full copy (row, meta,
        // terms) of an unrelated live post, and a later rollback-operation on
        // the returned operation_id would silently revert it.
        $this->assertArrayNotHasKey('operation_id', $out, 'A refused delete must not hand back a rollback handle');
        $this->assertFalse($out['recoverable']);
        $this->assertSame(
            $snapshots_before,
            $this->snapshot_count(),
            'A refused delete must write no snapshot row'
        );
    }

    // ---- Flamingo absent ---------------------------------------------------

    /**
     * The entry ops are gated by a 'requires' dependency check rather than by
     * a handler that assumes \Flamingo_Inbound_Message exists. With Flamingo
     * off, every entry op must be a structured top-level error and the form
     * ops must keep working, because CF7 alone is enough for those.
     *
     * @dataProvider entry_operations
     */
    public function test_entry_ops_refuse_structurally_when_flamingo_is_absent(string $op, string $half, array $args): void
    {
        add_filter('wpmcp_contactform7_flamingo_active', '__return_false');

        $payload = [ 'operation' => $op, 'args' => $args, 'confirm' => true ];
        $out     = 'read' === $half
            ? $this->integration->handle_read($payload)
            : $this->write_with_optin($payload);

        $this->assertArrayNotHasKey('result', $out, "{$op} must not answer without Flamingo");
        $this->assertSame('flamingo_unavailable', $out['error']['code']);
        $this->assertNotEmpty($out['error']['message']);
        $this->assertSame($op, $out['error']['data']['operation']);
    }

    /** @return array<string, array{0: string, 1: string, 2: array<string, int>}> */
    public static function entry_operations(): array
    {
        return [
            'list-entries' => [ 'list-entries', 'read', [ 'form_id' => 1 ] ],
            'get-entry'    => [ 'get-entry', 'read', [ 'entry_id' => 1 ] ],
            'delete-entry' => [ 'delete-entry', 'write', [ 'entry_id' => 1 ] ],
        ];
    }

    public function test_form_ops_still_work_when_flamingo_is_absent(): void
    {
        add_filter('wpmcp_contactform7_flamingo_active', '__return_false');

        $out = $this->read('list-forms');

        $this->assertArrayNotHasKey('error', $out, 'CF7 alone is enough to list forms');
        $this->assertNotEmpty($out['result']['forms']);
    }

    public function test_catalog_marks_the_entry_ops_unmet_when_flamingo_is_absent(): void
    {
        add_filter('wpmcp_contactform7_flamingo_active', '__return_false');

        $catalog = $this->integration->catalog();
        $ops     = array_column($catalog['operations'], null, 'name');

        $this->assertTrue($catalog['available'], 'CF7 itself is still active: available is the HOST plugin flag');
        $this->assertFalse($ops['list-entries']['dependency_met']);
        $this->assertFalse($ops['get-entry']['dependency_met']);
        $this->assertFalse($ops['delete-entry']['dependency_met']);
        $this->assertTrue($ops['list-forms']['dependency_met'], 'The form ops depend on nothing but CF7');
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
        // edit_users, not manage_options: it is the cap Flamingo maps every
        // inbound-message capability to, and under is_multisite() core turns
        // edit_users into manage_network_users while a site admin keeps
        // manage_options, so manage_options would have made wpmcp a LOOSER
        // door onto submissions than Flamingo's own UI.
        $this->assertSame('edit_users', $ops['list-entries']['capability']);
        $this->assertSame('edit_users', $ops['get-entry']['capability']);
        $this->assertSame('edit_users', $ops['delete-entry']['capability']);
        $this->assertNotSame($this->integration->capability(), $ops['delete-entry']['capability']);
        $this->assertTrue($ops['delete-entry']['requires_confirm']);
        $this->assertFalse($ops['delete-entry']['enabled'], 'Entry deletion is default-off');
        $this->assertTrue($ops['list-entries']['dependency_met'], 'Flamingo is present in this test');
        $this->assertTrue($ops['list-forms']['dependency_met']);
    }

    /**
     * Deletion is reversible, and reversibility is in tension with erasure:
     * the snapshot keeps a plaintext copy of the submission until it is
     * pruned. The op description must say so rather than presenting
     * reversibility as an unqualified safety property.
     */
    public function test_delete_entry_description_does_not_present_deletion_as_erasure(): void
    {
        $ops  = array_column($this->integration->catalog()['operations'], null, 'name');
        $text = $ops['delete-entry']['description'];

        $this->assertStringContainsString('NOT erasure', $text);
        $this->assertStringContainsString('plaintext copy', $text);
    }

    /**
     * The entry capability must not be one-way. rollback-operation is an
     * edit_posts ability, so without the PII guard in Rollback_Service an
     * Editor could resurrect a submission they may not read.
     */
    public function test_a_deleted_entry_cannot_be_resurrected_below_the_entry_capability(): void
    {
        $id  = $this->entries_a[0];
        $out = $this->write_with_optin([
            'operation' => 'delete-entry',
            'args'      => [ 'entry_id' => $id ],
            'confirm'   => true,
        ]);
        $this->assertNull(get_post($id));

        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        $rolled = (new Rollback_Operation())->handle([ 'operation_id' => $out['operation_id'] ]);

        $this->assertFalse($rolled['restored']);
        $this->assertNotEmpty($rolled['warnings']);
        $this->assertNull(get_post($id), 'The submission stays deleted for a caller who may not read it');
    }

    /** Rows currently in the snapshot store. */
    private function snapshot_count(): int
    {
        global $wpdb;
        $table = Snapshot_Store::table_name();
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}"); // phpcs:ignore WordPress.DB
    }
}
