<?php
/**
 * Suggestion pipeline: candidate generation -> relevance -> anchor (wrap-first)
 * -> naturalness -> confidence -> dedupe/density guards -> pending records.
 * Writes nothing to content; only produces reviewable suggestions.
 *
 * @package AILinking
 */

namespace AILinking\Suggestions;

use AILinking\Support\Tables;
use AILinking\Support\Settings;
use AILinking\Jobs\ProgressStore;
use AILinking\Providers\Gateway;

defined( 'ABSPATH' ) || exit;

class SuggestionEngine {

	/**
	 * Generate pending suggestions for a single source post.
	 *
	 * @param int $source_id Source post ID.
	 * @return int Number of suggestions created.
	 */
	public static function generate_for_post( $source_id ) {
		global $wpdb;
		$index = Tables::index();
		$graph = Tables::link_graph();
		$sugg  = Tables::suggestions();

		$source = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT post_id, parsed_text, lang_code, word_count, post_status, is_excluded FROM {$index} WHERE post_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL
				$source_id
			),
			ARRAY_A
		);
		if ( ! $source || 'publish' !== $source['post_status'] || (int) $source['is_excluded'] === 1 ) {
			return 0;
		}

		$text = (string) $source['parsed_text'];
		if ( '' === trim( $text ) ) {
			return 0;
		}

		$settings    = Settings::all();
		$min_rel     = (float) $settings['min_relevance'];
		$per_post    = (int) $settings['max_suggestions_post'];
		$per_1000    = (int) $settings['max_links_per_1000'];
		$min_words   = (int) $settings['min_anchor_words'];
		$max_words   = (int) $settings['max_anchor_words'];
		$word_count  = max( 1, (int) $source['word_count'] );

		// Density ceiling.
		$max_allowed = max( 2, (int) ceil( ( $word_count / 1000 ) * $per_1000 ) );
		$existing_links = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$graph} WHERE source_post_id = %d AND location = 'content' AND target_post_id > 0", // phpcs:ignore WordPress.DB.PreparedSQL
				$source_id
			)
		);
		$capacity = $max_allowed - $existing_links;
		if ( $capacity <= 0 ) {
			return 0; // already at or above target density.
		}
		$limit = min( $per_post, $capacity );

		// Targets already linked or already suggested (avoid duplicates).
		$skip = array();
		$linked = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT target_post_id FROM {$graph} WHERE source_post_id = %d AND target_post_id > 0", // phpcs:ignore WordPress.DB.PreparedSQL
				$source_id
			)
		);
		foreach ( $linked as $t ) {
			$skip[ (int) $t ] = true;
		}
		$suggested = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT target_post_id FROM {$sugg} WHERE source_post_id = %d AND status IN ('pending','approved','applied','rejected')", // phpcs:ignore WordPress.DB.PreparedSQL
				$source_id
			)
		);
		foreach ( $suggested as $t ) {
			$skip[ (int) $t ] = true;
		}

		$targets = Settings::target_post_types();
		$candidates = Tfidf::candidates( $source_id, $source['lang_code'], $targets, ( $limit * 4 ) + 10 );

		// Optional embedding re-rank (precision) on top of TF-IDF recall.
		if ( Gateway::embeddings_enabled() ) {
			$candidates = VectorStore::rerank( $source_id, $candidates );
		}

		$created = 0;
		foreach ( $candidates as $cand ) {
			if ( $created >= $limit ) {
				break;
			}
			$tid = (int) $cand['post_id'];
			if ( isset( $skip[ $tid ] ) || $tid === (int) $source_id ) {
				continue;
			}
			if ( (float) $cand['score'] < $min_rel ) {
				continue;
			}

			$anchor = AnchorGenerator::find( $text, $cand['title'], $min_words, $max_words );
			if ( null === $anchor ) {
				continue; // wrap-first: no natural anchor, no suggestion.
			}

			$relevance   = (float) $cand['score'];
			$naturalness = Naturalness::score( $anchor['anchor'], $relevance );
			$confidence  = Naturalness::confidence( $relevance, $naturalness );

			$inserted = $wpdb->insert(
				$sugg,
				array(
					'source_post_id'    => $source_id,
					'target_post_id'    => $tid,
					'target_url'        => $cand['url'],
					'anchor_text'       => self::trim_len( $anchor['anchor'], 255 ),
					'suggested_context' => $anchor['context'],
					'relevance_score'   => $relevance,
					'naturalness_score' => $naturalness,
					'confidence_score'  => $confidence,
					'type'              => 'outbound',
					'engine'            => isset( $cand['engine'] ) ? $cand['engine'] : 'tfidf',
					'lang_code'         => $source['lang_code'],
					'status'            => 'pending',
				),
				array( '%d', '%d', '%s', '%s', '%s', '%f', '%f', '%f', '%s', '%s', '%s', '%s' )
			);

			if ( $inserted ) {
				$skip[ $tid ] = true;
				$created++;
			}
		}

		return $created;
	}

	/**
	 * Begin a full-site suggestion scan.
	 *
	 * @return array Progress snapshot.
	 */
	public static function start_scan() {
		global $wpdb;
		$index = Tables::index();
		$types = Settings::crawl_post_types();

		// A fresh scan replaces the pending queue so new settings take effect.
		// Approved / applied / rejected suggestions are preserved.
		$sugg_table = Tables::suggestions();
		$wpdb->query( "DELETE FROM {$sugg_table} WHERE status = 'pending'" ); // phpcs:ignore WordPress.DB.PreparedSQL

		if ( empty( $types ) ) {
			$progress = array(
				'total'     => 0,
				'processed' => 0,
				'created'   => 0,
				'cursor'    => 0,
				'status'    => 'complete',
			);
			ProgressStore::set( 'suggest', $progress );
			return $progress;
		}

		$ph = implode( ',', array_fill( 0, count( $types ), '%s' ) );

		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$index} WHERE is_excluded = 0 AND post_status = 'publish' AND post_type IN ($ph)", // phpcs:ignore WordPress.DB.PreparedSQL
				$types
			)
		);

		$progress = array(
			'total'     => $total,
			'processed' => 0,
			'created'   => 0,
			'cursor'    => 0,
			'status'    => $total > 0 ? 'running' : 'complete',
		);
		ProgressStore::set( 'suggest', $progress );
		return $progress;
	}

	/**
	 * Process one batch of the suggestion scan (keyset cursor over the index).
	 *
	 * @param int $limit Posts per batch.
	 * @return array Progress snapshot with a `done` flag.
	 */
	public static function scan_batch( $limit = 10 ) {
		global $wpdb;
		$index    = Tables::index();
		$progress = ProgressStore::get( 'suggest' );
		if ( empty( $progress ) || 'running' !== $progress['status'] ) {
			$progress           = self::start_scan();
			$progress['status'] = $progress['total'] > 0 ? 'running' : 'complete';
		}

		$cursor = (int) $progress['cursor'];
		$types  = Settings::crawl_post_types();

		if ( empty( $types ) ) {
			$progress['status'] = 'complete';
			$progress['done']   = true;
			ProgressStore::set( 'suggest', $progress );
			return $progress;
		}

		$ph = implode( ',', array_fill( 0, count( $types ), '%s' ) );

		$args   = $types;
		$args[] = $cursor;
		$args[] = $limit;
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$index}
				 WHERE is_excluded = 0 AND post_status = 'publish' AND post_type IN ($ph) AND post_id > %d
				 ORDER BY post_id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL
				$args
			)
		);

		if ( empty( $ids ) ) {
			$progress['status'] = 'complete';
			$progress['done']   = true;
			ProgressStore::set( 'suggest', $progress );
			return $progress;
		}

		foreach ( $ids as $pid ) {
			$pid = (int) $pid;
			$progress['created'] += self::generate_for_post( $pid );
			$progress['cursor']   = $pid;
			$progress['processed']++;
		}

		$progress['done'] = false;
		ProgressStore::set( 'suggest', $progress );
		return $progress;
	}

	/**
	 * @param string $s   String.
	 * @param int    $max Max length.
	 * @return string
	 */
	private static function trim_len( $s, $max ) {
		// Cap by characters (not bytes) so multibyte anchors aren't split mid-character.
		if ( function_exists( 'mb_substr' ) ) {
			return ( mb_strlen( $s, 'UTF-8' ) <= $max ) ? $s : mb_substr( $s, 0, $max, 'UTF-8' );
		}
		return ( strlen( $s ) <= $max ) ? $s : substr( $s, 0, $max );
	}
}
