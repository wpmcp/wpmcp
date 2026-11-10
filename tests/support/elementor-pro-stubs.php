<?php
/**
 * Tables and rows for the Elementor Pro form submissions ops (issue #654) on
 * the plugin-data pair, for tests that run without Elementor Pro loaded.
 *
 * Taken from Elementor Pro 4.3.3's own source:
 *  - modules/forms/submissions/database/migrations/initial.php and
 *    referer-extra.php hold the DDL of {prefix}e_submissions,
 *    {prefix}e_submissions_values and {prefix}e_submissions_actions_log
 *    (every column, with Elementor Pro's types and defaults, after
 *    migration 5);
 *  - database/query.php (add_submission(), get_new_submission_initial_data(),
 *    add_action_log()) says what a stored submission looks like: status
 *    'new', type 'submission', a uuid hash_id, main_meta_id pointing at the
 *    first email value (or the first value), local and GMT dates;
 *  - database/repositories/form-snapshot-repository.php stores the form's
 *    fields as JSON in the __elementor_forms_snapshot post meta.
 *
 * DDL commits implicitly, so the tables are created in wpSetUpBeforeClass()
 * and dropped in wpTearDownAfterClass().
 */

if ( ! function_exists( 'wpmcp_test_create_elementor_pro_tables' ) ) {
	function wpmcp_test_create_elementor_pro_tables(): void {
		global $wpdb;

		wpmcp_test_drop_elementor_pro_tables();
		$charset = $wpdb->get_charset_collate();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL built from literals.
		$wpdb->query( "CREATE TABLE {$wpdb->prefix}e_submissions (
			id bigint(20) unsigned auto_increment primary key,
			type varchar(60) null,
			hash_id varchar(60) not null,
			main_meta_id bigint(20) unsigned not null,
			post_id bigint(20) unsigned not null,
			referer varchar(500) not null,
			referer_title varchar(300) null,
			element_id varchar(20) not null,
			form_name varchar(60) not null,
			campaign_id bigint(20) unsigned not null,
			user_id bigint(20) unsigned null,
			user_ip varchar(46) not null,
			user_agent text not null,
			actions_count INT DEFAULT 0,
			actions_succeeded_count INT DEFAULT 0,
			status varchar(20) not null,
			is_read tinyint(1) default 0 not null,
			meta text null,
			created_at_gmt datetime not null,
			updated_at_gmt datetime not null,
			created_at datetime not null,
			updated_at datetime not null,
			UNIQUE KEY hash_id_unique_index (hash_id)
		) {$charset}" );

		$wpdb->query( "CREATE TABLE {$wpdb->prefix}e_submissions_values (
			id bigint(20) unsigned auto_increment primary key,
			submission_id bigint(20) unsigned not null default 0,
			`key` varchar(60) null,
			value longtext null,
			KEY submission_id_index (submission_id)
		) {$charset}" );

		$wpdb->query( "CREATE TABLE {$wpdb->prefix}e_submissions_actions_log (
			id bigint(20) unsigned auto_increment primary key,
			submission_id bigint(20) unsigned not null,
			action_name varchar(60) not null,
			action_label varchar(60) null,
			status varchar(20) not null,
			log text null,
			created_at_gmt datetime not null,
			updated_at_gmt datetime not null,
			created_at datetime not null,
			updated_at datetime not null,
			KEY submission_id_index (submission_id)
		) {$charset}" );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	function wpmcp_test_drop_elementor_pro_tables(): void {
		global $wpdb;
		foreach ( [ 'e_submissions', 'e_submissions_values', 'e_submissions_actions_log' ] as $table ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $table ) );
		}
	}

	/**
	 * A submission stored the way Query::add_submission() and
	 * add_action_log() store one. $fields are [id, type, value] triples.
	 *
	 * @param array<int, array{0: string, 1: string, 2: string}> $fields
	 * @param array<string, mixed>                                 $row     Column overrides.
	 * @param string[]                                             $actions Action names logged as success.
	 */
	function wpmcp_test_elementor_submission( int $post_id, string $element_id, array $fields, array $row = [], array $actions = [ 'email' ] ): int {
		global $wpdb;

		$gmt = (string) ( $row['created_at_gmt'] ?? current_time( 'mysql', true ) );
		$wpdb->insert( $wpdb->prefix . 'e_submissions', $row + [
			'type'                    => 'submission',
			'hash_id'                 => wp_generate_uuid4(),
			'main_meta_id'            => 0,
			'post_id'                 => $post_id,
			'referer'                 => 'https://example.org/contact/',
			'referer_title'           => 'Contact',
			'element_id'              => $element_id,
			'form_name'               => 'Contact form',
			'campaign_id'             => 0,
			'user_id'                 => 0,
			'user_ip'                 => '203.0.113.7',
			'user_agent'              => 'Mozilla/5.0 (test)',
			'actions_count'           => count( $actions ),
			'actions_succeeded_count' => count( $actions ),
			'status'                  => 'new',
			'is_read'                 => 0,
			'meta'                    => wp_json_encode( [ 'edit_post_id' => (string) $post_id ] ),
			'created_at_gmt'          => $gmt,
			'updated_at_gmt'          => $gmt,
			'created_at'              => get_date_from_gmt( $gmt ),
			'updated_at'              => get_date_from_gmt( $gmt ),
		] );
		$id = (int) $wpdb->insert_id;

		$main  = 0;
		$first = 0;
		foreach ( $fields as [ $key, $type, $value ] ) {
			$wpdb->insert( $wpdb->prefix . 'e_submissions_values', [ 'submission_id' => $id, 'key' => $key, 'value' => $value ] );
			$first = $first ?: (int) $wpdb->insert_id;
			if ( 0 === $main && 'email' === $type ) {
				$main = (int) $wpdb->insert_id;
			}
		}
		$wpdb->update( $wpdb->prefix . 'e_submissions', [ 'main_meta_id' => $main ?: $first ], [ 'id' => $id ] );

		foreach ( $actions as $action ) {
			$wpdb->insert( $wpdb->prefix . 'e_submissions_actions_log', [
				'submission_id'  => $id,
				'action_name'    => $action,
				'action_label'   => ucfirst( $action ),
				'status'         => 'success',
				'log'            => null,
				'created_at_gmt' => $gmt,
				'updated_at_gmt' => $gmt,
				'created_at'     => get_date_from_gmt( $gmt ),
				'updated_at'     => get_date_from_gmt( $gmt ),
			] );
		}

		return $id;
	}

	/**
	 * A page holding an Elementor Pro form widget, as the editor saves it
	 * (settings at their default are not stored), and optionally the form
	 * snapshot Save_To_Database writes on the first submission.
	 *
	 * @param array<string, mixed> $settings
	 */
	function wpmcp_test_elementor_form_page( string $element_id, array $settings, bool $with_snapshot = true ): int {
		$post_id = (int) wpmcp_test_elementor_page();
		$data    = [
			[
				'id'       => 'c0ffee1',
				'elType'   => 'container',
				'settings' => [],
				'elements' => [
					[ 'id' => $element_id, 'elType' => 'widget', 'widgetType' => 'form', 'settings' => $settings, 'elements' => [] ],
				],
			],
		];
		update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
		update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );

		if ( $with_snapshot ) {
			$fields = [];
			foreach ( (array) ( $settings['form_fields'] ?? [] ) as $field ) {
				$fields[] = [ 'id' => $field['custom_id'], 'type' => $field['field_type'] ?? 'text', 'label' => $field['field_label'] ?? '' ];
			}
			update_post_meta( $post_id, '__elementor_forms_snapshot', wp_slash( wp_json_encode( [ [ 'id' => $element_id, 'name' => $settings['form_name'] ?? 'New Form', 'fields' => $fields ] ] ) ) );
		}

		return $post_id;
	}

	function wpmcp_test_elementor_page(): int {
		return (int) wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Contact' ] );
	}
}
