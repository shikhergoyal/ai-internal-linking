<?php
/**
 * Fetch Search Console performance data into the keywords table, one API page per
 * step (keyset over startRow), reusing KeywordImporter scoring so API rows and
 * CSV rows are indistinguishable downstream. Bounded by a max-rows ceiling (rows
 * arrive click-desc, so the tail is low value) to stay safe on very large sites.
 *
 * Drives the same ProgressStore contract as the indexer, so the admin AJAX loop
 * and the daily cron tick can both advance it (one worker at a time via the lock).
 *
 * @package AILinking
 */

namespace AILinking\Integrations;

use AILinking\Support\Settings;
use AILinking\Jobs\ProgressStore;

defined( 'ABSPATH' ) || exit;

class GscFetcher {

	const JOB       = 'gsc';
	const ROW_LIMIT = 5000;

	/**
	 * Upper bound on rows stored per fetch (filterable). Rows are returned in
	 * clicks-descending order, so this keeps the highest-value keywords.
	 *
	 * @return int
	 */
	public static function max_rows() {
		return (int) apply_filters( 'ailinking_gsc_max_rows', 25000 );
	}

	/**
	 * Begin a fetch: resolve property + date window, clear prior GSC rows.
	 *
	 * @return array Progress snapshot.
	 */
	public static function start_fetch() {
		$property = (string) Settings::get( 'gsc_property', '' );
		if ( '' === $property ) {
			$progress = array( 'status' => 'complete', 'error' => 'no_property', 'imported' => 0, 'cursor' => 0, 'done' => true );
			ProgressStore::set( self::JOB, $progress );
			return $progress;
		}

		$range = (int) Settings::get( 'gsc_range_days', 90 );
		if ( $range <= 0 ) {
			$range = 90;
		}

		// Clean re-import (matches CSV behaviour: replace prior rows from this source).
		KeywordImporter::delete_source( 'gsc' );

		$progress = array(
			'property'   => $property,
			'start_date' => gmdate( 'Y-m-d', time() - ( $range * DAY_IN_SECONDS ) ),
			'end_date'   => gmdate( 'Y-m-d' ),
			'cursor'     => 0,
			'imported'   => 0,
			'pages'      => 0,
			'status'     => 'running',
		);
		ProgressStore::set( self::JOB, $progress );
		return $progress;
	}

	/**
	 * Fetch and store one page of results.
	 *
	 * @return array Progress snapshot with a `done` flag.
	 */
	public static function fetch_batch() {
		if ( ! ProgressStore::acquire( self::JOB ) ) {
			$progress         = ProgressStore::get( self::JOB );
			$progress['done'] = ( 'complete' === ( isset( $progress['status'] ) ? $progress['status'] : '' ) );
			return $progress;
		}

		try {
			$progress = ProgressStore::get( self::JOB );
			$status   = isset( $progress['status'] ) ? $progress['status'] : '';
			if ( empty( $progress ) || 'running' !== $status ) {
				$progress = self::start_fetch();
				if ( 'running' !== $progress['status'] ) {
					$progress['done'] = true;
					return $progress;
				}
			}

			$res = SearchConsoleClient::query(
				$progress['property'],
				$progress['start_date'],
				$progress['end_date'],
				(int) $progress['cursor'],
				self::ROW_LIMIT
			);

			if ( empty( $res['ok'] ) ) {
				$progress['status'] = 'paused';
				$progress['error']  = isset( $res['error']['message'] ) ? $res['error']['message'] : 'error';
				$progress['done']   = true;
				self::finish( $progress );
				ProgressStore::set( self::JOB, $progress );
				return $progress;
			}

			$rows  = $res['rows'];
			$count = count( $rows );
			$batch = array();
			foreach ( $rows as $row ) {
				$keys    = isset( $row['keys'] ) && is_array( $row['keys'] ) ? $row['keys'] : array();
				$keyword = isset( $keys[0] ) ? (string) $keys[0] : '';
				$page    = isset( $keys[1] ) ? (string) $keys[1] : '';
				if ( '' === $keyword ) {
					continue;
				}
				$batch[] = KeywordImporter::build_row(
					$keyword,
					$page,
					(int) round( isset( $row['clicks'] ) ? $row['clicks'] : 0 ),
					(int) round( isset( $row['impressions'] ) ? $row['impressions'] : 0 ),
					(float) ( isset( $row['ctr'] ) ? $row['ctr'] : 0 ),
					(float) ( isset( $row['position'] ) ? $row['position'] : 0 ),
					'gsc'
				);
			}

			$progress['imported'] += KeywordImporter::insert_rows( $batch );
			$progress['cursor']    = (int) $progress['cursor'] + $count;
			$progress['pages']     = (int) $progress['pages'] + 1;

			if ( $count < self::ROW_LIMIT || $progress['imported'] >= self::max_rows() ) {
				$progress['status'] = 'complete';
				$progress['done']   = true;
				self::finish( $progress );
			} else {
				$progress['done'] = false;
			}

			ProgressStore::set( self::JOB, $progress );
			return $progress;
		} finally {
			ProgressStore::release( self::JOB );
		}
	}

	/**
	 * Record the outcome of a finished (complete or paused) run.
	 *
	 * @param array $progress Progress snapshot.
	 */
	private static function finish( array $progress ) {
		Settings::update(
			array(
				'gsc_last_sync'   => time(),
				'gsc_last_status' => isset( $progress['status'] ) ? (string) $progress['status'] : '',
				'gsc_last_count'  => (int) ( isset( $progress['imported'] ) ? $progress['imported'] : 0 ),
			)
		);
	}

	/**
	 * Run a whole fetch to completion (used by the daily cron tick).
	 */
	public static function run_scheduled() {
		if ( ! Settings::get( 'gsc_auto_sync', false ) ) {
			return;
		}
		if ( ! GoogleServiceAccount::is_connected() ) {
			return;
		}

		self::start_fetch();
		$guard = 0;
		do {
			$progress = self::fetch_batch();
			$guard++;
		} while ( empty( $progress['done'] ) && $guard < 30 );
	}
}
