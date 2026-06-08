<?php
/**
 * Durable job progress, stored in non-autoloaded options (never transients,
 * which can expire mid-job).
 *
 * @package AILinking
 */

namespace AILinking\Jobs;

defined( 'ABSPATH' ) || exit;

class ProgressStore {

	/**
	 * @param string $key Job key (e.g. 'index', 'suggest').
	 * @return array
	 */
	public static function get( $key ) {
		$value = get_option( self::option( $key ), array() );
		return is_array( $value ) ? $value : array();
	}

	/**
	 * @param string $key  Job key.
	 * @param array  $data Progress snapshot.
	 */
	public static function set( $key, array $data ) {
		update_option( self::option( $key ), $data, false );
	}

	/**
	 * @param string $key Job key.
	 */
	public static function clear( $key ) {
		delete_option( self::option( $key ) );
	}

	/**
	 * @param string $key Job key.
	 * @return string Option name.
	 */
	private static function option( $key ) {
		return 'ailinking_progress_' . sanitize_key( $key );
	}
}
