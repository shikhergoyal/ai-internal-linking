<?php
/**
 * Admin-AJAX handlers that drive indexing, suggestion scans, and status changes.
 * Every handler is nonce-verified and capability-gated.
 *
 * @package AILinking
 */

namespace AILinking\Admin;

use AILinking\Security\Capabilities;
use AILinking\Support\Tables;
use AILinking\Indexer\Indexer;
use AILinking\Suggestions\SuggestionEngine;

defined( 'ABSPATH' ) || exit;

class Ajax {

	const BATCHES_PER_REQUEST = 3;

	/**
	 * Register AJAX endpoints.
	 */
	public function register() {
		add_action( 'wp_ajax_ailinking_run_index', array( $this, 'run_index' ) );
		add_action( 'wp_ajax_ailinking_run_suggest', array( $this, 'run_suggest' ) );
		add_action( 'wp_ajax_ailinking_set_status', array( $this, 'set_status' ) );
	}

	/**
	 * Guard shared by all handlers.
	 */
	private function guard() {
		check_ajax_referer( 'ailinking_ajax', 'nonce' );
		if ( ! Capabilities::can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ai-internal-linking' ) ), 403 );
		}
	}

	/**
	 * Advance the index a few batches.
	 */
	public function run_index() {
		$this->guard();

		$start = ! empty( $_POST['start'] );
		if ( $start ) {
			Indexer::start_full_reindex();
		}

		$progress = array();
		for ( $i = 0; $i < self::BATCHES_PER_REQUEST; $i++ ) {
			$progress = Indexer::process_batch( 15 );
			if ( ! empty( $progress['done'] ) ) {
				break;
			}
		}

		wp_send_json_success( $this->shape( $progress ) );
	}

	/**
	 * Advance the suggestion scan a few batches.
	 */
	public function run_suggest() {
		$this->guard();

		$start = ! empty( $_POST['start'] );
		if ( $start ) {
			SuggestionEngine::start_scan();
		}

		$progress = array();
		for ( $i = 0; $i < self::BATCHES_PER_REQUEST; $i++ ) {
			$progress = SuggestionEngine::scan_batch( 8 );
			if ( ! empty( $progress['done'] ) ) {
				break;
			}
		}

		wp_send_json_success( $this->shape( $progress ) );
	}

	/**
	 * Change a suggestion's review status.
	 */
	public function set_status() {
		$this->guard();
		global $wpdb;

		$id     = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
		$allowed = array( 'pending', 'approved', 'rejected' );

		if ( $id <= 0 || ! in_array( $status, $allowed, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'ai-internal-linking' ) ), 400 );
		}

		$updated = $wpdb->update(
			Tables::suggestions(),
			array( 'status' => $status ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			wp_send_json_error( array( 'message' => __( 'Could not update.', 'ai-internal-linking' ) ), 500 );
		}

		wp_send_json_success( array( 'id' => $id, 'status' => $status ) );
	}

	/**
	 * Normalise a progress array for the client.
	 *
	 * @param array $progress Progress snapshot.
	 * @return array
	 */
	private function shape( $progress ) {
		$total     = (int) ( $progress['total'] ?? 0 );
		$processed = (int) ( $progress['processed'] ?? 0 );
		$done      = ! empty( $progress['done'] ) || 'complete' === ( $progress['status'] ?? '' );
		$percent   = $total > 0 ? min( 100, (int) round( ( $processed / $total ) * 100 ) ) : 100;

		return array(
			'total'     => $total,
			'processed' => $processed,
			'created'   => (int) ( $progress['created'] ?? 0 ),
			'percent'   => $percent,
			'done'      => $done,
		);
	}
}
