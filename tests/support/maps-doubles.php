<?php
// phpcs:ignoreFile -- test fixture: table DDL and doubles shaped like the real plugins.

/**
 * Fixtures for the maps and store locator ops (issue #907).
 *
 * The WP Go Maps tables follow includes/class.database.php of WP Go Maps
 * 10.2 ({prefix}wpgmza_maps and {prefix}wpgmza, the latlng POINT column
 * included; the SPATIAL index is left out because a temporary table cannot
 * carry one). The WP Maps tables follow WP Maps 5.0 (modules/map and
 * modules/location). WP Store Locator 3.1 is doubled through
 * wpsl_get_service(): its geocode service (geocode_location($store_data,
 * $post_id, $api_call), answering ['latlng' => ['lat', 'lng']] or
 * ['message' => ...]) and its system utils (flush_autoload_transients()).
 */

if (! function_exists('wpmcp_test_create_map_tables')) {
	function wpmcp_test_create_map_tables(): void {
		global $wpdb;
		$p = $wpdb->prefix;
		wpmcp_test_drop_map_tables();
		$wpdb->query( "CREATE TABLE {$p}wpgmza_maps (
			id int(11) NOT NULL AUTO_INCREMENT,
			map_title varchar(256) NOT NULL,
			map_width varchar(6) NOT NULL DEFAULT '100',
			map_height varchar(6) NOT NULL DEFAULT '400',
			map_start_lat varchar(700) NOT NULL DEFAULT '',
			map_start_lng varchar(700) NOT NULL DEFAULT '',
			map_start_location varchar(700) NOT NULL DEFAULT '',
			map_start_zoom INT(10) NOT NULL DEFAULT 12,
			active INT(1) NOT NULL DEFAULT 0,
			map_width_type VARCHAR(3) NOT NULL DEFAULT '%',
			map_height_type VARCHAR(3) NOT NULL DEFAULT 'px',
			other_settings longtext NOT NULL,
			PRIMARY KEY  (id)
		)" );
		$wpdb->query( "CREATE TABLE {$p}wpgmza (
			id int(11) NOT NULL AUTO_INCREMENT,
			map_id int(11) NOT NULL,
			address varchar(700) NOT NULL,
			description mediumtext NOT NULL,
			pic varchar(700) NOT NULL,
			link varchar(2083) NOT NULL,
			icon varchar(700) NOT NULL,
			lat varchar(100) NOT NULL,
			lng varchar(100) NOT NULL,
			anim varchar(3) NOT NULL,
			title varchar(700) NOT NULL,
			infoopen varchar(3) NOT NULL,
			category varchar(500) NOT NULL,
			approved tinyint(1) DEFAULT '1',
			retina tinyint(1) DEFAULT '0',
			type tinyint(1) DEFAULT '0',
			did varchar(500) NOT NULL,
			sticky tinyint(1) DEFAULT '0',
			other_data LONGTEXT NOT NULL,
			latlng POINT NOT NULL,
			layergroup INT(3) DEFAULT '0',
			PRIMARY KEY  (id),
			KEY map_id_idx (map_id)
		)" );
		$wpdb->query( "CREATE TABLE {$p}create_map (
			map_id int(11) NOT NULL AUTO_INCREMENT,
			map_title varchar(255) DEFAULT NULL,
			map_width varchar(255) DEFAULT NULL,
			map_height varchar(255) DEFAULT NULL,
			map_zoom_level varchar(255) DEFAULT NULL,
			map_type varchar(255) DEFAULT NULL,
			map_locations longtext DEFAULT NULL,
			PRIMARY KEY  (map_id)
		)" );
		$wpdb->query( "CREATE TABLE {$p}map_locations (
			location_id INT(11) NOT NULL AUTO_INCREMENT,
			location_title VARCHAR(255),
			location_address VARCHAR(255),
			location_latitude VARCHAR(255),
			location_longitude VARCHAR(255),
			location_city VARCHAR(255),
			location_state VARCHAR(255),
			location_country VARCHAR(255),
			location_postal_code VARCHAR(255),
			location_author INT(11),
			PRIMARY KEY  (location_id)
		)" );
	}

	function wpmcp_test_drop_map_tables(): void {
		global $wpdb;
		foreach ( [ 'wpgmza_maps', 'wpgmza', 'create_map', 'map_locations' ] as $name ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$name}" );
		}
	}

	/** A WP Go Maps map row, as its map editor saves one. */
	function wpmcp_test_go_map( array $row = [] ): int {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'wpgmza_maps', $row + [
			'map_title'      => 'Our locations',
			'map_start_lat'  => '51.5072',
			'map_start_lng'  => '-0.1276',
			'map_start_zoom' => 10,
			'other_settings' => serialize( [ 'store_locator_enabled' => 1, 'map_type' => 1, 'wpgmza_api_key' => 'MAP-SECRET-123' ] ),
		] );
		return (int) $wpdb->insert_id;
	}

	/** A WP Go Maps marker row, latlng set the way its Marker class sets it. */
	function wpmcp_test_go_marker( array $row = [] ): int {
		global $wpdb;
		$row += [ 'map_id' => 1, 'title' => 'Head office', 'address' => '1 High St, London', 'description' => '<p>Open daily</p>', 'lat' => '51.5', 'lng' => '-0.12', 'category' => '', 'link' => '' ];
		$wpdb->query( $wpdb->prepare(
			"INSERT INTO {$wpdb->prefix}wpgmza (map_id, address, description, pic, link, icon, lat, lng, anim, title, infoopen, category, did, other_data, latlng) VALUES (%d, %s, %s, '', %s, '', %s, %s, '', %s, '', %s, '', '', ST_GeomFromText(%s))",
			$row['map_id'], $row['address'], $row['description'], $row['link'], $row['lat'], $row['lng'], $row['title'], $row['category'], sprintf( 'POINT(%s %s)', $row['lat'], $row['lng'] )
		) );
		return (int) $wpdb->insert_id;
	}

	/** The marker's latlng as text (POINT(lat lng)). */
	function wpmcp_test_go_marker_point( int $id ): ?string {
		global $wpdb;
		$point = $wpdb->get_var( $wpdb->prepare( "SELECT ST_AsText(latlng) FROM {$wpdb->prefix}wpgmza WHERE id = %d", $id ) );
		return null === $point ? null : (string) $point;
	}

	/** A marker row as stored, latlng as hex. */
	function wpmcp_test_go_marker_row( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT *, HEX(latlng) AS latlng FROM {$wpdb->prefix}wpgmza WHERE id = %d", $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}
}

if (! class_exists('WPMCP_Test_WPSL_Services')) {
	final class WPMCP_Test_WPSL_Geocoder {
		/** @var array */
		public $response = [ 'latlng' => [ 'lat' => '52.370216', 'lng' => '4.895168' ], 'country_iso' => 'NL' ];
		/** @var array<int, array> */
		public $calls = [];

		public function geocode_location( $store_data, $post_id = '', $api_call = false ) {
			$this->calls[] = [ $store_data, $post_id, $api_call ];
			return $this->response;
		}
	}

	final class WPMCP_Test_WPSL_System {
		/** @var int */
		public $flushes = 0;

		public function flush_autoload_transients() {
			$this->flushes++;
		}
	}

	final class WPMCP_Test_WPSL_Services {
		/** @var WPMCP_Test_WPSL_Geocoder|null */
		public static $geocode;
		/** @var WPMCP_Test_WPSL_System|null */
		public static $system;

		public static function reset(): void {
			self::$geocode = new WPMCP_Test_WPSL_Geocoder();
			self::$system  = new WPMCP_Test_WPSL_System();
		}

		public static function get( string $id ) {
			if ( null === self::$geocode ) {
				self::reset();
			}
			return 'geocode' === $id ? self::$geocode : ( 'system_utils' === $id ? self::$system : null );
		}
	}
}

if (! function_exists('wpsl_get_service')) {
	function wpsl_get_service( $service_id ) {
		return WPMCP_Test_WPSL_Services::get( (string) $service_id );
	}
}
