<?php
/**
 * What the WordPress.org WooCommerce listing must never ship (issue #1140).
 *
 * The Plugin Developer FAQ (updated 10 Nov 2026) does not accept new plugins
 * that allow arbitrary code insertion or execution, naming "PHP or
 * JavaScript editors, file managers, and AI tools that generate code"
 * intended to run on the site. Our compliance engine models the written
 * guidelines and does not encode that rule, so this list does, and
 * scripts/flavors/woocommerce/assert-narrowed.php fails the build when any
 * entry is back in the staged tree or the zip.
 *
 * This is the gate's own list, deliberately written out rather than read
 * from manifest.php: the manifest is the surgery, this is the check that
 * does not trust it. It covers what the manifest removes by name plus what
 * the flavor whitelist and the shared strip already keep out (the PHP
 * snippet tools, Elementor custom code), so a change to either cannot bring
 * one back unnoticed.
 *
 * The direct download (scripts/build-release.sh), the directory cut
 * (scripts/build-wporg-release.sh) and Pro are not narrowed and keep every
 * one of these.
 *
 *   abilities  ability name => the category the refusal names. The staged
 *              src/MCP/Not_In_Build.php must refuse exactly these names.
 *   classes    fully qualified class names that must not be declared or
 *              referenced anywhere in the staged tree.
 *   paths      staged paths that must not exist.
 *   methods    'Class::method' pairs that must not be declared.
 *   literals   PHP string literals that must not appear in shipped code (an
 *              action of a kept ability, which has no class or name of its
 *              own to check).
 */

declare(strict_types=1);

return [
    'abilities' => [
        'wpmcp/write-file'             => 'file writes',
        'wpmcp/edit-file'              => 'file writes',
        'wpmcp/delete-file'            => 'file writes',
        'wpmcp/query'                  => 'raw SQL',
        'wpmcp/insert-row'             => 'database writes',
        'wpmcp/update-rows'            => 'database writes',
        'wpmcp/delete-rows'            => 'database writes',
        'wpmcp/create-php-snippet'     => 'the PHP snippet store',
        'wpmcp/list-php-snippets'      => 'the PHP snippet store',
        'wpmcp/get-php-snippet'        => 'the PHP snippet store',
        'wpmcp/update-php-snippet'     => 'the PHP snippet store',
        'wpmcp/delete-php-snippet'     => 'the PHP snippet store',
        'wpmcp/deactivate-php-snippet' => 'the PHP snippet store',
        'wpmcp/activate-php-snippet'   => 'the PHP snippet store',
        'wpmcp/validate-php-snippet'   => 'the PHP snippet store',
        'wpmcp/run-php-snippet'        => 'the PHP snippet store',
        'wpmcp/create-code-snippet'    => 'code snippet creation',
        'wpmcp/push-site-archive'      => 'site migration',
        'wpmcp/receive-site-archive'   => 'site migration',
    ],

    'classes' => [
        'WPMCP\\Tools\\Filesystem\\Write_File',
        'WPMCP\\Tools\\Filesystem\\Edit_File',
        'WPMCP\\Tools\\Filesystem\\Delete_File',
        'WPMCP\\Tools\\Database\\Query',
        'WPMCP\\Tools\\Database\\Insert_Row',
        'WPMCP\\Tools\\Database\\Update_Rows',
        'WPMCP\\Tools\\Database\\Delete_Rows',
        'WPMCP\\Tools\\Code\\Create_Php_Snippet',
        'WPMCP\\Tools\\Code\\List_Php_Snippets',
        'WPMCP\\Tools\\Code\\Get_Php_Snippet',
        'WPMCP\\Tools\\Code\\Update_Php_Snippet',
        'WPMCP\\Tools\\Code\\Delete_Php_Snippet',
        'WPMCP\\Tools\\Code\\Deactivate_Php_Snippet',
        'WPMCP\\Tools\\Code\\Activate_Php_Snippet',
        'WPMCP\\Tools\\Code\\Validate_Php_Snippet',
        'WPMCP\\Tools\\Code\\Php_Snippet_Validator',
        'WPMCP\\Tools\\Code\\Run_Php_Snippet',
        'WPMCP\\Tools\\Code\\Php_Snippet_Runner',
        'WPMCP\\Tools\\Elementor\\Create_Code_Snippet',
        'WPMCP\\Tools\\Migration\\Push_Site_Archive',
        'WPMCP\\Tools\\Migration\\Receive_Site_Archive',
        'WPMCP\\Tools\\Migration\\Incoming_Archive_Store',
        'WPMCP\\Tools\\Migration\\Migration_Target_Client',
        'WPMCP\\Tools\\Migration\\Migration_Guard',
        'WPMCP\\Tools\\Security\\Salt_Rotator',
        'WPMCP\\Admin\\Announcements',
        'WPMCP\\Cloud\\Cloud_Client',
        'WPMCP\\Cloud\\Cloud_Config',
        'WPMCP\\Cloud\\Cloud_Credentials',
        'WPMCP\\Cloud\\Gateway_Cloud',
        'WPMCP\\Cloud\\Gateway_Consent',
        'WPMCP\\Cloud\\Token_Refresher',
        'WPMCP\\Cloud\\Settings_Sync',
    ],

    'paths' => [
        'src/Cloud',
        'src/Admin/Announcements.php',
        'src/Skills/library/wpmcp-safe-database-work',
    ],

    'methods' => [
        'WPMCP\\Tools\\Security\\Incident_Response::rotate_salts',
    ],

    'literals' => [
        // incident-response's wp-config.php salt rotation.
        'rotate-salts',
    ],
];
