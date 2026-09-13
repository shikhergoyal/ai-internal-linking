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
	 * Try to acquire a short-lived job lock, so only one worker runs a job at a
	 * time. The browser polls run_index while WP-Cron ticks the same job, and
	 * both call Indexer::process_batch(), which shares one cursor: two workers
	 * inside it double-count progress and skip posts.
	 *
	 * This used to read the lock and then write it, which is not a lock at all
	 * — two workers arriving together both see nothing, both write, and both
	 * proceed. The INSERT has to be the thing that decides, so the loser learns
	 * it lost from the database rather than from a value it read a moment
	 * earlier.
	 *
	 * INSERT IGNORE against the unique key on option_name is exactly how
	 * WP_Upgrader::create_lock() does it, for the same reason: add_option()
	 * checks for an existing value through the object cache before writing, so
	 * it cannot settle a race either.
	 *
	 * @param string $key    Job key.
	 * @param int    $ttl    Lock lifetime in seconds (a dead worker's lock expires).
	 * @param bool   $retook Internal: prevents more than one takeover attempt.
	 * @return bool True if the lock was acquired.
	 */
	public static function acquire( $key, $ttl = 120, $retook = false ) {
		global $wpdb;

		$lock = self::lock_option( $key );
		$now  = time();

		$got = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'no' )", // phpcs:ignore WordPress.DB.PreparedSQL
				$lock,
				(string) $now
			)
		);

		if ( $got ) {
			// The row was written behind the object cache's back.
			wp_cache_delete( $lock, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			return true;
		}

		if ( $retook ) {
			// We already took one turn at a stale lock and lost. Someone else
			// holds it now, which is the correct outcome.
			return false;
		}

		wp_cache_delete( $lock, 'options' );
		$held = (int) get_option( $lock, 0 );

		// Held by a worker that is still alive, or by one whose clock we cannot
		// read. Either way it is not ours.
		if ( $held > 0 && $held > ( $now - (int) $ttl ) ) {
			return false;
		}

		// The holder died mid-job. Drop the stale lock and race for it once;
		// whichever worker's INSERT lands is the one that gets it.
		self::release( $key );
		return self::acquire( $key, $ttl, true );
	}

	/**
	 * Release a job lock.
	 *
	 * @param string $key Job key.
	 */
	public static function release( $key ) {
		delete_option( self::lock_option( $key ) );
		// Locks were transients before 0.26.0. Clear the old name too, or a
		// lock held at the moment of upgrade would sit there until it expired.
		delete_transient( 'ailinking_lock_' . sanitize_key( $key ) );
	}

	/**
	 * @param string $key Job key.
	 * @return string Option name holding the lock.
	 */
	public static function lock_option( $key ) {
		return 'ailinking_lock_' . sanitize_key( $key );
	}

}
