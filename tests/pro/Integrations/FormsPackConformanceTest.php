<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Fluent_Forms_Integration;
use WPMCP\Integrations\Formidable_Integration;
use WPMCP\Integrations\Gravity_Forms_Integration;
use WPMCP\Integrations\Ninja_Forms_Integration;
use WPMCP\Integrations\WPForms_Integration;
use WPMCP\Tests\Support\Forms_Adapter_Conformance;

require_once __DIR__ . '/../../support/forms-stubs.php';
require_once __DIR__ . '/../../support/gfapi-stub.php';
require_once __DIR__ . '/../../support/ninjaforms-stubs.php';
require_once __DIR__ . '/../../support/fluentforms-stubs.php';
require_once __DIR__ . '/../../support/Forms_Adapter_Conformance.php';

/**
 * The shared forms conformance suite (issue #66) over the forms adapter pack:
 * WPForms, Gravity Forms, Formidable, Ninja Forms and Fluent Forms. Same
 * contract and scenario as the free adapters
 * (tests/support/Forms_Adapter_Conformance), each run against that plugin's
 * documented-API double. The paid plugins cannot be installed in CI, so these
 * doubles are the coverage; live verification against each plugin is a
 * release step (docs/release-checklist.md).
 */
class FormsPackConformanceTest extends Forms_Adapter_Conformance
{
    private const VOCABULARY = [ 'list-fields', 'list-notifications', 'list-entries', 'get-entry' ];

    public static function set_up_before_class(): void
    {
        parent::set_up_before_class();
        // DDL outside any test transaction: the entry tables the db_rows
        // snapshots are taken from.
        \GFAPI::install_table();
        \WPMCP_WPForms_Entry_Stub::install();
        \FF_Test_DB::install();
    }

    public static function tear_down_after_class(): void
    {
        \GFAPI::uninstall_table();
        \WPMCP_WPForms_Entry_Stub::uninstall();
        \FF_Test_DB::uninstall();
        parent::tear_down_after_class();
    }

    public static function adapters(): array
    {
        return [
            'wpforms'      => [ WPForms_Integration::class ],
            'gravityforms' => [ Gravity_Forms_Integration::class ],
            'formidable'   => [ Formidable_Integration::class ],
            'ninjaforms'   => [ Ninja_Forms_Integration::class ],
            'fluentforms'  => [ Fluent_Forms_Integration::class ],
        ];
    }

    protected static function expected_operations(string $slug): array
    {
        // Formidable has no spam or trash state for an entry, so there is no
        // status to update; every other pack adapter models one.
        return 'formidable' === $slug ? self::VOCABULARY : array_merge(self::VOCABULARY, [ 'update-entry-status' ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        \GFAPI::reset();
        \WPMCP_WPForms_Stub::$lite = false;
        wpforms()->form->forms     = [];
        \FrmForm::$forms           = [];
        \FrmEntry::$entries        = [];
        \FrmField::$fields         = [];
        \FrmFormAction::$actions   = [];
        \NF_Test_FormHandler::$forms   = [];
        \NF_Test_FormHandler::$actions = [];
        \NF_Test_Sub::register();
    }

    protected function tearDown(): void
    {
        unregister_post_type('nf_sub');
        parent::tearDown();
    }

    public function test_every_pack_adapter_is_pro_tier(): void
    {
        foreach (self::adapters() as $slug => [ $class ]) {
            $this->assertSame('pro', ( new $class() )->tier(), "{$slug} is part of the pro adapter pack");
        }
    }

    protected function fixture(string $slug): array
    {
        return $this->{'fixture_' . $slug}();
    }

    private function fixture_gravityforms(): array
    {
        \GFAPI::$forms = [
            1 => [
                'id'            => 1,
                'title'         => 'Contact',
                'fields'        => [ [ 'id' => 1, 'type' => 'text', 'label' => 'Name', 'isRequired' => true ] ],
                'notifications' => [ 'n1' => [ 'id' => 'n1', 'name' => 'Admin', 'to' => '{admin_email}', 'subject' => 'New entry' ] ],
            ],
            2 => [ 'id' => 2, 'title' => 'Other', 'fields' => [] ],
        ];
        $a = \GFAPI::seed_entry(1, [ '1' => 'Ada' ]);
        $b = \GFAPI::seed_entry(1, [ '1' => 'Grace' ]);
        \GFAPI::seed_entry(2, [ '1' => 'Other form' ]);
        return [
            'form_id'    => 1,
            'entry_ids'  => [ $b, $a ],
            'entry_args' => [],
            'status'     => [
                'from' => 'active',
                'to'   => 'spam',
                'read' => static fn (int $id): string => (string) \GFAPI::get_entry($id)['status'],
            ],
        ];
    }

    private function fixture_wpforms(): array
    {
        $content = wp_json_encode([
            'fields'   => [ [ 'id' => 1, 'type' => 'email', 'label' => 'Email', 'required' => '1' ] ],
            'settings' => [
                'notification_enable' => '1',
                'notifications'       => [ 1 => [ 'notification_name' => 'Default', 'email' => '{admin_email}', 'subject' => 'New entry' ] ],
            ],
        ]);
        wpforms()->form->forms = [
            3 => new \WP_Post((object) [ 'ID' => 3, 'post_title' => 'Newsletter', 'post_content' => $content, 'post_type' => 'wpforms' ]),
        ];
        $a = \WPMCP_WPForms_Entry_Stub::seed([ 'form_id' => 3, 'fields' => [ [ 'value' => 'a@example.test' ] ] ]);
        $b = \WPMCP_WPForms_Entry_Stub::seed([ 'form_id' => 3, 'fields' => [ [ 'value' => 'b@example.test' ] ] ]);
        \WPMCP_WPForms_Entry_Stub::seed([ 'form_id' => 4 ]);
        return [
            'form_id'    => 3,
            'entry_ids'  => [ $b, $a ],
            'entry_args' => [],
            'status'     => [
                'from' => 'active',
                'to'   => 'spam',
                'read' => static fn (int $id): string => (string) ( wpforms()->obj('entry')->get($id)->status ?: 'active' ),
            ],
        ];
    }

    private function fixture_formidable(): array
    {
        \FrmForm::$forms   = [ 5 => (object) [ 'id' => 5, 'name' => 'Booking', 'form_key' => 'booking' ] ];
        \FrmField::$fields = [ 5 => [ (object) [ 'id' => 50, 'field_key' => 'name', 'name' => 'Name', 'type' => 'text', 'required' => '1' ] ] ];
        \FrmFormAction::$actions = [ 5 => [ (object) [
            'ID'           => 70,
            'post_title'   => 'Email Notification',
            'post_status'  => 'publish',
            'post_excerpt' => 'email',
            'post_content' => [ 'email_to' => '[admin_email]', 'email_subject' => 'New booking' ],
        ] ] ];
        \FrmEntry::$entries = [
            500 => (object) [ 'id' => 500, 'form_id' => 5, 'item_key' => 'a', 'created_at' => '2026-03-01 10:00:00', 'metas' => [ 50 => 'Ada' ] ],
            501 => (object) [ 'id' => 501, 'form_id' => 5, 'item_key' => 'b', 'created_at' => '2026-03-02 10:00:00', 'metas' => [ 50 => 'Grace' ] ],
            502 => (object) [ 'id' => 502, 'form_id' => 9, 'item_key' => 'c', 'created_at' => '2026-03-03 10:00:00' ],
        ];
        return [ 'form_id' => 5, 'entry_ids' => [ 501, 500 ], 'entry_args' => [], 'status' => null ];
    }

    private function fixture_ninjaforms(): array
    {
        \NF_Test_FormHandler::$forms = [
            8 => new \NF_Test_Form(8, [ 'title' => 'Contact' ], [
                new \NF_Test_Field(80, [ 'key' => 'email', 'type' => 'email', 'label' => 'Your email', 'required' => 1 ]),
            ]),
        ];
        \NF_Test_FormHandler::$actions = [
            8 => [
                new \NF_Test_Action(90, [ 'type' => 'email', 'label' => 'Admin email', 'to' => '{wp:admin_email}', 'email_subject' => 'New submission', 'active' => '1' ]),
                new \NF_Test_Action(91, [ 'type' => 'successmessage', 'label' => 'Thanks' ]),
            ],
        ];
        $a = \NF_Test_Sub::seed(8, 1, [ 80 => 'a@example.test' ], '2026-04-01 10:00:00');
        $b = \NF_Test_Sub::seed(8, 2, [ 80 => 'b@example.test' ], '2026-04-02 10:00:00');
        \NF_Test_Sub::seed(9, 1, [], '2026-04-03 10:00:00');
        return [
            'form_id'    => 8,
            'entry_ids'  => [ $b, $a ],
            'entry_args' => [],
            'status'     => [
                'from' => 'active',
                'to'   => 'trash',
                'read' => static fn (int $id): string => 'trash' === get_post_status($id) ? 'trash' : 'active',
            ],
        ];
    }

    private function fixture_fluentforms(): array
    {
        $form_id = \FF_Test_DB::seed('fluentform_forms', [
            'title'       => 'Contact',
            'form_fields' => wp_json_encode([ 'fields' => [ [
                'element'    => 'input_email',
                'attributes' => [ 'name' => 'email' ],
                'settings'   => [ 'label' => 'Email', 'validation_rules' => [ 'required' => [ 'value' => true ] ] ],
            ] ] ]),
        ]);
        \FF_Test_DB::seed('fluentform_form_meta', [
            'form_id'  => $form_id,
            'meta_key' => 'notifications', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a column of Fluent Forms' own table, not a postmeta query.
            'value'    => wp_json_encode([ 'name' => 'Admin Notification', 'enabled' => true, 'sendTo' => [ 'type' => 'email', 'email' => '{wp.admin_email}' ], 'subject' => 'New entry' ]),
        ]);
        $a = \FF_Test_DB::seed('fluentform_submissions', [ 'form_id' => $form_id, 'serial_number' => 1, 'status' => 'unread', 'response' => wp_json_encode([ 'email' => 'a@example.test' ]) ]);
        $b = \FF_Test_DB::seed('fluentform_submissions', [ 'form_id' => $form_id, 'serial_number' => 2, 'status' => 'read', 'response' => wp_json_encode([ 'email' => 'b@example.test' ]) ]);
        \FF_Test_DB::seed('fluentform_submissions', [ 'form_id' => $form_id, 'serial_number' => 3, 'status' => 'trashed' ]);
        \FF_Test_DB::seed('fluentform_submissions', [ 'form_id' => $form_id + 1, 'serial_number' => 1, 'status' => 'unread' ]);
        return [
            'form_id'    => $form_id,
            'entry_ids'  => [ $b, $a ],
            'entry_args' => [],
            'status'     => [
                'from' => 'read',
                'to'   => 'spam',
                'read' => static fn (int $id): string => (string) wpFluent()->table('fluentform_submissions')->where('id', $id)->first()->status,
            ],
        ];
    }
}
