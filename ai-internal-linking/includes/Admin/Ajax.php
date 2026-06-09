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
use AILinking\Content\Editor;
use AILinking\LinkGraph\GraphAudits;

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
		add_action( 'wp_ajax_ailinking_apply', array( $this, 'apply' ) );
		add_action( 'wp_ajax_ailinking_undo', array( $this, 'undo' ) );
		add_action( 'wp_ajax_ailinking_run_audits', array( $this, 'run_audits' ) );
		add_action( 'wp_ajax_ailinking_remove_links', array( $this, 'remove_links' ) );
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
	 * Apply a suggestion to its source post (gated, non-destructive).
	 */
	public function apply() {
		$this->guard();
		$id  = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		if ( $id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'ai-internal-linking' ) ), 400 );
		}
		$result = Editor::apply( $id );
		wp_send_json_success( $result );
	}

	/**
	 * Undo an applied insertion.
	 */
	public function undo() {
		$this->guard();
		$ledger_id = isset( $_POST['ledger_id'] ) ? (int) $_POST['ledger_id'] : 0;
		$force     = ! empty( $_POST['force'] );
		if ( $ledger_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'ai-internal-linking' ) ), 400 );
		}
		$result = Editor::undo( $ledger_id, $force );
		wp_send_json_success( $result );
	}

	/**
	 * Recompute graph audits + click depth.
	 */
	public function run_audits() {
		$this->guard();
		GraphAudits::recompute_depth();
		$summary = GraphAudits::summary( true );
		wp_send_json_success( array( 'ok' => true, 'summary' => $summary ) );
	}

	/**
	 * Revert a batch of plugin-inserted links (clean removal while active).
	 */
	public function remove_links() {
		$this->guard();
		$result = Editor::remove_all_batch( 10 );
		GraphAudits::flush_summary();
		$result['done'] = ( 0 === (int) $result['remaining'] );
		wp_send_json_success( $result );
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
