<?php
/**
 * Uninstall routine — runs in isolation when the plugin is deleted via the admin UI.
 *
 * Content IS mutated by the apply feature (Phase 0b inserts <a> links). To leave
 * a clean footprint, this first unwraps every still-active inserted link out of
 * the post it sits in, then drops the plugin's tables/options/scheduled events.
 * Only our own tags are touched; edits made to those posts since are kept.
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
	'ailinking_clusters',
	'ailinking_cluster_members',
);

// 1. Take the links this plugin inserted back out, before dropping the ledger
//    that records them.
//
//    Tag by tag, out of the content as it stands right now — never by writing
//    back the copy of the post taken before the link went in. That copy is the
//    post at one moment in the past, so restoring it discards every edit made
//    since, and a post that received two links on different days has two such
//    copies with no order in which writing both back leaves it correct.
//    Unwrapping our own <a data-ailinking-id> tags out of the current content
//    is order-free and changes nothing else on the page.
//
//    A tag that is not found exactly once is left alone: the editor has already
//    changed or removed that link, so at worst a plain working hyperlink stays
//    behind — which is what the note at the top of this file promises, and far
//    better than overwriting someone's work to avoid it.
require_once __DIR__ . '/includes/Content/anchor-unwrap.php';

$ailinking_ledger = $wpdb->prefix . 'ailinking_ledger';

if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $ailinking_ledger ) ) === $ailinking_ledger ) {
	$ailinking_rows = $wpdb->get_results(
		"SELECT post_id, data_attr_id FROM `{$ailinking_ledger}` WHERE removed_at IS NULL AND storage_target = 'post_content' AND post_id > 0 AND data_attr_id <> '' ORDER BY id ASC", // phpcs:ignore WordPress.DB.PreparedSQL
		ARRAY_A
	);

	// Group by post: one read and one write per post, however many links it got.
	$ailinking_by_post = array();
	foreach ( (array) $ailinking_rows as $ailinking_row ) {
		$ailinking_by_post[ (int) $ailinking_row['post_id'] ][] = (string) $ailinking_row['data_attr_id'];
	}

	// Deleting a plugin is a single request. Stop short of the execution limit
	// rather than being killed part-way and leaving the tables undropped and the
	// plugin half-deleted. Anything not reached keeps a working hyperlink, and
	// Link Health → "Remove all inserted links" reverts the lot under a
	// progress bar for anyone who wants a clean slate before uninstalling.
	$ailinking_deadline = (int) ini_get( 'max_execution_time' );
	$ailinking_deadline = $ailinking_deadline > 0 ? max( 5.0, $ailinking_deadline * 0.6 ) : 20.0;
	$ailinking_started  = microtime( true );

	foreach ( $ailinking_by_post as $ailinking_post_id => $ailinking_ids ) {
		if ( ( microtime( true ) - $ailinking_started ) > $ailinking_deadline ) {
			break;
		}

		$ailinking_post = get_post( $ailinking_post_id );
		if ( ! $ailinking_post instanceof WP_Post ) {
			continue;
		}

		$ailinking_result = ailinking_unwrap_tagged_anchors( (string) $ailinking_post->post_content, $ailinking_ids );
		if ( ! $ailinking_result['removed'] ) {
			continue; // Nothing of ours is still in this post. Do not write.
		}

		wp_update_post(
			array(
				'ID'           => $ailinking_post_id,
				// wp_insert_post unslashes what it is given, so content has to
				// arrive slashed or every backslash in the post is eaten.
				'post_content' => wp_slash( $ailinking_result['html'] ),
			)
		);
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
