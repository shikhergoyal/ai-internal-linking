<?php
/**
 * PSR-4-style autoloader for the AILinking namespace.
 *
 * Maps `AILinking\Foo\Bar` => includes/Foo/Bar.php. No Composer required so the
 * plugin is installable by dropping it into wp-content/plugins.
 *
 * @package AILinking
 */

namespace AILinking;

defined( 'ABSPATH' ) || exit;

class Autoloader {

	const PREFIX = 'AILinking\\';

	/**
	 * Register the SPL autoloader.
	 */
	public static function register() {
		spl_autoload_register( array( __CLASS__, 'autoload' ) );
	}

	/**
	 * Resolve and require a class file.
	 *
	 * @param string $class Fully-qualified class name.
	 */
	public static function autoload( $class ) {
		if ( 0 !== strpos( $class, self::PREFIX ) ) {
			return;
		}

		$relative = substr( $class, strlen( self::PREFIX ) );
		$relative = str_replace( '\\', '/', $relative );
		$file     = AILINKING_PATH . 'includes/' . $relative . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
}
