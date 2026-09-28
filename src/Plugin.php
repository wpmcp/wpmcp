<?php

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols -- ABSPATH guard is an intentional side effect.

namespace WPMCP;

// Plugin Check's Direct_File_Access_Check only accepts the bare defined()
// test; an extra conjunct makes it report the file as unprotected. The test
// bootstrap defines ABSPATH itself, so the guard needs no test escape hatch.
// It sits above the use block because the check's namespace-blind fallback
// only scans the first 50 lines of the file.
if (! defined('ABSPATH')) {
    exit;
}

use WPMCP\Admin\Audit_Log_Page;
use WPMCP\Admin\History_Page;
use WPMCP\Admin\Restore_Controller;
use WPMCP\Maintenance\Maintenance_Guard;
use WPMCP\Tools\Maintenance\Get_Maintenance_Status;
use WPMCP\Tools\Maintenance\Enable_Maintenance;
use WPMCP\Tools\Maintenance\Disable_Maintenance;
use WPMCP\Tools\Context\Get_Site_Context;
use WPMCP\Tools\Context\Get_Page_Snapshot;
use WPMCP\Tools\Context\Get_Rendered_Html;
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
use WPMCP\Tools\Structure\Create_Sidebar_Widget;
use WPMCP\Tools\Structure\Update_Sidebar_Widget;
use WPMCP\Tools\Structure\Move_Sidebar_Widget;
use WPMCP\Tools\Structure\Delete_Sidebar_Widget;
use WPMCP\Tools\Export\Export_Content;
use WPMCP\Tools\Export\List_Exports;
use WPMCP\Tools\Export\Import_Content;
use WPMCP\Tools\Analysis\Check_Contrast;
use WPMCP\Tools\Code\Validate_Php_Snippet;
use WPMCP\Tools\Code\Run_Php_Snippet;
use WPMCP\Tools\CustomCode\Add_Scoped_Css;
use WPMCP\Tools\CustomCode\Add_Custom_Js;
use WPMCP\Tools\Code\Create_Php_Snippet;
use WPMCP\Tools\Code\List_Php_Snippets;
use WPMCP\Tools\Code\Get_Php_Snippet;
use WPMCP\Tools\Code\Update_Php_Snippet;
use WPMCP\Tools\Code\Delete_Php_Snippet;
use WPMCP\Tools\Code\Activate_Php_Snippet;
use WPMCP\Tools\Code\Deactivate_Php_Snippet;
use WPMCP\Tools\Cli\Run_Wp_Cli;
use WPMCP\Tools\Cli\Dispatch_Cli_Job;
use WPMCP\Tools\Cli\Get_Cli_Job;
use WPMCP\Tools\Cli\List_Cli_Jobs;
use WPMCP\Tools\Cli\Cancel_Cli_Job;
use WPMCP\Tools\Cli\Run_Cli_Job;
use WPMCP\Tools\Analysis\Extract_Content;
use WPMCP\Tools\Analysis\Analyze_Seo;
use WPMCP\Tools\Analysis\SeoData\Set_Seo_Data_Key;
use WPMCP\Tools\Analysis\Analyze_Accessibility;
use WPMCP\Tools\Analysis\Fix_Color_Contrast;
use WPMCP\Tools\Analysis\Add_Alt_Text_From_Context;
use WPMCP\Tools\Analysis\Fix_Link_Text;
use WPMCP\Admin\Handshake_Settings_Page;
use WPMCP\Admin\Connection_Page;
use WPMCP\Admin\Announcements;
use WPMCP\Admin\Snapshot_Retention_Notice;
use WPMCP\Admin\Ability_Grid_Page;
use WPMCP\Admin\Memory_Page;
use WPMCP\Admin\Redirect_Suggestion_Controller;
use WPMCP\Admin\Redirects_Page;
use WPMCP\Admin\Skills_Settings_Page;
use WPMCP\Pro\Chat\Chat_Page;
use WPMCP\Pro\Chat\Chat_Rest_Controller;
use WPMCP\Pro\Chat\Conversation_Store;
use WPMCP\Pro\Gate;
use WPMCP\Memory\Memory_Store;
use WPMCP\Skills\Skills_Module;
use WPMCP\Tools\Skills\Get_Skill;
use WPMCP\Tools\Skills\List_Skills;
use WPMCP\Governance\Default_Seeder;
use WPMCP\Connect\Exposure;
use WPMCP\MCP\Ability;
use WPMCP\MCP\Handshake_Instructions;
use WPMCP\MCP\Tool_Exposure;
use WPMCP\Tools\Bridge\Execute_Site_Ability;
use WPMCP\Tools\Bridge\Get_Site_Ability;
use WPMCP\Tools\Bridge\List_Site_Abilities;
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
use WPMCP\Tools\SEO\Crawler_Files;
use WPMCP\Tools\SEO\Get_Crawler_Files;
use WPMCP\Tools\SEO\Update_Crawler_Files;
use WPMCP\Tools\SEO\Get_SEO_Meta;
use WPMCP\Tools\SEO\Update_SEO_Meta;
use WPMCP\Tools\SEO\SEO_Adapter;
use WPMCP\Tools\SEO\Generate_Schema_Markup;
use WPMCP\Tools\SEO\Schema_Generator;
use WPMCP\Tools\SEO\Get_Social_Meta;
use WPMCP\Tools\SEO\Generate_Meta_Tags;
use WPMCP\Tools\SEO\Set_Social_Image;
use WPMCP\Tools\SEO\Get_Term_SEO_Meta;
use WPMCP\Tools\SEO\Update_Term_SEO_Meta;
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
use WPMCP\Tools\Content\Get_Preview_Link;
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
use WPMCP\Tools\Media\Upload_Media;
use WPMCP\Tools\Media\List_Media;
use WPMCP\Tools\Media\Find_Unused_Media;
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
use WPMCP\Tools\Comments\Create_Comment;
use WPMCP\Tools\Comments\Reply_To_Comment;
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
use WPMCP\Tools\Packages\Search_Themes;
use WPMCP\Tools\Packages\Install_Package_From_Zip;
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
use WPMCP\Tools\Diagnostics\Get_Site_Health;
use WPMCP\Tools\SiteEditor\Site_Templates_Read;
use WPMCP\Tools\SiteEditor\Site_Templates_Write;
use WPMCP\Tools\Cron\List_Cron_Events;
use WPMCP\Tools\Cron\Schedule_Event;
use WPMCP\Tools\Cron\Unschedule_Event;
use WPMCP\Tools\Cron\Run_Event;
use WPMCP\Tools\Backup\Trigger_Backup;
use WPMCP\Tools\Backup\Get_Backup_Status;
use WPMCP\Tools\Backup\List_Backup_Jobs;
use WPMCP\Tools\Backup\Cancel_Backup_Job;
use WPMCP\Tools\Backup\Run_Backup_Job;
use WPMCP\Tools\Backup\Get_Backup_Manifest;
use WPMCP\Tools\Backup\Delete_Backup_Archive;
use WPMCP\Tools\Backup\Restore_Site_Backup;
use WPMCP\Tools\Migration\Push_Site_Archive;
use WPMCP\Tools\Migration\Receive_Site_Archive;
use WPMCP\Tools\Migration\Find_Replace_Content;
use WPMCP\Tools\Migration\Rewrite_Site_Urls;
use WPMCP\Tools\Sync\Apply_Change_Set;
use WPMCP\Tools\Sync\Build_Change_Set;
use WPMCP\Tools\Sync\Get_Change_Set;
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
use WPMCP\Tools\Elementor\Regenerate_Elementor_Css;
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
use WPMCP\Tools\Elementor\Replace_System_Colors;
use WPMCP\Tools\Elementor\Replace_System_Typography;
use WPMCP\Tools\Elementor\List_Global_Classes;
use WPMCP\Tools\Elementor\Create_Global_Class;
use WPMCP\Tools\Elementor\Update_Global_Class;
use WPMCP\Tools\Elementor\Delete_Global_Class;
use WPMCP\Tools\Elementor\Reorder_Global_Classes;
use WPMCP\Tools\Elementor\Global_Class_Schema;
use WPMCP\Tools\Elementor\List_Global_Variables;
use WPMCP\Tools\Elementor\Create_Global_Variable;
use WPMCP\Tools\Elementor\Update_Global_Variable;
use WPMCP\Tools\Elementor\Delete_Global_Variable;
use WPMCP\Tools\Elementor\Global_Variable_Schema;
use WPMCP\Tools\Brand\List_Brand_Kits;
use WPMCP\Tools\Brand\Get_Brand_Kit;
use WPMCP\Tools\Brand\Apply_Brand_Kit;
use WPMCP\Tools\Brand\Rollback_Brand_Kit;
use WPMCP\Tools\Elementor\Export_Page;
use WPMCP\Tools\Elementor\Save_As_Template;
use WPMCP\Tools\Elementor\Apply_Template;
use WPMCP\Tools\Elementor\Import_Template;
use WPMCP\Tools\Elementor\Export_Template;
use WPMCP\Tools\Elementor\Resolve_Theme_Template;
use WPMCP\Tools\Elementor\Create_Theme_Template;
use WPMCP\Tools\Elementor\Set_Template_Conditions;
use WPMCP\Tools\Elementor\Get_Theme_Template;
use WPMCP\Tools\Elementor\List_Theme_Templates;
use WPMCP\Tools\Elementor\Delete_Theme_Template;
use WPMCP\Tools\Elementor\Detect_Elementor_Version;
use WPMCP\Tools\Elementor\Add_Flexbox;
use WPMCP\Tools\Elementor\Add_Div_Block;
use WPMCP\Tools\Elementor\Add_Atomic_Widget;
use WPMCP\Tools\Elementor\Atomic_Element;
use WPMCP\Tools\Elementor\Atomic_Styles;
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
use WPMCP\Tools\WooCommerce\List_Variations;
use WPMCP\Tools\WooCommerce\Update_Variation;
use WPMCP\Tools\WooCommerce\List_Low_Stock_Products;
use WPMCP\Tools\WooCommerce\Create_Variation;
use WPMCP\Tools\WooCommerce\Delete_Variation;
use WPMCP\Tools\WooCommerce\Bulk_Update_Products;
use WPMCP\Tools\WooCommerce\List_Coupons;
use WPMCP\Tools\WooCommerce\Get_Coupon;
use WPMCP\Tools\WooCommerce\Create_Coupon;
use WPMCP\Tools\WooCommerce\Update_Coupon;
use WPMCP\Tools\WooCommerce\Delete_Coupon;
use WPMCP\Tools\WooCommerce\Validate_Coupon;
use WPMCP\Tools\WooCommerce\List_Tax_Rates;
use WPMCP\Tools\WooCommerce\Create_Tax_Rate;
use WPMCP\Tools\WooCommerce\Update_Tax_Rate;
use WPMCP\Tools\WooCommerce\Delete_Tax_Rate;
use WPMCP\Tools\WooCommerce\Plan_Product_Import;
use WPMCP\Tools\WooCommerce\Apply_Product_Import;
use WPMCP\Tools\WooCommerce\Catalog\Woo_Ops;
use WPMCP\Tools\WooCommerce\Catalog\Woo_Read;
use WPMCP\Tools\WooCommerce\Catalog\Woo_Write;
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
use WPMCP\MCP\Server as Mcp_Server;
use WPMCP\MCP\Stdio_Transport;
use WPMCP\MCP\Transport_Guard;

final class Plugin
{
    /**
     * The product name as it appears in the admin menu. A brand, not a
     * sentence: it is deliberately NOT wrapped in __(), because a msgid
     * identical to the text domain gives translators no context, and a brand
     * inside a msgid is one more literal for a flavor build to chase. This
     * constant is the single place such a build would rewrite; today neither
     * scripts/build-woo-release.sh nor build-wporg-release.sh does, so every
     * flavor still renders "wpmcp" here. Menu and page titles compose it with
     * a translated tail through page_title() instead of baking it into a
     * msgid.
     */
    public const BRAND = 'wpmcp';

    /**
     * "<brand>: <screen>" for a page title or an H1. One translatable
     * pattern owns the separator so a locale can reorder the two parts or
     * use its own punctuation (French " : ", a full-width colon in CJK), and
     * the brand never enters a msgid. $screen is already translated by the
     * caller.
     */
    public static function page_title(string $screen): string
    {
        return sprintf(
            /* translators: 1: product name (not translated), 2: admin screen name */
            __('%1$s: %2$s', 'wpmcp'),
            self::BRAND,
            $screen
        );
    }

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
            'structure', 'taxonomy', 'export', 'backup', 'migration', 'analysis',
            'connect', 'governance', 'skills', 'gateway',
        ],
    ];

    /**
     * Listeners on wpmcp_rollback_options_restored, which every option and
     * option_set rollback fires. Each rebuilds state an integration derives
     * from options, the same way the forward write did.
     *
     * These classes live in src/Integrations, which the WooCommerce build
     * removes, so they are wired through register_rollback_refreshers() and
     * never named in a bare add_action(): a listener naming a class the build
     * does not ship fatals every option rollback on that build.
     */
    private const ROLLBACK_REFRESHERS = [
        // A theme framework pack write rebuilt the active theme's generated
        // CSS; its rollback does too (issue #316).
        [\WPMCP\Integrations\Theme_Framework_Pack::class, 'refresh_after_restore'],
        // An Elementor addon module toggle dropped the suite's cached module
        // map; its rollback does too (issue #286).
        [\WPMCP\Integrations\Elementor_Addon_Packs::class, 'after_restore'],
        // A block suite write dropped the suite's cached per-post CSS; its
        // rollback does too (issue #287). Hooked on the post restore action.
        [\WPMCP\Integrations\Block_Suite::class, 'refresh_after_restore', 'wpmcp_rollback_post_restored'],
    ];

    /**
     * Hook each rollback refresher whose class this build ships. The list is
     * a parameter only so a test can pass a class no build ships; boot()
     * always uses ROLLBACK_REFRESHERS.
     *
     * Entries are [class, method] on wpmcp_rollback_options_restored, or
     * [class, method, hook] for another rollback action.
     *
     * @param array<int,array{0:string,1:string,2?:string}>|null $refreshers
     */
    public static function register_rollback_refreshers(?array $refreshers = null): void
    {
        foreach ($refreshers ?? self::ROLLBACK_REFRESHERS as $refresher) {
            [$class, $method] = $refresher;
            if (class_exists($class)) {
                add_action($refresher[2] ?? 'wpmcp_rollback_options_restored', [$class, $method]);
            }
        }
    }

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
     * The text domain this build's strings carry. Each main plugin file
     * defines WPMCP_TEXT_DOMAIN to match its own Text Domain header: the
     * WooCommerce build rewrites every string in src/ to
     * 'wpmcp-for-woocommerce' at build time, so a literal 'wpmcp' here
     * would load its .mo into a domain none of its strings use.
     */
    public static function text_domain(): string
    {
        return defined('WPMCP_TEXT_DOMAIN') ? (string) WPMCP_TEXT_DOMAIN : 'wpmcp';
    }

    /**
     * Load a self-hosted .mo from the languages/ directory the Domain Path
     * header points at (issue #184). Hooked on init by boot(). wp.org
     * installs get language packs just in time since WP 4.6, but the pro
     * and flavor zips ship off-directory, where a .mo in the plugin's own
     * languages/ only loads through this call.
     */
    public function load_textdomain(): void
    {
        if (!defined('WPMCP_FILE')) {
            return;
        }
        load_plugin_textdomain(self::text_domain(), false, dirname(plugin_basename(WPMCP_FILE)) . '/languages');
    }

    /**
     * Boot-time runtime hook wiring for the flavor-gated feature groups: the
     * data-driven widget/block builders, the content search index, stored
     * custom CSS/JS output (delegated to
     * register_custom_code_runtime_hooks()), and agent project memory.
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
            // A permanently deleted spec must not leave generated PHP behind.
            add_action('before_delete_post', ['\\WPMCP\\Tools\\WidgetBuilder\\Widget_Registry', 'purge_on_delete'], 10, 2);
        }
        // Data-driven custom Gutenberg block builder: register the wpmcp_block
        // CPT and register active specs as real blocks via register_block_type.
        if ($this->group_enabled('block_builder')) {
            add_action('init', ['\\WPMCP\\Tools\\BlockBuilder\\Block_Spec_Store', 'ensure_post_type'], 5);
            add_action('init', ['\\WPMCP\\Tools\\BlockBuilder\\Block_Registry', 'register'], 20);
        }
        // Theme-builder site parts (issue #70): register the wpmcp_template
        // CPT on init and boot the render adapters on wp, once the main query
        // exists and the winning template for this request can be resolved.
        if ($this->group_enabled('theme_builder')) {
            add_action('init', ['\\WPMCP\\Tools\\ThemeBuilder\\Template_Store', 'ensure_post_type']);
            add_action('wp', ['\\WPMCP\\Tools\\ThemeBuilder\\Render\\Adapters', 'boot']);
            $this->register_dynamic_template_runtime_hooks();
        }
        // Content search index (issue #83): keep it correct incrementally on
        // every save/delete so search-content never reads stale copy. Gated
        // with its ability group so a flavor that drops the group also drops
        // the indexing cost.
        if ($this->group_enabled('search')) {
            (new Index_Hooks())->register();
        }
        // Stored custom CSS/JS output (issue #63), gated on its own group.
        $this->register_custom_code_runtime_hooks();
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

    /**
     * Front-end wiring for dynamic single, archive and search templates
     * (issue #290): swap in the winning template on template_include and
     * resolve its binding tokens as it renders. Its own method so the wp.org
     * build, which ships the site parts engine without these templates, can
     * remove it by name. String callables, like the builder branches above,
     * because vertical builds prune the classes they name.
     */
    public function register_dynamic_template_runtime_hooks(): void
    {
        if ($this->group_enabled('theme_builder')) {
            add_action('wp', ['\\WPMCP\\Tools\\ThemeBuilder\\Dynamic\\Dynamic_Templates', 'boot']);
            add_filter('wpmcp_site_part_rendered', ['\\WPMCP\\Tools\\ThemeBuilder\\Dynamic\\Binding_Resolver', 'filter_rendered'], 10, 2);
        }
    }

    /**
     * Front-end output wiring for stored custom CSS/JS (issue #63).
     *
     * Its own method rather than another branch inside
     * register_builder_runtime_hooks() for two reasons. The wp.org flavor
     * deletes whole methods by name, so a method is the unit that build can
     * remove cleanly, comment and all; and a test that wants to assert the
     * renderer got wired can call THIS instead of replaying the whole runtime
     * hook set, which re-registers the search index and memory-page hooks on
     * fresh instances WordPress cannot dedupe and leaks duplicated save_post
     * and deleted_post handlers into every test that runs after it.
     *
     * The wiring lives here, not in register_custom_code_abilities(), for two
     * more reasons: ability registration runs on wp_abilities_api_init, which
     * fires lazily on first registry access and is never reached on a plain
     * front-end page view (so stored code would never render for a visitor),
     * and registration is a pure catalog operation replayed in wp-admin and
     * against throwaway Registrars in tests, which must not acquire a
     * permanent front-end output side effect.
     *
     * Gated on the group, not on Gate::is_pro(): a lapsed license must not
     * silently strip CSS a site already depends on, the same reasoning the
     * memory hooks carry. Stored JS has its own gate inside the renderer.
     *
     * Custom_Code_Renderer is named as a STRING callable, matching the widget
     * and block builder branches above: vertical builds prune that file from
     * the zip, so no shipped build should carry a static reference to a class
     * it does not contain.
     */
    public function register_custom_code_runtime_hooks(): void
    {
        if (! $this->group_enabled('custom_code')) {
            return;
        }

        call_user_func(['\\WPMCP\\Tools\\CustomCode\\Custom_Code_Renderer', 'boot']);
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
            // Self-hosted translations from languages/ (issue #184).
            add_action('init', [$this, 'load_textdomain']);
            // Seal any phase A plaintext cloud credentials on the first page
            // load after an update (issue #141); a no-op query-wise otherwise.
            // The class is absent from flavors that strip src/Cloud.
            if (class_exists(\WPMCP\Cloud\Cloud_Credentials::class)) {
                add_action('init', [\WPMCP\Cloud\Cloud_Credentials::class, 'maybe_migrate_on_boot']);
            }
            $hook = function_exists('wp_register_ability') ? 'wp_abilities_api_init' : 'init';
            add_action($hook, [$this, 'register_abilities']);
            if (function_exists('wp_register_ability_category')) {
                add_action('wp_abilities_api_categories_init', [$this, 'register_ability_category']);
            }
            $this->register_builder_runtime_hooks();
            add_action('admin_menu', [$this, 'register_admin_menu']);
            add_action('wp_ajax_wpmcp_restore', [new Restore_Controller(), 'handle']);
            // Redacted CSV export of the filtered request log (issue #303).
            add_action('admin_post_' . Audit_Log_Page::EXPORT_ACTION, [new Audit_Log_Page(), 'export_requests']);
            // Integrations that derive state from options rebuild it after a
            // rollback puts those options back. Wired only for the classes
            // this build ships; see register_rollback_refreshers().
            self::register_rollback_refreshers();
            // A shipping zone deleted through WooCommerce drops the creation
            // marker a woo-write create gave it, so a zone that later reuses
            // the id is never mistaken for the created one (issue #338).
            add_action('woocommerce_delete_shipping_zone', ['\\WPMCP\\Safety\\Wc_Shipping_Zone_Snapshot', 'forget_creation']);
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
            // Managed crawler files (issue #384): robots.txt rules, llms.txt and
            // the core sitemap exclusions, all read from one option at request
            // time. See Crawler_Files.
            Crawler_Files::register_runtime_hooks();
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
            // In-admin AI chat (issue #73, PRO). The conversation CPT and the
            // purge that destroys conversations with their owner register
            // unconditionally, for the same reason the memory CPT above does:
            // a safety rule must not stop applying because a license lapsed.
            // If the type were unregistered on a lapsed install, the existing
            // conversations would become orphan rows that no deletion path
            // still claims. Only the routes and the screen are tier-gated.
            add_action('init', [Conversation_Store::class, 'register_post_type'], 5);
            Conversation_Store::register_user_deletion_hooks();
            // The route hook resolves the tier inside the callback and
            // self-no-ops, so no object is constructed at plugin load. That
            // matters here: the controller's Key_Vault needs aes-256-gcm, and
            // building it eagerly would turn an unsupported host into a
            // site-wide fatal instead of one unavailable feature.
            add_action('rest_api_init', static function (): void {
                if (! Gate::is_pro()) {
                    return;
                }
                (new Chat_Rest_Controller())->register_routes();
            });
            // Resolves a valid OAuth Bearer token to its bound WP user via
            // determine_current_user, so Registrar's existing capability
            // checks work for OAuth callers with no change to Registrar
            // itself. Also a no-op unless OAuth_Config::is_enabled().
            (new Bearer_Auth())->register();

            // Confines the gateway credential (#142) to the MCP connection and
            // binds it to its scoped Identity (issue #130), plus the
            // `wp wpmcp gateway-revoke` kill switch. src/Gateway ships on every
            // flavor, so this is unconditional.
            \WPMCP\Gateway\Gateway_Guard::register();
            // Handshake context injection (issue #80): swap the MCP
            // Adapter's initialize `instructions` for the admin-authored
            // text plus the permission-gated site summary. A no-op unless
            // the adapter (which owns this filter) is installed and fires it.
            add_filter(
                'mcp_adapter_initialize_response',
                [new Handshake_Instructions(), 'filter_initialize'],
                10,
                3
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
            // Snapshot retention (issue #158): an install that arrives with
            // a deeper history than the flat cap keeps it until the owner
            // decides, and this is the notice that asks.
            Snapshot_Retention_Notice::register();
            // Compact tool-surface mode (issue #79): in compact mode the
            // adapter's advertised tools/list collapses to the meta-tools
            // plus connection basics. Exposure-only - registration and
            // permissions are untouched - and a no-op unless the adapter
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
            // Mounts /wp-json/mcp/wpmcp-server, the JSON-RPC endpoint the
            // README and get-connection-info have always advertised. Without
            // it wp_register_ability() gives us abilities but no MCP
            // transport at all. See WPMCP\MCP\Server.
            Mcp_Server::register();
            // Public agent discovery documents (issue #302): the MCP Server
            // Card at <endpoint>/server-card, the AI Catalog and the Agent
            // Skills index under /.well-known/. Public metadata only; see
            // WPMCP\MCP\Discovery_Documents for what is never advertised.
            \WPMCP\MCP\Discovery_Endpoints::register();
            // Stdio MCP transport for WP-CLI-only and local workflows
            // (issue #77): `wp mcp-stdio serve`. No-op outside WP-CLI.
            Stdio_Transport::register();
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
            'label'       => self::BRAND,
            'description' => sprintf(
                /* translators: %s: product name (not translated) */
                __('Abilities provided by the %s plugin.', 'wpmcp'),
                self::BRAND
            ),
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
        $menu_title = $memory ? Memory_Page::badged(self::BRAND, $pending) : self::BRAND;

        // The history page views (and its Restore button rolls back) ALL
        // users' site-wide agent mutations, so it is gated at manage_options,
        // matching Restore_Controller::handle()'s ajax capability check.
        add_menu_page(
            self::BRAND,
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
            self::BRAND,
            self::BRAND,
            'manage_options',
            'wpmcp',
            [new History_Page(), 'render']
        );

        // Same manage_options gate as the top-level page and Restore_Controller's
        // ajax handler: this screen shows and can roll back every user's
        // site-wide agent mutations, so it needs the same trust level.
        add_submenu_page(
            'wpmcp',
            self::page_title(_x('Audit Log', 'admin menu', 'wpmcp')),
            _x('Audit Log', 'admin menu', 'wpmcp'),
            'manage_options',
            Audit_Log_Page::SLUG,
            [new Audit_Log_Page(), 'render']
        );

        // Handshake instructions (issue #80): the text on this screen is
        // broadcast to every connecting MCP client at initialize, so editing
        // it is a site-wide trust decision - manage_options, like the rest.
        add_submenu_page(
            'wpmcp',
            self::page_title(__('Handshake Instructions', 'wpmcp')),
            _x('Handshake', 'admin menu', 'wpmcp'),
            'manage_options',
            'wpmcp-handshake',
            [new Handshake_Settings_Page(), 'render']
        );

        // Connection manager (issue #76): provisions Application Passwords,
        // reveals them exactly once alongside filled client configs, serves
        // the desktop bundle, and hosts the master exposure switch - all
        // site-wide trust decisions, so manage_options like the rest.
        add_submenu_page(
            'wpmcp',
            self::page_title(_x('Connection', 'admin menu', 'wpmcp')),
            _x('Connection', 'admin menu', 'wpmcp'),
            'manage_options',
            Connection_Page::SLUG,
            [new Connection_Page(), 'render']
        );

        // Ability toggle grid (issue #78): sees and narrows the full MCP
        // ability surface - a site-wide trust decision, so manage_options
        // like the rest.
        add_submenu_page(
            'wpmcp',
            self::page_title(_x('Abilities', 'admin menu', 'wpmcp')),
            _x('Abilities', 'admin menu', 'wpmcp'),
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
            self::page_title(_x('Redirects', 'admin menu', 'wpmcp')),
            _x('Redirects', 'admin menu', 'wpmcp'),
            'manage_options',
            Redirects_Page::SLUG,
            [new Redirects_Page(), 'render']
        );

        // Agent skills (issue #74): the toggle on this screen decides whether
        // list-skills/get-skill are registered at all, i.e. what every
        // connecting agent is told about how to work on this site.
        add_submenu_page(
            'wpmcp',
            self::page_title(__('Agent Skills', 'wpmcp')),
            _x('Skills', 'admin menu', 'wpmcp'),
            'manage_options',
            Skills_Settings_Page::SLUG,
            [new Skills_Settings_Page(), 'render']
        );

        // In-admin AI chat (issue #73): the chat drives the same governed
        // ability surface as external MCP clients under the admin's own
        // identity, so viewing the screen is manage_options like the rest.
        // The entry appears only where the feature can actually run: no dead
        // menu item and no locked screen on installs without it.
        if (Gate::is_pro()) {
            add_submenu_page(
                'wpmcp',
                self::page_title(__('Chat', 'wpmcp')),
                __('Chat', 'wpmcp'),
                'manage_options',
                Chat_Page::SLUG,
                [new Chat_Page(), 'render']
            );
        }

        // Agent memory (issue #131). The target is the wpmcp_memory CPT list
        // table, not a bespoke screen: approving a proposal is WordPress's own
        // pending -> publish flow, with its list table, nonces, capability
        // checks and revisions, so there is no second approval path to audit.
        // Publishing an entry can enforce a server-side denial or broadcast
        // text to every connecting agent, hence manage_options like the rest.
        if ($memory) {
            add_submenu_page(
                'wpmcp',
                self::page_title(__('Agent Memory', 'wpmcp')),
                Memory_Page::badged(_x('Memory', 'admin menu', 'wpmcp'), $pending),
                'manage_options',
                Memory_Page::submenu_slug()
            );
        }
    }

    /**
     * The full declared ability surface (pre-gating; see
     * Registrar::declared()) for admin display. Lazily replays
     * register_abilities() when the registration hook has not fired in this
     * request - outside a wp_abilities_api_init window Registrar only fills
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
        $get_preview     = new Get_Preview_Link();
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
                    'terms'      => [ 'type' => 'object' ],
                    'meta'       => [ 'type' => 'object' ],
                    'session_id' => [ 'type' => 'string' ],
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
            'wpmcp/get-preview-link',
            'free',
            'Preview URL for a draft, pending or scheduled post you can edit',
            [
                'type'       => 'object',
                'properties' => [
                    'post_id' => [ 'type' => 'integer' ],
                ],
                'required'   => [ 'post_id' ],
            ],
            [$get_preview, 'handle'],
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
            'Delete a post, page or CPT entry. Trash by default (reversible). force:true deletes permanently, is off until the wpmcp_enable_delete_post filter opts in, needs confirm:true, and is snapshotted so it can be rolled back',
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
            'Duplicate a post, page or CPT entry with content, meta and terms, optionally with child posts. The copy is a draft unless another status is given. Editor bookkeeping meta (_edit_lock, _wp_old_slug) is skipped; builder data is copied',
            [
                'type'       => 'object',
                'properties' => [
                    'post_id'          => [ 'type' => 'integer' ],
                    'title'            => [ 'type' => 'string' ],
                    'status'           => [ 'type' => 'string', 'enum' => ['draft', 'pending', 'private', 'publish'] ],
                    'include_children' => [ 'type' => 'boolean' ],
                    'session_id'       => [ 'type' => 'string' ],
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
        $upload_media   = new Upload_Media();

        $registrar->register(new Ability(
            'wpmcp/get-media',
            'free',
            'Read a Media Library attachment: title, URL, every registered size, dimensions, mime type, alt text, caption and description',
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
            'Update an attachment\'s title, alt text, caption or description',
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
            'Delete a Media Library attachment. Off until the wpmcp_enable_delete_media filter opts in; needs confirm:true. force:true deletes permanently, snapshotted so it can be rolled back',
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
            'Add an image from a URL to the Media Library as a new attachment',
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
        $registrar->register(new Ability(
            'wpmcp/upload-media',
            'free',
            'Upload a file to the Media Library from base64 data. Type is sniffed from the bytes, not mime_type; executables and SVG refused; capped at the upload limit. Rollback deletes it',
            [
                'type'       => 'object',
                'properties' => [
                    'filename'   => [ 'type' => 'string' ],
                    'data'       => [ 'type' => 'string' ],
                    'mime_type'  => [ 'type' => 'string' ],
                    'title'      => [ 'type' => 'string' ],
                    'alt'        => [ 'type' => 'string' ],
                    'caption'    => [ 'type' => 'string' ],
                    'post_id'    => [ 'type' => 'integer' ],
                    'session_id' => [ 'type' => 'string' ],
                ],
                'required'   => [ 'filename', 'data' ],
            ],
            [$upload_media, 'handle'],
            'upload_files',
            'media',
            'create'
        ));

        $list_media          = new List_Media();
        $find_unused_media   = new Find_Unused_Media();
        $resize_media        = new Resize_Media();
        $upload_svg          = new Upload_Svg();
        $set_stock_key       = new Set_Stock_Key();
        $search_stock_images = new Search_Stock_Images();
        $import_stock_image  = new Import_Stock_Image();
        $insert_stock_image  = new Insert_Stock_Image();

        $registrar->register(new Ability(
            'wpmcp/list-media',
            'free',
            'List Media Library attachments, filtered by type ("image" or a mime like "image/png"), after/before dates and search, paged newest first with total/pages',
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
            'wpmcp/find-unused-media',
            'free',
            'Attachments nothing references (featured image, site logo/icon, content, builder data, post/term meta, options) with size on disk and checks run. Pass next_cursor as cursor until done; unattached:true lists parent-0 ones apart',
            [
                'type'       => 'object',
                'properties' => [
                    'cursor'     => [ 'type' => 'integer' ],
                    'per_page'   => [ 'type' => 'integer' ],
                    'type'       => [ 'type' => 'string' ],
                    'unattached' => [ 'type' => 'boolean' ],
                ],
            ],
            [$find_unused_media, 'handle'],
            'edit_posts',
            'media',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/resize-media',
            'free',
            'Regenerate the given registered image sizes of an attachment from its original and report each file (name, dimensions, URL). Snapshot-first with a file backup, so it can be rolled back',
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
            'Add an SVG to the Media Library from markup or an allowlisted URL. A fail-closed sanitizer rejects script, foreignObject, event handlers and external references; only sanitized markup is stored. Rollback deletes it',
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
            'Store (or clear with an empty api_key) your own pexels or unsplash API key. Keys are encrypted at rest with a site-salt-derived key and never echoed back',
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
            'Search openly-licensed stock images: openverse (keyless, Creative Commons), pexels and unsplash (own key via set-stock-key). Results carry license, license_url, attribution and source_url for import-stock-image',
            [
                'type'       => 'object',
                'properties' => [
                    'query'    => [ 'type' => 'string' ],
                    'provider' => [ 'type' => 'string', 'enum' => [ 'openverse', 'pexels', 'unsplash' ], 'default' => 'openverse' ],
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
            'Sideload a stock search result into the Media Library. SSRF-guarded: https only, host allowlist (wpmcp_remote_media_allowed_hosts filter) checked first, no redirects, size caps, bytes must verify as an image. Attribution and license are stored on the attachment; rollback deletes the import',
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
        $create_comment    = new Create_Comment();
        $reply_to_comment  = new Reply_To_Comment();

        $registrar->register(new Ability(
            'wpmcp/list-comments',
            'free',
            'List comments, optionally by post and status, with paging',
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
            'Read one comment',
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
            'Set a comment\'s status: approve, unapprove, spam, trash or untrash. Reversible',
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
            'Edit a comment\'s content or author name, email, url. Reversible',
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
            'Permanently delete a comment. Off until the wpmcp_enable_delete_comment filter opts in; needs confirm:true. Rollback restores it under a new ID',
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
        $registrar->register(new Ability(
            'wpmcp/create-comment',
            'free',
            'Comment on a post as you. status: approved (moderate_comments) or unapproved. Reversible',
            [
                'type'       => 'object',
                'properties' => [
                    'post_id'    => [ 'type' => 'integer' ],
                    'content'    => [ 'type' => 'string' ],
                    'status'     => [ 'type' => 'string' ],
                    'session_id' => [ 'type' => 'string' ],
                ],
                'required'   => [ 'post_id', 'content' ],
            ],
            [$create_comment, 'handle'],
            'edit_posts',
            'comments',
            'create'
        ));
        $registrar->register(new Ability(
            'wpmcp/reply-to-comment',
            'free',
            'Reply to comment id; as create-comment',
            [
                'type'       => 'object',
                'properties' => [
                    'id'         => [ 'type' => 'integer' ],
                    'content'    => [ 'type' => 'string' ],
                    'status'     => [ 'type' => 'string' ],
                    'session_id' => [ 'type' => 'string' ],
                ],
                'required'   => [ 'id', 'content' ],
            ],
            [$reply_to_comment, 'handle'],
            'edit_posts',
            'comments',
            'create'
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
            'Install a plugin from wordpress.org by slug. activate: true also needs activate_plugins and returns a rollbackable operation_id',
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
            'Install a theme from wordpress.org by slug. activate: true also needs switch_themes and returns rollbackable operation_ids',
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

        $search_themes = new Search_Themes();

        $registrar->register(new Ability(
            'wpmcp/search-themes',
            'free',
            'Search wordpress.org themes; filters as in search-plugins',
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
            [$search_themes, 'handle'],
            'install_themes',
            'packages',
            'read'
        ));

        $install_package_from_zip = new Install_Package_From_Zip();

        $registrar->register(new Ability(
            'wpmcp/install-package-from-zip',
            'pro',
            'Install or replace a plugin/theme from a Media Library ZIP. Off by default (wpmcp_enable_zip_install); needs confirm:true and its sha256. Rollbackable',
            [
                'type'       => 'object',
                'properties' => [
                    'attachment_id' => [ 'type' => 'integer' ],
                    'sha256'        => [ 'type' => 'string' ],
                    'type'          => [ 'type' => 'string', 'enum' => [ 'plugin', 'theme' ] ],
                    'confirm'       => [ 'type' => 'boolean' ],
                    'session_id'    => [ 'type' => 'string' ],
                ],
                'required'   => [ 'attachment_id', 'sha256', 'type', 'confirm' ],
            ],
            [$install_package_from_zip, 'handle'],
            'install_plugins',
            'packages',
            'create',
            false,
            true,
            false
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
            'Update rows matching a mandatory equality WHERE via $wpdb->update() (parameterized). Needs confirm:true and the wpmcp_enable_db_writes filter; refuses protected tables. Undo via rollback-operation when the table has a primary key and the WHERE fits the before-image cap, else recoverable:false and the before-image goes to the write audit log',
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
            'Delete rows matching a mandatory equality WHERE via $wpdb->delete() (parameterized). Needs confirm:true and the wpmcp_enable_db_writes filter; refuses protected tables. Undo via rollback-operation (rows reinserted with their ids) when the table has a primary key and the WHERE fits the before-image cap, else recoverable:false and the before-image goes to the write audit log',
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
            'Scan server config, WordPress internals (database size, autoloaded options, cron backlog, object cache, OPcache, plugin count) and a page (frontpage, or "url" / "post_id") for performance bottlenecks. Returns a scored report with ranked recommendations. Read-only',
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
            'Scan this site for malware (deep=true for the whole tree), core file integrity, hardening gaps and outdated software. Returns a 0-100 score, A-F grade and ranked fixes. Read-only',
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
            'Report active caching layers: the object cache backend (external or internal), OPcache, and any page-cache plugin (WP Rocket, W3 Total Cache, WP Super Cache, LiteSpeed Cache, WP Fastest Cache) detected by its functions or constants. Read-only',
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
            'Flush caches: object cache, all transients, OPcache when enabled, and any detected page-cache plugin through its own API. Returns what each layer cleared or lacked. Idempotent and not snapshotted, since a cache has no before-image to restore',
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
            'migration'      => fn () => $this->register_migration_abilities($registrar),
            'sync'           => fn () => $this->register_sync_abilities($registrar),
            'analysis'       => fn () => $this->register_analysis_abilities($registrar),
            'code'           => fn () => $this->register_code_abilities($registrar),
            'cli'            => fn () => $this->register_cli_abilities($registrar),
            'php_exec'       => fn () => $this->register_php_exec_abilities($registrar),
            'custom_code'    => fn () => $this->register_custom_code_abilities($registrar),
            'connect'        => fn () => $this->register_connect_abilities($registrar),
            'governance'     => fn () => $this->register_governance_abilities($registrar),
            'multisite'      => fn () => $this->register_multisite_abilities($registrar),
            'analytics'      => fn () => $this->register_analytics_abilities($registrar),
            'dispatch'       => fn () => $this->register_dispatch_abilities($registrar),
            'bridge'         => fn () => $this->register_bridge_abilities($registrar),
            'integration'    => fn () => $this->register_integration_abilities($registrar),
            'widget_builder' => fn () => $this->register_widget_builder_abilities($registrar),
            'block_builder'  => fn () => $this->register_block_builder_abilities($registrar),
            'theme_builder'  => fn () => $this->register_theme_builder_abilities($registrar),
            'cloud'          => fn () => $this->register_cloud_abilities($registrar),
            'gateway'        => fn () => $this->register_gateway_abilities($registrar),
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
     * The Elementor 4.0+ atomic write tools (issue #62).
     *
     * Registered only when the active builder can render atomic elements
     * (Atomic_Element::registration_supported()); detect-elementor-version is
     * registered alongside them unconditionally and reports what this pass
     * decided as `atomic_tools_registered`, so a caller can learn why these are
     * absent. Private, and reached only from register_elementor_abilities(), so
     * it stays behind the same group_enabled('elementor') check as every other
     * Elementor ability, matching register_acf_abilities() and the other
     * conditional groups.
     */
    private function register_atomic_elementor_abilities(Registrar $registrar): void
    {
        if (! Atomic_Element::registration_supported()) {
            Atomic_Element::note_registration(false);
            return;
        }

        // The full key set is enumerated once, on add-atomic-widget, and the
        // other three point at it. Repeating ~40 keys in four descriptions cost
        // most of a kilobyte of every tools/list payload
        // (tests/free/Platform/ToolsListBudgetTest.php).
        $style_doc = 'Optional flat "style" (color, background_color, font_size, padding, gap, width, ...; all keys on add-atomic-widget; raw "props" for the rest) becomes a local v4 style class; unknown keys or unusable values are errors, never dropped. ';

        $style_doc_full = sprintf(
            'An optional flat "style" object becomes a local v4 style class. Keys: %s, plus raw "props" for the rest. An unknown key or unusable value is an error, never dropped. ',
            implode(', ', Atomic_Styles::style_keys())
        );

        $add_flexbox = new Add_Flexbox();

        $registrar->register(new Ability(
            'wpmcp/add-flexbox',
            'pro',
            'Add an Elementor 4.0+ atomic flexbox (elType e-flexbox) to a page under parent_id (or top level) at an optional position. ' . $style_doc . 'Needs expected_hash from get-elementor-data. Undoable via rollback-operation',
            [
                'type'       => 'object',
                'properties' => [
                    'post_id'       => [ 'type' => 'integer' ],
                    'expected_hash' => [ 'type' => 'string' ],
                    'parent_id'     => [ 'type' => 'string' ],
                    'position'      => [ 'type' => 'integer' ],
                    'settings'      => [ 'type' => 'object' ],
                    'style'         => [ 'type' => 'object' ],
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
            'Add an Elementor 4.0+ atomic div-block (elType e-div-block) to a page under parent_id (or top level) at an optional position. ' . $style_doc . 'Needs expected_hash from get-elementor-data. Undoable via rollback-operation',
            [
                'type'       => 'object',
                'properties' => [
                    'post_id'       => [ 'type' => 'integer' ],
                    'expected_hash' => [ 'type' => 'string' ],
                    'parent_id'     => [ 'type' => 'string' ],
                    'position'      => [ 'type' => 'integer' ],
                    'settings'      => [ 'type' => 'object' ],
                    'style'         => [ 'type' => 'object' ],
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
            'Add an Elementor 4.0+ atomic widget (e-* widgetType such as e-heading, e-paragraph, e-button, e-image) to a page. Friendly params (title, content, text, image_url, alt, link) become typed $$type props for known types; any type also takes raw $$type-wrapped settings. ' . $style_doc_full . 'Needs expected_hash. Undoable via rollback-operation',
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
                    'style'         => [ 'type' => 'object' ],
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
            'Update an Elementor 4.0+ atomic widget\'s settings by element id. Friendly params map to typed $$type props for known types (only those passed change; other props survive); raw $$type-wrapped settings also work. ' . $style_doc . 'A style object rewrites its generated local style class. Needs expected_hash. Undoable via rollback-operation',
            [
                'type'       => 'object',
                'properties' => [
                    'post_id'       => [ 'type' => 'integer' ],
                    'expected_hash' => [ 'type' => 'string' ],
                    'element_id'    => [ 'type' => 'string' ],
                    'params'        => [ 'type' => 'object' ],
                    'settings'      => [ 'type' => 'object' ],
                    'style'         => [ 'type' => 'object' ],
                ],
                'required'   => [ 'post_id', 'expected_hash', 'element_id' ],
            ],
            [$update_atomic_widget, 'handle'],
            'edit_posts',
            'elementor',
            'update'
        ));

        // Read the answer back off the Registrar rather than restating the
        // predicate: register() also drops abilities the tier gate or governance
        // withholds, and detect-elementor-version reports this field.
        Atomic_Element::note_registration(null !== $registrar->get('wpmcp/add-atomic-widget'));
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
                'Read this site\'s APPROVED project memory: published facts, conventions and guardrails, published session summaries and the enforced block rules. Pending proposals are never returned (only their count), so an agent cannot read back its own unapproved suggestions as policy. Read-only',
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
                'Propose one durable memory entry. Stored PENDING and inert (not injected into sessions; severity=block not enforced) until an administrator publishes it in wp-admin. A severity=block proposal must name at least one target (tool:<ability>, post_id:<id>, post_type:<slug>); once published every matching call is refused in the permission check, so it is enforced, not advisory',
                $entry_props,
                ['text'],
            ],
            [
                'memory-save-summary',
                'create',
                new \WPMCP\Tools\Memory\Memory_Save_Summary(),
                'Record what a session changed as a pending session-summary entry. The facts are computed by the server from that session\'s snapshot rows, not from the supplied prose or any LLM, so it cannot over- or under-claim. Optional takeaways are filed as separate pending proposals',
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
            'List installed agent skills: versioned markdown playbooks for WordPress, Elementor and WooCommerce work (slug, name, description, version, tags). Bodies via get-skill. Skills whose required tools are missing are hidden unless include_unavailable is set. Read-only',
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

    /**
     * Site-local gateway credential lifecycle (issue #142, phase 1 of #130).
     *
     * Its own group, NOT part of 'cloud', and free tier. That looks odd for
     * a credential whose consumer is the multi-site proxy, and it is
     * deliberate: 'cloud' is pruned from the wp.org build
     * (scripts/flavors/wporg/strip.php drops src/Tools/Cloud and this
     * method's cloud sibling entirely) and excluded from the WooCommerce
     * vertical's FLAVOR_GROUPS. A credential that can be minted on a build
     * but not revoked on it is a security hole, and the issue's requirement
     * is explicit that revocation works locally with no network. So the
     * whole lifecycle lives where every flavor can reach it.
     *
     * All manage_options, domain 'gateway'. None of these touch the
     * network, so provisioning and revocation work with the cloud
     * unreachable.
     */
    private function register_gateway_abilities(Registrar $registrar): void
    {
        $confirm_schema = [
            'type'       => 'object',
            'properties' => ['confirm' => ['type' => 'boolean']],
            'required'   => ['confirm'],
        ];

        // The destructive and idempotent hints are overridden, not derived,
        // and both derived values would be wrong. 'create' would derive
        // destructive: false for gateway-provision, but the call
        // irreversibly kills the previous client secret, every refresh
        // token bound to it and every access token already minted from it;
        // MCP clients use destructiveHint for auto-approval, so the derived
        // value invites an agent to retry it over a live proxy credential.
        // 'delete' would derive idempotent: false for gateway-revoke, which
        // is documented and tested as safe to call repeatedly.
        //
        // Each registration is a literal `new Ability('wpmcp/...')` so the
        // wp.org free-tier assertion (scripts/flavors/wporg/assert-free-tier.php)
        // can see these free abilities in the built zip.
        $registrar->register(new Ability(
            'wpmcp/gateway-provision',
            'free',
            'Provision or rotate the site gateway credential. Returns client_id, client_secret and refresh_token once; the previous credential dies immediately. Carries the calling user\'s capabilities. Requires confirm: true',
            $confirm_schema,
            [new \WPMCP\Tools\Gateway\Gateway_Provision(), 'handle'],
            'manage_options',
            'gateway',
            'create',
            null,
            true,
            false
        ));

        $registrar->register(new Ability(
            'wpmcp/gateway-status',
            'free',
            'Whether the site gateway credential exists, its client_id and whether OAuth is enabled. Never returns secrets',
            [
                'type'       => 'object',
                'properties' => [],
            ],
            [new \WPMCP\Tools\Gateway\Gateway_Status(), 'handle'],
            'manage_options',
            'gateway',
            'read'
        ));

        $registrar->register(new Ability(
            'wpmcp/gateway-revoke',
            'free',
            'Revoke the site gateway credential and every token bound to it. Local, idempotent. Requires confirm: true',
            $confirm_schema,
            [new \WPMCP\Tools\Gateway\Gateway_Revoke(), 'handle'],
            'manage_options',
            'gateway',
            'delete',
            null,
            true,
            true
        ));
    }

    private function register_cloud_abilities(Registrar $registrar): void
    {
        $tools = [
            ['cloud-connect', 'update', new \WPMCP\Tools\Cloud\Cloud_Connect(), 'Connect this site to WP MCP Cloud: store the cloud url + api key and verify them by fetching the account. Returns the account on success. gateway_consent (default false) permits a gateway credential upload; false withdraws it and kills any the cloud holds', ['url' => ['type' => 'string'], 'key' => ['type' => 'string'], 'gateway_consent' => ['type' => 'boolean', 'default' => false]], ['url', 'key']],
            ['cloud-status', 'read', new \WPMCP\Tools\Cloud\Cloud_Status(), 'Report whether this site is connected to WP MCP Cloud, and where. Read-only', [], []],
            ['cloud-list-assets', 'read', new \WPMCP\Tools\Cloud\Cloud_List_Assets(), 'List the assets (widget/block specs) in this site\'s WP MCP Cloud account. Read-only', [], []],
            ['cloud-push-assets', 'update', new \WPMCP\Tools\Cloud\Cloud_Push_Assets(), 'Push this site\'s custom widget and block specs up to WP MCP Cloud (backup + reuse across sites). Optionally filter by type (widget|block)', ['types' => ['type' => 'array']], []],
            ['cloud-pull-assets', 'create', new \WPMCP\Tools\Cloud\Cloud_Pull_Assets(), 'Pull this site\'s WP MCP Cloud builder assets and recreate them as local custom widget/block specs (each validated first; refusals listed under skipped with a reason)', [], []],
            ['cloud-sync-settings', 'read', new \WPMCP\Tools\Cloud\Cloud_Sync_Settings(), 'Preview what would sync to WP MCP Cloud: governance toggles, MCP exposure switch, tool-exposure mode, skills switch. Never secrets or code-level gates (db writes, php exec, cli allowlist). Read-only', [], []],
            ['cloud-push-settings', 'update', new \WPMCP\Tools\Cloud\Cloud_Push_Settings(), 'Push the cloud-sync-settings posture plus identity scopes (no secrets) to WP MCP Cloud for cloud-apply-settings elsewhere. Paid. Changes nothing here', [], []],
            ['cloud-apply-settings', 'update', new \WPMCP\Tools\Cloud\Cloud_Apply_Settings(), 'Apply a posture: a cloud-sync-settings map, or (settings omitted) the last pushed one. Re-filtered to the allowlist; toggles and identities merge, scope fields only; never changes the MCP exposure switch or disables rollback-operation. Paid. Each write snapshotted; applied[i] pairs with operation_ids[i]; matching options listed as unchanged', ['settings' => ['type' => 'object'], 'session_id' => ['type' => 'string']], []],
            ['cloud-marketplace-browse', 'read', new \WPMCP\Tools\Cloud\Cloud_Marketplace_Browse(), 'Browse WP MCP Cloud marketplace widget and block specs, optionally by type and search. Read-only', ['type' => ['type' => 'string', 'enum' => ['widget', 'block']], 'search' => ['type' => 'string']], []],
            ['cloud-marketplace-install', 'create', new \WPMCP\Tools\Cloud\Cloud_Marketplace_Install(), 'Install a WP MCP Cloud marketplace listing by slug: validated like validate-widget-spec / validate-block-spec, template run through wp_kses_post, lands INACTIVE until set-widget-status / set-block-status. Refuses a name colliding with a local spec', ['slug' => ['type' => 'string']], ['slug']],
            ['cloud-gateway-provision', 'create', new \WPMCP\Tools\Cloud\Cloud_Gateway_Provision(), 'Mint the gateway credential bound to a scoped identity (MCP connection, that allowlist only) and upload it. Needs consent=true; replace=true kills a live one. Upload needs cloud-connect gateway_consent. Secrets shown ONCE. Revoke: gateway-revoke', ['identity' => ['type' => 'string'], 'consent' => ['type' => 'boolean'], 'replace' => ['type' => 'boolean'], 'upload' => ['type' => 'boolean']], ['identity', 'consent']],
            ['cloud-gateway-status', 'read', new \WPMCP\Tools\Cloud\Cloud_Gateway_Status(), 'Gateway credential\'s bound identity, upload and consent state. No secrets. Read-only', [], []],
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
            'Search all site text at once, including what post_content search misses: Elementor and Bricks settings, block attributes, template parts, reusable blocks and nav menus. Each hit has an addressable location (block path, element id or menu item id) and a snippet. Read-only; hits are re-checked against read_post. Empty index: run reindex-search',
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
            'Build or rebuild the search-content index. It updates on every save, so this is only for the first build or a full refresh after bulk or out-of-band changes. Cursor-based: each call indexes up to batch_size posts and returns next_offset',
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
     * Theme-builder site parts (issue #70): templates for header, footer, and
     * 404 assignable to contexts by include/exclude conditions, with
     * deterministic winner resolution (specificity > priority > id), rendered
     * into classic and block themes by the Render adapters.
     *
     * Named `site-part` rather than `template` on purpose: the Elementor
     * group already owns create-theme-template / resolve-theme-template /
     * apply-template for Elementor library documents, and an agent holding
     * both surfaces has to be able to tell them apart from the tool name
     * alone. These parts need no page builder.
     *
     * Engine is free with a cap of one template per part type, read from
     * Template_Store::cap_per_type(); unlimited templates and the granular
     * term / user_role rules lift on a licensed site. manage_options across
     * the group: these templates render site-wide markup, and the CPT is on
     * Content_Guard's internal list so the edit_posts content tools cannot
     * reach it either. Every write to an existing template is snapshot-first
     * through Safe_Mutation.
     */
    private function register_theme_builder_abilities(Registrar $registrar): void
    {
        $part_type         = [
            'type' => 'string',
            'enum' => \WPMCP\Tools\ThemeBuilder\Template_Store::PART_TYPES,
        ];
        $rule_schema       = [
            'type'       => 'object',
            'properties' => [
                'type'  => [
                    'type' => 'string',
                    'enum' => array_keys(\WPMCP\Tools\ThemeBuilder\Condition_Schema::RULE_TYPES),
                ],
                // Post type slug for post_type, post id for singular (omit
                // for any singular), term id for term, role slug for
                // user_role. The other rule types take no value.
                'value' => ['type' => ['string', 'integer']],
            ],
            'required'   => ['type'],
        ];
        // The include/exclude semantics are stated once, in the create
        // description, rather than repeated on both schemas (tools/list budget).
        $conditions_schema = [
            'type'       => 'object',
            'properties' => [
                'include' => ['type' => 'array', 'items' => $rule_schema],
                'exclude' => ['type' => 'array', 'items' => $rule_schema],
            ],
            'required'   => ['include'],
        ];
        $context_schema    = [
            'type'       => 'object',
            'properties' => [
                'is_front_page' => ['type' => 'boolean'],
                'is_404'        => ['type' => 'boolean'],
                'is_search'     => ['type' => 'boolean'],
                'is_archive'    => ['type' => 'boolean'],
                'is_singular'   => ['type' => 'boolean'],
                'post_type'     => ['type' => 'string'],
                'post_id'       => ['type' => 'integer'],
                'term_ids'      => ['type' => 'array', 'items' => ['type' => 'integer']],
                'user_roles'    => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
        ];
        $template_id       = ['type' => 'integer'];
        $create            = new \WPMCP\Tools\ThemeBuilder\Create_Site_Part();
        $list              = new \WPMCP\Tools\ThemeBuilder\List_Site_Parts();
        $resolve           = new \WPMCP\Tools\ThemeBuilder\Resolve_Site_Part();
        $update            = new \WPMCP\Tools\ThemeBuilder\Update_Site_Part();
        $set_status        = new \WPMCP\Tools\ThemeBuilder\Set_Site_Part_Status();
        $delete            = new \WPMCP\Tools\ThemeBuilder\Delete_Site_Part();

        $registrar->register(new Ability(
            'wpmcp/create-site-part',
            'free',
            'Create a header, footer or 404 site part, shown where an include rule matches and no exclude rule does; not the Elementor theme-template tools. Capped per part type; wpmcp/delete-site-part frees a slot',
            [
                'type'       => 'object',
                'properties' => [
                    'part_type'  => $part_type,
                    'title'      => ['type' => 'string'],
                    // Block markup; filtered with wp_kses_post on save.
                    'content'    => ['type' => 'string'],
                    'conditions' => $conditions_schema,
                    // Tie-break between equally specific matches; higher wins.
                    'priority'   => ['type' => 'integer'],
                ],
                'required'   => ['part_type', 'title', 'conditions'],
            ],
            [$create, 'handle'],
            'manage_options',
            'theme',
            'create'
        ));
        $registrar->register(new Ability(
            'wpmcp/list-site-parts',
            'free',
            'List site parts with their conditions and status, optionally by part_type. Read-only',
            [
                'type'       => 'object',
                'properties' => ['part_type' => $part_type],
            ],
            [$list, 'handle'],
            'manage_options',
            'theme',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/resolve-site-part',
            'free',
            'Which site part wins for a context (most specific, then priority), with every candidate and why. post_id alone fills post_type and term_ids. Read-only',
            [
                'type'       => 'object',
                'properties' => ['part_type' => $part_type, 'context' => $context_schema],
                'required'   => ['part_type'],
            ],
            [$resolve, 'handle'],
            'manage_options',
            'theme',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/update-site-part',
            'free',
            'Edit a site part; omitted fields are kept, part_type is fixed. Snapshot-first: operation_id rolls it back',
            [
                'type'       => 'object',
                'properties' => [
                    'template_id' => $template_id,
                    'title'       => ['type' => 'string'],
                    'content'     => ['type' => 'string'],
                    'conditions'  => $conditions_schema,
                    'priority'    => ['type' => 'integer'],
                ],
                'required'   => ['template_id'],
            ],
            [$update, 'handle'],
            'manage_options',
            'theme',
            'update'
        ));
        $registrar->register(new Ability(
            'wpmcp/set-site-part-status',
            'free',
            'Activate (publish) or deactivate (draft) a site part; only active parts resolve. Snapshot-first',
            [
                'type'       => 'object',
                'properties' => [
                    'template_id' => $template_id,
                    'status'      => ['type' => 'string', 'enum' => ['publish', 'draft']],
                ],
                'required'   => ['template_id', 'status'],
            ],
            [$set_status, 'handle'],
            'manage_options',
            'theme',
            'update'
        ));
        $registrar->register(new Ability(
            'wpmcp/delete-site-part',
            'free',
            'Trash a site part, freeing its per-part-type slot. Snapshot-first: operation_id rolls it back',
            [
                'type'       => 'object',
                'properties' => ['template_id' => $template_id],
                'required'   => ['template_id'],
            ],
            [$delete, 'handle'],
            'manage_options',
            'theme',
            'delete'
        ));
    }

    /**
     * The data-driven custom Gutenberg block builder (no eval):
     * store/validate/list block specs that a register_block_type render_callback
     * renders at runtime. All PRO, manage_options, domain 'blocks'.
     *
     * Block_Spec_Store stores the template verbatim for every caller. That is
     * the same premise Widget_Spec_Store (below) stopped relying on, since
     * manage_options is not unfiltered_html; the block builder still needs
     * the equivalent wp_kses_post gate, tracked as follow-up work on #178.
     */
    private function register_block_builder_abilities(Registrar $registrar): void
    {
        \WPMCP\Tools\Portable\Bundle_Kinds::register(new \WPMCP\Tools\BlockBuilder\Block_Bundle_Kind());
        $spec_schema = [ 'type' => 'object' ];

        $tools = [
            ['create-custom-block', 'create', new \WPMCP\Tools\BlockBuilder\Create_Custom_Block(), 'Create a custom Gutenberg block from a data spec (title, attributes, template with {{name}} placeholders). Validated, stored as a wpmcp_block post, and registered via register_block_type at runtime with a render_callback that interprets the template (no code generation, no eval). Remove with delete-custom-block', ['spec' => $spec_schema], ['spec']],
            ['update-custom-block', 'update', new \WPMCP\Tools\BlockBuilder\Update_Custom_Block(), 'Replace a custom block\'s spec by id (re-validated before it is stored). Snapshotted: returns an operation_id for rollback-operation', ['block_id' => ['type' => 'integer'], 'spec' => $spec_schema], ['block_id', 'spec']],
            ['get-custom-block', 'read', new \WPMCP\Tools\BlockBuilder\Get_Custom_Block(), 'Read one custom block\'s stored spec by id. Read-only', ['block_id' => ['type' => 'integer']], ['block_id']],
            ['list-custom-blocks', 'read', new \WPMCP\Tools\BlockBuilder\List_Custom_Blocks(), 'List the custom blocks on this site (id, name, title, active/inactive). Read-only', [], []],
            ['delete-custom-block', 'delete', new \WPMCP\Tools\BlockBuilder\Delete_Custom_Block(), 'Delete a custom block by moving it to the trash. Snapshotted: returns an operation_id for rollback-operation (restore-post also works)', ['block_id' => ['type' => 'integer']], ['block_id']],
            ['set-block-status', 'update', new \WPMCP\Tools\BlockBuilder\Set_Block_Status(), 'Enable (publish) or disable (draft) a custom block by id. Snapshotted: returns an operation_id for rollback-operation; a no-op status change writes nothing', ['block_id' => ['type' => 'integer'], 'status' => ['type' => 'string', 'enum' => \WPMCP\Tools\BlockBuilder\Set_Block_Status::STATUSES]], ['block_id', 'status']],
            ['validate-block-spec', 'read', new \WPMCP\Tools\BlockBuilder\Validate_Block_Spec(), 'Statically validate a custom-block spec (title, attributes, template) without storing it. Read-only', ['spec' => $spec_schema], ['spec']],
            ['list-block-control-types', 'read', new \WPMCP\Tools\BlockBuilder\List_Block_Control_Types(), 'List the attribute types a custom-block spec may use and the block.json type each maps to. Read-only', [], []],
        ];

        foreach ($tools as [$name, $op, $handler, $desc, $props, $required]) {
            // Every write (create/update/delete) records a ledger row under
            // a caller's session_id so rollback-session can undo them
            // together; a create's row is its creation row (issue #192).
            if (in_array($op, ['create', 'update', 'delete'], true)) {
                $props['session_id'] = [ 'type' => 'string' ];
            }
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
     * The data-driven custom Elementor widget builder (no eval):
     * store/validate/list widget specs that Dynamic_Widget renders at runtime.
     * All PRO, gated on manage_options, domain 'elementor'. That is site-wide
     * markup authoring, but manage_options is not unfiltered_html (a multisite
     * site admin has the first and not the second), so Widget_Spec_Store runs
     * the template through wp_kses_post for anyone lacking unfiltered_html.
     */
    private function register_widget_builder_abilities(Registrar $registrar): void
    {
        \WPMCP\Tools\Portable\Bundle_Kinds::register(new \WPMCP\Tools\WidgetBuilder\Widget_Bundle_Kind());
        $spec_schema = [ 'type' => 'object' ];

        $tools = [
            ['create-custom-widget', 'create', new \WPMCP\Tools\WidgetBuilder\Create_Custom_Widget(), 'Create a custom Elementor widget from a data spec (title, controls, template with {{name}} placeholders), stored as a wpmcp_widget post. Without unfiltered_html the template is wp_kses_post-filtered (template_filtered: true)', ['spec' => $spec_schema], ['spec']],
            ['update-custom-widget', 'update', new \WPMCP\Tools\WidgetBuilder\Update_Custom_Widget(), 'Replace a custom widget\'s spec by id (re-validated; same wp_kses_post gate as create)', ['widget_id' => ['type' => 'integer'], 'spec' => $spec_schema], ['widget_id', 'spec']],
            ['get-custom-widget', 'read', new \WPMCP\Tools\WidgetBuilder\Get_Custom_Widget(), 'Read a custom widget\'s stored spec by id. Read-only', ['widget_id' => ['type' => 'integer']], ['widget_id']],
            ['list-custom-widgets', 'read', new \WPMCP\Tools\WidgetBuilder\List_Custom_Widgets(), 'List this site\'s custom widgets (id, name, title, active/inactive). Read-only', [], []],
            ['delete-custom-widget', 'delete', new \WPMCP\Tools\WidgetBuilder\Delete_Custom_Widget(), 'Move a custom widget to the trash (reversible via restore-post)', ['widget_id' => ['type' => 'integer']], ['widget_id']],
            ['set-widget-status', 'update', new \WPMCP\Tools\WidgetBuilder\Set_Widget_Status(), 'Enable (publish) or disable (draft) a custom widget by id', ['widget_id' => ['type' => 'integer'], 'status' => ['type' => 'string']], ['widget_id', 'status']],
            ['validate-widget-spec', 'read', new \WPMCP\Tools\WidgetBuilder\Validate_Widget_Spec(), 'Validate a custom-widget spec without storing it. Read-only', ['spec' => $spec_schema], ['spec']],
            ['compile-custom-widget', 'update', new \WPMCP\Tools\WidgetBuilder\Compiler\Compile_Custom_Widget(), 'Compile a published custom-widget spec into a native Elementor widget; the plugin, never the agent, writes and lints the PHP. Off unless wpmcp_enable_widget_compiler is on; needs edit_files, honors DISALLOW_FILE_EDIT. set-widget-status disables it', ['widget_id' => ['type' => 'integer']], ['widget_id']],
            ['list-control-types', 'read', new \WPMCP\Tools\WidgetBuilder\List_Control_Types(), 'List the control types a custom-widget spec may use and their Elementor controls. Read-only', [], []],
        ];

        foreach ($tools as [$name, $op, $handler, $desc, $props, $required]) {
            // Every write (create/update/delete) records a ledger row under
            // a caller's session_id so rollback-session can undo them
            // together; a create's row is its creation row (issue #192).
            if (in_array($op, ['create', 'update', 'delete'], true)) {
                $props['session_id'] = [ 'type' => 'string' ];
            }
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
     * tools. Most pairs register unconditionally: availability is a call-time
     * concern for them (a missing host plugin yields a structured
     * integration_unavailable error, never a fatal). The forms adapters
     * (issue #66) opt out of that through
     * Integration_Dispatcher::registers_only_when_available() and register
     * only while their host plugin is loaded. This runs on
     * wp_abilities_api_init, after every plugin has loaded, so that check sees
     * the real answer.
     */
    private function register_integration_abilities(Registrar $registrar): void
    {
        $this->register_integrations($registrar, [
            new \WPMCP\Integrations\ACF_Integration(),
            new \WPMCP\Integrations\Contact_Form_7_Integration(),
            new \WPMCP\Integrations\Gravity_Tables_Integration(),
            new \WPMCP\Integrations\Modern_Events_Calendar_Integration(),
            new \WPMCP\Integrations\The_Events_Calendar_Integration(),
            new \WPMCP\Integrations\Give_Integration(),
            new \WPMCP\Integrations\Paid_Memberships_Pro_Integration(),
            new \WPMCP\Integrations\Meta_Box_Integration(),
            new \WPMCP\Integrations\Plugin_Data_Integration(),
            new \WPMCP\Integrations\Forminator_Integration(),
            new \WPMCP\Integrations\SureForms_Integration(),
            new \WPMCP\Integrations\MetForm_Integration(),
            new \WPMCP\Integrations\Theme_Integration(),
        ]);
        $this->register_forms_pack_abilities($registrar);
        $this->register_block_suite_abilities($registrar);
    }

    /**
     * Block suite packs (issue #287): Kadence Blocks, GenerateBlocks,
     * Spectra, Otter Blocks and the Blocksy companion blocks, plus pattern
     * import, behind one pro dispatcher pair that registers only while a supported
     * suite is loaded. Its own method so the WordPress.org directory build
     * drops it by name, with the pack's files.
     */
    private function register_block_suite_abilities(Registrar $registrar): void
    {
        $this->register_integrations($registrar, [
            new \WPMCP\Integrations\Block_Suites_Integration(),
        ]);
    }

    /**
     * The forms adapter pack (issue #66): WPForms, Gravity Forms, Formidable,
     * Ninja Forms and Fluent Forms, each a pro-tier dispatcher pair (the
     * adapters override tier()). Kept in its own method so the WordPress.org
     * directory build can drop the whole pack at build time, method and
     * adapter files together, rather than gating it at runtime.
     */
    private function register_forms_pack_abilities(Registrar $registrar): void
    {
        $this->register_integrations($registrar, [
            new \WPMCP\Integrations\Gravity_Forms_Integration(),
            new \WPMCP\Integrations\Formidable_Integration(),
            new \WPMCP\Integrations\WPForms_Integration(),
            new \WPMCP\Integrations\Ninja_Forms_Integration(),
            new \WPMCP\Integrations\Fluent_Forms_Integration(),
        ]);
    }

    /**
     * Register each integration's read/write pair, skipping one that asks to
     * register only while its host plugin is loaded when that plugin is not.
     *
     * @param \WPMCP\Integrations\Integration_Dispatcher[] $integrations
     */
    private function register_integrations(Registrar $registrar, array $integrations): void
    {
        foreach ($integrations as $integration) {
            if (! $integration->should_register()) {
                continue;
            }
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
            'Statically check a PHP snippet without running it: syntax validity (error message and line) and severity-tagged findings (eval, exec, shell_exec, backticks, obfuscation decoders, request-driven input, outbound HTTP). Read-only',
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

        $this->register_snippet_store_abilities($registrar);
    }

    /**
     * PHP snippet lifecycle store (issue #85): snippets as stored,
     * inactive-by-default objects around the existing
     * Php_Snippet_Guard/Php_Snippet_Validator surface. Everything here is
     * free tier: it stores and reads PHP source and never executes any of
     * it. Deactivation lives here too, deliberately ungated, so an
     * activated snippet can always be revoked. ACTIVATION is the pro,
     * exec-gated half and is registered in
     * register_php_exec_abilities() instead, next to run-php-snippet whose
     * gate chain it shares.
     *
     * Every write is snapshot-first via Safe_Mutation with object_type
     * 'php_snippet', i.e. PER RECORD (Snapshot::capture_php_snippet()).
     * Snapshotting the whole store option instead would mean rolling back
     * one snippet's creation deleted every snippet created after it.
     *
     * The static validator gates create/update, but it is an advisory
     * speed-bump, not a security boundary, and the descriptions say so:
     * a caller holding manage_options can defeat a line-based regex
     * denylist trivially. Capability, enablement and environment are the
     * real gates. Gated at manage_options like the other code tools.
     */
    private function register_snippet_store_abilities(Registrar $registrar): void
    {
        $create_php_snippet   = new Create_Php_Snippet();
        $list_php_snippets    = new List_Php_Snippets();
        $get_php_snippet      = new Get_Php_Snippet();
        $update_php_snippet   = new Update_Php_Snippet();
        $delete_php_snippet     = new Delete_Php_Snippet();
        $deactivate_php_snippet = new Deactivate_Php_Snippet();

        $registrar->register(new Ability(
            'wpmcp/create-php-snippet',
            'free',
            'Store a named PHP snippet, inactive, in the PHP snippet store (not an Elementor custom-code post: see create-code-snippet). Never executed; syntax errors or critical static findings refuse it (advisory check, not a security boundary). Activation is separate and gated. Snapshot-first, reversible per snippet',
            [
                'type'       => 'object',
                'properties' => [
                    'name'       => [ 'type' => 'string' ],
                    'code'       => [ 'type' => 'string' ],
                    'session_id' => [ 'type' => 'string' ],
                ],
                'required'   => [ 'name', 'code' ],
            ],
            [$create_php_snippet, 'handle'],
            'manage_options',
            'code',
            'create'
        ));

        $registrar->register(new Ability(
            'wpmcp/list-php-snippets',
            'free',
            'List STORED PHP SNIPPETS as summaries (id, name, status, static-check flag, timestamps) without code. Not Elementor custom code (see list-code-snippets). Read-only',
            [
                'type'       => 'object',
                'properties' => [],
            ],
            [$list_php_snippets, 'handle'],
            'manage_options',
            'code',
            'read'
        ));

        $registrar->register(new Ability(
            'wpmcp/get-php-snippet',
            'free',
            'Fetch one STORED PHP SNIPPET by id: code, status and last static-check report (advisory, not proof of safety). Not Elementor custom code (see get-code-snippet). Read-only',
            [
                'type'       => 'object',
                'properties' => [
                    'id' => [ 'type' => 'string' ],
                ],
                'required'   => [ 'id' ],
            ],
            [$get_php_snippet, 'handle'],
            'manage_options',
            'code',
            'read'
        ));

        $registrar->register(new Ability(
            'wpmcp/update-php-snippet',
            'free',
            'Update a stored PHP snippet\'s name and/or code. New code is re-checked statically (advisory, not a security boundary) and never executed; a code change resets it to inactive. Snapshot-first, reversible per snippet',
            [
                'type'       => 'object',
                'properties' => [
                    'id'         => [ 'type' => 'string' ],
                    'name'       => [ 'type' => 'string' ],
                    'code'       => [ 'type' => 'string' ],
                    'session_id' => [ 'type' => 'string' ],
                ],
                'required'   => [ 'id' ],
            ],
            [$update_php_snippet, 'handle'],
            'manage_options',
            'code',
            'update'
        ));

        $registrar->register(new Ability(
            'wpmcp/delete-php-snippet',
            'free',
            'Delete a stored PHP snippet by id (not an Elementor custom-code post: see delete-code-snippet). Snapshot-first, reversible per snippet (restored inactive); never executes anything',
            [
                'type'       => 'object',
                'properties' => [
                    'id'         => [ 'type' => 'string' ],
                    'session_id' => [ 'type' => 'string' ],
                ],
                'required'   => [ 'id' ],
            ],
            [$delete_php_snippet, 'handle'],
            'manage_options',
            'code',
            'delete'
        ));

        $registrar->register(new Ability(
            'wpmcp/deactivate-php-snippet',
            'free',
            'Deactivate a stored PHP snippet by id (reverse of activate-php-snippet). Not gated on the PHP execution opt-in, so an active snippet can always be revoked. Snapshot-first; never executes anything',
            [
                'type'       => 'object',
                'properties' => [
                    'id'         => [ 'type' => 'string' ],
                    'session_id' => [ 'type' => 'string' ],
                ],
                'required'   => [ 'id' ],
            ],
            [$deactivate_php_snippet, 'handle'],
            'manage_options',
            'code',
            'update'
        ));

        $this->register_bundle_abilities($registrar);
    }

    /**
     * Portable export and import bundles (issue #297): one versioned,
     * checksummed JSON bundle carries this site's stored PHP snippets, and
     * its custom block and widget specs where those builders are registered,
     * to another site with no cloud account in between. Each store adds its
     * own Bundle_Kind when its group registers, so a store this build or site
     * does not have contributes nothing and its items import as skipped.
     *
     * Import re-validates every item with its store's own validator and lands
     * it INACTIVE (snippets as text only; nothing is activated or executed on
     * any build), refuses or renames name collisions per on_conflict, and
     * records every creation under one session_id for rollback-session.
     * Two abilities rather than one op-switched tool, because export is
     * read-only and import is a write, and governance and the MCP annotations
     * key on the ability, not on an argument.
     */
    private function register_bundle_abilities(Registrar $registrar): void
    {
        \WPMCP\Tools\Portable\Bundle_Kinds::register(new \WPMCP\Tools\Code\Php_Snippet_Bundle_Kind());

        $registrar->register(new Ability(
            'wpmcp/export-bundle',
            'free',
            'Export PHP snippets, plus custom block/widget specs where present, as a checksummed JSON bundle for import-bundle; types and ids filter. Read-only',
            [
                'type'       => 'object',
                'properties' => [
                    'types' => [ 'type' => 'array' ],
                    'ids'   => [ 'type' => 'array' ],
                ],
            ],
            [new \WPMCP\Tools\Portable\Export_Bundle(), 'handle'],
            'manage_options',
            'code',
            'read'
        ));

        $registrar->register(new Ability(
            'wpmcp/import-bundle',
            'free',
            'Import an export-bundle: items are re-validated and created inactive; name clashes skip unless on_conflict=rename. Undo with rollback-session',
            [
                'type'       => 'object',
                'properties' => [
                    'bundle'      => [ 'type' => 'object' ],
                    'on_conflict' => [ 'type' => 'string', 'enum' => \WPMCP\Tools\Portable\Import_Bundle::ON_CONFLICT ],
                    'session_id'  => [ 'type' => 'string' ],
                ],
                'required'   => [ 'bundle' ],
            ],
            [new \WPMCP\Tools\Portable\Import_Bundle(), 'handle'],
            'manage_options',
            'code',
            'create'
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
            'Run an allowlisted wp-cli subcommand (e.g. "core version") and return stdout, stderr and exit code. Off by default (WPMCP_ALLOW_WP_CLI constant or wpmcp_allow_wp_cli filter); refused on production without a separate override; only subcommands on the wpmcp_wp_cli_allowlist filter run; shell metacharacters are rejected first',
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
            'Queue an allowlisted wp-cli command as a background job; returns its id at once (poll get-cli-job). Same gates as run-wp-cli: off by default (WPMCP_ALLOW_WP_CLI or wpmcp_allow_wp_cli), refused on production without a separate override, allowlisted subcommands and flags, no shell metacharacters; re-checked when the job runs (closing them stops queued jobs). timeout seconds (default 300, max 900). Refused while too many jobs are queued or running',
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
            'Return a background CLI job by id: status (queued/running/completed/failed/canceled), the dispatched command and, once run, size-capped stdout/stderr, exit code and timed-out flag, or the error that stopped it. Read-only',
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
        $run_php_snippet      = new Run_Php_Snippet();
        $activate_php_snippet = new Activate_Php_Snippet();

        $registrar->register(new Ability(
            'wpmcp/run-php-snippet',
            'pro',
            'Run a guarded, arbitrary PHP snippet; returns its return value, echoed output and any thrown error. REMOTE CODE EXECUTION: off by default (WPMCP_ALLOW_PHP_EXEC constant or wpmcp_allow_php_exec filter); refused on production or any unrecognized environment unless WPMCP_ALLOW_PHP_EXEC_ON_PRODUCTION is set too; snippets the static validator flags are rejected first (a speed-bump, not a security boundary). Not snapshotted; cannot be undone.',
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

        // activate-php-snippet (issue #85) is registered HERE, with the
        // executor, rather than beside its free CRUD siblings, for two
        // reasons. It is the exec gate's ability: it consumes
        // Php_Snippet_Guard::assert_execution_allowed(), the same chain
        // run-php-snippet clears. And every pro ability must live in a
        // method the wp.org strip deletes wholesale
        // (scripts/flavors/wporg/strip.php REMOVED_METHODS), or the
        // directory build's own "no 'pro' tier in this zip" gate fails on
        // the leftover literal. The free store CRUD stays where it is and
        // ships in that build; the activation surface does not.
        $registrar->register(new Ability(
            'wpmcp/activate-php-snippet',
            'pro',
            'Activate a STORED PHP SNIPPET by id (not Elementor custom code): flips the status flag, never executes it. Refused unless PHP execution is enabled (WPMCP_ALLOW_PHP_EXEC or wpmcp_allow_php_exec, default off) and the environment permits it, as for run-php-snippet. Every attempt is audited. Snapshot-first; rollback restores it inactive',
            [
                'type'       => 'object',
                'properties' => [
                    'id'         => [ 'type' => 'string' ],
                    'session_id' => [ 'type' => 'string' ],
                ],
                'required'   => [ 'id' ],
            ],
            [$activate_php_snippet, 'handle'],
            'manage_options',
            'code',
            'update'
        ));
    }

    /**
     * Register the custom CSS/JS injection tools (issue #63) as PRO-tier
     * abilities. add-scoped-css stores sanitized CSS scoped to ONE
     * post/page - or, with element_id, to ONE element on that page, by
     * prefixing the .elementor-element-<id> class the builder already renders
     * - in the plugin's own option-backed store, snapshot-first
     * via Safe_Mutation, so every write is reversible; site-wide CSS
     * deliberately stays with the existing wpmcp/add-custom-css ability
     * (Elementor group, core Additional CSS storage), so agents have one
     * path per scope instead of two competing site-wide ones. Css_Sanitizer
     * rejects anything script-capable on write AND again at render, against
     * a canonicalized form of the CSS so escape- and comment-obfuscated
     * spellings are covered; the adversarial corpus backing that claim is
     * tests/pro/CustomCode/CssSanitizerTest.php. The render-time pass is a
     * second chance at a value that arrived by some other route (direct DB
     * edit, another plugin), not an independent check. On top of the
     * ability's manage_options gate the handler also requires edit_css, the
     * bar core applies to Additional CSS. add-custom-js is an
     * XSS-class surface and follows the default-off, governance-gated
     * convention: registering the ability does not by itself allow any
     * write, because Add_Custom_Js::handle() refuses unless
     * Custom_Js_Guard::is_enabled() (WPMCP_ALLOW_JS_INJECTION constant or
     * wpmcp_allow_js_injection filter) AND the caller holds unfiltered_html
     * on top of the manage_options ability gate.
     *
     * Front-end output of the stored code is NOT wired here: see
     * register_custom_code_runtime_hooks(), reached from
     * register_builder_runtime_hooks().
     */
    private function register_custom_code_abilities(Registrar $registrar): void
    {
        $add_scoped_css = new Add_Scoped_Css();
        $add_custom_js  = new Add_Custom_Js();

        $registrar->register(new Ability(
            'wpmcp/add-scoped-css',
            'pro',
            'Store CSS for one post/page (post_id required), printed in wp_head only there. Pass css, or a selector plus bare declarations; element_id (Elementor) scopes it to .elementor-element-<id>. Appends; replace=true overwrites, css="" + replace=true clears. Site-wide: add-custom-css. Needs edit_css and manage_options. Refuses script-capable CSS (markup, expression(), script or data: URLs, @import), even obfuscated. Snapshot-first per page',
            [
                'type'       => 'object',
                'properties' => [
                    'css'        => [ 'type' => 'string' ],
                    'selector'   => [ 'type' => 'string' ],
                    'element_id' => [ 'type' => 'string' ],
                    'post_id'    => [ 'type' => 'integer' ],
                    'replace'    => [ 'type' => 'boolean' ],
                    'session_id' => [ 'type' => 'string' ],
                ],
                'required'   => [ 'css', 'post_id' ],
            ],
            [$add_scoped_css, 'handle'],
            'manage_options',
            'code',
            'update'
        ));
        $registrar->register(new Ability(
            'wpmcp/add-custom-js',
            'pro',
            'Store the site-wide JS snippet printed in wp_footer (replaces the previous one; js="" + replace=true clears). XSS-CLASS SURFACE, off by default: needs WPMCP_ALLOW_JS_INJECTION or the wpmcp_allow_js_injection filter, plus unfiltered_html and manage_options. Snapshot-first; closing the gate stops rendering stored JS',
            [
                'type'       => 'object',
                'properties' => [
                    'js'         => [ 'type' => 'string' ],
                    'replace'    => [ 'type' => 'boolean' ],
                    'session_id' => [ 'type' => 'string' ],
                ],
                'required'   => [ 'js' ],
            ],
            [$add_custom_js, 'handle'],
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
            'Schedule a recurring (recurrence checked against wp_get_schedules()) or single event. Refuses core-critical hooks (wp_version_check, wp_update_plugins/themes, wp_scheduled_delete, delete_expired_transients, wp_privacy_delete_old_export_files). Snapshots the cron option; rollback-operation restores it',
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
            'Unschedule one occurrence (wp_unschedule_event, given timestamp and matching args) or every event for a hook (wp_clear_scheduled_hook). Core hooks included, made safe by snapshotting the cron option: rollback-operation restores it',
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
            'Fire a scheduled cron hook now via do_action(), for debugging. Off until the wpmcp_enable_run_cron_event filter opts in; fires only hooks present in the cron array, always with their stored args. Not snapshotted: firing a hook is irreversible',
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
            'Turn maintenance mode on (wpmcp_maintenance option: enabled, message, retry_after seconds). Logged-out visitors and users without manage_options get a 503 with the message until it is disabled. Snapshotted; rollback-operation restores the prior state',
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
            'Turn maintenance mode off: sets enabled=false on the wpmcp_maintenance option (message and retry_after are preserved for a later re-enable). Snapshotted; rollback-operation restores the prior state',
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
     * Register the free-tier orientation reads: get-site-context (the site
     * level, parity gap tracked in issue #19) and get-page-snapshot (the page
     * level, issue #81).
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
            'Orientation payload: site name, URL, tagline, WordPress and PHP versions, theme, active plugins, public post types with counts, taxonomies, user count, locale, timezone, multisite, and active integrations (Elementor, WooCommerce, ACF, Yoast, RankMath). No admin email. Read-only',
            [
                'type'       => 'object',
                'properties' => [],
            ],
            [$get_site_context, 'handle'],
            'edit_posts',
            'context',
            'read'
        ));

        // get-page-snapshot (issue #81): the page-level counterpart, and a
        // FREE ability, which is why it is registered here rather than with
        // the pro analysis suite. The wp.org build deletes src/Tools/Analysis
        // and its registration method whole, so a free tool registered
        // there would vanish from the only build free users install;
        // tests/free/Platform/WporgFreeSurfaceTest.php gates that. Its pro
        // overlay sections attach via the wpmcp_page_snapshot_sections
        // filter, so this build renders it without any pro code present.
        $get_page_snapshot = new Get_Page_Snapshot();

        $registrar->register(new Ability(
            'wpmcp/get-page-snapshot',
            'free',
            'One-call page digest from stored post_content: structure counts, outline, media and link inventory, builder detection, SEO-lite signals (content_coverage flags builder page gaps). Heavy sections (global_tokens, responsive_overrides) are opt-in via sections. Size-capped. Read-only',
            [
                'type'       => 'object',
                'properties' => [
                    'post_id'  => [ 'type' => 'integer' ],
                    'sections' => [
                        // Deliberately not an enum: the built-in heavy
                        // sections are global_tokens and responsive_overrides,
                        // but unknown names are passed through to the
                        // wpmcp_page_snapshot_sections filter so a pro overlay
                        // can have its own opt-in section name.
                        'type'  => 'array',
                        'items' => [ 'type' => 'string' ],
                    ],
                ],
                'required'   => [ 'post_id' ],
            ],
            [$get_page_snapshot, 'handle'],
            'edit_posts',
            'context',
            'read'
        ));

        // get-rendered-html: what a logged-out visitor actually receives for
        // a page, fetched from this site's own host only (see the SSRF model
        // on the class). Free and in the 'context' group for the same reason
        // as get-page-snapshot above.
        $get_rendered_html = new Get_Rendered_Html();

        $registrar->register(new Ability(
            'wpmcp/get-rendered-html',
            'free',
            'Visitor-view HTML of a site page (post_id or url/path), chunked. Read-only',
            [
                'type'       => 'object',
                'properties' => [
                    'post_id'       => [ 'type' => 'integer' ],
                    'url'           => [ 'type' => 'string' ],
                    'chunk'         => [ 'type' => 'integer' ],
                    'offset'        => [ 'type' => 'integer' ],
                    'chunk_size'    => [ 'type' => 'integer' ],
                    'strip_scripts' => [ 'type' => 'boolean' ],
                    'text_only'     => [ 'type' => 'boolean' ],
                ],
            ],
            [$get_rendered_html, 'handle'],
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
            'List routes on this site\'s REST server (core and plugin namespaces): path, methods and a short args summary. Optional namespace and search filter by substring; limit caps rows (default 50, max 200). Read-only: never executes a route',
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
            'Run an internal REST request (rest_do_request) on any route here; returns status and body. The route\'s permission_callback runs as the current user, so access cannot widen. GET/HEAD always allowed; POST/PUT/PATCH/DELETE need the wpmcp_enable_rest_writes filter (off by default) and confirm:true, and report recoverable:false (not snapshotted)',
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
            'List registered block types: name, title, category, is_dynamic and attribute names. Optional category (exact) and search (substring on name) filters. Read-only',
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
            'Parse block markup into its block tree via parse_blocks(). Takes "blocks" (raw markup) or "id" (parses that post\'s post_content). Each node reports blockName, attrs, recursively parsed innerBlocks, and an innerHTML summary. Read-only',
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
            'Serialize a block tree (parse-blocks shape) back into block markup via serialize_blocks(). A pure transform that never touches a post; write the result with update-blocks',
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
            'Convert raw HTML to Gutenberg block markup: h1-h6, p, img, ul/ol, blockquote, pre/code, hr and table map to core blocks; anything else is wrapped in core/html so nothing is lost. Pure transform, never touches a post; write it with update-blocks',
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
        $this->register_site_template_abilities($registrar);
    }

    /**
     * Block theme templates, template parts and navigation menus (issue
     * #378). The content tools refuse wp_template, wp_template_part and
     * wp_navigation (Content_Guard), so these two are the way to change a
     * block theme's header, footer or layouts. Both take edit_theme_options,
     * as the site editor does. Every write is snapshot-first; a template
     * write is snapshotted by its key, so its rollback restores the prior
     * customization or its absence (the theme file). Global styles are not
     * covered here.
     */
    private function register_site_template_abilities(Registrar $registrar): void
    {
        $read   = new Site_Templates_Read();
        $write  = new Site_Templates_Write();
        $entity = [ 'type' => 'string', 'enum' => [ 'template', 'template_part', 'navigation' ] ];
        $id     = [ 'type' => [ 'string', 'integer' ] ];

        $registrar->register(new Ability(
            'wpmcp/site-templates-read',
            'free',
            'List the block theme\'s templates and parts (source theme/custom, area, customized) or wp_navigation menus; entity plus id reads one as parsed blocks and content_hash. Reports a classic theme. Read-only',
            [
                'type'       => 'object',
                'properties' => [
                    'entity' => $entity,
                    'id'     => $id,
                ],
            ],
            [$read, 'handle'],
            'edit_theme_options',
            'theme',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/site-templates-write',
            'free',
            'Edit a block theme template or part (id: slug or theme//slug) or wp_navigation menu (id). save: content or blocks, customizing over the theme file; add_block (content: one block)/update_block/remove_block: by path with expected_hash; revert: delete the customization. Snapshot-first',
            [
                'type'       => 'object',
                'properties' => [
                    'entity'        => $entity,
                    'id'            => $id,
                    'action'        => [ 'type' => 'string', 'enum' => [ 'save', 'add_block', 'update_block', 'remove_block', 'revert' ] ],
                    'content'       => [ 'type' => 'string' ],
                    'blocks'        => [ 'type' => 'array' ],
                    'path'          => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
                    'attrs'         => [ 'type' => 'object' ],
                    'inner_html'    => [ 'type' => 'string' ],
                    'expected_hash' => [ 'type' => 'string' ],
                    'title'         => [ 'type' => 'string' ],
                    'area'          => [ 'type' => 'string' ],
                    'session_id'    => [ 'type' => 'string' ],
                ],
                'required'   => [ 'entity', 'id' ],
            ],
            [$write, 'handle'],
            'edit_theme_options',
            'theme',
            'update'
        ));
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
            'Insert ONE block ("<!-- wp:... -->" markup) into a post at "path" (zero-based indexes into the parse-blocks tree; the last may equal the sibling count to append). Requires expected_hash (content_hash from parse-blocks); stale reads are refused. Snapshot-first; other blocks stay byte-identical',
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
            'Update ONE block in place by "path" (zero-based indexes into the parse-blocks tree, descending innerBlocks): replace "attrs" (full replacement) and/or "inner_html" (leaf blocks only; target a container\'s children by their own paths). Needs expected_hash (content_hash from parse-blocks); stale reads refused. Snapshot-first; every other block stays byte-identical',
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
            'Remove ONE block by "path" (zero-based indexes into the parse-blocks tree, descending innerBlocks); nested removals keep the container. Requires expected_hash (content_hash from parse-blocks); stale reads are refused. Snapshot-first, undoable via rollback-operation',
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
            'List registered block patterns: name, title, description, categories. Optional search (substring on name or title). Markup is not returned; insert-pattern inserts it server-side. Read-only',
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
            'Insert a registered block pattern\'s blocks into a post at "path" (add-block\'s path semantics; whitespace filler dropped). Requires expected_hash (content_hash from parse-blocks); stale reads are refused. Snapshot-first; existing blocks stay byte-identical',
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
            'Create a category, tag or custom taxonomy term. Snapshot-first; rollback-operation reverses the creation. Refuses an existing slug, a missing parent, or a parent on a non-hierarchical taxonomy',
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

        // Classic widget writes (issue #285). Each snapshots widget_{id_base}
        // and sidebars_widgets as one undo point; see Sidebar_Widget_Store.
        $widget_id = [ 'type' => 'string' ];
        $position  = [ 'type' => 'integer' ];
        $session   = [ 'type' => 'string' ];
        $registrar->register(new Ability(
            'wpmcp/create-sidebar-widget',
            'free',
            'Add a classic widget (registered id_base, incl. block) to a sidebar at a 0-based position, sanitized by its update(). Undoable',
            [
                'type'       => 'object',
                'properties' => [
                    'sidebar_id' => [ 'type' => 'string' ],
                    'id_base'    => [ 'type' => 'string' ],
                    'instance'   => [ 'type' => 'object' ],
                    'position'   => $position,
                    'session_id' => $session,
                ],
                'required'   => [ 'sidebar_id', 'id_base' ],
            ],
            [new Create_Sidebar_Widget(), 'handle'],
            'edit_theme_options',
            'structure',
            'create'
        ));
        $registrar->register(new Ability(
            'wpmcp/update-sidebar-widget',
            'free',
            'Merge settings into a classic widget (id like text-2) via its update(). Undoable',
            [
                'type'       => 'object',
                'properties' => [
                    'widget_id'  => $widget_id,
                    'instance'   => [ 'type' => 'object' ],
                    'session_id' => $session,
                ],
                'required'   => [ 'widget_id', 'instance' ],
            ],
            [new Update_Sidebar_Widget(), 'handle'],
            'edit_theme_options',
            'structure',
            'update'
        ));
        $registrar->register(new Ability(
            'wpmcp/move-sidebar-widget',
            'free',
            'Move a classic widget to a sidebar or wp_inactive_widgets at a 0-based position. Undoable',
            [
                'type'       => 'object',
                'properties' => [
                    'widget_id'  => $widget_id,
                    'sidebar_id' => [ 'type' => 'string' ],
                    'position'   => $position,
                    'session_id' => $session,
                ],
                'required'   => [ 'widget_id', 'sidebar_id' ],
            ],
            [new Move_Sidebar_Widget(), 'handle'],
            'edit_theme_options',
            'structure',
            'update'
        ));
        $registrar->register(new Ability(
            'wpmcp/delete-sidebar-widget',
            'free',
            'Delete a classic widget instance. Undoable',
            [
                'type'       => 'object',
                'properties' => [
                    'widget_id'  => $widget_id,
                    'session_id' => $session,
                ],
                'required'   => [ 'widget_id' ],
            ],
            [new Delete_Sidebar_Widget(), 'handle'],
            'edit_theme_options',
            'structure',
            'delete'
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
            'WXR export via core export_wp(), narrowed by content, author, start_date, end_date, status, to a protected uploads directory; returns path, size, count. Once per PHP process (core limit). mirror:true instead writes builder pages as stable JSON files for git (post_id, or all 50 per page). Read-only',
            [
                'type'       => 'object',
                'properties' => [
                    'content'    => [ 'type' => 'string' ],
                    'author'     => [ 'type' => 'integer' ],
                    'start_date' => [ 'type' => 'string' ],
                    'end_date'   => [ 'type' => 'string' ],
                    'status'     => [ 'type' => 'string' ],
                    'mirror'     => [ 'type' => 'boolean' ],
                    'post_id'    => [ 'type' => 'integer' ],
                    'page'       => [ 'type' => 'integer' ],
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
            'Import a WXR file as new posts (title, content, status, post_type, postmeta). Off by default (wpmcp_enable_import filter), needs confirm:true, not snapshotted (recoverable:false); returns created_post_ids for delete-post. mirror:true with post_id instead restores that page from its export-content mirror, undoable via rollback-operation',
            [
                'type'       => 'object',
                'properties' => [
                    'file'    => [ 'type' => 'string' ],
                    'confirm' => [ 'type' => 'boolean' ],
                    'mirror'  => [ 'type' => 'boolean' ],
                    'post_id' => [ 'type' => 'integer' ],
                ],
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
     * All seven are gated at manage_options, matching Export's and Cron's
     * capability (both are comparable site-operations-level tool groups, and
     * this plugin's only precedent for a stronger, pro-tier gate is the
     * Elementor deep-editing tools specifically, not "heavy" operations in
     * general). trigger-backup is 'create' (it creates a job record),
     * cancel-backup-job is 'update' (it transitions an existing job's
     * status), delete-backup-archive is 'delete'; get-backup-status,
     * list-backup-jobs and get-backup-manifest are 'read'.
     *
     * The backup job itself only reads site data and writes a backup
     * artifact file plus the wpmcp_backup_jobs option: it never mutates user
     * content, so none of the job tools are routed through Safe_Mutation and
     * none touch the safety core.
     *
     * restore-site-backup is the exception and is deliberately NOT routed
     * through Safe_Mutation either: a whole-database replace is outside the
     * per-object model Snapshot_Store captures, so a snapshot could not
     * undo it. Its rollback mechanism is the pre-restore database safety
     * archive it takes, unconditionally, before writing anything (issue
     * #190); a failed import is rolled back from that archive
     * automatically. It is registered with destructive=true and dry_run
     * defaulting to true, so an unconfirmed call only ever returns the
     * compatibility report.
     */
    private function register_backup_abilities(Registrar $registrar): void
    {
        $trigger_backup     = new Trigger_Backup();
        $get_backup_status  = new Get_Backup_Status();
        $list_backup_jobs   = new List_Backup_Jobs();
        $cancel_backup_job  = new Cancel_Backup_Job();
        $get_backup_manifest   = new Get_Backup_Manifest();
        $delete_backup_archive = new Delete_Backup_Archive();
        $restore_site_backup   = new Restore_Site_Backup();

        $registrar->register(new Ability(
            'wpmcp/trigger-backup',
            'free',
            'Queue a backup job run by WP-Cron and return its job id at once. type=full builds a portable site archive (zip: full SQL dump, wp-content, origin manifest) to restore or migrate; database, files and uploads narrow it to that scope; content is a WXR export via export-content',
            [
                'type'       => 'object',
                'properties' => [
                    'type'  => [
                        'type' => 'string',
                        'enum' => ['full', 'database', 'files', 'uploads', 'content'],
                    ],
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
        $registrar->register(new Ability(
            'wpmcp/get-backup-manifest',
            'free',
            'Read a completed site-backup archive\'s manifest by job id or path: origin site_url/home_url, table prefix, multisite, WordPress/PHP/plugin versions, scope, per-table row counts, BLOB tables and file count. Read-only; paths outside the site-backup directory are refused',
            [
                'type'       => 'object',
                'properties' => [
                    'job_id' => [ 'type' => 'integer' ],
                    'path'   => [ 'type' => 'string' ],
                ],
            ],
            [$get_backup_manifest, 'handle'],
            'manage_options',
            'backup',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/delete-backup-archive',
            'free',
            'Delete a site-backup archive by job id or path and report bytes freed. The job record stays, flagged deleted, so history never points at a missing file. Paths outside the site-backup directory are refused',
            [
                'type'       => 'object',
                'properties' => [
                    'job_id' => [ 'type' => 'integer' ],
                    'path'   => [ 'type' => 'string' ],
                ],
            ],
            [$delete_backup_archive, 'handle'],
            'manage_options',
            'backup',
            'delete'
        ));
        $registrar->register(new Ability(
            'wpmcp/restore-site-backup',
            'free',
            'Restore this site in place from a site-backup archive (job_id or path). dry_run defaults to TRUE: a report on format_version, scope (all or database), table prefix, multisite, WordPress downgrade, BLOB tables and a full db.sql parse (truncated dumps refused). dry_run=false first takes a database safety archive (job id returned; no restore if it fails), holds maintenance mode, imports each statement, and on failure reports it and rolls back. preserve_session (default true) keeps the caller signed in. include_files (default false, scope all) stages and swaps in wp-content. Refuses paths outside the site-backup dir',
            [
                'type'       => 'object',
                'properties' => [
                    'job_id'        => [ 'type' => 'integer' ],
                    'path'          => [ 'type' => 'string' ],
                    'include_files'    => [ 'type' => 'boolean' ],
                    'dry_run'          => [ 'type' => 'boolean' ],
                    'preserve_session' => [ 'type' => 'boolean' ],
                ],
            ],
            [$restore_site_backup, 'handle'],
            'manage_options',
            'backup',
            'update',
            false,
            true,
            true
        ));
    }

    /**
     * Site-to-site migration tools (issue #191). Phase 1 only so far:
     * rewrite-site-urls, the serialization-aware URL rewrite pass a
     * restored site needs when the target's URL differs from the origin's.
     * Free-tier at manage_options, matching the backup group it builds on
     * (and listed alongside it in every flavor allowlist: a vertical build
     * that can restore an archive needs the rewrite that follows a restore).
     * 'update' verb with explicit annotations: applying rewrites six core
     * tables in place (behind a database safety archive, not a per-object
     * snapshot), so destructive_hint is true and idempotent_hint false (a to_url containing from_url is not
     * safe to re-run) despite the 'update' default. The dry-run default
     * means the unconfirmed invocation is effectively read-only, but the
     * ability is classified by what it can do, not its default.
     *
     * Phase 2 is the push pair: push-site-archive on the source uploads an
     * archive in resumable chunks to receive-site-archive on the target,
     * over the target's own Abilities REST endpoint and authenticated as a
     * target user; the target verifies it, restores it through the #190
     * engine (pre-restore safety archive first) and runs this rewrite with
     * the manifest's URLs and its own. Both sit behind default-off opt-in
     * gates (Migration_Guard, listed in Opt_In_Gates). Both are 'update'
     * with destructive annotations: receive replaces the target database,
     * and a push with apply:true is what makes it do so.
     *
     * TODO(#191) phase 3: pull, initiated from the target.
     */
    private function register_migration_abilities(Registrar $registrar): void
    {
        $rewrite_site_urls    = new Rewrite_Site_Urls();
        $push_site_archive    = new Push_Site_Archive();
        $receive_site_archive = new Receive_Site_Archive();

        $registrar->register(new Ability(
            'wpmcp/rewrite-site-urls',
            'free',
            'Rewrite every embedded URL in the database from one site URL to another (options, postmeta, posts, termmeta, usermeta, comments), serialization-aware across plain, JSON-escaped, percent-encoded and scheme-relative forms; object values are refused and reported. dry_run (default) reports per-table counts; dry_run:false + confirm:true takes a database safety archive first or refuses, and returns its job_id for restore-site-backup to undo. Skips wpmcp_db_protected_tables (usermeta by default); never rewrites GUIDs',
            [
                'type'       => 'object',
                'properties' => [
                    'from_url' => [ 'type' => 'string' ],
                    'to_url'   => [ 'type' => 'string' ],
                    'dry_run'  => [ 'type' => 'boolean' ],
                    'confirm'  => [ 'type' => 'boolean' ],
                    'tables'   => [
                        'type'  => 'array',
                        'items' => [
                            'type' => 'string',
                            'enum' => ['options', 'postmeta', 'posts', 'termmeta', 'usermeta', 'comments'],
                        ],
                    ],
                ],
                'required'   => [ 'from_url', 'to_url' ],
            ],
            [$rewrite_site_urls, 'handle'],
            'manage_options',
            'migration',
            'update',
            false,
            true,
            false
        ));
        $registrar->register(new Ability(
            'wpmcp/push-site-archive',
            'free',
            'Push a site-backup archive (job_id or path; scope all or database) to another wpmcp site at target_url as its admin (target_user + target_app_password, or target_token). dry_run (default) only checks the target; dry_run:false + confirm:true uploads resumable chunks for max_seconds (call again to go on); apply:true also has the target restore it (safety archive first) and rewrite URLs. Needs the outgoing-migration opt-in here, incoming there',
            [
                'type'       => 'object',
                'properties' => [
                    'job_id'              => [ 'type' => 'integer' ],
                    'path'                => [ 'type' => 'string' ],
                    'target_url'          => [ 'type' => 'string' ],
                    'target_user'         => [ 'type' => 'string' ],
                    'target_app_password' => [ 'type' => 'string' ],
                    'target_token'        => [ 'type' => 'string' ],
                    'dry_run'             => [ 'type' => 'boolean' ],
                    'confirm'             => [ 'type' => 'boolean' ],
                    'apply'               => [ 'type' => 'boolean' ],
                    'include_files'       => [ 'type' => 'boolean' ],
                    'restart'             => [ 'type' => 'boolean' ],
                    'chunk_bytes'         => [ 'type' => 'integer' ],
                    'max_seconds'         => [ 'type' => 'integer' ],
                    'apply_timeout'       => [ 'type' => 'integer' ],
                ],
                'required'   => [ 'target_url' ],
            ],
            [$push_site_archive, 'handle'],
            'manage_options',
            'migration',
            'update',
            false,
            true,
            false
        ));
        $registrar->register(new Ability(
            'wpmcp/receive-site-archive',
            'free',
            'Target side of push-site-archive. action start (sha256, bytes, manifest; checked before upload, resumes), chunk (upload_id, offset, base64 data), status, apply (verify, restore with a safety archive, rewrite URLs; dry_run default, confirm:true applies). Needs the incoming-migration opt-in',
            [
                'type'       => 'object',
                'properties' => [
                    'action'        => [
                        'type' => 'string',
                        'enum' => ['start', 'chunk', 'status', 'apply'],
                    ],
                    'upload_id'     => [ 'type' => 'string' ],
                    'sha256'        => [ 'type' => 'string' ],
                    'bytes'         => [ 'type' => 'integer' ],
                    'manifest'      => [ 'type' => 'object' ],
                    'source_url'    => [ 'type' => 'string' ],
                    'offset'        => [ 'type' => 'integer' ],
                    'data'          => [ 'type' => 'string' ],
                    'dry_run'       => [ 'type' => 'boolean' ],
                    'confirm'       => [ 'type' => 'boolean' ],
                    'include_files' => [ 'type' => 'boolean' ],
                    'restart'       => [ 'type' => 'boolean' ],
                ],
                'required'   => [ 'action' ],
            ],
            [$receive_site_archive, 'handle'],
            'manage_options',
            'migration',
            'update',
            false,
            true,
            false
        ));

        // Snapshot-backed per post: every changed post goes through
        // Safe_Mutation under one returned session_id. Annotated like
        // its sibling (destructive, not idempotent: a replacement containing
        // the search text matches again on a second run).
        $find_replace_content = new Find_Replace_Content();

        $registrar->register(new Ability(
            'wpmcp/find-replace-content',
            'free',
            'Serialization-safe find and replace in post content, titles, excerpts or meta. dry_run (default) previews; apply returns a rollback-session id; over 10 posts needs confirm:true',
            [
                'type'       => 'object',
                'properties' => [
                    'search'         => [ 'type' => 'string' ],
                    'replace'        => [ 'type' => 'string' ],
                    'regex'          => [ 'type' => 'boolean' ],
                    'case_sensitive' => [ 'type' => 'boolean' ],
                    'fields'         => [
                        'type'  => 'array',
                        'items' => [ 'type' => 'string', 'enum' => ['content', 'title', 'excerpt', 'meta'] ],
                    ],
                    'meta_keys'      => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                    'post_types'     => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                    'statuses'       => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                    'post_ids'       => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
                    'max_matches'    => [ 'type' => 'integer' ],
                    'dry_run'        => [ 'type' => 'boolean' ],
                    'confirm'        => [ 'type' => 'boolean' ],
                ],
                'required'   => [ 'search', 'replace' ],
            ],
            [$find_replace_content, 'handle'],
            'manage_options',
            'migration',
            'update',
            false,
            true,
            false
        ));
    }

    /**
     * Local-live sync (issue #192): change-set export derived from the
     * snapshot ledger, inspection, and apply. The unit of sync is a set of
     * explicitly selected objects, never the whole database, so live-side
     * data the local copy has never seen (orders, comments, form entries) is
     * left alone by construction.
     *
     * build-change-set and get-change-set only read site data (build writes
     * one artifact file into the protected site-backup dir), so neither is
     * routed through Safe_Mutation. apply-change-set is the mutating half:
     * every update is snapshot-first through Safe_Mutation, every creation
     * records a creation row, all under one session so rollback-session
     * undoes a whole sync. It defaults to dry_run and is advertised as
     * destructive because it can overwrite live content (never without a
     * snapshot). Gated at manage_options like the backup group it builds on.
     * Free/Pro placement is an open question on the issue; registered free
     * so the feature is exercisable, revisit before release.
     */
    private function register_sync_abilities(Registrar $registrar): void
    {
        $build_change_set = new Build_Change_Set();
        $get_change_set   = new Get_Change_Set();
        $apply_change_set = new Apply_Change_Set();

        $registrar->register(new Ability(
            'wpmcp/build-change-set',
            'free',
            'Build a local-live sync change set (objects, media bytes, terms, templates, global classes, base revisions) from a ledger marker (session_id, operation_id or since_id) and/or object refs (post:ID, option:theme_mods_X, term:TAX:SLUG) as a JSON artifact in the site-backup dir. dry_run only lists it',
            [
                'type'       => 'object',
                'properties' => [
                    'session_id'   => [ 'type' => 'string' ],
                    'operation_id' => [ 'type' => 'string' ],
                    'since_id'     => [ 'type' => 'integer' ],
                    'objects'      => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                    'dry_run'      => [ 'type' => 'boolean' ],
                ],
            ],
            [$build_change_set, 'handle'],
            'manage_options',
            'sync',
            'create'
        ));
        $registrar->register(new Ability(
            'wpmcp/get-change-set',
            'free',
            'Inspect a change-set artifact before it is applied: origin, objects, dependencies, exclusions, truncation. include_objects=true adds full data; raw=true returns the artifact to pass to apply-change-set on the target. Read-only; site-backup dir only',
            [
                'type'       => 'object',
                'properties' => [
                    'path'            => [ 'type' => 'string' ],
                    'include_objects' => [ 'type' => 'boolean' ],
                    'raw'             => [ 'type' => 'boolean' ],
                ],
                'required'   => [ 'path' ],
            ],
            [$get_change_set, 'handle'],
            'manage_options',
            'sync',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/apply-change-set',
            'free',
            'Apply a change set (change_set from get-change-set raw=true, or path) here; dry_run defaults to true. Only selected objects are written, snapshot-first; media, terms and templates are only added; objects changed here since the base are refused unless keyed in force; deletions never apply. rollback-session undoes it',
            [
                'type'       => 'object',
                'properties' => [
                    'change_set' => [ 'type' => 'object' ],
                    'path'       => [ 'type' => 'string' ],
                    'dry_run'    => [ 'type' => 'boolean' ],
                    'force'      => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                    'session_id' => [ 'type' => 'string' ],
                ],
            ],
            [$apply_change_set, 'handle'],
            'manage_options',
            'sync',
            'update',
            false,
            true,
            true
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
            'Batch-update stored governance toggles by ability, domain and operation, e.g. {ability: {"wpmcp/delete-post": false}, domain: {"database": false}, operation: {"delete": false}}. Invalid entries are skipped and reported; only empty input throws',
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
            'Create or overwrite (by name) a scoped identity that, once active (wpmcp_current_identity filter), narrows usable abilities beyond capability and Governance. Optional domains/operations/abilities allowlists, mode (allow default, or deny) and exposure (full or compact) overriding the site-wide tool-surface mode',
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
            'Read-only sessions/users/pageviews summary over a date range (Y-m-d; default the 28 days ending yesterday) from the connected analytics provider. Errors when no provider is connected',
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
            'Read-only list of top pages by pageviews over a date range (Y-m-d; default the 28 days ending yesterday) from the connected analytics provider; limit default 10, max 100. Errors when no provider is connected',
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
            'Read-only clicks/impressions/ctr/position summary over a date range (Y-m-d; default the 28 days ending yesterday) from the connected Search Console provider. Errors when no provider is connected',
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
            'Read-only list of top search queries by clicks over a date range (Y-m-d; default the 28 days ending yesterday) from the connected Search Console provider; limit default 10, max 100. Errors when no provider is connected',
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
     *
     * get-site-health (issue #381) runs the Site Health tests and is gated
     * at view_site_health_checks, the capability core checks for the Site
     * Health screen itself.
     */
    private function register_diagnostics_abilities(Registrar $registrar): void
    {
        $get_debug_config = new Get_Debug_Config();
        $get_debug_log    = new Get_Debug_Log();
        $list_transients  = new List_Transients();
        $delete_transient = new Delete_Transient();
        $get_site_health  = new Get_Site_Health();

        $registrar->register(new Ability(
            'wpmcp/get-debug-config',
            'free',
            'Debug constants (WP_DEBUG, WP_DEBUG_LOG, WP_DEBUG_DISPLAY, SCRIPT_DEBUG, SAVEQUERIES) and, when logging is on, the debug.log path. No secrets',
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
            'Bounded tail (max 200 lines / 64KB) of the debug log, by default WP_CONTENT_DIR/debug.log or the WP_DEBUG_LOG path. A path argument is confined to WP_CONTENT_DIR',
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
            'List transients (name, expiry) with an optional search substring; limit default 50, max 500',
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
            'Delete one named transient. Not snapshotted: transients are cache data with nothing to restore',
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
        $registrar->register(new Ability(
            'wpmcp/get-site-health',
            'free',
            'Run Site Health tests, plugin-added ones included, as plain text. Async tests past timeout seconds (default 10, max 30) report not completed. cached: last full run',
            [
                'type'       => 'object',
                'properties' => [
                    'tests'   => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                    'timeout' => [ 'type' => 'integer' ],
                    'cached'  => [ 'type' => 'boolean' ],
                ],
            ],
            [$get_site_health, 'handle'],
            'view_site_health_checks',
            'diagnostics',
            'read'
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
            'Update one wp_options value by name. Refuses get-option\'s denylist; off until the wpmcp_enable_option_write filter opts in. Snapshotted; rollback-operation restores the prior value or removes a new option',
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
     * coverage grows without growing this advertised tool surface - pinned
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

        $get_global_settings = new Get_Global_Settings();

        $registrar->register(new Ability(
            'wpmcp/get-global-settings',
            'free',
            'Read the Elementor kit\'s global colors, typography, spacing and layout. Returns the settings_hash the global color and typography writes require. Read-only',
            [
                'type'       => 'object',
                'properties' => [],
            ],
            [$get_global_settings, 'handle'],
            'edit_posts',
            'elementor',
            'read'
        ));

        // A cache operation, not a content write: like clear-cache it is not
        // snapshotted, since generated CSS has no before-image worth restoring.
        $regenerate_elementor_css = new Regenerate_Elementor_Css();

        $registrar->register(new Ability(
            'wpmcp/regenerate-elementor-css',
            'free',
            'Rebuild Elementor CSS; all needs confirm',
            [
                'type'       => 'object',
                'properties' => [
                    'post_id' => [ 'type' => 'integer' ],
                    'confirm' => [ 'type' => 'boolean' ],
                ],
            ],
            [$regenerate_elementor_css, 'handle'],
            'edit_posts',
            'elementor',
            'update'
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
            'A page\'s parsed Elementor tree (id, elType, widgetType, settings, children). Large pages: summary=true (skeleton with labels and child/descendant counts), max_depth (cut nodes report truncated_children) or element_id for one subtree. Reports total_elements, returned_elements, truncated; data_hash covers the whole page, so windowed reads are a valid expected_hash. Read-only',
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
            'Update an Elementor element\'s settings by id, merging the given settings into its existing settings. Writes the page\'s _elementor_data, snapshot-first; undoable via rollback-operation',
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
            'Add a widget to a page\'s _elementor_data under parent_id (or top level) at an optional position. Cataloged widget_types (list-widgets) take typed params validated before any write; other registered widgets take raw settings. Needs expected_hash from get-elementor-data. Undoable via rollback-operation',
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
            'Patch a cataloged widget\'s settings by element id from typed params (add-widget\'s schema; see get-widget-schema), validated and merged. Non-cataloged widgets are refused toward update-element. Requires expected_hash from get-elementor-data. Undoable via rollback-operation',
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
            'Reparent an element by id: remove it and append it as a child of a new parent in the page\'s _elementor_data. Refuses moves into itself or its descendants. Undoable via rollback-operation (post snapshot)',
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
            'Generate a widget of any cataloged type from the curated schema (list-widgets / get-widget-schema) and insert it into a page\'s _elementor_data under parent_id, or at the top level, with a seedable element id. Unknown types and invalid settings are refused before any write. Undoable via rollback-operation',
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

        $update_global_colors = new Update_Global_Colors();

        $registrar->register(new Ability(
            'wpmcp/update-global-colors',
            'pro',
            'Update the active Elementor kit\'s global colors: system_colors entries patch the four system tokens by _id (color/title); custom_colors entries update one by _id or append. Colors must be hex. Needs expected_hash from get-global-settings. Undoable via rollback-operation',
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
            'Update the active Elementor kit\'s global typography: system_typography entries patch the four system tokens by _id; custom_typography entries update one by _id or append. typography_* fields merge in; setting a font enables custom typography so the token renders. Needs expected_hash from get-global-settings. Undoable via rollback-operation',
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

        $replace_system_colors = new Replace_System_Colors();

        $registrar->register(new Ability(
            'wpmcp/replace-system-colors',
            'pro',
            'Atomically replace all four Elementor system color slots (primary, secondary, text, accent) on the active kit: each exactly once with a valid hex color, or nothing is written. No "title" keeps the current one. Needs expected_hash from get-global-settings. Undoable via rollback-operation',
            [
                'type'       => 'object',
                'properties' => [
                    'expected_hash' => [ 'type' => 'string' ],
                    'system_colors' => [ 'type' => 'array' ],
                ],
                'required'   => [ 'expected_hash', 'system_colors' ],
            ],
            [$replace_system_colors, 'handle'],
            'manage_options',
            'elementor',
            'update'
        ));

        $replace_system_typography = new Replace_System_Typography();

        $registrar->register(new Ability(
            'wpmcp/replace-system-typography',
            'pro',
            'Atomically replace all four Elementor system typography slots (primary, secondary, text, accent) on the active kit: each exactly once, or nothing is written. Each entry has at least one typography_* field and nothing else (unknown keys refused); a font enables custom typography; no "title" keeps the current one. Needs expected_hash from get-global-settings. Undoable via rollback-operation',
            [
                'type'       => 'object',
                'properties' => [
                    'expected_hash'     => [ 'type' => 'string' ],
                    'system_typography' => [ 'type' => 'array' ],
                ],
                'required'   => [ 'expected_hash', 'system_typography' ],
            ],
            [$replace_system_typography, 'handle'],
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
        $this->register_global_variable_abilities($registrar);

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
            'Save a page\'s Elementor content as a reusable elementor_library template of template_type (page, section, container, header, footer, single, archive, popup, ...; default page). Not snapshotted (a create destroys nothing); remove with delete-post',
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
            'Copy a library template\'s content into a page with fresh ids that never collide, appended (default, optionally under parent_id at position) or replacing the whole page (mode=replace). Needs expected_hash from get-elementor-data. Undoable via rollback-operation',
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
            'Create an elementor_library template from an export-page or export-template envelope, also across sites. Element ids are regenerated; the envelope\'s page_settings and display conditions apply when present. Not snapshotted (a create destroys nothing); remove with delete-post',
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

        $export_template = new Export_Template();

        $registrar->register(new Ability(
            'wpmcp/export-template',
            'pro',
            'Export an elementor_library template as the portable envelope import-template accepts (element tree, page_settings, conditions, type, version), so it round-trips between sites intact. Read-only',
            [
                'type'       => 'object',
                'properties' => [
                    'template_id' => [ 'type' => 'integer' ],
                ],
                'required'   => [ 'template_id' ],
            ],
            [$export_template, 'handle'],
            'edit_posts',
            'elementor',
            'read'
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
            'Set where an Elementor theme template renders: conditions as part arrays (["include","singular","post"]) or slash strings ("include/general"), through Elementor Pro\'s conditions manager when present, else the same _elementor_conditions meta. Snapshot-first, undoable via rollback-operation',
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

        $resolve_theme_template = new Resolve_Theme_Template();

        $registrar->register(new Ability(
            'wpmcp/resolve-theme-template',
            'pro',
            'Which Elementor theme-builder template wins for a location (header, footer, single, archive, ...): every candidate with its conditions, specificity and matching excludes, plus the winner. post_type/post_id resolve against a real target; without them only specificity counts. Read-only',
            [
                'type'       => 'object',
                'properties' => [
                    'location'  => [ 'type' => 'string' ],
                    'post_type' => [ 'type' => 'string', 'description' => 'Optional context: the post type the location is being resolved for.' ],
                    'post_id'   => [ 'type' => 'integer', 'description' => 'Optional context: the specific post the location is being resolved for.' ],
                ],
                'required'   => [ 'location' ],
            ],
            [$resolve_theme_template, 'handle'],
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
            'Report the Elementor and Elementor Pro versions, supports_atomic (Elementor 4.0+), and atomic_tools_registered: the four atomic write tools register only on a builder that renders atomic elements, so check before assuming they exist. Read-only',
            [
                'type'       => 'object',
                'properties' => [],
            ],
            [$detect_elementor_version, 'handle'],
            'edit_posts',
            'elementor',
            'read'
        ));

        // Atomic write tools register only when the active builder can render
        // atomic elements (issue #62); detect-elementor-version stays
        // registered, and reports what this pass decided, so a caller can learn
        // why they are absent.
        $this->register_atomic_elementor_abilities($registrar);

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
            'Create an Elementor Custom Code snippet (elementor_snippet post: _elementor_code, _elementor_location, _elementor_priority). location: wp_head, wp_body_open or wp_footer. Stored on any site; renders where Elementor Pro Custom Code is active. Not snapshotted (a create destroys nothing); remove with delete-code-snippet',
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
     * Growing the library therefore never grows the advertised tool surface,
     * as pinned by tests/free/Platform/ToolsListBudgetTest.php.
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
            'List brand kits (bundled presets plus any from the wpmcp_brand_kits option or filter): slug, category, source, the four system colors, font families, logo flag. Filter by category, source (bundled/site) or search. Read-only',
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
            'Return one brand kit in the shape apply-brand-kit writes: system slots as hex, named swatches with _id, typography mapped to Elementor typography_* fields. Entries failing validation are listed in "invalid" and make the kit unappliable. Read-only',
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
            'Apply a brand kit to the active Elementor kit (system colors, swatches, the four system typography tokens, logo) as ONE snapshotted operation. Without confirm:true it only returns the per-token diff and settings_hash; with it, expected_hash must match. Any invalid entry refuses the whole kit. Undo: rollback-brand-kit or rollback-operation',
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
            'Undo a brand-kit apply by restoring its single snapshot (palette, swatches, typography, logo). Defaults to the most recent apply not yet rolled back; pass operation_id to target a specific apply',
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
     * requires expected_hash - the whole items map is rewritten on each call,
     * so without an optimistic lock a stale caller would silently drop a class
     * another agent just added - and is snapshotted as a dedicated
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
            'Create an Elementor v4 global class (Class Manager) with a label and styles; returns its g- id. Pass friendly "styles" and/or raw $$type "props", optional breakpoint/state; validated against Elementor\'s schema, so a property it would drop is refused. Needs expected_hash from list-global-classes. Undoable via rollback-operation',
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
            'Update an Elementor v4 global class by g- id: rename it and/or merge styles into one breakpoint + state variant (replace_variant:true replaces it), so other responsive or hover rules survive. Requires expected_hash from list-global-classes. Undoable via rollback-operation',
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
            'Delete an Elementor v4 global class by g- id. Without confirm:true it is a dry run listing every post applying the class; with it, the class set is snapshotted and the class deleted, so rollback-operation restores it. Requires expected_hash from list-global-classes',
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
            'Set the order of Elementor v4 global classes, which is their CSS source order and decides ties at equal specificity. Pass { order: [g-id, ...] }; omitted classes follow in current order and unknown ids are refused. Requires expected_hash from list-global-classes. Undoable',
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
     * Register the Elementor 4 global variable (design token) suite.
     *
     * Same conventions as the global class suite: pro tier, edit_posts to read
     * and manage_options to write (what Elementor's own variables REST API
     * asks for), expected_hash from list-global-variables on every write, and
     * a dedicated 'elementor_global_variables' snapshot of the kit's raw
     * variables record so rollback-operation restores it exactly. Writes go
     * through Elementor's Variables_Service. Registered unconditionally; the
     * tools refuse with 'unsupported' when the v4 variables module is absent.
     */
    private function register_global_variable_abilities(Registrar $registrar): void
    {
        $type_schema = [
            'type' => 'string',
            'enum' => array_keys(Global_Variable_Schema::TYPES),
        ];

        $list_global_variables = new List_Global_Variables();

        $registrar->register(new Ability(
            'wpmcp/list-global-variables',
            'pro',
            'List Elementor v4 global variables (color, font, size design tokens) with the state_hash the write tools need as expected_hash. Read-only',
            [
                'type'       => 'object',
                'properties' => [],
            ],
            [$list_global_variables, 'handle'],
            'edit_posts',
            'elementor',
            'read'
        ));

        $create_global_variable = new Create_Global_Variable();

        $registrar->register(new Ability(
            'wpmcp/create-global-variable',
            'pro',
            'Create an Elementor v4 global variable; returns its e-gv- id. label is the CSS variable name (letters, digits, - _). value: hex/rgb()/hsl() color, font family, size like 16px, or any CSS for custom-size (sizes need Elementor Pro). Needs expected_hash from list-global-variables. Undoable',
            [
                'type'       => 'object',
                'properties' => [
                    'expected_hash' => [ 'type' => 'string' ],
                    'label'         => [ 'type' => 'string' ],
                    'type'          => $type_schema,
                    'value'         => [ 'type' => 'string' ],
                ],
                'required'   => [ 'expected_hash', 'label', 'type', 'value' ],
            ],
            [$create_global_variable, 'handle'],
            'manage_options',
            'elementor',
            'create'
        ));

        $update_global_variable = new Update_Global_Variable();

        $registrar->register(new Ability(
            'wpmcp/update-global-variable',
            'pro',
            'Update an Elementor v4 global variable by e-gv- id: label, value, or size/custom-size switch (no other type change). Needs expected_hash from list-global-variables. Undoable',
            [
                'type'       => 'object',
                'properties' => [
                    'expected_hash' => [ 'type' => 'string' ],
                    'id'            => [ 'type' => 'string' ],
                    'label'         => [ 'type' => 'string' ],
                    'value'         => [ 'type' => 'string' ],
                    'type'          => $type_schema,
                ],
                'required'   => [ 'expected_hash', 'id' ],
            ],
            [$update_global_variable, 'handle'],
            'manage_options',
            'elementor',
            'update'
        ));

        $delete_global_variable = new Delete_Global_Variable();

        $registrar->register(new Ability(
            'wpmcp/delete-global-variable',
            'pro',
            'Delete an Elementor v4 global variable by e-gv- id. Without confirm:true it is a dry run listing the posts and classes using it. Needs expected_hash from list-global-variables. Undoable',
            [
                'type'       => 'object',
                'properties' => [
                    'expected_hash' => [ 'type' => 'string' ],
                    'id'            => [ 'type' => 'string' ],
                    'confirm'       => [ 'type' => 'boolean' ],
                ],
                'required'   => [ 'expected_hash', 'id' ],
            ],
            [$delete_global_variable, 'handle'],
            'manage_options',
            'elementor',
            'delete'
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
     * step, so any failure - including any single entry of a batch-update -
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
            'Create an Elementor layout element (container by default, or section/column) at the top level or under parent_id, at an optional position. Columns need a parent; widgets are never parents. Needs expected_hash from get-elementor-data; stale reads are refused. Undoable via rollback-operation',
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
            'Merge settings into an Elementor container, section or column by id: given keys are set, others survive. Widgets are refused (use update-element). Requires expected_hash from get-elementor-data. Undoable via rollback-operation',
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
            'Apply N Elementor element settings updates atomically under ONE snapshot: every {element_id, settings} is validated first, one unknown id refuses the batch, and any failure rolls it all back. Requires expected_hash from get-elementor-data. One rollback-operation undoes it',
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
            'Reorder the children of one Elementor parent (or the top level without parent_id) to an explicit id order, which must be an exact permutation of the current children. Requires expected_hash from get-elementor-data. Undoable via rollback-operation',
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
            'Deep-copy an Elementor element and its subtree with fresh ids, inserted right after the original. New ids use Elementor\'s 7-char hex format and are unique on the page. Requires expected_hash from get-elementor-data. Undoable via rollback-operation',
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
            'Search a page\'s Elementor tree by el_type, widget_type, setting_key + setting_value and/or css_class (AND-combined, at least one). Each match reports element_id, types, navigator label and ancestor path; the current data_hash is returned for chaining a mutation. Read-only',
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
            'Merge settings into a page\'s Elementor page settings (_elementor_page_settings): given keys are set, all others survive. Post field keys (post_title, post_status, template, ...) are refused, use the post tools. Needs expected_hash = settings_hash from get-elementor-data. Undoable via rollback-operation',
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
     * Register the WooCommerce store tools: the simple store tools plus the
     * deep operations catalog (woo-ops, woo-read, woo-write, issue #68).
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
     *
     * The catalog is the deep surface over the store's own wc/v3 REST API:
     * woo-ops lists the named ops, woo-read and woo-write dispatch one
     * in-process. All three carry manage_woocommerce at the ability layer;
     * the dispatchers then enforce per-op governance and the SAME per-op
     * capability split as the simple tools above (edit_shop_orders for
     * orders, notes and refunds, list_users to read customers, edit_users /
     * create_users to write them) before dispatching, so the catalog is never
     * looser than the tool covering the same data. They refuse to dispatch at
     * all when WooCommerce is inactive, returning a structured
     * integration_unavailable error rather than a bare rest_no_route 404.
     * woo-write routes every change to existing state through Safe_Mutation.
     *
     * Issue #195 depth: variation create/delete and bulk updates, coupons
     * (shop_coupon posts, 'post' snapshots) and tax rates (custom tables, the
     * dedicated 'wc_tax_rate' snapshot type). Each delete tool has its own
     * opt-in filter (wpmcp_enable_delete_variation, _coupon, _tax_rate) and
     * needs confirm:true. All free: the WooCommerce listing depends on it.
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
        $list_variations         = new List_Variations();
        $update_variation        = new Update_Variation();
        $list_low_stock          = new List_Low_Stock_Products();

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
            'Update a WooCommerce product\'s fields (price, stock, description, etc.). stock_status is accepted only while stock is unmanaged and never on a variable product. Snapshotted as a post; rollback-operation restores the prior price and stock exactly',
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
                    'stock_status'      => [ 'type' => 'string' ],
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
            'Delete a WooCommerce product (trash by default, force for permanent). Off until the wpmcp_enable_delete_product filter opts in; requires confirm:true. Snapshotted: rollback resurrects a force-deleted product at its id with price, stock and terms',
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
        $registrar->register(new Ability(
            'wpmcp/list-variations',
            'free',
            'List the variations of one variable WooCommerce product as safe summary rows (id, sku, attributes, prices, stock), with paging',
            [
                'type'       => 'object',
                'properties' => [
                    'product_id' => [ 'type' => 'integer' ],
                    'per_page'   => [ 'type' => 'integer' ],
                    'page'       => [ 'type' => 'integer' ],
                ],
                'required'   => [ 'product_id' ],
            ],
            [$list_variations, 'handle'],
            'manage_woocommerce',
            'woocommerce',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/update-variation',
            'free',
            'Update a WooCommerce variation: regular_price, sale_price, sku, status (publish or private), manage_stock, stock_quantity (integer) and stock_status (only while stock is unmanaged). Snapshotted; rollback-operation restores price and stock, keeps it attached to its parent and re-syncs the parent\'s price range',
            [
                'type'       => 'object',
                'properties' => [
                    'id'             => [ 'type' => 'integer' ],
                    'regular_price'  => [ 'type' => 'string' ],
                    'sale_price'     => [ 'type' => 'string' ],
                    'sku'            => [ 'type' => 'string' ],
                    'status'         => [ 'type' => 'string' ],
                    'manage_stock'   => [ 'type' => 'boolean' ],
                    'stock_quantity' => [ 'type' => 'integer' ],
                    'stock_status'   => [ 'type' => 'string' ],
                    'session_id'     => [ 'type' => 'string' ],
                ],
                'required'   => [ 'id' ],
            ],
            [$update_variation, 'handle'],
            'manage_woocommerce',
            'woocommerce',
            'update'
        ));
        $registrar->register(new Ability(
            'wpmcp/list-low-stock-products',
            'free',
            'List products and variations at or below a managed-stock threshold (default: the store\'s low-stock setting) or out of stock, as rows whose ids feed update-product/update-variation. Page while has_more is true. Read-only',
            [
                'type'       => 'object',
                'properties' => [
                    'threshold' => [ 'type' => 'integer' ],
                    'per_page'  => [ 'type' => 'integer' ],
                    'page'      => [ 'type' => 'integer' ],
                ],
            ],
            [$list_low_stock, 'handle'],
            'manage_woocommerce',
            'woocommerce',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/create-variation',
            'free',
            'Add a variation to a variable product. attributes maps each variation attribute to one of its options ("" or omitted = any); unknown attributes or options are refused. Prices, sku, status and stock follow update-variation\'s rules. Undo with delete-variation',
            [
                'type'       => 'object',
                'properties' => [
                    'product_id' => [ 'type' => 'integer' ],
                    'attributes' => [ 'type' => 'object' ],
                    'description' => [ 'type' => 'string' ],
                    'regular_price' => [ 'type' => 'string' ],
                    'sale_price' => [ 'type' => 'string' ],
                    'sku' => [ 'type' => 'string' ],
                    'status' => [ 'type' => 'string' ],
                    'manage_stock' => [ 'type' => 'boolean' ],
                    'stock_quantity' => [ 'type' => 'integer' ],
                    'stock_status' => [ 'type' => 'string' ],
                ],
                'required'   => [ 'product_id' ],
            ],
            [new Create_Variation(), 'handle'],
            'manage_woocommerce',
            'woocommerce',
            'create'
        ));
        $registrar->register(new Ability(
            'wpmcp/delete-variation',
            'free',
            'Permanently delete a product variation (there is no variation trash). Disabled until the site opts in via the wpmcp_enable_delete_variation filter; requires confirm:true. Snapshotted: rollback-operation resurrects it at the same id, attached to its parent',
            [
                'type'       => 'object',
                'properties' => [
                    'id' => [ 'type' => 'integer' ],
                    'confirm' => [ 'type' => 'boolean' ],
                    'session_id' => [ 'type' => 'string' ],
                ],
                'required'   => [ 'id', 'confirm' ],
            ],
            [new Delete_Variation(), 'handle'],
            'manage_woocommerce',
            'woocommerce',
            'delete'
        ));
        $registrar->register(new Ability(
            'wpmcp/bulk-update-products',
            'free',
            'Update up to 50 products and variations in one call; each item is {id, ...fields} as update-product or update-variation takes them, with the same rules and permission checks. Reports ok, operation_id or error per item; one failure does not stop the rest. rollback-session on the returned session_id undoes the batch',
            [
                'type'       => 'object',
                'properties' => [
                    'items' => [
                        'type'     => 'array',
                        'maxItems' => 50,
                        'items'    => [
                            'type'       => 'object',
                            'properties' => [ 'id' => [ 'type' => 'integer' ] ],
                            'required'   => [ 'id' ],
                        ],
                    ],
                    'session_id' => [ 'type' => 'string' ],
                ],
                'required'   => [ 'items' ],
            ],
            [new Bulk_Update_Products(), 'handle'],
            'manage_woocommerce',
            'woocommerce',
            'update'
        ));
        $registrar->register(new Ability(
            'wpmcp/list-coupons',
            'free',
            'List coupons as summary rows (id, code, status, type, amount, usage, expiry), with code search, status filter and paging. Read-only',
            [
                'type'       => 'object',
                'properties' => [
                    'search' => [ 'type' => 'string' ],
                    'status' => [ 'type' => 'string' ],
                    'per_page' => [ 'type' => 'integer' ],
                    'page' => [ 'type' => 'integer' ],
                ],
            ],
            [new List_Coupons(), 'handle'],
            'manage_woocommerce',
            'woocommerce',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/get-coupon',
            'free',
            'Read one coupon\'s full settings by id or code (limits, restrictions, usage). Read-only',
            [
                'type'       => 'object',
                'properties' => [
                    'id' => [ 'type' => 'integer' ],
                    'code' => [ 'type' => 'string' ],
                ],
            ],
            [new Get_Coupon(), 'handle'],
            'manage_woocommerce',
            'woocommerce',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/create-coupon',
            'free',
            'Create a coupon. Refuses a code already in use, an unknown discount_type, a percent over 100 or an invalid email restriction. Status defaults to draft. Undo with delete-coupon',
            [
                'type'       => 'object',
                'properties' => [
                    'code' => [ 'type' => 'string' ],
                    'discount_type' => [ 'type' => 'string' ],
                    'amount' => [ 'type' => [ 'string', 'number' ] ],
                    'description' => [ 'type' => 'string' ],
                    'date_expires' => [ 'type' => [ 'string', 'null' ] ],
                    'individual_use' => [ 'type' => 'boolean' ],
                    'free_shipping' => [ 'type' => 'boolean' ],
                    'exclude_sale_items' => [ 'type' => 'boolean' ],
                    'minimum_amount' => [ 'type' => [ 'string', 'number' ] ],
                    'maximum_amount' => [ 'type' => [ 'string', 'number' ] ],
                    'usage_limit' => [ 'type' => [ 'integer', 'null' ] ],
                    'usage_limit_per_user' => [ 'type' => [ 'integer', 'null' ] ],
                    'limit_usage_to_x_items' => [ 'type' => [ 'integer', 'null' ] ],
                    'product_ids' => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
                    'excluded_product_ids' => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
                    'product_categories' => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
                    'excluded_product_categories' => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
                    'email_restrictions' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                    'status' => [ 'type' => 'string' ],
                ],
                'required'   => [ 'code' ],
            ],
            [new Create_Coupon(), 'handle'],
            'manage_woocommerce',
            'woocommerce',
            'create'
        ));
        $registrar->register(new Ability(
            'wpmcp/update-coupon',
            'free',
            'Update a coupon\'s fields with the same checks as create-coupon. Snapshotted: rollback-operation restores every setting and usage history',
            [
                'type'       => 'object',
                'properties' => [
                    'id' => [ 'type' => 'integer' ],
                    'code' => [ 'type' => 'string' ],
                    'discount_type' => [ 'type' => 'string' ],
                    'amount' => [ 'type' => [ 'string', 'number' ] ],
                    'description' => [ 'type' => 'string' ],
                    'date_expires' => [ 'type' => [ 'string', 'null' ] ],
                    'individual_use' => [ 'type' => 'boolean' ],
                    'free_shipping' => [ 'type' => 'boolean' ],
                    'exclude_sale_items' => [ 'type' => 'boolean' ],
                    'minimum_amount' => [ 'type' => [ 'string', 'number' ] ],
                    'maximum_amount' => [ 'type' => [ 'string', 'number' ] ],
                    'usage_limit' => [ 'type' => [ 'integer', 'null' ] ],
                    'usage_limit_per_user' => [ 'type' => [ 'integer', 'null' ] ],
                    'limit_usage_to_x_items' => [ 'type' => [ 'integer', 'null' ] ],
                    'product_ids' => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
                    'excluded_product_ids' => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
                    'product_categories' => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
                    'excluded_product_categories' => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
                    'email_restrictions' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                    'status' => [ 'type' => 'string' ],
                    'session_id' => [ 'type' => 'string' ],
                ],
                'required'   => [ 'id' ],
            ],
            [new Update_Coupon(), 'handle'],
            'manage_woocommerce',
            'woocommerce',
            'update'
        ));
        $registrar->register(new Ability(
            'wpmcp/delete-coupon',
            'free',
            'Delete a coupon (trash by default, force for permanent). Disabled until the site opts in via the wpmcp_enable_delete_coupon filter; requires confirm:true. Snapshotted: rollback-operation restores it at the same id',
            [
                'type'       => 'object',
                'properties' => [
                    'id' => [ 'type' => 'integer' ],
                    'confirm' => [ 'type' => 'boolean' ],
                    'force' => [ 'type' => 'boolean' ],
                    'session_id' => [ 'type' => 'string' ],
                ],
                'required'   => [ 'id', 'confirm' ],
            ],
            [new Delete_Coupon(), 'handle'],
            'manage_woocommerce',
            'woocommerce',
            'delete'
        ));
        $registrar->register(new Ability(
            'wpmcp/validate-coupon',
            'free',
            'Check whether a coupon code would be accepted: published, expiry, usage limits, and (when email or subtotal is given) per-customer limit, email allow-list and spend rules. Each rule reports pass, fail or skipped; cart-dependent rules are flagged. Read-only',
            [
                'type'       => 'object',
                'properties' => [
                    'code' => [ 'type' => 'string' ],
                    'email' => [ 'type' => 'string' ],
                    'subtotal' => [ 'type' => [ 'string', 'number' ] ],
                ],
                'required'   => [ 'code' ],
            ],
            [new Validate_Coupon(), 'handle'],
            'manage_woocommerce',
            'woocommerce',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/list-tax-rates',
            'free',
            'List the store\'s tax classes and tax rates, filterable by class or country, with paging. Read-only',
            [
                'type'       => 'object',
                'properties' => [
                    'class' => [ 'type' => 'string' ],
                    'country' => [ 'type' => 'string' ],
                    'per_page' => [ 'type' => 'integer' ],
                    'page' => [ 'type' => 'integer' ],
                ],
            ],
            [new List_Tax_Rates(), 'handle'],
            'manage_woocommerce',
            'woocommerce',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/create-tax-rate',
            'free',
            'Create a tax rate. rate is a percentage from 0 to 100; country must be a known ISO code or "" for all; class must be standard or an existing class. Undo with delete-tax-rate',
            [
                'type'       => 'object',
                'properties' => [
                    'country' => [ 'type' => 'string' ],
                    'state' => [ 'type' => 'string' ],
                    'postcodes' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                    'cities' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                    'rate' => [ 'type' => [ 'string', 'number' ] ],
                    'name' => [ 'type' => 'string' ],
                    'priority' => [ 'type' => 'integer' ],
                    'compound' => [ 'type' => 'boolean' ],
                    'shipping' => [ 'type' => 'boolean' ],
                    'order' => [ 'type' => 'integer' ],
                    'class' => [ 'type' => 'string' ],
                ],
                'required'   => [ 'rate' ],
            ],
            [new Create_Tax_Rate(), 'handle'],
            'manage_woocommerce',
            'woocommerce',
            'create'
        ));
        $registrar->register(new Ability(
            'wpmcp/update-tax-rate',
            'free',
            'Update a tax rate with the same checks as create-tax-rate; postcodes and cities replace the whole list. Snapshotted: rollback-operation restores the row and its locations',
            [
                'type'       => 'object',
                'properties' => [
                    'id' => [ 'type' => 'integer' ],
                    'country' => [ 'type' => 'string' ],
                    'state' => [ 'type' => 'string' ],
                    'postcodes' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                    'cities' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                    'rate' => [ 'type' => [ 'string', 'number' ] ],
                    'name' => [ 'type' => 'string' ],
                    'priority' => [ 'type' => 'integer' ],
                    'compound' => [ 'type' => 'boolean' ],
                    'shipping' => [ 'type' => 'boolean' ],
                    'order' => [ 'type' => 'integer' ],
                    'class' => [ 'type' => 'string' ],
                    'session_id' => [ 'type' => 'string' ],
                ],
                'required'   => [ 'id' ],
            ],
            [new Update_Tax_Rate(), 'handle'],
            'manage_woocommerce',
            'woocommerce',
            'update'
        ));
        $registrar->register(new Ability(
            'wpmcp/delete-tax-rate',
            'free',
            'Delete a tax rate. Disabled until the site opts in via the wpmcp_enable_delete_tax_rate filter; requires confirm:true. Snapshotted: rollback-operation restores it at the same id',
            [
                'type'       => 'object',
                'properties' => [
                    'id' => [ 'type' => 'integer' ],
                    'confirm' => [ 'type' => 'boolean' ],
                    'session_id' => [ 'type' => 'string' ],
                ],
                'required'   => [ 'id', 'confirm' ],
            ],
            [new Delete_Tax_Rate(), 'handle'],
            'manage_woocommerce',
            'woocommerce',
            'delete'
        ));
        $registrar->register(new Ability(
            'wpmcp/plan-product-import',
            'free',
            'Plan a product import; writes nothing. Rows: sku, name, type (simple/variable), regular_price, sale_price, stock, categories, attributes, variations, images (media ids or https URLs), status. Per row: create, update (diff), skip or error; plus a plan_hash',
            [
                'type'       => 'object',
                'properties' => [
                    'rows' => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ],
                    'mode' => [ 'type' => 'string', 'enum' => [ 'upsert', 'create', 'update' ] ],
                ],
                'required'   => [ 'rows' ],
            ],
            [new Plan_Product_Import(), 'handle'],
            'manage_woocommerce',
            'woocommerce',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/apply-product-import',
            'free',
            'Apply a plan-product-import plan: same rows and mode plus its plan_hash. Refused if the store or rows changed; confirm:true above the threshold. rollback-session on its session_id undoes it',
            [
                'type'       => 'object',
                'properties' => [
                    'rows' => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ],
                    'mode' => [ 'type' => 'string', 'enum' => [ 'upsert', 'create', 'update' ] ],
                    'plan_hash' => [ 'type' => 'string' ],
                    'confirm' => [ 'type' => 'boolean' ],
                ],
                'required'   => [ 'rows', 'plan_hash' ],
            ],
            [new Apply_Product_Import(), 'handle'],
            'manage_woocommerce',
            'woocommerce',
            'create'
        ));

        // Deep WooCommerce operations catalog (issue #68). The tools above
        // stay the simple surface; the catalog dispatchers template internal
        // wc/v3 REST routes through their own in-process dispatch
        // (Wc_Rest_Dispatch for reads, Wc_Rest_Write_Dispatch for writes,
        // both free of any dependency on src/Tools/Rest or src/Integrations,
        // which the vertical wpmcp-for-woocommerce build prunes), so
        // authorization is the target endpoint's own permission_callback
        // running as the current user, on top of per-op governance and the
        // per-op capability (Op_Guard). woo-write snapshots every change to
        // existing state through Safe_Mutation, keeps destructive ops off
        // until a site opts in and behind confirm:true, and batches by
        // running each item through the same gates.
        // The handlers are constructed inline so the directory build's strip,
        // which deletes these registrations whole, leaves the catalog classes
        // unreferenced and sweeps them out of that zip.
        $registrar->register(new Ability(
            'wpmcp/woo-ops',
            'pro',
            'List WooCommerce ops (e.g. orders.get) by domain: products, variations, orders, refunds, coupons, customers, reviews, reports, shipping, taxes, webhooks, settings, gateways, system-status. Each op: mode, route, path params, capability, confirm, enabled, snapshot type, full-rollback flag. Read-only',
            [
                'type'       => 'object',
                'properties' => [
                    'domain' => [ 'type' => 'string' ],
                ],
            ],
            [new Woo_Ops(), 'handle'],
            'manage_woocommerce',
            'woocommerce',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/woo-read',
            'pro',
            'Run one read op from woo-ops as an in-process wc/v3 request as the current user, gated by op capability and governance (wpmcp/woo-{op}, dots as dashes). Path params fill the route, the rest are query. Returns the raw wc/v3 body (may include personal data; secrets redacted); 20 per page, max 50. Read-only',
            [
                'type'       => 'object',
                'properties' => [
                    'op'     => [ 'type' => 'string' ],
                    'params' => [ 'type' => 'object' ],
                ],
                'required'   => [ 'op' ],
            ],
            [new Woo_Read(), 'handle'],
            'manage_woocommerce',
            'woocommerce',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/woo-write',
            'pro',
            'Run one woo-ops write op, or a batch of up to 25, as in-process wc/v3 requests as the current user, gated like woo-read. Path params fill the route, the rest are the body (query for deletes). Changes to existing state are snapshotted (operation_id for rollback-operation); creates return recoverable:false and an undo_op. Refunds are not recoverable and call the gateway only with api_refund:true. Deletes and refunds need the wpmcp_woo_op_enabled filter and confirm:true. A batch prechecks every item, refuses whole on any failure, and shares one session_id for rollback-session',
            [
                'type'       => 'object',
                'properties' => [
                    'op'         => [ 'type' => 'string' ],
                    'params'     => [ 'type' => 'object' ],
                    'batch'      => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'op'     => [ 'type' => 'string' ],
                                'params' => [ 'type' => 'object' ],
                            ],
                            'required'   => [ 'op' ],
                        ],
                    ],
                    'confirm'    => [ 'type' => 'boolean' ],
                    'session_id' => [ 'type' => 'string' ],
                ],
            ],
            [new Woo_Write(), 'handle'],
            'manage_woocommerce',
            'woocommerce',
            'update',
            null,
            true,
            false
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
            'Update a navigation menu item\'s title, url, parent, or position. Snapshotted as a post; rollback-operation restores the prior values exactly',
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
            'Remove an item from a navigation menu. Snapshotted as a post; rollback-operation resurrects it at its original id, re-attached to its menu',
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
            'Assign a navigation menu to a registered theme location (the nav_menu_locations theme_mod). Snapshotted; rollback-operation restores the prior assignment',
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
            'Set one or more ACF field values on a post via update_field(). Snapshotted as a post; rollback-operation restores the prior values exactly. Disabled by default (site must opt in via the wpmcp_enable_acf_write filter)',
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
     * update-seo-meta are registered conditionally on SEO_Adapter detecting a
     * supported plugin, following the same conditional-registration pattern
     * as the ACF tool group: no supported plugin has a free/pro split of its
     * own to key off, so plugin absence is the only signal, and skipping
     * keeps these out of the catalog on sites running none of them.
     */
    private function register_seo_abilities(Registrar $registrar): void
    {
        $get_seo_status = new Get_SEO_Status();

        $registrar->register(new Ability(
            'wpmcp/get-seo-status',
            'free',
            'Report which SEO plugin is active on this site, by name and version',
            [
                'type'       => 'object',
                'properties' => [],
            ],
            [$get_seo_status, 'handle'],
            'edit_posts',
            'seo',
            'read'
        ));

        $registrar->register(new Ability(
            'wpmcp/get-crawler-files',
            'free',
            'robots.txt (served output, physical file, managed rules), llms.txt, and the served sitemap (core or SEO plugin) with its post types',
            [
                'type'       => 'object',
                'properties' => [],
            ],
            [new Get_Crawler_Files(), 'handle'],
            'edit_posts',
            'seo',
            'read'
        ));

        $registrar->register(new Ability(
            'wpmcp/update-crawler-files',
            'free',
            'Add robots.txt rules (filter, never the file), build llms.txt from pages, or exclude post types, taxonomies or posts from the core sitemap (lists replace). Refused if a physical file or the SEO plugin owns it. Snapshotted',
            [
                'type'       => 'object',
                'properties' => [
                    'robots_rules' => [ 'type' => 'string' ],
                    'llms'         => [
                        'type'       => 'object',
                        'properties' => [
                            'enabled' => [ 'type' => 'boolean' ],
                            'title'   => [ 'type' => 'string' ],
                            'summary' => [ 'type' => 'string' ],
                            'pages'   => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ],
                        ],
                    ],
                    'sitemap'      => [
                        'type'       => 'object',
                        'properties' => [
                            'exclude_post_types' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                            'exclude_taxonomies' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                            'exclude_post_ids'   => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
                        ],
                    ],
                    'session_id'   => [ 'type' => 'string' ],
                ],
            ],
            [new Update_Crawler_Files(), 'handle'],
            'manage_options',
            'seo',
            'update'
        ));

        $this->register_seo_pro_abilities($registrar);

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
            'Set a post\'s SEO title, meta description, focus keyword, canonical URL and/or robots flags (noindex/nofollow) via the active SEO plugin\'s postmeta keys. Snapshotted as a post; rollback-operation restores the prior values exactly',
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
     * The paid half of the SEO group (issue #67): generation tools, the
     * extended social vocabulary, and term-level SEO. Every one declares tier
     * 'pro', which the Registrar enforces centrally rather than each handler
     * re-checking. Kept in its own method, called from
     * register_seo_abilities(), so the directory build removes the paid
     * surface as one method instead of editing registrations out one by one.
     *
     * generate-schema-markup and generate-meta-tags register unconditionally
     * (like get-seo-status): both build from the post's own record, so they
     * work on a site with no SEO plugin at all. The rest read or write the
     * active plugin's storage and register only when one is detected. Plugin
     * and field combinations that are not mapped answer with a structured
     * "unsupported" payload, never an error.
     */
    private function register_seo_pro_abilities(Registrar $registrar): void
    {
        $generate_schema = new Generate_Schema_Markup();
        $generate_tags   = new Generate_Meta_Tags();

        $registrar->register(new Ability(
            'wpmcp/generate-schema-markup',
            'pro',
            'Generate schema.org JSON-LD (Article, WebPage, LocalBusiness or Product) for a post from title, dates, author, excerpt or SEO description, featured image, permalink and, for Product, the WooCommerce record. Proposal only: writes nothing',
            [
                'type'       => 'object',
                'properties' => [
                    'post_id'     => [ 'type' => 'integer' ],
                    'schema_type' => [
                        'type' => 'string',
                        // From SUPPORTED_TYPES on the generator itself: a
                        // type added there must not stay undiscoverable, and
                        // one removed must not stay advertised on a schema
                        // that now throws.
                        'enum' => Schema_Generator::SUPPORTED_TYPES,
                    ],
                ],
                'required'   => [ 'post_id' ],
            ],
            [$generate_schema, 'handle'],
            'edit_posts',
            'seo',
            'read'
        ));

        $registrar->register(new Ability(
            'wpmcp/generate-meta-tags',
            'pro',
            'Propose a post\'s title, description, canonical, robots, OpenGraph and Twitter tags from SEO plugin fields or the post record, each with its source. Read-only proposal',
            [
                'type'       => 'object',
                'properties' => [
                    'post_id' => [ 'type' => 'integer' ],
                ],
                'required'   => [ 'post_id' ],
            ],
            [$generate_tags, 'handle'],
            'edit_posts',
            'seo',
            'read'
        ));

        if ('' === SEO_Adapter::active_plugin()) {
            return;
        }

        $get_social_meta      = new Get_Social_Meta();
        $set_social_image     = new Set_Social_Image();
        $get_term_seo_meta    = new Get_Term_SEO_Meta();
        $update_term_seo_meta = new Update_Term_SEO_Meta();

        $registrar->register(new Ability(
            'wpmcp/get-social-meta',
            'pro',
            'Read a post\'s OpenGraph and Twitter overrides (title, description, image) from the active SEO plugin as one field set. Plugins with unmapped social storage return a structured unsupported response',
            [
                'type'       => 'object',
                'properties' => [
                    'post_id' => [ 'type' => 'integer' ],
                ],
                'required'   => [ 'post_id' ],
            ],
            [$get_social_meta, 'handle'],
            'edit_posts',
            'seo',
            'read'
        ));

        $registrar->register(new Ability(
            'wpmcp/set-social-image',
            'pro',
            'Set a post\'s OpenGraph and/or Twitter image from an attachment_id or image_url via the active SEO plugin. Snapshotted, undo with rollback-operation. Unmapped plugins return supported:false',
            [
                'type'       => 'object',
                'properties' => [
                    'post_id'       => [ 'type' => 'integer' ],
                    'attachment_id' => [ 'type' => 'integer' ],
                    'image_url'     => [ 'type' => 'string' ],
                    'target'        => [ 'type' => 'string', 'enum' => [ 'og', 'twitter', 'both' ] ],
                    'session_id'    => [ 'type' => 'string' ],
                ],
                'required'   => [ 'post_id' ],
            ],
            [$set_social_image, 'handle'],
            'edit_posts',
            'seo',
            'update'
        ));

        $registrar->register(new Ability(
            'wpmcp/get-term-seo-meta',
            'pro',
            'Read a term\'s SEO fields (as get-seo-meta) by taxonomy plus term_id or slug. Fields the plugin lacks on terms are in unsupported_fields; no term storage returns supported:false',
            [
                'type'       => 'object',
                'properties' => [
                    'taxonomy' => [ 'type' => 'string' ],
                    'term_id'  => [ 'type' => 'integer' ],
                    'slug'     => [ 'type' => 'string' ],
                ],
                'required'   => [ 'taxonomy' ],
            ],
            [$get_term_seo_meta, 'handle'],
            'edit_posts',
            'seo',
            'read'
        ));

        $registrar->register(new Ability(
            'wpmcp/update-term-seo-meta',
            'pro',
            'Set a term\'s SEO fields (as update-seo-meta) by taxonomy plus term_id or slug. Snapshotted; rollback-operation undoes it. Fields the plugin lacks on terms return in skipped_fields',
            [
                'type'       => 'object',
                'properties' => [
                    'taxonomy'      => [ 'type' => 'string' ],
                    'term_id'       => [ 'type' => 'integer' ],
                    'slug'          => [ 'type' => 'string' ],
                    'title'         => [ 'type' => 'string' ],
                    'description'   => [ 'type' => 'string' ],
                    'focus_keyword' => [ 'type' => 'string' ],
                    'canonical'     => [ 'type' => 'string' ],
                    'noindex'       => [ 'type' => 'boolean' ],
                    'nofollow'      => [ 'type' => 'boolean' ],
                    'session_id'    => [ 'type' => 'string' ],
                ],
                'required'   => [ 'taxonomy' ],
            ],
            [$update_term_seo_meta, 'handle'],
            'manage_categories',
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
            'Assign a post to a language (by code) via the active multilingual plugin (Polylang or WPML). Snapshotted as a post (a Polylang language is a term); rollback-operation restores the prior language assignment exactly',
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
            'Link posts as translations of one another from {language, post_id} pairs via Polylang or WPML. Only the primary (first) post is snapshotted, so rollback restores that post, not the others',
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
            'Create a managed redirect from a source path to a target URL or target_post_id (which survives slug changes). Chains are flattened to one hop and loops refused; the stored target is reported. Snapshotted; rollback-operation removes it',
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
            'Scan published content for internal links that are dead, point at a non-public post or go through a redirect. background:true queues a batched scan; poll it with scan_id. Read-only: proposes fixes, changes nothing',
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
            'A post\'s plain text and structure (headings, word count, link and image counts). keywords=N adds the top N terms and 2-3 word phrases, title and headings weighted. Read-only',
            [
                'type'       => 'object',
                'properties' => [
                    'post_id'  => [ 'type' => 'integer' ],
                    'keywords' => [ 'type' => 'integer' ],
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
            'op score (default; post_id): on-page SEO 0-100 with findings (title/meta length, headings, words, alt, links, keyword density, readability). op keywords: volume, difficulty, CPC, intent. op backlinks (target domain or URL): link counts. Both use your provider key (set-seo-data-key), cached. Read-only',
            [
                'type'       => 'object',
                'properties' => [
                    'op'            => [ 'type' => 'string', 'enum' => Analyze_Seo::OPS ],
                    'post_id'       => [ 'type' => 'integer' ],
                    'focus_keyword' => [ 'type' => 'string' ],
                    'keywords'      => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                    'target'        => [ 'type' => 'string' ],
                    'location_code' => [ 'type' => 'integer' ],
                    'language_code' => [ 'type' => 'string' ],
                ],
            ],
            [$analyze_seo, 'handle'],
            'edit_posts',
            'analysis',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/set-seo-data-key',
            'pro',
            'Save SEO data provider credentials for analyze-seo (empty api_key clears); dataforseo: "login:password". Encrypted, never echoed',
            [
                'type'       => 'object',
                'properties' => [
                    'provider' => [ 'type' => 'string', 'enum' => [ 'dataforseo' ] ],
                    'api_key'  => [ 'type' => 'string' ],
                ],
                'required'   => [ 'provider', 'api_key' ],
            ],
            [new Set_Seo_Data_Key(), 'handle'],
            'manage_options',
            'analysis',
            'update'
        ));

        $analyze_accessibility = new Analyze_Accessibility();

        $registrar->register(new Ability(
            'wpmcp/analyze-accessibility',
            'pro',
            'Scan a post\'s HTML for WCAG issues (missing alt text, heading jumps, empty or vague link text, unlabeled form controls): scored findings with element locations. Read-only',
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
            'WCAG contrast ratio of a foreground/background hex pair, with AA/AAA pass/fail for normal and large text. Read-only',
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
            'Raise failing inline text/background color pairs in a post to a target WCAG ratio by lightness only (hue kept); before/after color, ratio, level per pair. Dry run unless apply=true; one snapshot, one rollback',
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
            'Write missing image alt text in a post from filename, nearest heading or title. Keeps existing alt unless overwrite_existing=true; skips decorative images. Dry run unless apply=true; one snapshot, one rollback',
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
            'Replace empty or generic internal link text ("click here", "read more") with the destination title (WCAG 2.4.4, SEO). Skips external links and anchors with markup. Dry run unless apply=true; one snapshot, one rollback',
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
     * Connection-info tooling for the admin connection area (issue #18).
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
            'List every wpmcp ability here grouped by domain, with tier (free/pro), operation, required capability and read-only/destructive hints, plus per-domain counts. Optional domain and tier filters. Read-only',
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
            'Post builder: elementor, bricks, divi, wpbakery, avada, beaver-builder, breakdance, oxygen, oxygen-classic, thrive, gutenberg, classic. Read-only',
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
            'Bricks elements; Divi/WPBakery/Avada shortcodes, Thrive HTML, oxygen-classic JSON (last four with paths); Beaver Builder/Breakdance/Oxygen nodes. Read-only',
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
            'Bricks JSON; Divi/WPBakery/Avada shortcodes, Thrive HTML; oxygen-classic/Beaver Builder/Breakdance/Oxygen JSON tree. All but Bricks/Divi: operation update (path, attrs, text), add (to, index, element), remove (path), move (path, to, index); to "" = top. Undo: rollback-operation',
            [
                'type'       => 'object',
                'properties' => [
                    'post_id'   => [ 'type' => 'integer' ],
                    'builder'   => [ 'type' => 'string' ],
                    'content'   => [ 'type' => 'string' ],
                    'operation' => [ 'type' => 'string' ],
                    'path'      => [ 'type' => 'string' ],
                    'to'        => [ 'type' => 'string' ],
                    'index'     => [ 'type' => 'integer' ],
                    'attrs'     => [ 'type' => 'object' ],
                    'text'      => [ 'type' => 'string' ],
                    'element'   => [ 'type' => 'object' ],
                ],
                'required'   => [ 'post_id', 'builder' ],
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
     * registered ability surface - and with it the ability-manifest drift
     * guard - never varies with the mode. In full mode these three simply
     * ride along as ordinary tools; in compact mode they ARE the surface.
     *
     * call-tool is deliberately classified domain=dispatch, operation=update
     * with explicit destructive annotations: it proxies writes and deletes,
     * so it must not advertise itself as read-only, and Governance/identity
     * narrowing applies to the shell like any other ability (a scoped
     * identity that should dispatch must include domain 'dispatch' and
     * operation 'update'; AND-of-narrowing, no special bypass). The REAL
     * authorization decision for a dispatched call is made by the target
     * ability's own permission callback - see Call_Tool's docblock, and the
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
            'List every tool this install registers: name, summary, domain, operation and tier, by name. Optional domain filter; full:true adds full descriptions and MCP annotations; schemas are behind get-tool-schema. Read-only. In compact mode, the discovery entry point for unlisted tools',
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
            'Invoke any wpmcp tool by name with an arguments object, the path to tools compact mode hides from tools/list. The target\'s own checks (capability, governance, identity scope, license, rate limit, input validation, snapshot/rollback) apply as if called directly; it never widens access. Refuses non-wpmcp tools and the meta-tools',
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

    /**
     * The third-party ability bridge (issue #194), registered like the
     * dispatch meta-tools: three shells that discover and invoke abilities
     * OTHER plugins registered through the Abilities API, without a second
     * MCP plugin and without ever widening access. The whole surface sits
     * behind Bridge_Guard's default-off opt-in
     * (WPMCP_ENABLE_ABILITY_BRIDGE / wpmcp_enable_ability_bridge), and a
     * bridged invocation always runs the target ability's own
     * permission_callback; there is no bypass path, filter or setting.
     *
     * Bridged abilities are never added to tools/list; discovery goes
     * through list-site-abilities and execution through
     * execute-site-ability, the same compact-mode pattern as call-tool.
     * execute-site-ability carries explicit destructive annotations for the
     * same reason call-tool does: it proxies writes we did not author, and
     * nothing bridged carries the snapshot/rollback guarantee.
     */
    private function register_bridge_abilities(Registrar $registrar): void
    {
        $list_site_abilities  = new List_Site_Abilities();
        $get_site_ability     = new Get_Site_Ability();
        $execute_site_ability = new Execute_Site_Ability();

        $registrar->register(new Ability(
            'wpmcp/list-site-abilities',
            'free',
            'Abilities OTHER plugins register via the Abilities API: name, summary, owning plugin, whether an input schema exists, and reversible:false (bridged results are outside wpmcp rollback). Optional plugin filter. Read-only. Needs the ability bridge opt-in (default off)',
            [
                'type'       => 'object',
                'properties' => [
                    'plugin' => [ 'type' => 'string' ],
                ],
            ],
            [$list_site_abilities, 'handle'],
            'edit_posts',
            'bridge',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/get-site-ability',
            'free',
            'Read one third-party ability\'s full contract by name: complete description, exact input schema, output schema and meta where provided, and its owning plugin. Refuses wpmcp\'s own abilities (use wpmcp/get-tool-schema for those). Read-only. Use wpmcp/list-site-abilities to discover names',
            [
                'type'       => 'object',
                'properties' => [
                    'name' => [ 'type' => 'string' ],
                ],
                'required'   => [ 'name' ],
            ],
            [$get_site_ability, 'handle'],
            'edit_posts',
            'bridge',
            'read'
        ));
        $registrar->register(new Ability(
            'wpmcp/execute-site-ability',
            'free',
            'Invoke one third-party ability by name with an arguments object. Its own permission callback always runs (no bypass), plus wpmcp governance, identity scope and rate limiting. Results are reversible:false: bridged writes are outside the wpmcp rollback guarantee. Refuses wpmcp\'s own abilities; needs the site bridge opt-in (default off)',
            [
                'type'       => 'object',
                'properties' => [
                    'name'      => [ 'type' => 'string' ],
                    'arguments' => [ 'type' => 'object' ],
                ],
                'required'   => [ 'name' ],
            ],
            [$execute_site_ability, 'handle'],
            'edit_posts',
            'bridge',
            'update',
            false,
            true,
            false
        ));
    }
}
