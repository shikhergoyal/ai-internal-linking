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

	/**
	 * Try to acquire a short-lived job lock so only one worker runs a job at a time
	 * (prevents the AJAX driver and the WP-Cron tick from racing the same cursor).
	 *
	 * @param string $key Job key.
	 * @param int    $ttl Lock lifetime in seconds (auto-expires if a worker dies).
	 * @return bool True if the lock was acquired.
	 */
	public static function acquire( $key, $ttl = 120 ) {
		$lock = 'ailinking_lock_' . sanitize_key( $key );
		if ( get_transient( $lock ) ) {
			return false;
		}
		set_transient( $lock, 1, $ttl );
		return true;
	}

	/**
	 * Release a job lock.
	 *
	 * @param string $key Job key.
	 */
	public static function release( $key ) {
		delete_transient( 'ailinking_lock_' . sanitize_key( $key ) );
	}
}
