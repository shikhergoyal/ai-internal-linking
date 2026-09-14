<?php
/**
 * What is actually at the other end of an internal URL.
 *
 * The link graph stores a target_post_id, and anything that did not resolve to
 * one was called broken. But most of what does not resolve to a post is a
 * perfectly ordinary page: a category or tag archive, an author page, a date
 * archive, a shop or blog index, the front page, a search result, a feed. A
 * report that calls those broken sends someone off to fix pages that were never
 * broken, and buries the handful that really are.
 *
 * So ask WordPress what the URL is rather than assuming. Every answer here
 * comes from WordPress's own routing and from lookups that have to succeed: a
 * term archive counts only if the term exists AND its own permalink is the URL
 * in hand, an author page only if that user exists. /category/does-not-exist/
 * is still reported, because nothing claims it.
 *
 * No HTTP request is made. This says what WordPress would route the URL to, not
 * what a web server answered — which is stated on the Link Health screen rather
 * than left for someone to discover.
 *
 * @package AILinking
 */

namespace AILinking\Content;

defined( 'ABSPATH' ) || exit;

class UrlClassifier {

	/** Resolves to a post, page or custom-post-type entry. */
	const POST = 'post';

	/** A real WordPress page that is not a post: an archive, feed or search. */
	const ARCHIVE = 'archive';

	/** Points at this site and nothing here claims it. This one is broken. */
	const UNKNOWN = 'unknown';

	/** A real file served off disk: an upload, a theme asset. Not a page. */
	const FILE = 'file';

	/** Points somewhere else entirely; not ours to judge. */
	const EXTERNAL = 'external';

	/**
	 * Per-request memo keyed by normalised URL. One scan asks about the same
	 * category archive once for every page that links to it.
	 *
	 * @var array<string,array>
	 */
	private static $memo = array();

	/**
	 * @param string $url Internal URL, absolute or relative.
	 * @return array{kind:string,post_id:int}
	 */
	public static function classify( $url ) {
		$url = (string) $url;
		if ( ! UrlResolver::is_internal( $url ) ) {
			return array( 'kind' => self::EXTERNAL, 'post_id' => 0 );
		}

		$key = UrlResolver::normalize( $url );
		if ( isset( self::$memo[ $key ] ) ) {
			return self::$memo[ $key ];
		}

		self::$memo[ $key ] = self::resolve( $url );
		return self::$memo[ $key ];
	}

	/**
	 * Forget everything memoised, for a job long enough to outlive a change.
	 */
	public static function flush() {
		self::$memo = array();
	}

	/**
	 * @param string $url Internal URL.
	 * @return array{kind:string,post_id:int}
	 */
	private static function resolve( $url ) {
		$absolute = UrlResolver::absolute( $url );

		$post_id = UrlResolver::to_post_id( $absolute );
		if ( $post_id > 0 ) {
			return array( 'kind' => self::POST, 'post_id' => $post_id );
		}

		// "/page/2/" pages through whatever comes before it, which may be a
		// paginated post as easily as an archive.
		$base = self::strip_paging( $absolute );
		if ( $base !== $absolute ) {
			$post_id = UrlResolver::to_post_id( $base );
			if ( $post_id > 0 ) {
				return array( 'kind' => self::POST, 'post_id' => $post_id );
			}
		}

		if ( self::is_archive( $base ) ) {
			return array( 'kind' => self::ARCHIVE, 'post_id' => 0 );
		}

		// A link straight at a file — a PDF in the uploads folder, an image, a
		// theme asset. The web server hands those out without WordPress routing
		// them at all, so no amount of asking WordPress will resolve one, and
		// calling it broken because of that is the same mistake as calling a
		// category archive broken. Answered from disk, which is exact.
		$file = self::file_target( $absolute );
		if ( null !== $file ) {
			return $file;
		}

		return array( 'kind' => self::UNKNOWN, 'post_id' => 0 );
	}

	/**
	 * Whether WordPress would route this to something real that is not a post.
	 *
	 * @param string $absolute Absolute URL, pagination already removed.
	 * @return bool
	 */
	private static function is_archive( $absolute ) {
		return self::is_front_page( $absolute )
			|| self::is_search( $absolute )
			|| self::is_feed( $absolute )
			|| self::is_post_type_archive( $absolute )
			|| self::is_term_archive( $absolute )
			|| self::is_author_archive( $absolute )
			|| self::is_date_archive( $absolute );
	}

	/**
	 * Whether a URL points at a file that is really on disk.
	 *
	 * Attachments resolve to their post so the link graph gains a real edge.
	 * Anything else under wp-content is checked against the filesystem: present
	 * means a working link, absent means genuinely broken, and both answers are
	 * exact and free. Only wp-content is mapped, because that is the one
	 * directory whose URL-to-path relationship WordPress actually tells us.
	 *
	 * @param string $absolute Absolute URL.
	 * @return array{kind:string,post_id:int}|null Null when this is not a file URL.
	 */
	private static function file_target( $absolute ) {
		$path = (string) wp_parse_url( $absolute, PHP_URL_PATH );
		if ( '' === $path ) {
			return null;
		}

		$content_url = (string) wp_parse_url( content_url(), PHP_URL_PATH );
		$content_url = rtrim( $content_url, '/' );
		if ( '' === $content_url || 0 !== strpos( $path, $content_url . '/' ) ) {
			return null;
		}

		// An upload that is in the media library is a post, and worth an edge.
		if ( function_exists( 'attachment_url_to_postid' ) ) {
			$attachment = (int) attachment_url_to_postid( $absolute );
			if ( $attachment > 0 ) {
				return array( 'kind' => self::POST, 'post_id' => $attachment );
			}
		}

		$relative = substr( $path, strlen( $content_url ) );
		$file     = WP_CONTENT_DIR . rawurldecode( $relative );

		// Refuse to look outside wp-content, whatever the URL claims.
		$real = realpath( $file );
		$root = realpath( WP_CONTENT_DIR );
		if ( false === $real || false === $root || 0 !== strpos( $real, $root ) ) {
			return null;
		}

		return is_file( $real ) ? array( 'kind' => self::FILE, 'post_id' => 0 ) : null;
	}

	/**
	 * Path relative to the WordPress home path, without surrounding slashes, so
	 * a site installed in a subdirectory compares the same as one at the root.
	 *
	 * @param string $absolute Absolute URL.
	 * @return string
	 */
	public static function path_of( $absolute ) {
		$path = (string) wp_parse_url( (string) $absolute, PHP_URL_PATH );
		$home = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		// The prefix has to end at a segment boundary. Plain strpos() would
		// match a home of /blog against /blogging/thing and hand back
		// "ging/thing", which is a path that resolves to nothing.
		if ( '' !== $home && ( $path === $home || 0 === strpos( $path, $home . '/' ) ) ) {
			$path = substr( $path, strlen( $home ) );
		}
		return trim( (string) $path, '/' );
	}

	/**
	 * Remove a trailing /page/N/ or /comment-page-N/ from a URL.
	 *
	 * @param string $absolute Absolute URL.
	 * @return string
	 */
	public static function strip_paging( $absolute ) {
		$absolute = (string) $absolute;
		$stripped = preg_replace( '#/(?:page/|comment-page-)\d+/?$#i', '/', $absolute );
		return is_string( $stripped ) ? $stripped : $absolute;
	}

	/**
	 * Whether a path is a date archive: a year, optionally a month, optionally a
	 * day, at the end of the path. Anything before that is a permalink front
	 * such as /blog/.
	 *
	 * A path that is really a post would have resolved to one before this is
	 * ever asked, so matching here does not steal anything from a real page.
	 *
	 * @param string $path Path relative to home, no surrounding slashes.
	 * @return bool
	 */
	public static function path_is_date( $path ) {
		$path = trim( (string) $path, '/' );
		if ( '' === $path ) {
			return false;
		}
		return 1 === preg_match(
			'#(?:^|/)(?:19|20)\d{2}(?:/(?:0?[1-9]|1[0-2])(?:/(?:0?[1-9]|[12]\d|3[01]))?)?$#',
			$path
		);
	}

	private static function is_front_page( $absolute ) {
		return '' === self::path_of( $absolute );
	}

	private static function is_search( $absolute ) {
		$args = self::query_args( $absolute );
		if ( isset( $args['s'] ) ) {
			return true;
		}
		global $wp_rewrite;
		$base = ( isset( $wp_rewrite->search_base ) && $wp_rewrite->search_base ) ? $wp_rewrite->search_base : 'search';
		$path = self::path_of( $absolute );
		return $path === $base || 0 === strpos( $path, $base . '/' );
	}

	private static function is_feed( $absolute ) {
		$args = self::query_args( $absolute );
		if ( isset( $args['feed'] ) ) {
			return true;
		}
		global $wp_rewrite;
		$base = ( isset( $wp_rewrite->feed_base ) && $wp_rewrite->feed_base ) ? $wp_rewrite->feed_base : 'feed';
		$path = self::path_of( $absolute );
		if ( $path === $base ) {
			return true;
		}
		$suffix = '/' . $base;
		return strlen( $path ) > strlen( $suffix ) && substr( $path, -strlen( $suffix ) ) === $suffix;
	}

	private static function is_post_type_archive( $absolute ) {
		$target = UrlResolver::normalize( $absolute );
		foreach ( (array) get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			if ( empty( $type->has_archive ) ) {
				continue;
			}
			$link = get_post_type_archive_link( $type->name );
			if ( $link && UrlResolver::normalize( $link ) === $target ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A term archive for any public taxonomy. The term has to exist and its own
	 * permalink has to be this URL, so an invented category is still reported.
	 */
	private static function is_term_archive( $absolute ) {
		$path = self::path_of( $absolute );
		if ( '' === $path ) {
			return false;
		}
		$segments = explode( '/', $path );
		$slug     = (string) end( $segments );
		if ( '' === $slug ) {
			return false;
		}

		$taxonomies = get_taxonomies( array( 'public' => true ), 'names' );
		if ( empty( $taxonomies ) ) {
			return false;
		}

		$terms = get_terms(
			array(
				'taxonomy'               => array_values( $taxonomies ),
				'slug'                   => $slug,
				'hide_empty'             => false,
				'number'                 => 20,
				'update_term_meta_cache' => false,
			)
		);
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return false;
		}

		$target = UrlResolver::normalize( $absolute );
		foreach ( $terms as $term ) {
			$link = get_term_link( $term );
			if ( ! is_wp_error( $link ) && UrlResolver::normalize( $link ) === $target ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * An author archive, by pretty permalink or by ?author=N. The user has to
	 * exist.
	 */
	private static function is_author_archive( $absolute ) {
		$args = self::query_args( $absolute );
		if ( ! empty( $args['author'] ) && ctype_digit( (string) $args['author'] ) ) {
			return (bool) get_userdata( (int) $args['author'] );
		}

		global $wp_rewrite;
		$base = ( isset( $wp_rewrite->author_base ) && $wp_rewrite->author_base ) ? $wp_rewrite->author_base : 'author';
		$path = self::path_of( $absolute );
		if ( 0 !== strpos( $path, $base . '/' ) ) {
			return false;
		}

		$segments = explode( '/', trim( substr( $path, strlen( $base ) + 1 ), '/' ) );
		$slug     = isset( $segments[0] ) ? $segments[0] : '';
		if ( '' === $slug ) {
			return false;
		}
		return get_user_by( 'slug', $slug ) instanceof \WP_User;
	}

	private static function is_date_archive( $absolute ) {
		return self::path_is_date( self::path_of( $absolute ) );
	}

	/**
	 * @param string $absolute Absolute URL.
	 * @return array
	 */
	private static function query_args( $absolute ) {
		$query = (string) wp_parse_url( (string) $absolute, PHP_URL_QUERY );
		if ( '' === $query ) {
			return array();
		}
		$args = array();
		parse_str( $query, $args );
		return (array) $args;
	}
}
