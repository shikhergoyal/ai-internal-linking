<?php
/**
 * Normalises provider HTTP errors into a single shape the gateway acts on without
 * knowing which provider produced them.
 *
 * Shape: [ class, http_status, retry_after, is_retryable, triggers_failover, message ]
 *  - class: rate_limit|quota_exceeded|auth|invalid_request|server|overloaded|network|unknown
 *  - triggers_failover: try the next key in the pool
 *  - is_retryable: retry the SAME key after a backoff
 *
 * @package AILinking
 */

namespace AILinking\Providers;

use AILinking\Security\Redactor;

defined( 'ABSPATH' ) || exit;

class Errors {

	/**
	 * @param int    $status  HTTP status (0 for network failure).
	 * @param mixed  $body    Decoded body (array) or raw string.
	 * @param array  $headers Response headers (lowercased keys).
	 * @param string $message Optional fallback message.
	 * @return array Normalised error.
	 */
	public static function classify( $status, $body, array $headers = array(), $message = '' ) {
		$retry_after = self::retry_after( $headers );
		$msg         = self::message( $body, $message );

		// Network failure.
		if ( 0 === (int) $status ) {
			return self::make( 'network', 0, $retry_after, true, true, $msg ? $msg : 'Network error' );
		}

		$body_str = is_string( $body ) ? strtolower( $body ) : strtolower( wp_json_encode( $body ) );

		switch ( true ) {
			case 429 === $status:
				return self::make( 'rate_limit', $status, $retry_after, true, true, $msg );
			case 401 === $status || 403 === $status:
				return self::make( 'auth', $status, null, false, true, $msg );
			case 402 === $status || false !== strpos( $body_str, 'insufficient_quota' ) || false !== strpos( $body_str, 'quota' ):
				return self::make( 'quota_exceeded', $status, null, false, true, $msg );
			case 400 === $status || 422 === $status:
				return self::make( 'invalid_request', $status, null, false, false, $msg );
			case 529 === $status || false !== strpos( $body_str, 'overloaded' ):
				return self::make( 'overloaded', $status, $retry_after, true, false, $msg );
			case $status >= 500:
				return self::make( 'server', $status, $retry_after, true, false, $msg );
			default:
				return self::make( 'unknown', $status, null, false, false, $msg );
		}
	}

	/**
	 * Build the normalised array.
	 */
	private static function make( $class, $status, $retry_after, $retryable, $failover, $message ) {
		return array(
			'class'             => $class,
			'http_status'       => (int) $status,
			'retry_after'       => $retry_after,
			'is_retryable'      => (bool) $retryable,
			'triggers_failover' => (bool) $failover,
			'message'           => $message,
		);
	}

	/**
	 * Parse a Retry-After header in seconds (ignores HTTP-date form).
	 *
	 * @param array $headers Headers.
	 * @return int|null
	 */
	private static function retry_after( array $headers ) {
		if ( isset( $headers['retry-after'] ) && is_numeric( $headers['retry-after'] ) ) {
			return max( 0, (int) $headers['retry-after'] );
		}
		return null;
	}

	/**
	 * Extract a human message from a provider error body.
	 *
	 * @param mixed  $body    Body.
	 * @param string $default Fallback.
	 * @return string
	 */
	private static function message( $body, $default ) {
		return Redactor::scrub( self::raw_message( $body, $default ) );
	}

	/**
	 * Pull the provider's own wording out of the response body, unscrubbed.
	 *
	 * @param mixed  $body    Decoded body or raw string.
	 * @param string $default Fallback.
	 * @return string
	 */
	private static function raw_message( $body, $default ) {
		if ( is_array( $body ) ) {
			if ( isset( $body['error']['message'] ) ) {
				return (string) $body['error']['message'];
			}
			if ( isset( $body['error'] ) && is_string( $body['error'] ) ) {
				return $body['error'];
			}
			if ( isset( $body['message'] ) ) {
				return (string) $body['message'];
			}
		}
		return $default ? $default : 'Request failed';
	}
}
