<?php
/**
 * Faithful global test doubles for the Formidable, Contact Form 7 (plus
 * Flamingo), and WPForms integrations. These plugins cannot all be installed
 * from wordpress.org in the harness (paid tiers, entry storage, heavy
 * bootstraps), so these reproduce the exact public API surface each
 * integration calls, verified against Formidable 6.x, Contact Form 7 6.x,
 * Flamingo 2.x, and WPForms 1.9. Contact Form 7 and Flamingo are additionally
 * exercised against the real plugins in CI's live forms job
 * (tests/free/Integrations/ContactForm7LiveTest.php); the paid plugins remain
 * production-verified. Real classes always win.
 */

// ---- Formidable: FrmForm / FrmEntry ----------------------------------------
if (! class_exists('FrmForm')) {
    class FrmForm
    {
        /** @var array<int,object> */
        public static array $forms = [];

        public static function getAll($where = [], $order_by = '', $limit = '')
        {
            return array_values(self::$forms);
        }

        public static function getOne($id)
        {
            return self::$forms[(int) $id] ?? false;
        }
    }
}
if (! class_exists('FrmEntry')) {
    class FrmEntry
    {
        /** @var array<int,object> */
        public static array $entries = [];

        /**
         * Formidable 6.x signature. Honours the it.form_id WHERE, an
         * " ORDER BY it.created_at DESC" order clause, and a " LIMIT o,n" /
         * " LIMIT n" limit, the three shapes the adapter passes.
         */
        public static function getAll($where = [], $order_by = '', $limit = '', $meta = false, $inc_form = true)
        {
            $form_id = (int) ($where['it.form_id'] ?? 0);
            $rows    = array_values(array_filter(self::$entries, static fn ($e) => (int) ($e->form_id ?? 0) === $form_id));
            if (false !== stripos((string) $order_by, 'created_at DESC')) {
                usort($rows, static fn ($a, $b) => [ $b->created_at ?? '', $b->id ] <=> [ $a->created_at ?? '', $a->id ]);
            }
            if (preg_match('/LIMIT\s+(\d+)\s*(?:,\s*(\d+))?/i', (string) $limit, $m)) {
                $rows = isset($m[2]) ? array_slice($rows, (int) $m[1], (int) $m[2]) : array_slice($rows, 0, (int) $m[1]);
            }
            return $rows;
        }

        public static function getOne($id, $meta = false)
        {
            return self::$entries[(int) $id] ?? false;
        }

        /** Formidable counts frm_items by form when handed a numeric form id. */
        public static function getRecordCount($where = '')
        {
            $form_id = is_numeric($where) ? (int) $where : (int) ($where['it.form_id'] ?? ($where['form_id'] ?? 0));
            return count(array_filter(self::$entries, static fn ($e) => (int) ($e->form_id ?? 0) === $form_id));
        }
    }
}
if (! class_exists('FrmField')) {
    class FrmField
    {
        /** @var array<int,array<int,object>> form id => field rows */
        public static array $fields = [];

        public static function get_all_for_form($form_id, $limit = '', $inc_embed = 'exclude', $inc_repeat = 'include')
        {
            return self::$fields[(int) $form_id] ?? [];
        }
    }
}
if (! class_exists('FrmFormAction')) {
    class FrmFormAction
    {
        /**
         * form id => prepared action posts. Real prepare_action() decodes
         * post_content into the settings array, which is what is stored here.
         *
         * @var array<int,array<int,object>>
         */
        public static array $actions = [];

        public static function get_action_for_form($form_id, $type = 'all', $atts = [])
        {
            $all = self::$actions[(int) $form_id] ?? [];
            if ('all' === $type) {
                return $all;
            }
            return array_filter($all, static fn ($a) => ($a->post_excerpt ?? '') === $type);
        }
    }
}

// ---- Contact Form 7: WPCF7_ContactForm -------------------------------------
if (! class_exists('WPCF7_ContactForm')) {
    class WPCF7_ContactForm
    {
        /** @var array<int,\WPCF7_ContactForm> */
        public static array $registry = [];

        public int $_id = 0;
        public string $_title = '';
        public string $_name = '';
        public array $_props = [];

        public static function seed(int $id, string $title, string $name, array $props): void
        {
            $f = new self();
            $f->_id = $id;
            $f->_title = $title;
            $f->_name = $name;
            $f->_props = $props;
            self::$registry[$id] = $f;
        }

        public static function find($args = [])
        {
            return array_values(self::$registry);
        }

        public static function get_instance($id)
        {
            return self::$registry[(int) $id] ?? null;
        }

        public function id()
        {
            return $this->_id;
        }

        public function title()
        {
            return $this->_title;
        }

        public function name()
        {
            return $this->_name;
        }

        public function prop($name)
        {
            return $this->_props[$name] ?? '';
        }

        /**
         * CF7 parses its form markup into WPCF7_FormTag objects. This double
         * parses the same [type* name "value" ...] syntax into objects with
         * the public properties the adapter reads (type, basetype, name,
         * values); the real tag's pipes, options and attr are not modelled.
         */
        public function scan_form_tags($cond = null)
        {
            preg_match_all('/\[([a-zA-Z0-9_]+\*?)(?:\s+([a-zA-Z0-9_:.-]+))?((?:\s+"[^"]*")*)[^\]]*\]/', (string) $this->prop('form'), $m, PREG_SET_ORDER);
            $tags = [];
            foreach ($m as $match) {
                preg_match_all('/"([^"]*)"/', $match[3] ?? '', $values);
                $tags[] = (object) [
                    'type'     => $match[1],
                    'basetype' => rtrim($match[1], '*'),
                    'name'     => $match[2] ?? '',
                    'values'   => $values[1],
                ];
            }
            return $tags;
        }
    }
}

// ---- WPForms: wpforms()->form->get() ---------------------------------------
if (! function_exists('wpforms')) {
    class WPMCP_WPForms_Form_Stub
    {
        /** @var array<int,\WP_Post> */
        public array $forms = [];

        public function get($id = '', $args = [])
        {
            if ('' === $id || null === $id) {
                return array_values($this->forms);
            }
            return $this->forms[(int) $id] ?? null;
        }
    }

    /**
     * Double of WPForms Pro's entry handler (WPForms_Entry_Handler, a
     * WPForms_DB over the wpforms_entries table, primary key entry_id),
     * backed by a REAL table so the db_rows snapshot and rollback path runs
     * for real. Reproduced: get($id, ['cap' => false]) returning a row object
     * or null, get_entries($args, $count) honouring form_id, status, number,
     * offset and orderby entry_id / order, and update($id, $data, '', '',
     * $args) returning bool. Not reproduced: the capability checks the real
     * handler runs when 'cap' is not false, and every filter argument the
     * adapter does not pass.
     */
    class WPMCP_WPForms_Entry_Stub
    {
        public const TABLE = 'wpforms_entries';

        public static function table(): string
        {
            global $wpdb;
            return $wpdb->prefix . self::TABLE;
        }

        /** DDL: call from set_up_before_class, never inside a test transaction. */
        public static function install(): void
        {
            global $wpdb;
            $wpdb->query('CREATE TABLE IF NOT EXISTS ' . self::table() . " (
                entry_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                form_id bigint(20) unsigned NOT NULL DEFAULT 0,
                status varchar(30) NOT NULL DEFAULT '',
                starred tinyint(1) NOT NULL DEFAULT 0,
                viewed tinyint(1) NOT NULL DEFAULT 0,
                fields longtext NOT NULL,
                ip_address varchar(128) NOT NULL DEFAULT '',
                user_agent varchar(256) NOT NULL DEFAULT '',
                date datetime NOT NULL DEFAULT '2026-01-01 00:00:00',
                PRIMARY KEY  (entry_id)
            )");
        }

        public static function uninstall(): void
        {
            global $wpdb;
            $wpdb->query('DROP TABLE IF EXISTS ' . self::table());
        }

        public static function seed(array $row): int
        {
            global $wpdb;
            $row += [ 'fields' => '[]', 'date' => '2026-01-01 00:00:00' ];
            if (is_array($row['fields'])) {
                $row['fields'] = wp_json_encode($row['fields']);
            }
            $wpdb->insert(self::table(), $row);
            return (int) $wpdb->insert_id;
        }

        public function get($row_id, $args = [])
        {
            global $wpdb;
            $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE entry_id = %d', self::table(), (int) $row_id));
            return $row ?: null;
        }

        public function get_entries($args = [], $count = false)
        {
            global $wpdb;
            $args  = wp_parse_args($args, [ 'number' => 30, 'offset' => 0, 'form_id' => 0, 'status' => '', 'orderby' => 'entry_id', 'order' => 'DESC' ]);
            $where = $wpdb->prepare('form_id = %d', (int) $args['form_id']);
            if (array_key_exists('status', $args) && '' !== $args['status']) {
                $where .= $wpdb->prepare(' AND status = %s', (string) $args['status']);
            }
            if ($count) {
                return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE ', self::table()) . $where);
            }
            $order = 'ASC' === strtoupper((string) $args['order']) ? 'ASC' : 'DESC';
            return $wpdb->get_results(
                $wpdb->prepare('SELECT * FROM %i WHERE ', self::table()) . $where
                . " ORDER BY entry_id {$order}"
                . $wpdb->prepare(' LIMIT %d, %d', (int) $args['offset'], (int) $args['number'])
            );
        }

        public function update($row_id, $data = [], $where = '', $type = '', $args = [])
        {
            global $wpdb;
            return false !== $wpdb->update(self::table(), $data, [ 'entry_id' => (int) $row_id ]);
        }
    }

    class WPMCP_WPForms_Stub
    {
        /** Toggle to model WPForms Lite, which registers no entry handler. */
        public static bool $lite = false;

        public WPMCP_WPForms_Form_Stub $form;
        private WPMCP_WPForms_Entry_Stub $entry;

        public function __construct()
        {
            $this->form  = new WPMCP_WPForms_Form_Stub();
            $this->entry = new WPMCP_WPForms_Entry_Stub();
        }

        /** WPForms 1.7+: the class registry accessor. */
        public function obj(string $name): ?object
        {
            if ('form' === $name) {
                return $this->form;
            }
            return 'entry' === $name && ! self::$lite ? $this->entry : null;
        }
    }
    $GLOBALS['wpmcp_wpforms_stub'] = new WPMCP_WPForms_Stub();
    function wpforms()
    {
        return $GLOBALS['wpmcp_wpforms_stub'];
    }
    function wpforms_decode($json)
    {
        return json_decode((string) $json, true);
    }
}

// ---- Flamingo: Flamingo_Inbound_Message -------------------------------------
// Double of Flamingo 2.x's inbound-message model, matched to the real plugin on
// every surface the CF7 adapter touches, and honest about the rest.
//
// Matched: $found_items is PRIVATE static and there is NO get_instance(), so an
// integration reaching for either fatals in the suite exactly as in production;
// $id is private with a __get() shim, so only id() is a supported read; find()
// defaults to orderby => ID / order => ASC and post_status => 'any'; count()
// defaults post_status to 'publish' and, like the real one, does NOT reset
// posts_per_page or drop offset, so the out-of-range-page found_posts trap is
// reproduced rather than papered over; the constructor does not validate the
// post type. Storage is real flamingo_inbound posts queried through WP_Query,
// so paging, offset handling, channel scoping, post_status defaults, and the
// snapshot/rollback path are genuinely exercised.
//
// NOT reproduced (the adapter reads none of it): the real save()/__construct()
// also split field values into per-key `_field_{key}` postmeta with `_fields`
// holding nulls; the real find() additionally accepts `s`, `hash`, and a
// caller-supplied tax_query and appends channel_id ALONGSIDE channel rather
// than treating them as alternatives; and $spam is also true when the akismet
// meta says so, which seed() does model but only via that meta key.
if (! class_exists('Flamingo_Inbound_Message')) {
    class Flamingo_Inbound_Message
    {
        const post_type = 'flamingo_inbound';
        const spam_status = 'flamingo-spam';
        const channel_taxonomy = 'flamingo_inbound_channel';

        private static $found_items = 0;

        /** Private in the real plugin; readable only through id() or __get(). */
        private $id;

        public $post_status;
        public $channel;
        public $channel_id;
        public $subject = '';
        public $from = '';
        public $from_name = '';
        public $from_email = '';
        public $fields = [];
        public $meta = [];
        public $spam = false;

        /** Mirrors Flamingo's own registration so WP_Query can see the rows. */
        public static function register(): void
        {
            register_post_type(self::post_type, [
                'public'   => false,
                'label'    => 'Inbound Messages',
                'supports' => [ 'title', 'editor' ],
            ]);
            register_taxonomy(self::channel_taxonomy, self::post_type, [
                'public'       => false,
                'hierarchical' => true,
            ]);
            register_post_status(self::spam_status, [ 'internal' => true ]);
        }

        public static function unregister(): void
        {
            unregister_taxonomy(self::channel_taxonomy);
            unregister_post_type(self::post_type);
        }

        /**
         * Test seam only: create one inbound message the way Flamingo's add()
         * does, as a post plus its underscore-prefixed meta.
         */
        public static function seed(array $args = []): int
        {
            $id = wp_insert_post([
                'post_type'   => self::post_type,
                'post_title'  => (string) ($args['subject'] ?? 'Message'),
                'post_status' => (string) ($args['post_status'] ?? 'publish'),
                'post_date'   => (string) ($args['post_date'] ?? current_time('mysql')),
            ]);
            update_post_meta($id, '_subject', (string) ($args['subject'] ?? ''));
            update_post_meta($id, '_from', (string) ($args['from'] ?? ''));
            update_post_meta($id, '_from_name', (string) ($args['from_name'] ?? ''));
            update_post_meta($id, '_from_email', (string) ($args['from_email'] ?? ''));
            update_post_meta($id, '_fields', (array) ($args['fields'] ?? []));
            update_post_meta($id, '_meta', (array) ($args['meta'] ?? []));
            if (isset($args['akismet'])) {
                update_post_meta($id, '_akismet', (array) $args['akismet']);
            }
            if (! empty($args['channel'])) {
                wp_set_object_terms($id, [ (int) $args['channel'] ], self::channel_taxonomy);
            }
            return (int) $id;
        }

        public static function find($args = '')
        {
            $defaults = [
                'posts_per_page' => 10,
                'offset'         => 0,
                'orderby'        => 'ID',
                'order'          => 'ASC',
                'post_status'    => 'any',
                'channel'        => '',
                'channel_id'     => 0,
                's'              => '',
            ];
            $args = wp_parse_args($args, $defaults);

            $query = $args;
            $query['post_type'] = self::post_type;
            unset($query['channel'], $query['channel_id']);

            if (! empty($args['channel'])) {
                $query['tax_query'] = [ [
                    'taxonomy' => self::channel_taxonomy,
                    'terms'    => $args['channel'],
                    'field'    => 'slug',
                ] ];
            } elseif (! empty($args['channel_id'])) {
                $query['tax_query'] = [ [
                    'taxonomy' => self::channel_taxonomy,
                    'terms'    => (int) $args['channel_id'],
                    'field'    => 'term_id',
                ] ];
            }

            $q     = new \WP_Query();
            $posts = $q->query($query);
            self::$found_items = (int) $q->found_posts;

            $out = [];
            foreach ((array) $posts as $post) {
                $out[] = new self($post);
            }
            return $out;
        }

        public static function count($args = '')
        {
            // Flamingo's count() re-runs find() and, unlike find(), defaults
            // post_status to 'publish' rather than 'any'. Note what it does
            // NOT do, faithfully reproduced here: it does not reset
            // posts_per_page and it does not strip offset, so a caller that
            // passes its page window straight through gets found_posts from a
            // query that WP_Query::set_found_posts() abandons on an empty
            // result set, i.e. total 0 for any page past the last one.
            $args = wp_parse_args($args, [ 'post_status' => 'publish' ]);
            self::find($args);
            return absint(self::$found_items);
        }

        /** Note: like Flamingo, this does NOT validate the post type. */
        public function __construct($post = null)
        {
            $post = empty($post) ? null : get_post($post);
            if (! $post) {
                return;
            }
            $this->id = $post->ID;
            $this->post_status = $post->post_status;
            $this->subject = (string) get_post_meta($post->ID, '_subject', true);
            $this->from = (string) get_post_meta($post->ID, '_from', true);
            $this->from_name = (string) get_post_meta($post->ID, '_from_name', true);
            $this->from_email = (string) get_post_meta($post->ID, '_from_email', true);
            $this->fields = (array) get_post_meta($post->ID, '_fields', true);
            $this->meta = (array) get_post_meta($post->ID, '_meta', true);
            $akismet    = get_post_meta($post->ID, '_akismet', true);
            $this->spam = self::spam_status === $post->post_status
                || (is_array($akismet) && ! empty($akismet['spam']));

            $terms = wp_get_object_terms($post->ID, self::channel_taxonomy);
            if (! is_wp_error($terms) && ! empty($terms)) {
                $this->channel = $terms[0]->slug;
                $this->channel_id = (int) $terms[0]->term_id;
            }
        }

        public function id()
        {
            return $this->id;
        }

        /** Flamingo's own shim for the private $id. */
        public function __get($name)
        {
            return 'id' === $name ? $this->id : null;
        }

        /** Flamingo 2.x: trash, or delete outright when the trash is off. */
        public function trash()
        {
            if (empty($this->id)) {
                return;
            }
            if (! EMPTY_TRASH_DAYS) {
                return (bool) wp_delete_post($this->id, true);
            }
            return (bool) wp_trash_post($this->id);
        }

        /**
         * Flamingo 2.x: untrash, with Flamingo's wp_untrash_post_status filter
         * (registered at init in the real plugin) putting the message back in
         * the status it was trashed from rather than core's default draft.
         */
        public function untrash()
        {
            if (empty($this->id)) {
                return;
            }
            $restore = static fn ($new, $post_id, $previous) => self::post_type === get_post_type($post_id) ? $previous : $new;
            add_filter('wp_untrash_post_status', $restore, 10, 3);
            try {
                return (bool) wp_untrash_post($this->id);
            } finally {
                remove_filter('wp_untrash_post_status', $restore, 10);
            }
        }
    }
}
