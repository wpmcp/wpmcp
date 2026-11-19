<?php

namespace WPMCP\Governance;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Admin toggles for the operations that are off by default behind an
 * opt-in filter (issue #823 part 4), so a site owner without code access
 * can switch a group on.
 *
 * Each group is one opt-in filter (wpmcp_enable_memberpress_write,
 * wpmcp_woo_op_enabled, ...). The toggle works by hooking that same filter
 * at the earliest priority: while the toggle is on and nothing else hooks
 * the filter, it answers true. The rules:
 *
 *  - Every toggle defaults off, and nothing but the admin screen writes it.
 *  - Code wins. When any other callback hooks the filter (an mu-plugin, a
 *    theme, a test), the toggle stands down entirely and the screen shows
 *    the group as set in code, so the stored state never leaks into a site
 *    that decides in code.
 *  - Stored per site (OPTION) and, on multisite, network-wide
 *    (NETWORK_OPTION, set by a network admin). Either one switches the group
 *    on for a site.
 *  - Both options are locked in Option_Guard, so update-option, get-option
 *    and settings sync cannot touch them: an agent cannot enable its own
 *    permissions.
 *  - The code-only gates Opt_In_Gates lists (exec, database and file writes,
 *    migrations, package changes) are not groups here and never will be.
 *
 * Owners are named by file path, never by class: the directory builds drop
 * some of these files, and a group shows only when its owner ships in the
 * build. tests/free/Governance/OptInSettingsTest pins this list to every
 * opt_in_filter declared under src/.
 */
final class Opt_In_Settings
{
    /** Per-site toggles: filter name => 1. */
    public const OPTION = 'wpmcp_opt_in_groups';

    /** Network-wide toggles on multisite (sitemeta): filter name => 1. */
    public const NETWORK_OPTION = 'wpmcp_opt_in_groups_network';

    /** @var array<string, array{owner: string, destructive: bool}>|null */
    private static ?array $shipped = null;

    /**
     * filter => [owner file relative to the plugin root, destructive].
     * A destructive group includes an operation that deletes data or whose
     * effect undo cannot reverse (mail sent, a backup started).
     *
     * @return array<string, array{owner: string, destructive: bool}>
     */
    public static function all_groups(): array
    {
        $i = 'src/Integrations/';
        return [
            'wpmcp_woo_op_enabled'                 => [ 'owner' => 'src/Tools/WooCommerce/Catalog/Woo_Write.php', 'destructive' => true ],
            'wpmcp_enable_memberpress_write'       => [ 'owner' => $i . 'MemberPress_Pack.php', 'destructive' => false ],
            'wpmcp_enable_forms_write'             => [ 'owner' => $i . 'Forms_Integration.php', 'destructive' => false ],
            'wpmcp_enable_ws_form_write'           => [ 'owner' => $i . 'WS_Form_Pack.php', 'destructive' => true ],
            'wpmcp_enable_elementor_pro_write'     => [ 'owner' => $i . 'Elementor_Pro_Forms_Pack.php', 'destructive' => true ],
            'wpmcp_enable_theme_write'             => [ 'owner' => $i . 'Theme_Integration.php', 'destructive' => true ],
            'wpmcp_enable_seo_redirect_write'      => [ 'owner' => $i . 'Rank_Math_Redirects_Pack.php', 'destructive' => true ],
            'wpmcp_enable_acf_write'               => [ 'owner' => $i . 'ACF_Integration.php', 'destructive' => false ],
            'wpmcp_enable_metabox_write'           => [ 'owner' => $i . 'Meta_Box_Integration.php', 'destructive' => false ],
            'wpmcp_enable_jetengine_write'         => [ 'owner' => $i . 'JetEngine_Pack.php', 'destructive' => false ],
            'wpmcp_enable_pods_write'              => [ 'owner' => $i . 'Pods_Pack.php', 'destructive' => false ],
            'wpmcp_enable_core_site_write'         => [ 'owner' => $i . 'Core_Site_Pack.php', 'destructive' => true ],
            'wpmcp_enable_core_patterns_write'     => [ 'owner' => $i . 'Core_Patterns_Pack.php', 'destructive' => false ],
            'wpmcp_enable_core_privacy_write'      => [ 'owner' => $i . 'Core_Privacy_Pack.php', 'destructive' => true ],
            'wpmcp_enable_font_library_write'      => [ 'owner' => $i . 'Core_Fonts_Pack.php', 'destructive' => true ],
            'wpmcp_enable_cache_settings_write'    => [ 'owner' => $i . 'Cache_Settings_Pack.php', 'destructive' => false ],
            'wpmcp_enable_site_gate_write'         => [ 'owner' => $i . 'Site_Gate_Pack.php', 'destructive' => true ],
            'wpmcp_enable_action_scheduler_write'  => [ 'owner' => $i . 'Action_Scheduler_Pack.php', 'destructive' => true ],
            'wpmcp_enable_crm_send'                => [ 'owner' => $i . 'CRM_Adapter.php', 'destructive' => true ],
            'wpmcp_enable_helpdesk_write'          => [ 'owner' => $i . 'Helpdesk_Adapter.php', 'destructive' => true ],
            'wpmcp_enable_learndash_learner_write' => [ 'owner' => $i . 'LMS_LearnDash.php', 'destructive' => false ],
            'wpmcp_enable_smtp_write'              => [ 'owner' => $i . 'SMTP_Pack_Support.php', 'destructive' => true ],
            'wpmcp_enable_backup_plugin_run'       => [ 'owner' => $i . 'UpdraftPlus_Status.php', 'destructive' => true ],
            'wpmcp_enable_pretty_links_write'      => [ 'owner' => $i . 'Pretty_Links_Pack.php', 'destructive' => false ],
            'wpmcp_enable_thirstyaffiliates_write' => [ 'owner' => $i . 'Thirsty_Affiliates_Pack.php', 'destructive' => false ],
            'wpmcp_enable_site_reviews_write'      => [ 'owner' => $i . 'Site_Reviews_Pack.php', 'destructive' => false ],
            'wpmcp_enable_block_suite_settings_write' => [ 'owner' => $i . 'Block_Suite_Settings.php', 'destructive' => false ],
            'wpmcp_enable_otter_settings_write'    => [ 'owner' => $i . 'Block_Suite_Otter.php', 'destructive' => false ],
            'wpmcp_enable_restrict_content_write'  => [ 'owner' => $i . 'Restrict_Content_Pack.php', 'destructive' => false ],
            'wpmcp_enable_ultimate_member_write'   => [ 'owner' => $i . 'Ultimate_Member_Pack.php', 'destructive' => false ],
            'wpmcp_enable_edd_write'               => [ 'owner' => $i . 'EDD_Pack.php', 'destructive' => false ],
            'wpmcp_enable_translation_settings_write' => [ 'owner' => $i . 'Translation_Settings_Pack.php', 'destructive' => false ],
            'wpmcp_enable_surecart_write'          => [ 'owner' => $i . 'SureCart_Pack.php', 'destructive' => false ],
            'wpmcp_enable_string_translation_write' => [ 'owner' => $i . 'I18n_Strings.php', 'destructive' => false ],
            'wpmcp_enable_cartflows_write'         => [ 'owner' => $i . 'CartFlows_Pack.php', 'destructive' => false ],
            'wpmcp_enable_advanced_ads_write'      => [ 'owner' => $i . 'Advanced_Ads_Pack.php', 'destructive' => false ],
            'wpmcp_enable_image_optimizer_write'   => [ 'owner' => $i . 'Image_Optimizer_Pack.php', 'destructive' => true ],
            'wpmcp_enable_wpcode_write'            => [ 'owner' => $i . 'WPCode_Pack.php', 'destructive' => false ],
            'wpmcp_enable_loco_write'              => [ 'owner' => $i . 'Loco_Translate_Pack.php', 'destructive' => false ],
            'wpmcp_enable_give_write'              => [ 'owner' => $i . 'Give_Integration.php', 'destructive' => true ],
            'wpmcp_enable_search_write'            => [ 'owner' => $i . 'Search_Plugin_Support.php', 'destructive' => false ],
            'wpmcp_enable_hardening_write'         => [ 'owner' => $i . 'Hardening_Pack.php', 'destructive' => false ],
            'wpmcp_enable_checkout_field_editor_write' => [ 'owner' => $i . 'Checkout_Field_Editor_Pack.php', 'destructive' => false ],
            'wpmcp_enable_pmpro_write'             => [ 'owner' => $i . 'Paid_Memberships_Pro_Pack.php', 'destructive' => true ],
        ];
    }

    /**
     * Screen labels, kept apart from all_groups() so the boot-time hook
     * registration never loads translations early.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            'wpmcp_woo_op_enabled'                 => __('WooCommerce: destructive operations (delete, cancel, refund)', 'wpmcp'),
            'wpmcp_enable_memberpress_write'       => __('MemberPress: writes', 'wpmcp'),
            'wpmcp_enable_forms_write'             => __('Form plugins: writes', 'wpmcp'),
            'wpmcp_enable_ws_form_write'           => __('WS Form: writes', 'wpmcp'),
            'wpmcp_enable_elementor_pro_write'     => __('Elementor Pro forms: writes', 'wpmcp'),
            'wpmcp_enable_theme_write'             => __('Themes: settings, layouts and child themes', 'wpmcp'),
            'wpmcp_enable_seo_redirect_write'      => __('Rank Math: redirect writes', 'wpmcp'),
            'wpmcp_enable_acf_write'               => __('ACF: field and field group writes', 'wpmcp'),
            'wpmcp_enable_metabox_write'           => __('Meta Box: writes', 'wpmcp'),
            'wpmcp_enable_jetengine_write'         => __('JetEngine: writes', 'wpmcp'),
            'wpmcp_enable_pods_write'              => __('Pods: writes', 'wpmcp'),
            'wpmcp_enable_core_site_write'         => __('Core site settings: writes', 'wpmcp'),
            'wpmcp_enable_core_patterns_write'     => __('Block patterns: writes', 'wpmcp'),
            'wpmcp_enable_font_library_write'      => __('Font Library: install and delete fonts', 'wpmcp'),
            'wpmcp_enable_cache_settings_write'    => __('Cache plugin settings: writes', 'wpmcp'),
            'wpmcp_enable_site_gate_write'         => __('Maintenance and coming soon mode', 'wpmcp'),
            'wpmcp_enable_action_scheduler_write'  => __('Action Scheduler: run and cancel actions', 'wpmcp'),
            'wpmcp_enable_crm_send'                => __('CRM: send email', 'wpmcp'),
            'wpmcp_enable_helpdesk_write'          => __('Helpdesk: ticket writes', 'wpmcp'),
            'wpmcp_enable_learndash_learner_write' => __('LearnDash: learner enrolment and progress', 'wpmcp'),
            'wpmcp_enable_smtp_write'              => __('SMTP plugins: send a test email', 'wpmcp'),
            'wpmcp_enable_backup_plugin_run'       => __('Backup plugins (UpdraftPlus, BackWPup): start a backup', 'wpmcp'),
            'wpmcp_enable_pretty_links_write'      => __('Pretty Links: writes', 'wpmcp'),
            'wpmcp_enable_thirstyaffiliates_write' => __('ThirstyAffiliates: writes', 'wpmcp'),
            'wpmcp_enable_site_reviews_write'      => __('Site Reviews: writes', 'wpmcp'),
            'wpmcp_enable_core_privacy_write'      => __('Privacy requests: export and erase personal data', 'wpmcp'),
            'wpmcp_enable_block_suite_settings_write' => __('Kadence Blocks and Spectra: plugin settings writes', 'wpmcp'),
            'wpmcp_enable_otter_settings_write'    => __('Otter Blocks: settings writes', 'wpmcp'),
            'wpmcp_enable_restrict_content_write'  => __('Restrict Content: membership level changes', 'wpmcp'),
            'wpmcp_enable_ultimate_member_write'   => __('Ultimate Member: member role changes', 'wpmcp'),
            'wpmcp_enable_edd_write'               => __('Easy Digital Downloads: download and discount writes', 'wpmcp'),
            'wpmcp_enable_translation_settings_write' => __('GTranslate: language settings writes', 'wpmcp'),
            'wpmcp_enable_surecart_write'          => __('SureCart: product edits and coupon creates', 'wpmcp'),
            'wpmcp_enable_string_translation_write' => __('Polylang: theme and plugin string translations', 'wpmcp'),
            'wpmcp_enable_cartflows_write'         => __('CartFlows: flow, step and checkout product writes', 'wpmcp'),
            'wpmcp_enable_advanced_ads_write'      => __('Advanced Ads: writes', 'wpmcp'),
            'wpmcp_enable_image_optimizer_write'   => __('Image optimization plugins: optimize an image', 'wpmcp'),
            'wpmcp_enable_wpcode_write'            => __('WPCode: snippet and global script writes', 'wpmcp'),
            'wpmcp_enable_loco_write'              => __('Loco Translate: .po string edits and .mo compiles', 'wpmcp'),
            'wpmcp_enable_give_write'              => __('GiveWP: donation form and donation status writes', 'wpmcp'),
            'wpmcp_enable_search_write'            => __('Search plugins (Relevanssi, FiboSearch): settings writes', 'wpmcp'),
            'wpmcp_enable_hardening_write'         => __('Security hardening settings: writes', 'wpmcp'),
            'wpmcp_enable_checkout_field_editor_write' => __('Checkout Field Editor: checkout field writes', 'wpmcp'),
            'wpmcp_enable_pmpro_write'             => __('Paid Memberships Pro: level, member and discount code writes', 'wpmcp'),
        ];
    }

    /**
     * The groups whose owner ships in this build.
     *
     * @return array<string, array{owner: string, destructive: bool}>
     */
    public static function groups(): array
    {
        if (null !== self::$shipped) {
            return self::$shipped;
        }
        $root = defined('WPMCP_DIR') ? (string) WPMCP_DIR : dirname(__DIR__, 2) . '/';
        return self::$shipped = array_filter(
            self::all_groups(),
            static fn (array $group): bool => is_file($root . $group['owner'])
        );
    }

    /** Hook every shipped group's filter. Idempotent. */
    public static function register(): void
    {
        foreach (array_keys(self::groups()) as $filter) {
            add_filter($filter, [ self::class, 'apply' ], PHP_INT_MIN, 1);
        }
    }

    /**
     * The filter callback: true while the current filter's toggle is on
     * and no code hooks it; otherwise the value unchanged.
     *
     * @param mixed $value
     * @return mixed
     */
    public static function apply($value)
    {
        $filter = (string) current_filter();
        if (self::set_in_code($filter) || ! self::is_on($filter)) {
            return $value;
        }
        return true;
    }

    /** Whether anything other than this class hooks $filter. */
    public static function set_in_code(string $filter): bool
    {
        global $wp_filter;
        $hook = $wp_filter[ $filter ] ?? null;
        if (! $hook instanceof \WP_Hook) {
            return false;
        }
        foreach ($hook->callbacks as $callbacks) {
            foreach ($callbacks as $callback) {
                $fn = $callback['function'] ?? null;
                if (! (is_array($fn) && self::class === ($fn[0] ?? null) && 'apply' === ($fn[1] ?? null))) {
                    return true;
                }
            }
        }
        return false;
    }

    /** Whether the stored toggle for $filter is on (site or network). */
    public static function is_on(string $filter): bool
    {
        if (! isset(self::groups()[ $filter ])) {
            return false;
        }
        return self::stored_on($filter, 'site') || (is_multisite() && self::stored_on($filter, 'network'));
    }

    /** Whether $filter is on in one scope's store ('site' or 'network'). */
    public static function stored_on(string $filter, string $scope): bool
    {
        $stored = 'network' === $scope ? get_site_option(self::NETWORK_OPTION, []) : get_option(self::OPTION, []);
        return is_array($stored) && ! empty($stored[ $filter ]);
    }

    /**
     * Store one toggle. The caller (the admin screen) checks capability,
     * nonce and "set in code" first; this only refuses an unknown group or a
     * network write off multisite.
     */
    public static function set(string $filter, bool $enabled, string $scope): bool
    {
        if (! isset(self::groups()[ $filter ]) || ('network' === $scope && ! is_multisite())) {
            return false;
        }

        $network = 'network' === $scope;
        $stored  = $network ? get_site_option(self::NETWORK_OPTION, []) : get_option(self::OPTION, []);
        $stored  = is_array($stored) ? array_intersect_key($stored, self::all_groups()) : [];
        if ($enabled) {
            $stored[ $filter ] = 1;
        } else {
            unset($stored[ $filter ]);
        }

        if ($network) {
            update_site_option(self::NETWORK_OPTION, $stored);
        } else {
            update_option(self::OPTION, $stored);
        }
        return true;
    }
}
