<?php
/**
 * Database schema. Creates custom tables via dbDelta() and tracks a DB version
 * separate from the plugin version so migrations are explicit.
 *
 * dbDelta formatting rules observed: two spaces after PRIMARY KEY, one column /
 * key per line, no IF NOT EXISTS, full prefixed table name, charset from
 * $wpdb->get_charset_collate(). TEXT/LONGTEXT columns never carry a DEFAULT
 * (MySQL forbids it before 8.0.13).
 *
 * @package AILinking
 */

namespace AILinking\Install;

use AILinking\Support\Tables;

defined( 'ABSPATH' ) || exit;

class Schema {

	const DB_VERSION_OPTION = 'ailinking_db_version';

	/**
	 * Create or upgrade all tables, then stamp the DB version.
	 */
	public static function install() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		foreach ( self::statements( $charset_collate ) as $sql ) {
			dbDelta( $sql );
		}

		update_option( self::DB_VERSION_OPTION, AILINKING_DB_VERSION, false );
	}

	/**
	 * Run pending migrations when the stored DB version is behind, OR when the
	 * core table is missing (e.g. tables dropped, or plugin updated without a
	 * fresh activation).
	 */
	public static function maybe_upgrade() {
		$installed = get_option( self::DB_VERSION_OPTION, '0' );
		if ( version_compare( $installed, AILINKING_DB_VERSION, '<' ) ) {
			self::install();
			return;
		}
		// Recovery: recreate tables if they went missing (admin only, to avoid a
		// per-request query on the front end).
		if ( is_admin() && ! self::table_exists( Tables::index() ) ) {
			self::install();
		}
	}

	/**
	 * Create the tables now if the core table is missing. Cheap safety net before
	 * a (re)index so writes never fail silently on a half-installed site.
	 */
	public static function ensure_installed() {
		if ( ! self::table_exists( Tables::index() ) ) {
			self::install();
		}
	}

	/**
	 * Reset the SCAN data (index, suggestions, link graph, keywords, embeddings,
	 * jobs) and clear progress/caches/locks, so the next scan starts from scratch.
	 * Keeps the table schema, does NOT touch any WordPress posts, and deliberately
	 * PRESERVES your configuration: saved API keys (provider_keys) and their spend
	 * history (spend_log), plus all settings and the Search Console connection
	 * (which live in options, not these tables). Links already inserted into
	 * content remain in the posts.
	 */
	public static function reset_data() {
		global $wpdb;
		self::ensure_installed();

		// Configuration tables that must survive a data reset (your API keys and
		// their usage/spend history). Everything else is scan data and is cleared.
		$keep = array( 'provider_keys', 'spend_log' );

		foreach ( Tables::all_keys() as $key ) {
			if ( in_array( $key, $keep, true ) ) {
				continue;
			}
			$table = Tables::name( $key );
			$wpdb->query( "DELETE FROM `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL
		}

		// Clear job progress, the audit cache, and any locks. The monthly spend
		// window is left intact so the cap keeps tracking against your keys.
		delete_option( 'ailinking_progress_index' );
		delete_option( 'ailinking_progress_suggest' );
		delete_option( 'ailinking_progress_embed' );
		delete_transient( 'ailinking_audit_summary' );
		delete_transient( 'ailinking_lock_index' );
		delete_transient( 'ailinking_lock_suggest' );
		delete_transient( 'ailinking_lock_embed' );
	}

	/**
	 * Whether a table exists.
	 *
	 * @param string $table Full table name.
	 * @return bool
	 */
	public static function table_exists( $table ) {
		global $wpdb;
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	/**
	 * All CREATE TABLE statements.
	 *
	 * @param string $charset_collate Charset/collation clause.
	 * @return string[]
	 */
	private static function statements( $charset_collate ) {
		$index       = Tables::index();
		$link_graph  = Tables::link_graph();
		$suggestions = Tables::suggestions();
		$ledger      = Tables::ledger();
		$tfidf       = Tables::tfidf();
		$jobs        = Tables::jobs();
		$provider_keys = Tables::provider_keys();
		$spend_log     = Tables::spend_log();
		$keywords      = Tables::keywords();
		$keyword_map   = Tables::keyword_map();
		$embeddings    = Tables::embeddings();

		$statements = array();

		$statements[] = "CREATE TABLE {$index} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL,
			post_type varchar(20) NOT NULL DEFAULT 'post',
			post_status varchar(20) NOT NULL DEFAULT 'publish',
			title text NULL,
			url varchar(2048) NOT NULL DEFAULT '',
			content_system varchar(20) NOT NULL DEFAULT 'classic',
			parsed_text longtext NULL,
			content_hash char(32) NOT NULL DEFAULT '',
			word_count int unsigned NOT NULL DEFAULT 0,
			lang_code varchar(10) NOT NULL DEFAULT 'und',
			lang_source varchar(10) NOT NULL DEFAULT 'none',
			is_woo_product tinyint(1) NOT NULL DEFAULT 0,
			is_woo_system tinyint(1) NOT NULL DEFAULT 0,
			is_excluded tinyint(1) NOT NULL DEFAULT 0,
			exclude_reason varchar(40) NOT NULL DEFAULT '',
			click_depth smallint NOT NULL DEFAULT -1,
			pagerank_score float NOT NULL DEFAULT 0,
			write_safety varchar(12) NOT NULL DEFAULT 'auto',
			last_modified datetime NULL,
			indexed_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY post_id (post_id),
			KEY lang_code (lang_code),
			KEY post_type (post_type),
			KEY status_excluded (post_status,is_excluded),
			KEY content_hash (content_hash)
		) {$charset_collate};";

		$statements[] = "CREATE TABLE {$link_graph} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source_post_id bigint(20) unsigned NOT NULL,
			target_post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			target_url varchar(2048) NOT NULL DEFAULT '',
			target_url_norm varchar(512) NOT NULL DEFAULT '',
			anchor_text text NULL,
			anchor_type varchar(12) NOT NULL DEFAULT 'partial',
			location varchar(12) NOT NULL DEFAULT 'content',
			is_first_link tinyint(1) NOT NULL DEFAULT 0,
			origin varchar(12) NOT NULL DEFAULT 'discovered',
			suggestion_id bigint(20) unsigned NOT NULL DEFAULT 0,
			is_broken tinyint(1) NOT NULL DEFAULT 0,
			discovered_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY target_lookup (target_post_id,location),
			KEY source_lookup (source_post_id,location),
			KEY target_url_norm (target_url_norm),
			KEY origin (origin)
		) {$charset_collate};";

		$statements[] = "CREATE TABLE {$suggestions} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source_post_id bigint(20) unsigned NOT NULL,
			target_post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			target_url varchar(2048) NOT NULL DEFAULT '',
			anchor_text varchar(255) NOT NULL DEFAULT '',
			suggested_context text NULL,
			field_ref varchar(191) NOT NULL DEFAULT '',
			relevance_score float NOT NULL DEFAULT 0,
			naturalness_score float NOT NULL DEFAULT 0,
			confidence_score float NOT NULL DEFAULT 0,
			bridge_priority float NOT NULL DEFAULT 0,
			type varchar(20) NOT NULL DEFAULT 'outbound',
			engine varchar(20) NOT NULL DEFAULT 'tfidf',
			lang_code varchar(10) NOT NULL DEFAULT 'und',
			status varchar(20) NOT NULL DEFAULT 'pending',
			reject_reason varchar(40) NOT NULL DEFAULT '',
			applied_ledger_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY source_status (source_post_id,status),
			KEY target_post_id (target_post_id),
			KEY status_created (status,created_at),
			KEY status_score (status,confidence_score),
			KEY type (type)
		) {$charset_collate};";

		$statements[] = "CREATE TABLE {$ledger} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			suggestion_id bigint(20) unsigned NOT NULL DEFAULT 0,
			post_id bigint(20) unsigned NOT NULL,
			content_system varchar(20) NOT NULL DEFAULT 'classic',
			storage_target varchar(20) NOT NULL DEFAULT 'post_content',
			meta_key varchar(191) NOT NULL DEFAULT '',
			field_ref varchar(191) NOT NULL DEFAULT '',
			revision_id_before bigint(20) unsigned NOT NULL DEFAULT 0,
			value_before longtext NULL,
			value_after_hash char(32) NOT NULL DEFAULT '',
			inserted_html text NULL,
			target_url varchar(2048) NOT NULL DEFAULT '',
			data_attr_id varchar(64) NOT NULL DEFAULT '',
			applied_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			removed_at datetime NULL,
			PRIMARY KEY  (id),
			KEY post_active (post_id,removed_at),
			KEY suggestion_id (suggestion_id),
			KEY data_attr_id (data_attr_id)
		) {$charset_collate};";

		$statements[] = "CREATE TABLE {$tfidf} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL,
			term varchar(80) NOT NULL DEFAULT '',
			tf int unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY post_term (post_id,term),
			KEY term (term)
		) {$charset_collate};";

		$statements[] = "CREATE TABLE {$jobs} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			job_type varchar(30) NOT NULL,
			status varchar(16) NOT NULL DEFAULT 'queued',
			total_items int unsigned NOT NULL DEFAULT 0,
			processed_items int unsigned NOT NULL DEFAULT 0,
			cursor_offset bigint(20) unsigned NOT NULL DEFAULT 0,
			args longtext NULL,
			last_error text NULL,
			started_at datetime NULL,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY type_status (job_type,status)
		) {$charset_collate};";

		$statements[] = "CREATE TABLE {$provider_keys} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			provider varchar(30) NOT NULL DEFAULT '',
			label varchar(100) NOT NULL DEFAULT '',
			base_url varchar(255) NOT NULL DEFAULT '',
			model varchar(100) NOT NULL DEFAULT '',
			capability varchar(12) NOT NULL DEFAULT 'chat',
			key_cipher text NULL,
			key_last4 varchar(8) NOT NULL DEFAULT '',
			extra_config text NULL,
			priority int NOT NULL DEFAULT 100,
			enabled tinyint(1) NOT NULL DEFAULT 1,
			state varchar(16) NOT NULL DEFAULT 'active',
			cooldown_until datetime NULL,
			request_count bigint(20) unsigned NOT NULL DEFAULT 0,
			error_count int unsigned NOT NULL DEFAULT 0,
			last_error varchar(255) NOT NULL DEFAULT '',
			est_spend_cents bigint(20) unsigned NOT NULL DEFAULT 0,
			last_used_at datetime NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY provider_state (provider,state),
			KEY provider_cap (provider,capability,enabled)
		) {$charset_collate};";

		$statements[] = "CREATE TABLE {$spend_log} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			key_id bigint(20) unsigned NOT NULL DEFAULT 0,
			provider varchar(30) NOT NULL DEFAULT '',
			model varchar(100) NOT NULL DEFAULT '',
			operation varchar(20) NOT NULL DEFAULT 'chat',
			tokens_in int unsigned NOT NULL DEFAULT 0,
			tokens_out int unsigned NOT NULL DEFAULT 0,
			est_cost decimal(12,6) NOT NULL DEFAULT 0,
			http_status smallint unsigned NOT NULL DEFAULT 0,
			error_type varchar(30) NOT NULL DEFAULT '',
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY key_created (key_id,created_at),
			KEY provider_created (provider,created_at)
		) {$charset_collate};";

		$statements[] = "CREATE TABLE {$keywords} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			keyword varchar(500) NOT NULL DEFAULT '',
			keyword_norm varchar(191) NOT NULL DEFAULT '',
			source varchar(12) NOT NULL DEFAULT 'csv',
			page_url varchar(2048) NOT NULL DEFAULT '',
			post_id bigint(20) unsigned NULL DEFAULT NULL,
			clicks int unsigned NOT NULL DEFAULT 0,
			impressions int unsigned NOT NULL DEFAULT 0,
			position decimal(6,2) NOT NULL DEFAULT 0,
			ctr decimal(6,4) NOT NULL DEFAULT 0,
			is_striking tinyint(1) NOT NULL DEFAULT 0,
			opportunity_score float NOT NULL DEFAULT 0,
			data_date date NULL,
			imported_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY position (position),
			KEY striking (is_striking,opportunity_score),
			KEY post_id (post_id),
			KEY keyword_norm (keyword_norm),
			KEY source (source)
		) {$charset_collate};";

		$statements[] = "CREATE TABLE {$keyword_map} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			keyword_id bigint(20) unsigned NOT NULL DEFAULT 0,
			target_post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			target_url varchar(2048) NOT NULL DEFAULT '',
			map_type varchar(12) NOT NULL DEFAULT 'auto',
			confidence float NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY kw_target (keyword_id,target_post_id),
			KEY target_post_id (target_post_id)
		) {$charset_collate};";

		$statements[] = "CREATE TABLE {$embeddings} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL,
			provider varchar(30) NOT NULL DEFAULT '',
			model varchar(100) NOT NULL DEFAULT '',
			dims int unsigned NOT NULL DEFAULT 0,
			vector longtext NULL,
			norm float NOT NULL DEFAULT 0,
			content_hash char(32) NOT NULL DEFAULT '',
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY post_id (post_id),
			KEY model (model)
		) {$charset_collate};";

		return $statements;
	}
}
