<?php
/**
 * Secret encryption at rest for API keys / tokens.
 *
 * Uses an envelope scheme: a random 32-byte Data Encryption Key (DEK) is generated
 * once and stored wrapped by a Key Encryption Key (KEK) derived from the site's
 * salts (or the AILINKING_ENCRYPTION_KEY constant). Secrets are encrypted with the
 * DEK. If the salts rotate, the DEK can no longer be unwrapped — decryption returns
 * null (a clear "re-enter your keys" signal) rather than silently corrupting data.
 *
 * Honest scope: this protects against casual DB dumps / plaintext backups, NOT
 * against an attacker with filesystem read (who also has the KEK material).
 *
 * @package AILinking
 */

namespace AILinking\Security;

defined( 'ABSPATH' ) || exit;

class Crypto {

	const DEK_OPTION = 'ailinking_dek';

	/**
	 * Encrypt a secret. Returns an opaque token, or '' on failure.
	 *
	 * @param string $plaintext Secret.
	 * @return string
	 */
	public static function encrypt( $plaintext ) {
		$dek = self::dek();
		if ( null === $dek ) {
			return '';
		}
		$sealed = self::seal( (string) $plaintext, $dek );
		return null === $sealed ? '' : $sealed;
	}

	/**
	 * Decrypt a secret. Returns null on failure (e.g. salts rotated).
	 *
	 * @param string $token Opaque token from encrypt().
	 * @return string|null
	 */
	public static function decrypt( $token ) {
		$dek = self::dek();
		if ( null === $dek || '' === (string) $token ) {
			return null;
		}
		return self::open( (string) $token, $dek );
	}

	/**
	 * Whether the encryption layer is currently usable (DEK unwraps).
	 *
	 * @return bool
	 */
	public static function available() {
		return null !== self::dek();
	}

	/**
	 * Get the data encryption key (generating + wrapping it on first use).
	 *
	 * @return string|null 32 raw bytes, or null if an existing DEK can't be unwrapped.
	 */
	private static function dek() {
		static $cached = false;
		static $value  = null;
		if ( false !== $cached ) {
			return $value;
		}
		$cached = true;

		$kek    = self::kek();
		$stored = function_exists( 'get_option' ) ? get_option( self::DEK_OPTION, '' ) : '';

		if ( $stored ) {
			$value = self::open( $stored, $kek ); // null if salts changed.
			return $value;
		}

		$value   = self::random( 32 );
		$wrapped = self::seal( $value, $kek );
		if ( null === $wrapped ) {
			// No crypto backend (no sodium/openssl): encryption is unavailable.
			$value = null;
			return $value;
		}
		if ( function_exists( 'update_option' ) ) {
			update_option( self::DEK_OPTION, $wrapped, false );
		}
		return $value;
	}

	/**
	 * Derive the key-encryption-key from the constant or site salts.
	 *
	 * @return string 32 raw bytes.
	 */
	private static function kek() {
		if ( defined( 'AILINKING_ENCRYPTION_KEY' ) && AILINKING_ENCRYPTION_KEY ) {
			return hash( 'sha256', (string) AILINKING_ENCRYPTION_KEY, true );
		}
		$salt = '';
		foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY' ) as $c ) {
			if ( defined( $c ) ) {
				$salt .= (string) constant( $c );
			}
		}
		if ( '' === $salt && function_exists( 'wp_salt' ) ) {
			$salt = wp_salt( 'secure_auth' );
		}
		if ( '' === $salt ) {
			$salt = 'ailinking-insecure-default'; // last-resort (e.g. CLI without WP).
		}
		return hash( 'sha256', 'ailinking|' . $salt, true );
	}

	/**
	 * Encrypt bytes with a 32-byte key. Prefixes a scheme marker.
	 *
	 * @param string $plain Plaintext.
	 * @param string $key   32 raw bytes.
	 * @return string|null base64 token, or null on failure.
	 */
	private static function seal( $plain, $key ) {
		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			$nonce  = self::random( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = sodium_crypto_secretbox( $plain, $nonce, $key );
			return 's1:' . base64_encode( $nonce . $cipher );
		}
		if ( function_exists( 'openssl_encrypt' ) ) {
			$iv     = self::random( 12 );
			$tag    = '';
			$cipher = openssl_encrypt( $plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
			if ( false === $cipher ) {
				return null;
			}
			return 'o1:' . base64_encode( $iv . $tag . $cipher );
		}
		return null;
	}

	/**
	 * Decrypt a token produced by seal().
	 *
	 * @param string $token base64 token with scheme prefix.
	 * @param string $key   32 raw bytes.
	 * @return string|null
	 */
	private static function open( $token, $key ) {
		if ( 0 === strpos( $token, 's1:' ) ) {
			if ( ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
				return null;
			}
			$raw   = base64_decode( substr( $token, 3 ), true );
			$nb    = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
			if ( false === $raw || strlen( $raw ) <= $nb ) {
				return null;
			}
			$nonce  = substr( $raw, 0, $nb );
			$cipher = substr( $raw, $nb );
			$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, $key );
			return false === $plain ? null : $plain;
		}
		if ( 0 === strpos( $token, 'o1:' ) ) {
			if ( ! function_exists( 'openssl_decrypt' ) ) {
				return null;
			}
			$raw = base64_decode( substr( $token, 3 ), true );
			if ( false === $raw || strlen( $raw ) <= 28 ) {
				return null;
			}
			$iv     = substr( $raw, 0, 12 );
			$tag    = substr( $raw, 12, 16 );
			$cipher = substr( $raw, 28 );
			$plain  = openssl_decrypt( $cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
			return false === $plain ? null : $plain;
		}
		return null;
	}

	/**
	 * Cryptographically secure random bytes.
	 *
	 * @param int $n Byte count.
	 * @return string
	 */
	private static function random( $n ) {
		return random_bytes( $n );
	}
}
