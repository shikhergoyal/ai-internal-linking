<?php
/**
 * Strips credential-shaped strings out of text before it is stored or shown.
 *
 * Provider error messages are echoed back from someone else's server and are
 * kept in provider_keys.last_error. Some APIs quote the submitted credential in
 * failure text ("Incorrect API key provided: sk-..."), and this plugin
 * deliberately supports Custom and Local endpoints whose error text is entirely
 * under a third party's control. Without this, one such message would put a
 * plaintext key in the database and in every backup taken afterwards, defeating
 * encryption at rest for that copy.
 *
 * Two layers, because neither alone is sufficient:
 *   1. Exact removal of secrets we know we just sent. Format independent, so it
 *      catches vendors and proxies no pattern anticipates.
 *   2. Pattern matching for known key shapes and long opaque tokens, which
 *      catches credentials we did not send, such as a proxy leaking its own.
 *
 * Pure: output depends only on the arguments.
 *
 * @package AILinking
 */

namespace AILinking\Security;

defined( 'ABSPATH' ) || exit;

class Redactor {

	const MASK = '[redacted]';

	/**
	 * Shortest secret worth removing. Below this, an "exact" match is more
	 * likely to be an ordinary substring of the message than a credential.
	 */
	const MIN_SECRET_LEN = 8;

	/**
	 * Remove credentials from a string.
	 *
	 * @param string   $text  Untrusted text, typically a provider error message.
	 * @param string[] $known Secrets known to have been sent with the request.
	 * @return string
	 */
	public static function scrub( $text, array $known = array() ) {
		$text = (string) $text;
		if ( '' === $text ) {
			return '';
		}

		// Layer 1: exact, format independent.
		foreach ( $known as $secret ) {
			$secret = (string) $secret;
			if ( strlen( $secret ) >= self::MIN_SECRET_LEN ) {
				$text = str_replace( $secret, self::MASK, $text );
			}
		}

		// Layer 2: shapes. Vendor prefixes first, then the generic catch-all,
		// so a recognised key is labelled by its own rule rather than the
		// broad one.
		$patterns = array(
			'/\bsk-ant-[A-Za-z0-9_\-]{16,}/',   // Anthropic
			'/\bsk-[A-Za-z0-9_\-]{16,}/',       // OpenAI and compatible
			'/\bAIza[A-Za-z0-9_\-]{20,}/',      // Google
			'/\bgsk_[A-Za-z0-9]{16,}/',         // Groq
			'/\bxai-[A-Za-z0-9]{16,}/',         // xAI
			'/\bpplx-[A-Za-z0-9]{16,}/',        // Perplexity
			'/\bhf_[A-Za-z0-9]{16,}/',          // Hugging Face
			'/\br8_[A-Za-z0-9]{16,}/',          // Replicate
			'/\bBearer\s+[A-Za-z0-9._\-]{16,}/i', // Authorization header echoed back
			// Anything long and opaque: JWT segments, base64 blobs, unknown
			// vendor formats. 40 is above any real word and above a UUID (36),
			// so ordinary diagnostic text survives.
			'/\b[A-Za-z0-9_\-]{40,}\b/',
		);

		$scrubbed = preg_replace( $patterns, self::MASK, $text );

		// preg_replace returns null on failure (e.g. backtrack limit on a
		// pathological string). Never fall back to the unscrubbed original.
		return ( null === $scrubbed ) ? self::MASK : $scrubbed;
	}
}
