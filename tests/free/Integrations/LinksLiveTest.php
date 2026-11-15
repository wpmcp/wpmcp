<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * Issue #682: the Pretty Links and ThirstyAffiliates ops against the real
 * free plugins.
 *
 * It runs only in the local gate's live links leg (bin/test-local.sh, or
 * bin/test-local.sh --live-links alone), which sets WPMCP_LIVE_LINKS=1,
 * installs both plugins from wordpress.org on a separate WordPress install
 * and loads them in tests/bootstrap.php.
 *
 * A link made through an op must read back through the plugin's own
 * repository (Pretty Links) or model (ThirstyAffiliates), a link made by the
 * plugin must read through the op, and every write rolls back.
 *
 * Everywhere else the plugins are absent and the test is skipped; the live
 * leg runs with --fail-on-skipped so it cannot pass vacuously.
 *
 * @group links-live
 */
class LinksLiveTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! class_exists('PrettyLinks\Repositories\Links') || ! function_exists('ThirstyAffiliates') || ! post_type_exists('thirstylink')) {
            $this->markTestSkipped('Needs the real Pretty Links and ThirstyAffiliates (bin/test-local.sh --live-links, WPMCP_LIVE_LINKS=1).');
        }
        Snapshot_Store::install();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        add_filter('wpmcp_enable_pretty_links_write', '__return_true');
        add_filter('wpmcp_enable_thirstyaffiliates_write', '__return_true');
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_enable_pretty_links_write');
        remove_all_filters('wpmcp_enable_thirstyaffiliates_write');
        parent::tearDown();
    }

    private function call(string $half, string $op, array $args): array
    {
        $call = [ 'operation' => $op, 'args' => $args ];
        $out  = 'read' === $half ? (new Plugin_Data_Integration())->handle_read($call) : (new Plugin_Data_Integration())->handle_write($call);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out;
    }

    public function test_pretty_links_ops_agree_with_the_plugins_own_repository(): void
    {
        $repo = new \PrettyLinks\Repositories\Links();

        // A link the plugin made reads through the op.
        $made = $repo->create([ 'url' => 'https://example.com/live', 'slug' => 'live-one', 'name' => 'Live one', 'redirect_type' => '301' ]);
        $this->assertArrayHasKey('id', $made, (string) wp_json_encode($made));
        $read = $this->call('read', 'prettylinks-get-link', [ 'link_id' => (int) $made['id'] ])['result']['link'];
        $this->assertSame('live-one', $read['slug']);
        $this->assertSame('301', $read['redirect_type']);
        $this->assertSame((string) $made['pretty_url'], $read['pretty_url']);

        // A link the op makes loads through the plugin's repository and its slug is taken.
        $out  = $this->call('write', 'prettylinks-create-link', [ 'url' => 'https://example.com/op', 'slug' => 'op-made', 'name' => 'Op made', 'nofollow' => true ]);
        $id   = (int) $out['result']['link']['id'];
        $link = $repo->find($id);
        $this->assertIsArray($link);
        $this->assertSame('op-made', $link['slug']);
        $this->assertSame('https://example.com/op', $link['url']);
        $this->assertTrue((bool) $link['nofollow']);
        $this->assertSame('slug_in_use', ((new Plugin_Data_Integration())->handle_write([ 'operation' => 'prettylinks-create-link', 'args' => [ 'url' => 'https://example.com/x', 'slug' => 'op-made' ] ]))['error']['code'] ?? null);
        $this->assertNotNull($repo->findBySlug('op-made'));

        // Update, trash, and each undone.
        $up = $this->call('write', 'prettylinks-update-link', [ 'link_id' => $id, 'name' => 'Renamed' ]);
        $this->assertSame('Renamed', $repo->find($id)['name']);
        $this->assertTrue(Rollback_Service::restore_operation($up['operation_id']));
        $this->assertSame('Op made', $repo->find($id)['name']);

        $del = $this->call('write', 'prettylinks-delete-link', [ 'link_id' => $id ]);
        $this->assertNotEmpty($repo->search([ 'status' => 'trashed', 'include' => [ $id ] ])['items']);
        $this->assertSame([], array_column($repo->search([ 'status' => 'active', 'search' => 'op-made' ])['items'], 'id'));
        $this->assertTrue(Rollback_Service::restore_operation($del['operation_id']));
        $this->assertSame([ $id ], array_column($repo->search([ 'status' => 'active', 'search' => 'op-made' ])['items'], 'id'));

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertNull($repo->find($id));
        $this->assertNotNull($repo->find((int) $made['id']));
    }

    public function test_thirstyaffiliates_ops_agree_with_the_plugins_own_model(): void
    {
        $out = $this->call('write', 'thirstyaffiliates-create-link', [ 'name' => 'Live deal', 'url' => 'https://example.com/ta', 'redirect_type' => '307', 'no_follow' => 'yes' ]);
        $id  = (int) $out['result']['link']['id'];

        $link = new \ThirstyAffiliates\Models\Affiliate_Link($id);
        $this->assertSame('Live deal', $link->get_prop('name'));
        $this->assertSame('https://example.com/ta', $link->get_prop('destination_url'));
        $this->assertSame('307', $link->get_prop('redirect_type'));
        $this->assertTrue($link->is('no_follow'));
        $this->assertSame(0, $link->count_clicks());

        $read = $this->call('read', 'thirstyaffiliates-get-link', [ 'id' => $id ])['result']['link'];
        $this->assertSame('https://example.com/ta', $read['url']);
        $this->assertSame((string) get_permalink($id), $read['cloaked_url']);

        // A link the plugin made reads through the op.
        $own = new \ThirstyAffiliates\Models\Affiliate_Link();
        $own->set_prop('name', 'Own link');
        $own->set_prop('destination_url', 'https://example.com/own');
        $own_id = $own->save();
        $this->assertIsInt($own_id);
        $this->assertSame('https://example.com/own', $this->call('read', 'thirstyaffiliates-get-link', [ 'id' => (int) $own_id ])['result']['link']['url']);

        $up = $this->call('write', 'thirstyaffiliates-update-link', [ 'id' => $id, 'url' => 'https://example.com/ta2' ]);
        $this->assertSame('https://example.com/ta2', (new \ThirstyAffiliates\Models\Affiliate_Link($id))->get_prop('destination_url'));
        $this->assertTrue(Rollback_Service::restore_operation($up['operation_id']));
        $this->assertSame('https://example.com/ta', (new \ThirstyAffiliates\Models\Affiliate_Link($id))->get_prop('destination_url'));

        $del = $this->call('write', 'thirstyaffiliates-delete-link', [ 'id' => $id ]);
        $this->assertSame('trash', get_post_status($id));
        $this->assertTrue(Rollback_Service::restore_operation($del['operation_id']));
        $this->assertSame('publish', get_post_status($id));
    }

    public function test_the_click_tables_the_ops_read_match_the_real_schema(): void
    {
        global $wpdb;
        $this->assertContains('ip', \WPMCP\Safety\Plugin_Table_Rows_Snapshot::columns($wpdb->prefix . 'prli_clicks'));
        $this->assertContains('first_click', \WPMCP\Safety\Plugin_Table_Rows_Snapshot::columns($wpdb->prefix . 'prli_clicks'));
        $this->assertContains('deleted_at', \WPMCP\Safety\Plugin_Table_Rows_Snapshot::columns($wpdb->prefix . 'prli_links'));
        $this->assertContains('date_clicked', \WPMCP\Safety\Plugin_Table_Rows_Snapshot::columns($wpdb->prefix . 'ta_link_clicks'));
        $this->assertContains('meta_value', \WPMCP\Safety\Plugin_Table_Rows_Snapshot::columns($wpdb->prefix . 'ta_link_clicks_meta'));

        $id = (int) (new \PrettyLinks\Repositories\Links())->create([ 'url' => 'https://example.com/c', 'slug' => 'click-me', 'name' => 'c' ])['id'];
        $wpdb->insert($wpdb->prefix . 'prli_clicks', [ 'link_id' => $id, 'ip' => '203.0.113.9', 'first_click' => 1, 'created_at' => gmdate('Y-m-d H:i:s'), 'vuid' => 'v1' ]);
        $out = $this->call('read', 'prettylinks-get-link-clicks', [ 'link_id' => $id ])['result'];
        $this->assertSame(1, $out['total']);
        $this->assertSame('203.0.*.*', $out['clicks'][0]['ip']);
    }
}
