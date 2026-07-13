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
use WPMCP\MCP\Ability;
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
use WPMCP\Tools\Content\List_Post_Types;
use WPMCP\Tools\Content\List_Taxonomies;
use WPMCP\Tools\Content\Create_Post;
use WPMCP\Tools\Content\Get_Post;
use WPMCP\Tools\Content\Update_Post;
use WPMCP\Tools\Content\Delete_Post;
use WPMCP\Tools\Content\List_Posts;
use WPMCP\Tools\Content\Set_Post_Terms;
use WPMCP\Tools\Revisions\List_Revisions;
use WPMCP\Tools\Revisions\Get_Revision;
use WPMCP\Tools\Revisions\Restore_Revision;
use WPMCP\Tools\Media\Get_Media;
use WPMCP\Tools\Media\Update_Media;
use WPMCP\Tools\Media\Delete_Media;
use WPMCP\Tools\Media\Sideload_Image;
use WPMCP\Tools\Settings\Get_Settings;
use WPMCP\Tools\Settings\Update_Settings;
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
use WPMCP\Tools\Elementor\List_Widgets;
use WPMCP\Tools\Elementor\Get_Widget_Schema;
use WPMCP\Tools\Elementor\Get_Elementor_Data;
use WPMCP\Tools\Elementor\Update_Element;
use WPMCP\Tools\Elementor\Add_Widget;
use WPMCP\Tools\Elementor\Remove_Element;
use WPMCP\Tools\Elementor\Move_Element;
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

if (! defined('ABSPATH') && ! defined('WPMCP_TESTING')) {
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
    public function boot(): void
    {
        if (function_exists('register_activation_hook') && defined('WPMCP_FILE')) {
            register_activation_hook(WPMCP_FILE, [Activator::class, 'activate']);
        }
        if (function_exists('add_action')) {
            $hook = function_exists('wp_register_ability') ? 'wp_abilities_api_init' : 'init';
            add_action($hook, [$this, 'register_abilities']);
            if (function_exists('wp_register_ability_category')) {
                add_action('wp_abilities_api_categories_init', [$this, 'register_ability_category']);
            }
            add_action('admin_menu', [$this, 'register_admin_menu']);
            add_action('wp_ajax_wpmcp_restore', [new Restore_Controller(), 'handle']);
            // Front-end maintenance-mode enforcement. template_redirect runs after
            // WordPress has resolved the query but before a template is loaded, and
            // does not fire for wp-admin or REST requests, so authenticated capable
            // users, wp-admin, and the REST/MCP endpoints are never affected by it.
            add_action('template_redirect', [new Maintenance_Guard(), 'enforce']);
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
        // The history page views (and its Restore button rolls back) ALL
        // users' site-wide agent mutations, so it is gated at manage_options,
        // matching Restore_Controller::handle()'s ajax capability check.
        add_menu_page(
            'wpmcp',
            'wpmcp',
            'manage_options',
            'wpmcp',
            [new History_Page(), 'render']
        );

        // Same manage_options gate as the top-level page and Restore_Controller's
        // ajax handler: this screen shows and can roll back every user's
        // site-wide agent mutations, so it needs the same trust level.
        add_submenu_page(
            'wpmcp',
            'wpmcp: Audit Log',
            'Audit Log',
            'manage_options',
            'wpmcp-audit-log',
            [new Audit_Log_Page(), 'render']
        );
    }

    public function register_abilities(): void
    {
        $registrar          = $this->registrar();
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
            'Run a read-only SQL query (SELECT/SHOW/DESCRIBE/EXPLAIN/WITH). Writes, DDL, stacked statements, and file-access SQL are rejected before execution. Results are capped',
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
            'Update rows matching a mandatory equality WHERE via $wpdb->update() (parameterized). Requires confirm:true. Refuses protected tables. Disabled by default (wpmcp_enable_db_writes filter). Captures a before-image to the write audit log and honestly reports recoverable:false (no generic-table rollback)',
            [
                'type'       => 'object',
                'properties' => [
                    'table'   => [ 'type' => 'string' ],
                    'data'    => [ 'type' => 'object' ],
                    'where'   => [ 'type' => 'object' ],
                    'confirm' => [ 'type' => 'boolean' ],
                ],
                'required'   => [ 'table', 'data', 'where' ],
            ],
            [$update_rows, 'handle'],
            'manage_options',
            'database',
            'update',
            false,
            true,
            false
        ));
        $registrar->register(new Ability(
            'wpmcp/delete-rows',
            'free',
            'Delete rows matching a mandatory equality WHERE via $wpdb->delete() (parameterized). Requires confirm:true. Refuses protected tables. Disabled by default (wpmcp_enable_db_writes filter). Captures a before-image to the write audit log and honestly reports recoverable:false (no generic-table rollback)',
            [
                'type'       => 'object',
                'properties' => [
                    'table'   => [ 'type' => 'string' ],
                    'where'   => [ 'type' => 'object' ],
                    'confirm' => [ 'type' => 'boolean' ],
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

        $this->register_woocommerce_abilities($registrar);
        $this->register_menu_abilities($registrar);
        $this->register_elementor_abilities($registrar);
        $this->register_acf_abilities($registrar);
        $this->register_seo_abilities($registrar);
        $this->register_meta_abilities($registrar);
        $this->register_diagnostics_abilities($registrar);
        $this->register_cron_abilities($registrar);
        $this->register_maintenance_abilities($registrar);
        $this->register_context_abilities($registrar);
        $this->register_rest_abilities($registrar);
        $this->register_block_abilities($registrar);
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
     */
    private function register_block_abilities(Registrar $registrar): void
    {
        $list_block_types = new List_Block_Types();
        $get_block_type   = new Get_Block_Type();
        $parse_blocks     = new Parse_Blocks();

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
     * Elementor's own widgets manager and never touch post content), so
     * neither is routed through the safety core.
     */
    private function register_elementor_abilities(Registrar $registrar): void
    {
        $list_widgets      = new List_Widgets();
        $get_widget_schema = new Get_Widget_Schema();

        $registrar->register(new Ability(
            'wpmcp/list-widgets',
            'free',
            'List Elementor registered widget types (name, title, categories, icon, tier), optionally filtered by tier (free/pro), category, or a case-insensitive search over name/title. Read-only; reads this site\'s Elementor widgets manager only',
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
            'Return the full control schema (control name, type, label, default, section grouping) for a single Elementor widget type, read directly from the widget\'s own control stack. Read-only; reads this site\'s Elementor widgets manager only',
            [
                'type'       => 'object',
                'properties' => [
                    'widget_name' => [ 'type' => 'string' ],
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
     * change to the safety core.
     */
    private function register_elementor_pro_abilities(Registrar $registrar): void
    {
        $get_elementor_data = new Get_Elementor_Data();

        $registrar->register(new Ability(
            'wpmcp/get-elementor-data',
            'pro',
            'Return a page\'s parsed Elementor element tree (id, elType, widgetType, settings, and nested elements for every node), read directly from its _elementor_data postmeta. Read-only',
            [
                'type'       => 'object',
                'properties' => [
                    'post_id' => [ 'type' => 'integer' ],
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
            'Add a widget element (given a widget_type and optional settings) as a child of a specified parent element in a page\'s _elementor_data. The widget_type is validated against Elementor\'s own widgets manager first. Undoable via rollback-operation since _elementor_data is ordinary postmeta captured by the existing post snapshot',
            [
                'type'       => 'object',
                'properties' => [
                    'post_id'     => [ 'type' => 'integer' ],
                    'parent_id'   => [ 'type' => 'string' ],
                    'widget_type' => [ 'type' => 'string' ],
                    'settings'    => [ 'type' => 'object' ],
                ],
                'required'   => [ 'post_id', 'parent_id', 'widget_type' ],
            ],
            [$add_widget, 'handle'],
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
}
