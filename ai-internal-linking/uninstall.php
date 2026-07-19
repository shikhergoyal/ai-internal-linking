<?php
/**
 * Uninstall routine — runs in isolation when the plugin is deleted via the admin UI.
 *
 * Content IS mutated by the apply feature (Phase 0b inserts <a> links). To leave a
 * clean footprint, this first restores every still-active ledger row's original
 * content, then drops the plugin's tables/options/scheduled events.
 *
 * Note: inserted links are plain <a data-ailinking-id> tags, so even a filesystem
 * delete that bypasses this file never leaves orphaned shortcode markup — at worst
 * a working hyperlink remains.
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
	'ailinking_provider_keys',
	'ailinking_spend_log',
	'ailinking_keywords',
	'ailinking_keyword_map',
	'ailinking_embeddings',
	'ailinking_clusters',
	'ailinking_cluster_members',
);

// 1. Restore content for every active (un-reverted) inserted link before dropping data.
$ledger_table = $wpdb->prefix . 'ailinking_ledger';
$ledger_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $ledger_table ) );

if ( $ledger_exists === $ledger_table ) {
	$rows = $wpdb->get_results(
		"SELECT post_id, value_before, storage_target FROM `{$ledger_table}` WHERE removed_at IS NULL", // phpcs:ignore WordPress.DB.PreparedSQL
		ARRAY_A
	);
	if ( $rows ) {
		foreach ( $rows as $row ) {
			if ( 'post_content' === $row['storage_target'] && (int) $row['post_id'] > 0 ) {
				wp_update_post(
					array(
						'ID'           => (int) $row['post_id'],
						'post_content' => (string) $row['value_before'],
					)
				);
			}
		}
	}
}

// 2. Drop custom tables.
foreach ( $ailinking_tables as $ailinking_table ) {
	$table_name = $wpdb->prefix . $ailinking_table;
	// Table identifiers cannot be parameterised; the name is built from a trusted whitelist.
	$wpdb->query( "DROP TABLE IF EXISTS `{$table_name}`" ); // phpcs:ignore WordPress.DB.PreparedSQL
}

// 3. Remove plugin options (autoload + non-autoload).
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( 'ailinking_' ) . '%'
	)
);

// 4. Remove plugin transients (site + network).
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		'_transient_ailinking_%',
		'_transient_timeout_ailinking_%'
	)
);

// 5. Clear any scheduled cron events.
wp_clear_scheduled_hook( 'ailinking_cron_index' );
wp_clear_scheduled_hook( 'ailinking_cron_suggest' );
wp_clear_scheduled_hook( 'ailinking_cron_gsc' );
