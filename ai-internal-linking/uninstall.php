<?php
/**
 * Uninstall routine — runs in isolation when the plugin is deleted via the admin UI.
 *
 * Phase 0a: drops the plugin's custom tables and deletes its options/transients and
 * scheduled events. Inserted-link revert (the ledger-driven content restore) arrives
 * with the apply feature in Phase 0b; until then no content has been mutated.
 *
 * Note: inserted links (when that feature lands) are plain <a> tags, so even a
 * filesystem delete that bypasses this file never leaves orphaned shortcode markup.
 *
 * @package AILinking
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$ailinking_tables = array(
	'ailinking_index',
	'ailinking_link_graph',
	'ailinking_suggestions',
	'ailinking_ledger',
	'ailinking_tfidf',
	'ailinking_jobs',
);

foreach ( $ailinking_tables as $ailinking_table ) {
	$table_name = $wpdb->prefix . $ailinking_table;
	// Table identifiers cannot be parameterised; the name is built from a trusted whitelist.
	$wpdb->query( "DROP TABLE IF EXISTS `{$table_name}`" ); // phpcs:ignore WordPress.DB.PreparedSQL
}

// Remove plugin options (autoload + non-autoload).
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( 'ailinking_' ) . '%'
	)
);

// Remove plugin transients (site + network).
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		'_transient_ailinking_%',
		'_transient_timeout_ailinking_%'
	)
);

// Clear any scheduled cron events.
wp_clear_scheduled_hook( 'ailinking_cron_index' );
wp_clear_scheduled_hook( 'ailinking_cron_suggest' );
