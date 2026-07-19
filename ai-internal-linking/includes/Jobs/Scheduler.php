<?php
/**
 * Background scheduling. Prefers Action Scheduler when present (e.g. bundled in a
 * later phase or provided by WooCommerce), otherwise falls back to WP-Cron. In
 * Phase 0a the admin-AJAX loop is the primary driver for immediate feedback; the
 * cron tick provides hands-off progress.
 *
 * @package AILinking
 */

namespace AILinking\Jobs;

use AILinking\Indexer\Indexer;
use AILinking\Integrations\GscFetcher;

defined( 'ABSPATH' ) || exit;

class Scheduler {

	/**
	 * Whether Action Scheduler is available.
	 *
	 * @return bool
	 */
	public static function has_action_scheduler() {
		return function_exists( 'as_enqueue_async_action' );
	}

	/**
	 * Register hooks (called from Plugin::boot).
	 */
	public function register() {
		add_action( 'ailinking_cron_index', array( __CLASS__, 'cron_index_tick' ) );
		add_action( 'ailinking_cron_gsc', array( __CLASS__, 'cron_gsc_tick' ) );
	}

	/**
	 * Daily hands-off Search Console refresh (only when auto-sync is on).
	 */
	public static function cron_gsc_tick() {
		GscFetcher::run_scheduled();
	}

	/**
	 * Hands-off cron tick: advance the index a few batches if a run is active.
	 */
	public static function cron_index_tick() {
		$progress = ProgressStore::get( 'index' );
		if ( empty( $progress ) || 'running' !== ( $progress['status'] ?? '' ) ) {
			return;
		}
		// Process a handful of batches per tick; keep each request memory-safe.
		for ( $i = 0; $i < 5; $i++ ) {
			$result = Indexer::process_batch( 15 );
			if ( ! empty( $result['done'] ) ) {
				break;
			}
		}
	}
}
