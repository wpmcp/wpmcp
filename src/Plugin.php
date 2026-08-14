<?php

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols -- ABSPATH guard is an intentional side effect.

namespace WPMCP;

use WPMCP\Admin\Audit_Log_Page;
use WPMCP\Admin\History_Page;
use WPMCP\Admin\Restore_Controller;
use WPMCP\Maintenance\Maintenance_Guard;
use WPMCP\Tools\Maintenance\Get_Maintenance_Status;
use WPMCP\Tools\Maintenance\Enable_Maintenance;
use WPMCP\Tools\Maintenance\Disable_Maintenance;
use WPMCP\Tools\Context\Get_Site_Context;
use WPMCP\Tools\Rest\List_Rest_Routes;
use WPMCP\Tools\Rest\Call_Rest;
use WPMCP\Tools\Blocks\List_Block_Types;
use WPMCP\Tools\Blocks\Get_Block_Type;
use WPMCP\Tools\Blocks\Parse_Blocks;
use WPMCP\Tools\Blocks\Serialize_Blocks;
use WPMCP\Tools\Blocks\Convert_Html_To_Blocks;
use WPMCP\Tools\Blocks\Add_Block;
use WPMCP\Tools\Blocks\Update_Block;
use WPMCP\Tools\Blocks\Remove_Block;
use WPMCP\Tools\Blocks\Move_Block;
use WPMCP\Tools\Blocks\Duplicate_Block;
use WPMCP\Tools\Blocks\List_Patterns;
use WPMCP\Tools\Blocks\Insert_Pattern;
use WPMCP\Tools\Structure\List_Shortcodes;
use WPMCP\Tools\Structure\Render_Shortcode;
use WPMCP\Tools\Structure\List_Sidebars;
use WPMCP\Tools\Structure\List_Sidebar_Widgets;
use WPMCP\Tools\Export\Export_Content;
use WPMCP\Tools\Export\List_Exports;
use WPMCP\Tools\Export\Import_Content;
use WPMCP\Tools\Analysis\Check_Contrast;
use WPMCP\Tools\Code\Validate_Php_Snippet;
use WPMCP\Tools\Code\Run_Php_Snippet;
use WPMCP\Tools\Cli\Run_Wp_Cli;
use WPMCP\Tools\Cli\Dispatch_Cli_Job;
use WPMCP\Tools\Cli\Get_Cli_Job;
use WPMCP\Tools\Cli\List_Cli_Jobs;
use WPMCP\Tools\Cli\Cancel_Cli_Job;
use WPMCP\Tools\Cli\Run_Cli_Job;
use WPMCP\Tools\Analysis\Extract_Content;
use WPMCP\Tools\Analysis\Analyze_Seo;
use WPMCP\Tools\Analysis\Analyze_Accessibility;
use WPMCP\Tools\Analysis\Fix_Color_Contrast;
use WPMCP\Tools\Analysis\Add_Alt_Text_From_Context;
use WPMCP\Tools\Analysis\Fix_Link_Text;
use WPMCP\Admin\Handshake_Settings_Page;
use WPMCP\Admin\Connection_Page;
use WPMCP\Admin\Announcements;
use WPMCP\Admin\Ability_Grid_Page;
use WPMCP\Admin\Memory_Page;
use WPMCP\Admin\Redirect_Suggestion_Controller;
use WPMCP\Admin\Redirects_Page;
use WPMCP\Admin\Skills_Settings_Page;
use WPMCP\Memory\Memory_Store;
use WPMCP\Skills\Skills_Module;
use WPMCP\Tools\Skills\Get_Skill;
use WPMCP\Tools\Skills\List_Skills;
use WPMCP\Governance\Default_Seeder;
use WPMCP\Connect\Exposure;
use WPMCP\MCP\Ability;
use WPMCP\MCP\Handshake_Instructions;
use WPMCP\MCP\Tool_Exposure;
use WPMCP\Tools\Dispatch\Call_Tool;
use WPMCP\Tools\Dispatch\Get_Tool_Schema;
use WPMCP\Tools\Dispatch\List_Tools;
use WPMCP\MCP\Registrar;
use WPMCP\Tools\Get_Page;
use WPMCP\Tools\Update_Blocks;
use WPMCP\Tools\List_Operations;
use WPMCP\Tools\Rollback_Operation;
use WPMCP\Tools\Rollback_Session;
use WPMCP\Tools\ACF\List_Field_Groups;
use WPMCP\Tools\ACF\Get_Fields;
use WPMCP\Tools\ACF\Update_Fields;
use WPMCP\Tools\Meta\Get_Post_Meta;
use WPMCP\Tools\Meta\Set_Post_Meta;
use WPMCP\Tools\Meta\Get_Option;
use WPMCP\Tools\Meta\Update_Option;
use WPMCP\Tools\SEO\Get_SEO_Status;
use WPMCP\Tools\SEO\Get_SEO_Meta;
use WPMCP\Tools\SEO\Update_SEO_Meta;
use WPMCP\Tools\SEO\SEO_Adapter;
use WPMCP\Tools\I18n\I18n_Adapter;
use WPMCP\Tools\I18n\List_Languages;
use WPMCP\Tools\I18n\Get_Post_Translations;
use WPMCP\Tools\I18n\Set_Post_Language;
use WPMCP\Tools\I18n\Link_Post_Translations;
use WPMCP\Tools\Linking\Find_Orphan_Posts;
use WPMCP\Tools\Linking\Suggest_Internal_Links;
use WPMCP\Tools\Linking\Get_Link_Map;
use WPMCP\Tools\Redirects\Create_Redirect;
use WPMCP\Tools\Redirects\Delete_Redirect;
use WPMCP\Tools\Redirects\Find_Broken_Links;
use WPMCP\Tools\Redirects\List_Redirects;
use WPMCP\Tools\Redirects\Redirect_Handler;
use WPMCP\Tools\Redirects\Redirect_Store;
use WPMCP\Tools\Redirects\Run_Broken_Link_Scan;
use WPMCP\Tools\Redirects\Update_Redirect;
use WPMCP\Tools\Connect\Get_Connection_Info;
use WPMCP\Tools\Connect\List_Tool_Catalog;
use WPMCP\Tools\Content\List_Post_Types;
use WPMCP\Tools\Content\List_Taxonomies;
use WPMCP\Tools\Content\Create_Post;
use WPMCP\Tools\Content\Get_Post;
use WPMCP\Tools\Content\Update_Post;
use WPMCP\Tools\Content\Delete_Post;
use WPMCP\Tools\Content\List_Posts;
use WPMCP\Tools\Content\Set_Post_Terms;
use WPMCP\Tools\Content\Duplicate_Post;
use WPMCP\Tools\Content\Diff_Revisions;
use WPMCP\Tools\Content\Count_Content;
use WPMCP\Tools\Terms\List_Terms;
use WPMCP\Tools\Terms\Get_Term;
use WPMCP\Tools\Terms\Create_Term;
use WPMCP\Tools\Terms\Update_Term;
use WPMCP\Tools\Terms\Delete_Term;
use WPMCP\Tools\Terms\Set_Term_Meta;
use WPMCP\Tools\Revisions\List_Revisions;
use WPMCP\Tools\Revisions\Get_Revision;
use WPMCP\Tools\Revisions\Restore_Revision;
use WPMCP\Tools\Media\Get_Media;
use WPMCP\Tools\Media\Update_Media;
use WPMCP\Tools\Media\Delete_Media;
use WPMCP\Tools\Media\Sideload_Image;
use WPMCP\Tools\Media\List_Media;
use WPMCP\Tools\Media\Resize_Media;
use WPMCP\Tools\Media\Upload_Svg;
use WPMCP\Tools\Media\Stock\Set_Stock_Key;
use WPMCP\Tools\Media\Stock\Search_Stock_Images;
use WPMCP\Tools\Media\Stock\Import_Stock_Image;
use WPMCP\Tools\Media\Stock\Insert_Stock_Image;
use WPMCP\Tools\Settings\Get_Settings;
use WPMCP\Tools\Settings\Update_Settings;
use WPMCP\Tools\Search\Search_Content;
use WPMCP\Tools\Search\Reindex_Search;
use WPMCP\Tools\Search\Index_Hooks;
use WPMCP\Tools\Users\List_Users;
use WPMCP\Tools\Users\Get_User;
use WPMCP\Tools\Users\Create_User;
use WPMCP\Tools\Users\Update_User;
use WPMCP\Tools\Comments\List_Comments;
use WPMCP\Tools\Comments\Get_Comment;
use WPMCP\Tools\Comments\Moderate_Comment;
use WPMCP\Tools\Comments\Edit_Comment;
use WPMCP\Tools\Comments\Delete_Comment;
use WPMCP\Tools\Packages\List_Plugins;
use WPMCP\Tools\Packages\Activate_Plugin;
use WPMCP\Tools\Packages\Deactivate_Plugin;
use WPMCP\Tools\Packages\Install_Plugin;
use WPMCP\Tools\Packages\Update_Plugin;
use WPMCP\Tools\Packages\Delete_Plugin;
use WPMCP\Tools\Packages\List_Themes;
use WPMCP\Tools\Packages\Switch_Theme;
use WPMCP\Tools\Packages\Install_Theme;
use WPMCP\Tools\Packages\Update_Theme;
use WPMCP\Tools\Packages\Delete_Theme;
use WPMCP\Tools\Packages\Search_Plugins;
use WPMCP\Tools\Packages\Get_Plugin_Info;
use WPMCP\Tools\Database\List_Tables;
use WPMCP\Tools\Database\Describe_Table;
use WPMCP\Tools\Database\Query;
use WPMCP\Tools\Database\Insert_Row;
use WPMCP\Tools\Database\Update_Rows;
use WPMCP\Tools\Database\Delete_Rows;
use WPMCP\Tools\Filesystem\Read_File;
use WPMCP\Tools\Filesystem\List_Directory;
use WPMCP\Tools\Filesystem\Search_Files;
use WPMCP\Tools\Filesystem\Write_File;
use WPMCP\Tools\Filesystem\Edit_File;
use WPMCP\Tools\Filesystem\Delete_File;
use WPMCP\Tools\Performance\Analyze_Performance;
use WPMCP\Tools\Security\Scan_Security;
use WPMCP\Tools\Cache\Get_Cache_Status;
use WPMCP\Tools\Cache\Clear_Cache;
use WPMCP\Tools\Diagnostics\Get_Debug_Config;
use WPMCP\Tools\Diagnostics\Get_Debug_Log;
use WPMCP\Tools\Diagnostics\List_Transients;
use WPMCP\Tools\Diagnostics\Delete_Transient;
use WPMCP\Tools\Cron\List_Cron_Events;
use WPMCP\Tools\Cron\Schedule_Event;
use WPMCP\Tools\Cron\Unschedule_Event;
use WPMCP\Tools\Cron\Run_Event;
use WPMCP\Tools\Backup\Trigger_Backup;
use WPMCP\Tools\Backup\Get_Backup_Status;
use WPMCP\Tools\Backup\List_Backup_Jobs;
use WPMCP\Tools\Backup\Cancel_Backup_Job;
use WPMCP\Tools\Backup\Run_Backup_Job;
use WPMCP\Tools\Governance\Get_Governance_Settings;
use WPMCP\Tools\Governance\Update_Governance_Settings;
use WPMCP\Tools\Governance\List_Governance_Audit_Log;
use WPMCP\Tools\Multisite\Is_Multisite;
use WPMCP\Tools\Multisite\Get_Network_Info;
use WPMCP\Tools\Multisite\List_Network_Sites;
use WPMCP\Tools\Multisite\Get_Site_Details;
use WPMCP\Tools\Analytics\Get_Analytics_Connection_Status;
use WPMCP\Tools\Analytics\Get_Analytics_Summary;
use WPMCP\Tools\Analytics\Get_Top_Pages;
use WPMCP\Tools\Analytics\Get_Search_Console_Summary;
use WPMCP\Tools\Analytics\Get_Search_Console_Queries;
use WPMCP\Tools\Identity\Create_Identity;
use WPMCP\Tools\Identity\List_Identities;
use WPMCP\Tools\Identity\Delete_Identity;
use WPMCP\Tools\Elementor\List_Widgets;
use WPMCP\Tools\Elementor\Get_Widget_Schema;
use WPMCP\Tools\Elementor\Get_Elementor_Data;
use WPMCP\Tools\Elementor\Update_Element;
use WPMCP\Tools\Elementor\Update_Widget;
use WPMCP\Tools\Elementor\Add_Widget;
use WPMCP\Tools\Elementor\Remove_Element;
use WPMCP\Tools\Elementor\Move_Element;
use WPMCP\Tools\Elementor\Generate_Widget;
use WPMCP\Tools\Elementor\Get_Global_Settings;
use WPMCP\Tools\Elementor\Update_Global_Colors;
use WPMCP\Tools\Elementor\Update_Global_Typography;
use WPMCP\Tools\Elementor\List_Global_Classes;
use WPMCP\Tools\Elementor\Create_Global_Class;
use WPMCP\Tools\Elementor\Update_Global_Class;
use WPMCP\Tools\Elementor\Delete_Global_Class;
use WPMCP\Tools\Elementor\Reorder_Global_Classes;
use WPMCP\Tools\Elementor\Global_Class_Schema;
use WPMCP\Tools\Brand\List_Brand_Kits;
use WPMCP\Tools\Brand\Get_Brand_Kit;
use WPMCP\Tools\Brand\Apply_Brand_Kit;
use WPMCP\Tools\Brand\Rollback_Brand_Kit;
use WPMCP\Tools\Elementor\Export_Page;
use WPMCP\Tools\Elementor\Save_As_Template;
use WPMCP\Tools\Elementor\Apply_Template;
use WPMCP\Tools\Elementor\Import_Template;
use WPMCP\Tools\Elementor\Create_Theme_Template;
use WPMCP\Tools\Elementor\Set_Template_Conditions;
use WPMCP\Tools\Elementor\Get_Theme_Template;
use WPMCP\Tools\Elementor\List_Theme_Templates;
use WPMCP\Tools\Elementor\Delete_Theme_Template;
use WPMCP\Tools\Elementor\Detect_Elementor_Version;
use WPMCP\Tools\Elementor\Add_Flexbox;
use WPMCP\Tools\Elementor\Add_Div_Block;
use WPMCP\Tools\Elementor\Add_Atomic_Widget;
use WPMCP\Tools\Elementor\Update_Atomic_Widget;
use WPMCP\Tools\Elementor\Create_Popup;
use WPMCP\Tools\Elementor\Set_Popup_Settings;
use WPMCP\Tools\Elementor\List_Dynamic_Tags;
use WPMCP\Tools\Elementor\Set_Dynamic_Tag;
use WPMCP\Tools\Elementor\Add_Custom_Css;
use WPMCP\Tools\Elementor\Get_Custom_Css;
use WPMCP\Tools\Elementor\Create_Code_Snippet;
use WPMCP\Tools\Elementor\List_Code_Snippets;
use WPMCP\Tools\Elementor\Delete_Code_Snippet;
use WPMCP\Tools\Elementor\Add_Container;
use WPMCP\Tools\Elementor\Update_Container;
use WPMCP\Tools\Elementor\Batch_Update;
use WPMCP\Tools\Elementor\Reorder_Elements;
use WPMCP\Tools\Elementor\Duplicate_Element;
use WPMCP\Tools\Elementor\Set_Element_Label;
use WPMCP\Tools\Elementor\Find_Element;
use WPMCP\Tools\Elementor\Update_Page_Settings;
use WPMCP\Tools\Builders\Detect_Builder;
use WPMCP\Tools\Builders\Get_Builder_Content;
use WPMCP\Tools\Builders\Update_Builder_Content;
use WPMCP\Tools\WooCommerce\List_Products;
use WPMCP\Tools\WooCommerce\Get_Product;
use WPMCP\Tools\WooCommerce\Create_Product;
use WPMCP\Tools\WooCommerce\Update_Product;
use WPMCP\Tools\WooCommerce\Delete_Product;
use WPMCP\Tools\WooCommerce\List_Product_Categories;
use WPMCP\Tools\WooCommerce\List_Orders;
use WPMCP\Tools\WooCommerce\Get_Order;
use WPMCP\Tools\WooCommerce\Update_Order_Status;
use WPMCP\Tools\WooCommerce\Add_Order_Note;
use WPMCP\Tools\WooCommerce\Get_Sales_Report;
use WPMCP\Tools\Menus\List_Menus;
use WPMCP\Tools\Menus\Get_Menu;
use WPMCP\Tools\Menus\List_Menu_Locations;
use WPMCP\Tools\Menus\Create_Menu;
use WPMCP\Tools\Menus\Add_Menu_Item;
use WPMCP\Tools\Menus\Update_Menu_Item;
use WPMCP\Tools\Menus\Remove_Menu_Item;
use WPMCP\Tools\Menus\Assign_Menu_To_Location;
use WPMCP\Tools\Menus\Delete_Menu;
use WPMCP\Auth\Endpoints as OAuth_Endpoints;
use WPMCP\Auth\Bearer_Auth;
use WPMCP\Auth\OAuth_Config;
use WPMCP\Auth\Oauth_Gc;
use WPMCP\MCP\Structured_Result;
use WPMCP\MCP\Transport_Guard;

// Plugin Check's Direct_File_Access_Check only accepts the bare defined()
// test; an extra conjunct makes it report the file as unprotected. The test
// bootstrap defines ABSPATH itself, so the guard needs no test escape hatch.
if (! defined('ABSPATH')) {
    exit;
}

final class Plugin
{
    private static ?Plugin $instance = null;
    private ?Registrar $registrar = null;
    public static function instance(): Plugin
    {
        return self::$instance ??= new self();
    }
    private function __construct()
    {
    }

    /**
     * The shared Registrar instance every ability is registered into. Exposed
     * so admin screens (e.g. the audit log's tool_name filter) and tests can
     * enumerate the abilities that are actually registered, without each
     * caller building its own throwaway Registrar.
     */
    public function registrar(): Registrar
    {
        return $this->registrar ??= new Registrar();
    }

    /**
     * Test seam: forced build flavor. Guarded by WPMCP_TESTING.
     */
    private static ?string $flavor_override = null;

    /**
     * Ability groups per vertical build flavor. A flavor listed here
     * registers ONLY these groups (the inline core content/safety abilities
     * in register_abilities() are common to every flavor); any other flavor
     * value, including the default 'full', registers everything. The
     * corresponding build script (scripts/build-woo-release.sh) prunes the
     * excluded domains' files from that flavor's zip, so the gate and the
     * artifact must stay in sync.
     */
    private const FLAVOR_GROUPS = [
        'woocommerce' => [
            'compose', 'woocommerce', 'menu', 'seo', 'linking', 'redirects',
            'meta', 'diagnostics', 'cron', 'maintenance', 'context', 'block',
            'structure', 'taxonomy', 'export', 'backup', 'analysis', 'connect',
            'governance', 'skills',
        ],
    ];

    public static function set_flavor_for_tests(?string $flavor): void
    {
        if (! defined('WPMCP_TESTING') || ! WPMCP_TESTING) {
            return;
        }
        self::$flavor_override = $flavor;
    }

    /**
     * The build flavor: 'full' unless the main plugin file of a vertical
     * build (e.g. wpmcp-for-woocommerce.php) defines WPMCP_FLAVOR.
     */
    public static function flavor(): string
    {
        if (null !== self::$flavor_override) {
            return self::$flavor_override;
        }
        return defined('WPMCP_FLAVOR') ? WPMCP_FLAVOR : 'full';
    }

    private function group_enabled(string $group): bool
    {
        $allowed = self::FLAVOR_GROUPS[ self::flavor() ] ?? null;
        return null === $allowed || in_array($group, $allowed, true);
    }

    /**
     * Boot-time runtime hook wiring for the flavor-gated feature groups: the
     * data-driven widget/block builders and agent project memory.
     * Flavor-gated with the matching ability groups: vertical builds prune
     * these classes' files from the zip, so the hooks must not reference
     * them there. Public so tests can exercise the gating directly (boot()
     * itself runs during the suite bootstrap, outside coverage collection).
     */
    public function register_builder_runtime_hooks(): void
    {
        // Data-driven custom widget builder: register the wpmcp_widget CPT
        // and register active specs as Elementor widgets at runtime (no eval).
        if ($this->group_enabled('widget_builder')) {
            add_action('init', ['\\WPMCP\\Tools\\WidgetBuilder\\Widget_Spec_Store', 'ensure_post_type']);
            add_action('elementor/widgets/register', ['\\WPMCP\\Tools\\WidgetBuilder\\Widget_Registry', 'register']);
        }
        // Data-driven custom Gutenberg block builder: register the wpmcp_block
        // CPT and register active specs as real blocks via register_block_type.
        if ($this->group_enabled('block_builder')) {
            add_action('init', ['\\WPMCP\\Tools\\BlockBuilder\\Block_Spec_Store', 'ensure_post_type'], 5);
            add_action('init', ['\\WPMCP\\Tools\\BlockBuilder\\Block_Registry', 'register'], 20);
        }
        // Content search index (issue #83): keep it correct incrementally on
        // every save/delete so search-content never reads stale copy. Gated
        // with its ability group so a flavor that drops the group also drops
        // the indexing cost.
        if ($this->group_enabled('search')) {
            (new Index_Hooks())->register();
        }
        // Agent project memory (issue #131): the wpmcp_memory CPT is the
        // store AND the approval queue, so it is registered here rather than
        // lazily from the tools. Note this runs even though the three memory
        // TOOLS are pro: an administrator's published guardrails are enforced
        // in Registrar::is_permitted() on every tier, and a safety rule must
        // not stop applying because a license lapsed.
        if ($this->group_enabled('memory')) {
            add_action('init', [Memory_Store::class, 'ensure_post_type'], 5);
            (new Memory_Page())->register_hooks();
            // The enforced rule set is memoized per request. An administrator
            // publishing or trashing an entry in wp-admin does not go through
            // Memory_Store, so drop the memo on the post-status transition and
            // on deletion instead of trusting every write to route through us.
            add_action('transition_post_status', [Memory_Store::class, 'flush_rules_cache_on_transition'], 10, 3);
            add_action('deleted_post', [Memory_Store::class, 'flush_rules_cache_on_delete'], 10, 2);
        }
    }
    public function boot(): void
    {
        if (function_exists('register_activation_hook') && defined('WPMCP_FILE')) {
            register_activation_hook(WPMCP_FILE, [Activator::class, 'activate']);
        }
        if (function_exists('add_action')) {
            // Versioned default-disabled seeding (issue #78) runs BEFORE any
            // registration hook can fire: newly shipped default-off abilities
            // land as ordinary governance toggles, so enforcement lives in
            // the registration/permission path with no admin class loaded.
            Default_Seeder::seed();
            $hook = function_exists('wp_register_ability') ? 'wp_abilities_api_init' : 'init';
            add_action($hook, [$this, 'register_abilities']);
            if (function_exists('wp_register_ability_category')) {
                add_action('wp_abilities_api_categories_init', [$this, 'register_ability_category']);
            }
            $this->register_builder_runtime_hooks();
            add_action('admin_menu', [$this, 'register_admin_menu']);
            add_action('wp_ajax_wpmcp_restore', [new Restore_Controller(), 'handle']);
            // The WP-Cron executor for trigger-backup's scheduled events: runs
            // the queued job (producing a backup artifact) and flips its
            // status to completed/failed. See Run_Backup_Job's docblock.
            add_action(Run_Backup_Job::HOOK, [new Run_Backup_Job(), 'handle']);
            // The WP-Cron executor for dispatch-cli-job's scheduled events
            // (issue #84). It re-runs the FULL wp-cli guard chain before
            // executing anything, so hooking it here does not by itself let
            // any queued command run: a job queued while the opt-in gate was
            // open still fails closed once that gate is shut. See
            // Run_Cli_Job's docblock.
            add_action(Run_Cli_Job::HOOK, [new Run_Cli_Job(), 'handle']);
            // Front-end maintenance-mode enforcement. template_redirect runs after
            // WordPress has resolved the query but before a template is loaded, and
            // does not fire for wp-admin or REST requests, so authenticated capable
            // users, wp-admin, and the REST/MCP endpoints are never affected by it.
            add_action('template_redirect', [new Maintenance_Guard(), 'enforce']);
            // Managed redirects (issue #128), at priority 1 so an explicitly
            // configured redirect wins over core's redirect_canonical guess
            // (priority 10) for a path whose post was renamed or removed. When
            // no managed redirect matches, this does nothing and canonical
            // behavior is untouched. See Redirect_Handler's docblock.
            add_action('template_redirect', [new Redirect_Handler(), 'maybe_redirect'], Redirect_Handler::PRIORITY);
            // The WP-Cron executor for a background broken-link scan: one
            // batch per run, rescheduling itself until the scan completes.
            add_action(Run_Broken_Link_Scan::HOOK, [new Run_Broken_Link_Scan(), 'handle']);
            // Confirm/dismiss for pending redirect suggestions. Confirming
            // calls the create-redirect tool, so there is no second path that
            // can produce a redirect. See Redirect_Suggestion_Controller.
            add_action(
                'wp_ajax_' . Redirect_Suggestion_Controller::ACTION,
                [new Redirect_Suggestion_Controller(), 'handle']
            );
            // Self-healing schema creation for sites that upgraded into the
            // redirect feature without re-activating the plugin. Guarded by a
            // db-version option and hooked in wp-admin only, so no front-end
            // request ever risks DDL.
            add_action('admin_init', [Redirect_Store::class, 'maybe_install']);
            // OAuth 2.1 + Dynamic Client Registration REST routes (issue #43).
            // Endpoints::register() itself no-ops unless OAuth_Config::is_enabled()
            // (default false), so this hook registration is always safe to add.
            add_action('rest_api_init', [new OAuth_Endpoints(), 'register']);
            // Resolves a valid OAuth Bearer token to its bound WP user via
            // determine_current_user, so Registrar's existing capability
            // checks work for OAuth callers with no change to Registrar
            // itself. Also a no-op unless OAuth_Config::is_enabled().
            (new Bearer_Auth())->register();
            // Handshake context injection (issue #80): swap the MCP
            // Adapter's initialize `instructions` for the admin-authored
            // text plus the permission-gated site summary. A no-op unless
            // the adapter (which owns this filter) is installed and fires it.
            add_filter(
                'mcp_adapter_initialize_response',
                [new Handshake_Instructions(), 'filter_initialize'],
                10,
                2
            );
            // The Settings API registration for the handshake instructions
            // option (sanitize + clamp on every save through options.php).
            add_action('admin_init', [Handshake_Settings_Page::class, 'register_setting']);
            // Settings API registration for the agent-skills module toggle
            // (issue #74). Off unregisters list-skills/get-skill entirely.
            add_action('admin_init', [Skills_Settings_Page::class, 'register_setting']);
            // Master MCP exposure switch (issue #76): narrows through the
            // existing wpmcp_ability_enabled governance filter (off = every
            // ability denies on the next request) and surfaces its state in
            // the admin bar for manage_options users.
            Exposure::register();
            // Secret-free Claude Desktop bundle download from the Connection
            // screen (nonce + manage_options enforced inside the handler).
            add_action('admin_post_wpmcp_download_bundle', [new Connection_Page(), 'download_bundle']);
            // Cloud announcements feed (issue #138): dismissible dated
            // notices on wpmcp screens only, never site-wide. 24h transient
            // cache, per-user dismissal, silent on any cloud failure.
            Announcements::register();
            // Compact tool-surface mode (issue #79): in compact mode the
            // adapter's advertised tools/list collapses to the meta-tools
            // plus connection basics. Exposure-only — registration and
            // permissions are untouched — and a no-op unless the adapter
            // (which owns this filter) is installed, and while the mode
            // resolves to 'full' (the default).
            add_filter('mcp_adapter_tools_list', [new Tool_Exposure(), 'filter_tools_list'], 10, 2);
            // Transport hardening (issue #133): no-store cache headers on
            // every MCP + OAuth response, display_errors forced off so a
            // stray notice cannot corrupt JSON-RPC framing, and a 421
            // site-URL-mismatch guard for connectors left pointing at an
            // old domain. Scoped to our own routes; everything else on the
            // site is untouched.
            (new Transport_Guard())->register();
            // structuredContent must serialize as a JSON object. Normalized
            // at the wire boundary only, so tool contracts are unchanged.
            (new Structured_Result())->register();
            // Scheduled OAuth garbage collection (issue #133). The cron
            // callback is always attached (so a previously scheduled event
            // still has a handler if OAuth is switched off); the event is
            // only kept on the schedule while OAuth is enabled.
            Oauth_Gc::register();
            add_action('init', static function (): void {
                if (OAuth_Config::is_enabled()) {
                    Oauth_Gc::ensure_scheduled();
                    return;
                }
                Oauth_Gc::unschedule();
            }, 20);
        }
    }

    /**
     * The Abilities API (WP 6.9+) requires every ability to belong to a
     * registered category before wp_register_ability() will accept it.
     * Categories must be registered on their own wp_abilities_api_categories_init
     * hook, separate from wp_abilities_api_init.
     */
    public function register_ability_category(): void
    {
        wp_register_ability_category('wpmcp', [
            'label'       => 'wpmcp',
            'description' => 'Abilities provided by the wpmcp plugin.',
        ]);
    }

    public function register_admin_menu(): void
    {
        // Pending agent memory proposals (issue #131) are surfaced as the
        // WordPress count bubble on the top-level menu, so a proposal waiting
        // for a human decision is visible from any wpmcp screen (and from
        // anywhere in wp-admin), not only from the memory list table. Vertical
        // builds that drop the memory group also drop the class, so nothing
        // here may touch it when the group is off.
        $memory     = $this->group_enabled('memory');
        $pending    = $memory ? Memory_Page::pending_count() : 0;
        $menu_title = $memory ? Memory_Page::badged('wpmcp', $pending) : 'wpmcp';

        // The history page views (and its Restore button rolls back) ALL
        // users' site-wide agent mutations, so it is gated at manage_options,
        // matching Restore_Controller::handle()'s ajax capability check.
        add_menu_page(
            __('wpmcp', 'wpmcp'),
            $menu_title,
            'manage_options',
            'wpmcp',
            [new History_Page(), 'render']
        );

        // The history screen, added explicitly rather than left to the
        // duplicate WordPress auto-creates for the first submenu: that copy
        // clones the top-level MENU TITLE verbatim, which now carries the
        // pending-proposal bubble, and the bubble must appear once. Passing
        // the parent's own slug suppresses the auto-copy (see add_submenu_page)
        // and keeps this entry's label exactly what it has always rendered as.
        add_submenu_page(
            'wpmcp',
            __('wpmcp', 'wpmcp'),
            __('wpmcp', 'wpmcp'),
            'manage_options',
            'wpmcp',
            [new History_Page(), 'render']
        );

        // Same manage_options gate as the top-level page and Restore_Controller's
        // ajax handler: this screen shows and can roll back every user's
        // site-wide agent mutations, so it needs the same trust level.
        add_submenu_page(
            'wpmcp',
            __('wpmcp: Audit Log', 'wpmcp'),
            __('Audit Log', 'wpmcp'),
            'manage_options',
            Audit_Log_Page::SLUG,
            [new Audit_Log_Page(), 'render']
        );

        // Handshake instructions (issue #80): the text on this screen is
        // broadcast to every connecting MCP client at initialize, so editing
        // it is a site-wide trust decision — manage_options, like the rest.
        add_submenu_page(
            'wpmcp',
            __('wpmcp: Handshake Instructions', 'wpmcp'),
            __('Handshake', 'wpmcp'),
            'manage_options',
            'wpmcp-handshake',
            [new Handshake_Settings_Page(), 'render']
        );

        // Connection manager (issue #76): provisions Application Passwords,
        // reveals them exactly once alongside filled client configs, serves
        // the desktop bundle, and hosts the master exposure switch — all
        // site-wide trust decisions, so manage_options like the rest.
        add_submenu_page(
            'wpmcp',
            __('wpmcp: Connection', 'wpmcp'),
            __('Connection', 'wpmcp'),
            'manage_options',
            Connection_Page::SLUG,
            [new Connection_Page(), 'render']
        );

        // Ability toggle grid (issue #78): sees and narrows the full MCP
        // ability surface — a site-wide trust decision, so manage_options
        // like the rest.
        add_submenu_page(
            'wpmcp',
            __('wpmcp: Abilities', 'wpmcp'),
            __('Abilities', 'wpmcp'),
            'manage_options',
            Ability_Grid_Page::SLUG,
            [new Ability_Grid_Page(), 'render']
        );

        // Redirects (issue #128): shows the managed redirect table and the
        // pending suggestion queue, and is where a human confirms a
        // suggestion into a real redirect. Changing site-wide routing is a
        // manage_options decision, like the rest.
        add_submenu_page(
            'wpmcp',
            'wpmcp: Redirects',
            'Redirects',
            'manage_options',
            Redirects_Page::SLUG,
            [new Redirects_Page(), 'render']
        );

        // Agent skills (issue #74): the toggle on this screen decides whether
        // list-skills/get-skill are registered at all, i.e. what every
        // connecting agent is told about how to work on this site.
        add_submenu_page(
            'wpmcp',
            'wpmcp: Agent Skills',
            'Skills',
            'manage_options',
            Skills_Settings_Page::SLUG,
            [new Skills_Settings_Page(), 'render']
        );

        // Agent memory (issue #131). The target is the wpmcp_memory CPT list
        // table, not a bespoke screen: approving a proposal is WordPress's own
        // pending -> publish flow, with its list table, nonces, capability
        // checks and revisions, so there is no second approval path to audit.
        // Publishing an entry can enforce a server-side denial or broadcast
        // text to every connecting agent, hence manage_options like the rest.
        if ($memory) {
            add_submenu_page(
                'wpmcp',
                'wpmcp: Agent Memory',
                Memory_Page::badged('Memory', $pending),
                'manage_options',
                Memory_Page::submenu_slug()
            );
        }
    }

    /**
     * The full declared ability surface (pre-gating; see
     * Registrar::declared()) for admin display. Lazily replays
     * register_abilities() when the registration hook has not fired in this
     * request — outside a wp_abilities_api_init window Registrar only fills
     * its internal maps, so the replay never touches the Abilities API.
     *
     * @return \WPMCP\MCP\Ability[]
     */
    public function declared_abilities(): array
    {
        if ([] === $this->registrar()->declared()) {
            $this->register_abilities();
        }
        return $this->registrar()->declared();
    }

    /**
     * Hook callback (wp_abilities_api_init / init). Takes no parameters on
     * purpose: WordPress forwards hook arguments to callbacks, so an
     * optional Registrar parameter here would receive whatever the action
     * passes. Tests use register_abilities_into() with a throwaway
     * Registrar instead.
     */
    public function register_abilities(): void
    {
        $this->register_abilities_into($this->registrar());
    }

    public function register_abilities_into(Registrar $registrar): void
    {
        $get_page           = new Get_Page();
        $update_blocks      = new Update_Blocks();
        $list_operations    = new List_Operations();
        $rollback_operation = new Rollback_Operation();
        $rollback_session   = new Rollback_Session();
        $registrar->register(new Ability(
            'wpmcp/get-page',
            'free',
            'Read a page',
            [
                'type'       => 'object',
                'properties' => [
                    'id' => [ 'type' => 'integer' ],
                ],
                'required'   => [ 'id' ],
            ],
            [$get_page, 'handle'],
            'edit_posts',
            'core',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/update-blocks',
            'free',
            'Update a page\'s block content',
            [
                'type'       => 'object',
                'properties' => [
                    'id'         => [ 'type' => 'integer' ],
                    'blocks'     => [ 'type' => 'string' ],
                    'session_id' => [ 'type' => 'string' ],
                ],
                'required'   => [ 'id', 'blocks' ],
            ],
            [$update_blocks, 'handle'],
            'edit_posts',
            'core',
            'update'
        ));
        $registrar->register(new Ability(
            'wpmcp/list-operations',
            'free',
            'List recent safety snapshot operations',
            [
                'type'       => 'object',
                'properties' => [
                    'limit' => [ 'type' => 'integer' ],
                ],
            ],
            [$list_operations, 'handle'],
            'edit_posts',
            'core',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/rollback-operation',
            'free',
            'Undo a single operation by restoring its pre-change snapshot',
            [
                'type'       => 'object',
                'properties' => [
                    'operation_id' => [ 'type' => 'string' ],
                ],
                'required'   => [ 'operation_id' ],
            ],
            [$rollback_operation, 'handle'],
            'edit_posts',
            'core',
            'update'
        ));
        $registrar->register(new Ability(
            'wpmcp/rollback-session',
            'free',
            'Undo all operations from a session by restoring each object\'s pre-session snapshot',
            [
                'type'       => 'object',
                'properties' => [
                    'session_id' => [ 'type' => 'string' ],
                ],
                'required'   => [ 'session_id' ],
            ],
            [$rollback_session, 'handle'],
            'edit_posts',
            'core',
            'update'
        ));

        $list_post_types = new List_Post_Types();
        $list_taxonomies = new List_Taxonomies();
        $create_post     = new Create_Post();
        $get_post        = new Get_Post();
        $update_post     = new Update_Post();
        $delete_post     = new Delete_Post();
        $list_posts      = new List_Posts();
        $set_post_terms  = new Set_Post_Terms();
        $duplicate_post  = new Duplicate_Post();
        $diff_revisions  = new Diff_Revisions();
        $count_content   = new Count_Content();

        $registrar->register(new Ability(
            'wpmcp/list-post-types',
            'free',
            'List registered post types (posts, pages, custom post types)',
            [
                'type'       => 'object',
                'properties' => [
                    'public_only' => [ 'type' => 'boolean' ],
                ],
            ],
            [$list_post_types, 'handle'],
            'edit_posts',
            'content',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/list-taxonomies',
            'free',
            'List registered taxonomies (categories, tags, custom taxonomies)',
            [
                'type'       => 'object',
                'properties' => [
                    'post_type' => [ 'type' => 'string' ],
                ],
            ],
            [$list_taxonomies, 'handle'],
            'edit_posts',
            'content',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/create-post',
            'free',
            'Create a post, page, or custom post type',
            [
                'type'       => 'object',
                'properties' => [
                    'post_type' => [ 'type' => 'string' ],
                    'title'     => [ 'type' => 'string' ],
                    'content'   => [ 'type' => 'string' ],
                    'excerpt'   => [ 'type' => 'string' ],
                    'status'    => [ 'type' => 'string' ],
                    'slug'      => [ 'type' => 'string' ],
                    'parent'    => [ 'type' => 'integer' ],
                    'terms'     => [ 'type' => 'object' ],
                    'meta'      => [ 'type' => 'object' ],
                ],
            ],
            [$create_post, 'handle'],
            'edit_posts',
            'content',
            'create'
        ));
        $registrar->register(new Ability(
            'wpmcp/get-post',
            'free',
            'Read a single post, page, or custom post type',
            [
                'type'       => 'object',
                'properties' => [
                    'post_id' => [ 'type' => 'integer' ],
                ],
                'required'   => [ 'post_id' ],
            ],
            [$get_post, 'handle'],
            'edit_posts',
            'content',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/update-post',
            'free',
            'Partially update a post, page, or custom post type',
            [
                'type'       => 'object',
                'properties' => [
                    'post_id'        => [ 'type' => 'integer' ],
                    'title'          => [ 'type' => 'string' ],
                    'content'        => [ 'type' => 'string' ],
                    'excerpt'        => [ 'type' => 'string' ],
                    'status'         => [ 'type' => 'string' ],
                    'slug'           => [ 'type' => 'string' ],
                    'parent'         => [ 'type' => 'integer' ],
                    'terms'          => [ 'type' => 'object' ],
                    'terms_mode'     => [ 'type' => 'string' ],
                    'meta'           => [ 'type' => 'object' ],
                    'featured_image' => [ 'type' => [ 'object', 'null' ] ],
                    'session_id'     => [ 'type' => 'string' ],
                ],
                'required'   => [ 'post_id' ],
            ],
            [$update_post, 'handle'],
            'edit_posts',
            'content',
            'update'
        ));
        $registrar->register(new Ability(
            'wpmcp/delete-post',
            'free',
            'Delete a post, page, or custom post type. Trash by default (reversible). force:true permanently deletes: that path is disabled by default (site must opt in via the wpmcp_enable_delete_post filter) and requires confirm:true. Force-delete is snapshotted so the record can be rolled back',
            [
                'type'       => 'object',
                'properties' => [
                    'post_id'    => [ 'type' => 'integer' ],
                    'force'      => [ 'type' => 'boolean' ],
                    'confirm'    => [ 'type' => 'boolean' ],
                    'session_id' => [ 'type' => 'string' ],
                ],
                'required'   => [ 'post_id' ],
            ],
            [$delete_post, 'handle'],
            'edit_posts',
            'content',
            'delete'
        ));
        $registrar->register(new Ability(
            'wpmcp/list-posts',
            'free',
            'List/search posts, pages, or custom post types',
            [
                'type'       => 'object',
                'properties' => [
                    'post_type' => [ 'type' => 'string' ],
                    'status'    => [ 'type' => 'string' ],
                    'search'    => [ 'type' => 'string' ],
                    'author'    => [ 'type' => 'integer' ],
                    'parent'    => [ 'type' => 'integer' ],
                    'per_page'  => [ 'type' => 'integer' ],
                    'page'      => [ 'type' => 'integer' ],
                    'orderby'   => [ 'type' => 'string' ],
                    'order'     => [ 'type' => 'string' ],
                ],
            ],
            [$list_posts, 'handle'],
            'edit_posts',
            'content',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/duplicate-post',
            'free',
            'Duplicate a post, page or CPT entry with its content, meta and terms, optionally including child posts. The copy is a draft unless another status is given, so a half-finished clone never lands live. Editor bookkeeping meta (_edit_lock, _wp_old_slug) is skipped; page-builder data is copied',
            [
                'type'       => 'object',
                'properties' => [
                    'post_id'          => [ 'type' => 'integer' ],
                    'title'            => [ 'type' => 'string' ],
                    'status'           => [ 'type' => 'string', 'enum' => ['draft', 'pending', 'private', 'publish'] ],
                    'include_children' => [ 'type' => 'boolean' ],
                ],
                'required'   => [ 'post_id' ],
            ],
            [$duplicate_post, 'handle'],
            'edit_posts',
            'content',
            'create'
        ));
        $registrar->register(new Ability(
            'wpmcp/diff-revisions',
            'free',
            'Diff two revisions of a post, or one revision against the post\'s current state: a unified diff per changed field (title, content, excerpt) rather than two full documents. Unchanged fields are omitted. Read-only',
            [
                'type'       => 'object',
                'properties' => [
                    'from_revision_id' => [ 'type' => 'integer' ],
                    'to_revision_id'   => [ 'type' => 'integer' ],
                ],
                'required'   => [ 'from_revision_id' ],
            ],
            [$diff_revisions, 'handle'],
            'edit_posts',
            'content',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/count-content',
            'free',
            'Counts so a job can be sized before it starts: posts per public type by status, media by MIME family, comments by status, terms per taxonomy, users per role. From core counting APIs, so figures match wp-admin. Read-only',
            [
                'type'       => 'object',
                'properties' => [
                    'include'   => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                    'post_type' => [ 'type' => 'string' ],
                ],
            ],
            [$count_content, 'handle'],
            'edit_posts',
            'content',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/set-post-terms',
            'free',
            'Assign taxonomy terms to a post (replace, append, or remove)',
            [
                'type'       => 'object',
                'properties' => [
                    'post_id'    => [ 'type' => 'integer' ],
                    'taxonomy'   => [ 'type' => 'string' ],
                    'terms'      => [ 'type' => 'array' ],
                    'mode'       => [ 'type' => 'string' ],
                    'session_id' => [ 'type' => 'string' ],
                ],
                'required'   => [ 'post_id', 'taxonomy', 'terms' ],
            ],
            [$set_post_terms, 'handle'],
            'edit_posts',
            'content',
            'update'
        ));

        $list_revisions   = new List_Revisions();
        $get_revision     = new Get_Revision();
        $restore_revision = new Restore_Revision();

        $registrar->register(new Ability(
            'wpmcp/list-revisions',
            'free',
            'List a post\'s revisions (id, author, date, change excerpt)',
            [
                'type'       => 'object',
                'properties' => [
                    'post_id' => [ 'type' => 'integer' ],
                ],
                'required'   => [ 'post_id' ],
            ],
            [$list_revisions, 'handle'],
            'edit_posts',
            'content',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/get-revision',
            'free',
            'Read a single post revision\'s fields',
            [
                'type'       => 'object',
                'properties' => [
                    'revision_id' => [ 'type' => 'integer' ],
                ],
                'required'   => [ 'revision_id' ],
            ],
            [$get_revision, 'handle'],
            'edit_posts',
            'content',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/restore-revision',
            'free',
            'Restore a post to a given revision',
            [
                'type'       => 'object',
                'properties' => [
                    'post_id'     => [ 'type' => 'integer' ],
                    'revision_id' => [ 'type' => 'integer' ],
                    'session_id'  => [ 'type' => 'string' ],
                ],
                'required'   => [ 'post_id', 'revision_id' ],
            ],
            [$restore_revision, 'handle'],
            'edit_posts',
            'content',
            'update'
        ));

        $get_media      = new Get_Media();
        $update_media   = new Update_Media();
        $delete_media   = new Delete_Media();
        $sideload_image = new Sideload_Image();

        $registrar->register(new Ability(
            'wpmcp/get-media',
            'free',
            'Read full detail for a Media Library attachment: title, URL, every registered image size, dimensions, mime type, alt text, caption, and description',
            [
                'type'       => 'object',
                'properties' => [
                    'media_id' => [ 'type' => 'integer' ],
                ],
                'required'   => [ 'media_id' ],
            ],
            [$get_media, 'handle'],
            'edit_posts',
            'media',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/update-media',
            'free',
            'Update a Media Library attachment\'s title, alt text, caption, and/or description',
            [
                'type'       => 'object',
                'properties' => [
                    'media_id'    => [ 'type' => 'integer' ],
                    'title'       => [ 'type' => 'string' ],
                    'alt'         => [ 'type' => 'string' ],
                    'caption'     => [ 'type' => 'string' ],
                    'description' => [ 'type' => 'string' ],
                    'session_id'  => [ 'type' => 'string' ],
                ],
                'required'   => [ 'media_id' ],
            ],
            [$update_media, 'handle'],
            'edit_posts',
            'media',
            'update'
        ));
        $registrar->register(new Ability(
            'wpmcp/delete-media',
            'free',
            'Delete a Media Library attachment. Disabled by default (site must opt in via the wpmcp_enable_delete_media filter) and requires confirm:true. force:true permanently deletes, routed through the safety snapshot so it can be rolled back',
            [
                'type'       => 'object',
                'properties' => [
                    'media_id'   => [ 'type' => 'integer' ],
                    'confirm'    => [ 'type' => 'boolean' ],
                    'force'      => [ 'type' => 'boolean' ],
                    'session_id' => [ 'type' => 'string' ],
                ],
                'required'   => [ 'media_id', 'confirm' ],
            ],
            [$delete_media, 'handle'],
            'edit_posts',
            'media',
            'delete'
        ));
        $registrar->register(new Ability(
            'wpmcp/sideload-image',
            'free',
            'Download an image from a URL and add it to the Media Library as a new attachment',
            [
                'type'       => 'object',
                'properties' => [
                    'url'         => [ 'type' => 'string' ],
                    'post_id'     => [ 'type' => 'integer' ],
                    'description' => [ 'type' => 'string' ],
                    'alt'         => [ 'type' => 'string' ],
                ],
                'required'   => [ 'url' ],
            ],
            [$sideload_image, 'handle'],
            'edit_posts',
            'media',
            'create'
        ));

        $list_media          = new List_Media();
        $resize_media        = new Resize_Media();
        $upload_svg          = new Upload_Svg();
        $set_stock_key       = new Set_Stock_Key();
        $search_stock_images = new Search_Stock_Images();
        $import_stock_image  = new Import_Stock_Image();
        $insert_stock_image  = new Insert_Stock_Image();

        $registrar->register(new Ability(
            'wpmcp/list-media',
            'free',
            'List Media Library attachments with type ("image" or an exact mime like "image/png"), date-range (after/before), and search filters, paged newest first with a total/pages envelope',
            [
                'type'       => 'object',
                'properties' => [
                    'type'     => [ 'type' => 'string' ],
                    'search'   => [ 'type' => 'string' ],
                    'after'    => [ 'type' => 'string' ],
                    'before'   => [ 'type' => 'string' ],
                    'page'     => [ 'type' => 'integer' ],
                    'per_page' => [ 'type' => 'integer' ],
                ],
            ],
            [$list_media, 'handle'],
            'edit_posts',
            'media',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/resize-media',
            'free',
            'Regenerate the specified registered image sizes for an attachment from its original file and report each resulting file (name, dimensions, URL). Snapshot-first with a physical-file backup, so the operation is rollbackable',
            [
                'type'       => 'object',
                'properties' => [
                    'media_id'   => [ 'type' => 'integer' ],
                    'sizes'      => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                    'session_id' => [ 'type' => 'string' ],
                ],
                'required'   => [ 'media_id', 'sizes' ],
            ],
            [$resize_media, 'handle'],
            'edit_posts',
            'media',
            'update'
        ));
        $registrar->register(new Ability(
            'wpmcp/upload-svg',
            'free',
            'Add an SVG to the Media Library from raw markup or an allowlisted URL. Every SVG passes a bundled fail-closed sanitizer (script/foreignObject/event handlers/external references are rejected outright); only the sanitized markup is stored. Rollback deletes the upload',
            [
                'type'       => 'object',
                'properties' => [
                    'markup'     => [ 'type' => 'string' ],
                    'url'        => [ 'type' => 'string' ],
                    'title'      => [ 'type' => 'string' ],
                    'alt'        => [ 'type' => 'string' ],
                    'post_id'    => [ 'type' => 'integer' ],
                    'session_id' => [ 'type' => 'string' ],
                ],
            ],
            [$upload_svg, 'handle'],
            'upload_files',
            'media',
            'create'
        ));
        $registrar->register(new Ability(
            'wpmcp/set-stock-key',
            'free',
            'Store (or clear, by passing an empty api_key) a bring-your-own stock-provider API key for pexels or unsplash. Keys are encrypted at rest with a site-salt-derived key and are never echoed back',
            [
                'type'       => 'object',
                'properties' => [
                    'provider' => [ 'type' => 'string', 'enum' => [ 'pexels', 'unsplash' ] ],
                    'api_key'  => [ 'type' => 'string' ],
                ],
                'required'   => [ 'provider', 'api_key' ],
            ],
            [$set_stock_key, 'handle'],
            'manage_options',
            'media',
            'update'
        ));
        $registrar->register(new Ability(
            'wpmcp/search-stock-images',
            'free',
            'Search openly-licensed stock images. Providers: openverse (keyless, Creative Commons results), pexels and unsplash (bring-your-own key via set-stock-key). Results are provider-attributed with license, license_url, attribution, and source_url, ready to pass to import-stock-image',
            [
                'type'       => 'object',
                'properties' => [
                    'query'    => [ 'type' => 'string' ],
                    'provider' => [ 'type' => 'string', 'enum' => [ 'openverse', 'pexels', 'unsplash' ] ],
                    'page'     => [ 'type' => 'integer' ],
                    'per_page' => [ 'type' => 'integer' ],
                ],
                'required'   => [ 'query' ],
            ],
            [$search_stock_images, 'handle'],
            'edit_posts',
            'media',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/import-stock-image',
            'free',
            'Sideload a stock search result into the Media Library. The fetch is SSRF-guarded: https-only, host allowlist checked before any request (wpmcp_remote_media_allowed_hosts filter), redirects refused, size caps enforced, and the bytes must verify as a real image. Attribution/license metadata is persisted on the attachment; rollback deletes the import',
            [
                'type'       => 'object',
                'properties' => [
                    'image_url'   => [ 'type' => 'string' ],
                    'provider'    => [ 'type' => 'string' ],
                    'title'       => [ 'type' => 'string' ],
                    'alt'         => [ 'type' => 'string' ],
                    'post_id'     => [ 'type' => 'integer' ],
                    'attribution' => [ 'type' => 'string' ],
                    'license'     => [ 'type' => 'string' ],
                    'license_url' => [ 'type' => 'string' ],
                    'source_url'  => [ 'type' => 'string' ],
                    'session_id'  => [ 'type' => 'string' ],
                ],
                'required'   => [ 'image_url' ],
            ],
            [$import_stock_image, 'handle'],
            'edit_posts',
            'media',
            'create'
        ));
        $registrar->register(new Ability(
            'wpmcp/insert-stock-image',
            'pro',
            'Composite stock-image flow: run the same SSRF-guarded import as import-stock-image, then insert the image into the post\'s builder content as a Gutenberg image block. Returns two independently rollbackable operation ids (undo the insert, undo the import)',
            [
                'type'       => 'object',
                'properties' => [
                    'post_id'     => [ 'type' => 'integer' ],
                    'image_url'   => [ 'type' => 'string' ],
                    'provider'    => [ 'type' => 'string' ],
                    'title'       => [ 'type' => 'string' ],
                    'alt'         => [ 'type' => 'string' ],
                    'attribution' => [ 'type' => 'string' ],
                    'license'     => [ 'type' => 'string' ],
                    'license_url' => [ 'type' => 'string' ],
                    'source_url'  => [ 'type' => 'string' ],
                    'session_id'  => [ 'type' => 'string' ],
                ],
                'required'   => [ 'post_id', 'image_url' ],
            ],
            [$insert_stock_image, 'handle'],
            'edit_posts',
            'media',
            'create'
        ));

        $get_settings    = new Get_Settings();
        $update_settings = new Update_Settings();

        $registrar->register(new Ability(
            'wpmcp/get-settings',
            'free',
            'Read WordPress site settings (general, reading, writing, discussion, media, permalinks), each with its group, type, and whether it is writable',
            [
                'type'       => 'object',
                'properties' => [
                    'group' => [ 'type' => 'string' ],
                    'keys'  => [ 'type' => 'array' ],
                ],
            ],
            [$get_settings, 'handle'],
            'manage_options',
            'settings',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/update-settings',
            'free',
            'Update WordPress site settings from a strict allowlist. Validates/coerces each value (enum, int range, bool), rejects unsafe permalink structures, skips read-only or non-allowlisted keys, and applies the valid subset even if some keys fail',
            [
                'type'       => 'object',
                'properties' => [
                    'settings' => [ 'type' => 'object' ],
                ],
                'required'   => [ 'settings' ],
            ],
            [$update_settings, 'handle'],
            'manage_options',
            'settings',
            'update'
        ));

        $list_users  = new List_Users();
        $get_user    = new Get_User();
        $create_user = new Create_User();
        $update_user = new Update_User();

        $registrar->register(new Ability(
            'wpmcp/list-users',
            'free',
            'List WordPress users as safe summary rows (id, username, display name, email, roles, registration date). Never returns password hashes or other secrets',
            [
                'type'       => 'object',
                'properties' => [
                    'role'     => [ 'type' => 'string' ],
                    'search'   => [ 'type' => 'string' ],
                    'per_page' => [ 'type' => 'integer' ],
                    'page'     => [ 'type' => 'integer' ],
                    'orderby'  => [ 'type' => 'string' ],
                    'order'    => [ 'type' => 'string' ],
                ],
            ],
            [$list_users, 'handle'],
            'list_users',
            'users',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/get-user',
            'free',
            'Read one user\'s profile detail, including an is_admin flag derived from live capabilities. Never returns the password hash',
            [
                'type'       => 'object',
                'properties' => [
                    'id' => [ 'type' => 'integer' ],
                ],
                'required'   => [ 'id' ],
            ],
            [$get_user, 'handle'],
            'list_users',
            'users',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/create-user',
            'free',
            'Create a new non-admin user. Auto-generates a strong password (never returned) and emails the new user so they can set their own. Rejects admin and unknown roles; defaults to subscriber',
            [
                'type'       => 'object',
                'properties' => [
                    'username'     => [ 'type' => 'string' ],
                    'email'        => [ 'type' => 'string' ],
                    'role'         => [ 'type' => 'string' ],
                    'display_name' => [ 'type' => 'string' ],
                    'first_name'   => [ 'type' => 'string' ],
                    'last_name'    => [ 'type' => 'string' ],
                ],
                'required'   => [ 'username', 'email' ],
            ],
            [$create_user, 'handle'],
            'create_users',
            'users',
            'create'
        ));
        $registrar->register(new Ability(
            'wpmcp/update-user',
            'free',
            'Update a non-admin user\'s profile fields (display name, email, url, nickname, first/last name, description). Refuses admin-capable users. Never changes role or password. Snapshotted so the change can be rolled back',
            [
                'type'       => 'object',
                'properties' => [
                    'id'           => [ 'type' => 'integer' ],
                    'display_name' => [ 'type' => 'string' ],
                    'email'        => [ 'type' => 'string' ],
                    'url'          => [ 'type' => 'string' ],
                    'nickname'     => [ 'type' => 'string' ],
                    'first_name'   => [ 'type' => 'string' ],
                    'last_name'    => [ 'type' => 'string' ],
                    'description'  => [ 'type' => 'string' ],
                    'session_id'   => [ 'type' => 'string' ],
                ],
                'required'   => [ 'id' ],
            ],
            [$update_user, 'handle'],
            'edit_users',
            'users',
            'update'
        ));

        $list_comments     = new List_Comments();
        $get_comment       = new Get_Comment();
        $moderate_comment  = new Moderate_Comment();
        $edit_comment      = new Edit_Comment();
        $delete_comment    = new Delete_Comment();

        $registrar->register(new Ability(
            'wpmcp/list-comments',
            'free',
            'List comments as safe summary rows (id, post, author, content, status, date), optionally filtered by post and moderation status, with paging',
            [
                'type'       => 'object',
                'properties' => [
                    'post_id'  => [ 'type' => 'integer' ],
                    'status'   => [ 'type' => 'string' ],
                    'per_page' => [ 'type' => 'integer' ],
                    'page'     => [ 'type' => 'integer' ],
                ],
            ],
            [$list_comments, 'handle'],
            'moderate_comments',
            'comments',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/get-comment',
            'free',
            'Read one comment\'s detail (post, parent, author fields, content, status, date)',
            [
                'type'       => 'object',
                'properties' => [
                    'id' => [ 'type' => 'integer' ],
                ],
                'required'   => [ 'id' ],
            ],
            [$get_comment, 'handle'],
            'moderate_comments',
            'comments',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/moderate-comment',
            'free',
            'Change a comment\'s moderation status: approve, unapprove, spam, trash or untrash. Snapshotted so the change can be rolled back',
            [
                'type'       => 'object',
                'properties' => [
                    'id'         => [ 'type' => 'integer' ],
                    'status'     => [ 'type' => 'string' ],
                    'session_id' => [ 'type' => 'string' ],
                ],
                'required'   => [ 'id', 'status' ],
            ],
            [$moderate_comment, 'handle'],
            'moderate_comments',
            'comments',
            'update'
        ));
        $registrar->register(new Ability(
            'wpmcp/edit-comment',
            'free',
            'Edit a comment\'s content and/or author fields (name, email, url). Snapshotted so the change can be rolled back',
            [
                'type'       => 'object',
                'properties' => [
                    'id'           => [ 'type' => 'integer' ],
                    'content'      => [ 'type' => 'string' ],
                    'author'       => [ 'type' => 'string' ],
                    'author_email' => [ 'type' => 'string' ],
                    'author_url'   => [ 'type' => 'string' ],
                    'session_id'   => [ 'type' => 'string' ],
                ],
                'required'   => [ 'id' ],
            ],
            [$edit_comment, 'handle'],
            'edit_comments',
            'comments',
            'update'
        ));
        $registrar->register(new Ability(
            'wpmcp/delete-comment',
            'free',
            'Permanently delete a comment. Disabled by default (site must opt in via the wpmcp_enable_delete_comment filter) and requires confirm:true. Routed through the safety snapshot so it can be rolled back, though the resurrected comment gets a new ID',
            [
                'type'       => 'object',
                'properties' => [
                    'id'         => [ 'type' => 'integer' ],
                    'confirm'    => [ 'type' => 'boolean' ],
                    'session_id' => [ 'type' => 'string' ],
                ],
                'required'   => [ 'id', 'confirm' ],
            ],
            [$delete_comment, 'handle'],
            'edit_comments',
            'comments',
            'delete'
        ));

        $list_plugins      = new List_Plugins();
        $activate_plugin   = new Activate_Plugin();
        $deactivate_plugin = new Deactivate_Plugin();
        $install_plugin    = new Install_Plugin();
        $update_plugin     = new Update_Plugin();
        $delete_plugin     = new Delete_Plugin();
        $list_themes       = new List_Themes();
        $switch_theme      = new Switch_Theme();
        $install_theme     = new Install_Theme();
        $update_theme      = new Update_Theme();
        $delete_theme      = new Delete_Theme();

        $registrar->register(new Ability(
            'wpmcp/list-plugins',
            'free',
            'List installed plugins with active status, protected-package flag, and pending update info',
            [
                'type'       => 'object',
                'properties' => [],
            ],
            [$list_plugins, 'handle'],
            'activate_plugins',
            'packages',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/activate-plugin',
            'free',
            'Activate an installed plugin. Snapshots the prior active_plugins option so it can be rolled back',
            [
                'type'       => 'object',
                'properties' => [
                    'plugin'     => [ 'type' => 'string' ],
                    'session_id' => [ 'type' => 'string' ],
                ],
                'required'   => [ 'plugin' ],
            ],
            [$activate_plugin, 'handle'],
            'activate_plugins',
            'packages',
            'update'
        ));
        $registrar->register(new Ability(
            'wpmcp/deactivate-plugin',
            'free',
            'Deactivate a plugin. Refuses protected packages (wpmcp, Elementor). Snapshots the prior active_plugins option so it can be rolled back',
            [
                'type'       => 'object',
                'properties' => [
                    'plugin'     => [ 'type' => 'string' ],
                    'session_id' => [ 'type' => 'string' ],
                ],
                'required'   => [ 'plugin' ],
            ],
            [$deactivate_plugin, 'handle'],
            'activate_plugins',
            'packages',
            'update'
        ));
        $registrar->register(new Ability(
            'wpmcp/install-plugin',
            'free',
            'Install a plugin from wordpress.org by slug, optionally activating it. Additive only; nothing to roll back',
            [
                'type'       => 'object',
                'properties' => [
                    'slug'     => [ 'type' => 'string' ],
                    'activate' => [ 'type' => 'boolean' ],
                ],
                'required'   => [ 'slug' ],
            ],
            [$install_plugin, 'handle'],
            'install_plugins',
            'packages',
            'create'
        ));
        $registrar->register(new Ability(
            'wpmcp/update-plugin',
            'free',
            'Update an installed plugin to the latest wordpress.org version. Disabled by default (wpmcp_enable_update_plugin filter) and requires confirm:true. File changes are not rollback-able',
            [
                'type'       => 'object',
                'properties' => [
                    'plugin'  => [ 'type' => 'string' ],
                    'confirm' => [ 'type' => 'boolean' ],
                ],
                'required'   => [ 'plugin', 'confirm' ],
            ],
            [$update_plugin, 'handle'],
            'update_plugins',
            'packages',
            'update',
            false,
            true,
            false
        ));
        $registrar->register(new Ability(
            'wpmcp/delete-plugin',
            'free',
            'Permanently delete an installed plugin\'s files. Disabled by default (wpmcp_enable_delete_plugin filter) and requires confirm:true. Refuses protected or active plugins. Not rollback-able',
            [
                'type'       => 'object',
                'properties' => [
                    'plugin'  => [ 'type' => 'string' ],
                    'confirm' => [ 'type' => 'boolean' ],
                ],
                'required'   => [ 'plugin', 'confirm' ],
            ],
            [$delete_plugin, 'handle'],
            'delete_plugins',
            'packages',
            'delete'
        ));

        $registrar->register(new Ability(
            'wpmcp/list-themes',
            'free',
            'List installed themes with active status, parent theme, and pending update info',
            [
                'type'       => 'object',
                'properties' => [],
            ],
            [$list_themes, 'handle'],
            'activate_plugins',
            'packages',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/switch-theme',
            'free',
            'Activate (switch to) an installed theme. Snapshots the prior template/stylesheet options so it can be rolled back',
            [
                'type'       => 'object',
                'properties' => [
                    'stylesheet' => [ 'type' => 'string' ],
                    'session_id' => [ 'type' => 'string' ],
                ],
                'required'   => [ 'stylesheet' ],
            ],
            [$switch_theme, 'handle'],
            'switch_themes',
            'packages',
            'update'
        ));
        $registrar->register(new Ability(
            'wpmcp/install-theme',
            'free',
            'Install a theme from wordpress.org by slug, optionally activating it. Additive only; nothing to roll back',
            [
                'type'       => 'object',
                'properties' => [
                    'slug'     => [ 'type' => 'string' ],
                    'activate' => [ 'type' => 'boolean' ],
                ],
                'required'   => [ 'slug' ],
            ],
            [$install_theme, 'handle'],
            'install_themes',
            'packages',
            'create'
        ));
        $registrar->register(new Ability(
            'wpmcp/update-theme',
            'free',
            'Update an installed theme to the latest wordpress.org version. Disabled by default (wpmcp_enable_update_theme filter) and requires confirm:true. File changes are not rollback-able',
            [
                'type'       => 'object',
                'properties' => [
                    'stylesheet' => [ 'type' => 'string' ],
                    'confirm'    => [ 'type' => 'boolean' ],
                ],
                'required'   => [ 'stylesheet', 'confirm' ],
            ],
            [$update_theme, 'handle'],
            'update_themes',
            'packages',
            'update',
            false,
            true,
            false
        ));
        $registrar->register(new Ability(
            'wpmcp/delete-theme',
            'free',
            'Permanently delete an installed theme\'s files. Disabled by default (wpmcp_enable_delete_theme filter) and requires confirm:true. Refuses the active theme (or its active parent). Not rollback-able',
            [
                'type'       => 'object',
                'properties' => [
                    'stylesheet' => [ 'type' => 'string' ],
                    'confirm'    => [ 'type' => 'boolean' ],
                ],
                'required'   => [ 'stylesheet', 'confirm' ],
            ],
            [$delete_theme, 'handle'],
            'delete_themes',
            'packages',
            'delete'
        ));

        $search_plugins  = new Search_Plugins();
        $get_plugin_info = new Get_Plugin_Info();

        $registrar->register(new Ability(
            'wpmcp/search-plugins',
            'free',
            'Search the wordpress.org plugin directory by keyword, with optional tag/author filters and a capped per_page',
            [
                'type'       => 'object',
                'properties' => [
                    'query'    => [ 'type' => 'string' ],
                    'per_page' => [ 'type' => 'integer' ],
                    'tag'      => [ 'type' => 'string' ],
                    'author'   => [ 'type' => 'string' ],
                ],
                'required'   => [ 'query' ],
            ],
            [$search_plugins, 'handle'],
            'install_plugins',
            'packages',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/get-plugin-info',
            'free',
            'Fetch full wordpress.org plugin directory info for a slug: version, rating, installs, homepage, download link, and compatibility',
            [
                'type'       => 'object',
                'properties' => [
                    'slug' => [ 'type' => 'string' ],
                ],
                'required'   => [ 'slug' ],
            ],
            [$get_plugin_info, 'handle'],
            'install_plugins',
            'packages',
            'read'
        ));

        $list_tables    = new List_Tables();
        $describe_table = new Describe_Table();
        $query          = new Query();
        $insert_row     = new Insert_Row();
        $update_rows    = new Update_Rows();
        $delete_rows    = new Delete_Rows();

        $registrar->register(new Ability(
            'wpmcp/list-tables',
            'free',
            'List database tables with estimated row counts and sizes',
            [
                'type'       => 'object',
                'properties' => [],
            ],
            [$list_tables, 'handle'],
            'manage_options',
            'database',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/describe-table',
            'free',
            'Return the columns, types, and keys of a database table',
            [
                'type'       => 'object',
                'properties' => [
                    'table' => [ 'type' => 'string' ],
                ],
                'required'   => [ 'table' ],
            ],
            [$describe_table, 'handle'],
            'manage_options',
            'database',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/query',
            'free',
            'Run a read-only SQL query (SELECT/SHOW/DESCRIBE/EXPLAIN/WITH). Writes, DDL, stacked statements, and file-access SQL are rejected before execution. Reads of the users/usermeta tables are blocked (wpmcp_db_allow_user_table_reads filter opts in, with secrets masked). Results are capped',
            [
                'type'       => 'object',
                'properties' => [
                    'sql'   => [ 'type' => 'string' ],
                    'limit' => [ 'type' => 'integer' ],
                ],
                'required'   => [ 'sql' ],
            ],
            [$query, 'handle'],
            'manage_options',
            'database',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/insert-row',
            'free',
            'Insert a row into a table via $wpdb->insert() (parameterized). Refuses protected tables. Disabled by default (wpmcp_enable_db_writes filter)',
            [
                'type'       => 'object',
                'properties' => [
                    'table' => [ 'type' => 'string' ],
                    'data'  => [ 'type' => 'object' ],
                ],
                'required'   => [ 'table', 'data' ],
            ],
            [$insert_row, 'handle'],
            'manage_options',
            'database',
            'create'
        ));
        $registrar->register(new Ability(
            'wpmcp/update-rows',
            'free',
            'Update rows matching a mandatory equality WHERE via $wpdb->update() (parameterized). Requires confirm:true. Refuses protected tables. Disabled by default (wpmcp_enable_db_writes filter). Snapshot-backed and restorable via rollback-operation when the table has a primary key and the WHERE stays under the before-image cap; otherwise reports recoverable:false with a reason and logs the before-image to the write audit log',
            [
                'type'       => 'object',
                'properties' => [
                    'table'      => [ 'type' => 'string' ],
                    'data'       => [ 'type' => 'object' ],
                    'where'      => [ 'type' => 'object' ],
                    'confirm'    => [ 'type' => 'boolean' ],
                    'session_id' => [ 'type' => 'string' ],
                ],
                'required'   => [ 'table', 'data', 'where' ],
            ],
            [$update_rows, 'handle'],
            'manage_options',
            'database',
            'update'
        ));
        $registrar->register(new Ability(
            'wpmcp/delete-rows',
            'free',
            'Delete rows matching a mandatory equality WHERE via $wpdb->delete() (parameterized). Requires confirm:true. Refuses protected tables. Disabled by default (wpmcp_enable_db_writes filter). Snapshot-backed and restorable via rollback-operation (rows reinserted with their original primary-key ids) when the table has a primary key and the WHERE stays under the before-image cap; otherwise reports recoverable:false with a reason and logs the before-image to the write audit log',
            [
                'type'       => 'object',
                'properties' => [
                    'table'      => [ 'type' => 'string' ],
                    'where'      => [ 'type' => 'object' ],
                    'confirm'    => [ 'type' => 'boolean' ],
                    'session_id' => [ 'type' => 'string' ],
                ],
                'required'   => [ 'table', 'where' ],
            ],
            [$delete_rows, 'handle'],
            'manage_options',
            'database',
            'delete'
        ));

        $read_file      = new Read_File();
        $list_directory = new List_Directory();
        $search_files   = new Search_Files();
        $write_file     = new Write_File();
        $edit_file      = new Edit_File();
        $delete_file    = new Delete_File();

        $registrar->register(new Ability(
            'wpmcp/read-file',
            'free',
            'Read a file inside the WordPress installation (core, plugins, themes, uploads). Path is confined to the WP install',
            [
                'type'       => 'object',
                'properties' => [
                    'path' => [ 'type' => 'string' ],
                ],
                'required'   => [ 'path' ],
            ],
            [$read_file, 'handle'],
            'manage_options',
            'filesystem',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/list-directory',
            'free',
            'List entries (files/dirs with size and mtime) of a directory inside the WordPress install. Optional bounded recursive listing',
            [
                'type'       => 'object',
                'properties' => [
                    'path'      => [ 'type' => 'string' ],
                    'recursive' => [ 'type' => 'boolean' ],
                ],
            ],
            [$list_directory, 'handle'],
            'manage_options',
            'filesystem',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/search-files',
            'free',
            'Search file contents for a substring across a directory tree inside the WordPress install. Filterable by extension; results are capped',
            [
                'type'       => 'object',
                'properties' => [
                    'query'       => [ 'type' => 'string' ],
                    'path'        => [ 'type' => 'string' ],
                    'extensions'  => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                    'max_results' => [ 'type' => 'integer' ],
                ],
                'required'   => [ 'query' ],
            ],
            [$search_files, 'handle'],
            'manage_options',
            'filesystem',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/write-file',
            'free',
            'Create or overwrite a file inside the WordPress install. Backs up an existing file first (recoverable via restore). Refuses wp-config.php/.htaccess. Disabled by default (wpmcp_enable_fs_writes filter); requires edit_files and honors DISALLOW_FILE_EDIT',
            [
                'type'       => 'object',
                'properties' => [
                    'path'    => [ 'type' => 'string' ],
                    'content' => [ 'type' => 'string' ],
                ],
                'required'   => [ 'path', 'content' ],
            ],
            [$write_file, 'handle'],
            'manage_options',
            'filesystem',
            'update'
        ));
        $registrar->register(new Ability(
            'wpmcp/edit-file',
            'free',
            'Replace an exact string in a file (must match once unless replace_all). Backs up the original first (recoverable via restore). Refuses wp-config.php/.htaccess. Disabled by default (wpmcp_enable_fs_writes filter); requires edit_files and honors DISALLOW_FILE_EDIT',
            [
                'type'       => 'object',
                'properties' => [
                    'path'        => [ 'type' => 'string' ],
                    'old_string'  => [ 'type' => 'string' ],
                    'new_string'  => [ 'type' => 'string' ],
                    'replace_all' => [ 'type' => 'boolean' ],
                ],
                'required'   => [ 'path', 'old_string', 'new_string' ],
            ],
            [$edit_file, 'handle'],
            'manage_options',
            'filesystem',
            'update'
        ));
        $registrar->register(new Ability(
            'wpmcp/delete-file',
            'free',
            'Delete a file inside the WordPress install. Requires confirm:true. Backs up the file first (recoverable via restore). Refuses wp-config.php/.htaccess. Disabled by default (wpmcp_enable_fs_writes filter); requires edit_files and honors DISALLOW_FILE_EDIT',
            [
                'type'       => 'object',
                'properties' => [
                    'path'    => [ 'type' => 'string' ],
                    'confirm' => [ 'type' => 'boolean' ],
                ],
                'required'   => [ 'path' ],
            ],
            [$delete_file, 'handle'],
            'manage_options',
            'filesystem',
            'delete'
        ));

        $analyze_performance = new Analyze_Performance();

        $registrar->register(new Ability(
            'wpmcp/analyze-performance',
            'free',
            'Scan server configuration, WordPress internals (database size, autoloaded options, cron backlog, object cache, OPcache, plugin count), and a target page (defaults to the frontpage; pass "url" or "post_id" for a specific page) for performance issues and bottlenecks. Returns a scored report with severities and ranked, actionable recommendations. Read-only; analyzes this site only',
            [
                'type'       => 'object',
                'properties' => [
                    'url'                => [ 'type' => 'string' ],
                    'post_id'            => [ 'type' => 'integer' ],
                    'include_page_fetch' => [ 'type' => 'boolean' ],
                    'deep_assets'        => [ 'type' => 'boolean' ],
                ],
            ],
            [$analyze_performance, 'handle'],
            'manage_options',
            'performance',
            'read'
        ));

        $scan_security = new Scan_Security();

        $registrar->register(new Ability(
            'wpmcp/scan-security',
            'free',
            'Scan this site for security and malware problems across four areas: PHP malware heuristics (uploads plus active plugins/themes; pass deep=true for the whole tree), WordPress core file integrity (against official wordpress.org checksums), configuration hardening (file editor, debug output, admin username, XML-RPC, version disclosure, HTTPS, security headers), and outdated/abandoned software. Returns a scored report (0-100 plus A-F grade) with severities and ranked, actionable recommendations. Read-only; self-contained; scans this site only',
            [
                'type'       => 'object',
                'properties' => [
                    'checks'      => [
                        'type'  => 'array',
                        'items' => [
                            'type' => 'string',
                            'enum' => ['malware', 'integrity', 'hardening', 'software'],
                        ],
                    ],
                    'deep'        => [ 'type' => 'boolean' ],
                    'max_files'   => [ 'type' => 'integer' ],
                    'max_seconds' => [ 'type' => 'integer' ],
                ],
            ],
            [$scan_security, 'handle'],
            'manage_options',
            'security',
            'read'
        ));

        $get_cache_status = new Get_Cache_Status();

        $registrar->register(new Ability(
            'wpmcp/get-cache-status',
            'free',
            'Report which caching layers are active on this site: the persistent object cache backend (external vs internal), OPcache (available and enabled), and any active page-cache plugin (WP Rocket, W3 Total Cache, WP Super Cache, LiteSpeed Cache, WP Fastest Cache) detected by its signature functions or constants. Read-only; inspects this site only',
            [
                'type'       => 'object',
                'properties' => [],
            ],
            [$get_cache_status, 'handle'],
            'manage_options',
            'performance',
            'read'
        ));

        $clear_cache = new Clear_Cache();

        $registrar->register(new Ability(
            'wpmcp/clear-cache',
            'free',
            'Flush this site\'s caches: the object cache (wp_cache_flush), all transients (per-site and site-wide), OPcache when available and enabled, and any detected page-cache plugin cleared via its own API. Returns a per-layer summary of what was cleared versus not present. Safe and idempotent: clearing a cache has no meaningful before-image to restore, so it is not snapshotted or rolled back',
            [
                'type'       => 'object',
                'properties' => [],
            ],
            [$clear_cache, 'handle'],
            'manage_options',
            'performance',
            'update'
        ));

        $groups = [
            'compose'        => fn () => $this->register_compose_abilities($registrar),
            'woocommerce'    => fn () => $this->register_woocommerce_abilities($registrar),
            'menu'           => fn () => $this->register_menu_abilities($registrar),
            'elementor'      => fn () => $this->register_elementor_abilities($registrar),
            'builder'        => fn () => $this->register_builder_abilities($registrar),
            'acf'            => fn () => $this->register_acf_abilities($registrar),
            'seo'            => fn () => $this->register_seo_abilities($registrar),
            'i18n'           => fn () => $this->register_i18n_abilities($registrar),
            'linking'        => fn () => $this->register_linking_abilities($registrar),
            'redirects'      => fn () => $this->register_redirect_abilities($registrar),
            'meta'           => fn () => $this->register_meta_abilities($registrar),
            'diagnostics'    => fn () => $this->register_diagnostics_abilities($registrar),
            'cron'           => fn () => $this->register_cron_abilities($registrar),
            'maintenance'    => fn () => $this->register_maintenance_abilities($registrar),
            'context'        => fn () => $this->register_context_abilities($registrar),
            'rest'           => fn () => $this->register_rest_abilities($registrar),
            'block'          => fn () => $this->register_block_abilities($registrar),
            'structure'      => fn () => $this->register_structure_abilities($registrar),
            'taxonomy'       => fn () => $this->register_taxonomy_abilities($registrar),
            'export'         => fn () => $this->register_export_abilities($registrar),
            'backup'         => fn () => $this->register_backup_abilities($registrar),
            'analysis'       => fn () => $this->register_analysis_abilities($registrar),
            'code'           => fn () => $this->register_code_abilities($registrar),
            'cli'            => fn () => $this->register_cli_abilities($registrar),
            'php_exec'       => fn () => $this->register_php_exec_abilities($registrar),
            'connect'        => fn () => $this->register_connect_abilities($registrar),
            'governance'     => fn () => $this->register_governance_abilities($registrar),
            'multisite'      => fn () => $this->register_multisite_abilities($registrar),
            'analytics'      => fn () => $this->register_analytics_abilities($registrar),
            'dispatch'       => fn () => $this->register_dispatch_abilities($registrar),
            'integration'    => fn () => $this->register_integration_abilities($registrar),
            'widget_builder' => fn () => $this->register_widget_builder_abilities($registrar),
            'block_builder'  => fn () => $this->register_block_builder_abilities($registrar),
            'cloud'          => fn () => $this->register_cloud_abilities($registrar),
            'search'         => fn () => $this->register_search_abilities($registrar),
            'skills'         => fn () => $this->register_skills_abilities($registrar),
            'memory'         => fn () => $this->register_memory_abilities($registrar),
        ];
        foreach ($groups as $group => $register) {
            if ($this->group_enabled($group)) {
                $register();
            }
        }
    }

    /**
     * Agent project memory (issue #131): the three tools an agent uses to read
     * this site's approved memory and to PROPOSE additions to it.
     *
     * All PRO, manage_options, domain 'memory', and all three refuse to run
     * until the wpmcp_enable_memory filter opts the site in (default false),
     * so registering them grants nothing by itself. manage_options rather than
     * edit_posts because a published entry is broadcast to every connecting
     * agent and can deny writes site-wide; even proposing into that queue is a
     * site-operations action, not a content edit.
     *
     * Note what is deliberately NOT here: nothing that publishes. The write
     * path is memory-propose, which hard-codes 'pending' with no status
     * parameter to override, so the only way an entry becomes live is an
     * administrator publishing it in wp-admin.
     */
    private function register_memory_abilities(Registrar $registrar): void
    {
        $entry_props = [
            'title'    => ['type' => 'string'],
            'text'     => ['type' => 'string'],
            'kind'     => ['type' => 'string', 'enum' => \WPMCP\Memory\Memory_Entry::KINDS],
            'severity' => ['type' => 'string', 'enum' => \WPMCP\Memory\Memory_Entry::SEVERITIES],
            'targets'  => ['type' => 'array', 'items' => ['type' => 'string']],
        ];

        $tools = [
            [
                'memory-recall',
                'read',
                new \WPMCP\Tools\Memory\Memory_Recall(),
                'Read this site\'s APPROVED project memory: durable facts, conventions and guardrails an administrator has published, plus published session summaries and the currently enforced block rules. Pending proposals are never returned (only their count), so an agent cannot read back its own unapproved suggestions as site policy. Read-only',
                [
                    'topic' => ['type' => 'string'],
                    'kind'  => ['type' => 'string', 'enum' => \WPMCP\Memory\Memory_Entry::KINDS],
                    'limit' => ['type' => 'integer'],
                ],
                [],
            ],
            [
                'memory-propose',
                'create',
                new \WPMCP\Tools\Memory\Memory_Propose(),
                'Propose one durable memory entry. Stored PENDING and inert (not injected into future sessions, and if severity=block not enforced) until an administrator publishes it in wp-admin. A severity=block proposal must name at least one target (tool:<ability>, post_id:<id>, post_type:<slug>); once published the server refuses every matching call in the permission check, so the guardrail is enforced, not advisory',
                $entry_props,
                ['text'],
            ],
            [
                'memory-save-summary',
                'create',
                new \WPMCP\Tools\Memory\Memory_Save_Summary(),
                'Record what a session changed, as a pending session-summary entry. The factual part is computed by the server from that session\'s snapshot rows (the before-images written ahead of every mutation), not from the supplied prose and not by any LLM, so it cannot over- or under-claim. Optional takeaways are filed as individual pending proposals',
                [
                    'session_id' => ['type' => 'string'],
                    'summary'    => ['type' => 'string'],
                    'takeaways'  => [
                        'type'  => 'array',
                        'items' => ['type' => 'object', 'properties' => $entry_props],
                    ],
                ],
                ['session_id'],
            ],
        ];

        foreach ($tools as [$name, $op, $handler, $desc, $props, $required]) {
            $schema = ['type' => 'object', 'properties' => $props];
            if ([] !== $required) {
                $schema['required'] = $required;
            }
            $registrar->register(new Ability(
                'wpmcp/' . $name,
                'pro',
                $desc,
                $schema,
                [$handler, 'handle'],
                'manage_options',
                'memory',
                $op
            ));
        }
    }

    /**
     * WP MCP Cloud sync (MVP): connect a site to the cloud and push/pull its
     * builder assets (custom widget + block specs) over a versioned,
     * backend-agnostic REST contract. All PRO, manage_options, domain 'cloud'.
     */
    /**
     * Agent skills over MCP (issue #74): list-skills / get-skill, serving the
     * bundled markdown playbook library plus any site-custom source.
     *
     * Both tools are FREE and read-only. Tiering happens per skill document
     * (`tier: pro` in its frontmatter, enforced in Get_Skill through
     * Pro\Gate), not at the surface, so a premium skill library can drop into
     * the same directory layout without changing this registration.
     *
     * Skills_Module::is_enabled() is checked HERE rather than in the tools,
     * so a site that turns the surface off does not merely hide it: the two
     * abilities are never registered, never reach the Abilities API, and cost
     * a connecting client zero tokens in tools/list.
     */
    private function register_skills_abilities(Registrar $registrar): void
    {
        if (! Skills_Module::is_enabled()) {
            return;
        }

        $list_skills = new List_Skills();
        $get_skill   = new Get_Skill();

        $registrar->register(new Ability(
            'wpmcp/list-skills',
            'free',
            'List the agent skills installed on this site: versioned markdown playbooks for common WordPress, Elementor and WooCommerce work (slug, name, one-line description, version, tags). Bodies are not included; call get-skill for one. Skills whose required tools are not registered here are hidden unless include_unavailable is set. Read-only',
            [
                'type'       => 'object',
                'properties' => [
                    'search'              => [ 'type' => 'string' ],
                    'tag'                 => [ 'type' => 'string' ],
                    'include_unavailable' => [ 'type' => 'boolean' ],
                ],
            ],
            [$list_skills, 'handle'],
            'edit_posts',
            'skills',
            'read'
        ));

        $registrar->register(new Ability(
            'wpmcp/get-skill',
            'free',
            'Load one skill\'s full instructions by slug (from list-skills), returned exactly as written. Unknown slugs return a structured error listing the installed slugs. Read-only',
            [
                'type'       => 'object',
                'properties' => [
                    'slug' => [ 'type' => 'string' ],
                ],
                'required'   => [ 'slug' ],
            ],
            [$get_skill, 'handle'],
            'edit_posts',
            'skills',
            'read'
        ));
    }

    private function register_cloud_abilities(Registrar $registrar): void
    {
        $tools = [
            ['cloud-connect', 'update', new \WPMCP\Tools\Cloud\Cloud_Connect(), 'Connect this site to WP MCP Cloud: store the cloud url + api key and verify them by fetching the account. Returns the account on success', ['url' => ['type' => 'string'], 'key' => ['type' => 'string']], ['url', 'key']],
            ['cloud-status', 'read', new \WPMCP\Tools\Cloud\Cloud_Status(), 'Report whether this site is connected to WP MCP Cloud, and where. Read-only', [], []],
            ['cloud-list-assets', 'read', new \WPMCP\Tools\Cloud\Cloud_List_Assets(), 'List the assets (widget/block specs) in this site\'s WP MCP Cloud account. Read-only', [], []],
            ['cloud-push-assets', 'update', new \WPMCP\Tools\Cloud\Cloud_Push_Assets(), 'Push this site\'s custom widget and block specs up to WP MCP Cloud (backup + reuse across sites). Optionally filter by type (widget|block)', ['types' => ['type' => 'array']], []],
            ['cloud-pull-assets', 'create', new \WPMCP\Tools\Cloud\Cloud_Pull_Assets(), 'Pull the builder assets from this site\'s WP MCP Cloud account and recreate them locally as custom widget/block specs (each validated before it is stored)', [], []],
        ];

        foreach ($tools as [$name, $op, $handler, $desc, $props, $required]) {
            $schema = [ 'type' => 'object', 'properties' => $props ];
            if ([] !== $required) {
                $schema['required'] = $required;
            }
            $registrar->register(new Ability(
                'wpmcp/' . $name,
                'pro',
                $desc,
                $schema,
                [$handler, 'handle'],
                'manage_options',
                'cloud',
                $op
            ));
        }
    }

    /**
     * Site content search index (issue #83). Two free abilities over a
     * materialized index table: `search-content` (read) and `reindex-search`
     * (rebuild).
     *
     * Free tier, because "where does this text live?" is orientation, the same
     * class of question as get-site-context and list-posts, not deep analysis.
     *
     * Capabilities are deliberately split. `search-content` is gated at
     * edit_posts, the standard content-read bar in this codebase, and then
     * re-checks `read_post` PER RESULT so the index can never surface a
     * non-published post the caller could not have read directly.
     * `reindex-search` is gated at manage_options: a full rebuild walks every
     * post on the site, which is a site-maintenance cost, not a content edit.
     *
     * Neither tool touches Safe_Mutation. The index is derived state that
     * reindex-search reconstructs from posts, postmeta, and menus, so a
     * snapshot of it would protect nothing that is not already recoverable in
     * one call.
     */
    private function register_search_abilities(Registrar $registrar): void
    {
        $search_content = new Search_Content();
        $reindex_search = new Reindex_Search();

        $registrar->register(new Ability(
            'wpmcp/search-content',
            'free',
            'Search ALL of the site\'s text at once, including copy that post_content search cannot see: Elementor and Bricks element settings, Gutenberg block attributes, template parts, reusable blocks, and nav menus. Every hit returns an addressable location (block path, element id, or menu item id) plus a snippet, so the follow-up edit needs no discovery pass. Read-only; results are re-checked against the caller\'s read_post capability. Run reindex-search once if the index is empty',
            [
                'type'       => 'object',
                'properties' => [
                    'query'           => [ 'type' => 'string' ],
                    'post_types'      => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                    'sources'         => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                    'object_types'    => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                    'limit'           => [ 'type' => 'integer' ],
                    'offset'          => [ 'type' => 'integer' ],
                    'hits_per_result' => [ 'type' => 'integer' ],
                ],
                'required'   => [ 'query' ],
            ],
            [$search_content, 'handle'],
            'edit_posts',
            'content',
            'read'
        ));

        $registrar->register(new Ability(
            'wpmcp/reindex-search',
            'free',
            'Build or rebuild the content search index that search-content reads. The index is maintained incrementally on every save, so this is only needed for the first build or a deliberate full refresh (bulk import, migration, builder data changed outside WordPress). Cursor-based: each call indexes at most batch_size posts and returns next_offset, so large sites rebuild across bounded calls instead of one request that times out',
            [
                'type'       => 'object',
                'properties' => [
                    'post_types'    => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                    'include_menus' => [ 'type' => 'boolean' ],
                    'full'          => [ 'type' => 'boolean' ],
                    'batch_size'    => [ 'type' => 'integer' ],
                    'offset'        => [ 'type' => 'integer' ],
                ],
            ],
            [$reindex_search, 'handle'],
            'manage_options',
            'content',
            'update'
        ));
    }

    /**
     * The data-driven custom Gutenberg block builder (EMCP parity, no eval):
     * store/validate/list block specs that a register_block_type render_callback
     * renders at runtime. All PRO, manage_options, domain 'blocks'.
     */
    private function register_block_builder_abilities(Registrar $registrar): void
    {
        $spec_schema = [ 'type' => 'object' ];

        $tools = [
            ['create-custom-block', 'create', new \WPMCP\Tools\BlockBuilder\Create_Custom_Block(), 'Create a custom Gutenberg block from a data spec (title, attributes, template with {{name}} placeholders). Validated, stored as a wpmcp_block post, and registered via register_block_type at runtime with a render_callback that interprets the template (no code generation, no eval). Remove with delete-custom-block', ['spec' => $spec_schema], ['spec']],
            ['update-custom-block', 'update', new \WPMCP\Tools\BlockBuilder\Update_Custom_Block(), 'Replace a custom block\'s spec by id (re-validated before it is stored)', ['block_id' => ['type' => 'integer'], 'spec' => $spec_schema], ['block_id', 'spec']],
            ['get-custom-block', 'read', new \WPMCP\Tools\BlockBuilder\Get_Custom_Block(), 'Read one custom block\'s stored spec by id. Read-only', ['block_id' => ['type' => 'integer']], ['block_id']],
            ['list-custom-blocks', 'read', new \WPMCP\Tools\BlockBuilder\List_Custom_Blocks(), 'List the custom blocks on this site (id, name, title, active/inactive). Read-only', [], []],
            ['delete-custom-block', 'delete', new \WPMCP\Tools\BlockBuilder\Delete_Custom_Block(), 'Delete a custom block by moving it to the trash (reversible via restore-post)', ['block_id' => ['type' => 'integer']], ['block_id']],
            ['set-block-status', 'update', new \WPMCP\Tools\BlockBuilder\Set_Block_Status(), 'Enable (publish) or disable (draft) a custom block by id', ['block_id' => ['type' => 'integer'], 'status' => ['type' => 'string']], ['block_id', 'status']],
            ['validate-block-spec', 'read', new \WPMCP\Tools\BlockBuilder\Validate_Block_Spec(), 'Statically validate a custom-block spec (title, attributes, template) without storing it. Read-only', ['spec' => $spec_schema], ['spec']],
            ['list-block-control-types', 'read', new \WPMCP\Tools\BlockBuilder\List_Block_Control_Types(), 'List the attribute types a custom-block spec may use and the block.json type each maps to. Read-only', [], []],
        ];

        foreach ($tools as [$name, $op, $handler, $desc, $props, $required]) {
            $schema = [ 'type' => 'object', 'properties' => $props ];
            if ([] !== $required) {
                $schema['required'] = $required;
            }
            $registrar->register(new Ability(
                'wpmcp/' . $name,
                'pro',
                $desc,
                $schema,
                [$handler, 'handle'],
                'manage_options',
                'blocks',
                $op
            ));
        }
    }

    /**
     * The data-driven custom Elementor widget builder (EMCP parity, no eval):
     * store/validate/list widget specs that Dynamic_Widget renders at runtime.
     * All PRO, manage_options + unfiltered_html-class authority (site-wide
     * markup authoring), domain 'elementor'.
     */
    private function register_widget_builder_abilities(Registrar $registrar): void
    {
        $spec_schema = [ 'type' => 'object' ];

        $tools = [
            ['create-custom-widget', 'create', new \WPMCP\Tools\WidgetBuilder\Create_Custom_Widget(), 'Create a custom Elementor widget from a data spec (title, controls, template with {{name}} placeholders). Validated, stored as a wpmcp_widget post, and registered as a real Elementor widget at runtime by a single data-driven widget (no code generation, no eval). Remove with delete-custom-widget', ['spec' => $spec_schema], ['spec']],
            ['update-custom-widget', 'update', new \WPMCP\Tools\WidgetBuilder\Update_Custom_Widget(), 'Replace a custom widget\'s spec by id (re-validated before it is stored)', ['widget_id' => ['type' => 'integer'], 'spec' => $spec_schema], ['widget_id', 'spec']],
            ['get-custom-widget', 'read', new \WPMCP\Tools\WidgetBuilder\Get_Custom_Widget(), 'Read one custom widget\'s stored spec by id. Read-only', ['widget_id' => ['type' => 'integer']], ['widget_id']],
            ['list-custom-widgets', 'read', new \WPMCP\Tools\WidgetBuilder\List_Custom_Widgets(), 'List the custom widgets on this site (id, name, title, active/inactive). Read-only', [], []],
            ['delete-custom-widget', 'delete', new \WPMCP\Tools\WidgetBuilder\Delete_Custom_Widget(), 'Delete a custom widget by moving it to the trash (reversible via restore-post)', ['widget_id' => ['type' => 'integer']], ['widget_id']],
            ['set-widget-status', 'update', new \WPMCP\Tools\WidgetBuilder\Set_Widget_Status(), 'Enable (publish) or disable (draft) a custom widget by id', ['widget_id' => ['type' => 'integer'], 'status' => ['type' => 'string']], ['widget_id', 'status']],
            ['validate-widget-spec', 'read', new \WPMCP\Tools\WidgetBuilder\Validate_Widget_Spec(), 'Statically validate a custom-widget spec (title, controls, template) without storing it. Read-only', ['spec' => $spec_schema], ['spec']],
            ['list-control-types', 'read', new \WPMCP\Tools\WidgetBuilder\List_Control_Types(), 'List the control types a custom-widget spec may use and the Elementor control each maps to. Read-only', [], []],
        ];

        foreach ($tools as [$name, $op, $handler, $desc, $props, $required]) {
            $schema = [ 'type' => 'object', 'properties' => $props ];
            if ([] !== $required) {
                $schema['required'] = $required;
            }
            $registrar->register(new Ability(
                'wpmcp/' . $name,
                'pro',
                $desc,
                $schema,
                [$handler, 'handle'],
                'manage_options',
                'elementor',
                $op
            ));
        }
    }

    /**
     * Register the integration-dispatcher pairs (issue #65): one
     * {integration}-read plus one {integration}-write ability per third-party
     * integration, dispatching to a per-operation catalog instead of N flat
     * tools. Registered unconditionally — availability is a call-time concern
     * for dispatchers (a missing host plugin yields a structured
     * integration_unavailable error, never a fatal), unlike the flat ACF/SEO/
     * i18n groups which skip registration when their plugin is absent.
     */
    private function register_integration_abilities(Registrar $registrar): void
    {
        $integrations = [
            new \WPMCP\Integrations\ACF_Integration(),
            new \WPMCP\Integrations\Gravity_Forms_Integration(),
            new \WPMCP\Integrations\Formidable_Integration(),
            new \WPMCP\Integrations\Contact_Form_7_Integration(),
            new \WPMCP\Integrations\WPForms_Integration(),
            new \WPMCP\Integrations\Gravity_Tables_Integration(),
            new \WPMCP\Integrations\Modern_Events_Calendar_Integration(),
            new \WPMCP\Integrations\The_Events_Calendar_Integration(),
            new \WPMCP\Integrations\Give_Integration(),
            new \WPMCP\Integrations\Paid_Memberships_Pro_Integration(),
            new \WPMCP\Integrations\Meta_Box_Integration(),
            new \WPMCP\Integrations\Ninja_Forms_Integration(),
            new \WPMCP\Integrations\Fluent_Forms_Integration(),
            new \WPMCP\Integrations\Forminator_Integration(),
            new \WPMCP\Integrations\SureForms_Integration(),
            new \WPMCP\Integrations\MetForm_Integration(),
        ];

        foreach ($integrations as $integration) {
            foreach ($integration->abilities() as $ability) {
                $registrar->register($ability);
            }
        }
    }

    /**
     * Static-analysis tools for admin/AI-authored PHP snippets. Gated at
     * manage_options since arbitrary PHP review is a site-administration
     * capability, not a content-editing one. validate-php-snippet never
     * executes the snippet it is given, so there is nothing to snapshot or
     * roll back; it never touches Safe_Mutation.
     */
    private function register_code_abilities(Registrar $registrar): void
    {
        $validate_php_snippet = new Validate_Php_Snippet();

        $registrar->register(new Ability(
            'wpmcp/validate-php-snippet',
            'free',
            'Statically validate a PHP code snippet without executing it: report syntax validity (with error message and line if invalid) and safety findings (severity-tagged warnings for dangerous constructs such as eval, exec, shell_exec, backticks, obfuscation decoders, request-driven execution, and outbound HTTP calls). Read-only, never runs the snippet',
            [
                'type'       => 'object',
                'properties' => [
                    'code' => [ 'type' => 'string' ],
                ],
                'required'   => [ 'code' ],
            ],
            [$validate_php_snippet, 'handle'],
            'manage_options',
            'code',
            'read'
        ));
    }

    /**
     * Register the guarded wp-cli executor (issue #44) as a PRO-tier ability:
     * running arbitrary (allowlisted) wp-cli subcommands is an advanced,
     * potentially destructive site-operations capability, the same class of
     * feature as the Elementor deep-editing tools that are this plugin's
     * only other 'pro' precedent, not a "heavy" operation in general.
     *
     * Gated at manage_options (site-administration capability, matching
     * every other site-operations tool group), domain 'cli', operation
     * 'update' (it can mutate site state depending on the subcommand run,
     * even though the default allowlist is read-only-ish).
     *
     * All of the actual safety guarantees live in Wp_Cli_Guard and are
     * independent of this registration: the tool is registered here, but
     * Run_Wp_Cli::handle() still refuses to run anything unless wp-cli
     * execution is explicitly enabled (default OFF), the environment
     * permits it, the subcommand is allowlisted, the arguments are free of
     * shell metacharacters, and the wp binary resolves. Registering this
     * ability does not, by itself, allow any command to run. Not routed
     * through Safe_Mutation: a wp-cli invocation's effects (if any) are
     * whatever that subcommand does, which has no generic before-image this
     * plugin could capture, so there is nothing here to snapshot or roll
     * back.
     */
    private function register_cli_abilities(Registrar $registrar): void
    {
        $run_wp_cli = new Run_Wp_Cli();

        $registrar->register(new Ability(
            'wpmcp/run-wp-cli',
            'pro',
            'Run a guarded, allowlisted wp-cli subcommand (e.g. "core version", "plugin list", "option get siteurl") and return its stdout, stderr, and exit code. Disabled by default (opt in via the WPMCP_ALLOW_WP_CLI constant or wpmcp_allow_wp_cli filter); refuses to run on a production environment unless a separate override is also set; only subcommands on the wpmcp_wp_cli_allowlist filter\'s allowlist are permitted; arguments containing shell metacharacters are rejected before anything runs',
            [
                'type'       => 'object',
                'properties' => [
                    'command' => [ 'type' => 'string' ],
                ],
                'required'   => [ 'command' ],
            ],
            [$run_wp_cli, 'handle'],
            'manage_options',
            'cli',
            'update'
        ));

        $this->register_cli_job_abilities($registrar);
    }

    /**
     * Background CLI job dispatch and polling (issue #84): the async
     * counterpart to run-wp-cli, for commands (imports, bulk regeneration,
     * migrations) that cannot finish inside one MCP request/response cycle.
     * Built on the same WP-Cron job-runner pattern as trigger-backup
     * (Cli_Job_Store + Run_Cli_Job mirror Backup_Job_Store + Run_Backup_Job).
     *
     * Every one of these is PRO at manage_options, domain 'cli', exactly
     * matching run-wp-cli: dispatching a command asynchronously is the same
     * capability as running it synchronously, so it must not be reachable
     * at a lower tier or a weaker capability than the synchronous tool.
     * dispatch-cli-job is 'create' (it creates a job record),
     * cancel-cli-job is 'update' (it transitions one), and get-cli-job /
     * list-cli-jobs are 'read'.
     *
     * The exec surface is dispatch-cli-job alone, and it adds no new
     * privilege: it runs the identical Wp_Cli_Guard_Chain as run-wp-cli
     * before a job is ever queued, and Run_Cli_Job runs that chain AGAIN
     * immediately before execution so revoking the opt-in gate also stops
     * work that is already queued. Rate limiting is inherited from the
     * Registrar's per-client counter like every other ability. Not routed
     * through Safe_Mutation, for the same reason run-wp-cli is not: a
     * wp-cli invocation's effects have no generic before-image to snapshot.
     */
    private function register_cli_job_abilities(Registrar $registrar): void
    {
        $dispatch_cli_job = new Dispatch_Cli_Job();
        $get_cli_job      = new Get_Cli_Job();
        $list_cli_jobs    = new List_Cli_Jobs();
        $cancel_cli_job   = new Cancel_Cli_Job();

        $registrar->register(new Ability(
            'wpmcp/dispatch-cli-job',
            'pro',
            'Queue a guarded, allowlisted wp-cli command as a background job and return its job id immediately, for long-running work (imports, bulk media regeneration) that cannot complete inside a single request. Poll it with get-cli-job. Subject to exactly the same gates as run-wp-cli: disabled by default (WPMCP_ALLOW_WP_CLI constant or wpmcp_allow_wp_cli filter), refused on production without a separate override, allowlisted subcommands only, and no shell metacharacters or non-allowlisted flags. The guard chain is re-checked immediately before the job actually runs, so closing the gate also stops jobs that are already queued. Optional timeout in seconds (default 300, max 900). Refused while too many jobs are already queued or running',
            [
                'type'       => 'object',
                'properties' => [
                    'command' => [ 'type' => 'string' ],
                    'timeout' => [ 'type' => 'integer' ],
                ],
                'required'   => [ 'command' ],
            ],
            [$dispatch_cli_job, 'handle'],
            'manage_options',
            'cli',
            'create'
        ));
        $registrar->register(new Ability(
            'wpmcp/get-cli-job',
            'pro',
            'Return a background CLI job\'s current record by job id: status (queued/running/completed/failed/canceled), the command as dispatched, and once it has run its captured stdout/stderr (size-capped), exit code, and timed-out flag, or the error that stopped it. Read-only',
            [
                'type'       => 'object',
                'properties' => [
                    'job_id' => [ 'type' => 'integer' ],
                ],
                'required'   => [ 'job_id' ],
            ],
            [$get_cli_job, 'handle'],
            'manage_options',
            'cli',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/list-cli-jobs',
            'pro',
            'List background CLI jobs, newest first, with an optional status filter (queued/running/completed/failed/canceled). Read-only',
            [
                'type'       => 'object',
                'properties' => [
                    'status' => [ 'type' => 'string' ],
                ],
            ],
            [$list_cli_jobs, 'handle'],
            'manage_options',
            'cli',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/cancel-cli-job',
            'pro',
            'Cancel a queued background CLI job: unschedule its WP-Cron event and mark it canceled so it never runs. Refuses with an error if the job is unknown or is no longer queued (already running, or in a terminal status)',
            [
                'type'       => 'object',
                'properties' => [
                    'job_id' => [ 'type' => 'integer' ],
                ],
                'required'   => [ 'job_id' ],
            ],
            [$cancel_cli_job, 'handle'],
            'manage_options',
            'cli',
            'update'
        ));
    }

    /**
     * Register the guarded PHP snippet executor (issue #45) as a PRO-tier
     * ability. This is the single most dangerous capability this plugin
     * exposes: running arbitrary PHP is remote code execution by
     * definition. It is the ONE explicit escape hatch outside the
     * snapshot/rollback safety model (Safety\Snapshot_Store, Safe_Mutation,
     * Rollback_Service): a snippet's effects are not captured before it
     * runs and are not undoable afterward, because there is no generic
     * before-image to snapshot for "whatever arbitrary PHP does." This
     * plugin's "AI physically can't wreck your site" promise holds here
     * ONLY because Run_Php_Snippet/Php_Snippet_Guard default this off,
     * fail closed on production and any unrecognized environment, and
     * require an operator to deliberately, explicitly enable it.
     *
     * Gated at manage_options (matching run-wp-cli and every other
     * site-operations tool group), domain 'code', operation 'update' (it
     * can mutate arbitrary site state, unlike validate-php-snippet's
     * read-only static analysis). Registering this ability does not, by
     * itself, allow any snippet to run: Run_Php_Snippet::handle() still
     * refuses unless PHP execution is explicitly enabled, the environment
     * permits it, and the #22 static validator does not flag the snippet
     * as unsafe (a usability speed-bump, not a security boundary).
     */
    private function register_php_exec_abilities(Registrar $registrar): void
    {
        $run_php_snippet = new Run_Php_Snippet();

        $registrar->register(new Ability(
            'wpmcp/run-php-snippet',
            'pro',
            'Run a guarded, arbitrary PHP snippet and return its return value, echoed output, and any thrown error. THIS IS REMOTE CODE EXECUTION: disabled by default (opt in via the WPMCP_ALLOW_PHP_EXEC constant or wpmcp_allow_php_exec filter); refuses to run on a production environment or any unrecognized environment unless a separate WPMCP_ALLOW_PHP_EXEC_ON_PRODUCTION override is also set; snippets flagged unsafe by the static validator are rejected before execution as a usability speed-bump only, not a security boundary. Its effects are not captured by this plugin\'s snapshot/rollback system and cannot be undone.',
            [
                'type'       => 'object',
                'properties' => [
                    'code' => [ 'type' => 'string' ],
                ],
                'required'   => [ 'code' ],
            ],
            [$run_php_snippet, 'handle'],
            'manage_options',
            'code',
            'update'
        ));
    }

    /**
     * Register the WP-Cron inspection and scheduling tools as free-tier
     * abilities (parity gap tracked in issue #28).
     *
     * All four are gated at manage_options and tagged domain 'cron': the cron
     * array can reveal internal hook names and scheduling, and mutating it is
     * a site-operations-level action, not a content edit. list-cron-events is
     * 'read'; schedule-event is 'create' and unschedule-event 'delete', both
     * routed through Safe_Mutation on the 'cron' option so rollback-operation
     * restores the prior cron array. run-event is 'update' but, like
     * clear-cache, is not snapshotted (firing a hook is an irreversible side
     * effect) and is additionally disabled by default behind the
     * wpmcp_enable_run_cron_event filter, so registering it does not by itself
     * allow any hook to run.
     */
    private function register_cron_abilities(Registrar $registrar): void
    {
        $list_cron_events = new List_Cron_Events();
        $schedule_event   = new Schedule_Event();
        $unschedule_event = new Unschedule_Event();
        $run_event        = new Run_Event();

        $registrar->register(new Ability(
            'wpmcp/list-cron-events',
            'free',
            'List the scheduled WP-Cron events (hook, next-run timestamp, recurrence/schedule, interval in seconds, callback args) from the cron array, plus the available schedules from wp_get_schedules(). Optional hook filter. Read-only',
            [
                'type'       => 'object',
                'properties' => [
                    'hook' => [ 'type' => 'string' ],
                ],
            ],
            [$list_cron_events, 'handle'],
            'manage_options',
            'cron',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/schedule-event',
            'free',
            'Schedule a recurring event (wp_schedule_event, when a recurrence is given) or a single event (wp_schedule_single_event). The recurrence is validated against wp_get_schedules(). Refuses scheduling core-critical hooks (wp_version_check, wp_update_plugins/themes, wp_scheduled_delete, delete_expired_transients, wp_privacy_delete_old_export_files). Snapshotted via object_type option (the cron option); rollback-operation restores the prior cron array',
            [
                'type'       => 'object',
                'properties' => [
                    'hook'       => [ 'type' => 'string' ],
                    'recurrence' => [ 'type' => 'string' ],
                    'timestamp'  => [ 'type' => 'integer' ],
                    'args'       => [ 'type' => 'array' ],
                    'session_id' => [ 'type' => 'string' ],
                ],
                'required'   => [ 'hook' ],
            ],
            [$schedule_event, 'handle'],
            'manage_options',
            'cron',
            'create'
        ));
        $registrar->register(new Ability(
            'wpmcp/unschedule-event',
            'free',
            'Unschedule a single occurrence (wp_unschedule_event, when a timestamp and matching args are given) or every event for a hook (wp_clear_scheduled_hook). Unrestricted, including core hooks, but made safe by undoability: snapshotted via object_type option (the cron option), so rollback-operation restores the prior cron array',
            [
                'type'       => 'object',
                'properties' => [
                    'hook'       => [ 'type' => 'string' ],
                    'timestamp'  => [ 'type' => 'integer' ],
                    'args'       => [ 'type' => 'array' ],
                    'session_id' => [ 'type' => 'string' ],
                ],
                'required'   => [ 'hook' ],
            ],
            [$unschedule_event, 'handle'],
            'manage_options',
            'cron',
            'delete'
        ));
        $registrar->register(new Ability(
            'wpmcp/run-event',
            'free',
            'Fire a scheduled cron hook now via do_action(), for debugging scheduled jobs. Disabled by default until a site opts in with the wpmcp_enable_run_cron_event filter, and only fires a hook actually present in the cron array (never an arbitrary string). Always replays the stored event args, never caller-supplied ones. Not snapshotted: firing a hook is an irreversible side effect',
            [
                'type'       => 'object',
                'properties' => [
                    'hook' => [ 'type' => 'string' ],
                ],
                'required'   => [ 'hook' ],
            ],
            [$run_event, 'handle'],
            'manage_options',
            'cron',
            'update'
        ));
    }

    /**
     * Register the maintenance-mode tools as free-tier abilities (parity
     * gap tracked in issue #42).
     *
     * All three are gated at manage_options and tagged domain 'maintenance':
     * turning maintenance mode on or off is a site-operations-level action,
     * matching the same capability already used for cron and diagnostics.
     * get-maintenance-status is 'read'. enable-maintenance and
     * disable-maintenance are both 'update', routed through Safe_Mutation on
     * the 'wpmcp_maintenance' option, so rollback-operation restores the
     * prior on/off state. Front-end enforcement (Maintenance_Guard, hooked
     * to template_redirect in boot()) reads the same option and exempts any
     * user who is logged in and holds manage_options, so registering these
     * abilities never risks locking an admin out of their own site.
     */
    private function register_maintenance_abilities(Registrar $registrar): void
    {
        $get_maintenance_status = new Get_Maintenance_Status();
        $enable_maintenance     = new Enable_Maintenance();
        $disable_maintenance    = new Disable_Maintenance();

        $registrar->register(new Ability(
            'wpmcp/get-maintenance-status',
            'free',
            'Report whether maintenance mode is on and, when it is, the configured message and Retry-After seconds. Read-only',
            [
                'type'       => 'object',
                'properties' => [],
            ],
            [$get_maintenance_status, 'handle'],
            'manage_options',
            'maintenance',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/enable-maintenance',
            'free',
            'Turn maintenance mode on: sets the wpmcp_maintenance option (enabled=true, message, retry_after seconds). Front-end visitors who are not logged in as a manage_options user then receive a 503 with the configured message until maintenance mode is disabled again. Snapshotted via object_type option (the wpmcp_maintenance option); rollback-operation restores the prior state',
            [
                'type'       => 'object',
                'properties' => [
                    'message'     => [ 'type' => 'string' ],
                    'retry_after' => [ 'type' => 'integer' ],
                    'session_id'  => [ 'type' => 'string' ],
                ],
            ],
            [$enable_maintenance, 'handle'],
            'manage_options',
            'maintenance',
            'update'
        ));
        $registrar->register(new Ability(
            'wpmcp/disable-maintenance',
            'free',
            'Turn maintenance mode off: sets enabled=false on the wpmcp_maintenance option (message and retry_after are preserved for a later re-enable). Snapshotted via object_type option (the wpmcp_maintenance option); rollback-operation restores the prior state',
            [
                'type'       => 'object',
                'properties' => [
                    'session_id' => [ 'type' => 'string' ],
                ],
            ],
            [$disable_maintenance, 'handle'],
            'manage_options',
            'maintenance',
            'update'
        ));
    }

    /**
     * Register the site-context orientation tool as a free-tier ability
     * (parity gap tracked in issue #19).
     *
     * Gated at edit_posts, a low bar reflecting that this is orientation
     * data for an agent (site identity, versions, theme, content model,
     * integrations), not a site-settings-level read. The admin email is
     * deliberately excluded from the payload so this low gate never leaks a
     * secret-shaped value. Read-only, so it never touches Safe_Mutation.
     */
    private function register_context_abilities(Registrar $registrar): void
    {
        $get_site_context = new Get_Site_Context();

        $registrar->register(new Ability(
            'wpmcp/get-site-context',
            'free',
            'Report a single orientation payload for an agent connecting to this site: name, URL, tagline, WordPress and PHP versions, active theme, active plugin count and slugs, registered public post types with counts, public taxonomies, user count, locale, timezone, multisite status, and which integrations (Elementor, WooCommerce, ACF, Yoast, RankMath) are active. Excludes the admin email. Read-only',
            [
                'type'       => 'object',
                'properties' => [],
            ],
            [$get_site_context, 'handle'],
            'edit_posts',
            'context',
            'read'
        ));
    }

    /**
     * Register the generic WP REST API passthrough tools as free-tier
     * abilities (parity gap tracked in issue #36).
     *
     * list-rest-routes is read-only discovery: it only reads the route table
     * off rest_get_server(), never executes a route, and is gated at
     * edit_posts like other read tools.
     *
     * call-rest is gated at edit_posts too, matching the capability every
     * other read tool in this codebase requires: the REAL authorization
     * decision for both reads and writes is made by the target endpoint's
     * own permission_callback (see Call_Rest's class docblock), which runs
     * against the current user regardless of what edit_posts alone would
     * otherwise allow. edit_posts is therefore only the floor to reach this
     * tool at all, not a grant of what it can do. The write path
     * (POST/PUT/PATCH/DELETE) is additionally disabled by default behind the
     * wpmcp_enable_rest_writes filter and requires confirm:true; sites
     * enabling that filter are encouraged to also require manage_options (or
     * an equivalent stricter capability) on whichever endpoints they expect
     * this tool to write through, since call-rest itself cannot know in
     * advance which capability a given write endpoint's permission_callback
     * enforces.
     */
    private function register_rest_abilities(Registrar $registrar): void
    {
        $list_rest_routes = new List_Rest_Routes();
        $call_rest        = new Call_Rest();

        $registrar->register(new Ability(
            'wpmcp/list-rest-routes',
            'free',
            'List the routes registered on this site\'s WP REST API server (core plus every active plugin\'s namespace): route path, allowed HTTP methods, and a short summary of each route\'s args. Optional namespace and/or search filters narrow the result by substring match on the route path; limit caps the number of rows returned (default 50, max 200). Read-only: never executes a route',
            [
                'type'       => 'object',
                'properties' => [
                    'namespace' => [ 'type' => 'string' ],
                    'search'    => [ 'type' => 'string' ],
                    'limit'     => [ 'type' => 'integer' ],
                ],
            ],
            [$list_rest_routes, 'handle'],
            'edit_posts',
            'rest',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/call-rest',
            'free',
            'Perform an internal WP REST API request (rest_do_request) against any route registered on this site and return its HTTP status and body. Authorization is inherited from the REST API itself: the target endpoint\'s own permission_callback runs against the current user exactly as it would for a real HTTP request, so this tool cannot grant or widen access beyond what that endpoint already allows. GET/HEAD are always permitted (subject to the endpoint\'s own permission check). POST/PUT/PATCH/DELETE are refused unless a site has opted in via the wpmcp_enable_rest_writes filter (disabled by default) AND the caller passes confirm:true; a successful write reports recoverable:false because an arbitrary REST write cannot be generically snapshotted or undone',
            [
                'type'       => 'object',
                'properties' => [
                    'method'  => [ 'type' => 'string' ],
                    'route'   => [ 'type' => 'string' ],
                    'params'  => [ 'type' => 'object' ],
                    'confirm' => [ 'type' => 'boolean' ],
                ],
                'required'   => [ 'method', 'route' ],
            ],
            [$call_rest, 'handle'],
            'edit_posts',
            'rest',
            'update'
        ));
    }

    /**
     * Register the block-type introspection and (de)serialization tools as
     * free-tier abilities (parity gap tracked in issue #39).
     *
     * list-block-types is read-only discovery: it only reads
     * WP_Block_Type_Registry, gated at edit_posts like other read tools.
     * Tagged domain 'blocks'.
     *
     * convert-html-to-blocks (parity gap tracked in issue #48) is a pure
     * HTML-to-block-markup transform with no DB write, so it is likewise a
     * read/utility operation rather than create/update.
     */
    private function register_block_abilities(Registrar $registrar): void
    {
        $list_block_types      = new List_Block_Types();
        $get_block_type        = new Get_Block_Type();
        $parse_blocks          = new Parse_Blocks();
        $serialize_blocks      = new Serialize_Blocks();
        $convert_html_to_blocks = new Convert_Html_To_Blocks();

        $registrar->register(new Ability(
            'wpmcp/list-block-types',
            'free',
            'List the block types registered with WP_Block_Type_Registry: name, title, category, whether the block renders dynamically (is_dynamic), and its declared attribute names. Optional category (exact match) and/or search (substring match on block name) filters narrow the result. Read-only',
            [
                'type'       => 'object',
                'properties' => [
                    'category' => [ 'type' => 'string' ],
                    'search'   => [ 'type' => 'string' ],
                ],
            ],
            [$list_block_types, 'handle'],
            'edit_posts',
            'blocks',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/get-block-type',
            'free',
            'Return full detail for a single registered block type by name: its attributes schema, declared supports, and block-context wiring (uses_context, provides_context). Read-only',
            [
                'type'       => 'object',
                'properties' => [
                    'name' => [ 'type' => 'string' ],
                ],
                'required'   => [ 'name' ],
            ],
            [$get_block_type, 'handle'],
            'edit_posts',
            'blocks',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/parse-blocks',
            'free',
            'Parse block markup into its block tree via parse_blocks(). Accepts either "blocks" (raw markup) or "id" (an existing post, parses its post_content). Each node reports blockName, attrs, recursively parsed innerBlocks, and an innerHTML summary. Read-only',
            [
                'type'       => 'object',
                'properties' => [
                    'id'     => [ 'type' => 'integer' ],
                    'blocks' => [ 'type' => 'string' ],
                ],
            ],
            [$parse_blocks, 'handle'],
            'edit_posts',
            'blocks',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/serialize-blocks',
            'free',
            'Serialize a block tree (as produced by parse-blocks, or any array shaped the same way) back into valid block markup via serialize_blocks(). A pure transform, not a database write: it never touches a post. To write the resulting markup to a post use the existing update-blocks tool',
            [
                'type'       => 'object',
                'properties' => [
                    'blocks' => [ 'type' => 'array' ],
                ],
                'required'   => [ 'blocks' ],
            ],
            [$serialize_blocks, 'handle'],
            'edit_posts',
            'blocks',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/convert-html-to-blocks',
            'free',
            'Convert raw HTML into valid Gutenberg block markup. Maps common top-level elements to core blocks (h1-h6 to core/heading, p to core/paragraph, img to core/image, ul/ol to core/list, blockquote to core/quote, pre/code to core/code, hr to core/separator, table to core/table); anything unrecognized is wrapped in a core/html block so no content is lost. A pure transform, not a database write: it never touches a post. To write the resulting markup to a post use the existing update-blocks tool',
            [
                'type'       => 'object',
                'properties' => [
                    'html' => [ 'type' => 'string' ],
                ],
                'required'   => [ 'html' ],
            ],
            [$convert_html_to_blocks, 'handle'],
            'edit_posts',
            'blocks',
            'read'
        ));
        $this->register_surgical_block_abilities($registrar);
    }

    /**
     * Register the surgical per-block editing tools and pattern tools
     * (issue #56) as free-tier abilities, domain 'blocks'.
     *
     * Shared targeting model: a "path" is an array of zero-based indexes
     * into the tree exactly as parse-blocks reports it, each subsequent
     * index descending into innerBlocks. Every mutation requires
     * expected_hash (the content_hash returned by parse-blocks) so a stale
     * read is refused instead of clobbering concurrent edits, refuses
     * content that does not round-trip byte-identically through
     * parse/serialize (so untouched blocks are never rewritten), and is
     * snapshot-first via Safe_Mutation, restorable with rollback-operation.
     */
    private function register_surgical_block_abilities(Registrar $registrar): void
    {
        $add_block       = new Add_Block();
        $update_block    = new Update_Block();
        $remove_block    = new Remove_Block();
        $move_block      = new Move_Block();
        $duplicate_block = new Duplicate_Block();
        $list_patterns   = new List_Patterns();
        $insert_pattern  = new Insert_Pattern();

        $path_schema = [
            'type'  => 'array',
            'items' => [ 'type' => 'integer' ],
        ];

        $registrar->register(new Ability(
            'wpmcp/add-block',
            'free',
            'Surgically insert ONE block (given as "<!-- wp:... -->" delimited markup) into a post so it lands at "path" (array of zero-based indexes into the parse-blocks tree; the final segment may equal the sibling count to append; nested paths descend innerBlocks). Requires expected_hash (the content_hash from parse-blocks) and refuses stale reads. Snapshot-first; every other block stays byte-identical',
            [
                'type'       => 'object',
                'properties' => [
                    'id'            => [ 'type' => 'integer' ],
                    'path'          => $path_schema,
                    'markup'        => [ 'type' => 'string' ],
                    'expected_hash' => [ 'type' => 'string' ],
                    'session_id'    => [ 'type' => 'string' ],
                ],
                'required'   => [ 'id', 'path', 'markup', 'expected_hash' ],
            ],
            [$add_block, 'handle'],
            'edit_posts',
            'blocks',
            'create'
        ));
        $registrar->register(new Ability(
            'wpmcp/update-block',
            'free',
            'Surgically update ONE block in place by "path" (array of zero-based indexes into the parse-blocks tree, descending innerBlocks): replace its attributes ("attrs", full replacement) and/or its inner HTML ("inner_html", leaf blocks only, target a container\'s children by their own paths). Requires expected_hash (the content_hash from parse-blocks) and refuses stale reads. Snapshot-first; every other block stays byte-identical',
            [
                'type'       => 'object',
                'properties' => [
                    'id'            => [ 'type' => 'integer' ],
                    'path'          => $path_schema,
                    'attrs'         => [ 'type' => 'object' ],
                    'inner_html'    => [ 'type' => 'string' ],
                    'expected_hash' => [ 'type' => 'string' ],
                    'session_id'    => [ 'type' => 'string' ],
                ],
                'required'   => [ 'id', 'path', 'expected_hash' ],
            ],
            [$update_block, 'handle'],
            'edit_posts',
            'blocks',
            'update'
        ));
        $registrar->register(new Ability(
            'wpmcp/remove-block',
            'free',
            'Surgically remove ONE block by "path" (array of zero-based indexes into the parse-blocks tree, descending innerBlocks); nested removals keep the container wrapper intact. Requires expected_hash (the content_hash from parse-blocks) and refuses stale reads. Snapshot-first and fully restorable via rollback-operation',
            [
                'type'       => 'object',
                'properties' => [
                    'id'            => [ 'type' => 'integer' ],
                    'path'          => $path_schema,
                    'expected_hash' => [ 'type' => 'string' ],
                    'session_id'    => [ 'type' => 'string' ],
                ],
                'required'   => [ 'id', 'path', 'expected_hash' ],
            ],
            [$remove_block, 'handle'],
            'edit_posts',
            'blocks',
            'delete'
        ));
        $registrar->register(new Ability(
            'wpmcp/move-block',
            'free',
            'Move the block at "from_path" to position "to_index" among its own siblings (same parent only; compose remove-block + add-block to move across parents). Requires expected_hash (the content_hash from parse-blocks) and refuses stale reads. Snapshot-first',
            [
                'type'       => 'object',
                'properties' => [
                    'id'            => [ 'type' => 'integer' ],
                    'from_path'     => $path_schema,
                    'to_index'      => [ 'type' => 'integer' ],
                    'expected_hash' => [ 'type' => 'string' ],
                    'session_id'    => [ 'type' => 'string' ],
                ],
                'required'   => [ 'id', 'from_path', 'to_index', 'expected_hash' ],
            ],
            [$move_block, 'handle'],
            'edit_posts',
            'blocks',
            'update'
        ));
        $registrar->register(new Ability(
            'wpmcp/duplicate-block',
            'free',
            'Duplicate the block at "path" (deep copy, inserted immediately after the original within the same parent) and return the copy\'s new_path. Requires expected_hash (the content_hash from parse-blocks) and refuses stale reads. Snapshot-first',
            [
                'type'       => 'object',
                'properties' => [
                    'id'            => [ 'type' => 'integer' ],
                    'path'          => $path_schema,
                    'expected_hash' => [ 'type' => 'string' ],
                    'session_id'    => [ 'type' => 'string' ],
                ],
                'required'   => [ 'id', 'path', 'expected_hash' ],
            ],
            [$duplicate_block, 'handle'],
            'edit_posts',
            'blocks',
            'create'
        ));
        $registrar->register(new Ability(
            'wpmcp/list-patterns',
            'free',
            'List the block patterns registered with WP_Block_Patterns_Registry: name, title, description, and categories. Optional search (case-insensitive substring match on name or title) narrows the result. Pattern markup is inserted server-side by insert-pattern, so it is not returned here. Read-only',
            [
                'type'       => 'object',
                'properties' => [
                    'search' => [ 'type' => 'string' ],
                ],
            ],
            [$list_patterns, 'handle'],
            'edit_posts',
            'blocks',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/insert-pattern',
            'free',
            'Insert a registered block pattern\'s parsed blocks into a post starting at "path" (same path semantics as add-block; pure-whitespace filler nodes are dropped). Requires expected_hash (the content_hash from parse-blocks) and refuses stale reads. Snapshot-first; every pre-existing block stays byte-identical',
            [
                'type'       => 'object',
                'properties' => [
                    'id'            => [ 'type' => 'integer' ],
                    'name'          => [ 'type' => 'string' ],
                    'path'          => $path_schema,
                    'expected_hash' => [ 'type' => 'string' ],
                    'session_id'    => [ 'type' => 'string' ],
                ],
                'required'   => [ 'id', 'name', 'path', 'expected_hash' ],
            ],
            [$insert_pattern, 'handle'],
            'edit_posts',
            'blocks',
            'create'
        ));
    }

    /**
     * Taxonomy term CRUD.
     *
     * Free tier and gated at manage_categories, the capability WordPress
     * itself uses for the Categories and Tags screens, so an editor who can
     * manage terms in wp-admin can manage them here and nobody else can.
     *
     * Every write runs through Safe_Mutation with a 'term' snapshot keyed by
     * (taxonomy, slug), following the create-redirect precedent rather than
     * the create-post one: terms have a natural key that exists before the
     * write, so even a creation is reversible. A deleted term is restored at
     * its ORIGINAL term_id and term_taxonomy_id, which is what reattaches
     * the posts that were filed under it.
     */
    private function register_taxonomy_abilities(Registrar $registrar): void
    {
        $list_terms    = new List_Terms();
        $get_term      = new Get_Term();
        $create_term   = new Create_Term();
        $update_term   = new Update_Term();
        $delete_term   = new Delete_Term();
        $set_term_meta = new Set_Term_Meta();

        $registrar->register(new Ability(
            'wpmcp/list-terms',
            'free',
            'List terms in a taxonomy with search, parent filter, ordering and pagination. Empty terms are INCLUDED unless hide_empty is set (the opposite of core\'s default), so a partial list never leads to creating a duplicate. Read-only',
            [
                'type'       => 'object',
                'properties' => [
                    'taxonomy'   => [ 'type' => 'string' ],
                    'search'     => [ 'type' => 'string' ],
                    'parent'     => [ 'type' => 'integer' ],
                    'hide_empty' => [ 'type' => 'boolean' ],
                    'per_page'   => [ 'type' => 'integer' ],
                    'page'       => [ 'type' => 'integer' ],
                    'orderby'    => [ 'type' => 'string', 'enum' => ['name', 'slug', 'count', 'term_id'] ],
                    'order'      => [ 'type' => 'string', 'enum' => ['ASC', 'DESC'] ],
                ],
                'required'   => [ 'taxonomy' ],
            ],
            [$list_terms, 'handle'],
            'manage_categories',
            'taxonomy',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/get-term',
            'free',
            'Read one term by term_id or slug, with its meta and full ancestor chain. Ancestors disambiguate a hierarchical taxonomy, where several same-named terms sit under different parents. Read-only',
            [
                'type'       => 'object',
                'properties' => [
                    'taxonomy' => [ 'type' => 'string' ],
                    'term_id'  => [ 'type' => 'integer' ],
                    'slug'     => [ 'type' => 'string' ],
                ],
                'required'   => [ 'taxonomy' ],
            ],
            [$get_term, 'handle'],
            'manage_categories',
            'taxonomy',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/create-term',
            'free',
            'Create a category, tag or custom taxonomy term. Snapshot-first, so the creation itself is reversible with rollback-operation. Refuses an existing slug, a missing parent, or a parent on a non-hierarchical taxonomy',
            [
                'type'       => 'object',
                'properties' => [
                    'taxonomy'    => [ 'type' => 'string' ],
                    'name'        => [ 'type' => 'string' ],
                    'slug'        => [ 'type' => 'string' ],
                    'description' => [ 'type' => 'string' ],
                    'parent'      => [ 'type' => 'integer' ],
                    'session_id'  => [ 'type' => 'string' ],
                ],
                'required'   => [ 'taxonomy', 'name' ],
            ],
            [$create_term, 'handle'],
            'manage_categories',
            'taxonomy',
            'create'
        ));
        $registrar->register(new Ability(
            'wpmcp/update-term',
            'free',
            'Update a term\'s name, slug, description or parent, snapshot-first. Refuses a slug held by another term, and refuses reparenting onto itself or a descendant: core permits that and the resulting cycle makes the taxonomy unrenderable',
            [
                'type'       => 'object',
                'properties' => [
                    'taxonomy'    => [ 'type' => 'string' ],
                    'term_id'     => [ 'type' => 'integer' ],
                    'slug'        => [ 'type' => 'string' ],
                    'name'        => [ 'type' => 'string' ],
                    'description' => [ 'type' => 'string' ],
                    'parent'      => [ 'type' => 'integer' ],
                    'session_id'  => [ 'type' => 'string' ],
                ],
                'required'   => [ 'taxonomy' ],
            ],
            [$update_term, 'handle'],
            'manage_categories',
            'taxonomy',
            'update'
        ));
        $registrar->register(new Ability(
            'wpmcp/delete-term',
            'free',
            'Delete a term, snapshot-first. Rolling back restores it at its original term_id and term_taxonomy_id and refiles the posts that were in it. Reports objects affected; refuses a taxonomy\'s default term',
            [
                'type'       => 'object',
                'properties' => [
                    'taxonomy'   => [ 'type' => 'string' ],
                    'term_id'    => [ 'type' => 'integer' ],
                    'slug'       => [ 'type' => 'string' ],
                    'session_id' => [ 'type' => 'string' ],
                ],
                'required'   => [ 'taxonomy' ],
            ],
            [$delete_term, 'handle'],
            'manage_categories',
            'taxonomy',
            'delete'
        ));
        $registrar->register(new Ability(
            'wpmcp/set-term-meta',
            'free',
            'Write or delete a term meta value, snapshot-first (whole term plus meta map captured, so rollback is exact). value:null deletes the key, staying distinct from an empty string. Protected keys are refused',
            [
                'type'       => 'object',
                'properties' => [
                    'taxonomy'   => [ 'type' => 'string' ],
                    'term_id'    => [ 'type' => 'integer' ],
                    'slug'       => [ 'type' => 'string' ],
                    'key'        => [ 'type' => 'string' ],
                    'value'      => [],
                    'session_id' => [ 'type' => 'string' ],
                ],
                'required'   => [ 'taxonomy', 'key' ],
            ],
            [$set_term_meta, 'handle'],
            'manage_categories',
            'taxonomy',
            'update'
        ));
    }

    /**
     * Register the shortcode and widget/sidebar introspection tools as
     * free-tier abilities (parity gap tracked in issue #38).
     *
     * All are gated at edit_posts and tagged domain 'structure'. Read-only
     * except render-shortcode, which executes the shortcode's own registered
     * callback via do_shortcode() and is tagged 'read' as well since it has
     * no database side effect of its own (the callback may of course have
     * side effects, exactly as it would on a live page render).
     */
    private function register_structure_abilities(Registrar $registrar): void
    {
        $list_shortcodes      = new List_Shortcodes();
        $render_shortcode     = new Render_Shortcode();
        $list_sidebars        = new List_Sidebars();
        $list_sidebar_widgets = new List_Sidebar_Widgets();

        $registrar->register(new Ability(
            'wpmcp/list-shortcodes',
            'free',
            'List the shortcode tags registered in the global $shortcode_tags array: tag name and a short description of the registered callback where resolvable. Optional search (substring match on tag name) narrows the result. Read-only',
            [
                'type'       => 'object',
                'properties' => [
                    'search' => [ 'type' => 'string' ],
                ],
            ],
            [$list_shortcodes, 'handle'],
            'edit_posts',
            'structure',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/render-shortcode',
            'free',
            'Render a shortcode string (e.g. "[gallery ids=\"1,2\"]") via do_shortcode() and return the resulting HTML. Only invokes tags already present in the registered shortcode registry; input must contain an opening "[" or it is refused',
            [
                'type'       => 'object',
                'properties' => [
                    'shortcode' => [ 'type' => 'string' ],
                ],
                'required'   => [ 'shortcode' ],
            ],
            [$render_shortcode, 'handle'],
            'edit_posts',
            'structure',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/list-sidebars',
            'free',
            'List the sidebars/widget areas registered via register_sidebar(): id, name, description. Read-only',
            [
                'type'       => 'object',
                'properties' => [],
            ],
            [$list_sidebars, 'handle'],
            'edit_posts',
            'structure',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/list-sidebar-widgets',
            'free',
            'List the widgets assigned to a single sidebar (by sidebar_id): widget id and display name, from wp_get_sidebars_widgets() resolved against the registered widgets. Read-only',
            [
                'type'       => 'object',
                'properties' => [
                    'sidebar_id' => [ 'type' => 'string' ],
                ],
                'required'   => [ 'sidebar_id' ],
            ],
            [$list_sidebar_widgets, 'handle'],
            'edit_posts',
            'structure',
            'read'
        ));
    }

    /**
     * Register the content export/import tools as free-tier abilities
     * (parity gap tracked in issue #40). Gated at manage_options: a WXR
     * export can contain the full text of every post (including private and
     * draft content), so it warrants the same capability already used for
     * other site-wide read/write tools like get-settings and update-plugin.
     */
    private function register_export_abilities(Registrar $registrar): void
    {
        $export_content = new Export_Content();
        $list_exports    = new List_Exports();
        $import_content  = new Import_Content();

        $registrar->register(new Ability(
            'wpmcp/export-content',
            'free',
            'Generate a WordPress eXtended RSS (WXR) export of site content via the native WordPress exporter (export_wp()). Optional content (post type: all/post/page/attachment/a custom post type), author, start_date, end_date, and status narrow what is included. Writes the XML to a protected directory under uploads and returns the file path, size, and item count. Read-only: does not mutate the site. WordPress\'s own export_wp() can only be safely called once per PHP process (a core limitation, not specific to this tool), so a second call in the same long-lived process is refused with a clear message rather than fataling',
            [
                'type'       => 'object',
                'properties' => [
                    'content'    => [ 'type' => 'string' ],
                    'author'     => [ 'type' => 'integer' ],
                    'start_date' => [ 'type' => 'string' ],
                    'end_date'   => [ 'type' => 'string' ],
                    'status'     => [ 'type' => 'string' ],
                ],
            ],
            [$export_content, 'handle'],
            'manage_options',
            'export',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/list-exports',
            'free',
            'List the WXR export files previously generated by export-content: file name, size in bytes, and created timestamp for each. Read-only',
            [
                'type'       => 'object',
                'properties' => [],
            ],
            [$list_exports, 'handle'],
            'manage_options',
            'export',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/import-content',
            'free',
            'Import a WordPress eXtended RSS (WXR) file, creating posts via wp_insert_post() (title, content, status, post_type, postmeta). Disabled by default (site must opt in via the wpmcp_enable_import filter) and always requires confirm:true. Content creation at scale has no single object_type/object_id to snapshot, so this honestly reports recoverable:false; every created post id is returned in created_post_ids so a caller can follow up with delete-post for each one. Uses a lightweight built-in WXR parser, not the WordPress Importer plugin',
            [
                'type'       => 'object',
                'properties' => [
                    'file'    => [ 'type' => 'string' ],
                    'confirm' => [ 'type' => 'boolean' ],
                ],
                'required'   => [ 'file' ],
            ],
            [$import_content, 'handle'],
            'manage_options',
            'export',
            'create'
        ));
    }

    /**
     * Register the async backup job orchestration tools as free-tier
     * abilities (parity gap tracked in issue #53). This sits on top of the
     * synchronous export-content tool: trigger-backup queues a job and
     * schedules a WP-Cron event (Run_Backup_Job) that produces the actual
     * artifact and flips the job's status, so a backup on a large site does
     * not have to complete within a single MCP request/response cycle.
     *
     * All four are gated at manage_options, matching Export's and Cron's
     * capability (both are comparable site-operations-level tool groups, and
     * this plugin's only precedent for a stronger, pro-tier gate is the
     * Elementor deep-editing tools specifically, not "heavy" operations in
     * general). trigger-backup is 'create' (it creates a job record) and
     * cancel-backup-job is 'update' (it transitions an existing job's
     * status); get-backup-status and list-backup-jobs are 'read'.
     *
     * The backup job itself only reads site data and writes a backup
     * artifact file plus the wpmcp_backup_jobs option: it never mutates user
     * content, so none of these are routed through Safe_Mutation and none
     * touch the safety core.
     */
    private function register_backup_abilities(Registrar $registrar): void
    {
        $trigger_backup     = new Trigger_Backup();
        $get_backup_status  = new Get_Backup_Status();
        $list_backup_jobs   = new List_Backup_Jobs();
        $cancel_backup_job  = new Cancel_Backup_Job();

        $registrar->register(new Ability(
            'wpmcp/trigger-backup',
            'free',
            'Queue an asynchronous backup job and schedule a WP-Cron event that produces the backup artifact (a WXR export via export-content) and flips the job\'s status to completed or failed. Returns the job id immediately, before the backup itself has run, so a large-site backup does not have to complete within a single request',
            [
                'type'       => 'object',
                'properties' => [
                    'type'  => [ 'type' => 'string' ],
                    'scope' => [ 'type' => 'string' ],
                ],
            ],
            [$trigger_backup, 'handle'],
            'manage_options',
            'backup',
            'create'
        ));
        $registrar->register(new Ability(
            'wpmcp/get-backup-status',
            'free',
            'Return a backup job\'s current record (status: queued/running/completed/failed/canceled, result artifact reference or error, timestamps) by job id. Read-only',
            [
                'type'       => 'object',
                'properties' => [
                    'job_id' => [ 'type' => 'integer' ],
                ],
                'required'   => [ 'job_id' ],
            ],
            [$get_backup_status, 'handle'],
            'manage_options',
            'backup',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/list-backup-jobs',
            'free',
            'List backup jobs, newest first, with an optional status filter (queued/running/completed/failed/canceled). Read-only',
            [
                'type'       => 'object',
                'properties' => [
                    'status' => [ 'type' => 'string' ],
                ],
            ],
            [$list_backup_jobs, 'handle'],
            'manage_options',
            'backup',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/cancel-backup-job',
            'free',
            'Cancel a queued backup job: unschedule its WP-Cron event and mark it canceled. Refuses with an error if the job is no longer queued (already running or in a terminal status) or unknown',
            [
                'type'       => 'object',
                'properties' => [
                    'job_id' => [ 'type' => 'integer' ],
                ],
                'required'   => [ 'job_id' ],
            ],
            [$cancel_backup_job, 'handle'],
            'manage_options',
            'backup',
            'update'
        ));
    }

    /**
     * Governance configuration, scoped identities, and the governance
     * decision audit log. All free-tier at manage_options, domain
     * 'governance', matching the existing config/audit tools in this
     * codebase (list-operations, rollback-*, get/update-settings): access
     * control configuration is site administration, not a feature add-on,
     * so none of this is gated behind Pro.
     */
    private function register_governance_abilities(Registrar $registrar): void
    {
        $get_governance_settings    = new Get_Governance_Settings();
        $update_governance_settings = new Update_Governance_Settings();
        $list_governance_audit_log  = new List_Governance_Audit_Log();
        $create_identity            = new Create_Identity();
        $list_identities            = new List_Identities();
        $delete_identity            = new Delete_Identity();

        $registrar->register(new Ability(
            'wpmcp/get-governance-settings',
            'free',
            'Return the stored governance toggle maps (ability, domain, operation): explicit enable/disable decisions layered on top of the wpmcp_ability_enabled/wpmcp_domain_enabled/wpmcp_operation_enabled filters. Read-only',
            [
                'type'       => 'object',
                'properties' => [],
            ],
            [$get_governance_settings, 'handle'],
            'manage_options',
            'governance',
            'read'
        ));

        $registrar->register(new Ability(
            'wpmcp/update-governance-settings',
            'free',
            'Batch-update stored governance toggles across the ability, domain, and operation dimensions, e.g. {ability: {"wpmcp/delete-post": false}, domain: {"database": false}, operation: {"delete": false}}. Invalid individual entries are skipped and reported, not thrown for; only entirely empty input throws',
            [
                'type'       => 'object',
                'properties' => [
                    'ability'   => [ 'type' => 'object' ],
                    'domain'    => [ 'type' => 'object' ],
                    'operation' => [ 'type' => 'object' ],
                ],
            ],
            [$update_governance_settings, 'handle'],
            'manage_options',
            'governance',
            'update'
        ));

        $registrar->register(new Ability(
            'wpmcp/list-governance-audit-log',
            'free',
            'List governance-decision audit log entries (ability, active identity or "none", allowed/denied, timestamp), newest first. Optional limit (default 20). Read-only',
            [
                'type'       => 'object',
                'properties' => [
                    'limit' => [ 'type' => 'integer' ],
                ],
            ],
            [$list_governance_audit_log, 'handle'],
            'manage_options',
            'governance',
            'read'
        ));

        $registrar->register(new Ability(
            'wpmcp/create-identity',
            'free',
            'Create (or overwrite, by name) a scoped identity: a named restriction that, once active (see the wpmcp_current_identity filter), narrows which abilities are usable on top of the caller\'s capability and Governance. Accepts name (required), and optional domains/operations/abilities allowlists plus mode (allow, the default, or deny). Optional exposure (full or compact) sets this identity\'s tool-surface mode, overriding the site-wide setting; omit to inherit',
            [
                'type'       => 'object',
                'properties' => [
                    'name'       => [ 'type' => 'string' ],
                    'domains'    => [ 'type' => 'array' ],
                    'operations' => [ 'type' => 'array' ],
                    'abilities'  => [ 'type' => 'array' ],
                    'mode'       => [ 'type' => 'string' ],
                    'exposure'   => [ 'type' => 'string' ],
                ],
                'required'   => [ 'name' ],
            ],
            [$create_identity, 'handle'],
            'manage_options',
            'governance',
            'create'
        ));

        $registrar->register(new Ability(
            'wpmcp/list-identities',
            'free',
            'List every registered scoped identity. Read-only',
            [
                'type'       => 'object',
                'properties' => [],
            ],
            [$list_identities, 'handle'],
            'manage_options',
            'governance',
            'read'
        ));

        $registrar->register(new Ability(
            'wpmcp/delete-identity',
            'free',
            'Delete a scoped identity by name. Returns an error if no identity with that name exists',
            [
                'type'       => 'object',
                'properties' => [
                    'name' => [ 'type' => 'string' ],
                ],
                'required'   => [ 'name' ],
            ],
            [$delete_identity, 'handle'],
            'manage_options',
            'governance',
            'delete'
        ));
    }

    /**
     * Register the multisite/network-introspection tools as free-tier
     * abilities (parity gap tracked in issue #35).
     *
     * This tool group is READ-ONLY network introspection plus honest
     * flagging when a tool is called outside a network. Fleet-management
     * writes (create/delete/archive/activate a site) are explicitly OUT OF
     * SCOPE: those operations are not covered by the snapshot/rollback
     * safety model (Safe_Mutation understands single-site content, options,
     * and postmeta, not whole-site lifecycle events), and exposing them here
     * would violate the product's "nothing unrecoverable" promise. If fleet
     * writes are ever added, they need their own safety story first, not a
     * bolt-on to this read-only group.
     *
     * is-multisite is registered unconditionally: it is the tool a caller
     * uses to discover whether a network exists at all, so it must be
     * reachable even on a single-site install (compare get-seo-status, which
     * is also always registered so it can report "no plugin active"). The
     * other three tools (get-network-info, list-network-sites,
     * get-site-details) are gated behind is_multisite(), following the same
     * conditional-registration pattern as the ACF/SEO/i18n tool groups:
     * WordPress's own multisite flag is the signal, and skipping
     * registration entirely keeps them out of the catalog on single-site
     * installs rather than registering tools that would only ever return a
     * "not a network" error.
     *
     * Gated at manage_network (WordPress's network-admin capability),
     * falling back to manage_options: manage_network does not exist as a
     * meaningful capability on a single-site install (current_user_can()
     * against it is effectively always false there), so manage_options keeps
     * these usable in that context while still requiring the equivalent
     * network-admin capability on an actual multisite install.
     */
    private function register_multisite_abilities(Registrar $registrar): void
    {
        $is_multisite = new Is_Multisite();

        $registrar->register(new Ability(
            'wpmcp/is-multisite',
            'free',
            'Report whether this WordPress install is part of a multisite network. Always registered, even on single-site installs, so a caller can discover network status before using the rest of the multisite tool group',
            [
                'type'       => 'object',
                'properties' => [],
            ],
            [$is_multisite, 'handle'],
            'edit_posts',
            'multisite',
            'read'
        ));

        if (! is_multisite()) {
            return;
        }

        $get_network_info = new Get_Network_Info();

        $registrar->register(new Ability(
            'wpmcp/get-network-info',
            'free',
            'Report this network\'s id, name, domain, total site count, and main site id, via get_network()/get_main_site_id(). Read-only',
            [
                'type'       => 'object',
                'properties' => [],
            ],
            [$get_network_info, 'handle'],
            'manage_network',
            'multisite',
            'read'
        ));

        $list_network_sites = new List_Network_Sites();

        $registrar->register(new Ability(
            'wpmcp/list-network-sites',
            'free',
            'List sites on the network (blog_id, url, name, last_updated) via get_sites(), with optional limit (default 50) and offset for pagination. limit is capped at 500',
            [
                'type'       => 'object',
                'properties' => [
                    'limit'  => [ 'type' => 'integer' ],
                    'offset' => [ 'type' => 'integer' ],
                ],
            ],
            [$list_network_sites, 'handle'],
            'manage_network',
            'multisite',
            'read'
        ));

        $get_site_details = new Get_Site_Details();

        $registrar->register(new Ability(
            'wpmcp/get-site-details',
            'free',
            'Report a single network site\'s details (blog_id, url, name, last_updated) by blog_id, via get_site()/get_blog_details(). Returns an error for an unrecognized blog_id',
            [
                'type'       => 'object',
                'properties' => [
                    'blog_id' => [ 'type' => 'integer' ],
                ],
                'required'   => [ 'blog_id' ],
            ],
            [$get_site_details, 'handle'],
            'manage_network',
            'multisite',
            'read'
        ));
    }

    /**
     * Register the analytics/Search Console reporting tools as free-tier
     * abilities (issue #49).
     *
     * FREE tier, matching every other "read data from a connected
     * third-party plugin/service" tool group in this codebase: Multisite
     * introspection, I18n (Polylang/WPML), SEO status/meta, and ALL of
     * WooCommerce including get-sales-report (a reporting tool directly
     * analogous to analytics summaries) are all free-tier. 'pro' tier here is
     * reserved for a different kind of feature: deep content scoring/analysis
     * (analyze-seo, analyze-accessibility, check-contrast, extract-content,
     * see register_analysis_abilities()) and deep Elementor element-tree
     * editing. Analytics summary/top-pages/GSC-summary/GSC-queries are the
     * same shape as WooCommerce's sales report or Multisite's site listing:
     * basic read/reporting access to a connected system, not deep analysis.
     *
     * All five tools are registered unconditionally, including the four data
     * tools (not just get-analytics-connection-status): unlike is_multisite(),
     * which is fixed for the lifetime of a request, whether an analytics
     * provider is connected can change at runtime without a page reload (a
     * site admin can activate/connect Site Kit at any time), so gating
     * registration on current connection state would require re-registering
     * abilities mid-request. Each data tool instead returns a
     * wpmcp_analytics_not_connected WP_Error gracefully when nothing is
     * connected, the same "always in the catalog, fails gracefully at call
     * time" shape as get-network-info et al. do for is_multisite() (the
     * difference being is_multisite() cannot change per-request, so that
     * group gates registration itself; this group's connection state can, so
     * it does not).
     *
     * get-analytics-connection-status specifically is the tool a caller uses
     * to discover whether any analytics provider is connected at all, before
     * deciding whether to use the rest of the group (mirrors
     * wpmcp/is-multisite and get-seo-status).
     *
     * Gated at manage_options for all five tools, matching the issue's spec:
     * analytics/Search Console data, even a "not connected" status check, is
     * site-administration information, not a content-editing capability.
     *
     * This is deliberately READ-ONLY: analytics/Search Console summaries and
     * reports only. There is no write path to Google or to this site here,
     * so none of these tools touch Safe_Mutation.
     */
    private function register_analytics_abilities(Registrar $registrar): void
    {
        $get_connection_status = new Get_Analytics_Connection_Status();
        $get_analytics_summary = new Get_Analytics_Summary();
        $get_top_pages         = new Get_Top_Pages();
        $get_search_console_summary = new Get_Search_Console_Summary();
        $get_search_console_queries = new Get_Search_Console_Queries();

        $registrar->register(new Ability(
            'wpmcp/get-analytics-connection-status',
            'free',
            'Report whether an analytics provider (Google Site Kit or explicitly configured credentials) is active and appears connected. Always registered so a caller can discover state before using the rest of the analytics tool group. Read-only',
            [
                'type'       => 'object',
                'properties' => [],
            ],
            [$get_connection_status, 'handle'],
            'manage_options',
            'analytics',
            'read'
        ));

        $registrar->register(new Ability(
            'wpmcp/get-analytics-summary',
            'free',
            'Read-only sessions/users/pageviews summary over a date range (Y-m-d, defaulting to a trailing 28-day window ending yesterday) via the connected analytics provider. Returns an error when no provider is connected',
            [
                'type'       => 'object',
                'properties' => [
                    'start_date' => [ 'type' => 'string' ],
                    'end_date'   => [ 'type' => 'string' ],
                ],
            ],
            [$get_analytics_summary, 'handle'],
            'manage_options',
            'analytics',
            'read'
        ));

        $registrar->register(new Ability(
            'wpmcp/get-top-pages',
            'free',
            'Read-only list of top pages by pageviews over a date range (Y-m-d, defaulting to a trailing 28-day window ending yesterday) via the connected analytics provider, with optional limit (default 10, capped at 100). Returns an error when no provider is connected',
            [
                'type'       => 'object',
                'properties' => [
                    'start_date' => [ 'type' => 'string' ],
                    'end_date'   => [ 'type' => 'string' ],
                    'limit'      => [ 'type' => 'integer' ],
                ],
            ],
            [$get_top_pages, 'handle'],
            'manage_options',
            'analytics',
            'read'
        ));

        $registrar->register(new Ability(
            'wpmcp/get-search-console-summary',
            'free',
            'Read-only clicks/impressions/ctr/position summary over a date range (Y-m-d, defaulting to a trailing 28-day window ending yesterday) via the connected Search Console provider. Returns an error when no provider is connected',
            [
                'type'       => 'object',
                'properties' => [
                    'start_date' => [ 'type' => 'string' ],
                    'end_date'   => [ 'type' => 'string' ],
                ],
            ],
            [$get_search_console_summary, 'handle'],
            'manage_options',
            'analytics',
            'read'
        ));

        $registrar->register(new Ability(
            'wpmcp/get-search-console-queries',
            'free',
            'Read-only list of top search queries by clicks over a date range (Y-m-d, defaulting to a trailing 28-day window ending yesterday) via the connected Search Console provider, with optional limit (default 10, capped at 100). Returns an error when no provider is connected',
            [
                'type'       => 'object',
                'properties' => [
                    'start_date' => [ 'type' => 'string' ],
                    'end_date'   => [ 'type' => 'string' ],
                    'limit'      => [ 'type' => 'integer' ],
                ],
            ],
            [$get_search_console_queries, 'handle'],
            'manage_options',
            'analytics',
            'read'
        ));
    }

    /**
     * Register the system diagnostics tools as free-tier abilities (parity
     * gap tracked in issue #32).
     *
     * All four are gated at manage_options: debug config/log and the
     * transient list can reveal server paths and cache internals, so this
     * matches the same capability already used for get-cache-status and
     * clear-cache. get-debug-config, get-debug-log, and list-transients are
     * 'read' operations; delete-transient is 'update' but, like clear-cache,
     * is not routed through Safe_Mutation: a transient is cache-like data
     * with no meaningful before-image to restore.
     */
    private function register_diagnostics_abilities(Registrar $registrar): void
    {
        $get_debug_config = new Get_Debug_Config();
        $get_debug_log    = new Get_Debug_Log();
        $list_transients  = new List_Transients();
        $delete_transient = new Delete_Transient();

        $registrar->register(new Ability(
            'wpmcp/get-debug-config',
            'free',
            'Report the debug-related constants (WP_DEBUG, WP_DEBUG_LOG, WP_DEBUG_DISPLAY, SCRIPT_DEBUG, SAVEQUERIES) and, when logging is on, the resolved debug.log path. Read-only, no secrets',
            [
                'type'       => 'object',
                'properties' => [],
            ],
            [$get_debug_config, 'handle'],
            'manage_options',
            'diagnostics',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/get-debug-log',
            'free',
            'Return a bounded tail (at most 200 lines / 64KB) of the WordPress debug log, never the whole file. Defaults to WP_CONTENT_DIR/debug.log or the WP_DEBUG_LOG custom path; any path argument is confined to WP_CONTENT_DIR, refusing traversal',
            [
                'type'       => 'object',
                'properties' => [
                    'path'  => [ 'type' => 'string' ],
                    'lines' => [ 'type' => 'integer' ],
                ],
            ],
            [$get_debug_log, 'handle'],
            'manage_options',
            'diagnostics',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/list-transients',
            'free',
            'List transients (name, expiry) from the options table, with an optional search substring filter and a capped limit (default 50, hard cap 500)',
            [
                'type'       => 'object',
                'properties' => [
                    'search' => [ 'type' => 'string' ],
                    'limit'  => [ 'type' => 'integer' ],
                ],
            ],
            [$list_transients, 'handle'],
            'manage_options',
            'diagnostics',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/delete-transient',
            'free',
            'Delete a single named transient via delete_transient(). Not snapshotted: transients are cache-like data with no meaningful before-image to restore, the same reasoning documented for clear-cache',
            [
                'type'       => 'object',
                'properties' => [
                    'name' => [ 'type' => 'string' ],
                ],
                'required'   => [ 'name' ],
            ],
            [$delete_transient, 'handle'],
            'manage_options',
            'diagnostics',
            'update'
        ));
    }

    /**
     * Register the generic post-meta and wp_options tools as free-tier
     * abilities (parity gap tracked in issue #31).
     *
     * get-post-meta/set-post-meta are gated at edit_posts, matching the rest
     * of the content tools; get-option/update-option are gated at
     * manage_options, since arbitrary option access is a site-settings-level
     * capability, not a content-editing one. set-post-meta and update-option
     * are 'update' operations (route through Safe_Mutation and are
     * undoable); update-option is additionally disabled by default behind
     * the wpmcp_enable_option_write filter (see Update_Option), so
     * registering the ability does not by itself allow any write.
     */
    private function register_meta_abilities(Registrar $registrar): void
    {
        $get_post_meta = new Get_Post_Meta();
        $set_post_meta = new Set_Post_Meta();
        $get_option    = new Get_Option();
        $update_option = new Update_Option();

        $registrar->register(new Ability(
            'wpmcp/get-post-meta',
            'free',
            'Read a post\'s meta, either the full map or a single key. Protected meta (a leading underscore, or is_protected_meta) is always skipped',
            [
                'type'       => 'object',
                'properties' => [
                    'post_id' => [ 'type' => 'integer' ],
                    'key'     => [ 'type' => 'string' ],
                ],
                'required'   => [ 'post_id' ],
            ],
            [$get_post_meta, 'handle'],
            'edit_posts',
            'meta',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/set-post-meta',
            'free',
            'Set a single meta key/value on a post. Refuses protected meta keys (a leading underscore, or is_protected_meta). Snapshotted via object_type post; rollback-operation restores the prior value',
            [
                'type'       => 'object',
                'properties' => [
                    'post_id'    => [ 'type' => 'integer' ],
                    'key'        => [ 'type' => 'string' ],
                    'value'      => [ 'type' => 'string' ],
                    'session_id' => [ 'type' => 'string' ],
                ],
                'required'   => [ 'post_id', 'key' ],
            ],
            [$set_post_meta, 'handle'],
            'edit_posts',
            'meta',
            'update'
        ));
        $registrar->register(new Ability(
            'wpmcp/get-option',
            'free',
            'Read a single wp_options value by name. Refuses a conservative denylist of sensitive/core option names (auth keys and salts, siteurl, home, active_plugins, and secret/password/token-shaped names)',
            [
                'type'       => 'object',
                'properties' => [
                    'name' => [ 'type' => 'string' ],
                ],
                'required'   => [ 'name' ],
            ],
            [$get_option, 'handle'],
            'manage_options',
            'settings',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/update-option',
            'free',
            'Update a single wp_options value by name. Refuses the same denylist as get-option, and is disabled by default until a site opts in with the wpmcp_enable_option_write filter. Snapshotted via object_type option; rollback-operation restores the prior value (or removes the option if it did not exist before)',
            [
                'type'       => 'object',
                'properties' => [
                    'name'       => [ 'type' => 'string' ],
                    'value'      => [ 'type' => 'string' ],
                    'session_id' => [ 'type' => 'string' ],
                ],
                'required'   => [ 'name', 'value' ],
            ],
            [$update_option, 'handle'],
            'manage_options',
            'settings',
            'update'
        ));
    }

    /**
     * Register the Elementor widget catalog tools as free-tier abilities.
     *
     * Registered unconditionally, matching every other tool group: a caller
     * only reaches a handler by invoking the ability, and each handler
     * degrades gracefully with a WP_Error when Elementor is not loaded. Both
     * tools are read-only (list-widgets and get-widget-schema inspect
     * Elementor's own widgets manager plus the curated widget catalog and
     * never touch post content), so neither is routed through the safety
     * core. The catalog itself is pure data (Widget_Catalog), so widget
     * coverage grows without growing this advertised tool surface — pinned
     * by tests/free/Platform/ToolsListBudgetTest.php.
     */
    private function register_elementor_abilities(Registrar $registrar): void
    {
        $list_widgets      = new List_Widgets();
        $get_widget_schema = new Get_Widget_Schema();

        $registrar->register(new Ability(
            'wpmcp/list-widgets',
            'free',
            'List Elementor registered widget types (name, title, categories, icon, tier, availability), annotated from the curated widget catalog (purpose line, cataloged flag). Filter by tier (free/pro), category, or a case-insensitive search over name/title/catalog keywords. Read-only',
            [
                'type'       => 'object',
                'properties' => [
                    'tier'     => [ 'type' => 'string' ],
                    'category' => [ 'type' => 'string' ],
                    'search'   => [ 'type' => 'string' ],
                ],
            ],
            [$list_widgets, 'handle'],
            'edit_posts',
            'elementor',
            'read'
        ));

        $registrar->register(new Ability(
            'wpmcp/get-widget-schema',
            'free',
            'Return the settings schema for one Elementor widget type: the curated typed params (defaults, enums, responsive hints, required plugin) for cataloged widgets by default, or the full introspected control stack with full:true (also the fallback for non-cataloged widgets). Read-only',
            [
                'type'       => 'object',
                'properties' => [
                    'widget_name' => [ 'type' => 'string' ],
                    'full'        => [ 'type' => 'boolean' ],
                ],
                'required'   => [ 'widget_name' ],
            ],
            [$get_widget_schema, 'handle'],
            'edit_posts',
            'elementor',
            'read'
        ));

        $this->register_elementor_pro_abilities($registrar);
    }

    /**
     * Register the Elementor deep-editing tools as pro-tier abilities.
     *
     * These read and write a page's `_elementor_data` element tree (id,
     * elType, widgetType, settings, and nested elements). Because Registrar
     * skips 'pro' tier abilities unless Gate::is_pro() is true, these tools
     * are only registered on Pro-tier sites. Writes go through
     * Safe_Mutation::run() with object_type='post': `_elementor_data` is
     * ordinary postmeta on the page, so the existing post snapshot already
     * captures and restores it, and every write here is undoable with no
     * change to the safety core. add-widget, update-widget, and
     * generate-widget validate typed params against the curated widget
     * catalog (Widget_Catalog, issue #59) and build Elementor's real
     * settings shapes from them, so an unsupported widget type or an
     * invalid/incomplete params payload never reaches a write.
     */
    private function register_elementor_pro_abilities(Registrar $registrar): void
    {
        $get_elementor_data = new Get_Elementor_Data();

        $registrar->register(new Ability(
            'wpmcp/get-elementor-data',
            'pro',
            'Return a page\'s parsed Elementor element tree (id, elType, widgetType, settings, and nested elements for every node), read directly from its _elementor_data postmeta. For large pages use summary=true (skeleton only: id, elType, widgetType, label, child_count, descendant_count), max_depth to stop at a depth (cut nodes report truncated_children), and element_id to window on one subtree. Always reports total_elements, returned_elements and truncated; data_hash always covers the whole page, so a windowed read is still valid as expected_hash. Read-only',
            [
                'type'       => 'object',
                'properties' => [
                    'post_id'    => [ 'type' => 'integer' ],
                    'summary'    => [ 'type' => 'boolean' ],
                    'max_depth'  => [ 'type' => 'integer' ],
                    'element_id' => [ 'type' => 'string' ],
                ],
                'required'   => [ 'post_id' ],
            ],
            [$get_elementor_data, 'handle'],
            'edit_posts',
            'elementor',
            'read'
        ));

        $update_element = new Update_Element();

        $registrar->register(new Ability(
            'wpmcp/update-element',
            'pro',
            'Update an Elementor element\'s settings by id, merging the given settings into its existing settings. Reads and writes the page\'s _elementor_data; undoable via rollback-operation since _elementor_data is ordinary postmeta captured by the existing post snapshot',
            [
                'type'       => 'object',
                'properties' => [
                    'post_id'    => [ 'type' => 'integer' ],
                    'element_id' => [ 'type' => 'string' ],
                    'settings'   => [ 'type' => 'object' ],
                ],
                'required'   => [ 'post_id', 'element_id', 'settings' ],
            ],
            [$update_element, 'handle'],
            'edit_posts',
            'elementor',
            'update'
        ));

        $add_widget = new Add_Widget();

        $registrar->register(new Ability(
            'wpmcp/add-widget',
            'pro',
            'Add a widget to a page\'s _elementor_data under parent_id (or top level) at an optional position. Any cataloged widget_type (see list-widgets) takes typed params, validated against the curated schema before anything is written; non-cataloged registered widgets take raw settings. Requires expected_hash from get-elementor-data. Undoable via rollback-operation',
            [
                'type'       => 'object',
                'properties' => [
                    'post_id'       => [ 'type' => 'integer' ],
                    'expected_hash' => [ 'type' => 'string' ],
                    'parent_id'     => [ 'type' => 'string' ],
                    'position'      => [ 'type' => 'integer' ],
                    'widget_type'   => [ 'type' => 'string' ],
                    'params'        => [ 'type' => 'object' ],
                    'settings'      => [ 'type' => 'object' ],
                ],
                'required'   => [ 'post_id', 'expected_hash', 'widget_type' ],
            ],
            [$add_widget, 'handle'],
            'edit_posts',
            'elementor',
            'update'
        ));

        $update_widget = new Update_Widget();

        $registrar->register(new Ability(
            'wpmcp/update-widget',
            'pro',
            'Patch a cataloged widget\'s settings by element id from typed curated params (same schema add-widget uses; see get-widget-schema), validated and merged into the existing settings. Non-cataloged widgets are refused toward update-element. Requires expected_hash from get-elementor-data. Undoable via rollback-operation',
            [
                'type'       => 'object',
                'properties' => [
                    'post_id'       => [ 'type' => 'integer' ],
                    'expected_hash' => [ 'type' => 'string' ],
                    'element_id'    => [ 'type' => 'string' ],
                    'params'        => [ 'type' => 'object' ],
                ],
                'required'   => [ 'post_id', 'expected_hash', 'element_id', 'params' ],
            ],
            [$update_widget, 'handle'],
            'edit_posts',
            'elementor',
            'update'
        ));

        $remove_element = new Remove_Element();

        $registrar->register(new Ability(
            'wpmcp/remove-element',
            'pro',
            'Remove an element (and its children) from a page\'s _elementor_data by id. Undoable via rollback-operation since _elementor_data is ordinary postmeta captured by the existing post snapshot',
            [
                'type'       => 'object',
                'properties' => [
                    'post_id'    => [ 'type' => 'integer' ],
                    'element_id' => [ 'type' => 'string' ],
                ],
                'required'   => [ 'post_id', 'element_id' ],
            ],
            [$remove_element, 'handle'],
            'edit_posts',
            'elementor',
            'update'
        ));

        $move_element = new Move_Element();

        $registrar->register(new Ability(
            'wpmcp/move-element',
            'pro',
            'Reparent an element by id: remove it from its current location and append it as a child of a new parent element in the page\'s _elementor_data. Refuses moves into the element itself or one of its own descendants. Undoable via rollback-operation since _elementor_data is ordinary postmeta captured by the existing post snapshot',
            [
                'type'       => 'object',
                'properties' => [
                    'post_id'    => [ 'type' => 'integer' ],
                    'element_id' => [ 'type' => 'string' ],
                    'parent_id'  => [ 'type' => 'string' ],
                ],
                'required'   => [ 'post_id', 'element_id', 'parent_id' ],
            ],
            [$move_element, 'handle'],
            'edit_posts',
            'elementor',
            'update'
        ));

        $generate_widget = new Generate_Widget();

        $registrar->register(new Ability(
            'wpmcp/generate-widget',
            'pro',
            'Generate a widget element of any cataloged type from the curated settings schema (see list-widgets / get-widget-schema) and insert it into a page\'s _elementor_data, as a child of parent_id or at the top level when parent_id is omitted, with a deterministic seedable element id. Unknown types and invalid or missing required settings are rejected before anything is written. Undoable via rollback-operation',
            [
                'type'       => 'object',
                'properties' => [
                    'post_id'     => [ 'type' => 'integer' ],
                    'parent_id'   => [ 'type' => 'string' ],
                    'widget_type' => [ 'type' => 'string' ],
                    'settings'    => [ 'type' => 'object' ],
                    'seed'        => [ 'type' => 'string' ],
                ],
                'required'   => [ 'post_id', 'widget_type', 'settings' ],
            ],
            [$generate_widget, 'handle'],
            'edit_posts',
            'elementor',
            'create'
        ));

        $get_global_settings = new Get_Global_Settings();

        $registrar->register(new Ability(
            'wpmcp/get-global-settings',
            'pro',
            'Read the active Elementor kit\'s global design tokens: system and custom colors and typography, with the four Elementor system tokens filled from defaults when the kit is untouched. Returns a settings_hash to chain a guarded write with update-global-colors / update-global-typography. Read-only',
            [
                'type'       => 'object',
                'properties' => [],
            ],
            [$get_global_settings, 'handle'],
            'edit_posts',
            'elementor',
            'read'
        ));

        $update_global_colors = new Update_Global_Colors();

        $registrar->register(new Ability(
            'wpmcp/update-global-colors',
            'pro',
            'Update the active Elementor kit\'s global colors. system_colors entries patch the four system tokens by _id (color/title); custom_colors entries update a custom color by _id or append a new one. Colors are validated as hex. Requires expected_hash from get-global-settings. Undoable via rollback-operation since the kit\'s _elementor_page_settings is captured by the post snapshot',
            [
                'type'       => 'object',
                'properties' => [
                    'expected_hash' => [ 'type' => 'string' ],
                    'system_colors' => [ 'type' => 'array' ],
                    'custom_colors' => [ 'type' => 'array' ],
                ],
                'required'   => [ 'expected_hash' ],
            ],
            [$update_global_colors, 'handle'],
            'manage_options',
            'elementor',
            'update'
        ));

        $update_global_typography = new Update_Global_Typography();

        $registrar->register(new Ability(
            'wpmcp/update-global-typography',
            'pro',
            'Update the active Elementor kit\'s global typography. system_typography entries patch the four system tokens by _id; custom_typography entries update a token by _id or append a new one. Any typography_* field is merged in, and setting a font enables custom typography so the token renders. Requires expected_hash from get-global-settings. Undoable via rollback-operation',
            [
                'type'       => 'object',
                'properties' => [
                    'expected_hash'     => [ 'type' => 'string' ],
                    'system_typography' => [ 'type' => 'array' ],
                    'custom_typography' => [ 'type' => 'array' ],
                ],
                'required'   => [ 'expected_hash' ],
            ],
            [$update_global_typography, 'handle'],
            'manage_options',
            'elementor',
            'update'
        ));

        $list_global_classes = new List_Global_Classes();

        $registrar->register(new Ability(
            'wpmcp/list-global-classes',
            'pro',
            'List the Elementor v4 global CSS classes of the active kit in stored order (empty when the feature has not been used), with the state_hash the global class write tools require as expected_hash. Read-only',
            [
                'type'       => 'object',
                'properties' => [],
            ],
            [$list_global_classes, 'handle'],
            'edit_posts',
            'elementor',
            'read'
        ));

        $this->register_global_class_write_abilities($registrar);

        $export_page = new Export_Page();

        $registrar->register(new Ability(
            'wpmcp/export-page',
            'pro',
            'Export a page\'s Elementor content to a portable structure (content element tree + page_settings + type + version), the envelope import-template accepts, so a design can move between pages or sites. Read-only',
            [
                'type'       => 'object',
                'properties' => [
                    'post_id' => [ 'type' => 'integer' ],
                ],
                'required'   => [ 'post_id' ],
            ],
            [$export_page, 'handle'],
            'edit_posts',
            'elementor',
            'read'
        ));

        $save_as_template = new Save_As_Template();

        $registrar->register(new Ability(
            'wpmcp/save-as-template',
            'pro',
            'Save a page\'s Elementor content as a reusable elementor_library template of the given template_type (page, section, container, header, footer, single, archive, popup, ...; defaults to page). Creating a template destroys nothing, so it is not snapshotted; remove it with delete-post',
            [
                'type'       => 'object',
                'properties' => [
                    'post_id'       => [ 'type' => 'integer' ],
                    'title'         => [ 'type' => 'string' ],
                    'template_type' => [ 'type' => 'string' ],
                ],
                'required'   => [ 'post_id', 'title' ],
            ],
            [$save_as_template, 'handle'],
            'edit_posts',
            'elementor',
            'create'
        ));

        $apply_template = new Apply_Template();

        $registrar->register(new Ability(
            'wpmcp/apply-template',
            'pro',
            'Apply a library template\'s content to a page. The template tree is copied with freshly regenerated ids (never colliding with the page) and either appended (default, optionally under parent_id at position) or used to replace the whole page (mode=replace). Requires expected_hash from get-elementor-data. Undoable via rollback-operation',
            [
                'type'       => 'object',
                'properties' => [
                    'post_id'       => [ 'type' => 'integer' ],
                    'template_id'   => [ 'type' => 'integer' ],
                    'expected_hash' => [ 'type' => 'string' ],
                    'mode'          => [ 'type' => 'string' ],
                    'parent_id'     => [ 'type' => 'string' ],
                    'position'      => [ 'type' => 'integer' ],
                ],
                'required'   => [ 'post_id', 'template_id', 'expected_hash' ],
            ],
            [$apply_template, 'handle'],
            'edit_posts',
            'elementor',
            'update'
        ));

        $import_template = new Import_Template();

        $registrar->register(new Ability(
            'wpmcp/import-template',
            'pro',
            'Create an elementor_library template from a portable export structure (the content envelope export-page produces), so a design can be round-tripped into a reusable template, including across sites. Not snapshotted (a create destroys nothing); remove with delete-post',
            [
                'type'       => 'object',
                'properties' => [
                    'export'        => [ 'type' => 'object' ],
                    'title'         => [ 'type' => 'string' ],
                    'template_type' => [ 'type' => 'string' ],
                ],
                'required'   => [ 'export' ],
            ],
            [$import_template, 'handle'],
            'edit_posts',
            'elementor',
            'create'
        ));

        $create_theme_template = new Create_Theme_Template();

        $registrar->register(new Ability(
            'wpmcp/create-theme-template',
            'pro',
            'Create an Elementor theme-builder template: a header, footer, single, archive, loop-item, search-results, or error-404 elementor_library post, optionally seeded with elements and display conditions. Not snapshotted (a create destroys nothing); remove with delete-theme-template',
            [
                'type'       => 'object',
                'properties' => [
                    'title'         => [ 'type' => 'string' ],
                    'template_type' => [ 'type' => 'string' ],
                    'elements'      => [ 'type' => 'array' ],
                    'conditions'    => [ 'type' => 'array' ],
                ],
                'required'   => [ 'title', 'template_type' ],
            ],
            [$create_theme_template, 'handle'],
            'manage_options',
            'elementor',
            'create'
        ));

        $set_template_conditions = new Set_Template_Conditions();

        $registrar->register(new Ability(
            'wpmcp/set-template-conditions',
            'pro',
            'Set where an Elementor theme template renders: display conditions as arrays of parts (["include","singular","post"]) or slash strings ("include/general"). Uses Elementor Pro\'s conditions manager when present, else writes the same _elementor_conditions meta. Snapshot-first, undoable via rollback-operation',
            [
                'type'       => 'object',
                'properties' => [
                    'post_id'    => [ 'type' => 'integer' ],
                    'conditions' => [ 'type' => 'array' ],
                ],
                'required'   => [ 'post_id', 'conditions' ],
            ],
            [$set_template_conditions, 'handle'],
            'manage_options',
            'elementor',
            'update'
        ));

        $get_theme_template = new Get_Theme_Template();

        $registrar->register(new Ability(
            'wpmcp/get-theme-template',
            'pro',
            'Read one Elementor library template: its type, display conditions, and element count. Read-only',
            [
                'type'       => 'object',
                'properties' => [
                    'post_id' => [ 'type' => 'integer' ],
                ],
                'required'   => [ 'post_id' ],
            ],
            [$get_theme_template, 'handle'],
            'edit_posts',
            'elementor',
            'read'
        ));

        $list_theme_templates = new List_Theme_Templates();

        $registrar->register(new Ability(
            'wpmcp/list-theme-templates',
            'pro',
            'List Elementor theme-builder templates (header, footer, single, archive, ...), optionally filtered to one template_type, each with its display conditions. Read-only',
            [
                'type'       => 'object',
                'properties' => [
                    'template_type' => [ 'type' => 'string' ],
                ],
            ],
            [$list_theme_templates, 'handle'],
            'edit_posts',
            'elementor',
            'read'
        ));

        $delete_theme_template = new Delete_Theme_Template();

        $registrar->register(new Ability(
            'wpmcp/delete-theme-template',
            'pro',
            'Delete an Elementor library template by moving it to the trash (reversible through WordPress trash / restore-post), mirroring the default delete-post path',
            [
                'type'       => 'object',
                'properties' => [
                    'post_id' => [ 'type' => 'integer' ],
                ],
                'required'   => [ 'post_id' ],
            ],
            [$delete_theme_template, 'handle'],
            'manage_options',
            'elementor',
            'delete'
        ));

        $detect_elementor_version = new Detect_Elementor_Version();

        $registrar->register(new Ability(
            'wpmcp/detect-elementor-version',
            'pro',
            'Report the Elementor and Elementor Pro version and whether atomic elements (Elementor 4.0+) are supported, so a caller can choose between the classic widget/container tools and the atomic tools (add-flexbox, add-div-block, add-atomic-widget). Read-only',
            [
                'type'       => 'object',
                'properties' => [],
            ],
            [$detect_elementor_version, 'handle'],
            'edit_posts',
            'elementor',
            'read'
        ));

        $add_flexbox = new Add_Flexbox();

        $registrar->register(new Ability(
            'wpmcp/add-flexbox',
            'pro',
            'Add an Elementor 4.0+ atomic flexbox container (elType e-flexbox) to a page under parent_id (or top level) at an optional position. Requires expected_hash from get-elementor-data. Undoable via rollback-operation',
            [
                'type'       => 'object',
                'properties' => [
                    'post_id'       => [ 'type' => 'integer' ],
                    'expected_hash' => [ 'type' => 'string' ],
                    'parent_id'     => [ 'type' => 'string' ],
                    'position'      => [ 'type' => 'integer' ],
                    'settings'      => [ 'type' => 'object' ],
                ],
                'required'   => [ 'post_id', 'expected_hash' ],
            ],
            [$add_flexbox, 'handle'],
            'edit_posts',
            'elementor',
            'create'
        ));

        $add_div_block = new Add_Div_Block();

        $registrar->register(new Ability(
            'wpmcp/add-div-block',
            'pro',
            'Add an Elementor 4.0+ atomic div-block container (elType e-div-block) to a page under parent_id (or top level) at an optional position. Requires expected_hash from get-elementor-data. Undoable via rollback-operation',
            [
                'type'       => 'object',
                'properties' => [
                    'post_id'       => [ 'type' => 'integer' ],
                    'expected_hash' => [ 'type' => 'string' ],
                    'parent_id'     => [ 'type' => 'string' ],
                    'position'      => [ 'type' => 'integer' ],
                    'settings'      => [ 'type' => 'object' ],
                ],
                'required'   => [ 'post_id', 'expected_hash' ],
            ],
            [$add_div_block, 'handle'],
            'edit_posts',
            'elementor',
            'create'
        ));

        $add_atomic_widget = new Add_Atomic_Widget();

        $registrar->register(new Ability(
            'wpmcp/add-atomic-widget',
            'pro',
            'Add an Elementor 4.0+ atomic widget (elType widget with an e-* widgetType such as e-heading, e-paragraph, e-button, e-image) to a page. Friendly params (title, content, text, image_url, alt, link) are converted to typed $$type props for known types; any type also accepts raw $$type-wrapped settings. Requires expected_hash. Undoable via rollback-operation',
            [
                'type'       => 'object',
                'properties' => [
                    'post_id'       => [ 'type' => 'integer' ],
                    'expected_hash' => [ 'type' => 'string' ],
                    'widget_type'   => [ 'type' => 'string' ],
                    'parent_id'     => [ 'type' => 'string' ],
                    'position'      => [ 'type' => 'integer' ],
                    'params'        => [ 'type' => 'object' ],
                    'settings'      => [ 'type' => 'object' ],
                ],
                'required'   => [ 'post_id', 'expected_hash', 'widget_type' ],
            ],
            [$add_atomic_widget, 'handle'],
            'edit_posts',
            'elementor',
            'create'
        ));

        $update_atomic_widget = new Update_Atomic_Widget();

        $registrar->register(new Ability(
            'wpmcp/update-atomic-widget',
            'pro',
            'Update an Elementor 4.0+ atomic widget\'s settings by element id. Friendly params are mapped to typed $$type props for known types (only the params you pass change; untouched props survive); raw $$type-wrapped settings are also accepted. Requires expected_hash. Undoable via rollback-operation',
            [
                'type'       => 'object',
                'properties' => [
                    'post_id'       => [ 'type' => 'integer' ],
                    'expected_hash' => [ 'type' => 'string' ],
                    'element_id'    => [ 'type' => 'string' ],
                    'params'        => [ 'type' => 'object' ],
                    'settings'      => [ 'type' => 'object' ],
                ],
                'required'   => [ 'post_id', 'expected_hash', 'element_id' ],
            ],
            [$update_atomic_widget, 'handle'],
            'edit_posts',
            'elementor',
            'update'
        ));

        $create_popup = new Create_Popup();

        $registrar->register(new Ability(
            'wpmcp/create-popup',
            'pro',
            'Create an Elementor popup (an elementor_library post of type popup), optionally seeded with elements and trigger/display settings. Not snapshotted (a create destroys nothing); configure further with set-popup-settings, remove with delete-post',
            [
                'type'       => 'object',
                'properties' => [
                    'title'    => [ 'type' => 'string' ],
                    'elements' => [ 'type' => 'array' ],
                    'settings' => [ 'type' => 'object' ],
                ],
                'required'   => [ 'title' ],
            ],
            [$create_popup, 'handle'],
            'manage_options',
            'elementor',
            'create'
        ));

        $set_popup_settings = new Set_Popup_Settings();

        $registrar->register(new Ability(
            'wpmcp/set-popup-settings',
            'pro',
            'Set an Elementor popup\'s trigger/display settings (open/close triggers, timing, advanced rules), merged into the popup\'s _elementor_page_settings. Snapshot-first, undoable via rollback-operation',
            [
                'type'       => 'object',
                'properties' => [
                    'post_id'  => [ 'type' => 'integer' ],
                    'settings' => [ 'type' => 'object' ],
                ],
                'required'   => [ 'post_id', 'settings' ],
            ],
            [$set_popup_settings, 'handle'],
            'manage_options',
            'elementor',
            'update'
        ));

        $list_dynamic_tags = new List_Dynamic_Tags();

        $registrar->register(new Ability(
            'wpmcp/list-dynamic-tags',
            'pro',
            'List the Elementor dynamic tags registered on this site (name, title, group), optionally filtered by group. Most tags come from Elementor Pro, so the list is short or empty without it. Read-only',
            [
                'type'       => 'object',
                'properties' => [
                    'group' => [ 'type' => 'string' ],
                ],
            ],
            [$list_dynamic_tags, 'handle'],
            'edit_posts',
            'elementor',
            'read'
        ));

        $set_dynamic_tag = new Set_Dynamic_Tag();

        $registrar->register(new Ability(
            'wpmcp/set-dynamic-tag',
            'pro',
            'Bind an Elementor dynamic tag to an element setting, writing it into the element\'s settings[__dynamic__][setting_key] in Elementor\'s [elementor-tag ...] format and preserving other bindings. Requires expected_hash from get-elementor-data. Undoable via rollback-operation',
            [
                'type'       => 'object',
                'properties' => [
                    'post_id'       => [ 'type' => 'integer' ],
                    'expected_hash' => [ 'type' => 'string' ],
                    'element_id'    => [ 'type' => 'string' ],
                    'setting_key'   => [ 'type' => 'string' ],
                    'tag_name'      => [ 'type' => 'string' ],
                    'tag_settings'  => [ 'type' => 'object' ],
                ],
                'required'   => [ 'post_id', 'expected_hash', 'element_id', 'setting_key', 'tag_name' ],
            ],
            [$set_dynamic_tag, 'handle'],
            'edit_posts',
            'elementor',
            'update'
        ));

        $add_custom_css = new Add_Custom_Css();

        $registrar->register(new Ability(
            'wpmcp/add-custom-css',
            'pro',
            'Add site-wide custom CSS through WordPress core Additional CSS, so it works on any site (Elementor Pro not required). Appends by default, or replaces with replace=true. Snapshot-first when the Additional-CSS post exists, so it is undoable via rollback-operation',
            [
                'type'       => 'object',
                'properties' => [
                    'css'     => [ 'type' => 'string' ],
                    'replace' => [ 'type' => 'boolean' ],
                ],
                'required'   => [ 'css' ],
            ],
            [$add_custom_css, 'handle'],
            'manage_options',
            'elementor',
            'update'
        ));

        $get_custom_css = new Get_Custom_Css();

        $registrar->register(new Ability(
            'wpmcp/get-custom-css',
            'pro',
            'Read the site\'s WordPress core Additional CSS. Read-only',
            [
                'type'       => 'object',
                'properties' => [],
            ],
            [$get_custom_css, 'handle'],
            'manage_options',
            'elementor',
            'read'
        ));

        $create_code_snippet = new Create_Code_Snippet();

        $registrar->register(new Ability(
            'wpmcp/create-code-snippet',
            'pro',
            'Create an Elementor Custom Code snippet (an elementor_snippet post with _elementor_code / _elementor_location / _elementor_priority). location is wp_head, wp_body_open, or wp_footer. Stored on any site; renders where Elementor Pro Custom Code is active. Not snapshotted (a create destroys nothing); remove with delete-code-snippet',
            [
                'type'       => 'object',
                'properties' => [
                    'title'    => [ 'type' => 'string' ],
                    'code'     => [ 'type' => 'string' ],
                    'location' => [ 'type' => 'string' ],
                    'priority' => [ 'type' => 'integer' ],
                    'status'   => [ 'type' => 'string' ],
                ],
                'required'   => [ 'code' ],
            ],
            [$create_code_snippet, 'handle'],
            'manage_options',
            'elementor',
            'create'
        ));

        $list_code_snippets = new List_Code_Snippets();

        $registrar->register(new Ability(
            'wpmcp/list-code-snippets',
            'pro',
            'List the Elementor Custom Code snippets stored on this site (elementor_snippet posts) with their location, priority, and code. Read-only',
            [
                'type'       => 'object',
                'properties' => [],
            ],
            [$list_code_snippets, 'handle'],
            'manage_options',
            'elementor',
            'read'
        ));

        $delete_code_snippet = new Delete_Code_Snippet();

        $registrar->register(new Ability(
            'wpmcp/delete-code-snippet',
            'pro',
            'Delete an Elementor Custom Code snippet by moving it to the trash (reversible through WordPress trash / restore-post), mirroring the default delete-post path',
            [
                'type'       => 'object',
                'properties' => [
                    'snippet_id' => [ 'type' => 'integer' ],
                ],
                'required'   => [ 'snippet_id' ],
            ],
            [$delete_code_snippet, 'handle'],
            'manage_options',
            'elementor',
            'delete'
        ));

        $this->register_elementor_structural_abilities($registrar);
        $this->register_brand_kit_abilities($registrar);
    }

    /**
     * Register the brand-kit tools as pro-tier abilities (issue #75).
     *
     * A brand kit is a named design system (four system colors, extra named
     * swatches, the four system typography tokens, an optional logo
     * reference) stored as data: bundled presets in Brand_Kit_Store plus
     * anything a site adds through the `wpmcp_brand_kits` option or filter.
     * Growing the library therefore never grows the advertised tool surface
     * — pinned by tests/free/Platform/ToolsListBudgetTest.php.
     *
     * apply-brand-kit folds the entire kit into ONE
     * `_elementor_page_settings` patch handed to Elementor_Kit_Data::write(),
     * so a whole rebrand is a single snapshot and a single operation_id
     * rather than one operation per token type; rollback-brand-kit undoes
     * that operation without the agent needing to have kept the id. The
     * write is doubly gated: without confirm:true the tool returns the exact
     * diff and writes nothing, and with it the shared kit guard still
     * requires a fresh expected_hash.
     */
    private function register_brand_kit_abilities(Registrar $registrar): void
    {
        $list_brand_kits = new List_Brand_Kits();

        $registrar->register(new Ability(
            'wpmcp/list-brand-kits',
            'pro',
            'List the brand kits available on this site (bundled presets plus any added via the wpmcp_brand_kits option or filter): slug, category, source, the four system colors, distinct font families, logo flag. Filter by category, source (bundled/site) or a search over slug/title/description. Read-only',
            [
                'type'       => 'object',
                'properties' => [
                    'search'   => [ 'type' => 'string' ],
                    'category' => [ 'type' => 'string' ],
                    'source'   => [ 'type' => 'string', 'enum' => [ 'bundled', 'site' ] ],
                ],
            ],
            [$list_brand_kits, 'handle'],
            'edit_posts',
            'elementor',
            'read'
        ));

        $get_brand_kit = new Get_Brand_Kit();

        $registrar->register(new Ability(
            'wpmcp/get-brand-kit',
            'pro',
            'Return one brand kit\'s full definition in the shape apply-brand-kit writes: system slots as hex, named swatches with their _id, typography already mapped to Elementor typography_* fields. Entries that failed validation are listed in "invalid", which makes the kit unappliable. Read-only',
            [
                'type'       => 'object',
                'properties' => [
                    'slug' => [ 'type' => 'string' ],
                ],
                'required'   => [ 'slug' ],
            ],
            [$get_brand_kit, 'handle'],
            'edit_posts',
            'elementor',
            'read'
        ));

        $apply_brand_kit = new Apply_Brand_Kit();

        $registrar->register(new Ability(
            'wpmcp/apply-brand-kit',
            'pro',
            'Apply a brand kit to the active Elementor kit (system colors, named swatches, the four system typography tokens, logo) as ONE snapshotted operation, so the whole rebrand undoes in a single rollback. Without confirm:true nothing is written and the exact per-token diff plus the settings_hash to pass back is returned; with it, expected_hash must still match. A kit with any invalid entry is refused, never partly applied. Undoable via rollback-brand-kit or rollback-operation',
            [
                'type'       => 'object',
                'properties' => [
                    'slug'          => [ 'type' => 'string' ],
                    'confirm'       => [ 'type' => 'boolean' ],
                    'expected_hash' => [ 'type' => 'string' ],
                    'include_logo'  => [ 'type' => 'boolean' ],
                ],
                'required'   => [ 'slug' ],
            ],
            [$apply_brand_kit, 'handle'],
            'manage_options',
            'elementor',
            'update'
        ));

        $rollback_brand_kit = new Rollback_Brand_Kit();

        $registrar->register(new Ability(
            'wpmcp/rollback-brand-kit',
            'pro',
            'Undo a brand-kit apply by restoring the single snapshot it took, putting palette, swatches, typography and logo back as they were. Defaults to the most recent apply not already rolled back, so the operation_id need not be remembered; pass operation_id to target a specific apply',
            [
                'type'       => 'object',
                'properties' => [
                    'operation_id' => [ 'type' => 'string' ],
                ],
            ],
            [$rollback_brand_kit, 'handle'],
            'manage_options',
            'elementor',
            'update'
        ));
    }

    /**
     * Register the Elementor v4 global class (Class Manager) write suite
     * (issue #132).
     *
     * The four tools share Global_Classes_Store: reads and writes go through
     * Elementor's own global classes repository, so they keep working across
     * the 4.2 storage change (classes moved from the kit's
     * `_elementor_global_classes` meta into their own post type). Every write
     * requires expected_hash — the whole items map is rewritten on each call,
     * so without an optimistic lock a stale caller would silently drop a class
     * another agent just added — and is snapshotted as a dedicated
     * 'elementor_global_classes' operation holding the COMPLETE prior class
     * set, which is what makes rollback-operation able to resurrect a deleted
     * class rather than merely un-edit a surviving one.
     *
     * Styles are authored as friendly flat keys, converted to typed v4 props,
     * and validated against Elementor's own Style_Schema before the write;
     * a style key Elementor would silently discard fails the call instead.
     * The tools register unconditionally (like every other Elementor tool
     * here, so the advertised surface does not depend on which Elementor is
     * installed) and refuse at execution time with a clear 'unsupported'
     * error when the v4 class manager is absent.
     */
    private function register_global_class_write_abilities(Registrar $registrar): void
    {
        $styles_schema = [
            'type'        => 'object',
            'description' => 'Friendly flat styles mapped to Elementor v4 props: '
                . implode(', ', Global_Class_Schema::style_keys())
                . '. Each size accepts a <key>_unit (default px); colors are hex.',
        ];
        $props_schema = [
            'type'        => 'object',
            'description' => 'Raw escape hatch: CSS property => $$type-wrapped value, e.g. '
                . '{"box-shadow":{"$$type":"box-shadow","value":[]}}. Merged over the built styles and validated the same way.',
        ];
        $breakpoint_schema = [
            'type'        => 'string',
            'enum'        => Global_Class_Schema::BREAKPOINTS,
            'description' => 'Breakpoint this variant targets (default desktop).',
        ];
        $state_schema = [
            'type'        => 'string',
            'description' => 'Optional state for this variant (hover, active, focus, focus-visible, checked, e--selected, e--disabled). Omit for the normal state.',
        ];

        $create_global_class = new Create_Global_Class();

        $registrar->register(new Ability(
            'wpmcp/create-global-class',
            'pro',
            'Create an Elementor v4 global CSS class (Class Manager) with a label and styles, returning the new g- id to apply to elements. Pass friendly "styles" and/or raw $$type "props" plus an optional breakpoint/state; styles are validated against Elementor\'s own style schema, so a property Elementor would silently drop is refused instead. Requires expected_hash from list-global-classes. Snapshotted, so rollback-operation removes the class again',
            [
                'type'       => 'object',
                'properties' => [
                    'expected_hash' => [ 'type' => 'string' ],
                    'label'         => [ 'type' => 'string' ],
                    'styles'        => $styles_schema,
                    'props'         => $props_schema,
                    'breakpoint'    => $breakpoint_schema,
                    'state'         => $state_schema,
                ],
                'required'   => [ 'expected_hash', 'label' ],
            ],
            [$create_global_class, 'handle'],
            'manage_options',
            'elementor',
            'create'
        ));

        $update_global_class = new Update_Global_Class();

        $registrar->register(new Ability(
            'wpmcp/update-global-class',
            'pro',
            'Update an Elementor v4 global class by g- id: rename it and/or merge styles into the variant for one breakpoint + state (replace_variant:true replaces that variant instead of merging), so an unrelated responsive or hover rule survives. Requires expected_hash from list-global-classes. Snapshotted and undoable via rollback-operation',
            [
                'type'       => 'object',
                'properties' => [
                    'expected_hash'   => [ 'type' => 'string' ],
                    'id'              => [ 'type' => 'string' ],
                    'label'           => [ 'type' => 'string' ],
                    'styles'          => $styles_schema,
                    'props'           => $props_schema,
                    'breakpoint'      => $breakpoint_schema,
                    'state'           => $state_schema,
                    'replace_variant' => [ 'type' => 'boolean' ],
                ],
                'required'   => [ 'expected_hash', 'id' ],
            ],
            [$update_global_class, 'handle'],
            'manage_options',
            'elementor',
            'update'
        ));

        $delete_global_class = new Delete_Global_Class();

        $registrar->register(new Ability(
            'wpmcp/delete-global-class',
            'pro',
            'Delete an Elementor v4 global class by g- id. Without confirm:true this is a dry run that scans _elementor_data and reports every post applying the class, so the blast radius is known before committing; with confirm:true the class is deleted after the whole class set is snapshotted, so rollback-operation resurrects it. Requires expected_hash from list-global-classes',
            [
                'type'       => 'object',
                'properties' => [
                    'expected_hash' => [ 'type' => 'string' ],
                    'id'            => [ 'type' => 'string' ],
                    'confirm'       => [ 'type' => 'boolean' ],
                ],
                'required'   => [ 'expected_hash', 'id' ],
            ],
            [$delete_global_class, 'handle'],
            'manage_options',
            'elementor',
            'delete'
        ));

        $reorder_global_classes = new Reorder_Global_Classes();

        $registrar->register(new Ability(
            'wpmcp/reorder-global-classes',
            'pro',
            'Set the order of the Elementor v4 global classes. Class Manager order IS the CSS source order, so it decides which class wins when two apply at equal specificity. Pass { order: [g-id, ...] }; classes you omit are appended after in their current relative order (append-never-drop) and an unknown id is refused. Requires expected_hash from list-global-classes. Snapshotted and undoable',
            [
                'type'       => 'object',
                'properties' => [
                    'expected_hash' => [ 'type' => 'string' ],
                    'order'         => [
                        'type'  => 'array',
                        'items' => [ 'type' => 'string' ],
                    ],
                ],
                'required'   => [ 'expected_hash', 'order' ],
            ],
            [$reorder_global_classes, 'handle'],
            'manage_options',
            'elementor',
            'update'
        ));
    }

    /**
     * Register the Elementor structural editing suite as pro-tier abilities
     * (issue #58).
     *
     * All eight tools share the Element_Tree engine: mutations require
     * expected_hash (sha256 of the raw _elementor_data JSON, or of the
     * JSON-encoded page settings for update-page-settings, both reported by
     * get-elementor-data) so a stale read is a structured refusal with no
     * partial write; writes route through Elementor's own Document::save()
     * when available (canonical data, Post_CSS regeneration, document cache
     * invalidation) with a raw-meta fallback that clears the generated-CSS
     * cache explicitly; and every write is snapshot-first with a verify
     * step, so any failure — including any single entry of a batch-update —
     * rolls the whole operation back and every success is undoable via
     * rollback-operation. find-element is the one read-only tool in the
     * suite and never touches the safety core.
     */
    private function register_elementor_structural_abilities(Registrar $registrar): void
    {
        $add_container = new Add_Container();

        $registrar->register(new Ability(
            'wpmcp/add-container',
            'pro',
            'Create an Elementor layout element (container by default, or section/column) at the top level or nested under parent_id, at an optional position among its siblings. Columns require a parent; widgets are never valid parents. Requires expected_hash from get-elementor-data (stale reads are refused with no partial write). Undoable via rollback-operation',
            [
                'type'       => 'object',
                'properties' => [
                    'post_id'       => [ 'type' => 'integer' ],
                    'expected_hash' => [ 'type' => 'string' ],
                    'parent_id'     => [ 'type' => 'string' ],
                    'el_type'       => [ 'type' => 'string', 'enum' => [ 'container', 'section', 'column' ] ],
                    'settings'      => [ 'type' => 'object' ],
                    'position'      => [ 'type' => 'integer' ],
                ],
                'required'   => [ 'post_id', 'expected_hash' ],
            ],
            [$add_container, 'handle'],
            'edit_posts',
            'elementor',
            'create'
        ));

        $update_container = new Update_Container();

        $registrar->register(new Ability(
            'wpmcp/update-container',
            'pro',
            'Merge settings non-destructively into an Elementor layout element (container, section, or column) by id: given keys are overwritten or added, all other settings survive. Widgets are refused (use update-element). Requires expected_hash from get-elementor-data. Undoable via rollback-operation',
            [
                'type'       => 'object',
                'properties' => [
                    'post_id'       => [ 'type' => 'integer' ],
                    'expected_hash' => [ 'type' => 'string' ],
                    'element_id'    => [ 'type' => 'string' ],
                    'settings'      => [ 'type' => 'object' ],
                ],
                'required'   => [ 'post_id', 'expected_hash', 'element_id', 'settings' ],
            ],
            [$update_container, 'handle'],
            'edit_posts',
            'elementor',
            'update'
        ));

        $batch_update = new Batch_Update();

        $registrar->register(new Ability(
            'wpmcp/batch-update',
            'pro',
            'Apply N Elementor element settings updates atomically under ONE snapshot: every {element_id, settings} entry is validated before anything is written, one unknown id refuses the whole batch, and any failure rolls the entire batch back. Requires expected_hash from get-elementor-data. Undoable as a single rollback-operation',
            [
                'type'       => 'object',
                'properties' => [
                    'post_id'       => [ 'type' => 'integer' ],
                    'expected_hash' => [ 'type' => 'string' ],
                    'updates'       => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'element_id' => [ 'type' => 'string' ],
                                'settings'   => [ 'type' => 'object' ],
                            ],
                            'required'   => [ 'element_id', 'settings' ],
                        ],
                    ],
                ],
                'required'   => [ 'post_id', 'expected_hash', 'updates' ],
            ],
            [$batch_update, 'handle'],
            'edit_posts',
            'elementor',
            'update'
        ));

        $reorder_elements = new Reorder_Elements();

        $registrar->register(new Ability(
            'wpmcp/reorder-elements',
            'pro',
            'Reorder the children of one Elementor parent element (or the top level when parent_id is omitted) to an explicit id order. The order must be an exact permutation of the current children; anything else is refused before any write. Requires expected_hash from get-elementor-data. Undoable via rollback-operation',
            [
                'type'       => 'object',
                'properties' => [
                    'post_id'       => [ 'type' => 'integer' ],
                    'expected_hash' => [ 'type' => 'string' ],
                    'parent_id'     => [ 'type' => 'string' ],
                    'order'         => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                ],
                'required'   => [ 'post_id', 'expected_hash', 'order' ],
            ],
            [$reorder_elements, 'handle'],
            'edit_posts',
            'elementor',
            'update'
        ));

        $duplicate_element = new Duplicate_Element();

        $registrar->register(new Ability(
            'wpmcp/duplicate-element',
            'pro',
            'Deep-copy an Elementor element (and its whole subtree) with recursively regenerated ids, inserted immediately after the original among its siblings. Fresh ids use Elementor\'s 7-char hex format and are checked against every id on the page, so the builder opens the result without warnings. Requires expected_hash from get-elementor-data. Undoable via rollback-operation',
            [
                'type'       => 'object',
                'properties' => [
                    'post_id'       => [ 'type' => 'integer' ],
                    'expected_hash' => [ 'type' => 'string' ],
                    'element_id'    => [ 'type' => 'string' ],
                ],
                'required'   => [ 'post_id', 'expected_hash', 'element_id' ],
            ],
            [$duplicate_element, 'handle'],
            'edit_posts',
            'elementor',
            'create'
        ));

        $set_element_label = new Set_Element_Label();

        $registrar->register(new Ability(
            'wpmcp/set-element-label',
            'pro',
            'Set an Elementor element\'s navigator label (stored as the _title setting); an empty label clears the custom name. All other settings survive untouched. Requires expected_hash from get-elementor-data. Undoable via rollback-operation',
            [
                'type'       => 'object',
                'properties' => [
                    'post_id'       => [ 'type' => 'integer' ],
                    'expected_hash' => [ 'type' => 'string' ],
                    'element_id'    => [ 'type' => 'string' ],
                    'label'         => [ 'type' => 'string' ],
                ],
                'required'   => [ 'post_id', 'expected_hash', 'element_id', 'label' ],
            ],
            [$set_element_label, 'handle'],
            'edit_posts',
            'elementor',
            'update'
        ));

        $find_element = new Find_Element();

        $registrar->register(new Ability(
            'wpmcp/find-element',
            'pro',
            'Search a page\'s Elementor element tree by el_type, widget_type, setting_key + setting_value, and/or css_class token (criteria AND-combined; at least one required). Each match reports element_id, types, navigator label, and ancestor id path; the response carries the current data_hash so a structural mutation can be chained without a second read. Read-only',
            [
                'type'       => 'object',
                'properties' => [
                    'post_id'       => [ 'type' => 'integer' ],
                    'el_type'       => [ 'type' => 'string' ],
                    'widget_type'   => [ 'type' => 'string' ],
                    'setting_key'   => [ 'type' => 'string' ],
                    'setting_value' => [ 'type' => 'string' ],
                    'css_class'     => [ 'type' => 'string' ],
                ],
                'required'   => [ 'post_id' ],
            ],
            [$find_element, 'handle'],
            'edit_posts',
            'elementor',
            'read'
        ));

        $update_page_settings = new Update_Page_Settings();

        $registrar->register(new Ability(
            'wpmcp/update-page-settings',
            'pro',
            'Merge settings non-destructively into a page\'s Elementor page settings (_elementor_page_settings): given keys are overwritten or added, all other settings survive. Post field keys (post_title, post_status, template, ...) are refused, use the post tools. Requires expected_hash = the settings_hash from get-elementor-data. Undoable via rollback-operation',
            [
                'type'       => 'object',
                'properties' => [
                    'post_id'       => [ 'type' => 'integer' ],
                    'expected_hash' => [ 'type' => 'string' ],
                    'settings'      => [ 'type' => 'object' ],
                ],
                'required'   => [ 'post_id', 'expected_hash', 'settings' ],
            ],
            [$update_page_settings, 'handle'],
            'edit_posts',
            'elementor',
            'update'
        ));
    }

    /**
     * Register the WooCommerce store tools as free-tier abilities.
     *
     * These are registered unconditionally (matching every other tool group):
     * a caller only reaches a handler by invoking the ability, and each handler
     * uses WooCommerce functions that are present whenever WooCommerce is
     * active. Product mutations reuse the existing 'post' snapshot object type
     * (a product is a 'product' post), and update-order-status uses the
     * additive 'wc_order' snapshot type, so both are undoable through the same
     * engine. Writes require manage_woocommerce; order writes require
     * edit_shop_orders. The destructive delete-product tool is disabled by
     * default behind the wpmcp_enable_delete_product filter and needs confirm.
     */
    private function register_woocommerce_abilities(Registrar $registrar): void
    {
        $list_products           = new List_Products();
        $get_product             = new Get_Product();
        $create_product          = new Create_Product();
        $update_product          = new Update_Product();
        $delete_product          = new Delete_Product();
        $list_product_categories = new List_Product_Categories();
        $list_orders             = new List_Orders();
        $get_order               = new Get_Order();
        $update_order_status     = new Update_Order_Status();
        $add_order_note          = new Add_Order_Note();
        $get_sales_report        = new Get_Sales_Report();

        $registrar->register(new Ability(
            'wpmcp/list-products',
            'free',
            'List WooCommerce products as safe summary rows (id, name, sku, price, stock status), filterable by search, status, type, or category, with paging',
            [
                'type'       => 'object',
                'properties' => [
                    'search'   => [ 'type' => 'string' ],
                    'status'   => [ 'type' => 'string' ],
                    'type'     => [ 'type' => 'string' ],
                    'category' => [ 'type' => 'string' ],
                    'per_page' => [ 'type' => 'integer' ],
                    'page'     => [ 'type' => 'integer' ],
                ],
            ],
            [$list_products, 'handle'],
            'manage_woocommerce',
            'woocommerce',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/get-product',
            'free',
            'Read full detail for one WooCommerce product (prices, stock, description, categories, tags)',
            [
                'type'       => 'object',
                'properties' => [
                    'id' => [ 'type' => 'integer' ],
                ],
                'required'   => [ 'id' ],
            ],
            [$get_product, 'handle'],
            'manage_woocommerce',
            'woocommerce',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/create-product',
            'free',
            'Create a simple WooCommerce product via the CRUD layer. Creation has no prior state to snapshot; a mistaken product can be removed with delete-product',
            [
                'type'       => 'object',
                'properties' => [
                    'name'              => [ 'type' => 'string' ],
                    'regular_price'     => [ 'type' => 'string' ],
                    'sale_price'        => [ 'type' => 'string' ],
                    'sku'               => [ 'type' => 'string' ],
                    'description'       => [ 'type' => 'string' ],
                    'short_description' => [ 'type' => 'string' ],
                    'status'            => [ 'type' => 'string' ],
                    'manage_stock'      => [ 'type' => 'boolean' ],
                    'stock_quantity'    => [ 'type' => 'integer' ],
                ],
                'required'   => [ 'name' ],
            ],
            [$create_product, 'handle'],
            'manage_woocommerce',
            'woocommerce',
            'create'
        ));
        $registrar->register(new Ability(
            'wpmcp/update-product',
            'free',
            'Update a WooCommerce product\'s fields (price, stock, description, etc.). A product is a post, so this is snapshotted via object_type post and rollback-operation restores the prior price and stock exactly',
            [
                'type'       => 'object',
                'properties' => [
                    'id'                => [ 'type' => 'integer' ],
                    'name'              => [ 'type' => 'string' ],
                    'regular_price'     => [ 'type' => 'string' ],
                    'sale_price'        => [ 'type' => 'string' ],
                    'sku'               => [ 'type' => 'string' ],
                    'description'       => [ 'type' => 'string' ],
                    'short_description' => [ 'type' => 'string' ],
                    'status'            => [ 'type' => 'string' ],
                    'manage_stock'      => [ 'type' => 'boolean' ],
                    'stock_quantity'    => [ 'type' => 'integer' ],
                    'session_id'        => [ 'type' => 'string' ],
                ],
                'required'   => [ 'id' ],
            ],
            [$update_product, 'handle'],
            'manage_woocommerce',
            'woocommerce',
            'update'
        ));
        $registrar->register(new Ability(
            'wpmcp/delete-product',
            'free',
            'Delete a WooCommerce product (trash by default, force for permanent). Disabled by default (site must opt in via the wpmcp_enable_delete_product filter) and requires confirm:true. Snapshotted so it can be rolled back: force-delete resurrects the product at its original id with its price, stock, and terms',
            [
                'type'       => 'object',
                'properties' => [
                    'id'         => [ 'type' => 'integer' ],
                    'confirm'    => [ 'type' => 'boolean' ],
                    'force'      => [ 'type' => 'boolean' ],
                    'session_id' => [ 'type' => 'string' ],
                ],
                'required'   => [ 'id', 'confirm' ],
            ],
            [$delete_product, 'handle'],
            'manage_woocommerce',
            'woocommerce',
            'delete'
        ));
        $registrar->register(new Ability(
            'wpmcp/list-product-categories',
            'free',
            'List WooCommerce product categories (the product_cat taxonomy) as summary rows (id, name, slug, parent, count)',
            [
                'type'       => 'object',
                'properties' => [
                    'hide_empty' => [ 'type' => 'boolean' ],
                ],
            ],
            [$list_product_categories, 'handle'],
            'manage_woocommerce',
            'woocommerce',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/list-orders',
            'free',
            'List WooCommerce orders as safe summary rows (id, number, status, total, currency, date), filterable by status and customer, with paging. HPOS- and CPT-safe',
            [
                'type'       => 'object',
                'properties' => [
                    'status'      => [ 'type' => 'string' ],
                    'customer_id' => [ 'type' => 'integer' ],
                    'per_page'    => [ 'type' => 'integer' ],
                    'page'        => [ 'type' => 'integer' ],
                ],
            ],
            [$list_orders, 'handle'],
            'edit_shop_orders',
            'woocommerce',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/get-order',
            'free',
            'Read full detail for one WooCommerce order (status, billing email, payment method, line items, customer note). HPOS- and CPT-safe',
            [
                'type'       => 'object',
                'properties' => [
                    'id' => [ 'type' => 'integer' ],
                ],
                'required'   => [ 'id' ],
            ],
            [$get_order, 'handle'],
            'edit_shop_orders',
            'woocommerce',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/update-order-status',
            'free',
            'Change a WooCommerce order\'s status, validated against the store\'s registered statuses. Snapshotted via the wc_order object type so rollback-operation restores the prior status exactly. HPOS- and CPT-safe',
            [
                'type'       => 'object',
                'properties' => [
                    'id'         => [ 'type' => 'integer' ],
                    'status'     => [ 'type' => 'string' ],
                    'session_id' => [ 'type' => 'string' ],
                ],
                'required'   => [ 'id', 'status' ],
            ],
            [$update_order_status, 'handle'],
            'edit_shop_orders',
            'woocommerce',
            'update'
        ));
        $registrar->register(new Ability(
            'wpmcp/add-order-note',
            'free',
            'Add an internal or customer-facing note to a WooCommerce order. Additive only; nothing to roll back',
            [
                'type'       => 'object',
                'properties' => [
                    'id'            => [ 'type' => 'integer' ],
                    'note'          => [ 'type' => 'string' ],
                    'customer_note' => [ 'type' => 'boolean' ],
                ],
                'required'   => [ 'id', 'note' ],
            ],
            [$add_order_note, 'handle'],
            'edit_shop_orders',
            'woocommerce',
            'create'
        ));
        $registrar->register(new Ability(
            'wpmcp/get-sales-report',
            'free',
            'Read-only sales summary over a date range: order count, gross sales, items sold, and top products by quantity. Aggregated over wc_get_orders() (HPOS- and CPT-safe)',
            [
                'type'       => 'object',
                'properties' => [
                    'date_from' => [ 'type' => 'string' ],
                    'date_to'   => [ 'type' => 'string' ],
                ],
            ],
            [$get_sales_report, 'handle'],
            'manage_woocommerce',
            'woocommerce',
            'read'
        ));
    }

    /**
     * Register the navigation menu management tools as free-tier abilities.
     *
     * All require the edit_theme_options capability, WordPress's own gate for
     * managing menus. Reads (list/get menus, list locations) have no side
     * effects. Menu-item edits act on nav_menu_item posts and are undoable via
     * the existing 'post' snapshot type; assign-menu-to-location changes the
     * nav_menu_locations theme_mod and is undoable via the existing 'option'
     * type. delete-menu removes a nav_menu term: it is disabled by default
     * behind the wpmcp_enable_delete_menu filter, needs confirm, and is honest
     * that it cannot be rolled back automatically.
     */
    /**
     * One-call declarative page composition (issue #57). Registered free:
     * the Gutenberg dialect is the free tier's builder; the Elementor
     * builder dialect is gated PRO inside the handler via Pro\Gate, so the
     * one ability serves both tiers with the gate re-checked per call.
     */
    private function register_compose_abilities(Registrar $registrar): void
    {
        $build_page = new \WPMCP\Tools\Compose\Build_Page();

        $node_schema = [
            'type'        => 'object',
            'description' => 'One node of the recursive sections tree. Gutenberg dialect types: group, columns, column, buttons (containers, may have children); heading{text,level}, paragraph{text}, list{items,ordered}, quote{text,citation}, image{attachment_id|url,alt}, button{text,url}, separator, spacer{height}, code{text}, html{html}, pattern{slug, top-level only} (leaves). Elementor dialect types: container, section, column (containers; settings passed to the element verbatim); widget{widget,widget_settings} (leaf).',
            'properties'  => [
                'type'     => [ 'type' => 'string' ],
                'settings' => [ 'type' => 'object' ],
                'children' => [ 'type' => 'array', 'items' => [ '$ref' => '#/properties/spec/properties/content/items' ] ],
            ],
            'required'    => [ 'type' ],
        ];

        $registrar->register(new Ability(
            'wpmcp/build-page',
            'free',
            'Compose a complete page from ONE declarative spec: title, a recursive sections/blocks tree, media references (existing attachment ids), and optional menu placement. The whole composition is a single atomic, recoverable operation: the spec is strictly validated (node-path-addressed errors, bounded size/nodes/depth) before any write, a mid-build failure automatically removes everything it created, and on success one operation_id is returned whose rollback-operation removes the page and its menu placement entirely. Markup is composed deterministically from the spec; nothing in the spec is evaluated or executed. dialect "gutenberg" (default, free) builds block markup; dialect "elementor" (PRO, requires Elementor) builds an _elementor_data element tree. Set dry_run=true to validate and compose WITHOUT writing: the reply reports element counts per node type, nesting depth, markup size, unknown widget types, atomic props that had to be coerced, and every referential problem at once',
            [
                'type'       => 'object',
                'properties' => [
                    'spec' => [
                        'type'       => 'object',
                        'properties' => [
                            'title'   => [ 'type' => 'string' ],
                            'status'  => [ 'type' => 'string', 'enum' => [ 'draft', 'publish' ] ],
                            'slug'    => [ 'type' => 'string' ],
                            'dialect' => [ 'type' => 'string', 'enum' => [ 'gutenberg', 'elementor' ] ],
                            'content' => [ 'type' => 'array', 'items' => $node_schema ],
                            'media'   => [
                                'type'       => 'object',
                                'properties' => [ 'featured' => [ 'type' => 'integer' ] ],
                            ],
                            'menu'    => [
                                'type'       => 'object',
                                'properties' => [
                                    'menu_id'  => [ 'type' => 'integer' ],
                                    'title'    => [ 'type' => 'string' ],
                                    'position' => [ 'type' => 'integer' ],
                                    'parent'   => [ 'type' => 'integer' ],
                                ],
                                'required'   => [ 'menu_id' ],
                            ],
                        ],
                        'required'   => [ 'title', 'content' ],
                    ],
                    'dry_run'    => [ 'type' => 'boolean' ],
                    'session_id' => [ 'type' => 'string' ],
                ],
                'required'   => [ 'spec' ],
            ],
            [$build_page, 'handle'],
            'edit_posts',
            'content',
            'create'
        ));
    }

    private function register_menu_abilities(Registrar $registrar): void
    {
        $list_menus              = new List_Menus();
        $get_menu                = new Get_Menu();
        $list_menu_locations     = new List_Menu_Locations();
        $create_menu             = new Create_Menu();
        $add_menu_item           = new Add_Menu_Item();
        $update_menu_item        = new Update_Menu_Item();
        $remove_menu_item        = new Remove_Menu_Item();
        $assign_menu_to_location = new Assign_Menu_To_Location();
        $delete_menu             = new Delete_Menu();

        $registrar->register(new Ability(
            'wpmcp/list-menus',
            'free',
            'List the site\'s navigation menus as safe summary rows (id, name, slug, item count)',
            [
                'type'       => 'object',
                'properties' => [],
            ],
            [$list_menus, 'handle'],
            'edit_theme_options',
            'menus',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/get-menu',
            'free',
            'Read one navigation menu with its ordered items (id, title, url, type, parent, order)',
            [
                'type'       => 'object',
                'properties' => [
                    'id' => [ 'type' => 'integer' ],
                ],
                'required'   => [ 'id' ],
            ],
            [$get_menu, 'handle'],
            'edit_theme_options',
            'menus',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/list-menu-locations',
            'free',
            'List the theme\'s registered menu locations and the menu (if any) assigned to each',
            [
                'type'       => 'object',
                'properties' => [],
            ],
            [$list_menu_locations, 'handle'],
            'edit_theme_options',
            'menus',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/create-menu',
            'free',
            'Create a new navigation menu (a nav_menu term). Creation has no prior state to snapshot; a mistaken menu can be removed with delete-menu',
            [
                'type'       => 'object',
                'properties' => [
                    'name' => [ 'type' => 'string' ],
                ],
                'required'   => [ 'name' ],
            ],
            [$create_menu, 'handle'],
            'edit_theme_options',
            'menus',
            'create'
        ));
        $registrar->register(new Ability(
            'wpmcp/add-menu-item',
            'free',
            'Add an item to a navigation menu (custom link by title and url, or an object link via type, object, object_id). Additive; a mistaken item can be removed with remove-menu-item',
            [
                'type'       => 'object',
                'properties' => [
                    'menu_id'   => [ 'type' => 'integer' ],
                    'title'     => [ 'type' => 'string' ],
                    'url'       => [ 'type' => 'string' ],
                    'parent'    => [ 'type' => 'integer' ],
                    'position'  => [ 'type' => 'integer' ],
                    'type'      => [ 'type' => 'string' ],
                    'object'    => [ 'type' => 'string' ],
                    'object_id' => [ 'type' => 'integer' ],
                ],
                'required'   => [ 'menu_id' ],
            ],
            [$add_menu_item, 'handle'],
            'edit_theme_options',
            'menus',
            'create'
        ));
        $registrar->register(new Ability(
            'wpmcp/update-menu-item',
            'free',
            'Update a navigation menu item\'s title, url, parent, or position. A menu item is a post, so this is snapshotted via object_type post and rollback-operation restores the prior values exactly',
            [
                'type'       => 'object',
                'properties' => [
                    'item_id'    => [ 'type' => 'integer' ],
                    'title'      => [ 'type' => 'string' ],
                    'url'        => [ 'type' => 'string' ],
                    'parent'     => [ 'type' => 'integer' ],
                    'position'   => [ 'type' => 'integer' ],
                    'session_id' => [ 'type' => 'string' ],
                ],
                'required'   => [ 'item_id' ],
            ],
            [$update_menu_item, 'handle'],
            'edit_theme_options',
            'menus',
            'update'
        ));
        $registrar->register(new Ability(
            'wpmcp/remove-menu-item',
            'free',
            'Remove an item from a navigation menu. The item is a post, so this is snapshotted via object_type post and rollback-operation resurrects it at its original id, re-attached to its menu',
            [
                'type'       => 'object',
                'properties' => [
                    'item_id'    => [ 'type' => 'integer' ],
                    'session_id' => [ 'type' => 'string' ],
                ],
                'required'   => [ 'item_id' ],
            ],
            [$remove_menu_item, 'handle'],
            'edit_theme_options',
            'menus',
            'delete'
        ));
        $registrar->register(new Ability(
            'wpmcp/assign-menu-to-location',
            'free',
            'Assign a navigation menu to a registered theme location. The assignment lives in the nav_menu_locations theme_mod, so this is snapshotted via object_type option and rollback-operation restores the prior assignment',
            [
                'type'       => 'object',
                'properties' => [
                    'menu_id'    => [ 'type' => 'integer' ],
                    'location'   => [ 'type' => 'string' ],
                    'session_id' => [ 'type' => 'string' ],
                ],
                'required'   => [ 'menu_id', 'location' ],
            ],
            [$assign_menu_to_location, 'handle'],
            'edit_theme_options',
            'menus',
            'update'
        ));
        $registrar->register(new Ability(
            'wpmcp/delete-menu',
            'free',
            'Delete a navigation menu (a nav_menu term). Disabled by default (site must opt in via the wpmcp_enable_delete_menu filter) and requires confirm:true. This is not automatically reversible: the menu name and its items are returned so it can be rebuilt manually',
            [
                'type'       => 'object',
                'properties' => [
                    'id'      => [ 'type' => 'integer' ],
                    'confirm' => [ 'type' => 'boolean' ],
                ],
                'required'   => [ 'id', 'confirm' ],
            ],
            [$delete_menu, 'handle'],
            'edit_theme_options',
            'menus',
            'delete'
        ));
    }

    /**
     * Register the ACF (Advanced Custom Fields) tools as free-tier abilities.
     *
     * Registered conditionally, gated on function_exists('acf_get_field_groups'),
     * unlike WooCommerce and Elementor's tool groups (which register
     * unconditionally and degrade at call time): ACF has no free/pro split of
     * its own to key off, so absence of the plugin is the only signal, and
     * skipping registration entirely keeps these abilities out of the
     * catalog on sites that don't run ACF at all.
     *
     * update-fields is disabled by default via the wpmcp_enable_acf_write
     * filter (checked inside Update_Fields::handle()); the ability itself is
     * still registered so a caller can discover it and see why it refuses.
     */
    private function register_acf_abilities(Registrar $registrar): void
    {
        if (! function_exists('acf_get_field_groups')) {
            return;
        }

        $list_field_groups = new List_Field_Groups();
        $get_fields        = new Get_Fields();
        $update_fields     = new Update_Fields();

        $registrar->register(new Ability(
            'wpmcp/list-field-groups',
            'free',
            'List registered ACF (Advanced Custom Fields) field groups: key, title, a flattened summary of their location rules, and whether each is active',
            [
                'type'       => 'object',
                'properties' => [],
            ],
            [$list_field_groups, 'handle'],
            'edit_posts',
            'acf',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/get-fields',
            'free',
            'Read a post\'s ACF field values, keyed by field name, via get_fields()',
            [
                'type'       => 'object',
                'properties' => [
                    'post_id' => [ 'type' => 'integer' ],
                ],
                'required'   => [ 'post_id' ],
            ],
            [$get_fields, 'handle'],
            'edit_posts',
            'acf',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/update-fields',
            'free',
            'Set one or more ACF field values on a post via update_field(). A field value is ordinary postmeta, so this is snapshotted via object_type post and rollback-operation restores the prior values exactly. Disabled by default (site must opt in via the wpmcp_enable_acf_write filter)',
            [
                'type'       => 'object',
                'properties' => [
                    'post_id'    => [ 'type' => 'integer' ],
                    'fields'     => [ 'type' => 'object' ],
                    'session_id' => [ 'type' => 'string' ],
                ],
                'required'   => [ 'post_id', 'fields' ],
            ],
            [$update_fields, 'handle'],
            'edit_posts',
            'acf',
            'update'
        ));
    }

    /**
     * Register the SEO tools as free-tier abilities.
     *
     * get-seo-status is registered unconditionally: it must be reachable to
     * report "no SEO plugin active" at all, and it does not touch any
     * plugin-specific postmeta so it has nothing to degrade. get-seo-meta and
     * update-seo-meta are registered conditionally on SEO_Adapter detecting
     * Yoast or RankMath, following the same conditional-registration pattern
     * as the ACF tool group: neither plugin has a free/pro split of its own
     * to key off, so plugin absence is the only signal, and skipping keeps
     * these two out of the catalog on sites running neither plugin.
     */
    private function register_seo_abilities(Registrar $registrar): void
    {
        $get_seo_status = new Get_SEO_Status();

        $registrar->register(new Ability(
            'wpmcp/get-seo-status',
            'free',
            'Report which SEO plugin (Yoast SEO or Rank Math) is active on this site, by name and version',
            [
                'type'       => 'object',
                'properties' => [],
            ],
            [$get_seo_status, 'handle'],
            'edit_posts',
            'seo',
            'read'
        ));

        if ('' === SEO_Adapter::active_plugin()) {
            return;
        }

        $get_seo_meta    = new Get_SEO_Meta();
        $update_seo_meta = new Update_SEO_Meta();

        $registrar->register(new Ability(
            'wpmcp/get-seo-meta',
            'free',
            'Read a post\'s SEO title, meta description, focus keyword, canonical URL, and robots flags (noindex/nofollow) via the active SEO plugin\'s postmeta keys',
            [
                'type'       => 'object',
                'properties' => [
                    'post_id' => [ 'type' => 'integer' ],
                ],
                'required'   => [ 'post_id' ],
            ],
            [$get_seo_meta, 'handle'],
            'edit_posts',
            'seo',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/update-seo-meta',
            'free',
            'Set a post\'s SEO title, meta description, focus keyword, canonical URL, and/or robots flags (noindex/nofollow) via the active SEO plugin\'s postmeta keys. A field value is ordinary postmeta, so this is snapshotted via object_type post and rollback-operation restores the prior values exactly',
            [
                'type'       => 'object',
                'properties' => [
                    'post_id'       => [ 'type' => 'integer' ],
                    'title'         => [ 'type' => 'string' ],
                    'description'   => [ 'type' => 'string' ],
                    'focus_keyword' => [ 'type' => 'string' ],
                    'canonical'     => [ 'type' => 'string' ],
                    'noindex'       => [ 'type' => 'boolean' ],
                    'nofollow'      => [ 'type' => 'boolean' ],
                    'session_id'    => [ 'type' => 'string' ],
                ],
                'required'   => [ 'post_id' ],
            ],
            [$update_seo_meta, 'handle'],
            'edit_posts',
            'seo',
            'update'
        ));
    }

    /**
     * Register the multilingual (i18n) tools as free-tier abilities.
     *
     * All four are registered only when I18n_Adapter detects an active
     * multilingual plugin (Polylang or WPML), following the same
     * conditional-registration pattern as the ACF and SEO tool groups:
     * neither plugin has a free/pro split of its own to key off, so plugin
     * absence is the only signal, and skipping keeps these tools out of the
     * catalog on sites running no multilingual plugin. They share the
     * 'translation' domain.
     */
    private function register_i18n_abilities(Registrar $registrar): void
    {
        if ('' === I18n_Adapter::active_plugin()) {
            return;
        }

        $list_languages         = new List_Languages();
        $get_post_translations  = new Get_Post_Translations();
        $set_post_language      = new Set_Post_Language();
        $link_post_translations = new Link_Post_Translations();

        $registrar->register(new Ability(
            'wpmcp/list-languages',
            'free',
            'List the site\'s configured languages (code, human-readable name, and which is the default) via the active multilingual plugin (Polylang or WPML)',
            [
                'type'       => 'object',
                'properties' => [],
            ],
            [$list_languages, 'handle'],
            'edit_posts',
            'translation',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/get-post-translations',
            'free',
            'Read a post\'s translations (the translated post id and title, keyed by language code) via the active multilingual plugin (Polylang or WPML)',
            [
                'type'       => 'object',
                'properties' => [
                    'post_id' => [ 'type' => 'integer' ],
                ],
                'required'   => [ 'post_id' ],
            ],
            [$get_post_translations, 'handle'],
            'edit_posts',
            'translation',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/set-post-language',
            'free',
            'Assign a post to a language (by code) via the active multilingual plugin (Polylang or WPML). For Polylang the language is a term in the \'language\' taxonomy, so this is snapshotted via object_type post and rollback-operation restores the prior language assignment exactly',
            [
                'type'       => 'object',
                'properties' => [
                    'post_id'    => [ 'type' => 'integer' ],
                    'language'   => [ 'type' => 'string' ],
                    'session_id' => [ 'type' => 'string' ],
                ],
                'required'   => [ 'post_id', 'language' ],
            ],
            [$set_post_language, 'handle'],
            'edit_posts',
            'translation',
            'update'
        ));
        $registrar->register(new Ability(
            'wpmcp/link-post-translations',
            'free',
            'Link a set of posts as translations of one another, given a list of {language, post_id} pairs, via the active multilingual plugin (Polylang or WPML). The relationship spans multiple posts but only the primary (first) post is snapshotted, so rollback restores only the primary post, not the other linked posts',
            [
                'type'       => 'object',
                'properties' => [
                    'translations' => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'language' => [ 'type' => 'string' ],
                                'post_id'  => [ 'type' => 'integer' ],
                            ],
                            'required'   => [ 'language', 'post_id' ],
                        ],
                    ],
                    'session_id'   => [ 'type' => 'string' ],
                ],
                'required'   => [ 'translations' ],
            ],
            [$link_post_translations, 'handle'],
            'edit_posts',
            'translation',
            'update'
        ));
    }

    /**
     * Register the internal-linking analysis tools as free-tier abilities.
     *
     * All three are read-only: they build the internal-link graph from a
     * bounded set of published posts and report on it, never writing anything,
     * so none touch the safety core. They share domain 'seo' and operation
     * 'read', so their read_only_hint annotation derives to true automatically.
     */
    private function register_linking_abilities(Registrar $registrar): void
    {
        $find_orphan_posts      = new Find_Orphan_Posts();
        $suggest_internal_links = new Suggest_Internal_Links();
        $get_link_map           = new Get_Link_Map();

        $registrar->register(new Ability(
            'wpmcp/find-orphan-posts',
            'free',
            'List published posts or pages that have zero incoming internal links (orphans), by scanning the most-recent posts for links that resolve to this site\'s own content',
            [
                'type'       => 'object',
                'properties' => [
                    'post_type' => [ 'type' => 'string' ],
                    'limit'     => [ 'type' => 'integer' ],
                    'cap'       => [ 'type' => 'integer' ],
                ],
            ],
            [$find_orphan_posts, 'handle'],
            'edit_posts',
            'seo',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/suggest-internal-links',
            'free',
            'Suggest related published posts a given post should link to, ranked by shared categories/tags and title keyword overlap, excluding posts it already links to',
            [
                'type'       => 'object',
                'properties' => [
                    'post_id'   => [ 'type' => 'integer' ],
                    'post_type' => [ 'type' => 'string' ],
                    'limit'     => [ 'type' => 'integer' ],
                    'cap'       => [ 'type' => 'integer' ],
                ],
                'required'   => [ 'post_id' ],
            ],
            [$suggest_internal_links, 'handle'],
            'edit_posts',
            'seo',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/get-link-map',
            'free',
            'Summarize the internal-link graph: per-post outgoing and incoming link counts, the orphan list, and the most-linked posts',
            [
                'type'       => 'object',
                'properties' => [
                    'post_type' => [ 'type' => 'string' ],
                    'limit'     => [ 'type' => 'integer' ],
                    'cap'       => [ 'type' => 'integer' ],
                ],
            ],
            [$get_link_map, 'handle'],
            'edit_posts',
            'seo',
            'read'
        ));
    }

    /**
     * The redirect manager (issue #128): CRUD over the managed redirect table
     * plus the broken-link scanner. Domain 'seo', so the existing governance
     * domain toggle that covers the linking tools covers these too.
     *
     * Reads are edit_posts (an editor should be able to see why a URL bounces
     * and which links are dead); the three writes are manage_options, because
     * a redirect changes site-wide routing for every visitor. Every write
     * runs through Safe_Mutation with object_type 'redirect' and is undoable
     * with rollback-operation.
     *
     * There is no create-redirect-from-suggestion tool: deleting a post or
     * moving a published URL only ever emits a suggested_redirect in its own
     * response, and turning that into a live redirect always takes an
     * explicit create-redirect call.
     */
    private function register_redirect_abilities(Registrar $registrar): void
    {
        $registrar->register(new Ability(
            'wpmcp/list-redirects',
            'free',
            'List this site\'s managed redirects (source path, resolved target, status code, enabled, hit count), plus the pending redirect suggestions raised when a published post was deleted or moved. Filter by enabled state or a search string. Read-only',
            [
                'type'       => 'object',
                'properties' => [
                    'enabled' => [ 'type' => 'boolean' ],
                    'search'  => [ 'type' => 'string' ],
                    'limit'   => [ 'type' => 'integer' ],
                    'offset'  => [ 'type' => 'integer' ],
                ],
            ],
            [new List_Redirects(), 'handle'],
            'edit_posts',
            'seo',
            'read'
        ));

        $registrar->register(new Ability(
            'wpmcp/create-redirect',
            'free',
            'Create a managed redirect from a source path to a target URL or target_post_id (a post id keeps working after that post\'s slug changes). Chains are flattened to a single hop and loops are refused; the response reports the stored target. Snapshotted, so rollback-operation removes it',
            [
                'type'       => 'object',
                'properties' => [
                    'source'         => [ 'type' => 'string' ],
                    'target'         => [ 'type' => 'string' ],
                    'target_post_id' => [ 'type' => 'integer' ],
                    'status_code'    => [ 'type' => 'integer', 'enum' => Redirect_Store::ALLOWED_STATUS_CODES ],
                    'enabled'        => [ 'type' => 'boolean' ],
                    'notes'          => [ 'type' => 'string' ],
                    'session_id'     => [ 'type' => 'string' ],
                ],
                'required'   => [ 'source' ],
            ],
            [new Create_Redirect(), 'handle'],
            'manage_options',
            'seo',
            'create'
        ));

        $registrar->register(new Ability(
            'wpmcp/update-redirect',
            'free',
            'Change one managed redirect: its source, target/target_post_id, status code, enabled state or notes. Only the fields you pass change. Retargeting re-runs chain flattening and loop detection. Snapshotted and reversible',
            [
                'type'       => 'object',
                'properties' => [
                    'redirect_id'    => [ 'type' => 'integer' ],
                    'source'         => [ 'type' => 'string' ],
                    'target'         => [ 'type' => 'string' ],
                    'target_post_id' => [ 'type' => 'integer' ],
                    'status_code'    => [ 'type' => 'integer', 'enum' => Redirect_Store::ALLOWED_STATUS_CODES ],
                    'enabled'        => [ 'type' => 'boolean' ],
                    'notes'          => [ 'type' => 'string' ],
                    'session_id'     => [ 'type' => 'string' ],
                ],
                'required'   => [ 'redirect_id' ],
            ],
            [new Update_Redirect(), 'handle'],
            'manage_options',
            'seo',
            'update'
        ));

        $registrar->register(new Ability(
            'wpmcp/delete-redirect',
            'free',
            'Delete one managed redirect. The whole row is snapshotted first, so rollback-operation brings the same redirect back; use update-redirect with enabled:false instead to stop it firing while keeping its hit history',
            [
                'type'       => 'object',
                'properties' => [
                    'redirect_id' => [ 'type' => 'integer' ],
                    'session_id'  => [ 'type' => 'string' ],
                ],
                'required'   => [ 'redirect_id' ],
            ],
            [new Delete_Redirect(), 'handle'],
            'manage_options',
            'seo',
            'delete'
        ));

        $registrar->register(new Ability(
            'wpmcp/find-broken-links',
            'free',
            'Scan published content for internal links that are dead, point at a post that is not public yet, or go through a redirect instead of straight to its target. Call with background:true to queue a batched scan, then with scan_id to poll its progress and findings. Read-only: it proposes fixes and changes nothing',
            [
                'type'       => 'object',
                'properties' => [
                    'post_types' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                    'limit'      => [ 'type' => 'integer' ],
                    'background' => [ 'type' => 'boolean' ],
                    'batch_size' => [ 'type' => 'integer' ],
                    'scan_id'    => [ 'type' => 'integer' ],
                ],
            ],
            [new Find_Broken_Links(), 'handle'],
            'edit_posts',
            'seo',
            'read'
        ));
    }

    /**
     * Register the SEO + accessibility analysis and auto-fixer tools as
     * pro-tier abilities.
     *
     * The four AUDIT tools (extract-content, analyze-seo,
     * analyze-accessibility, check-contrast) are read-only: they extract and
     * score a post's stored content and never write anything, so none touch
     * the safety core. They share domain 'analysis' and operation 'read', so
     * their read_only_hint annotation derives to true automatically.
     *
     * The three AUTO-FIXERS (fix-color-contrast, add-alt-text-from-context,
     * fix-link-text, issue #71) close the loop on those audits. They are
     * registered with operation 'update' even though their DEFAULT behavior is
     * a dry run that writes nothing: an annotation has to describe what the
     * tool can do at its most permissive, not its safest mode, so a client
     * that refuses non-read-only tools must refuse these. Each fixer writes
     * its entire pass through one Safe_Mutation snapshot, so the returned
     * operation_id rolls back the whole pass (see Tools\Analysis\Fix_Pass).
     *
     * Because Registrar skips 'pro' tier abilities unless Gate::is_pro() is
     * true, all seven only register on Pro-tier sites, matching the Elementor
     * deep-editing pro group.
     */
    private function register_analysis_abilities(Registrar $registrar): void
    {
        $extract_content = new Extract_Content();

        $registrar->register(new Ability(
            'wpmcp/extract-content',
            'pro',
            'Extract a post\'s readable plain text and a structural summary (headings, word count, link and image counts) from its stored content. Read-only',
            [
                'type'       => 'object',
                'properties' => [
                    'post_id' => [ 'type' => 'integer' ],
                ],
                'required'   => [ 'post_id' ],
            ],
            [$extract_content, 'handle'],
            'edit_posts',
            'analysis',
            'read'
        ));

        $analyze_seo = new Analyze_Seo();

        $registrar->register(new Ability(
            'wpmcp/analyze-seo',
            'pro',
            'Score a post\'s on-page SEO (0-100) with severity-tagged findings: title and meta-description length, H1 and heading structure, word count, image alt coverage, internal/external link counts, focus-keyword density, and a Flesch reading-ease readability score. Read-only',
            [
                'type'       => 'object',
                'properties' => [
                    'post_id'       => [ 'type' => 'integer' ],
                    'focus_keyword' => [ 'type' => 'string' ],
                ],
                'required'   => [ 'post_id' ],
            ],
            [$analyze_seo, 'handle'],
            'edit_posts',
            'analysis',
            'read'
        ));

        $analyze_accessibility = new Analyze_Accessibility();

        $registrar->register(new Ability(
            'wpmcp/analyze-accessibility',
            'pro',
            'Scan a post\'s stored HTML for common WCAG issues (images missing alt text, heading order jumps, empty or non-descriptive link text, and form controls without labels) and return scored findings with the offending element locations. Read-only',
            [
                'type'       => 'object',
                'properties' => [
                    'post_id' => [ 'type' => 'integer' ],
                ],
                'required'   => [ 'post_id' ],
            ],
            [$analyze_accessibility, 'handle'],
            'edit_posts',
            'analysis',
            'read'
        ));

        $check_contrast = new Check_Contrast();

        $registrar->register(new Ability(
            'wpmcp/check-contrast',
            'pro',
            'Compute the WCAG contrast ratio between a foreground and background hex color and report AA/AAA pass/fail for normal and large text. Read-only',
            [
                'type'       => 'object',
                'properties' => [
                    'foreground' => [ 'type' => 'string' ],
                    'background' => [ 'type' => 'string' ],
                ],
                'required'   => [ 'foreground', 'background' ],
            ],
            [$check_contrast, 'handle'],
            'edit_posts',
            'analysis',
            'read'
        ));

        $fix_color_contrast = new Fix_Color_Contrast();

        $registrar->register(new Ability(
            'wpmcp/fix-color-contrast',
            'pro',
            'Raise failing inline text/background color pairs in a post to a target WCAG ratio, moving lightness only so the hue survives. Reports before/after color, ratio and achieved level per pair. Dry run unless apply=true; applying writes the pass under one snapshot that one rollback reverts',
            [
                'type'       => 'object',
                'properties' => [
                    'post_id'            => [ 'type' => 'integer' ],
                    'target_ratio'       => [ 'type' => 'number' ],
                    'default_background' => [ 'type' => 'string' ],
                    'apply'              => [ 'type' => 'boolean' ],
                    'session_id'         => [ 'type' => 'string' ],
                ],
                'required'   => [ 'post_id' ],
            ],
            [$fix_color_contrast, 'handle'],
            'edit_posts',
            'analysis',
            'update'
        ));

        $add_alt_text = new Add_Alt_Text_From_Context();

        $registrar->register(new Ability(
            'wpmcp/add-alt-text-from-context',
            'pro',
            'Write alt text for a post\'s images that have none, from the filename, nearest heading, or post title. Never overwrites existing alt text unless overwrite_existing=true, and never touches images marked decorative. Dry run unless apply=true; applying writes the pass under one snapshot that one rollback reverts',
            [
                'type'       => 'object',
                'properties' => [
                    'post_id'            => [ 'type' => 'integer' ],
                    'overwrite_existing' => [ 'type' => 'boolean' ],
                    'apply'              => [ 'type' => 'boolean' ],
                    'session_id'         => [ 'type' => 'string' ],
                ],
                'required'   => [ 'post_id' ],
            ],
            [$add_alt_text, 'handle'],
            'edit_posts',
            'analysis',
            'update'
        ));

        $fix_link_text = new Fix_Link_Text();

        $registrar->register(new Ability(
            'wpmcp/fix-link-text',
            'pro',
            'Replace empty or generic anchor text ("click here", "read more") on internal links with the destination post\'s title, fixing WCAG 2.4.4 and weak SEO anchors at once. External links and anchors containing markup are skipped. Dry run unless apply=true; applying writes the pass under one snapshot that one rollback reverts',
            [
                'type'       => 'object',
                'properties' => [
                    'post_id'    => [ 'type' => 'integer' ],
                    'apply'      => [ 'type' => 'boolean' ],
                    'session_id' => [ 'type' => 'string' ],
                ],
                'required'   => [ 'post_id' ],
            ],
            [$fix_link_text, 'handle'],
            'edit_posts',
            'analysis',
            'update'
        ));
    }

    /**
     * Connection-info tooling for the EMCP admin/connection area (issue #18).
     * get-connection-info is read-only and returns only a placeholder
     * Authorization value, never a real credential, so it needs no
     * Safe_Mutation snapshot/rollback and does not touch the safety core.
     * Gated at manage_options since it exposes this site's MCP endpoint URL
     * and connection instructions, matching the admin-only trust level of
     * the other introspection tools (list-rest-routes, get-cache-status).
     */
    private function register_connect_abilities(Registrar $registrar): void
    {
        $get_connection_info = new Get_Connection_Info();

        $registrar->register(new Ability(
            'wpmcp/get-connection-info',
            'free',
            'Return how to connect an MCP client to this site: the MCP server endpoint URL and ready-to-paste connection snippets for Claude Code, Cursor, and Claude Desktop, each using an Application Password placeholder. Never returns a real credential. Read-only',
            [
                'type'       => 'object',
                'properties' => [],
            ],
            [$get_connection_info, 'handle'],
            'manage_options',
            'connect',
            'read'
        ));

        $list_tool_catalog = new List_Tool_Catalog();

        $registrar->register(new Ability(
            'wpmcp/list-tool-catalog',
            'free',
            'List every wpmcp ability registered on this site, grouped by domain, with each entry\'s tier (free/pro), operation, required capability, and read-only/destructive hints, plus a per-domain summary count. Optional domain and/or tier filters narrow the result. Read-only',
            [
                'type'       => 'object',
                'properties' => [
                    'domain' => [ 'type' => 'string' ],
                    'tier'   => [ 'type' => 'string' ],
                ],
            ],
            [$list_tool_catalog, 'handle'],
            'manage_options',
            'connect',
            'read'
        ));
    }

    /**
     * Register the Bricks/Divi page-builder tools as pro-tier abilities,
     * matching how Elementor's deep-editing tools are tiered (issue #47).
     *
     * Unlike Elementor's `_elementor_data` deep-editing tools, none of these
     * three tools require the Bricks or Divi plugin classes to be loaded:
     * Bricks stores its structure as JSON in ordinary postmeta
     * (`_bricks_page_content_2`) and Divi's classic builder stores its
     * layout as shortcodes directly in `post_content` (flagged by the
     * `_et_pb_use_builder` postmeta). Both are plain WordPress storage this
     * plugin can read/write/snapshot/roll back without either paid plugin
     * installed; only the real plugins' visual render is production-only.
     * Writes go through Safe_Mutation::run() with object_type='post': the
     * existing post snapshot (full post row, including post_content, plus
     * all postmeta) already captures and restores both storage shapes, so
     * every write here is undoable with no change to the safety core.
     */
    private function register_builder_abilities(Registrar $registrar): void
    {
        $detect_builder = new Detect_Builder();

        $registrar->register(new Ability(
            'wpmcp/detect-builder',
            'pro',
            'Detect which page builder authored a post (elementor / bricks / divi / gutenberg / classic), by inspecting plain postmeta/post_content markers: Elementor\'s _elementor_edit_mode, Bricks\' _bricks_page_content_2, Divi\'s _et_pb_use_builder, or Gutenberg block comments in post_content, falling back to classic. Read-only',
            [
                'type'       => 'object',
                'properties' => [
                    'post_id' => [ 'type' => 'integer' ],
                ],
                'required'   => [ 'post_id' ],
            ],
            [$detect_builder, 'handle'],
            'edit_posts',
            'builders',
            'read'
        ));

        $get_builder_content = new Get_Builder_Content();

        $registrar->register(new Ability(
            'wpmcp/get-builder-content',
            'pro',
            'Return the raw builder structure for a post: for Bricks, the decoded _bricks_page_content_2 postmeta JSON; for Divi, the post_content shortcode string plus the use-builder flag. Returns a WP_Error for posts detected as elementor, gutenberg, or classic. Read-only',
            [
                'type'       => 'object',
                'properties' => [
                    'post_id' => [ 'type' => 'integer' ],
                ],
                'required'   => [ 'post_id' ],
            ],
            [$get_builder_content, 'handle'],
            'edit_posts',
            'builders',
            'read'
        ));

        $update_builder_content = new Update_Builder_Content();

        $registrar->register(new Ability(
            'wpmcp/update-builder-content',
            'pro',
            'Replace the builder structure for a post. Bricks: validates the given string is well-formed JSON decoding to an array, then writes _bricks_page_content_2. Divi: validates the given content is a string, then writes post_content and ensures _et_pb_use_builder is on. Undoable via rollback-operation since both are ordinary postmeta/post_content captured by the existing post snapshot',
            [
                'type'       => 'object',
                'properties' => [
                    'post_id' => [ 'type' => 'integer' ],
                    'builder' => [ 'type' => 'string' ],
                    'content' => [ 'type' => 'string' ],
                ],
                'required'   => [ 'post_id', 'builder', 'content' ],
            ],
            [$update_builder_content, 'handle'],
            'edit_posts',
            'builders',
            'update'
        ));
    }

    /**
     * The compact-surface meta-tools (issue #79), registered UNCONDITIONALLY
     * in both exposure modes: compact mode is exposure-only, so the
     * registered ability surface — and with it the ability-manifest drift
     * guard — never varies with the mode. In full mode these three simply
     * ride along as ordinary tools; in compact mode they ARE the surface.
     *
     * call-tool is deliberately classified domain=dispatch, operation=update
     * with explicit destructive annotations: it proxies writes and deletes,
     * so it must not advertise itself as read-only, and Governance/identity
     * narrowing applies to the shell like any other ability (a scoped
     * identity that should dispatch must include domain 'dispatch' and
     * operation 'update'; AND-of-narrowing, no special bypass). The REAL
     * authorization decision for a dispatched call is made by the target
     * ability's own permission callback — see Call_Tool's docblock, and the
     * call-rest precedent for a gateway tool whose floor capability is
     * edit_posts while every target enforces its own gate.
     */
    private function register_dispatch_abilities(Registrar $registrar): void
    {
        $list_tools      = new List_Tools();
        $get_tool_schema = new Get_Tool_Schema();
        $call_tool       = new Call_Tool();

        $registrar->register(new Ability(
            'wpmcp/list-tools',
            'free',
            'List every tool this wpmcp install currently registers: name, a short summary, domain, operation, and tier, sorted by name. Optional domain filter narrows the result; full:true adds complete descriptions and MCP annotations. Schemas stay behind get-tool-schema. Read-only. With compact mode active this is the discovery entry point for every tool not directly listed',
            [
                'type'       => 'object',
                'properties' => [
                    'domain' => [ 'type' => 'string' ],
                    'full'   => [ 'type' => 'boolean' ],
                ],
            ],
            [$list_tools, 'handle'],
            'edit_posts',
            'dispatch',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/get-tool-schema',
            'free',
            'Read one registered wpmcp tool\'s full contract by name: the exact input schema it was registered with, its complete description, MCP annotations, and its domain/operation/tier classification. Read-only. Use wpmcp/list-tools to discover names',
            [
                'type'       => 'object',
                'properties' => [
                    'name' => [ 'type' => 'string' ],
                ],
                'required'   => [ 'name' ],
            ],
            [$get_tool_schema, 'handle'],
            'edit_posts',
            'dispatch',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/call-tool',
            'free',
            'Invoke any wpmcp-registered tool by name with the given arguments object, the dispatch path for tools hidden from tools/list by compact mode. The target tool\'s own permission checks (capability, governance, identity scope, license), rate limit, input validation, and snapshot/rollback safety behavior all apply exactly as if it were called directly; this tool can never widen access. Refuses tools not registered by wpmcp and the meta-tools themselves',
            [
                'type'       => 'object',
                'properties' => [
                    'name'      => [ 'type' => 'string' ],
                    'arguments' => [ 'type' => 'object' ],
                ],
                'required'   => [ 'name' ],
            ],
            [$call_tool, 'handle'],
            'edit_posts',
            'dispatch',
            'update',
            false,
            true,
            false
        ));
    }
}
