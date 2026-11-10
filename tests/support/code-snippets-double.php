<?php
/**
 * A stand-in for Code Snippets for the Code Snippets ops (issue #576) on the
 * plugin-data pair, for tests that run without Code Snippets loaded.
 *
 * Storage is kept the way Code Snippets 4.1 keeps it (php/Core/DB.php,
 * php/snippet-ops.php, php/Model/Snippet.php): one {prefix}snippets table
 * with its columns, the recently_active_snippets option of id => time a
 * snippet was last switched off, and the code_snippets_locked option. The
 * functions keep the behavior the ops depend on: save_snippet() returns the
 * saved Snippet or null and, like the real one, RUNS an active PHP snippet's
 * code to test it (counted in $GLOBALS['wpmcp_test_code_snippets_executed']
 * here, so a test can prove the ops never trigger it); activate_snippet()
 * returns the Snippet or an error string and refuses code its validator
 * rejects (here: code containing WPMCP_CS_INVALID); deactivate_snippet()
 * returns the Snippet or null and stamps recently_active_snippets.
 *
 * Defined only when the real Code Snippets is not loaded, and
 * CODE_SNIPPETS_VERSION is never defined here, so the pack counts as active
 * only where a test turns wpmcp_code_snippets_active on.
 */

// phpcs:disable

namespace Code_Snippets\Model {
    if (! class_exists('Code_Snippets\Model\Snippet', false)) {
        class Snippet
        {
            private array $fields = [
                'id' => 0, 'name' => '', 'desc' => '', 'code' => '', 'tags' => [], 'scope' => 'global',
                'condition_id' => 0, 'active' => false, 'trashed' => false, 'locked' => false, 'priority' => 10,
                'network' => false, 'shared_network' => null, 'modified' => null, 'code_error' => null,
                'code_error_trace' => null, 'revision' => 1, 'cloud_id' => 0, 'created_by' => 0, 'updated_by' => 0,
            ];

            public function __construct($data = null)
            {
                foreach ((array) $data as $k => $v) {
                    $this->__set('description' === $k ? 'desc' : $k, $v);
                }
            }

            public function __get(string $f)
            {
                if ('type' === $f) {
                    $s = $this->fields['scope'];
                    return '-css' === substr($s, -4) ? 'css' : ('-js' === substr($s, -3) ? 'js' : ('content' === substr($s, -7) ? 'html' : ('condition' === $s ? 'cond' : 'php')));
                }
                if ('tags_list' === $f) {
                    return implode(', ', $this->fields['tags']);
                }
                return $this->fields[ $f ] ?? null;
            }

            public function __isset(string $f): bool
            {
                return isset($this->fields[ $f ]) || in_array($f, [ 'type', 'tags_list' ], true);
            }

            public function __set(string $f, $v): void
            {
                if (! array_key_exists($f, $this->fields)) {
                    return;
                }
                switch ($f) {
                    case 'id':
                    case 'revision':
                    case 'condition_id':
                    case 'created_by':
                    case 'updated_by':
                    case 'cloud_id':
                        $v = absint($v);
                        break;
                    case 'priority':
                        $v = max(0, min(32767, (int) $v));
                        break;
                    case 'tags':
                        $v = is_string($v) ? array_values(array_filter(array_map('trim', explode(',', $v)), 'strlen')) : array_values((array) $v);
                        break;
                    case 'active':
                        if (-1 === (int) $v) {
                            $this->fields['trashed'] = true;
                            $v = false;
                        } else {
                            $v = 1 === (int) $v && ! $this->fields['trashed'];
                        }
                        break;
                    case 'locked':
                        $v = (bool) $v;
                        break;
                }
                $this->fields[ $f ] = $v;
            }

            public function update_modified(): void
            {
                $this->fields['modified'] = gmdate('Y-m-d H:i:s');
            }
        }
    }
}

namespace Code_Snippets {
    use Code_Snippets\Model\Snippet;

    if (! function_exists('Code_Snippets\code_snippets')) {
        class WPMCP_Test_DB
        {
            public function get_table_name(?bool $network = null): string
            {
                global $wpdb;
                return $wpdb->prefix . 'snippets';
            }
        }

        class WPMCP_Test_Plugin
        {
            public WPMCP_Test_DB $db;

            public function __construct()
            {
                $this->db = new WPMCP_Test_DB();
            }

            public function user_can_manage_snippets(int $user_id, bool $network = false): bool
            {
                return user_can($user_id, apply_filters('code_snippets_cap', 'manage_options'));
            }
        }

        function code_snippets(): WPMCP_Test_Plugin
        {
            static $plugin;
            return $plugin ??= new WPMCP_Test_Plugin();
        }

        /** Create the snippets table with Code Snippets 4.1's columns. */
        function wpmcp_test_create_table(): void
        {
            global $wpdb;
            $t = code_snippets()->db->get_table_name();
            $wpdb->query("CREATE TABLE IF NOT EXISTS $t (
                id BIGINT(20) NOT NULL AUTO_INCREMENT,
                name TINYTEXT NOT NULL,
                description TEXT NOT NULL,
                code LONGTEXT NOT NULL,
                tags LONGTEXT NOT NULL,
                scope VARCHAR(15) NOT NULL DEFAULT 'global',
                condition_id BIGINT(20) NOT NULL DEFAULT 0,
                priority SMALLINT NOT NULL DEFAULT 10,
                active TINYINT(1) NOT NULL DEFAULT 0,
                modified DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                revision BIGINT(20) NOT NULL DEFAULT 1,
                cloud_id VARCHAR(255) NULL,
                created_by BIGINT(20) UNSIGNED NULL,
                updated_by BIGINT(20) UNSIGNED NULL,
                PRIMARY KEY (id)
            ) " . $wpdb->get_charset_collate());
        }

        function clean_snippets_cache(string $table_name): void
        {
            wp_cache_delete("all_snippets_$table_name", 'code_snippets');
        }

        function is_snippet_locked(int $id, ?bool $network = null): bool
        {
            $locked = get_option('code_snippets_locked', []);
            return ! empty($locked[ $id ]);
        }

        function get_snippets(array $ids = [], ?bool $network = null): array
        {
            global $wpdb;
            $t        = code_snippets()->db->get_table_name();
            $snippets = wp_cache_get("all_snippets_$t", 'code_snippets');
            if (! is_array($snippets)) {
                $snippets = array_map(static function (array $row): Snippet {
                    $s         = new Snippet($row);
                    $s->locked = is_snippet_locked($s->id);
                    return $s;
                }, (array) $wpdb->get_results("SELECT * FROM $t", ARRAY_A));
                wp_cache_set("all_snippets_$t", $snippets, 'code_snippets');
            }
            return [] === $ids ? $snippets : array_values(array_filter($snippets, static fn (Snippet $s): bool => in_array($s->id, array_map('intval', $ids), true)));
        }

        function get_snippet(int $id = 0, ?bool $network = null): ?Snippet
        {
            global $wpdb;
            if (0 === $id) {
                return new Snippet();
            }
            $t   = code_snippets()->db->get_table_name();
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id = %d", $id), ARRAY_A);
            $s   = new Snippet($row ?: []);
            if ($s->id > 0) {
                $s->locked = is_snippet_locked($s->id);
            }
            return $s;
        }

        function save_snippet($snippet): ?Snippet
        {
            global $wpdb;
            $t = code_snippets()->db->get_table_name();
            if ($snippet->id && is_snippet_locked($snippet->id)) {
                $old           = get_snippet($snippet->id);
                $snippet->code = $old->code;
                $snippet->name = $old->name;
            }
            $snippet->update_modified();
            if ('php' === $snippet->type && $snippet->active && 'single-use' !== $snippet->scope) {
                // The real save_snippet() runs the code here (test_snippet_code()).
                $GLOBALS['wpmcp_test_code_snippets_executed'] = ($GLOBALS['wpmcp_test_code_snippets_executed'] ?? 0) + 1;
            }
            $author = get_current_user_id() ?: null;
            $data   = [
                'name' => $snippet->name, 'description' => $snippet->desc, 'code' => $snippet->code,
                'tags' => $snippet->tags_list, 'scope' => $snippet->scope, 'condition_id' => (int) $snippet->condition_id,
                'priority' => $snippet->priority, 'active' => (int) $snippet->active, 'modified' => $snippet->modified,
                'revision' => $snippet->revision, 'cloud_id' => null, 'updated_by' => $author,
            ];
            if (0 === $snippet->id) {
                $data['created_by'] = $author;
                if (false === $wpdb->insert($t, $data)) {
                    return null;
                }
                $id = (int) $wpdb->insert_id;
            } else {
                if (false === $wpdb->update($t, $data, [ 'id' => $snippet->id ])) {
                    return null;
                }
                $id = $snippet->id;
            }
            clean_snippets_cache($t);
            return get_snippet($id);
        }

        function activate_snippet(int $id, ?bool $network = null)
        {
            global $wpdb;
            $s = get_snippet($id);
            if (0 === $s->id) {
                return sprintf('Could not locate snippet with ID %d.', $id);
            }
            if ('php' === $s->type && false !== strpos($s->code, 'WPMCP_CS_INVALID')) {
                return 'Could not activate snippet: code did not pass validation.';
            }
            if (! $wpdb->update(code_snippets()->db->get_table_name(), [ 'active' => '1' ], [ 'id' => $id ], [ '%d' ], [ '%d' ])) {
                return 'Could not activate snippet.';
            }
            clean_snippets_cache(code_snippets()->db->get_table_name());
            do_action('code_snippets/activate_snippet', get_snippet($id), false);
            return get_snippet($id);
        }

        function deactivate_snippet(int $id, ?bool $network = null): ?Snippet
        {
            global $wpdb;
            if (! $wpdb->update(code_snippets()->db->get_table_name(), [ 'active' => '0' ], [ 'id' => $id ], [ '%d' ], [ '%d' ])) {
                return null;
            }
            $recent        = get_option('recently_active_snippets', []);
            $recent[ $id ] = time();
            update_option('recently_active_snippets', $recent);
            clean_snippets_cache(code_snippets()->db->get_table_name());
            do_action('code_snippets/deactivate_snippet', $id, false);
            return get_snippet($id);
        }

        function delete_snippet(int $id, ?bool $network = null): bool
        {
            global $wpdb;
            if (is_snippet_locked($id)) {
                return false;
            }
            $done = (bool) $wpdb->delete(code_snippets()->db->get_table_name(), [ 'id' => $id ], [ '%d' ]);
            clean_snippets_cache(code_snippets()->db->get_table_name());
            return $done;
        }
    }
}

namespace {
    if (! function_exists('wpmcp_test_drop_code_snippets_table')) {
        function wpmcp_test_drop_code_snippets_table(): void
        {
            global $wpdb;
            $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}snippets");
        }
    }
}
