<?php

namespace WPMCP\Tests\Support;

use WPMCP\Integrations\Forms_Integration;
use WPMCP\Integrations\Integration_Dispatcher;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Rollback_Operation;

require_once __DIR__ . '/forms-adapters.php';

/**
 * Shared conformance suite for the forms adapters (issue #66): one contract,
 * run against every adapter, in two halves.
 *
 *  - The contract half asserts what every adapter's catalog must agree on,
 *    independent of whether its host plugin is loaded: the shared op
 *    vocabulary, one paging idiom, well-formed op definitions, entry
 *    operations above the pair's own capability, guarded deletion, and
 *    structured errors instead of fatals.
 *  - The fixture half runs the same behavioural scenario against each
 *    adapter's OWN fixture (seeded through that plugin's double): the seeded
 *    form is listed and readable, its fields and notifications come back,
 *    its entries are listed scoped to it and readable one by one only above
 *    the entry capability, and an entry status change is snapshotted and
 *    undone by rollback-operation.
 *
 * tests/free/Integrations/FormsAdapterConformanceTest runs it over the free
 * adapters and tests/pro/Integrations/FormsPackConformanceTest over the
 * adapter pack. A concrete suite supplies the adapter list, the operations
 * each adapter must offer, and a fixture per adapter.
 */
abstract class Forms_Adapter_Conformance extends \WP_UnitTestCase
{
    /** @return array<string, array{0: class-string<Forms_Integration>}> slug => [class] */
    abstract public static function adapters(): array;

    /** @return string[] operations the adapter must offer beyond list-operations. */
    abstract protected static function expected_operations(string $slug): array;

    /**
     * Seed one form (with at least one field and one notification where the
     * adapter lists them) and two entries on it, plus one entry on another
     * form, through the plugin's double.
     *
     * @return array{
     *   form_id: int,
     *   entry_ids: int[],
     *   entry_args: array<string, mixed>,
     *   status: null|array{from: string, to: string, read: callable(int): string}
     * }
     */
    abstract protected function fixture(string $slug): array;

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
    }

    // ---------------------------------------------------------------- contract

    /** @dataProvider adapters */
    public function test_catalog_answers_for_every_adapter(string $class): void
    {
        $integration = new $class();

        $out = $integration->handle_read([ 'operation' => 'list-operations' ]);

        $this->assertArrayNotHasKey('error', $out);
        $this->assertSame($integration->integration(), $out['result']['integration']);
        $this->assertIsBool($out['result']['available']);
        $this->assertNotEmpty($out['result']['operations']);
    }

    /**
     * The host-plugin-absent path for real, using a subclass that forces
     * is_available() false rather than hoping the double is not loaded: the
     * catalog must still answer with the full op list and available:false,
     * every other op must be a structured integration_unavailable error, and
     * the pair must not ask to be registered.
     *
     * @dataProvider adapters
     */
    public function test_catalog_still_answers_when_the_host_plugin_is_absent(string $class): void
    {
        $integration = new class ($class) extends Forms_Integration {
            private Integration_Dispatcher $inner;

            public function __construct(string $class)
            {
                $this->inner = new $class();
            }

            public function integration(): string
            {
                return $this->inner->integration();
            }

            public function is_available(): bool
            {
                return false;
            }

            protected function operations(): array
            {
                return \Closure::bind(
                    fn () => $this->operations(),
                    $this->inner,
                    $this->inner
                )();
            }
        };

        $this->assertFalse($integration->should_register(), 'A forms pair registers only while its plugin is loaded');

        $out = $integration->handle_read([ 'operation' => 'list-operations' ]);
        $this->assertArrayNotHasKey('error', $out);
        $this->assertFalse($out['result']['available'], 'available means the HOST PLUGIN is loaded');
        $this->assertNotEmpty($out['result']['operations'], 'The surface stays discoverable without the plugin');

        foreach ([ 'handle_read', 'handle_write' ] as $half) {
            $refused = $integration->{$half}([ 'operation' => 'list-forms' ]);
            $this->assertArrayNotHasKey('result', $refused, "{$half} must not answer without the host plugin");
            $this->assertSame('integration_unavailable', $refused['error']['code']);
        }
    }

    /** @dataProvider adapters */
    public function test_every_adapter_exposes_its_expected_operations(string $class): void
    {
        $integration = new $class();
        $this->assertInstanceOf(Forms_Integration::class, $integration);
        $ops = self::catalog_ops($integration);

        foreach (array_merge([ 'list-forms', 'get-form' ], static::expected_operations($integration->integration())) as $op) {
            $this->assertArrayHasKey($op, $ops, "{$op} is part of this adapter's surface");
        }
        $this->assertSame('read', $ops['list-forms']['mode']);
        foreach ([ 'get-form', 'list-fields', 'list-notifications' ] as $op) {
            if (! isset($ops[ $op ])) {
                continue;
            }
            $this->assertSame('read', $ops[ $op ]['mode'], "{$op} is a read");
            $this->assertSame([ 'form_id' ], $ops[ $op ]['input_schema']['required'], "{$op} takes exactly a form_id");
        }
    }

    /**
     * One paging vocabulary across the pack. Adapters that page entries do it
     * with page_size + offset; a second idiom (page, per_page, limit...) makes
     * an agent guess, so it is a conformance failure, not a style choice.
     *
     * @dataProvider adapters
     */
    public function test_entry_paging_uses_one_shared_vocabulary(string $class): void
    {
        $ops = self::catalog_ops(new $class());

        if (! isset($ops['list-entries'])) {
            $this->assertTrue(true, 'Adapter offers no entry listing');
            return;
        }

        $props = $ops['list-entries']['input_schema']['properties'];
        foreach ([ 'page', 'per_page', 'paged', 'limit' ] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $props, "list-entries must not introduce a second paging idiom ({$forbidden})");
        }
        $this->assertArrayHasKey('offset', $props, 'Paging is page_size + offset');
        $this->assertArrayHasKey('page_size', $props, 'Paging is page_size + offset');
        $this->assertSame(Forms_Integration::MAX_PAGE_SIZE, $props['page_size']['maximum'] ?? null);
    }

    /** @dataProvider adapters */
    public function test_every_op_definition_is_well_formed(string $class): void
    {
        foreach (self::catalog_ops(new $class()) as $name => $op) {
            $this->assertContains($op['mode'], [ 'read', 'write', 'destructive' ], "{$name} has an unknown mode");
            $this->assertNotEmpty($op['description'], "{$name} needs a description an agent can act on");
            $this->assertNotEmpty($op['capability'], "{$name} must resolve to a capability");
            $this->assertSame('object', $op['input_schema']['type'] ?? null, "{$name} must take an object of args");
            $this->assertIsBool($op['dependency_met'], "{$name} must report whether its own dependency check passes");
        }
    }

    /**
     * Entry reads are PII. An op that lists entries must be scoped to one
     * form, and an adapter that lists entries must be able to read one.
     *
     * @dataProvider adapters
     */
    public function test_entry_reads_are_scopable_and_read_only(string $class): void
    {
        $ops = self::catalog_ops(new $class());

        if (! isset($ops['list-entries'])) {
            $this->assertArrayNotHasKey('get-entry', $ops, 'get-entry without list-entries is an incomplete surface');
            return;
        }

        $this->assertSame('read', $ops['list-entries']['mode']);
        $this->assertContains(
            'form_id',
            $ops['list-entries']['input_schema']['required'] ?? [],
            'An entry listing must be scoped to one form: an optional form_id means an omitted one dumps every form\'s submissions'
        );
        $this->assertArrayHasKey('get-entry', $ops, 'An adapter that lists entries must be able to read one');
        $this->assertSame('read', $ops['get-entry']['mode']);
        // Forminator keeps entries in per-form custom tables, so it also
        // requires form_id; entry_id is the part every adapter must demand.
        $this->assertContains('entry_id', $ops['get-entry']['input_schema']['required']);
    }

    /**
     * "Entries are user data: reads behind an appropriate capability." Every
     * op that touches submissions sits behind an administrator-level
     * capability strictly above the pair's own.
     *
     * @dataProvider adapters
     */
    public function test_every_entry_operation_sits_above_the_pair_capability(string $class): void
    {
        $integration = new $class();
        $checked     = [];
        foreach (self::catalog_ops($integration) as $name => $op) {
            if (! preg_match('/entr|note/', $name)) {
                continue;
            }
            $checked[] = $name;
            $this->assertContains($op['capability'], [ 'manage_options', 'edit_users' ], "{$name} touches submissions and must be administrator-only");
            $this->assertNotSame($integration->capability(), $op['capability'], "{$name}: a per-op guard equal to the pair's capability is not a guard");
        }
        $this->assertSame(
            array_values(array_intersect([ 'list-entries', 'get-entry', 'get-notes', 'update-entry-status', 'delete-entry' ], array_keys(self::catalog_ops($integration)))),
            array_values(array_intersect([ 'list-entries', 'get-entry', 'get-notes', 'update-entry-status', 'delete-entry' ], $checked)),
            'Every submission op was checked'
        );
    }

    /** @dataProvider adapters */
    public function test_entry_status_update_contract(string $class): void
    {
        $ops = self::catalog_ops(new $class());

        if (! isset($ops['update-entry-status'])) {
            $this->assertTrue(true, 'Adapter offers no entry status change');
            return;
        }

        $op = $ops['update-entry-status'];
        $this->assertSame('write', $op['mode'], 'A status change is a reversible write, not a destructive op');
        $this->assertSame([ 'entry_id', 'status' ], $op['input_schema']['required']);
        $this->assertNotEmpty($op['input_schema']['properties']['status']['enum'] ?? [], 'The host plugin\'s statuses are enumerated, never free text');
    }

    /**
     * Deleting a submission destroys user data, so every adapter that offers
     * it must demand confirm:true and an administrator-only capability, and
     * ship it switched off.
     *
     * @dataProvider adapters
     */
    public function test_entry_deletion_is_uniformly_guarded(string $class): void
    {
        $ops = self::catalog_ops(new $class());

        if (! isset($ops['delete-entry'])) {
            $this->assertTrue(true, 'Adapter offers no entry deletion');
            return;
        }

        $this->assertSame('destructive', $ops['delete-entry']['mode']);
        $this->assertTrue($ops['delete-entry']['requires_confirm']);
        $this->assertFalse(
            $ops['delete-entry']['enabled'],
            'Issue #66: entry deletion is off by default and the site opts in with wpmcp_integration_op_enabled'
        );
        $this->assertContains($ops['delete-entry']['capability'], [ 'manage_options', 'edit_users' ]);
        $this->assertContains('entry_id', $ops['delete-entry']['input_schema']['required']);
    }

    /**
     * An unknown op must come back as a structured top-level error on both
     * halves, never as a fatal and never inside a success envelope.
     *
     * @dataProvider adapters
     */
    public function test_unknown_operation_is_a_structured_error_on_both_halves(string $class): void
    {
        $integration = new $class();
        $expected    = $integration->is_available() ? 'unknown_operation' : 'integration_unavailable';

        foreach ([ 'handle_read', 'handle_write' ] as $half) {
            $out = $integration->{$half}([ 'operation' => 'no-such-op' ]);

            $this->assertArrayNotHasKey('result', $out, "{$half} must not wrap a refusal in a success envelope");
            $this->assertSame($expected, $out['error']['code']);
            $this->assertNotEmpty($out['error']['message']);
        }
    }

    // ---------------------------------------------------------------- fixtures

    /** @dataProvider adapters */
    public function test_fixture_form_is_listed_and_read(string $class): void
    {
        $integration = new $class();
        $slug        = $integration->integration();
        $fixture     = $this->fixture($slug);
        $this->assertTrue($integration->is_available(), "{$slug}'s double must be loaded for its fixture scenario");
        $ops = self::catalog_ops($integration);

        $forms = self::ok($integration->handle_read([ 'operation' => 'list-forms' ]));
        $this->assertContains($fixture['form_id'], array_map(static fn ($f) => (int) ((array) $f)['id'], $forms['forms']));

        $form = self::ok($integration->handle_read([ 'operation' => 'get-form', 'args' => [ 'form_id' => $fixture['form_id'] ] ]));
        $this->assertNotNull($form['form']);
        $this->assertSame($fixture['form_id'], (int) ((array) $form['form'])['id']);

        $missing = self::ok($integration->handle_read([ 'operation' => 'get-form', 'args' => [ 'form_id' => 987654 ] ]));
        $this->assertNull($missing['form'], 'A missing form is null, not an error and not another form');

        if (isset($ops['list-fields'])) {
            $fields = self::ok($integration->handle_read([ 'operation' => 'list-fields', 'args' => [ 'form_id' => $fixture['form_id'] ] ]));
            $this->assertNotEmpty($fields['fields'], "{$slug}: the fixture form's fields come back");
            foreach ($fields['fields'] as $field) {
                $this->assertIsBool($field['required'] ?? null, "{$slug}: every field says whether it is required");
            }
            $none = self::ok($integration->handle_read([ 'operation' => 'list-fields', 'args' => [ 'form_id' => 987654 ] ]));
            $this->assertNull($none['fields']);
        }

        if (isset($ops['list-notifications'])) {
            $notes = self::ok($integration->handle_read([ 'operation' => 'list-notifications', 'args' => [ 'form_id' => $fixture['form_id'] ] ]));
            $this->assertNotEmpty($notes['notifications'], "{$slug}: the fixture form's notification comes back");
            $none = self::ok($integration->handle_read([ 'operation' => 'list-notifications', 'args' => [ 'form_id' => 987654 ] ]));
            $this->assertNull($none['notifications']);
        }
    }

    /** @dataProvider adapters */
    public function test_fixture_entries_are_scoped_readable_and_capability_gated(string $class): void
    {
        $integration = new $class();
        $slug        = $integration->integration();
        $ops         = self::catalog_ops($integration);
        if (! isset($ops['list-entries'])) {
            $this->assertTrue(true, 'Adapter offers no entries');
            return;
        }
        $fixture = $this->fixture($slug);

        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        $denied = $integration->handle_read([ 'operation' => 'list-entries', 'args' => [ 'form_id' => $fixture['form_id'] ] ]);
        $this->assertSame('operation_denied', $denied['error']['code'] ?? null, "{$slug}: an editor may not list submissions");
        $denied = $integration->handle_read([ 'operation' => 'get-entry', 'args' => [ 'entry_id' => $fixture['entry_ids'][0] ] + $fixture['entry_args'] ]);
        $this->assertSame('operation_denied', $denied['error']['code'] ?? null, "{$slug}: an editor may not read a submission");

        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        $list = self::ok($integration->handle_read([ 'operation' => 'list-entries', 'args' => [ 'form_id' => $fixture['form_id'] ] ]));
        $ids  = array_map(static fn ($e) => (int) ((array) $e)['id'], $list['entries']);
        sort($ids);
        $expected = $fixture['entry_ids'];
        sort($expected);
        $this->assertSame($expected, $ids, "{$slug}: exactly the fixture form's entries, none from the other form");
        $this->assertSame(count($expected), (int) $list['total']);

        $page = self::ok($integration->handle_read([ 'operation' => 'list-entries', 'args' => [ 'form_id' => $fixture['form_id'], 'page_size' => 1, 'offset' => 1 ] ]));
        $this->assertCount(1, $page['entries'], "{$slug}: page_size + offset page the listing");
        $this->assertSame(count($expected), (int) $page['total'], "{$slug}: total is the full count, not the page size");

        foreach ($fixture['entry_ids'] as $id) {
            $entry = self::ok($integration->handle_read([ 'operation' => 'get-entry', 'args' => [ 'entry_id' => $id ] + $fixture['entry_args'] ]));
            $this->assertNotNull($entry['entry']);
            $this->assertSame($id, (int) ((array) $entry['entry'])['id']);
        }
    }

    /** @dataProvider adapters */
    public function test_fixture_status_change_is_snapshotted_and_undone_by_rollback(string $class): void
    {
        $integration = new $class();
        $slug        = $integration->integration();
        $ops         = self::catalog_ops($integration);
        $fixture     = $this->fixture($slug);
        if (! isset($ops['update-entry-status'])) {
            $this->assertNull($fixture['status'], "{$slug}: a fixture status scenario without the op is a stale fixture");
            return;
        }
        $this->assertNotNull($fixture['status'], "{$slug} offers update-entry-status, so its fixture must exercise it");
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));

        $id   = $fixture['entry_ids'][0];
        $read = $fixture['status']['read'];
        $this->assertSame($fixture['status']['from'], $read($id));

        $out = $integration->handle_write([
            'operation' => 'update-entry-status',
            'args'      => [ 'entry_id' => $id, 'status' => $fixture['status']['to'] ],
        ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $this->assertTrue($out['recoverable'], "{$slug}: the status change must be snapshot-backed");
        $this->assertNotEmpty($out['operation_id']);
        $this->assertTrue($out['result']['changed']);
        $this->assertSame($fixture['status']['from'], $out['result']['previous_status']);
        $this->assertSame($fixture['status']['to'], $read($id));

        $undo = ( new Rollback_Operation() )->handle([ 'operation_id' => $out['operation_id'] ]);
        $this->assertTrue($undo['restored'], wp_json_encode($undo));
        $this->assertSame($fixture['status']['from'], $read($id), "{$slug}: rollback-operation restores the previous status");

        $missing = $integration->handle_write([
            'operation' => 'update-entry-status',
            'args'      => [ 'entry_id' => 987654, 'status' => $fixture['status']['to'] ],
        ]);
        $this->assertSame('entry_not_found', $missing['error']['code'] ?? null, "{$slug}: a missing entry is a structured refusal");
    }

    // ---------------------------------------------------------------- helpers

    /** @return array<string, array> catalog ops keyed by op name. */
    protected static function catalog_ops(Integration_Dispatcher $integration): array
    {
        return array_column($integration->catalog()['operations'], null, 'name');
    }

    /** The result of a dispatcher call that must have succeeded. */
    protected static function ok(array $out): array
    {
        if (isset($out['error'])) {
            self::fail('Expected a result, got an error: ' . wp_json_encode($out['error']));
        }
        return (array) $out['result'];
    }
}
