<?php
/**
 * GEO (generative-engine optimization) readiness scoring. Estimates how well a
 * page is positioned to be understood and cited by AI answer engines, from
 * internal-structure signals the plugin already has: link equity (PageRank),
 * reachability (click depth), topic-cluster membership, orphan status, structured
 * data presence, freshness, and whether it front-loads a substantive answer.
 *
 * The score() function is pure and unit-tested; gather()/summary() collect signals.
 *
 * @package AILinking
 */

namespace AILinking\Scorers;

use AILinking\Support\Tables;
use AILinking\Support\Settings;
use AILinking\Detectors\SiteDetector;

defined( 'ABSPATH' ) || exit;

class GeoReadiness {

	const SCAN_LIMIT  = 1000;
	const FRESH_DAYS  = 365;

	/**
	 * Compute a GEO-readiness score (0-100) + concrete fixes from signals. (pure)
	 *
	 * @param array $s Signals: pagerank(0-100), depth(int,-1=unknown), has_schema(bool),
	 *                  fresh(bool), in_healthy_cluster(bool), orphan(bool), answer_ready(bool).
	 * @return array{score:int,fixes:string[]}
	 */
	public static function score( array $s ) {
		$score = 0.0;
		$fixes = array();

		$pr = isset( $s['pagerank'] ) ? (float) $s['pagerank'] : 0.0;
		$score += min( 30.0, $pr * 0.3 );
		if ( $pr < 10 ) {
			$fixes[] = __( 'Low internal link equity — add inbound links from related pages.', 'ai-internal-linking' );
		}

		$d = isset( $s['depth'] ) ? (int) $s['depth'] : -1;
		if ( $d >= 0 && $d <= 2 ) {
			$score += 20.0;
		} elseif ( $d >= 0 && $d <= 4 ) {
			$score += 10.0;
		} else {
			$fixes[] = __( 'Deep or unreachable from the homepage — link it closer to top-level pages.', 'ai-internal-linking' );
		}

		if ( ! empty( $s['has_schema'] ) ) {
			$score += 15.0;
		} else {
			$fixes[] = __( 'No structured data detected — add Article/FAQ/Breadcrumb schema.', 'ai-internal-linking' );
		}

		if ( ! empty( $s['fresh'] ) ) {
			$score += 10.0;
		} else {
			$fixes[] = __( 'Content looks stale — refresh it; recency helps AI engines cite it.', 'ai-internal-linking' );
		}

		if ( ! empty( $s['in_healthy_cluster'] ) ) {
			$score += 15.0;
		} else {
			$fixes[] = __( 'Not in a clear topic cluster — group it under a pillar and link spoke→hub.', 'ai-internal-linking' );
		}

		if ( ! empty( $s['answer_ready'] ) ) {
			$score += 10.0;
		} else {
			$fixes[] = __( 'Front-load a concise answer near the top so engines can extract it.', 'ai-internal-linking' );
		}

		if ( ! empty( $s['orphan'] ) ) {
			$score -= 10.0;
			$fixes[] = __( 'Orphan page (no inbound internal links) — add contextual links to it.', 'ai-internal-linking' );
		}

		$score = max( 0, min( 100, (int) round( $score ) ) );
		return array( 'score' => $score, 'fixes' => $fixes );
	}

	/**
	 * Gather + score a bounded sample of pages.
	 *
	 * @param int $limit Max rows to scan.
	 * @return array<int,array{post_id:int,title:string,url:string,score:int,fixes:string[]}>
	 */
	public static function gather( $limit = self::SCAN_LIMIT ) {
		global $wpdb;
		$index   = Tables::index();
		$graph   = Tables::link_graph();
		$cmem    = Tables::cluster_members();
		$clust   = Tables::clusters();

		$types = Settings::crawl_post_types();
		if ( empty( $types ) ) {
			return array();
		}
		$ph     = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$args   = $types;
		$args[] = (int) $limit;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT i.post_id, i.title, i.url, i.pagerank_score, i.click_depth, i.word_count, i.last_modified,
				  ( SELECT COUNT(*) FROM {$graph} g WHERE g.target_post_id = i.post_id AND g.location='content' ) AS inbound,
				  ( SELECT COUNT(*) FROM {$cmem} cm JOIN {$clust} c ON c.cluster_id = cm.cluster_id WHERE cm.post_id = i.post_id AND c.is_flat = 0 ) AS healthy_cluster
				 FROM {$index} i
				 WHERE i.is_excluded=0 AND i.is_woo_system=0 AND i.post_status='publish' AND i.post_type IN ($ph)
				 LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL
				$args
			),
			ARRAY_A
		);
		if ( empty( $rows ) ) {
			return array();
		}

		$has_schema_site = ( 'none' !== SiteDetector::seo_plugin() );
		$fresh_cutoff    = time() - ( self::FRESH_DAYS * DAY_IN_SECONDS );

		$out = array();
		foreach ( $rows as $r ) {
			$lm    = ! empty( $r['last_modified'] ) ? strtotime( $r['last_modified'] ) : 0;
			$signals = array(
				'pagerank'           => (float) $r['pagerank_score'],
				'depth'              => (int) $r['click_depth'],
				'has_schema'         => $has_schema_site,
				'fresh'              => ( $lm > 0 && $lm >= $fresh_cutoff ),
				'in_healthy_cluster' => ( (int) $r['healthy_cluster'] > 0 ),
				'orphan'             => ( (int) $r['inbound'] === 0 ),
				'answer_ready'       => ( (int) $r['word_count'] >= 50 ),
			);
			$scored = self::score( $signals );
			$out[]  = array(
				'post_id' => (int) $r['post_id'],
				'title'   => (string) $r['title'],
				'url'     => (string) $r['url'],
				'score'   => $scored['score'],
				'fixes'   => $scored['fixes'],
			);
		}
		return $out;
	}

	/**
	 * Site-level GEO summary from a scored set.
	 *
	 * @param array $scored Output of gather().
	 * @return array{count:int,avg:int,low:int,worst:array[]}
	 */
	public static function summary( array $scored ) {
		$count = count( $scored );
		if ( 0 === $count ) {
			return array( 'count' => 0, 'avg' => 0, 'low' => 0, 'worst' => array() );
		}
		$sum = 0;
		$low = 0;
		foreach ( $scored as $s ) {
			$sum += $s['score'];
			if ( $s['score'] < 40 ) {
				$low++;
			}
		}
		usort(
			$scored,
			function ( $a, $b ) {
				if ( $a['score'] === $b['score'] ) {
					return 0;
				}
				return ( $a['score'] < $b['score'] ) ? -1 : 1;
			}
		);
		return array(
			'count' => $count,
			'avg'   => (int) round( $sum / $count ),
			'low'   => $low,
			'worst' => array_slice( $scored, 0, 50 ),
		);
	}

	/**
	 * Schema recommendations per crawled post type + AI-crawler guidance.
	 *
	 * @return array{seo_plugin:string,recommendations:array<string,string>}
	 */
	public static function schema_hints() {
		$seo = SiteDetector::seo_plugin();
		$map = array(
			'post'    => 'Article / BlogPosting',
			'page'    => 'WebPage / FAQPage',
			'product' => 'Product + Offer',
		);
		$recs = array();
		foreach ( SiteDetector::public_post_types() as $slug => $obj ) {
			$recs[ $slug ] = isset( $map[ $slug ] ) ? $map[ $slug ] : 'Article / WebPage';
		}
		return array( 'seo_plugin' => $seo, 'recommendations' => $recs );
	}
}
