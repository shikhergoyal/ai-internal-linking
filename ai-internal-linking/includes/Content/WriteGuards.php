<?php
/**
 * Safe, byte-preserving anchor insertion into an HTML fragment.
 *
 * Rather than round-tripping HTML through DOMDocument (which silently restructures
 * real-world markup), this scans the raw string, skips tags and the contents of
 * <a>/<code>/<pre>/<script>/<style>, finds a whole-word (Unicode-aware) match, and
 * splices the new <a> in at the exact byte offset. Every other byte of the source
 * is preserved verbatim — no structural corruption is possible. The first eligible
 * occurrence is linked; if the anchor has no eligible occurrence the write is
 * refused (suggest-only).
 *
 * @package AILinking
 */

namespace AILinking\Content;

defined( 'ABSPATH' ) || exit;

class WriteGuards {

	// Never link inside these elements: existing links, code, preformatted, scripts,
	// styles, or any heading (H1-H6).
	const SKIP_TAGS = array( 'a', 'code', 'pre', 'script', 'style', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' );

	/**
	 * Count eligible (link-able, whole-word) occurrences of an anchor.
	 *
	 * @param string $html   HTML fragment.
	 * @param string $anchor Anchor phrase (verbatim).
	 * @return int
	 */
	public static function count_in_html( $html, $anchor ) {
		return count( self::eligible_matches( $html, $anchor ) );
	}

	/**
	 * Splice a single anchor link around the one eligible occurrence.
	 *
	 * @param string $html    HTML fragment.
	 * @param string $anchor  Anchor phrase (verbatim).
	 * @param string $href    Target URL.
	 * @param string $data_id Provenance id stored on the <a> element.
	 * @return array{ok:bool,html?:string,reason?:string}
	 */
	public static function insert_anchor( $html, $anchor, $href, $data_id ) {
		if ( '' === $anchor ) {
			return array( 'ok' => false, 'reason' => 'empty_anchor' );
		}

		$matches = self::eligible_matches( $html, $anchor );
		if ( empty( $matches ) ) {
			return array( 'ok' => false, 'reason' => 'anchor_not_found' );
		}

		// Link the first eligible occurrence (document order). Key terms repeat in
		// rich posts (a heading, a summary list, an MCQ, a caption); linking the
		// first is the standard, reader-first choice and keeps a good suggestion
		// appliable instead of refusing it. The byte-splice + visible-text
		// integrity check still make structural corruption impossible.
		list( $offset, $length ) = $matches[0];
		$matched = substr( $html, $offset, $length );

		$tag = '<a href="' . esc_url( $href ) . '" data-ailinking-id="' . esc_attr( $data_id ) . '">' . $matched . '</a>';
		$new = substr( $html, 0, $offset ) . $tag . substr( $html, $offset + $length );

		return array( 'ok' => true, 'html' => $new );
	}

	/**
	 * Find byte offsets of whole-word anchor matches that are not inside a tag or
	 * a skip element.
	 *
	 * @param string $html   HTML fragment.
	 * @param string $anchor Anchor phrase.
	 * @return array<int,array{0:int,1:int}> List of [offset, length].
	 */
	private static function eligible_matches( $html, $anchor ) {
		$out = array();
		if ( '' === (string) $html || '' === (string) $anchor ) {
			return $out;
		}

		$tokens = preg_split( '/(<[^>]+>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( false === $tokens ) {
			return $out;
		}

		$pattern = '/(?<![\p{L}\p{Nd}])' . preg_quote( $anchor, '/' ) . '(?![\p{L}\p{Nd}])/u';

		$offset = 0;
		$skip   = 0;
		foreach ( $tokens as $tok ) {
			$len = strlen( $tok );
			if ( 0 === $len ) {
				continue;
			}

			$is_tag = ( '<' === $tok[0] && '>' === substr( $tok, -1 ) );

			if ( $is_tag ) {
				self::update_skip( $tok, $skip );
			} elseif ( 0 === $skip ) {
				if ( preg_match_all( $pattern, $tok, $m, PREG_OFFSET_CAPTURE ) ) {
					foreach ( $m[0] as $hit ) {
						$out[] = array( $offset + (int) $hit[1], strlen( $hit[0] ) );
					}
				}
			}

			$offset += $len;
		}

		return $out;
	}

	/**
	 * Update the skip-element depth based on a tag token.
	 *
	 * @param string $tag  Tag token (e.g. '<a href="...">').
	 * @param int    $skip Depth counter (by reference).
	 */
	private static function update_skip( $tag, &$skip ) {
		// Ignore comments / doctype / PIs.
		if ( 0 === strpos( $tag, '<!' ) || 0 === strpos( $tag, '<?' ) ) {
			return;
		}

		if ( preg_match( '#^</\s*([a-zA-Z0-9]+)#', $tag, $m ) ) {
			$name = strtolower( $m[1] );
			if ( in_array( $name, self::SKIP_TAGS, true ) && $skip > 0 ) {
				$skip--;
			}
			return;
		}

		if ( preg_match( '#^<\s*([a-zA-Z0-9]+)#', $tag, $m ) ) {
			$name         = strtolower( $m[1] );
			$self_closing = ( '/>' === substr( rtrim( $tag ), -2 ) );
			if ( in_array( $name, self::SKIP_TAGS, true ) && ! $self_closing ) {
				$skip++;
			}
		}
	}
}
