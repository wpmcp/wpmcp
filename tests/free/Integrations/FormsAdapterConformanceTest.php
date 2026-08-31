<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Contact_Form_7_Integration;
use WPMCP\Integrations\Fluent_Forms_Integration;
use WPMCP\Integrations\Formidable_Integration;
use WPMCP\Integrations\Forminator_Integration;
use WPMCP\Integrations\Gravity_Forms_Integration;
use WPMCP\Integrations\Integration_Dispatcher;
use WPMCP\Integrations\MetForm_Integration;
use WPMCP\Integrations\Ninja_Forms_Integration;
use WPMCP\Integrations\SureForms_Integration;
use WPMCP\Integrations\WPForms_Integration;

// Every host-plugin double this suite depends on, required HERE rather than
// inherited from whichever sibling test file happens to run first. Without
// these the availability-dependent assertions below silently degrade into
// skips, and the "runs over all nine adapters" claim stops being true.
require_once __DIR__ . '/../../support/forms-stubs.php';
require_once __DIR__ . '/../../support/gfapi-stub.php';
require_once __DIR__ . '/../../support/ninjaforms-stubs.php';
require_once __DIR__ . '/../../support/fluentforms-stubs.php';
require_once __DIR__ . '/../../support/forminator-stubs.php';
require_once __DIR__ . '/../../support/sureforms-stubs.php';

/**
 * Shared conformance suite for the forms adapters (issue #66): one contract,
 * run against every adapter, independent of whether its host plugin is
 * installed in the harness. Per-plugin behavior stays in the per-adapter tests;
 * this file only asserts what every forms adapter must agree on, so a new
 * adapter cannot ship with a different op vocabulary, a missing guard on a
 * destructive op, or a catalog that fatals when the plugin is absent.
 */
class FormsAdapterConformanceTest extends \WP_UnitTestCase
{
    /** @return array<string, array{0: class-string<Integration_Dispatcher>}> */
    public static function adapters(): array
    {
        return [
            'contactform7' => [ Contact_Form_7_Integration::class ],
            'wpforms'      => [ WPForms_Integration::class ],
            'gravityforms' => [ Gravity_Forms_Integration::class ],
            'formidable'   => [ Formidable_Integration::class ],
            'ninjaforms'   => [ Ninja_Forms_Integration::class ],
            'fluentforms'  => [ Fluent_Forms_Integration::class ],
            'forminator'   => [ Forminator_Integration::class ],
            'metform'      => [ MetForm_Integration::class ],
            'sureforms'    => [ SureForms_Integration::class ],
        ];
    }

    /**
     * list-operations is the reserved op that must answer for every adapter,
     * so an agent can always discover the surface without risking a fatal.
     * The genuinely host-plugin-absent case is
     * test_catalog_still_answers_when_the_host_plugin_is_absent below.
     *
     * @dataProvider adapters
     */
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
     * catalog must still answer with the full op list and available:false, and
     * every other op must be a structured integration_unavailable error.
     *
     * @dataProvider adapters
     */
    public function test_catalog_still_answers_when_the_host_plugin_is_absent(string $class): void
    {
        $integration = new class ($class) extends Integration_Dispatcher {
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
    public function test_every_adapter_exposes_the_shared_form_vocabulary(string $class): void
    {
        $ops = self::catalog_ops(new $class());

        $this->assertArrayHasKey('list-forms', $ops, 'Every forms adapter must list forms');
        $this->assertArrayHasKey('get-form', $ops, 'Every forms adapter must read one form');
        $this->assertSame('read', $ops['list-forms']['mode']);
        $this->assertSame('read', $ops['get-form']['mode']);
        $this->assertSame([ 'form_id' ], $ops['get-form']['input_schema']['required']);
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
        if (isset($props['offset']) || isset($props['page_size'])) {
            $this->assertArrayHasKey('offset', $props, 'Paging is page_size + offset');
            $this->assertArrayHasKey('page_size', $props, 'Paging is page_size + offset');
        }
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
     * Entry reads are PII. Whatever else an adapter does, an op that reads or
     * lists entries must accept a form_id so a caller can scope the read, and
     * must not be a write.
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
     * Deleting a submission destroys user data, so every adapter that offers
     * it must demand confirm:true and manage_options, never the pair's own
     * lower capability.
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
        $this->assertContains(
            $ops['delete-entry']['capability'],
            [ 'manage_options', 'edit_users' ],
            'Entry deletion must sit behind an administrator-only capability, never the pair\'s own edit_posts'
        );
        $this->assertNotSame(
            (new $class())->capability(),
            $ops['delete-entry']['capability'],
            'A per-op guard that equals the pair capability is not a guard'
        );
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
        // No skipping. When the host plugin is genuinely absent the contract
        // is still a structured top-level error, just a different code, and
        // that is the case worth covering rather than stepping over.
        $expected = $integration->is_available() ? 'unknown_operation' : 'integration_unavailable';

        foreach ([ 'handle_read', 'handle_write' ] as $half) {
            $out = $integration->{$half}([ 'operation' => 'no-such-op' ]);

            $this->assertArrayNotHasKey('result', $out, "{$half} must not wrap a refusal in a success envelope");
            $this->assertSame($expected, $out['error']['code']);
            $this->assertNotEmpty($out['error']['message']);
        }
    }

    /** @return array<string, array> catalog ops keyed by op name. */
    private static function catalog_ops(Integration_Dispatcher $integration): array
    {
        return array_column($integration->catalog()['operations'], null, 'name');
    }
}
