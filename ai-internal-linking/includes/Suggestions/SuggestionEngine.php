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
use AILinking\Providers\UsageStats;
use AILinking\Content\ContentWriter;

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
				"SELECT post_id, title, parsed_text, lang_code, word_count, post_status, is_excluded FROM {$index} WHERE post_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL
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
		$created = 0;

		// Pass 1 — keyword evidence: imported queries (GSC/Semrush) another page
		// ranks for that this post mentions without linking. Intent-grounded, so
		// it runs first and consumes capacity before TF-IDF.
		if ( ! empty( $settings['keyword_suggestions'] ) ) {
			$kw_candidates = KeywordSuggester::find( $source_id, $text, $source['lang_code'], $targets, $min_words, $max_words, $skip, $limit );
			foreach ( $kw_candidates as $cand ) {
				if ( $created >= $limit ) {
					break;
				}
				$tid = (int) $cand['post_id'];
				if ( isset( $skip[ $tid ] ) ) {
					continue;
				}
				$relevance   = (float) $cand['score'];
				$naturalness = Naturalness::score( $cand['anchor'], $relevance );

				$inserted = self::insert_suggestion(
					$source_id,
					$source['lang_code'],
					array(
						'target_post_id' => $tid,
						'target_url'     => $cand['url'],
						'anchor_text'    => $cand['anchor'],
						'context'        => $cand['context'],
						'relevance'      => $relevance,
						'naturalness'    => $naturalness,
						'confidence'     => Naturalness::confidence( $relevance, $naturalness ),
						'engine'         => 'keyword',
					)
				);
				if ( $inserted ) {
					$skip[ $tid ] = true;
					$created++;
				}
			}
		}

		// Pass 2 — generative LLM: let a chat model pick contextual links from a
		// grounded candidate pool (works with any chat key, incl. Claude). Every
		// target is a real page and every anchor is verified verbatim in the body,
		// so nothing is fabricated. Skipped entirely when no chat provider is set.
		if ( $created < $limit && Settings::get( 'llm_suggestions', false ) && Gateway::chat_enabled() ) {
			// Fetch more than will be shown. The filter below drops anything the
			// page already links to and anything already judged, so asking for
			// exactly the configured number would leave a well-linked page
			// showing the model far fewer destinations than you chose.
			$pool = Tfidf::candidates( $source_id, $source['lang_code'], $targets, LlmSuggester::fetch_count( LlmSuggester::max_candidates() ) );
			$pool = array_values(
				array_filter(
					$pool,
					function ( $c ) use ( $skip, $source_id ) {
						$tid = (int) $c['post_id'];
						return $tid !== (int) $source_id && ! isset( $skip[ $tid ] );
					}
				)
			);

			if ( ! empty( $pool ) ) {
				$picks = LlmSuggester::find( $source_id, $text, (string) $source['title'], $pool, $min_words, $max_words, $limit - $created );
				foreach ( $picks as $pick ) {
					if ( $created >= $limit ) {
						break;
					}
					$tid = (int) $pick['post_id'];
					if ( isset( $skip[ $tid ] ) ) {
						continue;
					}
					// Relevance is the measured similarity of the page the model
					// chose, not the confidence the model reported in itself.
					// A self-assessment is not evidence, and taking one as
					// relevance is what let a pick that stated no confidence at
					// all outrank a strong measured match.
					$relevance   = (float) $pick['score'];
					$naturalness = Naturalness::score( $pick['anchor'], $relevance );

					$inserted = self::insert_suggestion(
						$source_id,
						$source['lang_code'],
						array(
							'target_post_id' => $tid,
							'target_url'     => $pick['url'],
							'anchor_text'    => $pick['anchor'],
							'context'        => $pick['context'],
							'relevance'      => $relevance,
							'raw'            => isset( $pick['confidence'] ) ? (float) $pick['confidence'] : $relevance,
							'naturalness'    => $naturalness,
							'confidence'     => Naturalness::confidence( $relevance, $naturalness ),
							'engine'         => 'llm',
						)
					);
					if ( $inserted ) {
						$skip[ $tid ] = true;
						$created++;
					}
				}
			}
		}

		// Pass 3 — TF-IDF relevance (always available) fills the remaining capacity.
		if ( $created < $limit ) {
			$candidates = Tfidf::candidates( $source_id, $source['lang_code'], $targets, ( $limit * 4 ) + 10 );

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

				$inserted = self::insert_suggestion(
					$source_id,
					$source['lang_code'],
					array(
						'target_post_id' => $tid,
						'target_url'     => $cand['url'],
						'anchor_text'    => $anchor['anchor'],
						'context'        => $anchor['context'],
						'relevance'      => $relevance,
						'naturalness'    => $naturalness,
						'confidence'     => Naturalness::confidence( $relevance, $naturalness ),
						'engine'         => isset( $cand['engine'] ) ? $cand['engine'] : 'tfidf',
					)
				);
				if ( $inserted ) {
					$skip[ $tid ] = true;
					$created++;
				}
			}
		}

		return $created;
	}

	/**
	 * Insert a pending suggestion row.
	 *
	 * @param int    $source_id Source post ID.
	 * @param string $lang_code Source language.
	 * @param array  $s         target_post_id, target_url, anchor_text, context,
	 *                          relevance, naturalness, confidence, engine.
	 * @return bool Whether the row was inserted.
	 */
	private static function insert_suggestion( $source_id, $lang_code, array $s ) {
		global $wpdb;

		// Every engine ends up here, so this is the one place worth asking the
		// writer whether its anchor could actually be linked. The engines match
		// against the page's plain text; the writer works on the stored HTML,
		// where the same phrase may sit inside an existing link or be split by a
		// tag. Storing a suggestion that can never be applied costs the reader a
		// review and then refuses at the last moment.
		$post = get_post( (int) $source_id );
		if ( ! $post instanceof \WP_Post || ! ContentWriter::can_place( $post, (string) $s['anchor_text'] ) ) {
			return false;
		}

		$engine = (string) $s['engine'];
		$anchor = (string) $s['anchor_text'];
		$target = (int) $s['target_post_id'];

		// The engine's own number, kept as it was reported, and the same number
		// put on the scale every other engine is measured against. Everything
		// below judges the calibrated one, because judging the raw one meant
		// judging three different things with one threshold.
		// What the engine reported about itself, kept for display, and the
		// measured number that is actually judged. For most engines they are the
		// same figure; the AI engine reports both, because its confidence is an
		// opinion and its candidate's similarity is a measurement.
		$measured   = (float) $s['relevance'];
		$raw        = isset( $s['raw'] ) ? (float) $s['raw'] : $measured;
		$relevance  = Relevance::calibrate( $engine, $measured );
		$confidence = Naturalness::confidence( $relevance, (float) $s['naturalness'] );

		// The minimum-relevance setting now governs every engine. It used to be
		// applied in the TF-IDF pass alone, which made turning the quality dial
		// up delete the most conservatively scored engine first and leave the
		// other two untouched.
		$min_rel = (float) Settings::get( 'min_relevance', 0.08 );
		if ( $relevance < Relevance::calibrate( 'tfidf', $min_rel ) ) {
			return false;
		}

		if ( self::anchor_conflicts( $source_id, $anchor ) ) {
			return false;
		}

		if ( self::anchor_saturated( $target, $anchor ) ) {
			return false;
		}

		return (bool) $wpdb->insert(
			Tables::suggestions(),
			array(
				'source_post_id'    => (int) $source_id,
				'target_post_id'    => $target,
				'target_url'        => $s['target_url'],
				'anchor_text'       => self::trim_len( $anchor, 255 ),
				'suggested_context' => $s['context'],
				'relevance_score'   => $relevance,
				'raw_score'         => $raw,
				'naturalness_score' => $s['naturalness'],
				'confidence_score'  => $confidence,
				'type'              => 'outbound',
				'engine'            => $engine,
				'lang_code'         => $lang_code,
				'status'            => 'pending',
			),
			array( '%d', '%d', '%s', '%s', '%s', '%f', '%f', '%f', '%f', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Whether this page already has a suggestion whose anchor is the same phrase,
	 * or contains it, or sits inside it.
	 *
	 * Deduplication was keyed on the destination alone, so one article could be
	 * given "British rule" pointing at one page and "British rule" pointing at
	 * another, and after both were applied the same phrase led two different
	 * places on the same page. Overlapping phrases are just as bad: "Gandhi" and
	 * "Gandhi in South Africa" compete for the same words, and the writer takes
	 * the first eligible occurrence, so which one wins is an accident of order.
	 *
	 * @param int    $source_id Source post ID.
	 * @param string $anchor    Proposed anchor.
	 * @return bool
	 */
	public static function anchor_conflicts( $source_id, $anchor ) {
		global $wpdb;

		$anchor = trim( (string) $anchor );
		if ( '' === $anchor ) {
			return true;
		}

		$table = Tables::suggestions();

		$existing = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT anchor_text FROM {$table}
				  WHERE source_post_id = %d AND status IN ('pending','approved','applied')", // phpcs:ignore WordPress.DB.PreparedSQL
				(int) $source_id
			)
		);

		foreach ( (array) $existing as $other ) {
			if ( self::anchors_collide( $anchor, (string) $other ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether two anchors compete for the same words on one page.
	 *
	 * Identical, or one contained in the other. Both cases end the same way: the
	 * writer takes the first eligible occurrence of a phrase, so two suggestions
	 * that overlap are two links fighting over the same run of text, and which
	 * one wins is decided by the order they happen to be applied in.
	 *
	 * Depends only on its arguments, so it is covered by the unit suite.
	 *
	 * @param string $a One anchor.
	 * @param string $b The other.
	 * @return bool
	 */
	public static function anchors_collide( $a, $b ) {
		$a = trim( (string) $a );
		$b = trim( (string) $b );
		if ( '' === $a || '' === $b ) {
			return false;
		}

		$lower = function_exists( 'mb_strtolower' ) ? 'mb_strtolower' : 'strtolower';
		$a     = 'mb_strtolower' === $lower ? mb_strtolower( $a, 'UTF-8' ) : strtolower( $a );
		$b     = 'mb_strtolower' === $lower ? mb_strtolower( $b, 'UTF-8' ) : strtolower( $b );

		return $a === $b || false !== strpos( $a, $b ) || false !== strpos( $b, $a );
	}

	/**
	 * Whether a destination already has as many links with this exact anchor as
	 * it should get.
	 *
	 * The keyword engine has always had this guard, because keyword anchors are
	 * exact-match by nature. The other two engines had none, and the TF-IDF
	 * engine builds its anchors out of the destination's own title, so it
	 * produces the same exact phrase over and over across a site. The
	 * anchor-diversity report then counted the result without anything having
	 * tried to prevent it.
	 *
	 * @param int    $target_id Destination post ID.
	 * @param string $anchor    Proposed anchor.
	 * @return bool
	 */
	public static function anchor_saturated( $target_id, $anchor ) {
		$max = (int) apply_filters( 'ailinking_max_exact_anchors_per_target', 3 );
		if ( $max <= 0 || $target_id <= 0 ) {
			return false;
		}

		global $wpdb;
		$graph = Tables::link_graph();
		$sugg  = Tables::suggestions();
		$lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( (string) $anchor ), 'UTF-8' ) : strtolower( trim( (string) $anchor ) );

		$live = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$graph} WHERE target_post_id = %d AND location = 'content' AND LOWER(anchor_text) = %s", // phpcs:ignore WordPress.DB.PreparedSQL
				(int) $target_id,
				$lower
			)
		);
		$open = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$sugg} WHERE target_post_id = %d AND status IN ('pending','approved') AND LOWER(anchor_text) = %s", // phpcs:ignore WordPress.DB.PreparedSQL
				(int) $target_id,
				$lower
			)
		);

		return ( $live + $open ) >= $max;
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

		// Baseline for the live token ticker: usage this run is everything
		// logged after this point. Survives pause/resume and page reloads
		// because it lives in the progress record, not the browser.
		$usage_log_id = UsageStats::max_log_id();

		if ( empty( $types ) ) {
			$progress = array(
				'total'        => 0,
				'processed'    => 0,
				'created'      => 0,
				'cursor'       => 0,
				'status'       => 'complete',
				'usage_log_id' => $usage_log_id,
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
			'total'        => $total,
			'processed'    => 0,
			'created'      => 0,
			'cursor'       => 0,
			'status'       => $total > 0 ? 'running' : 'complete',
			'usage_log_id' => $usage_log_id,
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

		// One scanner at a time (guards against double-clicks / overlapping runs).
		if ( ! ProgressStore::acquire( 'suggest' ) ) {
			$progress = ProgressStore::get( 'suggest' );
			if ( empty( $progress ) ) {
				$progress = array( 'total' => 0, 'processed' => 0, 'status' => 'running' );
			}
			$progress['done'] = ( 'complete' === ( isset( $progress['status'] ) ? $progress['status'] : '' ) );
			return $progress;
		}

		try {
			$index    = Tables::index();
			$progress = ProgressStore::get( 'suggest' );
			if ( empty( $progress ) || 'running' !== ( isset( $progress['status'] ) ? $progress['status'] : '' ) ) {
				// Not an active run (paused / stopped / complete / never started). Do
				// NOT auto-start here — the caller owns starts (run_suggest start=1, or
				// a resume). This is what lets a paused scan stay paused and later
				// resume from its saved cursor instead of restarting from scratch.
				if ( empty( $progress ) ) {
					$progress = array( 'total' => 0, 'processed' => 0, 'created' => 0, 'status' => 'complete' );
				}
				$progress['done'] = true;
				return $progress;
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
				try {
					$progress['created'] += self::generate_for_post( $pid );
				} catch ( \Throwable $e ) {
					$progress['last_error'] = 'post ' . $pid . ': ' . $e->getMessage();
				}
				$progress['cursor'] = $pid;
				$progress['processed']++;
			}

			$progress['done'] = false;
			ProgressStore::set( 'suggest', $progress );
			return $progress;
		} finally {
			ProgressStore::release( 'suggest' );
		}
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
