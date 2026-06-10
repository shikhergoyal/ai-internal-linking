<?php
/**
 * Keyword-evidence suggestion source ("unlinked mention" model): when a post's
 * body text mentions a query that another page already ranks for (imported
 * from GSC/Semrush on the Keywords tab) — and doesn't link to that page — the
 * mention becomes a link suggestion with the ranking keyword as its anchor.
 *
 * Wrap-first holds: the keyword must literally exist in the source text.
 * Zero AI cost; runs entirely on the local index. Striking-distance keywords
 * (positions 5-20) and high-opportunity keywords are favoured.
 *
 * @package AILinking
 */

namespace AILinking\Suggestions;

use AILinking\Support\Tables;

defined( 'ABSPATH' ) || exit;

class KeywordSuggester {

	const POOL_MAX = 500;

	/**
	 * Per-request keyword pool (one query per scan batch, not one per post).
	 *
	 * @var array[]|null
	 */
	private static $pool = null;

	/**
	 * Highest opportunity score in the pool (normalisation denominator).
	 *
	 * @var float
	 */
	private static $max_opportunity = 0.0;

	/**
	 * Find keyword-anchored candidates for a source post.
	 *
	 * @param int             $source_id    Source post ID.
	 * @param string          $source_text  Source plain text (parsed body).
	 * @param string          $source_lang  Source language code.
	 * @param string[]        $target_types Valid target post types.
	 * @param int             $min_words    Minimum anchor words.
	 * @param int             $max_words    Maximum anchor words.
	 * @param array<int,bool> $skip         Target post IDs to skip (already linked/suggested).
	 * @param int             $limit        Max candidates to return.
	 * @return array<int,array{post_id:int,score:float,title:string,url:string,anchor:string,context:string}>
	 */
	public static function find( $source_id, $source_text, $source_lang, $target_types, $min_words, $max_words, $skip, $limit ) {
		if ( $limit <= 0 || '' === trim( $source_text ) ) {
			return array();
		}

		$pool = self::pool();
		if ( empty( $pool ) ) {
			return array();
		}

		$type_ok = array_fill_keys( $target_types, true );
		$seen    = array(); // one suggestion per target; best keyword wins (pool is ordered by opportunity).
		$out     = array();

		foreach ( $pool as $kw ) {
			if ( count( $out ) >= $limit ) {
				break;
			}
			$tid = (int) $kw['post_id'];
			if ( $tid === (int) $source_id || isset( $skip[ $tid ] ) || isset( $seen[ $tid ] ) ) {
				continue;
			}
			if ( $source_lang !== $kw['lang_code'] ) {
				continue;
			}
			if ( ! isset( $type_ok[ $kw['post_type'] ] ) ) {
				continue;
			}

			$word_count = self::word_count( $kw['keyword'] );
			if ( $word_count < $min_words || $word_count > $max_words ) {
				continue;
			}

			// Cheap substring prefilter before the whole-word regex.
			if ( false === self::stripos( $source_text, $kw['keyword'] ) ) {
				continue;
			}

			$hit = AnchorGenerator::locate_phrase( $source_text, $kw['keyword'] );
			if ( null === $hit ) {
				continue;
			}

			// Over-optimisation guard: don't pile the same exact anchor onto one target.
			if ( self::anchor_saturated( $tid, $kw['keyword_norm'] ) ) {
				continue;
			}

			$out[] = array(
				'post_id' => $tid,
				'score'   => self::relevance( ! empty( $kw['is_striking'] ), (float) $kw['opportunity_score'], self::$max_opportunity ),
				'title'   => (string) $kw['title'],
				'url'     => (string) $kw['url'],
				'anchor'  => $hit['anchor'],
				'context' => $hit['context'],
			);
			$seen[ $tid ] = true;
		}

		return $out;
	}

	/**
	 * Relevance for a keyword-evidence match. (pure)
	 *
	 * A ranking-keyword mention is strong intent evidence, so the floor sits
	 * above typical TF-IDF overlap scores; striking-distance keywords
	 * (positions 5-20, where one more internal link helps most) and
	 * high-opportunity keywords raise it further.
	 *
	 * @param bool  $is_striking     Position 5-20.
	 * @param float $opportunity     Keyword opportunity score.
	 * @param float $max_opportunity Highest opportunity in the pool (0 = unknown).
	 * @return float [0,0.98]
	 */
	public static function relevance( $is_striking, $opportunity, $max_opportunity ) {
		$score = 0.5;
		if ( $is_striking ) {
			$score += 0.2;
		}
		if ( $max_opportunity > 0 && $opportunity > 0 ) {
			$score += 0.28 * min( 1.0, $opportunity / $max_opportunity );
		}
		return round( min( 0.98, $score ), 4 );
	}

	/**
	 * Eligible keywords joined with their target's index row, best
	 * opportunities first, deduped per (keyword, target).
	 *
	 * @return array[]
	 */
	private static function pool() {
		if ( null !== self::$pool ) {
			return self::$pool;
		}
		global $wpdb;
		$keywords = Tables::keywords();
		$index    = Tables::index();

		$cap  = max( 1, (int) apply_filters( 'ailinking_keyword_pool_max', self::POOL_MAX ) );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT k.keyword, k.keyword_norm, k.post_id, k.is_striking, k.opportunity_score,
				        i.title, i.url, i.lang_code, i.post_type
				 FROM {$keywords} k
				 INNER JOIN {$index} i ON i.post_id = k.post_id
				 WHERE k.post_id IS NOT NULL AND k.post_id > 0
				   AND i.post_status = 'publish' AND i.is_excluded = 0 AND i.is_woo_system = 0
				 ORDER BY k.opportunity_score DESC, k.id ASC
				 LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL
				$cap
			),
			ARRAY_A
		);

		$pool  = array();
		$dedup = array();
		$max   = 0.0;
		foreach ( (array) $rows as $r ) {
			$key = $r['keyword_norm'] . '|' . $r['post_id'];
			if ( isset( $dedup[ $key ] ) || '' === trim( (string) $r['keyword'] ) ) {
				continue;
			}
			$dedup[ $key ] = true;
			$pool[]        = $r;
			if ( (float) $r['opportunity_score'] > $max ) {
				$max = (float) $r['opportunity_score'];
			}
		}

		self::$pool            = $pool;
		self::$max_opportunity = $max;
		return self::$pool;
	}

	/**
	 * Whether this exact anchor already points at the target too often
	 * (existing content links + open suggestions). Keyword anchors are
	 * exact-match by nature, so this caps the over-optimisation risk the
	 * anchor-diversity report would otherwise flag after the fact.
	 *
	 * @param int    $target_id    Target post ID.
	 * @param string $keyword_norm Lowercased keyword.
	 * @return bool
	 */
	private static function anchor_saturated( $target_id, $keyword_norm ) {
		$max = (int) apply_filters( 'ailinking_max_exact_anchors_per_target', 3 );
		if ( $max <= 0 ) {
			return false;
		}
		global $wpdb;
		$graph = Tables::link_graph();
		$sugg  = Tables::suggestions();

		$existing = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$graph} WHERE target_post_id = %d AND location = 'content' AND LOWER(anchor_text) = %s", // phpcs:ignore WordPress.DB.PreparedSQL
				$target_id,
				$keyword_norm
			)
		);
		if ( $existing >= $max ) {
			return true;
		}
		$open = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$sugg} WHERE target_post_id = %d AND status IN ('pending','approved') AND LOWER(anchor_text) = %s", // phpcs:ignore WordPress.DB.PreparedSQL
				$target_id,
				$keyword_norm
			)
		);
		return ( $existing + $open ) >= $max;
	}

	/**
	 * Count words in a keyword the same way anchor phrases are segmented.
	 *
	 * @param string $keyword Keyword.
	 * @return int
	 */
	private static function word_count( $keyword ) {
		if ( ! preg_match_all( '/[\p{L}\p{Nd}]+/u', (string) $keyword, $m ) ) {
			return 0;
		}
		return count( $m[0] );
	}

	/**
	 * Multibyte-aware case-insensitive substring search.
	 *
	 * @param string $haystack Haystack.
	 * @param string $needle   Needle.
	 * @return int|false
	 */
	private static function stripos( $haystack, $needle ) {
		if ( function_exists( 'mb_stripos' ) ) {
			return mb_stripos( $haystack, $needle, 0, 'UTF-8' );
		}
		return stripos( $haystack, $needle );
	}
}
