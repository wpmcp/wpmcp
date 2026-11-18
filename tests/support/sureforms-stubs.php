<?php
/**
 * Faithful global test double for the SureForms integration, reproducing the
 * SRFM\Inc\Database\Tables\Entries accessor the integration uses for every
 * entry read and delete (get, get_all, get_total_entries_by_status, delete),
 * verified against SureForms 2.x. SureForms creates its own srfm_entries table
 * on activation, which the harness cannot install, so the accessor is doubled
 * here while the forms side runs against a real sureforms_form CPT. A real
 * SureForms always wins. SRFM_VER is deliberately NOT defined: availability is
 * then driven by the CPT, so a test can exercise the unavailable path.
 */

namespace SRFM\Inc\Database\Tables {
    if (! class_exists('\SRFM\Inc\Database\Tables\Entries')) {
        class Entries
        {
            /** @var array<int,array<string,mixed>> Entry rows keyed by ID. */
            public static array $rows = [];

            /** Ids passed to delete(), in call order. */
            public static array $deleted = [];

            /** Force delete() to report failure. */
            public static bool $delete_fails = false;

            public static function reset(): void
            {
                self::$rows         = [];
                self::$deleted      = [];
                self::$delete_fails = false;
            }

            private static function table(): string
            {
                global $wpdb;
                return $wpdb->prefix . 'srfm_entries';
            }

            /**
             * The real srfm_entries table (the columns the integration and a
             * snapshot touch), so the db_rows snapshot of an entry delete runs
             * for real. DDL: call from set_up_before_class. When the table is
             * installed, seed() mirrors each row into it and delete() removes
             * it, as SureForms' own accessor would.
             */
            public static function install(): void
            {
                global $wpdb;
                $wpdb->query('CREATE TABLE IF NOT EXISTS ' . self::table() . " (
                    ID bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                    form_id bigint(20) unsigned NOT NULL DEFAULT 0,
                    status varchar(20) NOT NULL DEFAULT '',
                    created_at datetime NOT NULL DEFAULT '2026-01-01 00:00:00',
                    form_data longtext NOT NULL,
                    PRIMARY KEY  (ID)
                )");
                // SureForms' payments table (only the column a delete reads).
                $wpdb->query('CREATE TABLE IF NOT EXISTS ' . $wpdb->prefix . "srfm_payments (
                    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                    entry_id bigint(20) unsigned NOT NULL,
                    PRIMARY KEY  (id)
                )");
            }

            public static function uninstall(): void
            {
                global $wpdb;
                $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'srfm_payments');
                $wpdb->query('DROP TABLE IF EXISTS ' . self::table());
            }

            public static function table_row(int $id): ?array
            {
                global $wpdb;
                $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE ID = %d', self::table(), $id), ARRAY_A);
                return is_array($row) ? $row : null;
            }

            private static function installed(): bool
            {
                global $wpdb;
                return self::table() === $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', self::table()));
            }

            /** Seed one row the way SureForms stores it (form_data is JSON). */
            public static function seed(int $id, int $form_id, string $status, array $values, string $created_at = '2026-01-01 00:00:00'): void
            {
                self::$rows[ $id ] = [
                    'ID'         => $id,
                    'form_id'    => $form_id,
                    'status'     => $status,
                    'created_at' => $created_at,
                    'form_data'  => wp_json_encode($values),
                ];
                if (self::installed()) {
                    global $wpdb;
                    $wpdb->insert(self::table(), self::$rows[ $id ]);
                }
            }

            public static function get($id)
            {
                return self::$rows[ (int) $id ] ?? [];
            }

            /**
             * SureForms' get_records_by_args() WHERE contract, as its
             * Base::prepare_where_clauses() reads it: an int key holds a GROUP,
             * a list of int-keyed conditions; a string key is a plain column
             * equality. A condition placed directly at the top level (one
             * nesting level too shallow, issue #944) is dropped without a word,
             * exactly as the real builder drops it. Only '=' is honoured. Like
             * the real accessor it applies LIMIT offset, limit (default 10)
             * unless $set_limit is false.
             */
            public static function get_all($args = [], $set_limit = true)
            {
                $args = (array) $args + [ 'where' => [], 'limit' => 10, 'offset' => 0 ];
                $rows = array_values(self::$rows);
                foreach ((array) $args['where'] as $key => $group) {
                    $conditions = [];
                    if (is_int($key)) {
                        foreach ((array) $group as $inner => $condition) {
                            if (is_int($inner) && is_array($condition) && '=' === ($condition['compare'] ?? '')) {
                                $conditions[] = [ (string) ($condition['key'] ?? ''), $condition['value'] ?? null ];
                            }
                        }
                    } else {
                        $conditions[] = [ (string) $key, $group ];
                    }
                    foreach ($conditions as [ $column, $value ]) {
                        $rows = array_values(array_filter(
                            $rows,
                            static fn ($row) => isset($row[ $column ]) && (string) $row[ $column ] === (string) $value
                        ));
                    }
                }
                return $set_limit ? array_slice($rows, (int) $args['offset'], (int) $args['limit']) : $rows;
            }

            /** SureForms' Get_Instance trait: the accessor's singleton. */
            public static function get_instance(): self
            {
                return new self();
            }

            /** Base::get_total_count(): COUNT(*) under the same WHERE contract as get_all(). */
            public function get_total_count($where_clauses = [])
            {
                return count(self::get_all([ 'where' => $where_clauses ], false));
            }

            public static function get_total_entries_by_status($status, $form_id = 0)
            {
                return count(array_filter(
                    self::$rows,
                    static fn ($row) => (int) $row['form_id'] === (int) $form_id
                ));
            }

            public static function delete($id)
            {
                self::$deleted[] = (int) $id;
                if (self::$delete_fails) {
                    return false;
                }
                unset(self::$rows[ (int) $id ]);
                if (self::installed()) {
                    global $wpdb;
                    $wpdb->delete(self::table(), [ 'ID' => (int) $id ]);
                }
                return true;
            }
        }
    }
}
