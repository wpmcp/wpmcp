<?php
/**
 * Tables and rows for the WS Form ops (issue #676) on the plugin-data pair,
 * for tests that run without WS Form loaded.
 *
 * Taken from WS Form PRO 1.12.6:
 *  - includes/class-ws-form-activator.php (database_init()) holds the DDL
 *    of the {prefix}wsf_* tables used here, every column with WS Form's own
 *    types and defaults;
 *  - the seeded form is what WS_Form_Form::db_create_from_template(
 *    'contact-us') stored on a real WS Form PRO 1.12.6 (recorded through the
 *    live WS Form group): one tab, one section, seven fields, 'required'
 *    field meta, the GDPR checkbox's data_grid_checkbox and the form's
 *    'action' meta, both kept byte for byte as WS Form serialized them;
 *  - a submission is a wsf_submit row and its wsf_submit_meta rows as
 *    WS_Form_Submit::db_create() and db_update() wrote them there.
 *
 * DDL commits implicitly, so the tables are created in wpSetUpBeforeClass()
 * and dropped in wpTearDownAfterClass().
 */

if ( ! function_exists( 'wpmcp_test_create_ws_form_tables' ) ) {
	/** The 'action' form meta of the contact-us template, as WS Form stored it. */
	define( 'WPMCP_TEST_WS_FORM_ACTION_META', 'O:8:"stdClass":5:{s:13:"rows_per_page";i:10;s:11:"group_index";i:0;s:7:"default";a:0:{}s:7:"columns";a:2:{i:0;O:8:"stdClass":2:{s:2:"id";i:0;s:5:"label";s:6:"Action";}i:1;O:8:"stdClass":2:{s:2:"id";i:1;s:5:"label";s:4:"Data";}}s:6:"groups";a:1:{i:0;O:8:"stdClass":5:{s:5:"label";s:7:"Actions";s:4:"page";i:0;s:8:"disabled";s:0:"";s:10:"mask_group";s:0:"";s:4:"rows";a:3:{i:0;O:8:"stdClass":6:{s:2:"id";i:1;s:7:"default";s:0:"";s:8:"required";s:0:"";s:8:"disabled";s:0:"";s:6:"hidden";s:0:"";s:4:"data";a:2:{i:0;s:19:"Save to Submissions";i:1;s:196:"{"id":"database","meta":{"action_database_field_filter":"","action_database_field_filter_mapping":"","action_database_expire":"","action_database_expire_duration":"90"},"events":["save","submit"]}";}}i:1;O:8:"stdClass":6:{s:2:"id";i:2;s:7:"default";s:0:"";s:8:"required";s:0:"";s:8:"disabled";s:0:"";s:6:"hidden";s:0:"";s:4:"data";a:2:{i:0;s:12:"Show Message";i:1;s:348:"{"id":"message","meta":{"action_message_message":"Thank you for your inquiry.","action_message_type":"success","action_message_method":"before","action_message_form_hide":"on","action_message_clear":"on","action_message_scroll_top":"","action_message_duration":"","action_message_message_hide":"","action_message_form_show":""},"events":["submit"]}";}}i:2;O:8:"stdClass":6:{s:2:"id";i:3;s:7:"default";s:0:"";s:8:"disabled";s:0:"";s:8:"required";s:0:"";s:6:"hidden";s:0:"";s:4:"data";a:2:{i:0;s:10:"Send Email";i:1;s:774:"{"id":"email","meta":{"action_email_content_type":"text\\/html","action_email_from_email":"#blog_admin_email","action_email_from_name":"#blog_name","action_email_to":[{"action_email_email":"#blog_admin_email","action_email_name":"#blog_name"}],"action_email_cc":[],"action_email_bcc":[],"action_email_reply_to_email":"","action_email_subject":"#form_label","action_email_message_editor":"text_editor","action_email_message_wrapper":"on","action_email_message_textarea":"#email_submission","action_email_message_text_editor":"<h3>#email_subject<\\/h3><p>#email_submission<\\/p>","action_email_message_html_editor":"<h3>#email_subject<\\/h3>\\n\\n#email_submission","action_email_attachments":[],"action_email_headers":[],"action_email_charset":"#blog_charset"},"events":["submit"]}";}}}}}}' );

	/** The GDPR checkbox's data_grid_checkbox field meta, as WS Form stored it. */
	define( 'WPMCP_TEST_WS_FORM_CHECKBOX_GRID', 'O:8:"stdClass":5:{s:13:"rows_per_page";i:10;s:11:"group_index";i:0;s:7:"default";a:0:{}s:7:"columns";a:1:{i:0;O:8:"stdClass":2:{s:2:"id";i:0;s:5:"label";s:5:"Label";}}s:6:"groups";a:1:{i:0;O:8:"stdClass":5:{s:5:"label";s:10:"Checkboxes";s:4:"page";i:0;s:8:"disabled";s:0:"";s:10:"mask_group";s:0:"";s:4:"rows";a:1:{i:0;O:8:"stdClass":6:{s:2:"id";i:1;s:7:"default";s:0:"";s:8:"required";s:2:"on";s:8:"disabled";s:0:"";s:6:"hidden";s:0:"";s:4:"data";a:1:{i:0;s:90:"I consent to #blog_name storing my submitted information so they can respond to my inquiry";}}}}}}' );

	/** The contact-us template's fields: label, type, required. */
	define( 'WPMCP_TEST_WS_FORM_FIELDS', [
		[ 'First Name', 'text', true ],
		[ 'Last Name', 'text', false ],
		[ 'Email', 'email', true ],
		[ 'Phone', 'tel', false ],
		[ 'Your Inquiry', 'textarea', true ],
		[ 'GDPR', 'checkbox', false ],
		[ 'Submit', 'submit', false ],
	] );

	function wpmcp_test_ws_form_tables(): array {
		return [ 'form', 'form_meta', 'group', 'section', 'field', 'field_meta', 'submit', 'submit_meta', 'submit_note' ];
	}

	function wpmcp_test_create_ws_form_tables(): void {
		global $wpdb;

		wpmcp_test_drop_ws_form_tables();
		$p       = $wpdb->prefix . 'wsf_';
		$charset = $wpdb->get_charset_collate();
		$meta    = 'id bigint(20) unsigned NOT NULL AUTO_INCREMENT, parent_id bigint(20) unsigned NOT NULL, meta_key varchar(191) NOT NULL, meta_value longtext NOT NULL, PRIMARY KEY (id), KEY parent_id (parent_id), KEY meta_key (meta_key)';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL built from literals.
		$wpdb->query( "CREATE TABLE {$p}form (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			label varchar(1024) NOT NULL,
			date_added datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
			date_updated datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
			date_publish datetime,
			status varchar(16) DEFAULT 'draft' NOT NULL,
			count_stat_view bigint(20) unsigned DEFAULT 0 NOT NULL,
			count_stat_save bigint(20) unsigned DEFAULT 0 NOT NULL,
			count_stat_submit bigint(20) unsigned DEFAULT 0 NOT NULL,
			count_submit bigint(20) unsigned DEFAULT 0 NOT NULL,
			count_submit_unread bigint(20) unsigned DEFAULT 0 NOT NULL,
			published longtext NOT NULL,
			published_checksum varchar(32) DEFAULT '' NOT NULL,
			checksum varchar(32) DEFAULT '' NOT NULL,
			version varchar(32) DEFAULT '' NOT NULL,
			PRIMARY KEY (id)
		) {$charset}" );
		$wpdb->query( "CREATE TABLE {$p}form_meta ({$meta}) {$charset}" );
		$wpdb->query( "CREATE TABLE {$p}group (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			form_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			label varchar(1024) NOT NULL,
			sort_index bigint(20) unsigned NOT NULL,
			date_added datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
			date_updated datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
			PRIMARY KEY (id)
		) {$charset}" );
		$wpdb->query( "CREATE TABLE {$p}section (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			parent_section_id bigint(20) unsigned DEFAULT 0 NOT NULL,
			group_id bigint(20) unsigned DEFAULT 0 NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			label varchar(1024) NOT NULL,
			sort_index bigint(20) unsigned NOT NULL,
			child_count bigint(20) unsigned DEFAULT 0 NOT NULL,
			date_added datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
			date_updated datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
			PRIMARY KEY (id)
		) {$charset}" );
		$wpdb->query( "CREATE TABLE {$p}field (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			section_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			label varchar(1024) NOT NULL,
			sort_index bigint(20) unsigned NOT NULL,
			type varchar(32) NOT NULL,
			date_added datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
			date_updated datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
			PRIMARY KEY (id)
		) {$charset}" );
		$wpdb->query( "CREATE TABLE {$p}field_meta ({$meta}) {$charset}" );
		$wpdb->query( "CREATE TABLE {$p}submit (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			form_id bigint(20) unsigned NOT NULL,
			date_added datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
			date_updated datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
			date_expire datetime,
			user_id bigint(20) unsigned NOT NULL,
			hash char(32) NOT NULL,
			actions longtext NOT NULL,
			section_repeatable longtext NOT NULL,
			count_submit bigint(20) unsigned NOT NULL,
			duration bigint(20) unsigned NOT NULL,
			status char(20) NOT NULL,
			preview tinyint(1) NOT NULL,
			spam_level tinyint(1),
			starred tinyint(1) DEFAULT 0 NOT NULL,
			viewed tinyint(1) DEFAULT 0 NOT NULL,
			encrypted tinyint(1) DEFAULT 0 NOT NULL,
			token char(32) NOT NULL,
			token_validated tinyint(1) DEFAULT 0 NOT NULL,
			PRIMARY KEY (id),
			KEY form_id (form_id)
		) {$charset}" );
		$wpdb->query( "CREATE TABLE {$p}submit_meta (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			parent_id bigint(20) unsigned NOT NULL,
			section_id bigint(20) unsigned,
			field_id bigint(20) unsigned,
			repeatable_index bigint(20) unsigned,
			meta_key varchar(191),
			meta_value longtext NOT NULL,
			PRIMARY KEY (id),
			KEY parent_id (parent_id)
		) {$charset}" );
		$wpdb->query( "CREATE TABLE {$p}submit_note (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			submit_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_name varchar(255) DEFAULT '' NOT NULL,
			date_added datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
			date_updated datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
			content longtext NOT NULL,
			meta longtext NOT NULL,
			locked tinyint(1) DEFAULT 0 NOT NULL,
			PRIMARY KEY (id),
			KEY submit_id (submit_id)
		) {$charset}" );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	function wpmcp_test_drop_ws_form_tables(): void {
		global $wpdb;
		foreach ( wpmcp_test_ws_form_tables() as $name ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wsf_{$name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL.
		}
	}

	/**
	 * The contact-us form as WS Form stores it, under $label and $status.
	 *
	 * @return array{form_id: int, fields: int[]} the form id and its field ids in order.
	 */
	function wpmcp_test_ws_form_form( string $label = 'Contact Us', string $status = 'draft' ): array {
		global $wpdb;
		$p   = $wpdb->prefix . 'wsf_';
		$now = '2026-11-11 08:07:13';

		$wpdb->insert( "{$p}form", [ 'user_id' => 1, 'label' => $label, 'date_added' => $now, 'date_updated' => $now, 'status' => $status, 'published' => '', 'checksum' => '81d5fa75a2fb83cad2660b9813643b1e', 'published_checksum' => 'publish' === $status ? '81d5fa75a2fb83cad2660b9813643b1e' : '', 'version' => '1.12.6' ] );
		$form_id = (int) $wpdb->insert_id;
		$wpdb->insert( "{$p}form_meta", [ 'parent_id' => $form_id, 'meta_key' => 'action', 'meta_value' => WPMCP_TEST_WS_FORM_ACTION_META ] );
		$wpdb->insert( "{$p}form_meta", [ 'parent_id' => $form_id, 'meta_key' => 'submit_on_enter', 'meta_value' => '' ] );
		$wpdb->insert( "{$p}group", [ 'form_id' => $form_id, 'user_id' => 1, 'label' => 'Tab', 'sort_index' => 1, 'date_added' => $now, 'date_updated' => $now ] );
		$group_id = (int) $wpdb->insert_id;
		$wpdb->insert( "{$p}section", [ 'group_id' => $group_id, 'user_id' => 1, 'label' => 'Section', 'sort_index' => 1, 'date_added' => $now, 'date_updated' => $now ] );
		$section_id = (int) $wpdb->insert_id;

		$fields = [];
		foreach ( WPMCP_TEST_WS_FORM_FIELDS as $i => [ $field_label, $type, $required ] ) {
			$wpdb->insert( "{$p}field", [ 'section_id' => $section_id, 'user_id' => 1, 'label' => $field_label, 'sort_index' => $i + 1, 'type' => $type, 'date_added' => $now, 'date_updated' => $now ] );
			$field_id = (int) $wpdb->insert_id;
			$fields[] = $field_id;
			if ( 'submit' !== $type ) {
				$wpdb->insert( "{$p}field_meta", [ 'parent_id' => $field_id, 'meta_key' => 'label_render', 'meta_value' => 'on' ] );
				if ( 'checkbox' !== $type ) {
					$wpdb->insert( "{$p}field_meta", [ 'parent_id' => $field_id, 'meta_key' => 'required', 'meta_value' => $required ? 'on' : '' ] );
				}
			}
			if ( 'checkbox' === $type ) {
				$wpdb->insert( "{$p}field_meta", [ 'parent_id' => $field_id, 'meta_key' => 'data_grid_checkbox', 'meta_value' => WPMCP_TEST_WS_FORM_CHECKBOX_GRID ] );
			}
		}

		return [ 'form_id' => $form_id, 'fields' => $fields ];
	}

	/**
	 * A submission as WS_Form_Submit::db_create() and db_update() store it.
	 * $values maps field id => value (arrays are serialized, as WS Form
	 * does); $other is non-field meta such as tracking_remote_ip.
	 */
	function wpmcp_test_ws_form_submission( int $form_id, array $values, array $row = [], array $other = [] ): int {
		global $wpdb;
		$p   = $wpdb->prefix . 'wsf_';
		$now = $row['date_added'] ?? '2026-11-11 08:07:13';

		$wpdb->insert( "{$p}submit", $row + [
			'form_id'            => $form_id,
			'date_added'         => $now,
			'date_updated'       => $now,
			'user_id'            => 0,
			'hash'               => md5( uniqid( '', true ) ),
			'actions'            => '',
			'section_repeatable' => '',
			'count_submit'       => 0,
			'duration'           => 12,
			'status'             => 'publish',
			'preview'            => 0,
			'starred'            => 0,
			'viewed'             => 0,
			'encrypted'          => 0,
			'token'              => md5( uniqid( 't', true ) ),
		] );
		$id = (int) $wpdb->insert_id;
		foreach ( $values as $field_id => $value ) {
			$wpdb->insert( "{$p}submit_meta", [ 'parent_id' => $id, 'field_id' => (int) $field_id, 'meta_key' => 'field_' . (int) $field_id, 'meta_value' => is_array( $value ) ? serialize( $value ) : (string) $value ] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- WS Form's own storage format.
		}
		foreach ( $other as $key => $value ) {
			$wpdb->insert( "{$p}submit_meta", [ 'parent_id' => $id, 'meta_key' => (string) $key, 'meta_value' => (string) $value ] );
		}
		return $id;
	}
}
