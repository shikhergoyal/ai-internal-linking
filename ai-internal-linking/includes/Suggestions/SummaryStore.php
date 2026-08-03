<?php
/**
 * Reads, and lazily builds, the stored one-paragraph summary of each page.
 *
 * Summaries are built on first use rather than during indexing, because scoring
 * a sentence depends on knowing which words the whole site uses, and that is
 * not known until the whole site has been indexed. A page indexed first would
 * otherwise be summarised against an almost empty corpus, where nothing looks
 * common and boilerplate scores as well as substance.
 *
 * The result is cached in the index table and cleared whenever a page's content
 * changes, so the cost is paid once per page rather than once per scan.
 *
 * @package AILinking
 */

namespace AILinking\Suggestions;

use AILinking\Support\Tables;

defined( 'ABSPATH' ) || exit;

class SummaryStore {

	/**
	 * Summaries for a set of pages, building any that are missing.
	 *
	 * @param int[] $post_ids  Page ids.
	 * @param int   $max_words Word budget per summary.
	 * @return array<int,string> post id => summary (absent when none could be built).
	 */
	public static function for_posts( array $post_ids, $max_words ) {
		global $wpdb;

		$ids = array_values( array_unique( array_map( 'intval', $post_ids ) ) );
		if ( empty( $ids ) ) {
			return array();
		}

		$max_words = Summarizer::clamp_words( $max_words );
		$table     = Tables::index();
		$ph        = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, summary, parsed_text FROM {$table} WHERE post_id IN ({$ph})", // phpcs:ignore WordPress.DB.PreparedSQL
				$ids
			),
			ARRAY_A
		);

		$out     = array();
		$missing = array();
		foreach ( (array) $rows as $r ) {
			$pid = (int) $r['post_id'];
			$sum = trim( (string) $r['summary'] );
			if ( '' !== $sum ) {
				// Stored at some other length; shorten in place rather than
				// rebuilding, so lowering the setting costs nothing.
				$out[ $pid ] = Summarizer::trim_to_words( $sum, $max_words );
				continue;
			}
			$missing[ $pid ] = (string) $r['parsed_text'];
		}

		if ( empty( $missing ) ) {
			return $out;
		}

		$common = Tfidf::site_wide_terms();
		foreach ( $missing as $pid => $text ) {
			$weights = array();
			foreach ( Tfidf::term_frequencies( $text ) as $term => $tf ) {
				if ( ! isset( $common[ $term ] ) ) {
					$weights[ $term ] = $tf;
				}
			}

			// Build at the maximum, store that, and serve a trimmed copy. Raising
			// the setting later then costs nothing either.
			$full = Summarizer::summarize( $text, $weights, Summarizer::MAX_WORDS );
			if ( '' === $full ) {
				continue; // too short, or nothing distinctive: caller falls back
			}

			$wpdb->update( $table, array( 'summary' => $full ), array( 'post_id' => $pid ), array( '%s' ), array( '%d' ) );
			$out[ $pid ] = Summarizer::trim_to_words( $full, $max_words );
		}

		return $out;
	}

	/**
	 * Drop the stored summary for a page, so it is rebuilt on next use.
	 *
	 * @param int $post_id Page id.
	 * @return void
	 */
	public static function forget( $post_id ) {
		global $wpdb;
		$wpdb->update( Tables::index(), array( 'summary' => '' ), array( 'post_id' => (int) $post_id ), array( '%s' ), array( '%d' ) );
	}

	/**
	 * Drop every stored summary, after something changes site-wide.
	 *
	 * @return void
	 */
	public static function forget_all() {
		global $wpdb;
		$table = Tables::index();
		$wpdb->query( "UPDATE {$table} SET summary = ''" ); // phpcs:ignore WordPress.DB.PreparedSQL
	}
}
