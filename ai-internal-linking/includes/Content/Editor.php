<?php
/**
 * Non-destructive apply / undo orchestrator.
 *
 * Apply: refuse non-auto systems -> locate + guard -> revision + ledger backup ->
 * write -> re-index. Undo: restore the exact prior field value from the ledger.
 * Nothing is ever written without a backup, and a failed write rolls back cleanly.
 *
 * @package AILinking
 */

namespace AILinking\Content;

use AILinking\Plugin;
use AILinking\Support\Tables;
use AILinking\Detectors\BuilderDetector;
use AILinking\Indexer\Indexer;

defined( 'ABSPATH' ) || exit;

class Editor {

	/**
	 * Apply a suggestion to its source post.
	 *
	 * @param int $suggestion_id Suggestion ID.
	 * @return array{ok:bool,reason?:string,ledger_id?:int,system?:string}
	 */
	public static function apply( $suggestion_id ) {
		global $wpdb;
		$sugg_table = Tables::suggestions();

		$sugg = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$sugg_table} WHERE id = %d", $suggestion_id ), // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);
		if ( ! $sugg ) {
			return array( 'ok' => false, 'reason' => 'not_found' );
		}
		if ( ! in_array( $sugg['status'], array( 'pending', 'approved' ), true ) ) {
			return array( 'ok' => false, 'reason' => 'bad_status' );
		}

		$post = get_post( (int) $sugg['source_post_id'] );
		if ( ! $post instanceof \WP_Post ) {
			return array( 'ok' => false, 'reason' => 'source_missing' );
		}

		$system = BuilderDetector::detect( $post );
		if ( 'auto' !== BuilderDetector::write_safety( $system ) ) {
			return array( 'ok' => false, 'reason' => 'suggest_only', 'system' => $system );
		}

		$target_id = (int) $sugg['target_post_id'];
		$href      = $target_id > 0 ? get_permalink( $target_id ) : $sugg['target_url'];
		if ( empty( $href ) ) {
			return array( 'ok' => false, 'reason' => 'no_target' );
		}

		// Atomically claim the suggestion so two concurrent applies cannot both write.
		$claimed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$sugg_table} SET status = 'applying' WHERE id = %d AND status IN ('pending','approved')", // phpcs:ignore WordPress.DB.PreparedSQL
				$suggestion_id
			)
		);
		if ( ! $claimed ) {
			return array( 'ok' => false, 'reason' => 'bad_status' );
		}

		$anchor  = (string) $sugg['anchor_text'];
		$data_id = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : md5( $suggestion_id . microtime() );

		$write = ContentWriter::write( $post, $system, $anchor, $href, $data_id );
		if ( empty( $write['ok'] ) ) {
			self::release_claim( $suggestion_id );
			return array( 'ok' => false, 'reason' => $write['reason'], 'system' => $system );
		}

		// Guard against a human edit between our read and our write.
		$current = get_post( (int) $post->ID );
		if ( ! $current instanceof \WP_Post || md5( (string) $current->post_content ) !== md5( (string) $write['value_before'] ) ) {
			self::release_claim( $suggestion_id );
			return array( 'ok' => false, 'reason' => 'modified_since' );
		}

		// Backup: a WP revision (supplement) + the authoritative ledger row.
		$revision_id = (int) wp_save_post_revision( $post->ID );

		$ledger_id = LedgerRepository::insert(
			array(
				'suggestion_id'      => (int) $suggestion_id,
				'post_id'            => (int) $post->ID,
				'content_system'     => $system,
				'storage_target'     => $write['storage_target'],
				'meta_key'           => $write['meta_key'],
				'revision_id_before' => $revision_id,
				'value_before'       => $write['value_before'],
				'value_after_hash'   => md5( $write['value_after'] ),
				'inserted_html'      => '<a href="' . esc_url( $href ) . '" data-ailinking-id="' . esc_attr( $data_id ) . '">' . esc_html( $anchor ) . '</a>',
				'target_url'         => $href,
				'data_attr_id'       => $data_id,
			)
		);
		if ( ! $ledger_id ) {
			self::release_claim( $suggestion_id );
			return array( 'ok' => false, 'reason' => 'ledger_failed' );
		}

		// Write content (guard against self-triggered re-index during the update).
		Plugin::begin_internal_save();
		try {
			$result = wp_update_post(
				array(
					'ID'           => $post->ID,
					'post_content' => $write['value_after'],
				),
				true
			);
		} finally {
			Plugin::end_internal_save();
		}

		if ( is_wp_error( $result ) ) {
			LedgerRepository::delete( $ledger_id );
			self::release_claim( $suggestion_id );
			return array( 'ok' => false, 'reason' => 'update_failed' );
		}

		// Record the hash of the *actually stored* content (save filters may alter it),
		// so the undo "modified since" guard is accurate.
		$stored = get_post( $post->ID );
		if ( $stored instanceof \WP_Post ) {
			LedgerRepository::set_after_hash( $ledger_id, md5( (string) $stored->post_content ) );
		}

		$wpdb->update(
			$sugg_table,
			array(
				'status'            => 'applied',
				'applied_ledger_id' => $ledger_id,
			),
			array( 'id' => $suggestion_id ),
			array( '%s', '%d' ),
			array( '%d' )
		);

		// Refresh index + link graph (the new link is now a discovered edge).
		Indexer::index_post( (int) $post->ID );

		return array( 'ok' => true, 'ledger_id' => $ledger_id, 'system' => $system );
	}

	/**
	 * Undo an applied insertion by restoring the exact prior field value.
	 *
	 * @param int  $ledger_id Ledger row ID.
	 * @param bool $force     Restore even if the field changed since (overwrites later edits).
	 * @return array{ok:bool,reason?:string}
	 */
	public static function undo( $ledger_id, $force = false ) {
		global $wpdb;

		$row = LedgerRepository::get( $ledger_id );
		if ( ! $row || null !== $row['removed_at'] ) {
			return array( 'ok' => false, 'reason' => 'not_active' );
		}

		$post = get_post( (int) $row['post_id'] );
		if ( ! $post instanceof \WP_Post ) {
			// Post is gone; nothing to restore. Mark resolved.
			LedgerRepository::mark_removed( $ledger_id );
			return array( 'ok' => true );
		}

		$new_content = null;

		// Preferred: unwrap exactly our tagged anchor in the CURRENT content, which
		// preserves any unrelated edits the user made after the link was inserted.
		$data_id = (string) $row['data_attr_id'];
		if ( 'post_content' === $row['storage_target'] && '' !== $data_id ) {
			$unwrapped = self::unwrap_tagged_anchor( (string) $post->post_content, $data_id );
			if ( null !== $unwrapped ) {
				$new_content = $unwrapped;
			}
		}

		// Fallback: full restore of the prior value. Only when content is unchanged
		// since our write (unless forced), to avoid clobbering later edits.
		if ( null === $new_content ) {
			if ( ! $force && 'post_content' === $row['storage_target'] && md5( (string) $post->post_content ) !== $row['value_after_hash'] ) {
				return array( 'ok' => false, 'reason' => 'modified_since' );
			}
			$new_content = (string) $row['value_before'];
		}

		Plugin::begin_internal_save();
		try {
			$result = wp_update_post(
				array(
					'ID'           => $post->ID,
					'post_content' => $new_content,
				),
				true
			);
		} finally {
			Plugin::end_internal_save();
		}

		if ( is_wp_error( $result ) ) {
			return array( 'ok' => false, 'reason' => 'restore_failed' );
		}

		LedgerRepository::mark_removed( $ledger_id );

		// Reopen the originating suggestion for re-review.
		if ( (int) $row['suggestion_id'] > 0 ) {
			$wpdb->update(
				Tables::suggestions(),
				array( 'status' => 'approved', 'applied_ledger_id' => 0 ),
				array( 'id' => (int) $row['suggestion_id'] ),
				array( '%s', '%d' ),
				array( '%d' )
			);
		}

		Indexer::index_post( (int) $post->ID );

		return array( 'ok' => true );
	}

	/**
	 * Revert a batch of active insertions (clean-removal while the plugin is active).
	 *
	 * @param int $limit Rows per batch.
	 * @return array{processed:int,remaining:int}
	 */
	public static function remove_all_batch( $limit = 10 ) {
		$rows      = LedgerRepository::all_active( $limit );
		$processed = 0;
		foreach ( $rows as $row ) {
			$res = self::undo( (int) $row['id'], true );
			if ( ! empty( $res['ok'] ) ) {
				$processed++;
			}
		}
		return array(
			'processed' => $processed,
			'remaining' => LedgerRepository::count_active(),
		);
	}

	/**
	 * Release an apply claim by returning the suggestion to the approved queue.
	 *
	 * @param int $suggestion_id Suggestion ID.
	 */
	private static function release_claim( $suggestion_id ) {
		global $wpdb;
		$wpdb->update(
			Tables::suggestions(),
			array( 'status' => 'approved' ),
			array( 'id' => (int) $suggestion_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Remove exactly our tagged <a data-ailinking-id="..."> from HTML, unwrapping it
	 * back to its inner text. Returns the new HTML, or null if it isn't present
	 * exactly once (caller falls back to a full restore).
	 *
	 * @param string $html    Current content.
	 * @param string $data_id Provenance id.
	 * @return string|null
	 */
	private static function unwrap_tagged_anchor( $html, $data_id ) {
		$pattern = '#<a\b[^>]*\bdata-ailinking-id="' . preg_quote( $data_id, '#' ) . '"[^>]*>(.*?)</a>#is';
		if ( 1 !== preg_match_all( $pattern, $html, $m ) ) {
			return null;
		}
		$new = preg_replace( $pattern, '$1', $html, 1 );
		return is_string( $new ) ? $new : null;
	}
}
