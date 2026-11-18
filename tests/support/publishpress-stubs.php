<?php
/**
 * Tables and API stand-ins for the PublishPress ops (issue #709), for tests
 * that run without the plugins loaded.
 *
 * The DDL is taken from PublishPress Future 4.11.2 on wordpress.org
 * (src/Modules/Expirator/DBTableSchemas/ActionArgsSchema.php), every column
 * with the plugin's own type and default.
 *
 * The two functions below stand in for what the plugin does on its own
 * publishpressfuture_schedule_expiration and
 * publishpressfuture_unschedule_expiration actions
 * (Modules/Expirator/ExpirationScheduler::schedule() and unschedule()):
 * schedule() takes a site-local timestamp, stores the UTC time in a new
 * enabled ppfuture_actions_args row (after disabling any earlier one, as its
 * unscheduleIfScheduled() does) and mirrors the action in the legacy post
 * meta; unschedule() disables the row (the plugin's Action Scheduler cancel
 * hook does that) and sets _expiration-date-status to '0'
 * (ExpirablePostModel::disableExpiration()).
 *
 * DDL commits implicitly, so the table is created in wpSetUpBeforeClass()
 * and dropped in wpTearDownAfterClass().
 */

if ( ! function_exists( 'wpmcp_test_create_publishpress_tables' ) ) {
	function wpmcp_test_create_publishpress_tables(): void {
		global $wpdb;

		wpmcp_test_drop_publishpress_tables();
		$p       = $wpdb->get_blog_prefix();
		$charset = $wpdb->get_charset_collate();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL built from literals.
		$wpdb->query( "CREATE TABLE {$p}ppfuture_actions_args (
			id bigint UNSIGNED NOT NULL AUTO_INCREMENT,
			cron_action_id bigint UNSIGNED NOT NULL,
			post_id bigint UNSIGNED NOT NULL,
			enabled tinyint(1) NOT NULL DEFAULT '0',
			scheduled_date datetime NOT NULL,
			created_at datetime NOT NULL,
			args varchar(1000) NOT NULL,
			PRIMARY KEY  (id),
			KEY post_id (post_id,id),
			KEY enabled_post_id (post_id,enabled,id)
		) {$charset}" );
		// phpcs:enable
	}

	function wpmcp_test_drop_publishpress_tables(): void {
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->get_blog_prefix() . 'ppfuture_actions_args' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/** ExpirationScheduler::schedule(), for the options the ops send. */
	function wpmcp_test_publishpress_schedule( $post_id, $timestamp, $opts ): void {
		global $wpdb;
		$table = $wpdb->get_blog_prefix() . 'ppfuture_actions_args';
		// phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
		$utc = (int) get_gmt_from_date( date( 'Y-m-d H:i:s', (int) $timestamp ), 'U' );
		$wpdb->update( $table, [ 'enabled' => 0 ], [ 'post_id' => (int) $post_id ] );
		$opts['date'] = $timestamp;
		$wpdb->insert( $table, [
			'cron_action_id' => 1000 + (int) $post_id,
			'post_id'        => (int) $post_id,
			'enabled'        => 1,
			'scheduled_date' => gmdate( 'Y-m-d H:i:s', $utc ),
			'created_at'     => current_time( 'mysql', true ),
			'args'           => (string) wp_json_encode( $opts ),
		] );
		update_post_meta( $post_id, '_expiration-date', $utc );
		update_post_meta( $post_id, '_expiration-date-status', 'saved' );
		update_post_meta( $post_id, '_expiration-date-type', $opts['expireType'] ?? '' );
		update_post_meta( $post_id, '_expiration-date-post-status', $opts['newStatus'] ?? '' );
		update_post_meta( $post_id, '_expiration-date-taxonomy', $opts['categoryTaxonomy'] ?? '' );
		update_post_meta( $post_id, '_expiration-date-categories', $opts['category'] ?? [] );
		update_post_meta( $post_id, '_expiration-date-options', $opts );
	}

	/** ExpirationScheduler::unschedule(). */
	function wpmcp_test_publishpress_unschedule( $post_id ): void {
		global $wpdb;
		// The plugin acts only while the post has a scheduled action.
		if ( $wpdb->update( $wpdb->get_blog_prefix() . 'ppfuture_actions_args', [ 'enabled' => 0 ], [ 'post_id' => (int) $post_id, 'enabled' => 1 ] ) ) {
			update_post_meta( $post_id, '_expiration-date-status', '0' );
		}
	}
}
