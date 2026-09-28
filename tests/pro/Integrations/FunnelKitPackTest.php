<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Theme_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Snapshot_Store;

require_once __DIR__ . '/../../support/funnelkit-tables.php';

/**
 * FunnelKit funnels and steps (issue #356): paid-tier reads on the theme
 * dispatcher pair, so they add no top-level tools.
 *
 * FunnelKit is not installed in the shared test core. Presence is driven
 * through wpmcp_funnelkit_active, and the state the reads use is seeded the
 * way FunnelKit Funnel Builder 3.16 stores it (tests/support/funnelkit-tables.php):
 * funnels in {prefix}bwf_funnels with their ordered steps as JSON, each step
 * a post of its own type (wffn_landing, wfacp_checkout, wfocu_funnel,
 * wffn_ty, wffn_optin, wffn_oty) carrying _bwf_in_funnel, checkout products
 * in _wfacp_selected_products, upsell offers (wfocu_offer) listed in the
 * upsell step's _funnel_steps with their products in _wfocu_setting, and
 * per-step counts in wfco_report_views, wfacp_stats and bwf_optin_entries.
 *
 * The stats fixtures carry the customer data FunnelKit really stores beside
 * its counts (optin emails and form data, contact ids), and the tests assert
 * none of it comes back.
 *
 * Issue #365: the free plugin records no step views (its
 * WFCO_Model_Report_views shim forwards to the paid WFFN_Report_Views and
 * drops the write otherwise), and upsell offers exist only with the upsell
 * add-on (WFOCU_Core). Both are driven through filters here, on by default,
 * and a value that cannot be read carries a *_unavailable reason.
 */
class FunnelKitPackTest extends \WP_UnitTestCase
{
    private const LIST_OP = 'list-funnelkit-funnels';
    private const GET_OP  = 'get-funnelkit-funnel';

    private const LEAD_EMAIL = 'jane.lead@example.com';
    private const LEAD_NAME  = 'Jane Leadington';
    private const LEAD_PHONE = '+1-555-0147';

    public static function wpSetUpBeforeClass(): void
    {
        wpmcp_test_create_funnelkit_tables();
    }

    public static function wpTearDownAfterClass(): void
    {
        wpmcp_test_drop_funnelkit_tables();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        // WooCommerce grants manage_woocommerce to administrators when it
        // installs its roles; the shared core may not have run that.
        $admin = self::factory()->user->create([ 'role' => 'administrator' ]);
        get_userdata($admin)->add_cap('manage_woocommerce');
        wp_set_current_user($admin);
        add_filter('wpmcp_funnelkit_active', '__return_true');
        add_filter('wpmcp_funnelkit_views_recorded', '__return_true');
        add_filter('wpmcp_funnelkit_upsells_active', '__return_true');
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_funnelkit_active');
        remove_all_filters('wpmcp_funnelkit_views_recorded');
        remove_all_filters('wpmcp_funnelkit_upsells_active');
        remove_all_filters('query');
        delete_option('_bwf_global_funnel');
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function catalog(): array
    {
        return array_column((new Theme_Integration())->catalog()['operations'], null, 'name');
    }

    private function read(string $op, array $args = []): array
    {
        return (new Theme_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
    }

    private function step(string $post_type, string $title, int $funnel_id = 0, string $status = 'publish'): int
    {
        $id = self::factory()->post->create([
            'post_type'   => $post_type,
            'post_title'  => $title,
            'post_status' => $status,
        ]);
        if ($funnel_id > 0) {
            update_post_meta($id, '_bwf_in_funnel', $funnel_id);
        }
        return $id;
    }

    private function views(int $object_id, int $type, int $sessions, string $date = '2026-09-20'): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'wfco_report_views', [
            'date'           => $date,
            'no_of_sessions' => $sessions,
            'object_id'      => $object_id,
            'type'           => $type,
        ]);
    }

    /**
     * A full sales funnel: landing, optin, optin thank you, checkout with two
     * products, an upsell step with two offers, and a thank you page, plus
     * the counts and customer rows FunnelKit keeps beside it.
     *
     * @return array<string, int>
     */
    private function seed_sales_funnel(): array
    {
        global $wpdb;

        $ids = [
            'landing'  => $this->step('wffn_landing', 'Spring sale landing'),
            'optin'    => $this->step('wffn_optin', 'Get the guide'),
            'optin_ty' => $this->step('wffn_oty', 'Guide on its way'),
            'checkout' => $this->step('wfacp_checkout', 'Spring checkout'),
            'upsells'  => $this->step('wfocu_funnel', 'Spring upsells'),
            'offer1'   => $this->step('wfocu_offer', 'Add the travel case'),
            'offer2'   => $this->step('wfocu_offer', 'Or the mini case', 0, 'draft'),
            'thankyou' => $this->step('wffn_ty', 'Thanks for your order', 0, 'draft'),
        ];
        $ids['product_a'] = self::factory()->post->create([ 'post_type' => 'product', 'post_title' => 'Blender' ]);
        $ids['product_b'] = self::factory()->post->create([ 'post_type' => 'product', 'post_title' => 'Jar' ]);
        $ids['variation'] = self::factory()->post->create([ 'post_type' => 'product_variation', 'post_parent' => $ids['product_b'] ]);
        $ids['product_c'] = self::factory()->post->create([ 'post_type' => 'product', 'post_title' => 'Travel case' ]);

        $ids['funnel'] = wpmcp_test_funnelkit_funnel(
            'Spring sale',
            [
                [ 'type' => 'landing', 'id' => $ids['landing'] ],
                [ 'type' => 'optin', 'id' => $ids['optin'] ],
                [ 'type' => 'optin_ty', 'id' => $ids['optin_ty'] ],
                [ 'type' => 'wc_checkout', 'id' => $ids['checkout'] ],
                [ 'type' => 'wc_upsells', 'id' => $ids['upsells'] ],
                [ 'type' => 'wc_thankyou', 'id' => $ids['thankyou'] ],
            ],
            'Blender launch funnel',
            '2026-09-10 08:30:00'
        );
        foreach ([ 'landing', 'optin', 'optin_ty', 'checkout', 'upsells', 'thankyou' ] as $key) {
            update_post_meta($ids[ $key ], '_bwf_in_funnel', $ids['funnel']);
        }

        // WFACP_Common::update_page_product(): keyed by a random hash, a
        // variation carries its parent in parent_product_id.
        update_post_meta($ids['checkout'], '_wfacp_selected_products', [
            'a1b2c3' => [ 'id' => $ids['product_a'], 'quantity' => 1, 'parent_product_id' => 0, 'title' => 'Blender' ],
            'd4e5f6' => [ 'id' => $ids['variation'], 'quantity' => 2, 'parent_product_id' => $ids['product_b'], 'title' => 'Jar - Large' ],
        ]);

        update_post_meta($ids['upsells'], '_funnel_steps', [
            [ 'id' => $ids['offer1'], 'name' => 'Add the travel case', 'type' => 'upsell', 'state' => '1' ],
            [ 'id' => $ids['offer2'], 'name' => 'Or the mini case', 'type' => 'downsell', 'state' => '0' ],
        ]);
        $setting           = new \stdClass();
        $setting->products = (object) [ 'h1' => (object) [ 'id' => $ids['product_c'], 'quantity' => 1 ] ];
        $setting->settings = (object) [ 'jump_on_accepted' => false ];
        update_post_meta($ids['offer1'], '_wfocu_setting', $setting);

        $this->views($ids['landing'], 2, 120);
        $this->views($ids['landing'], 2, 30, '2026-09-21');
        $this->views($ids['landing'], 3, 40);
        $this->views($ids['optin'], 8, 50);
        $this->views($ids['optin_ty'], 10, 18);
        $this->views($ids['checkout'], 4, 35);
        $this->views($ids['thankyou'], 5, 9);

        foreach ([ 101, 102, 103 ] as $order_id) {
            $wpdb->insert($wpdb->prefix . 'wfacp_stats', [
                'order_id'      => $order_id,
                'wfacp_id'      => $ids['checkout'],
                'total_revenue' => '49.00',
                'cid'           => 7,
                'fid'           => $ids['funnel'],
                'date'          => '2026-09-20 12:00:00',
            ]);
        }
        foreach ([ 1, 2 ] as $n) {
            $wpdb->insert($wpdb->prefix . 'bwf_optin_entries', [
                'step_id'   => $ids['optin'],
                'funnel_id' => $ids['funnel'],
                'cid'       => 7 + $n,
                'opid'      => 'op' . $n,
                'email'     => self::LEAD_EMAIL,
                'data'      => wp_json_encode([ 'optin_first_name' => self::LEAD_NAME, 'optin_phone' => self::LEAD_PHONE ]),
                'date'      => '2026-09-20 12:00:00',
            ]);
        }

        return $ids;
    }

    /** Make FunnelKit's {prefix}$name look absent to the reader's table probe. */
    private function hide_table(string $name): void
    {
        global $wpdb;
        $probe = 'SHOW COLUMNS FROM `' . $wpdb->prefix . $name . '`';
        add_filter('query', static fn ($sql) => $sql === $probe ? 'SHOW COLUMNS FROM `wpmcp_no_such_table`' : $sql);
    }

    /** @return array<string, array<string, mixed>> steps keyed by type */
    private function steps_by_type(array $result): array
    {
        return array_column($result['steps'], null, 'type');
    }

    // ---------------------------------------------------------------
    // Catalog, tier, presence and capability
    // ---------------------------------------------------------------

    public function test_both_ops_are_paid_manage_woocommerce_reads_on_the_theme_pair(): void
    {
        $ops = $this->catalog();

        foreach ([ self::LIST_OP, self::GET_OP ] as $name) {
            $this->assertArrayHasKey($name, $ops, "{$name} must be on the theme pair with a license");
            $this->assertSame('read', $ops[ $name ]['mode']);
            $this->assertSame('manage_woocommerce', $ops[ $name ]['capability']);
            $this->assertTrue($ops[ $name ]['dependency_met']);
        }

        Gate::set_pro_for_tests(false);
        $free = $this->catalog();
        $this->assertArrayNotHasKey(self::LIST_OP, $free, 'the funnel reads are paid-tier');
        $this->assertArrayNotHasKey(self::GET_OP, $free, 'the funnel reads are paid-tier');
    }

    public function test_an_inactive_funnelkit_is_skipped_cleanly(): void
    {
        $this->seed_sales_funnel();
        remove_all_filters('wpmcp_funnelkit_active');
        add_filter('wpmcp_funnelkit_active', '__return_false');

        $ops = $this->catalog();
        foreach ([ self::LIST_OP => [], self::GET_OP => [ 'id' => 1 ] ] as $op => $args) {
            $this->assertFalse($ops[ $op ]['dependency_met'], "{$op} must be flagged while FunnelKit is inactive");
            $out = $this->read($op, $args);
            $this->assertSame('funnelkit_inactive', $out['error']['code'] ?? null, "{$op} must answer funnelkit_inactive");
            $this->assertArrayNotHasKey('result', $out);
        }
    }

    public function test_presence_defaults_to_funnelkits_own_marker(): void
    {
        remove_all_filters('wpmcp_funnelkit_active');

        $this->assertSame(defined('WFFN_VERSION'), $this->catalog()[ self::LIST_OP ]['dependency_met']);
    }

    public function test_reads_refuse_a_user_without_manage_woocommerce(): void
    {
        $user = self::factory()->user->create([ 'role' => 'editor' ]);
        get_userdata($user)->add_cap('edit_theme_options');
        wp_set_current_user($user);

        foreach ([ self::LIST_OP => [], self::GET_OP => [ 'id' => 1 ] ] as $op => $args) {
            $out = $this->read($op, $args);
            $this->assertSame('operation_denied', $out['error']['code'] ?? null, "{$op} runs at manage_woocommerce");
        }
    }

    // ---------------------------------------------------------------
    // list-funnelkit-funnels
    // ---------------------------------------------------------------

    public function test_list_returns_funnels_newest_first_with_a_step_summary(): void
    {
        $ids   = $this->seed_sales_funnel();
        $solo  = $this->step('wffn_landing', 'Webinar page');
        $older = wpmcp_test_funnelkit_funnel('Webinar', [ [ 'type' => 'landing', 'id' => $solo ] ], '', '2026-08-01 09:00:00');
        $empty = wpmcp_test_funnelkit_funnel('Draft idea', [], '', '2026-09-15 09:00:00');

        $out = $this->read(self::LIST_OP);
        $this->assertArrayNotHasKey('error', $out);
        $result = $out['result'];

        $this->assertSame(3, $result['total']);
        $this->assertSame([ $empty, $older, $ids['funnel'] ], array_column($result['funnels'], 'id'));

        $sale = array_column($result['funnels'], null, 'id')[ $ids['funnel'] ];
        $this->assertSame('Spring sale', $sale['title']);
        $this->assertSame('Blender launch funnel', $sale['description']);
        $this->assertSame('2026-09-10T08:30:00Z', $sale['created'], 'site-time date_added, as UTC');
        $this->assertSame(6, $sale['step_count']);
        $this->assertSame([ 'landing', 'optin', 'optin_ty', 'wc_checkout', 'wc_upsells', 'wc_thankyou' ], $sale['step_types']);
        $this->assertArrayNotHasKey('steps', $sale, 'the list carries a summary, not the raw step list');

        $draft = array_column($result['funnels'], null, 'id')[ $empty ];
        $this->assertSame(0, $draft['step_count']);
        $this->assertSame([], $draft['step_types']);
    }

    public function test_list_pages_and_searches_by_title(): void
    {
        foreach ([ 'Alpha launch', 'Beta launch', 'Gamma webinar' ] as $title) {
            wpmcp_test_funnelkit_funnel($title, []);
        }

        $page = $this->read(self::LIST_OP, [ 'per_page' => 2, 'page' => 2 ])['result'];
        $this->assertSame(3, $page['total']);
        $this->assertSame(2, $page['page']);
        $this->assertSame(2, $page['per_page']);
        $this->assertSame([ 'Alpha launch' ], array_column($page['funnels'], 'title'));

        $found = $this->read(self::LIST_OP, [ 'search' => 'launch' ])['result'];
        $this->assertSame(2, $found['total']);
        $this->assertSame([ 'Beta launch', 'Alpha launch' ], array_column($found['funnels'], 'title'));

        $this->assertSame('invalid_args', $this->read(self::LIST_OP, [ 'per_page' => 51 ])['error']['code'] ?? null);
    }

    // ---------------------------------------------------------------
    // get-funnelkit-funnel
    // ---------------------------------------------------------------

    public function test_get_returns_steps_in_order_with_linked_posts_products_status_and_counts(): void
    {
        $ids = $this->seed_sales_funnel();

        $out = $this->read(self::GET_OP, [ 'id' => $ids['funnel'] ]);
        $this->assertArrayNotHasKey('error', $out);
        $result = $out['result'];

        $this->assertSame($ids['funnel'], $result['id']);
        $this->assertSame('Spring sale', $result['title']);
        $this->assertFalse($result['store_checkout']);
        $this->assertSame([ 1, 2, 3, 4, 5, 6 ], array_column($result['steps'], 'position'));
        $this->assertSame(
            [ 'landing', 'optin', 'optin_ty', 'wc_checkout', 'wc_upsells', 'wc_thankyou' ],
            array_column($result['steps'], 'type')
        );

        $steps = $this->steps_by_type($result);

        $landing = $steps['landing'];
        $this->assertSame($ids['landing'], $landing['post_id']);
        $this->assertSame('wffn_landing', $landing['post_type']);
        $this->assertSame('Spring sale landing', $landing['title']);
        $this->assertSame('active', $landing['status']);
        $this->assertSame([], $landing['product_ids']);
        $this->assertSame([ 'views' => 150, 'conversions' => 40 ], $landing['counts']);

        $this->assertSame([ 'views' => 50, 'conversions' => 2 ], $steps['optin']['counts']);
        $this->assertSame([ 'views' => 18, 'conversions' => 0 ], $steps['optin_ty']['counts']);

        $checkout = $steps['wc_checkout'];
        $this->assertSame('wfacp_checkout', $checkout['post_type']);
        $this->assertSame([ $ids['product_a'], $ids['variation'] ], $checkout['product_ids']);
        $this->assertSame([ 'views' => 35, 'conversions' => 3 ], $checkout['counts']);

        $thankyou = $steps['wc_thankyou'];
        $this->assertSame('wffn_ty', $thankyou['post_type']);
        $this->assertSame('inactive', $thankyou['status']);
        $this->assertSame([ 'views' => 9, 'conversions' => null, 'conversions_unavailable' => 'FunnelKit does not track this for the step type' ], $thankyou['counts']);
        $this->assertSame(
            [ 'views' => null, 'conversions' => null, 'views_unavailable' => 'FunnelKit counts upsells per offer, not per step', 'conversions_unavailable' => 'FunnelKit counts upsells per offer, not per step' ],
            $steps['wc_upsells']['counts']
        );
    }

    public function test_views_carry_a_reason_when_no_add_on_records_them(): void
    {
        $ids = $this->seed_sales_funnel();
        remove_all_filters('wpmcp_funnelkit_views_recorded');
        add_filter('wpmcp_funnelkit_views_recorded', '__return_false');

        $steps  = $this->steps_by_type($this->read(self::GET_OP, [ 'id' => $ids['funnel'] ])['result']);
        $reason = 'recorded only by the Funnel Builder Pro add-on, which is not active';

        $this->assertSame([ 'views' => null, 'conversions' => null, 'views_unavailable' => $reason, 'conversions_unavailable' => $reason ], $steps['landing']['counts'], 'landing conversions are view rows too');
        $this->assertSame([ 'views' => null, 'conversions' => 2, 'views_unavailable' => $reason ], $steps['optin']['counts'], 'optin entries are the free plugin\'s own');
        $this->assertSame([ 'views' => null, 'conversions' => null, 'views_unavailable' => $reason, 'conversions_unavailable' => $reason ], $steps['optin_ty']['counts']);
        $this->assertSame([ 'views' => null, 'conversions' => 3, 'views_unavailable' => $reason ], $steps['wc_checkout']['counts'], 'checkout orders are the free plugin\'s own');
        $this->assertSame(null, $steps['wc_thankyou']['counts']['views']);
        $this->assertSame($reason, $steps['wc_thankyou']['counts']['views_unavailable']);
    }

    public function test_presence_of_the_paid_add_ons_defaults_to_their_own_classes(): void
    {
        $ids = $this->seed_sales_funnel();
        remove_all_filters('wpmcp_funnelkit_views_recorded');
        remove_all_filters('wpmcp_funnelkit_upsells_active');

        $steps = $this->steps_by_type($this->read(self::GET_OP, [ 'id' => $ids['funnel'] ])['result']);

        $this->assertSame(class_exists('WFFN_Report_Views'), ! isset($steps['landing']['counts']['views_unavailable']));
        $this->assertSame(class_exists('WFOCU_Core'), ! isset($steps['wc_upsells']['offers_unavailable']));
    }

    public function test_counts_carry_a_reason_when_a_stats_table_is_missing(): void
    {
        $ids = $this->seed_sales_funnel();
        foreach ([ 'wfco_report_views', 'wfacp_stats', 'bwf_optin_entries' ] as $table) {
            $this->hide_table($table);
        }

        $steps = $this->steps_by_type($this->read(self::GET_OP, [ 'id' => $ids['funnel'] ])['result']);

        $this->assertSame(
            [ 'views' => null, 'conversions' => null, 'views_unavailable' => 'wfco_report_views table missing', 'conversions_unavailable' => 'wfacp_stats table missing' ],
            $steps['wc_checkout']['counts']
        );
        $this->assertSame('bwf_optin_entries table missing', $steps['optin']['counts']['conversions_unavailable']);
        $this->assertSame('wfco_report_views table missing', $steps['landing']['counts']['conversions_unavailable']);
    }

    public function test_a_step_without_a_post_id_says_why_it_has_no_counts(): void
    {
        $funnel = wpmcp_test_funnelkit_funnel('Half built', [ [ 'type' => 'landing', 'id' => 0 ] ]);

        $step = $this->read(self::GET_OP, [ 'id' => $funnel ])['result']['steps'][0];

        $this->assertNull($step['post_id']);
        $this->assertSame([ 'views' => null, 'conversions' => null, 'views_unavailable' => 'step has no post id', 'conversions_unavailable' => 'step has no post id' ], $step['counts']);
    }

    public function test_list_says_why_when_the_funnels_table_is_missing(): void
    {
        $this->hide_table('bwf_funnels');

        $result = $this->read(self::LIST_OP)['result'];

        $this->assertSame([], $result['funnels']);
        $this->assertSame('bwf_funnels table missing', $result['funnels_unavailable']);

        remove_all_filters('query');
        $this->assertArrayNotHasKey('funnels_unavailable', $this->read(self::LIST_OP)['result']);
    }

    public function test_get_lists_upsell_offers_with_their_products(): void
    {
        $ids = $this->seed_sales_funnel();

        $upsells = $this->steps_by_type($this->read(self::GET_OP, [ 'id' => $ids['funnel'] ])['result'])['wc_upsells'];

        $this->assertSame($ids['upsells'], $upsells['post_id']);
        $this->assertSame('wfocu_funnel', $upsells['post_type']);
        $this->assertSame([ $ids['product_c'] ], $upsells['product_ids'], 'an upsell step links the products its offers sell');
        $this->assertSame(
            [
                [ 'post_id' => $ids['offer1'], 'type' => 'upsell', 'title' => 'Add the travel case', 'status' => 'active', 'product_ids' => [ $ids['product_c'] ] ],
                [ 'post_id' => $ids['offer2'], 'type' => 'downsell', 'title' => 'Or the mini case', 'status' => 'inactive', 'product_ids' => [] ],
            ],
            $upsells['offers']
        );
        $this->assertArrayNotHasKey('offers_unavailable', $upsells);
        $this->assertArrayNotHasKey('offers', $this->steps_by_type($this->read(self::GET_OP, [ 'id' => $ids['funnel'] ])['result'])['landing']);
    }

    public function test_offers_carry_a_reason_when_the_upsell_add_on_is_inactive(): void
    {
        $ids = $this->seed_sales_funnel();
        remove_all_filters('wpmcp_funnelkit_upsells_active');
        add_filter('wpmcp_funnelkit_upsells_active', '__return_false');

        $upsells = $this->steps_by_type($this->read(self::GET_OP, [ 'id' => $ids['funnel'] ])['result'])['wc_upsells'];

        $this->assertArrayNotHasKey('offers', $upsells, 'no empty list that reads as "no offers"');
        $this->assertSame('upsell add-on not active', $upsells['offers_unavailable']);
        $this->assertSame([], $upsells['product_ids']);
    }

    public function test_offers_carry_a_reason_when_no_offer_list_is_stored(): void
    {
        $ids = $this->seed_sales_funnel();
        delete_post_meta($ids['upsells'], '_funnel_steps');

        $upsells = $this->steps_by_type($this->read(self::GET_OP, [ 'id' => $ids['funnel'] ])['result'])['wc_upsells'];

        $this->assertArrayNotHasKey('offers', $upsells);
        $this->assertSame('no offer list stored in _funnel_steps', $upsells['offers_unavailable']);

        update_post_meta($ids['upsells'], '_funnel_steps', []);
        $upsells = $this->steps_by_type($this->read(self::GET_OP, [ 'id' => $ids['funnel'] ])['result'])['wc_upsells'];
        $this->assertSame([], $upsells['offers'], 'a stored empty list is a real "no offers"');
        $this->assertArrayNotHasKey('offers_unavailable', $upsells);
    }

    public function test_get_reports_a_step_whose_post_is_gone_and_the_store_checkout(): void
    {
        $landing = $this->step('wffn_landing', 'Kept');
        $funnel  = wpmcp_test_funnelkit_funnel('Store checkout', [
            [ 'type' => 'landing', 'id' => $landing ],
            [ 'type' => 'wc_checkout', 'id' => 999999 ],
        ]);
        update_option('_bwf_global_funnel', $funnel);

        $result = $this->read(self::GET_OP, [ 'id' => $funnel ])['result'];

        $this->assertTrue($result['store_checkout']);
        $gone = $result['steps'][1];
        $this->assertSame(999999, $gone['post_id']);
        $this->assertNull($gone['post_type']);
        $this->assertNull($gone['title']);
        $this->assertSame('missing', $gone['status']);
        $this->assertSame([], $gone['product_ids']);
    }

    public function test_get_refuses_an_unknown_funnel(): void
    {
        $out = $this->read(self::GET_OP, [ 'id' => 424242 ]);
        $this->assertSame('funnel_not_found', $out['error']['code'] ?? null);

        $this->assertSame('invalid_args', $this->read(self::GET_OP, [])['error']['code'] ?? null);
    }

    public function test_no_customer_personal_data_is_returned(): void
    {
        $ids = $this->seed_sales_funnel();

        $payloads = [
            $this->read(self::LIST_OP),
            $this->read(self::GET_OP, [ 'id' => $ids['funnel'] ]),
        ];
        foreach ($payloads as $payload) {
            $this->assertArrayNotHasKey('error', $payload);
            $json = (string) wp_json_encode($payload);
            foreach ([ self::LEAD_EMAIL, self::LEAD_NAME, self::LEAD_PHONE, 'optin_first_name', '"cid"', 'order_id', 'total_revenue' ] as $needle) {
                $this->assertStringNotContainsString($needle, $json, "funnel output must not carry {$needle}");
            }
        }
    }
}
