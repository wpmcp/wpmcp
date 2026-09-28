<?php
/**
 * The WooCommerce vertical's additions to the shared strip (issue #257).
 *
 * scripts/build-woo-release.sh runs scripts/flavors/wporg/strip.php over its
 * stage with this manifest, so the paid tier, the licensing SDK, the admin
 * nag and every other guideline 5 surface leave this zip through the same
 * count-validated edits that take them out of the directory cut. What is
 * listed here is only what this flavor drops beyond that: the ability groups
 * Plugin::FLAVOR_GROUPS['woocommerce'] never registers, whose files have no
 * business in the zip.
 *
 * This list MUST stay in sync with that whitelist, and it lists only paths
 * the shared policy keeps: an entry the shared policy already removes, or one
 * that no longer exists, fails the strip.
 */

declare(strict_types=1);

return [
    'label' => 'WooCommerce',

    'removed_methods' => [],

    'edits' => [],

    'removed_paths' => [
        // Domains the 'woocommerce' flavor never registers.
        'src/Tools/Elementor',
        'src/Tools/ACF',
        'src/Tools/I18n',
        'src/Tools/Rest',
        'src/Tools/Analytics',
        'src/Tools/Multisite',
        'src/Tools/Dispatch',
        'src/Tools/Bridge',
        'src/Tools/ThemeBuilder',
        'src/Tools/Search',
        'src/Tools/Sync',
        'src/Integrations',
        // src/Tools/Builders is NOT removed by path: Builder_Detector is a
        // plain postmeta reader and the free get-page-snapshot in the kept
        // 'context' group reads through it (issue #81). The shared strip's
        // sweep already takes the paid builder wrappers and Divi_Content;
        // Bricks_Content stays in the directory cut only because the search
        // index reads it, and this flavor prunes the index.
        'src/Tools/Builders/Bricks_Content.php',
        // The snippet validator is free in the directory cut; this flavor has
        // never shipped it.
        'src/Tools/Code/Php_Snippet_Validator.php',
        'src/Tools/Code/Validate_Php_Snippet.php',
        // The rest of the snippet tools (issue #85) go too. The shared strip
        // keeps the free store CRUD in the directory cut, but this flavor
        // drops the whole 'code' ability group at runtime
        // (Plugin::FLAVOR_GROUPS), so these classes would ship with no
        // registration path into them. Php_Snippet_Store.php deliberately
        // STAYS: src/Safety/Snapshot.php and src/Safety/Rollback_Service.php
        // name it from the always-loaded safety core, and a pre-existing
        // php_snippet snapshot row survives a site swapping the full plugin
        // for this flavor. A leftover 'active' flag is inert: no executor
        // ships, and rollback always restores a snippet inactive.
        'src/Tools/Code/Create_Php_Snippet.php',
        'src/Tools/Code/List_Php_Snippets.php',
        'src/Tools/Code/Get_Php_Snippet.php',
        'src/Tools/Code/Update_Php_Snippet.php',
        'src/Tools/Code/Delete_Php_Snippet.php',
        'src/Tools/Code/Deactivate_Php_Snippet.php',
    ],
];
