<?php
/**
 * Thin HTTP transport for provider adapters. Wraps the WP HTTP API and is
 * mockable in tests via set_transport().
 *
 * @package AILinking
 */

namespace AILinking\Providers;

defined( 'ABSPATH' ) || exit;

class Http {

	/** @var callable|null */
	private static $transport = null;

	/**
	 * Override the transport (tests). Pass null to restore the default.
	 *
	 * @param callable|null $fn function(string $url, array $headers, string $body, int $timeout): array
	 */
	public static function set_transport( $fn ) {
		self::$transport = $fn;
	}

	/**
	 * POST a JSON body. Returns ['status'=>int,'headers'=>array,'body'=>string,'error'=>?string].
	 *
	 * @param string $url     Endpoint.
	 * @param array  $headers Assoc header map.
	 * @param string $body    Raw body (JSON).
	 * @param int    $timeout Seconds.
	 * @return array
	 */
	public static function post( $url, array $headers, $body, $timeout = 30 ) {
		if ( null !== self::$transport ) {
			return call_user_func( self::$transport, $url, $headers, $body, $timeout );
		}

		$response = wp_remote_post(
			$url,
			array(
				'headers'     => $headers,
				'body'        => $body,
				'timeout'     => $timeout,
				'redirection' => 0,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'status'  => 0,
				'headers' => array(),
				'body'    => '',
				'error'   => $response->get_error_message(),
			);
		}

		$raw_headers = wp_remote_retrieve_headers( $response );
		$headers_arr = array();
		if ( $raw_headers ) {
			// WP returns a case-insensitive object; normalise to lowercase keys.
			foreach ( (array) $raw_headers->getAll() as $k => $v ) {
				$headers_arr[ strtolower( $k ) ] = is_array( $v ) ? implode( ', ', $v ) : $v;
			}
		}

		return array(
			'status'  => (int) wp_remote_retrieve_response_code( $response ),
			'headers' => $headers_arr,
			'body'    => (string) wp_remote_retrieve_body( $response ),
			'error'   => null,
		);
	}
}
