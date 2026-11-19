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
 *
 * Since issue #1140 it also narrows the wp.org listing to store tools plus
 * the safety core: removed_abilities deletes unconditional registrations by
 * name, added_files brings in the not-in-this-build refusal, and the edits
 * and paths below take out their handlers, the cloud client and the
 * announcements feed. scripts/flavors/woocommerce/assert-narrowed.php checks
 * the outcome against narrowed.php on the stage and on the zip.
 */

declare(strict_types=1);

return [
    'label' => 'WooCommerce',

    // The 'code' group (PHP snippet store, issue #85) never registers in this
    // flavor (Plugin::FLAVOR_GROUPS), and its handlers are pruned below, so
    // the dormant method goes too rather than shipping registrations of the
    // snippet tools (issue #1140). Its $groups entry is edited out below.
    'removed_methods' => [
        'register_code_abilities',
        // Reached only from register_code_abilities() and from each other.
        'register_snippet_store_abilities',
        'register_bundle_abilities',
    ],

    /*
     * Issue #1140: the wp.org listing is narrowed to store tools plus the
     * safety core. These register unconditionally in the prologue of
     * Plugin::register_abilities_into() or in a group this flavor keeps, so
     * the flavor whitelist cannot drop them; the strip deletes each
     * registration by name (strip.php, remove_named_ability()). The direct
     * download and Pro keep every one. scripts/flavors/woocommerce/
     * narrowed.php is the gate's independent list of what must not ship.
     */
    'removed_abilities' => [
        // File writes. read-file, list-directory and search-files stay.
        'wpmcp/write-file',
        'wpmcp/edit-file',
        'wpmcp/delete-file',
        // Raw SQL and DB writes. list-tables and describe-table stay.
        'wpmcp/query',
        'wpmcp/insert-row',
        'wpmcp/update-rows',
        'wpmcp/delete-rows',
        // Site migration: accepting or sending a whole-site archive.
        'wpmcp/push-site-archive',
        'wpmcp/receive-site-archive',
    ],

    /*
     * A direct call to a stripped tool is told it is not in this build
     * rather than "Tool not found" (src/MCP/Not_In_Build.php, wired into
     * both transports below).
     */
    'added_files' => [
        'src/MCP/Not_In_Build.php' => 'overlay/Not_In_Build.php',
    ],

    'edits' => [
        'src/MCP/Hidden_Tool_Refusal.php' => [
            [
                '        $error     = Plugin::instance()->registrar()->plugin_inactive_for_tool($name, $arguments);',
                '        $error     = Not_In_Build::refusal($name) ?? Plugin::instance()->registrar()->plugin_inactive_for_tool($name, $arguments);',
                1,
            ],
        ],
        'src/MCP/Stdio_Transport.php' => [
            [
                '                $inactive  = \WPMCP\Plugin::instance()->registrar()->plugin_inactive_for_tool($tool_name, $arguments);',
                '                $inactive  = Not_In_Build::refusal($tool_name) ?? \WPMCP\Plugin::instance()->registrar()->plugin_inactive_for_tool($tool_name, $arguments);',
                1,
            ],
        ],
        'src/Plugin.php' => [
            // src/Integrations is pruned from this flavor, and this boot-time
            // call fataled every request of the vertical on activation (found
            // installing the zip for issue #1140). The Query Monitor pack it
            // serves never ships here.
            [
                "            // The page request get-query-monitor-page-profile loads with a\n"
                    . "            // one-time token (issue #703); a no-op on every other request.\n"
                    . "            \\WPMCP\\Integrations\\Query_Monitor_Status::register();\n",
                '',
                1,
            ],
            ["            'code'           => fn () => \$this->register_code_abilities(\$registrar),\n", '', 1],
            // The handler instances of the registrations removed above.
            [
                "        \$query          = new Query();\n"
                    . "        \$insert_row     = new Insert_Row();\n"
                    . "        \$update_rows    = new Update_Rows();\n"
                    . "        \$delete_rows    = new Delete_Rows();\n",
                '',
                1,
            ],
            [
                "        \$write_file     = new Write_File();\n"
                    . "        \$edit_file      = new Edit_File();\n"
                    . "        \$delete_file    = new Delete_File();\n",
                '',
                1,
            ],
            [
                "        \$push_site_archive    = new Push_Site_Archive();\n"
                    . "        \$receive_site_archive = new Receive_Site_Archive();\n",
                '',
                1,
            ],
            // The cloud announcements feed and the cloud client leave
            // (decision D4); src/Admin/Announcements.php and src/Cloud go by
            // path below.
            [
                "            // Cloud announcements feed (issue #138): dismissible dated\n"
                    . "            // notices on wpmcp screens only, never site-wide. 24h transient\n"
                    . "            // cache, per-user dismissal, silent on any cloud failure.\n"
                    . "            Announcements::register();\n",
                '',
                1,
            ],
            [
                "            // Seal any phase A plaintext cloud credentials on the first page\n"
                    . "            // load after an update (issue #141); a no-op query-wise otherwise.\n"
                    . "            // The class is absent from flavors that strip src/Cloud.\n"
                    . "            if (class_exists(\\WPMCP\\Cloud\\Cloud_Credentials::class)) {\n"
                    . "                add_action('init', [\\WPMCP\\Cloud\\Cloud_Credentials::class, 'maybe_migrate_on_boot']);\n"
                    . "            }\n",
                '',
                1,
            ],
            // rotate-salts writes wp-config.php; the action leaves
            // incident-response (Incident_Response.php below), so its
            // description stops offering it.
            [
                "end-sessions (user_id or all); rotate-salts (wp-config.php, backup kept; refused if unwritable or defined elsewhere); reinstall-core-files",
                'end-sessions (user_id or all); reinstall-core-files',
                1,
            ],
            [
                'Cannot be undone: revoke, end-sessions, rotate-salts (logs out everyone, your cookie session too, not app passwords).',
                'Cannot be undone: revoke, end-sessions.',
                1,
            ],
        ],
        'src/Activator.php' => [
            [
                "\n        // Import any phase A plaintext cloud credentials into the encrypted\n"
                    . "        // vault (issue #141). Activation does not fire on an update, so the\n"
                    . "        // init hook (Cloud_Credentials::maybe_migrate_on_boot()) and the lazy\n"
                    . "        // read-path import cover that case; this covers a reactivation.\n"
                    . "        if (class_exists(Cloud_Credentials::class)) {\n"
                    . "            Cloud_Credentials::migrate_plaintext();\n"
                    . "        }\n",
                '',
                1,
            ],
        ],
        // The opt-in gates of abilities this build no longer registers.
        'src/Governance/Opt_In_Gates.php' => [
            ["            'wpmcp/run-php-snippet' => ['filter' => 'wpmcp_allow_php_exec', 'is_open' => [Php_Snippet_Guard::class, 'is_enabled']],\n", '', 1],
            [
                "            // Activating a stored snippet (issue #85) clears the same gate\n"
                    . "            // chain as running one, because an active snippet is one step\n"
                    . "            // from execution: it belongs on this list for the same reason\n"
                    . "            // dispatch-cli-job does. Its CRUD siblings and\n"
                    . "            // deactivate-php-snippet are deliberately NOT listed: they only\n"
                    . "            // read or reduce, they cannot put anything on the exec path,\n"
                    . "            // and marking them dangerous would blunt the warning.\n"
                    . "            'wpmcp/activate-php-snippet' => ['filter' => 'wpmcp_allow_php_exec', 'is_open' => [Php_Snippet_Guard::class, 'is_enabled']],\n",
                '',
                1,
            ],
            [
                "            // An Elementor Custom Code snippet (issue #558) is raw markup\n"
                    . "            // printed in wp_head, wp_body_open or wp_footer on every page:\n"
                    . "            // the same surface, so the same gate. Its list and delete\n"
                    . "            // siblings only read or reduce and are deliberately NOT listed.\n"
                    . "            'wpmcp/create-code-snippet' => ['filter' => 'wpmcp_allow_js_injection', 'is_open' => [Custom_Js_Guard::class, 'is_enabled']],\n",
                '',
                1,
            ],
            [
                "            'wpmcp/insert-row'      => ['filter' => 'wpmcp_enable_db_writes', 'is_open' => [Insert_Row::class, 'is_enabled']],\n"
                    . "            'wpmcp/update-rows'     => ['filter' => 'wpmcp_enable_db_writes', 'is_open' => [Update_Rows::class, 'is_enabled']],\n"
                    . "            'wpmcp/delete-rows'     => ['filter' => 'wpmcp_enable_db_writes', 'is_open' => [Delete_Rows::class, 'is_enabled']],\n",
                '',
                1,
            ],
            [
                "            'wpmcp/write-file'      => ['filter' => 'wpmcp_enable_fs_writes', 'is_open' => [Write_File::class, 'is_enabled']],\n"
                    . "            'wpmcp/edit-file'       => ['filter' => 'wpmcp_enable_fs_writes', 'is_open' => [Edit_File::class, 'is_enabled']],\n"
                    . "            'wpmcp/delete-file'     => ['filter' => 'wpmcp_enable_fs_writes', 'is_open' => [Delete_File::class, 'is_enabled']],\n",
                '',
                1,
            ],
            [
                "            // Site-to-site migration (issue #191): receiving replaces this\n"
                    . "            // site's database, sending ships its secrets to another host.\n"
                    . "            'wpmcp/receive-site-archive' => ['filter' => 'wpmcp_accept_incoming_migrations', 'is_open' => [Migration_Guard::class, 'accepts_incoming']],\n"
                    . "            'wpmcp/push-site-archive'    => ['filter' => 'wpmcp_allow_outgoing_migrations', 'is_open' => [Migration_Guard::class, 'allows_outgoing']],\n",
                '',
                1,
            ],
        ],
        // Quarantine stays and keeps the same fs-writes opt-in, read from
        // the filter directly now that Delete_File is not in the zip.
        'src/Tools/Security/File_Quarantine.php' => [
            [
                "        if (! Delete_File::is_enabled()) {\n",
                "        if (! (bool) apply_filters('wpmcp_enable_fs_writes', false)) {\n",
                1,
            ],
        ],
        // scan-security's compromise checks pointed at delete-file. The
        // file-removal path this build keeps is incident-response
        // quarantine-file, which is undoable the same way.
        'src/Tools/Security/Compromise_Audit.php' => [
            ['with delete-file (undoable)', 'with incident-response quarantine-file (undoable)', 6],
            ["'next_step' => 'wpmcp/delete-file'", "'next_step' => 'wpmcp/incident-response'", 2],
        ],
        // The bundled playbooks may not name a tool this build lacks.
        'src/Skills/library/wpmcp-governance/SKILL.md' => [
            [
                "same delete through `wpmcp/query` or `wpmcp/call-rest` is a policy violation,\n",
                "same delete through another tool is a policy violation,\n",
                1,
            ],
        ],
        'src/Skills/library/wpmcp-safe-writes/SKILL.md' => [
            [
                "- File writes are backed up separately (`wpmcp/edit-file`, `wpmcp/write-file`\n"
                    . "  keep a file backup), but a rollback of a theme file does not undo whatever\n"
                    . "  the site did while the broken file was live. A `.php` write is refused if it\n"
                    . "  does not parse, and reverted in the same call if the front end or admin then\n"
                    . "  hits a fatal error. When that site check cannot run, the write is refused;\n"
                    . "  pass `unchecked: true` only after telling the user the file will not be\n"
                    . "  checked.\n",
                '',
                1,
            ],
        ],
        // rotate-salts leaves incident-response with Salt_Rotator.
        'src/Tools/Security/Incident_Response.php' => [
            [" *  - rotate-salts: Salt_Rotator.\n", '', 1],
            [
                " * and sessions take core's own meta capabilities (edit_user), salts take\n"
                    . " * manage_network_options on multisite, core files take update_core, and both\n",
                " * and sessions take core's own meta capabilities (edit_user), core files\n"
                    . " * take update_core, and both\n",
                1,
            ],
            [
                " * revoke-all or end-sessions unless include_current:true. Salt rotation cannot\n"
                    . " * spare anyone: it invalidates every cookie session, the caller's included,\n"
                    . " * but never an application password.\n",
                " * revoke-all or end-sessions unless include_current:true.\n",
                1,
            ],
            ["        'rotate-salts',\n", '', 1],
            ["    private ?Salt_Rotator \$salts;\n", '', 1],
            [
                "    public function __construct(?Salt_Rotator \$salts = null, ?Core_File_Repair \$core = null, ?File_Quarantine \$quarantine = null)\n"
                    . "    {\n"
                    . "        \$this->salts      = \$salts;\n",
                "    public function __construct(?Core_File_Repair \$core = null, ?File_Quarantine \$quarantine = null)\n"
                    . "    {\n",
                1,
            ],
            [
                "                case 'rotate-salts':\n"
                    . "                    return \$this->rotate_salts();\n",
                '',
                1,
            ],
            [
                "    private function rotate_salts(): array\n"
                    . "    {\n"
                    . "        if (! current_user_can(is_multisite() ? 'manage_network_options' : 'manage_options')) {\n"
                    . "            throw new \\RuntimeException('Rotating the salts takes manage_options (manage_network_options on multisite).');\n"
                    . "        }\n"
                    . "        \$this->require_file_mods();\n"
                    . "\n"
                    . "        \$result = (\$this->salts ?? new Salt_Rotator())->rotate();\n"
                    . "\n"
                    . "        Governance_Audit_Log::record_quietly(self::ABILITY, true, 'rotate-salts:rotated=' . count(\$result['rotated']));\n"
                    . "\n"
                    . "        return \$result + [\n"
                    . "            'recoverable' => false,\n"
                    . "            'note'        => 'Every login session is now invalid, yours included; application passwords still work. The previous wp-config.php is kept as the backup file next to it.',\n"
                    . "        ];\n"
                    . "    }\n"
                    . "\n",
                '',
                1,
            ],
        ],
    ],

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
        // Portable bundles (issue #297) register inside the same 'code'
        // group, so they have no registration path here either.
        'src/Tools/Code/Php_Snippet_Bundle_Kind.php',
        'src/Tools/Portable',
        // Issue #1140, the handlers of the registrations removed above.
        'src/Tools/Filesystem/Write_File.php',
        'src/Tools/Filesystem/Edit_File.php',
        'src/Tools/Filesystem/Delete_File.php',
        'src/Tools/Database/Query.php',
        'src/Tools/Database/Insert_Row.php',
        'src/Tools/Database/Update_Rows.php',
        'src/Tools/Database/Delete_Rows.php',
        'src/Tools/Migration/Push_Site_Archive.php',
        'src/Tools/Migration/Receive_Site_Archive.php',
        'src/Tools/Migration/Incoming_Archive_Store.php',
        'src/Tools/Migration/Migration_Target_Client.php',
        'src/Tools/Migration/Migration_Guard.php',
        'src/Tools/Security/Salt_Rotator.php',
        // The playbook for raw SQL work: every tool it teaches beyond the
        // two schema readers is gone from this build.
        'src/Skills/library/wpmcp-safe-database-work',
        // The cloud announcements feed and the cloud client (decision D4).
        'src/Admin/Announcements.php',
        'src/Cloud',
    ],
];
