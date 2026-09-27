<?php

namespace WPMCP\Tests\Free\Integrations;

use SRFM\Inc\Database\Tables\Entries;
use WPMCP\Integrations\Contact_Form_7_Integration;
use WPMCP\Integrations\Forminator_Integration;
use WPMCP\Integrations\Forms_Integration;
use WPMCP\Integrations\MetForm_Integration;
use WPMCP\Integrations\SureForms_Integration;
use WPMCP\Tests\Support\Forms_Adapter_Conformance;

// Every host-plugin double this suite depends on, required HERE rather than
// inherited from whichever sibling test file happens to run first. Without
// these the fixture scenarios would silently degrade.
require_once __DIR__ . '/../../support/forms-stubs.php';
require_once __DIR__ . '/../../support/forminator-stubs.php';
require_once __DIR__ . '/../../support/sureforms-stubs.php';
require_once __DIR__ . '/../../support/Forms_Adapter_Conformance.php';

/**
 * The shared forms conformance suite (issue #66) over the free-tier adapters.
 * The contract and the scenario live in tests/support/Forms_Adapter_Conformance;
 * this class supplies the adapters, the operations each must offer, and one
 * fixture per adapter seeded through that plugin's own double. The adapter
 * pack runs the same suite in tests/pro/Integrations/FormsPackConformanceTest.
 */
class FormsAdapterConformanceTest extends Forms_Adapter_Conformance
{
    public static function adapters(): array
    {
        return [
            'contactform7' => [ Contact_Form_7_Integration::class ],
            'forminator'   => [ Forminator_Integration::class ],
            'metform'      => [ MetForm_Integration::class ],
            'sureforms'    => [ SureForms_Integration::class ],
        ];
    }

    protected static function expected_operations(string $slug): array
    {
        $entries = [ 'list-entries', 'get-entry', 'delete-entry' ];
        return [
            // The free-tier target of issue #66 carries the whole vocabulary:
            // forms, fields, notifications, entries, entry status.
            'contactform7' => array_merge([ 'list-fields', 'list-notifications', 'update-entry-status' ], $entries),
            'forminator'   => $entries,
            'metform'      => $entries,
            'sureforms'    => $entries,
        ][ $slug ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        \Flamingo_Inbound_Message::register();
        \WPCF7_ContactForm::$registry = [];
        \Forminator_API::reset();
        register_post_type('metform-form', [ 'public' => true, 'label' => 'Forms' ]);
        register_post_type('metform-entry', [ 'public' => true, 'label' => 'Entries' ]);
        register_post_type('sureforms_form', [ 'public' => true, 'label' => 'Forms' ]);
        Entries::reset();
    }

    protected function tearDown(): void
    {
        \Forminator_API::reset();
        Entries::reset();
        unregister_post_type('sureforms_form');
        unregister_post_type('metform-entry');
        unregister_post_type('metform-form');
        parent::tearDown();
    }

    protected function fixture(string $slug): array
    {
        return $this->{'fixture_' . $slug}();
    }

    private function fixture_contactform7(): array
    {
        $channel = (int) wp_insert_term('Contact', \Flamingo_Inbound_Message::channel_taxonomy)['term_id'];
        $other   = (int) wp_insert_term('Other', \Flamingo_Inbound_Message::channel_taxonomy)['term_id'];
        $form_id = self::factory()->post->create([ 'post_title' => 'Contact' ]);
        \WPCF7_ContactForm::seed($form_id, 'Contact', 'contact', [
            'form'   => '[text* your-name] [email* your-email] [select topic "Sales" "Support"] [submit "Send"]',
            'mail'   => [ 'recipient' => 'admin@example.test', 'subject' => 'New message', 'body' => '[your-name]' ],
            'mail_2' => [ 'active' => false, 'recipient' => '[your-email]', 'subject' => 'Thanks' ],
        ]);
        update_post_meta($form_id, '_flamingo', [ 'channel' => $channel ]);

        $ids = [];
        foreach ([ 'ada', 'grace' ] as $i => $who) {
            $ids[] = \Flamingo_Inbound_Message::seed([
                'subject'    => 'Hello from ' . $who,
                'from_email' => $who . '@example.test',
                'fields'     => [ 'your-name' => $who ],
                'channel'    => $channel,
                'post_date'  => sprintf('2026-08-%02d 10:00:00', 10 + $i),
            ]);
        }
        \Flamingo_Inbound_Message::seed([ 'subject' => 'Other form', 'channel' => $other ]);

        return [
            'form_id'    => $form_id,
            'entry_ids'  => array_reverse($ids),
            'entry_args' => [],
            'status'     => [
                'from' => 'inbox',
                'to'   => 'trash',
                'read' => static fn (int $id): string => 'trash' === get_post_status($id) ? 'trash' : 'inbox',
            ],
        ];
    }

    private function fixture_forminator(): array
    {
        \Forminator_API::$forms = [
            11 => new \Forminator_Test_Form(11, 'contact', [ 'formName' => 'Contact' ], 'publish', [
                new \Forminator_Test_Field([ 'element_id' => 'email-1', 'type' => 'email', 'field_label' => 'Email', 'required' => true ]),
            ]),
            12 => new \Forminator_Test_Form(12, 'other', [], 'publish'),
        ];
        \Forminator_API::$entries = [
            101 => new \Forminator_Test_Entry(101, 11, '2026-02-01 10:00:00', [ 'email-1' => [ 'value' => 'a@example.test' ] ]),
            102 => new \Forminator_Test_Entry(102, 11, '2026-02-02 10:00:00', [ 'email-1' => [ 'value' => 'b@example.test' ] ]),
            103 => new \Forminator_Test_Entry(103, 12, '2026-02-03 10:00:00', []),
        ];
        return [ 'form_id' => 11, 'entry_ids' => [ 102, 101 ], 'entry_args' => [ 'form_id' => 11 ], 'status' => null ];
    }

    private function fixture_metform(): array
    {
        $form_id = self::factory()->post->create([ 'post_type' => 'metform-form', 'post_title' => 'Signup' ]);
        $other   = self::factory()->post->create([ 'post_type' => 'metform-form', 'post_title' => 'Other' ]);
        $ids     = [];
        foreach ([ $form_id, $form_id, $other ] as $i => $owner) {
            $id = self::factory()->post->create([ 'post_type' => 'metform-entry', 'post_title' => 'Entry ' . $i ]);
            update_post_meta($id, 'metform_entries__form_id', $owner);
            update_post_meta($id, 'metform_entries__form_data', [ 'email' => $i . '@example.test' ]);
            if ($owner === $form_id) {
                $ids[] = $id;
            }
        }
        return [ 'form_id' => $form_id, 'entry_ids' => $ids, 'entry_args' => [], 'status' => null ];
    }

    private function fixture_sureforms(): array
    {
        $form_id = self::factory()->post->create([
            'post_type'    => 'sureforms_form',
            'post_title'   => 'Contact',
            'post_content' => '<!-- wp:srfm/input {"slug":"name","label":"Your name","required":true} /-->',
        ]);
        Entries::seed(201, $form_id, 'unread', [ 'name' => 'Ada' ]);
        Entries::seed(202, $form_id, 'read', [ 'name' => 'Grace' ]);
        Entries::seed(203, $form_id + 1000, 'unread', [ 'name' => 'Other form' ]);
        return [ 'form_id' => $form_id, 'entry_ids' => [ 201, 202 ], 'entry_args' => [], 'status' => null ];
    }

    /**
     * The adapter list the registration and smoke tests rely on
     * (tests/support/forms-adapters.php) must be exactly the concrete
     * Forms_Integration subclasses shipped in src/Integrations, and this suite
     * plus the pack suite must between them cover every one of them at its
     * declared tier.
     */
    public function test_the_forms_adapter_list_is_complete_and_tiered(): void
    {
        $found = [];
        foreach (glob(dirname(__DIR__, 3) . '/src/Integrations/*_Integration.php') as $file) {
            $class = 'WPMCP\\Integrations\\' . basename($file, '.php');
            $ref   = new \ReflectionClass($class);
            if (! $ref->isAbstract() && $ref->isSubclassOf(Forms_Integration::class)) {
                $found[ ( new $class() )->integration() ] = $class;
            }
        }
        $listed = wpmcp_forms_adapter_classes();
        ksort($found);
        ksort($listed);
        $this->assertSame($listed, $found);

        foreach (self::adapters() as $slug => [ $class ]) {
            $this->assertSame('free', ( new $class() )->tier(), "{$slug} is a free-tier forms adapter");
        }
        foreach (array_diff_key($listed, self::adapters()) as $slug => $class) {
            $this->assertSame('pro', ( new $class() )->tier(), "{$slug} belongs to the adapter pack");
        }
    }
}
