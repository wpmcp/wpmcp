<?php
/**
 * A stand-in for TablePress for the TablePress ops (issue #481) on the
 * plugin-data pair, for tests that run without TablePress loaded.
 *
 * Storage is kept the way TablePress 3.4 keeps it (models/model-table.php,
 * models/model-post.php, classes/class-wp_option.php): a table is a
 * `tablepress_table` post (registered with capability_type tablepress_table
 * and map_meta_cap off) whose content is the cell data as JSON and whose
 * excerpt is the description, two post meta fields hold the options and the
 * visibility as JSON, and the `tablepress_tables` option holds
 * {"last_id": n, "table_post": {table id: post id}} as JSON. Like TablePress,
 * the model reads that option once when it is built and keeps it, so a test
 * sees the same staleness a rollback must repair. prepare_table() keeps
 * TablePress's size and visibility checks, save() and delete() clear the
 * render cache, and tablepress_get_table() caches its output for visitors who
 * are not logged in, under the transient names TablePress uses.
 *
 * The class is only defined when the real TablePress is not loaded, and
 * TABLEPRESS_ABSPATH is never defined here, so the pack counts as active only
 * where a test turns wpmcp_tablepress_active on.
 */

if ( ! class_exists( 'TablePress', false ) ) {
	// phpcs:disable
	class TablePress {
		/** @var WPMCP_Test_TablePress_Model|null */
		public static $model_table;
		public static string $shortcode = 'table';

		public static function load_class( string $class_name, string $file, string $folder, $params = null ): object {
			$map = [
				'TablePress_Export' => 'WPMCP_Test_TablePress_Export',
				'TablePress_Import' => 'WPMCP_Test_TablePress_Import',
			];
			$class = $map[ $class_name ] ?? null;
			if ( null === $class ) {
				throw new \LogicException( "The TablePress stub has no {$class_name}." );
			}
			return new $class();
		}
	}

	class WPMCP_Test_TablePress_Model {
		private array $tables;

		public function __construct() {
			$stored       = get_option( 'tablepress_tables', null );
			$this->tables = null === $stored ? [ 'last_id' => 0, 'table_post' => [] ] : (array) json_decode( (string) $stored, true );
		}

		public function _debug_get_tables(): array {
			return $this->tables;
		}

		public function _debug_update_tables( array $tables ): void {
			$this->tables = $tables;
			update_option( 'tablepress_tables', wp_json_encode( $tables ) );
		}

		public function table_exists( string $table_id ): bool {
			return isset( $this->tables['table_post'][ $table_id ] );
		}

		private function post_id( string $table_id ) {
			return $this->tables['table_post'][ $table_id ] ?? false;
		}

		public function load( string $table_id, bool $load_data = true, bool $load_options_visibility = true ) {
			$post_id = $this->post_id( $table_id );
			if ( false === $post_id ) {
				return new WP_Error( 'table_load_no_post_id_for_table_id' );
			}
			$post = get_post( (int) $post_id );
			if ( ! $post instanceof WP_Post ) {
				return new WP_Error( 'table_load_no_post_for_post_id' );
			}
			$table = [
				'id'            => $table_id,
				'name'          => $post->post_title,
				'description'   => $post->post_excerpt,
				'author'        => (int) $post->post_author,
				'last_modified' => $post->post_modified,
			];
			if ( $load_data ) {
				$data = json_decode( $post->post_content, true );
				if ( null === $data ) {
					$table['data']         = [ [ "The internal data of table {$table_id} is corrupted." ] ];
					$table['is_corrupted'] = true;
				} else {
					$table['data'] = (array) $data;
				}
			}
			if ( $load_options_visibility ) {
				$table['options']    = (array) json_decode( (string) get_post_meta( (int) $post_id, '_tablepress_table_options', true ), true );
				$table['visibility'] = (array) json_decode( (string) get_post_meta( (int) $post_id, '_tablepress_table_visibility', true ), true );
			}
			return $table;
		}

		public function load_all( bool $prime_meta_cache = true, bool $run_filter = true ): array {
			return array_map( 'strval', array_keys( $this->tables['table_post'] ) );
		}

		private function to_post( array $table, $post_id ): array {
			return [
				'ID'             => $post_id,
				'post_type'      => 'tablepress_table',
				'post_status'    => 'publish',
				'post_title'     => $table['name'],
				'post_excerpt'   => $table['description'],
				'post_content'   => wp_json_encode( $table['data'] ),
				'post_mime_type' => 'application/json',
			];
		}

		public function save( array $table ) {
			$post_id = $this->post_id( (string) $table['id'] );
			if ( false === $post_id ) {
				return new WP_Error( 'table_save_no_post_id_for_table_id' );
			}
			$saved = wp_update_post( wp_slash( $this->to_post( $table, $post_id ) ), true );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
			update_post_meta( (int) $post_id, '_tablepress_table_options', wp_slash( wp_json_encode( $table['options'] ) ) );
			update_post_meta( (int) $post_id, '_tablepress_table_visibility', wp_slash( wp_json_encode( $table['visibility'] ) ) );
			$this->invalidate_table_output_cache( (string) $table['id'] );
			return (string) $table['id'];
		}

		public function add( array $table, string $copy_or_add = 'add' ) {
			$post            = $this->to_post( $table, 0 );
			unset( $post['ID'] );
			$post_id = wp_insert_post( wp_slash( $post ), true );
			if ( is_wp_error( $post_id ) ) {
				return $post_id;
			}
			add_post_meta( $post_id, '_tablepress_table_options', wp_slash( wp_json_encode( $table['options'] ) ) );
			add_post_meta( $post_id, '_tablepress_table_visibility', wp_slash( wp_json_encode( $table['visibility'] ) ) );
			$tables = $this->tables;
			do {
				++$tables['last_id'];
				$id = (string) $tables['last_id'];
			} while ( isset( $tables['table_post'][ $id ] ) );
			$tables['table_post'][ $id ] = $post_id;
			uksort( $tables['table_post'], 'strnatcasecmp' );
			$this->_debug_update_tables( $tables );
			return $id;
		}

		public function copy( string $table_id ) {
			$table = $this->load( $table_id, true, true );
			if ( is_wp_error( $table ) ) {
				return $table;
			}
			$table['name'] = sprintf( 'Copy of %s', '' === trim( $table['name'] ) ? '(no name)' : $table['name'] );
			$table['id']   = false;
			$table         = $this->prepare_table( $this->get_table_template(), $table, false );
			return is_wp_error( $table ) ? $table : $this->add( $table, 'copy' );
		}

		public function delete( string $table_id ) {
			if ( ! $this->table_exists( $table_id ) ) {
				return new WP_Error( 'table_delete_table_does_not_exist' );
			}
			wp_delete_post( (int) $this->post_id( $table_id ), true );
			$tables = $this->tables;
			unset( $tables['table_post'][ $table_id ] );
			$this->_debug_update_tables( $tables );
			$this->invalidate_table_output_cache( $table_id );
			return true;
		}

		public function change_table_id( string $old_id, string $new_id ) {
			$post_id = $this->post_id( $old_id );
			if ( false === $post_id ) {
				return new WP_Error( 'table_change_id_no_post_id_for_table_id' );
			}
			if ( '' === $new_id || preg_match( '/[^a-zA-Z0-9_-]/', $new_id ) ) {
				return new WP_Error( 'table_change_id_new_id_is_invalid' );
			}
			if ( $this->table_exists( $new_id ) ) {
				return new WP_Error( 'table_change_table_new_id_exists' );
			}
			$tables                          = $this->tables;
			$tables['table_post'][ $new_id ] = $post_id;
			unset( $tables['table_post'][ $old_id ] );
			uksort( $tables['table_post'], 'strnatcasecmp' );
			$this->_debug_update_tables( $tables );
			return true;
		}

		public function get_table_template(): array {
			return [
				'id'            => false,
				'name'          => '',
				'description'   => '',
				'data'          => [ [ '' ] ],
				'last_modified' => wp_date( 'Y-m-d H:i:s' ),
				'author'        => get_current_user_id(),
				'options'       => [
					'last_editor'                 => get_current_user_id(),
					'table_head'                  => 1,
					'table_foot'                  => 0,
					'alternating_row_colors'      => true,
					'row_hover'                   => true,
					'print_name'                  => false,
					'print_name_position'         => 'above',
					'print_description'           => false,
					'print_description_position'  => 'below',
					'extra_css_classes'           => '',
					'use_datatables'              => true,
					'datatables_sort'             => true,
					'datatables_filter'           => true,
					'datatables_paginate'         => true,
					'datatables_lengthchange'     => true,
					'datatables_paginate_entries' => 10,
					'datatables_info'             => true,
					'datatables_scrollx'          => false,
					'datatables_custom_commands'  => '',
				],
				'visibility'    => [ 'rows' => [ 1 ], 'columns' => [ 1 ] ],
			];
		}

		public function prepare_table( array $table, array $new_table, bool $table_size_check = true ) {
			if ( false !== $table['id'] && $table['id'] !== $new_table['id'] ) {
				return new WP_Error( 'table_prepare_no_id_match' );
			}
			if ( ! isset( $new_table['name'], $new_table['description'] ) || empty( $new_table['data'] ) || empty( $new_table['data'][0] ) ) {
				return new WP_Error( 'table_prepare_name_description_or_data_not_set' );
			}
			if ( ! isset( $new_table['visibility']['rows'], $new_table['visibility']['columns'] ) ) {
				return new WP_Error( 'table_prepare_visibility_not_set' );
			}
			if ( $table_size_check ) {
				$rows    = (int) ( $new_table['number']['rows'] ?? 0 );
				$columns = (int) ( $new_table['number']['columns'] ?? 0 );
				if ( 0 === $rows || 0 === $columns || count( $new_table['data'] ) !== $rows || count( $new_table['data'][0] ) !== $columns ) {
					return new WP_Error( 'table_prepare_size_check_numbers_dont_match' );
				}
				if ( count( $new_table['visibility']['rows'] ) !== $rows || count( $new_table['visibility']['columns'] ) !== $columns ) {
					return new WP_Error( 'table_prepare_size_check_visibility_doesnt_match' );
				}
			}
			$table['name']        = $new_table['name'];
			$table['description'] = $new_table['description'];
			$table['data']        = $new_table['data'];
			array_walk_recursive( $table['data'], static function ( &$cell ): void {
				$cell = (string) $cell;
			} );
			if ( isset( $new_table['options'] ) ) {
				$defaults         = $this->get_table_template()['options'];
				$table['options'] = array_merge(
					array_intersect_key( (array) $table['options'], $defaults ),
					array_intersect_key( (array) $new_table['options'], $defaults )
				);
			}
			$table['visibility']                  = [
				'rows'    => array_map( 'intval', $new_table['visibility']['rows'] ),
				'columns' => array_map( 'intval', $new_table['visibility']['columns'] ),
			];
			$table['options']['last_editor']      = get_current_user_id();
			$table['last_modified']               = wp_date( 'Y-m-d H:i:s' );
			return $table;
		}

		public function invalidate_table_output_cache( string $table_id ): void {
			$list_name = 'tablepress_c_' . md5( $table_id );
			$list      = get_transient( $list_name );
			if ( false !== $list ) {
				foreach ( (array) json_decode( $list, true ) as $name ) {
					delete_transient( $name );
				}
			}
			delete_transient( $list_name );
		}
	}

	class WPMCP_Test_TablePress_Export {
		public function export_table( array $table, string $export_format, $options ): string {
			$delimiter = $options['csv_delimiter'] ?? ',';
			if ( 'json' === $export_format ) {
				return (string) wp_json_encode( $table['data'] );
			}
			if ( 'html' === $export_format ) {
				$rows = array_map( static fn ( array $row ): string => '<tr><td>' . implode( '</td><td>', array_map( 'esc_html', $row ) ) . '</td></tr>', $table['data'] );
				return '<table>' . implode( '', $rows ) . '</table>';
			}
			$lines = [];
			foreach ( $table['data'] as $row ) {
				$lines[] = implode( $delimiter, array_map( static fn ( string $cell ): string => '"' . str_replace( '"', '""', $cell ) . '"', $row ) );
			}
			return implode( "\n", $lines );
		}
	}

	/** TablePress_Import::run() for the form-field source: CSV, JSON or HTML, add, replace or append. */
	class WPMCP_Test_TablePress_Import {
		public function run( array $config ) {
			$data  = trim( (string) $config['form-field'] );
			$table = $this->parse( $data );
			if ( null === $table ) {
				return [ 'tables' => [], 'errors' => [ (object) [ 'error' => new WP_Error( 'table_import_phpspreadsheet_failed' ) ] ] ];
			}
			$width = max( array_map( 'count', $table ) );
			foreach ( $table as &$row ) {
				$row = array_pad( $row, $width, '' );
			}
			unset( $row );
			$model = TablePress::$model_table;
			if ( 'add' === $config['type'] ) {
				$template = $model->get_table_template();
				$new      = [ 'id' => false, 'name' => 'Imported from Manual Input', 'description' => 'Imported from Manual Input', 'data' => $table, 'options' => $template['options'], 'visibility' => [ 'rows' => array_fill( 0, count( $table ), 1 ), 'columns' => array_fill( 0, $width, 1 ) ] ];
				$id       = $model->add( $model->prepare_table( $template, $new, false ) );
				return is_wp_error( $id ) ? $id : [ 'tables' => [ [ 'id' => $id ] ], 'errors' => [] ];
			}
			if ( ! current_user_can( 'tablepress_edit_table', $config['existing_table'] ) ) {
				return [ 'tables' => [], 'errors' => [ (object) [ 'error' => new WP_Error( 'table_import_replace_append_capability_check_failed' ) ] ] ];
			}
			$existing = $model->load( $config['existing_table'], true, true );
			if ( 'append' === $config['type'] ) {
				$table = array_merge( $existing['data'], $table );
				$width = max( array_map( 'count', $table ) );
				foreach ( $table as &$row ) {
					$row = array_pad( $row, $width, '' );
				}
				unset( $row );
			}
			$new = $existing;
			$new['data'] = $table;
			$new['visibility'] = [
				'rows'    => array_pad( array_slice( $existing['visibility']['rows'], 0, count( $table ) ), count( $table ), 1 ),
				'columns' => array_pad( array_slice( $existing['visibility']['columns'], 0, $width ), $width, 1 ),
			];
			$saved = $model->save( $model->prepare_table( $existing, $new, false ) );
			return is_wp_error( $saved ) ? $saved : [ 'tables' => [ [ 'id' => $saved ] ], 'errors' => [] ];
		}

		private function parse( string $data ): ?array {
			if ( '' === $data ) {
				return null;
			}
			if ( '[' === $data[0] || '{' === $data[0] ) {
				$json = json_decode( $data, true );
				if ( ! is_array( $json ) ) {
					return null;
				}
				$rows = isset( $json['data'] ) ? $json['data'] : $json;
				return array_values( array_map( static fn ( $row ): array => array_map( 'strval', array_values( (array) $row ) ), $rows ) );
			}
			if ( '<' === $data[0] ) {
				preg_match_all( '#<tr[^>]*>(.*?)</tr>#is', $data, $trs );
				$rows = [];
				foreach ( $trs[1] as $tr ) {
					preg_match_all( '#<t[dh][^>]*>(.*?)</t[dh]>#is', $tr, $cells );
					$rows[] = array_map( 'trim', $cells[1] );
				}
				return [] === $rows ? null : $rows;
			}
			// The escape character is spelled out (PHP 8.4 deprecates relying on
			// its default); a backslash is the default this parse always used.
			return array_map( static fn ( string $line ): array => str_getcsv( $line, ',', '"', '\\' ), preg_split( '/\r\n|\n|\r/', $data ) );
		}
	}
	// phpcs:enable
}

if ( ! function_exists( 'tablepress_get_table' ) ) {
	/** TablePress's template tag: the table's HTML, cached for visitors who are not logged in. */
	function tablepress_get_table( $query ): string {
		$id    = (string) ( is_array( $query ) ? ( $query['id'] ?? '' ) : $query );
		$cache = ! is_user_logged_in();
		$name  = 'tablepress_' . md5( $id );
		if ( $cache ) {
			$hit = get_transient( $name );
			if ( false !== $hit ) {
				return $hit . '<!-- from cache -->';
			}
		}
		$table = TablePress::$model_table->load( $id, true, true );
		if ( is_wp_error( $table ) ) {
			return "[table \"{$id}\" not found /]";
		}
		$html = ( new WPMCP_Test_TablePress_Export() )->export_table( $table, 'html', [] );
		if ( $cache ) {
			set_transient( $name, $html, DAY_IN_SECONDS );
			set_transient( 'tablepress_c_' . md5( $id ), wp_json_encode( [ $name ] ), 2 * DAY_IN_SECONDS );
		}
		return $html;
	}
}

if ( ! function_exists( 'wpmcp_test_tablepress_boot' ) ) {
	/** The capabilities TablePress 3.4 gives administrators on activation (model-options.php). */
	function wpmcp_test_tablepress_caps(): array {
		return [
			'tablepress_edit_tables',
			'tablepress_delete_tables',
			'tablepress_list_tables',
			'tablepress_add_tables',
			'tablepress_copy_tables',
			'tablepress_import_tables',
			'tablepress_export_tables',
		];
	}

	/** TablePress's meta capability mapping (TablePress_Options_Model::map_tablepress_meta_caps()). */
	function wpmcp_test_tablepress_meta_caps( array $caps, $cap, int $user_id, array $args ): array {
		$map = [
			'tablepress_edit_table'    => 'tablepress_edit_tables',
			'tablepress_edit_table_id' => 'tablepress_edit_tables',
			'tablepress_copy_table'    => 'tablepress_copy_tables',
			'tablepress_delete_table'  => 'tablepress_delete_tables',
			'tablepress_export_table'  => 'tablepress_export_tables',
		];
		return is_string( $cap ) && isset( $map[ $cap ] ) ? [ $map[ $cap ] ] : $caps;
	}

	/** Register the post type and model the way TablePress::run() does, fresh from the stored option. */
	function wpmcp_test_tablepress_boot(): void {
		register_post_type( 'tablepress_table', [
			'public'          => false,
			'show_ui'         => false,
			'query_var'       => false,
			'rewrite'         => false,
			'capability_type' => 'tablepress_table',
			'map_meta_cap'    => false,
			'supports'        => [ 'title', 'editor', 'excerpt', 'revisions' ],
		] );
		add_filter( 'map_meta_cap', 'wpmcp_test_tablepress_meta_caps', 10, 4 );
		TablePress::$model_table = new WPMCP_Test_TablePress_Model();
	}

	function wpmcp_test_tablepress_shutdown(): void {
		remove_filter( 'map_meta_cap', 'wpmcp_test_tablepress_meta_caps', 10 );
		unregister_post_type( 'tablepress_table' );
		TablePress::$model_table = null;
	}
}
