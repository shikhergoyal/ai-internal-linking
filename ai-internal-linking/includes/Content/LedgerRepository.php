<?php
/**
 * CRUD for the inserted-links ledger — the source of truth for reversible,
 * uninstall-safe insertion.
 *
 * @package AILinking
 */

namespace AILinking\Content;

use AILinking\Support\Tables;

defined( 'ABSPATH' ) || exit;

class LedgerRepository {

	/**
	 * Insert a ledger row.
	 *
	 * @param array $data Column values.
	 * @return int Insert ID (0 on failure).
	 */
	public static function insert( array $data ) {
		global $wpdb;
		$ok = $wpdb->insert(
			Tables::ledger(),
			array(
				'suggestion_id'      => (int) ( $data['suggestion_id'] ?? 0 ),
				'post_id'            => (int) $data['post_id'],
				'content_system'     => (string) ( $data['content_system'] ?? 'classic' ),
				'storage_target'     => (string) ( $data['storage_target'] ?? 'post_content' ),
				'meta_key'           => (string) ( $data['meta_key'] ?? '' ),
				'field_ref'          => (string) ( $data['field_ref'] ?? '' ),
				'revision_id_before' => (int) ( $data['revision_id_before'] ?? 0 ),
				'value_before'       => (string) ( $data['value_before'] ?? '' ),
				'value_after_hash'   => (string) ( $data['value_after_hash'] ?? '' ),
				'inserted_html'      => (string) ( $data['inserted_html'] ?? '' ),
				'target_url'         => (string) ( $data['target_url'] ?? '' ),
				'data_attr_id'       => (string) ( $data['data_attr_id'] ?? '' ),
				'applied_at'         => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * @param int $id Ledger row ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = Tables::ledger();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);
		return $row ? $row : null;
	}

	/**
	 * Active (not-yet-reverted) ledger rows for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array[]
	 */
	public static function active_for_post( $post_id ) {
		global $wpdb;
		$table = Tables::ledger();
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE post_id = %d AND removed_at IS NULL", $post_id ), // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);
	}

	/**
	 * A batch of active ledger rows (for bulk removal).
	 *
	 * @param int $limit    Max rows.
	 * @param int $after_id Cursor (exclusive).
	 * @return array[]
	 */
	public static function all_active( $limit, $after_id = 0 ) {
		global $wpdb;
		$table = Tables::ledger();
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE removed_at IS NULL AND id > %d ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL
				$after_id,
				$limit
			),
			ARRAY_A
		);
	}

	/**
	 * @return int Count of active (un-reverted) inserted links.
	 */
	public static function count_active() {
		global $wpdb;
		$table = Tables::ledger();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE removed_at IS NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Update the stored post-write hash (set to the actually-saved content so the
	 * undo "modified since" check is accurate after save filters run).
	 *
	 * @param int    $id   Ledger row ID.
	 * @param string $hash md5 of the stored value.
	 */
	public static function set_after_hash( $id, $hash ) {
		global $wpdb;
		$wpdb->update(
			Tables::ledger(),
			array( 'value_after_hash' => $hash ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Mark a ledger row reverted.
	 *
	 * @param int $id Ledger row ID.
	 */
	public static function mark_removed( $id ) {
		global $wpdb;
		$wpdb->update(
			Tables::ledger(),
			array( 'removed_at' => current_time( 'mysql' ) ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Delete a ledger row outright (used to roll back a failed apply).
	 *
	 * @param int $id Ledger row ID.
	 */
	public static function delete( $id ) {
		global $wpdb;
		$wpdb->delete( Tables::ledger(), array( 'id' => $id ), array( '%d' ) );
	}
}
