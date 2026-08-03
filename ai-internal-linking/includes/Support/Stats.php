<?php
/**
 * The two figures on the dashboard Status card.
 *
 * They are read in two places that must never disagree: the server rendering
 * the page, and every scan response that refreshes the card without a reload.
 * Keeping the queries here means a change to what "indexed" counts cannot
 * drift between the two.
 *
 * @package AILinking
 */

namespace AILinking\Support;

defined( 'ABSPATH' ) || exit;

class Stats {

	/**
	 * Pages currently in the index, excluding ones the user opted out of.
	 *
	 * @return int
	 */
	public static function indexed_pages() {
		global $wpdb;
		$table = Tables::index();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE is_excluded = 0" ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Suggestions still waiting for a decision.
	 *
	 * @return int
	 */
	public static function pending_suggestions() {
		global $wpdb;
		$table = Tables::suggestions();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'pending'" ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Both figures, shaped for the browser.
	 *
	 * Sent with every progress tick, so the Status card tracks a running scan
	 * instead of showing whatever was true when the page was last loaded.
	 *
	 * @return array
	 */
	public static function live() {
		return array(
			'indexed' => self::indexed_pages(),
			'pending' => self::pending_suggestions(),
		);
	}
}
