<?php
/**
 * Post types and tables for the MemberPress ops (issue #668) on the
 * plugin-data pair, for tests that run without MemberPress loaded.
 *
 * Taken from MemberPress 1.14.3's own source:
 *  - app/controllers/MeprProductsCtrl.php and MeprRulesCtrl.php register the
 *    memberpressproduct (public, hierarchical) and memberpressrule (not
 *    public) post types, both with capability_type page;
 *  - app/lib/MeprDb.php holds the DDL of {prefix}mepr_transactions,
 *    {prefix}mepr_members and {prefix}mepr_rule_access_conditions (only the
 *    columns the ops read are kept here, with MemberPress's own types and
 *    defaults);
 *  - app/models/MeprProduct.php and MeprRule.php name the post meta keys
 *    (the *_str statics) and store booleans the way update_post_meta()
 *    stores PHP true and false: '1' and ''.
 *
 * DDL commits implicitly, so the tables are created in wpSetUpBeforeClass()
 * and dropped in wpTearDownAfterClass().
 */

if ( ! function_exists( 'wpmcp_test_register_memberpress_types' ) ) {
	function wpmcp_test_register_memberpress_types(): void {
		register_post_type( 'memberpressproduct', [
			'public'          => true,
			'capability_type' => 'page',
			'hierarchical'    => true,
			'supports'        => [ 'title', 'editor', 'page-attributes', 'comments', 'thumbnail' ],
		] );
		register_post_type( 'memberpressrule', [
			'public'          => false,
			'show_ui'         => true,
			'capability_type' => 'page',
			'hierarchical'    => false,
			'supports'        => [ 'title' ],
		] );
	}

	function wpmcp_test_unregister_memberpress_types(): void {
		foreach ( [ 'memberpressproduct', 'memberpressrule' ] as $type ) {
			if ( post_type_exists( $type ) ) {
				unregister_post_type( $type );
			}
		}
	}

	function wpmcp_test_create_memberpress_tables(): void {
		global $wpdb;

		wpmcp_test_drop_memberpress_tables();
		$charset = $wpdb->get_charset_collate();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL built from literals.
		$wpdb->query( "CREATE TABLE {$wpdb->prefix}mepr_transactions (
			id bigint(20) NOT NULL auto_increment,
			amount decimal(16,2) NOT NULL,
			total decimal(16,2) DEFAULT 0,
			tax_amount decimal(16,2) DEFAULT 0,
			user_id bigint(20) NOT NULL,
			product_id bigint(20) NOT NULL,
			coupon_id bigint(20) DEFAULT NULL,
			trans_num varchar(255) DEFAULT NULL,
			status varchar(255) DEFAULT 'pending',
			txn_type varchar(255) DEFAULT 'payment',
			response text DEFAULT NULL,
			gateway varchar(255) DEFAULT 'manual',
			subscription_id bigint(20) DEFAULT NULL,
			created_at datetime NOT NULL,
			expires_at datetime DEFAULT '0000-00-00 00:00:00',
			refunded_at datetime DEFAULT NULL,
			currency varchar(3) DEFAULT '',
			PRIMARY KEY (id)
		) {$charset}" );

		$wpdb->query( "CREATE TABLE {$wpdb->prefix}mepr_members (
			id bigint(20) NOT NULL auto_increment,
			user_id bigint(20) NOT NULL,
			txn_count bigint(20),
			expired_txn_count bigint(20),
			active_txn_count bigint(20),
			sub_count bigint(20),
			active_sub_count bigint(20),
			memberships longtext,
			inactive_memberships longtext,
			login_count bigint(20),
			total_spent decimal(16,2) DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			trial_txn_count bigint(20),
			PRIMARY KEY (id)
		) {$charset}" );

		$wpdb->query( "CREATE TABLE {$wpdb->prefix}mepr_rule_access_conditions (
			id bigint(20) NOT NULL auto_increment,
			rule_id bigint(20) NOT NULL,
			access_type varchar(20) NOT NULL,
			access_operator varchar(20) NOT NULL,
			access_condition varchar(60) NOT NULL,
			PRIMARY KEY (id)
		) {$charset}" );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	function wpmcp_test_drop_memberpress_tables(): void {
		global $wpdb;
		foreach ( [ 'mepr_transactions', 'mepr_members', 'mepr_rule_access_conditions' ] as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL.
		}
	}

	/** A transaction row the way MemberPress writes one; returns its id. */
	function wpmcp_test_memberpress_transaction( int $user_id, int $product_id, array $fields = [] ): int {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'mepr_transactions', $fields + [
			'amount'     => '10.00',
			'total'      => '10.00',
			'tax_amount' => '0.00',
			'user_id'    => $user_id,
			'product_id' => $product_id,
			'trans_num'  => 'mp-txn-' . wp_generate_password( 8, false ),
			'status'     => 'complete',
			'txn_type'   => 'payment',
			'gateway'    => 'manual',
			'response'   => '{"secret":"gateway payload"}',
			'created_at' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
			'expires_at' => '0000-00-00 00:00:00',
		] );
		return (int) $wpdb->insert_id;
	}

	/** A mepr_members row as MeprUser::update_member_data() keeps it. */
	function wpmcp_test_memberpress_member( int $user_id, string $active, string $inactive = '', array $fields = [] ): void {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		$wpdb->insert( $wpdb->prefix . 'mepr_members', $fields + [
			'user_id'              => $user_id,
			'txn_count'            => 1,
			'expired_txn_count'    => '' === $inactive ? 0 : 1,
			'active_txn_count'     => '' === $active ? 0 : 1,
			'sub_count'            => 0,
			'active_sub_count'     => 0,
			'memberships'          => $active,
			'inactive_memberships' => $inactive,
			'login_count'          => 3,
			'total_spent'          => '10.00',
			'created_at'           => $now,
			'updated_at'           => $now,
			'trial_txn_count'      => 0,
		] );
	}

	/** Every access condition row of a rule, in id order. */
	function wpmcp_test_memberpress_conditions( int $rule_id ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( "SELECT access_type, access_operator, access_condition FROM {$wpdb->prefix}mepr_rule_access_conditions WHERE rule_id = %d ORDER BY id ASC", $rule_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture.
	}
}

/*
 * The part of MeprCptModel the rules and memberships caches depend on
 * (issue #867), taken from MemberPress 1.14.3 app/lib/MeprCptModel.php:
 * all() returns the transient 'mepr_all_models_for_class_<class>' while it
 * is set and a reset is not asked for, otherwise rebuilds it from the
 * published posts of the model's post type and keeps it for a year.
 * MeprCptCtrl::update_all_models_for_class_transient() rebuilds it on
 * save_post only when is_admin(), which an MCP request is not.
 */
if ( ! class_exists( 'MeprCptModel' ) ) {
	class MeprCptModel {
		private const TYPES = [
			'MeprRule'    => 'memberpressrule',
			'MeprProduct' => 'memberpressproduct',
		];

		public static function all( $class, $reset_transients = false ) {
			$transient = 'mepr_all_models_for_class_' . strtolower( $class );
			$cached    = get_transient( $transient );
			if ( ! empty( $cached ) && ! $reset_transients ) {
				return $cached;
			}
			$models = [];
			foreach ( get_posts( [ 'numberposts' => -1, 'post_type' => self::TYPES[ $class ], 'post_status' => 'publish' ] ) as $post ) {
				$models[] = (object) [ 'ID' => $post->ID, 'post_type' => $post->post_type ];
			}
			delete_transient( $transient );
			set_transient( $transient, $models, YEAR_IN_SECONDS );
			return $models;
		}
	}
}

if ( ! function_exists( 'wpmcp_test_memberpress_locked' ) ) {
	/**
	 * Whether MemberPress would lock a page for a visitor without access: the
	 * way MeprRule::get_rules() finds a single_page rule, from the cached list
	 * MeprCptModel::all() returns, and MeprRule::is_locked_for_user() treats
	 * a visitor with no ID as locked out of any content that has a rule.
	 */
	function wpmcp_test_memberpress_locked( int $page_id ): bool {
		foreach ( MeprCptModel::all( 'MeprRule' ) as $rule ) {
			if ( 'single_page' === get_post_meta( $rule->ID, '_mepr_rules_type', true ) && (string) $page_id === (string) get_post_meta( $rule->ID, '_mepr_rules_content', true ) ) {
				return true;
			}
		}
		return false;
	}
}

/*
 * The part of MemberPress's product emails create-membership reads (issue
 * #822), from MemberPress 1.14.3: MeprEmailFactory::all('MeprBaseProductEmail')
 * returns one object per product email class, keyed by class, each with the
 * public $defaults (enabled, subject, body, use_template) and $show_form that
 * app/emails/MeprUserProductWelcomeEmail.php sets; the wp-admin membership
 * screen renders one form per email with show_form and saves those values.
 */
if ( ! class_exists( 'MeprEmailFactory' ) ) {
	abstract class MeprBaseProductEmail {
		public $defaults  = [];
		public $show_form = false;
	}

	class MeprUserProductWelcomeEmail extends MeprBaseProductEmail {
		public function __construct() {
			$this->show_form = true;
			$this->defaults  = [
				'enabled'      => false,
				'subject'      => '** Thanks for Purchasing {$product_name}',
				'body'         => 'Welcome to {$product_name}',
				'use_template' => true,
			];
		}
	}

	class MeprEmailFactory {
		public static function all( $etype = 'MeprBaseEmail', $args = [] ) {
			return 'MeprBaseProductEmail' === $etype ? [ 'MeprUserProductWelcomeEmail' => new MeprUserProductWelcomeEmail() ] : [];
		}
	}
}
