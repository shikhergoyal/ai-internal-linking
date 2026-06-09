<?php
/**
 * Centralised custom-table names.
 *
 * @package AILinking
 */

namespace AILinking\Support;

defined( 'ABSPATH' ) || exit;

class Tables {

	/**
	 * Fully-qualified table name for a logical table key.
	 *
	 * @param string $key One of: index, link_graph, suggestions, ledger, tfidf, jobs.
	 * @return string
	 */
	public static function name( $key ) {
		global $wpdb;
		return $wpdb->prefix . 'ailinking_' . $key;
	}

	public static function index() {
		return self::name( 'index' );
	}

	public static function link_graph() {
		return self::name( 'link_graph' );
	}

	public static function suggestions() {
		return self::name( 'suggestions' );
	}

	public static function ledger() {
		return self::name( 'ledger' );
	}

	public static function tfidf() {
		return self::name( 'tfidf' );
	}

	public static function jobs() {
		return self::name( 'jobs' );
	}

	public static function provider_keys() {
		return self::name( 'provider_keys' );
	}

	public static function spend_log() {
		return self::name( 'spend_log' );
	}

	public static function keywords() {
		return self::name( 'keywords' );
	}

	public static function keyword_map() {
		return self::name( 'keyword_map' );
	}

	public static function embeddings() {
		return self::name( 'embeddings' );
	}

	/**
	 * All logical table keys (used by install/uninstall).
	 *
	 * @return string[]
	 */
	public static function all_keys() {
		return array( 'index', 'link_graph', 'suggestions', 'ledger', 'tfidf', 'jobs', 'provider_keys', 'spend_log', 'keywords', 'keyword_map', 'embeddings' );
	}
}
