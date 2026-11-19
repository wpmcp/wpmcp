<?php
/**
 * Fixtures for the appointment booking ops (issue #679), for tests that run
 * without Bookly, Simply Schedule Appointments, BookingPress or LatePoint in
 * the shared test core.
 *
 * The tables the ops read and write, copied from each plugin's own CREATE
 * TABLE statements, with the columns the ops do not touch and every plain
 * index and foreign key left out:
 *  - Bookly 28.8 (lib/Installer.php): bookly_staff, bookly_services,
 *    bookly_customers, bookly_appointments, bookly_customer_appointments;
 *  - Simply Schedule Appointments 1.6.13 (SSA_Appointment_Model and
 *    SSA_Appointment_Type_Model schemas): ssa_appointments,
 *    ssa_appointment_types;
 *  - BookingPress 1.1.28 (BookingPress::install()): bookingpress_services,
 *    bookingpress_appointment_bookings;
 *  - LatePoint 5.7.9 (OsDatabaseHelper): latepoint_services,
 *    latepoint_agents, latepoint_customers, latepoint_bookings.
 *
 * DDL commits implicitly, so callers create them in wpSetUpBeforeClass()
 * and drop them in wpTearDownAfterClass(); rows written inside a test roll
 * back with it.
 */

if ( ! function_exists( 'wpmcp_test_create_booking_tables' ) ) {
	/** @return string[] the unprefixed table names */
	function wpmcp_test_booking_tables(): array {
		return [
			'bookly_staff', 'bookly_services', 'bookly_customers', 'bookly_appointments', 'bookly_customer_appointments',
			'ssa_appointments', 'ssa_appointment_types',
			'bookingpress_services', 'bookingpress_appointment_bookings',
			'latepoint_services', 'latepoint_agents', 'latepoint_customers', 'latepoint_bookings',
		];
	}

	function wpmcp_test_create_booking_tables(): void {
		global $wpdb;
		$p = $wpdb->prefix;
		$c = $wpdb->get_charset_collate();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}bookly_staff (
			`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, `category_id` INT UNSIGNED DEFAULT NULL,
			`wp_user_id` BIGINT(20) UNSIGNED DEFAULT NULL, `full_name` VARCHAR(255) DEFAULT NULL,
			`email` VARCHAR(255) DEFAULT NULL, `phone` VARCHAR(32) DEFAULT NULL, `time_zone` VARCHAR(255) DEFAULT NULL,
			`visibility` ENUM('public','private','archive') NOT NULL DEFAULT 'public', `position` INT NOT NULL DEFAULT 9999
		) $c" );
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}bookly_services (
			`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, `category_id` INT UNSIGNED DEFAULT NULL,
			`type` ENUM('simple','collaborative','compound','package') NOT NULL DEFAULT 'simple',
			`title` VARCHAR(255) DEFAULT '', `duration` INT NOT NULL DEFAULT 900, `price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
			`capacity_min` INT NOT NULL DEFAULT 1, `capacity_max` INT NOT NULL DEFAULT 1,
			`visibility` ENUM('public','private','group') NOT NULL DEFAULT 'public', `position` INT NOT NULL DEFAULT 9999
		) $c" );
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}bookly_customers (
			`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, `wp_user_id` BIGINT(20) UNSIGNED DEFAULT NULL,
			`full_name` VARCHAR(128) NOT NULL DEFAULT '', `first_name` VARCHAR(64) NOT NULL DEFAULT '',
			`last_name` VARCHAR(64) NOT NULL DEFAULT '', `phone` VARCHAR(32) NOT NULL DEFAULT '',
			`email` VARCHAR(128) NOT NULL DEFAULT '', `notes` TEXT NOT NULL, `created_at` DATETIME NOT NULL
		) $c" );
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}bookly_appointments (
			`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, `location_id` INT UNSIGNED DEFAULT NULL,
			`staff_id` INT UNSIGNED NOT NULL, `staff_any` TINYINT(1) NOT NULL DEFAULT 0,
			`service_id` INT UNSIGNED DEFAULT NULL, `custom_service_name` VARCHAR(255) DEFAULT NULL,
			`custom_service_price` DECIMAL(10,2) DEFAULT NULL, `start_date` DATETIME DEFAULT NULL,
			`end_date` DATETIME DEFAULT NULL, `extras_duration` INT NOT NULL DEFAULT 0, `internal_note` TEXT DEFAULT NULL,
			`created_from` ENUM('bookly','google','outlook','apple') NOT NULL DEFAULT 'bookly',
			`created_at` DATETIME NOT NULL, `updated_at` DATETIME NOT NULL
		) $c" );
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}bookly_customer_appointments (
			`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, `series_id` INT UNSIGNED DEFAULT NULL,
			`customer_id` INT UNSIGNED NOT NULL, `appointment_id` INT UNSIGNED NOT NULL,
			`number_of_persons` INT UNSIGNED NOT NULL DEFAULT 1, `units` INT UNSIGNED NOT NULL DEFAULT 1,
			`notes` TEXT DEFAULT NULL, `status` VARCHAR(255) NOT NULL DEFAULT 'approved',
			`status_changed_at` DATETIME NULL, `token` VARCHAR(255) DEFAULT NULL, `time_zone` VARCHAR(255) DEFAULT NULL,
			`created_from` ENUM('frontend','backend') NOT NULL DEFAULT 'frontend',
			`created_at` DATETIME NOT NULL, `updated_at` DATETIME NOT NULL
		) $c" );
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}ssa_appointment_types (
			`id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, `author_id` BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			`title` TEXT NOT NULL, `slug` TINYTEXT NOT NULL, `duration` INT(11) NOT NULL DEFAULT 0,
			`capacity` INT(11) NOT NULL DEFAULT 1, `status` TINYTEXT NOT NULL, `display_order` INT(11) NOT NULL DEFAULT 0,
			`date_created` DATETIME NULL, `date_modified` DATETIME NULL
		) $c" );
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}ssa_appointments (
			`id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
			`appointment_type_id` BIGINT(20) UNSIGNED NOT NULL DEFAULT 0, `group_id` BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			`author_id` BIGINT(20) UNSIGNED NOT NULL DEFAULT 0, `customer_id` BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			`customer_information` TEXT NOT NULL, `customer_timezone` TINYTEXT NOT NULL,
			`start_date` DATETIME NULL, `end_date` DATETIME NULL, `title` TEXT NOT NULL, `description` TEXT NOT NULL,
			`status` TINYTEXT NOT NULL, `date_created` DATETIME NULL, `date_modified` DATETIME NULL
		) $c" );
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}bookingpress_services (
			`bookingpress_service_id` INT(11) NOT NULL AUTO_INCREMENT, `bookingpress_category_id` SMALLINT DEFAULT 0,
			`bookingpress_service_name` VARCHAR(255) NOT NULL, `bookingpress_service_price` DOUBLE NOT NULL,
			`bookingpress_service_duration_val` INT(11) NOT NULL, `bookingpress_service_duration_unit` VARCHAR(1) NOT NULL,
			`bookingpress_service_description` TEXT NOT NULL, `bookingpress_service_position` INT(11) NOT NULL,
			`bookingpress_servicedate_created` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (`bookingpress_service_id`)
		) $c" );
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}bookingpress_appointment_bookings (
			`bookingpress_appointment_booking_id` BIGINT(11) NOT NULL AUTO_INCREMENT,
			`bookingpress_booking_id` VARCHAR(255) DEFAULT NULL, `bookingpress_customer_id` BIGINT(11) NOT NULL,
			`bookingpress_customer_name` VARCHAR(255) DEFAULT NULL, `bookingpress_customer_phone` VARCHAR(255) DEFAULT NULL,
			`bookingpress_customer_firstname` VARCHAR(255) DEFAULT NULL, `bookingpress_customer_lastname` VARCHAR(255) DEFAULT NULL,
			`bookingpress_customer_email` VARCHAR(255) DEFAULT NULL, `bookingpress_staff_member_id` SMALLINT DEFAULT NULL,
			`bookingpress_service_id` INT(11) NOT NULL, `bookingpress_service_name` VARCHAR(255) NOT NULL,
			`bookingpress_service_price` FLOAT NOT NULL, `bookingpress_service_currency` VARCHAR(100) NOT NULL,
			`bookingpress_service_duration_val` INT(11) NOT NULL, `bookingpress_service_duration_unit` VARCHAR(1) NOT NULL,
			`bookingpress_appointment_date` DATE NOT NULL, `bookingpress_appointment_end_date` DATE NOT NULL,
			`bookingpress_appointment_time` TIME NOT NULL, `bookingpress_appointment_end_time` TIME NOT NULL,
			`bookingpress_appointment_internal_note` TEXT DEFAULT NULL,
			`bookingpress_appointment_status` SMALLINT(1) DEFAULT 1,
			`bookingpress_created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (`bookingpress_appointment_booking_id`)
		) $c" );
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}latepoint_services (
			`id` MEDIUMINT(9) NOT NULL AUTO_INCREMENT, `name` VARCHAR(255) NOT NULL, `short_description` TEXT,
			`price_min` DECIMAL(20,4), `price_max` DECIMAL(20,4), `charge_amount` DECIMAL(20,4),
			`duration` INT(11) NOT NULL, `category_id` INT(11), `order_number` INT(11),
			`capacity_min` INT(4), `capacity_max` INT(4), `status` VARCHAR(20) NOT NULL, `visibility` VARCHAR(20) NOT NULL,
			`created_at` DATETIME, `updated_at` DATETIME, PRIMARY KEY (`id`)
		) $c" );
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}latepoint_agents (
			`id` MEDIUMINT(9) NOT NULL AUTO_INCREMENT, `first_name` VARCHAR(255) NOT NULL, `last_name` VARCHAR(255),
			`display_name` VARCHAR(255), `title` VARCHAR(255), `email` VARCHAR(110) NOT NULL, `phone` VARCHAR(255),
			`wp_user_id` INT(11), `status` VARCHAR(20) NOT NULL, `created_at` DATETIME, `updated_at` DATETIME,
			PRIMARY KEY (`id`)
		) $c" );
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}latepoint_customers (
			`id` MEDIUMINT(9) NOT NULL AUTO_INCREMENT, `first_name` VARCHAR(255), `last_name` VARCHAR(255),
			`email` VARCHAR(320), `phone` VARCHAR(255), `status` VARCHAR(50) NOT NULL, `wordpress_user_id` INT(11),
			`notes` TEXT, `admin_notes` TEXT, `created_at` DATETIME, `updated_at` DATETIME, PRIMARY KEY (`id`)
		) $c" );
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}latepoint_bookings (
			`id` INT(11) NOT NULL AUTO_INCREMENT, `booking_code` VARCHAR(10), `start_date` DATE, `end_date` DATE,
			`start_time` MEDIUMINT(9), `end_time` MEDIUMINT(9), `start_datetime_utc` DATETIME, `end_datetime_utc` DATETIME,
			`buffer_before` MEDIUMINT(9) NOT NULL DEFAULT 0, `buffer_after` MEDIUMINT(9) NOT NULL DEFAULT 0,
			`duration` MEDIUMINT(9), `status` VARCHAR(30) DEFAULT 'pending' NOT NULL, `customer_id` MEDIUMINT(9) NOT NULL,
			`service_id` MEDIUMINT(9) NOT NULL, `agent_id` MEDIUMINT(9) NOT NULL, `location_id` MEDIUMINT(9),
			`total_attendees` MEDIUMINT(4), `customer_timezone` VARCHAR(100), `server_timezone` VARCHAR(100),
			`created_at` DATETIME, `updated_at` DATETIME, PRIMARY KEY (`id`)
		) $c" );
		// phpcs:enable
	}

	function wpmcp_test_drop_booking_tables(): void {
		global $wpdb;
		foreach ( wpmcp_test_booking_tables() as $table ) {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . $table ); // phpcs:ignore WordPress.DB
		}
	}

	/**
	 * One booking in each plugin's own shape, for the same customer, service
	 * and slot. Returns the booking id each op takes.
	 *
	 * @return array{bookly: int, ssa: int, bookingpress: int, latepoint: int}
	 */
	function wpmcp_test_seed_bookings(): array {
		global $wpdb;
		$p   = $wpdb->prefix;
		$now = '2026-11-01 09:00:00';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery

		$wpdb->insert( "{$p}bookly_staff", [ 'full_name' => 'Sam Stylist', 'email' => 'sam.stylist@example.com', 'phone' => '+15550001' ] );
		$staff = (int) $wpdb->insert_id;
		$wpdb->insert( "{$p}bookly_services", [ 'title' => 'Haircut', 'duration' => 3600, 'price' => '40.00' ] );
		$service = (int) $wpdb->insert_id;
		$wpdb->insert( "{$p}bookly_customers", [ 'full_name' => 'Jane Doe', 'email' => 'jane.doe@example.com', 'phone' => '+15551234', 'notes' => '', 'created_at' => $now ] );
		$customer = (int) $wpdb->insert_id;
		$wpdb->insert( "{$p}bookly_appointments", [ 'staff_id' => $staff, 'service_id' => $service, 'start_date' => '2026-12-01 10:00:00', 'end_date' => '2026-12-01 11:00:00', 'created_at' => $now, 'updated_at' => $now ] );
		$appointment = (int) $wpdb->insert_id;
		$wpdb->insert( "{$p}bookly_customer_appointments", [ 'customer_id' => $customer, 'appointment_id' => $appointment, 'status' => 'approved', 'created_at' => $now, 'updated_at' => $now ] );
		$bookly = (int) $wpdb->insert_id;

		$wpdb->insert( "{$p}ssa_appointment_types", [ 'title' => 'Haircut', 'slug' => 'haircut', 'duration' => 60, 'status' => 'publish', 'date_created' => $now ] );
		$type = (int) $wpdb->insert_id;
		$wpdb->insert( "{$p}ssa_appointments", [
			'appointment_type_id'  => $type,
			'customer_information' => (string) wp_json_encode( [ 'Name' => 'Jane Doe', 'Email' => 'jane.doe@example.com', 'Phone' => '+15551234' ] ),
			'customer_timezone'    => 'UTC',
			'start_date'           => '2026-12-01 10:00:00',
			'end_date'             => '2026-12-01 11:00:00',
			'title'                => '',
			'description'          => '',
			'status'               => 'booked',
			'date_created'         => $now,
			'date_modified'        => $now,
		] );
		$ssa = (int) $wpdb->insert_id;

		$wpdb->insert( "{$p}bookingpress_services", [ 'bookingpress_service_name' => 'Haircut', 'bookingpress_service_price' => 40, 'bookingpress_service_duration_val' => 1, 'bookingpress_service_duration_unit' => 'h', 'bookingpress_service_description' => '', 'bookingpress_service_position' => 0 ] );
		$bp_service = (int) $wpdb->insert_id;
		$wpdb->insert( "{$p}bookingpress_appointment_bookings", [
			'bookingpress_booking_id'            => 'BP-1',
			'bookingpress_customer_id'           => 1,
			'bookingpress_customer_name'         => '',
			'bookingpress_customer_firstname'    => 'Jane',
			'bookingpress_customer_lastname'     => 'Doe',
			'bookingpress_customer_email'        => 'jane.doe@example.com',
			'bookingpress_customer_phone'        => '+15551234',
			'bookingpress_service_id'            => $bp_service,
			'bookingpress_service_name'          => 'Haircut',
			'bookingpress_service_price'         => 40,
			'bookingpress_service_currency'      => 'USD',
			'bookingpress_service_duration_val'  => 1,
			'bookingpress_service_duration_unit' => 'h',
			'bookingpress_appointment_date'      => '2026-12-01',
			'bookingpress_appointment_end_date'  => '2026-12-01',
			'bookingpress_appointment_time'      => '10:00:00',
			'bookingpress_appointment_end_time'  => '11:00:00',
			'bookingpress_appointment_status'    => 1,
		] );
		$bookingpress = (int) $wpdb->insert_id;

		$wpdb->insert( "{$p}latepoint_services", [ 'name' => 'Haircut', 'duration' => 60, 'charge_amount' => 40, 'status' => 'active', 'visibility' => 'visible' ] );
		$lp_service = (int) $wpdb->insert_id;
		$wpdb->insert( "{$p}latepoint_agents", [ 'first_name' => 'Sam', 'last_name' => 'Stylist', 'email' => 'sam.stylist@example.com', 'phone' => '+15550001', 'status' => 'active' ] );
		$agent = (int) $wpdb->insert_id;
		$wpdb->insert( "{$p}latepoint_customers", [ 'first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'jane.doe@example.com', 'phone' => '+15551234', 'status' => 'active' ] );
		$lp_customer = (int) $wpdb->insert_id;
		$wpdb->insert( "{$p}latepoint_bookings", [
			'booking_code' => 'LP1',
			'start_date'   => '2026-12-01',
			'end_date'     => '2026-12-01',
			'start_time'   => 600,
			'end_time'     => 660,
			'duration'     => 60,
			'status'       => 'approved',
			'customer_id'  => $lp_customer,
			'service_id'   => $lp_service,
			'agent_id'     => $agent,
			'created_at'   => $now,
			'updated_at'   => $now,
		] );
		$latepoint = (int) $wpdb->insert_id;
		// phpcs:enable

		return [ 'bookly' => $bookly, 'ssa' => $ssa, 'bookingpress' => $bookingpress, 'latepoint' => $latepoint ];
	}
}
