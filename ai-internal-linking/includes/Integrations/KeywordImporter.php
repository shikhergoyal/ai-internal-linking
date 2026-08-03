<?php
/**
 * Keyword import (Google Search Console / generic CSV exports).
 * Detects columns, computes striking-distance (position 5-20) + an opportunity
 * score, resolves landing pages to post IDs, and stores rows for the suggestion
 * engine. Rows arrive either from a CSV upload (import_csv) or the Search Console
 * API fetch (GscFetcher) — both build rows through build_row() so scoring is
 * identical regardless of source.
 *
 * @package AILinking
 */

namespace AILinking\Integrations;

use AILinking\Support\Tables;
use AILinking\Content\UrlResolver;

defined( 'ABSPATH' ) || exit;

class KeywordImporter {

	/**
	 * Map a CSV header row to column indexes (pure; unit-tested).
	 *
	 * @param array $header Header cells.
	 * @return array<string,int>
	 */
	public static function header_map( array $header ) {
		$map = array();
		foreach ( $header as $i => $raw ) {
			$h = strtolower( trim( (string) $raw ) );
			if ( '' === $h ) {
				continue;
			}
			if ( ! isset( $map['keyword'] ) && ( 'query' === $h || 'keyword' === $h || 'queries' === $h || 'search term' === $h ) ) {
				$map['keyword'] = $i;
			} elseif ( ! isset( $map['clicks'] ) && 'clicks' === $h ) {
				$map['clicks'] = $i;
			} elseif ( ! isset( $map['impressions'] ) && ( 'impressions' === $h || 'impr.' === $h ) ) {
				$map['impressions'] = $i;
			} elseif ( ! isset( $map['ctr'] ) && ( 'ctr' === $h || 'click through rate' === $h ) ) {
				$map['ctr'] = $i;
			} elseif ( ! isset( $map['position'] ) && ( 'position' === $h || 'avg. position' === $h || 'average position' === $h || 'pos' === $h ) ) {
				$map['position'] = $i;
			} elseif ( ! isset( $map['page'] ) && ( 'page' === $h || 'url' === $h || 'landing page' === $h || 'address' === $h ) ) {
				$map['page'] = $i;
			}
		}
		return $map;
	}

	/**
	 * Parse a numeric cell (strips %, commas, spaces). (pure)
	 *
	 * @param string $s Cell.
	 * @return float
	 */
	public static function parse_number( $s ) {
		$s = str_replace( array( '%', ',', ' ' ), '', (string) $s );
		return is_numeric( $s ) ? (float) $s : 0.0;
	}

	/**
	 * Striking-distance: positions 5-20. (pure)
	 *
	 * @param float $position Avg position.
	 * @return bool
	 */
	public static function is_striking( $position ) {
		return $position >= 5.0 && $position <= 20.0;
	}

	/**
	 * Approximate organic CTR at an average position. Used to express
	 * opportunity in clicks rather than in raw impressions. (pure)
	 *
	 * @param float $position Avg position.
	 * @return float CTR in 0..1.
	 */
	public static function ctr_at( $position ) {
		$i = (int) round( max( 1.0, (float) $position ) );

		$curve = array(
			1 => 0.280, 2 => 0.150, 3 => 0.110, 4 => 0.080, 5 => 0.060,
			6 => 0.048, 7 => 0.039, 8 => 0.032, 9 => 0.028, 10 => 0.025,
		);
		if ( isset( $curve[ $i ] ) ) {
			return $curve[ $i ];
		}
		if ( $i <= 20 ) {
			// Page-one tail: 0.025 at 10 decaying to 0.008 at 20.
			return max( 0.008, 0.025 - ( ( $i - 10 ) * 0.0017 ) );
		}
		return 0.005; // page two and beyond, effectively flat.
	}

	/**
	 * Opportunity score: the extra clicks this keyword could realistically win,
	 * which is what an internal link is being spent on. (pure)
	 *
	 * Deliberately NOT "impressions weighted toward position 1". That older
	 * shape peaked at position 1, so keywords already ranking first scored
	 * highest and consumed the link budget, even though a page at #1 is the
	 * one that needs another internal link least. Here:
	 *
	 *   gain  = CTR at the top 3 minus CTR where you sit now, floored at zero,
	 *           so anything already at or above position 3 scores 0.
	 *   reach = 1 inside striking distance, then decaying past position 20,
	 *           because one internal link will not drag page 5 to the top 3.
	 *
	 * The result peaks around position 20 and falls away on both sides, which
	 * lines up with is_striking() (5-20) instead of fighting it.
	 *
	 * @param int   $impressions Impressions.
	 * @param float $position    Avg position.
	 * @return float
	 */
	public static function opportunity( $impressions, $position ) {
		$impressions = max( 0, (int) $impressions );
		$position    = max( 1.0, (float) $position );

		$gain = self::ctr_at( 3.0 ) - self::ctr_at( $position );
		if ( $gain <= 0.0 ) {
			return 0.0; // already at or above the target position.
		}

		$reach = ( $position <= 20.0 ) ? 1.0 : ( 20.0 / $position );

		return $impressions * $gain * $reach;
	}

	/**
	 * Build one normalized keyword row from raw values. Shared by the CSV import
	 * and the Search Console API fetch so both score identically. (pure aside from
	 * the URL->post-id lookup)
	 *
	 * @param string $keyword     Query / keyword.
	 * @param string $page        Landing page URL ('' if none).
	 * @param int    $clicks      Clicks.
	 * @param int    $impressions Impressions.
	 * @param float  $ctr         Click-through rate.
	 * @param float  $position    Average position.
	 * @param string $source      'gsc'|'csv'.
	 * @return array
	 */
	public static function build_row( $keyword, $page, $clicks, $impressions, $ctr, $position, $source ) {
		$keyword = (string) $keyword;
		$page    = (string) $page;
		$post_id = ( '' !== $page ) ? UrlResolver::to_post_id( $page ) : 0;
		$source  = in_array( $source, array( 'gsc', 'csv' ), true ) ? $source : 'csv';

		return array(
			'keyword'           => substr( $keyword, 0, 500 ),
			'keyword_norm'      => substr( strtolower( $keyword ), 0, 191 ),
			'source'            => $source,
			'page_url'          => substr( $page, 0, 2048 ),
			// 0, not null: the unique key below relies on it, and MySQL treats
			// every NULL as distinct, so unmapped phrases would keep duplicating.
			'post_id'           => $post_id > 0 ? $post_id : 0,
			'clicks'            => (int) $clicks,
			'impressions'       => (int) $impressions,
			'position'          => (float) $position,
			'ctr'               => (float) $ctr,
			'is_striking'       => self::is_striking( (float) $position ) ? 1 : 0,
			'opportunity_score' => self::opportunity( (int) $impressions, (float) $position ),
		);
	}

	/**
	 * Delete all keyword rows from one source (used before a clean re-import).
	 *
	 * @param string $source Source slug.
	 */
	public static function delete_source( $source ) {
		global $wpdb;
		$wpdb->delete( Tables::keywords(), array( 'source' => (string) $source ), array( '%s' ) );
	}

	/**
	 * Insert a batch of rows built by build_row().
	 *
	 * Upserts rather than inserts. Search Console reports the same phrase for a
	 * page across several date ranges and property variants, so a plain insert
	 * stored the phrase again every time: one real site accumulated 107
	 * duplicated groups, several repeated eight times, each copy consuming a
	 * slot of the 500-phrase pool the engine actually looks at. The unique key
	 * on (keyword_norm, post_id, source) makes the repeat collide, and the
	 * update keeps the newer metrics instead of dropping the row on the floor.
	 *
	 * @param array[] $rows Rows.
	 * @return int Number of rows stored (inserted or refreshed).
	 */
	public static function insert_rows( array $rows ) {
		global $wpdb;
		$table = Tables::keywords();
		$n     = 0;

		foreach ( $rows as $r ) {
			$ok = $wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$table}
					 (keyword, keyword_norm, source, page_url, post_id, clicks, impressions, position, ctr, is_striking, opportunity_score)
					 VALUES (%s, %s, %s, %s, %d, %d, %d, %f, %f, %d, %f)
					 ON DUPLICATE KEY UPDATE
					   keyword = VALUES(keyword),
					   page_url = VALUES(page_url),
					   clicks = VALUES(clicks),
					   impressions = VALUES(impressions),
					   position = VALUES(position),
					   ctr = VALUES(ctr),
					   is_striking = VALUES(is_striking),
					   opportunity_score = VALUES(opportunity_score)", // phpcs:ignore WordPress.DB.PreparedSQL
					$r['keyword'],
					$r['keyword_norm'],
					$r['source'],
					$r['page_url'],
					(int) $r['post_id'],
					(int) $r['clicks'],
					(int) $r['impressions'],
					(float) $r['position'],
					(float) $r['ctr'],
					(int) $r['is_striking'],
					(float) $r['opportunity_score']
				)
			);
			if ( false !== $ok ) {
				$n++;
			}
		}

		return $n;
	}

	/**
	 * Import a CSV file.
	 *
	 * @param string $path   Server path to the uploaded CSV.
	 * @param string $source 'gsc'|'csv'.
	 * @return array{ok:bool,imported?:int,reason?:string}
	 */
	public static function import_csv( $path, $source = 'csv' ) {
		if ( ! is_readable( $path ) ) {
			return array( 'ok' => false, 'reason' => 'unreadable' );
		}
		$handle = fopen( $path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $handle ) {
			return array( 'ok' => false, 'reason' => 'open_failed' );
		}

		$header = fgetcsv( $handle );
		if ( ! is_array( $header ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			return array( 'ok' => false, 'reason' => 'empty' );
		}
		$map = self::header_map( $header );
		if ( ! isset( $map['keyword'] ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			return array( 'ok' => false, 'reason' => 'no_keyword_column' );
		}

		global $wpdb;
		$table  = Tables::keywords();
		$source = in_array( $source, array( 'gsc', 'csv' ), true ) ? $source : 'csv';

		// Replace previous rows from the same source for a clean re-import.
		$wpdb->delete( $table, array( 'source' => $source ), array( '%s' ) );

		$batch    = array();
		$imported = 0;

		while ( ( $row = fgetcsv( $handle ) ) !== false ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
			$keyword = isset( $map['keyword'], $row[ $map['keyword'] ] ) ? trim( (string) $row[ $map['keyword'] ] ) : '';
			if ( '' === $keyword ) {
				continue;
			}
			$clicks      = isset( $map['clicks'], $row[ $map['clicks'] ] ) ? (int) self::parse_number( $row[ $map['clicks'] ] ) : 0;
			$impressions = isset( $map['impressions'], $row[ $map['impressions'] ] ) ? (int) self::parse_number( $row[ $map['impressions'] ] ) : 0;
			$ctr         = isset( $map['ctr'], $row[ $map['ctr'] ] ) ? self::parse_number( $row[ $map['ctr'] ] ) : 0.0;
			$position    = isset( $map['position'], $row[ $map['position'] ] ) ? self::parse_number( $row[ $map['position'] ] ) : 0.0;
			$page        = isset( $map['page'], $row[ $map['page'] ] ) ? trim( (string) $row[ $map['page'] ] ) : '';

			$batch[] = self::build_row( $keyword, $page, $clicks, $impressions, $ctr, $position, $source );
			$imported++;

			if ( count( $batch ) >= 100 ) {
				self::flush( $batch );
				$batch = array();
			}
		}
		if ( $batch ) {
			self::flush( $batch );
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		return array( 'ok' => true, 'imported' => $imported );
	}

	/**
	 * Bulk-insert a batch of keyword rows.
	 *
	 * @param array[] $rows Rows.
	 */
	private static function flush( array $rows ) {
		self::insert_rows( $rows );
	}
}
