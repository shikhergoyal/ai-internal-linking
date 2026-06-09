<?php
/**
 * Universal automatic topic-cluster detection.
 *
 * Builds pillar/spoke clusters from signals every WordPress site already has —
 * no manual post-ID entry required:
 *
 *   1. Taxonomy structure (categories first, then other hierarchical/public
 *      taxonomies) — WordPress's native way of grouping content by topic. Each
 *      term with enough indexed members becomes a candidate cluster.
 *   2. The internal link graph — within each candidate the pillar (hub) is the
 *      member the other members already link to most (highest content in-degree),
 *      so the structure the site already exhibits is respected rather than guessed.
 *
 * Clusters are split per language so a cluster never mixes languages. Detection
 * is idempotent: a term whose cluster slug already exists is skipped, so re-running
 * never duplicates clusters or clobbers ones you created by hand.
 *
 * The decision logic (pillar pick, viability, naming) lives in pure, unit-tested
 * functions; detect() only does the I/O of reading the site and writing clusters.
 *
 * @package AILinking
 */

namespace AILinking\Clusters;

use AILinking\Support\Tables;
use AILinking\Detectors\SiteDetector;

defined( 'ABSPATH' ) || exit;

class AutoClusterDetector {

	/** Default smallest viable cluster: a pillar plus two spokes. */
	const DEFAULT_MIN_SIZE = 3;

	/** Default largest group treated as a topic cluster (huge terms aren't hubs). */
	const DEFAULT_MAX_SIZE = 60;

	/** Default cap on clusters created in one run (runaway guard). */
	const DEFAULT_MAX_CLUSTERS = 100;

	/**
	 * Choose the pillar (hub) from a set of candidate members.
	 *
	 * Pure + unit-tested. Priority: highest intra-site content in-degree (the post
	 * the others link to most), then PageRank, then length, then lowest post id for
	 * a fully deterministic tie-break.
	 *
	 * @param array<int,array{post_id:int,in_degree:int,pagerank:float,word_count:int}> $members Member rows.
	 * @return int Pillar post id (0 when empty).
	 */
	public static function pick_pillar( array $members ) {
		$best = null;
		foreach ( $members as $m ) {
			if ( null === $best || self::beats( $m, $best ) ) {
				$best = $m;
			}
		}
		return $best ? (int) $best['post_id'] : 0;
	}

	/**
	 * Whether member $a is a stronger pillar candidate than $b.
	 *
	 * @param array $a Candidate.
	 * @param array $b Incumbent.
	 * @return bool
	 */
	private static function beats( array $a, array $b ) {
		$ai = (int) $a['in_degree'];
		$bi = (int) $b['in_degree'];
		if ( $ai !== $bi ) {
			return $ai > $bi;
		}
		$ap = (float) $a['pagerank'];
		$bp = (float) $b['pagerank'];
		if ( $ap !== $bp ) {
			return $ap > $bp;
		}
		$aw = (int) $a['word_count'];
		$bw = (int) $b['word_count'];
		if ( $aw !== $bw ) {
			return $aw > $bw;
		}
		return (int) $a['post_id'] < (int) $b['post_id'];
	}

	/**
	 * Filter raw term groups down to viable cluster candidates.
	 *
	 * Pure + unit-tested. Drops groups that are too small to form a hub-and-spoke
	 * structure, and groups so large they're a site-wide bucket (e.g. "Uncategorized")
	 * rather than a topic.
	 *
	 * @param array<string,int[]> $groups   Map of group key => post ids.
	 * @param int                 $min_size Minimum members.
	 * @param int                 $max_size Maximum members (0 = no cap).
	 * @return array<string,int[]> Viable groups, ids de-duplicated.
	 */
	public static function viable_groups( array $groups, $min_size, $max_size ) {
		$out = array();
		foreach ( $groups as $key => $ids ) {
			$ids = array_values( array_unique( array_map( 'intval', (array) $ids ) ) );
			$n   = count( $ids );
			if ( $n < (int) $min_size ) {
				continue;
			}
			if ( $max_size > 0 && $n > (int) $max_size ) {
				continue;
			}
			$out[ $key ] = $ids;
		}
		return $out;
	}

	/**
	 * Human cluster name for a term + language. Pure + unit-tested.
	 *
	 * @param string $term_name Term display name.
	 * @param string $lang      Language code ('und' when unknown/monolingual).
	 * @return string
	 */
	public static function cluster_name( $term_name, $lang ) {
		$term_name = trim( (string) $term_name );
		if ( '' === $term_name ) {
			$term_name = 'Cluster';
		}
		if ( '' !== (string) $lang && 'und' !== $lang ) {
			return $term_name . ' (' . $lang . ')';
		}
		return $term_name;
	}

	/**
	 * Stable cluster slug for a term + language (used for idempotency). Pure.
	 *
	 * @param string $term_name Term display name.
	 * @param string $lang      Language code.
	 * @return string
	 */
	public static function cluster_slug( $term_name, $lang ) {
		$base = sanitize_title( (string) $term_name );
		if ( '' === $base ) {
			$base = 'cluster';
		}
		if ( '' !== (string) $lang && 'und' !== $lang ) {
			$base .= '-' . sanitize_key( $lang );
		}
		return $base;
	}

	/**
	 * Detect and create clusters from the site's taxonomies + link graph.
	 *
	 * @return array{created:int,skipped:int,candidates:int} Summary counts.
	 */
	public static function detect() {
		global $wpdb;

		$min_size     = (int) apply_filters( 'ailinking_auto_cluster_min_size', self::DEFAULT_MIN_SIZE );
		$max_size     = (int) apply_filters( 'ailinking_auto_cluster_max_size', self::DEFAULT_MAX_SIZE );
		$max_clusters = (int) apply_filters( 'ailinking_auto_cluster_max', self::DEFAULT_MAX_CLUSTERS );

		$meta      = self::indexed_meta();
		$in_degree = self::content_in_degree();
		$existing  = self::existing_slugs();

		$created    = 0;
		$skipped    = 0;
		$candidates = 0;

		foreach ( self::candidate_groups( $meta, $min_size, $max_size ) as $group ) {
			$candidates++;

			$slug = self::cluster_slug( $group['term_name'], $group['lang'] );
			if ( isset( $existing[ $slug ] ) ) {
				$skipped++;
				continue;
			}
			if ( $created >= $max_clusters ) {
				$skipped++;
				continue;
			}

			$rows = array();
			foreach ( $group['ids'] as $pid ) {
				$rows[] = array(
					'post_id'    => $pid,
					'in_degree'  => isset( $in_degree[ $pid ] ) ? (int) $in_degree[ $pid ] : 0,
					'pagerank'   => isset( $meta[ $pid ]['pagerank'] ) ? (float) $meta[ $pid ]['pagerank'] : 0.0,
					'word_count' => isset( $meta[ $pid ]['word_count'] ) ? (int) $meta[ $pid ]['word_count'] : 0,
				);
			}

			$pillar = self::pick_pillar( $rows );
			$name   = self::cluster_name( $group['term_name'], $group['lang'] );

			$cluster_id = ClusterRepository::create( $name, $pillar, $group['lang'] );
			if ( ! $cluster_id ) {
				$skipped++;
				continue;
			}

			foreach ( $group['ids'] as $pid ) {
				if ( $pid === $pillar ) {
					continue; // already added as pillar by create().
				}
				ClusterRepository::add_member( $cluster_id, $pid, 'spoke' );
			}

			$existing[ $slug ] = true;
			$created++;
		}

		return array(
			'created'    => $created,
			'skipped'    => $skipped,
			'candidates' => $candidates,
		);
	}

	/**
	 * Indexed, linkable posts keyed by id => [ lang, word_count, pagerank ].
	 *
	 * @return array<int,array{lang:string,word_count:int,pagerank:float}>
	 */
	private static function indexed_meta() {
		global $wpdb;
		$table = Tables::index();
		$rows  = $wpdb->get_results(
			"SELECT post_id, lang_code, word_count, pagerank_score
			 FROM {$table}
			 WHERE is_excluded = 0 AND post_status = 'publish'", // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);
		$meta = array();
		foreach ( (array) $rows as $r ) {
			$meta[ (int) $r['post_id'] ] = array(
				'lang'       => (string) $r['lang_code'],
				'word_count' => (int) $r['word_count'],
				'pagerank'   => (float) $r['pagerank_score'],
			);
		}
		return $meta;
	}

	/**
	 * Content in-degree per target post (how many internal links point at it).
	 *
	 * @return array<int,int>
	 */
	private static function content_in_degree() {
		global $wpdb;
		$table = Tables::link_graph();
		$rows  = $wpdb->get_results(
			"SELECT target_post_id, COUNT(*) AS c
			 FROM {$table}
			 WHERE location = 'content' AND target_post_id > 0
			 GROUP BY target_post_id", // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[ (int) $r['target_post_id'] ] = (int) $r['c'];
		}
		return $out;
	}

	/**
	 * Slugs of clusters that already exist (for idempotent re-runs).
	 *
	 * @return array<string,bool>
	 */
	private static function existing_slugs() {
		global $wpdb;
		$table = Tables::clusters();
		$slugs = $wpdb->get_col( "SELECT slug FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL
		$out   = array();
		foreach ( (array) $slugs as $s ) {
			$out[ (string) $s ] = true;
		}
		return $out;
	}

	/**
	 * Build candidate groups from public taxonomies, split per language.
	 *
	 * Hierarchical taxonomies (categories and the like) are preferred because they
	 * model topics; flat taxonomies (tags) are opt-in via filter. The first
	 * taxonomy to claim a term wins, so a post isn't split across near-duplicate
	 * tag/category clusters.
	 *
	 * @param array $meta     Indexed post meta.
	 * @param int   $min_size Minimum cluster size.
	 * @param int   $max_size Maximum cluster size.
	 * @return array<int,array{term_name:string,lang:string,ids:int[]}>
	 */
	private static function candidate_groups( array $meta, $min_size, $max_size ) {
		$taxonomies = self::cluster_taxonomies();
		$groups     = array();

		foreach ( $taxonomies as $tax_slug ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $tax_slug,
					'hide_empty' => true,
				)
			);
			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				continue;
			}

			foreach ( $terms as $term ) {
				$object_ids = get_objects_in_term( (int) $term->term_id, $tax_slug );
				if ( is_wp_error( $object_ids ) || empty( $object_ids ) ) {
					continue;
				}

				// Split members of this term by language; only indexed posts count.
				$by_lang = array();
				foreach ( $object_ids as $oid ) {
					$pid = (int) $oid;
					if ( ! isset( $meta[ $pid ] ) ) {
						continue;
					}
					$lang                 = $meta[ $pid ]['lang'];
					$by_lang[ $lang ][]   = $pid;
				}

				foreach ( $by_lang as $lang => $ids ) {
					$key            = $tax_slug . '|' . $term->term_id . '|' . $lang;
					$groups[ $key ] = array(
						'term_name' => $term->name,
						'lang'      => $lang,
						'ids'       => $ids,
					);
				}
			}
		}

		// Keep only viable-size groups, then return as a flat list.
		$sizes  = array();
		foreach ( $groups as $key => $g ) {
			$sizes[ $key ] = $g['ids'];
		}
		$viable = self::viable_groups( $sizes, $min_size, $max_size );

		$out = array();
		foreach ( $viable as $key => $ids ) {
			$out[] = array(
				'term_name' => $groups[ $key ]['term_name'],
				'lang'      => $groups[ $key ]['lang'],
				'ids'       => $ids,
			);
		}
		return $out;
	}

	/**
	 * Ordered list of taxonomy slugs to mine for clusters. Hierarchical first.
	 *
	 * @return string[]
	 */
	private static function cluster_taxonomies() {
		$hier = array();
		$flat = array();
		foreach ( SiteDetector::public_taxonomies() as $slug => $obj ) {
			if ( ! empty( $obj->hierarchical ) ) {
				$hier[] = $slug;
			} else {
				$flat[] = $slug;
			}
		}
		// Categories model topics best; tags are noisier, so off by default.
		$include_flat = (bool) apply_filters( 'ailinking_auto_cluster_use_tags', false );
		$list         = $include_flat ? array_merge( $hier, $flat ) : $hier;

		return (array) apply_filters( 'ailinking_auto_cluster_taxonomies', $list );
	}
}
