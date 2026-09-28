<?php
/**
 * Faithful global GFAPI test double for the Gravity Forms integration tests.
 *
 * Gravity Forms is a paid plugin and cannot be installed from wordpress.org in
 * the test harness, so this stub reproduces the exact public method contracts
 * used by Gravity_Forms_Integration (verified against Gravity Forms 2.9's
 * includes/api.php). Live Gravity Forms remains production-verified, matching
 * the plugin's stance for third-party services CI cannot boot.
 *
 * Entries live in a REAL gf_entry table (id, form_id, status, date_created,
 * plus a JSON column standing in for gf_entry_meta), because the status write
 * is snapshotted as a db_rows before-image of that row and the rollback must
 * be exercised against a real row. install_table() is DDL: call it from
 * set_up_before_class, never inside a test transaction. Forms and notes stay
 * in static arrays.
 *
 * Not reproduced: gf_entry_meta as a separate table, search criteria other
 * than status, and sorting other than newest first.
 *
 * Only defined when the real GFAPI is absent, so a real install always wins.
 */
if (! class_exists('GFAPI')) {
    class GFAPI
    {
        /** @var array<int,array<string,mixed>> */
        public static array $forms = [];
        /** @var array<int,array<string,mixed>> */
        public static array $notes = [];

        public static function reset(): void
        {
            self::$forms = self::$notes = [];
        }

        public static function table(): string
        {
            global $wpdb;
            return $wpdb->prefix . 'gf_entry';
        }

        public static function install_table(): void
        {
            global $wpdb;
            $wpdb->query('CREATE TABLE IF NOT EXISTS ' . self::table() . " (
                id int(10) unsigned NOT NULL AUTO_INCREMENT,
                form_id mediumint(8) unsigned NOT NULL DEFAULT 0,
                status varchar(20) NOT NULL DEFAULT 'active',
                date_created datetime NOT NULL DEFAULT '2026-01-01 00:00:00',
                meta longtext NOT NULL,
                PRIMARY KEY  (id)
            )");
        }

        public static function uninstall_table(): void
        {
            global $wpdb;
            $wpdb->query('DROP TABLE IF EXISTS ' . self::table());
        }

        /** Test seam: insert one entry; field values are keyed by field id. */
        public static function seed_entry(int $form_id, array $values, string $status = 'active', string $date = '2026-01-01 00:00:00'): int
        {
            global $wpdb;
            $wpdb->insert(self::table(), [
                'form_id'      => $form_id,
                'status'       => $status,
                'date_created' => $date,
                'meta'         => wp_json_encode($values),
            ]);
            return (int) $wpdb->insert_id;
        }

        private static function shape(array $row): array
        {
            $meta = json_decode((string) $row['meta'], true);
            return [
                'id'           => (string) $row['id'],
                'form_id'      => (string) $row['form_id'],
                'status'       => (string) $row['status'],
                'date_created' => (string) $row['date_created'],
            ] + (is_array($meta) ? $meta : []);
        }

        public static function get_forms($active = true, $trash = false, $sort_column = 'id', $sort_dir = 'ASC')
        {
            return array_values(self::$forms);
        }

        public static function get_form($form_id)
        {
            return self::$forms[(int) $form_id] ?? false;
        }

        public static function count_entries($form_ids, $search_criteria = [])
        {
            $total = 0;
            self::get_entries($form_ids, $search_criteria, null, [ 'offset' => 0, 'page_size' => 1 ], $total);
            return $total;
        }

        public static function get_entries($form_ids, $search_criteria = [], $sorting = null, $paging = null, &$total_count = null)
        {
            global $wpdb;
            $where = $wpdb->prepare('form_id = %d', (int) $form_ids);
            if (! empty($search_criteria['status'])) {
                $where .= $wpdb->prepare(' AND status = %s', (string) $search_criteria['status']);
            }
            $total_count = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE ', self::table()) . $where);
            $rows        = $wpdb->get_results(
                $wpdb->prepare('SELECT * FROM %i WHERE ', self::table()) . $where
                . ' ORDER BY id DESC'
                . $wpdb->prepare(' LIMIT %d, %d', (int) ($paging['offset'] ?? 0), (int) ($paging['page_size'] ?? 20)),
                ARRAY_A
            );
            return array_map([ self::class, 'shape' ], (array) $rows);
        }

        public static function get_entry($entry_id)
        {
            global $wpdb;
            $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', self::table(), (int) $entry_id), ARRAY_A);
            return $row ? self::shape($row) : new \WP_Error('not_found', sprintf('Entry with id %s not found', $entry_id));
        }

        /** GF 2.9: returns the $wpdb->update() result (rows changed, or false). */
        public static function update_entry_property($entry_id, $property, $value)
        {
            global $wpdb;
            return $wpdb->update(self::table(), [ $property => $value ], [ 'id' => (int) $entry_id ]);
        }

        public static function get_notes($search_criteria = [], $sorting = null)
        {
            $entry_id = (int) ($search_criteria['entry_id'] ?? 0);
            return array_values(array_filter(self::$notes, static fn ($n) => (int) ($n['entry_id'] ?? 0) === $entry_id));
        }
    }
}
