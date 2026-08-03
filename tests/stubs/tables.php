<?php
/**
 * Minimal stand-in for the table-name helper, so a class that talks to $wpdb
 * can be loaded by the standalone harness without WordPress.
 *
 * @package AILinking
 */

namespace AILinking\Support;

class Tables {

	/**
	 * Table name used by the fake $wpdb in the tests.
	 *
	 * @return string
	 */
	public static function tfidf() {
		return 'wp_ailinking_tfidf';
	}

	/**
	 * @return string
	 */
	public static function index() {
		return 'wp_ailinking_index';
	}

	/**
	 * @return string
	 */
	public static function keywords() {
		return 'wp_ailinking_keywords';
	}

	/**
	 * Any other table name Schema::statements() asks for.
	 *
	 * @param string $name Method name.
	 * @param array  $args Unused.
	 * @return string
	 */
	public static function __callStatic( $name, $args ) {
		return 'wp_ailinking_' . $name;
	}
}
