<?php
/**
 * Faithful global test doubles for the Formidable, Contact Form 7, and
 * WPForms read integrations. These plugins cannot all be installed from
 * wordpress.org in the harness (paid tiers, entry storage, heavy bootstraps),
 * so these reproduce the exact public API surface each integration calls,
 * verified against Formidable 6.x, Contact Form 7 5.x, and WPForms 1.8.x.
 * Live plugins remain production-verified. Real classes always win.
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

        public static function getAll($where = [], $order_by = '', $limit = '', $meta = false)
        {
            $form_id = (int) ($where['it.form_id'] ?? 0);
            return array_values(array_filter(self::$entries, static fn ($e) => (int) ($e->form_id ?? 0) === $form_id));
        }

        public static function getOne($id, $meta = false)
        {
            return self::$entries[(int) $id] ?? false;
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
    class WPMCP_WPForms_Stub
    {
        public WPMCP_WPForms_Form_Stub $form;
        public function __construct()
        {
            $this->form = new WPMCP_WPForms_Form_Stub();
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
    }
}
