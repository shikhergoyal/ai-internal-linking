<?php
/**
 * Decision logic for the inbox's bulk actions, kept free of WordPress so it can
 * be tested directly.
 *
 * Bulk apply writes to real posts, so the rules about what may be touched live
 * here rather than being spread through the AJAX handler.
 *
 * @package AILinking
 */

namespace AILinking\Admin;

defined( 'ABSPATH' ) || exit;

class BulkActions {

	/**
	 * Most ids accepted in one request.
	 *
	 * The browser chunks a large selection into several requests; this is the
	 * server's own guard so a hand-made request cannot ask for ten thousand
	 * applies in one PHP process and time out half way through.
	 */
	const MAX_PER_REQUEST = 50;

	/**
	 * Statuses a bulk status change is allowed to overwrite.
	 *
	 * Deliberately excludes 'applied' and 'applying'. A link that is already in
	 * your content must not be quietly flipped back to pending by a bulk action:
	 * the row would stop matching the content, and the ledger entry that powers
	 * Undo would be orphaned. Removing an applied link is what Undo is for.
	 */
	const OVERWRITABLE = array( 'pending', 'approved', 'rejected' );

	/**
	 * Operations the endpoint accepts.
	 *
	 * @return string[]
	 */
	public static function allowed_ops() {
		return array( 'approved', 'rejected', 'pending', 'apply' );
	}

	/**
	 * Whether an operation is a plain status change. (pure)
	 *
	 * @param string $op Operation.
	 * @return bool
	 */
	public static function is_status_op( $op ) {
		return in_array( (string) $op, array( 'approved', 'rejected', 'pending' ), true );
	}

	/**
	 * Whether an operation is one this endpoint handles at all. (pure)
	 *
	 * @param string $op Operation.
	 * @return bool
	 */
	public static function is_allowed( $op ) {
		return in_array( (string) $op, self::allowed_ops(), true );
	}

	/**
	 * Clean a submitted list of suggestion ids. (pure)
	 *
	 * Drops anything that is not a positive integer, removes duplicates (a
	 * repeated id in an apply batch would mean trying to write the same link
	 * twice), preserves the submitted order so results line up with the rows
	 * the user is looking at, and truncates to the per-request ceiling.
	 *
	 * @param mixed $raw Submitted ids.
	 * @return int[]
	 */
	public static function sanitize_ids( $raw ) {
		if ( ! is_array( $raw ) ) {
			$raw = ( '' === $raw || null === $raw ) ? array() : array( $raw );
		}

		$out  = array();
		$seen = array();
		foreach ( $raw as $value ) {
			$id = (int) $value;
			if ( $id <= 0 || isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;
			$out[]       = $id;
			if ( count( $out ) >= self::MAX_PER_REQUEST ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Turn per-item apply results into counts. (pure)
	 *
	 * Bulk apply is partial by nature: a page-builder page cannot be written to,
	 * a post edited since the scan is refused, and an already-applied row is
	 * skipped. Reporting only "12 of 20 succeeded" would leave no way to tell a
	 * harmless skip from a real failure, so the reasons are counted too.
	 *
	 * @param array[] $results Each an Editor::apply() return value.
	 * @return array{applied:int,failed:int,reasons:array<string,int>}
	 */
	public static function summarize( array $results ) {
		$applied = 0;
		$failed  = 0;
		$reasons = array();

		foreach ( $results as $r ) {
			if ( ! empty( $r['ok'] ) ) {
				$applied++;
				continue;
			}
			$failed++;
			$reason             = isset( $r['reason'] ) && '' !== $r['reason'] ? (string) $r['reason'] : 'unknown';
			$reasons[ $reason ] = isset( $reasons[ $reason ] ) ? $reasons[ $reason ] + 1 : 1;
		}

		arsort( $reasons );

		return array(
			'applied' => $applied,
			'failed'  => $failed,
			'reasons' => $reasons,
		);
	}

	/**
	 * A human-readable explanation for an apply failure reason. (pure-ish)
	 *
	 * @param string $reason Reason code from Editor::apply().
	 * @return string
	 */
	public static function reason_label( $reason ) {
		$map = array(
			'suggest_only'           => __( 'page-builder page — add this link manually', 'ai-internal-linking' ),
			'unsupported_system'     => __( 'this content type cannot be written to automatically', 'ai-internal-linking' ),
			'modified_since'         => __( 'the page changed since the scan; re-scan it', 'ai-internal-linking' ),
			'bad_status'             => __( 'already applied, or being applied elsewhere', 'ai-internal-linking' ),
			'anchor_not_found'       => __( 'the anchor text is no longer in the page', 'ai-internal-linking' ),
			'empty_anchor'           => __( 'the suggestion has no anchor text', 'ai-internal-linking' ),
			'not_found'              => __( 'the suggestion no longer exists', 'ai-internal-linking' ),
			'source_missing'         => __( 'the source page no longer exists', 'ai-internal-linking' ),
			'no_target'              => __( 'the target page has no address', 'ai-internal-linking' ),
			'integrity_check_failed' => __( 'the visible-text check failed, so nothing was written', 'ai-internal-linking' ),
			'blocks_unavailable'     => __( 'the block editor API was unavailable', 'ai-internal-linking' ),
			'ledger_failed'          => __( 'the undo record could not be written, so nothing was changed', 'ai-internal-linking' ),
			'update_failed'          => __( 'WordPress refused the update', 'ai-internal-linking' ),
		);
		$reason = (string) $reason;
		return isset( $map[ $reason ] ) ? $map[ $reason ] : $reason;
	}
}
