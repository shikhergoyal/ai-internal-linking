<?php
/**
 * Broken internal-link detection over the discovered link graph. Flags edges whose
 * internal target is unresolved (target_post_id = 0) or points at a post that is
 * no longer published/indexed.
 *
 * @package AILinking
 */

namespace AILinking\LinkGraph;

use AILinking\Support\Tables;

defined( 'ABSPATH' ) || exit;

class BrokenLinks {

	/**
	 * Recompute the is_broken flag across content edges.
	 *
	 * @return int Number of broken edges.
	 */
	public static function scan() {
		global $wpdb;
		$graph = Tables::link_graph();
		$index = Tables::index();

		// Reset.
		$wpdb->query( "UPDATE {$graph} SET is_broken = 0 WHERE location='content'" ); // phpcs:ignore WordPress.DB.PreparedSQL

		// Unresolved internal links.
		$wpdb->query( "UPDATE {$graph} SET is_broken = 1 WHERE location='content' AND target_post_id = 0" ); // phpcs:ignore WordPress.DB.PreparedSQL

		// Resolved but the target is no longer published/indexed.
		$wpdb->query(
			"UPDATE {$graph} g
			 LEFT JOIN {$index} i ON i.post_id = g.target_post_id AND i.post_status = 'publish'
			 SET g.is_broken = 1
			 WHERE g.location='content' AND g.target_post_id > 0 AND i.post_id IS NULL" // phpcs:ignore WordPress.DB.PreparedSQL
		);

		return self::count();
	}

	/**
	 * @return int Broken edge count.
	 */
	public static function count() {
		global $wpdb;
		$graph = Tables::link_graph();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$graph} WHERE location='content' AND is_broken=1" ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Broken-edge list for the dashboard.
	 *
	 * @param int $limit Rows.
	 * @return array[]
	 */
	public static function listing( $limit = 50 ) {
		global $wpdb;
		$graph = Tables::link_graph();
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT source_post_id, target_url, anchor_text FROM {$graph}
				 WHERE location='content' AND is_broken=1 ORDER BY source_post_id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL
				$limit
			),
			ARRAY_A
		);
	}
}
