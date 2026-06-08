<?php
/**
 * Resolve internal URLs to post IDs and normalise URLs for comparison.
 *
 * url_to_postid() alone is unreliable for custom post types, multilingual
 * prefixes, and non-standard rewrites — so we fall back to matching against the
 * plugin's own index.url column.
 *
 * @package AILinking
 */

namespace AILinking\Content;

use AILinking\Support\Tables;

defined( 'ABSPATH' ) || exit;

class UrlResolver {

	/**
	 * Whether a URL points at this site.
	 *
	 * @param string $url URL (absolute or relative).
	 * @return bool
	 */
	public static function is_internal( $url ) {
		$url = trim( $url );
		if ( '' === $url ) {
			return false;
		}
		// Relative URLs are internal by definition.
		if ( '/' === $url[0] ) {
			return true;
		}
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( empty( $host ) ) {
			return false;
		}
		$home = wp_parse_url( home_url(), PHP_URL_HOST );
		return strtolower( $host ) === strtolower( (string) $home );
	}

	/**
	 * Normalise a URL for duplicate detection / matching: absolute, lowercased
	 * host+path, trailing slash and tracking params stripped.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function normalize( $url ) {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}
		// Make relative URLs absolute against home.
		if ( '/' === $url[0] ) {
			$url = home_url( $url );
		}
		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) ) {
			return strtolower( rtrim( $url, '/' ) );
		}
		$host = strtolower( $parts['host'] );
		$path = isset( $parts['path'] ) ? rtrim( $parts['path'], '/' ) : '';
		if ( '' === $path ) {
			$path = '/';
		}
		return $host . $path;
	}

	/**
	 * Resolve an internal URL to a post ID, with index fallback.
	 *
	 * @param string $url Internal URL.
	 * @return int Post ID, or 0 if unresolved.
	 */
	public static function to_post_id( $url ) {
		if ( ! self::is_internal( $url ) ) {
			return 0;
		}

		$absolute = ( '/' === $url[0] ) ? home_url( $url ) : $url;

		$post_id = (int) url_to_postid( $absolute );
		if ( $post_id > 0 ) {
			return $post_id;
		}

		// Fallback: exact match against the plugin's own index (covers permalink
		// equality that url_to_postid() misses for some custom rewrites). We avoid
		// a full normalised scan here to keep indexing linear; unresolved internal
		// links are still recorded with target_post_id = 0.
		global $wpdb;
		$table = Tables::index();

		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$table} WHERE url = %s OR url = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL
				$absolute,
				trailingslashit( $absolute )
			)
		);

		return $found ? (int) $found : 0;
	}
}
