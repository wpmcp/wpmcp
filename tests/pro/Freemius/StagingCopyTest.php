<?php

namespace WPMCP\Tests\Pro\Freemius;

use WPMCP\Admin\Audit_Log_Page;
use WPMCP\Admin\Management_Access;
use WPMCP\Freemius\Bootstrap;
use WPMCP\Freemius\Staging_Copy;
use WPMCP\Memory\Memory_Store;
use WPMCP\Plugin;

/**
 * Staging copies of a licensed site keep Pro without using a licence site:
 * clone auto-resolution as a temporary duplicate, the SDK's "activate here"
 * notice suppressed, and a banner on wpmcp screens that only states what
 * Freemius confirms.
 */
class StagingCopyTest extends \WP_UnitTestCase
{
    protected function tearDown(): void
    {
        Staging_Copy::set_staging_for_tests(null);
        Staging_Copy::set_clone_manager_for_tests(null);
        Bootstrap::set_fs_for_tests(null);
        remove_action('init', [Staging_Copy::class, 'suppress_sdk_notice'], 11);
        remove_action('admin_notices', [Staging_Copy::class, 'render_banner']);
        set_current_screen('front');
        parent::tearDown();
    }

    // ------------------------------------------------------------ detection

    public function test_reads_the_raw_address_settings_not_filtered_values(): void
    {
        if (defined('WP_HOME') || defined('WP_SITEURL')) {
            $this->markTestSkipped('The address constants override the options on this install.');
        }

        // A multilingual or domain-mapping plugin filtering the address must
        // not make a live site read as staging.
        add_filter('option_home', static fn () => 'https://staging.example.org');
        add_filter('option_siteurl', static fn () => 'https://staging.example.org');
        $this->assertFalse(Staging_Copy::is_staging_site());
        remove_all_filters('option_home');
        remove_all_filters('option_siteurl');

        update_option('siteurl', 'http://example.org/staging/7380');
        $this->assertTrue(Staging_Copy::is_staging_site());
    }

    public function test_the_test_install_itself_is_not_staging(): void
    {
        $this->assertFalse(Staging_Copy::is_staging_site());
    }

    // ------------------------------------------------- clone auto-resolution

    public function test_clone_constant_is_not_defined_on_a_live_site(): void
    {
        // Plugin load already ran Bootstrap::init() on example.org.
        $this->assertFalse(defined('FS__RESOLVE_CLONE_AS'));

        $this->assertFalse(Staging_Copy::define_clone_resolution(false, 'WPMCP_TEST_RESOLVE_LIVE'));
        $this->assertFalse(defined('WPMCP_TEST_RESOLVE_LIVE'));
    }

    public function test_clone_constant_is_defined_as_temporary_duplicate_on_staging(): void
    {
        $this->assertSame('FS__RESOLVE_CLONE_AS', Staging_Copy::CLONE_CONSTANT);

        $this->assertTrue(Staging_Copy::define_clone_resolution(true, 'WPMCP_TEST_RESOLVE_STAGING'));
        $this->assertSame('temporary_duplicate', constant('WPMCP_TEST_RESOLVE_STAGING'));
    }

    public function test_a_site_owner_value_for_the_clone_constant_wins(): void
    {
        define('WPMCP_TEST_RESOLVE_OWNER', 'new_home');

        $this->assertFalse(Staging_Copy::define_clone_resolution(true, 'WPMCP_TEST_RESOLVE_OWNER'));
        $this->assertSame('new_home', constant('WPMCP_TEST_RESOLVE_OWNER'));
    }

    public function test_the_sdk_reads_the_constant_lazily_on_init(): void
    {
        // The constant only works if the SDK reads it after plugin load. Pin
        // that assumption: the clone manager hooks its resolver to init.
        $this->assertTrue(class_exists(\FS_Clone_Manager::class));
        $this->assertNotFalse(has_action('init', [\FS_Clone_Manager::instance(), '_init']));
        $this->assertSame(10, has_action('init', [\FS_Clone_Manager::instance(), '_init']));
    }

    // ---------------------------------------------------------------- hooks

    public function test_register_adds_nothing_on_a_live_site(): void
    {
        set_current_screen('dashboard');
        Staging_Copy::set_staging_for_tests(false);

        Staging_Copy::register();

        $this->assertFalse(has_action('init', [Staging_Copy::class, 'suppress_sdk_notice']));
        $this->assertFalse(has_action('admin_notices', [Staging_Copy::class, 'render_banner']));
    }

    public function test_register_suppresses_after_the_sdk_clone_init_on_staging(): void
    {
        set_current_screen('dashboard');
        Staging_Copy::set_staging_for_tests(true);

        Staging_Copy::register();

        // After FS_Clone_Manager::_init (init, 10) adds the sticky notice and
        // before admin_notices renders it, so it never flashes.
        $this->assertSame(11, has_action('init', [Staging_Copy::class, 'suppress_sdk_notice']));
        $this->assertSame(10, has_action('admin_notices', [Staging_Copy::class, 'render_banner']));
    }

    // ---------------------------------------------------- notice suppression

    public function test_suppression_removes_the_temporary_duplicate_notice_on_staging(): void
    {
        $manager = new Fake_Clone_Manager(notice_shown: true);
        Staging_Copy::set_clone_manager_for_tests($manager);
        Staging_Copy::set_staging_for_tests(true);

        $this->assertTrue(Staging_Copy::suppress_sdk_notice());
        $this->assertSame(1, $manager->removed);
    }

    public function test_suppression_does_nothing_on_a_live_site(): void
    {
        $manager = new Fake_Clone_Manager(notice_shown: true);
        Staging_Copy::set_clone_manager_for_tests($manager);
        Staging_Copy::set_staging_for_tests(false);

        $this->assertFalse(Staging_Copy::suppress_sdk_notice());
        $this->assertSame(0, $manager->removed);
    }

    public function test_suppression_skips_the_write_when_no_notice_is_stored(): void
    {
        $manager = new Fake_Clone_Manager(notice_shown: false);
        Staging_Copy::set_clone_manager_for_tests($manager);
        Staging_Copy::set_staging_for_tests(true);

        $this->assertFalse(Staging_Copy::suppress_sdk_notice());
        $this->assertSame(0, $manager->removed);
    }

    public function test_suppression_is_inert_without_the_sdk_method(): void
    {
        Staging_Copy::set_clone_manager_for_tests(new \stdClass());
        Staging_Copy::set_staging_for_tests(true);

        $this->assertFalse(Staging_Copy::suppress_sdk_notice());

        Staging_Copy::set_clone_manager_for_tests(false);
        $this->assertFalse(Staging_Copy::suppress_sdk_notice());
    }

    public function test_suppression_against_the_real_sdk_clone_manager(): void
    {
        $manager = \FS_Clone_Manager::instance();
        $manager->add_temporary_duplicate_sticky_notice([WPMCP_FS_ID], 'Activate a license here.', 'WP MCP');
        $this->assertTrue($manager->is_temporary_duplicate_notice_shown());

        Staging_Copy::set_staging_for_tests(true);

        $this->assertTrue(Staging_Copy::suppress_sdk_notice());
        $this->assertFalse($manager->is_temporary_duplicate_notice_shown());
    }

    // -------------------------------------------------------- banner states

    public function test_banner_confirmed_clone_in_temporary_duplicate_mode(): void
    {
        $this->assertSame(
            Staging_Copy::BANNER_CLONE,
            Staging_Copy::banner_state(true, new Fake_Fs(clone: true, premium: true), new Fake_Clone_Manager(selected: true))
        );
    }

    public function test_banner_never_shows_on_a_live_site(): void
    {
        $this->assertNull(Staging_Copy::banner_state(false, new Fake_Fs(clone: true, premium: true), new Fake_Clone_Manager(selected: true)));
        $this->assertNull(Staging_Copy::banner_state(false, new Fake_Fs(), new Fake_Clone_Manager()));
    }

    public function test_banner_hidden_when_temporary_duplicate_mode_expired(): void
    {
        $this->assertNull(Staging_Copy::banner_state(true, new Fake_Fs(clone: true, premium: true), new Fake_Clone_Manager(selected: true, expired: true)));
    }

    public function test_banner_hidden_when_clone_resolved_some_other_way(): void
    {
        // e.g. the owner set FS__RESOLVE_CLONE_AS to new_home in wp-config.
        $this->assertNull(Staging_Copy::banner_state(true, new Fake_Fs(clone: true, premium: true), new Fake_Clone_Manager(selected: false)));
    }

    public function test_banner_hidden_for_a_clone_without_pro(): void
    {
        $this->assertNull(Staging_Copy::banner_state(true, new Fake_Fs(clone: true, premium: false), new Fake_Clone_Manager(selected: true)));
    }

    public function test_banner_hidden_for_an_unregistered_clone(): void
    {
        $this->assertNull(Staging_Copy::banner_state(true, new Fake_Fs(registered: false, clone: true, premium: true), new Fake_Clone_Manager(selected: true)));
    }

    public function test_banner_hidden_for_a_clone_when_clone_state_is_unknown(): void
    {
        $this->assertNull(Staging_Copy::banner_state(true, new Fake_Fs(clone: true, premium: true), null));
        $this->assertNull(Staging_Copy::banner_state(true, new Fake_Fs(clone: true, premium: true), new \stdClass()));
    }

    public function test_banner_fresh_staging_install_without_a_licence(): void
    {
        $this->assertSame(Staging_Copy::BANNER_FRESH, Staging_Copy::banner_state(true, new Fake_Fs(), new Fake_Clone_Manager()));
        $this->assertSame(Staging_Copy::BANNER_FRESH, Staging_Copy::banner_state(true, new Fake_Fs(registered: false), null));
    }

    public function test_banner_hidden_when_a_licence_is_already_active_here(): void
    {
        $this->assertNull(Staging_Copy::banner_state(true, new Fake_Fs(premium: true, license: true), new Fake_Clone_Manager()));
    }

    public function test_banner_hidden_when_an_inactive_licence_is_attached_here(): void
    {
        $this->assertNull(Staging_Copy::banner_state(true, new Fake_Fs(premium: false, license: true), new Fake_Clone_Manager()));
    }

    public function test_banner_hidden_without_the_sdk(): void
    {
        $this->assertNull(Staging_Copy::banner_state(true, null, null));
        $this->assertNull(Staging_Copy::banner_state(true, new \stdClass(), new Fake_Clone_Manager(selected: true)));
    }

    // ------------------------------------------------------ banner rendering

    public function test_render_shows_the_clone_banner_on_wpmcp_screens(): void
    {
        $this->as_admin_on('toplevel_page_wpmcp');
        $this->stage(new Fake_Fs(clone: true, premium: true), new Fake_Clone_Manager(selected: true));

        $html = $this->rendered();

        $this->assertStringContainsString('notice-info', $html);
        $this->assertStringContainsString('Staging copy. This site does not count toward your WP MCP license.', $html);
    }

    public function test_render_shows_the_fresh_install_banner_on_wpmcp_submenus(): void
    {
        $this->as_admin_on('wpmcp_page_wpmcp-connection');
        $this->stage(new Fake_Fs(), new Fake_Clone_Manager());

        $this->assertStringContainsString(
            'This looks like a staging site. Copy it from your licensed live site instead of activating a license here, so it does not use one of your sites.',
            $this->rendered()
        );
    }

    /**
     * Core builds a submenu's screen id from the parent's sanitized menu
     * title, and the wpmcp title carries the pending-proposals badge, so with
     * proposals waiting a submenu is "wpmcp-N_page_...", not "wpmcp_page_...".
     * Same premise as the Pro banner's test; the id comes from core.
     */
    public function test_render_follows_the_real_submenu_screen_id_while_the_menu_carries_a_badge(): void
    {
        if (! post_type_exists(Memory_Store::POST_TYPE)) {
            $this->markTestSkipped('memory group off in this flavor');
        }
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        self::factory()->post->create(['post_type' => Memory_Store::POST_TYPE, 'post_status' => 'pending']);
        self::factory()->post->create(['post_type' => Memory_Store::POST_TYPE, 'post_status' => 'pending']);

        $saved = [$GLOBALS['admin_page_hooks'] ?? null, $GLOBALS['menu'] ?? null, $GLOBALS['submenu'] ?? null, $GLOBALS['_registered_pages'] ?? null];
        try {
            $this->as_admin_on('front');
            Plugin::instance()->register_admin_menu();
            $screen_id = get_plugin_page_hookname(Audit_Log_Page::SLUG, 'wpmcp');
            $this->assertStringStartsWith('wpmcp-2_page_', $screen_id, 'the badge changes the hook prefix; if core stopped doing that, this test needs a new premise');

            set_current_screen($screen_id);
            $this->stage(new Fake_Fs(clone: true, premium: true), new Fake_Clone_Manager(selected: true));

            $this->assertStringContainsString('Staging copy.', $this->rendered());
        } finally {
            [$GLOBALS['admin_page_hooks'], $GLOBALS['menu'], $GLOBALS['submenu'], $GLOBALS['_registered_pages']] = $saved;
        }
    }

    public function test_render_nothing_off_wpmcp_screens(): void
    {
        $this->as_admin_on('dashboard');
        $this->stage(new Fake_Fs(clone: true, premium: true), new Fake_Clone_Manager(selected: true));

        $this->assertSame('', $this->rendered());
    }

    public function test_render_nothing_for_users_below_the_management_capability(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        set_current_screen('toplevel_page_wpmcp');
        $this->stage(new Fake_Fs(clone: true, premium: true), new Fake_Clone_Manager(selected: true));

        $this->assertSame('', $this->rendered());
    }

    public function test_render_nothing_when_the_state_is_unconfirmed(): void
    {
        $this->as_admin_on('toplevel_page_wpmcp');
        $this->stage(new Fake_Fs(clone: true, premium: true), new Fake_Clone_Manager(selected: true, expired: true));

        $this->assertSame('', $this->rendered());
    }

    public function test_render_nothing_on_a_live_site(): void
    {
        $this->as_admin_on('toplevel_page_wpmcp');
        Bootstrap::set_fs_for_tests(new Fake_Fs());
        Staging_Copy::set_clone_manager_for_tests(new Fake_Clone_Manager());
        Staging_Copy::set_staging_for_tests(false);

        $this->assertSame('', $this->rendered());
    }

    private function as_admin_on(string $screen): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->assertTrue(current_user_can(Management_Access::CAPABILITY));
        set_current_screen($screen);
    }

    private function stage(object $fs, object $manager): void
    {
        Bootstrap::set_fs_for_tests($fs);
        Staging_Copy::set_clone_manager_for_tests($manager);
        Staging_Copy::set_staging_for_tests(true);
    }

    private function rendered(): string
    {
        ob_start();
        Staging_Copy::render_banner();
        return (string) ob_get_clean();
    }
}

/**
 * Stand-in for the Freemius instance: just the predicates the banner reads.
 */
class Fake_Fs
{
    public function __construct(
        private bool $registered = true,
        private bool $clone = false,
        private bool $premium = false,
        private bool $license = false
    ) {
    }

    public function is_registered(): bool
    {
        return $this->registered;
    }

    public function is_clone(): bool
    {
        return $this->clone;
    }

    public function can_use_premium_code(): bool
    {
        return $this->premium;
    }

    public function _get_license(): ?object
    {
        return $this->license ? new \stdClass() : null;
    }
}

/**
 * Stand-in for FS_Clone_Manager.
 */
class Fake_Clone_Manager
{
    public int $removed = 0;

    public function __construct(
        private bool $selected = false,
        private bool $expired = false,
        private bool $notice_shown = false
    ) {
    }

    public function was_temporary_duplicate_mode_selected(): bool
    {
        return $this->selected;
    }

    public function has_temporary_duplicate_mode_expired(): bool
    {
        return $this->expired;
    }

    public function is_temporary_duplicate_notice_shown(): bool
    {
        return $this->notice_shown;
    }

    public function remove_temporary_duplicate_notice(): void
    {
        $this->removed++;
        $this->notice_shown = false;
    }
}
