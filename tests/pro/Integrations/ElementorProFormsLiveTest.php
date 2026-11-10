<?php

namespace WPMCP\Tests\Pro\Integrations;

use ElementorPro\Modules\Forms\Submissions\Database\Query;
use ElementorPro\Modules\Forms\Submissions\Database\Repositories\Form_Snapshot_Repository;
use WPMCP\Integrations\Elementor_Pro_Forms_Pack;
use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * Issue #654: the Elementor Pro submissions ops against a real Elementor Pro.
 *
 * It runs only in the local gate's live Elementor Pro leg
 * (bin/test-local.sh --live-elementor-pro), which sets
 * WPMCP_LIVE_ELEMENTOR_PRO=1, copies Elementor Pro from
 * WPMCP_ELEMENTOR_PRO_SRC onto a separate WordPress install beside the
 * pinned free Elementor (Elementor Pro is commercial, not on wordpress.org),
 * loads it in tests/bootstrap.php and builds its submissions tables through
 * its own Migration::install(). No license key is set.
 *
 * The page is saved through Elementor's own document API, submissions are
 * stored through Elementor Pro's own Query::add_submission() and
 * Form_Snapshot_Repository, and what the writes leave is read back through
 * Query::get_submission(), so the ops agree with Elementor Pro on both sides.
 *
 * Everywhere else Elementor Pro is absent and the test is skipped; the live
 * leg runs with --fail-on-skipped so it cannot pass vacuously.
 *
 * @group elementor-pro-live
 */
class ElementorProFormsLiveTest extends \WP_UnitTestCase
{
    private int $page;

    protected function setUp(): void
    {
        parent::setUp();
        if (! defined('ELEMENTOR_PRO_VERSION') || ! class_exists(Query::class)) {
            $this->markTestSkipped('Needs the real Elementor Pro (bin/test-local.sh --live-elementor-pro, WPMCP_ELEMENTOR_PRO_SRC).');
        }
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        add_filter('wpmcp_enable_elementor_pro_write', '__return_true');
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        Form_Snapshot_Repository::instance()->clear_cache();
        // Elementor renders a document's settings through the active kit.
        $kits = \Elementor\Plugin::$instance->kits_manager;
        if (! $kits->get_active_id() || ! get_post((int) $kits->get_active_id())) {
            update_option('elementor_active_kit', \Elementor\Core\Kits\Manager::create_default_kit());
        }

        $this->page = self::factory()->post->create([ 'post_type' => 'page', 'post_title' => 'Contact', 'post_status' => 'publish' ]);
        $document   = \Elementor\Plugin::$instance->documents->get($this->page, false);
        $document->save([
            'elements' => [
                [
                    'id'       => 'c0ffee1',
                    'elType'   => 'container',
                    'settings' => [],
                    'elements' => [
                        [
                            'id'         => 'a1b2c3d',
                            'elType'     => 'widget',
                            'widgetType' => 'form',
                            'settings'   => [
                                'form_name'      => 'Contact form',
                                'form_fields'    => [
                                    [ '_id' => 'f1', 'custom_id' => 'name', 'field_type' => 'text', 'field_label' => 'Your name' ],
                                    [ '_id' => 'f2', 'custom_id' => 'email', 'field_type' => 'email', 'field_label' => 'Your email', 'required' => 'true' ],
                                ],
                                'submit_actions' => [ 'save-to-database' ],
                            ],
                            'elements'   => [],
                        ],
                    ],
                ],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_enable_elementor_pro_write');
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function call(string $half, string $op, array $args, bool $confirm = false): array
    {
        $integration = new Plugin_Data_Integration();
        $call        = [ 'operation' => $op, 'args' => $args ] + ($confirm ? [ 'confirm' => true ] : []);
        $out         = 'read' === $half ? $integration->handle_read($call) : $integration->handle_write($call);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out['result'] ?? [];
    }

    /** A submission stored the way Save_To_Database stores one. */
    private function submit(string $name, string $email): int
    {
        Form_Snapshot_Repository::instance()->create_or_update($this->page, 'a1b2c3d', [
            'name'   => 'Contact form',
            'fields' => [
                [ 'id' => 'name', 'type' => 'text', 'label' => 'Your name' ],
                [ 'id' => 'email', 'type' => 'email', 'label' => 'Your email' ],
            ],
        ]);
        $id = (int) Query::get_instance()->add_submission(
            [
                'main_meta_id'            => 0,
                'post_id'                 => $this->page,
                'referer'                 => 'https://example.org/contact/',
                'referer_title'           => 'Contact',
                'element_id'              => 'a1b2c3d',
                'form_name'               => 'Contact form',
                'campaign_id'             => 0,
                'user_id'                 => 0,
                'user_ip'                 => '203.0.113.7',
                'user_agent'              => 'Mozilla/5.0 (live)',
                'actions_count'           => 1,
                'actions_succeeded_count' => 0,
                'meta'                    => wp_json_encode([ 'edit_post_id' => (string) $this->page ]),
            ],
            [
                [ 'id' => 'name', 'type' => 'text', 'value' => $name ],
                [ 'id' => 'email', 'type' => 'email', 'value' => $email ],
            ]
        );
        $this->assertGreaterThan(0, $id, 'Elementor Pro stored the submission');
        return $id;
    }

    public function test_elementor_pro_is_detected_and_its_tables_exist(): void
    {
        $this->assertTrue(Elementor_Pro_Forms_Pack::active());
        $this->assertTrue(Elementor_Pro_Forms_Pack::tables_exist());
        $this->assertSame(Query::get_instance()->get_table_submissions(), Elementor_Pro_Forms_Pack::table());
        $this->assertSame(Query::get_instance()->get_table_submissions_values(), Elementor_Pro_Forms_Pack::table('values'));
        $this->assertSame(Query::get_instance()->get_table_form_actions_log(), Elementor_Pro_Forms_Pack::table('actions_log'));
        $ops = array_column((new Plugin_Data_Integration())->catalog()['operations'], null, 'name');
        $this->assertTrue($ops['elementor-delete-submission']['dependency_met']);
    }

    public function test_a_form_saved_through_elementor_is_listed_with_its_submissions(): void
    {
        $this->submit('Ada', 'ada@example.org');
        $forms = array_column($this->call('read', 'elementor-list-forms', [ 'post_id' => $this->page ])['forms'], null, 'form');
        $form  = $forms[ $this->page . '_a1b2c3d' ] ?? null;
        $this->assertNotNull($form, (string) wp_json_encode($forms));
        $this->assertSame('Contact form', $form['name']);
        $this->assertSame([ 'name', 'email' ], array_column($form['fields'], 'id'));
        $this->assertTrue($form['saves_submissions']);
        $this->assertSame(1, $form['submissions']['total']);
    }

    public function test_submissions_stored_by_elementor_pro_read_back_like_its_own_query(): void
    {
        $id   = $this->submit('Ada', 'ada@example.org');
        $own  = Query::get_instance()->get_submission($id)['data'];
        $list = $this->call('read', 'elementor-list-submissions', [ 'form' => $this->page . '_a1b2c3d' ]);
        $this->assertSame([ $id ], array_column($list['submissions'], 'id'));
        $this->assertSame($own['main']['value'], $list['submissions'][0]['main']['value']);

        $read = $this->call('read', 'elementor-get-submission', [ 'id' => $id ])['submission'];
        $this->assertSame(array_column($own['values'], 'value', 'key'), array_column($read['values'], 'value', 'key'));
        $this->assertSame('Your email', $read['values'][1]['label']);
        $this->assertSame($own['status'], $read['status']);
        $this->assertSame($own['is_read'], $read['is_read']);
        $this->assertSame($own['created_at'], $read['created_at']);

        $csv = $this->call('read', 'elementor-export-submissions', [ 'form' => $this->page . '_a1b2c3d' ])['csv'];
        $this->assertStringStartsWith('"Your name","Your email","Form Name (ID)"', $csv);
        $this->assertStringContainsString('"ada@example.org"', $csv);
    }

    public function test_a_status_change_is_what_elementor_pro_reads_and_rolls_back(): void
    {
        $id  = $this->submit('Ada', 'ada@example.org');
        $out = (new Plugin_Data_Integration())->handle_write([ 'operation' => 'elementor-update-submission-status', 'args' => [ 'id' => $id, 'status' => 'trash', 'is_read' => true ] ]);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));

        $own = Query::get_instance()->get_submission($id)['data'];
        $this->assertSame('trash', $own['status']);
        $this->assertTrue($own['is_read']);
        $this->assertSame(1, Query::get_instance()->count_submissions_by_status()->get('trash'));

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $own = Query::get_instance()->get_submission($id)['data'];
        $this->assertSame('new', $own['status']);
        $this->assertFalse($own['is_read']);
    }

    public function test_a_delete_leaves_nothing_for_elementor_pro_and_rollback_brings_it_all_back(): void
    {
        $id    = $this->submit('Ada', 'ada@example.org');
        Query::get_instance()->add_action_log($id, new class () {
            public function get_name()
            {
                return 'save-to-database';
            }

            public function get_label()
            {
                return 'Collect Submissions';
            }
        }, Query::ACTIONS_LOG_STATUS_SUCCESS);
        $other  = $this->submit('Bob', 'bob@example.org');
        $before = Query::get_instance()->get_submission($id)['data'];

        $out = (new Plugin_Data_Integration())->handle_write([ 'operation' => 'elementor-delete-submission', 'args' => [ 'id' => $id ], 'confirm' => true ]);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertNull(Query::get_instance()->get_submission($id));
        $this->assertSame([], Query::get_instance()->get_submissions_actions_log($id));
        $this->assertNotNull(Query::get_instance()->get_submission($other));

        $this->assertSame(3, Rollback_Service::restore_session($out['session_id']));
        $after = Query::get_instance()->get_submission($id)['data'];
        unset($before['post']['edit_url'], $after['post']['edit_url']);
        $this->assertSame($before, $after);
        $this->assertSame('save-to-database', $after['form_actions_log'][0]['name']);
    }
}
