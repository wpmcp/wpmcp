<?php

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols -- ABSPATH guard is an intentional side effect.

namespace WPMCP;

use WPMCP\Admin\History_Page;
use WPMCP\Admin\Restore_Controller;
use WPMCP\MCP\Ability;
use WPMCP\MCP\Registrar;
use WPMCP\Tools\Get_Page;
use WPMCP\Tools\Update_Blocks;
use WPMCP\Tools\List_Operations;
use WPMCP\Tools\Rollback_Operation;
use WPMCP\Tools\Rollback_Session;

if (! defined('ABSPATH') && ! defined('WPMCP_TESTING')) {
    exit;
}

final class Plugin
{
    private static ?Plugin $instance = null;
    public static function instance(): Plugin
    {
        return self::$instance ??= new self();
    }
    private function __construct()
    {
    }
    public function boot(): void
    {
        if (function_exists('register_activation_hook') && defined('WPMCP_FILE')) {
            register_activation_hook(WPMCP_FILE, [Activator::class, 'activate']);
        }
        if (function_exists('add_action')) {
            $hook = function_exists('wp_register_ability') ? 'wp_abilities_api_init' : 'init';
            add_action($hook, [$this, 'register_abilities']);
            add_action('admin_menu', [$this, 'register_admin_menu']);
            add_action('wp_ajax_wpmcp_restore', [new Restore_Controller(), 'handle']);
        }
    }

    public function register_admin_menu(): void
    {
        add_menu_page(
            'wpmcp',
            'wpmcp',
            'edit_posts',
            'wpmcp',
            [new History_Page(), 'render']
        );
    }

    public function register_abilities(): void
    {
        $registrar          = new Registrar();
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
            [$get_page, 'handle']
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
            [$update_blocks, 'handle']
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
            [$list_operations, 'handle']
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
            [$rollback_operation, 'handle']
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
            [$rollback_session, 'handle']
        ));
    }
}
