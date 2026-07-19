<?php
/**
 * Thin Search Console API v3 client. Two calls are used: sites.list (to populate
 * the property picker) and searchAnalytics.query (the performance data). Auth is
 * delegated to GoogleServiceAccount; every response is normalized to
 * ['ok'=>bool, ...|'error'=>['message'=>..]].
 *
 * @package AILinking
 */

namespace AILinking\Integrations;

defined( 'ABSPATH' ) || exit;

class SearchConsoleClient {

	const API_BASE = 'https://www.googleapis.com/webmasters/v3';

	/**
	 * List the properties this service account can read.
	 *
	 * @return array{ok:bool,sites?:array,error?:array}
	 */
	public static function list_sites() {
		$tok = GoogleServiceAccount::access_token();
		if ( empty( $tok['ok'] ) ) {
			return array( 'ok' => false, 'error' => $tok['error'] );
		}

		$resp = wp_remote_get(
			self::API_BASE . '/sites',
			array(
				'timeout' => 20,
				'headers' => array( 'Authorization' => 'Bearer ' . $tok['token'] ),
			)
		);

		$parsed = self::decode( $resp );
		if ( empty( $parsed['ok'] ) ) {
			return $parsed;
		}

		$sites = array();
		$body  = $parsed['body'];
		if ( ! empty( $body['siteEntry'] ) && is_array( $body['siteEntry'] ) ) {
			foreach ( $body['siteEntry'] as $entry ) {
				$level = isset( $entry['permissionLevel'] ) ? (string) $entry['permissionLevel'] : '';
				if ( 'siteUnverifiedUser' === $level ) {
					continue; // No data access.
				}
				if ( empty( $entry['siteUrl'] ) ) {
					continue;
				}
				$sites[] = array(
					'siteUrl'         => (string) $entry['siteUrl'],
					'permissionLevel' => $level,
				);
			}
		}

		return array( 'ok' => true, 'sites' => $sites );
	}

	/**
	 * Query search-analytics rows for one page of results.
	 *
	 * @param string $site_url   Exact property string from list_sites().
	 * @param string $start_date YYYY-MM-DD.
	 * @param string $end_date   YYYY-MM-DD.
	 * @param int    $start_row  Pagination offset.
	 * @param int    $row_limit  Rows per page (max 25000).
	 * @return array{ok:bool,rows?:array,error?:array}
	 */
	public static function query( $site_url, $start_date, $end_date, $start_row, $row_limit ) {
		$tok = GoogleServiceAccount::access_token();
		if ( empty( $tok['ok'] ) ) {
			return array( 'ok' => false, 'error' => $tok['error'] );
		}

		$url     = self::API_BASE . '/sites/' . rawurlencode( (string) $site_url ) . '/searchAnalytics/query';
		$payload = array(
			'startDate'  => (string) $start_date,
			'endDate'    => (string) $end_date,
			'dimensions' => array( 'query', 'page' ),
			'rowLimit'   => (int) $row_limit,
			'startRow'   => (int) $start_row,
		);

		$resp = wp_remote_post(
			$url,
			array(
				'timeout' => 60,
				'headers' => array(
					'Authorization' => 'Bearer ' . $tok['token'],
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		$parsed = self::decode( $resp );
		if ( empty( $parsed['ok'] ) ) {
			return $parsed;
		}

		$rows = ( ! empty( $parsed['body']['rows'] ) && is_array( $parsed['body']['rows'] ) ) ? $parsed['body']['rows'] : array();
		return array( 'ok' => true, 'rows' => $rows );
	}

	/**
	 * Decode a wp_remote_* response into a normalized envelope.
	 *
	 * @param array|\WP_Error $resp Response.
	 * @return array{ok:bool,body?:array,error?:array}
	 */
	private static function decode( $resp ) {
		if ( is_wp_error( $resp ) ) {
			return array( 'ok' => false, 'error' => array( 'message' => $resp->get_error_message() ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $resp );
		$body = json_decode( wp_remote_retrieve_body( $resp ), true );

		if ( $code < 200 || $code >= 300 ) {
			$msg = __( 'Search Console request failed.', 'ai-internal-linking' );
			if ( isset( $body['error']['message'] ) ) {
				$msg = (string) $body['error']['message'];
			}
			if ( 403 === $code ) {
				$msg .= ' ' . __( '(Is the service-account email added as a user on this property in Search Console?)', 'ai-internal-linking' );
			}
			return array( 'ok' => false, 'error' => array( 'message' => $msg, 'code' => $code ) );
		}

		return array( 'ok' => true, 'body' => is_array( $body ) ? $body : array() );
	}
}
