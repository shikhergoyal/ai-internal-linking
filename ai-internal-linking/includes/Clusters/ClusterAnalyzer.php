<?php
/**
 * Hub-and-spoke / topical-authority analysis. Computes intra-cluster in-degree,
 * detects "flat" clusters (no clear authoritative hub), measures spoke→hub
 * coverage, and scores cluster authority. Flat clusters risk being cited by no
 * answer engine, so they are flagged with concrete fixes.
 *
 * @package AILinking
 */

namespace AILinking\Clusters;

use AILinking\Support\Tables;

defined( 'ABSPATH' ) || exit;

class ClusterAnalyzer {

	/**
	 * Detect whether a set of in-degrees lacks a clear hub (pure; unit-tested).
	 *
	 * @param int[] $indegrees In-degree of each member.
	 * @return array{0:bool,1:string} [ is_flat, severity ]
	 */
	public static function flat_detect( array $indegrees ) {
		$n = count( $indegrees );
		if ( $n < 2 ) {
			return array( false, '' );
		}
		$sum  = array_sum( $indegrees );
		$mean = $sum / $n;
		$max  = max( $indegrees );

		if ( $mean <= 0 || $max <= 0 ) {
			return array( true, 'high' ); // no internal links at all.
		}
		$ratio = $max / $mean;
		if ( $ratio < 1.5 ) {
			return array( true, 'high' );
		}
		if ( $ratio < 2.5 ) {
			return array( true, 'medium' );
		}
		return array( false, '' );
	}

	/**
	 * Analyze one cluster and cache its stats.
	 *
	 * @param int $cluster_id Cluster id.
	 * @return bool
	 */
	public static function analyze( $cluster_id ) {
		global $wpdb;
		$cluster = ClusterRepository::get( $cluster_id );
		if ( ! $cluster ) {
			return false;
		}
		$members = ClusterRepository::members( $cluster_id );
		if ( empty( $members ) ) {
			ClusterRepository::update_stats( $cluster_id, 0, 1, 'high', 0 );
			return true;
		}

		$pillar    = (int) $cluster['pillar_post_id'];
		$ids       = array();
		foreach ( $members as $m ) {
			$ids[] = (int) $m['post_id'];
		}
		$id_set = array_fill_keys( $ids, true );

		// Intra-cluster content edges.
		$graph = Tables::link_graph();
		$ph    = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$args  = array_merge( $ids, $ids );
		$edges = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT source_post_id, target_post_id FROM {$graph}
				 WHERE location='content' AND target_post_id>0
				   AND source_post_id IN ($ph) AND target_post_id IN ($ph)", // phpcs:ignore WordPress.DB.PreparedSQL
				$args
			),
			ARRAY_A
		);

		$in_degree    = array_fill_keys( $ids, 0 );
		$links_to_hub = array_fill_keys( $ids, 0 );
		foreach ( (array) $edges as $e ) {
			$s = (int) $e['source_post_id'];
			$t = (int) $e['target_post_id'];
			if ( isset( $in_degree[ $t ] ) ) {
				$in_degree[ $t ]++;
			}
			if ( $pillar > 0 && $t === $pillar && isset( $links_to_hub[ $s ] ) ) {
				$links_to_hub[ $s ] = 1;
			}
		}

		list( $is_flat, $severity ) = self::flat_detect( array_values( $in_degree ) );

		// Persist per-member stats.
		foreach ( $members as $m ) {
			$pid = (int) $m['post_id'];
			ClusterRepository::update_member_stats( (int) $m['id'], $in_degree[ $pid ], $links_to_hub[ $pid ] );
		}

		// Authority: spoke→hub coverage + hub centrality.
		$spoke_count = max( 0, count( $ids ) - 1 );
		$linked      = 0;
		foreach ( $ids as $pid ) {
			if ( $pid !== $pillar && ! empty( $links_to_hub[ $pid ] ) ) {
				$linked++;
			}
		}
		$coverage   = $spoke_count > 0 ? ( $linked / $spoke_count ) : 0.0;
		$hub_in     = $pillar > 0 && isset( $in_degree[ $pillar ] ) ? $in_degree[ $pillar ] : 0;
		$hub_factor = $spoke_count > 0 ? min( 1.0, $hub_in / $spoke_count ) : 0.0;
		$authority  = round( ( $coverage * 60 ) + ( $hub_factor * 40 ), 1 );

		ClusterRepository::update_stats( $cluster_id, $authority, $is_flat ? 1 : 0, $severity, count( $ids ) );
		return true;
	}

	/**
	 * Analyze all clusters.
	 *
	 * @return int Count analyzed.
	 */
	public static function analyze_all() {
		$n = 0;
		foreach ( ClusterRepository::list_clusters() as $c ) {
			if ( self::analyze( (int) $c['cluster_id'] ) ) {
				$n++;
			}
		}
		return $n;
	}

	/**
	 * Human fix hint for a cluster's current state.
	 *
	 * @param array $cluster Cluster row.
	 * @return string
	 */
	public static function fix_hint( array $cluster ) {
		if ( (int) $cluster['pillar_post_id'] <= 0 ) {
			return __( 'Set a pillar (hub) page for this cluster.', 'ai-internal-linking' );
		}
		if ( ! empty( $cluster['is_flat'] ) ) {
			return __( 'Flat cluster: no clear hub. Add links from spokes to the pillar and from the pillar to key spokes.', 'ai-internal-linking' );
		}
		if ( (float) $cluster['authority_score'] < 60 ) {
			return __( 'Improve spoke→pillar coverage: ensure each supporting post links to the pillar with a descriptive anchor.', 'ai-internal-linking' );
		}
		return __( 'Healthy hub-and-spoke structure.', 'ai-internal-linking' );
	}
}
