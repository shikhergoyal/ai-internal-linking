<?php
/**
 * Activation routine: build tables, seed defaults, flag the onboarding wizard.
 *
 * @package AILinking
 */

namespace AILinking\Install;

use AILinking\Support\Settings;

defined( 'ABSPATH' ) || exit;

class Activator {

	/**
	 * Run on plugin activation.
	 */
	public static function activate() {
		Schema::install();

		// Seed default settings without clobbering an existing config.
		if ( false === get_option( Settings::OPTION, false ) ) {
			add_option( Settings::OPTION, Settings::defaults(), '', false );
		}

		// Trigger a one-time redirect to the onboarding wizard.
		set_transient( 'ailinking_activation_redirect', 1, 60 );

		// Schedule a hands-off indexing tick (admin-AJAX is the primary driver in 0a).
		if ( ! wp_next_scheduled( 'ailinking_cron_index' ) ) {
			wp_schedule_event( time() + 60, 'hourly', 'ailinking_cron_index' );
		}
	}
}
