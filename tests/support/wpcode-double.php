<?php

// phpcs:ignoreFile -- a test stand-in for a third-party plugin, written in its style.

/**
 * A stand-in for WPCode (Insert Headers and Footers) 2.4, enough for
 * WPCode_Pack: the 'wpcode' post type with its capability mapping, the
 * wpcode_type, wpcode_location and wpcode_tags taxonomies, the
 * WPCode_Snippet save() path (post, terms and _wpcode_* meta, slashed input,
 * the activation test-run of PHP code, the compress-output quirk) and
 * wpcode() with its cache, auto-insert locations and error log.
 *
 * Behaviour is copied from WPCode 2.4.2 (includes/class-wpcode-snippet.php,
 * includes/post-type.php, includes/capabilities.php). WPCODE_VERSION is not
 * defined: tests switch presence on with wpmcp_wpcode_active.
 *
 * Test knobs: $GLOBALS['wpmcp_test_wpcode_executed'] counts activation
 * test-runs, a PHP snippet whose code contains "wpmcp_wpcode_fail" makes the
 * run report an error (as a fatal would), and
 * $GLOBALS['wpmcp_test_wpcode_cache_rebuilds'] counts cache rebuilds.
 */

if (! class_exists('WPCode_Snippet')) {
    class WPCode_Snippet
    {
        public $post_type          = 'wpcode';
        public $location_taxonomy  = 'wpcode_location';
        public $code_type_taxonomy = 'wpcode_type';
        public $tags_taxonomy      = 'wpcode_tags';
        public $id;
        public $post_data;
        public $title;
        public $code;
        public $code_type;
        public $location;
        public $auto_insert;
        public $insert_number;
        public $active;
        public $tags;
        private $priority;
        private $note;
        public $compress_output;

        public function __construct($snippet)
        {
            if (is_int($snippet)) {
                $this->load_from_id($snippet);
            } elseif ($snippet instanceof WP_Post) {
                $this->post_data = $snippet;
            } elseif (is_array($snippet)) {
                foreach (get_object_vars($this) as $key => $value) {
                    if (isset($snippet[ $key ])) {
                        $this->$key = $snippet[ $key ];
                    }
                }
            }
            if (isset($this->post_data) && $this->post_type !== $this->post_data->post_type) {
                unset($this->post_data, $this->id);
            }
        }

        public function load_from_id($snippet_id)
        {
            $this->post_data = get_post($snippet_id);
            if ($this->post_data) {
                $this->id = $this->post_data->ID;
            }
        }

        public function get_id()
        {
            if (! isset($this->id)) {
                $this->id = isset($this->post_data) ? $this->post_data->ID : 0;
            }
            return $this->id;
        }

        public function get_code_type()
        {
            if (! isset($this->code_type)) {
                $this->code_type = '';
                $terms           = isset($this->post_data) ? wp_get_post_terms($this->post_data->ID, $this->code_type_taxonomy) : [];
                if (! empty($terms) && ! is_wp_error($terms)) {
                    $this->code_type = $terms[0]->slug;
                }
            }
            return $this->code_type;
        }

        public function get_code()
        {
            if (! isset($this->code)) {
                $this->code = isset($this->post_data) ? $this->post_data->post_content : '';
            }
            return $this->code;
        }

        public function save()
        {
            $post_args = [ 'post_type' => $this->post_type ];
            if (isset($this->id) && 0 !== $this->id) {
                $post_args['ID'] = $this->id;
                $this->load_from_id($this->id);
            }
            if (is_user_logged_in() && ! empty($this->id) && 'publish' === get_post_status($this->id) && ! current_user_can('wpcode_activate_snippets', $this)) {
                wpcode()->error->add_error([ 'message' => 'You are not allowed to edit an active snippet.' ]);
                return false;
            }
            if (isset($this->title)) {
                $post_args['post_title'] = $this->title;
            }
            if (isset($this->code)) {
                $post_args['post_content'] = $this->code;
            }
            if (! current_user_can('wpcode_activate_snippets', $this)) {
                unset($this->active);
            }
            if (isset($this->active)) {
                $this->run_activation_checks();
                $post_args['post_status'] = $this->active ? 'publish' : 'draft';
            }
            do_action('wpcode_before_snippet_save', $this);
            if (isset($post_args['ID'])) {
                $result = wp_update_post($post_args);
            } else {
                if (empty($post_args['post_title'])) {
                    $post_args['post_title'] = 'Untitled Snippet';
                }
                $result = wp_insert_post($post_args);
            }
            if (0 === $result || is_wp_error($result)) {
                return false;
            }
            $this->id = $result;
            delete_post_meta($this->id, '_wpcode_last_error');
            if (isset($this->code_type)) {
                wp_set_post_terms($this->id, $this->code_type, $this->code_type_taxonomy);
            }
            if (isset($this->auto_insert)) {
                update_post_meta($this->id, '_wpcode_auto_insert', $this->auto_insert);
            }
            if (isset($this->location) && 1 === $this->auto_insert) {
                wp_set_post_terms($this->id, $this->location, $this->location_taxonomy);
            } elseif (isset($this->auto_insert)) {
                wp_set_post_terms($this->id, [], $this->location_taxonomy);
            }
            if (isset($this->tags)) {
                wp_set_post_terms($this->id, $this->tags, $this->tags_taxonomy);
            }
            if (isset($this->priority)) {
                update_post_meta($this->id, '_wpcode_priority', $this->priority);
            }
            if (isset($this->note)) {
                update_post_meta($this->id, '_wpcode_note', $this->note);
            }
            if (isset($this->compress_output)) {
                update_post_meta($this->id, '_wpcode_compress_output', true);
            } else {
                delete_post_meta($this->id, '_wpcode_compress_output');
            }
            do_action('wpcode_snippet_after_update', $this->id, $this);
            wpcode()->cache->cache_all_loaded_snippets();
            return $this->id;
        }

        public function run_activation_checks()
        {
            if (! in_array($this->get_code_type(), [ 'php', 'universal' ], true) || false === $this->active) {
                return;
            }
            wpcode()->error->clear_errors();
            $GLOBALS['wpmcp_test_wpcode_executed'] = (int) ($GLOBALS['wpmcp_test_wpcode_executed'] ?? 0) + 1;
            if (false !== strpos((string) $this->get_code(), 'wpmcp_wpcode_fail')) {
                wpcode()->error->add_error([ 'message' => 'Call to undefined function wpmcp_wpcode_fail()' ]);
            }
            if (wpcode()->error->has_error()) {
                $this->active = false;
            }
        }
    }
}

if (! class_exists('WPMCP_Test_WPCode')) {
    class WPMCP_Test_WPCode_Error
    {
        private $errors = [];

        public function add_error($error)
        {
            $this->errors[] = $error;
        }

        public function has_error()
        {
            return [] !== $this->errors;
        }

        public function clear_errors()
        {
            $this->errors = [];
        }

        public function get_last_error_message()
        {
            $last = end($this->errors);
            return is_array($last) ? (string) ($last['message'] ?? '') : '';
        }
    }

    class WPMCP_Test_WPCode_Cache
    {
        /** Caches the active snippets by location in the wpcode_snippets option, as WPCode does. */
        public function cache_all_loaded_snippets()
        {
            $GLOBALS['wpmcp_test_wpcode_cache_rebuilds'] = (int) ($GLOBALS['wpmcp_test_wpcode_cache_rebuilds'] ?? 0) + 1;
            $by_location = [];
            foreach (get_posts([ 'post_type' => 'wpcode', 'post_status' => 'publish', 'posts_per_page' => -1, 'orderby' => 'ID', 'order' => 'ASC' ]) as $post) {
                $terms = wp_get_object_terms($post->ID, 'wpcode_location', [ 'fields' => 'slugs' ]);
                $by_location[ $terms[0] ?? '' ][] = $post->ID;
            }
            update_option('wpcode_snippets', $by_location);
        }
    }

    class WPMCP_Test_WPCode_Auto_Insert_Type
    {
        private $locations;

        public function __construct(array $locations)
        {
            $this->locations = array_fill_keys($locations, [ 'label' => '' ]);
        }

        public function get_locations()
        {
            return $this->locations;
        }
    }

    class WPMCP_Test_WPCode_Auto_Insert
    {
        public function get_types()
        {
            return [
                'everywhere' => new WPMCP_Test_WPCode_Auto_Insert_Type([ 'everywhere', 'frontend_only', 'admin_only', 'frontend_cl', 'on_demand' ]),
                'site_wide'  => new WPMCP_Test_WPCode_Auto_Insert_Type([ 'site_wide_header', 'site_wide_body', 'site_wide_footer' ]),
                'single'     => new WPMCP_Test_WPCode_Auto_Insert_Type([ 'before_post', 'after_post', 'before_content', 'after_content' ]),
            ];
        }
    }

    class WPMCP_Test_WPCode
    {
        public $error;
        public $cache;
        public $auto_insert;

        public function __construct()
        {
            $this->error       = new WPMCP_Test_WPCode_Error();
            $this->cache       = new WPMCP_Test_WPCode_Cache();
            $this->auto_insert = new WPMCP_Test_WPCode_Auto_Insert();
        }
    }
}

if (! function_exists('wpcode')) {
    function wpcode()
    {
        static $instance = null;
        if (null === $instance) {
            $instance = new WPMCP_Test_WPCode();
        }
        return $instance;
    }
}

if (! function_exists('wpcode_current_user_can_edit_header_footer')) {
    function wpcode_current_user_can_edit_header_footer()
    {
        return current_user_can('unfiltered_html', 'wpcode-editor') && current_user_can('wpcode_activate_snippets');
    }
}

if (! function_exists('wpcode_clear_all_plugins_page_cache')) {
    function wpcode_clear_all_plugins_page_cache($context = '')
    {
        $GLOBALS['wpmcp_test_wpcode_page_cache_cleared'] = (int) ($GLOBALS['wpmcp_test_wpcode_page_cache_cleared'] ?? 0) + 1;
    }
}

/** Register WPCode's post type and taxonomies, and give administrators its two capabilities, as WPCode's install does. */
function wpmcp_test_wpcode_register(): void
{
    if (! post_type_exists('wpcode')) {
        register_post_type('wpcode', [
            'public'          => false,
            'show_ui'         => false,
            'capability_type' => [ 'wpcode_snippet', 'wpcode_snippets' ],
            'map_meta_cap'    => true,
            'capabilities'    => [
                'edit_posts'             => 'wpcode_edit_snippets',
                'edit_others_posts'      => 'wpcode_edit_snippets',
                'delete_posts'           => 'wpcode_edit_snippets',
                'publish_posts'          => 'wpcode_activate_snippets',
                'read_private_posts'     => 'wpcode_edit_snippets',
                'read'                   => 'read',
                'create_posts'           => 'wpcode_edit_snippets',
                'delete_private_posts'   => 'wpcode_edit_snippets',
                'delete_published_posts' => 'wpcode_edit_snippets',
                'delete_others_posts'    => 'wpcode_edit_snippets',
                'edit_private_posts'     => 'wpcode_edit_snippets',
                'edit_published_posts'   => 'wpcode_edit_snippets',
            ],
        ]);
    }
    foreach ([ 'wpcode_type', 'wpcode_location', 'wpcode_tags' ] as $taxonomy) {
        if (! taxonomy_exists($taxonomy)) {
            register_taxonomy($taxonomy, 'wpcode', [ 'public' => false ]);
        }
    }
    $admin = get_role('administrator');
    if ($admin) {
        $admin->add_cap('wpcode_edit_snippets');
        $admin->add_cap('wpcode_activate_snippets');
    }
    if (! has_action('wpcode_before_snippet_save', 'wpmcp_test_wpcode_remove_content_filters')) {
        add_action('wpcode_before_snippet_save', 'wpmcp_test_wpcode_remove_content_filters');
    }
}

/** WPCode's wpcode_maybe_remove_core_content_filters(): content_save_pre never rewrites a snippet. */
function wpmcp_test_wpcode_remove_content_filters(): void
{
    remove_all_filters('content_save_pre');
}
