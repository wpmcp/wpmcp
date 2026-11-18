<?php
/**
 * Stand-ins for the GamiPress 7.1.5 functions the GamiPress pack calls
 * (issue #833), storing what they store the way GamiPress does:
 *  - a balance in the user meta _gamipress_<type>_points, the last change
 *    in _gamipress_<type>_new_points and the running totals in
 *    _gamipress_<type>_points_awarded and _gamipress_<type>_points_deducted
 *    (gamipress_update_user_points(), gamipress_log_user_points());
 *  - an earned achievement or rank as a row of {prefix}gamipress_user_earnings
 *    (gamipress_insert_user_earning()), its points awarded on top;
 *  - a rank in _gamipress_<type>_rank with _earned_time and _previous_rank
 *    (gamipress_update_user_rank());
 *  - every change as a row of {prefix}gamipress_logs with meta in
 *    {prefix}gamipress_logs_meta (gamipress_insert_log()).
 * With an admin id, the amount gamipress_award_points_to_user() and
 * gamipress_deduct_points_to_user() take is the new total, as in GamiPress.
 *
 * Loaded only by the stub-backed tests; the live test runs the real plugin,
 * so every function is guarded.
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixtures for a third-party table.

if ( ! function_exists( 'wpmcp_test_gamipress_types' ) ) {

	/** The registered types, kind => slug => GamiPress's entry. */
	function wpmcp_test_gamipress_types( ?array $set = null ): array {
		static $types = [ 'points' => [], 'achievement' => [], 'rank' => [] ];
		if ( null !== $set ) {
			$types = $set;
		}
		return $types;
	}

	/** Coins points, a badge achievement type and a level rank type, with their post types. */
	function wpmcp_test_register_gamipress_types(): void {
		wpmcp_test_gamipress_types( [
			'points'      => [ 'coins' => [ 'ID' => 11, 'singular_name' => 'Coin', 'plural_name' => 'Coins' ] ],
			'achievement' => [ 'badge' => [ 'ID' => 12, 'singular_name' => 'Badge', 'plural_name' => 'Badges' ] ],
			'rank'        => [ 'level' => [ 'ID' => 13, 'singular_name' => 'Level', 'plural_name' => 'Levels' ] ],
		] );
		register_post_type( 'badge', [ 'public' => true, 'hierarchical' => true, 'supports' => [ 'title', 'editor', 'page-attributes' ] ] );
		register_post_type( 'level', [ 'public' => true, 'hierarchical' => true, 'supports' => [ 'title', 'editor', 'page-attributes' ] ] );
	}

	function wpmcp_test_unregister_gamipress_types(): void {
		wpmcp_test_gamipress_types( [ 'points' => [], 'achievement' => [], 'rank' => [] ] );
		unregister_post_type( 'badge' );
		unregister_post_type( 'level' );
	}

	function wpmcp_test_create_gamipress_tables(): void {
		global $wpdb;
		wpmcp_test_drop_gamipress_tables();
		$charset = $wpdb->get_charset_collate();
		$wpdb->query( "CREATE TABLE {$wpdb->prefix}gamipress_user_earnings (
			user_earning_id bigint(20) NOT NULL auto_increment,
			title text,
			user_id bigint(20) NOT NULL,
			post_id bigint(20) NOT NULL,
			post_type varchar(50) NOT NULL,
			points bigint(20) NOT NULL DEFAULT 0,
			points_type varchar(50) NOT NULL DEFAULT '',
			date datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY (user_earning_id),
			KEY user_id (user_id),
			KEY post_id (post_id)
		) {$charset}" );
		$wpdb->query( "CREATE TABLE {$wpdb->prefix}gamipress_user_earnings_meta (
			meta_id bigint(20) unsigned NOT NULL auto_increment,
			user_earning_id bigint(20) unsigned NOT NULL DEFAULT 0,
			meta_key varchar(255) DEFAULT NULL,
			meta_value longtext,
			PRIMARY KEY (meta_id)
		) {$charset}" );
		$wpdb->query( "CREATE TABLE {$wpdb->prefix}gamipress_logs (
			log_id bigint(20) NOT NULL auto_increment,
			title text,
			type varchar(50) NOT NULL,
			trigger_type varchar(255) NOT NULL DEFAULT '',
			access varchar(50) NOT NULL DEFAULT 'public',
			user_id bigint(20) NOT NULL,
			date datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			points bigint(20) NOT NULL DEFAULT 0,
			points_type varchar(50) NOT NULL DEFAULT '',
			PRIMARY KEY (log_id)
		) {$charset}" );
		$wpdb->query( "CREATE TABLE {$wpdb->prefix}gamipress_logs_meta (
			meta_id bigint(20) unsigned NOT NULL auto_increment,
			log_id bigint(20) unsigned NOT NULL DEFAULT 0,
			meta_key varchar(255) DEFAULT NULL,
			meta_value longtext,
			PRIMARY KEY (meta_id)
		) {$charset}" );
	}

	function wpmcp_test_drop_gamipress_tables(): void {
		global $wpdb;
		foreach ( [ 'gamipress_user_earnings', 'gamipress_user_earnings_meta', 'gamipress_logs', 'gamipress_logs_meta' ] as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" );
		}
	}

	function wpmcp_test_gamipress_log( int $user_id, string $type, array $meta = [] ): int {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'gamipress_logs', [
			'title'       => $type,
			'type'        => $type,
			'user_id'     => $user_id,
			'date'        => current_time( 'mysql' ),
			'points'      => (int) ( $meta['points'] ?? 0 ),
			'points_type' => (string) ( $meta['points_type'] ?? '' ),
		] );
		$id = (int) $wpdb->insert_id;
		foreach ( $meta as $key => $value ) {
			$wpdb->insert( $wpdb->prefix . 'gamipress_logs_meta', [ 'log_id' => $id, 'meta_key' => '_gamipress_' . $key, 'meta_value' => (string) $value ] );
		}
		return $id;
	}

	function wpmcp_test_gamipress_earning( int $user_id, int $post_id, array $fields = [] ): int {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'gamipress_user_earnings', $fields + [
			'title'       => (string) get_the_title( $post_id ),
			'user_id'     => $user_id,
			'post_id'     => $post_id,
			'post_type'   => (string) get_post_type( $post_id ),
			'points'      => (int) get_post_meta( $post_id, '_gamipress_points', true ),
			'points_type' => (string) get_post_meta( $post_id, '_gamipress_points_type', true ),
			'date'        => current_time( 'mysql' ),
		] );
		return (int) $wpdb->insert_id;
	}
}

if ( ! function_exists( 'gamipress_get_points_types' ) ) {

	function gamipress_get_points_types() {
		return wpmcp_test_gamipress_types()['points'];
	}

	function gamipress_get_achievement_types() {
		return wpmcp_test_gamipress_types()['achievement'];
	}

	function gamipress_get_rank_types() {
		return wpmcp_test_gamipress_types()['rank'];
	}

	function gamipress_get_manager_capability() {
		return 'manage_options';
	}

	function gamipress_get_user_points( $user_id = 0, $points_type = '' ) {
		return absint( get_user_meta( $user_id, "_gamipress_{$points_type}_points", true ) );
	}

	function gamipress_get_user_points_awarded( $user_id = 0, $points_type = '' ) {
		return absint( get_user_meta( $user_id, "_gamipress_{$points_type}_points_awarded", true ) );
	}

	function gamipress_get_user_points_deducted( $user_id = 0, $points_type = '' ) {
		return absint( get_user_meta( $user_id, "_gamipress_{$points_type}_points_deducted", true ) );
	}

	function gamipress_update_user_points( $user_id = 0, $new_points = 0, $admin_id = 0, $achievement_id = null, $points_type = '', $reason = '' ) {
		$current = gamipress_get_user_points( $user_id, $points_type );
		if ( $admin_id ) {
			$new_points = $new_points - $current;
		}
		$total = max( $current + $new_points, 0 );
		update_user_meta( $user_id, "_gamipress_{$points_type}_points", $total );
		update_user_meta( $user_id, "_gamipress_{$points_type}_new_points", $new_points );
		$counter = $new_points > 0 ? 'awarded' : 'deducted';
		update_user_meta( $user_id, "_gamipress_{$points_type}_points_{$counter}", absint( get_user_meta( $user_id, "_gamipress_{$points_type}_points_{$counter}", true ) ) + abs( $new_points ) );
		$type = $admin_id ? ( $new_points > 0 ? 'points_award' : 'points_revoke' ) : ( $new_points > 0 ? 'points_earn' : 'points_deduct' );
		wpmcp_test_gamipress_log( (int) $user_id, $type, [ 'points' => $new_points, 'points_type' => $points_type, 'admin_id' => (int) $admin_id, 'pattern' => (string) $reason ] );
		return $total;
	}

	function gamipress_award_points_to_user( $user_id = 0, $points = 0, $points_type = '', $args = [] ) {
		$args = wp_parse_args( $args, [ 'admin_id' => 0, 'achievement_id' => null, 'reason' => '' ] );
		if ( $points < 0 && 0 === $args['admin_id'] ) {
			$points *= -1;
		}
		return gamipress_update_user_points( $user_id, $points, $args['admin_id'], $args['achievement_id'], $points_type, $args['reason'] );
	}

	function gamipress_deduct_points_to_user( $user_id = 0, $points = 0, $points_type = '', $args = [] ) {
		$args = wp_parse_args( $args, [ 'admin_id' => 0, 'achievement_id' => null, 'reason' => '' ] );
		if ( $points > 0 && 0 === $args['admin_id'] ) {
			$points *= -1;
		}
		return gamipress_update_user_points( $user_id, $points, $args['admin_id'], $args['achievement_id'], $points_type, $args['reason'] );
	}

	function gamipress_award_achievement_to_user( $achievement_id = 0, $user_id = 0, $admin_id = 0 ) {
		wpmcp_test_gamipress_earning( (int) $user_id, (int) $achievement_id );
		wpmcp_test_gamipress_log( (int) $user_id, $admin_id ? 'achievement_award' : 'achievement_earn', [ 'achievement_id' => (int) $achievement_id, 'admin_id' => (int) $admin_id ] );
		$points = absint( get_post_meta( $achievement_id, '_gamipress_points', true ) );
		$type   = (string) get_post_meta( $achievement_id, '_gamipress_points_type', true );
		if ( $points > 0 && isset( gamipress_get_points_types()[ $type ] ) ) {
			gamipress_award_points_to_user( $user_id, $points, $type, [ 'achievement_id' => $achievement_id ] );
		}
	}

	function gamipress_revoke_achievement_to_user( $achievement_id = 0, $user_id = 0, $earning_id = 0 ) {
		global $wpdb;
		$table   = $wpdb->prefix . 'gamipress_user_earnings';
		$earning = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d AND post_id = %d LIMIT 1", $user_id, $achievement_id ) );
		if ( ! $earning ) {
			return;
		}
		$wpdb->delete( $table, [ 'user_earning_id' => $earning->user_earning_id ] );
		$wpdb->delete( $table . '_meta', [ 'user_earning_id' => $earning->user_earning_id ] );
		if ( (int) $earning->points > 0 && isset( gamipress_get_points_types()[ $earning->points_type ] ) ) {
			gamipress_deduct_points_to_user( $user_id, (int) $earning->points, $earning->points_type, [ 'achievement_id' => $achievement_id ] );
		}
	}

	function gamipress_get_user_rank_id( $user_id = null, $rank_type = '' ) {
		$id = absint( get_user_meta( $user_id, "_gamipress_{$rank_type}_rank", true ) );
		if ( $id ) {
			return $id;
		}
		$lowest = get_posts( [ 'post_type' => $rank_type, 'post_status' => 'publish', 'orderby' => 'menu_order', 'order' => 'ASC', 'numberposts' => 1, 'fields' => 'ids' ] );
		return $lowest ? (int) $lowest[0] : 0;
	}

	function gamipress_update_user_rank( $user_id = 0, $rank_id = 0, $admin_id = 0 ) {
		$type = (string) get_post_type( $rank_id );
		$old  = gamipress_get_user_rank_id( $user_id, $type );
		if ( $old === (int) $rank_id ) {
			return get_post( $rank_id );
		}
		update_user_meta( $user_id, "_gamipress_{$type}_rank", (int) $rank_id );
		update_user_meta( $user_id, "_gamipress_{$type}_rank_earned_time", time() );
		if ( $old ) {
			update_user_meta( $user_id, "_gamipress_{$type}_previous_rank", $old );
		}
		wpmcp_test_gamipress_earning( (int) $user_id, (int) $rank_id );
		wpmcp_test_gamipress_log( (int) $user_id, $admin_id ? 'rank_award' : 'rank_earn', [ 'rank_id' => (int) $rank_id, 'old_rank_id' => $old ] );
		return get_post( $rank_id );
	}
}
