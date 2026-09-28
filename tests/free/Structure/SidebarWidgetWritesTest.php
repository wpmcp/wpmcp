<?php

namespace WPMCP\Tests\Free\Structure;

use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Rollback_Operation;
use WPMCP\Tools\Structure\Create_Sidebar_Widget;
use WPMCP\Tools\Structure\Delete_Sidebar_Widget;
use WPMCP\Tools\Structure\Move_Sidebar_Widget;
use WPMCP\Tools\Structure\Update_Sidebar_Widget;

/**
 * Classic sidebar widget writes (issue #285): create, update, move and delete
 * a widget instance, each snapshotting the widget_{id_base} option AND
 * sidebars_widgets under one operation so a rollback restores both at once.
 */
class SidebarWidgetWritesTest extends \WP_UnitTestCase
{
    private const SIDEBAR_A = 'wpmcp-widgets-a';
    private const SIDEBAR_B = 'wpmcp-widgets-b';

    /** @var array<string, mixed> */
    private array $prior = [];

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));

        foreach ([ 'sidebars_widgets', 'widget_text', 'widget_search', 'widget_block' ] as $name) {
            $this->prior[ $name ] = get_option($name);
        }

        register_sidebar([ 'id' => self::SIDEBAR_A, 'name' => 'WPMCP A' ]);
        register_sidebar([ 'id' => self::SIDEBAR_B, 'name' => 'WPMCP B' ]);

        $sidebars                    = (array) get_option('sidebars_widgets', []);
        $sidebars[ self::SIDEBAR_A ] = [];
        $sidebars[ self::SIDEBAR_B ] = [];
        update_option('sidebars_widgets', $sidebars);
    }

    protected function tearDown(): void
    {
        unregister_sidebar(self::SIDEBAR_A);
        unregister_sidebar(self::SIDEBAR_B);
        foreach ($this->prior as $name => $value) {
            if (false === $value) {
                delete_option($name);
            } else {
                update_option($name, $value);
            }
        }
        parent::tearDown();
    }

    private function create(array $args): array
    {
        return (new Create_Sidebar_Widget())->handle($args);
    }

    private function sidebar(string $id): array
    {
        return array_values((array) (get_option('sidebars_widgets')[ $id ] ?? []));
    }

    public function test_round_trip_create_update_move_delete(): void
    {
        $created = $this->create([
            'sidebar_id' => self::SIDEBAR_A,
            'id_base'    => 'text',
            'instance'   => [ 'title' => 'Hello', 'text' => 'World' ],
        ]);

        $widget_id = $created['widget_id'];
        $this->assertMatchesRegularExpression('/^text-\d+$/', $widget_id);
        $this->assertSame(self::SIDEBAR_A, $created['sidebar_id']);
        $this->assertTrue($created['recoverable']);
        $this->assertNotEmpty($created['operation_id']);
        $this->assertSame([ $widget_id ], $this->sidebar(self::SIDEBAR_A));

        $number   = (int) substr($widget_id, 5);
        $settings = get_option('widget_text');
        $this->assertSame('Hello', $settings[ $number ]['title']);
        $this->assertSame('World', $settings[ $number ]['text']);

        $updated = (new Update_Sidebar_Widget())->handle([
            'widget_id' => $widget_id,
            'instance'  => [ 'title' => 'Changed' ],
        ]);
        $this->assertSame('Changed', $updated['instance']['title']);
        $settings = get_option('widget_text');
        $this->assertSame('Changed', $settings[ $number ]['title']);
        // Update merges over the stored instance: text was not sent, so it stays.
        $this->assertSame('World', $settings[ $number ]['text']);

        $moved = (new Move_Sidebar_Widget())->handle([
            'widget_id'  => $widget_id,
            'sidebar_id' => self::SIDEBAR_B,
        ]);
        $this->assertSame(self::SIDEBAR_B, $moved['sidebar_id']);
        $this->assertSame([], $this->sidebar(self::SIDEBAR_A));
        $this->assertSame([ $widget_id ], $this->sidebar(self::SIDEBAR_B));

        $deleted = (new Delete_Sidebar_Widget())->handle([ 'widget_id' => $widget_id ]);
        $this->assertTrue($deleted['deleted']);
        $this->assertSame([], $this->sidebar(self::SIDEBAR_B));
        $this->assertArrayNotHasKey($number, get_option('widget_text'));
    }

    public function test_create_sanitizes_through_the_widgets_own_update(): void
    {
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        add_filter('map_meta_cap', [ $this, 'deny_unfiltered_html' ], 10, 2);

        try {
            $created = $this->create([
                'sidebar_id' => self::SIDEBAR_A,
                'id_base'    => 'text',
                'instance'   => [ 'title' => '<b>T</b>', 'text' => '<script>alert(1)</script>ok' ],
            ]);
        } finally {
            remove_filter('map_meta_cap', [ $this, 'deny_unfiltered_html' ], 10);
        }

        $this->assertSame('T', $created['instance']['title']);
        $this->assertStringNotContainsString('<script>', $created['instance']['text']);
    }

    public function deny_unfiltered_html(array $caps, string $cap): array
    {
        return 'unfiltered_html' === $cap ? [ 'do_not_allow' ] : $caps;
    }

    public function test_create_rollback_restores_both_options_exactly(): void
    {
        $this->create([
            'sidebar_id' => self::SIDEBAR_A,
            'id_base'    => 'text',
            'instance'   => [ 'title' => 'Existing', 'text' => '' ],
        ]);

        $before_sidebars = get_option('sidebars_widgets');
        $before_text     = get_option('widget_text');

        $out = $this->create([
            'sidebar_id' => self::SIDEBAR_A,
            'id_base'    => 'text',
            'instance'   => [ 'title' => 'New', 'text' => 'x' ],
            'position'   => 0,
        ]);
        $this->assertSame(0, $out['position']);
        $this->assertSame($out['widget_id'], $this->sidebar(self::SIDEBAR_A)[0]);
        $this->assertNotSame($before_text, get_option('widget_text'));

        $row = Snapshot_Store::get_by_operation($out['operation_id']);
        $this->assertSame('option_set', $row['snapshot']['object_type']);
        $this->assertEqualsCanonicalizing(
            [ 'sidebars_widgets', 'widget_text' ],
            array_keys($row['snapshot']['data']['options'])
        );

        $rolled = (new Rollback_Operation())->handle([ 'operation_id' => $out['operation_id'] ]);
        $this->assertTrue($rolled['restored']);

        $this->assertSame($before_sidebars, get_option('sidebars_widgets'));
        $this->assertSame($before_text, get_option('widget_text'));
    }

    public function test_rollback_of_first_widget_deletes_an_option_that_did_not_exist(): void
    {
        delete_option('widget_search');
        $before_sidebars = get_option('sidebars_widgets');

        $out = $this->create([ 'sidebar_id' => self::SIDEBAR_A, 'id_base' => 'search' ]);
        $this->assertIsArray(get_option('widget_search'));

        Rollback_Service::restore_operation($out['operation_id']);

        $this->assertFalse(get_option('widget_search'));
        $this->assertSame($before_sidebars, get_option('sidebars_widgets'));
    }

    public function test_delete_rollback_brings_the_instance_and_placement_back(): void
    {
        $a = $this->create([ 'sidebar_id' => self::SIDEBAR_A, 'id_base' => 'text', 'instance' => [ 'title' => 'A' ] ]);
        $b = $this->create([ 'sidebar_id' => self::SIDEBAR_A, 'id_base' => 'text', 'instance' => [ 'title' => 'B' ] ]);

        $before_sidebars = get_option('sidebars_widgets');
        $before_text     = get_option('widget_text');

        $out = (new Delete_Sidebar_Widget())->handle([ 'widget_id' => $a['widget_id'] ]);
        $this->assertSame([ $b['widget_id'] ], $this->sidebar(self::SIDEBAR_A));

        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertSame($before_sidebars, get_option('sidebars_widgets'));
        $this->assertSame($before_text, get_option('widget_text'));
    }

    public function test_move_across_sidebars_at_a_position_and_to_inactive(): void
    {
        $a = $this->create([ 'sidebar_id' => self::SIDEBAR_A, 'id_base' => 'text', 'instance' => [ 'title' => 'A' ] ])['widget_id'];
        $b = $this->create([ 'sidebar_id' => self::SIDEBAR_B, 'id_base' => 'text', 'instance' => [ 'title' => 'B' ] ])['widget_id'];
        $c = $this->create([ 'sidebar_id' => self::SIDEBAR_B, 'id_base' => 'search' ])['widget_id'];

        $before = get_option('sidebars_widgets');

        $out = (new Move_Sidebar_Widget())->handle([ 'widget_id' => $a, 'sidebar_id' => self::SIDEBAR_B, 'position' => 1 ]);
        $this->assertSame(1, $out['position']);
        $this->assertSame([], $this->sidebar(self::SIDEBAR_A));
        $this->assertSame([ $b, $a, $c ], $this->sidebar(self::SIDEBAR_B));

        (new Move_Sidebar_Widget())->handle([ 'widget_id' => $c, 'sidebar_id' => self::SIDEBAR_B, 'position' => 0 ]);
        $this->assertSame([ $c, $b, $a ], $this->sidebar(self::SIDEBAR_B));

        (new Move_Sidebar_Widget())->handle([ 'widget_id' => $b, 'sidebar_id' => 'wp_inactive_widgets' ]);
        $this->assertSame([ $c, $a ], $this->sidebar(self::SIDEBAR_B));
        $this->assertContains($b, $this->sidebar('wp_inactive_widgets'));

        Rollback_Service::restore_operation($out['operation_id']);
        $this->assertSame($before, get_option('sidebars_widgets'));
    }

    public function test_session_rollback_unwinds_every_widget_write(): void
    {
        $session         = 'w-' . substr(wp_generate_uuid4(), 0, 8);
        $before_sidebars = get_option('sidebars_widgets');
        $before_text     = get_option('widget_text');

        $id = $this->create([ 'sidebar_id' => self::SIDEBAR_A, 'id_base' => 'text', 'instance' => [ 'title' => 'S' ], 'session_id' => $session ])['widget_id'];
        (new Move_Sidebar_Widget())->handle([ 'widget_id' => $id, 'sidebar_id' => self::SIDEBAR_B, 'session_id' => $session ]);
        (new Update_Sidebar_Widget())->handle([ 'widget_id' => $id, 'instance' => [ 'title' => 'T' ], 'session_id' => $session ]);

        Rollback_Service::restore_session($session);

        $this->assertSame($before_sidebars, get_option('sidebars_widgets'));
        $this->assertSame($before_text, get_option('widget_text'));
    }

    public function test_block_widgets_are_supported(): void
    {
        global $wp_widget_factory;
        $has_block = false;
        foreach ($wp_widget_factory->widgets as $widget) {
            $has_block = $has_block || 'block' === $widget->id_base;
        }
        if (! $has_block) {
            $this->markTestSkipped('This WordPress has no block widget type.');
        }

        $out = $this->create([
            'sidebar_id' => self::SIDEBAR_A,
            'id_base'    => 'block',
            'instance'   => [ 'content' => '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->' ],
        ]);
        $this->assertMatchesRegularExpression('/^block-\d+$/', $out['widget_id']);
        $this->assertStringContainsString('<p>Hi</p>', get_option('widget_block')[ (int) substr($out['widget_id'], 6) ]['content']);
    }

    public function test_unknown_widget_type_is_refused_and_writes_nothing(): void
    {
        $before = get_option('sidebars_widgets');
        try {
            $this->create([ 'sidebar_id' => self::SIDEBAR_A, 'id_base' => 'not-a-widget' ]);
            $this->fail('Expected an unknown widget type to be refused.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('not-a-widget', $e->getMessage());
        }
        $this->assertSame($before, get_option('sidebars_widgets'));
        $this->assertFalse(get_option('widget_not-a-widget'));
    }

    public function test_unregistered_sidebar_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->create([ 'sidebar_id' => 'no-such-sidebar', 'id_base' => 'text' ]);
    }

    public function test_move_to_unregistered_sidebar_is_refused(): void
    {
        $id = $this->create([ 'sidebar_id' => self::SIDEBAR_A, 'id_base' => 'text' ])['widget_id'];
        $this->expectException(\InvalidArgumentException::class);
        (new Move_Sidebar_Widget())->handle([ 'widget_id' => $id, 'sidebar_id' => 'no-such-sidebar' ]);
    }

    public function test_update_and_delete_refuse_unknown_instances(): void
    {
        foreach ([ 'text-999999', 'not-a-widget-2', 'garbage' ] as $widget_id) {
            foreach ([ new Update_Sidebar_Widget(), new Delete_Sidebar_Widget(), new Move_Sidebar_Widget() ] as $tool) {
                try {
                    $tool->handle([ 'widget_id' => $widget_id, 'instance' => [ 'title' => 'x' ], 'sidebar_id' => self::SIDEBAR_A ]);
                    $this->fail(get_class($tool) . ' accepted ' . $widget_id);
                } catch (\InvalidArgumentException $e) {
                    $this->assertNotSame('', $e->getMessage());
                }
            }
        }
    }

    public function test_option_set_is_a_restorable_type(): void
    {
        $this->assertContains('option_set', Rollback_Service::restorable_object_types());
    }

    public function test_tools_register_free_with_the_theme_options_capability(): void
    {
        wp_get_abilities();
        $registered = [];
        foreach (\WPMCP\Plugin::instance()->registrar()->all() as $ability) {
            $registered[ $ability->name ] = $ability;
        }
        $expected = [
            'wpmcp/create-sidebar-widget' => 'create',
            'wpmcp/update-sidebar-widget' => 'update',
            'wpmcp/move-sidebar-widget'   => 'update',
            'wpmcp/delete-sidebar-widget' => 'delete',
        ];
        foreach ($expected as $name => $operation) {
            $this->assertArrayHasKey($name, $registered, $name);
            $this->assertSame('free', $registered[ $name ]->tier, $name);
            $this->assertSame('edit_theme_options', $registered[ $name ]->capability, $name);
            $this->assertSame($operation, $registered[ $name ]->operation, $name);
            $this->assertSame('structure', $registered[ $name ]->domain, $name);
        }
    }
}
