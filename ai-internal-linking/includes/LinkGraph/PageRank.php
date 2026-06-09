<?php
/**
 * Internal PageRank over the content link graph — a simple link-equity signal used
 * to prioritise linking toward authoritative pages. Iterative power method with
 * damping and dangling-node handling. Scores are cached on the index row.
 *
 * @package AILinking
 */

namespace AILinking\LinkGraph;

use AILinking\Support\Tables;
use AILinking\Support\Settings;

defined( 'ABSPATH' ) || exit;

class PageRank {

	const DAMPING    = 0.85;
	const ITERATIONS = 30;

	/**
	 * Pure power-iteration PageRank (unit-tested).
	 *
	 * @param array $adj     Adjacency: [ node => [target nodes...] ].
	 * @param array $nodes   All node ids to rank.
	 * @param float $damping Damping factor.
	 * @param int   $iters   Iterations.
	 * @return array<int,float> node => score (sums to ~1).
	 */
	public static function power_iteration( array $adj, array $nodes, $damping = self::DAMPING, $iters = self::ITERATIONS ) {
		$n = count( $nodes );
		if ( 0 === $n ) {
			return array();
		}

		$rank = array();
		foreach ( $nodes as $node ) {
			$rank[ $node ] = 1.0 / $n;
		}
		$node_set = array_fill_keys( $nodes, true );
		$base     = ( 1.0 - $damping ) / $n;

		for ( $it = 0; $it < $iters; $it++ ) {
			$next     = array();
			$dangling = 0.0;
			foreach ( $nodes as $node ) {
				$next[ $node ] = $base;
			}
			// Sum rank from dangling nodes (no eligible out-links) to redistribute.
			foreach ( $nodes as $node ) {
				$outs = isset( $adj[ $node ] ) ? array_values( array_filter( $adj[ $node ], function ( $t ) use ( $node_set ) { return isset( $node_set[ $t ] ); } ) ) : array();
				if ( empty( $outs ) ) {
					$dangling += $rank[ $node ];
					continue;
				}
				$share = $damping * $rank[ $node ] / count( $outs );
				foreach ( $outs as $t ) {
					$next[ $t ] += $share;
				}
			}
			$dist = $damping * $dangling / $n;
			foreach ( $nodes as $node ) {
				$next[ $node ] += $dist;
			}
			$rank = $next;
		}

		return $rank;
	}

	/**
	 * Compute PageRank over the live graph and cache it on the index.
	 *
	 * @return int Number of nodes ranked.
	 */
	public static function compute() {
		global $wpdb;
		$index = Tables::index();
		$graph = Tables::link_graph();

		$types = Settings::crawl_post_types();
		if ( empty( $types ) ) {
			return 0;
		}
		$ph    = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$nodes = array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare(
					"SELECT post_id FROM {$index} WHERE is_excluded=0 AND is_woo_system=0 AND post_status='publish' AND post_type IN ($ph)", // phpcs:ignore WordPress.DB.PreparedSQL
					$types
				)
			)
		);
		if ( empty( $nodes ) ) {
			return 0;
		}

		$edges = $wpdb->get_results(
			"SELECT source_post_id, target_post_id FROM {$graph} WHERE location='content' AND target_post_id>0", // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);
		$adj = array();
		foreach ( (array) $edges as $e ) {
			$adj[ (int) $e['source_post_id'] ][] = (int) $e['target_post_id'];
		}

		$rank = self::power_iteration( $adj, $nodes );

		// Persist (chunked CASE update). Normalise to 0..100 for readability.
		$max  = 0.0;
		foreach ( $rank as $v ) {
			$max = max( $max, $v );
		}
		$scale = $max > 0 ? ( 100.0 / $max ) : 0.0;

		$pairs = array();
		foreach ( $rank as $pid => $score ) {
			$pairs[] = array( (int) $pid, round( $score * $scale, 4 ) );
		}
		foreach ( array_chunk( $pairs, 100 ) as $chunk ) {
			$cases = '';
			$ids   = array();
			foreach ( $chunk as $p ) {
				$cases .= ' WHEN ' . (int) $p[0] . ' THEN ' . (float) $p[1];
				$ids[]  = (int) $p[0];
			}
			$in = implode( ',', $ids );
			$wpdb->query( "UPDATE {$index} SET pagerank_score = CASE post_id{$cases} END WHERE post_id IN ({$in})" ); // phpcs:ignore WordPress.DB.PreparedSQL
		}

		return count( $rank );
	}

	/**
	 * Top pages by PageRank.
	 *
	 * @param int $limit Rows.
	 * @return array[]
	 */
	public static function top( $limit = 25 ) {
		global $wpdb;
		$index = Tables::index();
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, title, url, pagerank_score FROM {$index}
				 WHERE is_excluded=0 AND post_status='publish'
				 ORDER BY pagerank_score DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL
				$limit
			),
			ARRAY_A
		);
	}
}
