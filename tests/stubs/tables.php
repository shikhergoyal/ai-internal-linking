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
}
