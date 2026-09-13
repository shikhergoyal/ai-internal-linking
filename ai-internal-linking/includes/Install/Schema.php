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
use AILinking\Suggestions\Naturalness;
use AILinking\Jobs\ProgressStore;

defined( 'ABSPATH' ) || exit;

class Schema {

	const DB_VERSION_OPTION = 'ailinking_db_version';

	/** Counts failed upgrade attempts, so a blocked ALTER cannot loop for ever. */
	const UPGRADE_TRIES_OPTION = 'ailinking_upgrade_tries';

	/** Records which columns are missing when an upgrade gives up. */
	const UPGRADE_NOTICE_OPTION = 'ailinking_upgrade_incomplete';

	/**
	 * Plugin version whose schema has been verified present. Autoloaded, so the
	 * common case costs no query: without it, a version stamped as migrated
	 * while a column is missing is a state nothing ever corrects.
	 */
	const SCHEMA_OK_OPTION = 'ailinking_schema_verified';

	/** Attempts before recording the version anyway and reporting the problem. */
	const MAX_UPGRADE_TRIES = 3;

	/**
	 * Create or upgrade all tables, then stamp the DB version.
	 */
	public static function install() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		// Must precede dbDelta: it is about to add a unique key to the keywords
		// table, and MySQL refuses that outright if duplicates are already there.
		self::prepare_keywords_for_unique_key();

		foreach ( self::statements( $charset_collate ) as $sql ) {
			dbDelta( $sql );
		}

		self::drop_retired_tables();

		// Only record the new version once the tables really carry what this
		// version asks for. Stamping unconditionally is a trap: if dbDelta runs
		// against a half-updated copy of the plugin — which happens when files
		// are uploaded one by one, since the main file carrying the new version
		// number can land before the schema file — the migration is recorded as
		// done and never retried, leaving a column permanently missing and every
		// query against it failing quietly. Leaving the version alone means the
		// next request simply tries again.
		$missing = self::missing_columns();
		if ( $missing ) {
			// Do not retry for ever. If the host refuses the ALTER — no
			// permission, a locked table — leaving the version unstamped means
			// dbDelta runs again on every single admin request, which is a
			// permanently slow admin and a worse problem than the missing
			// column. After a few attempts, record the version anyway and leave
			// a note saying what is missing.
			$tries = (int) get_option( self::UPGRADE_TRIES_OPTION, 0 ) + 1;
			if ( $tries < self::MAX_UPGRADE_TRIES ) {
				update_option( self::UPGRADE_TRIES_OPTION, $tries, false );
				// Deliberately not marking the schema verified: the next
				// request is meant to come back and try again.
				return;
			}
			update_option( self::UPGRADE_NOTICE_OPTION, $missing, false );
		} else {
			delete_option( self::UPGRADE_NOTICE_OPTION );
		}

		// Either the schema is right, or it is wrong in a way retrying will not
		// fix. Both are settled states; stop paying to re-check them.
		update_option( self::SCHEMA_OK_OPTION, AILINKING_VERSION, true );

		self::rescore_suggestions();

		delete_option( self::UPGRADE_TRIES_OPTION );
		update_option( self::DB_VERSION_OPTION, AILINKING_DB_VERSION, false );
	}

	/**
	 * Recompute naturalness and confidence on suggestions already stored.
	 *
	 * Both are derived from the anchor text and the relevance score, and both of
	 * those are stored, so this is exact rather than an estimate and it touches
	 * no content. Without it a reviewer would be looking at two different scales
	 * side by side until the next full scan: rows scored before 0.21.0 carry a
	 * naturalness that was largely a restatement of relevance.
	 *
	 * Idempotent — running it again produces the same numbers.
	 *
	 * @return void
	 */
	private static function rescore_suggestions() {
		global $wpdb;
		$table = Tables::suggestions();
		if ( ! self::table_exists( $table ) ) {
			return;
		}

		$rows = $wpdb->get_results( "SELECT id, anchor_text, relevance_score FROM {$table}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		foreach ( (array) $rows as $r ) {
			$relevance   = (float) $r['relevance_score'];
			$naturalness = Naturalness::score( (string) $r['anchor_text'] );
			$wpdb->update(
				$table,
				array(
					'naturalness_score' => $naturalness,
					'confidence_score'  => Naturalness::confidence( $relevance, $naturalness ),
				),
				array( 'id' => (int) $r['id'] ),
				array( '%f', '%f' ),
				array( '%d' )
			);
		}
	}

	/**
	 * Everything this version declares that the database does not have, as
	 * "table.column" (or the table itself when it is absent altogether).
	 *
	 * Every declared table is checked, not just the index. Checking one table
	 * and stamping the version on the strength of it is how 1.9.0 came to be
	 * recorded as migrated on a site whose link_graph never got target_kind:
	 * the index table was perfect, so the check passed and said nothing about
	 * the table that had actually changed.
	 *
	 * @return string[]
	 */
	public static function missing_columns() {
		global $wpdb;
		$missing = array();

		foreach ( self::declared_columns() as $table => $columns ) {
			if ( ! self::table_exists( $table ) ) {
				$missing[] = $table . ' (the table itself)';
				continue;
			}

			$have = array();
			foreach ( (array) $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`" ) as $c ) { // phpcs:ignore WordPress.DB.PreparedSQL
				$have[ strtolower( (string) $c ) ] = true;
			}

			foreach ( $columns as $column ) {
				if ( ! isset( $have[ $column ] ) ) {
					$missing[] = $table . '.' . $column;
				}
			}
		}

		return $missing;
	}

	/**
	 * Make the keywords table fit to carry a unique key.
	 *
	 * Two things block it. Rows imported before 0.20.0 can have a NULL post_id,
	 * and MySQL treats NULLs as distinct, so unmapped phrases would go on
	 * duplicating even with the key in place. And any duplicate already stored
	 * makes the ALTER fail outright. One real site had 107 duplicated groups,
	 * several repeated eight times, each one eating a slot of the 500-phrase
	 * pool the engine actually considers.
	 *
	 * @return void
	 */
	private static function prepare_keywords_for_unique_key() {
		global $wpdb;
		$table = Tables::keywords();
		if ( ! self::table_exists( $table ) ) {
			return;
		}

		$wpdb->query( "UPDATE {$table} SET post_id = 0 WHERE post_id IS NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL

		// Keep the earliest row of each group; it carries the original import date.
		$wpdb->query(
			"DELETE a FROM {$table} a
			 INNER JOIN {$table} b
			 WHERE a.id > b.id
			   AND a.keyword_norm = b.keyword_norm
			   AND a.post_id = b.post_id
			   AND a.source = b.source" // phpcs:ignore WordPress.DB.PreparedSQL
		);
	}

	/**
	 * Whether the database carries everything this version declares.
	 *
	 * @return bool
	 */
	private static function schema_matches() {
		return array() === self::missing_columns();
	}

	/**
	 * Every table this version declares, mapped to its column names, read from
	 * the CREATE TABLE statements themselves so the declaration and the check
	 * can never drift apart.
	 *
	 * @return array<string,string[]> Table name => column names.
	 */
	public static function declared_columns() {
		global $wpdb;
		$out = array();

		foreach ( self::statements( $wpdb->get_charset_collate() ) as $sql ) {
			$table   = '';
			$columns = array();

			foreach ( preg_split( '/\r\n|\r|\n/', $sql ) as $line ) {
				$line = trim( $line );
				if ( '' === $line || 0 === strpos( $line, ')' ) ) {
					continue;
				}
				if ( 0 === stripos( $line, 'CREATE TABLE' ) ) {
					if ( preg_match( '/^CREATE\s+TABLE\s+`?([a-z0-9_]+)`?/i', $line, $m ) ) {
						$table = $m[1];
					}
					continue;
				}
				// Field lines only: keys are not columns.
				if ( preg_match( '/^(PRIMARY\s+KEY|UNIQUE\s+KEY|KEY|INDEX)\b/i', $line ) ) {
					continue;
				}
				if ( preg_match( '/^`?([a-z0-9_]+)`?\s+/i', $line, $m ) ) {
					$columns[] = strtolower( $m[1] );
				}
			}

			if ( '' !== $table && $columns ) {
				$out[ $table ] = $columns;
			}
		}

		return $out;
	}

	/**
	 * Column names the index table is declared with.
	 *
	 * @return string[]
	 */
	public static function index_columns() {
		$all   = self::declared_columns();
		$table = Tables::index();
		return isset( $all[ $table ] ) ? $all[ $table ] : array();
	}

	/**
	 * Drop tables belonging to features that no longer exist, so an upgraded
	 * site does not carry dead data forever.
	 *
	 * - clusters / cluster_members: retired in 0.11.0, which only dropped them
	 *   on uninstall, so every site upgraded in place still carries them.
	 * - embeddings: retired in 0.14.0 with the semantic re-ranker.
	 * - keyword_map: created since the first release and never read by anything.
	 *   The keyword engine joins the keywords table to the index directly.
	 *
	 * DROP IF EXISTS is idempotent, so listing a table that is already gone
	 * costs nothing and keeps the record of what was retired.
	 */
	private static function drop_retired_tables() {
		global $wpdb;
		$retired = array( 'embeddings', 'clusters', 'cluster_members', 'keyword_map' );
		foreach ( $retired as $name ) {
			$table = $wpdb->prefix . 'ailinking_' . $name;
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
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
		// The version says the migration is done. That is not the same as it
		// having worked. dbDelta can fail to add a column, and a deploy that
		// uploads files one at a time can run this while the main file already
		// declares the new DB version and the schema file is still the old one
		// — which stamps the version against a migration that never ran. The
		// version test above then passes for ever and nothing tries again.
		//
		// The verified flag is autoloaded, so the settled case costs no query.
		if ( get_option( self::SCHEMA_OK_OPTION ) === AILINKING_VERSION ) {
			return;
		}
		if ( self::schema_matches() ) {
			update_option( self::SCHEMA_OK_OPTION, AILINKING_VERSION, true );
			return;
		}
		self::install();
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
	 * Reset the SCAN data (index, link graph, keywords, jobs, and every
	 * suggestion not yet applied) and clear progress/caches/locks, so the next
	 * scan starts from scratch. Keeps the table schema and does NOT touch any
	 * WordPress posts.
	 *
	 * Deliberately preserved:
	 *
	 * - Configuration: saved API keys (provider_keys) and their spend history
	 *   (spend_log), plus all settings and the Search Console connection, which
	 *   live in options rather than these tables.
	 * - The undo record for every link still sitting in the content: the active
	 *   ledger rows, and the applied suggestions that carry the Undo button.
	 *
	 * That second one is not scan data and is not ours to delete. This routine
	 * used to empty the ledger with everything else, which stranded every link
	 * the plugin had ever inserted: no per-link undo, no "Remove all inserted
	 * links", and an uninstall that could no longer find them to take out. They
	 * became permanent untracked edits in someone's posts. A reset exists so the
	 * site can be scanned again, and nothing about scanning again is helped by
	 * forgetting what was written into the content.
	 */
	public static function reset_data() {
		global $wpdb;
		self::ensure_installed();

		$ledger      = Tables::ledger();
		$suggestions = Tables::suggestions();

		// Ledger rows whose link has already been reverted are spent history:
		// they carry no undo, so they clear with the rest of the data.
		$wpdb->query( "DELETE FROM `{$ledger}` WHERE removed_at IS NOT NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL

		// Every suggestion goes except the applied ones still backed by a live
		// ledger row. The Undo button is rendered from the suggestion, not from
		// the ledger, so keeping the ledger alone would preserve the record with
		// no way left in the admin to reach it.
		$wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL
			"DELETE FROM `{$suggestions}`
			  WHERE status <> 'applied'
			     OR applied_ledger_id NOT IN ( SELECT id FROM `{$ledger}` WHERE removed_at IS NULL )"
		);

		// Tables handled above, or holding configuration that a reset keeps.
		$keep = array( 'provider_keys', 'spend_log', 'ledger', 'suggestions' );

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
		// Job locks live in options since 0.26.0. ProgressStore::release()
		// clears both homes, so a reset cannot leave a job wedged behind a lock
		// whose owner is gone.
		ProgressStore::release( 'index' );
		ProgressStore::release( 'suggest' );
		ProgressStore::release( 'embed' );
		// The site-wide word list is derived from the term table that was just
		// emptied, so keeping it would describe pages using a vocabulary that no
		// longer exists.
		delete_transient( 'ailinking_site_wide_terms' );
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
			summary text NULL,
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
			target_kind varchar(12) NOT NULL DEFAULT 'unknown',
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
			KEY provider_created (provider,created_at),
			KEY created (created_at)
		) {$charset_collate};";

		$statements[] = "CREATE TABLE {$keywords} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			keyword varchar(500) NOT NULL DEFAULT '',
			keyword_norm varchar(191) NOT NULL DEFAULT '',
			source varchar(12) NOT NULL DEFAULT 'csv',
			page_url varchar(2048) NOT NULL DEFAULT '',
			post_id bigint(20) unsigned NOT NULL DEFAULT 0,
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
			KEY source (source),
			UNIQUE KEY kw_page_source (keyword_norm,post_id,source)
		) {$charset_collate};";


		return $statements;
	}
}
