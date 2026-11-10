<?php

namespace WPMCP\Freemius;

use WPMCP\Admin\Management_Access;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Staging copies of a licensed site keep Pro without using a licence site.
 *
 * On a site Staging_Detector calls staging:
 *
 * - FS__RESOLVE_CLONE_AS is defined as temporary_duplicate before the SDK
 *   initialises. FS_Clone_Manager::_init (on init) then resolves the clone
 *   that way on every wp-admin page load, which re-stamps the temporary
 *   duplicate selection, so the two-week window never runs out while the
 *   site is in use. A clone also skips the install_sync cron, so no licence
 *   activation is consumed. A value the site owner set in wp-config wins.
 *   The constant is SDK-wide: every Freemius product on the copy resolves the
 *   same way, which is the least intrusive of the SDK's three choices.
 * - The SDK's one-time "temporary duplicate, activate a license here" notice
 *   is removed, because following it would spend one of the customer's
 *   sites on a staging copy.
 * - wpmcp's own screens carry a short banner, but only for a state Freemius
 *   confirms. Anything else shows nothing rather than a claim that could be
 *   false.
 *
 * Lives under src/Freemius so the WordPress.org and WooCommerce cuts, which
 * remove the SDK, remove this with it.
 */
class Staging_Copy
{
    public const CLONE_CONSTANT = 'FS__RESOLVE_CLONE_AS';
    public const CLONE_MODE     = 'temporary_duplicate';

    public const BANNER_CLONE = 'clone';
    public const BANNER_FRESH = 'fresh';

    /** Test seam: forced staging answer, or null for the real addresses. */
    private static ?bool $staging_override = null;

    /**
     * Test seam: forced clone manager (object), forced-absent (false), or
     * null for FS_Clone_Manager::instance().
     */
    private static object|false|null $clone_manager_override = null;

    public static function set_staging_for_tests(?bool $staging): void
    {
        if (! defined('WPMCP_TESTING') || ! WPMCP_TESTING) {
            return;
        }
        self::$staging_override = $staging;
    }

    public static function set_clone_manager_for_tests(object|false|null $manager): void
    {
        if (! defined('WPMCP_TESTING') || ! WPMCP_TESTING) {
            return;
        }
        self::$clone_manager_override = $manager;
    }

    /**
     * Called by Bootstrap::init() before the SDK instance is created.
     */
    public static function boot(): void
    {
        self::define_clone_resolution(self::is_staging_site());
        self::register();
    }

    /** Hooks for a staging copy; adds nothing on any other site. */
    public static function register(): void
    {
        if (! self::is_staging_site()) {
            return;
        }

        if (is_admin()) {
            // After FS_Clone_Manager::_init (init, 10) has stored its sticky
            // notice, before admin_notices renders it: no flash.
            add_action('init', [self::class, 'suppress_sdk_notice'], 11);
        }
        add_action('admin_notices', [self::class, 'render_banner']);
    }

    /**
     * The two WordPress address settings, read the way the SDK compares them:
     * the WP_HOME / WP_SITEURL constants when set, else the stored options
     * without their option_* filters (a domain-mapping or multilingual
     * plugin rewriting the address must not change the answer).
     */
    public static function is_staging_site(): bool
    {
        if (null !== self::$staging_override) {
            return self::$staging_override;
        }

        $options = wp_load_alloptions();
        $home    = defined('WP_HOME') ? (string) WP_HOME : (string) ($options['home'] ?? '');
        $siteurl = defined('WP_SITEURL') ? (string) WP_SITEURL : (string) ($options['siteurl'] ?? '');

        return Staging_Detector::is_staging($home, $siteurl);
    }

    /**
     * Defines the SDK's clone-resolution constant on a staging copy, unless
     * something (normally wp-config.php) already defined it. Returns whether
     * this call defined it. $constant is a test seam: constants cannot be
     * undefined between tests.
     */
    public static function define_clone_resolution(bool $is_staging, string $constant = self::CLONE_CONSTANT): bool
    {
        if (! $is_staging || defined($constant)) {
            return false;
        }

        return define($constant, self::CLONE_MODE);
    }

    /**
     * init (priority 11) callback: drops the SDK's temporary duplicate notice
     * on a staging copy. Writes only when the notice is actually stored.
     */
    public static function suppress_sdk_notice(): bool
    {
        if (! self::is_staging_site()) {
            return false;
        }

        $manager = self::clone_manager();
        if (null === $manager || ! is_callable([$manager, 'remove_temporary_duplicate_notice'])) {
            return false;
        }
        if (
            is_callable([$manager, 'is_temporary_duplicate_notice_shown'])
            && true !== $manager->is_temporary_duplicate_notice_shown()
        ) {
            return false;
        }

        $manager->remove_temporary_duplicate_notice();

        return true;
    }

    /**
     * Which banner, if any, is true for this site:
     *
     * - BANNER_CLONE: a registered clone that Freemius holds in an unexpired
     *   temporary duplicate state, running Pro off the live site's licence.
     *   That state is the one in which no activation is used.
     * - BANNER_FRESH: not a clone and no licence attached here, so the
     *   advice to copy from the live site instead of activating applies.
     * - null: every other case, including an unknown SDK shape.
     */
    public static function banner_state(bool $is_staging, ?object $fs, ?object $clone_manager): ?string
    {
        if (! $is_staging || null === $fs) {
            return null;
        }
        foreach (['is_registered', 'is_clone', 'can_use_premium_code', '_get_license'] as $method) {
            if (! is_callable([$fs, $method])) {
                return null;
            }
        }

        if (true === $fs->is_clone()) {
            if (
                null === $clone_manager
                || ! is_callable([$clone_manager, 'was_temporary_duplicate_mode_selected'])
                || ! is_callable([$clone_manager, 'has_temporary_duplicate_mode_expired'])
            ) {
                return null;
            }

            $confirmed = true === $fs->is_registered()
                && true === $fs->can_use_premium_code()
                && true === $clone_manager->was_temporary_duplicate_mode_selected()
                && false === $clone_manager->has_temporary_duplicate_mode_expired();

            return $confirmed ? self::BANNER_CLONE : null;
        }

        if (true !== $fs->can_use_premium_code() && null === $fs->_get_license()) {
            return self::BANNER_FRESH;
        }

        return null;
    }

    /** admin_notices callback: wpmcp screens and managers only. */
    public static function render_banner(): void
    {
        if (! self::is_wpmcp_screen() || ! current_user_can(Management_Access::CAPABILITY)) {
            return;
        }

        $state = self::banner_state(self::is_staging_site(), Bootstrap::fs(), self::clone_manager());
        if (self::BANNER_CLONE === $state) {
            $message = __('Staging copy. This site does not count toward your WP MCP license.', 'wpmcp');
        } elseif (self::BANNER_FRESH === $state) {
            $message = __('This looks like a staging site. Copy it from your licensed live site instead of activating a license here, so it does not use one of your sites.', 'wpmcp');
        } else {
            return;
        }

        printf('<div class="notice notice-info"><p>%s</p></div>', esc_html($message));
    }

    private static function clone_manager(): ?object
    {
        if (null !== self::$clone_manager_override) {
            return false === self::$clone_manager_override ? null : self::$clone_manager_override;
        }

        if (! class_exists('FS_Clone_Manager') || ! method_exists('FS_Clone_Manager', 'instance')) {
            return null;
        }

        return \FS_Clone_Manager::instance();
    }

    /**
     * wpmcp's top level and its submenus. A submenu's screen id starts with
     * the parent's sanitized menu title, which carries the pending-proposals
     * badge ("wpmcp-3_page_"), so the real prefix is read back from core's
     * $admin_page_hooks the way Admin\Pro_Banner does, with the plain prefix
     * as the fallback.
     */
    private static function is_wpmcp_screen(): bool
    {
        if (! function_exists('get_current_screen')) {
            return false;
        }
        $screen = get_current_screen();
        if (null === $screen) {
            return false;
        }
        if ('toplevel_page_wpmcp' === $screen->id || 0 === strpos($screen->id, 'wpmcp_page_')) {
            return true;
        }

        $hook = $GLOBALS['admin_page_hooks']['wpmcp'] ?? '';

        return is_string($hook) && '' !== $hook && 0 === strpos($screen->id, $hook . '_page_');
    }
}
