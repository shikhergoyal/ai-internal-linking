<?php
/**
 * Graph-only SEO audits over the internal link graph: orphans, dead-ends, link
 * density, and click-depth from the front page. All zero-cost (no AI, no HTTP).
 *
 * @package AILinking
 */

namespace AILinking\LinkGraph;

use AILinking\Support\Tables;
use AILinking\Support\Settings;
use AILinking\Content\UrlResolver;
use AILinking\Content\LedgerRepository;
use AILinking\Scorers\AnchorDiversity;

defined( 'ABSPATH' ) || exit;

class GraphAudits {

	const SUMMARY_TRANSIENT = 'ailinking_audit_summary';
	const UNDER_MIN_WORDS   = 300;

	/**
	 * Cached audit summary (counts + depth stats).
	 *
	 * @param bool $fresh Force recompute.
	 * @return array
	 */
	public static function summary( $fresh = false ) {
		if ( ! $fresh ) {
			$cached = get_transient( self::SUMMARY_TRANSIENT );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$per1000 = (int) Settings::get( 'max_links_per_1000', 5 );

		$summary = array(
			'indexed'        => self::count_eligible(),
			'orphans'        => self::orphans_count(),
			'dead_ends'      => self::dead_ends_count(),
			'over_linked'    => self::density_count( 'over', $per1000 ),
			'under_linked'   => self::density_count( 'under', $per1000 ),
			'applied_links'  => LedgerRepository::count_active(),
			'broken'         => BrokenLinks::count(),
			'over_optimized' => AnchorDiversity::over_count(),
			'depth'          => self::depth_stats(),
		);

		set_transient( self::SUMMARY_TRANSIENT, $summary, HOUR_IN_SECONDS );
		return $summary;
	}

	/**
	 * Invalidate the cached summary.
	 */
	public static function flush_summary() {
		delete_transient( self::SUMMARY_TRANSIENT );
	}

	/**
	 * Recompute all derived graph metrics (depth, PageRank, broken links).
	 *
	 * @return array{ok:bool,reason?:string}
	 */
	public static function recompute_all() {
		$depth = self::recompute_depth();
		PageRank::compute();
		BrokenLinks::scan();
		self::flush_summary();
		return $depth;
	}

	/** ---- Counts ---- */

	private static function count_eligible() {
		global $wpdb;
		$index = Tables::index();
		list( $clause, $args ) = self::scope_clause();
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$index} i WHERE {$clause}", $args ) // phpcs:ignore WordPress.DB.PreparedSQL
		);
	}

	private static function orphans_count() {
		global $wpdb;
		$index = Tables::index();
		$graph = Tables::link_graph();
		list( $clause, $args ) = self::scope_clause();
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$index} i
				 LEFT JOIN (SELECT DISTINCT target_post_id FROM {$graph} WHERE location='content' AND target_post_id>0) g
				 ON i.post_id = g.target_post_id
				 WHERE g.target_post_id IS NULL AND {$clause}", // phpcs:ignore WordPress.DB.PreparedSQL
				$args
			)
		);
	}

	private static function dead_ends_count() {
		global $wpdb;
		$index = Tables::index();
		$graph = Tables::link_graph();
		list( $clause, $args ) = self::scope_clause();
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$index} i
				 LEFT JOIN (SELECT DISTINCT source_post_id FROM {$graph} WHERE location='content' AND target_post_id>0) g
				 ON i.post_id = g.source_post_id
				 WHERE g.source_post_id IS NULL AND {$clause}", // phpcs:ignore WordPress.DB.PreparedSQL
				$args
			)
		);
	}

	/**
	 * @param string $which  'over' | 'under'.
	 * @param int    $per1000 Density target.
	 * @return int
	 */
	private static function density_count( $which, $per1000 ) {
		global $wpdb;
		$index = Tables::index();
		$graph = Tables::link_graph();
		list( $clause, $args ) = self::scope_clause();
		$per1000 = max( 1, (int) $per1000 );

		$having = ( 'over' === $which )
			? "COALESCE(c.cnt,0) > GREATEST(1, CEIL(i.word_count/1000*{$per1000}))"
			: "COALESCE(c.cnt,0) = 0 AND i.word_count >= " . (int) self::UNDER_MIN_WORDS;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$index} i
				 LEFT JOIN (SELECT source_post_id, COUNT(*) cnt FROM {$graph} WHERE location='content' AND target_post_id>0 GROUP BY source_post_id) c
				 ON i.post_id = c.source_post_id
				 WHERE {$clause} AND {$having}", // phpcs:ignore WordPress.DB.PreparedSQL
				$args
			)
		);
	}

	/** ---- Lists (for the dashboard tables) ---- */

	public static function orphans( $limit = 50 ) {
		global $wpdb;
		$index = Tables::index();
		$graph = Tables::link_graph();
		list( $clause, $args ) = self::scope_clause();
		$args[] = $limit;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT i.post_id, i.title, i.url FROM {$index} i
				 LEFT JOIN (SELECT DISTINCT target_post_id FROM {$graph} WHERE location='content' AND target_post_id>0) g
				 ON i.post_id = g.target_post_id
				 WHERE g.target_post_id IS NULL AND {$clause}
				 ORDER BY i.post_id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL
				$args
			),
			ARRAY_A
		);
	}

	public static function dead_ends( $limit = 50 ) {
		global $wpdb;
		$index = Tables::index();
		$graph = Tables::link_graph();
		list( $clause, $args ) = self::scope_clause();
		$args[] = $limit;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT i.post_id, i.title, i.url FROM {$index} i
				 LEFT JOIN (SELECT DISTINCT source_post_id FROM {$graph} WHERE location='content' AND target_post_id>0) g
				 ON i.post_id = g.source_post_id
				 WHERE g.source_post_id IS NULL AND {$clause}
				 ORDER BY i.post_id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL
				$args
			),
			ARRAY_A
		);
	}

	public static function over_linked( $limit = 50 ) {
		global $wpdb;
		$index   = Tables::index();
		$graph   = Tables::link_graph();
		$per1000 = max( 1, (int) Settings::get( 'max_links_per_1000', 5 ) );
		list( $clause, $args ) = self::scope_clause();
		$args[] = $limit;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT i.post_id, i.title, i.url, i.word_count, COALESCE(c.cnt,0) AS links FROM {$index} i
				 LEFT JOIN (SELECT source_post_id, COUNT(*) cnt FROM {$graph} WHERE location='content' AND target_post_id>0 GROUP BY source_post_id) c
				 ON i.post_id = c.source_post_id
				 WHERE {$clause} AND COALESCE(c.cnt,0) > GREATEST(1, CEIL(i.word_count/1000*{$per1000}))
				 ORDER BY links DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL
				$args
			),
			ARRAY_A
		);
	}

	/** ---- Click depth (BFS from the front page) ---- */

	/**
	 * Recompute click-depth from the front page and store it on each index row.
	 *
	 * @return array{ok:bool,reason?:string,reached?:int}
	 */
	public static function recompute_depth() {
		global $wpdb;
		$index = Tables::index();
		$graph = Tables::link_graph();

		$seed = (int) get_option( 'page_on_front' );
		if ( $seed <= 0 ) {
			$seed = UrlResolver::to_post_id( home_url( '/' ) );
		}
		if ( $seed <= 0 ) {
			return array( 'ok' => false, 'reason' => 'no_seed' );
		}

		// Build adjacency from content edges.
		$edges = $wpdb->get_results(
			"SELECT source_post_id, target_post_id FROM {$graph} WHERE location='content' AND target_post_id>0", // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);
		$adj = array();
		foreach ( $edges as $e ) {
			$s = (int) $e['source_post_id'];
			$t = (int) $e['target_post_id'];
			$adj[ $s ][] = $t;
		}

		// BFS.
		$depth = array( $seed => 0 );
		$queue = array( $seed );
		while ( ! empty( $queue ) ) {
			$node = array_shift( $queue );
			$d    = $depth[ $node ];
			if ( empty( $adj[ $node ] ) ) {
				continue;
			}
			foreach ( $adj[ $node ] as $next ) {
				if ( ! isset( $depth[ $next ] ) ) {
					$depth[ $next ] = $d + 1;
					$queue[]        = $next;
				}
			}
		}

		// Reset, then write depths in chunked CASE updates.
		$wpdb->query( "UPDATE {$index} SET click_depth = -1" ); // phpcs:ignore WordPress.DB.PreparedSQL

		$pairs = array();
		foreach ( $depth as $pid => $d ) {
			$pairs[] = array( (int) $pid, (int) $d );
		}
		foreach ( array_chunk( $pairs, 100 ) as $chunk ) {
			$cases = '';
			$ids   = array();
			foreach ( $chunk as $p ) {
				$cases .= ' WHEN ' . (int) $p[0] . ' THEN ' . (int) $p[1];
				$ids[]  = (int) $p[0];
			}
			$in = implode( ',', $ids );
			$wpdb->query( "UPDATE {$index} SET click_depth = CASE post_id{$cases} END WHERE post_id IN ({$in})" ); // phpcs:ignore WordPress.DB.PreparedSQL
		}

		self::flush_summary();
		return array( 'ok' => true, 'reached' => count( $depth ) );
	}

	/**
	 * @return array Depth statistics from the index.
	 */
	private static function depth_stats() {
		global $wpdb;
		$index = Tables::index();
		list( $clause, $args ) = self::scope_clause();

		$max = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(click_depth) FROM {$index} i WHERE {$clause}", $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$unreached = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$index} i WHERE click_depth < 0 AND {$clause}", $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$deep      = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$index} i WHERE click_depth >= 4 AND {$clause}", $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL

		return array(
			'max'       => $max,
			'unreached' => $unreached,
			'deep'      => $deep, // depth >= 4 from the front page
		);
	}

	/**
	 * Shared eligibility WHERE clause (alias i) + bound args (post types).
	 *
	 * @return array{0:string,1:array}
	 */
	private static function scope_clause() {
		$types = Settings::crawl_post_types();
		if ( empty( $types ) ) {
			$types = array( 'post', 'page' );
		}
		$ph     = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$clause = "i.is_excluded = 0 AND i.is_woo_system = 0 AND i.post_status = 'publish' AND i.post_type IN ($ph)";
		return array( $clause, $types );
	}
}
