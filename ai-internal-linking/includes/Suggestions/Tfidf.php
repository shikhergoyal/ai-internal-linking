<?php
/**
 * Local, zero-cost TF-IDF engine: tokenisation, per-post term storage, and
 * weighted-overlap candidate generation. This is the always-available baseline;
 * the generative AI pass draws its candidate shortlist from these results.
 *
 * @package AILinking
 */

namespace AILinking\Suggestions;

use AILinking\Support\Tables;
use AILinking\Support\Settings;

defined( 'ABSPATH' ) || exit;

class Tfidf {

	const MAX_TERMS_PER_POST = 300;
	const SOURCE_TERMS       = 40;

	/**
	 * Common English stopwords. Multilingual stopword handling is a later refinement.
	 *
	 * @return array<string,bool>
	 */
	private static function stopwords() {
		static $set = null;
		if ( null !== $set ) {
			return $set;
		}
		$words = array(
			'the', 'and', 'for', 'are', 'but', 'not', 'you', 'all', 'any', 'can', 'her', 'was', 'one', 'our', 'out', 'his', 'has', 'had', 'how', 'who', 'its', 'who', 'did', 'yes', 'get', 'got', 'why', 'let', 'put', 'too', 'use', 'she', 'him', 'now', 'new', 'way', 'may', 'say', 'see', 'two', 'man', 'day', 'this', 'that', 'with', 'from', 'they', 'will', 'would', 'there', 'their', 'what', 'about', 'which', 'when', 'were', 'your', 'have', 'more', 'than', 'then', 'them', 'some', 'into', 'only', 'other', 'such', 'also', 'just', 'over', 'most', 'been', 'being', 'because', 'these', 'those', 'here', 'very', 'much', 'many', 'each', 'both', 'after', 'before', 'where', 'while', 'should', 'could', 'does', 'doing', 'done', 'between', 'through', 'during', 'above', 'below', 'again', 'further', 'once', 'under', 'same', 'own', 'off', 'down', 'upon', 'within', 'without',
		);
		$set = array();
		foreach ( $words as $w ) {
			$set[ $w ] = true;
		}
		return apply_filters( 'ailinking_stopwords', $set );
	}

	/**
	 * Tokenise text into significant lowercase terms.
	 *
	 * @param string $text Plain text.
	 * @return string[]
	 */
	public static function tokenize( $text ) {
		$text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
		preg_match_all( '/\p{L}[\p{L}\p{Nd}\'-]{2,}/u', $text, $m );
		$tokens = isset( $m[0] ) ? $m[0] : array();

		$stop = self::stopwords();
		$out  = array();
		foreach ( $tokens as $tok ) {
			$tok = trim( $tok, "'-" );
			if ( strlen( $tok ) < 3 || isset( $stop[ $tok ] ) ) {
				continue;
			}
			if ( strlen( $tok ) > 80 ) {
				$tok = substr( $tok, 0, 80 );
			}
			$out[] = $tok;
		}
		return $out;
	}

	/**
	 * Compute capped term frequencies for a text.
	 *
	 * @param string $text Plain text.
	 * @return array<string,int> term => frequency, highest first, capped.
	 */
	public static function term_frequencies( $text ) {
		$tokens = self::tokenize( $text );
		$freq   = array();
		foreach ( $tokens as $t ) {
			$freq[ $t ] = isset( $freq[ $t ] ) ? $freq[ $t ] + 1 : 1;
		}
		arsort( $freq );
		if ( count( $freq ) > self::MAX_TERMS_PER_POST ) {
			$freq = array_slice( $freq, 0, self::MAX_TERMS_PER_POST, true );
		}
		return $freq;
	}

	/**
	 * Persist a post's term vector (replace strategy).
	 *
	 * @param int    $post_id Post ID.
	 * @param string $text    Plain text corpus.
	 */
	public static function store_for_post( $post_id, $text ) {
		global $wpdb;
		$table = Tables::tfidf();

		self::delete_for_post( $post_id );

		$freq = self::term_frequencies( $text );
		if ( empty( $freq ) ) {
			return;
		}

		$rows = array();
		foreach ( $freq as $term => $tf ) {
			$rows[] = array( $post_id, $term, $tf );
		}

		foreach ( array_chunk( $rows, 100 ) as $chunk ) {
			$place  = array();
			$values = array();
			foreach ( $chunk as $r ) {
				$place[]  = '(%d,%s,%d)';
				$values[] = $r[0];
				$values[] = $r[1];
				$values[] = $r[2];
			}
			$sql = "INSERT IGNORE INTO {$table} (post_id,term,tf) VALUES " . implode( ',', $place ); // phpcs:ignore WordPress.DB.PreparedSQL
			$wpdb->query( $wpdb->prepare( $sql, $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
	}

	/**
	 * Delete a post's term vector.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function delete_for_post( $post_id ) {
		global $wpdb;
		$wpdb->delete( Tables::tfidf(), array( 'post_id' => $post_id ), array( '%d' ) );
	}

	/**
	 * Total indexed documents (distinct posts with a term vector).
	 *
	 * @return int
	 */
	public static function total_docs() {
		static $n = null;
		if ( null !== $n ) {
			return $n;
		}
		global $wpdb;
		$table = Tables::tfidf();
		$n     = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT post_id) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL
		return $n;
	}

	/**
	 * The most distinctive words for a set of pages.
	 *
	 * Used to tell the model what each possible destination is actually about,
	 * rather than making it judge from a title alone. Batched deliberately: one
	 * round trip per 40 pages, not one per page, because this runs inside a scan
	 * that is already doing a network round trip per post.
	 *
	 * Ordered by raw frequency, which is what the table is indexed for. Rarity
	 * weighting would be better but needs a second pass over document
	 * frequencies, and for a handful of descriptive words the difference does
	 * not justify the extra query.
	 *
	 * @param int[] $post_ids Page ids.
	 * @param int   $per_post Words to return for each.
	 * @return array<int,string[]> post id => words, most used first.
	 */
	public static function top_terms_for( array $post_ids, $per_post = 10 ) {
		global $wpdb;

		$ids      = array_values( array_unique( array_map( 'intval', $post_ids ) ) );
		$per_post = max( 1, (int) $per_post );
		if ( empty( $ids ) ) {
			return array();
		}

		$table = Tables::tfidf();
		$out   = array();

		// One bounded subquery per page, UNION ALL'd into a single round trip.
		//
		// The obvious "WHERE post_id IN (...) ORDER BY tf DESC LIMIT n" does not
		// work here, and fails silently rather than loudly: a LIMIT applies to
		// the whole result, not per page, so the first pages in the list eat the
		// entire allowance (up to MAX_TERMS_PER_POST rows each) and every page
		// after them comes back with no words at all. Each subquery carries its
		// own LIMIT, so every page gets exactly its share.
		foreach ( array_chunk( $ids, 40 ) as $chunk ) {
			$parts = array();
			$args  = array();
			foreach ( $chunk as $id ) {
				$parts[] = "(SELECT post_id, term FROM {$table} WHERE post_id = %d ORDER BY tf DESC LIMIT %d)";
				$args[]  = $id;
				$args[]  = $per_post;
			}

			$rows = $wpdb->get_results(
				$wpdb->prepare( implode( ' UNION ALL ', $parts ), $args ), // phpcs:ignore WordPress.DB.PreparedSQL
				ARRAY_A
			);

			foreach ( (array) $rows as $r ) {
				$pid = (int) $r['post_id'];
				if ( ! isset( $out[ $pid ] ) ) {
					$out[ $pid ] = array();
				}
				if ( count( $out[ $pid ] ) < $per_post ) {
					$out[ $pid ][] = (string) $r['term'];
				}
			}
		}

		return $out;
	}

	/**
	 * Generate ranked link candidates for a source post.
	 *
	 * Relevance = weighted overlap: how much of the source's idf-weighted content
	 * the candidate covers. Bounded [0,1]; needs no full-vector norms.
	 *
	 * @param int      $source_id    Source post ID.
	 * @param string   $source_lang  Source language code.
	 * @param string[] $target_types Valid target post types.
	 * @param int      $limit        Max candidates to return.
	 * @return array<int,array{post_id:int,score:float,title:string,url:string}>
	 */
	public static function candidates( $source_id, $source_lang, $target_types, $limit = 30 ) {
		global $wpdb;
		$tfidf = Tables::tfidf();
		$index = Tables::index();

		// 1. Source term vector (top terms).
		$src_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT term, tf FROM {$tfidf} WHERE post_id = %d ORDER BY tf DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL
				$source_id,
				self::SOURCE_TERMS
			),
			ARRAY_A
		);
		if ( empty( $src_rows ) ) {
			return array();
		}

		$src_tf = array();
		foreach ( $src_rows as $r ) {
			$src_tf[ $r['term'] ] = (int) $r['tf'];
		}
		$terms = array_keys( $src_tf );

		// 2. Document frequency for these terms; drop too-common / absent terms.
		$n            = max( 1, self::total_docs() );
		$placeholders = implode( ',', array_fill( 0, count( $terms ), '%s' ) );
		$df_rows      = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT term, COUNT(*) AS df FROM {$tfidf} WHERE term IN ($placeholders) GROUP BY term", // phpcs:ignore WordPress.DB.PreparedSQL
				$terms
			),
			ARRAY_A
		);
		$idf = array();
		foreach ( $df_rows as $r ) {
			$df = (int) $r['df'];
			if ( $df < 1 || $df > $n * 0.4 ) {
				continue; // too rare to matter or too common to discriminate.
			}
			$idf[ $r['term'] ] = log( 1 + ( $n / $df ) );
		}
		if ( empty( $idf ) ) {
			return array();
		}

		$useful_terms = array_keys( $idf );

		// Source weighted norm (denominator of the overlap ratio). Guard the key:
		// accent/case-insensitive DB collation can return accent-variant terms
		// (e.g. niña vs nina) that aren't exact keys in $src_tf.
		$src_weight_total = 0.0;
		foreach ( $useful_terms as $t ) {
			$src_weight_total += $idf[ $t ] * ( isset( $src_tf[ $t ] ) ? $src_tf[ $t ] : 0 );
		}
		if ( $src_weight_total <= 0 ) {
			return array();
		}

		// 3. Candidate postings for the useful terms.
		$ph2  = implode( ',', array_fill( 0, count( $useful_terms ), '%s' ) );
		$args = $useful_terms;
		$args[] = $source_id;
		$cand_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, term, tf FROM {$tfidf} WHERE term IN ($ph2) AND post_id != %d", // phpcs:ignore WordPress.DB.PreparedSQL
				$args
			),
			ARRAY_A
		);
		if ( empty( $cand_rows ) ) {
			return array();
		}

		// 4. Weighted-overlap score + shared-term count.
		$score  = array();
		$shared = array();
		foreach ( $cand_rows as $r ) {
			$pid  = (int) $r['post_id'];
			$term = $r['term'];
			if ( ! isset( $idf[ $term ], $src_tf[ $term ] ) ) {
				continue;
			}
			$contrib = $idf[ $term ] * min( $src_tf[ $term ], (int) $r['tf'] );
			$score[ $pid ]  = isset( $score[ $pid ] ) ? $score[ $pid ] + $contrib : $contrib;
			$shared[ $pid ] = isset( $shared[ $pid ] ) ? $shared[ $pid ] + 1 : 1;
		}

		// Require at least two shared significant terms.
		foreach ( $score as $pid => $s ) {
			if ( $shared[ $pid ] < 2 ) {
				unset( $score[ $pid ] );
			}
		}
		if ( empty( $score ) ) {
			return array();
		}

		// 5. Filter candidate set by language / type / exclusions via the index.
		$ids = array_keys( $score );
		$ph3 = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$meta = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, post_type, lang_code, title, url FROM {$index}
				 WHERE post_id IN ($ph3) AND is_excluded = 0 AND is_woo_system = 0 AND post_status = 'publish'", // phpcs:ignore WordPress.DB.PreparedSQL
				$ids
			),
			ARRAY_A
		);

		$type_ok = array_fill_keys( $target_types, true );
		$out     = array();
		foreach ( $meta as $row ) {
			$pid = (int) $row['post_id'];
			if ( ! isset( $type_ok[ $row['post_type'] ] ) ) {
				continue;
			}
			// Same language only ('und' matches 'und').
			if ( $source_lang !== $row['lang_code'] ) {
				continue;
			}
			$relevance = min( 1.0, $score[ $pid ] / $src_weight_total );
			$out[]     = array(
				'post_id' => $pid,
				'score'   => round( $relevance, 4 ),
				'title'   => (string) $row['title'],
				'url'     => (string) $row['url'],
			);
		}

		usort(
			$out,
			function ( $a, $b ) {
				if ( $a['score'] === $b['score'] ) {
					return 0;
				}
				return ( $a['score'] < $b['score'] ) ? 1 : -1;
			}
		);

		return array_slice( $out, 0, $limit );
	}
}
