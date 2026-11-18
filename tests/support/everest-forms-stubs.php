<?php
/**
 * Tables, forms and entries for the Everest Forms ops (issue #676) on the
 * plugin-data pair, for tests that run without Everest Forms loaded.
 *
 * Taken from Everest Forms 3.6.4 (wordpress.org):
 *  - includes/class-evf-install.php (get_schema()) holds the DDL of the
 *    {prefix}evf_entries and {prefix}evf_entrymeta tables, every column
 *    with Everest Forms' own types and defaults;
 *  - a form is an everest_form post whose post_content is the JSON that
 *    EVF_Install::create_forms() stores for the default "Contact Form"
 *    (includes/templates/contact.php), here with a checkbox field added in
 *    the shape the checkbox field's builder saves (choices keyed 1..n);
 *  - an entry is an evf_entries row and its evf_entrymeta rows as
 *    EVF_Form_Task::entry_save() writes them: the `fields` column holds
 *    the JSON of every formatted field (name, value, id, type, meta_key; a
 *    checkbox's value is {name, type, label: [...]}), and each field with a
 *    value is also one meta row keyed by its meta key, the value passed
 *    through maybe_serialize().
 *
 * DDL commits implicitly, so the tables are created in wpSetUpBeforeClass()
 * and dropped in wpTearDownAfterClass().
 */

if ( ! function_exists( 'wpmcp_test_create_everest_forms_tables' ) ) {
	function wpmcp_test_create_everest_forms_tables(): void {
		global $wpdb;

		wpmcp_test_drop_everest_forms_tables();
		$charset = $wpdb->get_charset_collate();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL built from literals.
		$wpdb->query( "CREATE TABLE {$wpdb->prefix}evf_entries (
			entry_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			form_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			user_device varchar(100) NOT NULL,
			user_ip_address VARCHAR(100) NULL DEFAULT '',
			referer text NOT NULL,
			fields longtext NULL,
			status varchar(20) NOT NULL,
			viewed tinyint(1) NOT NULL DEFAULT '0',
			starred tinyint(1) NOT NULL DEFAULT '0',
			date_created datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (entry_id),
			KEY form_id (form_id)
		) {$charset}" );
		$wpdb->query( "CREATE TABLE {$wpdb->prefix}evf_entrymeta (
			meta_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			entry_id BIGINT UNSIGNED NOT NULL,
			meta_key varchar(255) default NULL,
			meta_value longtext NULL,
			PRIMARY KEY  (meta_id),
			KEY entry_id (entry_id),
			KEY meta_key (meta_key(32))
		) {$charset}" );
		// phpcs:enable
	}

	function wpmcp_test_drop_everest_forms_tables(): void {
		global $wpdb;
		foreach ( [ 'evf_entries', 'evf_entrymeta' ] as $table ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $table ) );
		}
	}

	/** The default Contact Form's content (templates/contact.php) plus a checkbox field. */
	function wpmcp_test_everest_forms_content( int $form_id, string $title ): array {
		return [
			'id'            => $form_id,
			'form_field_id' => '1',
			'form_fields'   => [
				'fullname' => [ 'id' => 'fullname', 'type' => 'text', 'label' => 'Name', 'meta-key' => 'name', 'description' => '', 'required' => '1', 'placeholder' => '', 'css' => '' ],
				'email'    => [ 'id' => 'email', 'type' => 'email', 'label' => 'Email', 'meta-key' => 'email', 'description' => '', 'required' => '1', 'placeholder' => '', 'default_value' => '', 'css' => '' ],
				'subject'  => [ 'id' => 'subject', 'type' => 'text', 'label' => 'Subject', 'meta-key' => 'subject', 'description' => '', 'required' => '1', 'placeholder' => '', 'css' => '' ],
				'message'  => [ 'id' => 'message', 'type' => 'textarea', 'label' => 'Message', 'meta-key' => 'message', 'description' => '', 'placeholder' => '', 'css' => '' ],
				'topics'   => [
					'id'       => 'topics',
					'type'     => 'checkbox',
					'label'    => 'Topics',
					'meta-key' => 'topics',
					'choices'  => [
						'1' => [ 'label' => 'Sales', 'value' => '', 'image' => '' ],
						'2' => [ 'label' => 'Support', 'value' => '', 'image' => '' ],
					],
				],
			],
			'settings'      => [
				'form_title'                         => $title,
				'form_desc'                          => '',
				'successful_form_submission_message' => 'Thanks for contacting us! We will be in touch with you shortly.',
				'redirect_to'                        => 'same',
				'custom_page'                        => '2',
				'external_url'                       => '',
				'layout_class'                       => 'default',
				'form_class'                         => '',
				'submit_button_text'                 => 'Submit',
				'honeypot'                           => '1',
				'email'                              => [
					'connection_1' => [
						'connection_name'   => 'Admin Notification',
						'evf_to_email'      => '{admin_email}',
						'evf_from_name'     => 'Test Blog',
						'evf_from_email'    => '{admin_email}',
						'evf_reply_to'      => '{field_id="email"}',
						'evf_email_subject' => 'New Form Entry - ' . $title,
						'evf_email_message' => '{all_fields}',
					],
				],
			],
			'structure'     => [
				'row_1' => [ 'grid_1' => [ 'fullname', 'email', 'subject', 'message', 'topics' ] ],
			],
		];
	}

	/** An everest_form post as EVF_Install::create_forms() stores it. Returns its id. */
	function wpmcp_test_everest_form( string $title = 'Contact Form', string $status = 'publish', ?callable $edit = null ): int {
		$id      = (int) wp_insert_post( [ 'post_title' => $title, 'post_status' => $status, 'post_type' => 'everest_form', 'post_content' => '{}' ] );
		$content = wpmcp_test_everest_forms_content( $id, $title );
		if ( null !== $edit ) {
			$content = $edit( $content );
		}
		wp_update_post( [ 'ID' => $id, 'post_content' => wp_slash( (string) wp_json_encode( $content ) ) ] );
		return $id;
	}

	/**
	 * An entry as EVF_Form_Task::entry_save() writes it. $values: field id
	 * => submitted value (a list for the checkbox). $row overrides columns.
	 */
	function wpmcp_test_everest_forms_entry( int $form_id, array $values, array $row = [] ): int {
		global $wpdb;

		$content = wpmcp_test_everest_forms_content( $form_id, '' );
		$fields  = [];
		foreach ( $values as $field_id => $value ) {
			$def = $content['form_fields'][ $field_id ];
			if ( 'checkbox' === $def['type'] ) {
				$fields[ $field_id ] = [
					'id'        => $field_id,
					'type'      => 'checkbox',
					'value'     => [ 'name' => $def['label'], 'type' => 'checkbox', 'label' => (array) $value ],
					'meta_key'  => $def['meta-key'],
					'value_raw' => (array) $value,
				];
				continue;
			}
			$fields[ $field_id ] = [ 'name' => $def['label'], 'value' => $value, 'id' => $field_id, 'type' => $def['type'], 'meta_key' => $def['meta-key'] ];
		}

		$wpdb->insert(
			$wpdb->prefix . 'evf_entries',
			$row + [
				'form_id'         => $form_id,
				'user_id'         => 0,
				'user_device'     => 'Chrome/Mac/Desktop',
				'user_ip_address' => '203.0.113.7',
				'status'          => 'publish',
				'referer'         => 'https://example.org/contact/',
				'fields'          => wp_json_encode( $fields ),
				'date_created'    => '2026-11-11 09:00:00',
			]
		);
		$entry_id = (int) $wpdb->insert_id;
		foreach ( $fields as $field ) {
			$wpdb->insert( $wpdb->prefix . 'evf_entrymeta', [ 'entry_id' => $entry_id, 'meta_key' => sanitize_key( $field['meta_key'] ), 'meta_value' => maybe_serialize( $field['value'] ) ] );
		}
		return $entry_id;
	}
}
