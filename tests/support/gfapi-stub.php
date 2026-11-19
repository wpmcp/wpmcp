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
 * A form seeded with seed_form_meta() also gets a REAL gf_form_meta row
 * (form_id, display_meta, confirmations, notifications as JSON, the Gravity
 * Forms 2.9 layout), and get_form() then reads its notifications and
 * confirmations from that row, the way GFFormsModel::get_form_meta() does.
 * The GFFormsModel double below writes one of those columns, which is what
 * GFFormsModel::update_form_meta() does (issue #674).
 *
 * install_table() also creates gf_entry_meta, gf_entry_notes and
 * gf_addon_payment_transaction with Gravity Forms' own column names (2.9's
 * class-gf-upgrade.php and class-gf-payment-addon.php), the rows delete-entry
 * removes and checks (issue #675). get_entry() keeps reading values from the
 * JSON column, so the separate meta rows are only seeded where a test needs
 * them.
 *
 * Not reproduced: search criteria other than status, and sorting other than
 * newest first.
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
            $wpdb->query('CREATE TABLE IF NOT EXISTS ' . $wpdb->prefix . 'gf_entry_meta (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                form_id mediumint(8) unsigned NOT NULL DEFAULT 0,
                entry_id bigint(20) unsigned NOT NULL,
                meta_key varchar(255),
                meta_value longtext,
                item_index varchar(60),
                PRIMARY KEY  (id),
                KEY entry_id (entry_id)
            )');
            $wpdb->query('CREATE TABLE IF NOT EXISTS ' . $wpdb->prefix . "gf_entry_notes (
                id int(10) unsigned NOT NULL AUTO_INCREMENT,
                entry_id int(10) unsigned NOT NULL,
                user_name varchar(250),
                user_id bigint(20),
                date_created datetime NOT NULL DEFAULT '2026-01-01 00:00:00',
                value longtext,
                note_type varchar(50),
                sub_type varchar(50),
                PRIMARY KEY  (id),
                KEY entry_id (entry_id)
            )");
            $wpdb->query('CREATE TABLE IF NOT EXISTS ' . $wpdb->prefix . "gf_addon_payment_transaction (
                id int(10) unsigned NOT NULL AUTO_INCREMENT,
                lead_id int(10) unsigned NOT NULL,
                transaction_type varchar(30) NOT NULL DEFAULT 'payment',
                transaction_id varchar(50),
                amount decimal(19,2),
                PRIMARY KEY  (id),
                KEY lead_id (lead_id)
            )");
        }

        public static function uninstall_table(): void
        {
            global $wpdb;
            $wpdb->query('DROP TABLE IF EXISTS ' . self::table());
            $wpdb->query('DROP TABLE IF EXISTS ' . self::meta_table());
            foreach ([ 'gf_entry_meta', 'gf_entry_notes', 'gf_addon_payment_transaction' ] as $suffix) {
                $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . $suffix);
            }
        }

        /** Test seam: one row in a child table (gf_entry_meta, gf_entry_notes, gf_addon_payment_transaction). */
        public static function seed_row(string $suffix, array $row): int
        {
            global $wpdb;
            $wpdb->insert($wpdb->prefix . $suffix, $row);
            return (int) $wpdb->insert_id;
        }

        public static function meta_table(): string
        {
            global $wpdb;
            return $wpdb->prefix . 'gf_form_meta';
        }

        /** DDL for the form meta table (call from set_up_before_class). */
        public static function install_meta_table(): void
        {
            global $wpdb;
            $wpdb->query('CREATE TABLE IF NOT EXISTS ' . self::meta_table() . ' (
                form_id mediumint(10) unsigned NOT NULL,
                display_meta longtext,
                entries_grid_meta mediumtext,
                confirmations longtext,
                notifications longtext,
                PRIMARY KEY  (form_id)
            )');
        }

        /** Test seam: store a form's notifications and confirmations in gf_form_meta. */
        public static function seed_form_meta(int $form_id, array $notifications, array $confirmations): void
        {
            global $wpdb;
            $wpdb->replace(self::meta_table(), [
                'form_id'       => $form_id,
                'display_meta'  => wp_json_encode([ 'id' => $form_id ]),
                'notifications' => wp_json_encode($notifications),
                'confirmations' => wp_json_encode($confirmations),
            ]);
        }

        /** @return array<string, mixed>|null the decoded columns of a form's meta row */
        public static function meta_row(int $form_id): ?array
        {
            global $wpdb;
            if ((string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', self::meta_table())) !== self::meta_table()) {
                return null;
            }
            $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE form_id = %d', self::meta_table(), $form_id), ARRAY_A);
            if (! $row) {
                return null;
            }
            return [
                'notifications' => json_decode((string) $row['notifications'], true),
                'confirmations' => json_decode((string) $row['confirmations'], true),
            ];
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
            $form = self::$forms[(int) $form_id] ?? false;
            if (false !== $form && null !== ($meta = self::meta_row((int) $form_id))) {
                $form['notifications'] = is_array($meta['notifications']) ? $meta['notifications'] : [];
                $form['confirmations'] = is_array($meta['confirmations']) ? $meta['confirmations'] : [];
            }
            return $form;
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

if (! class_exists('GFFormsModel')) {
    class GFFormsModel
    {
        /** Calls to update_form_meta(), so a test can see the write went through it. */
        public static array $meta_updates = [];

        public static function get_meta_table_name(): string
        {
            return \GFAPI::meta_table();
        }

        /** GF 2.9: JSON-encodes $form_meta into one column of gf_form_meta and returns the query result. */
        public static function update_form_meta($form_id, $form_meta, $meta_name = 'display_meta')
        {
            global $wpdb;
            self::$meta_updates[] = [ (int) $form_id, (string) $meta_name ];
            return $wpdb->update(\GFAPI::meta_table(), [ $meta_name => json_encode($form_meta) ], [ 'form_id' => (int) $form_id ]);
        }
    }
}
