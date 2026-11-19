<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\HubSpot_Pack;
use WPMCP\Integrations\Jetpack_Crm_Pack;
use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Mutation_Failed;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * Jetpack CRM contacts and forms, and HubSpot forms (issue #684): paid-tier
 * ops on the plugin-data pair, so they add no top-level tools.
 *
 * Neither plugin is loaded in the shared test core. Jetpack CRM's tables are
 * built here from its own definitions (6.8.7, ZeroBSCRM.Database.php) and
 * its contacts API ($zbs->DAL->contacts, addUpdateContact and
 * deleteContact) is a stand-in that writes those tables the way the plugin
 * does. Presence is driven through wpmcp_jetpack_crm_active and
 * wpmcp_hubspot_active; HubSpot's account is its leadin_* options.
 */
class JetpackCrmHubSpotPackTest extends \WP_UnitTestCase
{
    private const OPS = [
        'jetpackcrm-list-contacts'  => 'read',
        'jetpackcrm-get-contact'    => 'read',
        'jetpackcrm-list-forms'     => 'read',
        'jetpackcrm-create-contact' => 'write',
        'jetpackcrm-update-contact' => 'write',
        'hubspot-list-forms'        => 'read',
    ];

    /** @var array<int, array{0: string, 1: array}> calls into the stand-in API */
    public static array $calls = [];

    private int $admin;

    /** @var mixed */
    private $saved_zbs;

    public static function wpSetUpBeforeClass(): void
    {
        global $wpdb;
        $p = $wpdb->prefix;
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$p}zbs_contacts (
            `ID` INT NOT NULL AUTO_INCREMENT,
            `zbs_site` INT NULL DEFAULT NULL,
            `zbs_team` INT NULL DEFAULT NULL,
            `zbs_owner` INT NOT NULL,
            `zbsc_status` VARCHAR(100) NULL,
            `zbsc_email` VARCHAR(200) NULL,
            `zbsc_prefix` VARCHAR(30) NULL,
            `zbsc_fname` VARCHAR(100) NULL,
            `zbsc_lname` VARCHAR(100) NULL,
            `zbsc_addr1` VARCHAR(200) NULL,
            `zbsc_addr2` VARCHAR(200) NULL,
            `zbsc_city` VARCHAR(200) NULL,
            `zbsc_county` VARCHAR(200) NULL,
            `zbsc_country` VARCHAR(200) NULL,
            `zbsc_postcode` VARCHAR(50) NULL,
            `zbsc_secaddr1` VARCHAR(200) NULL,
            `zbsc_secaddr2` VARCHAR(200) NULL,
            `zbsc_seccity` VARCHAR(200) NULL,
            `zbsc_seccounty` VARCHAR(200) NULL,
            `zbsc_seccountry` VARCHAR(200) NULL,
            `zbsc_secpostcode` VARCHAR(50) NULL,
            `zbsc_hometel` VARCHAR(40) NULL,
            `zbsc_worktel` VARCHAR(40) NULL,
            `zbsc_mobtel` VARCHAR(40) NULL,
            `zbsc_wpid` INT NULL DEFAULT NULL,
            `zbsc_avatar` VARCHAR(300) NULL,
            `zbsc_tw` VARCHAR(100) NULL,
            `zbsc_li` VARCHAR(300) NULL,
            `zbsc_fb` VARCHAR(200) NULL,
            `zbsc_created` INT(14) NOT NULL,
            `zbsc_lastupdated` INT(14) NOT NULL,
            `zbsc_lastcontacted` INT(14) NULL DEFAULT NULL,
            PRIMARY KEY (`ID`)
        )");
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$p}zbs_forms (
            `ID` INT NOT NULL AUTO_INCREMENT,
            `zbs_site` INT NULL DEFAULT NULL,
            `zbs_team` INT NULL DEFAULT NULL,
            `zbs_owner` INT NOT NULL,
            `zbsf_title` VARCHAR(200) NULL DEFAULT NULL,
            `zbsf_style` VARCHAR(20) NOT NULL,
            `zbsf_views` INT(10) NULL DEFAULT 0,
            `zbsf_conversions` INT(10) NULL DEFAULT 0,
            `zbsf_created` INT(14) NOT NULL,
            `zbsf_lastupdated` INT(14) NOT NULL,
            PRIMARY KEY (`ID`)
        )");
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$p}zbs_tags (
            `ID` INT NOT NULL AUTO_INCREMENT,
            `zbs_site` INT NULL DEFAULT NULL,
            `zbs_team` INT NULL DEFAULT NULL,
            `zbs_owner` INT NOT NULL,
            `zbstag_objtype` INT NOT NULL,
            `zbstag_name` VARCHAR(200) NOT NULL,
            `zbstag_slug` VARCHAR(200) NOT NULL,
            `zbstag_created` INT(14) NOT NULL,
            `zbstag_lastupdated` INT(14) NOT NULL,
            PRIMARY KEY (`ID`)
        )");
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$p}zbs_tags_links (
            `ID` INT NOT NULL AUTO_INCREMENT,
            `zbs_site` INT NULL DEFAULT NULL,
            `zbs_team` INT NULL DEFAULT NULL,
            `zbs_owner` INT NOT NULL,
            `zbstl_objtype` INT(4) NOT NULL,
            `zbstl_objid` INT NOT NULL,
            `zbstl_tagid` INT NOT NULL,
            PRIMARY KEY (`ID`)
        )");
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$p}zbs_object_links (
            `ID` INT NOT NULL AUTO_INCREMENT,
            `zbs_site` INT NULL DEFAULT NULL,
            `zbs_team` INT NULL DEFAULT NULL,
            `zbs_owner` INT NOT NULL,
            `zbsol_objtype_from` INT(4) NOT NULL,
            `zbsol_objtype_to` INT(4) NOT NULL,
            `zbsol_objid_from` INT NOT NULL,
            `zbsol_objid_to` INT NOT NULL,
            PRIMARY KEY (`ID`)
        )");
    }

    public static function wpTearDownAfterClass(): void
    {
        global $wpdb;
        foreach ([ 'zbs_contacts', 'zbs_forms', 'zbs_tags', 'zbs_tags_links', 'zbs_object_links' ] as $t) {
            $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . $t);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        $this->admin = self::factory()->user->create([ 'role' => 'administrator' ]);
        wp_set_current_user($this->admin);
        add_filter('wpmcp_jetpack_crm_active', '__return_true');
        add_filter('wpmcp_hubspot_active', '__return_true');
        add_filter('wpmcp_enable_jetpack_crm_write', '__return_true');
        self::$calls     = [];
        $this->saved_zbs = $GLOBALS['zbs'] ?? null;
        $GLOBALS['zbs']  = (object) [ 'DAL' => (object) [ 'contacts' => new Fake_Jpcrm_Contacts() ] ];
    }

    protected function tearDown(): void
    {
        global $wpdb;
        foreach ([ 'zbs_contacts', 'zbs_forms', 'zbs_tags', 'zbs_tags_links', 'zbs_object_links' ] as $t) {
            $wpdb->query('DELETE FROM ' . $wpdb->prefix . $t);
        }
        remove_all_filters('wpmcp_jetpack_crm_active');
        remove_all_filters('wpmcp_hubspot_active');
        remove_all_filters('wpmcp_enable_jetpack_crm_write');
        foreach ([ 'leadin_portalId', 'leadin_account_name', 'leadin_hublet', 'leadin_refresh_token' ] as $o) {
            delete_option($o);
        }
        $GLOBALS['zbs'] = $this->saved_zbs;
        Gate::set_pro_for_tests(null);
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

    /** A contact as addUpdateContact() inserts one. */
    private function contact(array $fields = []): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'zbs_contacts', $fields + [
            'zbs_site'         => 1,
            'zbs_team'         => 1,
            'zbs_owner'        => -1,
            'zbsc_status'      => 'Lead',
            'zbsc_email'       => 'jane.doe@example.com',
            'zbsc_fname'       => 'Jane',
            'zbsc_lname'       => 'Doe',
            'zbsc_mobtel'      => '+15550100',
            'zbsc_city'        => 'Lahore',
            'zbsc_created'     => 1767225600,
            'zbsc_lastupdated' => 1767225600,
        ]);
        return (int) $wpdb->insert_id;
    }

    private function row(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE ID = %d', $wpdb->prefix . 'zbs_contacts', $id), ARRAY_A);
        return is_array($row) ? $row : null;
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

    public function test_presence_defaults_to_the_plugin_constants(): void
    {
        remove_all_filters('wpmcp_jetpack_crm_active');
        remove_all_filters('wpmcp_hubspot_active');
        $this->assertSame(defined('JPCRM_VERSION') || defined('ZBS_ROOTFILE'), Jetpack_Crm_Pack::active());
        $this->assertSame(defined('LEADIN_PLUGIN_VERSION'), HubSpot_Pack::active());
    }

    public function test_a_missing_plugin_answers_plugin_missing_naming_it(): void
    {
        add_filter('wpmcp_jetpack_crm_active', '__return_false', 20);
        add_filter('wpmcp_hubspot_active', '__return_false', 20);
        $out = $this->read('jetpackcrm-list-contacts');
        $this->assertSame('plugin_missing', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertStringContainsString('Install and activate Jetpack CRM', $out['error']['message']);
        $out = $this->read('hubspot-list-forms');
        $this->assertSame('plugin_missing', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertStringContainsString('Install and activate HubSpot', $out['error']['message']);
    }

    public function test_without_the_paid_tier_no_op_is_listed(): void
    {
        Gate::set_pro_for_tests(false);
        $this->assertFalse(Jetpack_Crm_Pack::active());
        $this->assertFalse(HubSpot_Pack::active());
        $names = array_column((new Plugin_Data_Integration())->catalog()['operations'], 'name');
        $this->assertSame([], preg_grep('/^(jetpackcrm|hubspot)-/', $names));
    }

    public function test_writes_are_off_until_the_site_opts_in(): void
    {
        $id = $this->contact();
        remove_all_filters('wpmcp_enable_jetpack_crm_write');
        foreach ([
            [ 'jetpackcrm-create-contact', [ 'email' => 'new@example.com' ] ],
            [ 'jetpackcrm-update-contact', [ 'id' => $id, 'first_name' => 'X' ] ],
        ] as [ $op, $args ]) {
            $out = $this->write($op, $args);
            $this->assertSame('opt_in_required', $out['error']['code'] ?? null, $op);
            $this->assertStringContainsString('wpmcp_enable_jetpack_crm_write', $out['error']['message']);
        }
        $this->assertSame('Jane', $this->row($id)['zbsc_fname']);
        $this->assertSame([], self::$calls);
    }

    public function test_users_without_the_jetpack_crm_capabilities_are_refused(): void
    {
        $id = $this->contact();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        $this->assertSame('operation_denied', $this->read('jetpackcrm-list-contacts')['error']['code'] ?? null);
        $this->assertSame('operation_denied', $this->read('jetpackcrm-get-contact', [ 'id' => $id ])['error']['code'] ?? null);
        $this->assertSame('operation_denied', $this->read('jetpackcrm-list-forms')['error']['code'] ?? null);
        $this->assertSame('operation_denied', $this->write('jetpackcrm-update-contact', [ 'id' => $id, 'first_name' => 'X' ])['error']['code'] ?? null);

        $editor = wp_get_current_user();
        $editor->add_cap('admin_zerobs_view_customers');
        $this->assertArrayNotHasKey('error', $this->read('jetpackcrm-list-contacts'));
    }

    // ---------------------------------------------------------------
    // Jetpack CRM reads
    // ---------------------------------------------------------------

    public function test_list_contacts_masks_personal_data_and_filters(): void
    {
        $jane = $this->contact();
        $this->contact([ 'zbsc_email' => 'bob@shop.example.com', 'zbsc_fname' => 'Bob', 'zbsc_lname' => 'Stone', 'zbsc_status' => 'Customer' ]);

        $out = $this->read('jetpackcrm-list-contacts');
        $this->assertTrue($out['masked']);
        $this->assertSame(2, $out['total']);
        $first = $out['contacts'][0];
        $this->assertSame('b***@shop.example.com', $first['email']);
        $this->assertSame('B***', $first['first_name']);
        $this->assertSame('Customer', $first['status']);
        $this->assertArrayNotHasKey('mobile_phone', $first);

        $out = $this->read('jetpackcrm-list-contacts', [ 'status' => 'Lead' ]);
        $this->assertSame([ $jane ], array_column($out['contacts'], 'id'));
        $out = $this->read('jetpackcrm-list-contacts', [ 'search' => 'stone' ]);
        $this->assertSame(1, $out['total']);
        $out = $this->read('jetpackcrm-list-contacts', [ 'per_page' => 1, 'page' => 2 ]);
        $this->assertSame([ $jane ], array_column($out['contacts'], 'id'));
    }

    public function test_get_contact_returns_full_fields_and_tags_to_an_administrator(): void
    {
        global $wpdb;
        $id = $this->contact();
        $wpdb->insert($wpdb->prefix . 'zbs_tags', [ 'zbs_owner' => -1, 'zbstag_objtype' => 1, 'zbstag_name' => 'VIP', 'zbstag_slug' => 'vip', 'zbstag_created' => 1, 'zbstag_lastupdated' => 1 ]);
        $wpdb->insert($wpdb->prefix . 'zbs_tags_links', [ 'zbs_owner' => -1, 'zbstl_objtype' => 1, 'zbstl_objid' => $id, 'zbstl_tagid' => (int) $wpdb->insert_id ]);

        $contact = $this->read('jetpackcrm-get-contact', [ 'id' => $id ])['contact'];
        $this->assertSame('jane.doe@example.com', $contact['email']);
        $this->assertSame('Jane', $contact['first_name']);
        $this->assertSame('+15550100', $contact['mobile_phone']);
        $this->assertSame('Lahore', $contact['city']);
        $this->assertSame([ 'VIP' ], $contact['tags']);

        $by_email = $this->read('jetpackcrm-get-contact', [ 'email' => 'JANE.DOE@example.com' ])['contact'];
        $this->assertSame($id, $by_email['id']);
        $this->assertSame('contact_not_found', $this->read('jetpackcrm-get-contact', [ 'id' => $id + 99 ])['error']['code'] ?? null);
        $this->assertSame('invalid_args', $this->read('jetpackcrm-get-contact', [])['error']['code'] ?? null);
    }

    public function test_get_contact_is_masked_below_manage_options(): void
    {
        $id   = $this->contact();
        $user = self::factory()->user->create_and_get([ 'role' => 'editor' ]);
        $user->add_cap('admin_zerobs_view_customers');
        wp_set_current_user($user->ID);
        $out = $this->read('jetpackcrm-get-contact', [ 'id' => $id ]);
        $this->assertTrue($out['masked']);
        $this->assertSame('j***@example.com', $out['contact']['email']);
        $this->assertSame('+***', $out['contact']['mobile_phone']);
    }

    public function test_list_forms_returns_each_form_with_its_counts_and_shortcode(): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'zbs_forms', [ 'zbs_owner' => -1, 'zbsf_title' => 'Newsletter', 'zbsf_style' => 'simple', 'zbsf_views' => 40, 'zbsf_conversions' => 4, 'zbsf_created' => 1767225600, 'zbsf_lastupdated' => 1767225600 ]);
        $form = (int) $wpdb->insert_id;
        $out  = $this->read('jetpackcrm-list-forms');
        $this->assertSame(1, $out['total']);
        $this->assertSame([
            'id'          => $form,
            'title'       => 'Newsletter',
            'style'       => 'simple',
            'views'       => 40,
            'conversions' => 4,
            'shortcode'   => '[jetpackcrm_form id="' . $form . '"]',
            'created'     => '2026-01-01 00:00:00',
        ], $out['forms'][0]);
    }

    // ---------------------------------------------------------------
    // Jetpack CRM writes
    // ---------------------------------------------------------------

    public function test_create_contact_goes_through_the_plugin_and_rollback_removes_it(): void
    {
        $existing = $this->contact();
        $out      = $this->ok($this->write('jetpackcrm-create-contact', [ 'email' => 'New.Person@Example.com', 'first_name' => 'New', 'last_name' => 'Person', 'status' => 'Lead', 'mobile_phone' => '+15550111' ]));
        $this->assertTrue($out['recoverable']);
        $contact = $out['result']['contact'];
        $this->assertGreaterThan($existing, $contact['id']);
        $this->assertSame('New.Person@Example.com', $contact['email']);
        $this->assertSame('New', $contact['first_name']);
        $this->assertSame('addUpdateContact', self::$calls[0][0]);
        $this->assertSame('New.Person@Example.com', self::$calls[0][1]['data']['email']);
        $this->assertSame('+15550111', self::$calls[0][1]['data']['mobtel']);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertNull($this->row($contact['id']));
        $this->assertSame('deleteContact', self::$calls[1][0], 'the undo removes the contact through Jetpack CRM');
        $this->assertNotNull($this->row($existing));
    }

    public function test_create_contact_tracks_the_id_the_plugin_assigned(): void
    {
        // Another writer takes the id the snapshot reserved, so the plugin
        // assigns a later one.
        $first                     = $this->contact();
        Fake_Jpcrm_Contacts::$skip = 5;

        $out = $this->ok($this->write('jetpackcrm-create-contact', [ 'email' => 'skip@example.com' ]));
        $id  = $out['result']['contact']['id'];
        $this->assertSame($first + 6, $id);
        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertNull($this->row($id));
        $this->assertNotNull($this->row($first));
    }

    public function test_create_contact_refuses_an_email_that_already_exists(): void
    {
        $this->contact();
        $out = $this->write('jetpackcrm-create-contact', [ 'email' => 'Jane.Doe@example.com' ]);
        $this->assertSame('contact_exists', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertSame([], self::$calls);
    }

    public function test_undo_of_a_create_is_refused_once_the_contact_has_invoices(): void
    {
        global $wpdb;
        $out = $this->ok($this->write('jetpackcrm-create-contact', [ 'email' => 'buyer@example.com' ]));
        $id  = $out['result']['contact']['id'];
        $wpdb->insert($wpdb->prefix . 'zbs_object_links', [ 'zbs_owner' => -1, 'zbsol_objtype_from' => 4, 'zbsol_objtype_to' => 1, 'zbsol_objid_from' => 77, 'zbsol_objid_to' => $id ]);

        try {
            Rollback_Service::restore_operation($out['operation_id']);
            $this->fail('the undo should be refused');
        } catch (Mutation_Failed $e) {
            $this->assertStringContainsString('left in place', $e->getMessage());
        }
        $this->assertNotNull($this->row($id), 'a contact with an invoice since is left in place');
        $this->assertCount(1, self::$calls, 'deleteContact was never called');
    }

    public function test_update_contact_changes_only_the_given_fields_and_rollback_restores_them(): void
    {
        $id  = $this->contact();
        $out = $this->ok($this->write('jetpackcrm-update-contact', [ 'id' => $id, 'first_name' => 'Janet', 'status' => 'Customer', 'email' => 'janet@example.com' ]));
        $this->assertTrue($out['recoverable']);
        $row = $this->row($id);
        $this->assertSame('Janet', $row['zbsc_fname']);
        $this->assertSame('Customer', $row['zbsc_status']);
        $this->assertSame('janet@example.com', $row['zbsc_email']);
        $this->assertSame('Doe', $row['zbsc_lname']);
        $this->assertSame('+15550100', $row['zbsc_mobtel']);
        $keys = array_column(self::$calls[0][1]['limitedFields'], 'key');
        sort($keys);
        $this->assertSame([ 'zbsc_email', 'zbsc_fname', 'zbsc_status' ], $keys);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $row = $this->row($id);
        $this->assertSame('Jane', $row['zbsc_fname']);
        $this->assertSame('Lead', $row['zbsc_status']);
        $this->assertSame('jane.doe@example.com', $row['zbsc_email']);
    }

    public function test_update_contact_refuses_an_unknown_contact_and_an_email_another_holds(): void
    {
        $id = $this->contact();
        $this->contact([ 'zbsc_email' => 'taken@example.com' ]);
        $this->assertSame('contact_not_found', $this->write('jetpackcrm-update-contact', [ 'id' => $id + 99, 'first_name' => 'X' ])['error']['code'] ?? null);
        $this->assertSame('contact_exists', $this->write('jetpackcrm-update-contact', [ 'id' => $id, 'email' => 'TAKEN@example.com' ])['error']['code'] ?? null);
        $this->assertSame([], self::$calls);
    }

    // ---------------------------------------------------------------
    // HubSpot
    // ---------------------------------------------------------------

    public function test_hubspot_list_forms_reports_the_account_and_every_embedded_form(): void
    {
        update_option('leadin_portalId', '4455667');
        update_option('leadin_account_name', 'Acme Inc');
        update_option('leadin_hublet', 'eu1');
        update_option('leadin_refresh_token', 'secret-token');
        $page  = self::factory()->post->create([ 'post_type' => 'page', 'post_title' => 'Contact', 'post_content' => '[hubspot type="form" portal="4455667" id="aaaa-1111"]' ]);
        $post  = self::factory()->post->create([ 'post_title' => 'Blog', 'post_content' => '<!-- wp:leadin/hubspot-form-block {"portalId":"4455667","formId":"bbbb-2222"} /-->' . "\n" . '[hubspot type="form" portal="4455667" id="aaaa-1111"]' ]);
        $draft = self::factory()->post->create([ 'post_title' => 'Secret', 'post_status' => 'draft', 'post_author' => self::factory()->user->create(), 'post_content' => '[hubspot type="form" portal="4455667" id="cccc-3333"]' ]);
        self::factory()->post->create([ 'post_title' => 'Meeting', 'post_content' => '[hubspot type="meeting" url="https://meetings.hubspot.com/x"]' ]);

        $out = $this->read('hubspot-list-forms');
        $this->assertTrue($out['connected']);
        $this->assertSame('4455667', $out['portal_id']);
        $this->assertSame('Acme Inc', $out['account_name']);
        $this->assertStringNotContainsString('secret-token', (string) wp_json_encode($out));
        $forms = array_column($out['forms'], null, 'form_id');
        $this->assertSame([ 'aaaa-1111', 'bbbb-2222', 'cccc-3333' ], array_keys($forms), 'an administrator sees the draft too');
        $this->assertEqualsCanonicalizing([ $page, $post ], array_column($forms['aaaa-1111']['posts'], 'id'));
        $this->assertSame([ $post ], array_column($forms['bbbb-2222']['posts'], 'id'));

        $author = self::factory()->user->create([ 'role' => 'author' ]);
        wp_set_current_user($author);
        $forms = array_column($this->read('hubspot-list-forms')['forms'], null, 'form_id');
        $this->assertArrayNotHasKey('cccc-3333', $forms, 'another user\'s draft stays hidden');
        unset($draft);
    }

    public function test_hubspot_list_forms_without_an_account_says_not_connected(): void
    {
        $out = $this->read('hubspot-list-forms');
        $this->assertFalse($out['connected']);
        $this->assertNull($out['portal_id']);
        $this->assertSame([], $out['forms']);
    }
}

/**
 * Jetpack CRM's contacts API as the ops call it ($zbs->DAL->contacts): the
 * same arguments and return values, writing the fixture tables the way
 * ZBS_DAL_Contacts does.
 */
class Fake_Jpcrm_Contacts
{
    /** Ids to skip before the next insert, as another writer would take them. */
    public static int $skip = 0;

    /** @return int|false */
    public function addUpdateContact($args = [])
    {
        global $wpdb;
        JetpackCrmHubSpotPackTest::$calls[] = [ 'addUpdateContact', $args ];
        $table = $wpdb->prefix . 'zbs_contacts';
        $id    = (int) ($args['id'] ?? -1);
        if ($id > 0) {
            $set = [];
            foreach ((array) $args['limitedFields'] as $field) {
                $set[ $field['key'] ] = $field['val'];
            }
            $set['zbsc_lastupdated'] = time();
            return false === $wpdb->update($table, $set, [ 'ID' => $id ]) ? false : $id;
        }
        $row = [ 'zbs_site' => 1, 'zbs_team' => 1, 'zbs_owner' => -1, 'zbsc_created' => time(), 'zbsc_lastupdated' => time() ];
        foreach ((array) $args['data'] as $key => $value) {
            if (is_string($value)) {
                $row[ 'zbsc_' . $key ] = $value;
            }
        }
        if (self::$skip > 0) {
            $row['ID']  = (int) $wpdb->get_var($wpdb->prepare('SELECT COALESCE(MAX(ID), 0) FROM %i', $table)) + 1 + self::$skip;
            self::$skip = 0;
        }
        return false === $wpdb->insert($table, $row) ? false : (int) $wpdb->insert_id;
    }

    public function deleteContact($args = []): bool
    {
        global $wpdb;
        JetpackCrmHubSpotPackTest::$calls[] = [ 'deleteContact', $args ];
        $id = (int) ($args['id'] ?? 0);
        $wpdb->delete($wpdb->prefix . 'zbs_tags_links', [ 'zbstl_objtype' => 1, 'zbstl_objid' => $id ]);
        return false !== $wpdb->delete($wpdb->prefix . 'zbs_contacts', [ 'ID' => $id ]);
    }
}
