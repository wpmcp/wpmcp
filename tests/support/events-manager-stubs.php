<?php
/**
 * Events Manager tables, post type and an EM_Event double for the Events
 * Manager ops (issue #678) on the plugin-data pair, for tests that run
 * without Events Manager loaded.
 *
 * Taken from Events Manager 7.5's own source:
 *  - em-install.php holds the DDL of {prefix}em_events, em_bookings,
 *    em_bookings_meta and em_locations (only the columns the ops read and
 *    the double writes are kept, with Events Manager's own types);
 *  - em-posts.php registers the `event` post type;
 *  - classes/em-event.php: an event loaded by post id reads its schedule
 *    from `_event_*` post meta, validate() refuses a missing name, a bad
 *    date and an end before the start, and save() writes the post with
 *    force_status (publish when none), then save_meta() mirrors every field
 *    into `_<field>` post meta and the em_events row, linking them through
 *    `_event_id`. The double does exactly that much.
 *
 * DDL commits implicitly, so the tables are created in wpSetUpBeforeClass()
 * and dropped in wpTearDownAfterClass().
 */

if ( ! function_exists( 'wpmcp_test_create_events_manager_tables' ) ) {
	function wpmcp_test_create_events_manager_tables(): void {
		global $wpdb;

		wpmcp_test_drop_events_manager_tables();
		$charset = $wpdb->get_charset_collate();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL built from literals.
		$wpdb->query( "CREATE TABLE {$wpdb->prefix}em_events (
			event_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NULL DEFAULT NULL,
			event_type VARCHAR(20) DEFAULT 'single',
			event_slug VARCHAR(200) NULL DEFAULT NULL,
			event_owner bigint(20) unsigned DEFAULT NULL,
			event_status tinyint(1) NULL DEFAULT NULL,
			event_name text NULL DEFAULT NULL,
			event_start_date date NULL DEFAULT NULL,
			event_end_date date NULL DEFAULT NULL,
			event_start_time time NULL DEFAULT NULL,
			event_end_time time NULL DEFAULT NULL,
			event_all_day tinyint(1) unsigned NULL DEFAULT NULL,
			event_start datetime NULL DEFAULT NULL,
			event_end datetime NULL DEFAULT NULL,
			event_timezone tinytext NULL DEFAULT NULL,
			post_content longtext NULL DEFAULT NULL,
			event_rsvp tinyint(1) unsigned NOT NULL DEFAULT 0,
			event_spaces int(5) NULL DEFAULT 0,
			location_id bigint(20) unsigned NULL DEFAULT NULL,
			event_date_modified datetime NULL DEFAULT NULL,
			PRIMARY KEY  (event_id)
		) $charset" );
		$wpdb->query( "CREATE TABLE {$wpdb->prefix}em_bookings (
			booking_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			booking_uuid char(32) NOT NULL,
			event_id bigint(20) unsigned NULL,
			person_id bigint(20) unsigned NOT NULL,
			booking_spaces int(5) NOT NULL,
			booking_comment text DEFAULT NULL,
			booking_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
			booking_status int(2) NOT NULL DEFAULT 1,
			booking_price decimal(14,4) unsigned NOT NULL DEFAULT 0,
			booking_meta LONGTEXT NULL,
			PRIMARY KEY  (booking_id)
		) $charset" );
		$wpdb->query( "CREATE TABLE {$wpdb->prefix}em_bookings_meta (
			meta_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			booking_id bigint(20) unsigned NOT NULL,
			meta_key varchar(255) DEFAULT NULL,
			meta_value longtext,
			PRIMARY KEY  (meta_id)
		) $charset" );
		$wpdb->query( "CREATE TABLE {$wpdb->prefix}em_locations (
			location_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL,
			location_name text NULL DEFAULT NULL,
			location_address VARCHAR(200) NULL DEFAULT NULL,
			location_town VARCHAR(200) NULL DEFAULT NULL,
			location_postcode VARCHAR(10) NULL DEFAULT NULL,
			location_country char(2) NULL DEFAULT NULL,
			PRIMARY KEY  (location_id)
		) $charset" );
		// phpcs:enable
	}

	function wpmcp_test_drop_events_manager_tables(): void {
		global $wpdb;
		foreach ( [ 'em_events', 'em_bookings', 'em_bookings_meta', 'em_locations' ] as $name ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $name ) );
		}
	}

	function wpmcp_test_register_events_manager_types(): void {
		register_post_type( 'event', [ 'public' => true, 'label' => 'Events', 'supports' => [ 'title', 'editor', 'excerpt', 'author' ] ] );
	}
}

if ( ! class_exists( 'EM_Event' ) ) {
	// phpcs:disable -- a double of a third-party class, named and shaped as Events Manager has it.
	class EM_Event {
		/** The fields save_meta() mirrors into `_<field>` post meta and the em_events row. */
		const FIELDS = [ 'event_start_date', 'event_end_date', 'event_start_time', 'event_end_time', 'event_all_day', 'event_start', 'event_end', 'event_timezone', 'event_rsvp', 'event_spaces', 'location_id', 'event_type' ];

		public $event_id;
		public $post_id;
		public $event_name = '';
		public $post_content = '';
		public $post_excerpt = '';
		public $post_status;
		public $force_status;
		public $event_start_date;
		public $event_end_date;
		public $event_start_time;
		public $event_end_time;
		public $event_all_day = 0;
		public $event_start;
		public $event_end;
		public $event_timezone;
		public $event_rsvp = 0;
		public $event_spaces = 0;
		public $location_id;
		public $event_type = 'single';
		public $errors = [];

		public function __construct( $id = false, $search_by = 'event_id' ) {
			if ( 'post_id' === $search_by && $id && ( $post = get_post( (int) $id ) ) ) {
				$this->post_id      = (int) $post->ID;
				$this->event_name   = $post->post_title;
				$this->post_content = $post->post_content;
				$this->post_excerpt = $post->post_excerpt;
				$this->post_status  = $post->post_status;
				$this->event_id     = (int) get_post_meta( $post->ID, '_event_id', true );
				foreach ( self::FIELDS as $field ) {
					$value = get_post_meta( $post->ID, '_' . $field, true );
					if ( '' !== $value ) {
						$this->$field = $value;
					}
				}
			}
			if ( empty( $this->event_timezone ) ) {
				$this->event_timezone = 'UTC';
			}
		}

		public function set_timezone( $timezone = false, $preserve_times = true ) {
			$this->event_start = $this->event_end = null;
			$this->event_timezone = $timezone;
		}

		public function add_error( $error ) {
			$this->errors[] = $error;
		}

		public function validate() {
			if ( empty( $this->event_name ) ) {
				$this->add_error( 'Event name is required.' );
			}
			if ( ! preg_match( '/\d{4}-\d{2}-\d{2}/', (string) $this->event_start_date ) || ! preg_match( '/\d{4}-\d{2}-\d{2}/', (string) $this->event_end_date ) ) {
				$this->add_error( 'Dates must have correct formatting. Please use the date picker provided.' );
			} elseif ( $this->event_start_date . ' ' . $this->event_start_time > $this->event_end_date . ' ' . $this->event_end_time ) {
				$this->add_error( 'Events cannot start after they end.' );
			}
			return count( $this->errors ) === 0;
		}

		public function save() {
			$post = [
				'post_type'    => 'event',
				'post_title'   => $this->event_name,
				'post_content' => (string) $this->post_content,
				'post_status'  => $this->force_status ? $this->force_status : 'publish',
			];
			if ( $this->post_id ) {
				$post = array_merge( get_post( $this->post_id, ARRAY_A ), $post );
			}
			$post_id = wp_insert_post( wp_slash( $post ), true );
			if ( is_wp_error( $post_id ) ) {
				$this->add_error( $post_id->get_error_message() );
				return false;
			}
			$this->post_id     = (int) $post_id;
			$this->post_status = get_post_status( $post_id );
			return $this->save_meta();
		}

		public function save_meta() {
			global $wpdb;

			$this->event_start = $this->event_start_date . ' ' . $this->event_start_time;
			$this->event_end   = $this->event_end_date . ' ' . $this->event_end_time;
			foreach ( self::FIELDS as $field ) {
				update_post_meta( $this->post_id, '_' . $field, $this->$field );
			}
			$row = [
				'post_id'             => $this->post_id,
				'event_name'          => $this->event_name,
				'event_status'        => 'publish' === $this->post_status ? 1 : 0,
				'post_content'        => $this->post_content,
				'event_date_modified' => current_time( 'mysql' ),
			];
			foreach ( self::FIELDS as $field ) {
				$row[ $field ] = $this->$field;
			}
			if ( $this->event_id ) {
				$wpdb->update( $wpdb->prefix . 'em_events', $row, [ 'event_id' => $this->event_id ] );
			} else {
				$wpdb->insert( $wpdb->prefix . 'em_events', $row );
				$this->event_id = (int) $wpdb->insert_id;
			}
			update_post_meta( $this->post_id, '_event_id', $this->event_id );
			return true;
		}
	}
	// phpcs:enable
}
