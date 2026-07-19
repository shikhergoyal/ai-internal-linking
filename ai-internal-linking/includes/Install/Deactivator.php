<?php
/**
 * Deactivation routine: clear scheduled events only. Never deletes data.
 *
 * @package AILinking
 */

namespace AILinking\Install;

defined( 'ABSPATH' ) || exit;

class Deactivator {

	/**
	 * Run on plugin deactivation.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'ailinking_cron_index' );
		wp_clear_scheduled_hook( 'ailinking_cron_suggest' );
		wp_clear_scheduled_hook( 'ailinking_cron_gsc' );
		delete_transient( 'ailinking_activation_redirect' );
	}
}
