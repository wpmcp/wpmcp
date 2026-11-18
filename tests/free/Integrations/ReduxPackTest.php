<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Redux_Pack;
use WPMCP\Integrations\Theme_Integration;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tests\Support\Redux_Panel_Double;
use WPMCP\Tools\Rollback_Operation;

require_once __DIR__ . '/../../support/redux-double.php';

/**
 * Issue #897: Redux Framework options panels on the theme pair. Panels are
 * discovered by opt_name, fields read with their type, default and value
 * (secrets hidden), and one field written through Redux's own
 * validate_options() and set(), with type validation first, unknown and
 * unsupported fields refused, a snapshot of the storage Redux uses, and the
 * compiler hook run as the panel's own save runs it.
 */
class ReduxPackTest extends \WP_UnitTestCase
{
    /** @var Redux_Panel_Double[] */
    private array $panels = [];

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        $GLOBALS['wpdb']->query('DELETE FROM ' . Snapshot_Store::table_name());
        add_filter('wpmcp_redux_active', '__return_true');
        add_filter('wpmcp_redux_instances', [ $this, 'instances' ]);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_redux_active');
        remove_all_filters('wpmcp_redux_instances');
        remove_all_filters(Redux_Pack::ENABLE_FILTER);
        remove_all_actions('redux/options/shop_theme/compiler');
        foreach ([ 'shop_theme', 'other_panel' ] as $option) {
            delete_option($option);
        }
        remove_theme_mod('shop_theme-mods');
        $this->panels = [];
        parent::tearDown();
    }

    public function instances(): array
    {
        return $this->panels;
    }

    /** A panel shaped like a theme's: colors, footer text, a contact email, a licence key and a typography field. */
    private function panel(string $database = '', string $opt = 'shop_theme', array $args = []): Redux_Panel_Double
    {
        $sections = [
            [
                'id'     => 'general',
                'title'  => 'General',
                'fields' => [
                    [ 'id' => 'intro', 'type' => 'info', 'desc' => 'Read me' ],
                    [ 'id' => 'primary_color', 'type' => 'color', 'title' => 'Primary color', 'default' => '#336699' ],
                    [ 'id' => 'footer_text', 'type' => 'textarea', 'title' => 'Footer text', 'default' => 'Made with care' ],
                    [ 'id' => 'contact_email', 'type' => 'text', 'title' => 'Contact email', 'validate' => 'email', 'default' => 'shop@example.com' ],
                    [ 'id' => 'sticky_header', 'type' => 'switch', 'title' => 'Sticky header', 'default' => false ],
                    [ 'id' => 'layout', 'type' => 'select', 'title' => 'Layout', 'options' => [ 'boxed' => 'Boxed', 'wide' => 'Wide' ], 'default' => 'wide' ],
                    [ 'id' => 'pages', 'type' => 'select', 'title' => 'Landing page', 'data' => 'pages' ],
                    [ 'id' => 'columns', 'type' => 'slider', 'title' => 'Columns', 'min' => 1, 'max' => 6, 'default' => 3 ],
                ],
            ],
            [
                'id'     => 'advanced',
                'title'  => 'Advanced',
                'fields' => [
                    [ 'id' => 'license_key', 'type' => 'text', 'title' => 'Licence key' ],
                    [ 'id' => 'smtp_pass', 'type' => 'password', 'title' => 'SMTP' ],
                    [ 'id' => 'body_font', 'type' => 'typography', 'title' => 'Body font' ],
                    [ 'id' => 'accent_css', 'type' => 'color', 'title' => 'Accent', 'compiler' => true ],
                    [ 'id' => 'super_only', 'type' => 'text', 'title' => 'Hidden', 'permissions' => 'wpmcp_redux_test_cap' ],
                ],
            ],
        ];
        $panel = new Redux_Panel_Double([ 'opt_name' => $opt, 'database' => $database, 'display_name' => 'Shop Theme' ] + $args, $sections);
        $this->panels[ $opt ] = $panel;
        return $panel;
    }

    private function seed(array $values, string $opt = 'shop_theme'): void
    {
        update_option($opt, $values);
        $this->panels[ $opt ]->options = $values;
    }

    private function read(string $op, array $args = []): array
    {
        return (new Theme_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
    }

    private function write(array $args): array
    {
        return (new Theme_Integration())->handle_write([ 'operation' => 'set-redux-option', 'args' => $args ]);
    }

    private function code(array $out): string
    {
        return (string) ($out['error']['code'] ?? '');
    }

    private function opt_in(): void
    {
        add_filter(Redux_Pack::ENABLE_FILTER, '__return_true');
    }

    public function test_ops_are_on_the_theme_pair_and_the_write_is_off_until_opt_in(): void
    {
        $this->panel();
        $this->seed([ 'primary_color' => '#111111' ]);
        $ops = array_column((new Theme_Integration())->catalog()['operations'], null, 'name');

        $this->assertSame('read', $ops['list-redux-panels']['mode']);
        $this->assertSame('read', $ops['get-redux-options']['mode']);
        $this->assertSame('write', $ops['set-redux-option']['mode']);
        $this->assertTrue($ops['get-redux-options']['enabled']);
        $this->assertFalse($ops['set-redux-option']['enabled']);

        $out = $this->write([ 'field' => 'primary_color', 'value' => '#222222' ]);
        $this->assertSame('opt_in_required', $this->code($out));
        $this->assertStringContainsString(Redux_Pack::ENABLE_FILTER, $out['error']['message']);
        $this->assertSame('#111111', get_option('shop_theme')['primary_color']);
    }

    public function test_without_redux_the_ops_name_redux_framework(): void
    {
        remove_all_filters('wpmcp_redux_active');
        add_filter('wpmcp_redux_active', '__return_false');

        $out = $this->read('list-redux-panels');

        $this->assertSame('redux_inactive', $this->code($out));
        $this->assertStringContainsString('Redux Framework', $out['error']['message']);
    }

    public function test_list_panels_reports_each_opt_name_with_its_storage(): void
    {
        $this->panel();
        $this->panel('transient', 'other_panel');

        $out = $this->read('list-redux-panels')['result'];

        $panels = array_column($out['panels'], null, 'opt_name');
        $this->assertEqualsCanonicalizing([ 'other_panel', 'shop_theme' ], array_keys($panels));
        $this->assertSame('option', $panels['shop_theme']['storage']);
        $this->assertTrue($panels['shop_theme']['writable']);
        $this->assertSame('transient', $panels['other_panel']['storage']);
        $this->assertFalse($panels['other_panel']['writable']);
        $this->assertSame(2, $panels['shop_theme']['section_count']);
        $this->assertSame(11, $panels['shop_theme']['field_count'], 'display-only fields and fields the user may not see are not counted');
    }

    public function test_get_options_returns_values_defaults_and_hides_secrets(): void
    {
        $this->panel();
        $this->seed([ 'primary_color' => '#111111', 'license_key' => 'ABCD-1234-SECRET', 'smtp_pass' => 'hunter2', 'super_only' => 'x' ]);

        $out = $this->read('get-redux-options')['result'];

        $this->assertSame('shop_theme', $out['opt_name']);
        $fields = array_column($out['fields'], null, 'id');
        $this->assertArrayNotHasKey('intro', $fields, 'display-only fields are left out');
        $this->assertArrayNotHasKey('super_only', $fields, 'a field the user lacks the permissions for is left out');
        $this->assertSame('#111111', $fields['primary_color']['value']);
        $this->assertSame('#336699', $fields['primary_color']['default']);
        $this->assertTrue($fields['primary_color']['writable']);
        $this->assertSame('Made with care', $fields['footer_text']['value'], 'an unsaved field reads as its default');
        $this->assertTrue($fields['footer_text']['is_default']);
        $this->assertSame('general', $fields['footer_text']['section']);
        $this->assertSame(Redux_Pack::HIDDEN, $fields['license_key']['value']);
        $this->assertTrue($fields['license_key']['secret']);
        $this->assertFalse($fields['license_key']['writable']);
        $this->assertSame(Redux_Pack::HIDDEN, $fields['smtp_pass']['value']);
        $this->assertFalse($fields['body_font']['writable'], 'typography is read only');
        $this->assertFalse($fields['pages']['writable'], 'options filled from WordPress data are read only');
        $this->assertStringNotContainsString('hunter2', (string) wp_json_encode($out));
        $this->assertStringNotContainsString('SECRET', (string) wp_json_encode($out));
    }

    public function test_get_options_filters_by_section_and_field_and_refuses_unknown_ids(): void
    {
        $this->panel();

        $section = $this->read('get-redux-options', [ 'section' => 'advanced' ])['result'];
        $this->assertSame([ 'license_key', 'smtp_pass', 'body_font', 'accent_css' ], array_column($section['fields'], 'id'));

        $one = $this->read('get-redux-options', [ 'fields' => [ 'layout' ] ])['result'];
        $this->assertSame([ 'layout' ], array_column($one['fields'], 'id'));
        $this->assertSame([ 'boxed' => 'Boxed', 'wide' => 'Wide' ], $one['fields'][0]['options']);

        $this->assertSame('unknown_field', $this->code($this->read('get-redux-options', [ 'fields' => [ 'nope' ] ])));
        $this->assertSame('unknown_section', $this->code($this->read('get-redux-options', [ 'section' => 'nope' ])));
    }

    public function test_several_panels_need_an_opt_name_and_an_unknown_one_is_refused(): void
    {
        $this->panel();
        $this->panel('', 'other_panel');

        $this->assertSame('opt_name_required', $this->code($this->read('get-redux-options')));
        $this->assertSame('redux_panel_not_found', $this->code($this->read('get-redux-options', [ 'opt_name' => 'missing' ])));
        $this->assertSame('other_panel', $this->read('get-redux-options', [ 'opt_name' => 'other_panel' ])['result']['opt_name']);
    }

    public function test_set_writes_one_field_through_redux_and_undoes(): void
    {
        $panel = $this->panel();
        $this->seed([ 'primary_color' => '#111111', 'footer_text' => 'Old footer' ]);
        $this->opt_in();

        $out = $this->write([ 'field' => 'primary_color', 'value' => '#AA3300' ]);

        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertTrue($out['recoverable']);
        $this->assertSame('#AA3300', get_option('shop_theme')['primary_color']);
        $this->assertSame('Old footer', get_option('shop_theme')['footer_text'], 'other fields are kept');
        $this->assertSame(1, $panel->options_class->sets, 'the value is stored through Redux set()');
        $this->assertSame('#111111', $out['result']['previous']);
        $this->assertSame('#AA3300', $out['result']['value']);

        (new Rollback_Operation())->handle([ 'operation_id' => $out['operation_id'] ]);
        $this->assertSame('#111111', get_option('shop_theme')['primary_color']);
        $this->assertSame('Old footer', get_option('shop_theme')['footer_text']);
    }

    public function test_set_validates_by_type_and_refuses_before_any_snapshot(): void
    {
        $this->panel();
        $this->seed([ 'primary_color' => '#111111', 'layout' => 'wide', 'columns' => '3' ]);
        $this->opt_in();

        $cases = [
            [ [ 'field' => 'nope', 'value' => 'x' ], 'unknown_field' ],
            [ [ 'field' => 'body_font', 'value' => 'Arial' ], 'field_not_writable' ],
            [ [ 'field' => 'license_key', 'value' => 'NEW' ], 'field_not_writable' ],
            [ [ 'field' => 'pages', 'value' => '2' ], 'field_not_writable' ],
            [ [ 'field' => 'primary_color', 'value' => 'red; background:url(x)' ], 'invalid_value' ],
            [ [ 'field' => 'layout', 'value' => 'narrow' ], 'invalid_value' ],
            [ [ 'field' => 'columns', 'value' => 9 ], 'invalid_value' ],
            [ [ 'field' => 'footer_text', 'value' => [ 'a' ] ], 'invalid_value' ],
            [ [ 'field' => 'primary_color', 'value' => '#111111' ], 'no_change' ],
        ];
        foreach ($cases as [ $args, $code ]) {
            $this->assertSame($code, $this->code($this->write($args)), (string) wp_json_encode($args));
        }
        $this->assertSame([ 'primary_color' => '#111111', 'layout' => 'wide', 'columns' => '3' ], get_option('shop_theme'));
        $this->assertSame([], Snapshot_Store::list_by_session('default'));
    }

    public function test_choice_switch_and_slider_values_are_stored_as_the_panel_stores_them(): void
    {
        $this->panel();
        $this->seed([ 'layout' => 'wide' ]);
        $this->opt_in();

        $this->assertArrayNotHasKey('error', $this->write([ 'field' => 'layout', 'value' => 'boxed' ]));
        $this->assertArrayNotHasKey('error', $this->write([ 'field' => 'sticky_header', 'value' => true ]));
        $this->assertArrayNotHasKey('error', $this->write([ 'field' => 'columns', 'value' => 4 ]));

        $stored = get_option('shop_theme');
        $this->assertSame('boxed', $stored['layout']);
        $this->assertSame('1', $stored['sticky_header']);
        $this->assertSame('4', $stored['columns']);
    }

    public function test_a_value_redux_validation_rejects_is_refused_and_leaves_nothing_behind(): void
    {
        $this->panel();
        $this->seed([ 'contact_email' => 'shop@example.com' ]);
        $this->opt_in();

        $out = $this->write([ 'field' => 'contact_email', 'value' => 'not an email' ]);

        $this->assertSame('redux_rejected_value', $this->code($out));
        $this->assertStringContainsString('valid email', $out['error']['message'], 'Redux\'s own message is passed on');
        $this->assertSame('shop@example.com', get_option('shop_theme')['contact_email']);
        $this->assertSame([], Snapshot_Store::list_by_session('default'), 'the undo point is voided');
    }

    public function test_a_first_write_stores_the_other_fields_defaults_as_the_panel_save_does_and_undo_removes_the_row(): void
    {
        $this->panel();
        $this->opt_in();

        $out = $this->write([ 'field' => 'layout', 'value' => 'boxed' ]);

        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $stored = get_option('shop_theme');
        $this->assertSame('boxed', $stored['layout']);
        $this->assertSame('#336699', $stored['primary_color']);
        $this->assertSame('Made with care', $stored['footer_text']);

        (new Rollback_Operation())->handle([ 'operation_id' => $out['operation_id'] ]);
        $this->assertFalse(get_option('shop_theme'));
    }

    public function test_a_rejected_first_write_leaves_no_row(): void
    {
        $this->panel();
        $this->opt_in();

        $this->assertSame('redux_rejected_value', $this->code($this->write([ 'field' => 'contact_email', 'value' => 'nope' ])));
        $this->assertFalse(get_option('shop_theme'));
    }

    public function test_theme_mods_storage_is_written_and_undone(): void
    {
        $this->panel('theme_mods');
        set_theme_mod('shop_theme-mods', [ 'footer_text' => 'Old' ]);
        $this->panels['shop_theme']->options = [ 'footer_text' => 'Old' ];
        $this->opt_in();

        $out = $this->write([ 'field' => 'footer_text', 'value' => 'New footer' ]);

        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertSame('New footer', get_theme_mod('shop_theme-mods')['footer_text']);
        (new Rollback_Operation())->handle([ 'operation_id' => $out['operation_id'] ]);
        $this->assertSame('Old', get_theme_mod('shop_theme-mods')['footer_text']);
    }

    public function test_transient_storage_is_read_only(): void
    {
        $this->panel('transient');
        $this->opt_in();

        $this->assertSame('redux_storage_not_writable', $this->code($this->write([ 'field' => 'footer_text', 'value' => 'New' ])));
    }

    public function test_a_compiler_field_runs_the_panel_compiler_hook(): void
    {
        $this->panel();
        $this->seed([ 'accent_css' => '#000000' ]);
        $this->opt_in();
        $seen = [];
        add_action('redux/options/shop_theme/compiler', static function ($options) use (&$seen): void {
            $seen[] = $options['accent_css'] ?? null;
        });

        $out = $this->write([ 'field' => 'accent_css', 'value' => '#ffffff' ]);

        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertSame([ '#ffffff' ], $seen);
        $this->assertTrue($out['result']['compiler_ran']);

        (new Rollback_Operation())->handle([ 'operation_id' => $out['operation_id'] ]);
        $this->assertSame([ '#ffffff', '#000000' ], $seen, 'the undo runs the compiler for the restored values');
    }

    public function test_the_panel_page_permissions_are_required(): void
    {
        $this->panel('', 'shop_theme', [ 'page_permissions' => 'wpmcp_redux_test_cap' ]);
        $this->opt_in();

        $this->assertSame('operation_denied', $this->code($this->read('get-redux-options')));
        $this->assertSame('operation_denied', $this->code($this->write([ 'field' => 'footer_text', 'value' => 'x' ])));
    }
}
