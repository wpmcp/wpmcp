<?php
/**
 * Tables, fixtures and a winner stub for the Thrive Optimize ops (issue
 * #827) on the plugin-data pair, for tests that run without Thrive Optimize
 * loaded.
 *
 * Taken from Thrive Optimize 10.9.2.1's own source:
 *  - migrations/install-1.0.php, revenue-1.1.php and stopped_date-1.2.php
 *    hold the DDL of {prefix}tab_tests and {prefix}tab_test_items (the
 *    TD_DB_Migration prefix "tab"), kept here with Thrive's own types and
 *    defaults;
 *  - a test's variations are child posts of the tested page with the
 *    post_status tab_variation (Thrive_AB_Post_Status::VARIATION), and the
 *    page itself is the control; each one's traffic share, status and the
 *    page's running test id are post meta thrive_ab_traffic,
 *    thrive_ab_status and thrive_ab_running_test_id (Thrive_AB_Meta), and
 *    is_control is unprefixed post meta;
 *  - Thrive_AB_Ajax::set_winner() is the plugin's own "pick a winner" path.
 *    The stub below records its calls and makes the change the plugin's
 *    makes to the test rows (winner flagged, test completed), so the op can
 *    be checked without Thrive Architect.
 *
 * DDL commits implicitly, so the tables are created in wpSetUpBeforeClass()
 * and dropped in wpTearDownAfterClass().
 */

if ( ! function_exists( 'wpmcp_test_create_thrive_optimize_tables' ) ) {
	function wpmcp_test_create_thrive_optimize_tables(): void {
		global $wpdb;

		wpmcp_test_drop_thrive_optimize_tables();
		$charset = $wpdb->get_charset_collate();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL built from literals.
		$wpdb->query( "CREATE TABLE {$wpdb->prefix}tab_tests (
			`id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			`page_id` BIGINT(20) UNSIGNED NOT NULL,
			`title` TEXT NOT NULL,
			`status` ENUM('running','completed','archived') NOT NULL DEFAULT 'running',
			`auto_win_enabled` TINYINT UNSIGNED NOT NULL DEFAULT '1',
			`auto_win_min_conversions` INT UNSIGNED NOT NULL DEFAULT '100',
			`auto_win_min_duration` TINYINT UNSIGNED NOT NULL DEFAULT '14',
			`auto_win_chance_original` DECIMAL(5,2) UNSIGNED NOT NULL DEFAULT '95.00',
			`notes` TEXT NOT NULL,
			`type` VARCHAR(20) NOT NULL DEFAULT 'monetary',
			`date_added` DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			`date_started` DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			`date_completed` DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			`goal_pages` LONGTEXT NULL DEFAULT NULL,
			PRIMARY KEY (`id`),
			INDEX `idx_page_id` (`page_id`)
		) $charset" );
		$wpdb->query( "CREATE TABLE {$wpdb->prefix}tab_test_items (
			`id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			`page_id` BIGINT(20) UNSIGNED NOT NULL,
			`variation_id` BIGINT(20) UNSIGNED NOT NULL,
			`test_id` BIGINT(20) UNSIGNED NOT NULL,
			`title` TEXT NOT NULL,
			`is_control` TINYINT UNSIGNED NOT NULL DEFAULT '0',
			`is_winner` TINYINT UNSIGNED NOT NULL DEFAULT '0',
			`impressions` INT UNSIGNED NOT NULL DEFAULT '0',
			`unique_impressions` INT UNSIGNED NOT NULL DEFAULT '0',
			`conversions` INT UNSIGNED NOT NULL DEFAULT '0',
			`revenue` DECIMAL(10,2) UNSIGNED NOT NULL DEFAULT '0.0',
			`active` TINYINT UNSIGNED NOT NULL DEFAULT '1',
			`stopped_date` DATETIME NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY (`id`),
			INDEX `idx_test_id` (`test_id`)
		) $charset" );
		// phpcs:enable
	}

	function wpmcp_test_drop_thrive_optimize_tables(): void {
		global $wpdb;
		foreach ( [ 'tab_tests', 'tab_test_items' ] as $table ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $table ) );
		}
	}

	/**
	 * A running test on a new page with one variation per entry of $variations
	 * (title => [traffic, unique_impressions, conversions, revenue]); the first
	 * entry is the control (the page itself).
	 *
	 * @return array{test: int, page: int, variations: array<string, int>, items: array<string, int>}
	 */
	function wpmcp_test_thrive_optimize_test( array $variations, array $test = [] ): array {
		global $wpdb;

		$titles = array_keys( $variations );
		$page   = wp_insert_post( [ 'post_type' => 'page', 'post_title' => (string) $titles[0], 'post_status' => 'publish' ] );
		$wpdb->insert( $wpdb->prefix . 'tab_tests', $test + [
			'page_id'      => $page,
			'title'        => 'Headline test',
			'status'       => 'running',
			'notes'        => '',
			'type'         => 'optins',
			'date_added'   => '2026-11-01 10:00:00',
			'date_started' => '2026-11-01 10:00:00',
		] );
		$test_id = (int) $wpdb->insert_id;
		if ( 'running' === ( $test['status'] ?? 'running' ) ) {
			update_post_meta( $page, 'thrive_ab_running_test_id', $test_id );
		}

		$ids   = [];
		$items = [];
		foreach ( $variations as $title => [ $traffic, $unique, $conversions, $revenue ] ) {
			$control = [] === $ids;
			$id      = $control ? $page : wp_insert_post( [ 'post_type' => 'page', 'post_parent' => $page, 'post_title' => (string) $title, 'post_status' => 'tab_variation' ] );
			update_post_meta( $id, 'thrive_ab_traffic', $traffic );
			update_post_meta( $id, 'thrive_ab_status', 'published' );
			update_post_meta( $id, 'is_control', $control ? 1 : 0 );
			$wpdb->insert( $wpdb->prefix . 'tab_test_items', [
				'page_id'            => $page,
				'variation_id'       => $id,
				'test_id'            => $test_id,
				'title'              => (string) $title,
				'is_control'         => $control ? 1 : 0,
				'impressions'        => $unique,
				'unique_impressions' => $unique,
				'conversions'        => $conversions,
				'revenue'            => $revenue,
			] );
			$ids[ $title ]   = (int) $id;
			$items[ $title ] = (int) $wpdb->insert_id;
		}

		return [ 'test' => $test_id, 'page' => (int) $page, 'variations' => $ids, 'items' => $items ];
	}

	/** One row of a Thrive Optimize table, as stored. */
	function wpmcp_test_thrive_optimize_row( string $table, int $id ): array {
		global $wpdb;
		return (array) $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $wpdb->prefix . $table, $id ), ARRAY_A );
	}
}

if ( ! class_exists( 'Thrive_AB_Ajax', false ) ) {
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound, Squiz.Classes.ValidClassName.NotCamelCaps -- Thrive Optimize's own class name.
	class Thrive_AB_Ajax {
		/** @var array<int, array> */
		public static array $calls = [];

		/** Thrive_AB_Ajax::set_winner()'s row changes: the winner flagged, the test completed. */
		public static function set_winner( $data ) {
			global $wpdb;

			self::$calls[] = $data;
			$item          = (array) $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $wpdb->prefix . 'tab_test_items', (int) $data['id'] ), ARRAY_A );
			$wpdb->update( $wpdb->prefix . 'tab_test_items', [ 'is_winner' => 1 ], [ 'id' => (int) $data['id'] ] );
			$wpdb->update( $wpdb->prefix . 'tab_tests', [ 'status' => 'completed', 'date_completed' => gmdate( 'Y-m-d H:i:s' ) ], [ 'id' => (int) $item['test_id'] ] );
			update_post_meta( (int) $data['page_id'], 'thrive_ab_running_test_id', false );

			return $data;
		}
	}
}
