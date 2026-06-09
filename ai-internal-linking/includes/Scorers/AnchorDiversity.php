<?php
/**
 * Anchor-text diversity + over-optimization report. Healthy internal linking uses
 * exact-match anchors sparingly and partial/branded/generic frequently; a target
 * with too high an exact-match ratio risks over-optimization.
 *
 * @package AILinking
 */

namespace AILinking\Scorers;

use AILinking\Support\Tables;

defined( 'ABSPATH' ) || exit;

class AnchorDiversity {

	const EXACT_RATIO_FLAG = 0.40;
	const MIN_INBOUND      = 3;

	/**
	 * Anchor-type distribution across content links.
	 *
	 * @return array{counts:array<string,int>,total:int}
	 */
	public static function distribution() {
		global $wpdb;
		$graph = Tables::link_graph();
		$rows  = $wpdb->get_results(
			"SELECT anchor_type, COUNT(*) AS c FROM {$graph} WHERE location='content' AND target_post_id>0 GROUP BY anchor_type", // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);
		$counts = array();
		$total  = 0;
		foreach ( (array) $rows as $r ) {
			$counts[ $r['anchor_type'] ] = (int) $r['c'];
			$total                      += (int) $r['c'];
		}
		return array( 'counts' => $counts, 'total' => $total );
	}

	/**
	 * @return int Count of over-optimized targets.
	 */
	public static function over_count() {
		global $wpdb;
		$graph = Tables::link_graph();
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM (
				   SELECT target_post_id, COUNT(*) total, SUM( CASE WHEN anchor_type='exact' THEN 1 ELSE 0 END ) exacts
				   FROM {$graph} WHERE location='content' AND target_post_id>0
				   GROUP BY target_post_id
				   HAVING total >= %d AND ( exacts / total ) > %f
				 ) t", // phpcs:ignore WordPress.DB.PreparedSQL
				self::MIN_INBOUND,
				self::EXACT_RATIO_FLAG
			)
		);
	}

	/**
	 * Over-optimized targets with their exact-match ratio.
	 *
	 * @param int $limit Rows.
	 * @return array[]
	 */
	public static function over_optimized( $limit = 50 ) {
		global $wpdb;
		$graph = Tables::link_graph();
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT target_post_id, COUNT(*) total, SUM( CASE WHEN anchor_type='exact' THEN 1 ELSE 0 END ) exacts
				 FROM {$graph} WHERE location='content' AND target_post_id>0
				 GROUP BY target_post_id
				 HAVING total >= %d AND ( exacts / total ) > %f
				 ORDER BY ( exacts / total ) DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL
				self::MIN_INBOUND,
				self::EXACT_RATIO_FLAG,
				$limit
			),
			ARRAY_A
		);
	}
}
