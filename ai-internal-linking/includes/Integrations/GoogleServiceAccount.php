<?php
/**
 * Google service-account authentication for the Search Console API.
 *
 * The site owner pastes (or uploads) a service-account JSON key. We store it
 * encrypted at rest (Security\Crypto), then mint short-lived access tokens by
 * signing a JWT with the key's private key (RS256) and exchanging it at Google's
 * token endpoint (the OAuth 2.0 JWT-bearer flow). No redirect URIs, no consent
 * screen, no refresh-token storage — which is why it works headless (cron) and
 * on any host.
 *
 * The site owner grants access by adding the service-account email as a user on
 * their Search Console property; nothing here can read a property that has not
 * been shared with that email.
 *
 * @package AILinking
 */

namespace AILinking\Integrations;

use AILinking\Security\Crypto;
use AILinking\Support\Settings;

defined( 'ABSPATH' ) || exit;

class GoogleServiceAccount {

	/** Option holding the encrypted service-account JSON. */
	const OPTION_KEY = 'ailinking_gsc_key';

	/** Transient caching the current access token. */
	const TOKEN_TRANSIENT = 'ailinking_gsc_token';

	/** Read-only Search Console scope. */
	const SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';

	/**
	 * Validate and store a service-account JSON key.
	 *
	 * @param string $json Raw JSON key file contents.
	 * @return array{ok:bool,client_email?:string,error?:string}
	 */
	public static function save_credentials( $json ) {
		$data = json_decode( (string) $json, true );
		if ( ! is_array( $data ) ) {
			return array( 'ok' => false, 'error' => 'invalid_json' );
		}
		if ( 'service_account' !== ( isset( $data['type'] ) ? $data['type'] : '' ) ) {
			return array( 'ok' => false, 'error' => 'not_service_account' );
		}
		foreach ( array( 'client_email', 'private_key', 'token_uri' ) as $field ) {
			if ( empty( $data[ $field ] ) ) {
				return array( 'ok' => false, 'error' => 'missing_' . $field );
			}
		}
		if ( ! Crypto::available() ) {
			return array( 'ok' => false, 'error' => 'crypto_unavailable' );
		}

		$sealed = Crypto::encrypt( wp_json_encode( $data ) );
		if ( '' === $sealed ) {
			return array( 'ok' => false, 'error' => 'encrypt_failed' );
		}

		update_option( self::OPTION_KEY, $sealed, false );
		Settings::update( array( 'gsc_client_email' => sanitize_text_field( $data['client_email'] ) ) );
		delete_transient( self::TOKEN_TRANSIENT );

		return array( 'ok' => true, 'client_email' => (string) $data['client_email'] );
	}

	/**
	 * Whether a service-account key is stored.
	 *
	 * @return bool
	 */
	public static function is_connected() {
		return '' !== (string) get_option( self::OPTION_KEY, '' );
	}

	/**
	 * Decrypt and return the stored credentials.
	 *
	 * @return array|null Null if absent or unreadable (e.g. salts rotated).
	 */
	public static function credentials() {
		$sealed = (string) get_option( self::OPTION_KEY, '' );
		if ( '' === $sealed ) {
			return null;
		}
		$json = Crypto::decrypt( $sealed );
		if ( null === $json ) {
			return null;
		}
		$data = json_decode( $json, true );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * The service-account email the user must add in Search Console.
	 *
	 * @return string
	 */
	public static function client_email() {
		$email = (string) Settings::get( 'gsc_client_email', '' );
		if ( '' !== $email ) {
			return $email;
		}
		$creds = self::credentials();
		return ( $creds && ! empty( $creds['client_email'] ) ) ? (string) $creds['client_email'] : '';
	}

	/**
	 * Forget the stored key and all derived state. Keeps imported keyword rows.
	 */
	public static function disconnect() {
		delete_option( self::OPTION_KEY );
		delete_option( 'ailinking_gsc_sites' );
		delete_transient( self::TOKEN_TRANSIENT );
		Settings::update(
			array(
				'gsc_client_email' => '',
				'gsc_property'     => '',
				'gsc_auto_sync'    => false,
				'gsc_last_sync'    => 0,
				'gsc_last_status'  => '',
				'gsc_last_count'   => 0,
			)
		);
	}

	/**
	 * Get a valid access token (cached until shortly before it expires).
	 *
	 * @return array{ok:bool,token?:string,error?:array}
	 */
	public static function access_token() {
		$cached = get_transient( self::TOKEN_TRANSIENT );
		if ( is_string( $cached ) && '' !== $cached ) {
			return array( 'ok' => true, 'token' => $cached );
		}

		$creds = self::credentials();
		if ( null === $creds ) {
			return array( 'ok' => false, 'error' => array( 'message' => __( 'Not connected, or the stored key could not be read. Re-enter the JSON key.', 'ai-internal-linking' ) ) );
		}
		if ( ! function_exists( 'openssl_sign' ) ) {
			return array( 'ok' => false, 'error' => array( 'message' => __( 'The PHP OpenSSL extension is required for Google authentication.', 'ai-internal-linking' ) ) );
		}

		$jwt = self::build_jwt( $creds );
		if ( null === $jwt ) {
			return array( 'ok' => false, 'error' => array( 'message' => __( 'Could not sign the authentication token — check the private key in the JSON.', 'ai-internal-linking' ) ) );
		}

		$resp = wp_remote_post(
			(string) $creds['token_uri'],
			array(
				'timeout' => 20,
				'body'    => array(
					'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
					'assertion'  => $jwt,
				),
			)
		);
		if ( is_wp_error( $resp ) ) {
			return array( 'ok' => false, 'error' => array( 'message' => $resp->get_error_message() ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $resp );
		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( 200 !== $code || empty( $body['access_token'] ) ) {
			$msg = __( 'Google authentication failed.', 'ai-internal-linking' );
			if ( isset( $body['error_description'] ) ) {
				$msg = (string) $body['error_description'];
			} elseif ( isset( $body['error'] ) ) {
				$msg = is_array( $body['error'] ) && isset( $body['error']['message'] ) ? (string) $body['error']['message'] : (string) $body['error'];
			}
			return array( 'ok' => false, 'error' => array( 'message' => $msg, 'code' => $code ) );
		}

		$token = (string) $body['access_token'];
		$ttl   = isset( $body['expires_in'] ) ? max( 60, (int) $body['expires_in'] - 60 ) : 3000;
		set_transient( self::TOKEN_TRANSIENT, $token, $ttl );

		return array( 'ok' => true, 'token' => $token );
	}

	/**
	 * Build and RS256-sign the JWT bearer assertion.
	 *
	 * @param array $creds Decoded credentials.
	 * @return string|null Signed JWT, or null on signing failure.
	 */
	private static function build_jwt( array $creds ) {
		$now    = time();
		$header = self::b64url( wp_json_encode( array( 'alg' => 'RS256', 'typ' => 'JWT' ) ) );
		$claims = self::b64url(
			wp_json_encode(
				array(
					'iss'   => $creds['client_email'],
					'scope' => self::SCOPE,
					'aud'   => $creds['token_uri'],
					'iat'   => $now,
					'exp'   => $now + 3600,
				)
			)
		);

		$input     = $header . '.' . $claims;
		$signature = '';
		if ( ! openssl_sign( $input, $signature, (string) $creds['private_key'], OPENSSL_ALGO_SHA256 ) ) {
			return null;
		}

		return $input . '.' . self::b64url( $signature );
	}

	/**
	 * URL-safe base64 without padding.
	 *
	 * @param string $data Raw bytes.
	 * @return string
	 */
	private static function b64url( $data ) {
		return rtrim( strtr( base64_encode( (string) $data ), '+/', '-_' ), '=' );
	}
}
