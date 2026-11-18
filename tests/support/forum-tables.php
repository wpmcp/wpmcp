<?php
/**
 * Tables for the wpForo and Asgaros Forum ops (issue #671), for tests that
 * run without either plugin loaded.
 *
 * The DDL is taken from the free plugins on wordpress.org:
 *  - wpForo 3.2.5, includes/install-sql.php: the main board's
 *    {prefix}wpforo_forums, wpforo_topics, wpforo_posts and wpforo_postmeta
 *    (FULLTEXT and secondary keys left out, they do not change the data);
 *  - Asgaros Forum, includes/forum-database.php: {prefix}forum_forums,
 *    forum_topics and forum_posts.
 *
 * DDL commits implicitly, so the tables are created in wpSetUpBeforeClass()
 * and dropped in wpTearDownAfterClass().
 */

if ( ! function_exists( 'wpmcp_test_create_forum_tables' ) ) {
	function wpmcp_test_forum_tables(): array {
		return [ 'wpforo_forums', 'wpforo_topics', 'wpforo_posts', 'wpforo_postmeta', 'forum_forums', 'forum_topics', 'forum_posts' ];
	}

	function wpmcp_test_create_forum_tables(): void {
		global $wpdb;

		wpmcp_test_drop_forum_tables();
		$p       = $wpdb->prefix;
		$charset = $wpdb->get_charset_collate();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test fixture DDL built from literals.
		$wpdb->query( "CREATE TABLE {$p}wpforo_forums (
			`forumid` INT UNSIGNED NOT NULL AUTO_INCREMENT,
			`title` VARCHAR(255) NOT NULL,
			`slug` VARCHAR(255) NOT NULL,
			`description` LONGTEXT,
			`parentid` INT UNSIGNED NOT NULL DEFAULT 0,
			`icon` VARCHAR(255),
			`cover` BIGINT UNSIGNED NOT NULL DEFAULT 0,
			`cover_height` INT(4) UNSIGNED NOT NULL DEFAULT 150,
			`last_topicid` INT UNSIGNED NOT NULL DEFAULT 0,
			`last_postid` INT UNSIGNED NOT NULL DEFAULT 0,
			`last_userid` VARCHAR(255) NOT NULL DEFAULT '',
			`last_post_date` DATETIME NOT NULL DEFAULT '1000-01-01 00:00:00',
			`topics` INT NOT NULL DEFAULT 0,
			`posts` INT NOT NULL DEFAULT 0,
			`permissions` TEXT,
			`meta_key` TEXT,
			`meta_desc` TEXT,
			`status` tinyint(1) UNSIGNED NOT NULL DEFAULT 0,
			`is_cat` tinyint(1) UNSIGNED NOT NULL DEFAULT 0,
			`layout` tinyint(1) UNSIGNED NOT NULL DEFAULT 0,
			`order` INT UNSIGNED NOT NULL DEFAULT 0,
			`color` VARCHAR(7) NOT NULL DEFAULT '',
			`type` VARCHAR(20) NOT NULL DEFAULT 'forum',
			PRIMARY KEY (`forumid`)
		) {$charset}" );
		$wpdb->query( "CREATE TABLE {$p}wpforo_topics (
			`topicid` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			`forumid` INT UNSIGNED NOT NULL,
			`first_postid` BIGINT UNSIGNED NOT NULL DEFAULT 0,
			`userid` INT UNSIGNED NOT NULL,
			`title` VARCHAR(255) NOT NULL,
			`slug` VARCHAR(255) NOT NULL,
			`created` DATETIME NOT NULL DEFAULT '1000-01-01 00:00:00',
			`modified` DATETIME NOT NULL DEFAULT '1000-01-01 00:00:00',
			`last_post` BIGINT UNSIGNED NOT NULL DEFAULT 0,
			`posts` INT NOT NULL DEFAULT 0,
			`votes` INT NOT NULL DEFAULT 0,
			`answers` INT NOT NULL DEFAULT 0,
			`views` INT UNSIGNED NOT NULL DEFAULT 0,
			`meta_key` TEXT,
			`meta_desc` TEXT,
			`type` TINYINT NOT NULL DEFAULT 0,
			`solved` TINYINT(1) NOT NULL DEFAULT 0,
			`closed` tinyint(1) UNSIGNED NOT NULL DEFAULT 0,
			`has_attach` tinyint(1) UNSIGNED NOT NULL DEFAULT 0,
			`private` tinyint(1) UNSIGNED NOT NULL DEFAULT 0,
			`status` tinyint(1) UNSIGNED NOT NULL DEFAULT 0,
			`name` VARCHAR(50) NOT NULL DEFAULT '',
			`email` VARCHAR(50) NOT NULL DEFAULT '',
			`prefix` VARCHAR(100) NOT NULL DEFAULT '',
			`tags` TEXT,
			`indexed` VARCHAR(32) DEFAULT NULL,
			`local` TINYINT(1) NOT NULL DEFAULT 0,
			`cloud` TINYINT(1) NOT NULL DEFAULT 0,
			`task_tag` INT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY (`topicid`)
		) {$charset}" );
		$wpdb->query( "CREATE TABLE {$p}wpforo_postmeta (
			`metaid` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			`postid` BIGINT UNSIGNED NOT NULL,
			`metakey` VARCHAR(255) NOT NULL,
			`metavalue` MEDIUMTEXT,
			`forumid` INT UNSIGNED NOT NULL DEFAULT 0,
			`topicid` BIGINT UNSIGNED NOT NULL DEFAULT 0,
			`is_first_post` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
			`status` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
			`private` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY (`metaid`)
		) {$charset}" );
		$wpdb->query( "CREATE TABLE {$p}wpforo_posts (
			`postid` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			`parentid` BIGINT UNSIGNED NOT NULL DEFAULT 0,
			`forumid` INT UNSIGNED NOT NULL,
			`topicid` BIGINT UNSIGNED NOT NULL,
			`userid` INT UNSIGNED NOT NULL,
			`title` varchar(255),
			`body` LONGTEXT,
			`created` DATETIME NOT NULL DEFAULT '1000-01-01 00:00:00',
			`modified` DATETIME NOT NULL DEFAULT '1000-01-01 00:00:00',
			`likes` INT UNSIGNED NOT NULL DEFAULT 0,
			`votes` INT NOT NULL DEFAULT 0,
			`is_answer` tinyint(1) UNSIGNED NOT NULL DEFAULT 0,
			`is_first_post` tinyint(1) UNSIGNED NOT NULL DEFAULT 0,
			`status` tinyint(1) UNSIGNED NOT NULL DEFAULT 0,
			`name` VARCHAR(50) NOT NULL DEFAULT '',
			`email` VARCHAR(50) NOT NULL DEFAULT '',
			`private` tinyint(1) UNSIGNED NOT NULL DEFAULT 0,
			`root` BIGINT NULL DEFAULT NULL,
			PRIMARY KEY (`postid`)
		) {$charset}" );
		$wpdb->query( "CREATE TABLE {$p}forum_forums (
			id int(11) NOT NULL auto_increment,
			name varchar(255) NOT NULL default '',
			parent_id int(11) NOT NULL default '0',
			parent_forum int(11) NOT NULL default '0',
			description varchar(255) NOT NULL default '',
			icon varchar(255) NOT NULL default '',
			sort int(11) NOT NULL default '0',
			forum_status varchar(255) NOT NULL default 'normal',
			slug varchar(255) NOT NULL default '',
			PRIMARY KEY  (id)
		) {$charset}" );
		$wpdb->query( "CREATE TABLE {$p}forum_topics (
			id int(11) NOT NULL auto_increment,
			parent_id int(11) NOT NULL default '0',
			author_id int(11) NOT NULL default '0',
			views int(11) NOT NULL default '0',
			name varchar(255) NOT NULL default '',
			sticky int(1) NOT NULL default '0',
			closed int(1) NOT NULL default '0',
			approved int(1) NOT NULL default '1',
			slug varchar(255) NOT NULL default '',
			PRIMARY KEY  (id)
		) {$charset}" );
		$wpdb->query( "CREATE TABLE {$p}forum_posts (
			id int(11) NOT NULL auto_increment,
			text longtext,
			parent_id int(11) NOT NULL default '0',
			forum_id int(11) NOT NULL default '0',
			date datetime NOT NULL default '1000-01-01 00:00:00',
			date_edit datetime NOT NULL default '1000-01-01 00:00:00',
			author_id int(11) NOT NULL default '0',
			author_edit int(11) NOT NULL default '0',
			uploads longtext,
			PRIMARY KEY  (id)
		) {$charset}" );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	function wpmcp_test_drop_forum_tables(): void {
		global $wpdb;
		foreach ( wpmcp_test_forum_tables() as $table ) {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . $table ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed test table names.
		}
	}

	/** A wpForo forum row; returns its forumid. */
	function wpmcp_test_wpforo_forum( array $row = [] ): int {
		global $wpdb;
		static $n = 0;
		++$n;
		$wpdb->insert( $wpdb->prefix . 'wpforo_forums', $row + [ 'title' => 'Forum ' . $n, 'slug' => 'forum-' . $n . '-' . wp_generate_password( 4, false ), 'last_userid' => '0', 'status' => 1 ] );
		return (int) $wpdb->insert_id;
	}

	/**
	 * A wpForo topic with its first post and the given number of replies,
	 * counters kept the way wpForo keeps them; returns [topicid, postids].
	 *
	 * @return array{0: int, 1: int[]}
	 */
	function wpmcp_test_wpforo_topic( int $forumid, array $row = [], int $replies = 1 ): array {
		global $wpdb;
		$p = $wpdb->prefix;
		$wpdb->insert( $p . 'wpforo_topics', $row + [ 'forumid' => $forumid, 'userid' => 1, 'title' => 'A topic', 'slug' => 'a-topic-' . wp_generate_password( 4, false ), 'created' => '2026-11-01 10:00:00', 'modified' => '2026-11-01 10:00:00', 'tags' => '' ] );
		$topicid = (int) $wpdb->insert_id;
		$ids     = [];
		for ( $i = 0; $i <= $replies; $i++ ) {
			$guest = 1 === $i;
			$wpdb->insert( $p . 'wpforo_posts', [
				'forumid'       => $forumid,
				'topicid'       => $topicid,
				'userid'        => $guest ? 0 : 1,
				'title'         => 0 === $i ? 'A topic' : 'RE: A topic',
				'body'          => 0 === $i ? 'Opening post' : 'Reply ' . $i,
				'created'       => sprintf( '2026-11-01 10:%02d:00', $i ),
				'modified'      => sprintf( '2026-11-01 10:%02d:00', $i ),
				'is_first_post' => 0 === $i ? 1 : 0,
				'name'          => $guest ? 'Guest Person' : '',
				'email'         => $guest ? 'guest.person@example.org' : '',
			] );
			$ids[] = (int) $wpdb->insert_id;
			$wpdb->insert( $p . 'wpforo_postmeta', [ 'postid' => (int) $wpdb->insert_id, 'metakey' => 'm', 'metavalue' => 'v', 'forumid' => $forumid, 'topicid' => $topicid, 'is_first_post' => 0 === $i ? 1 : 0 ] );
		}
		$wpdb->update( $p . 'wpforo_topics', [ 'first_postid' => $ids[0], 'last_post' => end( $ids ), 'posts' => count( $ids ) ], [ 'topicid' => $topicid ] );
		$wpdb->query( $wpdb->prepare( "UPDATE {$p}wpforo_forums SET topics = topics + 1, posts = posts + %d WHERE forumid = %d", count( $ids ), $forumid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed test table name.
		return [ $topicid, $ids ];
	}

	/** An Asgaros forum row; returns its id. */
	function wpmcp_test_asgaros_forum( array $row = [] ): int {
		global $wpdb;
		static $n = 0;
		++$n;
		$wpdb->insert( $wpdb->prefix . 'forum_forums', $row + [ 'name' => 'Board ' . $n, 'slug' => 'board-' . $n, 'description' => 'About ' . $n ] );
		return (int) $wpdb->insert_id;
	}

	/**
	 * An Asgaros topic with its first post and the given number of replies;
	 * returns [topic id, post ids].
	 *
	 * @return array{0: int, 1: int[]}
	 */
	function wpmcp_test_asgaros_topic( int $forum_id, array $row = [], int $replies = 1 ): array {
		global $wpdb;
		$p = $wpdb->prefix;
		$wpdb->insert( $p . 'forum_topics', $row + [ 'parent_id' => $forum_id, 'author_id' => 1, 'name' => 'A thread', 'slug' => 'a-thread-' . wp_generate_password( 4, false ) ] );
		$topic = (int) $wpdb->insert_id;
		$ids   = [];
		for ( $i = 0; $i <= $replies; $i++ ) {
			$wpdb->insert( $p . 'forum_posts', [
				'text'      => 0 === $i ? 'Opening post' : 'Reply ' . $i,
				'parent_id' => $topic,
				'forum_id'  => $forum_id,
				'date'      => sprintf( '2026-11-01 10:%02d:00', $i ),
				'author_id' => 1,
				'uploads'   => 'a:0:{}',
			] );
			$ids[] = (int) $wpdb->insert_id;
		}
		return [ $topic, $ids ];
	}

	/**
	 * Every row of the forum tables, comparable before and after a rollback.
	 *
	 * @return array<string, array<int, array<string, string|null>>>
	 */
	function wpmcp_test_forum_dump(): array {
		global $wpdb;
		$out = [];
		foreach ( wpmcp_test_forum_tables() as $table ) {
			$out[ $table ] = $wpdb->get_results( 'SELECT * FROM ' . $wpdb->prefix . $table . ' ORDER BY 1 ASC', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed test table names.
		}
		return $out;
	}
}
