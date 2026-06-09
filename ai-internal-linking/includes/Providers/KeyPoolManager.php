<?php
/**
 * Multi-key pool: encrypted key storage, rotation, failover state machine, health,
 * and a lazy monthly spend reset (no cron dependency).
 *
 * Key states: active | cooling | errored | exhausted (plus enabled=0 for manual).
 *
 * @package AILinking
 */

namespace AILinking\Providers;

use AILinking\Support\Tables;
use AILinking\Security\Crypto;

defined( 'ABSPATH' ) || exit;

class KeyPoolManager {

	const MONTH_OPTION = 'ailinking_spend_month';

	/**
	 * Add a key (encrypts the secret). Returns the new id, or 0 on failure.
	 *
	 * @param array $data provider,label,api_key,base_url,model,capability,extra,priority
	 * @return int
	 */
	public static function add_key( array $data ) {
		global $wpdb;

		$plain  = isset( $data['api_key'] ) ? (string) $data['api_key'] : '';
		$cipher = '';
		$last4  = '';
		if ( '' !== $plain ) {
			$cipher = Crypto::encrypt( $plain );
			if ( '' === $cipher ) {
				return 0; // encryption unavailable.
			}
			$last4 = substr( $plain, -4 );
		}

		$ok = $wpdb->insert(
			Tables::provider_keys(),
			array(
				'provider'     => (string) $data['provider'],
				'label'        => isset( $data['label'] ) ? (string) $data['label'] : '',
				'base_url'     => isset( $data['base_url'] ) ? (string) $data['base_url'] : '',
				'model'        => isset( $data['model'] ) ? (string) $data['model'] : '',
				'capability'   => isset( $data['capability'] ) ? (string) $data['capability'] : 'chat',
				'key_cipher'   => $cipher,
				'key_last4'    => $last4,
				'extra_config' => isset( $data['extra'] ) ? wp_json_encode( $data['extra'] ) : '',
				'priority'     => isset( $data['priority'] ) ? (int) $data['priority'] : 100,
				'enabled'      => 1,
				'state'        => 'active',
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' )
		);
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * @param int $id Key id.
	 */
	public static function delete_key( $id ) {
		global $wpdb;
		$wpdb->delete( Tables::provider_keys(), array( 'id' => (int) $id ), array( '%d' ) );
	}

	/**
	 * @param int  $id      Key id.
	 * @param bool $enabled Enabled flag.
	 */
	public static function set_enabled( $id, $enabled ) {
		global $wpdb;
		$wpdb->update(
			Tables::provider_keys(),
			array(
				'enabled' => $enabled ? 1 : 0,
				'state'   => $enabled ? 'active' : 'active',
			),
			array( 'id' => (int) $id ),
			array( '%d', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Lease an eligible key for a provider + plane. Returns a context array
	 * (with the decrypted key) or null if none available.
	 *
	 * @param string $provider Provider slug.
	 * @param string $plane    'chat' | 'embedding'.
	 * @param string $strategy 'round_robin' | 'primary_failover'.
	 * @param int[]  $skip     Key ids to skip (already tried this request).
	 * @return array|null
	 */
	public static function lease( $provider, $plane, $strategy = 'round_robin', $skip = array() ) {
		global $wpdb;
		$table = Tables::provider_keys();
		$now   = current_time( 'mysql', true ); // UTC, to match gmdate()-written cooldowns.

		$order = ( 'primary_failover' === $strategy ) ? 'priority ASC, last_used_at ASC' : 'last_used_at ASC';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				 WHERE provider = %s AND enabled = 1
				   AND capability IN (%s, 'both')
				   AND state NOT IN ('errored','exhausted')
				   AND ( state = 'active' OR cooldown_until IS NULL OR cooldown_until <= %s )
				 ORDER BY {$order}", // phpcs:ignore WordPress.DB.PreparedSQL
				$provider,
				$plane,
				$now
			),
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			if ( in_array( (int) $row['id'], array_map( 'intval', (array) $skip ), true ) ) {
				continue;
			}
			$key = ( '' !== (string) $row['key_cipher'] ) ? Crypto::decrypt( $row['key_cipher'] ) : '';
			if ( null === $key ) {
				continue; // cannot decrypt (salts rotated) — skip.
			}
			// Mark used; flip an expired cooldown back to active.
			$wpdb->update(
				$table,
				array( 'last_used_at' => $now, 'state' => 'active' ),
				array( 'id' => (int) $row['id'] ),
				array( '%s', '%s' ),
				array( '%d' )
			);

			return array(
				'key_id'   => (int) $row['id'],
				'api_key'  => $key,
				'base_url' => (string) $row['base_url'],
				'model'    => (string) $row['model'],
				'extra'    => $row['extra_config'] ? (array) json_decode( $row['extra_config'], true ) : array(),
			);
		}

		return null;
	}

	/**
	 * Record a successful call: counters + spend (with lazy monthly reset) + log.
	 *
	 * @param int    $key_id     Key id.
	 * @param string $provider   Provider.
	 * @param string $model      Model.
	 * @param string $operation  'chat'|'embedding'.
	 * @param int    $tokens_in  Prompt tokens.
	 * @param int    $tokens_out Completion tokens.
	 * @param int    $cost_cents Estimated cost (cents).
	 */
	public static function report_success( $key_id, $provider, $model, $operation, $tokens_in, $tokens_out, $cost_cents ) {
		global $wpdb;
		self::maybe_roll_month();
		$table = Tables::provider_keys();

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				 SET request_count = request_count + 1,
				     est_spend_cents = est_spend_cents + %d,
				     state = 'active', cooldown_until = NULL, last_error = ''
				 WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL
				max( 0, (int) $cost_cents ),
				(int) $key_id
			)
		);

		$wpdb->insert(
			Tables::spend_log(),
			array(
				'key_id'      => (int) $key_id,
				'provider'    => (string) $provider,
				'model'       => (string) $model,
				'operation'   => (string) $operation,
				'tokens_in'   => (int) $tokens_in,
				'tokens_out'  => (int) $tokens_out,
				'est_cost'    => max( 0, (int) $cost_cents ) / 100,
				'http_status' => 200,
			),
			array( '%d', '%s', '%s', '%s', '%d', '%d', '%f', '%d' )
		);
	}

	/**
	 * Record a failed call and transition the key per the error class.
	 *
	 * @param int   $key_id   Key id.
	 * @param array $error    Normalized error.
	 * @param string $provider Provider.
	 */
	public static function report_error( $key_id, array $error, $provider = '' ) {
		global $wpdb;
		$table = Tables::provider_keys();
		$class = isset( $error['class'] ) ? $error['class'] : 'unknown';
		$msg   = isset( $error['message'] ) ? substr( (string) $error['message'], 0, 255 ) : '';

		$state    = null;
		$cooldown = null;

		switch ( $class ) {
			case 'rate_limit':
				$state    = 'cooling';
				$secs     = isset( $error['retry_after'] ) && $error['retry_after'] ? (int) $error['retry_after'] : 30;
				$cooldown = gmdate( 'Y-m-d H:i:s', time() + min( 300, max( 5, $secs ) ) );
				break;
			case 'quota_exceeded':
				$state    = 'exhausted';
				$cooldown = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );
				break;
			case 'auth':
				$state = 'errored';
				break;
			case 'server':
			case 'overloaded':
			case 'network':
				// Stay active; brief cooldown to ease off.
				$state    = 'cooling';
				$cooldown = gmdate( 'Y-m-d H:i:s', time() + 10 );
				break;
			default:
				$state = null; // invalid_request / unknown: no state change.
		}

		$set_clauses = 'error_count = error_count + 1';
		$params      = array();
		if ( null !== $state ) {
			$set_clauses .= ', state = %s';
			$params[]     = $state;
		}
		if ( null !== $cooldown ) {
			$set_clauses .= ', cooldown_until = %s';
			$params[]     = $cooldown;
		}
		$set_clauses .= ', last_error = %s';
		$params[]     = $msg;
		$params[]     = (int) $key_id;

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET {$set_clauses} WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL
				$params
			)
		);

		$wpdb->insert(
			Tables::spend_log(),
			array(
				'key_id'      => (int) $key_id,
				'provider'    => (string) $provider,
				'operation'   => 'error',
				'http_status' => isset( $error['http_status'] ) ? (int) $error['http_status'] : 0,
				'error_type'  => (string) $class,
			),
			array( '%d', '%s', '%s', '%d', '%s' )
		);
	}

	/**
	 * Count usable keys for a provider + plane.
	 *
	 * @param string $provider Provider.
	 * @param string $plane    Plane.
	 * @return int
	 */
	public static function healthy_count( $provider, $plane ) {
		global $wpdb;
		$table = Tables::provider_keys();
		$now   = current_time( 'mysql', true ); // UTC, to match gmdate()-written cooldowns.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table}
				 WHERE provider = %s AND enabled = 1 AND capability IN (%s,'both')
				   AND state NOT IN ('errored','exhausted')
				   AND ( state = 'active' OR cooldown_until IS NULL OR cooldown_until <= %s )", // phpcs:ignore WordPress.DB.PreparedSQL
				$provider,
				$plane,
				$now
			)
		);
	}

	/**
	 * Current-month spend (cents) for a scope.
	 *
	 * @param string $scope 'all' or a provider slug.
	 * @return int
	 */
	public static function monthly_spend_cents( $scope = 'all' ) {
		global $wpdb;
		self::maybe_roll_month();
		$table = Tables::provider_keys();
		if ( 'all' === $scope ) {
			return (int) $wpdb->get_var( "SELECT COALESCE(SUM(est_spend_cents),0) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(est_spend_cents),0) FROM {$table} WHERE provider = %s", $scope ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Health rows for the admin dashboard (no secrets).
	 *
	 * @return array[]
	 */
	public static function health() {
		global $wpdb;
		$table = Tables::provider_keys();
		return $wpdb->get_results(
			"SELECT id, provider, label, model, capability, key_last4, enabled, state, cooldown_until, request_count, error_count, est_spend_cents, last_error, last_used_at FROM {$table} ORDER BY provider, id", // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);
	}

	/**
	 * Zero monthly spend counters when the calendar month changes.
	 */
	private static function maybe_roll_month() {
		global $wpdb;
		$current = substr( (string) current_time( 'mysql' ), 0, 7 ); // YYYY-MM
		$stored  = get_option( self::MONTH_OPTION, '' );
		if ( $stored !== $current ) {
			$wpdb->query( "UPDATE " . Tables::provider_keys() . " SET est_spend_cents = 0" ); // phpcs:ignore WordPress.DB.PreparedSQL
			update_option( self::MONTH_OPTION, $current, false );
		}
	}
}
