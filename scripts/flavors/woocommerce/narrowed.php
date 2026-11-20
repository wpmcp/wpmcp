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
 *   actions    ability name => [action => category] for actions a kept
 *              ability loses. Each action name must not appear as a PHP
 *              string literal or in a shipped document, and the refusal's
 *              ACTIONS must match.
 */

declare(strict_types=1);

return [
    'abilities' => [
        'wpmcp/write-file'             => 'file writes',
        'wpmcp/edit-file'              => 'file writes',
        'wpmcp/delete-file'            => 'file writes',
        'wpmcp/read-file'              => 'file access',
        'wpmcp/list-directory'         => 'file access',
        'wpmcp/search-files'           => 'file access',
        'wpmcp/query'                  => 'raw SQL',
        'wpmcp/insert-row'             => 'database writes',
        'wpmcp/update-rows'            => 'database writes',
        'wpmcp/delete-rows'            => 'database writes',
        'wpmcp/rewrite-site-urls'      => 'site-wide search and replace',
        'wpmcp/find-replace-content'   => 'site-wide search and replace',
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
        'wpmcp/restore-site-backup'    => 'whole-site restore from a backup archive',
    ],

    'classes' => [
        'WPMCP\\Tools\\Filesystem\\Write_File',
        'WPMCP\\Tools\\Filesystem\\Edit_File',
        'WPMCP\\Tools\\Filesystem\\Delete_File',
        'WPMCP\\Tools\\Filesystem\\Read_File',
        'WPMCP\\Tools\\Filesystem\\List_Directory',
        'WPMCP\\Tools\\Filesystem\\Search_Files',
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
        'WPMCP\\Tools\\Migration\\Rewrite_Site_Urls',
        'WPMCP\\Tools\\Migration\\Find_Replace_Content',
        'WPMCP\\Tools\\Security\\Salt_Rotator',
        'WPMCP\\Tools\\Security\\Core_File_Repair',
        'WPMCP\\Tools\\Security\\File_Quarantine',
        'WPMCP\\Admin\\Announcements',
        'WPMCP\\Cloud\\Cloud_Client',
        'WPMCP\\Cloud\\Cloud_Config',
        'WPMCP\\Cloud\\Cloud_Credentials',
        'WPMCP\\Cloud\\Gateway_Cloud',
        'WPMCP\\Cloud\\Gateway_Consent',
        'WPMCP\\Cloud\\Token_Refresher',
        'WPMCP\\Cloud\\Settings_Sync',
        // Whole-site restore and the SQL importer (issue #1147, owner
        // decision (a), 20 Nov 2026).
        'WPMCP\\Tools\\Backup\\Restore_Site_Backup',
        'WPMCP\\Tools\\Backup\\Run_Restore_Job',
        'WPMCP\\Tools\\Backup\\Restore_Session',
        'WPMCP\\Tools\\Backup\\Restore_Logs',
        'WPMCP\\Tools\\Backup\\Sql_Importer',
        'WPMCP\\Tools\\Backup\\Sql_Import_Policy',
        'WPMCP\\Tools\\Backup\\Sql_Statement_Reader',
        'WPMCP\\Tools\\Backup\\Safety_Archive',
    ],

    'paths' => [
        'src/Tools/Migration',
        'src/Cloud',
        'src/Admin/Announcements.php',
        'src/Skills/library/wpmcp-safe-database-work',
    ],

    'methods' => [
        'WPMCP\\Tools\\Security\\Incident_Response::rotate_salts',
        'WPMCP\\Tools\\Security\\Incident_Response::reinstall_core_files',
        'WPMCP\\Tools\\Security\\Incident_Response::quarantine_action',
    ],

    'actions' => [
        'wpmcp/incident-response' => [
            'rotate-salts'             => 'salt rotation (it rewrites wp-config.php)',
            'reinstall-core-files'     => 'file changes',
            'quarantine-file'          => 'file changes',
            'list-quarantine'          => 'file changes',
            'restore-quarantined-file' => 'file changes',
            'purge-quarantined-file'   => 'file changes',
        ],
    ],
];
