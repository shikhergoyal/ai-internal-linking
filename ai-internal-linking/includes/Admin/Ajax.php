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
use AILinking\Support\Settings;
use AILinking\Indexer\Indexer;
use AILinking\Suggestions\SuggestionEngine;
use AILinking\Suggestions\VectorStore;
use AILinking\Content\Editor;
use AILinking\LinkGraph\GraphAudits;
use AILinking\Providers\Registry;
use AILinking\Providers\Gateway;
use AILinking\Clusters\ClusterAnalyzer;
use AILinking\Integrations\GscFetcher;
use AILinking\Integrations\GoogleServiceAccount;

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
		add_action( 'wp_ajax_ailinking_run_embed', array( $this, 'run_embed' ) );
		add_action( 'wp_ajax_ailinking_test_connection', array( $this, 'test_connection' ) );
		add_action( 'wp_ajax_ailinking_run_clusters', array( $this, 'run_clusters' ) );
		add_action( 'wp_ajax_ailinking_gsc_fetch', array( $this, 'gsc_fetch' ) );
	}

	/**
	 * Fetch one page of Search Console data (drives the progress bar).
	 */
	public function gsc_fetch() {
		$this->guard();

		if ( ! GoogleServiceAccount::is_connected() ) {
			wp_send_json_success(
				array(
					'total'      => 0,
					'processed'  => 0,
					'created'    => 0,
					'percent'    => 100,
					'done'       => true,
					'last_error' => __( 'Not connected to Search Console.', 'ai-internal-linking' ),
				)
			);
		}

		if ( ! empty( $_POST['start'] ) ) {
			GscFetcher::start_fetch();
		}

		// One API page per request (each page can carry thousands of rows).
		$progress = GscFetcher::fetch_batch();

		$imported = (int) ( $progress['imported'] ?? 0 );
		$done     = ! empty( $progress['done'] );
		$error    = isset( $progress['error'] ) ? (string) $progress['error'] : '';
		$total    = $done ? max( 1, $imported ) : $imported + GscFetcher::ROW_LIMIT;
		$percent  = $done ? 100 : ( $total > 0 ? min( 95, (int) floor( ( $imported / $total ) * 100 ) ) : 10 );

		wp_send_json_success(
			array(
				'total'      => $total,
				'processed'  => $imported,
				'created'    => $imported,
				'percent'    => $percent,
				'done'       => $done,
				'last_error' => $error,
			)
		);
	}

	/**
	 * Analyze all clusters (hub-and-spoke authority + flat detection).
	 */
	public function run_clusters() {
		$this->guard();
		$count = ClusterAnalyzer::analyze_all();
		wp_send_json_success( array( 'ok' => true, 'count' => $count ) );
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

		// Starting a scan returns immediately so the progress bar appears at 0%
		// right away. The work runs on the follow-up polls: with AI suggestions
		// on, each post is a network round-trip to the model, so a single request
		// must not block on many (that reads as a "frozen" scan / a timeout).
		if ( ! empty( $_POST['start'] ) ) {
			$progress = SuggestionEngine::start_scan();
			wp_send_json_success( $this->shape( $progress ) );
		}

		$llm      = Settings::get( 'llm_suggestions', false ) && Gateway::chat_enabled();
		$per      = $llm ? 1 : 8;                // posts per chunk
		$deadline = time() + ( $llm ? 12 : 20 ); // wall-clock budget per request (seconds)

		$progress = array();
		do {
			$progress = SuggestionEngine::scan_batch( $per );
			if ( ! empty( $progress['done'] ) ) {
				break;
			}
		} while ( time() < $deadline );

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
		GraphAudits::recompute_all();
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
	 * Advance the embedding build a few batches.
	 */
	public function run_embed() {
		$this->guard();

		// No embeddings provider -> the build has nothing to do. Say so, instead
		// of silently completing (Anthropic/Claude has no embeddings API).
		if ( ! Gateway::embeddings_enabled() ) {
			wp_send_json_success(
				array(
					'total'      => 0,
					'processed'  => 0,
					'created'    => 0,
					'percent'    => 100,
					'done'       => true,
					'last_error' => __( 'No embeddings provider is set, so there is nothing to build. Anthropic/Claude has no embeddings API — add an embedding-capable key (OpenAI, Voyage, Gemini, Mistral…) and select it as the Embeddings provider under Settings. For Claude-powered links, enable “AI link suggestions” under Settings instead (no embeddings needed).', 'ai-internal-linking' ),
				)
			);
		}

		if ( ! empty( $_POST['start'] ) ) {
			VectorStore::start_build();
		}
		$progress = array();
		for ( $i = 0; $i < self::BATCHES_PER_REQUEST; $i++ ) {
			$progress = VectorStore::build_batch( 5 );
			if ( ! empty( $progress['done'] ) ) {
				break;
			}
		}
		$shaped          = $this->shape( $progress );
		$shaped['error'] = isset( $progress['error'] ) ? $progress['error'] : '';
		wp_send_json_success( $shaped );
	}

	/**
	 * Probe a provider with a supplied key (not stored). Returns ok + message.
	 */
	public function test_connection() {
		$this->guard();
		$provider_id = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : '';
		$plane       = isset( $_POST['plane'] ) && 'embedding' === $_POST['plane'] ? 'embedding' : 'chat';
		$key         = isset( $_POST['api_key'] ) ? trim( (string) wp_unslash( $_POST['api_key'] ) ) : '';
		$base        = isset( $_POST['base_url'] ) ? esc_url_raw( wp_unslash( $_POST['base_url'] ) ) : '';
		$model       = isset( $_POST['model'] ) ? sanitize_text_field( wp_unslash( $_POST['model'] ) ) : '';

		$provider = Registry::get( $provider_id );
		if ( ! $provider ) {
			wp_send_json_success( array( 'ok' => false, 'message' => __( 'Unknown provider.', 'ai-internal-linking' ) ) );
		}

		$models = $provider->default_models();
		if ( '' === $model ) {
			$list  = ( 'embedding' === $plane ) ? $models['embedding'] : $models['chat'];
			$model = ! empty( $list ) ? $list[0] : '';
		}

		$ctx = array(
			'api_key'  => $key,
			'base_url' => '' !== $base ? $base : $provider->default_base_url(),
			'model'    => $model,
			'timeout'  => 20,
			'extra'    => array(),
		);

		if ( 'embedding' === $plane && $provider->supports_embeddings() ) {
			$resp = $provider->embed( array( 'input' => array( 'ping' ) ), $ctx );
		} else {
			$resp = $provider->chat( array( 'messages' => array( array( 'role' => 'user', 'content' => 'ping' ) ), 'max_tokens' => 5, 'temperature' => 0 ), $ctx );
		}

		if ( ! empty( $resp['ok'] ) ) {
			wp_send_json_success( array( 'ok' => true, 'message' => __( 'Connection OK.', 'ai-internal-linking' ) ) );
		}
		$msg = isset( $resp['error']['message'] ) ? $resp['error']['message'] : __( 'Connection failed.', 'ai-internal-linking' );
		wp_send_json_success( array( 'ok' => false, 'message' => $msg ) );
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
		if ( $total > 0 && $processed > $total ) {
			$processed = $total; // never display past 100%.
		}
		$done      = ! empty( $progress['done'] ) || 'complete' === ( $progress['status'] ?? '' );
		$percent   = $total > 0 ? min( 100, (int) round( ( $processed / $total ) * 100 ) ) : 100;

		return array(
			'total'      => $total,
			'processed'  => $processed,
			'created'    => (int) ( $progress['created'] ?? 0 ),
			'percent'    => $percent,
			'done'       => $done,
			'last_error' => isset( $progress['last_error'] ) ? (string) $progress['last_error'] : '',
		);
	}
}
