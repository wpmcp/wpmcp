<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Integrations\Popup_Builder_Pack;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * Popup Builder (issue #898): free-tier ops on the plugin-data dispatcher
 * pair, so they add no top-level tools.
 *
 * Popup Builder is not loaded in the shared test core. Presence is driven
 * through wpmcp_popup_builder_active and its post type is registered here
 * the way Popup Builder 4.4.7 does outside wp-admin (com/classes/
 * RegisterPostType.php): `popupbuilder` with capability_type sgpb_popup /
 * sgpb_popups and map_meta_cap, whose caps its activation grants to the
 * administrator (com/classes/Actions.php). A popup keeps its settings in
 * the sg_popup_options post meta array (sgpb-type, sgpb-is-active and the
 * rest), its display rules in sg_popup_target (['sgpb-target' => [ 0 =>
 * rules ], 'sgpb-conditions' => ...], only group 0 is read) and its
 * triggers in sg_popup_events ([ 0 => [ ['param', 'value', 'hiddenOption'] ]
 * ]); a draft keeps them under the same keys with a _preview suffix. Views
 * are counted per popup id in the SgpbCounter option.
 *
 * The writes are off until the site opts in
 * (wpmcp_enable_popup_builder_write) and each is undone in a test.
 */
class PopupBuilderPackTest extends \WP_UnitTestCase
{
    private const OPS = [
        'popupbuilder-list-popups'   => 'read',
        'popupbuilder-get-popup'     => 'read',
        'popupbuilder-update-popup'  => 'write',
        'popupbuilder-enable-popup'  => 'write',
        'popupbuilder-disable-popup' => 'write',
    ];

    private const CAPS = [
        'read_private_sgpb_popups', 'edit_sgpb_popup', 'edit_sgpb_popups', 'edit_others_sgpb_popups',
        'edit_published_sgpb_popups', 'publish_sgpb_popups', 'delete_sgpb_popups',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        register_post_type('popupbuilder', [
            'public'          => true,
            'show_ui'         => true,
            'map_meta_cap'    => true,
            'capability_type' => [ 'sgpb_popup', 'sgpb_popups' ],
            'supports'        => [ 'title', 'editor' ],
        ]);
        $role = get_role('administrator');
        foreach (self::CAPS as $cap) {
            $role->add_cap($cap);
        }
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        if (is_multisite()) {
            grant_super_admin(get_current_user_id());
        }
        add_filter('wpmcp_popup_builder_active', '__return_true');
        add_filter('wpmcp_enable_popup_builder_write', '__return_true');
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_popup_builder_active');
        remove_all_filters('wpmcp_enable_popup_builder_write');
        $role = get_role('administrator');
        foreach (self::CAPS as $cap) {
            $role->remove_cap($cap);
        }
        unregister_post_type('popupbuilder');
        delete_option('SgpbCounter');
        parent::tearDown();
    }

    private function read(string $op, array $args = []): array
    {
        $out = (new Plugin_Data_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
        return isset($out['result']) && ! isset($out['error']) ? $out['result'] : $out;
    }

    private function write(string $op, array $args): array
    {
        return (new Plugin_Data_Integration())->handle_write([ 'operation' => $op, 'args' => $args ]);
    }

    private function ok(array $out): array
    {
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out;
    }

    /** A popup as SGPopup::save() stores it. */
    private function popup(string $title = 'Spring sale', string $type = 'html', bool $active = true, string $status = 'publish', array $target = [], string $suffix = ''): int
    {
        $id = self::factory()->post->create([ 'post_type' => 'popupbuilder', 'post_title' => $title, 'post_status' => $status, 'post_content' => '<p>20% off with SPRING20</p>' ]);
        update_post_meta($id, 'sg_popup_options' . $suffix, [
            'sgpb-type'        => $type,
            'sgpb-is-active'   => $active ? 'on' : '',
            'sgpb-popup-delay' => '0',
            'sgpb-width'       => '640px',
            'sgpb-post-id'     => (string) $id,
        ]);
        update_post_meta($id, 'sg_popup_target' . $suffix, [
            'sgpb-target'     => [ 0 => [] !== $target ? $target : [ 0 => [ 'param' => 'everywhere' ] ] ],
            'sgpb-conditions' => [ 0 => [ 0 => [ 'param' => 'select_role', 'operator' => '==', 'value' => '' ] ] ],
        ]);
        update_post_meta($id, 'sg_popup_events' . $suffix, [
            0 => [
                0 => [ 'param' => 'load', 'value' => '5', 'hiddenOption' => [ 'repetitive' => '' ] ],
                1 => [ 'param' => 'click', 'value' => '', 'operator' => 'defaultClickClassName', 'hiddenOption' => [] ],
            ],
        ]);
        return $id;
    }

    // ---------------------------------------------------------------
    // Catalog and availability
    // ---------------------------------------------------------------

    public function test_the_catalog_lists_every_op_with_its_mode(): void
    {
        $ops = array_column((new Plugin_Data_Integration())->catalog()['operations'], null, 'name');
        foreach (self::OPS as $name => $mode) {
            $this->assertArrayHasKey($name, $ops);
            $this->assertSame($mode, $ops[ $name ]['mode'], $name);
            $this->assertTrue($ops[ $name ]['dependency_met'], $name);
        }
    }

    public function test_while_popup_builder_is_missing_a_call_answers_plugin_missing_with_the_plugins_link(): void
    {
        remove_all_filters('wpmcp_popup_builder_active');
        add_filter('wpmcp_popup_builder_active', '__return_false');
        $out = $this->read('popupbuilder-list-popups');
        $this->assertSame('plugin_missing', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertStringContainsString('Install and activate Popup Builder', $out['error']['message']);
        $this->assertSame(admin_url('plugins.php'), $out['error']['data']['plugins_url'] ?? null);
    }

    public function test_presence_defaults_to_the_plugin_constant(): void
    {
        remove_all_filters('wpmcp_popup_builder_active');
        $this->assertSame(defined('SGPB_POPUP_VERSION'), Popup_Builder_Pack::active());
    }

    public function test_writes_are_off_until_the_site_opts_in(): void
    {
        $id = $this->popup();
        remove_all_filters('wpmcp_enable_popup_builder_write');
        foreach ([
            [ 'popupbuilder-update-popup', [ 'id' => $id, 'title' => 'x' ] ],
            [ 'popupbuilder-enable-popup', [ 'id' => $id ] ],
            [ 'popupbuilder-disable-popup', [ 'id' => $id ] ],
        ] as [ $op, $args ]) {
            $out = $this->write($op, $args);
            $this->assertSame('opt_in_required', $out['error']['code'] ?? null, $op);
            $this->assertStringContainsString('wpmcp_enable_popup_builder_write', $out['error']['message']);
        }
        $this->assertSame('Spring sale', get_post($id)->post_title);
        $this->assertSame('on', get_post_meta($id, 'sg_popup_options', true)['sgpb-is-active']);
    }

    public function test_only_users_with_the_popup_builder_capabilities_may_read_or_write(): void
    {
        $id = $this->popup();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        $this->assertSame('operation_denied', $this->read('popupbuilder-list-popups')['error']['code'] ?? null, 'an editor lacks edit_sgpb_popups');
        $this->assertArrayHasKey('error', $this->read('popupbuilder-get-popup', [ 'id' => $id ]));
        $this->assertArrayHasKey('error', $this->write('popupbuilder-disable-popup', [ 'id' => $id ]));
        $this->assertSame('on', get_post_meta($id, 'sg_popup_options', true)['sgpb-is-active']);
    }

    // ---------------------------------------------------------------
    // Reads
    // ---------------------------------------------------------------

    public function test_list_popups_returns_type_status_triggers_rules_and_views_with_filters(): void
    {
        $a = $this->popup('Alpha');
        $b = $this->popup('Beta', 'image', false);
        $c = $this->popup('Gamma draft', 'html', true, 'draft', [], '_preview');
        update_option('SgpbCounter', [ $a => 42 ]);

        $out = $this->ok($this->read('popupbuilder-list-popups'));
        $this->assertSame(3, $out['total']);
        $rows = array_column($out['popups'], null, 'id');
        $this->assertSame('html', $rows[ $a ]['type']);
        $this->assertTrue($rows[ $a ]['active']);
        $this->assertFalse($rows[ $b ]['active']);
        $this->assertSame('image', $rows[ $b ]['type']);
        $this->assertSame('html', $rows[ $c ]['type'], 'a draft is read from its _preview meta');
        $this->assertSame('draft', $rows[ $c ]['status']);
        $this->assertSame(42, $rows[ $a ]['views']);
        $this->assertSame(0, $rows[ $b ]['views']);
        $this->assertSame([ 'load', 'click' ], $rows[ $a ]['triggers']);
        $this->assertSame([ 'everywhere' ], $rows[ $a ]['rules']);
        $this->assertArrayNotHasKey('content', $rows[ $a ], 'lists leave the content out');

        $this->assertSame([ $b ], array_column($this->read('popupbuilder-list-popups', [ 'type' => 'image' ])['popups'], 'id'));
        $this->assertSame([ $b ], array_column($this->read('popupbuilder-list-popups', [ 'active' => false ])['popups'], 'id'));
        $this->assertSame([ $c ], array_column($this->read('popupbuilder-list-popups', [ 'status' => 'draft' ])['popups'], 'id'));
        $this->assertSame([ $a ], array_column($this->read('popupbuilder-list-popups', [ 'search' => 'Alpha' ])['popups'], 'id'));
    }

    public function test_get_popup_returns_content_rules_triggers_and_the_shortcode(): void
    {
        $page = self::factory()->post->create([ 'post_type' => 'page', 'post_title' => 'Pricing' ]);
        $id   = $this->popup('Alpha', 'html', true, 'publish', [
            0 => [ 'param' => 'page_selected', 'operator' => '==', 'value' => [ $page => 'Pricing' ] ],
            1 => [ 'param' => 'post_type', 'operator' => '!=', 'value' => [ 'product' ] ],
        ]);

        $popup = $this->ok($this->read('popupbuilder-get-popup', [ 'id' => $id ]))['popup'];
        $this->assertSame('Alpha', $popup['title']);
        $this->assertStringContainsString('SPRING20', $popup['content']);
        $this->assertSame('[sg_popup id="' . $id . '"]', $popup['shortcode']);
        $this->assertSame([
            [ 'param' => 'page_selected', 'operator' => '==', 'value' => [ [ 'id' => (string) $page, 'name' => 'Pricing' ] ] ],
            [ 'param' => 'post_type', 'operator' => '!=', 'value' => [ 'product' ] ],
        ], $popup['rules']);
        $this->assertSame([ 'param' => 'load', 'value' => '5', 'options' => [ 'repetitive' => '' ] ], $popup['triggers'][0]);
        $this->assertSame('640px', $popup['settings']['sgpb-width']);
        $this->assertSame('popup_not_found', $this->read('popupbuilder-get-popup', [ 'id' => self::factory()->post->create() ])['error']['code'] ?? null);
    }

    // ---------------------------------------------------------------
    // Writes
    // ---------------------------------------------------------------

    public function test_update_popup_changes_title_content_and_rules_and_rolls_back_exactly(): void
    {
        $page = self::factory()->post->create([ 'post_type' => 'page', 'post_title' => 'Pricing' ]);
        $cat  = self::factory()->category->create([ 'name' => 'News' ]);
        $tag  = self::factory()->tag->create([ 'name' => 'Black Friday', 'slug' => 'black-friday' ]);
        $id   = $this->popup('Alpha');
        $meta = static fn (): array => [ get_post($id)->post_title, get_post($id)->post_content, get_post_meta($id, 'sg_popup_target', true), get_post_meta($id, 'sg_popup_options', true), get_post_meta($id, 'sg_popup_events', true) ];
        $before = $meta();

        $out = $this->ok($this->write('popupbuilder-update-popup', [
            'id'      => $id,
            'title'   => 'Alpha 2',
            'content' => '<p>30% off with FALL30</p>',
            'rules'   => [
                [ 'param' => 'page_selected', 'post_ids' => [ $page ] ],
                [ 'param' => 'post_category', 'term_ids' => [ $cat ] ],
                [ 'param' => 'post_tags_ids', 'term_ids' => [ $tag ], 'operator' => '!=' ],
                [ 'param' => 'page_type', 'values' => [ 'is_home_page' ] ],
            ],
        ]));
        $this->assertTrue($out['recoverable']);
        $this->assertSame('Alpha 2', get_post($id)->post_title);
        $this->assertSame('<p>30% off with FALL30</p>', get_post($id)->post_content);
        $target = get_post_meta($id, 'sg_popup_target', true);
        $this->assertSame([
            [ 'param' => 'page_selected', 'operator' => '==', 'value' => [ $page => 'Pricing' ] ],
            [ 'param' => 'post_category', 'operator' => '==', 'value' => [ $cat => 'News' ] ],
            [ 'param' => 'post_tags_ids', 'operator' => '!=', 'value' => [ 'black-friday' => 'Black Friday' ] ],
            [ 'param' => 'page_type', 'operator' => '==', 'value' => [ 'is_home_page' ] ],
        ], $target['sgpb-target'][0]);
        $this->assertSame($before[2]['sgpb-conditions'], $target['sgpb-conditions'], 'conditions untouched');
        $this->assertSame($before[3], get_post_meta($id, 'sg_popup_options', true), 'settings untouched');

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame($before, $meta());
    }

    public function test_update_popup_writes_a_drafts_preview_rules(): void
    {
        $id = $this->popup('Draft', 'html', true, 'draft', [], '_preview');
        $this->ok($this->write('popupbuilder-update-popup', [ 'id' => $id, 'rules' => [ [ 'param' => 'post_all' ] ] ]));
        $this->assertSame([ [ 'param' => 'post_all', 'operator' => '==' ] ], get_post_meta($id, 'sg_popup_target_preview', true)['sgpb-target'][0]);
        $this->assertSame('', get_post_meta($id, 'sg_popup_target', true), 'no live key appears on a draft');
    }

    public function test_update_popup_refuses_bad_rules_and_non_popups(): void
    {
        $id      = $this->popup();
        $private = self::factory()->post->create([ 'post_type' => 'page', 'post_status' => 'draft' ]);
        $this->assertSame('popup_not_found', $this->write('popupbuilder-update-popup', [ 'id' => self::factory()->post->create(), 'title' => 'x' ])['error']['code'] ?? null);
        $this->assertSame('rule_invalid', $this->write('popupbuilder-update-popup', [ 'id' => $id, 'rules' => [ [ 'param' => 'page_selected' ] ] ])['error']['code'] ?? null, 'a selected rule needs post_ids');
        $this->assertSame('rule_invalid', $this->write('popupbuilder-update-popup', [ 'id' => $id, 'rules' => [ [ 'param' => 'page_selected', 'post_ids' => [ $private ] ] ] ])['error']['code'] ?? null, 'only published pages');
        $this->assertSame('rule_invalid', $this->write('popupbuilder-update-popup', [ 'id' => $id, 'rules' => [ [ 'param' => 'post_selected', 'post_ids' => [ self::factory()->post->create([ 'post_type' => 'page' ]) ] ] ] ])['error']['code'] ?? null, 'post_selected takes posts');
        $this->assertArrayHasKey('error', $this->write('popupbuilder-update-popup', [ 'id' => $id, 'rules' => [ [ 'param' => 'page_type', 'values' => [ 'phpinfo' ] ] ] ]), 'page types are checked against the plugin list, since it calls them');
        $this->assertSame('rule_invalid', $this->write('popupbuilder-update-popup', [ 'id' => $id, 'rules' => [ [ 'param' => 'post_type', 'values' => [ 'no_such_type' ] ] ] ])['error']['code'] ?? null);
        $this->assertSame('rule_invalid', $this->write('popupbuilder-update-popup', [ 'id' => $id, 'rules' => [ [ 'param' => 'post_category', 'term_ids' => [ 999999 ] ] ] ])['error']['code'] ?? null);
        $this->assertSame([ [ 'param' => 'everywhere' ] ], get_post_meta($id, 'sg_popup_target', true)['sgpb-target'][0]);
    }

    public function test_disable_and_enable_switch_the_plugins_own_toggle_and_each_rolls_back(): void
    {
        $id = $this->popup();

        $off = $this->ok($this->write('popupbuilder-disable-popup', [ 'id' => $id ]));
        $this->assertTrue($off['recoverable']);
        $this->assertSame('', get_post_meta($id, 'sg_popup_options', true)['sgpb-is-active']);
        $this->assertSame('640px', get_post_meta($id, 'sg_popup_options', true)['sgpb-width'], 'other settings kept');
        $this->assertSame('publish', get_post_status($id), 'the post status is not the switch');
        $this->assertFalse($off['result']['popup']['active']);

        $on = $this->ok($this->write('popupbuilder-enable-popup', [ 'id' => $id ]));
        $this->assertSame('on', get_post_meta($id, 'sg_popup_options', true)['sgpb-is-active']);
        $this->assertTrue($on['result']['popup']['active']);

        $this->assertTrue(Rollback_Service::restore_operation($on['operation_id']));
        $this->assertSame('', get_post_meta($id, 'sg_popup_options', true)['sgpb-is-active']);
        $this->assertTrue(Rollback_Service::restore_operation($off['operation_id']));
        $this->assertSame('on', get_post_meta($id, 'sg_popup_options', true)['sgpb-is-active']);
    }
}
