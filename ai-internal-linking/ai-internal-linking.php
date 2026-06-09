<?php
/**
 * Plugin Name:       AI Internal Linking
 * Plugin URI:        https://example.com/ai-internal-linking
 * Description:        Universal, AI-assisted internal linking. Crawls any WordPress site, then suggests contextual internal links (SEO + GEO best practices). Every suggestion is reviewed and gated — nothing is auto-inserted.
 * Version:           0.6.3
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            You
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ai-internal-linking
 * Domain Path:       /languages
 *
 * @package AILinking
 */

defined( 'ABSPATH' ) || exit;

define( 'AILINKING_VERSION', '0.6.3' );
define( 'AILINKING_DB_VERSION', '1.2.0' );
define( 'AILINKING_FILE', __FILE__ );
define( 'AILINKING_PATH', plugin_dir_path( __FILE__ ) );
define( 'AILINKING_URL', plugin_dir_url( __FILE__ ) );
define( 'AILINKING_BASENAME', plugin_basename( __FILE__ ) );
define( 'AILINKING_MIN_PHP', '7.4' );
define( 'AILINKING_MIN_WP', '6.2' );

/**
 * Bail gracefully on unmet minimum requirements rather than fataling.
 */
function ailinking_requirements_met() {
	global $wp_version;
	if ( version_compare( PHP_VERSION, AILINKING_MIN_PHP, '<' ) ) {
		return false;
	}
	if ( version_compare( $wp_version, AILINKING_MIN_WP, '<' ) ) {
		return false;
	}
	return true;
}

if ( ! ailinking_requirements_met() ) {
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error"><p>';
			echo esc_html(
				sprintf(
					/* translators: 1: required PHP version, 2: required WP version */
					__( 'AI Internal Linking requires PHP %1$s+ and WordPress %2$s+. The plugin is inactive until your environment meets these requirements.', 'ai-internal-linking' ),
					AILINKING_MIN_PHP,
					AILINKING_MIN_WP
				)
			);
			echo '</p></div>';
		}
	);
	return;
}

require_once AILINKING_PATH . 'includes/Autoloader.php';
\AILinking\Autoloader::register();

register_activation_hook( __FILE__, array( '\AILinking\Install\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\AILinking\Install\Deactivator', 'deactivate' ) );

/**
 * Boot the plugin once all plugins are loaded.
 */
function ailinking() {
	return \AILinking\Plugin::instance();
}

add_action( 'plugins_loaded', 'ailinking', 20 );
