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
	 * Opportunity score: impressions weighted toward better (lower) positions. (pure)
	 *
	 * @param int   $impressions Impressions.
	 * @param float $position    Avg position.
	 * @return float
	 */
	public static function opportunity( $impressions, $position ) {
		$position = max( 1.0, (float) $position );
		return (float) $impressions * ( 21.0 - min( 20.0, $position ) ) / 20.0;
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
			'post_id'           => $post_id > 0 ? $post_id : null,
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
	 * @param array[] $rows Rows.
	 * @return int Number of rows inserted.
	 */
	public static function insert_rows( array $rows ) {
		global $wpdb;
		$table = Tables::keywords();
		$n     = 0;
		foreach ( $rows as $r ) {
			if ( $wpdb->insert( $table, $r, array( '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%f', '%f', '%d', '%f' ) ) ) {
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
