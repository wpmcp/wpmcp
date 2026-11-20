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
        // Site migration and site-wide search and replace: every ability in
        // the 'migration' group leaves (owner decision, 20 Nov 2026). Its
        // $groups entry is edited out below.
        'register_migration_abilities',
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
        // File writes, and the read-only file tools too (owner decision,
        // 20 Nov 2026: the FAQ names file managers).
        'wpmcp/write-file',
        'wpmcp/edit-file',
        'wpmcp/delete-file',
        'wpmcp/read-file',
        'wpmcp/list-directory',
        'wpmcp/search-files',
        // Raw SQL and DB writes. list-tables and describe-table stay.
        'wpmcp/query',
        'wpmcp/insert-row',
        'wpmcp/update-rows',
        'wpmcp/delete-rows',
        // Whole-site restore from a backup archive replays the archive's SQL
        // dump through Sql_Importer (owner decision (a), 20 Nov 2026, issue
        // #1147). Taking backups stays: trigger-backup and the job tools
        // keep working, only putting an archive back over this site goes.
        'wpmcp/restore-site-backup',
    ],

    /*
     * A direct call to a stripped tool, or to a stripped action of a kept
     * one, is told it is not in this build rather than "Tool not found" or
     * the input schema's "is not one of" (src/MCP/Not_In_Build.php, consulted
     * first by both transports below).
     */
    'added_files' => [
        'src/MCP/Not_In_Build.php' => 'overlay/Not_In_Build.php',
    ],

    'edits' => [
        'src/MCP/Hidden_Tool_Refusal.php' => [
            [
                "    private static function rewrite(array \$message, array \$answer): array\n    {\n",
                "    private static function rewrite(array \$message, array \$answer): array\n    {\n"
                    . "        \$not_in_build = Not_In_Build::for_message(\$message);\n"
                    . "        if (null !== \$not_in_build) {\n"
                    . "            return [ 'jsonrpc' => '2.0', 'id' => \$answer['id'] ?? null, 'result' => self::result(\$not_in_build) ];\n"
                    . "        }\n",
                1,
            ],
        ],
        'src/MCP/Stdio_Transport.php' => [
            [
                "        \$ability_name = self::ability_for_tool(\$tool_name);\n",
                "        \$ability_name = self::ability_for_tool(\$tool_name);\n"
                    . "        \$not_in_build = Not_In_Build::refusal(\$tool_name, is_array(\$params['arguments'] ?? null) ? \$params['arguments'] : []);\n"
                    . "        if (null !== \$not_in_build) {\n"
                    . "            return self::result_response(\$id, Hidden_Tool_Refusal::result(\$not_in_build));\n"
                    . "        }\n",
                1,
            ],
        ],
        'src/Plugin.php' => [
            ["            'code'           => fn () => \$this->register_code_abilities(\$registrar),\n", '', 1],
            ["            'migration'      => fn () => \$this->register_migration_abilities(\$registrar),\n", '', 1],
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
                "        \$read_file      = new Read_File();\n"
                    . "        \$list_directory = new List_Directory();\n"
                    . "        \$search_files   = new Search_Files();\n"
                    . "        \$write_file     = new Write_File();\n"
                    . "        \$edit_file      = new Edit_File();\n"
                    . "        \$delete_file    = new Delete_File();\n",
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
            // incident-response keeps its credential and session actions
            // (Incident_Response.php below), so its description and schema
            // stop offering the file and salt actions.
            ['\'list-app-passwords (no secrets); revoke-app-password (uuid or all); list-sessions (one user, masked, no tokens); end-sessions (user_id or all); rotate-salts (wp-config.php, backup kept; refused if unwritable or defined elsewhere); reinstall-core-files (files scan-security flags, from the checksum-verified official package, never wp-content; undo: rollback-operation); quarantine-file (path, reason: moves it to a private folder; not core, wp-config or WP MCP files; undo: rollback-operation); list-quarantine; restore-quarantined-file (id, overwrite); purge-quarantined-file (id). Quarantine needs the wpmcp_enable_fs_writes opt-in. Writes need confirm:true. Cannot be undone: revoke, end-sessions, rotate-salts (logs out everyone, your cookie session too, not app passwords). Your session and app password stay unless include_current. Other users take edit_users\',', '\'list-app-passwords (no secrets); revoke-app-password (uuid or all); list-sessions (one user, masked, no tokens); end-sessions (user_id or all). Writes need confirm:true and cannot be undone. Your session and app password stay unless include_current. Other users take edit_users\',', 1],
            ['                    \'paths\'           => [ \'type\' => \'array\', \'items\' => [ \'type\' => \'string\' ] ],
                    \'path\'            => [ \'type\' => \'string\' ],
                    \'reason\'          => [ \'type\' => \'string\' ],
                    \'id\'              => [ \'type\' => \'string\' ],
                    \'overwrite\'       => [ \'type\' => \'boolean\' ],
                    \'unchecked\'       => [ \'type\' => \'boolean\' ],
', '', 1],
            // The restore engine leaves (issue #1147, owner decision (a), 20
            // Nov 2026): its cron executor and the handler instance of the
            // restore-site-backup registration removed above. prune_unused_imports()
            // then drops the two `use` lines nothing names any more.
            [
                "            // The executor for a restore queued by restore-site-backup\n"
                    . "            // (issue #727). See Run_Restore_Job's docblock.\n"
                    . "            add_action(Run_Restore_Job::HOOK, [new Run_Restore_Job(), 'handle']);\n",
                '',
                1,
            ],
            ["        \$restore_site_backup   = new Restore_Site_Backup();\n", '', 1],
            [
                "     * restore-site-backup is the exception and is deliberately NOT routed\n"
                    . "     * through Safe_Mutation either: a whole-database replace is outside the\n"
                    . "     * per-object model Snapshot_Store captures, so a snapshot could not\n"
                    . "     * undo it. Its rollback mechanism is the pre-restore database safety\n"
                    . "     * archive it takes, unconditionally, before writing anything (issue\n"
                    . "     * #190); a failed import is rolled back from that archive\n"
                    . "     * automatically. It is registered with destructive=true and dry_run\n"
                    . "     * defaulting to true, so an unconfirmed call only ever returns the\n"
                    . "     * compatibility report.\n",
                "     * This build ships no whole-site restore (issue #1147).\n",
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
        // scan-security's compromise checks pointed at read-file,
        // search-files and delete-file. None ships here, so the hints point
        // at the site's own file access (SFTP or the host's file manager)
        // and the next_step keys naming those tools go.
        'src/Tools/Security/Compromise_Audit.php' => [
            [", 'next_step' => 'wpmcp/read-file']", ']', 5],
            [", 'next_step' => 'wpmcp/search-files']", ']', 2],
            [", 'next_step' => 'wpmcp/delete-file']", ']', 2],
            ['Read the file with read-file. If it is not yours, remove it with delete-file (undoable), remove the hook', 'Inspect the file over SFTP or in your hosting file manager. If it is not yours, remove it there, remove the hook', 1],
            ['Read it with read-file. If your host or a plugin you trust did not put it there, remove it with delete-file (undoable).', 'Inspect it over SFTP or in your hosting file manager. If your host or a plugin you trust did not put it there, remove it there.', 1],
            ['Read each with read-file and remove any you do not recognise with delete-file (undoable).', 'Inspect each over SFTP or in your hosting file manager and remove any you do not recognise.', 1],
            ['and inspect the other with search-files and read-file.', 'and inspect the other over SFTP or in your hosting file manager.', 1],
            ['Read it with read-file and remove it with delete-file (undoable) unless you know what it is.', 'Inspect it over SFTP or in your hosting file manager and remove it unless you know what it is.', 1],
            ['or remove it with delete-file (undoable)', 'or remove it over SFTP or in your hosting file manager', 2],
            ['Read each with read-file and compare it with a fresh copy of its plugin or theme.', 'Compare each, over SFTP or in your hosting file manager, with a fresh copy of its plugin or theme.', 1],
            ['for the spam domain with search-files and search-content, remove', 'for the spam domain over SFTP or in your hosting file manager, remove', 1],
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
        // incident-response keeps its credential and session actions only;
        // salt rotation, core-file reinstall and the quarantine family leave
        // with Salt_Rotator, Core_File_Repair and File_Quarantine.
        'src/Tools/Security/Incident_Response.php' => [
            [' *  - rotate-salts: Salt_Rotator.
 *  - reinstall-core-files: Core_File_Repair.
 *  - quarantine-file, list-quarantine, restore-quarantined-file,
 *    purge-quarantined-file: File_Quarantine (issue #844), behind the
 *    wpmcp_enable_fs_writes opt-in like the other file-changing tools.
', '', 1],
            [' * and sessions take core\'s own meta capabilities (edit_user), salts take
 * manage_network_options on multisite, core files take update_core, and both
 * file writes honor DISALLOW_FILE_MODS.
', ' * and sessions take core\'s own meta capabilities (edit_user).
', 1],
            [' * revoke-all or end-sessions unless include_current:true. Salt rotation cannot
 * spare anyone: it invalidates every cookie session, the caller\'s included,
 * but never an application password.
', ' * revoke-all or end-sessions unless include_current:true.
', 1],
            ['        \'rotate-salts\',
        \'reinstall-core-files\',
        \'quarantine-file\',
        \'list-quarantine\',
        \'restore-quarantined-file\',
        \'purge-quarantined-file\',
', '', 1],
            ['    private ?Salt_Rotator $salts;
    private ?Core_File_Repair $core;
    private ?File_Quarantine $quarantine;

    public function __construct(?Salt_Rotator $salts = null, ?Core_File_Repair $core = null, ?File_Quarantine $quarantine = null)
    {
        $this->salts      = $salts;
        $this->core       = $core;
        $this->quarantine = $quarantine;
    }

', '', 1],
            ['        if (\'list-quarantine\' === $action) {
            $result = $this->quarantine()->list();
            Governance_Audit_Log::record_quietly(self::ABILITY, true, \'list-quarantine:count=\' . $result[\'count\']);
            return $result;
        }

', '', 1],
            ['                false !== strpos($action, \'quarantine\') ? \'%s requires confirm:true.\' : \'%s cannot be undone in full and requires confirm:true.\',
', '                \'%s cannot be undone in full and requires confirm:true.\',
', 1],
            ['                case \'end-sessions\':
                    return $this->end_sessions($args);
                case \'rotate-salts\':
                    return $this->rotate_salts();
                case \'quarantine-file\':
                case \'restore-quarantined-file\':
                case \'purge-quarantined-file\':
                    return $this->quarantine_action($action, $args);
                default:
                    return $this->reinstall_core_files($args);
', '                default:
                    return $this->end_sessions($args);
', 1],
            ['    // -----------------------------------------------------------------
    // salts and core files
    // -----------------------------------------------------------------

    private function rotate_salts(): array
    {
        if (! current_user_can(is_multisite() ? \'manage_network_options\' : \'manage_options\')) {
            throw new \\RuntimeException(\'Rotating the salts takes manage_options (manage_network_options on multisite).\');
        }
        $this->require_file_mods();

        $result = ($this->salts ?? new Salt_Rotator())->rotate();

        Governance_Audit_Log::record_quietly(self::ABILITY, true, \'rotate-salts:rotated=\' . count($result[\'rotated\']));

        return $result + [
            \'recoverable\' => false,
            \'note\'        => \'Every login session is now invalid, yours included; application passwords still work. The previous wp-config.php is kept as the backup file next to it.\',
        ];
    }

    private function reinstall_core_files(array $args): array
    {
        if (! current_user_can(\'update_core\')) {
            throw new \\RuntimeException(\'Reinstalling core files takes update_core.\');
        }
        $this->require_file_mods();

        $paths  = isset($args[\'paths\']) && is_array($args[\'paths\']) ? array_values($args[\'paths\']) : [];
        $result = ($this->core ?? new Core_File_Repair())->repair($paths, $args);

        Governance_Audit_Log::record_quietly(
            self::ABILITY,
            true,
            sprintf(\'reinstall-core-files:repaired=%d:skipped=%d\', count($result[\'repaired\']), count($result[\'skipped\']))
        );

        return $result;
    }

    private function quarantine(): File_Quarantine
    {
        return $this->quarantine ??= new File_Quarantine();
    }

    private function quarantine_action(string $action, array $args): array
    {
        $quarantine = $this->quarantine();
        if (\'quarantine-file\' === $action) {
            $result = $quarantine->quarantine($args);
            $detail = \'id=\' . $result[\'entry\'][\'id\'];
        } elseif (\'restore-quarantined-file\' === $action) {
            $result = $quarantine->restore($args);
            $detail = \'id=\' . $result[\'id\'];
        } else {
            $result = $quarantine->purge($args);
            $detail = \'id=\' . $result[\'id\'];
        }
        Governance_Audit_Log::record_quietly(self::ABILITY, true, $action . \':\' . $detail);
        return $result;
    }

    private function require_file_mods(): void
    {
        if (! wp_is_file_mod_allowed(\'wpmcp_incident_response\')) {
            throw new \\RuntimeException(\'File changes are disabled on this site (DISALLOW_FILE_MODS).\');
        }
    }

', '', 1],
        ],
        // The quarantine suggestion leaves with quarantine-file.
        'src/Tools/Security/Malware_Audit.php' => [
            ['    /**
     * Pure: give each malware finding that names a file the next step an
     * agent can chain (issue #844): incident-response quarantine-file for
     * that path, with the finding id as the reason. Informational findings
     * and findings without a single location are left as they are.
     *
     * @param array[] $findings
     * @return array[]
     */
    public function with_suggested_actions(array $findings): array
    {
        foreach ($findings as $i => $finding) {
            $location = $finding[\'value\'][\'location\'] ?? null;
            if (\'malware\' !== ($finding[\'category\'] ?? \'\') || \'info\' === ($finding[\'status\'] ?? \'\') || ! is_string($location) || \'\' === $location) {
                continue;
            }
            $path = (string) preg_replace(\'/:\\d+$/\', \'\', $location);
            $findings[ $i ][\'suggested_action\'] = [
                \'ability\'   => \'wpmcp/incident-response\',
                \'arguments\' => [\'action\' => \'quarantine-file\', \'path\' => $path, \'reason\' => (string) ($finding[\'id\'] ?? \'\')],
            ];
        }
        return $findings;
    }
', '    /**
     * Pure: this build ships no file tool to chain a malware finding to, so
     * findings carry no suggested action (issue #1140).
     *
     * @param array[] $findings
     * @return array[]
     */
    public function with_suggested_actions(array $findings): array
    {
        return $findings;
    }
', 1],
        ],

        /*
         * Issue #1147, owner decision (a), 20 Nov 2026: no restore engine
         * ships (Restore_Site_Backup, Run_Restore_Job and the SQL importer
         * go by path below), so no restore can run in this build. The backup
         * code that asks about a restore keeps the same answers it gives on
         * a site where none is running: a job of type 'restore' (only ever
         * left behind by the full plugin) is still never run or canceled as
         * a backup, and the restore-only signals read as "none".
         */
        // Restore_Site_Backup::JOB_TYPE is 'restore'.
        'src/Tools/Backup/Run_Backup_Job.php' => [
            ["Restore_Site_Backup::JOB_TYPE === (\$job['type'] ?? null)", "'restore' === (\$job['type'] ?? null)", 2],
        ],
        'src/Tools/Backup/Cancel_Backup_Job.php' => [
            ["Restore_Site_Backup::JOB_TYPE === (\$job['type'] ?? null)", "'restore' === (\$job['type'] ?? null)", 1],
            // Run_Restore_Job::HOOK, so a queued restore record left by the
            // full plugin still has its event cleared.
            ["wp_clear_scheduled_hook(Run_Restore_Job::HOOK, [\$job_id]);", "wp_clear_scheduled_hook('wpmcp_run_restore_job', [\$job_id]);", 1],
        ],
        // A live restore is one whose progress file is on disk; without the
        // engine there is none to report.
        'src/Tools/Backup/Get_Backup_Status.php' => [
            [
                "        \$progress = Restore_Site_Backup::progress();\n"
                    . "        if (null === \$progress || \$job_id !== (int) (\$progress['state']['restore_job_id'] ?? 0)) {\n"
                    . "            return null;\n"
                    . "        }\n"
                    . "\n"
                    . "        // A finished record is the outcome; a leftover progress file does\n"
                    . "        // not override it.\n"
                    . "        \$finished = is_array(\$job) && Restore_Site_Backup::JOB_TYPE === (\$job['type'] ?? null)\n"
                    . "            && in_array(\$job['status'] ?? null, ['completed', 'failed', 'canceled'], true);\n"
                    . "        if (\$finished && ! \$progress['running']) {\n"
                    . "            return null;\n"
                    . "        }\n"
                    . "\n"
                    . "        \$base = is_array(\$job) && Restore_Site_Backup::JOB_TYPE === (\$job['type'] ?? null)\n"
                    . "            ? \$job\n"
                    . "            : [\n"
                    . "                'id'         => \$job_id,\n"
                    . "                'type'       => Restore_Site_Backup::JOB_TYPE,\n"
                    . "                'scope'      => '',\n"
                    . "                'created_at' => Backup_Job_Store::now(),\n"
                    . "                'updated_at' => Backup_Job_Store::now(),\n"
                    . "            ];\n"
                    . "\n"
                    . "        \$stopped = Restore_Site_Backup::stopped_fields(\$progress['state']);\n"
                    . "\n"
                    . "        if (\$progress['running']) {\n"
                    . "            return array_merge(\$base, \$stopped, ['status' => 'running', 'error' => null]);\n"
                    . "        }\n"
                    . "\n"
                    . "        return array_merge(\$base, \$stopped);\n",
                "        // This build ships no restore engine (issue #1147), so no\n"
                    . "        // restore progress file is ever written here.\n"
                    . "        unset(\$job);\n"
                    . "\n"
                    . "        return null;\n",
                1,
            ],
        ],
        // Restore_Site_Backup::stopped_run() decides only for restore jobs;
        // with no restore engine a leftover restore record keeps the
        // time-based stale limit every other job has.
        'src/Tools/Backup/Backup_Job_Store.php' => [
            [
                "        if (Restore_Site_Backup::JOB_TYPE !== (\$job['type'] ?? null)) {\n"
                    . "            return null;\n"
                    . "        }\n"
                    . "\n"
                    . "        return Restore_Site_Backup::stopped_run(\$job);\n",
                "        // This build ships no restore engine (issue #1147).\n"
                    . "        unset(\$job);\n"
                    . "\n"
                    . "        return null;\n",
                1,
            ],
        ],
        // Restore_Site_Backup::is_running() is false where no restore can
        // run; the wpmcp_maintenance option still drives both.
        'src/Maintenance/Maintenance_Guard.php' => [
            ["use WPMCP\\Tools\\Backup\\Restore_Site_Backup;\n", '', 1],
            [
                "        if (! \$on && ! Restore_Site_Backup::is_running()) {\n",
                "        if (! \$on) {\n",
                1,
            ],
            [
                " * restored site. Restore_Site_Backup::is_running() (its progress file plus\n"
                    . " * its MySQL lock) covers that window.\n",
                " * restored site. This build ships no restore engine (issue #1147), so\n"
                    . " * only the option applies here.\n",
                1,
            ],
        ],
        'src/Maintenance/Site_Gate.php' => [
            ["use WPMCP\\Tools\\Backup\\Restore_Site_Backup;\n", '', 1],
            ["        \$restore = Restore_Site_Backup::is_running();\n", "        \$restore = false;\n", 1],
        ],
        // The core update's undo advice named restore-site-backup; the
        // archive it insists on is still taken and its job id still returned.
        'src/Tools/Packages/Manage_Updates.php' => [
            [
                "'Core update failed: %s. Restore backup job %d with restore-site-backup if the site is broken.',",
                "'Core update failed: %s. Backup job %d holds the full-site archive taken first; restore it with your host\\'s tools if the site is broken.',",
                1,
            ],
            [
                "'Core changes cannot be snapshot-rolled-back. To revert, restore backup job %d with restore-site-backup (include_files).',",
                "'Core changes cannot be snapshot-rolled-back. Backup job %d holds the full-site archive taken first; this build cannot restore it.',",
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
        'src/Tools/Filesystem/Read_File.php',
        'src/Tools/Filesystem/List_Directory.php',
        'src/Tools/Filesystem/Search_Files.php',
        // Migration push and receive, and site-wide search and replace.
        'src/Tools/Migration',
        'src/Tools/Security/Salt_Rotator.php',
        'src/Tools/Security/Core_File_Repair.php',
        'src/Tools/Security/File_Quarantine.php',
        // The playbook for raw SQL work: every tool it teaches beyond the
        // two schema readers is gone from this build.
        'src/Skills/library/wpmcp-safe-database-work',
        // The cloud announcements feed and the cloud client (decision D4).
        'src/Admin/Announcements.php',
        'src/Cloud',
        // Whole-site restore and the SQL importer it replays a dump through
        // (issue #1147, owner decision (a), 20 Nov 2026). Restore_Session and
        // Restore_Logs serve only the restore; Sql_Import_Policy and
        // Sql_Statement_Reader serve only the importer. Safety_Archive's
        // callers (the migration tools) left in #1140, and its one message
        // pointed at restore-site-backup. Restore_Files, Restore_Maintenance
        // and Url_Rewriter stay: the archive builder, the data registry and
        // the maintenance guard use them.
        'src/Tools/Backup/Restore_Site_Backup.php',
        'src/Tools/Backup/Run_Restore_Job.php',
        'src/Tools/Backup/Restore_Session.php',
        'src/Tools/Backup/Restore_Logs.php',
        'src/Tools/Backup/Sql_Importer.php',
        'src/Tools/Backup/Sql_Import_Policy.php',
        'src/Tools/Backup/Sql_Statement_Reader.php',
        'src/Tools/Backup/Safety_Archive.php',
    ],
];
